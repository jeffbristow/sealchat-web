<?php
/* =====================================================================
 * Seal Chat Web — config.php   (the only file to edit by hand)
 * ---------------------------------------------------------------------
 * Seal Chat Web is the web interface for YOUR Seal Chat: open it on your
 * phone and chat through Seal Chat running on your computer.
 * Everything else (on/off, the connection link, your admin password) is
 * set in admin.php.
 *
 * Sections:
 *   1. Guard
 *   2. Setup code
 *   3. Settings location
 *   4. Security switches
 *   5. Updates (1.2.0)
 * ===================================================================== */

/* ---------------------------------------------------------------------
 * 1. GUARD — this file is only read by the other PHP files
 * ------------------------------------------------------------------- */
if (!defined('SEALCHAT_WEB')) {
    http_response_code(404);
    exit;
}

return [
    /* -----------------------------------------------------------------
     * 2. SETUP CODE
     *    Asked for once, the first time you open admin.php, before you
     *    choose your admin password. Change it to your own secret if you
     *    like (letters, digits and dashes).
     * --------------------------------------------------------------- */
    'setup_code' => 'CHANGE-ME-TO-YOUR-OWN-CODE',

    /* -----------------------------------------------------------------
     * 3. SETTINGS LOCATION
     *    '' = automatic: a folder "sealchat-web-data" in your hosting
     *         account's HOME folder (e.g. /home/you/sealchat-web-data),
     *         outside public_html, so it can never be downloaded.
     *    Or a full path, e.g. '/home/you/sealchat-web-data'.
     *    It holds admin.json (your password hash), relay.json (on/off +
     *    relay code) and relay/ (sealed messages waiting to be picked up).
     * --------------------------------------------------------------- */
    'data_dir' => '',

    /* -----------------------------------------------------------------
     * 4. SECURITY SWITCHES
     *    require_https:   admin.php and the web interface only work over
     *                     https:// (localhost is allowed for testing).
     *    login_failures:  wrong admin passwords allowed from one internet
     *                     address within 'lockout_minutes'.
     *    session_minutes: signed out of admin.php after this long idle.
     * --------------------------------------------------------------- */
    'require_https'   => true,
    'login_failures'  => 5,
    'lockout_minutes' => 15,
    'session_minutes' => 30,
    'timezone'        => 'America/New_York',

    /* -----------------------------------------------------------------
     * 5. UPDATES (1.2.0)  — admin.php → Updates
     *    update_urls: where to look for a newer Seal Chat Web, in order.
     *                 [] = GitHub (jeffbristow/sealchat-updates) only.
     *    (Older config.php files without this line work the same.)
     * --------------------------------------------------------------- */
    'update_urls'     => [],
];
