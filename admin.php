<?php
/* =====================================================================
 * Seal Chat Web — admin.php   (your setup page)
 * ---------------------------------------------------------------------
 * Turn the web interface on/off, copy the connection link for Seal Chat,
 * see/disconnect connected computers, change your admin password.
 * 1.2.0: "Updates" — check for a newer Seal Chat Web and install it
 * here with one click (signed packages only), or undo the last update.
 * 1.3.0: "Names" — give people a short name like ann@yourdomain.com
 * for their Seal Chat ID (writes /.well-known/sealchat.json).
 *
 * First visit: enter the setup code, then choose your admin password.
 *   The setup code is the one in config.php — or, if you left that as
 *   "CHANGE-ME…", a random code saved in setup-code.txt in the settings
 *   folder (the page tells you where). Only someone with access to your
 *   hosting files can read it.
 *
 * Sections:
 *   1. Start: settings, HTTPS check, session
 *   2. Password store (admin.json) + lockout after wrong passwords
 *   3. Handling form posts (setup, sign in, web interface on/off, new
 *      code, disconnect, updates, password, sign out)
 *   4. Page: setup / sign-in screens
 *   5. Page: settings (5a on/off · 5b computers · 5e names · 5d updates ·
 *      5c password)
 * ===================================================================== */

define('SEALCHAT_WEB', true);
require __DIR__ . '/lib.php';

/* =================================================================
 * 1. START
 * ================================================================= */
$cfg = sc_config();
sc_headers();

if (!empty($cfg['require_https']) && !sc_is_https() && !sc_is_localhost()) {
    http_response_code(403);
    exit('This page only works over https:// — open it with https at the start of the address.');
}

$cookiePath = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
// sessions are kept in our own folder (some shared hosts don't keep PHP sessions reliably)
$sessDir = sc_data_dir() . '/sessions';
if (!is_dir($sessDir)) { @mkdir($sessDir, 0700, true); }
if (is_dir($sessDir) && is_writable($sessDir)) { session_save_path($sessDir); ini_set('session.gc_probability', '1'); ini_set('session.gc_divisor', '50'); ini_set('session.gc_maxlifetime', '7200'); }
session_name('sealchat_web_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => $cookiePath, 'secure' => sc_is_https(), 'httponly' => true, 'samesite' => 'Strict']);
session_start();

$idleLimit = max(5, (int)($cfg['session_minutes'] ?? 30)) * 60;
if (!empty($_SESSION['ok']) && time() - ($_SESSION['last'] ?? 0) > $idleLimit) {
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['flash'] = ['bad', 'You were signed out after ' . (int)($idleLimit / 60) . ' minutes without activity.'];
}
if (!empty($_SESSION['ok'])) {
    $_SESSION['last'] = time();
}
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/** The setup code: config.php's, or a random one kept in setup-code.txt. */
function setup_code(array $cfg): string
{
    $c = trim((string)($cfg['setup_code'] ?? ''));
    if ($c !== '' && !str_starts_with($c, 'CHANGE-ME')) {
        return $c;
    }
    $f = sc_data_dir() . '/setup-code.txt';
    $c = is_file($f) ? trim((string)file_get_contents($f)) : '';
    if ($c === '') {
        $c = sc_relay_new_code();
        @file_put_contents($f, $c . "\n");
        @chmod($f, 0600);
    }
    return $c;
}
$codeFromFile = str_starts_with(trim((string)($cfg['setup_code'] ?? '')), 'CHANGE-ME') || trim((string)($cfg['setup_code'] ?? '')) === '';

/* =================================================================
 * 2. PASSWORD STORE + LOCKOUT
 * ================================================================= */
$admin = sc_read_json('admin.json', []);
$hasPassword = !empty($admin['hash']);
$ipKey = hash('sha256', 'scw|' . ($_SERVER['REMOTE_ADDR'] ?? '?'));   // addresses aren't stored readable

// lock_wait() + record_failure(): lib.php section 7 (1.2.1 — shared with webupdate.php)
function sign_in(): void
{
    session_regenerate_id(true);
    $_SESSION['ok'] = true;
    $_SESSION['last'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
function go(string $kind = '', string $text = ''): void
{
    if ($text !== '') {
        $_SESSION['flash'] = [$kind, $text];
    }
    header('Location: admin.php', true, 303);
    exit;
}

/* =================================================================
 * 3. FORM POSTS
 * ================================================================= */
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) {
        go('bad', 'The page had expired — please try again.');
    }

    /* ---------- 3a. first-time setup ---------- */
    if ($action === 'setup' && !$hasPassword) {
        $code = strtoupper(preg_replace('/\s+/', '', (string)($_POST['code'] ?? '')));
        $p1 = (string)($_POST['password'] ?? '');
        $p2 = (string)($_POST['password2'] ?? '');
        if (($w = lock_wait($admin, $ipKey)) > 0) {
            $error = 'Too many wrong tries. Wait ' . ceil($w / 60) . ' minute(s).';
        } elseif (!hash_equals(strtoupper(setup_code($cfg)), $code)) {
            record_failure($admin, $ipKey, $cfg);
            $error = 'That setup code is not right.';
        } elseif (strlen($p1) < 10) {
            $error = 'Choose a password of at least 10 characters.';
        } elseif ($p1 !== $p2) {
            $error = 'The two passwords are different.';
        } else {
            $admin = ['hash' => password_hash($p1, PASSWORD_DEFAULT), 'created' => date('c'), 'failures' => []];
            if (!sc_write_json('admin.json', $admin)) {
                $error = 'Could not save — check that PHP may write to ' . sc_data_dir();
            } else {
                sign_in();
                go('ok', 'Your admin password is set. Turn the web interface on below.');
            }
        }
    }

    /* ---------- 3b. sign in ---------- */
    elseif ($action === 'login' && $hasPassword) {
        if (($w = lock_wait($admin, $ipKey)) > 0) {
            $error = 'Too many wrong passwords. Wait ' . ceil($w / 60) . ' minute(s) and try again.';
        } elseif (password_verify((string)($_POST['password'] ?? ''), $admin['hash'])) {
            unset($admin['failures'][$ipKey]);
            if (password_needs_rehash($admin['hash'], PASSWORD_DEFAULT)) {
                $admin['hash'] = password_hash((string)$_POST['password'], PASSWORD_DEFAULT);
            }
            sc_write_json('admin.json', $admin);
            sign_in();
            go();
        } else {
            record_failure($admin, $ipKey, $cfg);
            usleep(400000);
            $error = 'Wrong password.';
        }
    }

    /* ---------- everything below needs you signed in ---------- */
    elseif (empty($_SESSION['ok'])) {
        go('bad', 'Please sign in.');
    }

    /* ---------- 3c. web interface on / off ---------- */
    elseif ($action === 'relay_save') {
        $r = sc_relay();
        $r['enabled'] = !empty($_POST['enabled']);
        if ($r['enabled'] && $r['code'] === '') {
            $r['code'] = sc_relay_new_code();
        }
        if (!sc_relay_save($r)) { go('bad', 'Could not save — check that PHP may write to ' . sc_data_dir()); }
        go('ok', $r['enabled'] ? 'The web interface is ON. Copy the connection link into Seal Chat (Settings → Web access).' : 'The web interface is OFF. Connected computers stop working until you turn it on again.');
    }

    /* ---------- 3d. new relay code ---------- */
    elseif ($action === 'relay_newcode') {
        $r = sc_relay();
        $r['code'] = sc_relay_new_code();
        sc_relay_save($r);
        go('ok', 'New relay code made. Computers already connected keep working; new ones need the new link.');
    }

    /* ---------- 3e. disconnect computers ---------- */
    elseif ($action === 'relay_remove') {
        $box = (string)($_POST['box'] ?? '');
        if ($box === '*') {
            foreach (array_keys(sc_relay_boxes()) as $b) { sc_relay_remove_box($b); }
            go('ok', 'All computers were disconnected.');
        }
        sc_relay_remove_box($box);
        go('ok', 'That computer was disconnected. It reconnects by itself if it still has the current relay code.');
    }

    /* ---------- 3h. 1.2.0: updates (check now · update now · undo) ---------- */
    elseif ($action === 'update_check') {
        $c = sc_update_check(true);
        $n = sc_update_available($c);
        if ($n) { go('ok', 'Seal Chat Web ' . $n['version'] . ' is available — see Updates below.'); }
        go($c['error'] && empty($c['latest']) ? 'bad' : 'ok', $c['error'] && empty($c['latest']) ? 'Couldn\'t check: ' . $c['error'] : 'You have the newest version (' . SEALCHAT_WEB_VERSION . ').');
    }
    elseif ($action === 'update_install') {
        [$ok, $msg] = sc_update_install();
        go($ok ? 'ok' : 'bad', $msg);
    }
    elseif ($action === 'update_undo') {
        [$ok, $msg] = sc_update_restore();
        go($ok ? 'ok' : 'bad', $msg);
    }

    /* ---------- 3i. 1.3.0: names (add / remove / publish again) ---------- */
    elseif ($action === 'name_add') {
        $n = strtolower(trim((string)($_POST['name'] ?? '')));
        $n = preg_replace('/@.*$/', '', ltrim($n, '@'));                  // "ann@site" or "@ann" → "ann"
        $id = sc_clean_id((string)($_POST['id'] ?? ''));
        $disp = trim(preg_replace('/[\x00-\x1f]/', '', (string)($_POST['display'] ?? '')));
        $disp = function_exists('mb_substr') ? mb_substr($disp, 0, 80) : substr($disp, 0, 80);
        if (!preg_match(SC_NAME_RE, $n)) { go('bad', 'Names use a–z, 0–9, dot, dash or underscore (start with a letter or digit, up to 40).'); }
        if ($id === '') { go('bad', 'That is not a Seal Chat ID. Copy it from Seal Chat: home screen → Copy my ID (it starts with sealchat:).'); }
        $names = sc_names();
        if (count($names) >= 500 && !isset($names[$n])) { go('bad', 'That is the most names one website can list here (500).'); }
        $was = $names[$n]['id'] ?? '';
        $names[$n] = ['id' => $id, 'name' => $disp, 'added' => $names[$n]['added'] ?? time()];
        [$saved, $pub, $why] = sc_names_save($names);
        if (!$saved) { go('bad', 'Could not save — ' . $why . '.'); }
        $full = $n . '@' . sc_names_domain();
        $msg = ($was !== '' && $was !== $id)
            ? $full . ' now points to a different ID. People who already added it will see a warning in Seal Chat (they are never switched automatically).'
            : $full . ' is ready — people can now add it in Seal Chat.';
        go($pub ? 'ok' : 'bad', $pub ? $msg : 'Saved, but couldn\'t publish it (' . $why . '). See "Names" below to upload the file by hand.');
    }
    elseif ($action === 'name_remove') {
        $names = sc_names();
        unset($names[strtolower((string)($_POST['name'] ?? ''))]);
        [$saved, $pub, $why] = sc_names_save($names);
        go($saved && $pub ? 'ok' : 'bad', $saved ? ($pub ? 'Name removed.' : 'Removed, but couldn\'t publish it (' . $why . ').') : 'Could not save — ' . $why . '.');
    }
    elseif ($action === 'name_publish') {
        [$saved, $pub, $why] = sc_names_save(sc_names());
        go($pub ? 'ok' : 'bad', $pub ? 'The names file was published again.' : 'Couldn\'t publish it (' . $why . ').');
    }

    /* ---------- 3f. change password ---------- */
    elseif ($action === 'password') {
        $p1 = (string)($_POST['new1'] ?? '');
        if (!password_verify((string)($_POST['current'] ?? ''), $admin['hash'])) { go('bad', 'Current password is wrong — password not changed.'); }
        if (strlen($p1) < 10) { go('bad', 'New password must be at least 10 characters.'); }
        if ($p1 !== (string)($_POST['new2'] ?? '')) { go('bad', 'The new passwords are different.'); }
        $admin['hash'] = password_hash($p1, PASSWORD_DEFAULT);
        $admin['changed'] = date('c');
        sc_write_json('admin.json', $admin);
        session_regenerate_id(true);
        go('ok', 'Password changed.');
    }

    /* ---------- 3g. sign out ---------- */
    elseif ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        go('ok', 'Signed out.');
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$csrf = $_SESSION['csrf'];

function page_start(): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex, nofollow"><title>Seal Chat Web — setup</title>'
        . '<link rel="icon" type="image/png" href="img/icon-192.png"><link rel="stylesheet" href="assets/admin.css?v=' . h(SEALCHAT_WEB_VERSION) . '"></head><body>';
}
function show_msgs(?array $flash, string $error): void
{
    if ($flash) { echo '<div class="msg ' . ($flash[0] === 'ok' ? 'ok' : 'bad') . '">' . h($flash[1]) . '</div>'; }
    if ($error !== '') { echo '<div class="msg bad">' . nl2br(h($error)) . '</div>'; }
}

/* =================================================================
 * 4. SETUP / SIGN-IN SCREENS
 * ================================================================= */
if (empty($_SESSION['ok'])) {
    page_start();
    echo '<div class="login"><div class="brand"><img src="img/icon-192.png" alt="" width="34" height="34"><span>Seal Chat Web — setup</span></div>';
    show_msgs($flash, $error);
    echo '<div class="panel">';
    if (!$hasPassword) {
        setup_code($cfg);     // makes setup-code.txt if needed ?>
        <h2>Create your admin password</h2>
        <p class="hint">First time here. Enter the <b>setup code</b>, then choose a password (10+ characters).</p>
        <?php if ($codeFromFile): ?>
          <p class="hint">Your setup code is in the file <code><?= h(sc_data_dir()) ?>/setup-code.txt</code> on your hosting (open it with cPanel File Manager). Or set your own in config.php.</p>
        <?php else: ?>
          <p class="hint">It is the <code>setup_code</code> in config.php.</p>
        <?php endif; ?>
        <form method="post" autocomplete="off">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="setup">
          <label class="f">Setup code<input name="code" required></label><br>
          <label class="f">New password<input type="password" name="password" minlength="10" required autocomplete="new-password"></label><br>
          <label class="f">Repeat password<input type="password" name="password2" minlength="10" required autocomplete="new-password"></label><br>
          <button class="btn primary wide">Create password</button>
        </form>
    <?php } else { ?>
        <h2>Sign in</h2>
        <p class="hint">Setup page for this Seal Chat web interface.</p>
        <form method="post">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="login">
          <label class="f">Password<input type="password" name="password" required autofocus autocomplete="current-password"></label><br>
          <button class="btn primary wide">Sign in</button>
        </form>
    <?php }
    echo '</div><p class="muted small center">Password file: <code>' . h(sc_data_dir()) . '/admin.json</code><br>Forgot the password? Delete that file and open this page again.</p>';
    echo '<p class="muted small center"><a href="./">← The web interface</a></p></div></body></html>';
    exit;
}

/* =================================================================
 * 5. SETTINGS PAGE
 * ================================================================= */
$relay = sc_relay();
$boxes = sc_relay_boxes();
$link = sc_web_base() . '#relay=' . $relay['code'];
page_start();
?>
<div class="admin">
  <div class="admin-head">
    <div class="brand"><img src="img/icon-192.png" alt="" width="34" height="34"><span>Seal Chat Web — setup</span></div>
    <div class="row">
      <a class="btn small" href="./" target="_blank" rel="noopener">Open the web interface ↗</a>
      <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="logout"><button class="btn small">Sign out</button></form>
    </div>
  </div>
  <?php show_msgs($flash, $error); ?>

  <!-- 5a. on/off + connection link -->
  <section class="panel">
    <div class="item-head">
      <h2 class="m0">Web interface</h2>
      <span class="badge <?= $relay['enabled'] ? 'on' : '' ?>"><?= $relay['enabled'] ? 'On' : 'Off' ?></span>
    </div>
    <p class="hint">Lets you use Seal Chat from your phone's browser at <a href="./" target="_blank" rel="noopener"><?= h(sc_web_base()) ?></a>. Seal Chat on your computer does the work; this website only passes on sealed messages it can't read.</p>
    <form method="post" class="row-form">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="relay_save">
      <label class="check"><input type="checkbox" name="enabled" value="1" <?= $relay['enabled'] ? 'checked' : '' ?>> Turn the web interface on</label>
      <button class="btn small">Save</button>
    </form>
    <?php if ($relay['enabled'] && $relay['code'] !== ''): ?>
      <div class="fields">
        <label class="f full">Connection link — paste it into Seal Chat: Settings → Web access
          <input class="mono" value="<?= h($link) ?>" readonly></label>
      </div>
      <div class="row-form">
        <button type="button" class="btn small" data-copy="<?= h($link) ?>">Copy link</button>
        <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="relay_newcode"><button class="btn small">Make a new code</button></form>
      </div>
      <p class="hint">Keep this link private: with it, a computer can connect to this web interface (it still can't read anyone's messages). A new code stops NEW computers using the old link.</p>
    <?php endif; ?>
  </section>

  <!-- 5b. connected computers -->
  <section class="panel">
    <h2>Connected computers</h2>
    <?php if (!$boxes): ?>
      <p class="hint">None yet.</p>
    <?php else: ?>
      <table class="mini">
        <tr><th>Connected</th><th>Last active</th><th>Paired devices</th><th></th></tr>
        <?php foreach ($boxes as $b => $info): ?>
        <tr>
          <td><?= h($info['created'] ? date('M j, Y', (int)$info['created']) : '?') ?></td>
          <td><?= h($info['seen'] ? date('M j, g:i a', (int)$info['seen']) : 'not yet') ?></td>
          <td><?= (int)$info['devices'] ?></td>
          <td><form method="post" class="inline"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="relay_remove"><input type="hidden" name="box" value="<?= h($b) ?>"><button class="btn small danger">Disconnect</button></form></td>
        </tr>
        <?php endforeach; ?>
      </table>
      <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="relay_remove"><input type="hidden" name="box" value="*"><button class="btn small danger">Disconnect all</button></form>
    <?php endif; ?>
    <p class="hint">"Last active" is when that computer last listened for the web interface — only while someone uses it. While idle, Seal Chat just looks at a small file the web server answers without running PHP.</p>
  </section>

  <!-- 5e. 1.3.0: names (name@yourdomain) -->
  <?php
  $names = sc_names();
  $ndom = sc_names_domain();
  $npub = sc_names_published($names);
  $nurl = 'https://' . $ndom . '/.well-known/sealchat.json';
  ?>
  <section class="panel" id="names">
    <div class="item-head">
      <h2 class="m0">Names</h2>
      <span class="badge <?= $names && $npub ? 'on' : '' ?>"><?= !$names ? 'None' : ($npub ? count($names) . ' published' : 'Not published') ?></span>
    </div>
    <p class="hint">Give yourself (and friends) a short name like <b>ann@<?= h($ndom) ?></b> instead of the long Seal Chat ID. Seal Chat reads the list at <a href="<?= h($nurl) ?>" target="_blank" rel="noopener"><?= h($nurl) ?></a> and always shows the person's key fingerprint before adding them. Nothing secret is in the list — just names and IDs.</p>
    <?php if ($names): ?>
      <table class="mini">
        <tr><th>Name</th><th>Shown as</th><th>Seal Chat ID</th><th></th></tr>
        <?php foreach ($names as $n => $v): ?>
        <tr>
          <td><b><?= h($n . '@' . $ndom) ?></b></td>
          <td><?= h($v['name'] ?? '') ?></td>
          <td class="mono small" title="<?= h($v['id']) ?>"><?= h(substr($v['id'], 0, 18) . '…' . substr($v['id'], -8)) ?></td>
          <td><form method="post" class="inline" data-confirm="Remove <?= h($n . '@' . $ndom) ?>? People who already added it keep the contact."><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="name_remove"><input type="hidden" name="name" value="<?= h($n) ?>"><button class="btn small danger">Remove</button></form></td>
        </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
    <form method="post" class="fields names-add">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="name_add">
      <label class="f">Name (before the @<?= h($ndom) ?>)<input name="name" required maxlength="40" pattern="[A-Za-z0-9][A-Za-z0-9._\-]{0,39}" placeholder="ann" autocapitalize="off" autocomplete="off" spellcheck="false"></label>
      <label class="f">Shown as (optional)<input name="display" maxlength="80" placeholder="Ann"></label>
      <label class="f full">Their Seal Chat ID (Seal Chat home screen → Copy my ID)<input class="mono" name="id" required placeholder="sealchat:…:…" autocapitalize="off" autocomplete="off" spellcheck="false"></label>
      <div class="full"><button class="btn">Add / change name</button></div>
    </form>
    <?php if ($names && !$npub): ?>
      <div class="msg bad">The list isn't published yet: PHP can't write <code><?= h(sc_names_file()) ?></code>.
        Either make the folder <code>.well-known</code> at the top of your website writable (cPanel → File Manager → permissions 755) and press "Publish again", or create the file yourself with this content:</div>
      <textarea class="mono names-file" rows="8" readonly><?= h(sc_names_json($names)) ?></textarea>
      <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="name_publish"><button class="btn small">Publish again</button></form>
    <?php endif; ?>
    <p class="hint">After adding your own name, put it in Seal Chat on your computer too: Settings → You → "Your name, if your website lists one". If the website blocks visitors that come through Tor (some Cloudflare settings do), people's Seal Chat can't read the list — allow Tor or turn off the "challenge" for <code>/.well-known/</code>.</p>
  </section>

  <!-- 5d. 1.2.0: updates -->
  <?php
  $uc = sc_update_check(false);          // asks again only when the last check is 12+ hours old
  $un = sc_update_available($uc);
  $ub = sc_update_backup();
  $ublock = sc_update_blockers();
  ?>
  <section class="panel" id="updates">
    <div class="item-head">
      <h2 class="m0">Updates</h2>
      <span class="badge <?= $un ? 'on' : '' ?>"><?= $un ? 'Update available' : 'Up to date' ?></span>
    </div>
    <p class="hint">This is Seal Chat Web <b><?= h(SEALCHAT_WEB_VERSION) ?></b>.
      <?php if (!empty($uc['checked'])): ?>Last checked <?= h(date('M j, g:i a', (int)$uc['checked'])) ?><?= ($uc['from'] ?? '') === 'github' ? ' (from GitHub)' : '' ?>.<?php endif; ?>
      <?php if (!empty($uc['error']) && empty($uc['latest'])): ?><br>Couldn't check: <?= h($uc['error']) ?><?php endif; ?></p>
    <?php if ($un): ?>
      <p><b>Seal Chat Web <?= h($un['version']) ?></b><?= $un['size'] !== '' ? ' · ' . h($un['size']) : '' ?><?= $un['note'] !== '' ? ' — ' . h($un['note']) : '' ?></p>
      <?php if ($ublock): ?>
        <div class="msg bad">Updating from here needs PHP to be able to write to this folder. It can't use / write: <?= h(implode(', ', $ublock)) ?>. In cPanel → File Manager, set those to 755 (folders) / 644 (files), or upload the new zip by hand as before.</div>
      <?php else: ?>
        <form method="post" class="row-form" data-confirm="Update Seal Chat Web to <?= h($un['version']) ?> now? Your config.php and settings are kept, and you can undo it afterwards.">
          <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="update_install">
          <button class="btn primary">Update now</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
    <div class="row-form">
      <form method="post" class="inline"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="update_check"><button class="btn small">Check now</button></form>
      <?php if ($ub): ?>
      <form method="post" class="inline" data-confirm="Go back to Seal Chat Web <?= h($ub['version']) ?>?"><input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="update_undo"><button class="btn small">Undo the last update (back to <?= h($ub['version']) ?>)</button></form>
      <?php endif; ?>
    </div>
    <p class="hint">Only packages signed with Seal Chat's private key are installed — the checksum and signature are checked before any file is replaced. config.php, the settings folder and the wake files are never touched. The files that get replaced are kept in the settings folder for "Undo".</p>
  </section>

  <!-- 5c. password -->
  <section class="panel">
    <h2>Change admin password</h2>
    <form method="post" class="fields">
      <input type="hidden" name="csrf" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="password">
      <label class="f full">Current password<input type="password" name="current" required autocomplete="current-password"></label>
      <label class="f">New password (10+ characters)<input type="password" name="new1" minlength="10" required autocomplete="new-password"></label>
      <label class="f">Repeat new password<input type="password" name="new2" minlength="10" required autocomplete="new-password"></label>
      <div class="full"><button class="btn">Change password</button></div>
    </form>
  </section>
  <p class="muted small">Settings are stored in <code><?= h(sc_data_dir()) ?></code> · Seal Chat Web <?= h(SEALCHAT_WEB_VERSION) ?></p>
</div>
<script src="assets/admin.js?v=<?= h(SEALCHAT_WEB_VERSION) ?>"></script>
</body></html>
