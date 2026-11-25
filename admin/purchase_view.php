<?php
// admin/purchase_view.php — ক্রয়ের বিস্তারিত: আইটেম, পরিশোধ (কিস্তি/একসাথে), পরিশোধ মোছা, ক্রয় বাতিল
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/purchases.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_purchases');

$pdo = get_pdo();
if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money_bd')) { function money_bd($n){ $n=(float)$n; $n=round($n); return '৳'.number_format($n,0,'',','); } }
$money2 = fn($n) => '৳' . number_format((float)$n, 2, '.', ',');

$id = is_string($_GET['id'] ?? null) && ctype_digit($_GET['id']) ? (int)$_GET['id'] : 0;
$flash = function (bool $ok, string $msg) { $_SESSION['flash'] = ['ok' => $ok, 'msg' => $msg]; };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
  $pid = is_string($_POST['purchase_id'] ?? null) && ctype_digit($_POST['purchase_id']) ? (int)$_POST['purchase_id'] : 0;
  $back = 'purchase_view.php?id=' . $pid;
  if (!csrf_verify()) { $flash(false, 'সিকিউরিটি টোকেন মিলেনি'); header('Location: ' . ($pid ? $back : 'purchases.php')); exit; }
  $by = (string)($_SESSION['admin_username'] ?? '');
  if ($action === 'pay') {
    [$ok, $msg] = pu_add_payment($pdo, $pid, $_POST['amount'] ?? '', $_POST['paid_date'] ?? '', $_POST['method'] ?? '', $_POST['note'] ?? '', $by);
    $flash($ok, $ok ? 'পরিশোধ যোগ হয়েছে।' : $msg);
  } elseif ($action === 'delete_payment') {
    $payId = is_string($_POST['payment_id'] ?? null) && ctype_digit($_POST['payment_id']) ? (int)$_POST['payment_id'] : 0;
    $own = $pdo->prepare("SELECT 1 FROM purchase_payments WHERE id=? AND purchase_id=?"); $own->execute([$payId, $pid]);
    if ($own->fetchColumn()) { [$ok, $msg] = pu_delete_payment($pdo, $payId); $flash($ok, $ok ? 'পরিশোধ মুছে ফেলা হয়েছে — বাকি আবার বেড়েছে।' : $msg); }
    else $flash(false, 'পরিশোধটি পাওয়া যায়নি।');
  } elseif ($action === 'void') {
    [$ok, $msg, $short] = pu_void($pdo, $pid);
    $flash($ok, $ok ? 'ক্রয় বাতিল হয়েছে — স্টক ফেরানো হয়েছে।' . ($short > 0 ? " ($short টি পণ্যের স্টক কম ছিল, ০-তে নামানো হয়েছে)" : '') . ' গড় ক্রয়মূল্য ফেরানো হয়নি।' : $msg);
  } else { $flash(false, 'অজানা অ্যাকশন।'); }
  header('Location: ' . ($pid ? $back : 'purchases.php')); exit;
}

$data = $id > 0 ? pu_get($pdo, $id) : null;
require_once __DIR__.'/header.php';
if (!$data): ?>
  <div class="alert alert-danger" id="pvMissing">ক্রয়টি পাওয়া যায়নি। <a href="purchases.php">ক্রয়ের তালিকা</a></div>
<?php require __DIR__.'/footer.php'; exit; endif;
$p = $data['purchase']; $items = $data['items']; $pays = $data['payments'];
$cancelled = $p['status'] === 'cancelled';
$methods = ['ক্যাশ', 'ব্যাংক', 'bKash', 'Nagad', 'চেক'];
?>
<style>.kpi-card{border:1px solid #e5e7eb;border-radius:14px}.kpi-value{font-weight:800;font-size:1.25rem}</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <div>
    <h5 class="m-0">ক্রয় #<?php echo (int)$p['id']; ?> <?php if ($cancelled): ?><span class="badge bg-secondary" id="pvStatus">বাতিল</span><?php elseif ($p['due'] > 0): ?><span class="badge bg-warning text-dark" id="pvStatus">বাকি</span><?php else: ?><span class="badge bg-success" id="pvStatus">শোধ</span><?php endif; ?></h5>
    <div class="text-muted small"><a href="supplier_view.php?id=<?php echo (int)$p['supplier_id']; ?>"><?php echo h($p['supplier_name'] ?? '—'); ?></a> · <?php echo h(date('d M Y', strtotime($p['purchase_date']))); ?><?php echo $p['invoice_no'] !== null ? ' · ইনভয়েস ' . h($p['invoice_no']) : ''; ?></div>
  </div>
  <a class="btn btn-outline-secondary btn-sm" href="purchases.php">ক্রয়ের তালিকা</a>
</div>

<?php if (!empty($_SESSION['flash'])): $fl = $_SESSION['flash']; unset($_SESSION['flash']); ?>
  <div class="alert <?php echo !empty($fl['ok']) ? 'alert-success' : 'alert-danger'; ?> py-2" id="pvFlash"><?php echo h($fl['msg'] ?? ''); ?></div>
<?php endif; ?>

<div class="row g-3 mb-3" id="pvKpis">
  <div class="col-4"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">মোট</div><div class="kpi-value" id="vTotal"><?php echo $money2($p['total']); ?></div></div></div></div>
  <div class="col-4"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">পরিশোধ</div><div class="kpi-value" id="vPaid"><?php echo $money2($p['paid']); ?></div></div></div></div>
  <div class="col-4"><div class="card kpi-card"><div class="card-body py-3"><div class="text-muted small">বাকি</div><div class="kpi-value <?php echo (!$cancelled && $p['due'] > 0) ? 'text-danger' : ''; ?>" id="vDue"><?php echo $cancelled ? '—' : $money2($p['due']); ?></div></div></div></div>
</div>

<div class="card mb-3"><div class="card-body p-0"><div class="table-responsive">
  <table class="table table-striped mb-0" id="pvItems">
    <thead class="table-light"><tr><th>পণ্য</th><th class="text-end">পরিমাণ</th><th class="text-end">ক্রয়-দাম</th><th class="text-end">মোট</th></tr></thead>
    <tbody><?php foreach ($items as $it): ?><tr><td><?php echo h($it['product_name']); ?></td><td class="text-end"><?php echo (int)$it['qty']; ?></td><td class="text-end"><?php echo $money2($it['unit_cost']); ?></td><td class="text-end"><?php echo $money2($it['line_total']); ?></td></tr><?php endforeach; ?></tbody>
  </table>
</div></div></div>
<?php if ($p['note']): ?><div class="mb-3 small text-muted">নোট: <?php echo h($p['note']); ?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-lg-7">
    <div class="card h-100"><div class="card-body p-0">
      <div class="p-3 pb-2"><h6 class="m-0">পরিশোধের ইতিহাস</h6></div>
      <div class="table-responsive"><table class="table table-striped mb-0" id="pvPays">
        <thead class="table-light"><tr><th>তারিখ</th><th class="text-end">পরিমাণ</th><th>মাধ্যম</th><th>নোট</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($pays as $y): ?>
          <tr data-id="<?php echo (int)$y['id']; ?>"><td><?php echo h(date('d M Y', strtotime($y['paid_date']))); ?></td><td class="text-end"><?php echo $money2($y['amount']); ?></td><td><?php echo h($y['method'] ?? '—'); ?></td><td class="small text-muted"><?php echo h($y['note'] ?? ''); ?></td>
            <td class="text-end"><?php if (!$cancelled): ?><button type="button" class="btn btn-sm btn-outline-danger btn-delpay" data-id="<?php echo (int)$y['id']; ?>" data-amt="<?php echo h($money2($y['amount'])); ?>">মুছুন</button><?php endif; ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$pays): ?><tr><td colspan="5" class="text-center text-muted py-3">এখনো কোনো পরিশোধ নেই</td></tr><?php endif; ?>
        </tbody></table></div>
    </div></div>
  </div>
  <div class="col-12 col-lg-5">
    <?php if (!$cancelled && $p['due'] > 0): ?>
    <div class="card mb-3"><div class="card-body">
      <h6 class="mb-3">পরিশোধ করুন</h6>
      <form method="post" id="pvPayForm" autocomplete="off">
        <?php echo csrf_field(); ?><input type="hidden" name="action" value="pay"><input type="hidden" name="purchase_id" value="<?php echo (int)$p['id']; ?>">
        <div class="row g-2">
          <div class="col-12"><label class="form-label small mb-1">পরিমাণ (৳) * <span class="text-muted">(বাকি <?php echo $money2($p['due']); ?>)</span></label>
            <div class="input-group"><input type="number" name="amount" id="pvAmount" class="form-control" min="0.01" max="<?php echo h(number_format((float)$p['due'], 2, '.', '')); ?>" step="0.01" required><button type="button" class="btn btn-outline-success" id="pvFull">পুরো বাকি</button></div></div>
          <div class="col-6"><label class="form-label small mb-1">তারিখ *</label><input type="date" name="paid_date" class="form-control" max="<?php echo date('Y-m-d'); ?>" required value="<?php echo date('Y-m-d'); ?>"></div>
          <div class="col-6"><label class="form-label small mb-1">মাধ্যম</label><input name="method" class="form-control" maxlength="30" list="pvMethods"><datalist id="pvMethods"><?php foreach ($methods as $m): ?><option value="<?php echo h($m); ?>"><?php endforeach; ?></datalist></div>
          <div class="col-12"><label class="form-label small mb-1">নোট</label><input name="note" class="form-control" maxlength="255"></div>
        </div>
        <button class="btn btn-primary mt-3" id="pvPaySave">পরিশোধ সংরক্ষণ</button>
      </form>
    </div></div>
    <?php endif; ?>
    <?php if (!$cancelled): ?>
    <div class="card"><div class="card-body">
      <h6 class="mb-2">ক্রয় বাতিল</h6>
      <?php if ((float)$p['paid'] > 0): ?>
        <div class="small text-muted" id="pvVoidInfo">পরিশোধ করা আছে, তাই বাতিল করা যাবে না। ভুল হলে আগে পরিশোধ-সারি মুছুন, তারপর বাতিল করুন।</div>
      <?php else: ?>
        <div class="small text-muted mb-2">ভুল এন্ট্রি হলে বাতিল করুন: স্টক ফিরে যাবে (০-এর নিচে নামবে না)। <strong>গড় ক্রয়মূল্য আগের অবস্থায় ফেরানো হয় না</strong> — দরকারে পণ্য এডিটে হাতে ঠিক করুন।</div>
        <button type="button" class="btn btn-outline-danger btn-sm" id="pvVoidBtn" data-bs-toggle="modal" data-bs-target="#voidModal">ক্রয় বাতিল করুন</button>
      <?php endif; ?>
    </div></div>
    <?php endif; ?>
  </div>
</div>

<div class="modal fade" id="voidModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header py-2"><h6 class="modal-title">ক্রয় বাতিল</h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body">ক্রয় #<?php echo (int)$p['id']; ?> বাতিল হবে এবং এর স্টক বিয়োগ হবে। চালিয়ে যাবেন?</div>
  <form method="post" class="modal-footer py-2"><?php echo csrf_field(); ?><input type="hidden" name="action" value="void"><input type="hidden" name="purchase_id" value="<?php echo (int)$p['id']; ?>">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">না</button><button class="btn btn-danger" id="voidConfirm">হ্যাঁ, বাতিল করুন</button></form>
</div></div></div>

<div class="modal fade" id="delPayModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">
  <div class="modal-header py-2"><h6 class="modal-title">পরিশোধ মুছুন</h6><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
  <div class="modal-body"><strong id="delPayAmt"></strong> পরিশোধটি মুছে ফেলা হবে এবং বাকি আবার বাড়বে। চালিয়ে যাবেন?</div>
  <form method="post" class="modal-footer py-2"><?php echo csrf_field(); ?><input type="hidden" name="action" value="delete_payment"><input type="hidden" name="purchase_id" value="<?php echo (int)$p['id']; ?>"><input type="hidden" name="payment_id" id="delPayId" value="">
    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">না</button><button class="btn btn-danger" id="delPayConfirm">মুছুন</button></form>
</div></div></div>

<script>
(function(){
  const full = document.getElementById('pvFull');
  if (full) full.addEventListener('click', ()=>{ document.getElementById('pvAmount').value = '<?php echo h(number_format((float)$p['due'], 2, '.', '')); ?>'; });
  document.querySelectorAll('.btn-delpay').forEach(b=>b.addEventListener('click', ()=>{
    document.getElementById('delPayId').value = b.dataset.id; document.getElementById('delPayAmt').textContent = b.dataset.amt;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('delPayModal')).show();
  }));
})();
</script>
<?php require __DIR__.'/footer.php'; ?>
