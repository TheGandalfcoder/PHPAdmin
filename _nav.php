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
<style>
.pear-nav{background:#1a1a1a;color:#fff;position:sticky;top:0;z-index:100;border-bottom:1px solid #333;}
.pear-nav__inner{max-width:1200px;margin:auto;padding:0 18px;display:flex;justify-content:space-between;align-items:center;height:52px;flex-wrap:wrap;}
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

/* hamburger button — hidden on desktop */
.pear-nav__burger{display:none;flex-direction:column;justify-content:space-between;width:24px;height:18px;background:none;border:none;cursor:pointer;padding:0;}
.pear-nav__burger span{display:block;height:2px;background:#fff;border-radius:2px;transition:transform .2s,opacity .2s;}

/* mobile styles */
@media(max-width:640px){
  .pear-nav__inner{height:auto;padding:12px 18px;}
  .pear-nav__burger{display:flex;}
  .pear-nav__links{display:none;flex-direction:column;align-items:flex-start;gap:14px;width:100%;padding:14px 0 8px;}
  .pear-nav__links.open{display:flex;}
  .pear-nav__links a{font-size:15px;}
}
</style>
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
