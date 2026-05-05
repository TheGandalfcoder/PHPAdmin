<?php
/*
 * setup_admin.php — Run once to create the first admin account, then delete this file.
 */
require_once 'db.php';
require_once 'auth.php';

$db = db();

// Block if an admin already exists
$existing = $db->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetch_assoc();
if ($existing) {
    exit('An admin account already exists. Delete this file.');
}

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name    = trim($_POST['name']    ?? '');
    $email   = trim($_POST['email']   ?? '');
    $pass    = $_POST['password']     ?? '';
    $confirm = $_POST['confirm']      ?? '';
    $key     = $_POST['setup_key']    ?? '';

    if ($key !== 'PEAR-SETUP-2025') {
        $errors[] = 'Incorrect setup key.';
    }
    if ($name === '') $errors[] = 'Name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
    if (strlen($pass) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($pass !== $confirm) $errors[] = 'Passwords do not match.';

    if (empty($errors)) {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, 'admin')");
        $stmt->bind_param("sss", $name, $email, $hash);
        $stmt->execute();
        $stmt->close();
        $success = true;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Admin Setup | Pear Store</title>
  <style>
    body{font-family:-apple-system,sans-serif;background:#f5f5f7;margin:0;display:flex;align-items:center;justify-content:center;min-height:100vh;}
    .card{background:#fff;border:1px solid #e5e7eb;border-radius:14px;padding:36px;max-width:420px;width:100%;box-shadow:0 4px 24px rgba(0,0,0,.07);}
    h1{margin:0 0 6px;font-size:22px;}
    p{color:#6b7280;font-size:14px;margin:0 0 24px;}
    .field{margin-bottom:14px;}
    .field label{display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;text-transform:uppercase;letter-spacing:.04em;}
    .field input{width:100%;padding:9px 11px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;font-family:inherit;}
    .field input:focus{outline:none;border-color:#0071e3;box-shadow:0 0 0 3px rgba(0,113,227,.1);}
    .btn{width:100%;padding:11px;background:#0071e3;color:#fff;border:none;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;}
    .errors{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:12px;border-radius:8px;font-size:14px;margin-bottom:16px;}
    .errors ul{margin:4px 0 0;padding-left:16px;}
    .success{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;padding:16px;border-radius:8px;font-size:14px;}
    .warning{background:#fffbeb;border:1px solid #fde68a;color:#92400e;padding:12px 14px;border-radius:8px;font-size:13px;margin-bottom:20px;}
  </style>
</head>
<body>
<div class="card">
  <h1>Admin Setup</h1>
  <p>Create the first administrator account. Delete this file immediately after use.</p>

  <div class="warning">
    Setup key: <strong>PEAR-SETUP-2025</strong> &mdash; change this before deploying.
  </div>

  <?php if ($success): ?>
    <div class="success">
      <strong>Admin account created.</strong><br>
      You can now <a href="login.php" style="color:#166534">sign in</a>.<br><br>
      <strong>Delete this file from your server immediately.</strong>
    </div>
  <?php else: ?>
    <?php if (!empty($errors)): ?>
      <div class="errors"><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form method="post">
      <div class="field">
        <label>Setup key</label>
        <input type="text" name="setup_key" required>
      </div>
      <div class="field">
        <label>Admin name</label>
        <input type="text" name="name" value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
      </div>
      <div class="field">
        <label>Email</label>
        <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
      </div>
      <div class="field">
        <label>Password</label>
        <input type="password" name="password" required>
      </div>
      <div class="field">
        <label>Confirm password</label>
        <input type="password" name="confirm" required>
      </div>
      <button type="submit" class="btn">Create Admin Account</button>
    </form>
  <?php endif; ?>
</div>
</body>
</html>
