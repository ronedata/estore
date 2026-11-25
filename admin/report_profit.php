<?php
// admin/report_profit.php — লাভ-ক্ষতি (Profit & Loss): রাজস্ব − বিক্রিত পণ্যের ক্রয়মূল্য (COGS) − খরচ
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/finance.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('view_reports');

$pdo = get_pdo();
if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
$money = fn($n) => ((float)$n < 0 ? '−' : '') . '৳' . number_format(abs(round((float)$n)), 0, '', ',');
$money2 = fn($n) => ((float)$n < 0 ? '−' : '') . '৳' . number_format(abs((float)$n), 2, '.', ',');

$range = rp_parse_range($_GET);
$basis = fi_basis(is_string($_GET['basis'] ?? null) ? $_GET['basis'] : 'all');
$p = fi_pnl($pdo, $range, $basis);
$months = fi_pnl_months($pdo, $range, $basis);
$cats = fi_profit_by_category($pdo, $range, $basis);
$prods = fi_profit_by_product($pdo, $range, $basis, 10);
$keep = rp_keep_params($range, 'all'); if ($basis !== 'all') $keep['basis'] = $basis;

if (($_GET['export'] ?? '') === 'csv') {
  while (ob_get_level() > 0) @ob_end_clean();
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="profit_loss_' . $range['from'] . '_' . $range['to'] . '.csv"');
  header('Cache-Control: no-store');
  $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
  fputcsv($o, ['লাভ-ক্ষতি', $range['from'] . ' থেকে ' . $range['to'], $basis === 'delivered' ? 'শুধু Delivered' : 'বাতিল বাদে সব']);
  fputcsv($o, ['বিবরণ', 'টাকা']);
  foreach ([['অর্ডার সংখ্যা', $p['orders']], ['বিক্রয় (ডিসকাউন্টের পর)', $p['net_sales']], ['ডেলিভারি চার্জ আদায়', $p['delivery']], ['রাজস্ব', $p['revenue']],
            ['বিক্রিত পণ্যের ক্রয়মূল্য (COGS)', $p['cogs']], ['মোট লাভ', $p['gross']], ['খরচ', $p['expenses']], ['নিট লাভ/(ক্ষতি)', $p['net']],
            ['ক্রয়মূল্য অজানা আইটেম (সংখ্যা)', $p['unknown_lines']], ['ক্রয়মূল্য অজানা আইটেমের বিক্রি', $p['unknown_sales']], ['ক্রয়মূল্য-জানা আইটেমে মার্জিন', $p['known_margin']]] as $r) fputcsv($o, $r);
  fputcsv($o, []); fputcsv($o, ['মাস', 'অর্ডার', 'রাজস্ব', 'COGS', 'খরচ', 'নিট লাভ']);
  foreach ($months as $m) fputcsv($o, [$m['label'], $m['orders'], $m['revenue'], $m['cogs'], $m['expenses'], $m['profit']]);
  fclose($o); exit;
}

$years = range((int)date('Y'), (int)date('Y') - 5);
if (!in_array($range['year'], $years, true)) array_unshift($years, $range['year']);
$monthNames = [1=>'জানুয়ারি',2=>'ফেব্রুয়ারি',3=>'মার্চ',4=>'এপ্রিল',5=>'মে',6=>'জুন',7=>'জুলাই',8=>'আগস্ট',9=>'সেপ্টেম্বর',10=>'অক্টোবর',11=>'নভেম্বর',12=>'ডিসেম্বর'];
$netClass = $p['net'] > 0 ? 'text-success' : ($p['net'] < 0 ? 'text-danger' : '');

require_once __DIR__.'/header.php';
?>
<style>
  .kpi-card{border:1px solid #e5e7eb;border-radius:14px}
  .kpi-value{font-weight:800;font-size:1.25rem}
  .st-row{display:flex;justify-content:space-between;gap:12px;padding:.55rem .25rem;border-bottom:1px solid #eef2f7}
  .st-row.sub{padding-left:1.25rem;color:#64748b}
  .st-row.total{font-weight:800;background:#f8fafc;border-bottom:2px solid #cbd5e1}
  @media (max-width: 576px){ .table-responsive{border:0} }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
  <div><h5 class="m-0">লাভ-ক্ষতি (Profit &amp; Loss)</h5><div class="text-muted small" id="plLabel"><?php echo h($range['label']); ?> · <?php echo $basis === 'delivered' ? 'শুধু Delivered অর্ডার' : 'বাতিল বাদে সব অর্ডার'; ?></div></div>
  <a class="btn btn-outline-secondary btn-sm" id="plCsv" href="report_profit.php?<?php echo h(http_build_query(array_merge($keep, ['export' => 'csv']))); ?>"><i class="fa-solid fa-file-csv"></i> CSV</a>
</div>
<?php echo fi_tabs('profit'); ?>
<?php if ($range['error']): ?><div class="alert alert-warning py-2" id="plError"><?php echo h($range['error']); ?></div><?php endif; ?>

<form method="get" class="card card-body mb-3" id="plForm">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-2"><label class="form-label small mb-1">সময়</label>
      <select name="type" id="plType" class="form-select">
        <option value="today" <?php echo $range['type']==='today'?'selected':''; ?>>আজ</option>
        <option value="monthly" <?php echo $range['type']==='monthly'?'selected':''; ?>>মাসিক</option>
        <option value="yearly" <?php echo $range['type']==='yearly'?'selected':''; ?>>বার্ষিক</option>
        <option value="custom" <?php echo $range['type']==='custom'?'selected':''; ?>>কাস্টম</option>
      </select></div>
    <div class="col-6 col-md-2 pl-f pl-monthly pl-yearly"><label class="form-label small mb-1">সাল</label><select name="year" class="form-select"><?php foreach ($years as $y): ?><option value="<?php echo $y; ?>" <?php echo $range['year']===$y?'selected':''; ?>><?php echo $y; ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2 pl-f pl-monthly"><label class="form-label small mb-1">মাস</label><select name="month" class="form-select"><?php foreach ($monthNames as $n => $nm): ?><option value="<?php echo $n; ?>" <?php echo $range['month']===$n?'selected':''; ?>><?php echo h($nm); ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2 pl-f pl-custom"><label class="form-label small mb-1">শুরু</label><input type="date" name="from" class="form-control" value="<?php echo h($range['from']); ?>"></div>
    <div class="col-6 col-md-2 pl-f pl-custom"><label class="form-label small mb-1">শেষ</label><input type="date" name="to" class="form-control" value="<?php echo h($range['to']); ?>"></div>
    <div class="col-6 col-md-3"><label class="form-label small mb-1">কোন অর্ডার ধরব</label>
      <select name="basis" id="plBasis" class="form-select"><option value="all" <?php echo $basis==='all'?'selected':''; ?>>বাতিল বাদে সব</option><option value="delivered" <?php echo $basis==='delivered'?'selected':''; ?>>শুধু Delivered</option></select></div>
    <div class="col-6 col-md-1 d-grid"><button class="btn btn-primary">দেখুন</button></div>
  </div>
</form>

<div class="row g-3 mb-3" id="plKpis">
  <div class="col-6 col-xl-3"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">রাজস্ব</div><div class="kpi-value" id="kRevenue"><?php echo $money($p['revenue']); ?></div><div class="small text-muted"><?php echo $p['orders']; ?>টি অর্ডার</div></div></div></div>
  <div class="col-6 col-xl-3"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">মোট লাভ (রাজস্ব − COGS)</div><div class="kpi-value" id="kGross"><?php echo $money($p['gross']); ?></div></div></div></div>
  <div class="col-6 col-xl-3"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">খরচ</div><div class="kpi-value" id="kExpenses"><?php echo $money($p['expenses']); ?></div><div class="small text-muted"><?php echo $p['expense_count']; ?>টি এন্ট্রি</div></div></div></div>
  <div class="col-6 col-xl-3"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">নিট লাভ / (ক্ষতি)</div><div class="kpi-value <?php echo $netClass; ?>" id="kNet"><?php echo $money($p['net']); ?></div></div></div></div>
</div>

<?php if ($p['unknown_lines'] > 0): ?>
<div class="alert alert-warning" id="plUnknown">
  <strong><i class="fa-solid fa-triangle-exclamation"></i> ক্রয়মূল্য অজানা:</strong>
  <span id="plUnknownLines"><?php echo (int)$p['unknown_lines']; ?></span>টি অর্ডার-আইটেমের (বিক্রি <span id="plUnknownSales"><?php echo $money($p['unknown_sales']); ?></span>) ক্রয়মূল্য জানা নেই। এগুলোর খরচ COGS-এ ধরা হয়নি, তাই <strong>মোট ও নিট লাভ আসলের চেয়ে বেশি দেখাচ্ছে</strong>।
  ক্রয়মূল্য-জানা আইটেমে মার্জিন (ডিসকাউন্ট ও ডেলিভারি আগে): <strong id="plKnownMargin"><?php echo $money($p['known_margin']); ?></strong> (বিক্রি <?php echo $money($p['known_sales']); ?> − ক্রয়মূল্য <?php echo $money($p['cogs']); ?>)।
  পণ্যে ক্রয়মূল্য দিলে বা ক্রয় এন্ট্রি করলে নতুন অর্ডারের হিসাব সঠিক হবে।
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-12 col-lg-6">
    <div class="card h-100"><div class="card-body">
      <h6 class="mb-2">আয়-ব্যয় বিবরণী</h6>
      <div id="plStatement">
        <div class="st-row sub"><span>বিক্রয় (ডিসকাউন্টের পর)</span><span id="sNetSales"><?php echo $money2($p['net_sales']); ?></span></div>
        <div class="st-row sub"><span>ডেলিভারি চার্জ আদায়</span><span id="sDelivery"><?php echo $money2($p['delivery']); ?></span></div>
        <div class="st-row total"><span>রাজস্ব</span><span id="sRevenue"><?php echo $money2($p['revenue']); ?></span></div>
        <div class="st-row sub"><span>− বিক্রিত পণ্যের ক্রয়মূল্য (COGS)</span><span id="sCogs"><?php echo $money2($p['cogs']); ?></span></div>
        <div class="st-row total"><span>মোট লাভ</span><span id="sGross"><?php echo $money2($p['gross']); ?></span></div>
        <div class="st-row sub"><span>− খরচ (<?php echo $p['expense_count']; ?>টি)</span><span id="sExpenses"><?php echo $money2($p['expenses']); ?></span></div>
        <div class="st-row total"><span>নিট লাভ / (ক্ষতি)</span><span id="sNet" class="<?php echo $netClass; ?>"><?php echo $money2($p['net']); ?></span></div>
      </div>
      <div class="small text-muted mt-3">ক্রয় (মাল কেনা) সরাসরি খরচ নয় — মাল বিক্রি হলে তার ক্রয়মূল্য COGS হয়ে আসে। ডিসকাউন্ট বিক্রয় থেকে আগেই বাদ। কুরিয়ারকে দেওয়া চার্জ "খরচ"-এ এন্ট্রি করলে তবেই এখানে আসবে।</div>
    </div></div>
  </div>
  <div class="col-12 col-lg-6">
    <div class="card h-100"><div class="card-body p-0">
      <div class="p-3 pb-2"><h6 class="m-0">ক্যাটেগরি অনুযায়ী মার্জিন</h6><div class="small text-muted">শুধু ক্রয়মূল্য-জানা আইটেম; ডিসকাউন্ট ও ডেলিভারি আগে</div></div>
      <div class="table-responsive"><table class="table table-striped mb-0" id="plCats">
        <thead class="table-light"><tr><th>ক্যাটেগরি</th><th class="text-end">পরিমাণ</th><th class="text-end">বিক্রি</th><th class="text-end">ক্রয়মূল্য</th><th class="text-end">মার্জিন</th></tr></thead>
        <tbody><?php foreach ($cats as $c): ?><tr><td><?php echo h($c['name']); ?></td><td class="text-end"><?php echo $c['qty']; ?></td><td class="text-end"><?php echo $money($c['sales']); ?></td><td class="text-end"><?php echo $money($c['cost']); ?></td><td class="text-end fw-semibold <?php echo $c['profit'] < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo $money($c['profit']); ?></td></tr><?php endforeach; ?>
        <?php if (!$cats): ?><tr><td colspan="5" class="text-center text-muted py-3">ক্রয়মূল্য-জানা কোনো বিক্রি নেই</td></tr><?php endif; ?></tbody>
      </table></div>
    </div></div>
  </div>
</div>

<?php if (count($months) > 1): ?>
<div class="card mb-3"><div class="card-body p-0">
  <div class="p-3 pb-2"><h6 class="m-0">মাস অনুযায়ী</h6></div>
  <div class="table-responsive"><table class="table table-striped mb-0" id="plMonths">
    <thead class="table-light"><tr><th>মাস</th><th class="text-end">অর্ডার</th><th class="text-end">রাজস্ব</th><th class="text-end">COGS</th><th class="text-end">খরচ</th><th class="text-end">নিট লাভ</th></tr></thead>
    <tbody><?php foreach ($months as $m): ?><tr data-key="<?php echo h($m['key']); ?>"><td><?php echo h($m['label']); ?></td><td class="text-end"><?php echo $m['orders']; ?></td><td class="text-end"><?php echo $money($m['revenue']); ?></td><td class="text-end"><?php echo $money($m['cogs']); ?></td><td class="text-end"><?php echo $money($m['expenses']); ?></td><td class="text-end fw-semibold <?php echo $m['profit'] > 0 ? 'text-success' : ($m['profit'] < 0 ? 'text-danger' : ''); ?>"><?php echo $money($m['profit']); ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
</div></div>
<?php endif; ?>

<div class="card"><div class="card-body p-0">
  <div class="p-3 pb-2"><h6 class="m-0">সবচেয়ে বেশি লাভের পণ্য (শীর্ষ ১০)</h6><div class="small text-muted">শুধু ক্রয়মূল্য-জানা আইটেম; ডিসকাউন্ট ও ডেলিভারি আগে</div></div>
  <div class="table-responsive"><table class="table table-striped mb-0" id="plProducts">
    <thead class="table-light"><tr><th>পণ্য</th><th class="text-end">পরিমাণ</th><th class="text-end">বিক্রি</th><th class="text-end">ক্রয়মূল্য</th><th class="text-end">মার্জিন</th></tr></thead>
    <tbody><?php foreach ($prods as $r): ?><tr><td><?php echo h($r['name']); ?></td><td class="text-end"><?php echo $r['qty']; ?></td><td class="text-end"><?php echo $money($r['sales']); ?></td><td class="text-end"><?php echo $money($r['cost']); ?></td><td class="text-end fw-semibold <?php echo $r['profit'] < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo $money($r['profit']); ?></td></tr><?php endforeach; ?>
    <?php if (!$prods): ?><tr><td colspan="5" class="text-center text-muted py-3">ক্রয়মূল্য-জানা কোনো বিক্রি নেই</td></tr><?php endif; ?></tbody>
  </table></div>
</div></div>

<script>
(function(){
  const type=document.getElementById('plType');
  function sync(){ document.querySelectorAll('.pl-f').forEach(el=>{ el.style.display = el.classList.contains('pl-'+type.value) ? '' : 'none'; }); }
  type.addEventListener('change',sync); sync();
})();
</script>
<?php require __DIR__.'/footer.php'; ?>
