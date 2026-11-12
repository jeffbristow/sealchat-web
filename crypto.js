/* =====================================================================
   Seal Chat Web — crypto.js   (1.0.0)
   ---------------------------------------------------------------------
   The encryption between this browser and Seal Chat on your computer.
   The website (relay.php) only ever sees the sealed results.

   How it fits together:
     PAIRING   The QR code shown by Seal Chat holds a one-time secret P.
               It is in the part of the link after "#", which browsers
               never send to the website.
                 pair key  = HKDF(P, "sealchat-web-pair-v1")   → seals the
                             pairing messages (the relay can't read or fake them)
                 ticket    = HKDF(P, "sealchat-web-ticket-v1") → lets this
                             browser use the relay while pairing
               Both sides then make a fresh P-256 key pair and swap the
               public halves (inside the sealed pairing messages):
                 device key = HKDF(ECDH secret, salt P, "sealchat-web-key-v1|<device id>")
                 code       = HKDF(ECDH secret, salt P, "sealchat-web-code-v1") → 6 digits
                             shown on BOTH screens so you can compare them
    MESSAGES  AES-256-GCM with the device key; the direction and device id
               are bound in as "additional data", so a message can't be
               replayed in the other direction or for another device.
               Every message carries an increasing number (s) — old or
               repeated ones are refused.

   Works in browsers (window.SCCrypto) and in Node 20+ (module.exports),
   so the same code is used by the tests.

   Sections:
     1. Bytes + base64url
     2. Building blocks (HKDF, ECDH, SHA-256)
     3. Pairing + device keys
     4. Sealing / opening messages
   ===================================================================== */
(function (root) {
  'use strict';
  var C = (root.crypto && root.crypto.subtle) ? root.crypto : (typeof require === 'function' ? require('crypto').webcrypto : null);
  var subtle = C && C.subtle;
  var te = new TextEncoder(), td = new TextDecoder();

  /* ---------- 1. bytes ---------- */
  function b64u(bytes) {
    bytes = bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes);
    var s = '';
    for (var i = 0; i < bytes.length; i += 0x8000) s += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  function unb64u(str) {
    str = String(str || '').replace(/-/g, '+').replace(/_/g, '/');
    while (str.length % 4) str += '=';
    var bin = atob(str), out = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) out[i] = bin.charCodeAt(i);
    return out;
  }
  function random(n) { var b = new Uint8Array(n); C.getRandomValues(b); return b; }
  function hex(bytes) { return Array.prototype.map.call(new Uint8Array(bytes), function (x) { return ('0' + x.toString(16)).slice(-2); }).join(''); }

  /* ---------- 2. building blocks ---------- */
  async function hkdf(ikm, salt, info, len) {
    var base = await subtle.importKey('raw', ikm, 'HKDF', false, ['deriveBits']);
    var bits = await subtle.deriveBits({ name: 'HKDF', hash: 'SHA-256', salt: salt || new Uint8Array(0), info: te.encode(info) }, base, len * 8);
    return new Uint8Array(bits);
  }
  async function sha256hex(text) { return hex(await subtle.digest('SHA-256', typeof text === 'string' ? te.encode(text) : text)); }
  async function newKeyPair() {
    var kp = await subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, false, ['deriveBits']);
    return { priv: kp.privateKey, pub: new Uint8Array(await subtle.exportKey('raw', kp.publicKey)) };
  }
  async function ecdh(priv, peerPubRaw) {
    var pub = await subtle.importKey('raw', peerPubRaw, { name: 'ECDH', namedCurve: 'P-256' }, false, []);
    return new Uint8Array(await subtle.deriveBits({ name: 'ECDH', public: pub }, priv, 256));
  }
  function aesKey(raw) { return subtle.importKey('raw', raw, { name: 'AES-GCM' }, false, ['encrypt', 'decrypt']); }

  /* ---------- 3. pairing + device keys ---------- */
  /** From the QR secret P: the pairing key and the relay ticket. */
  async function pairSecrets(P) {
    return {
      pairKey: await aesKey(await hkdf(P, null, 'sealchat-web-pair-v1', 32)),
      ticket: b64u(await hkdf(P, null, 'sealchat-web-ticket-v1', 24))
    };
  }
  /** After the key swap: the device key (can't be read back out) + the 6-digit code. */
  async function deviceSecrets(shared, P, devId) {
    var code = await hkdf(shared, P, 'sealchat-web-code-v1', 4);
    var n = ((code[0] << 24) >>> 0) + (code[1] << 16) + (code[2] << 8) + code[3];
    var six = ('000000' + (n % 1000000)).slice(-6);
    var raw = await hkdf(shared, P, 'sealchat-web-key-v1|' + devId, 32);
    // raw is only kept if this browser can't store the key object itself (see app.js)
    return { key: await aesKey(raw), raw: raw, code: six.slice(0, 3) + ' ' + six.slice(3) };
  }

  /* ---------- 4. sealing ---------- */
  async function seal(key, aad, obj) {
    var iv = random(12);
    var ct = await subtle.encrypt({ name: 'AES-GCM', iv: iv, additionalData: te.encode(aad) }, key, te.encode(JSON.stringify(obj)));
    return JSON.stringify({ n: b64u(iv), c: b64u(new Uint8Array(ct)) });
  }
  async function open(key, aad, text) {
    var o = JSON.parse(text);
    var pt = await subtle.decrypt({ name: 'AES-GCM', iv: unb64u(o.n), additionalData: te.encode(aad) }, key, unb64u(o.c));
    return JSON.parse(td.decode(pt));
  }
  // what is bound into each message (must match webaccess.js on the computer)
  var AAD = {
    pair: function (dir, tid) { return 'sealchat-web-pair-v1|' + dir + '|' + tid; },
    msg: function (dir, devId) { return 'sealchat-web-v1|' + dir + '|' + devId; }
  };

  var api = { aesKey: aesKey, b64u: b64u, unb64u: unb64u, random: random, hex: hex, hkdf: hkdf, sha256hex: sha256hex, newKeyPair: newKeyPair, ecdh: ecdh, pairSecrets: pairSecrets, deviceSecrets: deviceSecrets, seal: seal, open: open, AAD: AAD };
  if (typeof module === 'object' && module.exports) module.exports = api; else root.SCCrypto = api;
})(typeof self !== 'undefined' ? self : globalThis);
