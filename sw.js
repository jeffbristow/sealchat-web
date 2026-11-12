/* =====================================================================
   Seal Chat Web — sw.js   (notifications, 1.1.0)
   ---------------------------------------------------------------------
   The "service worker": a tiny script the phone's browser keeps so it
   can show a notification while Seal Chat Web isn't open.
   - Seal Chat on your computer sends the notification, encrypted for
     this browser only, through the phone maker's push service (Apple /
     Google / Mozilla). The browser decrypts it and hands it to this file.
   - It only says "New message" (or "New message from <name>", if you
     allowed names) — never what the message says.
   - Tapping the notification opens Seal Chat Web (PIN first, as always).
   It doesn't cache pages or touch anything else.

   Sections:
     1. Start-up (take over straight away after an update)
     2. A notification arrives → show it
     3. The notification is tapped → open Seal Chat Web
   ===================================================================== */
'use strict';

/* ---------- 1. Start-up ---------- */
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });

/* ---------- 2. Show it ----------
   iPhone requires that EVERY push shows a notification, so even an
   unreadable one shows the plain "New message". */
self.addEventListener('push', function (e) {
  var n = { title: 'Seal Chat', body: 'New message', tag: 'sealchat' };
  try { if (e.data) { var d = e.data.json(); if (d && typeof d === 'object') n = Object.assign(n, d); } } catch (x) { /* keep the plain text */ }
  e.waitUntil(self.registration.showNotification(String(n.title).slice(0, 60), {
    body: String(n.body).slice(0, 200),
    tag: String(n.tag || 'sealchat').slice(0, 40),   // a newer notification replaces the older one
    renotify: true,
    icon: 'img/icon-192.png',
    badge: 'img/badge-96.png',                       // Android status-bar icon (white on clear)
    data: { url: self.registration.scope }
  }));
});

/* ---------- 3. Tapped ---------- */
self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  var url = (e.notification.data && e.notification.data.url) || self.registration.scope;
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    for (var i = 0; i < list.length; i++) {
      if (list[i].url.indexOf(self.registration.scope) === 0 && 'focus' in list[i]) return list[i].focus();
    }
    return self.clients.openWindow(url);
  }));
});
