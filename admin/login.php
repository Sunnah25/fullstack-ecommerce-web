<?php
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => false, // set to true on live HTTPS server
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

// If already logged in, go straight to dashboard
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: /admin/dashboard');
    exit();
}

require_once '../config.php';
include '../includes/db.php';
require_once '../includes/csrf.php';

$error = '';


// Rate limit login attempts
// max 5 attempts per 15 minutes per IP
$limit = checkRateLimit('admin_login', 5, 900);
if ($limit['limited']) {
    $error = "Too many login attempts.
              Please wait "
        . $limit['wait']
        . " minute(s) before trying again.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrfToken();

    $username = trim(mysqli_real_escape_string($conn, $_POST['username']));

    // Check against .env credentials directly
    $envUsername     = getenv('ADMIN_USERNAME');
    $envPasswordHash = getenv('ADMIN_PASSWORD_HASH');

    $usernameMatch = hash_equals($envUsername, $username);
    $passwordMatch = password_verify($_POST['password'], $envPasswordHash);

    if ($usernameMatch && $passwordMatch) {
        // Regenerate session ID on login
        // Prevents session fixation attacks
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username']  =
            $envUsername;
        $_SESSION['admin_login_time'] = time();
        $_SESSION['admin_ip'] =
            $_SERVER['REMOTE_ADDR'] ?? '';



        header('Location: /admin/dashboard');
        exit();
    } else {
        $error = "Invalid username or password.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Genova Perfumes</title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/admin.css">
</head>

<body class="admin-body">

    <div class="admin-login-wrap">
        <div class="admin-login-box">

            <div class="admin-login-logo">🌸</div>
            <h1>Admin Panel</h1>
            <p>Genova Perfumes Management</p>

            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['timeout'])): ?>
                <div class="alert alert-error">
                    Session expired. Please log in again.
                </div>
            <?php endif; ?>

            <form method="POST" action="" class="admin-login-form">
                <?php echo csrfField(); ?>
                <div class="form-group">
                    <label>Username</label>
                    <input type="text"
                        name="username"
                        placeholder="Enter username"
                        value="<?php echo isset($username) ? htmlspecialchars($username) : ''; ?>"
                        required autofocus>
                </div>
                <div class="form-group">
                    <label>Password</label>
                    <input type="password"
                        name="password"
                        placeholder="Enter password"
                        required>
                </div>
                <button type="submit" class="btn-primary btn-login">Login →</button>
            </form>

        </div>
    </div>

</body>

</html>