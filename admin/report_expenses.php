<?php
// admin/report_expenses.php — খরচ রিপোর্ট: মোট, ক্যাটেগরি অনুযায়ী (শতাংশসহ), মাসভিত্তিক, বড় খরচ, তালিকা ও CSV
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/finance.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('view_reports');

$pdo = get_pdo();
if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
$money = fn($n) => '৳' . number_format(round((float)$n), 0, '', ',');

$range = rp_parse_range($_GET);
$cat = null;
if (is_string($_GET['cat'] ?? null) && $_GET['cat'] !== '' && (mb_strlen($_GET['cat']) <= EX_CAT_MAX || $_GET['cat'] === '__none')) $cat = $_GET['cat'];
$page = is_string($_GET['page'] ?? null) && ctype_digit($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$PER = 25;
$keep = rp_keep_params($range, 'all'); if ($cat !== null) $keep['cat'] = $cat;

if (($_GET['export'] ?? '') === 'csv') {
  $all = ex_list($pdo, $range, $cat, 1, 100000);
  while (ob_get_level() > 0) @ob_end_clean();
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="expense_report_' . $range['from'] . '_' . $range['to'] . '.csv"');
  header('Cache-Control: no-store');
  $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
  fputcsv($o, ['তারিখ', 'শিরোনাম', 'ক্যাটেগরি', 'পরিমাণ', 'নোট']);
  foreach ($all as $r) fputcsv($o, [$r['expense_date'], rp_csv_safe($r['title']), rp_csv_safe($r['category']), $r['amount'], rp_csv_safe($r['note'])]);
  fputcsv($o, ['মোট', '', '', ex_total($pdo, $range, $cat), '']);
  fclose($o); exit;
}

$total = ex_total($pdo, $range, $cat);
$count = ex_count($pdo, $range, $cat);
$byCat = ex_by_category($pdo, $range);
$monthsX = fi_expense_months($pdo, $range);
$top = fi_top_expenses($pdo, $range, $cat, 5);
$pages = max(1, (int)ceil($count / $PER));
if ($page > $pages) $page = $pages;
$rows = ex_list($pdo, $range, $cat, $page, $PER);
$cats = ex_categories($pdo);
$daysSpan = max(1, (int)((strtotime($range['to']) - strtotime($range['from'])) / 86400) + 1);
$top1 = $cat === null ? ($byCat[0] ?? null) : ($total > 0 ? ['name' => ($cat === '__none' ? 'ক্যাটেগরি নেই' : $cat), 'sum' => $total] : null);
$years = range((int)date('Y'), (int)date('Y') - 5);
if (!in_array($range['year'], $years, true)) array_unshift($years, $range['year']);
$monthNames = [1=>'জানুয়ারি',2=>'ফেব্রুয়ারি',3=>'মার্চ',4=>'এপ্রিল',5=>'মে',6=>'জুন',7=>'জুলাই',8=>'আগস্ট',9=>'সেপ্টেম্বর',10=>'অক্টোবর',11=>'নভেম্বর',12=>'ডিসেম্বর'];
$mMax = 0.0; foreach ($monthsX as $m) $mMax = max($mMax, $m['amount']);

require_once __DIR__.'/header.php';
?>
<style>
  .kpi-card{border:1px solid #e5e7eb;border-radius:14px}
  .kpi-value{font-weight:800;font-size:1.25rem}
  .rp-bar{height:8px;border-radius:999px;background:#fee2e2;overflow:hidden}
  .rp-bar>span{display:block;height:100%;background:#dc2626;border-radius:999px}
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
  <div><h5 class="m-0">খরচ রিপোর্ট</h5><div class="text-muted small" id="erLabel"><?php echo h($range['label']); ?></div></div>
  <div class="d-flex gap-2"><a class="btn btn-outline-secondary btn-sm" href="expenses.php">খরচ যোগ/সম্পাদনা</a><a class="btn btn-outline-secondary btn-sm" id="erCsv" href="report_expenses.php?<?php echo h(http_build_query(array_merge($keep, ['export' => 'csv']))); ?>"><i class="fa-solid fa-file-csv"></i> CSV</a></div>
</div>
<?php echo fi_tabs('expenses'); ?>
<?php if ($range['error']): ?><div class="alert alert-warning py-2" id="erError"><?php echo h($range['error']); ?></div><?php endif; ?>

<form method="get" class="card card-body mb-3" id="erForm">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-2"><label class="form-label small mb-1">সময়</label>
      <select name="type" id="erType" class="form-select">
        <option value="today" <?php echo $range['type']==='today'?'selected':''; ?>>আজ</option>
        <option value="monthly" <?php echo $range['type']==='monthly'?'selected':''; ?>>মাসিক</option>
        <option value="yearly" <?php echo $range['type']==='yearly'?'selected':''; ?>>বার্ষিক</option>
        <option value="custom" <?php echo $range['type']==='custom'?'selected':''; ?>>কাস্টম</option>
      </select></div>
    <div class="col-6 col-md-2 er-f er-monthly er-yearly"><label class="form-label small mb-1">সাল</label><select name="year" class="form-select"><?php foreach ($years as $y): ?><option value="<?php echo $y; ?>" <?php echo $range['year']===$y?'selected':''; ?>><?php echo $y; ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2 er-f er-monthly"><label class="form-label small mb-1">মাস</label><select name="month" class="form-select"><?php foreach ($monthNames as $n => $nm): ?><option value="<?php echo $n; ?>" <?php echo $range['month']===$n?'selected':''; ?>><?php echo h($nm); ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2 er-f er-custom"><label class="form-label small mb-1">শুরু</label><input type="date" name="from" class="form-control" value="<?php echo h($range['from']); ?>"></div>
    <div class="col-6 col-md-2 er-f er-custom"><label class="form-label small mb-1">শেষ</label><input type="date" name="to" class="form-control" value="<?php echo h($range['to']); ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label small mb-1">ক্যাটেগরি</label>
      <select name="cat" class="form-select"><option value="">সব</option><option value="__none" <?php echo $cat==='__none'?'selected':''; ?>>ক্যাটেগরি নেই</option><?php foreach ($cats as $c): ?><option value="<?php echo h($c); ?>" <?php echo $cat===$c?'selected':''; ?>><?php echo h($c); ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-1 d-grid"><button class="btn btn-primary">দেখুন</button></div>
  </div>
</form>

<div class="row g-3 mb-3" id="erKpis">
  <div class="col-6 col-xl-3"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">মোট খরচ</div><div class="kpi-value" id="kTotal"><?php echo $money($total); ?></div></div></div></div>
  <div class="col-6 col-xl-3"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">এন্ট্রি সংখ্যা</div><div class="kpi-value" id="kCount"><?php echo $count; ?></div><div class="small text-muted">গড় <?php echo $count ? $money($total / $count) : '৳0'; ?> প্রতি এন্ট্রি</div></div></div></div>
  <div class="col-6 col-xl-3"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">দৈনিক গড় (<?php echo $daysSpan; ?> দিনে)</div><div class="kpi-value" id="kDaily"><?php echo $money($total / $daysSpan); ?></div></div></div></div>
  <div class="col-6 col-xl-3"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">সবচেয়ে বেশি খরচের ক্যাটেগরি</div><div class="kpi-value" id="kTopCat" style="font-size:1rem"><?php echo $top1 ? h($top1['name']) : '—'; ?></div><div class="small text-muted"><?php echo $top1 ? $money($top1['sum']) : ''; ?></div></div></div></div>
</div>

<div class="row g-3 mb-3">
  <div class="col-12 col-lg-6">
    <div class="card h-100"><div class="card-body">
      <h6 class="mb-3">ক্যাটেগরি অনুযায়ী</h6>
      <?php if (!$byCat): ?><div class="text-muted small" id="erNoCats">এই সময়ে কোনো খরচ নেই</div><?php else: ?>
      <div id="erCats">
      <?php $grand = array_sum(array_column($byCat, 'sum')); $mx = max(array_column($byCat, 'sum')); foreach ($byCat as $c): $pct = $grand > 0 ? $c['sum'] / $grand * 100 : 0; $w = $mx > 0 ? max(2, round($c['sum'] / $mx * 100)) : 0; ?>
        <div class="mb-2"><div class="d-flex justify-content-between small"><span><?php echo h($c['name']); ?> <span class="text-muted">(<?php echo $c['count']; ?>টি)</span></span><strong><?php echo $money($c['sum']); ?> <span class="text-muted">· <?php echo number_format($pct, 1); ?>%</span></strong></div><div class="rp-bar"><span style="width:<?php echo $w; ?>%"></span></div></div>
      <?php endforeach; ?>
      </div><?php endif; ?>
    </div></div>
  </div>
  <div class="col-12 col-lg-6">
    <div class="card h-100"><div class="card-body p-0">
      <div class="p-3 pb-2"><h6 class="m-0">সবচেয়ে বড় ৫টি খরচ</h6></div>
      <div class="table-responsive"><table class="table table-striped mb-0" id="erTop">
        <thead class="table-light"><tr><th>তারিখ</th><th>শিরোনাম</th><th>ক্যাটেগরি</th><th class="text-end">পরিমাণ</th></tr></thead>
        <tbody><?php foreach ($top as $r): ?><tr><td><?php echo h(date('d M Y', strtotime($r['expense_date']))); ?></td><td><?php echo h($r['title']); ?></td><td><?php echo h($r['category'] ?? '—'); ?></td><td class="text-end"><?php echo $money($r['amount']); ?></td></tr><?php endforeach; ?>
        <?php if (!$top): ?><tr><td colspan="4" class="text-center text-muted py-3">এই সময়ে কোনো খরচ নেই</td></tr><?php endif; ?></tbody>
      </table></div>
    </div></div>
  </div>
</div>

<?php if (count($monthsX) > 1): ?>
<div class="card mb-3"><div class="card-body">
  <h6 class="mb-3">মাস অনুযায়ী খরচ</h6>
  <div id="erMonths">
  <?php foreach ($monthsX as $m): $w = $mMax > 0 ? max(2, round($m['amount'] / $mMax * 100)) : 2; ?>
    <div class="mb-2" data-key="<?php echo h($m['key']); ?>"><div class="d-flex justify-content-between small"><span><?php echo h($m['label']); ?> <span class="text-muted">(<?php echo $m['count']; ?>টি)</span></span><strong><?php echo $money($m['amount']); ?></strong></div><div class="rp-bar"><span style="width:<?php echo $m['amount'] > 0 ? $w : 0; ?>%"></span></div></div>
  <?php endforeach; ?>
  </div>
</div></div>
<?php endif; ?>

<div class="card"><div class="card-body p-0">
  <div class="d-flex justify-content-between align-items-center p-3 pb-2"><h6 class="m-0">খরচের তালিকা</h6><span class="small text-muted" id="erCount"><?php echo $count; ?>টি<?php echo $count ? ' — পৃষ্ঠা ' . $page . '/' . $pages : ''; ?></span></div>
  <div class="table-responsive"><table class="table table-striped mb-0" id="erTable">
    <thead class="table-light"><tr><th>তারিখ</th><th>শিরোনাম</th><th>ক্যাটেগরি</th><th class="text-end">পরিমাণ</th><th>নোট</th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?><tr data-id="<?php echo (int)$r['id']; ?>"><td><?php echo h(date('d M Y', strtotime($r['expense_date']))); ?></td><td class="fw-semibold"><?php echo h($r['title']); ?></td><td><?php echo h($r['category'] ?? '—'); ?></td><td class="text-end"><?php echo $money($r['amount']); ?></td><td class="small text-muted"><?php echo h($r['note'] ?? ''); ?></td></tr><?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="5" class="text-center text-muted py-4">এই সময়ে কোনো খরচ নেই</td></tr><?php endif; ?></tbody>
  </table></div>
  <?php if ($pages > 1): ?><div class="card-footer"><?php render_pagination($page, $pages, $keep); ?></div><?php endif; ?>
</div></div>
<script>
(function(){
  const type=document.getElementById('erType');
  function sync(){ document.querySelectorAll('.er-f').forEach(el=>{ el.style.display = el.classList.contains('er-'+type.value) ? '' : 'none'; }); }
  type.addEventListener('change',sync); sync();
})();
</script>
<?php require __DIR__.'/footer.php'; ?>
