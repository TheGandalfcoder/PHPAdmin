<?php
require_once 'db.php';
require_once 'auth.php';

if (is_logged_in()) {
    header('Location: account.php');
    exit;
}

$errors = [];
$values = ['name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Invalid form submission. Please try again.';
    } else {
        $name     = trim($_POST['name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $pass     = $_POST['password'] ?? '';
        $confirm  = $_POST['confirm'] ?? '';
        $values   = ['name' => $name, 'email' => $email];

        if ($name === '') $errors[] = 'Full name is required.';
        if ($email === '') {
            $errors[] = 'Email address is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Please enter a valid email address.';
        }
        if (strlen($pass) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if ($pass !== $confirm) {
            $errors[] = 'Passwords do not match.';
        }

        if (empty($errors)) {
            $db   = db();
            $stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($exists) {
                $errors[] = 'An account with that email address already exists.';
            } else {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                $stmt = $db->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'customer')");
                $stmt->bind_param("sss", $name, $email, $hash);
                $stmt->execute();
                $newId = (int)$db->insert_id;
                $stmt->close();

                $user = ['id' => $newId, 'name' => $name, 'email' => $email, 'role' => 'customer'];
                login_set_user($user);
                header('Location: account.php');
                exit;
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
  <title>Create Account | Pear Store</title>
  <style>
    *{box-sizing:border-box;}
    body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;margin:0;background:#f5f5f7;color:#1d1d1f;}
    .auth-page{min-height:calc(100vh - 52px);display:flex;align-items:center;justify-content:center;padding:40px 16px;}
    .auth-card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:40px;width:100%;max-width:420px;box-shadow:0 4px 24px rgba(0,0,0,.06);}
    .auth-card h1{font-size:24px;font-weight:700;margin:0 0 6px;letter-spacing:-.02em;}
    .auth-card p{color:#6b7280;margin:0 0 24px;font-size:14px;}
    .field{margin-bottom:16px;}
    .field label{display:block;font-size:13px;font-weight:600;margin-bottom:6px;color:#374151;}
    .field input{width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;font-family:inherit;transition:border-color .15s,box-shadow .15s;}
    .field input:focus{outline:none;border-color:#0071e3;box-shadow:0 0 0 3px rgba(0,113,227,.12);}
    .field .hint{font-size:12px;color:#9ca3af;margin-top:4px;}
    .btn-primary{width:100%;padding:12px;background:#0071e3;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;transition:background .15s;margin-top:8px;}
    .btn-primary:hover{background:#005bb5;}
    .auth-errors{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:12px 16px;border-radius:8px;font-size:14px;margin-bottom:20px;}
    .auth-errors ul{margin:6px 0 0;padding-left:18px;}
    .auth-errors ul li{margin-bottom:2px;}
    .auth-footer{text-align:center;margin-top:24px;font-size:14px;color:#6b7280;}
    .auth-footer a{color:#0071e3;text-decoration:none;font-weight:500;}
  </style>
</head>
<body>
<?php include '_nav.php'; ?>
<div class="auth-page">
  <div class="auth-card">
    <h1>Create Account</h1>
    <p>Join Pear Store to track your orders and manage your account.</p>

    <?php if (!empty($errors)): ?>
      <div class="auth-errors">
        <strong>Please correct the following:</strong>
        <ul>
          <?php foreach ($errors as $err): ?>
            <li><?= e($err) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" novalidate>
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

      <div class="field">
        <label for="name">Full name</label>
        <input type="text" id="name" name="name" value="<?= e($values['name']) ?>"
               autocomplete="name" required>
      </div>

      <div class="field">
        <label for="email">Email address</label>
        <input type="email" id="email" name="email" value="<?= e($values['email']) ?>"
               autocomplete="email" required>
      </div>

      <div class="field">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" autocomplete="new-password" required>
        <div class="hint">Minimum 8 characters.</div>
      </div>

      <div class="field">
        <label for="confirm">Confirm password</label>
        <input type="password" id="confirm" name="confirm" autocomplete="new-password" required>
      </div>

      <button type="submit" class="btn-primary">Create Account</button>
    </form>

    <div class="auth-footer">
      Already have an account? <a href="login.php">Sign in</a>
    </div>
  </div>
</div>
</body>
</html>
