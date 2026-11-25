<?php
// admin/supplier_view.php — সরবরাহকারীর হিসাব: ক্রয়ের ইতিহাস, মোট বাকি, একসাথে পরিশোধ (পুরোনো বাকি থেকে বণ্টন)
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/purchases.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_purchases');

$pdo = get_pdo();
if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
$money2 = fn($n) => '৳' . number_format((float)$n, 2, '.', ',');
$id = is_string($_GET['id'] ?? null) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $sid = is_string($_POST['supplier_id'] ?? null) && ctype_digit($_POST['supplier_id']) ? (int)$_POST['supplier_id'] : 0;
  $back = $sid ? 'supplier_view.php?id=' . $sid : 'suppliers.php';
  if (!csrf_verify()) { $_SESSION['flash'] = ['ok' => false, 'msg' => 'সিকিউরিটি টোকেন মিলেনি']; header('Location: ' . $back); exit; }
  if (($_POST['action'] ?? '') === 'pay_all') {
    [$ok, $msg, $alloc] = pu_pay_supplier($pdo, $sid, $_POST['amount'] ?? '', $_POST['paid_date'] ?? '', $_POST['method'] ?? '', $_POST['note'] ?? '', (string)($_SESSION['admin_username'] ?? ''));
    $_SESSION['flash'] = ['ok' => $ok, 'msg' => $ok ? 'পরিশোধ হয়েছে — ' . count($alloc) . 'টি ক্রয়ে পুরোনো বাকি থেকে বণ্টন হয়েছে।' : $msg];
  } else { $_SESSION['flash'] = ['ok' => false, 'msg' => 'অজানা অ্যাকশন।']; }
  header('Location: ' . $back); exit;
}

$sup = $id > 0 ? pu_supplier_get($pdo, $id) : null;
require_once __DIR__.'/header.php';
if (!$sup): ?>
  <div class="alert alert-danger" id="svMissing">সরবরাহকারী পাওয়া যায়নি। <a href="suppliers.php">তালিকা</a></div>
<?php require __DIR__.'/footer.php'; exit; endif;
$tot = pu_totals($pdo, null, $id);
$page = is_string($_GET['page'] ?? null) && ctype_digit($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$PER = 25;
$total = pu_count($pdo, null, $id, 'all', null);
$pages = max(1, (int)ceil($total / $PER));
if ($page > $pages) $page = $pages;
$rows = pu_list($pdo, null, $id, 'all', null, $page, $PER);
$methods = ['ক্যাশ', 'ব্যাংক', 'bKash', 'Nagad', 'চেক'];
?>
<style>.kpi-card{border:1px solid #e5e7eb;border-radius:14px}.kpi-value{font-weight:800;font-size:1.25rem}</style>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div><h5 class="m-0"><?php echo h($sup['name']); ?> <?php if (!(int)$sup['active']): ?><span class="badge bg-secondary">নিষ্ক্রিয়</span><?php endif; ?></h5>
    <div class="text-muted small"><?php echo h(trim(($sup['phone'] ?? '') . ' ' . ($sup['address'] ?? ''))); ?></div></div>
  <div class="d-flex gap-2"><a class="btn btn-outline-secondary btn-sm" href="suppliers.php">সব সরবরাহকারী</a><a class="btn btn-primary btn-sm" href="purchase_form.php?supplier=<?php echo (int)$sup['id']; ?>"><i class="fa-solid fa-plus"></i> নতুন ক্রয়</a></div>
</div>

<?php if (!empty($_SESSION['flash'])): $fl = $_SESSION['flash']; unset($_SESSION['flash']); ?>
  <div class="alert <?php echo !empty($fl['ok']) ? 'alert-success' : 'alert-danger'; ?> py-2" id="svFlash"><?php echo h($fl['msg'] ?? ''); ?></div>
<?php endif; ?>

<div class="row g-3 mb-3" id="svKpis">
  <div class="col-4"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">মোট ক্রয় (বাতিল বাদে)</div><div class="kpi-value" id="sTotal"><?php echo $money2($tot['total']); ?></div><div class="small text-muted"><?php echo $tot['count']; ?>টি ক্রয়</div></div></div></div>
  <div class="col-4"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">পরিশোধ</div><div class="kpi-value" id="sPaid"><?php echo $money2($tot['paid']); ?></div></div></div></div>
  <div class="col-4"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">বাকি</div><div class="kpi-value <?php echo $tot['due'] > 0 ? 'text-danger' : ''; ?>" id="sDue"><?php echo $money2($tot['due']); ?></div></div></div></div>
</div>

<?php if ($tot['due'] > 0): ?>
<div class="card mb-3" id="svPayCard"><div class="card-body">
  <h6 class="mb-1">একসাথে পরিশোধ</h6>
  <div class="small text-muted mb-3">যত টাকা দেবেন তা সবচেয়ে পুরোনো বাকি ক্রয় থেকে শুরু করে ভাগ হবে। মোট বাকি <?php echo $money2($tot['due']); ?>।</div>
  <form method="post" id="svPayForm" autocomplete="off"><?php echo csrf_field(); ?><input type="hidden" name="action" value="pay_all"><input type="hidden" name="supplier_id" value="<?php echo (int)$sup['id']; ?>">
    <div class="row g-2">
      <div class="col-12 col-md-4"><label class="form-label small mb-1">পরিমাণ (৳) *</label><div class="input-group"><input type="number" name="amount" id="svAmount" class="form-control" min="0.01" max="<?php echo h(number_format((float)$tot['due'], 2, '.', '')); ?>" step="0.01" required><button type="button" class="btn btn-outline-success" id="svFull">সব বাকি</button></div></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">তারিখ *</label><input type="date" name="paid_date" class="form-control" max="<?php echo date('Y-m-d'); ?>" required value="<?php echo date('Y-m-d'); ?>"></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-1">মাধ্যম</label><input name="method" class="form-control" maxlength="30" list="svMethods"><datalist id="svMethods"><?php foreach ($methods as $m): ?><option value="<?php echo h($m); ?>"><?php endforeach; ?></datalist></div>
      <div class="col-12 col-md-4"><label class="form-label small mb-1">নোট</label><input name="note" class="form-control" maxlength="200"></div>
    </div>
    <button class="btn btn-primary mt-3" id="svPaySave">পরিশোধ সংরক্ষণ</button>
  </form>
</div></div>
<?php endif; ?>

<div class="card"><div class="card-body p-0">
  <div class="p-3 pb-2"><h6 class="m-0">ক্রয়ের ইতিহাস</h6></div>
  <div class="table-responsive"><table class="table table-striped align-middle mb-0" id="svTable">
    <thead class="table-light"><tr><th>তারিখ</th><th>ইনভয়েস</th><th class="text-end">মোট</th><th class="text-end">পরিশোধ</th><th class="text-end">বাকি</th><th>অবস্থা</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $c = $r['status'] === 'cancelled'; ?>
      <tr data-id="<?php echo (int)$r['id']; ?>" class="<?php echo $c ? 'text-muted' : ''; ?>"><td><?php echo h(date('d M Y', strtotime($r['purchase_date']))); ?></td><td><?php echo h($r['invoice_no'] ?? '—'); ?></td><td class="text-end"><?php echo $money2($r['total']); ?></td><td class="text-end"><?php echo $money2($r['paid']); ?></td>
        <td class="text-end fw-semibold <?php echo (!$c && $r['due'] > 0) ? 'text-danger' : ''; ?>"><?php echo $c ? '—' : $money2($r['due']); ?></td>
        <td><?php if ($c): ?><span class="badge bg-secondary">বাতিল</span><?php elseif ($r['due'] > 0): ?><span class="badge bg-warning text-dark">বাকি</span><?php else: ?><span class="badge bg-success">শোধ</span><?php endif; ?></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="purchase_view.php?id=<?php echo (int)$r['id']; ?>">View</a></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">এই সরবরাহকারীর কোনো ক্রয় নেই</td></tr><?php endif; ?>
    </tbody></table></div>
  <?php if ($pages > 1): ?><div class="card-footer"><?php render_pagination($page, $pages, ['id' => $id]); ?></div><?php endif; ?>
</div></div>
<script>
(function(){ const f=document.getElementById('svFull'); if(f) f.addEventListener('click',()=>{ document.getElementById('svAmount').value='<?php echo h(number_format((float)$tot['due'], 2, '.', '')); ?>'; }); })();
</script>
<?php require __DIR__.'/footer.php'; ?>
