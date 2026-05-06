<?php
require_once 'db.php';
require_once 'auth.php';
csrf_token();

$db = db();

$allowed = ['phone','pad','laptop'];
$category = $_GET['category'] ?? '';
if (!in_array($category, $allowed, true)) die("Invalid category");

// read filter values from the query string
$q       = trim($_GET['q'] ?? '');
$min     = trim($_GET['min'] ?? '');
$max     = trim($_GET['max'] ?? '');
$model   = trim($_GET['model'] ?? '');
$sort    = $_GET['sort'] ?? 'version_desc';

$minNum = ($min !== '' && is_numeric($min)) ? (float)$min : null;
$maxNum = ($max !== '' && is_numeric($max)) ? (float)$max : null;
$modelNum = ($model !== '' && ctype_digit($model)) ? (int)$model : null;

// get available versions for filter
$models = [];
$stmtM = $db->prepare("SELECT DISTINCT version FROM products WHERE category=? ORDER BY version DESC");
$stmtM->bind_param("s", $category);
$stmtM->execute();
$resM = $stmtM->get_result();
while ($row = $resM->fetch_assoc()) $models[] = (int)$row['version'];
$stmtM->close();

// build WHERE clause
$where = "WHERE category = ?";
$params = [$category];
$types  = "s";

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

// only allow known sort values to prevent sql injection
$orderBy = "ORDER BY version DESC";
if ($sort === 'price_asc') $orderBy = "ORDER BY price ASC";
if ($sort === 'price_desc') $orderBy = "ORDER BY price DESC";
if ($sort === 'name_asc') $orderBy = "ORDER BY name ASC";
if ($sort === 'name_desc') $orderBy = "ORDER BY name DESC";
if ($sort === 'version_asc') $orderBy = "ORDER BY version ASC";
if ($sort === 'version_desc') $orderBy = "ORDER BY version DESC";

$sql = "SELECT id, category, version, name, price, description
        FROM products
        $where
        $orderBy";

$stmt = $db->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$res = $stmt->get_result();
$items = $res->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// returns the first image path found in img/<category>/<version>/
function firstVersionImage($category, $version){
  $dirDisk = __DIR__ . "/img/$category/$version";
  $dirWeb  = "img/$category/$version";
  if (!is_dir($dirDisk)) return null;

  $found = glob($dirDisk . "/*.{jpg,jpeg,png,webp,JPG,JPEG,PNG,WEBP}", GLOB_BRACE);
  if (empty($found)) return null;

  natsort($found);
  $found = array_values($found);
  return $dirWeb . "/" . basename($found[0]);
}

$title = strtoupper($category);

$clearUrl = "category.php?category=" . urlencode($category);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo e($title); ?> | Pear Store</title>
  <link rel="stylesheet" href="css/nav.css">
  <link rel="stylesheet" href="css/shop.css">
</head>
<body>
<?php include '_nav.php'; ?>

  <div class="toprow">
    <a href="home.php">← Back</a>
    <a href="cart.php">View cart →</a>
  </div>

  <h1 style="margin:10px 0;"><?php echo e($title); ?></h1>

  <div class="layout">

    <aside class="panel">
      <h3>Filter</h3>

      <form method="get" action="category.php">
        <input type="hidden" name="category" value="<?php echo e($category); ?>">

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
              <?php echo e($category); ?> <?php echo (int)$v; ?>
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

      <div class="muted" style="margin-top:12px;">
        Showing <strong><?php echo count($items); ?></strong> results
      </div>
    </aside>

    <main>
      <?php if (empty($items)): ?>
        <p>No products found.</p>
      <?php else: ?>
        <div class="grid">
          <?php foreach ($items as $p):
            $img = firstVersionImage($p['category'], $p['version']);
          ?>
            <a href="product.php?id=<?php echo (int)$p['id']; ?>" class="card-link">
              <div class="card">
                <?php if ($img): ?><img src="<?php echo e($img); ?>" alt="<?php echo e($p['name']); ?>"><?php endif; ?>
                <div style="font-weight:700"><?php echo e($p['name']); ?></div>
                <div class="price">£<?php echo number_format((float)$p['price'], 2); ?></div>
                <div class="small"><?php echo e($p['description']); ?></div>
                <div style="margin-top:10px">
                  <span style="color:#0071e3;">View details</span>
                </div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </main>

  </div>
</body>
</html>
