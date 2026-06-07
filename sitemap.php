<?php
require_once 'config.php';
include 'includes/db.php';

header('Content-Type: application/xml; charset=utf-8');
echo '<?xml version="1.0" encoding="UTF-8"?>';
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">

  <url>
    <loc><?php echo SHOP_URL; ?>/</loc>
    <changefreq>weekly</changefreq>
    <priority>1.0</priority>
  </url>

  <url>
    <loc><?php echo SHOP_URL; ?>/products</loc>
    <changefreq>daily</changefreq>
    <priority>0.9</priority>
  </url>

  <?php
  // Add all active products
  $products = mysqli_query($conn,
      "SELECT id, name, updated_at
       FROM products
       WHERE stock >= 0
       ORDER BY id ASC");

  while ($p = mysqli_fetch_assoc($products)):
    $updated = $p['updated_at']
             ? date('Y-m-d',
               strtotime($p['updated_at']))
             : date('Y-m-d');
  ?>
  <url>
    <loc><?php echo SHOP_URL; ?>/product?id=<?php
         echo $p['id']; ?></loc>
    <lastmod><?php echo $updated; ?></lastmod>
    <changefreq>weekly</changefreq>
    <priority>0.8</priority>
  </url>
  <?php endwhile; ?>

  <url>
    <loc><?php echo SHOP_URL; ?>/contact</loc>
    <changefreq>monthly</changefreq>
    <priority>0.5</priority>
  </url>

  <url>
    <loc><?php echo SHOP_URL; ?>/returns</loc>
    <changefreq>monthly</changefreq>
    <priority>0.4</priority>
  </url>

  <url>
    <loc><?php echo SHOP_URL; ?>/privacy-policy</loc>
    <changefreq>yearly</changefreq>
    <priority>0.3</priority>
  </url>

  <url>
    <loc><?php echo SHOP_URL; ?>/terms</loc>
    <changefreq>yearly</changefreq>
    <priority>0.3</priority>
  </url>

</urlset>