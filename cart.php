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
                <span class="cr-price"><?php echo money_bd($it['line']); ?></span>
                <button type="button" class="cr-remove"
                        onclick="cartRemove(<?php echo $pid; ?>)">
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
        <a class="btn-pill" style="padding:14px" href="<?php echo BASE_URL; ?>/checkout.php">
          <i class="fa-solid fa-arrow-right"></i> চেকআউট করুন
        </a>
        <button class="btn-pill ghost" type="submit" style="padding:12px">
          <i class="fa-solid fa-rotate"></i> পরিমাণ আপডেট করুন
        </button>
        <a class="btn-pill ghost" href="<?php echo BASE_URL; ?>/shop.php" style="padding:12px">আরও কিনুন</a>
      </div>
    </form>
  <?php endif; ?>
</div>

<script>
function fmtBD(n){
  return '৳' + Math.round(n).toLocaleString('en-IN');
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
}

function cartRecalc(){
  var total = 0;
  document.querySelectorAll('#cartForm .qtyInput').forEach(function(inp){
    var max = parseInt(inp.dataset.max || '1', 10) || 1;
    var v = Math.min(max, Math.max(1, parseInt(inp.value, 10) || 1));
    inp.value = v;
    total += v * parseFloat(inp.dataset.price || '0');
  });
  var s = document.getElementById('cartSubtotal');
  var t = document.getElementById('cartTotal');
  if (s) s.textContent = fmtBD(total);
  if (t) t.textContent = fmtBD(total);
}

document.querySelectorAll('#cartForm .qtyInput').forEach(function(inp){
  inp.addEventListener('change', cartRecalc);
});

function cartRemove(pid){
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
      return;
    }
    window.location.reload();
  })
    .catch(function(){ window.location.reload(); });
}
</script>

<?php include __DIR__.'/includes/partials_footer.php'; ?>
