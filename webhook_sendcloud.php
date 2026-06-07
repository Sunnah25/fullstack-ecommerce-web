<?php
require_once 'config.php';
include 'includes/db.php';
include 'includes/order_status.php';

// ── SECURITY: Only accept POST ───────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(403);
    exit('Forbidden.');
}

// ── SECURITY: Verify Sendcloud signature ─────────
// Sendcloud signs webhooks with your secret key
$signature = $_SERVER['HTTP_SENDCLOUD_SIGNATURE']
    ?? '';
$payload   = file_get_contents('php://input');

// Only verify signature on live site
// (Sendcloud doesn't sign test webhooks)
if (!empty($signature) && !empty($payload)) {
    $expectedSig = hash_hmac(
        'sha256',
        $payload,
        SENDCLOUD_SECRET_KEY
    );
    if (!hash_equals($expectedSig, $signature)) {
        http_response_code(401);
        exit('Invalid signature.');
    }
}

// ── SECURITY: Only allow Sendcloud IPs ───────────
// https://support.sendcloud.com/hc/en-us/articles/360024965491
$allowedIPs = [
    '94.232.247.0/24',   // Sendcloud IP range
    '185.66.200.0/24',   // Sendcloud IP range
    '52.58.233.101',    // Sendcloud Real IP
    '145.241.211.43',  //My server (for testing)
    'DB_HOST',         // localhost for testing
    '::1',               // localhost IPv6
];

$clientIP = $_SERVER['REMOTE_ADDR'] ?? '';
$allowed  = false;

foreach ($allowedIPs as $range) {
    if (strpos($range, '/') !== false) {
        // CIDR range check
        list($subnet, $bits) = explode('/', $range);
        $ip      = ip2long($clientIP);
        $subnet  = ip2long($subnet);
        $mask    = -1 << (32 - intval($bits));
        $subnet &= $mask;
        if (($ip & $mask) === $subnet) {
            $allowed = true;
            break;
        }
    } else {
        if ($clientIP === $range) {
            $allowed = true;
            break;
        }
    }
}

if (!$allowed) {
    http_response_code(403);
    error_log("Webhook blocked from IP: $clientIP");
    exit('Forbidden.');
}

// Rest of webhook code continues...

// Get raw payload
$payload = file_get_contents('php://input');
$data    = json_decode($payload, true);

if (!$data) {
    http_response_code(400);
    exit('Invalid JSON');
}

// Log webhook for debugging
mysqli_query(
    $conn,
    "INSERT INTO webhook_logs
     (source, payload, processed)
     VALUES ('sendcloud', '"
        . mysqli_real_escape_string($conn, $payload)
        . "', 0)"
);

// ── Process the webhook ──────────────────────────────────────

$action  = $data['action'] ?? '';
$message = $data['message'] ?? [];
$parcel  = $message['parcel'] ?? [];

if (empty($parcel)) {
    http_response_code(200);
    exit('No parcel data');
}

$trackingNumber = $parcel['tracking_number'] ?? '';
$statusCode     = $parcel['status']['id']    ?? 0;
$statusMessage  = $parcel['status']['message'] ?? '';
$orderNumber    = $parcel['order_number']    ?? '';

// Log what we received
error_log("Sendcloud webhook: order=$orderNumber "
    . "tracking=$trackingNumber "
    . "status=$statusCode ($statusMessage)");

// Find order by order number or tracking number
$orderId = 0;
if ($orderNumber) {
    $orderId = intval($orderNumber);
} elseif ($trackingNumber) {
    $trackingSafe = mysqli_real_escape_string(
        $conn,
        $trackingNumber
    );
    $row = mysqli_fetch_assoc(mysqli_query(
        $conn,
        "SELECT id FROM orders
         WHERE tracking_number = '$trackingSafe'
         LIMIT 1"
    ));
    if ($row) $orderId = $row['id'];
}

if (!$orderId) {
    http_response_code(200);
    exit('Order not found');
}

// ── Map Sendcloud status codes to our statuses ───────────────
// Full list: https://support.sendcloud.com/hc/en-us/articles/360024959612
$newStatus = null;

// In transit / out for delivery
$inTransitCodes = [
    3,   // Delivered
    4,   // At sorting centre
    5,   // Delivered
    7,   // At customs
    8,   // Customs cleared
    11,  // Sorting
    12,  // Announced
    13,  // In delivery
    14,  // Driver on way
    15,  // Collected
    17,  // At carrier
    22,  // Shipment collected by driver
    25,  // Carrier en route
    26,  // Delivery attempt failed
    36,  // In transit
    41,  // Sorting complete
    43,  // Driver en route
    44,  // At pickup point
    47,  // Delivery attempted
    48,  // Delivery rescheduled
    50,  // Collected by recipient
    92,  // Out for delivery
    93,  // In transit
    94,  // At delivery point
    95,  // Delivered to neighbour
    96,  // Delivered to safe place
    97,  // Collection attempted
    98,  // Awaiting collection
    99,  // Ready to ship
    1000, // Announced
    1001, // In transit
    1002, // Sorting
    1003, // Collected
];

// Delivered codes
$deliveredCodes = [
    11,  // Delivered
    12,  // At destination
];

// Sendcloud specific delivered status
if (
    $statusCode === 11
    || stripos($statusMessage, 'delivered') !== false
    || stripos($statusMessage, 'delivery') !== false
) {
    $newStatus = 'delivered';
} elseif (
    in_array($statusCode, $inTransitCodes)
    || stripos($statusMessage, 'transit') !== false
    || stripos($statusMessage, 'sorting') !== false
    || stripos($statusMessage, 'collected') !== false
    || stripos($statusMessage, 'dispatch') !== false
) {
    $newStatus = 'shipped';
}

// Update order status if we have a new status
if ($newStatus) {
    $currentOrder = mysqli_fetch_assoc(mysqli_query(
        $conn,
        "SELECT status FROM orders WHERE id = $orderId"
    ));

    // Only update forward — never go backwards
    $statusOrder = [
        'pending_payment' => 0,
        'paid'            => 1,
        'processing'      => 2,
        'dispatched'      => 3,
        'shipped'         => 4,
        'delivered'       => 5,
    ];

    $currentLevel = $statusOrder[$currentOrder['status'] ?? ''] ?? 0;
    $newLevel     = $statusOrder[$newStatus] ?? 0;

    if ($newLevel > $currentLevel) {
        updateOrderStatus($conn, $orderId, $newStatus);

        // Send delivery email if delivered
        if ($newStatus === 'delivered') {
            require_once 'includes/mailer.php';
            sendDeliveryEmail($conn, $orderId);
        }

        error_log("Order #$orderId updated to $newStatus");
    }
}

// Mark webhook as processed
mysqli_query(
    $conn,
    "UPDATE webhook_logs
     SET processed = 1
     WHERE source = 'sendcloud'
     ORDER BY id DESC LIMIT 1"
);

// Always return 200 to Sendcloud
http_response_code(200);
echo json_encode(['status' => 'ok']);
