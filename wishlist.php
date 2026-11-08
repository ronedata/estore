<?php
// wishlist.php — customer's saved products
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/customer_auth.php';

$customer = current_customer();
$pdo = get_pdo();

if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }

/* AJAX remove */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove') {
  $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
         && in_array(strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']), ['xmlhttprequest', 'fetch'], true);

  $removed = false;
  if ($customer && csrf_verify()) {
    $pid = (int)($_POST['product_id'] ?? 0);
    if ($pid > 0) {
      $pdo->prepare("DELETE FROM wishlists WHERE customer_id=? AND product_id=?")
          ->execute([(int)$customer['id'], $pid]);
      $removed = true;
    }
  }

  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    if (!$customer) { http_response_code(401); echo json_encode(['ok'=>false,'login_required'=>true]); exit; }
    if (!$removed) { http_response_code(403); echo json_encode(['ok'=>false,'message'=>'টোকেন অবৈধ।']); exit; }
    echo json_encode(['ok'=>true, 'count'=>(int)$pdo->query("SELECT COUNT(*) FROM wishlists WHERE customer_id=".(int)$customer['id'])->fetchColumn()]);
    exit;
  }

  header('Location: wishlist.php'); exit;
}

$items = [];
if ($customer) {
  try {
    $stm = $pdo->prepare("
      SELECT p.*, c.name AS cat_name, w.created_at AS saved_at,
             (SELECT COALESCE(SUM(oi.qty),0) FROM order_items oi
              JOIN orders o ON o.id=oi.order_id
              WHERE oi.product_id=p.id AND o.status='delivered') AS sold_dynamic
      FROM wishlists w
      JOIN products p ON p.id = w.product_id AND p.active = 1
      LEFT JOIN categories c ON c.id = p.category_id
      WHERE w.customer_id = ?
      ORDER BY w.created_at DESC
    ");
    $stm->execute([(int)$customer['id']]);
    $items = $stm->fetchAll();
    foreach ($items as &$it) {
      $it['sold_count'] = max((int)($it['sold_count'] ?? 0), (int)($it['sold_dynamic'] ?? 0));
    }
    unset($it);
  } catch (Throwable $e) {}
}

include __DIR__.'/includes/partials_header.php';
?>
<div class="wrap">
  <div class="page-title-bar" style="margin-top:10px">
    <div>
      <h2><i class="fa-solid fa-heart" style="color:#e11d48"></i> উইশলিস্ট</h2>
      <p><?php echo count($items); ?> টি পণ্য সংরক্ষিত</p>
    </div>
    <?php if ($items): ?>
      <a class="see-all" href="<?php echo BASE_URL; ?>/shop.php">আরও দেখুন</a>
    <?php endif; ?>
  </div>

  <?php if (!$customer): ?>
    <div class="empty-state">
      <i class="fa-solid fa-lock"></i>
      <h3>লগইন করুন</h3>
      <p>উইশলিস্ট দেখতে ও সংরক্ষণ করতে অ্যাকাউন্টে প্রবেশ করুন।</p>
      <a class="btn-pill" href="<?php echo BASE_URL; ?>/account.php?next=<?php echo urlencode('/wishlist.php'); ?>">
        <i class="fa-solid fa-right-to-bracket"></i> লগইন / রেজিস্ট্রেশন
      </a>
    </div>
  <?php elseif (!$items): ?>
    <div class="empty-state">
      <i class="fa-regular fa-heart"></i>
      <h3>উইশলিস্ট খালি</h3>
      <p>পছন্দের পণ্যে ♥ আইকনে ক্লিক করে সংরক্ষণ করুন।</p>
      <a class="btn-pill" href="<?php echo BASE_URL; ?>/shop.php">শপিং শুরু করুন</a>
    </div>
  <?php else: ?>
    <div class="product-grid">
      <?php $cardRemoveWishlist = true; ?>
      <?php foreach ($items as $i => $card): ?>
        <?php include __DIR__.'/includes/partials_product_card.php'; ?>
      <?php endforeach; ?>
      <?php $cardRemoveWishlist = false; ?>
    </div>

    <script>
    function removeFromWishlist(pid, btn){
      if (!confirm('উইশলিস্ট থেকে সরাতে চান?')) return false;
      var fd = new URLSearchParams();
      fd.set('action', 'remove');
      fd.set('product_id', String(pid));
      var meta = document.querySelector('meta[name="csrf-token"]');
      if (meta && meta.content) fd.set('csrf', meta.content);
      if (btn) btn.disabled = true;
      fetch('<?php echo BASE_URL; ?>/wishlist.php', {
        method: 'POST',
        headers: {'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},
        body: fd.toString()
      }).then(function(r){ return r.json().catch(function(){ return {}; }); })
        .then(function(d){
          if (d && d.ok) {
            var card = btn ? btn.closest('.fruit-card') : null;
            if (card) card.remove();
            var p = document.querySelector('.page-title-bar p');
            if (p && typeof d.count !== 'undefined') p.textContent = d.count + ' টি পণ্য সংরক্ষিত';
            if (typeof estoreToast === 'function') estoreToast('উইশলিস্ট থেকে সরানো হয়েছে', 'ok');
          } else {
            if (btn) btn.disabled = false;
            if (typeof estoreToast === 'function') estoreToast((d && d.message) || 'সরানো যায়নি', 'err');
          }
        })
        .catch(function(){ if (btn) btn.disabled = false; });
      return false;
    }
    </script>
  <?php endif; ?>
</div>
<?php include __DIR__.'/includes/partials_footer.php'; ?>
