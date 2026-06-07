<?php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
include 'includes/db.php';

// Initialize cart
if (!isset($_SESSION['cart'])) {
  $_SESSION['cart'] = [];
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

  $id = intval($_POST['product_id']);

  // Add item
  if ($_POST['action'] === 'add') {
    $qty = max(1, min(99, intval($_POST['quantity'] ?? 1)));

    $stmt = mysqli_prepare($conn, "SELECT stock, price, discount_percent FROM products WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $id);
    mysqli_stmt_execute($stmt);
    $stockRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$stockRow) {
      $_SESSION['cart_error'] = "Product not found.";
      header('Location: /products');
      exit();
    }

    $stockAvail    = intval($stockRow['stock']);
    $currentInCart = isset($_SESSION['cart'][$id]) ? $_SESSION['cart'][$id]['qty'] : 0;

    if ($currentInCart + $qty > $stockAvail) {
      $_SESSION['cart_error'] = "Sorry — only $stockAvail of this item available.";
      header('Location: /products');
      exit();
    }

    // Lock price at time of adding to cart
    $discountPct = floatval($stockRow['discount_percent'] ?? 0);
    $lockedPrice = $discountPct > 0
      ? round($stockRow['price'] * (1 - $discountPct / 100), 2)
      : floatval($stockRow['price']);

    if (isset($_SESSION['cart'][$id])) {
      $_SESSION['cart'][$id]['qty'] += $qty;
    } else {
      $_SESSION['cart'][$id] = ['qty' => $qty, 'price' => $lockedPrice];
    }
    header('Location: /products');
    exit();
  }

  // Update quantity
  if ($_POST['action'] === 'update') {
    $qty = intval($_POST['quantity']);
    if ($qty <= 0) {
      unset($_SESSION['cart'][$id]);
    } else {
      $qty = max(1, min(99, $qty));

      $stmt = mysqli_prepare($conn, "SELECT stock FROM products WHERE id = ?");
      mysqli_stmt_bind_param($stmt, "i", $id);
      mysqli_stmt_execute($stmt);
      $stockRow = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
      mysqli_stmt_close($stmt);

      if (!$stockRow) {
        unset($_SESSION['cart'][$id]);
        header('Location: /cart');
        exit();
      }

      $stockAvail = intval($stockRow['stock']);
      if ($qty > $stockAvail) {
        $qty = $stockAvail;
      }

      // Preserve locked price, update qty only
      if (isset($_SESSION['cart'][$id]) && is_array($_SESSION['cart'][$id])) {
        $_SESSION['cart'][$id]['qty'] = $qty;
      } else {
        // Legacy session format — rebuild with current price
        $stmt2 = mysqli_prepare(
          $conn,
          "SELECT price, discount_percent
         FROM products WHERE id = ?"
        );
        mysqli_stmt_bind_param($stmt2, "i", $id);
        mysqli_stmt_execute($stmt2);
        $priceRow = mysqli_fetch_assoc(
          mysqli_stmt_get_result($stmt2)
        );
        mysqli_stmt_close($stmt2);

        $discPct  = floatval($priceRow['discount_percent'] ?? 0);
        $fallback = $discPct > 0
          ? round(floatval($priceRow['price'])
            * (1 - $discPct / 100), 2)
          : floatval($priceRow['price'] ?? 0);

        $_SESSION['cart'][$id] = [
          'qty'   => $qty,
          'price' => $fallback,
        ];
      }
    }
    header('Location: /cart');
    exit();
  }
  // Remove item
  if ($_POST['action'] === 'remove') {
    unset($_SESSION['cart'][$id]);
    header('Location: /cart');
    exit();
  }
}

// Clear entire cart
if (isset($_GET['clear'])) {
  $_SESSION['cart'] = [];
  header('Location: /cart');
  exit();
}

// Load cart products from database
$cartItems  = [];
$totalPrice = 0;

if (!empty($_SESSION['cart'])) {
  $ids = array_filter(array_map('intval', array_keys($_SESSION['cart'])), fn($id) => $id > 0);

  if (empty($ids)) {
    $_SESSION['cart'] = [];
    $cartItems = [];
  } else {
    $ids    = array_values(array_map('intval', $ids));
    // Already cast to int above, but explicitly
    // ensure no non-integer sneaks in
    $safeIds = array_map('intval', $ids);
    $safeIds = array_filter($safeIds, fn($id) => $id > 0);

    if (empty($safeIds)) {
      $_SESSION['cart'] = [];
      $cartItems = [];
    } else {
      $placeholders = implode(',', $safeIds);
      $result = mysqli_query(
        $conn,
        "SELECT id, name, price, discount_percent,
                    image, stock
            FROM products
            WHERE id IN ($placeholders)"
      );
      while ($product = mysqli_fetch_assoc($result)) {
        $cartEntry = $_SESSION['cart'][$product['id']] ?? null;
        if (!$cartEntry) continue;

        // Enforce array format — clear any malformed entries
        // Recover malformed entries rather than silently deleting
        if (!is_array($cartEntry) || !isset($cartEntry['qty'], $cartEntry['price'])) {
          $discountPct = floatval($product['discount_percent'] ?? 0);
          $fallbackPrice = $discountPct > 0
            ? round($product['price'] * (1 - $discountPct / 100), 2)
            : floatval($product['price']);
          $_SESSION['cart'][$product['id']] = [
            'qty'   => is_array($cartEntry) ? max(1, intval($cartEntry['qty'] ?? 1)) : 1,
            'price' => $fallbackPrice,
          ];
          $cartEntry = $_SESSION['cart'][$product['id']];
        }

        // Remove from cart if out of stock
        if (intval($product['stock']) <= 0) {
          unset($_SESSION['cart'][$product['id']]);
          continue;
        }

        $qty         = max(1, intval($cartEntry['qty']));
        $lockedPrice = floatval($cartEntry['price']);
        if ($lockedPrice <= 0) {
          $discountPct = floatval($product['discount_percent'] ?? 0);
          $lockedPrice = $discountPct > 0
            ? round($product['price'] * (1 - $discountPct / 100), 2)
            : floatval($product['price']);
          $_SESSION['cart'][$product['id']]['price'] = $lockedPrice;
        }

        // Fall back to current price if no locked price stored
        if ($lockedPrice === null) {
          $discountPct = floatval($product['discount_percent'] ?? 0);
          $lockedPrice = $discountPct > 0
            ? round($product['price'] * (1 - $discountPct / 100), 2)
            : floatval($product['price']);
        }

        $discountPct = floatval($product['discount_percent'] ?? 0);

        $product['qty']          = $qty;
        $product['charge_price'] = $lockedPrice;
        $product['discount_pct'] = $discountPct;
        $product['subtotal']     = $qty * $lockedPrice;
        $totalPrice             += $product['subtotal'];
        $cartItems[]             = $product;
      }
    }
  }
}

$pageTitle = "Cart - Genova Perfumes";
include 'includes/header.php';
?>

<div class="cart-page">
  <h1>🛒 Your Cart</h1>

  <?php if (empty($cartItems)): ?>

    <div class="cart-empty">
      <p>🌸 Your cart is empty.</p>
      <a href="/products" class="btn-primary">Continue Shopping</a>
    </div>

  <?php else: ?>

    <table class="cart-table">
      <thead>
        <tr>
          <th>Product</th>
          <th>Price</th>
          <th>Quantity</th>
          <th>Subtotal</th>
          <th>Remove</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($cartItems as $item): ?>
          <tr>
            <td class="cart-product-name" data-label="Product">
              <img src="<?php echo SHOP_URL; ?>/images/<?php
                                                        echo htmlspecialchars($item['image']); ?>"
                alt="<?php echo htmlspecialchars($item['name']); ?>"
                onerror="this.src='<?php echo SHOP_URL; ?>/images/placeholder.jpg'">
              <?php echo htmlspecialchars($item['name']); ?>
            </td>
            <td data-label="Price">
              <?php if ($item['discount_pct'] > 0): ?>
                <span class="price-original"
                  style="font-size:0.82rem;">
                  £<?php echo number_format(
                      $item['price'],
                      2
                    ); ?>
                </span><br>
                <span class="price-sale">
                  £<?php echo number_format(
                      $item['charge_price'],
                      2
                    ); ?>
                </span>
                <span class="sale-badge">
                  -<?php echo intval(
                      $item['discount_pct']
                    ); ?>%
                </span>
              <?php else: ?>
                £<?php echo number_format(
                    $item['price'],
                    2
                  ); ?>
              <?php endif; ?>
            </td>
            <td data-label="Quantity">
              <form method="POST" action="/cart" class="qty-form">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="product_id" value="<?php echo $item['id']; ?>">
                <input type="number"
                  name="quantity"
                  value="<?php echo $item['qty']; ?>"
                  min="0"
                  max="99"
                  class="qty-input"
                  onchange="this.form.submit()">
              </form>
            </td>
            <td data-label="Total">£<?php echo number_format($item['subtotal'], 2); ?></td>
            <td data-label="Remove">
              <form method="POST" action="/cart">
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="product_id" value="<?php echo $item['id']; ?>">
                <button type="submit" class="btn-remove">✕</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="cart-summary">
      <div class="cart-total">
        <span>Total:</span>
        <span class="total-price">£<?php echo number_format($totalPrice, 2); ?></span>
      </div>
      <div class="cart-actions">
        <a href="/cart?clear=1" class="btn-clear">Clear Cart</a>
        <a href="/products" class="btn-secondary">← Keep Shopping</a>
        <a href="/checkout" class="btn-primary">Checkout →</a>
      </div>
    </div>

  <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>