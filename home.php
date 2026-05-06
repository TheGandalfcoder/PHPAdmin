<?php
require_once 'db.php';
require_once 'auth.php';

$db = db();

// Get 1 per category (latest version)
$products = [];
$sql = "
  SELECT p.*
  FROM products p
  INNER JOIN (
    SELECT category, MAX(version) AS max_version
    FROM products
    GROUP BY category
  ) m ON p.category = m.category AND p.version = m.max_version
  ORDER BY FIELD(p.category,'phone','laptop','pad')
";
$res = $db->query($sql);
if ($res) {
  while ($row = $res->fetch_assoc()) {
    $products[] = $row;
  }
  $res->free();
}

// helper: returns ['web' => ..., 'disk' => ...] for the first image in img/<category>/<version>/
function firstVersionImage($category, $version){
  $dirDisk = __DIR__ . "/img/$category/$version";
  $dirWeb  = "img/$category/$version";
  if (!is_dir($dirDisk)) return null;

  $found = glob($dirDisk . "/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}", GLOB_BRACE);
  if (empty($found)) return null;

  natsort($found);
  $found = array_values($found);
  return ['web' => $dirWeb . "/" . basename($found[0]), 'disk' => $found[0]];
}

function imgFitClass(string $diskPath): string {
  $size = @getimagesize($diskPath);
  if (!$size) return 'img-fit-cover';
  return $size[1] > $size[0] ? 'img-fit-cover' : 'img-fit-fill';
}
?>


<!doctype html>

<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Pear Shop - Welcome</title>
  <link rel="stylesheet" href="css/nav.css">
  <link rel="stylesheet" href="css/home.css">
</head>
<body>
<?php include '_nav.php'; ?>
  <div class="hero">
    <div class="logo">
      <img src="img/logo.png" alt="Pear Logo">
    </div>
    <h1>Discover Pear Products</h1>
    <p>Shop the latest Pear devices, fast shipping, and expert support.<br>Experience innovation and design, inspired by Pear.</p>
    <a href="main.php" class="shop-btn">Shop Now</a>
  </div>
  <div class="section">
    <h2>Why Shop With Us?</h2>
    <p>
      We offer the newest Pear devices, fast shipping, and specialised software<br>
      Explore our products
    </p>

          <a href="category.php?category=phone" class="blue-btn">Shop PearPhone</a>
<a href="category.php?category=laptop" class="blue-btn">Shop PearBook</a>
<a href="category.php?category=pad" class="blue-btn">Shop PearPad</a>


    <a href="main.php" class="blue-btn">Browse Products</a>
   <div class="product-grid">
  <?php foreach ($products as $p):
    $imgData = firstVersionImage($p['category'], $p['version']);
  ?>
    <div class="product-card">
      <?php if ($imgData): ?>
        <div class="img-wrap">
          <img src="<?php echo htmlspecialchars($imgData['web']); ?>"
               alt="<?php echo htmlspecialchars($p['name']); ?>"
               class="<?php echo imgFitClass($imgData['disk']); ?>">
        </div>
      <?php endif; ?>

      <h3><?php echo htmlspecialchars($p['name']); ?></h3>
      <p><?php echo htmlspecialchars($p['description']); ?></p>
      <a href="product.php?id=<?php echo urlencode($p['id']); ?>" class="blue-btn">View</a>
      

    </div>
  <?php endforeach; ?>
</div>

  </div>
</body>
</html>