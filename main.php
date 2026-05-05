<?php
require_once 'db.php';
require_once 'auth.php';
csrf_token();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$cart = $_SESSION['cart'] ?? [];
if (!is_array($cart)) $cart = [];
function cartCount($cart){ return array_sum(array_values($cart)); }

// DB
$db = db();

// filter inputs (GET)
$q       = trim($_GET['q'] ?? '');
$min     = trim($_GET['min'] ?? '');
$max     = trim($_GET['max'] ?? '');
$cat     = trim($_GET['category'] ?? ''); // '' means all
$model   = trim($_GET['model'] ?? '');    // version
$sort    = $_GET['sort'] ?? 'version_desc';
// validate category
$allowedCats = ['','phone','pad','laptop'];
if (!in_array($cat, $allowedCats, true)) $cat = '';
// validate numeric inputs
$minNum = ($min !== '' && is_numeric($min)) ? (float)$min : null;
$maxNum = ($max !== '' && is_numeric($max)) ? (float)$max : null;
$modelNum = ($model !== '' && ctype_digit($model)) ? (int)$model : null;
// get available models (versions)
$models = [];
if ($cat === '') {
  $resM = $db->query("SELECT DISTINCT version FROM products ORDER BY version DESC");
  if ($resM) {
    while ($row = $resM->fetch_assoc()) $models[] = (int)$row['version'];
    $resM->free();
  }
} else {// specific category
  $stmtM = $db->prepare("SELECT DISTINCT version FROM products WHERE category=? ORDER BY version DESC");
  $stmtM->bind_param("s", $cat);
  $stmtM->execute();
  $resM = $stmtM->get_result();
  while ($row = $resM->fetch_assoc()) $models[] = (int)$row['version'];
  $stmtM->close();
}
// build filtered query
$where = "WHERE 1=1";
$params = [];
$types  = "";
// add filters
if ($cat !== '') {
  $where .= " AND category = ?";
  $params[] = $cat;
  $types .= "s";
}// category filter
if ($q !== '') {
  $where .= " AND name LIKE ?";
  $params[] = "%$q%";
  $types .= "s";
}// name search
if ($minNum !== null) {
  $where .= " AND price >= ?";
  $params[] = $minNum;
  $types .= "d";
}// min price
if ($maxNum !== null) {
  $where .= " AND price <= ?";
  $params[] = $maxNum;
  $types .= "d";
}// max price
if ($modelNum !== null) {
  $where .= " AND version = ?";
  $params[] = $modelNum;
  $types .= "i";
}

// sort whitelist (same vibe as category.php)
$orderBy = "ORDER BY category ASC, version DESC";// default
if ($sort === 'price_asc')    $orderBy = "ORDER BY price ASC";// price low to high
if ($sort === 'price_desc')   $orderBy = "ORDER BY price DESC";// price high to low
if ($sort === 'name_asc')     $orderBy = "ORDER BY name ASC";
if ($sort === 'name_desc')    $orderBy = "ORDER BY name DESC";
if ($sort === 'version_asc')  $orderBy = "ORDER BY category ASC, version ASC";
if ($sort === 'version_desc') $orderBy = "ORDER BY category ASC, version DESC";
// final SQL
$sql = "SELECT id, category, version, name, price, description 
        FROM products
        $where
        $orderBy";
// prepare and execute
$stmt = $db->prepare($sql);
if ($types !== "") $stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$products = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();
// close DB

// helper: get first image for category/version — returns ['web'=>..., 'disk'=>...]
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
  <style>
    body{font-family:Arial,Helvetica,sans-serif; max-width:1200px; margin:auto; padding:18px;}
    a{color:#0071e3; text-decoration:none;}
    .toprow{display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;}
    .layout{display:grid; grid-template-columns:260px 1fr; gap:18px; align-items:start; margin-top:14px;}
    @media (max-width: 900px){ .layout{grid-template-columns:1fr;} }

    .panel{border:1px solid #eef2f6; border-radius:12px; padding:14px;}
    .panel h3{margin:0 0 10px; font-size:16px;}
    label{display:block; font-size:13px; color:#444; margin:10px 0 6px;}
    input,select{width:100%; padding:9px 10px; border:1px solid #e5e7eb; border-radius:10px; font-family:inherit;}
    .btn{background:#0071e3; color:#fff; border:none; border-radius:10px; padding:10px 12px; cursor:pointer; width:100%; margin-top:12px;}
    .muted{color:#6b7280; font-size:13px;}

    .grid{display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:14px;}
    .card{border:1px solid #eef2f6; border-radius:12px; padding:14px; transition:.15s; background:#fff;}
    .card:hover{box-shadow:0 2px 12px rgba(0,113,227,.12); border-color:#0071e3;}
    .img-wrap{width:100%; height:170px; border-radius:10px; overflow:hidden; margin-bottom:10px; border:1px solid #eef2f6;}
    .img{width:100%; height:100%; display:block;}
    .img-fit-cover{object-fit:cover; object-position:top;}
    .img-fit-fill{object-fit:fill;}
    .price{color:#0071e3; font-weight:700;}
    .small{color:#444; font-size:14px;}
    .pill{display:inline-block; padding:4px 8px; border-radius:999px; border:1px solid #eef2f6; font-size:12px; color:#444; margin-top:8px;}
    button{background:#0071e3; border:0; color:#fff; padding:8px 10px; border-radius:8px; cursor:pointer;}
  </style>
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

    <!-- LEFT FILTER PANEL (category.php style) -->
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

    <!-- RIGHT GRID -->
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
