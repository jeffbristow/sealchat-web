=====================================================================
 SEAL CHAT WEB 1.4.0 — the web interface for Seal Chat (PHP, no database)
=====================================================================

WHAT'S NEW IN 1.4.0   (goes with Seal Chat 1.11.0)
  - Your contacts' Seal IDs, as your computer checked them: a small Seal
    ID mark next to their name in the chat list (⚠ if their Seal ID was
    replaced), a Seal ID label next to their name at the top of the chat,
    and a Seal ID section on the contact page (its address, whether it
    proves them, and the accounts and domains it proves).
  - The phone never checks a Seal ID itself; your computer does (through
    Tor, once a day). Trusting a replaced Seal ID is done in Seal Chat on
    your computer.
  - With an older Seal Chat nothing changes (it doesn't send Seal IDs).
  Updating: admin.php → Updates → Update now (or ⋯ → Updates on the
  phone). Changed: app.js, app.css, index.php, lib.php, README.txt, and
  the new img/sealid.svg.

WHAT'S NEW IN 1.3.2   (goes with Seal Chat 1.9.3)
  - Your phone works with ANY of your linked devices. With Seal Chat 1.9.3
    your devices (Settings → Your devices) share one connection to this
    website and the same paired phones, so it no longer matters which of
    them is running. relay.php lets only one of them answer the phone at a
    time; if that one is switched off or goes to sleep, another one that is
    running takes over within about a minute (your phone reconnects by
    itself with the PIN it already has).
  - Removing one of your devices in Seal Chat gives this website a new
    computer key, so the removed device can't use it any more. Your phones
    stay paired.
  - Older Seal Chat versions work exactly as before.
  Updating: admin.php → Updates → Update now (or ⋯ → Updates on the
  phone). Changed: relay.php, lib.php, README.txt.

WHAT'S NEW IN 1.3.1
  - Names panel: tidier layout, IDs in a fixed-width font, and the hint
    names the right place in Seal Chat (Settings → You).
  - My Seal Chat ID on the phone: your name wraps between words.

WHAT'S NEW IN 1.3.0   (goes with Seal Chat 1.8.0)
  - Replies and reactions on the phone: tap a message for 👍 ❤️ 😂 😮 😢 🙏,
    Reply or Copy text. Replies show the message they answer.
  - Mailbox: your contacts can leave sealed messages here while your
    computer is off (turn it on in Seal Chat: Settings → Web access →
    Mailbox). The website can't read them or see who sent them; they are
    kept at most 30 days and picked up when your computer is back.
  - Names: admin.php → Names gives people a short name like
    ann@yourdomain.com for their Seal Chat ID. It writes
    /.well-known/sealchat.json at the top of your website (or shows you
    the file to upload if PHP can't write there).
  - Add contact on the phone takes a name (ann@example.com) or an
    invite code (482-crystal-orange-river) as well as an ID; "My Seal
    Chat ID" shows your name if you set one.
  Updating from 1.2.2 / 1.2.3: admin.php → Updates → Update now (or
  ⋯ → Updates on the phone). Older: upload everything except config.php.

WHAT'S NEW IN 1.2.3
  • A small test release for "Update now": only the version number and
    this note change. If your setup page and ⋯ → Updates show 1.2.3,
    updating from the phone / setup page works on your hosting.

WHAT'S NEW IN 1.2.2
  • "Update now" is sturdier: files that are the same in both versions
    are no longer rewritten; the new files are unpacked in the settings
    folder first (outside the website); each changed file is put in place
    a second way if the first is refused; and if a file still can't be
    written, the old version is kept and the message gives the server's
    own reason (or points at the host's security scanner, e.g.
    Imunify360). Fixes "Couldn't write client.js — nothing was changed".
  Updating from 1.2.0 / 1.2.1: if "Update now" fails with that message,
  upload this zip by hand once (keep config.php); from 1.2.2 on, "Update
  now" uses the new way.

WHAT'S NEW IN 1.2.1
  • Updates from the phone: menu ⋯ → Updates shows your version, "Check
    now", and — when a newer one is on GitHub — "Update now". Installing
    asks for your ADMIN password (the one for admin.php), with the same
    lockout after wrong passwords; checking doesn't. The menu line reads
    "Update available: Seal Chat Web x.y.z" when there is one. New file:
    webupdate.php. (Undo stays on admin.php.)
  Updating from 1.2.0: admin.php → Updates → Update now (or ⋯ → Updates
  once you're on 1.2.1).

WHAT'S NEW IN 1.2.0
  • UPDATES FROM admin.php: a new "Updates" panel shows when a newer Seal
    Chat Web exists and installs it with "Update now". It gets updates
    from GitHub only (the repository jeffbristow/sealchat-updates). Nothing is installed unless the package's
    signature (made with Seal Chat's offline private key) and checksum are
    right, the zip contains only ordinary file names, and it really is
    that version. config.php, your settings folder and the wake files are
    never touched; the replaced files are kept in the settings folder for
    "Undo the last update". The phone's menu also mentions a new version.
    It checks by itself at most every 12 hours (after a page has already
    been sent, so the phone never waits for it). Needs PHP curl, zip and
    sodium (standard on cPanel PHP 8.x) and permission for PHP to write
    to this folder; if not, the panel says what is missing.
  • FILES (needs Seal Chat 1.7.0+ on your computer): 📎 sends photos,
    videos and files up to 100 MB (on iPhone: Photo Library, Take Photo or
    Choose File). Progress and Cancel in the chat. Photo previews, tap to
    open photos / videos / music, Save or Share… any file. "Download" asks
    your computer to fetch a file it hasn't downloaded yet. Files travel
    as sealed pieces through relay.php like messages — your website can't
    read them, and nothing is stored on this phone unless you tap Save.
    With an older Seal Chat on the computer, 📎 simply doesn't show.
  • Fixed: closing a page opened from a chat (contact details, a file)
    with the back button no longer closes the chat too.
  Updating from 1.1.x: upload everything except config.php (or, from 1.2.0
  on, use "Update now"). New in config.php (optional): 'update_urls'.

WHAT'S NEW IN 1.1.3
  • iPhone top bar, following Apple's guidance ("safe area insets are not a
    replacement for margins"): every header now keeps an extra 12 pt below
    the status bar, clear of iOS 26's blur. The Home Screen app uses an
    opaque status bar, so nothing is drawn under it at all. If you added
    Seal Chat to the Home Screen before, remove the icon and add it again
    once — iOS only reads the status-bar setting when the icon is added.
  Updating from 1.1.2: upload index.php, app.js, app.css, lib.php, README.txt.

WHAT'S NEW IN 1.1.2
  • iPhone: the top of the page (your name, the icon, ＋ and ⋯) no longer
    slides under the status bar. Some iOS versions (Home Screen app,
    iOS 26 Safari) report no status-bar space; Seal Chat Web now checks
    and, if so, leaves room for the status bar's real height itself.
  Updating from 1.1.1: upload app.js, app.css, lib.php and README.txt.

WHAT'S NEW IN 1.1.1 — EMOJI   (works with any Seal Chat 1.4.0+)
  • 😊 button next to the message box. On a phone it opens as a sheet at
    the bottom (the chat moves up so you still see what you type), every
    emoji and button at least 48 px; on a computer it's a small pop-over.
    Search, groups, "Recently used", skin tone.
  • Type ":" and a name — :smile, :+1 — for suggestions; a full ":tada:"
    turns into 🎉 by itself.
  • Messages of just 1–3 emoji show big.
  Updating from 1.1.0: upload all files except config.php. New files:
  emoji-picker.js, emoji-picker.css, vendor/emoji-data.js (~185 KB, loaded
  only when needed) and vendor/emojibase-LICENSE.txt.

WHAT'S NEW IN 1.1.0   (needs Seal Chat 1.6.0 or newer on your computer)
  • CONTACTS on the phone: add a contact by scanning their QR code with
    the phone's camera, from a photo of it, or by pasting their ID;
    accept / decline requests; rename; check the 60-digit safety number
    and mark verified; mute; block; remove. "My Seal Chat ID" shows your
    QR code for someone else to scan (plus Copy / Share). Your computer
    does the real work, exactly as in its own window.
  • NOTIFICATIONS: menu ⋯ → Notifications. "New message" (or "New message
    from Alice" if you allow names) — never the message itself. Sent by
    Seal Chat on your computer, encrypted for this browser only, through
    the phone maker's push service; nothing goes through your website.
    iPhone/iPad: only in the Home Screen version (Share → Add to Home
    Screen), iOS 16.4+. Android: Chrome, Edge, Firefox, Samsung Internet.
    Switch it per device here or in Seal Chat → Settings → Web access.
  • Bigger, easier to tap: every button and field is at least 48 px
    (Apple asks for 44 pt, Android for 48 dp), body text 17 px, nothing
    under 13 px; checked on iPhone 17 and Pixel 9 sizes, including the
    notch / Dynamic Island / home bar.
  Updating from 1.0.0: upload all files except config.php (keep yours).
  New files: sw.js (shows notifications) and img/badge-96.png. Paired
  phones stay paired. Seal Chat 1.5 and older keep working with it, but
  contacts and notifications need Seal Chat 1.6.0.

WHAT IT IS
  Use Seal Chat from your phone's browser (or any browser). You install
  these files on YOUR OWN web hosting. Seal Chat on your computer (1.4.0
  or newer) keeps your key and does all the real work; this web interface
  is a remote screen for it. Between the phone and your computer every
  message is sealed with a key only those two have — the website passes
  the sealed messages along and cannot read them.
  This is separate from the Seal Chat download / landing page website.

FILES
  index.php        the web interface (pairing, PIN, chats)
  app.js           what the screens do
  client.js        talking to your computer through relay.php
  crypto.js        the encryption (WebCrypto: P-256, HKDF, AES-256-GCM)
  relay.php        passes sealed messages between phone and computer
  sw.js            (1.1.0) shows notifications while the page is closed
  admin.php        your setup page: on/off, connection link, computers,
                   admin password
  config.php       the only file to edit by hand (optional)
  lib.php          shared code (blocked from the browser)
  app.css, manifest.webmanifest, img/   look + "Add to Home Screen"
                   (img/badge-96.png: Android's small notification icon)
  assets/          setup page style + script
  vendor/jsQR.js   QR code reader for scanning the pairing code (Apache-2.0)
  emoji-picker.js/.css, vendor/emoji-data.js   (1.1.1) emoji picker and
                   :shortcodes (emoji list: emojibase-data, MIT)
  wake/            small "wake" files Seal Chat watches (made automatically)
  .htaccess        blocks config/lib/.json files, forces HTTPS

INSTALL (shared hosting, PHP 8.x, HTTPS required)
  1. Upload this folder to your hosting, e.g. /public_html/chat/
     → https://yoursite.com/chat/
  2. Open https://yoursite.com/chat/admin.php
  3. Setup code: shown on that page as a file path — open
     <home>/sealchat-web-data/setup-code.txt in cPanel File Manager and
     copy the code. (Or put your own code in config.php first.)
  4. Choose your admin password (10+ characters).
  5. Tick "Turn the web interface on" → Save → "Copy link".
  6. Seal Chat on your computer: Settings → Web access → paste the link →
     Connect → Turn on → "Pair a device…".
  7. Phone: scan the QR code with the camera app, choose a PIN, check the
     6-digit code matches the one on your computer, click Approve there.
     iPhone: open the web interface in Safari, Share → "Add to Home Screen"
     FIRST and pair from that icon — it opens like an app, and Safari can't
     clear it after 7 days without use.

WHERE THE SETTINGS ARE KEPT
  A folder "sealchat-web-data" in your hosting HOME folder (e.g.
  /home/you/sealchat-web-data), outside public_html: admin.json (password
  hash), relay.json (on/off + relay code), setup-code.txt, sessions/ and
  relay/ (one mailbox per connected computer: who may use it + sealed
  messages waiting to be picked up — deleted when picked up, or after 3
  days). The exact path is shown on admin.php. If PHP can't write there,
  a "data" folder here is used instead (blocked by .htaccess).
  Forgot the admin password? Delete admin.json and open admin.php again.

TRAFFIC TO YOUR WEBSITE
  Off (in Seal Chat or here): nothing.
  On, nobody using the web interface: each connected computer asks for
  one tiny static file (wake/<random>.txt) every 30 s (Seal Chat setting:
  15 s – 5 min). The web server answers "304 Not Modified" without running
  PHP — about 1–2 KB per check; cPanel's bandwidth meter counts almost
  nothing for it.
  While the web interface is open: Seal Chat and the phone call relay.php,
  which holds one PHP process for up to 25 s per request; it goes back to
  the tiny checks 5 minutes after the page goes quiet.

SECURITY
  - The website never has your PGP key or readable messages.
  - Notifications (1.1.0, if you turn them on): the push service of your
    phone's maker (Apple / Google / Mozilla) sees that a small sealed
    notification went to your phone and when — not its words. They go
    through Tor when "Connect through Tor" is on in Seal Chat.
  - Each phone/browser is paired by QR code + a code you compare, and
    protected by a PIN that your COMPUTER checks (10 wrong = removed).
  - Remove a device in Seal Chat → Settings → Web access; disconnect a
    computer here on admin.php.
  - "Make a new code" stops NEW computers using the old connection link.
  - admin.php: HTTPS only, bcrypt password, lockout after 5 wrong tries,
    sign-out after 30 idle minutes, CSRF protection.
  - The web interface's code comes from your hosting: keep your hosting
    account's password strong — whoever controls the files controls the
    page your phone runs.

TROUBLESHOOTING
  "Waking your computer…" for a long time: the computer must be on, awake
  and running Seal Chat with Web access on.
  Seal Chat shows a problem reaching the website "through Tor": some hosts
  block Tor — untick "Connect through Tor" in Seal Chat → Web access.
