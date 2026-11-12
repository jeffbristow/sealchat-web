/* =====================================================================
   Seal Chat Web — admin.js   (admin.php helpers)
   Sections: 1. "Copy link" button
             2. (1.2.0) "Are you sure?" for Update now / Undo, and a
                "Working…" button while the update runs
   ===================================================================== */
(function () {
  'use strict';

  /* ---------- 1. Copy link ---------- */
  document.addEventListener('click', function (e) {
    var b = e.target.closest('[data-copy]');
    if (!b || !navigator.clipboard) return;
    navigator.clipboard.writeText(b.getAttribute('data-copy')).then(function () {
      var t = b.textContent; b.textContent = 'Copied ✓'; setTimeout(function () { b.textContent = t; }, 1500);
    });
  });

  /* ---------- 2. Confirm + working ---------- */
  document.addEventListener('submit', function (e) {
    var f = e.target;
    var q = f.getAttribute('data-confirm');
    if (q && !window.confirm(q)) { e.preventDefault(); return; }
    if (q) {
      var btn = f.querySelector('button');
      if (btn) { setTimeout(function () { btn.disabled = true; btn.textContent = 'Working… (this can take a minute)'; }, 0); }
    }
  });
})();
