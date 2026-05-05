<?php
require_once 'db.php';
require_once 'auth.php';

function firstVersionImage($category, $version){// helper: first image in img/<category>/<version>/
  $dirDisk = __DIR__ . "/img/$category/$version";
  $dirWeb  = "img/$category/$version";
  if (!is_dir($dirDisk)) return null;

  $found = glob($dirDisk . "/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}", GLOB_BRACE);// find images
  if (empty($found)) return null;

  natsort($found);// natural sort
  $found = array_values($found);
  return $dirWeb . "/" . basename($found[0]);
}

$orderId = $_GET['order_id'] ?? '';
if ($orderId === '') { die('Missing order_id'); }// order_id check

$db = db();

// Load order
$stmt = $db->prepare("SELECT * FROM orders WHERE order_id = ?");// order fetch
$stmt->bind_param("s", $orderId);
$stmt->execute();
$orderRes = $stmt->get_result();
$order = $orderRes->fetch_assoc();// fetch order data
$stmt->close();

if (!$order) { die('Order not found'); }

// Load items with product names/prices + category/version for images
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

// Build invoice HTML (table)
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

// Address block
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

  <style>
    :root{
      --bg:#f6f7fb;
      --card:#ffffff;
      --text:#111827;
      --muted:#6b7280;
      --border:#e5e7eb;
      --brand:#0071e3;
      --ok:#16a34a;
      --bad:#dc2626;
      --shadow: 0 8px 30px rgba(0,0,0,.08);
      --radius: 14px;
    }

    body{
      margin:0;
      font-family: Arial, Helvetica, sans-serif;
      background: var(--bg);
      color: var(--text);
    }

    .wrap{
      max-width: 980px;
      margin: 22px auto;
      padding: 0 16px;
    }

    .topbar{
      display:flex;
      justify-content:space-between;
      align-items:center;
      gap:12px;
      flex-wrap:wrap;
      margin-bottom: 14px;
    }

    .pill{
      display:inline-flex;
      align-items:center;
      gap:8px;
      padding: 8px 12px;
      border:1px solid var(--border);
      border-radius: 999px;
      background: rgba(255,255,255,.7);
      backdrop-filter: blur(6px);
      font-size: 14px;
      color: var(--muted);
    }

    .card{
      background: var(--card);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      padding: 18px;
      margin-top: 14px;
    }

    h1{
      margin: 0 0 8px;
      font-size: 26px;
      letter-spacing: -0.02em;
    }

    h3{
      margin: 18px 0 10px;
      font-size: 16px;
    }

    .meta{
      display:flex;
      gap:12px;
      flex-wrap:wrap;
      margin-top: 10px;
    }

    .meta .box{
      flex: 1 1 240px;
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 12px;
      background: #fbfbfd;
    }

    .label{ font-size: 12px; color: var(--muted); margin-bottom: 4px; }
    .value{ font-weight: 700; }

    .address{
      line-height: 1.4;
      color: #111827;
      margin: 0;
    }

    .invoice-table{
      width: 100%;
      border-collapse: collapse;
      overflow: hidden;
      border: 1px solid var(--border);
      border-radius: 12px;
    }

    .invoice-table th{
      text-align:left;
      font-size: 13px;
      color: var(--muted);
      background: #f9fafb;
      border-bottom: 1px solid var(--border);
      padding: 10px 12px;
      white-space: nowrap;
    }

    .invoice-table td{
      border-bottom: 1px solid #f1f5f9;
      padding: 10px 12px;
      vertical-align: middle;
      font-size: 14px;
    }

    .invoice-table tr:last-child td{
      border-bottom: none;
    }

    .th-right, .td-right{
      text-align:right;
      white-space: nowrap;
    }

    .th-img, .td-img{
      width: 58px;
    }

    .inv-thumb{
      width: 44px;
      height: 44px;
      object-fit: cover;
      border-radius: 10px;
      border: 1px solid var(--border);
      display:block;
      background:#fff;
    }

    .inv-thumb--empty{
      display:block;
      width: 44px;
      height: 44px;
      border-radius: 10px;
      border: 1px dashed var(--border);
      background: #fafafa;
    }

    .td-item{
      font-weight: 700;
    }

    .tr-summary td{
      border-top: 1px solid var(--border);
      font-size: 13px;
      color: var(--muted);
      padding: 8px 12px;
    }

    .tr-total td{
      border-top: 2px solid var(--border);
      font-weight: 700;
      font-size: 15px;
      padding: 10px 12px;
    }

    hr{
      border:none;
      border-top: 1px solid var(--border);
      margin: 18px 0;
    }

    #emailStatus{
      margin: 0;
      color: var(--muted);
      font-size: 14px;
    }

    .hint{
      margin-top:10px;
      font-size: 13px;
      color: var(--muted);
    }
  </style>
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
