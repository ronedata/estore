<?php
// admin/suppliers.php — সরবরাহকারী: যোগ, সম্পাদনা, সক্রিয়/নিষ্ক্রিয়, মোছা (ক্রয় না থাকলে), বাকির সারাংশ
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/purchases.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_purchases');

$pdo = get_pdo();
if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money_bd')) { function money_bd($n){ $n=(float)$n; $n=round($n); return '৳'.number_format($n,0,'',','); } }
$flash = function (bool $ok, string $msg) { $_SESSION['flash'] = ['ok' => $ok, 'msg' => $msg]; };
$formVals = null; $formErrors = []; $editPost = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
  if (!csrf_verify()) { $flash(false, 'সিকিউরিটি টোকেন মিলেনি'); header('Location: suppliers.php'); exit; }
  $pid = is_string($_POST['id'] ?? null) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : 0;
  if ($action === 'add' || $action === 'update') {
    [$clean, $errs] = pu_supplier_clean($_POST);
    if ($clean === null) { $formVals = $_POST; $formErrors = $errs; $editPost = $action === 'update' ? $pid : 0; }
    elseif ($action === 'add') { pu_supplier_add($pdo, $clean); $flash(true, 'সরবরাহকারী যোগ হয়েছে।'); header('Location: suppliers.php'); exit; }
    else { $ok = $pid > 0 && pu_supplier_update($pdo, $pid, $clean); $flash($ok, $ok ? 'সরবরাহকারী আপডেট হয়েছে।' : 'সরবরাহকারী পাওয়া যায়নি।'); header('Location: suppliers.php'); exit; }
  } elseif ($action === 'toggle') {
    $s = $pid > 0 ? pu_supplier_get($pdo, $pid) : null;
    if ($s) { pu_supplier_set_active($pdo, $pid, !(int)$s['active']); $flash(true, (int)$s['active'] ? 'নিষ্ক্রিয় করা হয়েছে।' : 'সক্রিয় করা হয়েছে।'); }
    else $flash(false, 'সরবরাহকারী পাওয়া যায়নি।');
    header('Location: suppliers.php'); exit;
  } elseif ($action === 'delete') {
    [$ok, $msg] = $pid > 0 ? pu_supplier_delete($pdo, $pid) : [false, 'সরবরাহকারী পাওয়া যায়নি।'];
    $flash($ok, $ok ? 'সরবরাহকারী মুছে ফেলা হয়েছে।' : $msg);
    header('Location: suppliers.php'); exit;
  } else { $flash(false, 'অজানা অ্যাকশন।'); header('Location: suppliers.php'); exit; }
}

$q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
if (mb_strlen($q) > 60) $q = mb_substr($q, 0, 60);
$rows = pu_suppliers_with_dues($pdo, $q);
$allRows = $q === '' ? $rows : pu_suppliers_with_dues($pdo);
$totalDue = 0.0; foreach ($allRows as $r) $totalDue += max(0, $r['due']);
$withDue = count(array_filter($allRows, fn($r) => $r['due'] > 0));
$editId = is_string($_GET['edit'] ?? null) && ctype_digit($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editRow = $editId > 0 ? pu_supplier_get($pdo, $editId) : null;
$isUpdate = (bool)$editRow || ($formVals !== null && $editPost > 0);
$f = $formVals ?? ($editRow ?: []);
$fv = fn($k) => h($f[$k] ?? '');

require_once __DIR__.'/header.php';
?>
<style>
  .kpi-card{border:1px solid #e5e7eb;border-radius:14px}
  .kpi-value{font-weight:800;font-size:1.25rem}
  @media (max-width: 576px){
    .table-responsive{border:0}
    table.su-table{display:block}
    table.su-table thead{display:none}
    table.su-table tbody{display:block}
    table.su-table tr{display:block;background:#fff;border:1px solid #e9ecef;border-radius:12px;padding:12px;margin-bottom:10px;box-shadow:0 6px 16px rgba(2,8,23,.04)}
    table.su-table td{display:flex;justify-content:space-between;gap:12px;padding:6px 0!important;border:0!important}
    table.su-table td::before{content:attr(data-label);font-weight:600;color:#64748b;min-width:90px}
  }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h5 class="m-0">সরবরাহকারী</h5>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="purchases.php"><i class="fa-solid fa-cart-flatbed"></i> ক্রয়ের তালিকা</a>
    <a class="btn btn-primary btn-sm" href="purchase_form.php"><i class="fa-solid fa-plus"></i> নতুন ক্রয়</a>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): $fl = $_SESSION['flash']; unset($_SESSION['flash']); ?>
  <div class="alert <?php echo !empty($fl['ok']) ? 'alert-success' : 'alert-danger'; ?> py-2" id="suFlash"><?php echo h($fl['msg'] ?? ''); ?></div>
<?php endif; ?>

<div class="row g-3 mb-3" id="suKpis">
  <div class="col-6 col-md-4"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">সরবরাহকারীদের কাছে মোট বাকি</div><div class="kpi-value" id="kDue"><?php echo money_bd($totalDue); ?></div></div></div></div>
  <div class="col-6 col-md-4"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">বাকি আছে এমন সরবরাহকারী</div><div class="kpi-value" id="kWithDue"><?php echo $withDue; ?></div></div></div></div>
  <div class="col-12 col-md-4"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">মোট সরবরাহকারী</div><div class="kpi-value" id="kCount"><?php echo count($allRows); ?></div></div></div></div>
</div>

<div class="card mb-3" id="suFormCard"><div class="card-body">
  <h6 class="mb-3"><?php echo $isUpdate ? 'সরবরাহকারী সম্পাদনা' : 'নতুন সরবরাহকারী'; ?></h6>
  <?php if ($formErrors): ?><div class="alert alert-danger py-2" id="suErrors"><?php foreach ($formErrors as $e): ?><div><?php echo h($e); ?></div><?php endforeach; ?></div><?php endif; ?>
  <form method="post" id="suForm" autocomplete="off">
    <?php echo csrf_field(); ?>
    <input type="hidden" name="action" value="<?php echo $isUpdate ? 'update' : 'add'; ?>">
    <?php if ($isUpdate): ?><input type="hidden" name="id" value="<?php echo (int)($editRow['id'] ?? $editPost); ?>"><?php endif; ?>
    <div class="row g-2">
      <div class="col-12 col-md-4"><label class="form-label small mb-1">নাম *</label><input name="name" class="form-control" maxlength="120" required value="<?php echo $fv('name'); ?>"></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">ফোন</label><input name="phone" class="form-control" maxlength="30" value="<?php echo $fv('phone'); ?>"></div>
      <div class="col-12 col-md-5"><label class="form-label small mb-1">ঠিকানা</label><input name="address" class="form-control" maxlength="255" value="<?php echo $fv('address'); ?>"></div>
      <div class="col-12"><label class="form-label small mb-1">নোট</label><input name="note" class="form-control" maxlength="255" value="<?php echo $fv('note'); ?>"></div>
    </div>
    <div class="mt-3 d-flex gap-2">
      <button class="btn btn-primary" id="suSave"><?php echo $isUpdate ? 'আপডেট' : 'সংরক্ষণ'; ?></button>
      <?php if ($isUpdate): ?><a class="btn btn-outline-secondary" href="suppliers.php">বাতিল</a><?php endif; ?>
    </div>
  </form>
</div></div>

<form method="get" class="card card-body mb-3"><div class="row g-2"><div class="col-9 col-md-6"><input class="form-control" name="q" maxlength="60" placeholder="নাম বা ফোন দিয়ে খুঁজুন" value="<?php echo h($q); ?>"></div><div class="col-3 col-md-2 d-grid"><button class="btn btn-outline-primary">খুঁজুন</button></div></div></form>

<div class="card"><div class="card-body p-0"><div class="table-responsive">
  <table class="table table-striped align-middle mb-0 su-table" id="suTable">
    <thead class="table-light"><tr><th>নাম</th><th>ফোন</th><th class="text-center">ক্রয়</th><th class="text-end">মোট ক্রয়</th><th class="text-end">পরিশোধ</th><th class="text-end">বাকি</th><th>অবস্থা</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr data-id="<?php echo (int)$r['id']; ?>">
        <td data-label="নাম" class="fw-semibold"><a href="supplier_view.php?id=<?php echo (int)$r['id']; ?>"><?php echo h($r['name']); ?></a></td>
        <td data-label="ফোন"><?php echo h($r['phone'] ?? '—'); ?></td>
        <td data-label="ক্রয়" class="text-center"><?php echo (int)$r['cnt']; ?></td>
        <td data-label="মোট ক্রয়" class="text-end"><?php echo money_bd($r['total']); ?></td>
        <td data-label="পরিশোধ" class="text-end"><?php echo money_bd($r['paid']); ?></td>
        <td data-label="বাকি" class="text-end fw-semibold <?php echo $r['due'] > 0 ? 'text-danger' : ''; ?>"><?php echo money_bd($r['due']); ?></td>
        <td data-label="অবস্থা"><span class="badge <?php echo (int)$r['active'] ? 'bg-success' : 'bg-secondary'; ?>"><?php echo (int)$r['active'] ? 'সক্রিয়' : 'নিষ্ক্রিয়'; ?></span></td>
        <td class="text-end text-nowrap">
          <a class="btn btn-sm btn-outline-primary" href="supplier_view.php?id=<?php echo (int)$r['id']; ?>">View</a>
          <a class="btn btn-sm btn-outline-secondary" href="suppliers.php?edit=<?php echo (int)$r['id']; ?>">Edit</a>
          <form method="post" class="d-inline"><?php echo csrf_field(); ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>"><button class="btn btn-sm btn-outline-warning"><?php echo (int)$r['active'] ? 'নিষ্ক্রিয়' : 'সক্রিয়'; ?></button></form>
          <?php if ((int)$r['cnt'] === 0): ?><button type="button" class="btn btn-sm btn-outline-danger btn-del" data-id="<?php echo (int)$r['id']; ?>" data-name="<?php echo h($r['name']); ?>">Delete</button><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="text-center text-muted py-4">কোনো সরবরাহকারী নেই</td></tr><?php endif; ?>
    </tbody>
  </table>
</div></div></div>

<div class="modal fade" id="delModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header py-2"><h6 class="modal-title">সরবরাহকারী মুছুন</h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">"<strong id="delName"></strong>" মুছে ফেলা হবে (কোনো ক্রয় নেই বলেই মোছা যাচ্ছে)। চালিয়ে যাবেন?</div>
    <form method="post" class="modal-footer py-2"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" id="delId" value="">
      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">বাতিল</button><button class="btn btn-danger" id="delConfirm">মুছুন</button></form>
  </div></div>
</div>
<script>
document.querySelectorAll('.btn-del').forEach(b=>b.addEventListener('click',()=>{
  document.getElementById('delId').value=b.dataset.id; document.getElementById('delName').textContent=b.dataset.name||'';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('delModal')).show();
}));
</script>
<?php require __DIR__.'/footer.php'; ?>
