<?php
require_once 'db.php';
require_once 'auth.php';
csrf_token();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$cart = $_SESSION['cart'] ?? [];
if (!is_array($cart)) $cart = [];
function cartCount($cart){ return array_sum(array_values($cart)); }

$db = db();

// read filter values from the query string
$q       = trim($_GET['q'] ?? '');
$min     = trim($_GET['min'] ?? '');
$max     = trim($_GET['max'] ?? '');
$cat     = trim($_GET['category'] ?? ''); // empty string means all categories
$model   = trim($_GET['model'] ?? '');    // model maps to the version column
$sort    = $_GET['sort'] ?? 'version_desc';

// reject any category value not in the known list
$allowedCats = ['','phone','pad','laptop'];
if (!in_array($cat, $allowedCats, true)) $cat = '';

$minNum   = ($min !== '' && is_numeric($min)) ? (float)$min : null;
$maxNum   = ($max !== '' && is_numeric($max)) ? (float)$max : null;
$modelNum = ($model !== '' && ctype_digit($model)) ? (int)$model : null;

// populate the model dropdown — scoped to the selected category if one is chosen
$models = [];
if ($cat === '') {
  $resM = $db->query("SELECT DISTINCT version FROM products ORDER BY version DESC");
  if ($resM) {
    while ($row = $resM->fetch_assoc()) $models[] = (int)$row['version'];
    $resM->free();
  }
} else {
  $stmtM = $db->prepare("SELECT DISTINCT version FROM products WHERE category=? ORDER BY version DESC");
  $stmtM->bind_param("s", $cat);
  $stmtM->execute();
  $resM = $stmtM->get_result();
  while ($row = $resM->fetch_assoc()) $models[] = (int)$row['version'];
  $stmtM->close();
}

$where  = "WHERE 1=1";
$params = [];
$types  = "";

if ($cat !== '') {
  $where .= " AND category = ?";
  $params[] = $cat;
  $types .= "s";
}
if ($q !== '') {
  $where .= " AND name LIKE ?";
  $params[] = "%$q%";
  $types .= "s";
}
if ($minNum !== null) {
  $where .= " AND price >= ?";
  $params[] = $minNum;
  $types .= "d";
}
if ($maxNum !== null) {
  $where .= " AND price <= ?";
  $params[] = $maxNum;
  $types .= "d";
}
if ($modelNum !== null) {
  $where .= " AND version = ?";
  $params[] = $modelNum;
  $types .= "i";
}

// only allow known sort values to prevent SQL injection
$orderBy = "ORDER BY category ASC, version DESC";
if ($sort === 'price_asc')    $orderBy = "ORDER BY price ASC";
if ($sort === 'price_desc')   $orderBy = "ORDER BY price DESC";
if ($sort === 'name_asc')     $orderBy = "ORDER BY name ASC";
if ($sort === 'name_desc')    $orderBy = "ORDER BY name DESC";
if ($sort === 'version_asc')  $orderBy = "ORDER BY category ASC, version ASC";
if ($sort === 'version_desc') $orderBy = "ORDER BY category ASC, version DESC";

$sql = "SELECT id, category, version, name, price, description
        FROM products
        $where
        $orderBy";
$stmt = $db->prepare($sql);
if ($types !== "") $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$products = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// returns the web path and disk path for the first image in img/<category>/<version>/
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

$clearUrl = "main.php";
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Pear Store</title>
  <link rel="stylesheet" href="css/nav.css">
  <link rel="stylesheet" href="css/shop.css">
</head>
<body>
<?php include '_nav.php'; ?>
  <div class="toprow">
    <div>
      <a href="home.php">← Home</a>
      <span style="margin:0 10px; color:#cbd5e1;">|</span>
      <a href="cart.php">Cart (<?php echo (int)cartCount($cart); ?>)</a>
    </div>
    <div class="muted">Showing <strong><?php echo count($products); ?></strong> results</div>
  </div>

  <?php if ($flash): ?>
    <?php if (!empty($flash['errors'])): ?>
      <div class="panel" style="border-color:#ffb4b4; color:#8b0000; margin-top:12px;">
        <strong>Errors:</strong>
        <ul><?php foreach ($flash['errors'] as $err): ?><li><?php echo e($err); ?></li><?php endforeach; ?></ul>
      </div>
    <?php elseif (!empty($flash['success'])): ?>
      <div class="panel" style="border-color:#b7f7c8; color:green; margin-top:12px;">
        <strong><?php echo e($flash['success']); ?></strong>
      </div>
    <?php endif; ?>
  <?php endif; ?>

  <div class="layout">

    <aside class="panel">
      <h3>Filter</h3>

      <form method="get" action="main.php">
        <label for="category">Category</label>
        <select id="category" name="category">
          <option value="" <?php echo $cat===''?'selected':''; ?>>All</option>
          <option value="phone" <?php echo $cat==='phone'?'selected':''; ?>>Phone</option>
          <option value="pad" <?php echo $cat==='pad'?'selected':''; ?>>Pad</option>
          <option value="laptop" <?php echo $cat==='laptop'?'selected':''; ?>>Laptop</option>
        </select>

        <label for="q">Name</label>
        <input id="q" name="q" value="<?php echo e($q); ?>" placeholder="Search e.g. Pear Phone">

        <label>Price</label>
        <div style="display:flex; gap:10px;">
          <input name="min" value="<?php echo e($min); ?>" placeholder="Min" inputmode="decimal">
          <input name="max" value="<?php echo e($max); ?>" placeholder="Max" inputmode="decimal">
        </div>

        <label for="model">Model (version)</label>
        <select id="model" name="model">
          <option value="">All models</option>
          <?php foreach ($models as $v): ?>
            <option value="<?php echo (int)$v; ?>" <?php echo ($modelNum === (int)$v) ? 'selected' : ''; ?>>
              <?php echo (int)$v; ?>
            </option>
          <?php endforeach; ?>
        </select>

        <label for="sort">Sort</label>
        <select id="sort" name="sort">
          <option value="version_desc" <?php echo $sort==='version_desc'?'selected':''; ?>>Newest model</option>
          <option value="version_asc"  <?php echo $sort==='version_asc'?'selected':''; ?>>Oldest model</option>
          <option value="price_asc"    <?php echo $sort==='price_asc'?'selected':''; ?>>Price: low to high</option>
          <option value="price_desc"   <?php echo $sort==='price_desc'?'selected':''; ?>>Price: high to low</option>
          <option value="name_asc"     <?php echo $sort==='name_asc'?'selected':''; ?>>Name: A–Z</option>
          <option value="name_desc"    <?php echo $sort==='name_desc'?'selected':''; ?>>Name: Z–A</option>
        </select>

        <button class="btn" type="submit">Apply</button>

        <div style="margin-top:10px;">
          <a class="muted" href="<?php echo e($clearUrl); ?>">Clear filters</a>
        </div>
      </form>
    </aside>

    <main>
      <?php if (empty($products)): ?>
        <p>No products found.</p>
      <?php else: ?>
        <div class="grid">
          <?php foreach ($products as $p): ?>
            <?php $imgData = firstVersionImage($p['category'], $p['version']); ?>
            <div class="card">
              <a href="product.php?id=<?php echo (int)$p['id']; ?>">
                <?php if ($imgData): ?>
                  <div class="img-wrap">
                    <img class="img <?php echo imgFitClass($imgData['disk']); ?>"
                         src="<?php echo e($imgData['web']); ?>"
                         alt="<?php echo e($p['name']); ?>">
                  </div>
                <?php endif; ?>
                <div style="font-weight:700"><?php echo e($p['name']); ?></div>
              </a>

              <div class="price">£<?php echo number_format((float)$p['price'], 2); ?></div>
              <div class="small"><?php echo e($p['description']); ?></div>
              <div class="pill"><?php echo e($p['category']); ?> • v<?php echo (int)$p['version']; ?></div>

              <form method="post" action="processor.php" style="margin-top:10px;">
                <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="add">
                <input type="hidden" name="id" value="<?php echo e((string)$p['id']); ?>">
                <input type="hidden" name="return_to" value="cart.php">
                <button type="submit">Add to cart</button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </main>

  </div>
</body>
</html>
