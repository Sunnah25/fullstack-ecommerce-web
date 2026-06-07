<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => false,   // Set it to true only on your live production server
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// ── Check logged in ──────────────────────────────
if (!isset($_SESSION['admin_logged_in'])
    || $_SESSION['admin_logged_in'] !== true) {
    header('Location: /admin/login'
         . '?redirect=' . urlencode(
             $_SERVER['REQUEST_URI'] ?? ''));
    exit();
}

// ── Session timeout (2 hours inactivity) ─────────
$timeout = 7200;
if (isset($_SESSION['admin_last_activity'])
    && (time() - $_SESSION['admin_last_activity'])
       > $timeout) {
    session_unset();
    session_destroy();
    header('Location: /admin/login'
         . '?timeout=1');
    exit();
}
$_SESSION['admin_last_activity'] = time();

// ── IP check — flag if IP changes mid-session ─────
// Prevents session token theft from another machine
if (isset($_SESSION['admin_ip'])
    && $_SESSION['admin_ip']
       !== ($_SERVER['REMOTE_ADDR'] ?? '')) {
    error_log("Admin session IP mismatch! "
            . "Session IP: {$_SESSION['admin_ip']} "
            . "Current IP: "
            . ($_SERVER['REMOTE_ADDR'] ?? ''));
    // Log it but don't block
    // (IP can change on mobile networks)
    // Change to session_destroy() if you want strict
}

// ── Block direct access to admin via non-web ──────
// Ensure request comes through web server
if (php_sapi_name() === 'cli') {
    exit('CLI access not allowed.');
}
?>