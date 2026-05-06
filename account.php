<?php
require_once 'db.php';
require_once 'auth.php';
require_login();

$user   = current_user();
$db     = db();
$errors = [];
$flash  = $_SESSION['account_flash'] ?? null;
unset($_SESSION['account_flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid form submission.';
    } else {
        $newName  = trim($_POST['name'] ?? '');
        $newEmail = trim($_POST['email'] ?? '');

        if ($newName === '') $errors[] = 'Name is required.';
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email address is required.';

        if (empty($errors)) {
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
            $stmt->bind_param("si", $newEmail, $user['id']);
            $stmt->execute();
            $clash = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($clash) {
                $errors[] = 'That email address is already associated with another account.';
            } else {
                $stmt = $db->prepare("UPDATE users SET name = ?, email = ? WHERE id = ?");
                $stmt->bind_param("ssi", $newName, $newEmail, $user['id']);
                $stmt->execute();
                $stmt->close();

                $_SESSION['auth_user']['name']  = $newName;
                $_SESSION['auth_user']['email'] = $newEmail;
                $user = current_user();
                $_SESSION['account_flash'] = ['success' => 'Account details updated.'];
                header('Location: account.php');
                exit;
            }
        }
    }
}

// fetch orders placed while logged in, plus any guest orders using the same email address
$orders = [];
$stmt = $db->prepare("
    SELECT order_id, created_at, total, status, city, country
    FROM orders
    WHERE user_id = ? OR (user_id IS NULL AND customer_email = ?)
    ORDER BY created_at DESC
");
$stmt->bind_param("is", $user['id'], $user['email']);
$stmt->execute();
$r = $stmt->get_result();
while ($row = $r->fetch_assoc()) $orders[] = $row;
$stmt->close();

// load the line items for each order so we can show the product breakdown
$orderItems = [];
foreach ($orders as $ord) {
    $oid  = $ord['order_id'];
    $stmt = $db->prepare("
        SELECT oi.quantity, p.name, p.price
        FROM order_items oi
        JOIN products p ON p.id = oi.product_id
        WHERE oi.order_id = ?
    ");
    $stmt->bind_param("s", $oid);
    $stmt->execute();
    $items = [];
    $res   = $stmt->get_result();
    while ($it = $res->fetch_assoc()) $items[] = $it;
    $stmt->close();
    $orderItems[$oid] = $items;
}

$pendingCount = count(array_filter($orders, fn($o) => $o['status'] === 'pending'));

function statusBadge(string $status): string {
    $map = [
        'pending'    => ['Pending Payment', '#92400e', '#fef3c7'],
        'paid'       => ['Paid',            '#065f46', '#d1fae5'],
        'dispatched' => ['Dispatched',      '#1e40af', '#dbeafe'],
        'arrived'    => ['Arrived',         '#065f46', '#d1fae5'],
        'cancelled'  => ['Cancelled',       '#991b1b', '#fee2e2'],
    ];
    [$label, $color, $bg] = $map[$status] ?? [$status, '#374151', '#f3f4f6'];
    return '<span style="display:inline-block;padding:3px 9px;border-radius:4px;font-size:11px;font-weight:700;'
         . 'text-transform:uppercase;letter-spacing:.04em;background:' . $bg . ';color:' . $color . '">'
         . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>My Account | Pear Store</title>
  <style>
    *{box-sizing:border-box;}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;margin:0;background:#f5f5f7;color:#1d1d1f;}
    .page{max-width:960px;margin:0 auto;padding:32px 18px;}
    h1{font-size:26px;font-weight:700;margin:0 0 4px;letter-spacing:-.02em;}
    .subhead{color:#6b7280;font-size:14px;margin:0 0 28px;}
    .grid{display:grid;grid-template-columns:300px 1fr;gap:24px;align-items:start;}
    @media(max-width:768px){.grid{grid-template-columns:1fr;}}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:24px;}
    .card h2{font-size:16px;font-weight:700;margin:0 0 18px;padding-bottom:12px;border-bottom:1px solid #f3f4f6;}
    .field{margin-bottom:14px;}
    .field label{display:block;font-size:12px;font-weight:600;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;margin-bottom:5px;}
    .field input{width:100%;padding:9px 11px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;font-family:inherit;transition:border-color .15s;}
    .field input:focus{outline:none;border-color:#0071e3;box-shadow:0 0 0 3px rgba(0,113,227,.1);}
    .btn{padding:9px 18px;background:#0071e3;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;transition:background .15s;}
    .btn:hover{background:#005bb5;}
    .alert-warn{background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:14px 18px;margin-bottom:20px;font-size:14px;color:#92400e;}
    .alert-warn strong{display:block;margin-bottom:2px;}
    .alert-ok{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:14px 18px;margin-bottom:20px;font-size:14px;color:#166534;}
    .errors{background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:14px;color:#b91c1c;}
    .errors ul{margin:4px 0 0;padding-left:16px;}
    .order-row{border:1px solid #e5e7eb;border-radius:10px;margin-bottom:12px;overflow:hidden;}
    .order-header{padding:14px 16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;background:#fafafa;}
    .order-id{font-size:12px;font-family:monospace;color:#374151;background:#e5e7eb;padding:2px 7px;border-radius:4px;}
    .order-meta{font-size:13px;color:#6b7280;}
    .order-total{font-size:15px;font-weight:700;color:#1d1d1f;}
    .order-items{padding:14px 16px;border-top:1px solid #f3f4f6;}
    .order-items table{width:100%;border-collapse:collapse;font-size:13px;}
    .order-items td{padding:4px 0;vertical-align:top;}
    .order-items td:last-child{text-align:right;font-weight:600;}
    .empty-state{text-align:center;padding:40px;color:#9ca3af;font-size:15px;}
    details summary{cursor:pointer;font-size:13px;color:#0071e3;list-style:none;padding:8px 16px;border-top:1px solid #f3f4f6;}
    details summary::-webkit-details-marker{display:none;}
    details summary::before{content:'+ '}
    details[open] summary::before{content:'- '}
  </style>
</head>
<body>
<?php include '_nav.php'; ?>
<div class="page">

  <h1>My Account</h1>
  <p class="subhead">Signed in as <?= e($user['name']) ?> &mdash; <?= e($user['email']) ?></p>

  <?php if ($pendingCount > 0): ?>
    <div class="alert-warn">
      <strong>Outstanding payment(s)</strong>
      You have <?= $pendingCount ?> order<?= $pendingCount > 1 ? 's' : '' ?> awaiting payment.
      Please contact us to process payment.
    </div>
  <?php endif; ?>

  <?php if (!empty($flash['success'])): ?>
    <div class="alert-ok"><?= e($flash['success']) ?></div>
  <?php endif; ?>

  <div class="grid">

    <aside class="card">
      <h2>Account Details</h2>

      <?php if (!empty($errors)): ?>
        <div class="errors">
          <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="field">
          <label>Full name</label>
          <input type="text" name="name" value="<?= e($user['name']) ?>" required>
        </div>
        <div class="field">
          <label>Email address</label>
          <input type="email" name="email" value="<?= e($user['email']) ?>" required>
        </div>
        <button type="submit" class="btn">Save changes</button>
      </form>
    </aside>

    <main>
      <div class="card">
        <h2>Order History (<?= count($orders) ?>)</h2>

        <?php if (empty($orders)): ?>
          <div class="empty-state">
            No orders yet. <a href="main.php" style="color:#0071e3">Start shopping</a>
          </div>
        <?php else: ?>
          <?php foreach ($orders as $ord):
            $oid   = $ord['order_id'];
            $items = $orderItems[$oid] ?? [];
          ?>
            <div class="order-row">
              <div class="order-header">
                <div>
                  <span class="order-id"><?= e(substr($oid, 0, 12)) ?>...</span>
                  <span class="order-meta" style="margin-left:10px">
                    <?= e(date('j M Y', strtotime($ord['created_at']))) ?>
                  </span>
                </div>
                <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                  <?= statusBadge($ord['status']) ?>
                  <span class="order-total">£<?= number_format((float)$ord['total'], 2) ?></span>
                  <a href="invoice.php?order_id=<?= urlencode($oid) ?>" style="font-size:13px;color:#0071e3;text-decoration:none;white-space:nowrap;">View Invoice</a>
                  <?php if ($ord['status'] === 'arrived'): ?>
                    <a href="review.php?order_id=<?= urlencode($oid) ?>" style="font-size:13px;color:#0071e3;text-decoration:none;white-space:nowrap;">Leave a Review</a>
                  <?php endif; ?>
                </div>
              </div>
              <details>
                <summary><?= count($items) ?> item<?= count($items) !== 1 ? 's' : '' ?></summary>
                <div class="order-items">
                  <table>
                    <?php foreach ($items as $it): ?>
                      <tr>
                        <td><?= e($it['name']) ?></td>
                        <td style="padding:4px 20px;">x<?= (int)$it['quantity'] ?></td>
                        <td>£<?= number_format((float)$it['price'] * (int)$it['quantity'], 2) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </table>
                </div>
              </details>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </main>

  </div>
</div>
</body>
</html>
