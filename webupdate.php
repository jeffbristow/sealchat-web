<?php
/* =====================================================================
 * Seal Chat Web — webupdate.php   (updates from the phone, 1.2.1)
 * ---------------------------------------------------------------------
 * What the phone's ⋯ → Updates page talks to. Same updater as admin.php
 * → Updates (lib.php section 6): GitHub only, signed packages only,
 * config.php and the settings folder never touched, Undo on admin.php.
 *
 * Requests: POST webupdate.php, JSON body, JSON answer.
 *   { "a": "status" }               this version + the newest known
 *   { "a": "check" }                ask GitHub now (at most once a minute)
 *   { "a": "install", "password" }  install the newer version — needs
 *                                    your ADMIN password (the one for
 *                                    admin.php), with the same lockout
 *                                    after wrong passwords
 * Checking needs no password: it only says which version is newest.
 * Installing changes the files on your website, so it does.
 *
 * Sections:
 *   1. Start: settings, HTTPS check, headers, reading the request
 *   2. status / check
 *   3. install (admin password)
 * ===================================================================== */

define('SEALCHAT_WEB', true);
require __DIR__ . '/lib.php';

/* =================================================================
 * 1. START
 * ================================================================= */
$cfg = sc_config();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

function wu_out(array $o, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($o, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!empty($cfg['require_https']) && !sc_is_https() && !sc_is_localhost()) {
    wu_out(['ok' => false, 'error' => 'Only over https://'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    wu_out(['ok' => false, 'error' => 'POST only'], 405);
}
// only this site's own pages may call it (the phone's page sends this header)
if (($_SERVER['HTTP_X_SC_UPDATE'] ?? '') !== '1') {
    wu_out(['ok' => false, 'error' => 'Not allowed'], 403);
}
$in = json_decode((string)file_get_contents('php://input', false, null, 0, 8192), true);
$a = is_array($in) ? (string)($in['a'] ?? '') : '';

/** What the phone shows. */
function wu_state(array $c): array
{
    $n = sc_update_available($c);
    return [
        'ok'       => true,
        'current'  => SEALCHAT_WEB_VERSION,
        'checked'  => (int)($c['checked'] ?? 0),
        'latest'   => $c['latest']['version'] ?? null,
        'update'   => $n ? ['version' => $n['version'], 'size' => $n['size'], 'note' => $n['note']] : null,
        'error'    => (!empty($c['error']) && empty($c['latest'])) ? (string)$c['error'] : '',
        'canWrite' => !sc_update_blockers(),
        'blockers' => sc_update_blockers(),
        'undo'     => sc_update_backup()['version'] ?? null,
    ];
}

/* =================================================================
 * 2. STATUS / CHECK
 * ================================================================= */
if ($a === 'status') {
    wu_out(wu_state(sc_read_json('update-check.json', [])));
}
if ($a === 'check') {
    $c = sc_read_json('update-check.json', []);
    $force = time() - (int)($c['checked'] ?? 0) >= 60;   // anyone can press it, so at most once a minute
    wu_out(wu_state(sc_update_check($force)));
}

/* =================================================================
 * 3. INSTALL (needs the admin password)
 * ================================================================= */
if ($a === 'install') {
    $admin = sc_read_json('admin.json', []);
    if (empty($admin['hash'])) {
        wu_out(['ok' => false, 'error' => 'Set an admin password on the setup page (admin.php) first.'], 403);
    }
    $ipKey = sc_ip_key();
    if (($w = lock_wait($admin, $ipKey)) > 0) {
        wu_out(['ok' => false, 'error' => 'Too many wrong passwords. Wait ' . ceil($w / 60) . ' minute(s).'], 429);
    }
    if (!password_verify((string)($in['password'] ?? ''), $admin['hash'])) {
        record_failure($admin, $ipKey, $cfg);
        usleep(400000);
        wu_out(['ok' => false, 'error' => 'Wrong admin password.', 'code' => 'password'], 403);
    }
    unset($admin['failures'][$ipKey]);
    sc_write_json('admin.json', $admin);
    [$ok, $msg] = sc_update_install();
    wu_out(['ok' => $ok, 'message' => $msg, 'error' => $ok ? '' : $msg] + wu_state(sc_read_json('update-check.json', [])));
}

wu_out(['ok' => false, 'error' => 'Unknown request'], 400);
