<?php
$_nav_user  = current_user();
$_nav_cart  = is_array($_SESSION['cart'] ?? null) ? array_sum($_SESSION['cart']) : 0;
$_nav_token = csrf_token();
?>
<nav class="pear-nav">
  <div class="pear-nav__inner">
    <a href="home.php" class="pear-nav__brand">Pear Store</a>

    <!-- hamburger button shown only on mobile -->
    <button class="pear-nav__burger" id="pearNavBurger" aria-label="Toggle menu">
      <span></span><span></span><span></span>
    </button>

    <div class="pear-nav__links" id="pearNavLinks">
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
<script>
(function(){
  var btn = document.getElementById('pearNavBurger');
  var links = document.getElementById('pearNavLinks');
  if (btn && links) {
    btn.addEventListener('click', function(){
      links.classList.toggle('open');
    });
  }
})();
</script>
