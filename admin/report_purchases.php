<?php
// admin/report_purchases.php — ক্রয় রিপোর্ট: মোট ক্রয়, পরিশোধ, বাকি; সরবরাহকারী ও পণ্য অনুযায়ী; তালিকা ও CSV
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/finance.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('view_reports');

$pdo = get_pdo();
if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
$money = fn($n) => '৳' . number_format(round((float)$n), 0, '', ',');

$range = rp_parse_range($_GET);
$sid = is_string($_GET['supplier'] ?? null) && ctype_digit($_GET['supplier']) ? (int)$_GET['supplier'] : 0;
$status = is_string($_GET['status'] ?? null) && in_array($_GET['status'], ['active', 'due', 'paid', 'cancelled'], true) ? $_GET['status'] : 'active';
$page = is_string($_GET['page'] ?? null) && ctype_digit($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$PER = 25;
$keep = rp_keep_params($range, 'all'); if ($sid) $keep['supplier'] = $sid; if ($status !== 'active') $keep['status'] = $status;

if (($_GET['export'] ?? '') === 'csv') {
  $all = pu_list($pdo, $range, $sid ?: null, $status, null, 1, 200);
  for ($pg = 2; count($all) === ($pg - 1) * 200; $pg++) { $next = pu_list($pdo, $range, $sid ?: null, $status, null, $pg, 200); if (!$next) break; $all = array_merge($all, $next); }
  while (ob_get_level() > 0) @ob_end_clean();
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="purchase_report_' . $range['from'] . '_' . $range['to'] . '.csv"');
  header('Cache-Control: no-store');
  $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
  fputcsv($o, ['তারিখ', 'ইনভয়েস', 'সরবরাহকারী', 'আইটেম', 'মোট', 'পরিশোধ', 'বাকি', 'অবস্থা']);
  foreach ($all as $r) fputcsv($o, [$r['purchase_date'], rp_csv_safe($r['invoice_no']), rp_csv_safe($r['supplier_name']), $r['item_count'], $r['total'], $r['paid'], $r['status'] === 'cancelled' ? '' : $r['due'], $r['status'] === 'cancelled' ? 'বাতিল' : ($r['due'] > 0 ? 'বাকি' : 'শোধ')]);
  fclose($o); exit;
}

$tot = pu_totals($pdo, $range, $sid ?: null);
$global = pu_totals($pdo, null, null);
$payIn = fi_purchase_payments_in($pdo, $range);
$bySup = $sid ? [] : fi_purchases_by_supplier($pdo, $range);
$byProd = fi_purchases_by_product($pdo, $range, 10);
$suppliers = pu_suppliers_with_dues($pdo);
$total = pu_count($pdo, $range, $sid ?: null, $status, null);
$pages = max(1, (int)ceil($total / $PER));
if ($page > $pages) $page = $pages;
$rows = pu_list($pdo, $range, $sid ?: null, $status, null, $page, $PER);
$years = range((int)date('Y'), (int)date('Y') - 5);
if (!in_array($range['year'], $years, true)) array_unshift($years, $range['year']);
$monthNames = [1=>'জানুয়ারি',2=>'ফেব্রুয়ারি',3=>'মার্চ',4=>'এপ্রিল',5=>'মে',6=>'জুন',7=>'জুলাই',8=>'আগস্ট',9=>'সেপ্টেম্বর',10=>'অক্টোবর',11=>'নভেম্বর',12=>'ডিসেম্বর'];

require_once __DIR__.'/header.php';
?>
<style>
  .kpi-card{border:1px solid #e5e7eb;border-radius:14px}
  .kpi-value{font-weight:800;font-size:1.25rem}
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
  <div><h5 class="m-0">ক্রয় রিপোর্ট</h5><div class="text-muted small" id="prLabel"><?php echo h($range['label']); ?></div></div>
  <a class="btn btn-outline-secondary btn-sm" id="prCsv" href="report_purchases.php?<?php echo h(http_build_query(array_merge($keep, ['export' => 'csv']))); ?>"><i class="fa-solid fa-file-csv"></i> CSV</a>
</div>
<?php echo fi_tabs('purchases'); ?>
<?php if ($range['error']): ?><div class="alert alert-warning py-2" id="prError"><?php echo h($range['error']); ?></div><?php endif; ?>

<form method="get" class="card card-body mb-3" id="prForm">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-2"><label class="form-label small mb-1">সময়</label>
      <select name="type" id="prType" class="form-select">
        <option value="today" <?php echo $range['type']==='today'?'selected':''; ?>>আজ</option>
        <option value="monthly" <?php echo $range['type']==='monthly'?'selected':''; ?>>মাসিক</option>
        <option value="yearly" <?php echo $range['type']==='yearly'?'selected':''; ?>>বার্ষিক</option>
        <option value="custom" <?php echo $range['type']==='custom'?'selected':''; ?>>কাস্টম</option>
      </select></div>
    <div class="col-6 col-md-2 pr-f pr-monthly pr-yearly"><label class="form-label small mb-1">সাল</label><select name="year" class="form-select"><?php foreach ($years as $y): ?><option value="<?php echo $y; ?>" <?php echo $range['year']===$y?'selected':''; ?>><?php echo $y; ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2 pr-f pr-monthly"><label class="form-label small mb-1">মাস</label><select name="month" class="form-select"><?php foreach ($monthNames as $n => $nm): ?><option value="<?php echo $n; ?>" <?php echo $range['month']===$n?'selected':''; ?>><?php echo h($nm); ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2 pr-f pr-custom"><label class="form-label small mb-1">শুরু</label><input type="date" name="from" class="form-control" value="<?php echo h($range['from']); ?>"></div>
    <div class="col-6 col-md-2 pr-f pr-custom"><label class="form-label small mb-1">শেষ</label><input type="date" name="to" class="form-control" value="<?php echo h($range['to']); ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">সরবরাহকারী</label>
      <select name="supplier" class="form-select"><option value="0">সব</option><?php foreach ($suppliers as $s): ?><option value="<?php echo (int)$s['id']; ?>" <?php echo $sid===(int)$s['id']?'selected':''; ?>><?php echo h($s['name']); ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">তালিকায়</label>
      <select name="status" class="form-select"><option value="active" <?php echo $status==='active'?'selected':''; ?>>সক্রিয় (বাতিল বাদে)</option><option value="due" <?php echo $status==='due'?'selected':''; ?>>বাকি আছে</option><option value="paid" <?php echo $status==='paid'?'selected':''; ?>>পুরো শোধ</option><option value="cancelled" <?php echo $status==='cancelled'?'selected':''; ?>>বাতিল</option></select></div>
    <div class="col-6 col-md-2 d-grid"><button class="btn btn-primary">দেখুন</button></div>
  </div>
</form>

<div class="row g-3 mb-3" id="prKpis">
  <div class="col-6 col-xl-3"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">মোট ক্রয়</div><div class="kpi-value" id="kTotal"><?php echo $money($tot['total']); ?></div><div class="small text-muted"><?php echo $tot['count']; ?>টি ক্রয় (বাতিল বাদে)</div></div></div></div>
  <div class="col-6 col-xl-3"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">এই ক্রয়গুলোর পরিশোধ</div><div class="kpi-value" id="kPaid"><?php echo $money($tot['paid']); ?></div></div></div></div>
  <div class="col-6 col-xl-3"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">এই ক্রয়গুলোর বাকি</div><div class="kpi-value text-danger" id="kDue"><?php echo $money($tot['due']); ?></div></div></div></div>
  <div class="col-6 col-xl-3"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">এই সময়ে সরবরাহকারীকে দেওয়া টাকা</div><div class="kpi-value" id="kPaidIn"><?php echo $money($payIn['amount']); ?></div><div class="small text-muted"><?php echo $payIn['count']; ?>টি পরিশোধ · সব মিলিয়ে দায় <?php echo $money($global['due']); ?></div></div></div></div>
</div>

<div class="row g-3 mb-3">
  <?php if (!$sid): ?>
  <div class="col-12 col-lg-6">
    <div class="card h-100"><div class="card-body p-0">
      <div class="p-3 pb-2"><h6 class="m-0">সরবরাহকারী অনুযায়ী</h6></div>
      <div class="table-responsive"><table class="table table-striped mb-0" id="prSuppliers">
        <thead class="table-light"><tr><th>সরবরাহকারী</th><th class="text-end">ক্রয়</th><th class="text-end">মোট</th><th class="text-end">পরিশোধ</th><th class="text-end">বাকি</th></tr></thead>
        <tbody><?php foreach ($bySup as $r): ?><tr><td><?php echo h($r['name']); ?></td><td class="text-end"><?php echo $r['count']; ?></td><td class="text-end"><?php echo $money($r['total']); ?></td><td class="text-end"><?php echo $money($r['paid']); ?></td><td class="text-end fw-semibold <?php echo $r['due'] > 0 ? 'text-danger' : ''; ?>"><?php echo $money($r['due']); ?></td></tr><?php endforeach; ?>
        <?php if (!$bySup): ?><tr><td colspan="5" class="text-center text-muted py-3">এই সময়ে কোনো ক্রয় নেই</td></tr><?php endif; ?></tbody>
      </table></div>
    </div></div>
  </div>
  <?php endif; ?>
  <div class="col-12 <?php echo $sid ? '' : 'col-lg-6'; ?>">
    <div class="card h-100"><div class="card-body p-0">
      <div class="p-3 pb-2"><h6 class="m-0">সবচেয়ে বেশি কেনা পণ্য (শীর্ষ ১০)</h6></div>
      <div class="table-responsive"><table class="table table-striped mb-0" id="prProducts">
        <thead class="table-light"><tr><th>পণ্য</th><th class="text-end">পরিমাণ</th><th class="text-end">মোট টাকা</th><th class="text-end">গড় দাম</th></tr></thead>
        <tbody><?php foreach ($byProd as $r): ?><tr><td><?php echo h($r['name']); ?></td><td class="text-end"><?php echo $r['qty']; ?></td><td class="text-end"><?php echo $money($r['amount']); ?></td><td class="text-end">৳<?php echo number_format($r['avg'], 2); ?></td></tr><?php endforeach; ?>
        <?php if (!$byProd): ?><tr><td colspan="4" class="text-center text-muted py-3">এই সময়ে কোনো ক্রয় নেই</td></tr><?php endif; ?></tbody>
      </table></div>
    </div></div>
  </div>
</div>

<div class="card"><div class="card-body p-0">
  <div class="d-flex justify-content-between align-items-center p-3 pb-2"><h6 class="m-0">ক্রয়ের তালিকা</h6><span class="small text-muted" id="prCount"><?php echo $total; ?>টি<?php echo $total ? ' — পৃষ্ঠা ' . $page . '/' . $pages : ''; ?></span></div>
  <div class="table-responsive"><table class="table table-striped align-middle mb-0" id="prTable">
    <thead class="table-light"><tr><th>তারিখ</th><th>ইনভয়েস</th><th>সরবরাহকারী</th><th class="text-end">মোট</th><th class="text-end">পরিশোধ</th><th class="text-end">বাকি</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $c = $r['status'] === 'cancelled'; ?>
      <tr data-id="<?php echo (int)$r['id']; ?>" class="<?php echo $c ? 'text-muted' : ''; ?>"><td><?php echo h(date('d M Y', strtotime($r['purchase_date']))); ?></td><td><?php echo h($r['invoice_no'] ?? '—'); ?></td><td><?php echo h($r['supplier_name'] ?? '—'); ?></td><td class="text-end"><?php echo $money($r['total']); ?></td><td class="text-end"><?php echo $money($r['paid']); ?></td><td class="text-end fw-semibold <?php echo (!$c && $r['due'] > 0) ? 'text-danger' : ''; ?>"><?php echo $c ? 'বাতিল' : $money($r['due']); ?></td><td class="text-end"><a class="btn btn-sm btn-outline-primary" href="purchase_view.php?id=<?php echo (int)$r['id']; ?>">View</a></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">কোনো ক্রয় পাওয়া যায়নি</td></tr><?php endif; ?>
    </tbody></table></div>
  <?php if ($pages > 1): ?><div class="card-footer"><?php render_pagination($page, $pages, $keep); ?></div><?php endif; ?>
</div></div>
<script>
(function(){
  const type=document.getElementById('prType');
  function sync(){ document.querySelectorAll('.pr-f').forEach(el=>{ el.style.display = el.classList.contains('pr-'+type.value) ? '' : 'none'; }); }
  type.addEventListener('change',sync); sync();
})();
</script>
<?php require __DIR__.'/footer.php'; ?>
