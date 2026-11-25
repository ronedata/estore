<?php
// admin/expenses.php — খরচ: যোগ, সম্পাদনা, মোছা, ফিল্টার, CSV
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/expenses.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_expenses');

$pdo = get_pdo();
if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money_bd')) { function money_bd($n){ $n=(float)$n; $n=round($n); return '৳'.number_format($n,0,'',','); } }

$flashSet = function (bool $ok, string $msg) { $_SESSION['flash'] = ['ok' => $ok, 'msg' => $msg]; };
$formVals = null; $formErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
  if (!csrf_verify()) {
    $flashSet(false, 'সিকিউরিটি টোকেন মিলেনি');
    header('Location: expenses.php'); exit;
  }
  $postId = is_string($_POST['id'] ?? null) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : 0;

  if ($action === 'add' || $action === 'update') {
    [$clean, $errs] = ex_clean($_POST);
    if ($clean === null) {
      $formVals = $_POST; $formErrors = $errs;
      $_GET['edit'] = $action === 'update' ? (string)$postId : null;
    } elseif ($action === 'add') {
      ex_add($pdo, $clean, (string)($_SESSION['admin_username'] ?? ''));
      $flashSet(true, 'খরচ যোগ হয়েছে।');
      header('Location: expenses.php'); exit;
    } else {
      if ($postId > 0 && ex_get($pdo, $postId) && ex_update($pdo, $postId, $clean)) $flashSet(true, 'খরচ আপডেট হয়েছে।');
      else $flashSet(false, 'খরচটি পাওয়া যায়নি।');
      header('Location: expenses.php'); exit;
    }
  } elseif ($action === 'delete') {
    $flashSet($postId > 0 && ex_delete($pdo, $postId), '');
    $_SESSION['flash']['msg'] = $_SESSION['flash']['ok'] ? 'খরচ মুছে ফেলা হয়েছে।' : 'খরচটি পাওয়া যায়নি।';
    header('Location: expenses.php'); exit;
  } else {
    $flashSet(false, 'অজানা অ্যাকশন।');
    header('Location: expenses.php'); exit;
  }
}

$range = rp_parse_range($_GET);
$cat = null;
if (is_string($_GET['cat'] ?? null) && $_GET['cat'] !== '' && (mb_strlen($_GET['cat']) <= EX_CAT_MAX || $_GET['cat'] === '__none')) $cat = $_GET['cat'];
$page = is_string($_GET['page'] ?? null) && ctype_digit($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$PER = 25;
$keep = rp_keep_params($range, 'all'); if ($cat !== null) $keep['cat'] = $cat;

if (($_GET['export'] ?? '') === 'csv') {
  $rowsAll = ex_list($pdo, $range, $cat, 1, 100000);
  while (ob_get_level() > 0) @ob_end_clean();
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="expenses_' . $range['from'] . '_' . $range['to'] . '.csv"');
  header('Cache-Control: no-store');
  $out = fopen('php://output', 'w');
  fwrite($out, "\xEF\xBB\xBF");
  fputcsv($out, ['তারিখ', 'শিরোনাম', 'ক্যাটেগরি', 'পরিমাণ', 'নোট', 'যোগ করেছেন']);
  foreach ($rowsAll as $r) fputcsv($out, [$r['expense_date'], rp_csv_safe($r['title']), rp_csv_safe($r['category']), $r['amount'], rp_csv_safe($r['note']), rp_csv_safe($r['created_by'])]);
  fputcsv($out, ['মোট', '', '', ex_total($pdo, $range, $cat), '', '']);
  fclose($out);
  exit;
}

$ov = ex_overview($pdo);
$filtTotal = ex_total($pdo, $range, $cat);
$total = ex_count($pdo, $range, $cat);
$pages = max(1, (int)ceil($total / $PER));
if ($page > $pages) $page = $pages;
$rows = ex_list($pdo, $range, $cat, $page, $PER);
$byCat = ex_by_category($pdo, $range);
$cats = ex_categories($pdo);
$years = range((int)date('Y'), (int)date('Y') - 5);
if (!in_array($range['year'], $years, true)) array_unshift($years, $range['year']);
$months = [1=>'জানুয়ারি',2=>'ফেব্রুয়ারি',3=>'মার্চ',4=>'এপ্রিল',5=>'মে',6=>'জুন',7=>'জুলাই',8=>'আগস্ট',9=>'সেপ্টেম্বর',10=>'অক্টোবর',11=>'নভেম্বর',12=>'ডিসেম্বর'];

$editRow = null;
$editId = is_string($_GET['edit'] ?? null) && ctype_digit($_GET['edit']) ? (int)$_GET['edit'] : 0;
if ($editId > 0) $editRow = ex_get($pdo, $editId);
$f = $formVals ?? ($editRow ?: []);
$fv = fn($k, $d = '') => h($f[$k] ?? $d);

require_once __DIR__.'/header.php';
?>
<style>
  .kpi-card{border:1px solid #e5e7eb;border-radius:14px}
  .kpi-value{font-weight:800;font-size:1.25rem}
  .rp-bar{height:8px;border-radius:999px;background:#fee2e2;overflow:hidden}
  .rp-bar>span{display:block;height:100%;background:#dc2626;border-radius:999px}
  @media (max-width: 576px){
    .table-responsive{border:0}
    table.ex-table{display:block}
    table.ex-table thead{display:none}
    table.ex-table tbody{display:block}
    table.ex-table tr{display:block;background:#fff;border:1px solid #e9ecef;border-radius:12px;padding:12px;margin-bottom:10px;box-shadow:0 6px 16px rgba(2,8,23,.04)}
    table.ex-table td{display:flex;justify-content:space-between;gap:12px;padding:6px 0!important;border:0!important}
    table.ex-table td::before{content:attr(data-label);font-weight:600;color:#64748b;min-width:90px}
  }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div><h5 class="m-0">খরচ</h5><div class="text-muted small" id="exLabel"><?php echo h($range['label']); ?></div></div>
  <a class="btn btn-outline-secondary btn-sm" id="exCsv" href="expenses.php?<?php echo h(http_build_query(array_merge($keep, ['export' => 'csv']))); ?>"><i class="fa-solid fa-file-csv"></i> CSV</a>
</div>

<?php if (!empty($_SESSION['flash'])): $fl = $_SESSION['flash']; unset($_SESSION['flash']); ?>
  <div class="alert <?php echo !empty($fl['ok']) ? 'alert-success' : 'alert-danger'; ?> py-2" id="exFlash"><?php echo h($fl['msg'] ?? ''); ?></div>
<?php endif; ?>

<div class="row g-3 mb-3" id="exKpis">
  <div class="col-6 col-xl-3"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">আজকের খরচ</div><div class="kpi-value" id="kToday"><?php echo money_bd($ov['today']); ?></div></div></div></div>
  <div class="col-6 col-xl-3"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">এই মাসের খরচ</div><div class="kpi-value" id="kMonth"><?php echo money_bd($ov['month']); ?></div></div></div></div>
  <div class="col-6 col-xl-3"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">এই বছরের খরচ</div><div class="kpi-value" id="kYear"><?php echo money_bd($ov['year']); ?></div></div></div></div>
  <div class="col-6 col-xl-3"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">ফিল্টারের মোট</div><div class="kpi-value" id="kFiltered"><?php echo money_bd($filtTotal); ?></div><div class="small text-muted"><?php echo $total; ?>টি এন্ট্রি</div></div></div></div>
</div>

<div class="card mb-3" id="exFormCard"><div class="card-body">
  <h6 class="mb-3"><?php echo $editRow || (!empty($formVals) && ($_POST['action'] ?? '') === 'update') ? 'খরচ সম্পাদনা' : 'নতুন খরচ যোগ'; ?></h6>
  <?php if ($formErrors): ?><div class="alert alert-danger py-2" id="exErrors"><?php foreach ($formErrors as $e): ?><div><?php echo h($e); ?></div><?php endforeach; ?></div><?php endif; ?>
  <?php $isUpdate = $editRow || (!empty($formVals) && ($_POST['action'] ?? '') === 'update'); ?>
  <form method="post" id="exForm" autocomplete="off">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="<?php echo $isUpdate ? 'update' : 'add'; ?>">
    <?php if ($isUpdate): ?><input type="hidden" name="id" value="<?php echo (int)($editRow['id'] ?? $editId); ?>"><?php endif; ?>
    <div class="row g-2">
      <div class="col-12 col-md-4"><label class="form-label small mb-1">শিরোনাম *</label><input name="title" class="form-control" maxlength="160" required value="<?php echo $fv('title'); ?>" placeholder="যেমন: দোকান ভাড়া"></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">পরিমাণ (৳) *</label><input type="number" name="amount" class="form-control" min="0.01" step="0.01" required value="<?php echo $fv('amount'); ?>"></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">তারিখ *</label><input type="date" name="expense_date" class="form-control" max="<?php echo date('Y-m-d'); ?>" required value="<?php echo $fv('expense_date', date('Y-m-d')); ?>"></div>
      <div class="col-12 col-md-4"><label class="form-label small mb-1">ক্যাটেগরি</label><input name="category" class="form-control" maxlength="60" list="exCats" value="<?php echo $fv('category'); ?>" placeholder="যেমন: ভাড়া, বেতন, বিজ্ঞাপন"><datalist id="exCats"><?php foreach ($cats as $c): ?><option value="<?php echo h($c); ?>"><?php endforeach; ?></datalist></div>
      <div class="col-12"><label class="form-label small mb-1">নোট</label><input name="note" class="form-control" maxlength="255" value="<?php echo $fv('note'); ?>"></div>
    </div>
    <div class="mt-3 d-flex gap-2">
      <button class="btn btn-primary" id="exSave"><?php echo $isUpdate ? 'আপডেট' : 'খরচ সংরক্ষণ'; ?></button>
      <?php if ($isUpdate): ?><a class="btn btn-outline-secondary" href="expenses.php">বাতিল</a><?php endif; ?>
    </div>
  </form>
</div></div>

<form method="get" class="card card-body mb-3" id="exFilter">
  <div class="row g-2 align-items-end">
    <div class="col-6 col-md-2"><label class="form-label small mb-1">সময়</label>
      <select name="type" id="exType" class="form-select">
        <option value="today" <?php echo $range['type']==='today'?'selected':''; ?>>আজ</option>
        <option value="monthly" <?php echo $range['type']==='monthly'?'selected':''; ?>>মাসিক</option>
        <option value="yearly" <?php echo $range['type']==='yearly'?'selected':''; ?>>বার্ষিক</option>
        <option value="custom" <?php echo $range['type']==='custom'?'selected':''; ?>>কাস্টম</option>
      </select></div>
    <div class="col-6 col-md-2 ex-f ex-monthly ex-yearly"><label class="form-label small mb-1">সাল</label><select name="year" class="form-select"><?php foreach ($years as $y): ?><option value="<?php echo $y; ?>" <?php echo $range['year']===$y?'selected':''; ?>><?php echo $y; ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2 ex-f ex-monthly"><label class="form-label small mb-1">মাস</label><select name="month" class="form-select"><?php foreach ($months as $n => $nm): ?><option value="<?php echo $n; ?>" <?php echo $range['month']===$n?'selected':''; ?>><?php echo h($nm); ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2 ex-f ex-custom"><label class="form-label small mb-1">শুরু</label><input type="date" name="from" class="form-control" value="<?php echo h($range['from']); ?>"></div>
    <div class="col-6 col-md-2 ex-f ex-custom"><label class="form-label small mb-1">শেষ</label><input type="date" name="to" class="form-control" value="<?php echo h($range['to']); ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-1">ক্যাটেগরি</label>
      <select name="cat" class="form-select"><option value="">সব</option><option value="__none" <?php echo $cat==='__none'?'selected':''; ?>>ক্যাটেগরি নেই</option>
        <?php foreach ($cats as $c): ?><option value="<?php echo h($c); ?>" <?php echo $cat===$c?'selected':''; ?>><?php echo h($c); ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2 d-grid"><button class="btn btn-outline-primary">দেখুন</button></div>
  </div>
</form>

<?php if ($byCat): $mx = max(array_column($byCat, 'sum')); ?>
<div class="card mb-3"><div class="card-body">
  <h6 class="mb-3">ক্যাটেগরি অনুযায়ী খরচ</h6>
  <div id="exByCat">
  <?php foreach ($byCat as $c): $pct = $mx > 0 ? max(2, round($c['sum'] / $mx * 100)) : 0; ?>
    <div class="mb-2"><div class="d-flex justify-content-between small"><span><?php echo h($c['name']); ?> <span class="text-muted">(<?php echo $c['count']; ?>টি)</span></span><strong><?php echo money_bd($c['sum']); ?></strong></div><div class="rp-bar"><span style="width:<?php echo $pct; ?>%"></span></div></div>
  <?php endforeach; ?>
  </div>
</div></div>
<?php endif; ?>

<div class="card"><div class="card-body p-0">
  <div class="table-responsive">
    <table class="table table-striped align-middle mb-0 ex-table" id="exTable">
      <thead class="table-light"><tr><th>তারিখ</th><th>শিরোনাম</th><th>ক্যাটেগরি</th><th class="text-end">পরিমাণ</th><th>নোট</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr data-id="<?php echo (int)$r['id']; ?>">
          <td data-label="তারিখ"><?php echo h(date('d M Y', strtotime($r['expense_date']))); ?></td>
          <td data-label="শিরোনাম" class="fw-semibold"><?php echo h($r['title']); ?></td>
          <td data-label="ক্যাটেগরি"><?php echo h($r['category'] ?? '—'); ?></td>
          <td data-label="পরিমাণ" class="text-end"><?php echo money_bd($r['amount']); ?></td>
          <td data-label="নোট" class="small text-muted"><?php echo h($r['note'] ?? ''); ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="expenses.php?<?php echo h(http_build_query(array_merge($keep, ['edit' => (int)$r['id']]))); ?>">Edit</a>
            <button type="button" class="btn btn-sm btn-outline-danger btn-del" data-id="<?php echo (int)$r['id']; ?>" data-title="<?php echo h($r['title']); ?>">Delete</button>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">এই সময়ে কোনো খরচ নেই</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?><div class="card-footer"><?php render_pagination($page, $pages, $keep); ?></div><?php endif; ?>
</div></div>

<div class="modal fade" id="delModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header py-2"><h6 class="modal-title">খরচ মুছুন</h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">"<strong id="delTitle"></strong>" খরচটি মুছে ফেলা হবে। চালিয়ে যাবেন?</div>
    <form method="post" class="modal-footer py-2">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delId" value="">
      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">বাতিল</button>
      <button class="btn btn-danger" id="delConfirm">মুছুন</button>
    </form>
  </div></div>
</div>

<script>
(function(){
  const type = document.getElementById('exType');
  function sync(){ document.querySelectorAll('.ex-f').forEach(el=>{ el.style.display = el.classList.contains('ex-'+type.value) ? '' : 'none'; }); }
  type.addEventListener('change', sync); sync();
  document.querySelectorAll('.btn-del').forEach(b=>b.addEventListener('click', ()=>{
    document.getElementById('delId').value = b.dataset.id;
    document.getElementById('delTitle').textContent = b.dataset.title || '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('delModal')).show();
  }));
})();
</script>
<?php require __DIR__.'/footer.php'; ?>
