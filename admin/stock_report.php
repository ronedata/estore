<?php
// admin/stock_report.php
// CSV এক্সপোর্ট/কুয়েরি প্রসেসিং HTML আউটপুটের আগে—তাই header.php পরে রিকোয়ার হবে
require_once __DIR__.'/auth.php';
require_once __DIR__.'/../includes/config.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('view_stock_report'); // <-- পারমিশন চেক

$pdo = get_pdo();

/* ---------- Helper ---------- */
if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money_bd_safe')) {
  function money_bd_safe($n){
    if (function_exists('money_bd')) return money_bd($n);
    $n = (float)$n; $n = round($n);
    return '৳'.number_format($n, 0, '', ',');
  }
}
/* CSRF টোকেন (টগল বাটনের জন্য দরকার) */
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

/* ---------- Filters ---------- */
$q        = trim($_GET['q'] ?? '');
$cat      = (int)($_GET['cat'] ?? 0);
$status   = $_GET['status'] ?? 'all';     // all | active | inactive
$low      = (int)($_GET['low'] ?? 5);     // ✅ ডিফল্ট থ্রেশহোল্ড = 5
$low_only = (int)($_GET['low_only'] ?? 1); // ✅ ডিফল্টে শুধু লো-স্টক দেখাবে (≤ low)
$sort     = $_GET['sort'] ?? 'name';      // name | stock_asc | stock_desc | value_desc

$where = ["1=1"];
$params = [];

if ($q !== '') {
  $where[] = "(p.name LIKE ? OR p.tags LIKE ?)";
  $params[] = "%$q%";
  $params[] = "%$q%";
}
if ($cat > 0) {
  $where[] = "p.category_id = ?";
  $params[] = $cat;
}
if ($status === 'active')   $where[] = "p.active = 1";
if ($status === 'inactive') $where[] = "p.active = 0";

/* Low-stock ফিল্টার: low_only=1 হলে ≤ low */
if ($low_only) {
  $where[] = "p.stock <= ?";
  $params[] = $low;
}

$order = "p.name ASC";
if ($sort === 'stock_asc')  $order = "p.stock ASC";
if ($sort === 'stock_desc') $order = "p.stock DESC";
if ($sort === 'value_desc') $order = "(p.stock * p.price) DESC";

$whereSql = implode(' AND ', $where);

/* ---------- Fetch data ---------- */
$sql = "SELECT p.id, p.name, p.price, p.stock, p.active, c.name AS cat_name,
        (SELECT image FROM product_images pi WHERE pi.product_id=p.id ORDER BY sort_order, id LIMIT 1) AS image
        FROM products p
        LEFT JOIN categories c ON c.id = p.category_id
        WHERE $whereSql
        ORDER BY $order";
$stm = $pdo->prepare($sql);
$stm->execute($params);
$rows = $stm->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Totals ---------- */
$totalSku   = 0;
$totalUnits = 0;
$totalValue = 0.0;
$lowCount   = 0;

foreach ($rows as $r){
  $totalSku++;
  $units = (int)$r['stock'];
  $totalUnits += $units;
  $totalValue += $units * (float)$r['price'];
  if ($units <= $low) $lowCount++;
}

/* ---------- CSV export (HTML আউটপুটের আগে) ---------- */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
  $filename = 'stock_report_'.date('Ymd_His').'.csv';
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  $out = fopen('php://output', 'w');
  // UTF-8 BOM (Excel friendly)
  fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
  fputcsv($out, ['ID','নাম','ক্যাটেগরি','দাম','স্টক','ভ্যালু','স্ট্যাটাস']);
  foreach ($rows as $r){
    $value = (int)$r['stock'] * (float)$r['price'];
    fputcsv($out, [
      $r['id'],
      $r['name'],
      $r['cat_name'],
      $r['price'],
      $r['stock'],
      $value,
      ($r['active'] ? 'Active' : 'Inactive'),
    ]);
  }
  // Totals
  fputcsv($out, ['মোট', '', '', '', $totalUnits, $totalValue, 'SKU: '.$totalSku]);
  fclose($out);
  exit;
}

/* ---------- Categories (for filter) ---------- */
$cats = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

/* ---------- তারপর HTML ---------- */
require_once __DIR__.'/header.php';
?>
<style>
  .report-wrap{max-width:1200px;margin:0 auto}
  .metric{
    background:#0f172a;color:#fff;border-radius:12px;padding:14px 16px;
    display:flex;flex-direction:column;gap:2px;height:100%;
  }
  .metric .label{opacity:.8;font-size:12px}
  .metric .value{font-weight:800;font-size:18px}
  /* Mobile card table */
  @media (max-width: 576px){
    .table-responsive{border:0}
    table.table{display:block}
    table.table thead{display:none}
    table.table tbody{display:block}
    table.table tr{
      display:block;background:#fff;border:1px solid #e9ecef;border-radius:12px;
      padding:12px;margin-bottom:10px;
      box-shadow:0 6px 16px rgba(2,8,23,.04);
    }
    table.table td{
      display:flex;justify-content:space-between;gap:12px;padding:6px 0;border:0 !important;
    }
    table.table td::before{
      content:attr(data-label);
      font-weight:600;color:#64748b;min-width:120px;text-align:left;
    }
    .td-title{font-weight:700;font-size:16px}
    .sticky-actions{position:sticky;bottom:8px;z-index:2}
  }
  .thumb-report{width:40px;height:40px;object-fit:cover;border:1px solid #e5e7eb;border-radius:6px;background:#fff}
  .cell-name-with-img{ display: flex; align-items: center; gap: 10px; }
  @media (max-width: 576px){
    /* মোবাইলে নামের আগে লেবেল দেখানোর জন্য */
    .cell-name-with-img::before{ content: 'নাম'; font-weight: 600; color: #64748b; min-width: 120px; text-align: left; }
  }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="m-0">স্টক রিপোর্ট</h5>
  <div class="d-none d-sm-flex gap-2">
    <a class="btn btn-outline-secondary" href="stock_report.php">রিফ্রেশ</a>
    <a class="btn btn-primary" href="?<?=h(http_build_query(array_merge($_GET,['export'=>'csv'])))?>">Export CSV</a>
  </div>
</div>

<!-- Metrics -->
<div class="report-wrap mb-3">
  <div class="row g-2">
    <div class="col-6 col-md-3">
      <div class="metric">
        <div class="label">মোট পণ্য (SKU)</div>
        <div class="value"><?=h(number_format($totalSku))?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="metric">
        <div class="label">মোট ইউনিট</div>
        <div class="value"><?=h(number_format($totalUnits))?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="metric">
        <div class="label">ইনভেন্টরি ভ্যালু</div>
        <div class="value"><?=h(money_bd_safe($totalValue))?></div>
      </div>
    </div>
    <div class="col-6 col-md-3">
      <div class="metric" style="background:#7c2d12">
        <div class="label">লো-স্টক (≤ <?=h($low)?>)</div>
        <div class="value"><?=h(number_format($lowCount))?></div>
      </div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card mb-3">
  <div class="card-body">
    <form class="row g-2">
      <div class="col-12 col-md-4">
        <input class="form-control" name="q" placeholder="নাম/ট্যাগ দিয়ে খুঁজুন"
               value="<?=h($q)?>">
      </div>

      <div class="col-6 col-md-3">
        <select class="form-select" name="cat">
          <option value="0">সব ক্যাটেগরি</option>
          <?php foreach($cats as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $cat===(int)$c['id']?'selected':'' ?>>
              <?= h($c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-6 col-md-2">
        <select class="form-select" name="status">
          <option value="all" <?= $status==='all'?'selected':'' ?>>সব স্ট্যাটাস</option>
          <option value="active" <?= $status==='active'?'selected':'' ?>>Active</option>
          <option value="inactive" <?= $status==='inactive'?'selected':'' ?>>Inactive</option>
        </select>
      </div>

      <div class="col-6 col-md-2">
        <input type="number" class="form-control" name="low" min="0" placeholder="Low ≤"
               value="<?= h($low) ?>">
      </div>

      <div class="col-6 col-md-1 d-flex align-items-center">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" value="1" id="low_only" name="low_only" <?= $low_only?'checked':'' ?>>
          <label class="form-check-label" for="low_only">শুধু লো-স্টক</label>
        </div>
      </div>

      <div class="col-12 col-md-12 d-flex gap-2">
        <button class="btn btn-primary">ফিল্টার</button>
        <a class="btn btn-outline-secondary" href="stock_report.php">ক্লিয়ার</a>
        <a class="btn btn-outline-dark ms-auto d-sm-none sticky-actions"
           href="?<?=h(http_build_query(array_merge($_GET,['export'=>'csv'])))?>">Export CSV</a>
      </div>
    </form>
  </div>
</div>

<!-- Table -->
<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="width:50px;">ID</th>
            <th>নাম</th>
            <th>ক্যাটেগরি</th>
            <th class="text-end">দাম</th>
            <th class="text-end">স্টক</th>
            <th class="text-end">ভ্যালু</th>
            <th>স্ট্যাটাস</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($rows as $r):
          $value = (int)$r['stock'] * (float)$r['price'];
          $isLow = ((int)$r['stock'] <= $low);
          $img = img_url($r['image'] ?? '');
          ?>
          <tr class="<?= $isLow ? 'table-warning' : '' ?>">
            <td data-label="ID"><?= (int)$r['id'] ?></td>

            <td data-label="নাম" class="td-title cell-name-with-img">
              <img class="thumb-report" src="<?= h($img) ?>" alt="<?= h($r['name']) ?>">
              <a class="link-primary fw-semibold" href="product_form.php?id=<?= (int)$r['id'] ?>"><?= h($r['name']) ?></a>
            </td>

            <td data-label="ক্যাটেগরি"><?= h($r['cat_name']) ?></td>
            <td data-label="দাম" class="text-end"><?= h(money_bd_safe($r['price'])) ?></td>
            <td data-label="স্টক" class="text-end"><?= (int)$r['stock'] ?></td>
            <td data-label="ভ্যালু" class="text-end"><?= h(money_bd_safe($value)) ?></td>

            <!-- ✅ Active/Inactive টগল (POST + CSRF) -->
            <td data-label="স্ট্যাটাস">
              <form method="post" action="product_toggle.php" class="d-inline">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                <input type="hidden" name="redirect" value="<?= h($_SERVER['REQUEST_URI']) ?>">
                <?php if ($r['active']): ?>
                  <button type="submit" class="btn btn-sm btn-success">Active</button>
                <?php else: ?>
                  <button type="submit" class="btn btn-sm btn-secondary">Inactive</button>
                <?php endif; ?>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot class="table-light">
          <tr>
            <th colspan="3" class="text-end">মোট</th>
            <th class="text-end">—</th>
            <th class="text-end"><?= h(number_format($totalUnits)) ?></th>
            <th class="text-end"><?= h(money_bd_safe($totalValue)) ?></th>
            <th>SKU: <?= h(number_format($totalSku)) ?></th>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>

<?php require __DIR__.'/footer.php'; ?>
