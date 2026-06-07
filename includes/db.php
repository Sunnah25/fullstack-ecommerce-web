<?php
if (!defined('ENVIRONMENT_LOADED')) {
    define('ENVIRONMENT_LOADED', true);
    require_once __DIR__ . '/environment.php';
}

// Ensure config constants are loaded
if (!defined('DB_HOST')) {
    require_once __DIR__ . '/../config.php';
}

// Catch connection errors before exception
// handler gets them
try {
    $conn = mysqli_connect(
        DB_HOST,
        DB_USER,
        DB_PASS,
        DB_NAME
    );

    if (!$conn) {
        throw new Exception(
            mysqli_connect_error()
        );
    }

    mysqli_set_charset($conn, 'utf8mb4');

    
} catch (Exception $e) {

    error_log("DB CONNECTION FAILED: "
        . $e->getMessage());

    if (ob_get_level()) ob_end_clean();

    if (!headers_sent()) {
        header('HTTP/1.1 503 Service Unavailable');
        header('Retry-After: 300');
    }

    $isDev = (getenv('ENVIRONMENT')
        ?: 'development') === 'development';

    if ($isDev) {
        echo '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Database Unavailable</title>
  <style>
    body { font-family:Georgia,serif;
           text-align:center; padding:80px 20px;
           background:#f5f0eb; }
    .box { background:#fff3f3;
           border:2px solid #e53935;
           padding:30px; max-width:600px;
           margin:0 auto; text-align:left; }
    h1   { color:#e53935; font-size:1.2rem; }
    pre  { font-size:11px; overflow:auto;
           background:#fff; padding:10px; }
  </style>
</head>
<body>
  <div class="box">
    <h1>⚠️ Database Connection Failed</h1>
    <p><strong>Error:</strong> '
            . htmlspecialchars($e->getMessage()) . '</p>
    <p style="color:#888; font-size:0.82rem;">
      This message only shows in development mode.<br>
      Check that MySQL is running in XAMPP.
    </p>
  </div>
</body>
</html>';
    } else {
        echo '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Be Right Back</title>
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
  <h1>🌸 We\'ll Be Right Back</h1>
  <p>We are performing maintenance.<br>
     Please try again in a few minutes.</p>
  <p><a href="/">Refresh</a></p>
</body>
</html>';
    }
    exit();
}
?>