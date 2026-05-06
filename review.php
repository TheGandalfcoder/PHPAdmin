<?php
require_once 'db.php';
require_once 'auth.php';
require_login();

$user    = current_user();
$db      = db();
$orderId = trim($_GET['order_id'] ?? '');

if ($orderId === '') {
    http_response_code(400);
    exit('Missing order ID.');
}

// Load order — must be arrived and belong to this user
$stmt = $db->prepare("SELECT * FROM orders WHERE order_id = ?");
$stmt->bind_param("s", $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    http_response_code(404);
    exit('Order not found.');
}

$ownsOrder = ($order['user_id'] === $user['id'])
          || ($order['user_id'] === null && $order['customer_email'] === $user['email']);

if (!$ownsOrder && !is_admin()) {
    http_response_code(403);
    exit('Access denied.');
}

if ($order['status'] !== 'arrived') {
    http_response_code(403);
    exit('Reviews can only be left for orders that have arrived.');
}

// Load products from this order
$stmt = $db->prepare("
    SELECT oi.product_id, oi.quantity, p.name, p.category, p.version
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    WHERE oi.order_id = ?
");
$stmt->bind_param("s", $orderId);
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Check which products already have a review for this order
$reviewed = [];
foreach ($products as $prod) {
    $stmt = $db->prepare("SELECT id FROM reviews WHERE product_id = ? AND order_id = ?");
    $stmt->bind_param("is", $prod['product_id'], $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) $reviewed[$prod['product_id']] = true;
}

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid form submission.';
    } else {
        $submitted = 0;
        foreach ($products as $prod) {
            $pid     = (int)$prod['product_id'];
            $rating  = (int)($_POST['rating_' . $pid] ?? 0);
            $comment = trim($_POST['comment_' . $pid] ?? '');

            if (isset($reviewed[$pid])) continue; // already reviewed

            if ($rating < 1 || $rating > 5) {
                $errors[] = 'Please select a star rating for "' . $prod['name'] . '".';
                continue;
            }
            if ($comment === '') {
                $errors[] = 'Please write a comment for "' . $prod['name'] . '".';
                continue;
            }

            // INSERT IGNORE means a duplicate review (same product + order) is silently skipped
            $stmt = $db->prepare("
                INSERT IGNORE INTO reviews (product_id, order_id, user_id, rating, comment)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->bind_param("issis", $pid, $orderId, $user['id'], $rating, $comment);
            $stmt->execute();
            $stmt->close();
            $reviewed[$pid] = true;
            $submitted++;
        }

        if (empty($errors) && $submitted > 0) {
            $success = true;
        } elseif (empty($errors) && $submitted === 0) {
            $success = true; // all already reviewed
        }
    }
}

$db->close();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Leave a Review | Pear Store</title>
  <style>
    *{box-sizing:border-box;}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;margin:0;background:#f5f5f7;color:#1d1d1f;}
    .page{max-width:700px;margin:32px auto;padding:0 16px 60px;}
    .back{font-size:14px;color:#0071e3;text-decoration:none;display:inline-block;margin-bottom:20px;}
    h1{font-size:24px;font-weight:700;margin:0 0 6px;letter-spacing:-.02em;}
    .sub{font-size:14px;color:#6b7280;margin:0 0 24px;}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:24px;margin-bottom:16px;}
    .product-name{font-size:16px;font-weight:700;margin:0 0 14px;}
    .stars{display:flex;flex-direction:row-reverse;justify-content:flex-end;gap:4px;margin-bottom:14px;}
    .stars input{display:none;}
    .stars label{font-size:28px;color:#d1d5db;cursor:pointer;transition:color .1s;}
    .stars input:checked ~ label,
    .stars label:hover,
    .stars label:hover ~ label{color:#f59e0b;}
    .field label{display:block;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px;}
    textarea{width:100%;padding:9px 11px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;font-family:inherit;resize:vertical;transition:border-color .15s;}
    textarea:focus{outline:none;border-color:#0071e3;box-shadow:0 0 0 3px rgba(0,113,227,.1);}
    .btn{padding:10px 22px;background:#0071e3;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;transition:background .15s;}
    .btn:hover{background:#005bb5;}
    .alert-ok{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px 18px;margin-bottom:20px;font-size:14px;color:#166534;}
    .alert-ok a{color:#166534;font-weight:600;}
    .errors{background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:14px;color:#b91c1c;}
    .errors ul{margin:4px 0 0;padding-left:16px;}
    .reviewed-note{font-size:13px;color:#6b7280;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px;}
  </style>
</head>
<body>
<?php include '_nav.php'; ?>
<div class="page">
  <a href="account.php" class="back">← Back to My Account</a>

  <h1>Leave a Review</h1>
  <p class="sub">Order #<?= e(substr($orderId, 0, 12)) ?>… &mdash; rate each product you received.</p>

  <?php if ($success): ?>
    <div class="alert-ok">
      Your review<?= count($products) > 1 ? 's have' : ' has' ?> been submitted. Thank you!
      <br><a href="account.php">Back to My Account</a>
    </div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
    <div class="errors">
      <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <?php if (!$success): ?>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

    <?php foreach ($products as $prod):
      $pid = (int)$prod['product_id'];
    ?>
      <div class="card">
        <div class="product-name"><?= e($prod['name']) ?></div>

        <?php if (isset($reviewed[$pid])): ?>
          <div class="reviewed-note">You have already reviewed this product.</div>
        <?php else: ?>
          <div class="field" style="margin-bottom:14px;">
            <label>Your rating</label>
            <div class="stars">
              <?php for ($s = 5; $s >= 1; $s--): ?>
                <input type="radio" name="rating_<?= $pid ?>" id="star<?= $pid ?>_<?= $s ?>" value="<?= $s ?>">
                <label for="star<?= $pid ?>_<?= $s ?>" title="<?= $s ?> star<?= $s > 1 ? 's' : '' ?>">&#9733;</label>
              <?php endfor; ?>
            </div>
          </div>
          <div class="field">
            <label>Comment</label>
            <textarea name="comment_<?= $pid ?>" rows="4" placeholder="What did you think?"><?= e($_POST['comment_' . $pid] ?? '') ?></textarea>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <?php
    $allReviewed = count(array_filter($products, fn($p) => isset($reviewed[(int)$p['product_id']]))) === count($products);
    if (!$allReviewed): ?>
      <button type="submit" class="btn">Submit Review<?= count($products) > 1 ? 's' : '' ?></button>
    <?php endif; ?>
  </form>
  <?php endif; ?>

</div>
</body>
</html>
