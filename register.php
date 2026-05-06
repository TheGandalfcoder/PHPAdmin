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
  <link rel="stylesheet" href="css/nav.css">
  <link rel="stylesheet" href="css/auth.css">
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
