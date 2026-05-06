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
  <link rel="stylesheet" href="css/setup.css">
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
