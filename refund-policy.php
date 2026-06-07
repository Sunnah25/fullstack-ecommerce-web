<?php
require_once 'config.php';
$pageTitle = "Refund Policy - Genova Perfumes";
include 'includes/header.php';
?>

<div class="legal-page">

  <div class="legal-header">
    <h1>Refund Policy</h1>
    <p>Last updated: <?php echo date('d F Y'); ?></p>
  </div>

  <div class="legal-content">

    <section>
      <h2>Our Commitment</h2>
      <p>At <?php echo SHOP_NAME; ?> we want you to love every
      fragrance you receive. If something is not right, we will
      always do our best to make it right.</p>
    </section>

    <section>
      <h2>30-Day Return Window</h2>
      <p>You may return any item within <strong>30 days</strong>
      of delivery for a full refund, provided:</p>
      <ul>
        <li>The item is unused and in its original packaging</li>
        <li>The seal has not been broken</li>
        <li>You have your order number and email address</li>
      </ul>
    </section>

    <section>
      <h2>Cancellations</h2>
      <p>You may cancel your order at any time before it is
      dispatched for a full refund. Once dispatched, please use
      our returns process instead.</p>
      <p>To cancel, visit our
      <a href="/returns">Returns page</a>
      and select Cancellation.</p>
    </section>

    <section>
      <h2>Damaged or Faulty Items</h2>
      <p>If your item arrives damaged or is not as described:</p>
      <ul>
        <li>Contact us within 48 hours of delivery</li>
        <li>Send a photo of the damage to
            <strong><?php echo SHOP_EMAIL; ?></strong></li>
        <li>We will send a replacement or issue a full refund
            — your choice</li>
        <li>You do not need to return damaged items</li>
      </ul>
    </section>

    <section>
      <h2>How Refunds Are Processed</h2>
      <ul>
        <li>Refunds are returned to your original payment method</li>
        <li>Processing time: <strong>5-10 working days</strong>
            after approval</li>
        <li>You will receive an email confirmation when your
            refund is processed</li>
        <li>Original delivery charges are refunded in full</li>
      </ul>
    </section>

    <section>
      <h2>Exceptions</h2>
      <p>We cannot accept returns on:</p>
      <ul>
        <li>Opened or used fragrances (for hygiene reasons),
            unless faulty</li>
        <li>Items returned after 30 days without prior
            agreement</li>
        <li>Items not in their original condition</li>
      </ul>
    </section>

    <section>
      <h2>Start a Return</h2>
      <p>Visit our <a href="/returns">
      Returns & Refunds page</a> with your order number and
      email address to submit a request. We aim to respond
      within 2 working days.</p>
      <p>Questions? Email us at
      <strong><?php echo SHOP_EMAIL; ?></strong>
      or call <?php echo SHOP_PHONE; ?>.</p>
    </section>

  </div>
</div>

<?php include 'includes/footer.php'; ?>