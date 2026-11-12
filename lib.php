<?php
/* =====================================================================
 * Seal Chat Web — lib.php   (shared code, 1.3.2)
 * ---------------------------------------------------------------------
 * Used by index.php (the web interface), relay.php (passes sealed
 * messages) and admin.php (your setup page). Blocked from the browser.
 *
 * Sections:
 *   1. Direct-access guard + config
 *   2. Where the settings live (outside public_html when possible)
 *   3. Reading / writing JSON safely (file lock + atomic replace)
 *   4. Relay settings (relay.json) + connected computers
 *   5. Output helpers (escaping, HTTPS check, security headers)
 *   6. Updates (1.2.0): check for a newer Seal Chat Web, download it,
 *      check its signature, install it, undo it
 *   7. Admin password lockout (1.2.1: shared by admin.php + webupdate.php)
 *   8. Names (1.3.0): name@yourdomain for Seal Chat IDs — writes
 *      /.well-known/sealchat.json at the top of this website
 * ===================================================================== */

/* =================================================================
 * 1. GUARD + CONFIG
 * ================================================================= */
if (!defined('SEALCHAT_WEB')) {
    http_response_code(404);
    exit;
}
const SEALCHAT_WEB_VERSION = '1.4.0';   // 1.3.2: one phone, any of your linked devices (relay.php) · 1.4.0: contacts' Seal IDs

function sc_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config.php';
        if (!empty($cfg['timezone'])) {
            @date_default_timezone_set($cfg['timezone']);
        }
    }
    return $cfg;
}

/* =================================================================
 * 2. DATA FOLDER
 * Default: "sealchat-web-data" in your HOME folder (e.g.
 * /home/you/sealchat-web-data) — never downloadable. Falls back to
 * ./data (blocked by .htaccess) when PHP can't write there.
 * ================================================================= */
function sc_data_dir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $cfg = sc_config();
    $docRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '';
    $candidates = [];
    if (!empty($cfg['data_dir'])) {
        $candidates[] = rtrim($cfg['data_dir'], '/\\');
    }
    $home = sc_home_dir();
    if ($home !== '') {
        $candidates[] = $home . '/sealchat-web-data';
    }
    $candidates[] = __DIR__ . '/data';
    foreach ($candidates as $c) {
        $inWeb = $docRoot !== '' && str_starts_with($c . '/', rtrim($docRoot, '/') . '/');
        if ($inWeb && $c !== __DIR__ . '/data' && empty($cfg['data_dir'])) {
            continue;
        }
        if (!is_dir($c)) {
            @mkdir($c, 0700, true);
        }
        if (is_dir($c) && is_writable($c)) {
            if ($c === __DIR__ . '/data' && !file_exists($c . '/.htaccess')) {
                @file_put_contents($c . '/.htaccess', "Require all denied\nDeny from all\n");
            }
            return $dir = $c;
        }
    }
    http_response_code(500);
    exit('Seal Chat Web: no writable settings folder. Set data_dir in config.php.');
}

/** The account's home folder (/home/you), or '' if it can't be found. */
function sc_home_dir(): string
{
    $h = '';
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $pw = @posix_getpwuid(posix_geteuid());
        $h = is_array($pw) ? (string)($pw['dir'] ?? '') : '';
    }
    if ($h === '' || !@is_dir($h)) {
        $h = (string)(getenv('HOME') ?: ($_SERVER['HOME'] ?? ''));
    }
    if (($h === '' || !@is_dir($h)) && preg_match('~^(/home\d*/[^/]+)/~', __DIR__, $m)) {
        $h = $m[1];
    }
    return ($h !== '' && @is_dir($h)) ? rtrim($h, '/') : '';
}

/* =================================================================
 * 3. JSON FILES
 * ================================================================= */
function sc_read_json(string $name, array $default = []): array
{
    $file = sc_data_dir() . '/' . $name;
    if (!is_file($file)) {
        return $default;
    }
    $fh = @fopen($file, 'rb');
    if (!$fh) {
        return $default;
    }
    flock($fh, LOCK_SH);
    $raw = stream_get_contents($fh);
    flock($fh, LOCK_UN);
    fclose($fh);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : $default;
}

/** Writes to a temporary file, then swaps it in — a crash can't leave half a file. */
function sc_write_json(string $name, array $data): bool
{
    $file = sc_data_dir() . '/' . $name;
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) {
        @unlink($tmp);
        return false;
    }
    @chmod($tmp, 0600);
    if (!@rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/* =================================================================
 * 4. RELAY SETTINGS (relay.json) + CONNECTED COMPUTERS
 * relay.json: is the web interface on, and the relay code Seal Chat
 * needs to connect (part of the "connection link" on admin.php).
 * Mailboxes: <data folder>/relay/<mailbox id>/ — one per computer.
 * ================================================================= */
function sc_relay(): array
{
    $r = sc_read_json('relay.json', []);
    return [
        'enabled' => !empty($r['enabled']),
        'code'    => (string)($r['code'] ?? ''),
        'changed' => $r['changed'] ?? null,
    ];
}

function sc_relay_save(array $r): bool
{
    $r['changed'] = date('c');
    return sc_write_json('relay.json', $r);
}

/** A new random relay code like "K7QM-2XPA-9FDE-HT4W" (no look-alike letters). */
function sc_relay_new_code(): string
{
    $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < 16; $i++) {
        $out .= $abc[random_int(0, strlen($abc) - 1)];
        if ($i % 4 === 3 && $i < 15) {
            $out .= '-';
        }
    }
    return $out;
}

function sc_relay_dir(): string
{
    $d = sc_data_dir() . '/relay';
    if (!is_dir($d)) {
        @mkdir($d, 0700, true);
    }
    return $d;
}

/** The connected computers: [box => ['created', 'seen', 'devices']] for admin.php. */
function sc_relay_boxes(): array
{
    $out = [];
    foreach (glob(sc_relay_dir() . '/*/state.json') ?: [] as $f) {
        $st = json_decode((string)@file_get_contents($f), true);
        if (is_array($st)) {
            $out[basename(dirname($f))] = ['created' => $st['created'] ?? 0, 'seen' => $st['cSeen'] ?? 0, 'devices' => count($st['devices'] ?? [])];
        }
    }
    return $out;
}

/** Removes a computer's mailbox and its wake file. */
function sc_relay_remove_box(string $box): void
{
    if (!preg_match('/^[a-f0-9]{32}$/', $box)) {
        return;
    }
    $dir = sc_relay_dir() . '/' . $box;
    $st = json_decode((string)@file_get_contents($dir . '/state.json'), true);
    if (is_array($st) && preg_match('/^[a-f0-9]{32}$/', (string)($st['wake'] ?? ''))) {
        @unlink(__DIR__ . '/wake/' . $st['wake'] . '.txt');
    }
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
}

/** This web interface's address (…/folder/), for the connection link. */
function sc_web_base(): string
{
    $host = preg_replace('/[^A-Za-z0-9.\-:\[\]]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return (sc_is_https() ? 'https' : 'http') . '://' . $host . $dir . '/';
}

/* =================================================================
 * 5. OUTPUT HELPERS
 * ================================================================= */
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sc_is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
}

function sc_is_localhost(): bool
{
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

/** Security headers for admin.php. Everything (scripts, styles, images) is local. */
function sc_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
}

/* =================================================================
 * 6. UPDATES (1.2.0)
 * -----------------------------------------------------------------
 * Where: GitHub only — update.json in the public repository
 * jeffbristow/sealchat-updates. Its "web" block: { version, url, sha256 }.
 * What is checked before anything is installed:
 *   - <url>.sig must be signed with Seal Chat's private key (only the
 *     PUBLIC key is here — it can check signatures, not make them),
 *   - the downloaded file's SHA-256 must match the signed one,
 *   - the zip must be exactly one "SealChat-Web-<version>/" folder with
 *     ordinary file names, and its lib.php must say that version.
 * Never touched: config.php, your settings folder, the wake/ files.
 * The replaced files are kept in <settings folder>/web-backup/ so
 * "Undo the last update" can put them back.
 * ================================================================= */
const SC_UPDATE_SOURCES = [
    'https://raw.githubusercontent.com/jeffbristow/sealchat-updates/main/update.json',
];
// Seal Chat's update-signing PUBLIC key (Ed25519, the same one the app uses)
const SC_UPDATE_PUBKEY = 'zEcesiPus1IIakjsDR1oHhXjObb/7SqFeJDXS5ciyYY=';
const SC_UPDATE_EVERY = 12 * 3600;          // check at most every 12 hours by itself
const SC_UPDATE_MAX_ZIP = 40 * 1024 * 1024; // refuse bigger packages

/* ---------- 6a. small helpers ---------- */
/** The addresses to ask, in order (config.php 'update_urls' can replace them). */
function sc_update_sources(): array
{
    $own = sc_config()['update_urls'] ?? [];
    $list = (is_array($own) && $own) ? $own : SC_UPDATE_SOURCES;
    return array_values(array_filter(array_map('strval', $list), fn($u) => preg_match('~^https://~i', $u)));
}

function sc_web_signed_text(string $version, string $sha256): string
{
    return 'sealchat-web-update-v1|' . $version . '|' . strtolower($sha256);
}

/**
 * HTTPS GET with limits. $toFile: stream to that file instead of memory.
 * Returns ['ok' => bool, 'status' => int, 'body' => string, 'error' => string].
 */
function sc_http_get(string $url, int $maxBytes, int $timeout = 20, ?string $toFile = null): array
{
    if (!preg_match('~^https://~i', $url)) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'only https:// addresses'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'PHP "curl" is not available on this hosting'];
    }
    $body = '';
    $got = 0;
    $fh = $toFile !== null ? @fopen($toFile, 'wb') : null;
    if ($toFile !== null && !$fh) {
        return ['ok' => false, 'status' => 0, 'body' => '', 'error' => 'cannot write ' . $toFile];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FOLLOWLOCATION => true,                  // GitHub Release downloads redirect
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'SealChatWeb/' . SEALCHAT_WEB_VERSION,
        CURLOPT_HTTPHEADER     => ['Accept: application/json, application/octet-stream, */*'],
        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) use (&$body, &$got, $maxBytes, $fh) {
            $got += strlen($chunk);
            if ($got > $maxBytes) {
                return 0;                                // stop: too big
            }
            if ($fh) { fwrite($fh, $chunk); } else { $body .= $chunk; }
            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = $ok === false ? curl_error($ch) : '';
    curl_close($ch);
    if ($fh) { fclose($fh); }
    if ($got > $maxBytes) {
        $err = 'the file is bigger than expected';
    } elseif ($ok !== false && ($status < 200 || $status >= 300)) {
        $err = 'HTTP ' . $status;
    }
    return ['ok' => $err === '', 'status' => $status, 'body' => $body, 'error' => $err];
}

/** Ask one address; returns the cleaned "web" block or null (+ $why). */
function sc_update_ask(string $src, ?string &$why = null): ?array
{
    $r = sc_http_get($src, 256 * 1024, 15);
    if (!$r['ok']) { $why = $r['error']; return null; }
    $j = json_decode($r['body'], true);
    $w = is_array($j) ? ($j['web'] ?? null) : null;
    if (!is_array($w)) { $why = 'no Seal Chat Web information there'; return null; }
    $ver = (string)($w['version'] ?? '');
    $url = (string)($w['url'] ?? '');
    if (!preg_match('/^\d+\.\d+\.\d+$/', $ver) || !preg_match('~^https://[^\s"<>]+$~i', $url)) { $why = 'unreadable answer'; return null; }
    $sha = strtolower((string)($w['sha256'] ?? ''));
    return ['version' => $ver, 'url' => $url, 'sha256' => preg_match('/^[a-f0-9]{64}$/', $sha) ? $sha : '',
            'size' => mb_substr((string)($w['size'] ?? ''), 0, 20), 'note' => mb_substr((string)($w['note'] ?? ''), 0, 300)];
}

/* ---------- 6b. check ---------- */
/**
 * The newest version known, from update-check.json (asked again when
 * older than 12 hours, or straight away with $force).
 * Returns ['checked', 'latest' => block|null, 'from' => 'website'|'mirror'|'', 'error'].
 */
function sc_update_check(bool $force = false): array
{
    $c = sc_read_json('update-check.json', []);
    if (!$force && !empty($c['checked']) && time() - (int)$c['checked'] < SC_UPDATE_EVERY) {
        return $c;
    }
    $out = ['checked' => time(), 'latest' => null, 'from' => '', 'error' => ''];
    $errors = [];
    foreach (sc_update_sources() as $i => $src) {
        $why = '';
        $w = sc_update_ask($src, $why);
        if ($w) {
            $out['latest'] = $w;
            $out['from'] = $i === 0 ? 'github' : 'other';
            break;
        }
        $errors[] = parse_url($src, PHP_URL_HOST) . ': ' . $why;
    }
    if (!$out['latest']) {
        $out['error'] = implode(' · ', $errors);
        if (!empty($c['latest'])) {                      // keep what we knew before
            $out['latest'] = $c['latest'];
            $out['from'] = $c['from'] ?? '';
        }
    }
    sc_write_json('update-check.json', $out);
    return $out;
}

/** The newer version's block, or null when this is the newest. */
function sc_update_available(?array $c = null): ?array
{
    $c = $c ?? sc_read_json('update-check.json', []);
    $l = $c['latest'] ?? null;
    return (is_array($l) && version_compare((string)$l['version'], SEALCHAT_WEB_VERSION, '>')) ? $l : null;
}

/** Folders/files PHP can't write — they would stop an update. Empty = fine. */
function sc_update_blockers(): array
{
    $bad = [];
    if (!is_writable(__DIR__)) { $bad[] = basename(__DIR__) . '/ (the Seal Chat Web folder)'; }
    foreach (['index.php', 'admin.php', 'relay.php', 'lib.php', 'app.js', 'app.css', 'assets', 'img', 'vendor', 'wake'] as $f) {
        if (file_exists(__DIR__ . '/' . $f) && !is_writable(__DIR__ . '/' . $f)) { $bad[] = $f; }
    }
    if (!function_exists('sodium_crypto_sign_verify_detached')) { $bad[] = 'PHP "sodium" (needed to check signatures) is not available'; }
    if (!class_exists('ZipArchive')) { $bad[] = 'PHP "zip" is not available'; }
    if (!function_exists('curl_init')) { $bad[] = 'PHP "curl" is not available'; }
    return $bad;
}

/** Never replaced by an update. */
function sc_update_keep(string $rel): bool
{
    return $rel === 'config.php' || str_starts_with($rel, 'data/') || (str_starts_with($rel, 'wake/') && $rel !== 'wake/.htaccess');
}

/* ---------- 6c. install ---------- */
/** Downloads, checks and installs the newer version. Returns [ok, message]. */
function sc_update_install(): array
{
    $bad = sc_update_blockers();
    if ($bad) { return [false, 'Can\'t update here — PHP can\'t write to / use: ' . implode(', ', $bad)]; }
    $work = sc_data_dir() . '/web-update';
    if (!is_dir($work)) { @mkdir($work, 0700, true); }
    $lock = @fopen($work . '/.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { return [false, 'An update is already running — try again in a minute.']; }
    @set_time_limit(300);
    try {
        $c = sc_update_check(true);
        $want = sc_update_available($c);
        if (!$want) { return [false, $c['error'] && empty($c['latest']) ? 'Couldn\'t check for updates: ' . $c['error'] : 'Seal Chat Web ' . SEALCHAT_WEB_VERSION . ' is already the newest.']; }
        $ver = $want['version'];

        // 1. download (the same version from the other sources if one can't be fetched)
        $urls = [$want['url']];
        foreach (sc_update_sources() as $src) {
            $w = sc_update_ask($src);
            if ($w && $w['version'] === $ver && !in_array($w['url'], $urls, true)) { $urls[] = $w['url']; }
        }
        $zip = $work . '/package.zip';
        $sig = null;
        $fails = [];
        foreach ($urls as $u) {
            @unlink($zip);
            $s = sc_http_get($u . '.sig', 65536, 20);
            if (!$s['ok']) { $fails[] = parse_url($u, PHP_URL_HOST) . ' (.sig): ' . $s['error']; continue; }
            $z = sc_http_get($u, SC_UPDATE_MAX_ZIP, 240, $zip);
            if (!$z['ok']) { $fails[] = parse_url($u, PHP_URL_HOST) . ': ' . $z['error']; continue; }
            $sig = json_decode($s['body'], true);
            break;
        }
        if ($sig === null) { return [false, 'Couldn\'t download the update: ' . implode(' · ', $fails)]; }

        // 2. signature + checksum (a failed check is a refusal — not retried elsewhere)
        $sha = hash_file('sha256', $zip);
        if (!is_array($sig) || ($sig['version'] ?? '') !== $ver || !is_string($sig['sig'] ?? null)) { @unlink($zip); return [false, 'Refused: the signature file is not for version ' . $ver . '.']; }
        if (!hash_equals(strtolower((string)($sig['sha256'] ?? '')), $sha)) { @unlink($zip); return [false, 'Refused: the downloaded file does not match its signature (damaged or changed).']; }
        if ($want['sha256'] !== '' && !hash_equals($want['sha256'], $sha)) { @unlink($zip); return [false, 'Refused: the downloaded file does not match the published checksum.']; }
        $raw = base64_decode($sig['sig'], true);
        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached($raw, sc_web_signed_text($ver, $sha), base64_decode(SC_UPDATE_PUBKEY))) {
            @unlink($zip);
            return [false, 'Refused: the update is NOT signed by Seal Chat. Nothing was changed.'];
        }

        // 3. read the zip into a staging list (nothing written into the site yet)
        $za = new ZipArchive();
        if ($za->open($zip) !== true) { return [false, 'Refused: the package is not a readable zip.']; }
        $top = 'SealChat-Web-' . $ver . '/';
        $files = [];
        $total = 0;
        if ($za->numFiles > 1000) { $za->close(); return [false, 'Refused: too many files in the package.']; }
        for ($i = 0; $i < $za->numFiles; $i++) {
            $st = $za->statIndex($i);
            $name = (string)$st['name'];
            if (!str_starts_with($name, $top)) { $za->close(); return [false, 'Refused: unexpected file in the package (' . $name . ').']; }
            $rel = substr($name, strlen($top));
            if ($rel === '' || str_ends_with($rel, '/')) { continue; }   // folders
            if (!preg_match('~^[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+)*$~', $rel) || preg_match('~(^|/)\.\.?(/|$)~', $rel)) {
                $za->close(); return [false, 'Refused: unsafe file name in the package (' . $rel . ').'];
            }
            $total += (int)$st['size'];
            if ($total > 3 * SC_UPDATE_MAX_ZIP) { $za->close(); return [false, 'Refused: the package unpacks too big.']; }
            if (sc_update_keep($rel)) { continue; }
            $files[$rel] = $i;
        }
        $libInside = isset($files['lib.php']) ? (string)$za->getFromIndex($files['lib.php']) : '';
        if (!preg_match("/SEALCHAT_WEB_VERSION\\s*=\\s*'" . preg_quote($ver, '/') . "'/", $libInside) || !isset($files['index.php'], $files['relay.php'])) {
            $za->close(); return [false, 'Refused: the package is not Seal Chat Web ' . $ver . '.'];
        }

        // 4. unpack into the settings folder first (outside the website), and
        //    keep only the files that are new or CHANGED — identical ones are
        //    never rewritten (1.2.2: fewer writes, fewer things for a host to refuse)
        $stage = $work . '/stage';
        sc_rm_tree($stage);
        @mkdir($stage, 0700, true);
        $changed = [];
        foreach ($files as $rel => $i) {
            $data = $za->getFromIndex($i);
            if ($data === false) {                           // second way: read it as a stream
                $fp = $za->getStream($za->getNameIndex($i));
                $data = $fp ? stream_get_contents($fp) : false;
                if ($fp) { fclose($fp); }
            }
            if ($data === false) { $za->close(); return [false, 'Couldn\'t read ' . $rel . ' from the downloaded package — nothing was changed. ' . sc_why()]; }
            $dst = __DIR__ . '/' . $rel;
            if (is_file($dst) && hash_file('sha256', $dst) === hash('sha256', $data)) { continue; }   // already the same
            $st = $stage . '/' . $rel;
            if (!is_dir(dirname($st))) { @mkdir(dirname($st), 0700, true); }
            if (@file_put_contents($st, $data) === false) { $za->close(); return [false, 'Couldn\'t unpack ' . $rel . ' into the settings folder — nothing was changed. ' . sc_why(sc_data_dir())]; }
            $changed[] = $rel;
        }
        $za->close();

        // 5. back up what will be replaced (one backup: the version before this update)
        $bk = sc_data_dir() . '/web-backup';
        sc_rm_tree($bk);
        @mkdir($bk . '/files', 0700, true);
        $replaced = [];
        $added = [];
        foreach ($changed as $rel) {
            $dst = __DIR__ . '/' . $rel;
            if (is_file($dst)) {
                @mkdir(dirname($bk . '/files/' . $rel), 0700, true);
                if (!@copy($dst, $bk . '/files/' . $rel)) { return [false, 'Couldn\'t back up ' . $rel . ' — nothing was changed. ' . sc_why(sc_data_dir())]; }
                $replaced[] = $rel;
            } else {
                $added[] = $rel;
            }
        }
        sc_write_json('web-backup/backup.json', ['version' => SEALCHAT_WEB_VERSION, 'to' => $ver, 'made' => date('c'), 'replaced' => $replaced, 'added' => $added]);

        // 6. put each changed file in place: a copy next to it, then a swap
        //    (or, if the host refuses the extra file, straight over the old one)
        $done = [];
        foreach ($changed as $rel) {
            $dst = __DIR__ . '/' . $rel;
            if (!is_dir(dirname($dst)) && !@mkdir(dirname($dst), 0755, true)) { $fail = [$rel, sc_why(dirname($dst))]; break; }
            $tmp = $dst . '.sc-new';
            $ok = @copy($stage . '/' . $rel, $tmp) && @chmod($tmp, 0644) !== null && @rename($tmp, $dst);
            if (!$ok) {
                $why1 = sc_why(dirname($dst));
                @unlink($tmp);
                $ok = @copy($stage . '/' . $rel, $dst);       // second way
                if (!$ok) { $fail = [$rel, $why1]; break; }
            }
            $done[] = $rel;
        }
        if (isset($fail)) {
            sc_update_restore();                           // put back the files already replaced
            sc_rm_tree($stage);
            return [false, 'Couldn\'t write ' . $fail[0] . ' — the previous version was kept. ' . $fail[1]
                . ' If the server gives no reason, your host\'s security scanner (for example Imunify360 in cPanel) may be blocking PHP from writing that file: check its log, or upload the new zip by hand (keep your config.php).'];
        }
        sc_rm_tree($stage);
        sc_opcache_forget($done);
        @unlink($zip);
        $c['done'] = ['from' => SEALCHAT_WEB_VERSION, 'to' => $ver, 'at' => time()];
        sc_write_json('update-check.json', $c);
        return [true, 'Seal Chat Web was updated from ' . SEALCHAT_WEB_VERSION . ' to ' . $ver . '. Your config.php and settings were kept. Phones pick up the new version the next time they open it.'];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/* ---------- 6d. undo ---------- */
/** The backup's details, or null when there is nothing to undo. */
function sc_update_backup(): ?array
{
    $b = sc_read_json('web-backup/backup.json', []);
    return !empty($b['version']) ? $b : null;
}

/** Puts the files from before the last update back. Returns [ok, message]. */
function sc_update_restore(): array
{
    $b = sc_update_backup();
    if (!$b) { return [false, 'There is no earlier version to go back to.']; }
    $bk = sc_data_dir() . '/web-backup/files/';
    $fails = [];
    foreach ((array)$b['replaced'] as $rel) {
        if (!preg_match('~^[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+)*$~', (string)$rel) || sc_update_keep($rel)) { continue; }
        if (!@copy($bk . $rel, __DIR__ . '/' . $rel . '.sc-old') || !@rename(__DIR__ . '/' . $rel . '.sc-old', __DIR__ . '/' . $rel)) { $fails[] = $rel; }
    }
    foreach ((array)$b['added'] as $rel) {
        if (preg_match('~^[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+)*$~', (string)$rel) && !sc_update_keep($rel)) { @unlink(__DIR__ . '/' . $rel); }
    }
    sc_opcache_forget((array)$b['replaced']);
    if ($fails) { return [false, 'Some files couldn\'t be put back: ' . implode(', ', $fails)]; }
    sc_rm_tree(sc_data_dir() . '/web-backup');
    @unlink(sc_data_dir() . '/update-check.json');       // check again with the old version
    return [true, 'Seal Chat Web is back to version ' . $b['version'] . '.'];
}

/** Makes PHP load the new .php files straight away (hosting caches them). */
function sc_opcache_forget(array $rels): void
{
    if (!function_exists('opcache_invalidate')) { return; }
    foreach ($rels as $rel) {
        if (str_ends_with((string)$rel, '.php')) { @opcache_invalidate(__DIR__ . '/' . $rel, true); }
    }
}

/** The server's own reason for the last failed file operation, plus free space (1.2.2). */
function sc_why(?string $dir = null): string
{
    $e = error_get_last();
    $msg = $e ? trim(preg_replace('/^[a-z_]+\(\S*\):\s*/i', '', (string)$e['message'])) : '';
    $free = $dir ? @disk_free_space($dir) : false;
    $out = $msg !== '' ? 'The server said: "' . mb_substr($msg, 0, 200) . '".' : '';
    if ($free !== false && $free < 20 * 1024 * 1024) { $out .= ' Only ' . round($free / 1048576, 1) . ' MB of disk space is free.'; }
    return trim($out);
}

/** Deletes a folder inside the settings folder (only there). */
function sc_rm_tree(string $dir): void
{
    $base = realpath(sc_data_dir());
    $real = realpath($dir);
    if (!$real || !$base || !str_starts_with($real, $base . DIRECTORY_SEPARATOR)) { return; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($real);
}

/* =================================================================
 * 7. ADMIN PASSWORD LOCKOUT (1.2.1)
 * Wrong admin passwords from one internet address are counted in
 * admin.json; after config.php's 'login_failures' within
 * 'lockout_minutes' that address has to wait. Used by admin.php
 * (sign in) and webupdate.php ("Update now" on the phone).
 * ================================================================= */
/** Seconds this address still has to wait (0 = may try). */
function lock_wait(array $admin, string $ipKey): int
{
    $f = $admin['failures'][$ipKey] ?? null;
    return ($f && ($f['until'] ?? 0) > time()) ? (int)$f['until'] - time() : 0;
}

/** Counts one wrong password for this address (and forgets old entries). */
function record_failure(array &$admin, string $ipKey, array $cfg): void
{
    $window = max(1, (int)$cfg['lockout_minutes']) * 60;
    $f = $admin['failures'][$ipKey] ?? ['n' => 0, 'first' => time(), 'until' => 0];
    if (time() - $f['first'] > $window) {
        $f = ['n' => 0, 'first' => time(), 'until' => 0];
    }
    $f['n']++;
    if ($f['n'] >= max(1, (int)$cfg['login_failures'])) {
        $f['until'] = time() + $window;
    }
    $admin['failures'][$ipKey] = $f;
    foreach ($admin['failures'] as $k => $v) {
        if (($v['until'] ?? 0) < time() && time() - ($v['first'] ?? 0) > $window) {
            unset($admin['failures'][$k]);
        }
    }
    sc_write_json('admin.json', $admin);
}

/** The key admin.json uses for this visitor's address (not stored readable). */
function sc_ip_key(): string
{
    return hash('sha256', 'scw|' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
}


/* =================================================================
 * 8. NAMES (1.3.0) — name@yourdomain
 * Seal Chat lets people add each other with a short name such as
 * ann@example.com instead of the long ID. Seal Chat (on their computer,
 * through Tor) reads https://example.com/.well-known/sealchat.json and
 * shows the key fingerprint before adding anyone.
 *   - the list is kept in the settings folder (names.json)
 *   - and published to DOCUMENT_ROOT/.well-known/sealchat.json
 *   - if PHP can't write there, admin.php shows the file to upload by hand
 * ================================================================= */
const SC_NAME_RE = '/^[a-z0-9][a-z0-9._-]{0,39}$/';

/** The domain the names belong to (this website's host, without www.). */
function sc_names_domain(): string
{
    $host = strtolower(preg_replace('/[^A-Za-z0-9.\-]/', '', explode(':', $_SERVER['HTTP_HOST'] ?? 'localhost')[0]));
    return preg_replace('/^www\./', '', $host);
}

/** Where the public file goes (the top of the website, even if Seal Chat Web is in a subfolder). */
function sc_names_file(): string
{
    $root = rtrim(str_replace('\\', '/', (string)($_SERVER['DOCUMENT_ROOT'] ?? '')), '/');
    if ($root === '' || !is_dir($root)) { $root = dirname(__DIR__); }
    return $root . '/.well-known/sealchat.json';
}

/** "sealchat:<onion>:<fp>" cleaned, or '' if it isn't a Seal Chat ID (same rules as the app). */
function sc_clean_id(string $text): string
{
    $t = strtolower(preg_replace('/\.onion/i', '', preg_replace('/\s+/', '', $text)));
    if (!preg_match('/^(?:sealchat:)?([a-z2-7]{56})[:\/#]?([0-9a-f]{40}|[0-9a-f]{64})$/', $t, $m)) { return ''; }
    if (substr($m[1], -1) !== 'd') { return ''; }
    return 'sealchat:' . $m[1] . ':' . strtoupper($m[2]);
}

/** [ name => ['id' => …, 'name' => display] ] */
function sc_names(): array
{
    $n = sc_read_json('names.json', ['names' => []]);
    return is_array($n['names'] ?? null) ? $n['names'] : [];
}

/** The public JSON text for a list of names. */
function sc_names_json(array $names): string
{
    $out = [];
    ksort($names);
    foreach ($names as $n => $v) {
        $out[$n] = ($v['name'] ?? '') !== '' ? ['id' => $v['id'], 'name' => $v['name']] : $v['id'];
    }
    return json_encode(['v' => 1, 'names' => (object)$out, 'updated' => gmdate('c')], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
}

/** Saves the list and publishes the public file. Returns [saved, published, why]. */
function sc_names_save(array $names): array
{
    if (!sc_write_json('names.json', ['names' => $names])) { return [false, false, 'could not write to the settings folder']; }
    $file = sc_names_file();
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { return [true, false, 'could not make the folder ' . $dir]; }
    $tmp = $dir . '/.sealchat.json.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, sc_names_json($names)) === false) { return [true, false, 'could not write to ' . $dir]; }
    @chmod($tmp, 0644);
    if (!@rename($tmp, $file)) { @unlink($tmp); return [true, false, 'could not replace ' . $file]; }
    return [true, true, ''];
}

/** Is the published file the same as the saved list? (ignoring the "updated" time) */
function sc_names_published(array $names): bool
{
    $f = sc_names_file();
    if (!is_file($f)) { return !$names; }
    $j = json_decode((string)@file_get_contents($f), true);
    $want = json_decode(sc_names_json($names), true);
    return is_array($j) && json_encode($j['names'] ?? null) === json_encode($want['names']);
}
