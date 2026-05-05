<?php
require_once 'db.php';
require_once 'auth.php';
csrf_token();

$db = db();

$allowed = ['phone','pad','laptop'];// allowed categories
$category = $_GET['category'] ?? '';// category input
if (!in_array($category, $allowed, true)) die("Invalid category");//  category validation
//filter inputs
$q       = trim($_GET['q'] ?? '');                 // name search
$min     = trim($_GET['min'] ?? '');               // min price
$max     = trim($_GET['max'] ?? '');               // max price
$model   = trim($_GET['model'] ?? '');             // version filter
$sort    = $_GET['sort'] ?? 'version_desc';        // sorting

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

// sort whitelist
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

// --------- IMAGE HELPER ----------
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

// For "Clear filters" link
$clearUrl = "category.php?category=" . urlencode($category);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?php echo e($title); ?> | Pear Store</title>
  <style>
    body{font-family:Arial,Helvetica,sans-serif; max-width:1200px; margin:auto; padding:18px;}
    a{color:#0071e3; text-decoration:none;}
    .layout{display:grid; grid-template-columns:260px 1fr; gap:18px; align-items:start;}
    @media (max-width: 900px){ .layout{grid-template-columns:1fr;} }

    .panel{border:1px solid #eef2f6; border-radius:12px; padding:14px;}
    .panel h3{margin:0 0 10px; font-size:16px;}
    label{display:block; font-size:13px; color:#444; margin:10px 0 6px;}
    input,select{width:100%; padding:9px 10px; border:1px solid #e5e7eb; border-radius:10px; font-family:inherit;}
    .btn{background:#0071e3; color:#fff; border:none; border-radius:10px; padding:10px 12px; cursor:pointer; width:100%; margin-top:12px;}
    .muted{color:#6b7280; font-size:13px;}

    .grid{display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:14px; margin-top:16px;}
    .card{border:1px solid #eef2f6; border-radius:12px; padding:14px; transition:.15s;}
    img{width:100%; height:170px; object-fit:cover; border-radius:10px; margin-bottom:10px;}
    .price{color:#0071e3; font-weight:700;}
    .small{color:#444; font-size:14px;}
    .card-link { text-decoration:none; color:inherit; }
    .card-link:hover .card { box-shadow:0 2px 12px #0071e322; border-color:#0071e3; }
    .toprow{display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:12px;}
  </style>
</head>
<body>
<?php include '_nav.php'; ?>

  <div class="toprow">
    <a href="home.php">← Back</a>
    <a href="cart.php">View cart →</a>
  </div>

  <h1 style="margin:10px 0;"><?php echo e($title); ?></h1>

  <div class="layout">

    <!-- LEFT FILTER PANEL -->
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

    <!-- RIGHT GRID -->
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
