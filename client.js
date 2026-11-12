/* =====================================================================
   Seal Chat Web — client.js   (1.0.0)
   ---------------------------------------------------------------------
   Everything the web interface does with relay.php and Seal Chat on your
   computer — no screen code here (that's app.js), so the same file is
   also used by the tests.

     pair()     first time: QR link → key swap → code → approved
     unlock()   PIN → wakes your computer → "welcome" with your contacts
     ask()      a request (contacts, history, send, read) → its answer
     events     'push'   live updates (new message, ✓✓, contact list)
                'state'  connecting / online / computer-offline / …
                'revoked' this device was removed on the computer

   Sections:
     1. Setup + relay calls
     2. Pairing
     3. Unlock (PIN) + waking the computer
     4. Listening loop
     5. Requests
   ===================================================================== */
(function (root) {
  'use strict';
  var K = root.SCCrypto || (typeof require === 'function' ? require('./crypto.js') : null);

  var WAIT = 20;                 // seconds relay.php holds "recv" open
  var WAKE_EVERY = 10000;        // ask again while the computer hasn't answered

  /* =================================================================
     1. SETUP
     storage: { load() → record|null, save(record), clear() }
     record:  { box, dev, token, key (CryptoKey), name, inSeq }
     ================================================================= */
  function Client(opts) {
    this.base = opts.base;                 // folder with relay.php, ending in "/"
    this.storage = opts.storage;
    this.handlers = {};
    this.rec = null;
    this.sid = null;
    this.pin = null;
    this.ack = 0;
    this.outSeq = 0;
    this.waiting = {};                     // q -> { resolve, reject, timer }
    this.q = 0;
    this.running = false;
    this.cSeen = 0;
    this.skew = 0;                         // website clock − ours (seconds)
  }
  Client.prototype.on = function (ev, fn) { (this.handlers[ev] = this.handlers[ev] || []).push(fn); return this; };
  Client.prototype.emit = function (ev, a) { (this.handlers[ev] || []).forEach(function (f) { try { f(a); } catch (e) { setTimeout(function () { throw e; }); } }); };

  Client.prototype.load = async function () { this.rec = await this.storage.load(); return this.rec; };

  /** POST relay.php?a=<action> with our mailbox id + token. */
  Client.prototype.call = async function (action, body, box, token, signal) {
    var r;
    try {
      r = await fetch(this.base + 'relay.php?a=' + action, {
        method: 'POST', cache: 'no-store', signal: signal,
        headers: { 'Content-Type': 'application/json', 'X-SC-Box': box || this.rec.box, 'Authorization': 'Bearer ' + (token || this.rec.token), 'X-SC-Auth': token || this.rec.token },
        body: JSON.stringify(body || {})
      });
    } catch (e) {
      if (e && e.name === 'AbortError') throw e;
      var ne = new Error('No connection to the website'); ne.code = 'net'; throw ne;
    }
    var j = null;
    try { j = await r.json(); } catch (e) { /* not JSON */ }
    if (!j) { var e1 = new Error('The website answered ' + r.status); e1.code = 'http'; throw e1; }
    if (j.now) this.skew = j.now - Math.floor(Date.now() / 1000);
    if (!j.ok) { var e2 = new Error(j.error || 'Refused'); e2.code = j.code; e2.status = r.status; throw e2; }
    return j;
  };

  /* =================================================================
     2. PAIRING
     link part after "#pair=": <box>.<ticket id>.<secret P>
     ================================================================= */
  Client.parsePair = function (hash) {
    var m = /pair=([a-f0-9]{32})\.([a-f0-9]{16})\.([A-Za-z0-9_-]{40,50})/.exec(String(hash || ''));
    return m ? { box: m[1], tid: m[2], P: K.unb64u(m[3]) } : null;
  };

  /**
   * Sends the pairing request and waits for your decision on the computer.
   * onCode(code) is called as soon as the 6-digit code is known.
   * Resolves the saved record; rejects with code 'declined' / 'expired'.
   */
  Client.prototype.pair = async function (p, name, pin, onCode) {
    var self = this;
    var sec = await K.pairSecrets(p.P);
    var kp = await K.newKeyPair();
    var dev = K.hex(K.random(8));
    var token = K.b64u(K.random(32));
    var msg = { t: 'pair', dev: dev, pub: K.b64u(kp.pub), name: name, th: await K.sha256hex(token), pin: pin };
    await this.call('send', { to: 'c', items: [await K.seal(sec.pairKey, K.AAD.pair('d2c', p.tid), msg)] }, p.box, sec.ticket);
    await this.call('wake', {}, p.box, sec.ticket).catch(function () {});
    var ack = 0, keys = null, lastWake = Date.now(), until = Date.now() + 10 * 60000;
    while (Date.now() < until) {
      var j;
      try { j = await this.call('recv', { ack: ack, wait: WAIT }, p.box, sec.ticket); }
      catch (e) {
        if (e.code === 'auth') { var ex = new Error('This pairing code has expired. Start pairing again on your computer.'); ex.code = 'expired'; throw ex; }
        if (e.code === 'net' || e.code === 'http') { await sleep(3000); continue; }
        throw e;
      }
      this.cSeen = j.cSeen;
      for (var i = 0; i < j.items.length; i++) {
        ack = Math.max(ack, j.items[i].id);
        var o;
        try { o = await K.open(sec.pairKey, K.AAD.pair('c2d', p.tid), j.items[i].d); } catch (e) { continue; }
        if (o.t === 'pairKey' && !keys) {
          keys = await K.deviceSecrets(await K.ecdh(kp.priv, K.unb64u(o.pub)), p.P, dev);
          if (onCode) onCode(keys.code);
        } else if (o.t === 'pairOk' && keys) {
          self.rec = { box: p.box, dev: dev, token: token, key: keys.key, keyRaw: keys.raw, name: o.name || name, inSeq: 0, paired: Date.now() };
          await self.storage.save(self.rec);
          return self.rec;
        } else if (o.t === 'pairNo') {
          var ed = new Error('Pairing was declined on the computer.'); ed.code = 'declined'; throw ed;
        }
      }
      if (!keys && Date.now() - lastWake > WAKE_EVERY) { lastWake = Date.now(); this.call('wake', {}, p.box, sec.ticket).catch(function () {}); }
    }
    var et = new Error('Your computer did not answer in time. Is Seal Chat running with web access on?'); et.code = 'expired'; throw et;
  };

  /* =================================================================
     3. UNLOCK + WAKE
     ================================================================= */
  /** Checks the PIN with your computer (waking it first). Resolves "welcome". */
  Client.prototype.unlock = async function (pin) {
    if (!this.rec) throw new Error('Not paired');
    this.pin = pin;
    this.start();
    this.wake();
    var r = await this.ask('hello', { pin: pin }, 15 * 60000, true);
    if (r.t === 'badpin') { this.pin = null; var e = new Error('Wrong PIN — ' + r.left + ' tr' + (r.left === 1 ? 'y' : 'ies') + ' left before this device is removed'); e.code = 'badpin'; throw e; }
    if (r.t !== 'welcome') throw new Error('Unexpected answer from your computer');
    this.sid = r.sid;
    this.emit('state', 'online');
    return r;
  };

  Client.prototype.wake = function () {
    var self = this;
    this.lastWake = Date.now();
    return this.call('wake', {}).then(function (j) { self.cSeen = j.cSeen; return j; }).catch(function (e) { self._fatal(e); });
  };

  /** Seconds since your computer last talked to the website (website clock). */
  Client.prototype.computerQuietFor = function () { return Math.floor(Date.now() / 1000) + this.skew - (this.cSeen || 0); };

  /* =================================================================
     4. LISTENING LOOP (long poll while the page is open)
     ================================================================= */
  Client.prototype.start = function () {
    if (this.running) return;
    var self = this;
    this.running = true;
    clearInterval(this.quietTimer);
    this.quietTimer = setInterval(function () { if (self.running) self._checkQuiet(); }, 3000);
    this._loop();
  };
  Client.prototype.stop = function () {
    this.running = false;
    clearInterval(this.quietTimer);
    if (this.abort) try { this.abort.abort(); } catch (e) { /* ignore */ }
  };
  /** Forget the unlock (PIN) — e.g. the "Lock" button. */
  Client.prototype.lock = function () {
    var sid = this.sid;
    this.sid = null; this.pin = null;
    if (sid) this.tell('bye', { sid: sid });
    this.stop();
    for (var q in this.waiting) { clearTimeout(this.waiting[q].timer); this.waiting[q].reject(new Error('Locked')); }
    this.waiting = {};
  };

  Client.prototype._loop = async function () {
    var self = this, fails = 0;
    while (this.running) {
      try {
        this.abort = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var j = await this.call('recv', { ack: this.ack, wait: WAIT }, null, null, this.abort && this.abort.signal);
        fails = 0;
        this.cSeen = j.cSeen;
        for (var i = 0; i < j.items.length; i++) {
          this.ack = Math.max(this.ack, j.items[i].id);
          await this._incoming(j.items[i].d);
        }
        this._checkQuiet();
      } catch (e) {
        if (!this.running) break;
        if (e && e.name === 'AbortError') continue;
        if (this._fatal(e)) break;
        fails++;
        this.emit('state', 'no-website');
        await sleep(Math.min(30000, 2000 * fails));
      }
    }
    self.abort = null;
  };

  /** Tells the screen whether your computer seems to be listening. */
  Client.prototype._checkQuiet = function () {
    var self = this, now = Date.now();
    var oldest = Object.keys(this.waiting).reduce(function (m, q) { return Math.min(m, self.waiting[q].at); }, now);
    var quiet = this.computerQuietFor();
    if (now - oldest > 6000) {
      // a request has waited 6 s+: your computer is probably on its idle checks — nudge it
      this.emit('state', 'waking');
      if (now - (this.lastWake || 0) > WAKE_EVERY) this.wake();
    } else if (this.sid) this.emit('state', quiet > 90 ? 'computer-quiet' : 'online');
  };

  /** 401 = this device was removed; 404 = the computer disconnected from the website. */
  Client.prototype._fatal = function (e) {
    if (!e) return false;
    if (e.code === 'auth' || e.code === 'nobox') {
      this.running = false;
      this.emit('revoked', e.code === 'auth' ? 'removed' : 'nobox');
      return true;
    }
    if (e.code === 'off') { this.emit('state', 'relay-off'); return false; }
    return false;
  };

  Client.prototype._incoming = async function (data) {
    var o;
    try { o = await K.open(this.rec.key, K.AAD.msg('c2d', this.rec.dev), data); } catch (e) { return; }
    if (!(o.s > (this.rec.inSeq || 0))) return;          // old or repeated
    this.rec.inSeq = o.s;
    this.storage.save(this.rec);
    if (o.t === 'revoked') { this.running = false; this.emit('revoked', o.why || 'removed'); return; }
    if (o.t === 'relogin') {
      // your computer restarted: unlock again with the PIN we still hold
      var w = this.waiting[o.re];
      if (this.pin && w && !w.hello) { this._relogin(o.re); return; }
    }
    if (o.re && this.waiting[o.re]) {
      var x = this.waiting[o.re]; delete this.waiting[o.re];
      clearTimeout(x.timer); x.resolve(o);
      return;
    }
    this.emit('push', o);
  };

  Client.prototype._relogin = async function (failedQ) {
    var w = this.waiting[failedQ]; delete this.waiting[failedQ];
    try {
      var r = await this.ask('hello', { pin: this.pin }, 120000, true);
      if (r.t !== 'welcome') throw new Error('Please unlock again');
      this.sid = r.sid;
      this.emit('push', { t: 'contacts', list: r.contacts });
      var again = await this.ask(w.t, w.data, 60000);
      w.resolve(again);
    } catch (e) { w.reject(e); }
  };

  /* =================================================================
     5. REQUESTS
     ================================================================= */
  Client.prototype._seq = function () { this.outSeq = Math.max(Date.now() * 1000, this.outSeq + 1); return this.outSeq; };

  /** Sends a request; resolves with your computer's answer (or rejects after timeoutMs). */
  Client.prototype.ask = function (t, data, timeoutMs, isHello) {
    var self = this;
    var q = ++this.q;
    var obj = Object.assign({}, data || {}, { t: t, q: q, s: this._seq() });
    if (!isHello) obj.sid = this.sid;
    return new Promise(function (resolve, reject) {
      var timer = setTimeout(function () {
        delete self.waiting[q];
        var e = new Error('Your computer did not answer. Is it on, with Seal Chat running and web access turned on?'); e.code = 'timeout'; reject(e);
      }, timeoutMs || 60000);
      self.waiting[q] = { resolve: function (o) { if (o.t === 'err') { var e = new Error(o.error); e.code = 'err'; reject(e); } else resolve(o); }, reject: reject, timer: timer, t: t, data: data, hello: !!isHello, at: Date.now() };
      self._post(obj).catch(function (e) { clearTimeout(timer); delete self.waiting[q]; self._fatal(e); reject(e); });
    });
  };

  /** A message that needs no answer. */
  Client.prototype.tell = function (t, data) {
    return this._post(Object.assign({}, data || {}, { t: t, q: ++this.q, s: this._seq() })).catch(function () {});
  };

  // sends one at a time, so they arrive in order
  Client.prototype._post = function (obj) {
    var self = this;
    var p = (this.chain || Promise.resolve()).catch(function () {}).then(async function () {
      var sealed = await K.seal(self.rec.key, K.AAD.msg('d2c', self.rec.dev), obj);
      var j = await self.call('send', { to: 'c', items: [sealed] });
      self.cSeen = j.cSeen;
      if (self.computerQuietFor() > 30 && Date.now() - (self.lastWake || 0) > 5000) self.wake();
      return j;
    });
    this.chain = p;
    return p;
  };

  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

  if (typeof module === 'object' && module.exports) module.exports = Client; else root.SCClient = Client;
})(typeof self !== 'undefined' ? self : globalThis);
