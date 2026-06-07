<?php
// ============================================================
// CENTRALISED LOGGING
// ============================================================

function logPayment(
    $conn,
    $orderId,
    $eventType,
    $status,
    $amount = 0,
    $stripeId = '',
    $notes = ''
) {
    $ip  = $_SERVER['REMOTE_ADDR'] ?? '';
    $oid = intval($orderId);
    $amt = floatval($amount);

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO payment_logs
         (order_id, event_type, status, amount,
          stripe_id, ip_address, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        error_log("logPayment prepare failed: " . mysqli_error($conn));
        return;
    }
    mysqli_stmt_bind_param(
        $stmt,
        "issdsss",
        $oid,
        $eventType,
        $status,
        $amt,
        $stripeId,
        $ip,
        $notes
    );
    if (!mysqli_stmt_execute($stmt)) {
        error_log("logPayment execute failed: " . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
}

function logWebhook(
    $conn,
    $source,
    $eventType,
    $payload,
    $processed = 0,
    $errorMsg = ''
) {
    $payload   = substr($payload, 0, 5000);
    $processed = intval($processed);

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO webhook_logs
         (source, event_type, payload,
          processed, error_msg)
         VALUES (?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        error_log("logWebhook prepare failed: " . mysqli_error($conn));
        return;
    }
    mysqli_stmt_bind_param(
        $stmt,
        "sssis",
        $source,
        $eventType,
        $payload,
        $processed,
        $errorMsg
    );
    if (!mysqli_stmt_execute($stmt)) {
        error_log("logWebhook execute failed: " . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
}

function logError(
    $context,
    $message,
    $severity = 'ERROR'
) {
    $timestamp = date('Y-m-d H:i:s');
    $ip        = $_SERVER['REMOTE_ADDR'] ?? 'cli';
    $url       = $_SERVER['REQUEST_URI'] ?? '';
    $line      = "[$timestamp] [$severity]"
        . " [$context] [$ip] $url"
        . " — $message\n";

    error_log(
        $line,
        3,
        __DIR__ . '/../logs/error.log'
    );
}
