<?php
/* =====================================================================
 * Seal Chat Web — index.php   (the web interface, 1.1.1 · 1.2.0 · 1.2.1)
 * ---------------------------------------------------------------------
 * The page you open on your phone (or any browser). It is only a remote
 * screen for Seal Chat running on YOUR computer: messages are read and
 * sent there, and travel between the two sealed (see crypto.js).
 * On an iPhone: Share → "Add to Home Screen" makes it open like an app.
 *
 * Files in this folder:
 *   index.php    this page (screens: start, pairing, PIN, chats)
 *   app.js       what the screens do
 *   client.js    talking to your computer through relay.php
 *   crypto.js    the encryption
 *   relay.php    passes sealed messages (can't read them)
 *   admin.php    your setup page (on/off, connection link, computers)
 *   lib.php, config.php   shared code + the one file to edit by hand
 *   sw.js        (1.1.0) shows notifications when Seal Chat Web is closed
 *   emoji-picker.js/.css, vendor/emoji-data.js   (1.1.1) the 😊 picker,
 *                :shortcode suggestions and big emoji-only messages
 *   app.css, manifest.webmanifest, img/, vendor/jsQR.js (QR scanning)
 *   wake/        the small "wake" files Seal Chat watches
 *   webupdate.php  (1.2.1) checking for / installing a newer Seal Chat
 *                Web from the phone's ⋯ → Updates (install = admin password)
 *
 * Sections:
 *   1. Settings + security headers
 *   2. Page head (phone / home-screen app settings)
 *   3. Screens: start · pair · unlock · chats
 *   4. Pages over the chats (1.1.0): notifications · add a contact ·
 *      contact details · my Seal Chat ID · (1.2.0) a file opened ·
 *      (1.2.1) updates
 *   5. Menu, QR scanner, toast (1.2.0: "update available" line)
 *   6. (1.2.0) After the page is sent: look for a Seal Chat Web update
 *      (at most every 12 hours; the phone doesn't wait for it)
 * ===================================================================== */

define('SEALCHAT_WEB', true);
require __DIR__ . '/lib.php';

/* =================================================================
 * 1. SETTINGS + HEADERS
 * ================================================================= */
$cfg = sc_config();
$relay = sc_relay();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');          // the pairing secret is in the address — never pass it on
header('X-Frame-Options: DENY');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; style-src 'self'; script-src 'self'; connect-src 'self'; manifest-src 'self'; form-action 'none'; frame-ancestors 'none'; base-uri 'none'; object-src 'none'");
header('Permissions-Policy: camera=(self), microphone=(), geolocation=()');
$https = sc_is_https() || sc_is_localhost();
$v = h(SEALCHAT_WEB_VERSION);
$webUpdate = sc_update_available();   // 1.2.0: from the last check only — no waiting here

/* =================================================================
 * 2. HEAD
 * ================================================================= */
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#121418">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<!-- 1.1.3: an OPAQUE status bar in the Home Screen app — iOS keeps the page out of the status bar
     area entirely (no content under the bar, so nothing is blurred by it) -->
<meta name="apple-mobile-web-app-status-bar-style" content="black">
<meta name="apple-mobile-web-app-title" content="Seal Chat">
<meta name="format-detection" content="telephone=no">
<title>Seal Chat</title>
<link rel="manifest" href="manifest.webmanifest">
<link rel="icon" type="image/png" href="img/icon-192.png">
<link rel="apple-touch-icon" href="img/apple-touch-icon.png">
<link rel="stylesheet" href="app.css?v=<?= $v ?>">
<link rel="stylesheet" href="emoji-picker.css?v=<?= $v ?>">
</head>
<body>

<?php /* =============================================================
       * 3. SCREENS
       * ============================================================= */ ?>

<?php if (!$https || !$relay['enabled']): ?>
<!-- web access is switched off on this website, or not over https -->
<section class="screen center">
  <div class="card">
    <img class="logo" src="img/icon-192.png" alt="">
    <h1>Seal Chat</h1>
    <?php if (!$https): ?>
      <p>Open this page with <b>https://</b> at the start of the address.</p>
    <?php else: ?>
      <p>The web interface is turned off.</p>
      <p class="muted small">Its owner can turn it on in <a href="admin.php">admin.php</a>.</p>
    <?php endif; ?>
  </div>
</section>
</body></html>
<?php exit; endif; ?>

<!-- 3a. start: this browser isn't paired yet -->
<section id="scrStart" class="screen center hidden">
  <div class="card">
    <img class="logo" src="img/icon-192.png" alt="">
    <h1>Seal Chat on this device</h1>
    <p class="muted">Read and send your Seal Chat messages here. Seal Chat keeps running on your computer and does the real work — your key never leaves it.</p>
    <ol class="steps">
      <li>On your computer: Seal Chat → <b>Settings → Web access</b>, turn it on, then <b>Pair a device…</b></li>
      <li>Scan the QR code it shows <span id="ssCamHint">with this device's camera</span>.</li>
    </ol>
    <button id="ssScan" class="btn primary wide">Scan the QR code</button>
    <details class="more"><summary>Or paste the pairing link</summary>
      <div class="row gap"><input id="ssLink" class="grow" placeholder="https://…/#pair=…" autocomplete="off" autocapitalize="off" spellcheck="false"><button id="ssGo" class="btn">Go</button></div>
    </details>
    <p id="ssHome" class="tip hidden">Tip: tap <b>Share</b> → <b>Add to Home Screen</b> first and pair from the new icon — it then opens like an app, and Safari can't clear it after a week without use.</p>
  </div>
</section>

<!-- 3b. pair: name + PIN, then compare the code -->
<section id="scrPair" class="screen center hidden">
  <div class="card">
    <img class="logo" src="img/icon-192.png" alt="">
    <h1>Pair this device</h1>
    <div id="prForm">
      <label>Name for this device<input id="prName" maxlength="40" autocomplete="off"></label>
      <label>Choose a PIN (4–12 digits)<input id="prPin" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="12" autocomplete="new-password"></label>
      <label>Repeat the PIN<input id="prPin2" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="12" autocomplete="new-password"></label>
      <p class="muted small">You type this PIN each time you open Seal Chat here. Your computer checks it — after 10 wrong PINs this device is removed.</p>
      <button id="prGo" class="btn primary wide">Pair</button>
    </div>
    <div id="prWait" class="hidden">
      <div class="spinner"></div>
      <p id="prStatus">Contacting your computer…</p>
      <div id="prCodeBox" class="hidden">
        <p class="muted small">Your computer shows a code. If it is the same as this one, click <b>Approve</b> there:</p>
        <div id="prCode" class="code"></div>
      </div>
    </div>
    <div class="error" id="prErr"></div>
    <button id="prCancel" class="link">Cancel</button>
  </div>
</section>

<!-- 3c. unlock: PIN, then wake the computer -->
<section id="scrLock" class="screen center hidden">
  <div class="card">
    <img class="logo" src="img/icon-192.png" alt="">
    <h1>Seal Chat</h1>
    <p class="muted small" id="lkName"></p>
    <form id="lkForm" autocomplete="off">
      <input id="lkPin" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="12" placeholder="PIN" autocomplete="current-password" aria-label="PIN">
      <button id="lkGo" class="btn primary wide">Unlock</button>
    </form>
    <div id="lkWait" class="hidden">
      <div class="spinner"></div>
      <p id="lkStatus">Connecting to your computer…</p>
      <p id="lkHint" class="muted small hidden">Seal Chat on your computer looks for the web interface every few seconds (Settings → Web access). It has to be on, awake and running Seal Chat with web access turned on.</p>
      <button id="lkStop" class="btn">Stop</button>
    </div>
    <div class="error" id="lkErr"></div>
    <button id="lkUnpair" class="link">Unpair this device…</button>
  </div>
</section>

<!-- 3d. chats -->
<section id="scrMain" class="screen hidden">
  <aside id="side">
    <header class="side-head">
      <img src="img/icon-192.png" alt="" class="brand-icon">
      <div class="grow">
        <b id="meName">Seal Chat</b>
        <div id="netState" class="net"><i class="dot"></i><span>Connected</span></div>
      </div>
      <button id="btnAdd" class="icon-btn" aria-label="Add a contact">＋</button>
      <button id="btnMenu" class="icon-btn" aria-label="Menu">⋯</button>
    </header>
    <input id="search" class="search" placeholder="Search" autocomplete="off" type="search">
    <div id="contactList" class="contact-list"></div>
  </aside>
  <main id="pane">
    <div id="emptyPane" class="empty-pane">
      <img src="img/icon-192.png" alt="" class="logo dim">
      <p class="muted">Choose a chat.</p>
    </div>
    <div id="chatPane" class="chat-pane hidden">
      <header class="chat-head">
        <button id="btnBack" class="icon-btn back" aria-label="Back">‹</button>
        <div id="chAvatar"></div>
        <button id="chTitle" class="grow ch-title" aria-label="Contact details"><span class="ch-name-row"><b id="chName"></b><span id="chSealId" class="sid-pill hidden"></span></span><div id="chSub" class="muted small"></div></button>
        <button id="btnInfo" class="icon-btn" aria-label="Contact details">ⓘ</button>
      </header>
      <div id="msgList" class="msg-list"></div>
      <!-- 1.3.0: shown while replying to a message -->
      <div id="replyBar" class="reply-bar hidden"><span class="grow" id="replyText"></span><button type="button" id="replyCancel" class="icon-btn" aria-label="Cancel reply">✕</button></div>
      <form id="composer" class="composer" autocomplete="off">
        <!-- 1.2.0: 📎 send a photo / video / file (shown when your computer has Seal Chat 1.7.0+) -->
        <button id="btnAttach" type="button" class="icon-btn attach-btn hidden" aria-label="Send a photo or file">📎</button>
        <input id="fileInput" type="file" multiple class="hidden" tabindex="-1" aria-hidden="true">
        <button id="btnEmoji" type="button" class="icon-btn emoji-btn" aria-label="Emoji">😊</button>
        <textarea id="msgInput" rows="1" placeholder="Message" enterkeyhint="send"></textarea>
        <button id="btnSend" class="btn primary send" aria-label="Send">➤</button>
      </form>
    </div>
  </main>
</section>

<?php /* =============================================================
       * 4. PAGES OVER THE CHATS (1.1.0)
       *    Full-screen on a phone, a centred panel on a wide screen.
       * ============================================================= */ ?>

<!-- 4a. notifications on this device -->
<section id="pgNotify" class="page hidden" aria-label="Notifications">
  <header class="page-head"><button class="icon-btn back" data-close aria-label="Back">‹</button><h2>Notifications</h2></header>
  <div class="page-body">
    <p class="muted">Get a notification on this device when a message or contact request arrives while Seal Chat isn't open here.</p>
    <div id="ntState" class="state-card"></div>
    <div id="ntIos" class="tip hidden">On iPhone and iPad, notifications only work in the <b>Home Screen</b> version: in Safari tap <b>Share</b> → <b>Add to Home Screen</b>, open Seal Chat from the new icon and turn them on there. (iOS 16.4 or newer.)</div>
    <button id="ntOn" class="btn primary wide">Turn on notifications</button>
    <label class="switch-row" id="ntNamesRow"><span><b>Show who it's from</b><br><span class="muted small">"New message from Alice" instead of just "New message". The message itself is never shown.</span></span><input type="checkbox" id="ntNames"></label>
    <button id="ntTest" class="btn wide">Send a test notification</button>
    <button id="ntOff" class="btn wide danger">Turn off on this device</button>
    <p class="muted small">How it works: Seal Chat on your computer sends the notification, encrypted for this browser only, through <span id="ntSvc">your phone maker's push service</span>. It can't read the words. You can also switch notifications for this device off in Seal Chat → Settings → Web access.</p>
  </div>
</section>

<!-- 4b. add a contact: scan · photo · paste -->
<section id="pgAdd" class="page hidden" aria-label="Add a contact">
  <header class="page-head"><button class="icon-btn back" data-close aria-label="Back">‹</button><h2>Add a contact</h2></header>
  <div class="page-body">
    <p class="muted">Add someone with their Seal Chat ID (scan their QR code or paste the ID), their name if they have one (like ann@example.com), or an invite code they gave you (like 482-crystal-orange-river). Your computer checks the key at their address before sending your request.</p>
    <button id="adScan" class="btn primary wide">📷 Scan their QR code</button>
    <label class="btn wide file-btn">🖼 Use a photo of their QR code<input id="adPhoto" type="file" accept="image/*" capture="environment"></label>
    <label>Or paste their Seal Chat ID, or type their name or invite code<textarea id="adId" rows="3" placeholder="sealchat:…:…   or   ann@example.com   or   482-crystal-orange-river" autocomplete="off" autocapitalize="off" spellcheck="false"></textarea></label>
    <div id="adCheck" class="state-card hidden"></div>
    <label>Name for them (optional — only you see it)<input id="adNick" maxlength="80" autocomplete="off"></label>
    <div class="error" id="adErr"></div>
    <button id="adGo" class="btn primary wide" disabled>Send contact request</button>
  </div>
</section>

<!-- 4c. my Seal Chat ID (for someone else to scan) -->
<section id="pgMe" class="page hidden" aria-label="My Seal Chat ID">
  <header class="page-head"><button class="icon-btn back" data-close aria-label="Back">‹</button><h2>My Seal Chat ID</h2></header>
  <div class="page-body center">
    <p class="muted">Let someone scan this with their Seal Chat — on their computer's camera or their phone — or send them the ID.</p>
    <div class="qr-box"><img id="meQr" alt="Your Seal Chat ID as a QR code"><div id="meQrWait" class="spinner"></div></div>
    <p id="meHandle" class="me-handle hidden"></p>
    <p id="meIdText" class="id-text selectable"></p>
    <div class="row gap two-btn"><button id="meCopy" class="btn grow">Copy ID</button><button id="meShare" class="btn primary grow hidden">Share…</button></div>
    <div class="error" id="meErr"></div>
    <p class="muted small">Tip: turn your screen brightness up so the code scans easily. Your ID includes your key's fingerprint, so nobody else can use it to pretend to be you.</p>
  </div>
</section>

<!-- 4e. 1.2.0: a photo / video / file opened from a chat -->
<section id="pgFile" class="page hidden" aria-label="File">
  <header class="page-head"><button class="icon-btn back" data-close aria-label="Back">‹</button><h2 id="fvTitle">File</h2></header>
  <div class="page-body center file-view">
    <div id="fvBox" class="fv-box"></div>
    <p id="fvInfo" class="muted small"></p>
    <div class="row gap two-btn"><button id="fvSave" class="btn grow">Save</button><button id="fvShare" class="btn primary grow hidden">Share…</button></div>
    <p id="fvRisky" class="error hidden">This is a program or script file. Only open it if you trust it and expected it.</p>
  </div>
</section>

<!-- 4f. 1.2.1: updates for Seal Chat Web itself (the files on your website) -->
<section id="pgUpdate" class="page hidden" aria-label="Updates">
  <header class="page-head"><button class="icon-btn back" data-close aria-label="Back">‹</button><h2>Updates</h2></header>
  <div class="page-body">
    <p class="muted">Updates for Seal Chat Web — the files on your website. They come from GitHub and are only installed if they carry Seal Chat's signature. Your config.php and settings are kept.</p>
    <div id="upState" class="state-card"></div>
    <p id="upNote" class="muted small"></p>
    <button id="upCheck" class="btn wide">Check now</button>
    <div id="upInstallBox" class="hidden">
      <label class="f">Admin password (the one for the setup page)
        <input id="upPass" type="password" autocomplete="current-password" enterkeyhint="go"></label>
      <button id="upGo" class="btn primary wide">Update now</button>
    </div>
    <div class="error" id="upErr"></div>
    <p class="muted small">Seal Chat on your computer updates itself separately (Settings → About &amp; updates). "Undo the last update" is on the setup page, admin.php.</p>
  </div>
</section>

<!-- 4d. one contact: requests, rename, safety number, mute, block, remove -->
<section id="pgContact" class="page hidden" aria-label="Contact">
  <header class="page-head"><button class="icon-btn back" data-close aria-label="Back">‹</button><h2 id="ctTitle">Contact</h2></header>
  <div class="page-body">
    <div class="ct-top"><div id="ctAvatar"></div><div class="grow"><b id="ctName"></b><div id="ctSub" class="muted small"></div></div></div>
    <div id="ctRequest" class="hidden">
      <p class="tip"><b id="ctReqName"></b> wants to chat with you. Accept only if you know who this is — check their safety number with them below.</p>
      <button id="ctAccept" class="btn primary wide">Accept</button>
      <div class="row gap two-btn"><button id="ctDecline" class="btn grow">Decline</button><button id="ctBlock2" class="btn grow danger">Block</button></div>
    </div>
    <div id="ctFailed" class="hidden"><p class="tip" id="ctFailText"></p><button id="ctRetry" class="btn wide">Try again</button></div>
    <button id="ctChat" class="btn primary wide hidden">Open chat</button>
    <label>Name (only you see it)<div class="row gap"><input id="ctNick" class="grow" maxlength="80" autocomplete="off"><button id="ctNickSave" class="btn">Save</button></div></label>
    <div id="ctSafety" class="hidden">
      <h3 class="sub-h">Safety number</h3>
      <p class="muted small">The same 60 digits appear on both your Seal Chats. Compare them by phone or in person — if they match, nobody is in the middle.</p>
      <div id="ctSafeNum" class="safety selectable"></div>
      <label class="switch-row"><span><b>Verified</b><br><span class="muted small">I compared the safety number with them.</span></span><input type="checkbox" id="ctVerified"></label>
    </div>
    <!-- 1.4.0: their Seal ID, as checked by your computer -->
    <div id="ctSealId" class="hidden">
      <h3 class="sub-h">Seal ID</h3>
      <div id="ctSidBody"></div>
    </div>
    <label class="switch-row" id="ctMuteRow"><span><b>Mute</b><br><span class="muted small">No notifications for this chat (here or on your computer).</span></span><input type="checkbox" id="ctMuted"></label>
    <details class="more"><summary>Their Seal Chat ID and key</summary><p id="ctIdText" class="id-text selectable small"></p><p id="ctKey" class="muted small"></p></details>
    <div class="ct-danger">
      <button id="ctBlock" class="btn wide danger">Block</button>
      <button id="ctUnblock" class="btn wide hidden">Unblock</button>
      <button id="ctRemove" class="btn wide danger">Remove contact</button>
    </div>
  </div>
</section>

<?php /* =============================================================
       * 5. MENU, QR SCANNER, TOAST
       * ============================================================= */ ?>
<!-- pop-up menu, toast, QR scanner -->
<div id="menu" class="sheet hidden">
  <div class="sheet-box">
    <button data-act="addContact" class="sheet-item">Add a contact</button>
    <button data-act="myId" class="sheet-item">My Seal Chat ID</button>
    <button data-act="notify" class="sheet-item">Notifications <span id="mnNotify" class="muted"></span></button>
    <button data-act="refresh" class="sheet-item">Refresh</button>
    <button data-act="lock" class="sheet-item">Lock</button>
    <!-- 1.2.1: Updates — check for / install a newer Seal Chat Web (installing asks for the admin password) -->
    <button data-act="updates" class="sheet-item<?= $webUpdate ? ' sheet-update' : '' ?>" id="mnUpdates"><?= $webUpdate ? 'Update available: Seal Chat Web ' . h($webUpdate['version']) : 'Updates' ?></button>
    <button data-act="unpair" class="sheet-item danger">Unpair this device…</button>
    <button data-act="close" class="sheet-item muted">Cancel</button>
  </div>
</div>
<!-- 1.3.0: tap a message → react / reply / copy -->
<div id="msgSheet" class="sheet hidden">
  <div class="sheet-box">
    <div class="react-row" id="msReacts"></div>
    <button data-mact="reply" class="sheet-item">↩ Reply</button>
    <button data-mact="copy" class="sheet-item">Copy text</button>
    <button data-mact="unreact" class="sheet-item hidden" id="msUnreact">Remove my reaction</button>
    <button data-mact="close" class="sheet-item muted">Cancel</button>
  </div>
</div>
<div id="scanner" class="scanner hidden">
  <div class="scan-box">
    <h2 id="scanTitle">Scan the pairing QR code</h2>
    <div class="scan-view"><video id="scanVideo" playsinline muted></video><div class="scan-frame"></div></div>
    <p id="scanMsg" class="muted small">Point the camera at the QR code on your computer's screen.</p>
    <button id="scanClose" class="btn wide">Cancel</button>
  </div>
</div>
<div id="toast" class="toast hidden"></div>

<script src="crypto.js?v=<?= $v ?>"></script>
<script src="client.js?v=<?= $v ?>"></script>
<script src="emoji-picker.js?v=<?= $v ?>"></script>
<script src="app.js?v=<?= $v ?>"></script>
</body>
</html>
<?php
/* =================================================================
 * 6. UPDATE CHECK AFTER THE PAGE IS SENT (1.2.0)
 * The page is already on the phone; only then (and only when the last
 * check is 12+ hours old) does the server ask for a newer version.
 * Hosting without "finish request" skips this — admin.php checks then.
 * ================================================================= */
$ucLast = (int)(sc_read_json('update-check.json', [])['checked'] ?? 0);
if (time() - $ucLast >= SC_UPDATE_EVERY) {
    if (function_exists('litespeed_finish_request')) { litespeed_finish_request(); sc_update_check(true); }
    elseif (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); sc_update_check(true); }
}
