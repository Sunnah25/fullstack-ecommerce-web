<?php
// ============================================================
// ORDER STATUS SYSTEM
// ============================================================

// All valid statuses in order
define('ORDER_STATUSES', [
    'pending_payment' => [
        'label'  => 'Pending Payment',
        'color'  => '#999',
        'badge'  => 'badge-pending',
        'admin'  => false, // hide from admin lists
    ],
    'paid' => [
        'label'  => 'Paid — Awaiting Dispatch',
        'color'  => '#f59e0b',
        'badge'  => 'badge-pending',
        'admin'  => true,
    ],
    'processing' => [
        'label'  => 'Processing',
        'color'  => '#f59e0b',
        'badge'  => 'badge-pending',
        'admin'  => true,
    ],
    'dispatched' => [
        'label'  => 'Dispatched',
        'color'  => '#1565c0',
        'badge'  => 'badge-dispatched',
        'admin'  => true,
    ],
    'shipped' => [
        'label'  => 'In Transit',
        'color'  => '#7b1fa2',
        'badge'  => 'badge-shipped',
        'admin'  => true,
    ],
    'delivered' => [
        'label'  => 'Delivered',
        'color'  => '#2e7d32',
        'badge'  => 'badge-complete',
        'admin'  => true,
    ],
    'cancelled' => [
        'label'  => 'Cancelled',
        'color'  => '#e53935',
        'badge'  => 'badge-cancelled',
        'admin'  => true,
    ],
    'refunded' => [
        'label'  => 'Refunded',
        'color'  => '#e53935',
        'badge'  => 'badge-cancelled',
        'admin'  => true,
    ],
    'processing_refund' => [
        'label' => '⏳ Processing Refund',
        'color' => '#f59e0b',
        'badge' => 'badge-pending',
        'admin' => true,
    ],
]);

function getStatusLabel($status)
{
    return ORDER_STATUSES[$status]['label']
        ?? ucfirst($status);
}

function getStatusBadge($status)
{
    return ORDER_STATUSES[$status]['badge']
        ?? 'badge-pending';
}

function updateOrderStatus(
    $conn,
    $orderId,
    $newStatus
) {
    if (!isset(ORDER_STATUSES[$newStatus])) {
        error_log("updateOrderStatus: invalid status '$newStatus' for order #$orderId");
        return false;
    }

    $orderId   = intval($orderId);

    $timeField = match ($newStatus) {
        'dispatched' => ', dispatched_at = NOW()',
        'shipped'    => ', shipped_at    = NOW()',
        'delivered'  => ', delivered_at  = NOW()',
        'cancelled'  => ', cancelled_at  = NOW()',
        default      => ''
    };

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE orders SET status = ? $timeField WHERE id = ?"
    );
    mysqli_stmt_bind_param($stmt, "si", $newStatus, $orderId);
    $result = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    return $result;
}
