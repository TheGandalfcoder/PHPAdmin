<?php
require_once 'db.php';
require_once 'auth.php';
require_login();

$orderId = trim($_GET['order_id'] ?? '');
if ($orderId === '') {
    http_response_code(400);
    exit('Missing order ID.');
}

$db   = db();
$user = current_user();

// Load order — must belong to this user or match their email
$stmt = $db->prepare("SELECT * FROM orders WHERE order_id = ?");
$stmt->bind_param("s", $orderId);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    http_response_code(404);
    exit('Order not found.');
}

// Access check — must be the order owner or an admin
$ownsOrder = ($order['user_id'] === $user['id'])
          || ($order['user_id'] === null && $order['customer_email'] === $user['email']);

if (!$ownsOrder && !is_admin()) {
    http_response_code(403);
    exit('Access denied.');
}

// Load order items
$stmt = $db->prepare("
    SELECT oi.quantity, p.name, p.price, p.category, p.version, p.id AS product_id
    FROM order_items oi
    JOIN products p ON p.id = oi.product_id
    WHERE oi.order_id = ?
");
$stmt->bind_param("s", $orderId);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$db->close();

$vatRate  = 0.20;
$subtotal = 0.0;
foreach ($items as $it) {
    $subtotal += (float)$it['price'] * (int)$it['quantity'];
}
$vatAmount  = round($subtotal * $vatRate, 2);
$grandTotal = round($subtotal + $vatAmount, 2);

$address = htmlspecialchars($order['address_line1'], ENT_QUOTES, 'UTF-8');
if (!empty($order['address_line2'])) $address .= ', ' . htmlspecialchars($order['address_line2'], ENT_QUOTES, 'UTF-8');
$address .= ', ' . htmlspecialchars($order['city'], ENT_QUOTES, 'UTF-8');
$address .= ', ' . htmlspecialchars($order['postcode'], ENT_QUOTES, 'UTF-8');
$address .= ', ' . htmlspecialchars($order['country'], ENT_QUOTES, 'UTF-8');

function statusColour(string $s): array {
    $map = [
        'pending'    => ['#92400e','#fef3c7'],
        'paid'       => ['#065f46','#d1fae5'],
        'dispatched' => ['#1e40af','#dbeafe'],
        'arrived'    => ['#065f46','#d1fae5'],
        'cancelled'  => ['#991b1b','#fee2e2'],
    ];
    return $map[$s] ?? ['#374151','#f3f4f6'];
}
[$badgeFg, $badgeBg] = statusColour($order['status']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Invoice <?= e(substr($orderId,0,12)) ?>… | Pear Store</title>
  <style>
    *{box-sizing:border-box;}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;margin:0;background:#f5f5f7;color:#1d1d1f;}
    .page{max-width:760px;margin:32px auto;padding:0 16px 60px;}
    .back{font-size:14px;color:#0071e3;text-decoration:none;display:inline-block;margin-bottom:20px;}
    .invoice{background:#fff;border:1px solid #e5e7eb;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,.06);}
    .inv-head{padding:28px 32px;border-bottom:1px solid #f3f4f6;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;}
    .inv-head h1{font-size:22px;font-weight:700;margin:0 0 4px;letter-spacing:-.02em;}
    .inv-head .order-id{font-size:12px;font-family:monospace;color:#6b7280;}
    .badge{display:inline-block;padding:4px 10px;border-radius:4px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;}
    .meta-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:0;border-bottom:1px solid #f3f4f6;}
    .meta-box{padding:18px 32px;border-right:1px solid #f3f4f6;}
    .meta-box:last-child{border-right:none;}
    .meta-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;margin-bottom:5px;}
    .meta-value{font-size:14px;font-weight:600;color:#1d1d1f;line-height:1.4;}
    table{width:100%;border-collapse:collapse;}
    th{text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#9ca3af;font-weight:700;padding:12px 32px;background:#f9fafb;border-bottom:1px solid #f3f4f6;}
    th.r,td.r{text-align:right;}
    td{padding:14px 32px;font-size:14px;border-bottom:1px solid #f9fafb;vertical-align:middle;}
    tr:last-child td{border-bottom:none;}
    .totals{padding:16px 32px;background:#f9fafb;border-top:1px solid #f3f4f6;}
    .totals-row{display:flex;justify-content:flex-end;gap:80px;font-size:14px;padding:4px 0;}
    .totals-row.grand{font-size:16px;font-weight:700;padding-top:10px;border-top:1px solid #e5e7eb;margin-top:6px;}
    .totals-label{color:#6b7280;}
    @media print{.back{display:none;}}
    @media(max-width:600px){
      th,td{padding:10px 16px;}
      .inv-head,.meta-box,.totals{padding-left:16px;padding-right:16px;}
    }
  </style>
</head>
<body>
<?php include '_nav.php'; ?>
<div class="page">
  <a href="account.php" class="back">← Back to My Account</a>

  <div class="invoice">

    <div class="inv-head">
      <div>
        <h1>Invoice</h1>
        <div class="order-id">Order #<?= e($orderId) ?></div>
      </div>
      <div style="text-align:right;">
        <span class="badge" style="background:<?= $badgeBg ?>;color:<?= $badgeFg ?>"><?= ucfirst(e($order['status'])) ?></span>
        <div style="font-size:13px;color:#6b7280;margin-top:6px;"><?= e(date('j F Y', strtotime($order['created_at']))) ?></div>
      </div>
    </div>

    <div class="meta-grid">
      <div class="meta-box">
        <div class="meta-label">Customer</div>
        <div class="meta-value"><?= e($order['customer_name']) ?></div>
        <?php if ($order['customer_email']): ?>
          <div style="font-size:12px;color:#6b7280;margin-top:2px;"><?= e($order['customer_email']) ?></div>
        <?php endif; ?>
      </div>
      <div class="meta-box">
        <div class="meta-label">Delivery Address</div>
        <div class="meta-value" style="font-weight:400;"><?= $address ?></div>
      </div>
      <div class="meta-box">
        <div class="meta-label">Order Date</div>
        <div class="meta-value"><?= e(date('j M Y, H:i', strtotime($order['created_at']))) ?></div>
      </div>
    </div>

    <table>
      <thead>
        <tr>
          <th>Product</th>
          <th class="r">Qty</th>
          <th class="r">Unit Price</th>
          <th class="r">Line Total</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $it):
          $line = (float)$it['price'] * (int)$it['quantity'];
        ?>
        <tr>
          <td><?= e($it['name']) ?></td>
          <td class="r"><?= (int)$it['quantity'] ?></td>
          <td class="r">£<?= number_format((float)$it['price'], 2) ?></td>
          <td class="r"><strong>£<?= number_format($line, 2) ?></strong></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="totals">
      <div class="totals-row">
        <span class="totals-label">Subtotal</span>
        <span>£<?= number_format($subtotal, 2) ?></span>
      </div>
      <div class="totals-row">
        <span class="totals-label">VAT (<?= (int)($vatRate*100) ?>%)</span>
        <span>£<?= number_format($vatAmount, 2) ?></span>
      </div>
      <div class="totals-row grand">
        <span class="totals-label">Total (inc. VAT)</span>
        <span>£<?= number_format($grandTotal, 2) ?></span>
      </div>
    </div>

  </div>
</div>
</body>
</html>
