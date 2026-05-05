<?php
$_nav_user  = current_user();
$_nav_cart  = is_array($_SESSION['cart'] ?? null) ? array_sum($_SESSION['cart']) : 0;
$_nav_token = csrf_token();
?>
<nav class="pear-nav">
  <div class="pear-nav__inner">
    <a href="home.php" class="pear-nav__brand">Pear Store</a>
    <div class="pear-nav__links">
      <a href="main.php">Shop</a>
      <a href="cart.php">Cart (<?= (int)$_nav_cart ?>)</a>
      <?php if ($_nav_user): ?>
        <?php if ($_nav_user['role'] === 'admin'): ?>
          <a href="admin.php" class="pear-nav__highlight">Admin Dashboard</a>
        <?php else: ?>
          <a href="account.php">My Account</a>
        <?php endif; ?>
        <form method="post" action="logout.php" class="pear-nav__form">
          <input type="hidden" name="csrf_token" value="<?= e($_nav_token) ?>">
          <button type="submit" class="pear-nav__signout">Sign Out</button>
        </form>
      <?php else: ?>
        <a href="login.php">Sign In</a>
        <a href="register.php" class="pear-nav__cta">Create Account</a>
      <?php endif; ?>
    </div>
  </div>
</nav>
<style>
.pear-nav{background:#1a1a1a;color:#fff;position:sticky;top:0;z-index:100;border-bottom:1px solid #333;}
.pear-nav__inner{max-width:1200px;margin:auto;padding:0 18px;display:flex;justify-content:space-between;align-items:center;height:52px;}
.pear-nav__brand{color:#fff;text-decoration:none;font-weight:700;font-size:15px;letter-spacing:-.02em;}
.pear-nav__links{display:flex;align-items:center;gap:20px;}
.pear-nav__links a{color:#aaa;text-decoration:none;font-size:13px;transition:color .15s;}
.pear-nav__links a:hover{color:#fff;}
.pear-nav__cta{background:#0071e3;color:#fff!important;padding:6px 14px;border-radius:6px;font-size:13px!important;}
.pear-nav__cta:hover{background:#005bb5!important;}
.pear-nav__highlight{color:#0071e3!important;}
.pear-nav__form{display:inline;margin:0;padding:0;}
.pear-nav__signout{background:none;border:none;color:#aaa;font-size:13px;cursor:pointer;font-family:inherit;padding:0;transition:color .15s;}
.pear-nav__signout:hover{color:#fff;}
</style>
