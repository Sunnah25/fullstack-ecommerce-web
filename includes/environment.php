<?php
// ============================================================
// ENVIRONMENT CONFIGURATION
// Set ENVIRONMENT in .env to 'production' on live server
// ============================================================

// Ensure logs directory exists and is writable
$logsDir = __DIR__ . '/../logs';
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0750, true);
}
unset($logsDir);


// Load .env file manually
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue; // skip comments
        if (strpos($line, '=') !== false) {
            [$key, $value] = explode('=', $line, 2);
            putenv(trim($key) . '=' . trim($value));
        }
    }
}

$environment = getenv('ENVIRONMENT') ?: 'production';

if ($environment === 'production') {

    // Hide ALL errors from browser
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(0);

    // Log errors to file instead
    ini_set('log_errors', '1');
    ini_set(
        'error_log',
        __DIR__ . '/../logs/error.log'
    );
} else {
    // Development — show errors
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

// ── Custom error handler ─────────────────────────
// Shows friendly message instead of
// raw PHP errors on production
set_error_handler(function (
    $errno,
    $errstr,
    $errfile,
    $errline
) {
    global $environment;

    // Always log
    error_log("Error [$errno]: $errstr
               in $errfile on line $errline");

    if ($environment === 'production') {
        // Show friendly page not raw error
        if (!headers_sent()) {
            http_response_code(500);
        }

        return true; // Don't execute PHP
        // internal error handler
    }
    return false; // Use PHP default in dev
});

// Catch fatal errors that set_error_handler misses
register_shutdown_function(function () use ($environment) {
    $error = error_get_last();
    if (!$error) return;

    $fatalTypes = [
        E_ERROR,
        E_PARSE,
        E_CORE_ERROR,
        E_COMPILE_ERROR,
        E_USER_ERROR
    ];

    if (!in_array($error['type'], $fatalTypes)) return;

    error_log("Fatal error: {$error['message']}"
        . " in {$error['file']}"
        . " on line {$error['line']}");

    if (ob_get_level()) ob_end_clean();
    if (!headers_sent()) http_response_code(500);

    if ($environment !== 'production') {
        echo '<div style="font-family:monospace;
              background:#fff3f3; padding:20px;
              border:2px solid #e53935; margin:20px;">'
            . '<h2 style="color:#e53935;">Fatal Error</h2>'
            . '<p>' . htmlspecialchars($error['message'])
            . '</p><p style="color:#999;">'
            . htmlspecialchars($error['file'])
            . ' line ' . $error['line'] . '</p></div>';
    } else {
        echo '<!DOCTYPE html><html><head>'
            . '<title>Something went wrong</title></head>'
            . '<body style="font-family:Georgia;'
            . 'text-align:center;padding:80px;">'
            . '<h1>Something went wrong</h1>'
            . '<p>Please try again shortly.</p>'
            . '</body></html>';
    }
    exit();
});

// ── Custom exception handler ─────────────────────
set_exception_handler(function ($exception) {

    // Log it
    error_log("Uncaught exception: "
        . $exception->getMessage()
        . " in " . $exception->getFile()
        . " on line " . $exception->getLine());

    $isDev = (getenv('ENVIRONMENT') ?: 'production')
        === 'development';

    // Clear any buffered output
    if (ob_get_level()) ob_end_clean();

    if (!headers_sent()) {
        http_response_code(500);
    }

    if ($isDev) {
        // Development: show full error
        echo '<div style="font-family:monospace;
              background:#fff3f3; padding:20px;
              border:2px solid #e53935;
              margin:20px;">';
        echo '<h2 style="color:#e53935;">Exception</h2>';
        echo '<p><strong>'
            . htmlspecialchars($exception->getMessage())
            . '</strong></p>';
        echo '<pre style="font-size:12px;
              overflow:auto;">'
            . htmlspecialchars(
                $exception->getTraceAsString()
            )
            . '</pre>';
        echo '</div>';
    } else {
        // Production: friendly page
        echo '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Something went wrong</title>
  <style>
    body { font-family:Georgia,serif;
           text-align:center; padding:80px 20px;
           background:#f5f0eb; color:#2c2c2c; }
    h1   { color:#1a1a1a; letter-spacing:3px;
           font-weight:normal; }
    p    { color:#888; margin-top:16px; }
    a    { color:#d4af7a; }
  </style>
</head>
<body>
  <h1>🌸 Something Went Wrong</h1>
  <p>We are looking into it.<br>
     Please try again shortly.</p>
  <p><a href="/">Return to Homepage</a></p>
</body>
</html>';
    }
    exit();
});

// ── Security headers ─────────────────────────────
// Only send if not CLI and headers not sent yet
if (
    php_sapi_name() !== 'cli'
    && !headers_sent()
) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}



// ── Upload limits ─────────────────────────────────
// Prevent DoS via huge file uploads
ini_set('upload_max_filesize', '5M');
ini_set('post_max_size',       '6M');
ini_set('max_execution_time',  '30');
ini_set('max_input_time',      '30');
ini_set('memory_limit',        '128M');




// Secure session configuration
// Session settings must be set BEFORE
// session_start() is called
// Only set if session not yet started
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', '3600');
    ini_set('session.cookie_lifetime', '0');
    // Only set secure on production HTTPS
    // On localhost this would break sessions
    if (
        !empty($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off'
    ) {
        ini_set('session.cookie_secure', '1');
    }
}

