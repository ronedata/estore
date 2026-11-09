<?php
require_once __DIR__.'/includes/config.php';
$pdo = get_pdo();

/* সংখ্যা ইংরেজি অঙ্কে (কার্ট ও পণ্য পেজের মতো) — আগের বাংলা অঙ্ক ও আরবি হাজার-বিভাজক (٬) সাইটের ফন্টে নেই, ব্রাউজার অন্য ফন্ট এনে ভাঙা দেখাত */
function bn_digits($s){ return (string)$s; }
function bn_money($n){ return '৳' . number_format(round((float)$n), 0, '.', ','); }
function bn_num($n){ return (string)(int)$n; }

/* ---- "Buy Now" ---- */
if (isset($_GET['buy'])) {
  $buyId = (int)$_GET['buy'];
  $qty   = max(1, (int)($_GET['qty'] ?? 1));
  $_SESSION['cart'] = [$buyId => $qty];
}

$cart = $_SESSION['cart'] ?? [];

$checkoutError = $_SESSION['flash_error']  ?? ''; unset($_SESSION['flash_error']);
$cartNotice    = $_SESSION['cart_notice']  ?? ''; unset($_SESSION['cart_notice']);

if (!$cart) {
  include __DIR__.'/includes/partials_header.php';
  echo '<div class="wrap"><div class="empty-state"><i class="fa-solid fa-bag-shopping"></i>'
     . '<h3>কার্ট খালি</h3><p>চেকআউট করতে প্রথমে পণ্য যোগ করুন।</p>'
     . '<a class="btn-pill" href="'.BASE_URL.'/shop.php">শপিং শুরু করুন</a></div></div>';
  include __DIR__.'/includes/partials_footer.php';
  exit;
}

$ids  = array_keys($cart);
$ph   = implode(',', array_fill(0, count($ids), '?'));
$stm  = $pdo->prepare("SELECT id, name, price, image, tags, stock FROM products WHERE id IN ($ph) AND active=1");
$stm->execute($ids);
$rows = $stm->fetchAll();

$items = [];
$subtotal = 0.0;
$stockAdjusted = false;
foreach ($rows as $r) {
  $pid   = (int)$r['id'];
  $stock = max(0, (int)($r['stock'] ?? 0));
  if ($stock === 0) { $stockAdjusted = true; continue; }
  $qty = max(1, (int)($cart[$pid] ?? 1));
  if ($qty > $stock) { $qty = $stock; $stockAdjusted = true; }
  $line = $qty * (float)$r['price'];
  $subtotal += $line;
  $items[] = ['p'=>$r, 'qty'=>$qty, 'line'=>$line];
}

if ($stockAdjusted) {
  $clean = [];
  foreach ($items as $it) { $clean[(int)$it['p']['id']] = (int)$it['qty']; }
  if ($clean) $_SESSION['cart'] = $clean; else unset($_SESSION['cart']);
}

if (!$items) {
  include __DIR__.'/includes/partials_header.php';
  echo '<div class="wrap"><div class="empty-state"><i class="fa-solid fa-ban"></i>'
     . '<h3>সব পণ্য স্টক আউট</h3><p>কার্টের পণ্যগুলো এখন পাওয়া যাচ্ছে না।</p>'
     . '<a class="btn-pill" href="'.BASE_URL.'/shop.php">শপিং শুরু করুন</a></div></div>';
  include __DIR__.'/includes/partials_footer.php';
  exit;
}

/* Free Shipping */
function has_free_tag($tags){
  return (bool)preg_match('/\bfree(ship(ping)?)?\b/i', (string)$tags);
}
$all_have_free = count($items) > 0;
foreach ($items as $it) {
  if (!has_free_tag($it['p']['tags'] ?? '')) { $all_have_free = false; break; }
}

/* Shipping + payment */
require_once __DIR__.'/includes/delivery.php';
$SS = [];
try {
  $SS = $pdo->query("SELECT `key`,`value` FROM settings WHERE `key` IN ('cod_note')")
        ->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
} catch (Throwable $e) {}
$ship_global = dl_global($pdo);
$districts   = dl_all($pdo);
$codNote = trim((string)($SS['cod_note'] ?? ''));

$enabledPayments = enabled_payment_methods();
if (!$enabledPayments) {
  foreach (payment_methods_catalog() as $code => $meta) { $enabledPayments[$code] = $meta + ['code'=>$code]; break; }
}
$defaultPaymentCode = array_key_first($enabledPayments);
$defaultPayment = $defaultPaymentCode !== null ? $enabledPayments[$defaultPaymentCode] : null;
if (!$defaultPayment) {
  $defaultPaymentCode = 'COD';
  $defaultPayment = ['code'=>'COD','label'=>'Cash on Delivery','description'=>'পণ্য হাতে পেয়ে পেমেন্ট করুন।'];
  $enabledPayments[$defaultPaymentCode] = $defaultPayment;
}

/* Logged-in customer prefill */
$prefill = ['name'=>'', 'mobile'=>'', 'address'=>''];
$cid = (int)($_SESSION['customer_id'] ?? 0);
if ($cid > 0) {
  try {
    $cs = $pdo->prepare("SELECT name, mobile, address FROM customers WHERE id=?");
    $cs->execute([$cid]);
    if ($cr = $cs->fetch()) {
      $prefill = [
        'name'    => (string)($cr['name'] ?? ''),
        'mobile'  => (string)($cr['mobile'] ?? ''),
        'address' => (string)($cr['address'] ?? ''),
      ];
    }
  } catch (Throwable $e) {}
}

$initialShip = $all_have_free ? 0 : $ship_global;

include __DIR__.'/includes/partials_header.php';
?>
<div class="wrap" style="max-width:1020px">
  <div class="page-title-bar" style="margin-top:10px">
    <div>
      <h2>চেকআউট</h2>
      <p><?php echo count($items); ?> টি পণ্য · ডেলিভারি তথ্য দিন</p>
    </div>
    <a class="see-all" href="<?php echo BASE_URL; ?>/cart.php">কার্টে ফিরুন</a>
  </div>

  <?php if ($checkoutError): ?><div class="notice err"><?php echo h($checkoutError); ?></div><?php endif; ?>
  <?php if ($cartNotice): ?><div class="notice warn"><?php echo h($cartNotice); ?></div><?php endif; ?>

  <div style="display:grid;gap:14px;grid-template-columns:1fr" id="coLayout">
    <!-- Summary -->
    <div class="card-box" id="coSummary">
      <h3>অর্ডার সামারি</h3>

      <ul style="display:flex;flex-direction:column;gap:10px;margin-bottom:14px">
        <?php foreach ($items as $it):
          $img = img_url($it['p']['image'] ?? '');
        ?>
          <li style="display:flex;align-items:center;gap:10px">
            <img src="<?php echo h($img); ?>" alt="" loading="lazy"
                 style="width:46px;height:46px;border-radius:8px;object-fit:cover;background:#f8fafc;flex:0 0 auto">
            <div style="flex:1;min-width:0">
              <div class="co-it-name"><?php echo h($it['p']['name']); ?></div>
              <div class="co-it-qty">পরিমাণ: <?php echo bn_num((int)$it['qty']); ?> × <?php echo bn_money($it['p']['price']); ?></div>
            </div>
            <div class="co-it-price"><?php echo bn_money($it['line']); ?></div>
          </li>
        <?php endforeach; ?>
      </ul>

      <div class="sum-row"><span>সাবটোটাল</span>
        <span id="sumSubtotal" data-subtotal="<?php echo (float)$subtotal; ?>"><?php echo bn_money($subtotal); ?></span>
      </div>
      <div class="sum-row"><span>ডেলিভারি চার্জ</span>
        <span id="sumShip"
              data-free="<?php echo $all_have_free ? '1' : '0'; ?>"
              data-global="<?php echo $all_have_free ? 0 : (float)$ship_global; ?>">
          <?php echo bn_money($initialShip); ?>
        </span>
      </div>
      <div class="sum-row total"><span>মোট</span>
        <span id="sumTotal"><?php echo bn_money($subtotal + $initialShip); ?></span>
      </div>

      <?php if ($all_have_free): ?>
        <div class="notice ok" style="margin-top:12px;font-size:.76rem">
          <i class="fa-solid fa-truck-fast"></i> সব পণ্যে Free Shipping — ডেলিভারি চার্জ প্রযোজ্য নয়।
        </div>
      <?php endif; ?>
    </div>

    <!-- Form -->
    <div class="card-box" id="coForm">
      <h3>ডেলিভারি তথ্য</h3>

      <form method="post" action="<?php echo BASE_URL; ?>/place_order.php" id="checkoutForm" novalidate>
        <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf'] ?? ''); ?>">

        <?php if ($all_have_free): ?>
          <input type="hidden" name="free_shipping" value="1">
          <div class="notice ok" style="margin-bottom:12px">Free Shipping প্রযোজ্য</div>
        <?php endif; ?>

        <?php if ($districts): /* টেবিল খালি/অনুপস্থিত হলে ফিল্ড বাদ — অর্ডার আটকাবে না, গ্লোবাল চার্জ লাগবে */ ?>
        <div class="field" id="districtField">
          <label for="cDistrict">জেলা *</label>
          <select id="cDistrict" name="district_id" required>
            <option value="">— জেলা বাছুন —</option>
            <?php
              $curDiv = null;
              foreach ($districts as $d):
                if ($d['division'] !== $curDiv) {
                  if ($curDiv !== null) echo '</optgroup>';
                  echo '<optgroup label="' . h($d['division']) . ' বিভাগ">';
                  $curDiv = $d['division'];
                }
                $eff = $all_have_free ? 0 : dl_effective_fee($d, $ship_global);
            ?>
              <option value="<?php echo (int)$d['id']; ?>" data-fee="<?php echo (float)$eff; ?>"
                      data-en="<?php echo h($d['name_en']); ?>" data-alt="<?php echo h(dl_aliases((string)$d['name_en'])); ?>"><?php echo h($d['name_bn']); ?></option>
            <?php endforeach; if ($curDiv !== null) echo '</optgroup>'; ?>
          </select>
          <div class="help" id="districtHint"><?php echo $all_have_free ? 'ডেলিভারি চার্জ প্রযোজ্য নয়।' : 'জেলা বাছলে ডেলিভারি চার্জ হালনাগাদ হবে।'; ?></div>
        </div>
        <?php endif; ?>

        <div class="field">
          <label for="cName">নাম *</label>
          <input id="cName" name="customer_name" required maxlength="120"
                 value="<?php echo h($prefill['name']); ?>">
        </div>

        <div class="field" id="mobileField">
          <label for="mobile">মোবাইল নম্বর *</label>
          <input id="mobile" name="mobile" type="tel" required
                 placeholder="01XXXXXXXXX" inputmode="numeric" maxlength="14"
                 autocomplete="tel" aria-describedby="mobileHelp"
                 value="<?php echo h($prefill['mobile']); ?>">
          <div class="help" id="mobileHelp">
            ফরম্যাট: <code>01XXXXXXXXX</code> · <code>+8801…</code> দিলেও স্বয়ংক্রিয়ভাবে ঠিক হবে
          </div>
          <div class="err" id="mobileError" role="alert" style="display:none"></div>
          <input type="hidden" id="mobileE164" name="mobile_e164" value="">
        </div>

        <div class="field">
          <label for="cAddress">ঠিকানা *</label>
          <textarea id="cAddress" name="address" rows="3" required><?php echo h($prefill['address']); ?></textarea>
        </div>

        <div class="field">
          <label for="cNote">নোট (ঐচ্ছিক)</label>
          <textarea id="cNote" name="note" rows="2"></textarea>
        </div>

        <?php if ($enabledPayments): ?>
          <div class="field">
            <label>পেমেন্ট মেথড *</label>
            <div style="display:grid;gap:9px" id="paymentOptions">
              <?php foreach ($enabledPayments as $code => $m): $isActive = ($code === $defaultPaymentCode); ?>
                <label class="pay-opt<?php echo $isActive ? ' active' : ''; ?>">
                  <input type="radio" name="payment_method" value="<?php echo h($code); ?>"
                         <?php echo $isActive ? 'checked' : ''; ?>
                         data-label="<?php echo h($m['label']); ?>">
                  <div>
                    <div style="font-weight:800;font-size:.85rem"><?php echo h($m['label']); ?></div>
                    <div style="font-size:.75rem;color:var(--text-muted);margin-top:2px"><?php echo h($m['description']); ?></div>
                  </div>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($codNote !== ''): ?>
          <div class="cod-note" id="codNote" style="display:flex;gap:8px;align-items:flex-start;margin:0 0 12px;padding:10px 12px;border-radius:12px;background:#ecfdf5;border:1px solid #d1fae5;color:#065f46;font-size:.82rem;line-height:1.5">
            <i class="fa-solid fa-circle-info" style="margin-top:3px"></i>
            <span><?php echo nl2br(h($codNote)); ?></span>
          </div>
        <?php endif; ?>

        <button class="btn-pill wide" type="submit" style="padding:14px;margin-top:6px">
          <i class="fa-solid fa-check-circle"></i> অর্ডার নিশ্চিত করুন
        </button>
        <div class="help" style="text-align:center;margin-top:8px">
          অর্ডার করার পর আমাদের টিম ফোনে কনফার্ম করবে
        </div>
      </form>
    </div>
  </div>
</div>

<style>
.pay-opt{
  position:relative;display:block;padding:12px 14px;
  border:1.5px solid var(--border-color);border-radius:11px;background:#fff;cursor:pointer;
  transition:border-color .18s,background .18s;
}
.pay-opt input{position:absolute;inset:0;opacity:0;pointer-events:none}
.pay-opt.active{border-color:var(--primary-purple);background:#f7f5ff}
@media(min-width:860px){
  #coLayout{grid-template-columns:1.15fr .85fr;align-items:start}
  #coSummary{grid-column:2;grid-row:1;position:sticky;top:96px}
  #coForm{grid-column:1;grid-row:1}
}
/* জেলা বাছাই-বক্সের মূল স্টাইল: assets/js/district-picker.js */
.field.invalid .dsel-btn{border-color:#ef4444;background:#fef2f2}
.field .help.err{color:#dc2626;font-weight:600}
/* ---- অর্ডার সামারি টাইপোগ্রাফি ---- */
#coSummary .co-it-name{font-size:.92rem;font-weight:600;line-height:1.35;color:var(--text-main)}
#coSummary .co-it-qty{font-size:.8rem;font-weight:500;color:#64748b;margin-top:2px}
#coSummary .co-it-price{font-size:.95rem;font-weight:700;white-space:nowrap;color:var(--text-main);font-variant-numeric:tabular-nums}
#coSummary .sum-row{font-size:.92rem;font-variant-numeric:tabular-nums}
#coSummary .sum-row span:last-child{font-weight:600;color:var(--text-main)}
#coSummary .sum-row.total{font-size:1.1rem}
#coSummary .sum-row.total span:last-child{font-weight:800}
</style>

<script src="<?php echo BASE_URL; ?>/assets/js/district-picker.js"></script>
<script>
(function(){
  function moneyBD(n){
    n = Math.round(Number(n) || 0);
    return '৳' + n.toLocaleString('en-US');
  }

  const subtotalEl = document.getElementById('sumSubtotal');
  const shipEl     = document.getElementById('sumShip');
  const totalEl    = document.getElementById('sumTotal');
  const isFree     = (shipEl && shipEl.dataset.free === '1');

  const districtSel = document.getElementById('cDistrict');
  const hintEl      = document.getElementById('districtHint');

  function currentShip(){
    if (isFree) return 0;
    const opt = districtSel && districtSel.selectedOptions[0];
    if (opt && opt.value !== '' && opt.dataset.fee !== undefined) return Number(opt.dataset.fee);
    return Number(shipEl?.dataset.global || 0);
  }

  function recalc(){
    const subtotal = Number(subtotalEl?.dataset.subtotal || 0);
    const ship = currentShip();
    if (shipEl)  shipEl.textContent  = moneyBD(ship);
    if (totalEl) totalEl.textContent = moneyBD(subtotal + ship);
    if (hintEl && districtSel && districtSel.value !== '') {
      hintEl.classList.remove('err');
      hintEl.textContent = isFree ? 'ডেলিভারি চার্জ প্রযোজ্য নয়।' : (ship === 0 ? 'এই জেলায় ডেলিভারি ফ্রি।' : 'এই জেলার ডেলিভারি চার্জ ' + moneyBD(ship));
    }
  }

  if (districtSel) {
    districtSel.addEventListener('change', function(){
      document.getElementById('districtField').classList.remove('invalid');
      recalc();
    });
  }

  /* ---- খোঁজা-যোগ্য জেলা বাছাই (assets/js/district-picker.js). JS না চললে সাধারণ select-ই থাকে ---- */
  if (districtSel && window.DistrictPicker) {
    DistrictPicker.enhance(districtSel, {
      buttonId: 'cDistrictBtn',
      labelEl: document.querySelector('#districtField label'),
      showFees: !isFree,
      fmtMoney: moneyBD,
      removeRequired: true
    });
  }

  document.querySelectorAll('#paymentOptions .pay-opt').forEach(function(opt){
    opt.addEventListener('click', function(){
      document.querySelectorAll('#paymentOptions .pay-opt').forEach(o => o.classList.remove('active'));
      opt.classList.add('active');
    });
  });

  /* ---- BD mobile normalization ---- */
  const form       = document.getElementById('checkoutForm');
  const mobileIn   = document.getElementById('mobile');
  const mobileErr  = document.getElementById('mobileError');
  const mobileE164 = document.getElementById('mobileE164');

  function normalizeBDMobile(raw){
    let s = String(raw || '').trim().replace(/[^\d+]/g, '');
    if ((s.match(/\+/g) || []).length > 1) return {ok:false, reason:'অবৈধ "+" সিম্বল'};
    if (s.startsWith('+')) s = s.slice(1);
    if (/^01[3-9]\d{8}$/.test(s))       return {ok:true, local:s, e164:'+880' + s.slice(1)};
    if (/^8801[3-9]\d{8}$/.test(s))     return {ok:true, local:'0' + s.slice(3), e164:'+' + s};
    return {ok:false, reason:'বাংলাদেশি ১১ ডিজিট দিন (01 দিয়ে শুরু), যেমন 017xxxxxxxx।'};
  }

  function showErr(msg){
    mobileErr.style.display = 'block';
    mobileErr.textContent = msg;
    document.getElementById('mobileField').classList.add('invalid');
  }
  function clearErr(){
    mobileErr.style.display = 'none';
    mobileErr.textContent = '';
    document.getElementById('mobileField').classList.remove('invalid');
  }

  mobileIn.addEventListener('blur', function(){
    if (!mobileIn.value.trim()) { clearErr(); return; }
    const res = normalizeBDMobile(mobileIn.value);
    if (res.ok) { mobileIn.value = res.local; mobileE164.value = res.e164; clearErr(); }
    else showErr(res.reason);
  });

  form.addEventListener('submit', function(e){
    clearErr();
    if (districtSel && districtSel.value === '') {
      e.preventDefault();
      document.getElementById('districtField').classList.add('invalid');
      if (hintEl) { hintEl.textContent = 'অনুগ্রহ করে ডেলিভারির জেলা বেছে নিন।'; hintEl.classList.add('err'); }
      const dBtn = document.getElementById('cDistrictBtn');
      const dTarget = dBtn || districtSel;
      dTarget.scrollIntoView({block: 'center', behavior: 'smooth'});
      if (dBtn) dBtn.focus({preventScroll: true});
      return;
    }
    const res = normalizeBDMobile(mobileIn.value);
    if (!res.ok) { e.preventDefault(); showErr(res.reason); mobileIn.focus(); return; }
    mobileIn.value = res.local;
    mobileE164.value = res.e164;
  });

  recalc();
})();
</script>

<?php include __DIR__.'/includes/partials_footer.php'; ?>
