<?php
// admin/order_view.php
require_once __DIR__.'/header.php';
require_once __DIR__.'/../includes/delivery.php';
require_once __DIR__.'/../includes/steadfast.php';
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
require_once __DIR__.'/../includes/finance.php';
$calcSub = 0;
foreach ($items as $r) { $calcSub += ((float)$r['price']) * ((int)$r['qty']); }
$subtotal = isset($o['subtotal']) && $o['subtotal']!==null ? (float)$o['subtotal'] : $calcSub;
$shipping = isset($o['shipping_fee']) ? (float)$o['shipping_fee'] : 0;
$discount = (float)($o['discount'] ?? 0);
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

<?php if (!empty($_SESSION['flash']) && isset($_SESSION['flash']['msg'])): $fl = $_SESSION['flash']; unset($_SESSION['flash']); ?>
  <div class="alert <?php echo !empty($fl['ok']) ? 'alert-success' : 'alert-danger'; ?> py-2" id="ovFlash"><?php echo h($fl['msg']); ?></div>
<?php endif; ?>

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
          <dd class="col-7 col-sm-8"><?php echo h(dl_order_area_label($o)); ?></dd>

          <dt class="col-5 col-sm-4">ডেলিভারি চার্জ</dt>
          <dd class="col-7 col-sm-8"><?php echo money_bd($shipping); ?></dd>
<?php if ($discount > 0): ?>
          <dt class="col-5 col-sm-4">ডিসকাউন্ট</dt>
          <dd class="col-7 col-sm-8 text-success"><?php echo '&minus;'.money_bd($discount); ?>
            <?php if (!empty($o['discount_note'])): ?><div class="small text-muted"><?php echo h($o['discount_note']); ?></div><?php endif; ?>
            <?php if (!empty($o['discount_by'])): ?><div class="small text-muted">দিয়েছেন: <?php echo h($o['discount_by']); ?></div><?php endif; ?></dd>
<?php endif; ?>

          <dt class="col-5 col-sm-4">সাবটোটাল</dt>
          <dd class="col-7 col-sm-8"><?php echo money_bd($subtotal); ?></dd>

          <dt class="col-5 col-sm-4">মোট</dt>
          <dd class="col-7 col-sm-8 fw-bold"><?php echo money_bd($total); ?></dd>
<?php if (function_exists('can') && can('view_reports')): $ofit = fi_order_profits($pdo, [['id' => (int)$o['id'], 'total' => $total]])[(int)$o['id']] ?? null; ?>
          <dt class="col-5 col-sm-4">লাভ</dt>
          <dd class="col-7 col-sm-8" id="ovProfit"><?php if ($ofit && $ofit['profit'] !== null): ?><span class="fw-bold <?php echo $ofit['profit'] < 0 ? 'text-danger' : 'text-success'; ?>"><?php echo ($ofit['profit'] < 0 ? '&minus;' : '') . money_bd(abs($ofit['profit'])); ?></span> <span class="small text-muted">(মোট − ক্রয়মূল্য <?php echo money_bd($ofit['cost']); ?>)</span><?php else: ?><span class="text-muted">অজানা</span> <span class="small text-muted">(<?php echo (int)($ofit['unknown'] ?? 0); ?>টি আইটেমের ক্রয়মূল্য নেই)</span><?php endif; ?></dd>
<?php endif; ?>

          <dt class="col-5 col-sm-4">পেমেন্ট</dt>
          <dd class="col-7 col-sm-8"><?php echo h(payment_method_label($o['payment_method'] ?? '')); ?></dd>
          <dt class="col-5 col-sm-4">তারিখ</dt>
          <dd class="col-7 col-sm-8"><?php echo dateBD(h($created)); ?></dd>
        </dl>
      </div>
    </div>
  </div>
</div>

<?php
$cSent = sf_order_sent($o);
$cReady = function_exists('can') && can('manage_courier') && sf_ready($pdo);
if ($cSent || $cReady):
  $cTrack = sf_tracking_url($o['courier_tracking_code'] ?? '');
?>
<div class="card mt-3" id="courierCard"><div class="card-body">
  <h6 class="fw-bold mb-2">কুরিয়ার (Steadfast)</h6>
  <?php if ($cSent): ?>
  <dl class="row mini mb-2">
    <dt class="col-5 col-sm-3">স্ট্যাটাস</dt><dd class="col-7 col-sm-9"><span class="badge <?php echo h(sf_status_class($o['courier_status'] ?? '')); ?>" id="courierStatus"><?php echo h(sf_status_label($o['courier_status'] ?? '')); ?></span></dd>
    <dt class="col-5 col-sm-3">কনসাইনমেন্ট</dt><dd class="col-7 col-sm-9" id="courierCid"><?php echo h($o['courier_consignment_id']); ?></dd>
    <dt class="col-5 col-sm-3">ট্র্যাকিং কোড</dt><dd class="col-7 col-sm-9" id="courierTrack"><?php if ($cTrack !== ''): ?><a href="<?php echo h($cTrack); ?>" target="_blank" rel="noopener"><?php echo h($o['courier_tracking_code']); ?></a><?php else: ?>—<?php endif; ?></dd>
    <dt class="col-5 col-sm-3">পাঠানো</dt><dd class="col-7 col-sm-9"><?php echo h($o['courier_sent_at'] ?? '—'); ?></dd>
    <dt class="col-5 col-sm-3">শেষ সিঙ্ক</dt><dd class="col-7 col-sm-9"><?php echo h($o['courier_synced_at'] ?? '—'); ?></dd>
  </dl>
  <?php if ($cReady): ?>
  <form method="post" action="courier_action.php" class="d-inline"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>"><input type="hidden" name="action" value="sync">
    <button class="btn btn-outline-primary btn-sm" id="courierSync" type="submit"><i class="fa-solid fa-rotate"></i> স্ট্যাটাস মেলান</button></form>
  <?php endif; ?>
  <?php else: ?>
  <p class="text-muted small mb-2">এই অর্ডার এখনো কুরিয়ারে পাঠানো হয়নি।<?php echo (strtoupper(trim((string)($o['payment_method'] ?? ''))) === 'COD') ? ' কুরিয়ার আদায় করবে: ' . money_bd($total) : ' অনলাইন পেমেন্ট — আদায় ৳0'; ?></p>
  <form method="post" action="courier_action.php"><?php echo csrf_field(); ?><input type="hidden" name="id" value="<?php echo (int)$o['id']; ?>"><input type="hidden" name="action" value="send">
    <button class="btn btn-success btn-sm" id="courierSend" type="submit" onclick="return confirm('অর্ডারটি এখন কুরিয়ারে পাঠানো হবে। নিশ্চিত?')"><i class="fa-solid fa-truck-fast"></i> কুরিয়ারে পাঠান</button></form>
  <?php endif; ?>
</div></div>
<?php endif; ?>

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
<?php if ($discount > 0): ?>
          <tr>
            <th colspan="3" class="text-end text-success">ডিসকাউন্ট</th>
            <th class="text-end text-success"><?php echo '&minus;'.money_bd($discount); ?></th>
          </tr>
<?php endif; ?>
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
