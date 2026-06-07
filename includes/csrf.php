<?php
// ============================================================
// CSRF PROTECTION
// ============================================================

function generateCsrfToken() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] =
            bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrfToken() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $token = $_POST['csrf_token']
          ?? $_SERVER['HTTP_X_CSRF_TOKEN']
          ?? '';

    if (empty($token)
        || empty($_SESSION['csrf_token'])
        || !hash_equals($_SESSION['csrf_token'],
                        $token)) {
        http_response_code(403);
        error_log("CSRF validation failed from IP: "
                . ($_SERVER['REMOTE_ADDR'] ?? ''));
        die(json_encode([
            'error' => 'Invalid request.
                        Please refresh the page
                        and try again.'
        ]));
    }
    return true;
}

// Output hidden CSRF field for forms
function csrfField() {
    $token = generateCsrfToken();
    return '<input type="hidden"
                   name="csrf_token"
                   value="'
         . htmlspecialchars($token) . '">';
}

// ── Rate Limiting ────────────────────────────────
// Prevents email spam and form abuse
function checkRateLimit($action,
                         $maxAttempts = 5,
                         $windowSeconds = 300) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Use IP address not session
    // so new tabs don't bypass the limit
    $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'rate_' . $action . '_'
         . md5($ip);
    $now = time();

    // Store in session keyed by IP+action
    if (!isset($_SESSION[$key])
        || ($now - $_SESSION[$key]['window_start'])
           > $windowSeconds) {
        $_SESSION[$key] = [
            'attempts'     => 0,
            'window_start' => $now,
        ];
    }

    $_SESSION[$key]['attempts']++;

    if ($_SESSION[$key]['attempts']
        > $maxAttempts) {
        $waitSecs = $windowSeconds
                  - ($now - $_SESSION[$key]
                     ['window_start']);
        return [
            'limited' => true,
            'wait'    => ceil($waitSecs / 60),
        ];
    }

    return ['limited' => false];
}



// Input validation helpers
function sanitiseInt($value, $min = 0,
                      $max = PHP_INT_MAX) {
    $val = intval($value);
    if ($val < $min) return $min;
    if ($val > $max) return $max;
    return $val;
}

function sanitiseFloat($value, $min = 0) {
    $val = floatval($value);
    return $val < $min ? $min : $val;
}

function sanitiseString($conn, $value,
                          $maxLength = 255) {
    $val = trim($value);
    $val = substr($val, 0, $maxLength);
    return mysqli_real_escape_string($conn, $val);
}

function sanitiseEmail($value) {
    $email = trim($value);
    return filter_var($email,
        FILTER_VALIDATE_EMAIL)
        ? $email : '';
}

?>