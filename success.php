<?php
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/delivery.php';
require_once __DIR__.'/includes/order_access.php';
$pdo = get_pdo();

/* গোপন কোড (t) দিয়ে; আইডি দিয়ে নয় — আইডি আন্দাজ করে অন্যের অর্ডার দেখা যেত */
$o = oa_by_token($pdo, $_GET['t'] ?? '') ?: null;
$id = $o ? (int)$o['id'] : 0;

/* ========== Settings / Support ========== */
$store_phone = (string)($pdo->query("SELECT value FROM settings WHERE `key`='store_phone'")->fetch()['value'] ?? '');
$ship_nat    = dl_global($pdo);
$ship_dhaka  = $ship_nat;
foreach (dl_all($pdo) as $dlRow) { if ($dlRow['name_en'] === 'Dhaka') { $ship_dhaka = dl_effective_fee($dlRow, $ship_nat); break; } }

/* ========== BN helpers ========== */
function bn_digits($s){
  return strtr((string)$s, ['0'=>'০','1'=>'১','2'=>'২','3'=>'৩','4'=>'৪','5'=>'৫','6'=>'৬','7'=>'৭','8'=>'৮','9'=>'৯', ','=>'٬']);
}
function bn_money($n){
  $n = round((float)$n);
  $s = number_format($n, 0, '.', ',');     // 11,565
  return '৳' . bn_digits($s);
}
function dateBD($ts){
  return bn_digits(date('d M, Y g:i A', is_numeric($ts)? (int)$ts : strtotime($ts)));
}

/* ========== Status badge map ========== */
function status_view($raw){
  $s = strtolower(trim((string)$raw));
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
  return $map[$s] ?? [($raw ?: 'অজানা'), 'badge gray'];
}

/* ========== Totals (respect DB value 0) ========== */
$ship_area_raw = strtolower(trim($o['shipping_area'] ?? $o['shipping_area_code'] ?? ''));
$ship_fee_raw  = $o['shipping_fee'] ?? $o['delivery_fee'] ?? $o['delivery_charge'] ?? null;

/* ship_fee: DB-তে থাকলে যেটা আছে সেটাই ব্যবহার (০ সহ) */
if ($ship_fee_raw === null || $ship_fee_raw === '') {
  // fallback to settings
  if (in_array($ship_area_raw, ['nationwide','outside_dhaka','outside-dhaka'])) {
    $ship_fee = $ship_nat;
  } else {
    $ship_fee = $ship_dhaka;
    $ship_area_raw = $ship_area_raw ?: 'dhaka';
  }
} else {
  $ship_fee = (float)$ship_fee_raw; // 0 হলে 그대로 থাকবে => Free Shipping
}

$ship_area_label = trim((string)($o['shipping_district'] ?? '')) !== ''
  ? (string)$o['shipping_district']
  : ((in_array($ship_area_raw, ['nationwide','outside_dhaka','outside-dhaka'])) ? 'সারা দেশে' : 'ঢাকার ভিতরে');

$grand_total = (float)($o['grand_total'] ?? $o['total'] ?? $o['payable'] ?? $o['amount'] ?? 0);
$subtotal    = $o['subtotal'] ?? $o['sub_total'] ?? $o['items_total'] ?? $o['total_without_shipping'] ?? null;
if ($subtotal === null && $grand_total > 0) {
  $subtotal = max(0, $grand_total - $ship_fee);
}
$subtotal = (float)$subtotal;
if ($grand_total <= 0) $grand_total = $subtotal + $ship_fee;

[$status_text, $status_class] = status_view($o['status'] ?? '');

include __DIR__.'/includes/partials_header.php';
?>

<!-- Confetti Library -->
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.2/dist/confetti.browser.min.js"></script>

<style>
  #success-page { max-width: 720px; margin: 20px auto 30px; padding: 0 14px; text-align: center; }
  .success-card {
    background:#fff; border:1px solid var(--border-color);
    border-radius:var(--radius-lg); box-shadow:var(--shadow-soft);
    padding: 26px 22px;
  }
  .icon-wrap {
    width: 64px; height: 64px; margin: 0 auto 16px;
    border-radius: 50%; background: #dcfce7;
    display: grid; place-items: center;
  }
  .icon-wrap svg { width: 34px; height: 34px; color: #15803d; }
  .success-card h1 {
    color: #15803d; font-weight: 800;
    font-size: clamp(1.15rem, 1.2vw + .9rem, 1.5rem);
  }
  .success-card .lead { color: var(--text-muted); margin: 8px 0 16px; font-size:.86rem; }
  .order-id-box {
    background:#f8fafc; border:1px dashed #cbd5e1;
    padding:12px 14px; border-radius:12px;
    display:inline-flex; align-items:center; gap:12px; flex-wrap:wrap; justify-content:center;
  }
  .order-id-box span { font-weight:700; font-size:.92rem; color:var(--text-main); }
  .order-id-box .copy-btn {
    border:1px solid var(--border-color); background:#fff; color:var(--text-muted);
    border-radius:8px; padding:6px 12px; cursor:pointer; font-size:.78rem; font-weight:700;
  }
  .order-id-box .copy-btn:active { transform: translateY(1px); }

  .details-grid { display:grid; gap:14px; margin-top:22px; text-align:left; }
  @media (min-width: 640px) { .details-grid { grid-template-columns: 1fr 1fr; } }
  .details-box { background:#f8fafc; padding:15px; border-radius:12px; }
  .details-box h3 {
    font-size:.9rem; font-weight:800; margin:0 0 10px; padding-bottom:8px;
    border-bottom:1px solid var(--border-color);
  }
  .details-box p { margin:4px 0; color:var(--text-muted); font-size:.82rem; }
  .details-box p strong { color:var(--text-main); }

  .summary-rows { display:grid; gap:8px; }
  .summary-row { display:flex; justify-content:space-between; align-items:center; font-size:.82rem; color:#475569; }
  .summary-row.total {
    font-weight:800; font-size:.95rem; color:var(--text-main);
    margin-top:8px; padding-top:8px; border-top:1px solid var(--border-color);
  }

  .btn-bar { display:flex; gap:10px; flex-wrap:wrap; margin-top:22px; justify-content:center; }

  .help-text { margin-top:16px; font-size:.8rem; color:var(--text-muted); }
  .help-text a { color:var(--primary-purple); font-weight:700; }

  .error-card h1 { color:#b91c1c; }
  .error-card .icon-wrap { background:#fee2e2; }
  .error-card .icon-wrap svg { color:#b91c1c; }

  .badge {
    display:inline-block; padding:4px 11px; border-radius:999px;
    font-weight:800; font-size:11px;
    background:#eff6ff; color:#1e40af; border:1px solid #dbeafe;
  }
  .badge.green { background:#dcfce7; color:#15803d; border-color:#bbf7d0; }
  .badge.red   { background:#fee2e2; color:#b91c1c; border-color:#fecaca; }
  .badge.blue  { background:#dbeafe; color:#1d4ed8; border-color:#bfdbfe; }
  .badge.indigo{ background:#e0e7ff; color:#4338ca; border-color:#c7d2fe; }
  .badge.orange{ background:#ffedd5; color:#c2410c; border-color:#fed7aa; }
  .badge.gray  { background:#f1f5f9; color:#475569; border-color:#e2e8f0; }
</style>

<div id="success-page">
  <?php if(!$o): ?>
    <div class="success-card error-card">
      <div class="icon-wrap">
        <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
      </div>
      <h1>অর্ডার পাওয়া যায়নি</h1>
      <p class="lead">দুঃখিত, আপনার দেওয়া লিংকে কোনো অর্ডার খুঁজে পাওয়া যায়নি।</p>
      <div class="btn-bar">
        <a class="btn-pill" href="<?php echo BASE_URL; ?>/shop.php">শপিং করুন</a>
        <a class="btn-pill ghost" href="<?php echo BASE_URL; ?>/index.php">হোমে ফিরুন</a>
      </div>
    </div>
  <?php else: ?>
    <div class="success-card">
      <div class="icon-wrap">
        <svg fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
      </div>
      <h1>আপনার অর্ডারটি সফল হয়েছে!</h1>
      <p class="lead">ধন্যবাদ! আমরা শীঘ্রই আপনার সাথে যোগাযোগ করে অর্ডারটি কনফার্ম করবো।</p>

      <div class="order-id-box">
        <span>অর্ডার আইডি: <strong>#<?php echo h($o['order_code']); ?></strong></span>
        <button class="copy-btn" id="btnCopy" type="button" data-code="<?php echo h($o['order_code']); ?>">কপি</button>
      </div>

      <div class="details-grid">
        <div class="details-box">
          <h3>ডেলিভারি তথ্য</h3>
          <p><strong>নাম:</strong> <?php echo h($o['customer_name']); ?></p>
          <p><strong>মোবাইল:</strong> <?php echo h($o['mobile']); ?></p>
          <p><strong>ঠিকানা:</strong> <?php echo h($o['address']); ?></p>
        </div>
        <div class="details-box">
          <h3>অর্ডার সারাংশ</h3>
          <div class="summary-rows">
            <div class="summary-row">
              <span>স্ট্যাটাস</span>
              <span><span class="<?php echo h($status_class); ?>"><?php echo h($status_text); ?></span></span>
            </div>
            <div class="summary-row"><span>সাবটোটাল</span> <span><?php echo bn_money($subtotal); ?></span></div>
            <div class="summary-row"><span>ডেলিভারি চার্জ<?php echo trim((string)($o['shipping_district'] ?? '')) !== '' ? ' ('.h($o['shipping_district']).')' : ''; ?></span> <span><?php echo bn_money($ship_fee); ?></span></div>
            <div class="summary-row total"><span>সর্বমোট</span> <span><?php echo bn_money($grand_total); ?></span></div>
          </div>
        </div>
      </div>

      <div class="btn-bar">
        <a class="btn-pill" href="<?php echo BASE_URL; ?>/shop.php">আবার কেনাকাটা করুন</a>
        <a class="btn-pill ghost" href="<?php echo BASE_URL; ?>/index.php">হোমে ফিরুন</a>
      </div>

      <?php if ($store_phone): ?>
      <p class="help-text">যেকোনো প্রয়োজনে কল করুন: <a href="tel:<?php echo h($store_phone); ?>"><?php echo h($store_phone); ?></a></p>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<script>
  (function(){
    // Confetti animation (Robust handling)
    try {
        if (typeof confetti === 'function') {
            const duration = 3 * 1000; // ৩ সেকেন্ড ধরে চলবে
            const animationEnd = Date.now() + duration;
            const defaults = { startVelocity: 30, spread: 360, ticks: 60, zIndex: 0 };

            function randomInRange(min, max) {
                return Math.random() * (max - min) + min;
            }

            const interval = setInterval(function() {
                const timeLeft = animationEnd - Date.now();

                if (timeLeft <= 0) {
                    return clearInterval(interval);
                }

                const particleCount = 50 * (timeLeft / duration);
                // স্ক্রিনের বাম এবং ডান দিক থেকে কনফেটি আসবে
                confetti(Object.assign({}, defaults, { particleCount, origin: { x: randomInRange(0.1, 0.3), y: Math.random() - 0.2 } }));
                confetti(Object.assign({}, defaults, { particleCount, origin: { x: randomInRange(0.7, 0.9), y: Math.random() - 0.2 } }));
            }, 250);
        }
    } catch (e) {
      console.warn('Confetti animation failed to load or run.');
    }

    // Copy order ID (with fallback)
    const btn = document.getElementById('btnCopy');
    if(!btn) return;

    function fallbackCopy(text) {
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.focus(); ta.select();
      try { document.execCommand('copy'); } catch (e) {}
      document.body.removeChild(ta);
    }

    btn.addEventListener('click', async ()=>{
      try{
        const codeToCopy = btn.dataset.code || '';
        if (!navigator.clipboard) {
          fallbackCopy(codeToCopy);
        } else {
          await navigator.clipboard.writeText(codeToCopy);
        }
        btn.textContent = 'কপি হয়েছে';
      }catch(e){
        btn.textContent = 'কপি ব্যর্থ';
      }
      setTimeout(()=>btn.textContent='কপি', 1500);
    });
  })();
</script>

<?php include __DIR__.'/includes/partials_footer.php'; ?>
