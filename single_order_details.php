<?php
// order_details.php
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/delivery.php';
require_once __DIR__.'/includes/order_access.php';
require_once __DIR__.'/includes/customer_auth.php';
$customer = require_customer();
$pdo = get_pdo();

/* ---------- Helpers (fallbacks) ---------- */
if (!function_exists('h')) { function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('money_bd')) { function money_bd($n){ $n=(float)$n; $n=round($n); return '৳'.number_format($n,0,'',','); } }
if (!function_exists('bn_digits')) { function bn_digits($s){ return strtr((string)$s, ['0'=>'০','1'=>'১','2'=>'২','3'=>'৩','4'=>'৪','5'=>'৫','6'=>'৬','7'=>'৭','8'=>'৮','9'=>'৯']); } }
if (!function_exists('dateBD')) {
    function dateBD($ts){
        /* ইংরেজি অঙ্ক — সাইটের বাকি অংশের মতো; বাংলা অঙ্ক ছোট আকারে (১/৬, ৪/৮) গুলিয়ে যায় */
        return date('d M Y, g:i A', is_numeric($ts)? (int)$ts : strtotime((string)$ts));
    }
}

/* ---------- Status helpers ---------- */
function statusBadgeInfo($status){
  $s = strtolower(trim((string)$status));
  $map = [
    'pending'    => ['পেন্ডিং','badge gray'],
    'processing' => ['প্রসেসিং','badge blue'],
    'packed'     => ['প্যাকড','badge blue'],
    'shipped'    => ['শিপড','badge indigo'],
    'delivered'  => ['ডেলিভারড','badge green'],
    'cancelled'  => ['ক্যানসেল্ড','badge red'],
    'canceled'   => ['ক্যানসেল্ড','badge red'],
    'returned'   => ['রিটার্নড','badge orange'],
  ];
  return $map[$s] ?? [$status ?: 'স্ট্যাটাস নেই', 'badge gray'];
}
function statusProgress($status){
  // progress index based on normal flow; cancelled/returned handled separately
  $flow = ['pending','processing','packed','shipped','delivered'];
  $s = strtolower(trim((string)$status));
  if (in_array($s, ['cancelled','canceled','returned'], true)) return ['type'=>'halt','value'=>0];
  $idx = array_search($s, $flow, true);
  if ($idx === false) $idx = 0;
  $count = count($flow);
  $percent = (int) round(($idx / ($count-1)) * 100);
  return ['type'=>'flow','value'=>max(0,min(100,$percent)), 'step'=>$idx, 'steps'=>$flow];
}

/* ---------- Load order ---------- */
/* গোপন কোড (?t=…) সবার জন্য; আইডি (?id=…) শুধু লগইন করা মালিকের জন্য — নইলে আইডি বদলে বদলে সবার তথ্য দেখা যেত */
$o  = oa_load_for_customer($pdo, $_GET, $customer);
$id = $o ? (int)$o['id'] : 0;

/* ---------- Load items ---------- */
$items = [];
if ($o) {
    $it = $pdo->prepare("
      SELECT oi.*, COALESCE(p.name, CONCAT('Product #', oi.product_id)) AS pname
      FROM order_items oi
      LEFT JOIN products p ON p.id = oi.product_id
      WHERE oi.order_id=?
      ORDER BY oi.id
    ");
    $it->execute([$id]);
    $items = $it->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* ---------- Totals (fallback if missing) ---------- */
if ($o) {
    $calcSub = 0.0;
    foreach ($items as $r) { $calcSub += ((float)($r['price'] ?? 0)) * ((int)($r['qty'] ?? 0)); }
    $subtotal = isset($o['subtotal']) && $o['subtotal']!==null ? (float)$o['subtotal'] : $calcSub;
    $shipping = isset($o['shipping_fee']) ? (float)$o['shipping_fee'] : 0.0;
    $discount = (float)($o['discount'] ?? 0);
    $total    = isset($o['total']) ? (float)$o['total'] : ($subtotal + $shipping);
    $progress = statusProgress($o['status'] ?? '');
    [$stText, $stClass] = statusBadgeInfo($o['status'] ?? '');
}

include __DIR__.'/includes/partials_header.php';
?>

<style>
  :root{
    --brand: #0ea5e9; --brand-dark: #0284c7;
    --text: #0f172a; --muted: #475569; --border: #e5e7eb; --white: #fff; --bg: #f8fafc;
    --radius: 16px; --shadow: 0 12px 28px rgba(2,8,23,.08);
    --success: #10b981; --warn:#f59e0b; --danger:#ef4444;
  }
  @media (prefers-color-scheme: dark){
    :root{
      --text:#e5e7eb; --muted:#94a3b8; --border:#1f2937; --white:#0b1220; --bg:#0b1220;
      --shadow: 0 12px 28px rgba(0,0,0,.45);
    }
  }
  *{box-sizing:border-box}
  body{ background: var(--bg); color: var(--text); }
  a.btn{ display:inline-flex; align-items:center; gap:8px; border-radius:12px; font-weight:700; text-decoration:none; padding:10px 14px; border:1px solid var(--border); }
  a.btn.ghost{ background:transparent; color:#4338ca; border-color:#c7d2fe; }
  a.btn:hover{ filter:saturate(1.05); transform: translateY(-1px); transition: all .2s ease; }
  .icon{ font-style:normal; }
  #odv .disc-val{ color:#2e7d32; font-weight:700; }
  #odv .table td.right, #odv .table th.right, #odv .totals-mobile .value, .mini-dl dd{ font-variant-numeric: tabular-nums; }
  #odv .table td.right{ font-weight:500; font-size:.95rem; }
  .topbar .actions .btn i{ font-size:.9em; }

  #odv{ max-width: 980px; margin: 28px auto; padding: 0 14px; }
  .topbar{
    display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:12px;
  }
  .topbar h2{ font-weight: 800; font-size: clamp(1.35rem, 1.1rem + 1vw, 1.9rem); margin:0; line-height:1.2; }
  .topbar .actions{ display:flex; gap:8px; flex-wrap:wrap; }

  #odv .card{
    background: var(--white); border: 1px solid var(--border);
    border-radius: var(--radius); box-shadow: var(--shadow);
    padding: 18px;
  }
  #odv h3 {
    font-size: 1.18rem; font-weight: 700; margin: 0 0 12px;
    padding-bottom: 10px; border-bottom: 1px solid var(--border);
  }

  /* sticky summary on small screens */
  .sticky-summary{ position: sticky; top: 8px; z-index: 5; }

  #odv .layout { display: grid; gap: 16px; }
  @media (min-width: 768px) {
    #odv .layout { grid-template-columns: 1fr 1fr; gap: 20px; }
    .sticky-summary{ position: static; }
  }
  .mini-dl { display: grid; grid-template-columns: 120px 1fr; gap: 10px; font-size: 15px; }
  .mini-dl dt { color: var(--muted); }
  .mini-dl dd { font-weight: 600; margin: 0; word-break: break-word; }
  /* emphasize total inside summary if used */
  .mini-dl dd.total-strong{
    font-weight:900; font-size:1.12rem;
    padding: 2px 8px; border:1px solid var(--border); border-radius:10px;
  }

  /* Badges */
  .badge{ display:inline-flex; align-items:center; gap:6px; padding:4px 10px; border-radius:999px; font-weight:700; font-size: 12px; }
  .badge.gray{ background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; }
  .badge.blue{ background:#eff6ff; color:#1e40af; border:1px solid #dbeafe; }
  .badge.indigo{ background:#eef2ff; color:#3730a3; border:1px solid #e0e7ff; }
  .badge.green{ background:#ecfdf5; color:#065f46; border:1px solid #d1fae5; }
  .badge.red{ background:#fee2e2; color:#b91c1c; border:1px solid #fecaca; }
  .badge.orange{ background:#fff7ed; color:#9a3412; border:1px solid #fed7aa; }

  /* Progress bar */
  .progress{
    height: 10px; background:#e2e8f0; border-radius:999px; overflow:hidden; border:1px solid var(--border);
  }
  .progress > span{
    display:block; height:100%; width:0%;
    background: linear-gradient(90deg, var(--brand-dark), var(--brand));
    transition: width .4s ease;
  }
  .progress-labels{ display:flex; justify-content:space-between; font-size:12px; color:var(--muted); margin-top:6px; gap:6px; }
  .progress-labels span.done{ color:#16a34a; font-weight:700; }

  /* Responsive Table */
  #odv .table-responsive { border-radius: 12px; overflow: hidden; border: 1px solid var(--border); }
  #odv .table { width: 100%; border-collapse: collapse; margin: 0; }
  #odv .table th, #odv .table td { padding: 12px 14px; vertical-align: middle; text-align: left; }
  #odv .table thead { background: #f8fafc; }
  #odv .table tbody tr:not(:last-child) { border-bottom: 1px solid var(--border); }
  #odv .table .right { text-align: right; }
  #odv .table tfoot th, #odv .table tfoot td { font-weight: 700; }
  #odv .table tfoot tr:last-child th, #odv .table tfoot tr:last-child td { font-size: 1.08rem; }

  @media (max-width: 768px){
    #odv .table-responsive { border: 0; }
    #odv .table { display: block; }
    #odv .table thead { display: none; }
    #odv .table tbody, #odv .table tfoot { display: block; }
    #odv .table tr {
      display: block; background: var(--white); border: 1px solid var(--border);
      border-radius: var(--radius); padding: 14px; margin-bottom: 12px;
      box-shadow: 0 4px 12px rgba(2,8,23,.05);
    }
    #odv .table tfoot tr {
      padding: 0; border: none; box-shadow: none; background: transparent;
    }
    #odv .table td {
      display: flex; justify-content: space-between; gap: 12px;
      padding: 9px 0 !important; border: 0 !important;
    }
    #odv .table td::before {
      content: attr(data-label); font-weight: 600; color: var(--muted);
      min-width: 110px; text-align: left;
    }
    #odv .table tfoot th, #odv .table tfoot td {
      display: flex; justify-content: space-between;
      padding: 10px 14px !important;
    }
    #odv .table tfoot tr:not(:last-child) th, #odv .table tfoot tr:not(:last-child) td { border-bottom: 1px solid var(--border); }
    #odv .table tfoot [colspan="3"]::before { content: none; }
  }

  /* Totals mobile card */
  .totals-mobile{
    display:none; /* ডেস্কটপে হাইড */
    background: var(--white);
    border:1px solid var(--border);
    border-radius: 14px;
    box-shadow: 0 6px 18px rgba(2,8,23,.06);
    padding: 12px;
    margin-top: 12px;
  }
  .totals-mobile .row{
    display:flex; justify-content:space-between; align-items:center;
    padding:10px 4px; font-size:16px; gap:12px;
  }
  .totals-mobile .row:not(:last-child){
    border-bottom:1px dashed var(--border);
  }
  .totals-mobile .label{ color: var(--muted); font-weight:600; }
  .totals-mobile .value{ font-weight:800; }
  .totals-mobile .total .label{ color: var(--text); }
  .totals-mobile .total .value{
    font-size: 1.15rem;
    padding: 6px 10px;
    border-radius: 10px;
    border:1px solid var(--border);
    box-shadow: inset 0 1px 0 rgba(255,255,255,.05);
  }

  @media (max-width: 768px){
    /* মোবাইলে টেবিলের ফুটার লুকাও, বদলে সুন্দর কার্ড দেখাও */
    #odv .table tfoot{ display:none; }
    .totals-mobile{ display:block; }
  }

  /* Focus state */
  a.btn:focus-visible, .badge:focus-visible { outline: 3px solid #93c5fd; outline-offset: 2px; }
</style>

<div id="odv">
  <?php if (!$o): ?>
    <div class="card" style="text-align:center;">
        <h2><i class="fa-solid fa-circle-exclamation" style="color:#f97316;"></i> অর্ডার পাওয়া যায়নি</h2>
        <p class="lead">দুঃখিত, আপনার দেওয়া লিঙ্কে কোনো অর্ডার খুঁজে পাওয়া যায়নি।</p>
        <a href="track_orders.php" class="btn" style="background:var(--brand);color:var(--white);border-color:transparent;">অর্ডার ট্র্যাকিং পেজে যান</a>
    </div>
  <?php else: ?>
    <div class="topbar">
      <h2>অর্ডার বিস্তারিত: #<?php echo h($o['order_code'] ?? $o['id']); ?></h2>
      <div class="actions">
        <a href="track_orders.php" class="btn ghost"><i class="fa-solid fa-box-open" aria-hidden="true"></i> সব অর্ডার</a>
      </div>
    </div>

    <div class="layout">
      <div class="card">
        <h3>কাস্টমার তথ্য</h3>
        <dl class="mini-dl">
          <dt>নাম</dt><dd><?php echo h($o['customer_name'] ?? ''); ?></dd>
          <dt>মোবাইল</dt><dd><?php echo h($o['mobile'] ?? ''); ?></dd>
          <dt>ঠিকানা</dt><dd><?php echo nl2br(h($o['address'] ?? '')); ?></dd>
          <?php if(!empty($o['note'])): ?>
            <dt>নোট</dt><dd class="text-muted"><?php echo nl2br(h($o['note'])); ?></dd>
          <?php endif; ?>
        </dl>
      </div>

      <div class="card sticky-summary">
        <h3>অর্ডার সারসংক্ষেপ</h3>
        <dl class="mini-dl">
          <dt>স্ট্যাটাস</dt><dd><span class="<?php echo h($stClass); ?>"><?php echo h($stText); ?></span></dd>
          <dt>শিপিং এরিয়া</dt><dd><?php echo h(dl_order_area_label($o)); ?></dd>
          <dt>ডেলিভারি চার্জ</dt><dd><?php echo money_bd($shipping); ?></dd>
          <?php if ($discount > 0): ?><dt>ডিসকাউন্ট</dt><dd class="disc-val">&minus;<?php echo money_bd($discount); ?></dd><?php endif; ?>
          <dt>সাবটোটাল</dt><dd><?php echo money_bd($subtotal); ?></dd>
          <dt style="font-weight:700;">মোট</dt><dd class="total-strong"><?php echo money_bd($total); ?></dd>
          <dt>পেমেন্ট পদ্ধতি</dt><dd><?php echo h(payment_method_label($o['payment_method'] ?? '')); ?></dd>
          <dt>তারিখ</dt><dd><?php echo dateBD($o['created_at'] ?? ''); ?></dd>
        </dl>

        <?php if(($progress['type'] ?? '') === 'flow'): ?>
          <div style="margin-top:10px;">
            <div class="progress" aria-label="Order progress">
              <span style="width: <?php echo (int)$progress['value']; ?>%;"></span>
            </div>
            <div class="progress-labels" aria-hidden="true">
              <?php
                $labelsBn = ['pending'=>'পেন্ডিং','processing'=>'প্রসেসিং','packed'=>'প্যাকড','shipped'=>'শিপড','delivered'=>'ডেলিভারড'];
                foreach(($progress['steps'] ?? []) as $i=>$st){
                  $done = ($i <= (int)$progress['step']);
                  echo '<span'.($done?' class="done"':'').'>'.h($labelsBn[$st] ?? ucfirst($st)).'</span>';
                }
              ?>
            </div>
          </div>
        <?php elseif(($progress['type'] ?? '') === 'halt'): ?>
          <p style="margin-top:8px;color:var(--muted);">এই অর্ডার স্বাভাবিক ফ্লোতে নেই (Cancelled/Returned)।</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="card" style="margin-top:16px;">
      <h3>পণ্য তালিকা</h3>
      <div class="table-responsive">
        <table class="table" role="table">
          <thead>
            <tr>
              <th>পণ্য</th>
              <th class="right">দাম</th>
              <th class="right">পরিমাণ</th>
              <th class="right">লাইন টোটাল</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach($items as $r):
              $qty   = (int)($r['qty'] ?? 0);
              $price = (float)($r['price'] ?? 0);
              $line  = $price * $qty;
          ?>
            <tr>
              <td data-label="পণ্য"><?php echo h($r['pname'] ?? ''); ?></td>
              <td data-label="দাম" class="right"><?php echo money_bd($price); ?></td>
              <td data-label="পরিমাণ" class="right"><?php echo (int)$qty; ?></td>
              <td data-label="লাইন টোটাল" class="right"><?php echo money_bd($line); ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if(!$items): ?>
            <tr><td colspan="4" style="text-align:center;">কোনো আইটেম নেই</td></tr>
          <?php endif; ?>
          </tbody>
          <tfoot>
            <tr>
              <th colspan="3" class="right">সাবটোটাল</th>
              <td class="right"><?php echo money_bd($subtotal); ?></td>
            </tr>
            <tr>
              <th colspan="3" class="right">ডেলিভারি চার্জ</th>
              <td class="right"><?php echo money_bd($shipping); ?></td>
            </tr>
            <?php if ($discount > 0): ?>
            <tr>
              <th colspan="3" class="right">ডিসকাউন্ট</th>
              <td class="right disc-val">&minus;<?php echo money_bd($discount); ?></td>
            </tr>
            <?php endif; ?>
            <tr>
              <th colspan="3" class="right"><strong>মোট</strong></th>
              <td class="right"><strong><?php echo money_bd($total); ?></strong></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- মোবাইলের জন্য আলাদা টোটালস কার্ড -->
      <div class="totals-mobile" aria-label="মোবাইল টোটালস সারসংক্ষেপ">
        <div class="row">
          <div class="label">সাবটোটাল</div>
          <div class="value"><?php echo money_bd($subtotal); ?></div>
        </div>
        <div class="row">
          <div class="label">ডেলিভারি চার্জ</div>
          <div class="value"><?php echo money_bd($shipping); ?></div>
        </div>
        <?php if ($discount > 0): ?>
        <div class="row">
          <div class="label">ডিসকাউন্ট</div>
          <div class="value disc-val">&minus;<?php echo money_bd($discount); ?></div>
        </div>
        <?php endif; ?>
        <div class="row total">
          <div class="label"><strong>মোট</strong></div>
          <div class="value"><strong><?php echo money_bd($total); ?></strong></div>
        </div>
      </div>
      <!-- /মোবাইল টোটালস কার্ড -->
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__.'/includes/partials_footer.php'; ?>
