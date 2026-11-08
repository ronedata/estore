<?php
// admin/order_print.php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_orders');
$pdo = get_pdo();

/* ---------- helpers ---------- */
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

/* ---------- load settings (optional) ---------- */
$settings = $pdo->query("SELECT `key`,`value` FROM settings WHERE `key` IN ('store_name','store_phone','store_email','store_about')")
                ->fetchAll(PDO::FETCH_KEY_PAIR);
$STORE_NAME  = $settings['store_name']  ?? (defined('APP_NAME') ? APP_NAME : 'Store');
$STORE_PHONE = $settings['store_phone'] ?? '';
$STORE_EMAIL = $settings['store_email'] ?? '';
//$STORE_ABOUT = $settings['store_about'] ?? '';

/* ---------- load order ---------- */
$id = (int)($_GET['id'] ?? 0);
$stm = $pdo->prepare("SELECT * FROM orders WHERE id=?");
$stm->execute([$id]);
$o = $stm->fetch(PDO::FETCH_ASSOC);
if(!$o){ http_response_code(404); echo "Order not found"; exit; }

/* ---------- load items ---------- */
$it = $pdo->prepare("
  SELECT oi.*, COALESCE(p.name, CONCAT('#', oi.product_id)) AS pname
  FROM order_items oi
  LEFT JOIN products p ON p.id = oi.product_id
  WHERE oi.order_id=?
  ORDER BY oi.id
");
$it->execute([$id]); 
$items = $it->fetchAll(PDO::FETCH_ASSOC);

/* ---------- totals (fallbacks) ---------- */
$calcSub = 0;
foreach ($items as $r) { $calcSub += ((float)$r['price']) * ((int)$r['qty']); }
$subtotal = isset($o['subtotal']) && $o['subtotal'] !== null ? (float)$o['subtotal'] : $calcSub;
$shipping = isset($o['shipping_fee']) ? (float)$o['shipping_fee'] : 0;
$total    = isset($o['total']) ? (float)$o['total'] : ($subtotal + $shipping);

/* ---------- date format ---------- */
$created = $o['created_at'] ?? '';
if ($created) {
  $ts = strtotime($created);
  if ($ts) $created = date('Y-m-d H:i', $ts);
}
?>
<!doctype html>
<html lang="bn">
<head>
  <meta charset="utf-8">
  <title>Invoice - #<?php echo h($o['order_code'] ?? $o['id']); ?> - <?php echo h($STORE_NAME); ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

  <style>
    body{background:#fff}
    .wrap{max-width:980px;margin:16px auto;padding:16px}
    .head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;border-bottom:1px solid #e5e7eb;padding-bottom:12px;margin-bottom:12px}
    .store h5{margin:0}
    .meta td{padding:2px 8px}
    .card-mini .card-body{padding:12px}

    /* Mobile-friendly table */
    @media (max-width:576px){
      .table thead{display:none}
      .table tbody tr{
        display:block;border:1px solid #e9ecef;border-radius:10px;margin-bottom:10px;padding:8px
      }
      .table tbody td{
        display:flex;justify-content:space-between;gap:10px;border:0 !important;padding:6px 4px !important
      }
      .table tbody td::before{
        content:attr(data-label);font-weight:600;color:#64748b
      }
      .table tfoot th,.table tfoot td{padding:.5rem .25rem !important}
      .btn-toolbar{gap:8px}
    }
    /* Print */
    @media print{
      .no-print{display:none!important}
      .wrap{margin:0;max-width:100%;padding:0}
      .table{border-color:#000}
      a{color:#000;text-decoration:none}
    }
  </style>
</head>
<body>
<div class="wrap">
  <!-- actions (hidden on print) -->
  <div class="d-flex justify-content-between align-items-center no-print mb-2">
    <div class="fw-semibold">অর্ডার ইনভয়েস</div>
    <div class="btn-toolbar">
      <a class="btn btn-sm btn-outline-secondary" href="orders.php">সব অর্ডার</a>
      <button class="btn btn-sm btn-primary" onclick="window.print()">প্রিন্ট</button>
    </div>
  </div>

  <!-- header -->
  <div class="head">
    <div class="store">
      <h5><?php echo h($STORE_NAME); ?></h5>
      <div class="small text-muted">
        <?php if($STORE_PHONE) echo h($STORE_PHONE); ?></br>
        <!--<?php if($STORE_PHONE && $STORE_EMAIL) echo ' • '; ?>-->
        <?php if($STORE_EMAIL) echo h($STORE_EMAIL); ?>
        <!--<?php if($STORE_ABOUT): ?><br><?php echo nl2br(h($STORE_ABOUT)); ?><?php endif; ?>-->
      </div>
    </div>
    <table class="meta small">
      <tr><td class="text-muted">ইনভয়েস</td><td>#<?php echo h($o['order_code'] ?? $o['id']); ?></td></tr>
      <tr><td class="text-muted">তারিখ</td><td><?php echo dateBD(h($created)); ?></td></tr>
      <tr><td class="text-muted">স্ট্যাটাস</td>
          <td><span class="badge <?php echo status_badge_class($o['status'] ?? ''); ?>"><?php echo ucfirst(h($o['status'] ?? '')); ?></span></td></tr>
    </table>
  </div>

  <!-- customer & order info -->
  <div class="row g-3 mb-3">
    <div class="col-12 col-lg-6">
      <div class="card card-mini">
        <div class="card-body">
          <h6 class="fw-bold mb-2">কাস্টমার তথ্য</h6>
          <div class="mb-1"><span class="text-muted">নাম:</span> <?php echo h($o['customer_name']); ?></div>
          <div class="mb-1"><span class="text-muted">মোবাইল:</span> <?php echo h($o['mobile']); ?></div>
          <div class="mb-1"><span class="text-muted">ঠিকানা:</span> <?php echo nl2br(h($o['address'])); ?></div>
          <?php if(!empty($o['note'])): ?>
            <div class="small text-muted mt-2"><span class="fw-semibold">নোট:</span> <?php echo nl2br(h($o['note'])); ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-12 col-lg-6">
      <div class="card card-mini">
        <div class="card-body">
          <h6 class="fw-bold mb-2">অর্ডার তথ্য</h6>
          <div class="mb-1"><span class="text-muted">শিপিং এরিয়া:</span> <?php echo h($o['shipping_area']); ?></div>
          <div class="mb-1"><span class="text-muted">পেমেন্ট পদ্ধতি:</span> <?php echo h(payment_method_label($o['payment_method'] ?? '')); ?></div>
          <div class="mb-1"><span class="text-muted">ডেলিভারি চার্জ:</span> <?php echo money_bd($shipping); ?></div>
        </div>
      </div>
    </div>
  </div>

  <!-- items -->
  <div class="table-responsive">
    <table class="table table-bordered align-middle">
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
        <?php if (!$items): ?>
          <tr><td colspan="4" class="text-center text-muted">কোনো আইটেম নেই</td></tr>
        <?php endif; ?>
      </tbody>
      <tfoot>
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

  <!-- footer actions (hidden on print) -->
  <div class="no-print mt-3 d-flex gap-2">
    <button class="btn btn-dark" onclick="window.print()">প্রিন্ট</button>
    <a class="btn btn-outline-secondary" href="order_view.php?id=<?php echo (int)$o['id']; ?>">বিস্তারিত</a>
  </div>
</div>
</body>
</html>
