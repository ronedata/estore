<?php
// admin/purchase_form.php — নতুন ক্রয়: সরবরাহকারী, ইনভয়েস, তারিখ, আইটেম (পণ্য, পরিমাণ, ক্রয়-দাম), শুরুর পরিশোধ
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/purchases.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_purchases');

$pdo = get_pdo();
if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }

$errors = [];
$post = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify()) {
    $_SESSION['flash'] = ['ok' => false, 'msg' => 'সিকিউরিটি টোকেন মিলেনি'];
    header('Location: purchase_form.php'); exit;
  }
  [$clean, $errors] = pu_clean_purchase($pdo, $_POST);
  if ($clean !== null) {
    $newId = pu_create($pdo, $clean, (string)($_SESSION['admin_username'] ?? ''));
    if ($newId > 0) {
      $_SESSION['flash'] = ['ok' => true, 'msg' => 'ক্রয় সংরক্ষণ হয়েছে — স্টক ও গড় ক্রয়মূল্য হালনাগাদ হয়েছে।'];
      header('Location: purchase_view.php?id=' . $newId); exit;
    }
    $errors = ['ক্রয় সংরক্ষণ করা যায়নি, আবার চেষ্টা করুন। কিছুই লেখা হয়নি।'];
  }
  $post = $_POST;
}

$suppliers = pu_suppliers_with_dues($pdo, null, true);
$products = $pdo->query("SELECT id, name, stock, cost_price FROM products ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$pjson = array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name'], 'stock' => (int)$p['stock'], 'cost' => $p['cost_price'] === null ? null : (float)$p['cost_price']], $products);
$preSup = $post['supplier_id'] ?? (is_string($_GET['supplier'] ?? null) && ctype_digit($_GET['supplier']) ? $_GET['supplier'] : '');

$rows = [];
if ($post && is_array($post['product_id'] ?? null) && is_array($post['qty'] ?? null) && is_array($post['unit_cost'] ?? null)) {
  foreach ($post['product_id'] as $i => $pid) {
    $rows[] = ['product_id' => is_scalar($pid) ? (string)$pid : '', 'qty' => is_scalar($post['qty'][$i] ?? null) ? (string)$post['qty'][$i] : '', 'unit_cost' => is_scalar($post['unit_cost'][$i] ?? null) ? (string)$post['unit_cost'][$i] : ''];
  }
}
if (!$rows) $rows[] = ['product_id' => '', 'qty' => '', 'unit_cost' => ''];
$pv = fn($k, $d = '') => h(is_scalar($post[$k] ?? null) ? $post[$k] : $d);

require_once __DIR__.'/header.php';
?>
<style>
  .pf-line td{vertical-align:middle}
  .pf-hint{font-size:.78rem;color:#64748b}
  @media (max-width: 768px){
    table.pf-tbl{display:block}
    table.pf-tbl thead{display:none}
    table.pf-tbl tbody{display:block}
    table.pf-tbl tr.pf-line{display:block;background:#fff;border:1px solid #e9ecef;border-radius:12px;padding:10px;margin-bottom:10px}
    table.pf-tbl td{display:block;padding:4px 0!important;border:0!important}
  }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h5 class="m-0">নতুন ক্রয়</h5>
  <a class="btn btn-outline-secondary btn-sm" href="purchases.php">ক্রয়ের তালিকা</a>
</div>

<?php if ($errors): ?><div class="alert alert-danger" id="pfErrors"><?php foreach ($errors as $e): ?><div><?php echo h($e); ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if (!empty($_SESSION['flash'])): $fl = $_SESSION['flash']; unset($_SESSION['flash']); ?><div class="alert <?php echo !empty($fl['ok']) ? 'alert-success' : 'alert-danger'; ?> py-2"><?php echo h($fl['msg'] ?? ''); ?></div><?php endif; ?>

<?php if (!$suppliers): ?>
  <div class="alert alert-warning" id="pfNoSupplier">আগে একজন সক্রিয় সরবরাহকারী যোগ করুন। <a href="suppliers.php">সরবরাহকারী যোগ করুন</a></div>
<?php else: ?>
<form method="post" id="pfForm" autocomplete="off">
  <?php echo csrf_field(); ?>
  <div class="card mb-3"><div class="card-body">
    <div class="row g-2">
      <div class="col-12 col-md-4"><label class="form-label small mb-1">সরবরাহকারী *</label>
        <select name="supplier_id" id="pfSupplier" class="form-select" required>
          <option value="">— বাছুন —</option>
          <?php foreach ($suppliers as $s): ?><option value="<?php echo (int)$s['id']; ?>" <?php echo (string)$preSup === (string)$s['id'] ? 'selected' : ''; ?>><?php echo h($s['name']); ?><?php echo $s['due'] > 0 ? ' (বাকি ৳' . number_format($s['due']) . ')' : ''; ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-6 col-md-4"><label class="form-label small mb-1">ইনভয়েস নম্বর</label><input name="invoice_no" class="form-control" maxlength="60" value="<?php echo $pv('invoice_no'); ?>"></div>
      <div class="col-6 col-md-4"><label class="form-label small mb-1">তারিখ *</label><input type="date" name="purchase_date" class="form-control" max="<?php echo date('Y-m-d'); ?>" required value="<?php echo $pv('purchase_date', date('Y-m-d')); ?>"></div>
    </div>
  </div></div>

  <div class="card mb-3"><div class="card-body">
    <h6 class="mb-3">পণ্য</h6>
    <div class="table-responsive">
      <table class="table align-middle pf-tbl mb-2" id="pfTable">
        <thead class="table-light"><tr><th style="min-width:260px">পণ্য</th><th style="width:120px">পরিমাণ</th><th style="width:150px">ক্রয়-দাম (প্রতি পিস)</th><th style="width:140px" class="text-end">লাইন মোট</th><th style="width:50px"></th></tr></thead>
        <tbody id="pfBody">
        <?php foreach ($rows as $r): ?>
          <tr class="pf-line">
            <td>
              <select name="product_id[]" class="form-select pf-prod">
                <option value="">— পণ্য বাছুন —</option>
                <?php foreach ($products as $p): ?><option value="<?php echo (int)$p['id']; ?>" <?php echo $r['product_id'] === (string)$p['id'] ? 'selected' : ''; ?>><?php echo h($p['name']); ?></option><?php endforeach; ?>
              </select>
              <div class="pf-hint pf-info"></div>
            </td>
            <td><input type="number" name="qty[]" class="form-control pf-qty" min="1" max="<?php echo PU_MAX_QTY; ?>" step="1" value="<?php echo h($r['qty']); ?>"></td>
            <td><input type="number" name="unit_cost[]" class="form-control pf-cost" min="0" step="0.01" value="<?php echo h($r['unit_cost']); ?>"></td>
            <td class="text-end fw-semibold pf-line-total">৳0</td>
            <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger pf-del" title="সারি সরান">×</button></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <button type="button" class="btn btn-outline-primary btn-sm" id="pfAdd"><i class="fa-solid fa-plus"></i> আরেকটি পণ্য</button>
  </div></div>

  <div class="card mb-3"><div class="card-body">
    <div class="row g-3 align-items-end">
      <div class="col-12 col-md-3"><div class="text-muted small">মোট ক্রয়</div><div class="fs-4 fw-bold" id="pfTotal">৳0</div></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">এখন পরিশোধ (৳)</label><input type="number" name="paid" id="pfPaid" class="form-control" min="0" step="0.01" placeholder="০ = সবটা বাকি" value="<?php echo $pv('paid'); ?>"></div>
      <div class="col-6 col-md-3"><label class="form-label small mb-1">মাধ্যম</label><input name="method" class="form-control" maxlength="30" list="pfMethods" value="<?php echo $pv('method'); ?>"><datalist id="pfMethods"><option value="ক্যাশ"><option value="ব্যাংক"><option value="bKash"><option value="Nagad"><option value="চেক"></datalist></div>
      <div class="col-12 col-md-3"><div class="text-muted small">বাকি থাকবে</div><div class="fs-4 fw-bold text-danger" id="pfDue">৳0</div></div>
      <div class="col-12"><div class="d-flex gap-2 flex-wrap"><button type="button" class="btn btn-sm btn-outline-success" id="pfPayAll">পুরোটা এখনই পরিশোধ</button><button type="button" class="btn btn-sm btn-outline-secondary" id="pfPayNone">সবটা বাকি</button></div></div>
      <div class="col-12"><label class="form-label small mb-1">নোট</label><input name="note" class="form-control" maxlength="255" value="<?php echo $pv('note'); ?>"></div>
    </div>
    <div class="form-text mt-2">সংরক্ষণ করলে প্রতিটি পণ্যের স্টক বাড়বে এবং ক্রয়মূল্য <strong>গড়ে</strong> হালনাগাদ হবে (আগের স্টক ও নতুন স্টক মিলিয়ে)। বাকি টাকা পরে কিস্তিতে বা একসাথে শোধ করা যাবে।</div>
    <div class="mt-3 d-flex gap-2"><button class="btn btn-primary" id="pfSave">ক্রয় সংরক্ষণ</button><a class="btn btn-outline-secondary" href="purchases.php">বাতিল</a></div>
  </div></div>
</form>

<script>
(function(){
  const PRODUCTS = <?php echo json_encode($pjson, JSON_UNESCAPED_UNICODE); ?>;
  const byId = {}; PRODUCTS.forEach(p=>byId[p.id]=p);
  const body = document.getElementById('pfBody');
  const money = n => '৳' + Math.round(n).toLocaleString('en-US');
  const cents = v => Math.round((parseFloat(v)||0)*100);
  function recalc(){
    let total = 0;
    body.querySelectorAll('.pf-line').forEach(tr=>{
      const q = parseInt(tr.querySelector('.pf-qty').value,10)||0, c = cents(tr.querySelector('.pf-cost').value);
      const line = q>0 ? q*c : 0; total += line;
      tr.querySelector('.pf-line-total').textContent = money(line/100);
    });
    document.getElementById('pfTotal').textContent = money(total/100);
    const paid = cents(document.getElementById('pfPaid').value);
    document.getElementById('pfDue').textContent = money(Math.max(0,total-paid)/100);
    document.getElementById('pfTotal').dataset.cents = total;
  }
  function hint(tr){
    const p = byId[parseInt(tr.querySelector('.pf-prod').value,10)];
    tr.querySelector('.pf-info').textContent = p ? ('এখন স্টক ' + p.stock + (p.cost===null ? ', ক্রয়মূল্য অজানা' : ', গড় ক্রয়মূল্য ' + money(p.cost))) : '';
  }
  function bind(tr){
    tr.querySelector('.pf-prod').addEventListener('change', ()=>{
      const p = byId[parseInt(tr.querySelector('.pf-prod').value,10)];
      const cost = tr.querySelector('.pf-cost');
      if (p && p.cost!==null && cost.value==='') cost.value = p.cost;
      hint(tr); recalc();
    });
    tr.querySelector('.pf-qty').addEventListener('input', recalc);
    tr.querySelector('.pf-cost').addEventListener('input', recalc);
    tr.querySelector('.pf-del').addEventListener('click', ()=>{
      if (body.querySelectorAll('.pf-line').length>1) tr.remove();
      else { tr.querySelector('.pf-prod').value=''; tr.querySelector('.pf-qty').value=''; tr.querySelector('.pf-cost').value=''; hint(tr); }
      recalc();
    });
    hint(tr);
  }
  body.querySelectorAll('.pf-line').forEach(bind);
  document.getElementById('pfAdd').addEventListener('click', ()=>{
    const first = body.querySelector('.pf-line');
    const tr = first.cloneNode(true);
    tr.querySelector('.pf-prod').value=''; tr.querySelector('.pf-qty').value=''; tr.querySelector('.pf-cost').value='';
    body.appendChild(tr); bind(tr); recalc();
  });
  document.getElementById('pfPaid').addEventListener('input', recalc);
  document.getElementById('pfPayAll').addEventListener('click', ()=>{ document.getElementById('pfPaid').value = (parseInt(document.getElementById('pfTotal').dataset.cents||'0',10)/100).toFixed(2); recalc(); });
  document.getElementById('pfPayNone').addEventListener('click', ()=>{ document.getElementById('pfPaid').value=''; recalc(); });
  recalc();
})();
</script>
<?php endif; ?>
<?php require __DIR__.'/footer.php'; ?>
