<?php
require_once 'db.php';
require_once 'auth.php';
csrf_token();

// DB
$db = db();

// Validate id
$id = $_GET['id'] ?? '';
if (!ctype_digit($id)) die("Invalid product");

// Load product
$stmt = $db->prepare("SELECT id, category, version, name, price, description FROM products WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result();
$p = $res->fetch_assoc();
$stmt->close();

if (!$p) die("Product not found");

// Load images from: img/<category>/<version>/
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
  <style>
    body{font-family:Arial,Helvetica,sans-serif; max-width:980px; margin:auto; padding:18px;}
    a{color:#0071e3; text-decoration:none;}
    .viewer{display:flex; gap:24px; align-items:flex-start; flex-wrap:wrap; margin-top:18px;}
    .left{max-width:420px;}
    .main-img{width:420px; max-width:100%; height:420px; object-fit:cover; border-radius:12px; border:1px solid #eef2f6;}
    .thumbs{display:flex; gap:8px; margin-top:10px; flex-wrap:wrap;}
    .thumb{width:70px; height:70px; object-fit:cover; border-radius:10px; border:2px solid #eef2f6; cursor:pointer;}
    .thumb.selected{border-color:#0071e3;}
    .price{color:#0071e3; font-weight:700; font-size:22px; margin:8px 0 0;}
    .desc{margin-top:16px; color:#444; line-height:1.4;}
    .card{border:1px solid #eef2f6; border-radius:12px; padding:16px; min-width:280px; flex:1;}
    .btn{background:#0071e3; color:#fff; border:none; border-radius:10px; padding:10px 18px; font-size:16px; cursor:pointer; margin-top:16px;}
    .noimg{width:420px; max-width:100%; height:420px; border:1px dashed #cbd5e1; border-radius:12px;
           display:flex; align-items:center; justify-content:center; color:#64748b;}
  </style>
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

      <!-- Add to cart and go to cart page -->
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
</div>
</body>
</html>
