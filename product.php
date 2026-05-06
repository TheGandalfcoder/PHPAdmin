<?php
require_once 'db.php';
require_once 'auth.php';
csrf_token();

$db = db();

$id = $_GET['id'] ?? '';
if (!ctype_digit($id)) die("Invalid product");

$stmt = $db->prepare("SELECT id, category, version, name, price, description FROM products WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();
$p = $res->fetch_assoc();
$stmt->close();

if (!$p) die("Product not found");

// load all approved reviews with the reviewer's name joined from users
$stmt = $db->prepare("
    SELECT r.rating, r.comment, r.created_at, u.name AS reviewer
    FROM reviews r
    JOIN users u ON u.id = r.user_id
    WHERE r.product_id = ?
    ORDER BY r.created_at DESC
");
$stmt->bind_param("i", $id);
$stmt->execute();
$reviews = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$avgRating = 0;
if (!empty($reviews)) {
    $avgRating = array_sum(array_column($reviews, 'rating')) / count($reviews);
}

// images are stored under img/<category>/<version>/ on disk
$imgDirDisk = __DIR__ . "/img/{$p['category']}/{$p['version']}";
$imgDirWeb  = "img/{$p['category']}/{$p['version']}";
$images = [];

if (is_dir($imgDirDisk)) {
  $found = glob($imgDirDisk . "/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}", GLOB_BRACE);
  if (!empty($found)) {
    natsort($found);
    foreach ($found as $f) {
      $images[] = $imgDirWeb . "/" . basename($f);
    }
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?php echo e($p['name']); ?> | Pear Store</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link rel="stylesheet" href="css/nav.css">
  <link rel="stylesheet" href="css/product.css">
</head>
<body>
<?php include '_nav.php'; ?>
<div style="max-width:980px;margin:auto;padding:18px;">
  <a href="category.php?category=<?php echo e($p['category']); ?>">← Back to <?php echo e(strtoupper($p['category'])); ?></a>

  <h1 style="margin:12px 0 0;"><?php echo e($p['name']); ?></h1>
  <div class="price">£<?php echo number_format((float)$p['price'], 2); ?></div>

  <div class="viewer">
    <div class="left">
      <?php if (!empty($images)): ?>
        <img id="mainImg" src="<?php echo e($images[0]); ?>" alt="<?php echo e($p['name']); ?>" class="main-img">

        <?php if (count($images) > 1): ?>
          <div class="thumbs">
            <?php foreach ($images as $i => $img): ?>
              <img
                src="<?php echo e($img); ?>"
                class="thumb<?php echo $i===0 ? ' selected' : ''; ?>"
                alt="Thumbnail <?php echo (int)$i+1; ?>"
                onclick="showImg(<?php echo (int)$i; ?>)"
              >
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <div class="noimg">No images found in img/<?php echo e($p['category']); ?>/<?php echo e($p['version']); ?>/</div>
      <?php endif; ?>
    </div>

    <div class="card">
      <div class="desc"><?php echo e($p['description']); ?></div>

      <form method="post" action="processor.php">
        <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="id" value="<?php echo e((string)$p['id']); ?>">
        <input type="hidden" name="return_to" value="cart.php">
        <button class="btn" type="submit">Add to cart</button>
      </form>

      <div style="margin-top:12px;">
        <a href="cart.php">View cart →</a>
      </div>
    </div>
  </div>

  <script>
    const images = <?php echo json_encode($images); ?>;
    function showImg(idx){
      const main = document.getElementById('mainImg');
      if (!main) return;
      main.src = images[idx];
      document.querySelectorAll('.thumb').forEach((t,i)=>t.classList.toggle('selected', i===idx));
    }
  </script>

  <div class="reviews-section">
    <h2>Customer Reviews</h2>
    <?php if (!empty($reviews)): ?>
      <p class="reviews-avg">
        <?= str_repeat('★', round($avgRating)) ?><?= str_repeat('☆', 5 - round($avgRating)) ?>
        <?= number_format($avgRating, 1) ?> out of 5 (<?= count($reviews) ?> review<?= count($reviews) !== 1 ? 's' : '' ?>)
      </p>
      <?php foreach ($reviews as $rev): ?>
        <div class="review-card">
          <div class="review-header">
            <span class="review-author"><?= e($rev['reviewer']) ?></span>
            <span class="review-date"><?= e(date('j M Y', strtotime($rev['created_at']))) ?></span>
          </div>
          <div class="review-stars"><?= str_repeat('★', (int)$rev['rating']) ?><?= str_repeat('☆', 5 - (int)$rev['rating']) ?></div>
          <p class="review-comment"><?= e($rev['comment']) ?></p>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="no-reviews">No reviews yet. Be the first to review this product after your order arrives.</div>
    <?php endif; ?>
  </div>

</div>
</body>
</html>
