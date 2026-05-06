<?php
require_once 'db.php';
require_once 'auth.php';

// returns the first image path found in img/<category>/<version>/
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

$orderId = $_GET['order_id'] ?? '';
if ($orderId === '') { die('Missing order_id'); }

$db = db();

$stmt = $db->prepare("SELECT * FROM orders WHERE order_id = ?");
$stmt->bind_param("s", $orderId);
$stmt->execute();
$orderRes = $stmt->get_result();
$order = $orderRes->fetch_assoc();
$stmt->close();

if (!$order) { die('Order not found'); }

// load items with names, prices, and image paths
$stmt = $db->prepare("
  SELECT
    oi.product_id,
    oi.quantity,
    p.name,
    p.price,
    p.category,
    p.version
  FROM order_items oi
  JOIN products p ON p.id = oi.product_id
  WHERE oi.order_id = ?
");
$stmt->bind_param("s", $orderId);
$stmt->execute();
$itemsRes = $stmt->get_result();

$items = [];
while ($row = $itemsRes->fetch_assoc()) {
  $items[] = $row;
}
$stmt->close();
$db->close();

// build the invoice table as HTML so it can be embedded in the page and emailed
$vatRate = 0.20;

$invoiceHtml = '<table class="invoice-table">';
$invoiceHtml .= '
  <tr>
    <th class="th-img"></th>
    <th>Item</th>
    <th class="th-right">Qty</th>
    <th class="th-right">Unit</th>
    <th class="th-right">Line</th>
  </tr>
';

$subtotal = 0.0;
foreach ($items as $it) {
  $line = (float)$it['price'] * (int)$it['quantity'];
  $subtotal += $line;

  $thumb = firstVersionImage($it['category'], $it['version']);
  $thumbHtml = $thumb
    ? '<img class="inv-thumb" src="'.e($thumb).'" alt="'.e($it['name']).'">'
    : '<div class="inv-thumb inv-thumb--empty"></div>';

  $invoiceHtml .= '<tr>';
  $invoiceHtml .= '<td class="td-img">'.$thumbHtml.'</td>';
  $invoiceHtml .= '<td class="td-item">' . e($it['name']) . '</td>';
  $invoiceHtml .= '<td class="td-right">' . (int)$it['quantity'] . '</td>';
  $invoiceHtml .= '<td class="td-right">£' . number_format((float)$it['price'], 2) . '</td>';
  $invoiceHtml .= '<td class="td-right"><strong>£' . number_format($line, 2) . '</strong></td>';
  $invoiceHtml .= '</tr>';
}

// VAT summary rows
$vatAmount  = round($subtotal * $vatRate, 2);
$grandTotal = round($subtotal + $vatAmount, 2);

$invoiceHtml .= '<tr class="tr-summary"><td colspan="4" class="td-right">Subtotal</td><td class="td-right">£' . number_format($subtotal, 2) . '</td></tr>';
$invoiceHtml .= '<tr class="tr-summary"><td colspan="4" class="td-right">VAT (' . (int)($vatRate * 100) . '%)</td><td class="td-right">£' . number_format($vatAmount, 2) . '</td></tr>';
$invoiceHtml .= '<tr class="tr-total"><td colspan="4" class="td-right">Total (inc. VAT)</td><td class="td-right">£' . number_format($grandTotal, 2) . '</td></tr>';

$invoiceHtml .= '</table>';

$total = $grandTotal;

// build the delivery address as a single escaped string
$address = e($order['address_line1']) . '<br>';
if (!empty($order['address_line2'])) $address .= e($order['address_line2']) . '<br>';
$address .= e($order['city']) . '<br>' . e($order['postcode']) . '<br>' . e($order['country']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Order Success</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="css/nav.css">
  <link rel="stylesheet" href="css/order-success.css">
</head>

<body>
<?php include '_nav.php'; ?>
  <div class="wrap">

    <div class="topbar">
         <a href="home.php">← Home</a>
      <div class="pill">Order complete</div>
      <div class="pill">Order ID: <strong><?php echo e($orderId); ?></strong></div>
    </div>

    <div class="card">
      <h1>Thanks! Your order is placed.</h1>

      <div class="meta">
        <div class="box">
          <div class="label">Total</div>
          <div class="value">£<?php echo number_format((float)$order['total'], 2); ?></div>
        </div>

        <div class="box">
          <div class="label">Customer</div>
          <div class="value"><?php echo e($order['customer_name']); ?></div>
          <?php if (!empty($order['customer_email'])): ?>
            <div class="hint"><?php echo e($order['customer_email']); ?></div>
          <?php endif; ?>
        </div>

        <div class="box">
          <div class="label">Delivery address</div>
          <p class="address"><?php echo $address; ?></p>
        </div>
      </div>

      <h3>Invoice preview</h3>
      <?php echo $invoiceHtml; ?>

      <hr>
      <p id="emailStatus">Sending invoice email…</p>
    </div>

    <!-- EmailJS SDK -->
    <script src="https://cdn.jsdelivr.net/npm/@emailjs/browser@4/dist/email.min.js"></script>
    <script>
      emailjs.init("PNXDYon0cBekaw3H8");

      const templateParams = {
        to_email: "<?php echo e($order['customer_email']); ?>",
        customer_name: "<?php echo e($order['customer_name']); ?>",
        order_id: "<?php echo e($orderId); ?>",
        total: "<?php echo number_format((float)$order['total'], 2); ?>",
        invoice_html: <?php echo json_encode($invoiceHtml); ?>
      };

      emailjs.send("service_1jwevr7", "template_qrdh3h7", templateParams)
        .then(() => {
          document.getElementById("emailStatus").textContent = "Invoice email sent ";
        })
        .catch((err) => {
          console.error(err);
          document.getElementById("emailStatus").textContent = "Invoice email failed ";
        });
    </script>

  </div>
</body>
</html>
