<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
$pageTitle = "Home";
require_once 'config.php';
include 'includes/db.php';



$metaDesc = 'Genova Perfumes — Discover luxury
             fragrances at affordable prices.
             Free delivery across the UK.';


include 'includes/header.php';
?>

<!-- ═══════════════════════════════════════
     HERO
═══════════════════════════════════════ -->
<section class="hero">

  <div class="hero-bg"></div>
  <div class="hero-content">
    <span class="hero-eyebrow">Natural · Pure · Handcrafted</span>
    <h1>Scent That Tells<br><em>Your Story</em></h1>
    <p>Discover our collection of natural fragrances,
      crafted from the finest botanical ingredients.</p>
    <div class="hero-buttons">
      <a href="/products"
        class="btn-primary">Explore Collection</a>
      <a href="/contact"
        class="btn-outline">Get in Touch</a>
    </div>
  </div>
  <div class="hero-scroll">Scroll</div>
</section>

<!-- ═══════════════════════════════════════
     TRUST STRIP
═══════════════════════════════════════ -->
<div class="trust-strip">
  <div class="strip-item">
    <span>🌿</span> 100% Natural Ingredients
  </div>
  <div class="strip-item">
    <span>🚚</span> Free UK Delivery
  </div>
  <div class="strip-item">
    <span>↩️</span> 30-Day Returns
  </div>
  <div class="strip-item">
    <span>🔒</span> Secure Payments
  </div>
</div>

<!-- ═══════════════════════════════════════
     FEATURED PRODUCTS
═══════════════════════════════════════ -->
<section class="featured-section">
  <div class="section-header">
    <span class="section-eyebrow">Our Collection</span>
    <h2>Featured Fragrances</h2>
    <p class="section-subtitle">
      Each scent is a journey — find yours
    </p>
  </div>

  <div class="products-grid">
    <?php
    $stmt = mysqli_prepare(
      $conn,
      "SELECT id, name, price, discount_percent,
              image, description
       FROM products
       WHERE featured = 1
       ORDER BY created_at DESC
       LIMIT 8"
    );
    mysqli_stmt_execute($stmt);
    $featured = mysqli_stmt_get_result($stmt);
    if (mysqli_num_rows($featured) > 0):
      while ($product = mysqli_fetch_assoc($featured)):
    ?>
        <div class="product-card"
          style="cursor:pointer;"
          onclick="window.location='/product?id=<?php
                                                                echo $product['id']; ?>'">
          <div class="product-card-image">
            <img src="<?php echo SHOP_URL; ?>/images/<?php
                                                      echo htmlspecialchars(basename($product['image'])); ?>"
              alt="<?php echo htmlspecialchars($product['name']); ?>"
              onerror="this.src='<?php echo SHOP_URL; ?>/images/placeholder.jpg'">
            <div class="product-card-overlay">
              <a href="/product?id=<?php
                                                    echo $product['id']; ?>"
                class="btn-view-product"
                onclick="event.stopPropagation()">
                View Fragrance
              </a>
            </div>
          </div>
          <div class="product-info">
            <h2><?php echo htmlspecialchars($product['name']); ?></h2>
            <p><?php echo htmlspecialchars(
                  mb_substr($product['description'], 0, 80)
                ) . '...'; ?>
            </p>
            <div class="product-footer">
              <?php
              $salePrice = getSalePrice(
                $product['price'],
                $product['discount_percent'] ?? 0
              );
              ?>
              <?php if ($salePrice): ?>
                <div class="price-wrap">
                  <span class="price-original">
                    £<?php echo number_format($product['price'], 2); ?>
                  </span>
                  <span class="price-sale">
                    £<?php echo number_format($salePrice, 2); ?>
                  </span>
                  <span class="sale-badge">
                    -<?php echo intval($product['discount_percent']); ?>%
                  </span>
                </div>
              <?php else: ?>
                <span class="price">
                  £<?php echo number_format($product['price'], 2); ?>
                </span>
              <?php endif; ?>
              <a href="/product?id=<?php
                                                    echo $product['id']; ?>"
                class="btn-add"
                onclick="event.stopPropagation()">
                View →
              </a>
            </div>
          </div>
        </div>
      <?php
      endwhile;
    else:
      ?>
      <p style="text-align:center; color:#999;
                grid-column:1/-1; padding:40px 0;">
        Products coming soon — check back shortly!
      </p>
    <?php endif; ?>
  </div>

  <div style="text-align:center; margin-top:50px;">
    <a href="/products"
      class="btn-primary">
      View All Fragrances →
    </a>
  </div>
</section>

<!-- ═══════════════════════════════════════
     WHY US
═══════════════════════════════════════ -->
<section class="why-us">
  <div class="section-header">
    <span class="section-eyebrow">Why Choose Us</span>
    <h2>The Aura Difference</h2>
  </div>
  <div class="why-grid">
    <div class="why-item">
      <div class="why-icon">🌿</div>
      <h3>Natural Ingredients</h3>
      <p>Every fragrance crafted from the finest
        natural botanical sources worldwide.</p>
    </div>
    <div class="why-item">
      <div class="why-icon">📦</div>
      <h3>Free UK Delivery</h3>
      <p>Free delivery on every single order,
        dispatched within 24 hours.</p>
    </div>
    <div class="why-item">
      <div class="why-icon">💛</div>
      <h3>Crafted With Care</h3>
      <p>Small batch production ensuring
        every bottle meets our standards.</p>
    </div>
    <div class="why-item">
      <div class="why-icon">↩️</div>
      <h3>30-Day Returns</h3>
      <p>Not happy? Full refund within
        30 days, no questions asked.</p>
    </div>
  </div>
</section>

<!-- ═══════════════════════════════════════
     BRAND STORY
═══════════════════════════════════════ -->
<section class="scent-story">
  <div class="scent-story-image"></div>
  <div class="scent-story-content">
    <span class="section-eyebrow">Our Story</span>
    <h2>Born From Nature,<br>Made For You</h2>
    <p>Every fragrance in our collection begins
      with a single idea — that scent is the most
      powerful form of memory.</p>
    <p>We source only the finest natural botanicals,
      resins and woods to create perfumes that feel
      as good as they smell. No synthetics.
      No shortcuts. Just pure, honest fragrance.</p>
    <a href="/products"
      class="btn-primary"
      style="margin-top:10px; display:inline-block;">
      Shop the Collection
    </a>
  </div>
</section>
<!-- Portfolio Notice Popup -->
<div id="portfolioNotice" style="
    position:fixed; bottom:20px; left:50%; transform:translateX(-50%);
    background:#1a1a1a; color:#f5f5f5; padding:18px 24px;
    border-left:4px solid #d4af7a; border-radius:4px;
    max-width:480px; width:90%; z-index:99999;
    box-shadow:0 4px 20px rgba(0,0,0,0.4);
    font-family:Georgia,serif; font-size:0.82rem; line-height:1.6;">
    <button onclick="document.getElementById('portfolioNotice').style.display='none'"
        style="position:absolute; top:8px; right:12px;
               background:none; border:none; color:#aaa;
               font-size:1.1rem; cursor:pointer; line-height:1;">✕</button>
    <strong style="color:#d4af7a; font-size:0.88rem;">
        📌 Portfolio Project
    </strong><br>
    This website is for portfolio purposes only. It functions as a
    full e-commerce store but is not currently in business use.
    <strong>No orders will be charged or fulfilled.</strong>
    <div style="margin-top:10px; padding-top:10px;
                border-top:1px solid #333;
                display:flex; align-items:center;
                justify-content:space-between;">
        <span style="color:#aaa; font-size:0.78rem;">
            Developed by <strong style="color:#d4af7a;">Sunnah</strong>
        </span>
        <a href="https://github.com/Sunnah25"
            target="_blank"
            style="background:#d4af7a; color:#1a1a1a;
                   padding:5px 12px; font-size:0.75rem;
                   text-decoration:none; letter-spacing:1px;
                   font-family:Georgia,serif;">
            SEE MORE →
        </a>
    </div>
</div>


<?php include 'includes/footer.php'; ?>
