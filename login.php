<?php
require_once 'db.php';
require_once 'auth.php';

if (is_logged_in()) {
    header('Location: ' . (is_admin() ? 'admin.php' : 'account.php'));
    exit;
}

$error = '';
$next  = $_GET['next'] ?? $_POST['next'] ?? '';
// only redirect to pages we own after login — prevents open redirect attacks
$allowedNext = ['home.php', 'main.php', 'cart.php', 'account.php', 'admin.php', 'review.php'];
if (!in_array(strtok($next, '?'), $allowedNext, true)) {
    $next = 'home.php';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $error = 'Invalid form submission. Please try again.';
    } else {
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            $error = 'Email and password are required.';
        } else {
            $db   = db();
            $stmt = $db->prepare("SELECT id, name, email, password_hash, role FROM users WHERE email = ? LIMIT 1");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user && password_verify($password, $user['password_hash'])) {
                login_set_user($user);
                $stmt = $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                $stmt->bind_param("i", $user['id']);
                $stmt->execute();
                $stmt->close();
                header('Location: ' . ($user['role'] === 'admin' ? 'admin.php' : ($next ?: 'account.php')));
                exit;
            } else {
                $error = 'Invalid email address or password.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Sign In | Pear Store</title>
  <link rel="stylesheet" href="css/nav.css">
  <link rel="stylesheet" href="css/auth.css">
</head>
<body>
<?php include '_nav.php'; ?>
<div class="auth-page">
  <div class="auth-card">
    <h1>Sign In</h1>
    <p>Welcome back to Pear Store.</p>

    <?php if ($error !== ''): ?>
      <div class="auth-error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" novalidate>
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="next" value="<?= e($next) ?>">

      <div class="field">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" value="<?= e($_POST['email'] ?? '') ?>"
               autocomplete="email" required>
      </div>

      <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" autocomplete="current-password" required>
      </div>

      <button type="submit" class="btn-primary">Sign In</button>
    </form>

    <div class="auth-footer">
      Don't have an account? <a href="register.php">Create one</a>
    </div>
  </div>
</div>
</body>
</html>
