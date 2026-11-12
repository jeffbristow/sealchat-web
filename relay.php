<?php
/* =====================================================================
 * Seal Chat Web — relay.php   (1.0.0 · mailbox 1.3.0 · shared by linked devices 1.3.2)
 * ---------------------------------------------------------------------
 * Passes SEALED messages between the web interface (your phone's
 * browser) and Seal Chat on your computer. It cannot read them: they are
 * encrypted with a key only those two have (see crypto.js).
 *
 * Each connected computer has a "mailbox" folder in
 *   <settings folder>/relay/<mailbox id>/
 *     state.json     who may use the mailbox (hashes of their tokens)
 *     q-c.json       waiting for the computer
 *     q-d-<id>.json  waiting for a paired device
 *     q-t-<id>.json  waiting for a device that is still pairing
 *     n-<queue>      the newest message number in that queue (cheap to watch)
 *     q-m.json       (1.3.0) the MAILBOX: sealed messages your contacts left
 *                    while your computer was off (kept up to 30 days)
 * Messages are deleted as soon as they are picked up, and after 3 days
 * if they never are.
 *
 * While nobody uses the web interface, Seal Chat does NOT call this file.
 * It only looks at a small static "wake" file (wake/<random>.txt),
 * which the web server answers without running PHP ("304 Not
 * Modified"). Opening the web interface changes that file (action
 * "wake"); Seal Chat notices on its next look and starts calling relay.php
 * until the web interface has been quiet for a few minutes.
 *
 * Requests: POST relay.php?a=<action>, JSON body, JSON answer.
 *   Headers: X-SC-Box: <mailbox id>, Authorization: Bearer <token>
 *
 * Sections:
 *   1. Start: settings, limits, answer helpers
 *   2. Mailbox files (locked reads/writes)
 *   3. Who is asking (computer / device / pairing device)
 *   4. Actions: register, unregister, allow, send, recv, wake
 *   5. Mailbox (1.3.0): mbox_set, mbox_drop, mbox_get
 *      Your contacts' Seal Chat (1.8.0+) leaves a message here when it can't
 *      reach your computer. Each message is signed and encrypted for YOU
 *      with PGP — this website can't read it and doesn't learn who sent
 *      it (that is inside the encryption). Leaving one needs the mailbox's
 *      "drop" token, which your computer gives only to your contacts.
 *   6. (1.3.2) Linked devices sharing one mailbox: release, rekey
 *
 * 1.3.2 — ONE PHONE, ANY OF YOUR DEVICES
 * With Seal Chat 1.9.3, your linked devices (Settings → Your devices) share
 * the same connection to this website, so your phone works with whichever
 * of them is running. Only ONE may answer the phone at a time, or two
 * computers would both reply. That one holds the "lease":
 *   - "recv" from a computer names it ("node"). If another computer holds a
 *     lease that hasn't run out, the answer is "standby" (no messages); the
 *     standby computer asks again every ~15 s.
 *   - The holder renews it with every "recv". If it stops (asleep, shut
 *     down), the lease runs out ~45 s later and the next computer takes it.
 *   - Going back to idle, the holder gives it back ("release"); computers on
 *     standby then go idle too, until the phone wakes them again.
 *   - Pairing a phone on a device always takes the lease ("force").
 *   - Taking it over passes on where the old holder was (message number and
 *     the last message it picked up), so nothing is answered twice.
 * "allow" (v 2) adds and removes devices instead of replacing the whole
 * list, so two computers can't undo each other's changes. "rekey" changes
 * the computer token — used when you remove one of your devices.
 * Older Seal Chat (no "node") works exactly as before.
 * ===================================================================== */

define('SEALCHAT_WEB', true);
require __DIR__ . '/lib.php';

/* =================================================================
 * 1. START
 * ================================================================= */
const RL_MAX_BODY   = 6 * 1024 * 1024;    // one request
const RL_MAX_ITEM   = 3 * 1024 * 1024;    // one sealed message
const RL_MAX_QUEUE  = 24 * 1024 * 1024;   // waiting in one queue
const RL_MAX_ITEMS  = 600;
const RL_KEEP_SECS  = 3 * 86400;          // unread messages are dropped after this
const RL_MAX_WAIT   = 25;                 // longest "wait for a message" (seconds)
const RL_MAX_BOXES  = 20;                 // computers per website
const MB_MAX_ITEM   = 768 * 1024;         // 1.3.0 mailbox: one sealed message (text, or a file's offer + preview)
const MB_MAX_QUEUE  = 20 * 1024 * 1024;   //               waiting in one mailbox
const MB_MAX_ITEMS  = 1000;
const MB_KEEP_SECS  = 30 * 86400;         //               never picked up → deleted after 30 days
const MB_PER_MINUTE = 120;                //               drops per minute (all senders together)
const LS_EXTRA      = 20;                 // 1.3.2: a lease lasts the "recv" wait + this many seconds

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function out(array $data, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(int $status, string $code, string $text): void
{
    out(['ok' => false, 'code' => $code, 'error' => $text], $status);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail(405, 'method', 'Use POST');
}
$cfg = sc_config();
if (!empty($cfg['require_https']) && !sc_is_https() && !sc_is_localhost()) {
    fail(403, 'https', 'Use https://');
}
$relay = sc_relay();
if (!$relay['enabled']) {
    fail(403, 'off', 'The web interface is turned off on this website (admin.php).');
}
if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > RL_MAX_BODY) {
    fail(413, 'big', 'Too much at once');
}
$raw = (string)file_get_contents('php://input', false, null, 0, RL_MAX_BODY + 1);
if (strlen($raw) > RL_MAX_BODY) {
    fail(413, 'big', 'Too much at once');
}
$in = $raw === '' ? [] : json_decode($raw, true);
if (!is_array($in)) {
    fail(400, 'json', 'Bad request');
}
$action = (string)($_GET['a'] ?? '');
@set_time_limit(RL_MAX_WAIT + 30);

/* =================================================================
 * 2. MAILBOX FILES
 * One lock per mailbox: every change happens while holding it.
 * ================================================================= */
$box = strtolower((string)($_SERVER['HTTP_X_SC_BOX'] ?? ($in['box'] ?? '')));
if (!preg_match('/^[a-f0-9]{32}$/', $box)) {
    fail(400, 'box', 'Missing mailbox id');
}
$dir = sc_relay_dir() . '/' . $box;
$lockFh = null;

function lock_box(): void
{
    global $dir, $lockFh;
    if ($lockFh) {
        return;
    }
    $lockFh = @fopen($dir . '/lock', 'c');
    if (!$lockFh || !flock($lockFh, LOCK_EX)) {
        fail(500, 'lock', 'Could not lock the mailbox');
    }
}
function unlock_box(): void
{
    global $lockFh;
    if ($lockFh) {
        flock($lockFh, LOCK_UN);
        fclose($lockFh);
        $lockFh = null;
    }
}
function read_file_json(string $f, array $default): array
{
    $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
    return is_array($j) ? $j : $default;
}
function write_file_atomic(string $f, string $data): void
{
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $data) === false || !@rename($tmp, $f)) {
        @unlink($tmp);
        fail(500, 'write', 'The website could not save (disk full or no permission?)');
    }
    @chmod($f, 0600);
}
function state(): array
{
    global $dir;
    return read_file_json($dir . '/state.json', []);
}
function save_state(array $st): void
{
    global $dir;
    write_file_atomic($dir . '/state.json', json_encode($st, JSON_UNESCAPED_SLASHES));
}
/** Queue names: "c", "d-<device>", "t-<ticket>". */
function q_file(string $q): string { global $dir; return $dir . '/q-' . $q . '.json'; }
function n_file(string $q): string { global $dir; return $dir . '/n-' . $q; }
function q_read(string $q): array { return read_file_json(q_file($q), ['next' => 1, 'items' => []]); }
function q_write(string $q, array $data): void
{
    write_file_atomic(q_file($q), json_encode($data, JSON_UNESCAPED_SLASHES));
    write_file_atomic(n_file($q), (string)($data['next'] - 1) . ':' . count($data['items']));
}
function q_delete(string $q): void { @unlink(q_file($q)); @unlink(n_file($q)); }

/** Rewrites the wake file (new content = new ETag), so Seal Chat sees "changed". */
function touch_wake(array $st): void
{
    if (!preg_match('/^[a-f0-9]{32}$/', (string)($st['wake'] ?? ''))) {
        return;
    }
    $wd = __DIR__ . '/wake';
    if (!is_dir($wd)) {
        @mkdir($wd, 0755, true);
    }
    $f = $wd . '/' . $st['wake'] . '.txt';
    $tmp = $f . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, bin2hex(random_bytes(16)) . "\n") !== false) {
        @chmod($tmp, 0644);
        @rename($tmp, $f);
    }
}

/* =================================================================
 * 3. WHO IS ASKING
 * Returns ['c'] (the computer), ['d', id] (paired device) or
 * ['t', id] (device still pairing) — from the Bearer token.
 * ================================================================= */
function bearer(): string
{
    $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $h = (string)$v; }
        }
    }
    if ($h === '') {
        $h = (string)($_SERVER['HTTP_X_SC_AUTH'] ?? '');   // some hosts drop "Authorization"
    }
    return preg_match('/^Bearer\s+([A-Za-z0-9_\-]{20,200})$/', trim($h), $m) ? $m[1] : (preg_match('/^[A-Za-z0-9_\-]{20,200}$/', trim($h)) ? trim($h) : '');
}
function who(array $st): ?array
{
    $tok = bearer();
    if ($tok === '' || !$st) {
        return null;
    }
    $h = hash('sha256', $tok);
    if (hash_equals((string)($st['ctok'] ?? ''), $h)) {
        return ['c'];
    }
    foreach (($st['devices'] ?? []) as $id => $th) {
        if (hash_equals((string)$th, $h)) {
            return ['d', (string)$id];
        }
    }
    foreach (($st['tickets'] ?? []) as $id => $t) {
        if (hash_equals((string)($t['h'] ?? ''), $h) && ($t['exp'] ?? 0) > time()) {
            return ['t', (string)$id];
        }
    }
    return null;
}
function need_who(): array
{
    $st = state();
    if (!$st) {
        fail(404, 'nobox', 'This computer is not connected to the website any more. Turn web access off and on again in Seal Chat.');
    }
    $w = who($st);
    if (!$w) {
        usleep(300000);
        fail(401, 'auth', 'Not allowed (this device may have been removed)');
    }
    return [$st, $w];
}
$idOk = fn($s) => is_string($s) && preg_match('/^[a-f0-9]{16,32}$/', $s);
$hashOk = fn($s) => is_string($s) && preg_match('/^[a-f0-9]{64}$/', $s);

/* =================================================================
 * 4. ACTIONS
 * ================================================================= */
switch ($action) {

    /* ---------- 4a. register: Seal Chat connects its mailbox (needs the relay code) ---------- */
    case 'register': {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($in['code'] ?? '')));
        $want = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $relay['code']));
        if ($want === '' || !hash_equals($want, $code)) {
            sleep(1);
            fail(403, 'code', 'The relay code is not right. Copy the connection link again from admin.php.');
        }
        $tok = (string)($in['token'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_\-]{30,200}$/', $tok)) {
            fail(400, 'token', 'Bad token');
        }
        if (!is_dir($dir)) {
            if (count(sc_relay_boxes()) >= RL_MAX_BOXES) {
                fail(507, 'full', 'This website already has ' . RL_MAX_BOXES . ' computers connected (admin.php → Connected computers).');
            }
            if (!@mkdir($dir, 0700, true)) {
                fail(500, 'write', 'The website could not create the mailbox folder');
            }
        }
        lock_box();
        $st = state();
        if ($st && !hash_equals((string)$st['ctok'], hash('sha256', $tok))) {
            fail(409, 'taken', 'That mailbox belongs to another computer');
        }
        if (!$st) {
            $st = ['ctok' => hash('sha256', $tok), 'wake' => bin2hex(random_bytes(16)), 'devices' => new stdClass(), 'tickets' => new stdClass(), 'created' => time(), 'cSeen' => 0];
            save_state($st);
            touch_wake($st);
        }
        // 1.3.2: what this relay can do (Seal Chat 1.9.3 shares web access between your devices only with 'lease')
        out(['ok' => true, 'wake' => 'wake/' . $st['wake'] . '.txt', 'now' => time(), 'feat' => ['lease', 'allow2', 'rekey']]);
    }

    /* ---------- 4b. unregister: Seal Chat disconnects (deletes everything) ---------- */
    case 'unregister': {
        [$st, $w] = need_who();
        if ($w[0] !== 'c') {
            fail(403, 'role', 'Only the computer can do that');
        }
        sc_relay_remove_box($box);
        out(['ok' => true]);
    }

    /* ---------- 4c. allow: the computer says which devices may use the mailbox ---------- */
    case 'allow': {
        lock_box();
        [$st, $w] = need_who();
        if ($w[0] !== 'c') {
            fail(403, 'role', 'Only the computer can do that');
        }
        // 1.3.2 (v 2): ADD these devices / tickets and take away the ones in "remove" / "untickets",
        // so two of your linked computers can't undo each other's changes. (v 1: the list replaces all.)
        $merge = (int)($in['v'] ?? 1) >= 2;
        $devices = $merge ? (array)($st['devices'] ?? []) : [];
        foreach ((array)($in['devices'] ?? []) as $id => $th) {
            if ($idOk($id) && $hashOk($th) && (isset($devices[$id]) || count($devices) < 20)) { $devices[$id] = $th; }
        }
        foreach ((array)($in['remove'] ?? []) as $id) {
            if (is_string($id)) { unset($devices[$id]); }
        }
        $tickets = [];
        if ($merge) {
            foreach ((array)($st['tickets'] ?? []) as $id => $t) {
                if (is_array($t) && (int)($t['exp'] ?? 0) > time()) { $tickets[$id] = $t; }
            }
            foreach ((array)($in['untickets'] ?? []) as $id) {
                if (is_string($id)) { unset($tickets[$id]); }
            }
        }
        foreach ((array)($in['tickets'] ?? []) as $id => $t) {
            if ($idOk($id) && is_array($t) && $hashOk($t['h'] ?? null)) {
                $tickets[$id] = ['h' => $t['h'], 'exp' => min(time() + 1800, (int)($t['exp'] ?? 0))];
            }
        }
        if (count($tickets) > 3) {                     // at most 3 pairings at once: the newest
            uasort($tickets, fn($a, $b) => $b['exp'] <=> $a['exp']);
            $tickets = array_slice($tickets, 0, 3, true);
        }
        // queues of devices that were removed go too
        foreach (array_keys((array)($st['devices'] ?? [])) as $id) { if (!isset($devices[$id])) { q_delete('d-' . $id); } }
        foreach (array_keys((array)($st['tickets'] ?? [])) as $id) { if (!isset($tickets[$id])) { q_delete('t-' . $id); } }
        $st['devices'] = $devices ?: new stdClass();
        $st['tickets'] = $tickets ?: new stdClass();
        save_state($st);
        out(['ok' => true]);
    }

    /* ---------- 4d. send: leave sealed messages for the other side ---------- */
    case 'send': {
        lock_box();
        [$st, $w] = need_who();
        $to = (string)($in['to'] ?? '');
        if ($w[0] === 'c') {
            if (preg_match('/^d:([a-f0-9]{16,32})$/', $to, $m) && isset($st['devices'][$m[1]])) { $q = 'd-' . $m[1]; }
            elseif (preg_match('/^t:([a-f0-9]{16,32})$/', $to, $m) && isset($st['tickets'][$m[1]])) { $q = 't-' . $m[1]; }
            else { fail(404, 'to', 'No such device'); }
        } else {
            $q = 'c';        // devices only ever write to the computer
        }
        $items = $in['items'] ?? null;
        if (!is_array($items) || !$items || count($items) > 50) {
            fail(400, 'items', 'Nothing to send');
        }
        $data = q_read($q);
        $now = time();
        $data['items'] = array_values(array_filter($data['items'], fn($it) => $now - ($it['t'] ?? 0) < RL_KEEP_SECS));
        $bytes = array_sum(array_map(fn($it) => strlen((string)$it['d']), $data['items']));
        $from = $w[0] === 'c' ? 'c' : $w[0] . ':' . $w[1];
        $ids = [];
        foreach ($items as $d) {
            if (!is_string($d) || strlen($d) > RL_MAX_ITEM) {
                fail(413, 'big', 'A message is too large');
            }
            $bytes += strlen($d);
            if ($bytes > RL_MAX_QUEUE || count($data['items']) >= RL_MAX_ITEMS) {
                fail(507, 'full', 'The mailbox is full — is Seal Chat running on the computer?');
            }
            $id = (int)$data['next'];
            $data['next'] = $id + 1;
            $data['items'][] = ['id' => $id, 't' => $now, 'f' => $from, 'd' => $d];
            $ids[] = $id;
        }
        q_write($q, $data);
        out(['ok' => true, 'ids' => $ids, 'cSeen' => (int)($st['cSeen'] ?? 0), 'now' => $now]);
    }

    /* ---------- 4e. recv: pick up messages (waits up to ~25 s for new ones) ---------- */
    case 'recv': {
        [$st, $w] = need_who();
        $q = $w[0] === 'c' ? 'c' : $w[0] . '-' . $w[1];
        $ack = max(0, (int)($in['ack'] ?? 0));
        $wait = max(0, min(RL_MAX_WAIT, (int)($in['wait'] ?? 0)));
        lock_box();
        $st = state();

        // ---- 1.3.2: which of your linked computers answers the phone (the lease) ----
        $took = null;
        $node = (string)($in['node'] ?? '');
        if ($w[0] === 'c' && preg_match('/^[A-Za-z0-9_\-]{8,64}$/', $node)) {
            $now = time();
            $L = is_array($st['lease'] ?? null) ? $st['lease'] : null;
            $heldByOther = $L && ($L['by'] ?? '') !== $node && (int)($L['until'] ?? 0) > $now && empty($L['rel']);
            $view = array_values(array_filter((array)($in['view'] ?? []), $idOk));
            if ($heldByOther && empty($in['force'])) {
                // another of your computers is answering: wait (no messages, cSeen etc. untouched)
                out(['ok' => true, 'items' => [], 'standby' => ['by' => (string)$L['by'], 'until' => (int)$L['until'], 'view' => array_values((array)($L['view'] ?? []))], 'cSeen' => (int)($st['cSeen'] ?? 0), 'now' => $now]);
            }
            if ($L && !empty($L['rel']) && ($L['by'] ?? '') !== $node && empty($in['claim']) && empty($in['force'])) {
                // it was given back on purpose (the phone went quiet): this one goes idle too
                out(['ok' => true, 'items' => [], 'released' => true, 'cSeen' => (int)($st['cSeen'] ?? 0), 'now' => $now]);
            }
            if ($L && ($L['by'] ?? '') !== $node) {
                // taking over: where the previous one was, so nothing is answered twice
                $took = ['seq' => (int)($L['seq'] ?? 0), 'ack' => (int)($L['ack'] ?? 0)];
                $ack = max($ack, $took['ack']);
            }
            $st['lease'] = ['by' => $node, 'until' => $now + $wait + LS_EXTRA, 'rel' => false, 'seq' => max(0, (int)($in['seq'] ?? 0)), 'ack' => $ack, 'view' => array_slice($view, 0, 20)];
        }

        $data = q_read($q);
        $before = count($data['items']);
        $data['items'] = array_values(array_filter($data['items'], fn($it) => $it['id'] > $ack && time() - ($it['t'] ?? 0) < RL_KEEP_SECS));
        if (count($data['items']) !== $before) {
            q_write($q, $data);
        }
        if ($w[0] === 'c') {             // "the computer is listening right now (until cUntil)"
            $st['cSeen'] = time();
            $st['cUntil'] = time() + $wait;
            // …and plans to keep listening until cIdleAt (then it goes back to the wake file)
            $st['cIdleAt'] = min(time() + 3600, max(0, (int)($in['until'] ?? 0)));
            save_state($st);
        }
        unlock_box();

        $deadline = microtime(true) + $wait;
        $seen = (string)@file_get_contents(n_file($q));
        while (!$data['items'] && microtime(true) < $deadline) {
            usleep(500000);
            clearstatcache();
            $now = (string)@file_get_contents(n_file($q));
            if ($now !== $seen) {
                $seen = $now;
                $data = q_read($q);
                $data['items'] = array_values(array_filter($data['items'], fn($it) => $it['id'] > $ack));
            }
            if (connection_status() !== CONNECTION_NORMAL) {
                exit;
            }
        }
        // hand out at most ~5 MB at once; the rest comes with the next request
        $outItems = [];
        $bytes = 0;
        foreach ($data['items'] as $it) {
            $bytes += strlen($it['d']);
            if ($outItems && $bytes > 5 * 1024 * 1024) {
                break;
            }
            $outItems[] = ['id' => $it['id'], 'f' => $it['f'], 'd' => $it['d']];
        }
        $cSeen = $w[0] === 'c' ? time() : (int)(state()['cSeen'] ?? 0);
        $res = ['ok' => true, 'items' => $outItems, 'cSeen' => $cSeen, 'now' => time()];
        if ($took) {
            $res['took'] = $took;                                  // 1.3.2
        }
        out($res);
    }

    /* ---------- 4f. wake: the web interface asks Seal Chat to start listening ---------- */
    case 'wake': {
        [$st, $w] = need_who();
        if ($w[0] === 'c') {
            fail(403, 'role', 'Only devices wake the computer');
        }
        // nothing to change if the computer is listening right now AND will keep
        // listening a while (otherwise it could stop just before seeing our message)
        $listening = (int)($st['cUntil'] ?? 0) > time() + 1 && (int)($st['cIdleAt'] ?? 0) > time() + 8;
        if (!$listening) {
            touch_wake($st);
        }
        out(['ok' => true, 'cSeen' => (int)($st['cSeen'] ?? 0), 'now' => time(), 'woke' => !$listening]);
    }

    /* =================================================================
     * 5. MAILBOX (1.3.0)
     * ================================================================= */
    /* ---------- 5a. mbox_set: the computer turns its mailbox on (hash of the drop token) or off (null) ---------- */
    case 'mbox_set': {
        lock_box();
        [$st, $w] = need_who();
        if ($w[0] !== 'c') {
            fail(403, 'role', 'Only the computer can do that');
        }
        $h = $in['drop'] ?? null;
        if ($h === null) {
            unset($st['mdrop']);
            q_delete('m');                                     // off: what was waiting goes too
        } elseif ($hashOk($h)) {
            $st['mdrop'] = $h;
        } else {
            fail(400, 'drop', 'Bad drop token');
        }
        save_state($st);
        out(['ok' => true, 'mailbox' => isset($st['mdrop']), 'keepDays' => MB_KEEP_SECS / 86400, 'maxItem' => MB_MAX_ITEM]);
    }

    /* ---------- 5b. mbox_drop: a contact leaves a sealed message (needs the drop token) ---------- */
    case 'mbox_drop': {
        if (!is_dir($dir)) {
            usleep(300000);
            fail(404, 'nombox', 'No mailbox here (it may have been turned off)');
        }
        lock_box();
        $st = state();
        $drop = (string)($_SERVER['HTTP_X_SC_DROP'] ?? ($in['drop'] ?? ''));
        if (!$st || empty($st['mdrop']) || !preg_match('/^[A-Za-z0-9_\-]{20,200}$/', $drop) || !hash_equals((string)$st['mdrop'], hash('sha256', $drop))) {
            usleep(300000);
            fail(404, 'nombox', 'No mailbox here (it may have been turned off)');
        }
        $d = $in['d'] ?? null;
        if (!is_string($d) || $d === '' || strlen($d) > MB_MAX_ITEM || !preg_match('/^[A-Za-z0-9+\/=]+$/', $d)) {
            fail(413, 'big', 'That message is too large for the mailbox');
        }
        $data = q_read('m');
        $now = time();
        $data['items'] = array_values(array_filter($data['items'], fn($it) => $now - ($it['t'] ?? 0) < MB_KEEP_SECS));
        $recent = count(array_filter($data['items'], fn($it) => $now - ($it['t'] ?? 0) < 60));
        if ($recent >= MB_PER_MINUTE) {
            fail(429, 'slow', 'Too many messages at once — try again in a minute');
        }
        $bytes = array_sum(array_map(fn($it) => strlen((string)$it['d']), $data['items'])) + strlen($d);
        if ($bytes > MB_MAX_QUEUE || count($data['items']) >= MB_MAX_ITEMS) {
            fail(507, 'full', 'This mailbox is full');
        }
        $id = (int)$data['next'];
        $data['next'] = $id + 1;
        $data['items'][] = ['id' => $id, 't' => $now, 'd' => $d];
        q_write('m', $data);
        touch_wake($st);                                       // a running computer notices at once
        out(['ok' => true, 'id' => $id, 'now' => $now]);
    }

    /* ---------- 5c. mbox_get: the computer picks up (and with "ack", deletes what it already has) ---------- */
    case 'mbox_get': {
        lock_box();
        [$st, $w] = need_who();
        if ($w[0] !== 'c') {
            fail(403, 'role', 'Only the computer can do that');
        }
        $ack = max(0, (int)($in['ack'] ?? 0));
        $data = q_read('m');
        $now = time();
        $before = count($data['items']);
        $data['items'] = array_values(array_filter($data['items'], fn($it) => $it['id'] > $ack && $now - ($it['t'] ?? 0) < MB_KEEP_SECS));
        if (count($data['items']) !== $before) {
            q_write('m', $data);
        }
        $outItems = [];
        $bytes = 0;
        foreach ($data['items'] as $it) {
            $bytes += strlen($it['d']);
            if ($outItems && $bytes > 4 * 1024 * 1024) {
                break;
            }
            $outItems[] = ['id' => $it['id'], 't' => $it['t'], 'd' => $it['d']];
        }
        out(['ok' => true, 'items' => $outItems, 'more' => count($outItems) < count($data['items']), 'mailbox' => isset($st['mdrop']), 'now' => $now]);
    }

    /* =================================================================
     * 6. LINKED DEVICES SHARING ONE MAILBOX (1.3.2)
     * ================================================================= */
    /* ---------- 6a. release: the computer answering the phone goes back to idle ---------- */
    case 'release': {
        lock_box();
        [$st, $w] = need_who();
        if ($w[0] !== 'c') {
            fail(403, 'role', 'Only the computer can do that');
        }
        $node = (string)($in['node'] ?? '');
        $L = is_array($st['lease'] ?? null) ? $st['lease'] : null;
        if ($L && ($L['by'] ?? '') === $node) {
            $st['lease']['rel'] = true;
            $st['lease']['until'] = time();
            $st['cUntil'] = 0;                               // the phone's next "wake" wakes your computers again
            $st['cIdleAt'] = 0;
            save_state($st);
        }
        out(['ok' => true]);
    }

    /* ---------- 6b. rekey: a new computer token (after you removed one of your devices) ---------- */
    case 'rekey': {
        lock_box();
        [$st, $w] = need_who();
        if ($w[0] !== 'c') {
            fail(403, 'role', 'Only the computer can do that');
        }
        $tok = (string)($in['token'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_\-]{30,200}$/', $tok)) {
            fail(400, 'token', 'Bad token');
        }
        $st['ctok'] = hash('sha256', $tok);
        unset($st['lease']);
        save_state($st);
        out(['ok' => true]);
    }

    default:
        fail(400, 'action', 'Unknown action');
}
