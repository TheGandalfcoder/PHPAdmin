<?php
require_once 'db.php';
require_once 'auth.php';
csrf_token();

// read and clear any flash message from the previous request
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$db = db();

$productsById = [];
$res = $db->query("SELECT id, category, version, name, price, description FROM products");
if ($res) {
  while ($row = $res->fetch_assoc()) {
    $row['price'] = (float)$row['price'];
    $productsById[(string)$row['id']] = $row;
  }
  $res->free();
}
$db->close();

// Cart
$cart = $_SESSION['cart'] ?? [];
if (!is_array($cart)) $cart = [];

function cartCount($cart){
  return array_sum(array_map('intval', array_values($cart)));
}

function cartSubtotal($cart, $productsById){
  $sum = 0.0;
  foreach ($cart as $id => $qty) {
    $id = (string)$id;
    $q  = (int)$qty;
    if ($q <= 0) continue;
    if (!isset($productsById[$id])) continue;
    $sum += $productsById[$id]['price'] * $q;
  }
  return round($sum, 2);
}

function vatAmount($subtotal, $rate = 0.20){
  return round(((float)$subtotal) * (float)$rate, 2);
}

function totalWithVat($subtotal, $rate = 0.20){
  return round(((float)$subtotal) + (((float)$subtotal) * (float)$rate), 2);
}

// returns the path to the first image found in img/<category>/<version>/
function firstVersionImage($category, $version){
  $dirDisk = __DIR__ . "/img/$category/$version";
  $dirWeb  = "img/$category/$version";
  if (!is_dir($dirDisk)) return null;

  $found = glob($dirDisk . "/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}", GLOB_BRACE);
  if (empty($found)) return null;

  natsort($found);
  $found = array_values($found);
  return $dirWeb . "/" . basename($found[0]);
}

$vatRate    = 0.20;
$subtotal   = cartSubtotal($cart, $productsById);
$vat        = vatAmount($subtotal, $vatRate);
$grandTotal = totalWithVat($subtotal, $vatRate);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Your Cart | Pear Store</title>
  <style>
    body{font-family:Arial,Helvetica,sans-serif; max-width:1100px; margin:auto; padding:18px;}
    a{color:#0071e3; text-decoration:none;}
    .row{display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap;}
    .card{border:1px solid #eef2f6; border-radius:12px; padding:14px; margin-top:14px;}
    .item{display:flex; gap:14px; align-items:center; padding:12px 0; border-bottom:1px solid #eef2f6;}
    .item:last-child{border-bottom:none;}
    img{width:84px; height:84px; object-fit:cover; border-radius:10px; border:1px solid #eef2f6;}
    .name{font-weight:700;}
    .small{color:#444; font-size:14px;}
    .price{color:#0071e3; font-weight:700;}
    button{background:#0071e3; border:0; color:#fff; padding:8px 10px; border-radius:8px; cursor:pointer;}
    .danger{background:#ff4d4d;}
    input, select{font-family:inherit; padding:7px 8px;}
    .totals{min-width:240px;}
    .totals .line{display:flex; justify-content:space-between; gap:12px; margin:6px 0;}
    .totals .label{color:#444; font-size:14px;}
    .totals .value{font-weight:700;}
    .totals .grand{font-weight:800; font-size:20px;}
    .muted{color:#666; font-size:13px;}
  </style>
</head>
<body>
<?php include '_nav.php'; ?>
  <div class="row">
    <div>
      <a href="home.php">← Home</a> &nbsp;|&nbsp;
      <a href="main.php">Continue shopping</a>
    </div>
    <div>
      Cart: <strong><?php echo (int)cartCount($cart); ?></strong> items —
      <strong>£<?php echo number_format($grandTotal, 2); ?></strong>
      <span class="small">(inc. VAT)</span>
    </div>
  </div>

  <?php if ($flash): ?>
    <?php if (!empty($flash['errors'])): ?>
      <div class="card" style="border-color:#ffb4b4; color:#8b0000">
        <strong>Errors:</strong>
        <ul><?php foreach ($flash['errors'] as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?></ul>
      </div>
    <?php elseif (!empty($flash['success'])): ?>
      <div class="card" style="border-color:#b7f7c8; color:green">
        <strong><?php echo e($flash['success']); ?></strong>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="card">
    <h2 style="margin:0 0 10px;">Your Cart</h2>

    <?php if (empty($cart)): ?>
      <div class="small">Your cart is empty.</div>
    <?php else: ?>

      <?php foreach ($cart as $id => $qty):
        $id = (string)$id;
        $qty = (int)$qty;
        if ($qty <= 0) continue;
        if (!isset($productsById[$id])) continue;
        $p = $productsById[$id];
        $thumb = firstVersionImage($p['category'], $p['version']);
      ?>
        <div class="item">
          <?php if ($thumb): ?>
            <a href="product.php?id=<?php echo (int)$p['id']; ?>">
              <img src="<?php echo e($thumb); ?>" alt="<?php echo e($p['name']); ?>">
            </a>
          <?php endif; ?>

          <div style="flex:1;">
            <div class="name">
              <a href="product.php?id=<?php echo (int)$p['id']; ?>"><?php echo e($p['name']); ?></a>
            </div>
            <div class="small">
              £<?php echo number_format($p['price'],2); ?> × <?php echo $qty; ?> =
              <strong>£<?php echo number_format($p['price'] * $qty,2); ?></strong>
            </div>
          </div>

          <div style="display:flex; gap:8px; align-items:center;">
            <form method="post" action="processor.php">
              <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
              <input type="hidden" name="return_to" value="cart.php">
              <input type="hidden" name="action" value="inc">
              <input type="hidden" name="id" value="<?php echo e($id); ?>">
              <button type="submit">+</button>
            </form>

            <form method="post" action="processor.php">
              <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
              <input type="hidden" name="return_to" value="cart.php">
              <input type="hidden" name="action" value="dec">
              <input type="hidden" name="id" value="<?php echo e($id); ?>">
              <button type="submit">−</button>
            </form>

            <form method="post" action="processor.php">
              <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
              <input type="hidden" name="return_to" value="cart.php">
              <input type="hidden" name="action" value="remove">
              <input type="hidden" name="id" value="<?php echo e($id); ?>">
              <button type="submit" class="danger">🗑️</button>
            </form>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="row" style="margin-top:14px;">
        <div class="totals">
          <div class="line">
            <div class="label">Subtotal</div>
            <div class="value">£<?php echo number_format($subtotal, 2); ?></div>
          </div>
          <div class="line">
            <div class="label">VAT (20%)</div>
            <div class="value">£<?php echo number_format($vat, 2); ?></div>
          </div>
          <div class="line">
            <div class="label"><strong>Total (inc. VAT)</strong></div>
            <div class="grand">£<?php echo number_format($grandTotal, 2); ?></div>
          </div>
          <div class="muted">VAT rate: <?php echo (int)($vatRate * 100); ?>%</div>
        </div>

        <div style="display:flex; gap:10px; align-items:flex-start; flex-wrap:wrap;">
          <form method="post" action="processor.php">
            <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="return_to" value="cart.php">
            <input type="hidden" name="action" value="clear">
            <button type="submit" class="danger">Clear cart</button>
          </form>

          <form method="post" action="processor.php" id="checkoutForm"
                style="display:flex; gap:8px; align-items:flex-start; flex-wrap:wrap;">

            <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="return_to" value="cart.php">
            <input type="hidden" name="action" value="checkout">

            <div style="display:flex; flex-direction:column; gap:8px;">
              <input name="name" id="nameInput" placeholder="Your name" required
                     value="<?php echo e(current_user()['name'] ?? ''); ?>">
              <input name="email" placeholder="Email (required for invoice)"
                     value="<?php echo e(current_user()['email'] ?? ''); ?>">

              <!-- address fields shown only once the user has typed their name -->
              <div id="addressBlock" style="display:none; margin-top:6px; padding-top:6px; border-top:1px solid #eef2f6;">
                <div class="small" style="margin-bottom:6px;">Delivery address</div>

                <input name="address_line1" placeholder="Address line 1" required>
                <input name="address_line2" placeholder="Address line 2 (optional)">
                <input name="city" placeholder="City / Town" required>
                <input name="postcode" placeholder="Postcode" required>
                <select name="country" required>
                  <option value="">Select country</option>
                  <option value="United Kingdom">United Kingdom</option>
                  <option value="Ireland">Ireland</option>
                  <option value="United States">United States</option>
                  <option value="France">France</option>
                  <option value="Germany">Germany</option>
                  <option value="Spain">Spain</option>
                </select>
              </div>
            </div>

            <div style="display:flex; flex-direction:column; gap:8px;">
              <button type="submit">Checkout</button>
            </div>
          </form>

          <script>
            (function () {
              const nameInput = document.getElementById('nameInput');
              const addressBlock = document.getElementById('addressBlock');

              function toggleAddress() {
                const hasName = nameInput.value.trim().length > 0;
                addressBlock.style.display = hasName ? 'block' : 'none';
              }

              nameInput.addEventListener('input', toggleAddress);
              toggleAddress();
            })();
          </script>

        </div>
      </div>

    <?php endif; ?>
  </div>
</body>
</html>
