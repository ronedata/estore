<?php
// admin/report_orders.php — অর্ডার ও বিক্রয় রিপোর্ট (তারিখ-সীমা, স্ট্যাটাস, CSV)
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/reports.php';
require_once __DIR__.'/../includes/finance.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('view_reports');

$pdo = get_pdo();
if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money_bd')) { function money_bd($n){ $n=(float)$n; $n=round($n); return '৳'.number_format($n,0,'',','); } }

$range  = rp_parse_range($_GET);
$status = is_string($_GET['status'] ?? null) && in_array($_GET['status'], rp_statuses(), true) ? $_GET['status'] : 'all';
$page   = is_string($_GET['page'] ?? null) && ctype_digit($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$PER    = 25;

/* ---------- CSV (ফিল্টার অনুযায়ী সব সারি) ---------- */
if (($_GET['export'] ?? '') === 'csv') {
  [$w, $p] = rp_where($range, $status);
  $st = $pdo->prepare("SELECT o.id, o.order_code, o.created_at, o.customer_name, o.mobile, o.shipping_district, o.payment_method, o.subtotal, o.discount, o.shipping_fee, o.total, o.status
                       FROM orders o WHERE $w ORDER BY o.created_at DESC, o.id DESC");
  $st->execute($p);
  $csvRows = $st->fetchAll(PDO::FETCH_ASSOC);
  $csvProfit = [];
  foreach (array_chunk($csvRows, 500) as $chunk) $csvProfit += fi_order_profits($pdo, $chunk);
  while (ob_get_level() > 0) @ob_end_clean();
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="order_report_' . $range['from'] . '_' . $range['to'] . '.csv"');
  header('Cache-Control: no-store');
  $out = fopen('php://output', 'w');
  fwrite($out, "\xEF\xBB\xBF");
  fputcsv($out, ['অর্ডার', 'তারিখ', 'নাম', 'মোবাইল', 'জেলা', 'পেমেন্ট', 'সাবটোটাল', 'ডিসকাউন্ট', 'ডেলিভারি', 'মোট', 'লাভ', 'স্ট্যাটাস']);
  foreach ($csvRows as $r) {
    $pf = $csvProfit[(int)$r['id']]['profit'] ?? null;
    fputcsv($out, [rp_csv_safe($r['order_code']), $r['created_at'], rp_csv_safe($r['customer_name']), rp_csv_safe($r['mobile']),
                   rp_csv_safe($r['shipping_district']), rp_csv_safe($r['payment_method']), $r['subtotal'], $r['discount'], $r['shipping_fee'], $r['total'], $pf === null ? '' : $pf, $r['status']]);
  }
  fclose($out);
  exit;
}

$sum   = rp_summary($pdo, $range, $status);
$bySt  = rp_by_status($pdo, $range);
$cats  = rp_sales_by_category($pdo, $range);
$total = rp_orders_count($pdo, $range, $status);
$pages = max(1, (int)ceil($total / $PER));
if ($page > $pages) $page = $pages;
$rows  = rp_orders_page($pdo, $range, $status, $page, $PER);
$profits = fi_order_profits($pdo, $rows);
$keep  = rp_keep_params($range, $status);
$catMax = 0.0; foreach ($cats as $c) $catMax = max($catMax, $c['amount']);
$years = range((int)date('Y'), (int)date('Y') - 5);
if (!in_array($range['year'], $years, true)) array_unshift($years, $range['year']);
$months = [1=>'জানুয়ারি',2=>'ফেব্রুয়ারি',3=>'মার্চ',4=>'এপ্রিল',5=>'মে',6=>'জুন',7=>'জুলাই',8=>'আগস্ট',9=>'সেপ্টেম্বর',10=>'অক্টোবর',11=>'নভেম্বর',12=>'ডিসেম্বর'];
$stClass = ['pending'=>'bg-warning text-dark','processing'=>'bg-info text-dark','shipped'=>'bg-primary','delivered'=>'bg-success','cancelled'=>'bg-secondary'];

require_once __DIR__.'/header.php';
?>
<style>
  .kpi-card{border:1px solid #e5e7eb;border-radius:14px}
  .kpi-value{font-weight:800;font-size:1.25rem}
  .rp-bar{height:8px;border-radius:999px;background:#e0e7ff;overflow:hidden}
  .rp-bar>span{display:block;height:100%;background:#4f46e5;border-radius:999px}
  .st-chip{border:1px solid #e5e7eb;border-radius:999px;padding:.25rem .7rem;font-size:.85rem;text-decoration:none;color:inherit;background:#fff}
  .st-chip.active{border-color:#4f46e5;background:#eef2ff}
  @media (max-width: 576px){
    .table-responsive{border:0}
    table.rp-table{display:block}
    table.rp-table thead{display:none}
    table.rp-table tbody{display:block}
    table.rp-table tr{display:block;background:#fff;border:1px solid #e9ecef;border-radius:12px;padding:12px;margin-bottom:10px;box-shadow:0 6px 16px rgba(2,8,23,.04)}
    table.rp-table td{display:flex;justify-content:space-between;gap:12px;padding:6px 0!important;border:0!important}
    table.rp-table td::before{content:attr(data-label);font-weight:600;color:#64748b;min-width:100px}
  }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h5 class="m-0">অর্ডার ও বিক্রয় রিপোর্ট</h5>
    <div class="text-muted small" id="rpLabel"><?php echo h($range['label']); ?></div>
  </div>
  <a class="btn btn-outline-secondary btn-sm" id="rpCsv" href="report_orders.php?<?php echo h(http_build_query(array_merge($keep, ['export' => 'csv']))); ?>"><i class="fa-solid fa-file-csv"></i> CSV</a>
</div>

<?php echo fi_tabs('orders'); ?>
<?php if ($range['error']): ?><div class="alert alert-warning py-2" id="rpError"><?php echo h($range['error']); ?></div><?php endif; ?>

<form method="get" class="card card-body mb-3" id="rpForm">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-2">
      <label class="form-label small mb-1">সময়</label>
      <select name="type" id="rpType" class="form-select">
        <option value="today" <?php echo $range['type']==='today'?'selected':''; ?>>আজ</option>
        <option value="monthly" <?php echo $range['type']==='monthly'?'selected':''; ?>>মাসিক</option>
        <option value="yearly" <?php echo $range['type']==='yearly'?'selected':''; ?>>বার্ষিক</option>
        <option value="custom" <?php echo $range['type']==='custom'?'selected':''; ?>>কাস্টম</option>
      </select>
    </div>
    <div class="col-6 col-md-2 rp-f rp-monthly rp-yearly">
      <label class="form-label small mb-1">সাল</label>
      <select name="year" class="form-select"><?php foreach ($years as $y): ?><option value="<?php echo $y; ?>" <?php echo $range['year']===$y?'selected':''; ?>><?php echo $y; ?></option><?php endforeach; ?></select>
    </div>
    <div class="col-6 col-md-2 rp-f rp-monthly">
      <label class="form-label small mb-1">মাস</label>
      <select name="month" class="form-select"><?php foreach ($months as $n => $nm): ?><option value="<?php echo $n; ?>" <?php echo $range['month']===$n?'selected':''; ?>><?php echo h($nm); ?></option><?php endforeach; ?></select>
    </div>
    <div class="col-6 col-md-2 rp-f rp-custom">
      <label class="form-label small mb-1">শুরু</label>
      <input type="date" name="from" class="form-control" value="<?php echo h($range['from']); ?>">
    </div>
    <div class="col-6 col-md-2 rp-f rp-custom">
      <label class="form-label small mb-1">শেষ</label>
      <input type="date" name="to" class="form-control" value="<?php echo h($range['to']); ?>">
    </div>
    <div class="col-6 col-md-2">
      <label class="form-label small mb-1">স্ট্যাটাস</label>
      <select name="status" class="form-select">
        <option value="all">সব</option>
        <?php foreach (rp_statuses() as $s): ?><option value="<?php echo $s; ?>" <?php echo $status===$s?'selected':''; ?>><?php echo h(rp_status_label($s)); ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-6 col-md-2 d-grid"><button class="btn btn-primary">দেখুন</button></div>
  </div>
</form>

<div class="row g-3 mb-3" id="rpKpis">
  <div class="col-6 col-xl-3"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">মোট অর্ডার</div><div class="kpi-value" id="kOrders"><?php echo $sum['orders']; ?></div><div class="small text-muted">বাতিল <?php echo $sum['cancelled']; ?>টি</div></div></div></div>
  <div class="col-6 col-xl-3"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">রাজস্ব (বাতিল বাদে)</div><div class="kpi-value" id="kRevenue"><?php echo money_bd($sum['revenue']); ?></div><div class="small text-muted">গড় অর্ডার <?php echo money_bd($sum['avg']); ?></div></div></div></div>
  <div class="col-6 col-xl-3"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">দেওয়া ডিসকাউন্ট</div><div class="kpi-value" id="kDiscount"><?php echo money_bd($sum['discount']); ?></div></div></div></div>
  <div class="col-6 col-xl-3"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">ডেলিভারি চার্জ</div><div class="kpi-value" id="kShipping"><?php echo money_bd($sum['shipping']); ?></div></div></div></div>
</div>

<div class="d-flex flex-wrap gap-2 mb-3" id="rpStatusChips">
  <?php $chipQ = array_diff_key($keep, ['status' => 1]); ?>
  <a class="st-chip <?php echo $status==='all'?'active':''; ?>" href="report_orders.php?<?php echo h(http_build_query($chipQ)); ?>">সব</a>
  <?php foreach (rp_statuses() as $s): ?>
    <a class="st-chip <?php echo $status===$s?'active':''; ?>" href="report_orders.php?<?php echo h(http_build_query(array_merge($chipQ, ['status' => $s]))); ?>"><?php echo h(rp_status_label($s)); ?> <strong><?php echo $bySt[$s]['count']; ?></strong></a>
  <?php endforeach; ?>
</div>

<div class="row g-3 mb-3">
  <div class="col-12">
    <div class="card h-100"><div class="card-body">
      <h6 class="mb-1">ক্যাটেগরি অনুযায়ী বিক্রি</h6>
      <div class="text-muted small mb-3">পণ্যের দাম × পরিমাণ (বাতিল, ডিসকাউন্ট ও ডেলিভারি আগে)</div>
      <?php if (!$cats): ?>
        <div class="text-muted small" id="rpNoCats">এই সময়ে কোনো বিক্রি নেই</div>
      <?php else: ?>
        <div id="rpCats">
        <?php foreach ($cats as $c): $pct = $catMax > 0 ? max(2, round($c['amount'] / $catMax * 100)) : 0; ?>
          <div class="mb-2">
            <div class="d-flex justify-content-between small"><span><?php echo h($c['name']); ?> <span class="text-muted">(<?php echo $c['qty']; ?>টি)</span></span><strong><?php echo money_bd($c['amount']); ?></strong></div>
            <div class="rp-bar"><span style="width:<?php echo $pct; ?>%"></span></div>
          </div>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div></div>
  </div>
  <div class="col-12">
    <div class="card h-100"><div class="card-body p-0">
      <div class="d-flex justify-content-between align-items-center p-3 pb-2">
        <h6 class="m-0">অর্ডারের তালিকা</h6>
        <span class="small text-muted" id="rpCount"><?php echo $total; ?>টি<?php echo $total ? ' — পৃষ্ঠা ' . $page . '/' . $pages : ''; ?></span>
      </div>
      <div class="table-responsive">
        <table class="table table-striped align-middle mb-0 rp-table" id="rpTable">
          <thead class="table-light"><tr><th>অর্ডার</th><th>তারিখ</th><th>গ্রাহক</th><th class="text-end">ডিসকাউন্ট</th><th class="text-end">ডেলিভারি</th><th class="text-end">মোট</th><th class="text-end">লাভ</th><th>স্ট্যাটাস</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td data-label="অর্ডার">#<?php echo h($r['order_code']); ?></td>
              <td data-label="তারিখ"><?php echo h(date('d M Y, g:i A', strtotime($r['created_at']))); ?></td>
              <td data-label="গ্রাহক"><?php echo h($r['customer_name']); ?><div class="small text-muted"><?php echo h($r['mobile']); ?></div></td>
              <td data-label="ডিসকাউন্ট" class="text-end"><?php echo (float)$r['discount'] > 0 ? money_bd($r['discount']) : '—'; ?></td>
              <td data-label="ডেলিভারি" class="text-end"><?php echo money_bd($r['shipping_fee']); ?></td>
              <td data-label="মোট" class="text-end fw-semibold"><?php echo money_bd($r['total']); ?></td>
              <?php $pf = $profits[(int)$r['id']]['profit'] ?? null; $isC = $r['status'] === 'cancelled'; ?>
              <td data-label="লাভ" class="text-end <?php echo ($pf !== null && !$isC) ? ($pf < 0 ? 'text-danger' : 'text-success') : 'text-muted'; ?>"<?php echo ($pf === null && !$isC) ? ' title="কোনো আইটেমের ক্রয়মূল্য অজানা"' : ''; ?>><?php echo ($pf === null || $isC) ? '—' : (($pf < 0 ? '−' : '') . money_bd(abs($pf))); ?></td>
              <td data-label="স্ট্যাটাস"><span class="badge <?php echo $stClass[$r['status']] ?? 'bg-light text-dark'; ?>"><?php echo h(rp_status_label($r['status'])); ?></span></td>
              <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="order_view.php?id=<?php echo (int)$r['id']; ?>">View</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">কোনো অর্ডার পাওয়া যায়নি</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($pages > 1): ?><div class="card-footer"><?php render_pagination($page, $pages, $keep); ?></div><?php endif; ?>
    </div></div>
  </div>
</div>

<script>
(function(){
  const type = document.getElementById('rpType');
  function sync(){
    document.querySelectorAll('.rp-f').forEach(el=>{
      el.style.display = el.classList.contains('rp-'+type.value) ? '' : 'none';
    });
  }
  type.addEventListener('change', sync); sync();
})();
</script>
<?php require __DIR__.'/footer.php'; ?>
