<?php
require_once 'config.php';
$pageTitle = "Privacy Policy - Genova Perfumes";
include 'includes/header.php';
?>

<div class="legal-page">

  <div class="legal-header">
    <h1>Privacy Policy</h1>
    <p>Last updated: <?php echo date('d F Y'); ?></p>
  </div>

  <div class="legal-content">

    <section>
      <h2>1. Who We Are</h2>
      <p><?php echo SHOP_NAME; ?> is an online perfume retailer based
      in the United Kingdom. We are committed to protecting your
      personal data in accordance with the UK General Data Protection
      Regulation (UK GDPR) and the Data Protection Act 2018.</p>
      <p>For any privacy related questions, contact us at:
      <strong><?php echo SHOP_EMAIL; ?></strong></p>
    </section>

    <section>
      <h2>2. What Data We Collect</h2>
      <p>When you use our website or place an order, we may collect
      the following personal data:</p>
      <ul>
        <li><strong>Identity data</strong> — your full name</li>
        <li><strong>Contact data</strong> — email address,
            phone number</li>
        <li><strong>Delivery data</strong> — your delivery address
            and postcode</li>
        <li><strong>Transaction data</strong> — details of orders
            you have placed</li>
        <li><strong>Technical data</strong> — IP address, browser
            type, pages visited (via server logs)</li>
        <li><strong>Communications data</strong> — messages you
            send via our contact form</li>
      </ul>
      <p>We do <strong>not</strong> collect or store any payment card
      details. All payments are processed securely by Stripe. Please
      see <a href="https://stripe.com/gb/privacy"
      target="_blank">Stripe's Privacy Policy</a> for details.</p>
    </section>

    <section>
      <h2>3. How We Use Your Data</h2>
      <p>We use your personal data for the following purposes:</p>
      <ul>
        <li>To process and fulfil your orders</li>
        <li>To send order confirmations and delivery updates</li>
        <li>To respond to your enquiries and support requests</li>
        <li>To process returns, refunds and cancellations</li>
        <li>To comply with our legal obligations</li>
        <li>To prevent fraud and maintain security</li>
      </ul>
    </section>

    <section>
      <h2>4. Legal Basis for Processing</h2>
      <p>We process your data under the following legal bases:</p>
      <ul>
        <li><strong>Contract</strong> — processing is necessary to
            fulfil your order</li>
        <li><strong>Legal obligation</strong> — we must keep
            transaction records for HMRC</li>
        <li><strong>Legitimate interests</strong> — to prevent fraud
            and improve our service</li>
        <li><strong>Consent</strong> — where you have given us
            explicit consent (e.g. marketing emails)</li>
      </ul>
    </section>

    <section>
      <h2>5. How Long We Keep Your Data</h2>
      <ul>
        <li><strong>Order data</strong> — 7 years (required by
            HMRC for tax purposes)</li>
        <li><strong>Contact form messages</strong> — 2 years</li>
        <li><strong>Technical logs</strong> — 90 days</li>
        <li><strong>Marketing consent</strong> — until you
            unsubscribe</li>
      </ul>
    </section>

    <section>
      <h2>6. Sharing Your Data</h2>
      <p>We do not sell your personal data. We share it only with:</p>
      <ul>
        <li><strong>Stripe</strong> — to process your payment</li>
        <li><strong>Royal Mail / Evri</strong> — to deliver your
            order (name and address only)</li>
        <li><strong>Our hosting provider</strong> — who stores
            data securely on UK/EU servers</li>
        <li><strong>Legal authorities</strong> — if required by
            law</li>
      </ul>
    </section>

    <section>
      <h2>7. Your Rights Under UK GDPR</h2>
      <p>You have the following rights regarding your personal data:</p>
      <ul>
        <li><strong>Right of access</strong> — request a copy of
            your data</li>
        <li><strong>Right to rectification</strong> — ask us to
            correct inaccurate data</li>
        <li><strong>Right to erasure</strong> — ask us to delete
            your data ("right to be forgotten")</li>
        <li><strong>Right to restriction</strong> — ask us to
            limit how we use your data</li>
        <li><strong>Right to portability</strong> — receive your
            data in a portable format</li>
        <li><strong>Right to object</strong> — object to
            processing based on legitimate interests</li>
      </ul>
      <p>To exercise any of these rights, email us at
      <strong><?php echo SHOP_EMAIL; ?></strong>. We will respond
      within 30 days.</p>
    </section>

    <section>
      <h2>8. Cookies</h2>
      <p>We use the following cookies on our website:</p>
      <ul>
        <li><strong>Session cookie</strong> — keeps your shopping
            cart working during your visit. This is essential and
            cannot be disabled.</li>
        <li><strong>No tracking or advertising cookies</strong>
            — we do not use Google Analytics, Facebook Pixel,
            or any third party tracking.</li>
      </ul>
    </section>

    <section>
      <h2>9. Data Security</h2>
      <p>We take reasonable technical measures to protect your data
      including encrypted connections (HTTPS), secure password
      hashing, and restricted database access. However, no internet
      transmission is completely secure.</p>
    </section>

    <section>
      <h2>10. Contact & Complaints</h2>
      <p>For any privacy concerns contact us at
      <strong><?php echo SHOP_EMAIL; ?></strong>.</p>
      <p>If you are unhappy with how we handle your data, you have
      the right to lodge a complaint with the
      <strong>Information Commissioner's Office (ICO)</strong>
      at <a href="https://ico.org.uk" target="_blank">ico.org.uk</a>
      or call 0303 123 1113.</p>
    </section>

  </div>
</div>

<?php include 'includes/footer.php'; ?>