<?php
// admin/order_view.php
require_once __DIR__.'/header.php';
gate('manage_orders');
$pdo = get_pdo();

/* ---------- Helpers (fallback) ---------- */
if (!function_exists('h')) {
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('money_bd')) {
  function money_bd($n){ $n=(float)$n; $n=round($n); return '৳'.number_format($n,0,'',','); }
}
function bn_digits($s){
  return strtr((string)$s, ['0'=>'০','1'=>'১','2'=>'২','3'=>'৩','4'=>'৪','5'=>'৫','6'=>'৬','7'=>'৭','8'=>'৮','9'=>'৯']);
}
function dateBD($ts){
  // d M, Y g:i A → শুধুই ডিজিটগুলো বাংলা হবে
  return bn_digits(date('d M, Y g:i A', is_numeric($ts)? (int)$ts : strtotime($ts)));
}
function status_badge_class($s){
  switch ($s) {
    case 'pending':    return 'bg-warning text-dark';
    case 'processing': return 'bg-info text-dark';
    case 'shipped':    return 'bg-primary';
    case 'delivered':  return 'bg-success';
    case 'cancelled':  return 'bg-secondary';
  }
  return 'bg-light text-dark';
}

/* ---------- Load order ---------- */
$id = (int)($_GET['id'] ?? 0);
$stm = $pdo->prepare("SELECT * FROM orders WHERE id=?");
$stm->execute([$id]);
$o = $stm->fetch(PDO::FETCH_ASSOC);
if (!$o) {
  echo '<div class="alert alert-warning">অর্ডার পাওয়া যায়নি</div>';
  require __DIR__.'/footer.php'; exit;
}

/* ---------- Load items ---------- */
$it = $pdo->prepare("
  SELECT oi.*, COALESCE(p.name, CONCAT('#', oi.product_id)) AS pname
  FROM order_items oi
  LEFT JOIN products p ON p.id = oi.product_id
  WHERE oi.order_id=?
  ORDER BY oi.id
");
$it->execute([$id]);
$items = $it->fetchAll(PDO::FETCH_ASSOC);

/* ---------- Totals (fallback if missing) ---------- */
$calcSub = 0;
foreach ($items as $r) { $calcSub += ((float)$r['price']) * ((int)$r['qty']); }
$subtotal = isset($o['subtotal']) && $o['subtotal']!==null ? (float)$o['subtotal'] : $calcSub;
$shipping = isset($o['shipping_fee']) ? (float)$o['shipping_fee'] : 0;
$total    = isset($o['total']) ? (float)$o['total'] : ($subtotal + $shipping);

/* ---------- Date nice format ---------- */
$created = $o['created_at'] ?? '';
if ($created) { $ts = strtotime($created); if ($ts) $created = date('Y-m-d H:i', $ts); }
?>
<style>
  /* ===== Mobile-friendly table ===== */
  @media (max-width: 576px){
    .table-responsive{border:0}
    table.table{display:block}
    table.table thead{display:none}
    table.table tbody{display:block}
    table.table tr{
      display:block; background:#fff; border:1px solid #e9ecef; border-radius:12px;
      padding:10px; margin-bottom:10px; box-shadow:0 6px 16px rgba(2,8,23,.04);
    }
    table.table td{
      display:flex; justify-content:space-between; gap:12px;
      padding:6px 0 !important; border:0 !important;
    }
    table.table td::before{
      content:attr(data-label);
      font-weight:600; color:#64748b; min-width:120px; text-align:left;
    }
  }
  .mini dt{color:#64748b}
  .mini dd{margin-bottom:.5rem}
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h5 class="m-0">অর্ডার #<?php echo h($o['order_code'] ?? $o['id']); ?></h5>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary btn-sm" href="orders.php">সব অর্ডার</a>
    <a class="btn btn-primary btn-sm" target="_blank" href="order_print.php?id=<?php echo (int)$o['id']; ?>">প্রিন্ট</a>
  </div>
</div>

<div class="row g-3">
  <!-- Customer -->
  <div class="col-12 col-lg-6">
    <div class="card h-100">
      <div class="card-body">
        <h6 class="fw-bold mb-2">কাস্টমার তথ্য</h6>
        <dl class="row mini mb-0">
          <dt class="col-4 col-sm-3">নাম</dt><dd class="col-8 col-sm-9"><?php echo h($o['customer_name']); ?></dd>
          <dt class="col-4 col-sm-3">মোবাইল</dt><dd class="col-8 col-sm-9"><?php echo h($o['mobile']); ?></dd>
          <dt class="col-4 col-sm-3">ঠিকানা</dt><dd class="col-8 col-sm-9"><?php echo nl2br(h($o['address'])); ?></dd>
          <?php if(!empty($o['note'])): ?>
            <dt class="col-4 col-sm-3">নোট</dt><dd class="col-8 col-sm-9 text-muted"><?php echo nl2br(h($o['note'])); ?></dd>
          <?php endif; ?>
        </dl>
      </div>
    </div>
  </div>

  <!-- Order summary -->
  <div class="col-12 col-lg-6">
    <div class="card h-100">
      <div class="card-body">
        <h6 class="fw-bold mb-2">অর্ডার সারসংক্ষেপ</h6>
        <dl class="row mini mb-0">
          <dt class="col-5 col-sm-4">স্ট্যাটাস</dt>
          <dd class="col-7 col-sm-8">
            <span class="badge <?php echo status_badge_class($o['status'] ?? ''); ?>">
              <?php echo ucfirst(h($o['status'] ?? '')); ?>
            </span>
          </dd>

          <dt class="col-5 col-sm-4">শিপিং এরিয়া</dt>
          <dd class="col-7 col-sm-8"><?php echo h($o['shipping_area']); ?></dd>

          <dt class="col-5 col-sm-4">ডেলিভারি চার্জ</dt>
          <dd class="col-7 col-sm-8"><?php echo money_bd($shipping); ?></dd>

          <dt class="col-5 col-sm-4">সাবটোটাল</dt>
          <dd class="col-7 col-sm-8"><?php echo money_bd($subtotal); ?></dd>

          <dt class="col-5 col-sm-4">মোট</dt>
          <dd class="col-7 col-sm-8 fw-bold"><?php echo money_bd($total); ?></dd>

          <dt class="col-5 col-sm-4">পেমেন্ট</dt>
          <dd class="col-7 col-sm-8"><?php echo h(payment_method_label($o['payment_method'] ?? '')); ?></dd>
          <dt class="col-5 col-sm-4">তারিখ</dt>
          <dd class="col-7 col-sm-8"><?php echo dateBD(h($created)); ?></dd>
        </dl>
      </div>
    </div>
  </div>
</div>

<!-- Items -->
<div class="card mt-3">
  <div class="card-body">
    <h6 class="fw-bold mb-2">পণ্য তালিকা</h6>
    <div class="table-responsive">
      <table class="table align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>পণ্য</th>
            <th class="text-center">পরিমাণ</th>
            <th class="text-end">দাম</th>
            <th class="text-end">লাইন</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach($items as $r): 
            $qty   = (int)$r['qty'];
            $price = (float)$r['price'];
            $line  = $price * $qty;
          ?>
          <tr>
            <td data-label="পণ্য"><?php echo h($r['pname']); ?></td>
            <td data-label="পরিমাণ" class="text-center"><?php echo $qty; ?></td>
            <td data-label="দাম" class="text-end"><?php echo money_bd($price); ?></td>
            <td data-label="লাইন" class="text-end"><?php echo money_bd($line); ?></td>
          </tr>
          <?php endforeach; ?>
          <?php if(!$items): ?>
            <tr><td colspan="4" class="text-center text-muted">কোনো আইটেম নেই</td></tr>
          <?php endif; ?>
        </tbody>
        <tfoot class="table-light">
          <tr>
            <th colspan="3" class="text-end">সাবটোটাল</th>
            <th class="text-end"><?php echo money_bd($subtotal); ?></th>
          </tr>
          <tr>
            <th colspan="3" class="text-end">ডেলিভারি</th>
            <th class="text-end"><?php echo money_bd($shipping); ?></th>
          </tr>
          <tr>
            <th colspan="3" class="text-end">মোট</th>
            <th class="text-end"><?php echo money_bd($total); ?></th>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
</div>

<?php require __DIR__.'/footer.php'; ?>
