<?php
require_once 'config.php';
$pageTitle = "Terms & Conditions - Genova Perfumes";
include 'includes/header.php';
?>

<div class="legal-page">

  <div class="legal-header">
    <h1>Terms & Conditions</h1>
    <p>Last updated: <?php echo date('d F Y'); ?></p>
  </div>

  <div class="legal-content">

    <section>
      <h2>1. About Us</h2>
      <p><?php echo SHOP_NAME; ?> operates the website at
        <?php echo SHOP_URL; ?>. By placing an order or using our
        website you agree to these terms.</p>
      <p>Contact us at: <strong><?php echo SHOP_EMAIL; ?></strong>
        or <?php echo SHOP_PHONE; ?></p>
    </section>

    <section>
      <h2>2. Your Statutory Rights</h2>
      <p>These terms do not affect your statutory rights under UK
        consumer law including the Consumer Rights Act 2015 and the
        Consumer Contracts Regulations 2013.</p>
    </section>

    <section>
      <h2>3. Placing an Order</h2>
      <ul>
        <li>By placing an order you confirm you are at least 18
          years old and a UK resident</li>
        <li>Your order is an offer to buy. We accept your offer
          when we confirm your payment</li>
        <li>We reserve the right to refuse or cancel any order
          at our discretion</li>
        <li>Prices are shown in GBP and include VAT where
          applicable</li>
        <li>We reserve the right to change prices at any time.
          Orders already placed are not affected</li>
      </ul>
    </section>

    <section>
      <h2>4. Payment</h2>
      <p>All payments are processed securely by Stripe. We accept
        major credit and debit cards. Payment is taken at the time
        of order. We do not store any card details on our servers.</p>
    </section>

    <section>
      <h2>5. Delivery</h2>
      <ul>
        <li>We offer free delivery on all orders</li>
        <li>Orders are typically dispatched within 1-2 working
          days</li>
        <li>Delivery times are estimates and not guaranteed</li>
        <li>We currently deliver to UK addresses only</li>
        <li>Risk of loss passes to you on delivery</li>
      </ul>
    </section>

    <section>
      <h2>6. Returns & Refunds</h2>
      <p>Under the Consumer Contracts Regulations 2013 you have
        the right to cancel your order within <strong>14 days</strong>
        of receiving your goods without giving a reason.</p>
      <ul>
        <li>Items must be returned unused, in original packaging
          within 30 days</li>
        <li>Refunds are processed within 14 days of us receiving
          the returned item</li>
        <li>We do not cover return postage costs unless the item
          is faulty</li>
        <li>Fragrances that have been opened and used cannot be
          returned for hygiene reasons, unless faulty</li>
        <li>If your order has not yet been dispatched,
          you will receive a <strong>full refund</strong>
          within 5-10 working days.</li>
        <li>If your order has already been dispatched,
          you must return the item before a refund
          is issued.</li>
        <li>A <strong>return handling fee</strong>
          will be deducted from your refund to cover
          our outbound shipping costs, unless the item
          is faulty or not as described.</li>
      </ul>
      <p>To start a return visit our
        <a href="/returns">Returns page</a>.
      </p>
    </section>

    <section>
      <h2>7. Product Descriptions</h2>
      <p>We make every effort to ensure product descriptions and
        images are accurate. However, colours may vary slightly due
        to screen settings. Fragrance descriptions are subjective
        and may be perceived differently by different people.</p>
    </section>

    <section>
      <h2>8. Liability</h2>
      <p>To the fullest extent permitted by UK law, we exclude all
        liability for indirect or consequential losses. Our total
        liability to you shall not exceed the value of your order.
        Nothing in these terms limits our liability for death or
        personal injury caused by negligence, fraud, or any other
        liability that cannot be excluded by law.</p>
    </section>

    <section>
      <h2>9. Intellectual Property</h2>
      <p>All content on this website including images, text and
        branding is owned by <?php echo SHOP_NAME; ?> or used with
        permission. You may not reproduce any content without our
        written permission.</p>
    </section>

    <section>
      <h2>10. Governing Law</h2>
      <p>These terms are governed by the laws of England and Wales.
        Any disputes shall be subject to the exclusive jurisdiction
        of the courts of England and Wales.</p>
    </section>

  </div>
</div>

<?php include 'includes/footer.php'; ?>