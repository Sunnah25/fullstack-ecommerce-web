<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../vendor/autoload.php';

function sendEmail($to, $toName, $subject, $htmlBody)
{
  $mail = new PHPMailer(true);

  try {
    // Server settings
    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USERNAME;
    $mail->Password   = MAIL_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = MAIL_PORT;

    // Sender
    $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
    $mail->addReplyTo(MAIL_FROM_EMAIL, MAIL_FROM_NAME);

    // Recipient
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
      error_log("sendEmail: invalid email address: " . $to);
      return false;
    }
    $mail->addAddress($to, $toName);

    // Content
    $mail->isHTML(true);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = $subject;
    $mail->Body    = $htmlBody;
    $mail->AltBody = strip_tags(
      str_replace(
        ['<br>', '<br/>', '<br />'],
        "\n",
        $htmlBody
      )
    );

    $mail->send();
    return true;
  } catch (Exception $e) {
    error_log("Email failed"
      . " [to: $to]"
      . " [subject: $subject]"
      . " [error: " . $mail->ErrorInfo . "]");
    return false;
  }
}


// Shared email sender with optional attachment
// Uses same SMTP config as sendEmail()
function sendEmailWithAttachment(
  $to,
  $toName,
  $subject,
  $htmlBody,
  $attachPath = null,
  $attachName = 'attachment'
) {

  if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    error_log("sendEmailWithAttachment: "
      . "invalid email: " . $to);
    return false;
  }

  $mail = new PHPMailer(true);
  try {
    $mail->isSMTP();
    $mail->Host       = MAIL_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_USERNAME;
    $mail->Password   = MAIL_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = MAIL_PORT;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
    $mail->addReplyTo(MAIL_FROM_EMAIL, MAIL_FROM_NAME);
    $mail->addAddress($to, $toName);

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body    = $htmlBody;
    $mail->AltBody = strip_tags(str_replace(
      ['<br>', '<br/>', '<br />'],
      "\n",
      $htmlBody
    ));

    if ($attachPath && file_exists($attachPath)) {
      $mail->addAttachment($attachPath, $attachName);
    }

    $mail->send();
    return true;
  } catch (Exception $e) {
    error_log("Email with attachment failed"
      . " [to: $to] [subject: $subject]: "
      . $mail->ErrorInfo);
    return false;
  }
}


// ── Email Templates ──────────────────────────────────────────

function emailHeader($title)
{
  return '
    <!DOCTYPE html>
    <html>
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width">
      <style>
        body {
          margin: 0; padding: 0;
          font-family: Georgia, serif;
          background: #f5f0eb;
          color: #2c2c2c;
        }
        .wrapper {
          max-width: 600px;
          margin: 30px auto;
          background: #fff;
          border: 1px solid #ede0d0;
        }
        .header {
          background: #1a1a1a;
          padding: 30px 40px;
          text-align: center;
        }
        .header h1 {
          color: #d4af7a;
          font-size: 24px;
          letter-spacing: 4px;
          margin: 0 0 4px;
          font-weight: normal;
        }
        .header p {
          color: #888;
          font-size: 12px;
          letter-spacing: 2px;
          margin: 0;
          text-transform: uppercase;
        }
        .content {
          padding: 40px;
        }
        .content h2 {
          color: #3d2b1f;
          font-size: 20px;
          letter-spacing: 1px;
          margin: 0 0 16px;
          font-weight: normal;
        }
        .content p {
          color: #555;
          font-size: 14px;
          line-height: 1.8;
          margin: 0 0 14px;
        }
        .order-box {
          background: #fdf8f4;
          border: 1px solid #ede0d0;
          border-left: 4px solid #d4af7a;
          padding: 20px 24px;
          margin: 24px 0;
        }
        .order-box p {
          margin: 4px 0;
          font-size: 14px;
        }
        .order-box strong {
          color: #1a1a1a;
        }
        .items-table {
          width: 100%;
          border-collapse: collapse;
          margin: 20px 0;
        }
        .items-table th {
          background: #1a1a1a;
          color: #d4af7a;
          padding: 10px 14px;
          text-align: left;
          font-size: 11px;
          letter-spacing: 1px;
          text-transform: uppercase;
          font-weight: normal;
        }
        .items-table td {
          padding: 12px 14px;
          border-bottom: 1px solid #f0ebe4;
          font-size: 13px;
          color: #444;
        }
        .total-row td {
          border-top: 2px solid #1a1a1a;
          border-bottom: none;
          font-weight: bold;
          color: #1a1a1a;
          font-size: 15px;
          padding-top: 14px;
        }
        .btn {
          display: inline-block;
          background: #d4af7a;
          color: #1a1a1a;
          padding: 14px 32px;
          text-decoration: none;
          font-family: Georgia, serif;
          font-size: 13px;
          letter-spacing: 2px;
          text-transform: uppercase;
          margin: 8px 0;
        }
        .tracking-box {
          background: #f0faf0;
          border: 1px solid #c8e6c9;
          border-left: 4px solid #4caf50;
          padding: 20px 24px;
          margin: 24px 0;
          text-align: center;
        }
        .tracking-number {
          font-size: 22px;
          font-family: monospace;
          color: #1a1a1a;
          letter-spacing: 3px;
          font-weight: bold;
          margin: 8px 0;
        }
        .footer {
          background: #1a1a1a;
          padding: 24px 40px;
          text-align: center;
        }
        .footer p {
          color: #666;
          font-size: 11px;
          letter-spacing: 1px;
          margin: 4px 0;
        }
        .footer a {
          color: #d4af7a;
          text-decoration: none;
        }
      </style>
    </head>
    <body>
    <div class="wrapper">
      <div class="header">
        <h1>🌸 ' . SHOP_NAME . '</h1>
        <p>' . $title . '</p>
      </div>
      <div class="content">';
}

function emailFooter()
{
  return '
      </div>
      <div class="footer">
        <p>© ' . date('Y') . ' ' . SHOP_NAME . '</p>
        <p>Questions? <a href="mailto:' . SHOP_EMAIL . '">'
    . SHOP_EMAIL . '</a></p>
        <p>
          <a href="' . SHOP_URL . '/privacy-policy">Privacy Policy</a>
          &nbsp;·&nbsp;
          <a href="' . SHOP_URL . '/returns">Returns</a>
          &nbsp;·&nbsp;
          <a href="' . SHOP_URL . '/contact">Contact</a>
        </p>
      </div>
    </div>
    </body>
    </html>';
}

// ── Send Order Confirmation Email ────────────────────────────
function sendOrderConfirmation($conn, $orderId)
{
  $stmt = mysqli_prepare($conn, "SELECT * FROM orders WHERE id = ?");
  mysqli_stmt_bind_param($stmt, "i", $orderId);
  mysqli_stmt_execute($stmt);
  $order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
  mysqli_stmt_close($stmt);
  if (!$order) return false;

  $stmt = mysqli_prepare(
    $conn,
    "SELECT oi.*, p.name
         FROM order_items oi
         JOIN products p ON oi.product_id = p.id
         WHERE oi.order_id = ?"
  );
  mysqli_stmt_bind_param($stmt, "i", $orderId);
  mysqli_stmt_execute($stmt);
  $items = mysqli_stmt_get_result($stmt);
  mysqli_stmt_close($stmt);


  $itemsHtml = '';
  while ($item = mysqli_fetch_assoc($items)) {
    $subtotal   = $item['price'] * $item['quantity'];
    $itemsHtml .= '
        <tr>
          <td>' . htmlspecialchars($item['name']) . '</td>
          <td style="text-align:center;">'
      . $item['quantity'] . '</td>
          <td style="text-align:right;">£'
      . number_format($item['price'], 2) . '</td>
          <td style="text-align:right;">£'
      . number_format($subtotal, 2) . '</td>
        </tr>';
  }

  $body = emailHeader('Order Confirmation') . '

      <h2>Thank you for your order! 🌸</h2>
      <p>Hi ' . htmlspecialchars($order['customer_name'])
    . ', your order has been confirmed and
          we are preparing it for dispatch.</p>

      <div class="order-box">
        <p><strong>Order #'
    . str_pad($orderId, 6, '0', STR_PAD_LEFT)
    . '</strong></p>
        <p>Date: '
    . date('d F Y', strtotime($order['created_at']))
    . '</p>
        <p>Delivering to: '
    . htmlspecialchars($order['customer_address'])
    . '</p>
        <p>🚚 Free delivery on all orders</p>
      </div>

      <table class="items-table">
        <thead>
          <tr>
            <th>Product</th>
            <th style="text-align:center;">Qty</th>
            <th style="text-align:right;">Price</th>
            <th style="text-align:right;">Subtotal</th>
          </tr>
        </thead>
        <tbody>
          ' . $itemsHtml . '
          <tr class="total-row">
            <td colspan="3">Total</td>
            <td style="text-align:right;">£'
    . number_format($order['total_amount'], 2)
    . '</td>
          </tr>
        </tbody>
      </table>

      <p>We will send you another email with your
         tracking number as soon as your order is
         dispatched. This is usually within 1-2
         working days.</p>

      <p>If you have any questions, reply to this
         email or visit our
         <a href="' . SHOP_URL . '/contact"
            style="color:#d4af7a;">contact page</a>.
      </p>

    ' . emailFooter();

  return sendEmail(
    $order['customer_email'],
    $order['customer_name'],
    'Order Confirmed — #'
      . str_pad($orderId, 6, '0', STR_PAD_LEFT)
      . ' | ' . SHOP_NAME,
    $body
  );
}

// ── Send Dispatch Email ──────────────────────────────────────
function sendDispatchEmail($conn, $orderId)
{
  $stmt = mysqli_prepare($conn, "SELECT * FROM orders WHERE id = ?");
  mysqli_stmt_bind_param($stmt, "i", $orderId);
  mysqli_stmt_execute($stmt);
  $order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
  mysqli_stmt_close($stmt);
  if (!$order) return false;

  $tracking    = $order['tracking_number'] ?? '';
  $trackingUrl = $order['tracking_url'] ?? '';
  $carrier     = $order['shipping_method'] ?? 'your carrier';

  $trackingSection = '';
  if ($tracking) {
    $trackingSection = '
        <div class="tracking-box">
          <p style="color:#2e7d32; font-size:13px;
                    margin:0 0 6px; letter-spacing:1px;
                    text-transform:uppercase;">
            Your Tracking Number
          </p>
          <div class="tracking-number">'
      . htmlspecialchars($tracking) . '</div>
          ' . ($trackingUrl ? '
          <br>
          <a href="' . htmlspecialchars($trackingUrl) . '"
             class="btn" style="background:#4caf50;">
            Track Your Parcel →
          </a>' : '') . '
        </div>';
  }

  $body = emailHeader('Your Order Is On Its Way!') . '

      <h2>Great news — your order is dispatched! 🚚</h2>
      <p>Hi ' . htmlspecialchars($order['customer_name'])
    . ', your order has been packed and
          handed to ' . htmlspecialchars($carrier)
    . '. It is now on its way to you!</p>

      <div class="order-box">
        <p><strong>Order #'
    . str_pad($orderId, 6, '0', STR_PAD_LEFT)
    . '</strong></p>
        <p>Delivering to: '
    . htmlspecialchars($order['customer_address'])
    . '</p>
        <p>Carrier: '
    . htmlspecialchars($carrier) . '</p>
      </div>

      ' . $trackingSection . '

      <p style="text-align:center; margin:24px 0;">
        <a href="' . SHOP_URL . '/track"class="btn">
            Track Your Order →
        </a>
      </p>

      <p>Delivery usually takes 1-3 working days
         depending on your location and the service
         selected.</p>

      <p>Not happy with your order?
         <a href="' . SHOP_URL . '/returns"
            style="color:#d4af7a;">
           Start a return here
         </a>.
      </p>

    ' . emailFooter();

  return sendEmail(
    $order['customer_email'],
    $order['customer_name'],
    'Your Order Is On Its Way! 🚚 — #'
      . str_pad($orderId, 6, '0', STR_PAD_LEFT),
    $body
  );
}


// ── Send Delivery Confirmation Email ────────────────────────
function sendDeliveryEmail($conn, $orderId)
{
  $stmt = mysqli_prepare($conn, "SELECT * FROM orders WHERE id = ?");
  mysqli_stmt_bind_param($stmt, "i", $orderId);
  mysqli_stmt_execute($stmt);
  $order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
  mysqli_stmt_close($stmt);
  if (!$order) return false;

  $body = emailHeader('Your Order Has Arrived!') . '

      <h2>Your order has been delivered! 🎉</h2>
      <p>Hi ' . htmlspecialchars($order['customer_name'])
    . ', your order has been delivered.
          We hope you love your new fragrance!</p>

      <div class="order-box">
        <p><strong>Order #'
    . str_pad($orderId, 6, '0', STR_PAD_LEFT)
    . '</strong></p>
        <p>Delivered to: '
    . htmlspecialchars($order['customer_address'])
    . '</p>
      </div>

      <p>Enjoying your fragrance? We would love
         to hear from you!</p>

      <p>Not completely happy?
         <a href="' . SHOP_URL . '/returns"
            style="color:#d4af7a;">
           Start a return within 30 days
         </a>.
      </p>

      <p style="text-align:center; margin-top:30px;">
        <a href="' . SHOP_URL . '/products"
           class="btn">
          Shop Again →
        </a>
      </p>

    ' . emailFooter();

  return sendEmail(
    $order['customer_email'],
    $order['customer_name'],
    'Your Order Has Been Delivered 🌸 — '
      . SHOP_NAME,
    $body
  );
}


// ── Return Request Confirmation Email ───────────────────────
function sendReturnRequestEmail(
  $conn,
  $returnId,
  $order
) {
  $typeLabels = [
    'return'                    => 'Return',
    'refund'                    => 'Refund',
    'cancellation'              => 'Cancellation',
    'cancellation_post_dispatch' => 'Cancellation',
  ];

  $stmt = mysqli_prepare($conn, "SELECT * FROM returns WHERE id = ?");
  mysqli_stmt_bind_param($stmt, "i", $returnId);
  mysqli_stmt_execute($stmt);
  $ret = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
  mysqli_stmt_close($stmt);
  if (!$ret) return false;

  $typeLabel = $typeLabels[$ret['type']]
    ?? ucfirst($ret['type']);

  $body = emailHeader('Request Received') . '

      <h2>We have received your request 📋</h2>
      <p>Hi ' . htmlspecialchars($order['customer_name'])
    . ', thank you for getting in touch.
          Your ' . $typeLabel . ' request for
          Order #' . str_pad(
      $order['id'],
      6,
      '0',
      STR_PAD_LEFT
    ) . ' has been received.</p>

      <div class="order-box">
        <p><strong>Request Reference:
           #' . $returnId . '</strong></p>
        <p>Type: ' . $typeLabel . '</p>
        <p>Order: #' . str_pad(
      $order['id'],
      6,
      '0',
      STR_PAD_LEFT
    ) . '</p>
        <p>Submitted: '
    . date('d F Y H:i') . '</p>
      </div>

      <p>Our team will review your request and
         respond within <strong>2 working days</strong>.
         We will email you with next steps.</p>

      ' . (in_array(
      $ret['type'],
      ['return', 'cancellation_post_dispatch']
    )
      ? '<p>If your request is approved, we will
         email you a <strong>prepaid return label</strong>
         so you can send the item back to us
         at no cost to you.</p>'
      : '') . '

      <p>If you have any questions in the meantime,
         please reply to this email or contact us at
         <a href="mailto:' . SHOP_EMAIL . '"
            style="color:#d4af7a;">'
    . SHOP_EMAIL . '</a>.</p>

    ' . emailFooter();

  return sendEmail(
    $order['customer_email'],
    $order['customer_name'],
    $typeLabel . ' Request Received — #'
      . $returnId . ' | ' . SHOP_NAME,
    $body
  );
}

// ── Return Label Email ───────────────────────────────────────
function sendReturnLabelEmail(
  $conn,
  $returnId,
  $order,
  $labelData
) {
  $body = emailHeader('Your Return Label') . '

      <h2>Your return label is ready 🏷️</h2>
      <p>Hi ' . htmlspecialchars($order['customer_name'])
    . ', your return request has been approved.
          Please follow the instructions below to
          return your item.</p>

      <div class="order-box">
        <p><strong>Return Reference:
           #' . $returnId . '</strong></p>
        <p>Order: #' . str_pad(
      $order['id'],
      6,
      '0',
      STR_PAD_LEFT
    ) . '</p>
        <p>Return via: <strong>'
    . htmlspecialchars($labelData['carrier'])
    . '</strong></p>
      </div>

      ' . ($labelData['carrier'] === 'Evri'
      ? '<div class="tracking-box">
           <p style="color:#2e7d32; font-size:13px;
                     margin:0 0 8px;">
             Your Evri QR Code
           </p>
           <p style="font-size:13px; color:#444;">
             Take this email to your nearest
             Evri ParcelShop and show the QR code
             — no printing needed!
           </p>
           <br>
           <a href="' . htmlspecialchars(
        $labelData['label_url']
      ) . '"
              class="btn"
              style="background:#9c27b0;">
             View QR Code / Label →
           </a>
         </div>'
      : '<div class="tracking-box">
           <p style="color:#2e7d32; font-size:13px;
                     margin:0 0 8px;">
             Your Royal Mail Return Label
           </p>
           <p style="font-size:13px; color:#444;">
             Please print this label and attach it
             to your parcel before dropping it at
             your nearest Post Office.
           </p>
           <br>
           <a href="' . htmlspecialchars(
        $labelData['label_url']
      ) . '"
              class="btn">
             Download & Print Label →
           </a>
         </div>') . '

      <p><strong>Return Address:</strong><br>
         ' . SHOP_NAME . '<br>
         ' . SHOP_ADDRESS . '<br>
         ' . SHOP_CITY . ', '
    . SHOP_POSTCODE . '</p>

      <p>Once we receive your item we will process
         your refund within <strong>5-10 working
         days</strong>. You will receive a
         confirmation email when your refund
         is issued.</p>

    ' . emailFooter();
  // Attach label file if it exists
  // then send via shared sendEmail()
  if (!empty($labelData['file_name'])) {
    $filePath = __DIR__ . '/../labels/'
      . $labelData['file_name'];
    $ext = strtolower(pathinfo(
      $labelData['file_name'],
      PATHINFO_EXTENSION
    ));

    // Use PHPMailer directly only for attachment
    // but reuse same SMTP config via sendEmailWithAttachment
    return sendEmailWithAttachment(
      $order['customer_email'],
      $order['customer_name'],
      'Your Return Label — Order #'
        . str_pad($order['id'], 6, '0', STR_PAD_LEFT)
        . ' | ' . SHOP_NAME,
      $body,
      file_exists($filePath) ? $filePath : null,
      'return_label.' . $ext
    );
  } else {
    return sendEmail(
      $order['customer_email'],
      $order['customer_name'],
      'Your Return Label — Order #'
        . str_pad($order['id'], 6, '0', STR_PAD_LEFT)
        . ' | ' . SHOP_NAME,
      $body
    );
  }
}




// ── Return Rejected Email ────────────────────────────────────
function sendReturnRejectedEmail(
  $conn,
  $returnId,
  $ret
) {
  $body = emailHeader('Update on Your Request') . '

      <h2>Update on your return request</h2>
      <p>Hi ' . htmlspecialchars($ret['customer_name'])
    . ', unfortunately we are unable to approve
          your return request #' . $returnId . '
          at this time.</p>

      ' . ($ret['admin_notes'] ? '
      <div class="order-box">
        <p><strong>Reason:</strong></p>
        <p>' . htmlspecialchars($ret['admin_notes'])
      . '</p>
      </div>' : '') . '

      <p>If you believe this decision is incorrect
         or you have additional information,
         please contact us directly at
         <a href="mailto:' . SHOP_EMAIL . '"
            style="color:#d4af7a;">'
    . SHOP_EMAIL . '</a> and we will be
         happy to review your case.</p>

    ' . emailFooter();

  return sendEmail(
    $ret['customer_email'],
    $ret['customer_name'],
    'Update on Your Return Request #'
      . $returnId,
    $body
  );
}

// ── Refund Confirmation Email ────────────────────────────────
function sendRefundConfirmationEmail(
  $conn,
  $orderId,
  $amount
) {
  $stmt = mysqli_prepare($conn, "SELECT * FROM orders WHERE id = ?");
  mysqli_stmt_bind_param($stmt, "i", $orderId);
  mysqli_stmt_execute($stmt);
  $order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
  mysqli_stmt_close($stmt);
  if (!$order) return false;

  $body = emailHeader('Refund Processed') . '

      <h2>Your refund has been processed 💳</h2>
      <p>Hi ' . htmlspecialchars(
    $order['customer_name']
  )
    . ', we have processed your refund
          of <strong>£' . number_format($amount, 2)
    . '</strong> for Order #'
    . str_pad($orderId, 6, '0', STR_PAD_LEFT)
    . '.</p>

      <div class="order-box">
        <p><strong>Refund Amount:
           £' . number_format($amount, 2)
    . '</strong></p>
        <p>Refund to: Your original payment method</p>
        <p>Processing time: 5-10 working days
           (depending on your bank)</p>
      </div>

      <p>If you have any questions please contact
         us at <a href="mailto:' . SHOP_EMAIL . '"
         style="color:#d4af7a;">'
    . SHOP_EMAIL . '</a>.</p>

      <p style="text-align:center; margin-top:24px;">
        <a href="' . SHOP_URL . '/products"
           class="btn">Shop Again →</a>
      </p>

    ' . emailFooter();

  return sendEmail(
    $order['customer_email'],
    $order['customer_name'],
    'Refund Processed — £'
      . number_format($amount, 2)
      . ' | ' . SHOP_NAME,
    $body
  );
}



// ── Complaint Confirmation Email ─────────────────────────────
function sendComplaintConfirmationEmail(
  $conn,
  $complaintId,
  $order,
  $type,
  $desc
) {
  $typeLabels = [
    'wrong_item'       => 'Wrong Item Received',
    'damaged_item'     => 'Damaged Item',
    'missing_item'     => 'Missing Item',
    'not_as_described' => 'Not As Described',
    'other'            => 'Other Issue',
  ];
  $typeLabel = $typeLabels[$type] ?? ucfirst($type);

  $body = emailHeader('Complaint Received') . '

      <h2>We have received your complaint 📋</h2>
      <p>Hi ' . htmlspecialchars(
    $order['customer_name']
  )
    . ', thank you for letting us know
          about this issue. We take all complaints
          very seriously and will do everything
          we can to make this right.</p>

      <div class="order-box">
        <p><strong>Complaint Reference:
           #' . $complaintId . '</strong></p>
        <p>Issue Type: ' . $typeLabel . '</p>
        <p>Order: #' . str_pad(
      $order['id'],
      6,
      '0',
      STR_PAD_LEFT
    ) . '</p>
        <p>Submitted: ' . date('d F Y H:i') . '</p>
      </div>

      <div class="order-box"
           style="border-left-color:#e53935;
                  background:#fff5f5;">
        <p><strong>Your description:</strong></p>
        <p style="font-style:italic;">'
    . htmlspecialchars($desc) . '</p>
      </div>

      <p><strong>What happens next:</strong></p>
      <p>Our team will review your complaint
         within <strong>24 hours</strong> and
         email you with resolution options.
         Please keep the item and its packaging
         until you hear from us.</p>

      <p>For wrong or damaged items we will offer
         you a choice of:</p>
      <ul style="color:#555; font-size:14px;
                 line-height:2; margin-left:20px;">
        <li>We send you the correct/replacement
            item free of charge</li>
        <li>Full refund to your original
            payment method</li>
      </ul>

      <p>If you need to speak with us urgently
         please contact us at
         <a href="mailto:' . SHOP_EMAIL . '"
            style="color:#d4af7a;">'
    . SHOP_EMAIL . '</a>.</p>

    ' . emailFooter();

  return sendEmail(
    $order['customer_email'],
    $order['customer_name'],
    'Complaint Received #' . $complaintId
      . ' — We will make this right | '
      . SHOP_NAME,
    $body
  );
}

// ── Complaint Resolution Email ───────────────────────────────
function sendComplaintResolutionEmail(
  $conn,
  $complaintId,
  $resolution,
  $adminResponse
) {
  $stmt = mysqli_prepare(
    $conn,
    "SELECT c.*, o.customer_name, o.customer_email, o.customer_address
         FROM complaints c
         JOIN orders o ON c.order_id = o.id
         WHERE c.id = ?"
  );
  mysqli_stmt_bind_param($stmt, "i", $complaintId);
  mysqli_stmt_execute($stmt);
  $complaint = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
  mysqli_stmt_close($stmt);

  if (!$complaint) return false;

  $resolutionText = match ($resolution) {
    'replacement' =>
    'We will send you the correct item
             as soon as possible at no extra cost.
             You will receive a dispatch
             confirmation email shortly.',
    'refund'      =>
    'A full refund has been processed
             to your original payment method.
             Please allow 5-10 working days
             for it to appear.',
    'partial_refund' =>
    'A partial refund has been processed
             to your original payment method.',
    default       => $adminResponse,
  };

  $body = emailHeader('Complaint Update') . '

      <h2>Update on your complaint 🌸</h2>
      <p>Hi ' . htmlspecialchars(
    $complaint['customer_name']
  )
    . ', thank you for your patience.
          We have reviewed your complaint #'
    . $complaintId . ' and here is
          our resolution:</p>

      <div class="tracking-box">
        <p style="font-size:14px; color:#1a1a1a;
                  margin:0 0 10px; font-weight:bold;">
          Resolution:
          ' . ucfirst(str_replace(
      '_',
      ' ',
      $resolution
    )) . '
        </p>
        <p style="font-size:14px; color:#444;
                  margin:0;">'
    . htmlspecialchars($resolutionText) . '</p>
      </div>

      ' . ($adminResponse ? '
      <div class="order-box">
        <p><strong>Message from our team:</strong></p>
        <p>' . htmlspecialchars($adminResponse)
      . '</p>
      </div>' : '') . '

      <p>We sincerely apologise for the
         inconvenience caused. Your satisfaction
         is our top priority.</p>

      <p>If you have any further questions
         please contact us at
         <a href="mailto:' . SHOP_EMAIL . '"
            style="color:#d4af7a;">'
    . SHOP_EMAIL . '</a>.</p>

    ' . emailFooter();

  return sendEmail(
    $complaint['customer_email'],
    $complaint['customer_name'],
    'Your Complaint Has Been Resolved — '
      . SHOP_NAME,
    $body
  );
}


// ── Replacement Dispatch Email ───────────────────────────────
function sendReplacementDispatchEmail(
  $conn,
  $orderId,
  $tracking,
  $carrier
) {
  $stmt = mysqli_prepare($conn, "SELECT * FROM orders WHERE id = ?");
  mysqli_stmt_bind_param($stmt, "i", $orderId);
  mysqli_stmt_execute($stmt);
  $order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
  mysqli_stmt_close($stmt);
  if (!$order) return false;

  $body = emailHeader('Replacement On Its Way!') . '

      <h2>Your replacement is dispatched! 🚚</h2>
      <p>Hi ' . htmlspecialchars(
    $order['customer_name']
  )
    . ', great news — your replacement
          item has been dispatched and is
          on its way to you!</p>

      <div class="tracking-box">
        <p style="color:#1565c0; font-size:13px;
                  margin:0 0 6px; letter-spacing:1px;
                  text-transform:uppercase;">
          Replacement Tracking Number
        </p>
        <div class="tracking-number">'
    . htmlspecialchars($tracking) . '</div>
        <p style="font-size:12px; color:#888;
                  margin-top:8px;">
          via ' . htmlspecialchars($carrier) . '
        </p>
      </div>

      <p>Please allow 1-3 working days for delivery.
         If you have any issues please contact us at
         <a href="mailto:' . SHOP_EMAIL . '"
            style="color:#d4af7a;">'
    . SHOP_EMAIL . '</a>.</p>

    ' . emailFooter();

  return sendEmail(
    $order['customer_email'],
    $order['customer_name'],
    'Your Replacement Is On Its Way 🚚 — '
      . SHOP_NAME,
    $body
  );
}

// ── Complaint Response Email ─────────────────────────────────
function sendComplaintResponseEmail(
  $conn,
  $complaintId,
  $response,
  $complaint
) {
  $body = emailHeader('Response to Your Complaint')
    . '

      <h2>We have responded to your complaint</h2>
      <p>Hi ' . htmlspecialchars(
      $complaint['customer_name']
    )
    . ', thank you for getting in touch.
          Here is our response to your
          complaint #' . $complaintId . ':</p>

      <div class="order-box">
        <p>' . nl2br(htmlspecialchars($response))
    . '</p>
      </div>

      <p>If you need further assistance please
         reply to this email or contact us at
         <a href="mailto:' . SHOP_EMAIL . '"
            style="color:#d4af7a;">'
    . SHOP_EMAIL . '</a>.</p>

    ' . emailFooter();

  return sendEmail(
    $complaint['customer_email'],
    $complaint['customer_name'],
    'Response to Your Complaint #'
      . $complaintId . ' — ' . SHOP_NAME,
    $body
  );
}
