<?php
require_once __DIR__.'/includes/config.php';
$pdo = get_pdo();

$cart  = $_SESSION['cart'] ?? [];
$items = [];
$subtotal = 0;

if ($cart) {
  $ids = array_keys($cart);
  if ($ids) {
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $stm = $pdo->prepare("SELECT id, name, price, image, stock FROM products WHERE id IN ($ph) AND active=1");
    $stm->execute($ids);
    foreach ($stm->fetchAll() as $r) {
      $stock = max(0, (int)($r['stock'] ?? 0));
      if ($stock === 0) continue;
      $q = max(1, (int)($cart[$r['id']] ?? 1));
      if ($q > $stock) $q = $stock;
      $line = $q * (float)$r['price'];
      $subtotal += $line;
      $items[] = ['p'=>$r, 'qty'=>$q, 'line'=>$line, 'stock'=>$stock];
    }
  }
}

$cartNotice = $_SESSION['cart_notice'] ?? ''; unset($_SESSION['cart_notice']);
$flashError = $_SESSION['flash_error']  ?? ''; unset($_SESSION['flash_error']);

include __DIR__.'/includes/partials_header.php';
?>
<div class="wrap">
  <div class="page-title-bar" style="margin-top:10px">
    <div>
      <h2>কার্ট</h2>
      <p><?php echo count($items); ?> টি পণ্য</p>
    </div>
    <?php if ($items): ?>
      <a class="see-all" href="<?php echo BASE_URL; ?>/shop.php">আরও কিনুন</a>
    <?php endif; ?>
  </div>

  <?php if ($cartNotice): ?><div class="notice warn"><?php echo h($cartNotice); ?></div><?php endif; ?>
  <?php if ($flashError): ?><div class="notice err"><?php echo h($flashError); ?></div><?php endif; ?>

  <?php if (!$items): ?>
    <div class="empty-state">
      <i class="fa-solid fa-bag-shopping"></i>
      <h3>কার্ট খালি</h3>
      <p>পছন্দের পণ্য যোগ করে শপিং সম্পন্ন করুন।</p>
      <a class="btn-pill" href="<?php echo BASE_URL; ?>/shop.php"><i class="fa-solid fa-store"></i> শপিং শুরু করুন</a>
    </div>
  <?php else: ?>
    <form method="post" action="<?php echo BASE_URL; ?>/cart_update.php" id="cartForm">
  <?php echo csrf_field(); ?>
      <input type="hidden" name="next" value="cart.php">

      <div class="cart-list">
        <?php foreach ($items as $it):
          $pid = (int)$it['p']['id'];
          $img = img_url($it['p']['image'] ?? '');
        ?>
          <div class="cart-row">
            <a href="<?php echo BASE_URL; ?>/product_details.php?id=<?php echo $pid; ?>">
              <img src="<?php echo h($img); ?>" alt="<?php echo h($it['p']['name']); ?>" loading="lazy">
            </a>
            <div class="cr-body">
              <a class="cr-title" href="<?php echo BASE_URL; ?>/product_details.php?id=<?php echo $pid; ?>">
                <?php echo h($it['p']['name']); ?>
              </a>

              <div class="cr-meta">
                <span><span class="cr-price"><?php echo money_bd($it['p']['price']); ?></span><span class="cr-unit">প্রতিটি</span></span>
                <button type="button" class="cr-remove" aria-label="<?php echo h($it['p']['name']); ?> কার্ট থেকে সরান"
                        onclick="cartRemove(<?php echo $pid; ?>, this)">
                  <i class="fa-regular fa-trash-can"></i> সরান
                </button>
              </div>

              <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <div class="qty-box">
                  <button type="button" onclick="cartQty(this,-1)">−</button>
                  <input type="number" class="qtyInput"
                         name="qty[<?php echo $pid; ?>]"
                         value="<?php echo (int)$it['qty']; ?>"
                         min="1" max="<?php echo (int)$it['stock']; ?>"
                         data-max="<?php echo (int)$it['stock']; ?>"
                         data-price="<?php echo (float)$it['p']['price']; ?>"
                         inputmode="numeric" aria-label="পরিমাণ">
                  <button type="button" onclick="cartQty(this,1)">+</button>
                </div>
                <?php if ($it['stock'] <= 5): ?>
                  <small style="color:#b45309;font-weight:700;font-size:.72rem">স্টকে মাত্র <?php echo (int)$it['stock']; ?> টি</small>
                <?php endif; ?>
                <span class="cr-line" aria-label="এই পণ্যের মোট"><?php echo money_bd($it['line']); ?></span>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="cart-summary">
        <div class="sum-row"><span>সাবটোটাল</span><span id="cartSubtotal"><?php echo money_bd($subtotal); ?></span></div>
        <div class="sum-row total"><span>মোট</span><span id="cartTotal"><?php echo money_bd($subtotal); ?></span></div>
        <div style="font-size:.72rem;color:#94a3b8;margin-top:6px">ডেলিভারি চার্জ চেকআউটে যোগ হবে।</div>
      </div>

      <div style="display:flex;flex-direction:column;gap:9px;margin-bottom:26px">
        <button class="btn-pill" type="submit" name="next" value="checkout.php" id="cartCheckoutBtn" style="padding:14px;width:100%;border:0;cursor:pointer">
          <i class="fa-solid fa-arrow-right"></i> চেকআউট করুন
        </button>
        <button class="btn-pill ghost" type="submit" id="cartUpdateBtn" style="padding:12px">
          <i class="fa-solid fa-rotate"></i> পরিমাণ আপডেট করুন
        </button>
        <a class="btn-pill ghost" href="<?php echo BASE_URL; ?>/shop.php" style="padding:12px">আরও কিনুন</a>
      </div>
    </form>
  <?php endif; ?>
</div>

<style>
.cart-row .cr-unit{font-size:.72rem;color:#94a3b8;margin-left:2px}
.cart-row .cr-line{margin-left:auto;font-size:1rem;font-weight:800;color:var(--text-main);white-space:nowrap;font-variant-numeric:tabular-nums}
.cart-row .cr-remove{display:inline-flex;align-items:center;gap:6px;min-height:34px;padding:6px 13px;border:1px solid #fecaca;border-radius:999px;background:#fef2f2;color:#dc2626;font-size:.8rem;font-weight:600;line-height:1;cursor:pointer;transition:background .15s,border-color .15s,color .15s,transform .1s,box-shadow .15s}
.cart-row .cr-remove i{font-size:.82rem}
.cart-row .cr-remove:hover{background:#fee2e2;border-color:#fca5a5;color:#b91c1c}
.cart-row .cr-remove:active{transform:scale(.96)}
.cart-row .cr-remove:focus-visible{outline:none;box-shadow:0 0 0 3px rgba(239,68,68,.28)}
.cart-row .cr-remove:disabled,.cart-row .cr-remove.is-busy{opacity:.65;cursor:progress;pointer-events:none}
@media (max-width:480px){.cart-row .cr-remove{min-height:38px;padding:7px 15px}}
</style>
<script>
function fmtBD(n){
  return '৳' + Math.round(n).toLocaleString('en-US');
}

/* পরিমাণ বদলালে কিছুক্ষণ পর নিজে সার্ভারে সেভ — অন্য পেজে গেলেও/ফিরে এলেও পরিমাণ হারায় না */
function cartSetBadges(n){
  ['cartCount', 'bnCartCount'].forEach(function(id){
    var el = document.getElementById(id);
    if (!el) return;
    el.textContent = n;
    el.classList.toggle('show', n > 0);
  });
}
var cartSaveTimer = null;
function cartSaveSoon(){
  clearTimeout(cartSaveTimer);
  cartSaveTimer = setTimeout(cartSave, 350);
}
function cartSave(){
  var form = document.getElementById('cartForm');
  if (!form) return;
  var fd = new FormData(form);
  fetch(form.action, {method: 'POST', body: fd, redirect: 'manual', keepalive: true})
    .then(function(){ if (typeof window.refreshCartCount === 'function') window.refreshCartCount(); })
    .catch(function(){});
}

function cartQty(btn, delta){
  var box = btn.closest('.qty-box');
  var inp = box.querySelector('.qtyInput');
  var max = parseInt(inp.dataset.max || '1', 10) || 1;
  var v = Math.min(max, Math.max(1, (parseInt(inp.value, 10) || 1) + delta));
  if (delta > 0 && v === max && (parseInt(inp.value,10)||1) === max) {
    estoreToast('স্টকে মাত্র ' + max + ' টি আছে', 'err');
  }
  inp.value = v;
  cartRecalc();
  cartSaveSoon();
}

function cartRecalc(){
  var total = 0, kinds = 0;
  document.querySelectorAll('#cartForm .qtyInput').forEach(function(inp){
    var max = parseInt(inp.dataset.max || '1', 10) || 1;
    var v = Math.min(max, Math.max(1, parseInt(inp.value, 10) || 1));
    inp.value = v;
    var line = v * parseFloat(inp.dataset.price || '0');
    total += line;
    kinds++;
    var row = inp.closest('.cart-row');
    var le = row ? row.querySelector('.cr-line') : null;
    if (le) le.textContent = fmtBD(line);
  });
  cartSetBadges(kinds);
  var s = document.getElementById('cartSubtotal');
  var t = document.getElementById('cartTotal');
  if (s) s.textContent = fmtBD(total);
  if (t) t.textContent = fmtBD(total);
}

document.querySelectorAll('#cartForm .qtyInput').forEach(function(inp){
  inp.addEventListener('change', function(){ cartRecalc(); cartSaveSoon(); });
});

function cartRemove(pid, btn){
  clearTimeout(cartSaveTimer);
  var orig = null;
  if (btn) {
    if (btn.disabled) return;
    orig = btn.innerHTML;
    btn.disabled = true;
    btn.classList.add('is-busy');
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> সরানো হচ্ছে…';
  }
  function cartRemoveReset(){
    if (btn && orig !== null) { btn.disabled = false; btn.classList.remove('is-busy'); btn.innerHTML = orig; }
  }
  var fd = new URLSearchParams();
  fd.set('product_id', String(pid));
  fd.set('next', 'cart.php');
  var meta = document.querySelector('meta[name="csrf-token"]');
  if (meta && meta.content) fd.set('csrf', meta.content);
  fetch('<?php echo BASE_URL; ?>/cart_remove.php', {
    method: 'POST',
    headers: {'X-Requested-With':'fetch','Content-Type':'application/x-www-form-urlencoded'},
    body: fd.toString()
  }).then(function(r){
    if (r && (r.status === 403 || r.status === 419)) {
      if (typeof estoreToast === 'function') estoreToast('সেশন মেয়াদোত্তীর্ণ — পেজ রিফ্রেশ করুন।', 'err');
      cartRemoveReset();
      return;
    }
    window.location.reload();
  })
    .catch(function(){ window.location.reload(); });
}
</script>

<?php include __DIR__.'/includes/partials_footer.php'; ?>
