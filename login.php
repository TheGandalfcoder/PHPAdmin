<?php
require_once 'db.php';
require_once 'auth.php';

if (is_logged_in()) {
    header('Location: ' . (is_admin() ? 'admin.php' : 'account.php'));
    exit;
}

$error = '';
$next  = $_GET['next'] ?? $_POST['next'] ?? '';
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
  <style>
    *{box-sizing:border-box;}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;margin:0;background:#f5f5f7;color:#1d1d1f;}
    .auth-page{min-height:calc(100vh - 52px);display:flex;align-items:center;justify-content:center;padding:40px 16px;}
    .auth-card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:40px;width:100%;max-width:400px;box-shadow:0 4px 24px rgba(0,0,0,.06);}
    .auth-card h1{font-size:24px;font-weight:700;margin:0 0 6px;letter-spacing:-.02em;}
    .auth-card p{color:#6b7280;margin:0 0 28px;font-size:14px;}
    .field{margin-bottom:16px;}
    .field label{display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:#374151;}
    .field input{width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;font-family:inherit;transition:border-color .15s,box-shadow .15s;}
    .field input:focus{outline:none;border-color:#0071e3;box-shadow:0 0 0 3px rgba(0,113,227,.12);}
    .btn-primary{width:100%;padding:12px;background:#0071e3;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;transition:background .15s;margin-top:8px;}
    .btn-primary:hover{background:#005bb5;}
    .auth-error{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:12px;border-radius:8px;font-size:14px;margin-bottom:20px;}
    .auth-footer{text-align:center;margin-top:24px;font-size:14px;color:#6b7280;}
    .auth-footer a{color:#0071e3;text-decoration:none;font-weight:500;}
    .auth-footer a:hover{text-decoration:underline;}
    .divider{display:flex;align-items:center;gap:10px;margin:20px 0;color:#9ca3af;font-size:13px;}
    .divider::before,.divider::after{content:'';flex:1;height:1px;background:#e5e7eb;}
  </style>
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
