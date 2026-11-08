<?php
// পারমিশন চেক আগে করতে হবে
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('view_dashboard'); // <-- পারমিশন চেক
require_once __DIR__.'/header.php';
$pdo = get_pdo();

/* ---- Fallback helpers (if not loaded globally) ---- */
if (!function_exists('h')) {
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money_bd')) {
  function money_bd($n){ $n=(float)$n; $n=round($n); return '৳'.number_format($n,0,'',','); }
}
if (!function_exists('today')) {
  function today(){ return date('Y-m-d'); }
}

/* ---- Settings / thresholds ---- */
$LOW_STOCK_DEFAULT = 5;
try {
  // চাইলে settings থেকে low_stock_threshold পড়তে পারেন
  $stm = $pdo->prepare("SELECT `key`, `value` FROM settings WHERE `key` = ?");
  $stm->execute(['low_stock_threshold']);
  $cfg = $stm->fetchAll(PDO::FETCH_KEY_PAIR);
  $LOW_STOCK = isset($cfg['low_stock_threshold']) && (int)$cfg['low_stock_threshold'] > 0
    ? (int)$cfg['low_stock_threshold'] : $LOW_STOCK_DEFAULT;
} catch (Throwable $e) {
  $LOW_STOCK = $LOW_STOCK_DEFAULT;
}

/* ---- KPI (today) ---- */
$td   = today();

/* আজকের অর্ডার সংখ্যা */
$stm = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=?");
$stm->execute([$td]); $orders_today = (int)$stm->fetchColumn();

/* আজকের revenue (total sum) */
$stm = $pdo->prepare("SELECT COALESCE(SUM(total),0) FROM orders WHERE DATE(created_at)=?");
$stm->execute([$td]); $revenue_today = (float)$stm->fetchColumn();

/* Pending count (আজকের) */
$stm = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=? AND status='pending'");
$stm->execute([$td]); $pending_today = (int)$stm->fetchColumn();

/* Low stock count */
$stm = $pdo->prepare("SELECT COUNT(*) FROM products WHERE stock <= ?");
$stm->execute([$LOW_STOCK]); $low_stock_count = (int)$stm->fetchColumn();

/* ---- Recent orders (today) ---- */
$limit = 15;
$stm = $pdo->prepare("SELECT o.id, o.order_code, o.customer_name, o.mobile, o.total, o.status, o.created_at,
                             COALESCE(SUM(oi.qty), 0) AS item_count
                      FROM orders o
                      LEFT JOIN order_items oi ON o.id = oi.order_id
                      WHERE DATE(o.created_at) = :today_date
                      GROUP BY o.id
                      ORDER BY o.created_at DESC
                      LIMIT :limit");
$stm->bindValue(':today_date', $td, PDO::PARAM_STR);
$stm->bindValue(':limit', $limit, PDO::PARAM_INT);
$stm->execute();
$recent = $stm->fetchAll();

/* ---- Badge class ---- */
function status_badge_class($s){
  switch ($s) {
    case 'pending':    return 'bg-warning text-dark';
    case 'processing': return 'bg-info text-dark';
    case 'shipped':    return 'bg-primary';
    case 'delivered':  return 'bg-success';
    case 'cancelled':  return 'bg-secondary';
    default:           return 'bg-light text-dark';
  }
}
?>
<!-- Font Awesome (safe inject if missing) -->
<script>
(function(){
  if(!document.querySelector('link[href*="font-awesome"][href*="cdnjs"]')){
    var l=document.createElement('link');
    l.rel='stylesheet';
    l.href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css';
    document.head.appendChild(l);
  }
})();
</script>

<style>
  /* KPI cards */
  .kpi-icon{
    width:42px;height:42px;border-radius:10px;display:inline-flex;align-items:center;justify-content:center;
    background:#eef2ff;color:#4f46e5;
  }
  .kpi-card{border:1px solid #e5e7eb;border-radius:14px}
  .kpi-value{font-weight:800;font-size:1.25rem}

  /* Quick actions */
  .qa .btn{ border-radius:12px; display:flex; align-items:center; gap:8px; justify-content:center; }
  .qa .btn i{ width:1.25em; text-align:center; }

  /* Recent orders table → mobile cards */
  @media (max-width: 576px){
    .table-responsive{ border:0; }
    table.table{ display:block; }
    table.table thead{ display:none; }
    table.table tbody{ display:block; }
    table.table tr{
      display:block; background:#fff; border:1px solid #e9ecef; border-radius:12px;
      padding:12px; margin-bottom:10px; box-shadow:0 6px 16px rgba(2,8,23,.04);
    }
    table.table td{
      display:flex; justify-content:space-between; gap:12px;
      padding:6px 0 !important; border:0 !important;
    }
    table.table td::before{
      content:attr(data-label);
      font-weight:600; color:#64748b; min-width:120px; text-align:left;
    }
    .td-actions .btn{ width:100%; }
    .td-actions{ display:flex; flex-direction:column; gap:8px; }
    .badge-status { margin-bottom:6px; }
  }
  .badge-status{ min-width:94px; display:inline-flex; align-items:center; justify-content:center; }

  .table-responsive {
    overflow-x: auto;
  }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="m-0">ড্যাশবোর্ড</h5>
  <div class="text-muted small">আজ: <?php echo h($td); ?></div>
</div>

<!-- KPI row -->
<div class="row g-3 mb-3">
  <div class="col-12 col-md-6 col-xl-3">
    <div class="card kpi-card">
      <div class="card-body d-flex align-items-center gap-3">
        <span class="kpi-icon"><i class="fa-solid fa-receipt"></i></span>
        <div>
          <div class="text-muted small">আজকের অর্ডার</div>
          <div class="kpi-value"><?php echo $orders_today; ?></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-12 col-md-6 col-xl-3">
    <div class="card kpi-card">
      <div class="card-body d-flex align-items-center gap-3">
        <span class="kpi-icon" style="background:#ecfeff;color:#0891b2"><i class="fa-solid fa-sack-dollar"></i></span>
        <div>
          <div class="text-muted small">আজকের রেভিনিউ</div>
          <div class="kpi-value"><?php echo money_bd($revenue_today); ?></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-12 col-md-6 col-xl-3">
    <div class="card kpi-card">
      <div class="card-body d-flex align-items-center gap-3">
        <span class="kpi-icon" style="background:#fff7ed;color:#c2410c"><i class="fa-solid fa-clock"></i></span>
        <div>
          <div class="text-muted small">পেন্ডিং (আজ)</div>
          <div class="kpi-value"><?php echo $pending_today; ?></div>
        </div>
      </div>
    </div>
  </div>
  <div class="col-12 col-md-6 col-xl-3">
    <div class="card kpi-card">
      <div class="card-body d-flex align-items-center gap-3">
        <span class="kpi-icon" style="background:#f0fdf4;color:#16a34a"><i class="fa-solid fa-box-open"></i></span>
        <div>
          <div class="text-muted small">লো-স্টক (≤ <?php echo (int)$LOW_STOCK; ?>)</div>
          <div class="kpi-value"><?php echo $low_stock_count; ?></div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Quick Actions -->
<div class="card mb-3">
  <div class="card-body">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h6 class="m-0">দ্রুত অ্যাকশন</h6>
      <div class="text-muted small">Often used</div>
    </div>
    <div class="row g-2 qa">
      <div class="col-6 col-md-4 col-xl-2 d-grid">
        <a class="btn btn-primary" href="product_form.php"><i class="fa-solid fa-plus"></i> নতুন পণ্য</a>
      </div>
      <div class="col-6 col-md-4 col-xl-2 d-grid">
        <a class="btn btn-outline-primary" href="products.php"><i class="fa-solid fa-boxes-stacked"></i> পণ্য তালিকা</a>
      </div>
      <div class="col-6 col-md-4 col-xl-2 d-grid">
        <a class="btn btn-outline-secondary" href="orders.php?from=<?php echo h($td); ?>&to=<?php echo h($td); ?>&status=all">
          <i class="fa-solid fa-list-ul"></i> আজকের অর্ডার
        </a>
      </div>
      <div class="col-6 col-md-4 col-xl-2 d-grid">
        <a class="btn btn-outline-success" href="categories.php"><i class="fa-solid fa-tags"></i> ক্যাটাগরি</a>
      </div>
      <div class="col-6 col-md-4 col-xl-2 d-grid">
        <a class="btn btn-outline-info" href="stock_report.php"><i class="fa-solid fa-warehouse"></i> স্টক রিপোর্ট</a>
      </div>
      <div class="col-6 col-md-4 col-xl-2 d-grid">
        <?php if (can('payments')): ?>
          <a class="btn btn-outline-warning" href="payments.php"><i class="fa-solid fa-credit-card"></i> Payment</a>
        <?php endif; ?>
      </div>
      <div class="col-6 col-md-4 col-xl-2 d-grid">
        <?php if (can('settings')): ?>
          <a class="btn btn-outline-dark" href="settings.php"><i class="fa-solid fa-gear"></i> সেটিংস</a>
        <?php endif; ?>
      </div>
      </div>
    </div>
  </div>

<!-- Recent orders (today) -->
<div class="card">
  <div class="card-body p-0">
    <div class="d-flex justify-content-between align-items-center p-3 pb-0">
      <h6 class="m-0">সাম্প্রতিক অর্ডার (আজকের)</h6>
      <a class="small" href="orders.php?from=<?php echo h($td); ?>&to=<?php echo h($td); ?>&status=all">
        সব দেখুন <i class="fa-solid fa-arrow-up-right-from-square ms-1"></i>
      </a>
    </div>
    <div class="table-responsive">
      <table class="table table-striped align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th style="min-width:90px">অর্ডার</th>
            <th>গ্রাহক</th>
            <th>মোবাইল</th>
            <th class="text-center">আইটেম</th>
            <th class="text-end">মোট</th>
            <th style="min-width:180px">স্ট্যাটাস</th>
            <th>সময়</th>
            <th class="text-end">অ্যাকশন</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($recent as $o): ?>
          <tr>
            <td data-label="অর্ডার">#<?php echo h($o['order_code']); ?></td>
            <td data-label="গ্রাহক" class="fw-semibold"><?php echo h($o['customer_name']); ?></td>
            <td data-label="মোবাইল"><?php echo h($o['mobile']); ?></td>
            <td data-label="আইটেম" class="text-center"><?php echo h((int)($o['item_count'] ?? 0)); ?></td>
            <td data-label="মোট" class="text-end"><?php echo money_bd($o['total']); ?></td>
            <td data-label="স্ট্যাটাস">
              <span class="badge badge-status <?php echo status_badge_class($o['status']); ?>">
                <?php echo ucfirst(h($o['status'])); ?>
              </span>
            </td>
            <td data-label="সময়"><?php echo h(substr($o['created_at'], 11, 8)); ?></td>
            <td data-label="অ্যাকশন" class="text-end td-actions">
              <a class="btn btn-sm btn-outline-secondary" href="order_print.php?id=<?php echo (int)$o['id']; ?>" target="_blank">
                <i class="fa-solid fa-print"></i> Print
              </a>
              <a class="btn btn-sm btn-outline-primary" href="order_view.php?id=<?php echo (int)$o['id']; ?>">
                <i class="fa-solid fa-eye"></i> View
              </a>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if(!$recent): ?>
          <tr><td colspan="8" class="text-center text-muted py-4">
            <i class="fa-regular fa-circle-check me-1"></i> আজ কোনো অর্ডার নেই
          </td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require __DIR__.'/footer.php'; ?>
