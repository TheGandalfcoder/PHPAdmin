<?php
require_once 'db.php';
require_once 'auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

function redirectTo(string $url): void {
    header('Location: ' . $url);
    exit;
}

function safeReturnTo(string $fallback = 'main.php'): string {
    $to      = $_POST['return_to'] ?? $fallback;
    $allowed = ['main.php', 'cart.php', 'home.php', 'product.php', 'category.php', 'account.php'];
    $base    = strtok($to, '?');
    return in_array($base, $allowed, true) ? $to : $fallback;
}

$returnTo = safeReturnTo('main.php');

if (!csrf_verify()) {
    $_SESSION['flash'] = ['errors' => ['Invalid CSRF token. Please try again.']];
    redirectTo($returnTo);
}

$action = $_POST['action'] ?? '';
if ($action === '') {
    $_SESSION['flash'] = ['errors' => ['No action specified.']];
    redirectTo($returnTo);
}

if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}
$cart =& $_SESSION['cart'];

switch ($action) {

    case 'add': {
        $id = $_POST['id'] ?? '';
        if ($id !== '' && ctype_digit((string)$id)) {
            $db   = db();
            $s    = $db->prepare("SELECT stock FROM products WHERE id = ? LIMIT 1");
            $s->bind_param("i", $id);
            $s->execute();
            $row  = $s->get_result()->fetch_assoc();
            $s->close();
            if (!$row || (int)$row['stock'] <= 0) {
                $_SESSION['flash'] = ['errors' => ['That item is out of stock.']];
            } else {
                $cart[$id] = ($cart[$id] ?? 0) + 1;
                $_SESSION['flash'] = ['success' => 'Item added to cart.'];
            }
        } else {
            $_SESSION['flash'] = ['errors' => ['Invalid product.']];
        }
        redirectTo($returnTo);
    }

    case 'inc': {
        $id = $_POST['id'] ?? '';
        if ($id !== '' && ctype_digit((string)$id)) {
            $cart[$id] = ($cart[$id] ?? 0) + 1;
        }
        redirectTo($returnTo);
    }

    case 'dec': {
        $id = $_POST['id'] ?? '';
        if ($id !== '' && isset($cart[$id])) {
            $cart[$id] = ((int)$cart[$id]) - 1;
            if ($cart[$id] <= 0) unset($cart[$id]);
        }
        redirectTo($returnTo);
    }

    case 'remove': {
        $id = $_POST['id'] ?? '';
        if ($id !== '' && isset($cart[$id])) {
            unset($cart[$id]);
        }
        redirectTo($returnTo);
    }

    case 'clear': {
        $_SESSION['cart'] = [];
        $_SESSION['flash'] = ['success' => 'Cart cleared.'];
        redirectTo($returnTo);
    }

    case 'checkout': {
        $cartData = $_SESSION['cart'] ?? [];
        if (empty($cartData)) {
            $_SESSION['flash'] = ['errors' => ['Your cart is empty.']];
            redirectTo($returnTo);
        }

        $name  = trim($_POST['name']  ?? '');
        $email = trim($_POST['email'] ?? '');

        if ($name === '') {
            $_SESSION['flash'] = ['errors' => ['Name is required.']];
            redirectTo($returnTo);
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash'] = ['errors' => ['Invalid email address.']];
            redirectTo($returnTo);
        }

        $addressLine1 = trim($_POST['address_line1'] ?? '');
        $addressLine2 = trim($_POST['address_line2'] ?? '');
        $city         = trim($_POST['city']          ?? '');
        $postcode     = trim($_POST['postcode']      ?? '');
        $country      = trim($_POST['country']       ?? '');

        if ($addressLine1 === '' || $city === '' || $postcode === '' || $country === '') {
            $_SESSION['flash'] = ['errors' => ['Delivery address is required.']];
            redirectTo($returnTo);
        }

        $items = [];
        foreach ($cartData as $pid => $qty) {
            $q   = (int)$qty;
            $pid = (string)$pid;
            if ($q <= 0 || $pid === '' || !ctype_digit($pid)) continue;
            $items[] = ['id' => $pid, 'quantity' => $q];
        }

        if (empty($items)) {
            $_SESSION['flash'] = ['errors' => ['No valid items in cart.']];
            redirectTo($returnTo);
        }

        $db = db();

        $ids          = array_map(fn($it) => (int)$it['id'], $items);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types        = str_repeat('i', count($ids));

        $stmt = $db->prepare("SELECT id, price, stock FROM products WHERE id IN ($placeholders)");
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $res      = $stmt->get_result();
        $priceMap = [];
        $stockMap = [];
        while ($row = $res->fetch_assoc()) {
            $priceMap[(string)$row['id']] = (float)$row['price'];
            $stockMap[(string)$row['id']] = (int)$row['stock'];
        }
        $stmt->close();

        // block the order if any item has no stock left
        foreach ($items as $it) {
            if (($stockMap[$it['id']] ?? 0) <= 0) {
                $_SESSION['flash'] = ['errors' => ['One or more items in your cart are out of stock. Remove them before checking out.']];
                redirectTo($returnTo);
            }
        }

        $total = 0.0;
        foreach ($items as $it) {
            if (isset($priceMap[$it['id']])) {
                $total += $priceMap[$it['id']] * (int)$it['quantity'];
            }
        }

        $orderId      = bin2hex(random_bytes(16));
        $createdAt    = date('Y-m-d H:i:s');
        $ip           = $_SERVER['REMOTE_ADDR'] ?? null;
        $loggedInUser = current_user();
        $userId       = $loggedInUser ? (int)$loggedInUser['id'] : null;
        $totalRounded = round($total, 2);

        // Use account email if none given at checkout
        if ($email === '' && $loggedInUser) {
            $email = $loggedInUser['email'];
        }

        // wrap the order insert, item inserts, and stock deductions in one transaction
        // so a failure at any step rolls everything back cleanly
        try {
            $db->begin_transaction();

            if ($userId !== null) {
                $stmt = $db->prepare("INSERT INTO orders (order_id, user_id, created_at, ip_address, customer_name, customer_email, address_line1, address_line2, city, postcode, country, total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$stmt) throw new Exception($db->error);
                $stmt->bind_param("sisssssssssd", $orderId, $userId, $createdAt, $ip, $name, $email, $addressLine1, $addressLine2, $city, $postcode, $country, $totalRounded);
            } else {
                $stmt = $db->prepare("INSERT INTO orders (order_id, created_at, ip_address, customer_name, customer_email, address_line1, address_line2, city, postcode, country, total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                if (!$stmt) throw new Exception($db->error);
                $stmt->bind_param("ssssssssssd", $orderId, $createdAt, $ip, $name, $email, $addressLine1, $addressLine2, $city, $postcode, $country, $totalRounded);
            }
            if (!$stmt->execute()) throw new Exception($stmt->error);
            $stmt->close();

            $itemStmt = $db->prepare("INSERT INTO order_items (order_id, product_id, quantity) VALUES (?, ?, ?)");
            if (!$itemStmt) throw new Exception($db->error);
            foreach ($items as $it) {
                $pid = (int)$it['id'];
                $qty = (int)$it['quantity'];
                $itemStmt->bind_param("sii", $orderId, $pid, $qty);
                if (!$itemStmt->execute()) throw new Exception($itemStmt->error);
            }
            $itemStmt->close();

            $stockStmt = $db->prepare("UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id = ?");
            if (!$stockStmt) throw new Exception($db->error);
            foreach ($items as $it) {
                $qty = (int)$it['quantity'];
                $pid = (int)$it['id'];
                $stockStmt->bind_param("ii", $qty, $pid);
                if (!$stockStmt->execute()) throw new Exception($stockStmt->error);
            }
            $stockStmt->close();

            $db->commit();
        } catch (Throwable $ex) {
            $db->rollback();
            $_SESSION['flash'] = ['errors' => ['Failed to place order. Please try again.']];
            redirectTo($returnTo);
        }

        $_SESSION['cart'] = [];
        redirectTo('order_success.php?order_id=' . urlencode($orderId));
    }

    default: {
        $_SESSION['flash'] = ['errors' => ['Unknown action.']];
        redirectTo($returnTo);
    }
}
