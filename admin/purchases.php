<?php
// admin/purchases.php — ক্রয়ের তালিকা: ফিল্টার (সময়, সরবরাহকারী, অবস্থা, খোঁজা), বাকির সারাংশ
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/purchases.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_purchases');

$pdo = get_pdo();
if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money_bd')) { function money_bd($n){ $n=(float)$n; $n=round($n); return '৳'.number_format($n,0,'',','); } }

$range = rp_parse_range($_GET);
$allTime = is_string($_GET['type'] ?? null) && $_GET['type'] === 'all';
if ($allTime) $range = null;
$sid = is_string($_GET['supplier'] ?? null) && ctype_digit($_GET['supplier']) ? (int)$_GET['supplier'] : 0;
$status = is_string($_GET['status'] ?? null) && in_array($_GET['status'], ['active', 'due', 'paid', 'cancelled'], true) ? $_GET['status'] : 'all';
$q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
if (mb_strlen($q) > 60) $q = mb_substr($q, 0, 60);
$page = is_string($_GET['page'] ?? null) && ctype_digit($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$PER = 25;

$keep = $allTime ? ['type' => 'all'] : rp_keep_params($range, 'all');
if ($sid) $keep['supplier'] = $sid;
if ($status !== 'all') $keep['status'] = $status;
if ($q !== '') $keep['q'] = $q;

$suppliers = pu_suppliers_with_dues($pdo);
$sel = $range ?? ['from' => '2000-01-01', 'to' => '2100-12-31'];
$tot = pu_totals($pdo, $range, $sid ?: null);
$global = pu_totals($pdo, null, null);
$total = pu_count($pdo, $range, $sid ?: null, $status, $q);
$pages = max(1, (int)ceil($total / $PER));
if ($page > $pages) $page = $pages;
$rows = pu_list($pdo, $range, $sid ?: null, $status, $q, $page, $PER);
$cur = rp_parse_range($allTime ? ['type' => 'monthly'] : $_GET);
$years = range((int)date('Y'), (int)date('Y') - 5);
if (!in_array($cur['year'], $years, true)) array_unshift($years, $cur['year']);
$months = [1=>'জানুয়ারি',2=>'ফেব্রুয়ারি',3=>'মার্চ',4=>'এপ্রিল',5=>'মে',6=>'জুন',7=>'জুলাই',8=>'আগস্ট',9=>'সেপ্টেম্বর',10=>'অক্টোবর',11=>'নভেম্বর',12=>'ডিসেম্বর'];
$typeSel = $allTime ? 'all' : $cur['type'];

require_once __DIR__.'/header.php';
?>
<style>
  .kpi-card{border:1px solid #e5e7eb;border-radius:14px}
  .kpi-value{font-weight:800;font-size:1.25rem}
  @media (max-width: 576px){
    .table-responsive{border:0}
    table.pu-table{display:block}
    table.pu-table thead{display:none}
    table.pu-table tbody{display:block}
    table.pu-table tr{display:block;background:#fff;border:1px solid #e9ecef;border-radius:12px;padding:12px;margin-bottom:10px;box-shadow:0 6px 16px rgba(2,8,23,.04)}
    table.pu-table td{display:flex;justify-content:space-between;gap:12px;padding:6px 0!important;border:0!important}
    table.pu-table td::before{content:attr(data-label);font-weight:600;color:#64748b;min-width:90px}
  }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div><h5 class="m-0">ক্রয়</h5><div class="text-muted small" id="puLabel"><?php echo $allTime ? 'সব সময়' : h($cur['label']); ?></div></div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="suppliers.php"><i class="fa-solid fa-truck-ramp-box"></i> সরবরাহকারী</a>
    <a class="btn btn-primary btn-sm" href="purchase_form.php"><i class="fa-solid fa-plus"></i> নতুন ক্রয়</a>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): $fl = $_SESSION['flash']; unset($_SESSION['flash']); ?>
  <div class="alert <?php echo !empty($fl['ok']) ? 'alert-success' : 'alert-danger'; ?> py-2" id="puFlash"><?php echo h($fl['msg'] ?? ''); ?></div>
<?php endif; ?>

<div class="row g-3 mb-3" id="puKpis">
  <div class="col-6 col-xl-3"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">ফিল্টারের মোট ক্রয়</div><div class="kpi-value" id="kTotal"><?php echo money_bd($tot['total']); ?></div><div class="small text-muted"><?php echo $tot['count']; ?>টি ক্রয় (বাতিল বাদে)</div></div></div></div>
  <div class="col-6 col-xl-3"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">ফিল্টারের পরিশোধ</div><div class="kpi-value" id="kPaid"><?php echo money_bd($tot['paid']); ?></div></div></div></div>
  <div class="col-6 col-xl-3"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">ফিল্টারের বাকি</div><div class="kpi-value text-danger" id="kDue"><?php echo money_bd($tot['due']); ?></div></div></div></div>
  <div class="col-6 col-xl-3"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">সব মিলিয়ে মোট বাকি (দায়)</div><div class="kpi-value" id="kGlobalDue"><?php echo money_bd($global['due']); ?></div></div></div></div>
</div>

<form method="get" class="card card-body mb-3" id="puFilter">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-2"><label class="form-label small mb-1">সময়</label>
      <select name="type" id="puType" class="form-select">
        <option value="all" <?php echo $typeSel==='all'?'selected':''; ?>>সব সময়</option>
        <option value="today" <?php echo $typeSel==='today'?'selected':''; ?>>আজ</option>
        <option value="monthly" <?php echo $typeSel==='monthly'?'selected':''; ?>>মাসিক</option>
        <option value="yearly" <?php echo $typeSel==='yearly'?'selected':''; ?>>বার্ষিক</option>
        <option value="custom" <?php echo $typeSel==='custom'?'selected':''; ?>>কাস্টম</option>
      </select></div>
    <div class="col-6 col-md-2 pu-f pu-monthly pu-yearly"><label class="form-label small mb-1">সাল</label><select name="year" class="form-select"><?php foreach ($years as $y): ?><option value="<?php echo $y; ?>" <?php echo $cur['year']===$y?'selected':''; ?>><?php echo $y; ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2 pu-f pu-monthly"><label class="form-label small mb-1">মাস</label><select name="month" class="form-select"><?php foreach ($months as $n => $nm): ?><option value="<?php echo $n; ?>" <?php echo $cur['month']===$n?'selected':''; ?>><?php echo h($nm); ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2 pu-f pu-custom"><label class="form-label small mb-1">শুরু</label><input type="date" name="from" class="form-control" value="<?php echo h($cur['from']); ?>"></div>
    <div class="col-6 col-md-2 pu-f pu-custom"><label class="form-label small mb-1">শেষ</label><input type="date" name="to" class="form-control" value="<?php echo h($cur['to']); ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">সরবরাহকারী</label>
      <select name="supplier" class="form-select"><option value="0">সব</option><?php foreach ($suppliers as $s): ?><option value="<?php echo (int)$s['id']; ?>" <?php echo $sid===(int)$s['id']?'selected':''; ?>><?php echo h($s['name']); ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">অবস্থা</label>
      <select name="status" class="form-select">
        <option value="all" <?php echo $status==='all'?'selected':''; ?>>সব</option>
        <option value="active" <?php echo $status==='active'?'selected':''; ?>>সক্রিয়</option>
        <option value="due" <?php echo $status==='due'?'selected':''; ?>>বাকি আছে</option>
        <option value="paid" <?php echo $status==='paid'?'selected':''; ?>>পুরো শোধ</option>
        <option value="cancelled" <?php echo $status==='cancelled'?'selected':''; ?>>বাতিল</option>
      </select></div>
    <div class="col-8 col-md-3"><label class="form-label small mb-1">ইনভয়েস / সরবরাহকারীর নাম</label><input name="q" maxlength="60" class="form-control" value="<?php echo h($q); ?>"></div>
    <div class="col-4 col-md-2 d-grid"><button class="btn btn-outline-primary">দেখুন</button></div>
  </div>
</form>

<div class="card"><div class="card-body p-0">
  <div class="d-flex justify-content-between align-items-center p-3 pb-2"><h6 class="m-0">ক্রয়ের তালিকা</h6><span class="small text-muted" id="puCount"><?php echo $total; ?>টি<?php echo $total ? ' — পৃষ্ঠা ' . $page . '/' . $pages : ''; ?></span></div>
  <div class="table-responsive">
    <table class="table table-striped align-middle mb-0 pu-table" id="puTable">
      <thead class="table-light"><tr><th>তারিখ</th><th>ইনভয়েস</th><th>সরবরাহকারী</th><th class="text-center">আইটেম</th><th class="text-end">মোট</th><th class="text-end">পরিশোধ</th><th class="text-end">বাকি</th><th>অবস্থা</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $cancelled = $r['status'] === 'cancelled'; ?>
        <tr data-id="<?php echo (int)$r['id']; ?>" class="<?php echo $cancelled ? 'text-muted' : ''; ?>">
          <td data-label="তারিখ"><?php echo h(date('d M Y', strtotime($r['purchase_date']))); ?></td>
          <td data-label="ইনভয়েস"><?php echo h($r['invoice_no'] ?? '—'); ?></td>
          <td data-label="সরবরাহকারী"><?php echo h($r['supplier_name'] ?? '—'); ?></td>
          <td data-label="আইটেম" class="text-center"><?php echo (int)$r['item_count']; ?></td>
          <td data-label="মোট" class="text-end"><?php echo money_bd($r['total']); ?></td>
          <td data-label="পরিশোধ" class="text-end"><?php echo money_bd($r['paid']); ?></td>
          <td data-label="বাকি" class="text-end fw-semibold <?php echo (!$cancelled && $r['due'] > 0) ? 'text-danger' : ''; ?>"><?php echo $cancelled ? '—' : money_bd($r['due']); ?></td>
          <td data-label="অবস্থা"><?php if ($cancelled): ?><span class="badge bg-secondary">বাতিল</span><?php elseif ($r['due'] > 0): ?><span class="badge bg-warning text-dark">বাকি</span><?php else: ?><span class="badge bg-success">শোধ</span><?php endif; ?></td>
          <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="purchase_view.php?id=<?php echo (int)$r['id']; ?>">View</a></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">কোনো ক্রয় পাওয়া যায়নি</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?><div class="card-footer"><?php render_pagination($page, $pages, $keep); ?></div><?php endif; ?>
</div></div>

<script>
(function(){
  const type=document.getElementById('puType');
  function sync(){ document.querySelectorAll('.pu-f').forEach(el=>{ el.style.display = el.classList.contains('pu-'+type.value) ? '' : 'none'; }); }
  type.addEventListener('change',sync); sync();
})();
</script>
<?php require __DIR__.'/footer.php'; ?>
