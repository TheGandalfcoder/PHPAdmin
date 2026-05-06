<?php
require_once 'db.php';
require_once 'auth.php';
require_admin();

$section = $_GET['section'] ?? 'overview';
$report  = $_GET['report']  ?? 'products';
$flash   = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

// handle all admin form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $_SESSION['admin_flash'] = ['error' => 'Invalid form token.'];
        header('Location: admin.php?section=' . urlencode($section));
        exit;
    }

    $act = $_POST['action'] ?? '';
    $db  = db();
    $me  = current_user();

    if ($act === 'update_order_status') {
        $orderId   = $_POST['order_id'] ?? '';
        $newStatus = $_POST['new_status'] ?? '';
        $note      = trim($_POST['note'] ?? '');
        $allowed   = ['pending', 'paid', 'dispatched', 'arrived', 'cancelled'];

        if ($orderId !== '' && in_array($newStatus, $allowed, true)) {
            $s = $db->prepare("SELECT status FROM orders WHERE order_id = ?");
            $s->bind_param("s", $orderId);
            $s->execute();
            $row = $s->get_result()->fetch_assoc();
            $s->close();
            $oldStatus = $row['status'] ?? null;

            if ($oldStatus !== null && $oldStatus !== $newStatus) {
                $s = $db->prepare("UPDATE orders SET status = ? WHERE order_id = ?");
                $s->bind_param("ss", $newStatus, $orderId);
                $s->execute();
                $s->close();

                $uid = $me['id'];
                $s   = $db->prepare("INSERT INTO order_status_log (order_id, old_status, new_status, changed_by, note) VALUES (?, ?, ?, ?, ?)");
                $s->bind_param("sssis", $orderId, $oldStatus, $newStatus, $uid, $note);
                $s->execute();
                $s->close();

                // restore stock when an order is cancelled
                if ($newStatus === 'cancelled' && $oldStatus !== 'cancelled') {
                    $si  = $db->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
                    $si->bind_param("s", $orderId);
                    $si->execute();
                    $si_res = $si->get_result();
                    $su = $db->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                    while ($it = $si_res->fetch_assoc()) {
                        $q = (int)$it['quantity']; $p = (int)$it['product_id'];
                        $su->bind_param("ii", $q, $p);
                        $su->execute();
                    }
                    $si->close(); $su->close();
                }
                // deduct stock again if an order is moved back from cancelled
                if ($oldStatus === 'cancelled' && $newStatus !== 'cancelled') {
                    $si  = $db->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?");
                    $si->bind_param("s", $orderId);
                    $si->execute();
                    $si_res = $si->get_result();
                    $su = $db->prepare("UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id = ?");
                    while ($it = $si_res->fetch_assoc()) {
                        $q = (int)$it['quantity']; $p = (int)$it['product_id'];
                        $su->bind_param("ii", $q, $p);
                        $su->execute();
                    }
                    $si->close(); $su->close();
                }

                $_SESSION['admin_flash'] = ['success' => 'Order status updated to ' . $newStatus . '.'];

                if ($newStatus === 'arrived') {
                    $se = $db->prepare("
                        SELECT o.customer_email, o.customer_name, u.email AS user_email
                        FROM orders o
                        LEFT JOIN users u ON u.id = o.user_id
                        WHERE o.order_id = ?
                    ");
                    $se->bind_param("s", $orderId);
                    $se->execute();
                    $od = $se->get_result()->fetch_assoc();
                    $se->close();
                    if ($od) {
                        $triggerEmail = !empty($od['customer_email']) ? $od['customer_email'] : ($od['user_email'] ?? '');
                        if ($triggerEmail !== '') {
                            $protocol  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                            $host      = $_SERVER['HTTP_HOST'];
                            $dir       = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
                            $reviewUrl = $protocol . '://' . $host . $dir . '/review.php?order_id=' . urlencode($orderId);

                            $payload = json_encode([
                                'service_id'      => 'service_1jwevr7',
                                'template_id'     => 'template_pa86w3f',
                                'user_id'         => 'PNXDYon0cBekaw3H8',
                                'template_params' => [
                                    'to_email'      => $triggerEmail,
                                    'customer_name' => $od['customer_name'],
                                    'order_id'      => $orderId,
                                    'review_url'    => $reviewUrl,
                                ],
                            ]);

                            $ch = curl_init('https://api.emailjs.com/api/v1.0/email/send');
                            curl_setopt($ch, CURLOPT_POST, true);
                            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                'Content-Type: application/json',
                                'Origin: ' . $protocol . '://' . $host,
                            ]);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                            curl_exec($ch);
                            curl_close($ch);
                        }
                    }
                }
            }
        }
    }

    elseif ($act === 'update_stock') {
        $pid   = (int)($_POST['product_id'] ?? 0);
        $stock = max(0, (int)($_POST['stock'] ?? 0));
        if ($pid > 0) {
            $s = $db->prepare("UPDATE products SET stock = ? WHERE id = ?");
            $s->bind_param("ii", $stock, $pid);
            $s->execute();
            $s->close();
            $_SESSION['admin_flash'] = ['success' => 'Stock level updated.'];
        }
    }

    elseif ($act === 'update_price') {
        $pid   = (int)($_POST['product_id'] ?? 0);
        $price = round((float)($_POST['price'] ?? 0), 2);
        if ($pid > 0 && $price > 0) {
            $s = $db->prepare("UPDATE products SET price = ? WHERE id = ?");
            $s->bind_param("di", $price, $pid);
            $s->execute();
            $s->close();
            $_SESSION['admin_flash'] = ['success' => 'Price updated.'];
        }
    }

    elseif ($act === 'update_customer') {
        $uid   = (int)($_POST['user_id'] ?? 0);
        $name  = trim($_POST['name']  ?? '');
        $email = trim($_POST['email'] ?? '');
        if ($uid > 0 && $name !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $s = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
            $s->bind_param("si", $email, $uid);
            $s->execute();
            $clash = $s->get_result()->fetch_assoc();
            $s->close();
            if ($clash) {
                $_SESSION['admin_flash'] = ['error' => 'That email is already in use by another account.'];
            } else {
                $s = $db->prepare("UPDATE users SET name = ?, email = ? WHERE id = ? AND role = 'customer'");
                $s->bind_param("ssi", $name, $email, $uid);
                $s->execute();
                $s->close();
                $_SESSION['admin_flash'] = ['success' => 'Customer details updated.'];
            }
        }
    }

    $qs = 'section=' . urlencode($section);
    if ($section === 'reports') $qs .= '&report=' . urlencode($report);
    header('Location: admin.php?' . $qs);
    exit;
}

// load data for the current section
$db = db();

$pendingCount  = (int)$db->query("SELECT COUNT(*) c FROM orders WHERE status='pending'")->fetch_assoc()['c'];
$lowStockCount = (int)$db->query("SELECT COUNT(*) c FROM products WHERE stock < 10")->fetch_assoc()['c'];

// overview section data
$totalRevenue = $monthRevenue = $totalOrders = $newCustomers = 0;
$recentOrders = $lowStockItems = $chartLabels = $chartValues = [];

if ($section === 'overview') {
    $totalRevenue = (float)$db->query("SELECT COALESCE(SUM(total),0) v FROM orders WHERE status!='cancelled'")->fetch_assoc()['v'];
    $monthRevenue = (float)$db->query("SELECT COALESCE(SUM(total),0) v FROM orders WHERE status!='cancelled' AND YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())")->fetch_assoc()['v'];
    $totalOrders  = (int)$db->query("SELECT COUNT(*) v FROM orders")->fetch_assoc()['v'];
    $newCustomers = (int)$db->query("SELECT COUNT(*) v FROM users WHERE role='customer' AND YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())")->fetch_assoc()['v'];

    $rawByDate = [];
    $r = $db->query("SELECT DATE(created_at) d, COALESCE(SUM(total),0) rev FROM orders WHERE status!='cancelled' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(created_at) ORDER BY d");
    while ($row = $r->fetch_assoc()) $rawByDate[$row['d']] = (float)$row['rev'];
    for ($i = 29; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-{$i} days"));
        $chartLabels[] = date('j M', strtotime($d));
        $chartValues[] = $rawByDate[$d] ?? 0;
    }

    $r = $db->query("SELECT order_id, created_at, customer_name, customer_email, total, status FROM orders ORDER BY created_at DESC LIMIT 10");
    while ($row = $r->fetch_assoc()) $recentOrders[] = $row;

    $r = $db->query("SELECT id, name, category, version, stock FROM products WHERE stock < 10 ORDER BY stock ASC LIMIT 10");
    while ($row = $r->fetch_assoc()) $lowStockItems[] = $row;
}

// orders section data
$ordersData = $orderItems = [];
$filterStatus = $filterFrom = $filterTo = $filterSearch = '';

if ($section === 'orders') {
    $filterStatus = $_GET['status'] ?? '';
    $filterFrom   = $_GET['from']   ?? '';
    $filterTo     = $_GET['to']     ?? '';
    $filterSearch = trim($_GET['q'] ?? '');

    $where  = "WHERE 1=1";
    $params = [];
    $types  = "";
    $allowed = ['pending', 'paid', 'dispatched', 'arrived', 'cancelled'];

    if ($filterStatus !== '' && in_array($filterStatus, $allowed, true)) {
        $where .= " AND o.status = ?"; $params[] = $filterStatus; $types .= "s";
    }
    if ($filterFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) {
        $where .= " AND DATE(o.created_at) >= ?"; $params[] = $filterFrom; $types .= "s";
    }
    if ($filterTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) {
        $where .= " AND DATE(o.created_at) <= ?"; $params[] = $filterTo; $types .= "s";
    }
    if ($filterSearch !== '') {
        $like = "%$filterSearch%";
        $where .= " AND (o.customer_name LIKE ? OR o.customer_email LIKE ? OR o.order_id LIKE ?)";
        $params[] = $like; $params[] = $like; $params[] = $like; $types .= "sss";
    }

    $stmt = $db->prepare("SELECT o.order_id, o.created_at, o.customer_name, o.customer_email, o.city, o.country, o.total, o.status FROM orders o $where ORDER BY o.created_at DESC LIMIT 200");
    if ($types !== '') $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $r = $stmt->get_result();
    while ($row = $r->fetch_assoc()) $ordersData[] = $row;
    $stmt->close();

    foreach ($ordersData as $ord) {
        $oid  = $ord['order_id'];
        $s    = $db->prepare("SELECT oi.quantity, p.name, p.price FROM order_items oi JOIN products p ON p.id = oi.product_id WHERE oi.order_id = ?");
        $s->bind_param("s", $oid);
        $s->execute();
        $items = [];
        $res   = $s->get_result();
        while ($it = $res->fetch_assoc()) $items[] = $it;
        $s->close();
        $orderItems[$oid] = $items;
    }
}

// inventory section data
$inventory = [];
if ($section === 'inventory') {
    $r = $db->query("SELECT id, category, version, name, price, stock FROM products ORDER BY category, version DESC, name");
    while ($row = $r->fetch_assoc()) $inventory[] = $row;
}

// reports section data
$productRevenue = $timelineData = $unprocessed = [];
$tLabels = $tValues = $tOrders = [];
$fromDate = $toDate = '';

if ($section === 'reports') {
    if ($report === 'products') {
        $r = $db->query("
            SELECT p.id, p.name, p.category, p.version,
                   COALESCE(SUM(oi.quantity),0) units_sold,
                   COALESCE(SUM(oi.quantity * p.price),0) revenue
            FROM products p
            LEFT JOIN order_items oi ON oi.product_id = p.id
            LEFT JOIN orders o ON o.order_id = oi.order_id AND o.status != 'cancelled'
            GROUP BY p.id, p.name, p.category, p.version
            ORDER BY revenue DESC
        ");
        while ($row = $r->fetch_assoc()) $productRevenue[] = $row;
    }

    if ($report === 'timeline') {
        $fromDate = $_GET['from'] ?? date('Y-m-d', strtotime('-29 days'));
        $toDate   = $_GET['to']   ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) $fromDate = date('Y-m-d', strtotime('-29 days'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate))   $toDate   = date('Y-m-d');

        $stmt = $db->prepare("SELECT DATE(created_at) d, COALESCE(SUM(total),0) revenue, COUNT(*) orders FROM orders WHERE status!='cancelled' AND DATE(created_at) BETWEEN ? AND ? GROUP BY DATE(created_at) ORDER BY d");
        $stmt->bind_param("ss", $fromDate, $toDate);
        $stmt->execute();
        $rawByDate = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) $rawByDate[$row['d']] = $row;
        $stmt->close();

        $cur = new DateTime($fromDate);
        $end = new DateTime($toDate);
        while ($cur <= $end) {
            $d = $cur->format('Y-m-d');
            $tLabels[]      = $cur->format('j M');
            $tValues[]      = (float)($rawByDate[$d]['revenue'] ?? 0);
            $tOrders[]      = (int)($rawByDate[$d]['orders'] ?? 0);
            $timelineData[] = ['date' => $d, 'revenue' => $rawByDate[$d]['revenue'] ?? 0, 'orders' => $rawByDate[$d]['orders'] ?? 0];
            $cur->modify('+1 day');
        }
    }

    if ($report === 'unprocessed') {
        $r = $db->query("SELECT order_id, created_at, customer_name, customer_email, total FROM orders WHERE status='pending' ORDER BY created_at ASC");
        while ($row = $r->fetch_assoc()) $unprocessed[] = $row;
    }
}

// customers section data
$customers = $editCustomer = null;
$editId = 0;

if ($section === 'customers') {
    $editId = (int)($_GET['edit'] ?? 0);
    if ($editId > 0) {
        $s = $db->prepare("SELECT id, name, email, created_at FROM users WHERE id = ? AND role='customer' LIMIT 1");
        $s->bind_param("i", $editId);
        $s->execute();
        $editCustomer = $s->get_result()->fetch_assoc();
        $s->close();
    }
    $customers = [];
    $r = $db->query("
        SELECT u.id, u.name, u.email, u.created_at, u.last_login,
               COUNT(o.order_id) order_count,
               COALESCE(SUM(CASE WHEN o.status!='cancelled' THEN o.total ELSE 0 END),0) total_spend
        FROM users u
        LEFT JOIN orders o ON o.user_id = u.id
        WHERE u.role = 'customer'
        GROUP BY u.id, u.name, u.email, u.created_at, u.last_login
        ORDER BY total_spend DESC
    ");
    while ($row = $r->fetch_assoc()) $customers[] = $row;
}

// helper functions used in the HTML below
function statusBadge(string $s): string {
    $map = [
        'pending'    => ['Pending',    '#92400e','#fef3c7'],
        'paid'       => ['Paid',       '#065f46','#d1fae5'],
        'dispatched' => ['Dispatched', '#1e40af','#dbeafe'],
        'arrived'    => ['Arrived',    '#065f46','#d1fae5'],
        'cancelled'  => ['Cancelled',  '#991b1b','#fee2e2'],
    ];
    [$lbl, $fg, $bg] = $map[$s] ?? [$s, '#374151', '#f3f4f6'];
    return '<span class="badge" style="background:' . $bg . ';color:' . $fg . '">' . htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8') . '</span>';
}

function stockLevel(int $s): string {
    if ($s <= 0)  return '<span style="color:#b91c1c;font-weight:700">OUT</span>';
    if ($s < 10)  return '<span style="color:#b45309;font-weight:700">' . $s . '</span>';
    if ($s < 25)  return '<span style="color:#b45309">' . $s . '</span>';
    return '<span style="color:#166534">' . $s . '</span>';
}

$navLinks = [
    'overview'  => 'Overview',
    'orders'    => 'Orders',
    'inventory' => 'Inventory',
    'reports'   => 'Reports',
    'customers' => 'Customers',
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Admin | Pear Store</title>
  <link rel="stylesheet" href="css/admin.css">
</head>
<body>

<div class="mob-bar">
  <button id="menuToggle" aria-label="Toggle menu">&#9776;</button>
  <span>Pear Admin</span>
</div>

<div class="admin-wrap">

  <aside class="sidebar" id="sidebar">
    <div class="sb-brand">
      <div class="sb-brand__dot">P</div>
      <div>
        <div class="sb-brand__name">Pear Store</div>
        <div class="sb-brand__sub">Admin Panel</div>
      </div>
    </div>

    <nav class="sb-nav">
      <div class="sb-section">Navigation</div>
      <?php foreach ($navLinks as $key => $label): ?>
        <a href="admin.php?section=<?= urlencode($key) ?>" class="<?= $section === $key ? 'active' : '' ?>">
          <?= e($label) ?>
          <?php if ($key === 'orders' && $pendingCount > 0): ?>
            <span class="sb-badge"><?= $pendingCount ?></span>
          <?php endif; ?>
          <?php if ($key === 'inventory' && $lowStockCount > 0): ?>
            <span class="sb-badge sb-badge--warn"><?= $lowStockCount ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="sb-footer">
      <div class="sb-footer__name">
        <strong><?= e(current_user()['name']) ?></strong>
        Administrator
      </div>
      <a href="home.php">Back to Store</a>
      <form method="post" action="logout.php">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <button type="submit" class="sb-signout">Sign Out</button>
      </form>
    </div>
  </aside>

  <main class="main">

    <?php if ($flash): ?>
      <div class="flash flash--<?= isset($flash['error']) ? 'error' : 'success' ?>">
        <?= e($flash['error'] ?? $flash['success'] ?? '') ?>
      </div>
    <?php endif; ?>

    <?php if ($section === 'overview'): ?>

      <div class="page-hd">
        <h1>Dashboard Overview</h1>
        <p>Pear Store at a glance &mdash; <?= date('l, j F Y') ?></p>
      </div>

      <div class="kpi-grid">
        <div class="kpi kpi--good">
          <div class="kpi__label">Total Revenue</div>
          <div class="kpi__value">£<?= number_format($totalRevenue, 0) ?></div>
          <div class="kpi__sub">All time, excl. cancelled</div>
        </div>
        <div class="kpi">
          <div class="kpi__label">This Month</div>
          <div class="kpi__value">£<?= number_format($monthRevenue, 0) ?></div>
          <div class="kpi__sub"><?= date('F Y') ?></div>
        </div>
        <div class="kpi <?= $pendingCount > 0 ? 'kpi--alert' : '' ?>">
          <div class="kpi__label">Pending Orders</div>
          <div class="kpi__value"><?= $pendingCount ?></div>
          <div class="kpi__sub">Awaiting action</div>
        </div>
        <div class="kpi">
          <div class="kpi__label">Total Orders</div>
          <div class="kpi__value"><?= number_format($totalOrders) ?></div>
          <div class="kpi__sub">All time</div>
        </div>
        <div class="kpi <?= $lowStockCount > 0 ? 'kpi--danger' : '' ?>">
          <div class="kpi__label">Low Stock</div>
          <div class="kpi__value"><?= $lowStockCount ?></div>
          <div class="kpi__sub">Products below 10 units</div>
        </div>
        <div class="kpi">
          <div class="kpi__label">New Customers</div>
          <div class="kpi__value"><?= $newCustomers ?></div>
          <div class="kpi__sub">This month</div>
        </div>
      </div>

      <div class="card">
        <div class="card__head">
          <h2>Revenue — Last 30 Days</h2>
        </div>
        <div class="chart-wrap">
          <canvas id="revenueChart"></canvas>
        </div>
      </div>

      <div class="two-col">

        <div class="card">
          <div class="card__head">
            <h2>Recent Orders</h2>
            <a href="admin.php?section=orders" class="btn btn-secondary btn-sm">View all</a>
          </div>
          <?php if (empty($recentOrders)): ?>
            <div class="empty">No orders yet.</div>
          <?php else: ?>
            <table class="tbl tbl--compact">
              <thead><tr>
                <th>Customer</th><th>Total</th><th>Status</th><th>Date</th>
              </tr></thead>
              <tbody>
                <?php foreach ($recentOrders as $o): ?>
                  <tr>
                    <td><?= e($o['customer_name']) ?></td>
                    <td><strong>£<?= number_format((float)$o['total'],2) ?></strong></td>
                    <td><?= statusBadge($o['status']) ?></td>
                    <td style="color:#6b7280"><?= e(date('j M', strtotime($o['created_at']))) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

        <div class="card">
          <div class="card__head">
            <h2>Low Stock Alerts</h2>
            <a href="admin.php?section=inventory" class="btn btn-secondary btn-sm">Manage stock</a>
          </div>
          <?php if (empty($lowStockItems)): ?>
            <div class="empty">All products adequately stocked.</div>
          <?php else: ?>
            <table class="tbl tbl--compact">
              <thead><tr><th>Product</th><th>Category</th><th>Stock</th></tr></thead>
              <tbody>
                <?php foreach ($lowStockItems as $p): ?>
                  <tr>
                    <td><?= e($p['name']) ?></td>
                    <td style="color:#6b7280"><?= e($p['category']) ?></td>
                    <td><?= stockLevel((int)$p['stock']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

      </div>

    <?php elseif ($section === 'orders'): ?>

      <div class="page-hd">
        <h1>Orders</h1>
        <p>Manage and update all customer orders.</p>
      </div>

      <div class="card">
        <form method="get" action="admin.php" class="filter-bar">
          <input type="hidden" name="section" value="orders">
          <div>
            <label>Status</label>
            <select name="status" class="inline-select" style="height:36px;">
              <option value="">All statuses</option>
              <option value="pending"    <?= $filterStatus==='pending'?'selected':''?>>Pending</option>
              <option value="paid"       <?= $filterStatus==='paid'?'selected':''?>>Paid</option>
              <option value="dispatched" <?= $filterStatus==='dispatched'?'selected':''?>>Dispatched</option>
              <option value="arrived"    <?= $filterStatus==='arrived'?'selected':''?>>Arrived</option>
              <option value="cancelled"  <?= $filterStatus==='cancelled'?'selected':''?>>Cancelled</option>
            </select>
          </div>
          <div>
            <label>From</label>
            <input type="date" name="from" value="<?= e($filterFrom) ?>">
          </div>
          <div>
            <label>To</label>
            <input type="date" name="to" value="<?= e($filterTo) ?>">
          </div>
          <div>
            <label>Search</label>
            <input type="text" name="q" value="<?= e($filterSearch) ?>" placeholder="Name, email, order ID" style="width:200px;">
          </div>
          <div style="padding-bottom:0">
            <button type="submit" class="btn btn-primary">Filter</button>
            <a href="admin.php?section=orders" class="btn btn-secondary" style="margin-left:6px;">Clear</a>
          </div>
        </form>

        <?php if (empty($ordersData)): ?>
          <div class="empty">No orders match the selected filters.</div>
        <?php else: ?>
          <table class="tbl">
            <thead><tr>
              <th>Order ID</th>
              <th>Date</th>
              <th>Customer</th>
              <th>Location</th>
              <th>Total</th>
              <th>Status</th>
              <th>Items</th>
              <th>Update Status</th>
            </tr></thead>
            <tbody>
              <?php foreach ($ordersData as $o):
                $oid   = $o['order_id'];
                $items = $orderItems[$oid] ?? [];
              ?>
                <tr>
                  <td style="font-family:monospace;font-size:11px;color:#374151"><?= e(substr($oid,0,12)) ?>...</td>
                  <td style="color:#6b7280;white-space:nowrap"><?= e(date('j M Y', strtotime($o['created_at']))) ?></td>
                  <td>
                    <div style="font-weight:600"><?= e($o['customer_name']) ?></div>
                    <div style="font-size:11px;color:#9ca3af"><?= e($o['customer_email']) ?></div>
                  </td>
                  <td style="color:#6b7280;font-size:12px"><?= e($o['city']) ?>, <?= e($o['country']) ?></td>
                  <td><strong>£<?= number_format((float)$o['total'],2) ?></strong></td>
                  <td><?= statusBadge($o['status']) ?></td>
                  <td>
                    <details>
                      <summary><?= count($items) ?> item<?= count($items)!==1?'s':'' ?></summary>
                      <div class="order-items-inner">
                        <table>
                          <?php foreach ($items as $it): ?>
                            <tr>
                              <td><?= e($it['name']) ?></td>
                              <td style="padding:3px 12px">x<?= (int)$it['quantity'] ?></td>
                              <td>£<?= number_format((float)$it['price']*(int)$it['quantity'],2) ?></td>
                            </tr>
                          <?php endforeach; ?>
                        </table>
                      </div>
                    </details>
                  </td>
                  <td>
                    <form method="post" action="admin.php?section=orders" class="inline-form">
                      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="update_order_status">
                      <input type="hidden" name="order_id" value="<?= e($oid) ?>">
                      <select name="new_status" class="inline-select">
                        <?php foreach (['pending','paid','dispatched','arrived','cancelled'] as $st): ?>
                          <option value="<?= $st ?>" <?= $o['status']===$st?'selected':'' ?>><?= ucfirst($st) ?></option>
                        <?php endforeach; ?>
                      </select>
                      <button type="submit" class="btn btn-primary btn-sm">Save</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

    <?php elseif ($section === 'inventory'): ?>

      <div class="page-hd">
        <h1>Inventory Management</h1>
        <p>Monitor stock levels and update prices. Items below 10 units are flagged.</p>
      </div>

      <div class="card">
        <div class="card__head">
          <h2>Products (<?= count($inventory) ?>)</h2>
          <div style="font-size:12px;color:#6b7280">
            <span style="color:#b91c1c;font-weight:700">Red</span> = 0 &nbsp;
            <span style="color:#b45309;font-weight:700">Amber</span> = 1&ndash;24 &nbsp;
            <span style="color:#166534">Green</span> = 25+
          </div>
        </div>
        <?php if (empty($inventory)): ?>
          <div class="empty">No products found.</div>
        <?php else: ?>
          <table class="tbl">
            <thead><tr>
              <th>Product</th>
              <th>Category</th>
              <th>Ver.</th>
              <th>Price</th>
              <th>Stock</th>
              <th style="width:240px">Update Stock</th>
              <th style="width:220px">Update Price</th>
            </tr></thead>
            <tbody>
              <?php foreach ($inventory as $p): ?>
                <tr>
                  <td><strong><?= e($p['name']) ?></strong></td>
                  <td style="color:#6b7280"><?= e(ucfirst($p['category'])) ?></td>
                  <td style="color:#6b7280">v<?= (int)$p['version'] ?></td>
                  <td><strong>£<?= number_format((float)$p['price'],2) ?></strong></td>
                  <td><?= stockLevel((int)$p['stock']) ?></td>
                  <td>
                    <form method="post" action="admin.php?section=inventory" class="inline-form">
                      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="update_stock">
                      <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                      <input type="number" name="stock" value="<?= (int)$p['stock'] ?>" min="0" style="width:72px">
                      <button type="submit" class="btn btn-primary btn-sm">Save</button>
                    </form>
                  </td>
                  <td>
                    <form method="post" action="admin.php?section=inventory" class="inline-form">
                      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                      <input type="hidden" name="action" value="update_price">
                      <input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
                      <input type="number" name="price" value="<?= e(number_format((float)$p['price'],2,'.','')) ?>" min="0.01" step="0.01" style="width:80px">
                      <button type="submit" class="btn btn-secondary btn-sm">Save</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

    <?php elseif ($section === 'reports'): ?>

      <div class="page-hd">
        <h1>Reports</h1>
        <p>Revenue analytics, product performance, and order tracking.</p>
      </div>

      <div class="card" style="margin-bottom:0;border-bottom:none;border-radius:12px 12px 0 0;">
        <div class="report-tabs" style="padding:0 20px;">
          <a href="admin.php?section=reports&report=products"   class="report-tab <?= $report==='products'?'active':'' ?>">Revenue by Product</a>
          <a href="admin.php?section=reports&report=timeline"   class="report-tab <?= $report==='timeline'?'active':'' ?>">Revenue Over Time</a>
          <a href="admin.php?section=reports&report=unprocessed" class="report-tab <?= $report==='unprocessed'?'active':'' ?>">Unprocessed Orders<?php if($pendingCount>0): ?> <span class="sb-badge" style="margin-left:4px"><?= $pendingCount ?></span><?php endif; ?></a>
        </div>
      </div>

      <?php if ($report === 'products'): ?>

        <div class="card" style="border-radius:0 0 12px 12px;border-top:none;">
          <div class="card__head">
            <h2>Revenue by Product</h2>
            <span style="font-size:12px;color:#6b7280">Excludes cancelled orders</span>
          </div>

          <?php if (!empty($productRevenue)): ?>
            <div class="chart-wrap">
              <canvas id="productChart"></canvas>
            </div>
          <?php endif; ?>

          <?php if (empty($productRevenue)): ?>
            <div class="empty">No sales data available.</div>
          <?php else: ?>
            <table class="tbl">
              <thead><tr>
                <th>#</th><th>Product</th><th>Category</th><th>Unit Price</th><th>Units Sold</th><th>Revenue</th>
              </tr></thead>
              <tbody>
                <?php foreach ($productRevenue as $i => $p): ?>
                  <tr>
                    <td style="color:#9ca3af"><?= $i+1 ?></td>
                    <td><strong><?= e($p['name']) ?></strong></td>
                    <td style="color:#6b7280"><?= e(ucfirst($p['category'])) ?> v<?= (int)$p['version'] ?></td>
                    <td>£<?= number_format((float)$p['price'] ?? 0,2) ?></td>
                    <td><?= number_format((int)$p['units_sold']) ?></td>
                    <td><strong>£<?= number_format((float)$p['revenue'],2) ?></strong></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

      <?php elseif ($report === 'timeline'): ?>

        <div class="card" style="border-radius:0 0 12px 12px;border-top:none;">
          <form method="get" action="admin.php" class="filter-bar" style="border-bottom:1px solid #f3f4f6;">
            <input type="hidden" name="section" value="reports">
            <input type="hidden" name="report" value="timeline">
            <div>
              <label>From</label>
              <input type="date" name="from" value="<?= e($fromDate) ?>">
            </div>
            <div>
              <label>To</label>
              <input type="date" name="to" value="<?= e($toDate) ?>">
            </div>
            <div style="padding-bottom:0">
              <button type="submit" class="btn btn-primary">Apply</button>
            </div>
          </form>

          <?php if (!empty($tValues)): ?>
            <div class="chart-wrap" style="height:240px;">
              <canvas id="timelineChart"></canvas>
            </div>
          <?php endif; ?>

          <?php
            $tlTotal   = array_sum($tValues);
            $tlOrders  = array_sum($tOrders);
          ?>
          <div style="display:flex;gap:20px;padding:16px 20px;border-top:1px solid #f3f4f6;background:#fafafa;">
            <div><span style="font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;font-weight:600">Total Revenue</span><br><strong style="font-size:18px">£<?= number_format($tlTotal,2) ?></strong></div>
            <div><span style="font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;font-weight:600">Total Orders</span><br><strong style="font-size:18px"><?= number_format($tlOrders) ?></strong></div>
            <div><span style="font-size:12px;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;font-weight:600">Avg per Order</span><br><strong style="font-size:18px">£<?= $tlOrders > 0 ? number_format($tlTotal/$tlOrders,2) : '0.00' ?></strong></div>
          </div>

          <?php if (empty($timelineData)): ?>
            <div class="empty">No revenue data for this period.</div>
          <?php else: ?>
            <table class="tbl tbl--compact">
              <thead><tr><th>Date</th><th>Orders</th><th>Revenue</th></tr></thead>
              <tbody>
                <?php foreach (array_reverse($timelineData) as $row): ?>
                  <tr>
                    <td><?= e(date('j M Y', strtotime($row['date']))) ?></td>
                    <td><?= (int)$row['orders'] ?></td>
                    <td><strong>£<?= number_format((float)$row['revenue'],2) ?></strong></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

      <?php elseif ($report === 'unprocessed'): ?>

        <div class="card" style="border-radius:0 0 12px 12px;border-top:none;">
          <div class="card__head">
            <h2>Unprocessed Orders (<?= count($unprocessed) ?>)</h2>
            <span style="font-size:12px;color:#6b7280">Orders with status: Pending</span>
          </div>
          <?php if (empty($unprocessed)): ?>
            <div class="empty">No unprocessed orders. All orders have been actioned.</div>
          <?php else: ?>
            <table class="tbl">
              <thead><tr>
                <th>Order ID</th><th>Date</th><th>Customer</th><th>Email</th><th>Total</th><th>Action</th>
              </tr></thead>
              <tbody>
                <?php foreach ($unprocessed as $o): ?>
                  <tr>
                    <td style="font-family:monospace;font-size:11px"><?= e(substr($o['order_id'],0,12)) ?>...</td>
                    <td><?= e(date('j M Y H:i', strtotime($o['created_at']))) ?></td>
                    <td><?= e($o['customer_name']) ?></td>
                    <td style="color:#6b7280"><?= e($o['customer_email']) ?></td>
                    <td><strong>£<?= number_format((float)$o['total'],2) ?></strong></td>
                    <td>
                      <form method="post" action="admin.php?section=reports&report=unprocessed" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="action" value="update_order_status">
                        <input type="hidden" name="order_id" value="<?= e($o['order_id']) ?>">
                        <input type="hidden" name="new_status" value="paid">
                        <button type="submit" class="btn btn-primary btn-sm">Mark Paid</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </div>

      <?php endif; // report ?>

    <?php elseif ($section === 'customers'): ?>

      <div class="page-hd">
        <h1>Customers</h1>
        <p>Registered customer accounts and their order history.</p>
      </div>

      <?php if ($editCustomer): ?>
        <div class="edit-panel">
          <h3>Editing: <?= e($editCustomer['name']) ?></h3>
          <form method="post" action="admin.php?section=customers">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="update_customer">
            <input type="hidden" name="user_id" value="<?= (int)$editCustomer['id'] ?>">
            <div style="display:flex;gap:14px;flex-wrap:wrap;">
              <div class="field" style="flex:1;min-width:200px;">
                <label>Full Name</label>
                <input type="text" name="name" value="<?= e($editCustomer['name']) ?>" required>
              </div>
              <div class="field" style="flex:1;min-width:200px;">
                <label>Email Address</label>
                <input type="email" name="email" value="<?= e($editCustomer['email']) ?>" required>
              </div>
            </div>
            <div style="display:flex;gap:10px;margin-top:4px;">
              <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
              <a href="admin.php?section=customers" class="btn btn-secondary btn-sm">Cancel</a>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <div class="card">
        <div class="card__head">
          <h2>Registered Customers (<?= count($customers ?? []) ?>)</h2>
        </div>
        <?php if (empty($customers)): ?>
          <div class="empty">No registered customers yet.</div>
        <?php else: ?>
          <table class="tbl">
            <thead><tr>
              <th>Name</th>
              <th>Email</th>
              <th>Registered</th>
              <th>Last Login</th>
              <th>Orders</th>
              <th>Total Spend</th>
              <th></th>
            </tr></thead>
            <tbody>
              <?php foreach ($customers as $c): ?>
                <tr>
                  <td><strong><?= e($c['name']) ?></strong></td>
                  <td style="color:#6b7280"><?= e($c['email']) ?></td>
                  <td style="color:#6b7280;white-space:nowrap"><?= e(date('j M Y', strtotime($c['created_at']))) ?></td>
                  <td style="color:#9ca3af;white-space:nowrap">
                    <?= $c['last_login'] ? e(date('j M Y', strtotime($c['last_login']))) : 'Never' ?>
                  </td>
                  <td><?= (int)$c['order_count'] ?></td>
                  <td><strong>£<?= number_format((float)$c['total_spend'],2) ?></strong></td>
                  <td>
                    <a href="admin.php?section=customers&edit=<?= (int)$c['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

    <?php endif; // section ?>

  </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.getElementById('menuToggle')?.addEventListener('click', function() {
    document.getElementById('sidebar').classList.toggle('open');
});

<?php if ($section === 'overview'): ?>
(function() {
    const labels = <?= json_encode($chartLabels) ?>;
    const values = <?= json_encode($chartValues) ?>;
    new Chart(document.getElementById('revenueChart'), {
        type: 'line',
        data: {
            labels,
            datasets: [{
                data: values,
                borderColor: '#0071e3',
                backgroundColor: 'rgba(0,113,227,.08)',
                fill: true,
                tension: 0.35,
                pointRadius: 2,
                pointHoverRadius: 5,
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => ' £' + ctx.parsed.y.toFixed(2) } }
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 11 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } },
                y: { beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { font: { size: 11 }, callback: v => '£' + v.toFixed(0) } }
            }
        }
    });
})();
<?php endif; ?>

<?php if ($section === 'reports' && $report === 'products' && !empty($productRevenue)): ?>
(function() {
    const top = <?= json_encode(array_slice($productRevenue, 0, 12)) ?>;
    new Chart(document.getElementById('productChart'), {
        type: 'bar',
        data: {
            labels: top.map(p => p.name),
            datasets: [{
                label: 'Revenue',
                data: top.map(p => parseFloat(p.revenue)),
                backgroundColor: '#0071e3',
                borderRadius: 6,
                maxBarThickness: 40
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => ' £' + ctx.parsed.y.toFixed(2) } }
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 11 }, maxRotation: 30 } },
                y: { beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { font: { size: 11 }, callback: v => '£' + v.toFixed(0) } }
            }
        }
    });
})();
<?php endif; ?>

<?php if ($section === 'reports' && $report === 'timeline' && !empty($tValues)): ?>
(function() {
    const labels = <?= json_encode($tLabels) ?>;
    const values = <?= json_encode($tValues) ?>;
    new Chart(document.getElementById('timelineChart'), {
        type: 'line',
        data: {
            labels,
            datasets: [{
                label: 'Revenue',
                data: values,
                borderColor: '#0071e3',
                backgroundColor: 'rgba(0,113,227,.08)',
                fill: true,
                tension: 0.3,
                pointRadius: 2,
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => ' £' + ctx.parsed.y.toFixed(2) } }
            },
            scales: {
                x: { grid: { display: false }, ticks: { font: { size: 11 }, maxRotation: 30, autoSkip: true, maxTicksLimit: 14 } },
                y: { beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { font: { size: 11 }, callback: v => '£' + v.toFixed(0) } }
            }
        }
    });
})();
<?php endif; ?>
</script>


</body>
</html>
