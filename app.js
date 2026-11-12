/* =====================================================================
   Seal Chat Web — app.js   (1.1.1: emoji — section 6 · 1.1.2: status-bar guard — section 1 · 1.2.0: files — section 13)
   ---------------------------------------------------------------------
   The screens. Talking to your computer is in client.js; encryption in
   crypto.js. Nothing here is sent anywhere except sealed, to your own
   computer through relay.php.

   Sections:
     1. Helpers (escaping, times, toast, screens)
     2. This device's saved pairing (IndexedDB)
     3. Start + pairing (link or QR scan)
     4. Unlock with PIN (wakes your computer)
     5. Contacts
     6. A chat: history, bubbles, sending
     7. Live updates from your computer
     8. Menu, back button, background / auto-lock
     9. Pages over the chats (1.1.0): open / close, Android back
    10. Notifications on this device (1.1.0)
    11. Contacts (1.1.0): add (scan / photo / paste), my ID, one contact
    12. Start-up
    13. Files (1.2.0): send from the phone, previews, open / save
    14. Updates for Seal Chat Web itself (1.2.1)
    15. Replies + reactions (1.3.0, needs Seal Chat 1.8.0+)
   ===================================================================== */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var client = null;
  var me = { name: '' };
  var contacts = [];
  var currentId = null;
  var messages = [];
  var more = false;
  var pairInfo = null;
  var pushState = { on: false, names: false };   // this device's notifications, as your computer has them (1.1.0)
  var canFiles = false, fileMax = 100 * 1048576;
  var canReact = false;                           // 1.3.0: your computer has Seal Chat 1.8.0+ (replies + reactions)  // 1.2.0: your computer has Seal Chat 1.7.0+ (files to / from this device)
  var AUTO_LOCK_MS = 5 * 60000;      // locked again after 5 minutes in the background
  var PING_MS = 60000;               // keeps your computer listening while the page is open

  /* =================================================================
     1. HELPERS
     ================================================================= */
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function linkify(text) {
    return esc(text).replace(/\bhttps?:\/\/[^\s<>"']+[^\s<>"'.,;:!?)\]]/g, function (u) { return '<a href="' + u + '" target="_blank" rel="noopener noreferrer">' + u + '</a>'; });
  }
  function timeOf(ts) { return new Date(ts).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }); }
  function sameDay(a, b) { return new Date(a).toDateString() === new Date(b).toDateString(); }
  function dayLabel(ts) {
    var d = new Date(ts), now = new Date(), y = new Date(); y.setDate(now.getDate() - 1);
    if (sameDay(d, now)) return 'Today';
    if (sameDay(d, y)) return 'Yesterday';
    return d.toLocaleDateString([], { weekday: 'short', month: 'short', day: 'numeric', year: d.getFullYear() === now.getFullYear() ? undefined : 'numeric' });
  }
  function shortWhen(ts) {
    if (!ts) return '';
    var d = new Date(ts), now = new Date();
    if (sameDay(d, now)) return timeOf(ts);
    if (now - d < 6 * 86400000) return d.toLocaleDateString([], { weekday: 'short' });
    return d.toLocaleDateString([], { month: 'short', day: 'numeric' });
  }
  function fmtSize(n) { n = Number(n) || 0; if (n < 1024) return n + ' B'; if (n < 1048576) return (n / 1024).toFixed(0) + ' KB'; if (n < 1073741824) return (n / 1048576).toFixed(1) + ' MB'; return (n / 1073741824).toFixed(2) + ' GB'; }

  var toastTimer;
  function toast(text, bad) {
    var t = $('toast'); t.textContent = text; t.className = 'toast' + (bad ? ' bad' : '');
    clearTimeout(toastTimer); toastTimer = setTimeout(function () { t.classList.add('hidden'); }, bad ? 5000 : 2600);
  }
  var SCREENS = ['scrStart', 'scrPair', 'scrLock', 'scrMain'];
  function show(id) { SCREENS.forEach(function (s) { $(s).classList.toggle('hidden', s !== id); }); }

  /** Initials on a colour from the contact's fingerprint, or their picture. */
  function avatarHtml(c, cls) {
    var initials = (c.name || '?').trim().split(/\s+/).map(function (w) { var m = /[\p{L}\p{N}]/u.exec(w); return m ? m[0] : ''; }).join('').slice(0, 2).toUpperCase() || '?';
    var color = 'c' + (parseInt(String(c.id || '0').slice(-4), 16) % 9 || 0);
    var pres = '<i class="pres' + (c.online ? ' on' : '') + '"></i>';
    if (c.avatar && /^data:image\/(jpeg|png);base64,/.test(c.avatar)) return '<div class="avatar ' + color + ' ' + (cls || '') + '"><img src="' + c.avatar + '" alt="">' + pres + '</div>';
    return '<div class="avatar ' + color + ' ' + (cls || '') + '">' + esc(initials) + pres + '</div>';
  }

  /** A short name for this device, e.g. "iPhone". */
  function deviceName() {
    var ua = navigator.userAgent;
    if (/iPhone/.test(ua)) return 'iPhone';
    if (/iPad/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1)) return 'iPad';
    if (/Android/.test(ua)) return /Mobile/.test(ua) ? 'Android phone' : 'Android tablet';
    if (/Macintosh/.test(ua)) return 'Mac browser';
    if (/Windows/.test(ua)) return 'Windows browser';
    if (/Linux|X11/.test(ua)) return 'Linux browser';
    return 'Browser';
  }
  var isIos = /iPhone|iPad/.test(navigator.userAgent) || (/Macintosh/.test(navigator.userAgent) && navigator.maxTouchPoints > 1);
  var standalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;
  var touch = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;

  /* ---------- 1.1.2: keep the top of the page clear of the iPhone's status bar ----------
     The page is drawn edge to edge (viewport-fit=cover) and normally iOS reports the
     status bar's height as env(safe-area-inset-top). Some iOS versions report 0 even
     though the page is under the status bar (Home Screen app, iOS 26 Safari), which put
     the name, icon and ＋ under the blurred bar. Then use the status bar's real height. */
  function statusBarGuard() {
    if (!isIos) return;
    var probe = document.createElement('div');
    probe.style.position = 'fixed'; probe.style.visibility = 'hidden'; probe.style.paddingTop = 'env(safe-area-inset-top)';
    document.body.appendChild(probe);
    var reported = parseFloat(getComputedStyle(probe).paddingTop) || 0;
    probe.remove();
    var portrait = window.innerHeight > window.innerWidth;
    // 1.1.3: judged only by where the page really starts (the Home Screen app now has an opaque
    // status bar, so there the page starts BELOW it and no extra room is added)
    var underBar = window.innerHeight >= screen.height - 30;   // the page starts at the very top of the screen
    var h = Math.max(screen.width, screen.height);
    // status bar heights: Dynamic Island 59–62 pt (iPhone 14 Pro and later / 17), notch 44–47 pt, older 20 pt
    var bar = h >= 874 ? 62 : h >= 852 ? 59 : h >= 812 ? 47 : 20;
    document.documentElement.style.setProperty('--safe-t-min', reported < 1 && portrait && underBar ? bar + 'px' : '0px');
  }
  statusBarGuard();
  window.addEventListener('resize', statusBarGuard);
  window.addEventListener('orientationchange', function () { setTimeout(statusBarGuard, 300); });

  /* =================================================================
     2. SAVED PAIRING (IndexedDB, this browser only)
     The device key is saved as a key object the page can use but never
     read out. (A browser that can't store key objects keeps the raw key.)
     ================================================================= */
  var storage = {
    db: function () {
      return new Promise(function (res, rej) {
        var r = indexedDB.open('sealchat-web', 1);
        r.onupgradeneeded = function () { r.result.createObjectStore('kv'); };
        r.onsuccess = function () { res(r.result); };
        r.onerror = function () { rej(r.error); };
      });
    },
    tx: function (mode, fn) {
      return storage.db().then(function (db) {
        return new Promise(function (res, rej) {
          var t = db.transaction('kv', mode), out;
          out = fn(t.objectStore('kv'));
          t.oncomplete = function () { res(out && out.result); db.close(); };
          t.onerror = t.onabort = function () { rej(t.error); db.close(); };
        });
      });
    },
    load: async function () {
      var rec = await storage.tx('readonly', function (s) { return s.get('device'); });
      if (rec && !rec.key && rec.keyRaw) rec.key = await SCCrypto.aesKey(new Uint8Array(rec.keyRaw));
      return rec || null;
    },
    save: async function (rec) {
      var copy = Object.assign({}, rec);
      delete copy.keyRaw;
      try { await storage.tx('readwrite', function (s) { return s.put(copy, 'device'); }); }
      catch (e) {
        // this browser can't store key objects → store the key bytes instead
        if (!rec.keyRaw) throw e;
        copy.key = null; copy.keyRaw = Array.from(rec.keyRaw);
        await storage.tx('readwrite', function (s) { return s.put(copy, 'device'); });
      }
      delete rec.keyRaw;
    },
    clear: function () { return storage.tx('readwrite', function (s) { return s.delete('device'); }); }
  };

  function newClient() {
    var c = new SCClient({ base: new URL('.', location.href).href, storage: storage });
    c.on('state', onNetState);
    c.on('push', onPush);
    c.on('revoked', onRevoked);
    return c;
  }

  /* =================================================================
     3. START + PAIRING
     ================================================================= */
  function showStart() {
    show('scrStart');
    $('ssHome').classList.toggle('hidden', !(isIos && !standalone));
    $('ssCamHint').textContent = standalone || !isIos ? 'with the button below' : "with your iPhone's Camera app, or the button below";
  }

  /** A pairing link arrived (address bar, paste or QR scan). */
  async function startPairing(hashOrLink) {
    var p = SCClient.parsePair(hashOrLink);
    if (!p) {
      if (/^sealchat:/i.test(String(hashOrLink).trim())) return toast('That is a Seal Chat ID (for adding contacts), not a pairing code. Use Settings → Web access → Pair a device… on your computer.', true);
      return toast('That is not a Seal Chat pairing link', true);
    }
    var existing = await storage.load().catch(function () { return null; });
    if (existing && !confirm('This device is already paired. Pair it again? (The old pairing stops working here.)')) return;
    pairInfo = p;
    show('scrPair');
    $('prForm').classList.remove('hidden'); $('prWait').classList.add('hidden'); $('prErr').textContent = '';
    $('prName').value = deviceName();
    $('prPin').value = $('prPin2').value = '';
    setTimeout(function () { $('prPin').focus(); }, 50);
  }

  $('prGo').onclick = async function () {
    $('prErr').textContent = '';
    var pin = $('prPin').value.trim(), name = $('prName').value.trim() || deviceName();
    if (!/^\d{4,12}$/.test(pin)) { $('prErr').textContent = 'The PIN must be 4 to 12 digits'; return; }
    if (pin !== $('prPin2').value.trim()) { $('prErr').textContent = 'The two PINs are different'; return; }
    $('prForm').classList.add('hidden'); $('prWait').classList.remove('hidden'); $('prCodeBox').classList.add('hidden');
    $('prStatus').textContent = 'Contacting your computer…';
    client = newClient();
    try {
      await client.pair(pairInfo, name, pin, function (code) {
        $('prCode').textContent = code;
        $('prCodeBox').classList.remove('hidden');
        $('prStatus').textContent = 'Waiting for you to approve on your computer…';
      });
      pairInfo = null;
      toast('Paired');
      unlockWith(pin);
    } catch (e) {
      $('prWait').classList.add('hidden'); $('prForm').classList.remove('hidden');
      $('prErr').textContent = e.message;
    }
  };
  $('prCancel').onclick = function () { pairInfo = null; boot(); };

  // start screen: paste / scan
  $('ssGo').onclick = function () { startPairing($('ssLink').value); };
  $('ssScan').onclick = function () { scanQr(); };   // (no options = the pairing code)

  /* ---------- QR scanner (camera, this page only) ---------- */
  var scan = null;
  function loadJsQr() {
    if (window.jsQR) return Promise.resolve();
    return new Promise(function (res, rej) { var s = document.createElement('script'); s.src = 'vendor/jsQR.js'; s.onload = res; s.onerror = function () { rej(new Error('Could not load the QR reader')); }; document.head.appendChild(s); });
  }
  /** opts (1.1.0): { title, hint, onCode(text), noCamera } — default: the pairing QR code */
  async function scanQr(opts) {
    opts = opts || {};
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { if (opts.noCamera) return opts.noCamera(); return toast('This browser cannot use the camera here. Paste the pairing link instead.', true); }
    $('scanTitle').textContent = opts.title || 'Scan the pairing QR code';
    $('scanMsg').textContent = opts.hint || "Point the camera at the QR code on your computer's screen.";
    try {
      await loadJsQr();
      var stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
      var v = $('scanVideo');
      v.srcObject = stream; await v.play();
      $('scanner').classList.remove('hidden');
      var cv = document.createElement('canvas'), ctx = cv.getContext('2d', { willReadFrequently: true });
      scan = { stream: stream, timer: setInterval(function () {
        if (!v.videoWidth) return;
        var w = Math.min(800, v.videoWidth), h = Math.round(v.videoHeight * w / v.videoWidth);
        cv.width = w; cv.height = h; ctx.drawImage(v, 0, 0, w, h);
        var code = window.jsQR(ctx.getImageData(0, 0, w, h).data, w, h, { inversionAttempts: 'dontInvert' });
        if (code && code.data) { stopScan(); (opts.onCode || startPairing)(code.data); }
      }, 250) };
    } catch (e) {
      stopScan();
      if (opts.noCamera) return opts.noCamera(e);
      toast(e && e.name === 'NotAllowedError' ? 'Camera permission was refused. Allow it in Settings, or paste the pairing link.' : 'Camera: ' + (e.message || e), true);
    }
  }
  /** 1.1.0: reads a QR code from a photo (for when the live camera isn't available). Resolves the text or null. */
  async function qrFromPhoto(file) {
    await loadJsQr();
    var url = URL.createObjectURL(file);
    try {
      var img = await new Promise(function (res, rej) { var i = new Image(); i.onload = function () { res(i); }; i.onerror = function () { rej(new Error('That picture could not be opened')); }; i.src = url; });
      var cv = document.createElement('canvas'), ctx = cv.getContext('2d', { willReadFrequently: true });
      var sizes = [1600, 1000, 640];                           // big photos: try a few sizes (QR codes read best at a moderate size)
      for (var k = 0; k < sizes.length; k++) {
        var sc = Math.min(1, sizes[k] / Math.max(img.naturalWidth, img.naturalHeight));
        cv.width = Math.round(img.naturalWidth * sc); cv.height = Math.round(img.naturalHeight * sc);
        ctx.drawImage(img, 0, 0, cv.width, cv.height);
        var code = window.jsQR(ctx.getImageData(0, 0, cv.width, cv.height).data, cv.width, cv.height, { inversionAttempts: 'attemptBoth' });
        if (code && code.data) return code.data;
      }
      return null;
    } finally { URL.revokeObjectURL(url); }
  }
  function stopScan() {
    if (scan) { clearInterval(scan.timer); scan.stream.getTracks().forEach(function (t) { t.stop(); }); scan = null; }
    $('scanner').classList.add('hidden');
  }
  $('scanClose').onclick = stopScan;

  /* =================================================================
     4. UNLOCK (PIN → wake your computer → welcome)
     ================================================================= */
  var hintTimer;
  function showLock(msg) {
    show('scrLock');
    $('lkForm').classList.remove('hidden'); $('lkWait').classList.add('hidden');
    $('lkErr').textContent = msg || '';
    $('lkName').textContent = client && client.rec ? 'Paired as "' + client.rec.name + '"' : '';
    $('lkPin').value = '';
    if (!touch) setTimeout(function () { $('lkPin').focus(); }, 50);
  }
  $('lkForm').onsubmit = function (e) {
    e.preventDefault();
    var pin = $('lkPin').value.trim();
    if (!/^\d{4,12}$/.test(pin)) { $('lkErr').textContent = 'Enter your PIN (4–12 digits)'; return; }
    unlockWith(pin);
  };
  async function unlockWith(pin) {
    show('scrLock');
    $('lkForm').classList.add('hidden'); $('lkWait').classList.remove('hidden'); $('lkErr').textContent = '';
    $('lkStatus').textContent = 'Connecting to your computer…';
    $('lkHint').classList.add('hidden');
    clearTimeout(hintTimer);
    hintTimer = setTimeout(function () { $('lkHint').classList.remove('hidden'); }, 25000);
    try {
      var w = await client.unlock(pin);
      clearTimeout(hintTimer);
      me = w.me || me;
      contacts = w.contacts || [];
      pushState = w.push || { on: false, names: false };
      canFiles = (w.can || []).indexOf('files') >= 0;            // 1.2.0
      canReact = (w.can || []).indexOf('react') >= 0;            // 1.3.0
      if (w.fileMax) fileMax = w.fileMax;
      $('btnAttach').classList.toggle('hidden', !canFiles);
      enterMain();
      syncPush();
    } catch (e) {
      clearTimeout(hintTimer);
      if (e.message === 'Locked') return;
      client.stop();
      showLock(e.message);
    }
  }
  $('lkStop').onclick = function () { clearTimeout(hintTimer); client.lock(); client = rebuild(client); showLock(''); };
  $('lkUnpair').onclick = unpair;

  /** A fresh client with the same saved pairing. */
  function rebuild(old) { var c = newClient(); c.rec = old && old.rec; return c; }

  /* =================================================================
     5. CONTACTS
     ================================================================= */
  function enterMain() {
    show('scrMain');
    $('meName').textContent = me.name || 'Seal Chat';
    renderContacts();
    if (currentId) openChat(currentId, true);
    startPing();
  }

  function renderContacts() {
    var q = $('search').value.trim().toLowerCase();
    var list = contacts.filter(function (c) { return !q || (c.name || '').toLowerCase().indexOf(q) >= 0; });
    list.sort(function (a, b) { return (b.lastAt || 0) - (a.lastAt || 0); });
    if (!list.length) { $('contactList').innerHTML = q ? '<p class="muted small pad">No match.</p>' : '<div class="pad center"><p class="muted">No contacts yet.</p><button class="btn primary" data-act-add>Add a contact</button></div>'; return; }
    $('contactList').innerHTML = list.map(function (c) {
      var last = c.status === 'pending-in' ? 'Wants to chat — tap to accept or decline'
        : c.status === 'pending-out' ? 'Request sent' : c.status === 'failed' ? 'Request not delivered — tap for details' : c.status === 'blocked' ? 'Blocked' : (c.lastDir === 'out' ? 'You: ' : '') + (c.lastText || '');
      var sid = sidState(c);                                // 1.4.0: Seal ID mark
      var mark = !sid || sid.kind === 'checking' || sid.kind === 'bad' ? ''
        : sid.kind === 'ok' ? '<img class="sid-mark" src="img/sealid.svg" alt="Seal ID" title="' + esc(sid.title) + '">'
        : '<span class="sid-mark warn" title="' + esc(sid.title) + '">⚠</span>';
      return '<button class="contact' + (c.id === currentId ? ' active' : '') + (c.status === 'pending-in' || c.status === 'failed' ? ' request' : '') + '" data-id="' + esc(c.id) + '">'
        + avatarHtml(c) + '<span class="meta"><span class="top"><span class="nm">' + esc(c.name) + '</span>' + mark + '<span class="when">' + esc(shortWhen(c.lastAt)) + '</span></span>'
        + '<span class="bottom"><span class="last">' + esc(last) + '</span>' + (c.unread ? '<span class="unread">' + c.unread + '</span>' : '') + '</span></span></button>';
    }).join('');
  }
  $('contactList').onclick = function (e) {
    if (e.target.closest('[data-act-add]')) return openPage('pgAdd');
    var b = e.target.closest('.contact'); if (!b) return;
    var c = contacts.find(function (x) { return x.id === b.dataset.id; });
    if (c && (c.status === 'pending-in' || c.status === 'failed')) return openPage('pgContact', c.id);   // 1.1.0: requests are handled here
    openChat(b.dataset.id);
  };
  $('search').oninput = renderContacts;

  /* =================================================================
     6. A CHAT
     ================================================================= */
  var narrow = function () { return window.matchMedia('(max-width: 760px)').matches; };

  async function openChat(id, keep, noPush) {
    var c = contacts.find(function (x) { return x.id === id; });
    if (!c) return;
    if (!keep && !noPush && narrow() && currentId !== id) history.pushState({ chat: id }, '');
    currentId = id;
    $('scrMain').classList.add('in-chat');
    $('emptyPane').classList.add('hidden'); $('chatPane').classList.remove('hidden');
    renderHead(c);
    renderContacts();
    if (!keep) endReply();                                  // 1.3.0
    var canSend = c.status === 'accepted' || c.status === 'pending-out';
    $('msgInput').disabled = $('btnSend').disabled = $('btnEmoji').disabled = $('btnAttach').disabled = !canSend;
    $('msgInput').placeholder = canSend ? 'Message' : c.status === 'pending-in' ? 'Accept their request first (ⓘ)' : 'You can\'t message this contact';
    if (!keep) { messages = []; $('msgList').innerHTML = '<p class="muted small center pad">Loading…</p>'; }
    try {
      var h = await client.ask('history', { cid: id });
      if (currentId !== id) return;
      messages = h.msgs || []; more = !!h.more;
      renderMessages(true);
      markRead();
    } catch (e) { toast(e.message, true); }
  }

  function renderHead(c) {
    $('chAvatar').innerHTML = avatarHtml(c, 'sm');
    $('chName').textContent = c.name;
    $('chSub').textContent = c.status === 'pending-out' ? 'Request sent — waiting for them to accept' : c.online ? 'Online' : c.lastSeen ? 'Last seen ' + shortWhen(c.lastSeen) : '';
    var sid = sidState(c), pill = $('chSealId');             // 1.4.0: Seal ID in the header
    pill.classList.toggle('hidden', !sid);
    if (sid) {
      pill.className = 'sid-pill ' + sid.kind;
      pill.title = sid.title;
      pill.innerHTML = (sid.kind === 'ok' ? '<img src="img/sealid.svg" alt="">' : sid.kind === 'warn' ? '<span>⚠</span>' : '') + '<span class="sid-t">' + esc(sid.label) + '</span>';
      pill.setAttribute('aria-label', sid.label);
    }
  }

  /* 1.4.0: a contact's Seal ID, as your computer checked it (Seal Chat 1.11.0+).
     ok = it proves this contact · warn = it was replaced · bad = not proven · checking = not checked yet */
  var SID_LABELS = { web: 'Website', dns: 'Domain', github: 'GitHub', x: 'X', mastodon: 'Mastodon', bluesky: 'Bluesky', hackernews: 'Hacker News', reddit: 'Reddit', youtube: 'YouTube', facebook: 'Facebook' };
  function sidHost(u) { try { return new URL(u).host; } catch (e) { return u || ''; } }
  function sidGood(p) { return p.status === 'verified' || p.status === 'witnessed'; }
  function sidState(c) {
    var s = c && c.sealid;
    if (!s || !s.url) return null;
    var host = sidHost(s.url);
    if (s.warning) return { kind: 'warn', label: 'Seal ID changed', title: c.name + '\'s Seal ID ' + host + ' changed: ' + s.warning };
    if (s.ok) {
      var also = (s.proofs || []).filter(sidGood).map(function (p) { return (SID_LABELS[p.service] || p.service) + ' ' + p.handle; });
      return { kind: 'ok', label: host, title: 'Seal ID ' + s.url + ' proves this is ' + (s.name || c.name) + '.'
        + (also.length ? ' Also proven there: ' + also.slice(0, 6).join(', ') + (also.length > 6 ? ', …' : '') + '.' : '') };
    }
    if (s.checked || s.error) return { kind: 'bad', label: 'Seal ID not proven', title: host + ': ' + (s.why || s.error || 'it does not prove this Seal Chat ID.') };
    return { kind: 'checking', label: 'Seal ID · checking…', title: 'Your computer is checking ' + host + '.' };
  }
  function sidDetailsHtml(c) {
    var s = c.sealid, sid = sidState(c);
    var badge = sid.kind === 'ok' ? '<span class="badge ok">✔ Proven</span>' : sid.kind === 'warn' ? '<span class="badge warn">⚠ Changed</span>'
      : sid.kind === 'bad' ? '<span class="badge warn">Not proven</span>' : '<span class="muted small">checking…</span>';
    var proofs = (s.proofs || []).map(function (p) {
      return '<li>' + esc(SID_LABELS[p.service] || p.service) + ': <span class="selectable">' + esc(p.handle) + '</span> '
        + (sidGood(p) ? '<span class="badge ok">✔</span>' : '<span class="badge">' + esc(p.status) + '</span>') + '</li>';
    }).join('');
    return '<p class="sid-addr"><a href="' + esc(s.url) + '" target="_blank" rel="noopener noreferrer">' + esc(sidHost(s.url)) + '</a> ' + badge + '</p>'
      + (s.name ? '<p class="muted small">Name there: ' + esc(s.name) + '</p>' : '')
      + (sid.kind === 'ok' ? '<p class="muted small">' + (s.how === 'same-key' ? 'Signed with the same key as their Seal Chat.' : 'Their Seal Chat key signed it.') + '</p>' : '')
      + (s.warning ? '<p class="tip warn-tip">⚠ ' + esc(s.warning) + ' Check with them another way. To trust the new Seal ID, open their contact details in Seal Chat on your computer.</p>' : '')
      + (sid.kind === 'bad' && (s.why || s.error) ? '<p class="muted small">' + esc(s.why || s.error) + '</p>' : '')
      + (proofs ? '<p class="muted small">Also proven there (as their Seal ID reports):</p><ul class="sid-proofs small">' + proofs + '</ul>' : '')
      + ((s.aliases || []).length ? '<p class="muted small">Their domains: ' + esc(s.aliases.join(' · ')) + '</p>' : '')
      + '<p class="muted small">' + (s.checked ? 'Checked by your computer ' + esc(shortWhen(s.checked)) + '. ' : '') + 'Your computer checks it again once a day.</p>';
  }

  function closeChat() {
    if (emoji) emoji.hide();
    currentId = null;
    $('scrMain').classList.remove('in-chat');
    $('chatPane').classList.add('hidden'); $('emptyPane').classList.remove('hidden');
    renderContacts();
  }
  $('btnBack').onclick = function () { if (history.state && history.state.chat) history.back(); else closeChat(); };
  // (a page over the chat closes first — section 9; 1.2.0: going back FROM a page to the chat keeps the chat open)
  window.addEventListener('popstate', function () { if (currentId && narrow() && !openPg && !(history.state && history.state.chat)) closeChat(); });

  function markRead() {
    if (!currentId || document.hidden) return;
    var c = contacts.find(function (x) { return x.id === currentId; });
    if (c && c.unread) { c.unread = 0; renderContacts(); }
    client.ask('read', { cid: currentId }).catch(function () {});
  }

  function tick(m) {
    if (m.dir !== 'out') return '';
    if (m.state === 'sending' || m.state === 'uploading') return '<span class="tick">…</span>';
    if (m.state === 'failed') return '<span class="tick bad">!</span>';
    if (m.state === 'queued') return '<span class="tick" title="Waiting to send — goes when they are online">⏱</span>';
    if (m.state === 'mailbox') return '<span class="tick" title="Left in their mailbox — they get it when their Seal Chat starts">📬</span>';   // 1.3.0
    if (m.state === 'read') return '<span class="tick read">✓✓</span>';
    return '<span class="tick">✓</span>';
  }

  function bubble(m, same) {
    var body;
    if (m.kind === 'file' && m.file) {
      body = fileBody(m) + (m.text ? '<div class="txt">' + linkify(m.text) + '</div>' : '');   // 1.2.0: section 13
    } else body = '<div class="txt">' + linkify(m.text) + '</div>';
    body = quoteHtml(m) + body;                                                  // 1.3.0: a reply shows what it answers
    var jumbo = m.kind !== 'file' && !m.re && window.SCEmoji && SCEmoji.isJumbo(m.text);   // 1.1.1: 1–3 emoji only → shown big
    return '<div class="bubble ' + m.dir + (jumbo ? ' jumbo' : '') + (m.state === 'queued' || m.state === 'sending' ? ' queued' : '') + (m.state === 'failed' ? ' failed' : '') + (same ? ' same' : '') + '" data-id="' + esc(m.id) + '">' + body
      + '<div class="ft">' + (m.state === 'failed' ? '<span class="bad">Not sent · tap to retry</span>' : '') + '<span>' + timeOf(m.at) + '</span>' + tick(m) + '</div>' + reactsHtml(m) + '</div>';
  }

  function renderMessages(toEnd) {
    var list = $('msgList');
    var nearBottom = list.scrollHeight - list.scrollTop - list.clientHeight < 120;
    var html = more ? '<button id="loadMore" class="btn small center-btn">Show earlier messages</button>' : '';
    if (!messages.length) html += '<p class="muted small center pad">No messages yet.</p>';
    var prev = null;
    messages.forEach(function (m) {
      if (!prev || !sameDay(prev.at, m.at)) html += '<div class="day">' + esc(dayLabel(m.at)) + '</div>';
      var same = prev && prev.dir === m.dir && m.at - prev.at < 120000 && sameDay(prev.at, m.at);
      html += bubble(m, same);
      prev = m;
    });
    list.innerHTML = html;
    if (toEnd || nearBottom) list.scrollTop = list.scrollHeight;
    var lm = $('loadMore');
    if (lm) lm.onclick = loadEarlier;
  }

  async function loadEarlier() {
    if (!messages.length) return;
    var id = currentId, list = $('msgList'), h0 = list.scrollHeight;
    try {
      var h = await client.ask('history', { cid: id, before: messages[0].id });
      if (currentId !== id) return;
      messages = (h.msgs || []).concat(messages); more = !!h.more;
      renderMessages(false);
      list.scrollTop = list.scrollHeight - h0;
    } catch (e) { toast(e.message, true); }
  }

  // tap a failed bubble to send it again
  $('msgList').onclick = function (e) {
    var fa = e.target.closest('[data-fact]');                   // 1.2.0: a file's button / preview (section 13)
    if (fa) return fileAct(fa.dataset.fact, fa.closest('.bubble').dataset.id);
    var q = e.target.closest('[data-jump]');                    // 1.3.0: tap a quote → the original
    if (q) return jumpTo(q.dataset.jump);
    var mb = e.target.closest('.bubble');                       // 1.3.0: tap a message → react / reply
    if (mb && !mb.classList.contains('failed') && !e.target.closest('a,button,img,video,audio')) { var mm = findMsg(mb.dataset.id); if (mm && !/^(tmp|up)/.test(mm.id)) return openMsgSheet(mm); }
    var b = e.target.closest('.bubble.failed');
    if (b && b.querySelector('.file')) return;
    if (!b) return;
    var m = messages.find(function (x) { return x.id === b.dataset.id; });
    if (m) { messages.splice(messages.indexOf(m), 1); sendText(m.text); }
  };

  var tmpN = 0;
  async function sendText(text) {
    var cid = currentId;
    var tmp = { id: 'tmp' + (++tmpN), dir: 'out', text: text, at: Date.now(), state: 'sending' };
    if (replyTo) { tmp.reId = replyTo.id; tmp.re = { id: replyTo.id, dir: replyTo.dir, snip: snipOf(replyTo) }; endReply(); }   // 1.3.0
    messages.push(tmp); renderMessages(true);
    try {
      var r = await client.ask('send', { cid: cid, text: text, tmp: tmp.id, re: tmp.reId || undefined });
      var i = messages.indexOf(tmp);
      if (messages.some(function (x) { return x.id === r.msg.id; })) { if (i >= 0) messages.splice(i, 1); }   // already arrived as a live update
      else if (i >= 0) messages[i] = r.msg;
      var c = contacts.find(function (x) { return x.id === cid; });
      if (c) { c.lastAt = r.msg.at; c.lastText = text; c.lastDir = 'out'; }
    } catch (e) {
      tmp.state = 'failed';
      toast(e.message, true);
    }
    if (currentId === cid) renderMessages(false);
    renderContacts();
  }

  $('composer').onsubmit = function (e) {
    e.preventDefault();
    var t = $('msgInput').value;
    if (!t.trim() || !currentId) return;
    $('msgInput').value = ''; autoSize();
    sendText(t);
    if (!touch) $('msgInput').focus();
  };
  // 1.1.1: emoji — 😊 picker (a bottom sheet on a phone), ":smile" suggestions (emoji-picker.js)
  var emoji = window.SCEmoji ? SCEmoji.attach({ input: $('msgInput'), button: $('btnEmoji'), dataUrl: 'vendor/emoji-data.js', mobile: touch, onError: function (e) { toast(e.message, true); } }) : null;
  function autoSize() { var t = $('msgInput'); t.style.height = 'auto'; t.style.height = Math.min(140, t.scrollHeight + 2) + 'px'; }
  $('msgInput').oninput = autoSize;
  $('msgInput').onkeydown = function (e) {
    // computer keyboard: Enter sends, Shift+Enter = new line. Phones: the Send button.
    if (e.key === 'Enter' && !e.shiftKey && !touch && !e.isComposing) { e.preventDefault(); $('composer').requestSubmit(); }
  };

  /* =================================================================
     7. LIVE UPDATES
     ================================================================= */
  function onPush(o) {
    if (o.t === 'contacts' && Array.isArray(o.list)) {
      contacts = o.list;
      if (currentId && document.visibilityState === 'visible') { var c = contacts.find(function (x) { return x.id === currentId; }); if (c) { renderHead(c); if (c.unread) markRead(); } }
      renderContacts();
      contactChanged();   // 1.1.0: the contact page follows changes made on your computer
    } else if (o.t === 'msg' && o.msg) {
      if (o.cid === currentId) {
        var at = messages.findIndex(function (x) { return x.id === o.msg.id; });
        if (at < 0 && o.msg.kind === 'file' && o.msg.dir === 'out') at = messages.findIndex(function (x) { return x.upName && x.upDone && x.upName === o.msg.file.name; });   // 1.2.0: our upload, finished
        if (at >= 0) messages[at] = o.msg;
        else if (!messages.some(function (x) { return x.state === 'sending' && x.text === o.msg.text && o.msg.dir === 'out'; })) messages.push(o.msg);
        renderMessages(false);
        if (o.msg.dir === 'in') markRead();
      }
    } else if (o.t === 'push' && o.push) {            // 1.1.0: switched on/off in Settings on your computer
      pushState = o.push; renderNotify();
    } else if (o.t === 'states' && o.cid === currentId) {
      var map = {};
      (o.states || []).forEach(function (s) { map[s[0]] = s[1]; });
      messages.forEach(function (m) { if (map[m.id]) m.state = map[m.id]; });
      renderMessages(false);
    }
  }

  function onNetState(s) {
    var el = $('netState');
    var text = { online: 'Connected to your computer', waking: 'Waking your computer…', 'computer-quiet': 'Your computer is not answering', 'no-website': 'No connection to the website', 'relay-off': 'The web interface is turned off (admin.php)' }[s] || s;
    el.className = 'net ' + (s === 'online' ? 'on' : s === 'waking' ? 'wait' : 'bad');
    el.querySelector('span').textContent = text;
    if (!$('scrLock').classList.contains('hidden') && !$('lkWait').classList.contains('hidden')) {
      $('lkStatus').textContent = s === 'waking' ? 'Waking your computer… (it checks every few seconds)' : s === 'no-website' ? 'No connection to the website — retrying…' : s === 'relay-off' ? 'The web interface is turned off (admin.php)' : 'Connecting to your computer…';
    }
  }

  async function onRevoked(why) {
    try { await storage.clear(); } catch (e) { /* ignore */ }
    if (client) client.stop();
    client = null; contacts = []; currentId = null;
    showStart();
    toast(why === 'pin' ? 'Too many wrong PINs — this device was removed. Pair it again from your computer.' : why === 'nobox' ? 'Your computer disconnected from this website. Pair again after turning web access back on.' : 'This device was removed in Seal Chat on your computer.', true);
  }

  /* =================================================================
     8. MENU, BACKGROUND, AUTO-LOCK
     ================================================================= */
  $('btnMenu').onclick = function () { $('mnNotify').textContent = pushState.on ? '· on' : ''; $('menu').classList.remove('hidden'); };
  $('menu').onclick = function (e) {
    var b = e.target.closest('[data-act]');
    if (!b && e.target !== $('menu')) return;
    $('menu').classList.add('hidden');
    var act = b && b.dataset.act;
    if (act === 'notify') openPage('pgNotify');
    if (act === 'updates') openPage('pgUpdate');          // 1.2.1
    if (act === 'addContact') openPage('pgAdd');
    if (act === 'myId') openPage('pgMe');
    if (act === 'refresh') refresh();
    if (act === 'lock') lockNow();
    if (act === 'unpair') unpair();
  };

  async function refresh() {
    try {
      var r = await client.ask('contacts', {});
      contacts = r.list || []; renderContacts();
      if (currentId) openChat(currentId, true);
    } catch (e) { toast(e.message, true); }
  }

  function lockNow() {
    stopPing();
    if (client) { client.lock(); client = rebuild(client); }
    contacts = []; messages = [];
    showLock('');
  }

  async function unpair() {
    if (!confirm('Unpair this device? You will need to pair it again from your computer to use Seal Chat here.')) return;
    try { if (client && client.sid) await client.tell('unpair', { sid: client.sid }); } catch (e) { /* computer offline: remove it there later */ }
    stopPing();
    if (client) client.stop();
    await storage.clear().catch(function () {});
    client = null; currentId = null;
    showStart();
    toast('This device is unpaired');
  }

  var pingTimer = null;
  function startPing() { stopPing(); pingTimer = setInterval(function () { if (client && client.sid && !document.hidden) client.ask('ping', {}, 90000).catch(function () {}); }, PING_MS); }
  function stopPing() { clearInterval(pingTimer); pingTimer = null; }

  // background: stop listening; after 5 minutes away, ask for the PIN again
  var hiddenAt = 0;
  document.addEventListener('visibilitychange', function () {
    if (!client || !client.rec) return;
    if (document.hidden) { hiddenAt = Date.now(); if (client.sid) client.stop(); return; }
    if (!client.sid) return;
    if (Date.now() - hiddenAt > AUTO_LOCK_MS) { lockNow(); return; }
    client.start();
    client.wake();
    refresh();
  });

  /* =================================================================
     9. PAGES OVER THE CHATS (1.1.0)
     One at a time; the phone's back gesture / button closes it.
     ================================================================= */
  var openPg = null;
  var PAGE_OPEN = { pgUpdate: function () { openUpdates(); }, pgNotify: function () { renderNotify(); }, pgAdd: function () { openAdd(); }, pgMe: function () { openMe(); }, pgContact: function (cid) { openContact(cid); } };
  function openPage(id, arg) {
    if (openPg && openPg !== id) $(openPg).classList.add('hidden');
    $(id).classList.remove('hidden');
    if (openPg !== id) history.pushState({ page: id }, '');
    openPg = id;
    if (PAGE_OPEN[id]) PAGE_OPEN[id](arg);
  }
  function closePage(fromHistory) {
    if (!openPg) return;
    var id = openPg; openPg = null;
    $(id).classList.add('hidden');
    if (PAGE_CLOSE[id]) PAGE_CLOSE[id]();
    if (!fromHistory && history.state && history.state.page === id) history.back();
  }
  var PAGE_CLOSE = {};
  document.addEventListener('click', function (e) { if (e.target.closest('[data-close]')) closePage(); });
  window.addEventListener('popstate', function () { if (openPg) closePage(true); });

  /* =================================================================
     10. NOTIFICATIONS ON THIS DEVICE (1.1.0)
     Your computer sends them (sealed for this browser) through the
     phone maker's push service; sw.js shows them.
     iPhone/iPad: only in the Home Screen app (iOS 16.4+).
     ================================================================= */
  function pushSupport() {
    if (isIos && !standalone) return 'ios-home';
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) return 'none';
    return 'ok';
  }
  function b64uBytes(s) { s = String(s).replace(/-/g, '+').replace(/_/g, '/'); var bin = atob(s + '==='.slice((s.length + 3) % 4)), out = new Uint8Array(bin.length); for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i); return out; }
  function bytesB64u(buf) { var b = new Uint8Array(buf), s = ''; for (var i = 0; i < b.length; i++) s += String.fromCharCode(b[i]); return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
  async function swReg() {
    var reg = await navigator.serviceWorker.register('sw.js', { scope: './' });
    await navigator.serviceWorker.ready;
    return reg;
  }
  /** Subscribes this browser (asks the computer for its push key) and tells the computer. */
  async function subscribePush(names) {
    var reg = await swReg();
    var key = (await client.ask('pushKey', {})).key;
    var sub = await reg.pushManager.getSubscription();
    if (sub && sub.options && sub.options.applicationServerKey && bytesB64u(sub.options.applicationServerKey) !== key) { await sub.unsubscribe(); sub = null; }   // made for another computer
    if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64uBytes(key) });
    var r = await client.ask('pushOn', { sub: sub.toJSON(), names: names });
    pushState = r.push; return sub;
  }
  function renderNotify() {
    var sup = pushSupport(), perm = sup === 'ok' ? Notification.permission : 'default';
    $('ntIos').classList.toggle('hidden', sup !== 'ios-home');
    var st = $('ntState'), on = pushState.on && perm === 'granted';
    var paused = !pushState.on && pushState.endpointTail;
    st.className = 'state-card' + (on ? ' on' : perm === 'denied' ? ' bad' : '');
    st.innerHTML = sup === 'none' ? '<span class="big">🚫</span><span>This browser can\'t show notifications from websites.</span>'
      : sup === 'ios-home' ? '<span class="big">📲</span><span>Open Seal Chat from your Home Screen to turn notifications on.</span>'
      : perm === 'denied' ? '<span class="big">🔕</span><span>Notifications are blocked for this site. Allow them in your browser\'s or phone\'s settings, then come back here.</span>'
      : on ? '<span class="big">🔔</span><span><b>On</b> — you\'ll be notified when Seal Chat isn\'t open here.</span>'
      : paused ? '<span class="big">⏸</span><span><b>Paused</b> in Seal Chat on your computer. Turn on to start again.</span>'
      : '<span class="big">🔕</span><span><b>Off</b> on this device.</span>';
    $('ntOn').classList.toggle('hidden', sup !== 'ok' || on || perm === 'denied');
    $('ntOff').classList.toggle('hidden', !(pushState.on || paused));
    $('ntTest').classList.toggle('hidden', !on);
    $('ntNamesRow').classList.toggle('hidden', sup !== 'ok');
    $('ntNames').checked = !!pushState.names; $('ntNames').disabled = !on;
  }
  $('ntOn').onclick = async function () {
    var b = this; b.disabled = true;
    try {
      // must be asked straight from the tap (iPhone refuses otherwise)
      var perm = await Notification.requestPermission();
      if (perm !== 'granted') { renderNotify(); return toast(perm === 'denied' ? 'Notifications were blocked. Allow them in Settings for this site.' : 'Notifications not allowed', true); }
      await subscribePush($('ntNames').checked);
      renderNotify(); toast('Notifications are on');
    } catch (e) { toast('Could not turn notifications on: ' + (e.message || e), true); }
    finally { b.disabled = false; }
  };
  $('ntOff').onclick = async function () {
    try {
      var r = await client.ask('pushOff', {}); pushState = r.push;
      var reg = await navigator.serviceWorker.getRegistration('./');
      var sub = reg && await reg.pushManager.getSubscription();
      if (sub) await sub.unsubscribe();
      renderNotify(); toast('Notifications are off on this device');
    } catch (e) { toast(e.message, true); }
  };
  $('ntNames').onchange = async function () {
    try { var r = await client.ask('pushNames', { names: this.checked }); pushState = r.push; renderNotify(); toast('Saved'); }
    catch (e) { this.checked = !this.checked; toast(e.message, true); }
  };
  $('ntTest').onclick = async function () {
    var b = this; b.disabled = true;
    try { var r = await client.ask('pushTest', {}, 60000); toast(r.ok ? 'Sent — it should appear in a few seconds (lock the screen or leave the app to see it)' : 'Not sent: ' + r.error, !r.ok); }
    catch (e) { toast(e.message, true); }
    finally { b.disabled = false; }
  };
  /** After unlocking: keep the computer's record and this browser's subscription the same. */
  async function syncPush() {
    try {
      if (pushSupport() !== 'ok') return;
      var reg = await navigator.serviceWorker.getRegistration('./');
      var sub = reg && await reg.pushManager.getSubscription();
      if (pushState.on && Notification.permission !== 'granted') { pushState = (await client.ask('pushOff', {})).push; return; }   // blocked in the phone's settings
      if (pushState.on && (!sub || String(sub.endpoint).slice(-12) !== pushState.endpointTail)) await subscribePush(pushState.names);   // the browser renewed it
    } catch (e) { /* not important enough to bother you */ }
  }

  /* =================================================================
     11. CONTACTS (1.1.0)
     Your computer does the real work (the same as adding or accepting
     in Seal Chat there): it checks the key at their address, signs the
     request and keeps the contact list. Changes show on both sides.
     ================================================================= */
  $('btnAdd').onclick = function () { openPage('pgAdd'); };
  $('chTitle').onclick = $('btnInfo').onclick = function () { if (currentId) openPage('pgContact', currentId); };

  /* ---------- 11a. add a contact ---------- */
  var adValid = null, adTimer = null;
  function openAdd(prefill) {
    $('adId').value = prefill || ''; $('adNick').value = ''; $('adErr').textContent = '';
    $('adCheck').classList.add('hidden'); adValid = null; $('adGo').disabled = true;
    if (prefill) checkAddId();
  }
  async function checkAddId() {
    var text = $('adId').value.trim(), box = $('adCheck');
    adValid = null; $('adGo').disabled = true; $('adErr').textContent = '';
    if (!text) { box.classList.add('hidden'); return; }
    if (SCClient.parsePair(text)) { box.className = 'state-card bad'; box.innerHTML = '<span class="big">⚠</span><span>That is a pairing link for a device, not a Seal Chat ID.</span>'; return; }
    try {
      var r = await client.ask('checkId', { text: text });
      if ($('adId').value.trim() !== text) return;           // changed meanwhile
      box.classList.remove('hidden');
      if (r.invite) {                                      // 1.3.0: an invite code (used only when you press Add)
        box.className = 'state-card on';
        box.innerHTML = '<span class="big">🎟</span><span><b>Invite code ' + esc(r.invite) + '</b><br><span class="muted small">Press Add. The person who made it must have Seal Chat open. A wrong code uses one of its 3 tries.</span></span>';
        adValid = r.invite; $('adGo').disabled = false; return;
      }
      if (r.mine) { box.className = 'state-card bad'; box.innerHTML = '<span class="big">🙂</span><span>That is your own Seal Chat ID.</span>'; return; }
      if (r.existing) { box.className = 'state-card'; box.innerHTML = '<span class="big">👤</span><span>Already in your contacts as <b>' + esc(r.existing.name) + '</b>.</span>'; return; }
      box.className = 'state-card on';
      box.innerHTML = '<span class="big">✓</span><span><b>' + (r.handle ? esc(r.handle) + (r.display ? ' (' + esc(r.display) + ')' : '') : 'Valid Seal Chat ID') + '</b><br><span class="muted small">Key fingerprint ' + esc(r.prettyFp) + '</span></span>';
      adValid = r.handle || r.id; $('adGo').disabled = false;          // 1.3.0: a name is added as the name (your computer remembers it)
      if (r.display && !$('adNick').value) $('adNick').placeholder = r.display;
    } catch (e) { box.className = 'state-card bad'; box.classList.remove('hidden'); box.innerHTML = '<span class="big">✗</span><span>' + esc(e.message) + '</span>'; }
  }
  $('adId').oninput = function () { clearTimeout(adTimer); adTimer = setTimeout(checkAddId, /@/.test($('adId').value) ? 900 : 350); };
  function gotCode(text) {
    if (SCClient.parsePair(text)) return toast('That QR code is for pairing a device, not a contact\'s ID', true);
    $('adId').value = String(text).trim(); checkAddId();
  }
  $('adScan').onclick = function () {
    scanQr({ title: "Scan their Seal Chat QR code", hint: 'Point the camera at their QR code — on their computer screen or their phone.', onCode: gotCode,
      noCamera: function () { toast('The live camera isn\'t available here — use "Use a photo of their QR code" instead.', true); } });
  };
  $('adPhoto').onchange = async function () {
    var f = this.files && this.files[0]; this.value = '';
    if (!f) return;
    try { var t = await qrFromPhoto(f); if (t) gotCode(t); else toast('No QR code found in that picture — try again closer, with the whole code in view.', true); }
    catch (e) { toast(e.message, true); }
  };
  $('adGo').onclick = async function () {
    if (!adValid) return;
    var b = this; b.disabled = true; $('adErr').textContent = '';
    try {
      var isCode = /^\d{3}-/.test(adValid);
      if (isCode) $('adErr').textContent = 'Reaching the invite through Tor — this can take up to a minute…';
      var r = await client.ask('addContact', { id: adValid, nick: $('adNick').value.trim() }, 180000);
      $('adErr').textContent = '';
      if (r.contact && !contacts.some(function (x) { return x.id === r.contact.id; })) contacts.push(r.contact);
      renderContacts();
      closePage();
      toast(isCode ? 'Code accepted ✓' : 'Request sent — they appear as a contact once they accept');
    } catch (e) { $('adErr').textContent = e.message; b.disabled = false; }
  };

  /* ---------- 11b. my Seal Chat ID ---------- */
  var myIdText = '';
  async function openMe() {
    $('meErr').textContent = ''; $('meQr').removeAttribute('src'); $('meQrWait').classList.remove('hidden');
    $('meIdText').textContent = ''; myIdText = '';
    $('meShare').classList.toggle('hidden', !navigator.share);
    try {
      var r = await client.ask('myId', {});
      myIdText = r.id; $('meIdText').textContent = r.id;
      // 1.3.0: your name@domain (set in the desktop app's Settings), if you have one
      $('meHandle').textContent = r.handle ? 'Your name: ' + r.handle + ' — people can add you with just this' : '';
      $('meHandle').classList.toggle('hidden', !r.handle);
      $('meQr').src = r.qr;
    } catch (e) { $('meErr').textContent = e.message; }
    finally { $('meQrWait').classList.add('hidden'); }
  }
  $('meCopy').onclick = async function () {
    if (!myIdText) return;
    try { await navigator.clipboard.writeText(myIdText); toast('Copied'); }
    catch (e) { var r = document.createRange(); r.selectNodeContents($('meIdText')); var sel = getSelection(); sel.removeAllRanges(); sel.addRange(r); toast('Selected — tap Copy'); }
  };
  $('meShare').onclick = function () {
    if (myIdText) navigator.share({ title: 'My Seal Chat ID', text: myIdText }).catch(function () { /* cancelled */ });
  };

  /* ---------- 11c. one contact ---------- */
  var ctId = null, ctDet = null;
  async function openContact(cid) {
    ctId = cid; ctDet = null;
    var c = contacts.find(function (x) { return x.id === cid; });
    if (c) renderContact(c);
    try { ctDet = await client.ask('contactDetails', { cid: cid }); if (ctId === cid) renderContact(ctDet.contact || c); }
    catch (e) { toast(e.message, true); }
  }
  function statusText(c) {
    return { 'pending-in': 'Wants to chat with you', 'pending-out': 'Request sent — waiting for them to accept', failed: 'Request not delivered', blocked: 'Blocked', accepted: c.online ? 'Online' : c.lastSeen ? 'Last seen ' + shortWhen(c.lastSeen) : 'Contact' }[c.status] || c.status;
  }
  function renderContact(c) {
    if (!c) return;
    var d = ctDet || {};
    $('ctTitle').textContent = c.name; $('ctName').textContent = c.name;
    $('ctAvatar').innerHTML = avatarHtml(c, 'lg');
    $('ctSub').textContent = statusText(c) + (c.verified ? ' · ✓ verified' : '');
    var req = c.status === 'pending-in';
    $('ctRequest').classList.toggle('hidden', !req); $('ctReqName').textContent = c.name;
    $('ctFailed').classList.toggle('hidden', c.status !== 'failed');
    $('ctFailText').textContent = 'Your request could not be delivered' + (d.failReason || c.failReason ? ': ' + (d.failReason || c.failReason) : '.') + ' Check the ID with them and try again.';
    $('ctChat').classList.toggle('hidden', !(c.status === 'accepted' || c.status === 'pending-out') || currentId === c.id);
    if (document.activeElement !== $('ctNick')) $('ctNick').value = d.nick != null ? d.nick : '';
    $('ctNick').placeholder = d.theirName || c.name;
    $('ctSafety').classList.toggle('hidden', !d.safety);
    if (d.safety) $('ctSafeNum').innerHTML = d.safety.split(/\s+/).map(function (g) { return '<span>' + esc(g) + '</span>'; }).join('');
    $('ctVerified').checked = !!c.verified;
    $('ctSealId').classList.toggle('hidden', !sidState(c));       // 1.4.0
    if (sidState(c)) $('ctSidBody').innerHTML = sidDetailsHtml(c);
    $('ctMuteRow').classList.toggle('hidden', req); $('ctMuted').checked = !!c.muted;
    $('ctIdText').textContent = d.sealId || '';
    $('ctKey').textContent = d.key ? 'Key: ' + [d.key.name, d.key.email].filter(Boolean).join(' · ') + (d.key.created ? ' · made ' + new Date(d.key.created).toLocaleDateString() : '') : '';
    $('ctBlock').classList.toggle('hidden', c.status === 'blocked' || req);
    $('ctUnblock').classList.toggle('hidden', c.status !== 'blocked');
  }
  /** Something changed (here or on your computer): keep the open contact page right. */
  function contactChanged() {
    if (openPg !== 'pgContact' || !ctId) return;
    var c = contacts.find(function (x) { return x.id === ctId; });
    if (!c) { closePage(); return toast('That contact was removed'); }
    var was = ctDet && ctDet.contact;
    if (ctDet) ctDet.contact = c;
    renderContact(c);
    if (was && (was.status !== c.status)) openContact(ctId);   // status changed: fetch the details again (e.g. safety number after accepting)
  }
  async function act(name, value, confirmText) {
    if (confirmText && !confirm(confirmText)) return;
    var cid = ctId;
    try {
      var r = await client.ask('contactAct', { cid: cid, act: name, value: value }, 90000);
      var i = contacts.findIndex(function (x) { return x.id === cid; });
      if (r.contact) { if (i >= 0) contacts[i] = r.contact; else contacts.push(r.contact); }
      else if (i >= 0) contacts.splice(i, 1);
      renderContacts();
      if (!r.contact) { closePage(); if (currentId === cid) closeChat(); return true; }
      if (ctId === cid) { if (ctDet) ctDet.contact = r.contact; renderContact(r.contact); if (name === 'accept' || name === 'retry') openContact(cid); }
      if (currentId === cid) openChat(cid, true);
      return true;
    } catch (e) { toast(e.message, true); return false; }
  }
  $('ctAccept').onclick = async function () { if (await act('accept')) toast('Accepted — you can chat now'); };
  $('ctDecline').onclick = function () { act('decline', null, 'Decline this request? They won\'t be told.').then(function (ok) { if (ok) toast('Declined'); }); };
  $('ctBlock').onclick = $('ctBlock2').onclick = function () { act('block', null, 'Block this contact? They can\'t send you messages or requests until you unblock them.').then(function (ok) { if (ok) toast('Blocked'); }); };
  $('ctUnblock').onclick = function () { act('unblock').then(function (ok) { if (ok) toast('Unblocked'); }); };
  $('ctRemove').onclick = function () { act('remove', null, 'Remove this contact and your chat with them from Seal Chat (also on your computer)?').then(function (ok) { if (ok) toast('Removed'); }); };
  $('ctRetry').onclick = function () { act('retry').then(function (ok) { if (ok) toast('Trying again…'); }); };
  $('ctNickSave').onclick = async function () {
    var nick = $('ctNick').value.trim(), old = ctDet ? ctDet.nick : '';
    if (ctDet) ctDet.nick = nick;                              // before the page redraws with the answer
    if (await act('rename', nick)) toast('Saved'); else if (ctDet) { ctDet.nick = old; $('ctNick').value = old; }
  };
  $('ctVerified').onchange = function () { var v = this.checked; act('verify', v).then(function (ok) { if (ok) toast(v ? 'Marked as verified' : 'No longer marked verified'); }); };
  $('ctMuted').onchange = function () { var v = this.checked; act('mute', v).then(function (ok) { if (ok) toast(v ? 'Muted' : 'Unmuted'); }); };
  $('ctChat').onclick = function () {
    // the page's place in the back history becomes the chat's (so Back returns to the list)
    var id = ctId; closePage(true);
    if (history.state && history.state.page) history.replaceState(narrow() ? { chat: id } : null, '');
    openChat(id, false, true);
  };

  /* =================================================================
     12. START-UP
     ================================================================= */
  async function boot() {
    var hash = location.hash;
    if (/pair=/.test(hash)) {
      history.replaceState(null, '', location.pathname + location.search);   // the secret leaves the address bar
      return startPairing(hash);
    }
    var rec = null;
    try { rec = await storage.load(); } catch (e) { toast('This browser blocks saving (private mode?) — pairing won\'t be kept.', true); }
    if (!rec) return showStart();
    client = newClient(); client.rec = rec;
    showLock('');
  }
  // a pairing link opened in a tab that already shows this page
  window.addEventListener('hashchange', function () { if (/pair=/.test(location.hash)) boot(); });
  if (!window.crypto || !window.crypto.subtle) { document.body.innerHTML = '<p class="pad">This page needs https:// and a current browser.</p>'; return; }
  boot();

  /* =================================================================
     13. FILES (1.2.0, needs Seal Chat 1.7.0+ on your computer)
     Sending: the file is read here in 512 KB pieces, each sealed and
     passed to your computer (3 at a time); your computer then sends it
     to your contact over Tor, exactly like a file picked there.
     Opening: the pieces come back the same way and are put together in
     this browser's memory only (nothing is saved unless you tap Save).
     ================================================================= */
  var PIECE = 512 * 1024, IN_FLIGHT = 3;
  var thumbs = {};          // message id → preview (data: URL) or false while asking
  var got = {};             // message id → { blob, url } of files opened this session
  var gotBytes = 0, KEEP_BYTES = 150 * 1048576;
  var busy = {};            // message id → 0..1 while being fetched to this device
  var ups = {};             // temp message id → { stop: bool }

  function isImg(f) { return /^image\/(jpeg|png|gif|webp|bmp|avif|heic)$/.test(f.mime || '') || /\.(jpe?g|png|gif|webp|bmp)$/i.test(f.name || ''); }
  function isVid(f) { return /^video\//.test(f.mime || '') || /\.(mp4|m4v|mov|webm)$/i.test(f.name || ''); }
  function isAud(f) { return /^audio\//.test(f.mime || '') || /\.(mp3|m4a|aac|wav|ogg|opus|flac)$/i.test(f.name || ''); }
  function icon(f) { return isImg(f) ? '🖼️' : isVid(f) ? '🎬' : isAud(f) ? '🎵' : /pdf$/.test(f.mime || '') ? '📄' : /zip|7z|rar/.test(f.mime || f.name) ? '🗜️' : '📎'; }
  function pct(x) { return Math.max(0, Math.min(100, Math.round(x * 100))); }

  /* ---------- 13a. how a file looks in the chat ---------- */
  function fileBody(m) {
    var f = m.file, t = f.transfer || {}, st = t.state || '', act = '', line, bar = null;
    var share = t.total ? t.done / t.total : 0;
    if (m.upName) {                                              // being sent from this device
      line = m.upDone ? 'Handing it to your computer…' : 'Sending to your computer · ' + pct(m.upShare || 0) + '%';
      bar = m.upShare || 0;
      if (!m.upDone) act = '<button class="btn small fact" data-fact="stop">Cancel</button>';
      if (m.state === 'failed') { line = 'Not sent'; bar = null; act = ''; }
    } else if (busy[m.id] != null) {
      line = 'Opening on this device · ' + pct(busy[m.id]) + '%'; bar = busy[m.id];
    } else if (f.removed) {
      line = 'Removed from your computer to free space';
    } else if (m.dir === 'in') {
      if (f.ready) { line = 'Tap to open'; act = f.size > fileMax ? '' : '<button class="btn small fact" data-fact="open">' + (isImg(f) || isVid(f) || isAud(f) ? 'Open' : 'Open / save') + '</button>'; if (f.size > fileMax) line = 'Too big for this device — open it on your computer'; }
      else if (st === 'downloading' || st === 'queued') { line = (st === 'queued' ? 'Waiting · ' : 'Your computer is downloading it · ') + pct(share) + '%'; bar = share; if (t.err) line = t.err; }
      else if (st === 'declined') line = 'Declined';
      else if (st === 'failed') { line = t.err || 'Download failed'; act = '<button class="btn small fact" data-fact="fetch">Try again</button>'; }
      else { line = st === 'paused' ? 'Paused' : 'Not downloaded yet'; act = '<button class="btn small fact" data-fact="fetch">Download</button>'; }
    } else {
      line = st === 'preparing' ? 'Preparing · ' + pct(share) + '%' : st === 'uploading' ? 'Sending · ' + pct(share) + '%' : st === 'done' ? 'Delivered' : st === 'declined' ? 'They declined it' : st === 'failed' ? (t.err || 'Not sent') : 'Waiting for them to download it';
      if (st === 'preparing' || st === 'uploading') bar = share;
      if (f.ready && f.size <= fileMax) act = '<button class="btn small fact" data-fact="open">Open</button>';
    }
    if (f.risky) line += ' · program file';
    var thumb = '';
    if (f.thumb && !m.upName) {
      if (thumbs[m.id]) thumb = '<img class="fthumb" data-fact="' + (f.ready ? 'open' : 'none') + '" src="' + esc(thumbs[m.id]) + '" alt="">';
      else if (thumbs[m.id] !== false) setTimeout(function () { loadThumb(m.id); }, 0);
    }
    return thumb + '<div class="file"><span class="ficon">' + icon(f) + '</span><span class="grow"><span class="fname">' + esc(f.name) + '</span>'
      + '<span class="fstate">' + fmtSize(f.size) + ' · ' + esc(line) + '</span>'
      + (bar != null ? '<span class="fbar"><i data-w="' + pct(bar) + '"></i></span>' : '') + act + '</span></div>';
  }
  // progress bars: the page's security policy doesn't allow style="" in HTML, so the width is set from data-w here
  var _render = renderMessages;
  renderMessages = function (toEnd) {
    _render(toEnd);
    $('msgList').querySelectorAll('.fbar > i[data-w]').forEach(function (i) { i.style.width = i.dataset.w + '%'; });
  };

  function findMsg(id) { return messages.find(function (x) { return x.id === id; }); }

  async function loadThumb(id) {
    if (thumbs[id] != null || !currentId) return;
    thumbs[id] = false;
    var cid = currentId;
    try {
      var r = await client.ask('fileThumb', { cid: cid, id: id }, 60000);
      if (r.thumb && /^data:image\/(jpeg|png|webp);base64,/.test(r.thumb)) { thumbs[id] = r.thumb; if (currentId === cid) renderMessages(false); }
    } catch (e) { /* no preview */ }
  }

  function fileAct(act, id) {
    var m = findMsg(id);
    if (!m) return;
    if (act === 'stop') { if (ups[id]) ups[id].stop = true; return; }
    if (act === 'fetch') {
      client.ask('fileFetch', { cid: currentId, id: id }).then(function (r) { if (r.msg) { var i = messages.indexOf(m); if (i >= 0) messages[i] = r.msg; renderMessages(false); } }).catch(function (e) { toast(e.message, true); });
      return;
    }
    if (act === 'open') openFile(m);
  }

  /* ---------- 13b. sending from this device ---------- */
  $('btnAttach').onclick = function () { if (canFiles && currentId) $('fileInput').click(); };
  $('fileInput').onchange = function () {
    var list = Array.prototype.slice.call(this.files || []);
    this.value = '';
    list.slice(0, 10).forEach(function (f) { sendFile(f); });
  };

  function readPiece(file, i) {
    return new Promise(function (res, rej) {
      var fr = new FileReader();
      fr.onload = function () { var s = String(fr.result); res(s.slice(s.indexOf(',') + 1)); };
      fr.onerror = function () { rej(new Error('Could not read "' + file.name + '" on this device')); };
      fr.readAsDataURL(file.slice(i * PIECE, Math.min(file.size, (i + 1) * PIECE)));
    });
  }

  async function sendFile(file) {
    var cid = currentId;
    if (!cid) return;
    if (!file.size) return toast('"' + file.name + '" is empty', true);
    if (file.size > fileMax) return toast('"' + file.name + '" is ' + fmtSize(file.size) + '. Files from this device can be up to ' + fmtSize(fileMax) + ' — send bigger ones from your computer.', true);
    var tmp = { id: 'up' + (++tmpN), dir: 'out', kind: 'file', text: '', at: Date.now(), state: 'uploading', upName: file.name, upShare: 0,
      file: { name: file.name, size: file.size, mime: file.type } };
    var ctl = ups[tmp.id] = { stop: false };
    if (replyTo) { tmp.reId = replyTo.id; tmp.re = { id: replyTo.id, dir: replyTo.dir, snip: snipOf(replyTo) }; endReply(); }   // 1.3.0
    messages.push(tmp); renderMessages(true);
    var redraw = function () { if (currentId === cid) renderMessages(false); };
    var up = null;
    try {
      var st = await client.ask('fileUp', { cid: cid, name: file.name, size: file.size, mime: file.type || '' }, 60000);
      up = st.up;
      var n = st.n, next = 0, done = 0;
      // a few pieces in flight at once (they are still sent one after another, in order)
      await new Promise(function (resolve, reject) {
        var failed = false;
        function pump() {
          if (failed) return;
          if (ctl.stop) { failed = true; var e = new Error('Cancelled'); e.code = 'stop'; return reject(e); }
          if (done === n) return resolve();
          while (next < n && next - done < IN_FLIGHT) {
            (function (i) {
              readPiece(file, i).then(function (d) { return client.ask('fileUpPart', { up: up, i: i, d: d }, 180000); })
                .then(function () { done++; tmp.upShare = done / n; redraw(); pump(); })
                .catch(function (e) { if (!failed) { failed = true; reject(e); } });
            })(next++);
          }
        }
        pump();
      });
      tmp.upDone = true; tmp.upShare = 1; redraw();
      var r = await client.ask('fileUpEnd', { cid: cid, up: up, tmp: tmp.id, re: tmp.reId || undefined }, 300000);
      var at = messages.indexOf(tmp);
      if (messages.some(function (x) { return x !== tmp && x.id === r.msg.id; })) { if (at >= 0) messages.splice(at, 1); }
      else if (at >= 0) messages[at] = r.msg;
      var c = contacts.find(function (x) { return x.id === cid; });
      if (c) { c.lastAt = r.msg.at; c.lastText = '📎 ' + file.name; c.lastDir = 'out'; }
    } catch (e) {
      if (up) client.tell('fileUpStop', { up: up });
      if (e.code === 'stop') { var k = messages.indexOf(tmp); if (k >= 0) messages.splice(k, 1); }
      else { tmp.state = 'failed'; toast(e.message, true); }
    }
    delete ups[tmp.id];
    redraw(); renderContacts();
  }

  /* ---------- 13c. opening on this device ---------- */
  async function fetchBlob(cid, m) {
    if (got[m.id]) return got[m.id].blob;
    busy[m.id] = 0; renderMessages(false);
    try {
      var first = await client.ask('fileGet', { cid: cid, id: m.id, i: 0 }, 180000);
      var n = first.n, parts = new Array(n), done = 1, next = 1;
      parts[0] = b64ToBytes(first.d);
      busy[m.id] = done / n; if (currentId === cid) renderMessages(false);
      await new Promise(function (resolve, reject) {
        var failed = false;
        function pump() {
          if (failed) return;
          if (done === n) return resolve();
          while (next < n && next - done < IN_FLIGHT) {
            (function (i) {
              client.ask('fileGet', { cid: cid, id: m.id, i: i }, 180000).then(function (r) {
                parts[i] = b64ToBytes(r.d); done++; busy[m.id] = done / n; if (currentId === cid) renderMessages(false); pump();
              }).catch(function (e) { if (!failed) { failed = true; reject(e); } });
            })(next++);
          }
        }
        pump();
      });
      var blob = new Blob(parts, { type: first.mime || m.file.mime || 'application/octet-stream' });
      if (blob.size !== first.size) throw new Error('The file did not arrive completely — try again');
      keep(m.id, blob);
      return blob;
    } finally { delete busy[m.id]; if (currentId === cid) renderMessages(false); }
  }

  function b64ToBytes(b) { var s = atob(b), u = new Uint8Array(s.length); for (var i = 0; i < s.length; i++) u[i] = s.charCodeAt(i); return u; }

  // keep the most recent files in memory (up to ~150 MB) so opening again is instant
  function keep(id, blob) {
    got[id] = { blob: blob, url: URL.createObjectURL(blob), at: Date.now() };
    gotBytes += blob.size;
    var ids = Object.keys(got).sort(function (a, b) { return got[a].at - got[b].at; });
    while (gotBytes > KEEP_BYTES && ids.length > 1) { var o = ids.shift(); if (o === id) continue; gotBytes -= got[o].blob.size; URL.revokeObjectURL(got[o].url); delete got[o]; }
  }

  var viewing = null;
  async function openFile(m) {
    var cid = currentId, f = m.file;
    if (busy[m.id] != null) return;
    try { await fetchBlob(cid, m); } catch (e) { return toast(e.message, true); }
    var g = got[m.id];
    viewing = { m: m, g: g };
    $('fvTitle').textContent = f.name;
    $('fvInfo').textContent = fmtSize(f.size) + (m.dir === 'in' ? '' : ' · sent by you');
    var box = $('fvBox'); box.innerHTML = '';
    var el;
    if (isImg(f) && !f.risky) { el = document.createElement('img'); el.alt = f.name; el.src = g.url; }
    else if (isVid(f) && !f.risky) { el = document.createElement('video'); el.controls = true; el.playsInline = true; el.src = g.url; }
    else if (isAud(f) && !f.risky) { el = document.createElement('audio'); el.controls = true; el.src = g.url; }
    else { el = document.createElement('div'); el.className = 'fv-big'; el.textContent = icon(f); }
    box.appendChild(el);
    $('fvRisky').classList.toggle('hidden', !f.risky);
    var canShare = !!(navigator.canShare && window.File && navigator.canShare({ files: [new File([g.blob], f.name, { type: g.blob.type })] }));
    $('fvShare').classList.toggle('hidden', !canShare);
    openPage('pgFile');
  }
  PAGE_CLOSE.pgFile = function () { var v = $('fvBox').querySelector('video,audio'); if (v) v.pause(); $('fvBox').innerHTML = ''; viewing = null; };

  // Save: the browser's own download (iPhone: "Download" → Files; Android: Downloads)
  $('fvSave').onclick = function () {
    if (!viewing) return;
    var a = document.createElement('a');
    a.href = viewing.g.url; a.download = viewing.m.file.name; a.rel = 'noopener';
    document.body.appendChild(a); a.click(); a.remove();
  };
  // Share…: the phone's share sheet (Save to Photos, Files, another app …)
  $('fvShare').onclick = function () {
    if (!viewing) return;
    var f = new File([viewing.g.blob], viewing.m.file.name, { type: viewing.g.blob.type });
    navigator.share({ files: [f], title: viewing.m.file.name }).catch(function (e) { if (e && e.name !== 'AbortError') toast(e.message, true); });
  };

  /* =================================================================
     14. UPDATES FOR SEAL CHAT WEB ITSELF (1.2.1)
     Talks to webupdate.php on this website (not to your computer).
     Checking is open; installing needs the admin password.
     ================================================================= */
  function upCall(body) {
    return fetch('webupdate.php', { method: 'POST', cache: 'no-store', headers: { 'Content-Type': 'application/json', 'X-SC-Update': '1' }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().catch(function () { throw new Error('The website answered ' + r.status); }); });
  }
  function upWhen(t) { return t ? new Date(t * 1000).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' }) : 'not yet'; }
  function renderUpdates(st) {
    var box = $('upState');
    $('upErr').textContent = st.ok === false ? (st.error || '') : '';
    if (!st.current) return;
    if (st.update) {
      box.className = 'state-card on';
      box.innerHTML = '<span class="big">⬆️</span><span><b>Seal Chat Web ' + esc(st.update.version) + ' is available</b><br><span class="muted small">You have ' + esc(st.current) + (st.update.size ? ' · ' + esc(st.update.size) : '') + '</span></span>';
      $('upNote').textContent = st.update.note || '';
      $('upInstallBox').classList.toggle('hidden', !st.canWrite);
      if (!st.canWrite) $('upErr').textContent = 'This website can\'t update itself: ' + (st.blockers || []).join(', ') + '. Use the setup page (admin.php) or upload the new zip by hand.';
    } else {
      box.className = 'state-card' + (st.error ? ' bad' : '');
      box.innerHTML = '<span class="big">' + (st.error ? '⚠️' : '✅') + '</span><span><b>' + (st.error ? 'Couldn\'t check for updates' : 'You have the newest version') + '</b><br><span class="muted small">Seal Chat Web ' + esc(st.current) + ' · last checked ' + esc(upWhen(st.checked)) + '</span></span>';
      $('upNote').textContent = st.error || '';
      $('upInstallBox').classList.add('hidden');
    }
    var mi = $('mnUpdates');
    if (mi) { mi.textContent = st.update ? 'Update available: Seal Chat Web ' + st.update.version : 'Updates'; mi.classList.toggle('sheet-update', !!st.update); }
  }
  function openUpdates() {
    $('upErr').textContent = ''; $('upPass').value = '';
    upCall({ a: 'status' }).then(renderUpdates).catch(function (e) { $('upErr').textContent = e.message; });
  }
  $('upCheck').onclick = function () {
    var b = this; b.disabled = true; b.textContent = 'Checking…';
    upCall({ a: 'check' }).then(renderUpdates).catch(function (e) { $('upErr').textContent = e.message; })
      .then(function () { b.disabled = false; b.textContent = 'Check now'; });
  };
  $('upGo').onclick = function () {
    var pw = $('upPass').value;
    if (!pw) { $('upErr').textContent = 'Enter the admin password first.'; return; }
    var b = this; b.disabled = true; b.textContent = 'Updating… (this can take a minute)'; $('upErr').textContent = '';
    upCall({ a: 'install', password: pw }).then(function (r) {
      $('upPass').value = '';
      if (!r.ok) { renderUpdates(r); return; }
      toast(r.message || 'Updated.');
      setTimeout(function () { location.reload(); }, 1800);       // load the new version
    }).catch(function (e) { $('upErr').textContent = e.message; })
      .then(function () { b.disabled = false; b.textContent = 'Update now'; });
  };
  $('upPass').onkeydown = function (e) { if (e.key === 'Enter') $('upGo').click(); };

  /* =================================================================
     15. REPLIES + REACTIONS (1.3.0, needs Seal Chat 1.8.0+)
     Tap a message → a row of reactions, Reply, Copy. A reply shows the
     quoted excerpt above it; tap the quote to jump to the original.
     ================================================================= */
  var QUICK = ['👍', '❤️', '😂', '😮', '😢', '🙏'];
  var replyTo = null, sheetMsg = null;

  function snipOf(m) { return m.kind === 'file' && m.file ? '📎 ' + m.file.name : String(m.text || '').replace(/\s+/g, ' ').slice(0, 140); }
  function curName() { var c = contacts.find(function (x) { return x.id === currentId; }); return c ? c.name : 'Them'; }
  function quoteHtml(m) {
    if (!m.re) return '';
    var who = m.re.dir === 'out' ? 'You' : m.re.dir === 'in' ? curName() : '';
    return '<div class="quote" data-jump="' + esc(m.re.id) + '">' + (who ? '<b>' + esc(who) + '</b> ' : '') + '<span>' + esc(m.re.snip || 'Message') + '</span></div>';
  }
  function reactsHtml(m) {
    var r = m.reactions || {};
    if (!r.me && !r.them) return '';
    return '<div class="reacts">' + (r.them ? '<span class="react">' + esc(r.them) + '</span>' : '') + (r.me ? '<span class="react mine">' + esc(r.me) + '</span>' : '') + '</div>';
  }
  function chatAccepted() { var c = contacts.find(function (x) { return x.id === currentId; }); return !!c && c.status === 'accepted'; }

  function openMsgSheet(m) {
    sheetMsg = m;
    var can = canReact && chatAccepted();
    var mine = m.reactions && m.reactions.me;
    $('msReacts').innerHTML = can ? QUICK.map(function (e) { return '<button class="react-btn' + (e === mine ? ' on' : '') + '" data-e="' + e + '" aria-label="React ' + e + '">' + e + '</button>'; }).join('') : '';
    $('msReacts').classList.toggle('hidden', !can);
    $('msgSheet').querySelector('[data-mact="reply"]').classList.toggle('hidden', !can);
    $('msUnreact').classList.toggle('hidden', !(can && mine));
    $('msgSheet').querySelector('[data-mact="copy"]').classList.toggle('hidden', !m.text);
    $('msgSheet').classList.remove('hidden');
  }
  $('msgSheet').onclick = function (e) {
    var eb = e.target.closest('[data-e]'), ab = e.target.closest('[data-mact]');
    if (!eb && !ab && e.target !== $('msgSheet')) return;
    $('msgSheet').classList.add('hidden');
    var m = sheetMsg; sheetMsg = null;
    if (!m) return;
    if (eb) return sendReaction(m, (m.reactions && m.reactions.me) === eb.dataset.e ? '' : eb.dataset.e);
    var act = ab && ab.dataset.mact;
    if (act === 'reply') startReply(m);
    if (act === 'unreact') sendReaction(m, '');
    if (act === 'copy' && navigator.clipboard) navigator.clipboard.writeText(m.text).then(function () { toast('Copied'); });
  };
  async function sendReaction(m, e) {
    var before = m.reactions;
    m.reactions = Object.assign({}, m.reactions || {}, { me: e }); renderMessages(false);   // show it straight away
    try { var r = await client.ask('react', { cid: currentId, mid: m.id, e: e }); var i = messages.findIndex(function (x) { return x.id === m.id; }); if (i >= 0 && r.msg) { messages[i] = r.msg; renderMessages(false); } }
    catch (err) { m.reactions = before; renderMessages(false); toast(err.message, true); }
  }
  function startReply(m) {
    replyTo = m;
    $('replyText').innerHTML = '<b>↩ ' + esc(m.dir === 'out' ? 'Replying to yourself' : 'Replying to ' + curName()) + '</b> <span class="muted">' + esc(snipOf(m)) + '</span>';
    $('replyBar').classList.remove('hidden');
    $('msgInput').focus();
  }
  function endReply() { replyTo = null; $('replyBar').classList.add('hidden'); $('replyText').textContent = ''; }
  $('replyCancel').onclick = endReply;
  function jumpTo(id) {
    var el = $('msgList').querySelector('.bubble[data-id="' + (window.CSS && CSS.escape ? CSS.escape(id) : id) + '"]');
    if (!el) return toast('The original message isn\'t loaded — scroll up with "Show earlier messages"');
    el.scrollIntoView({ block: 'center', behavior: 'smooth' });
    el.classList.add('flash'); setTimeout(function () { el.classList.remove('flash'); }, 1400);
  }
})();
