<?php
// product_details.php — product page with gallery, sku, stock, rating, reviews
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/functions.php';

$pdo = get_pdo();
$id  = (int)($_GET['id'] ?? 0);

$stm = $pdo->prepare("
  SELECT p.*, c.name AS cat_name
  FROM products p
  LEFT JOIN categories c ON c.id = p.category_id
  WHERE p.id = ? AND p.active = 1
");
$stm->execute([$id]);
$p = $stm->fetch();

if (!$p) {
  http_response_code(404);
  include __DIR__.'/includes/partials_header.php';
  echo '<div class="wrap"><div class="empty-state"><i class="fa-solid fa-triangle-exclamation"></i>'
     . '<h3>প্রোডাক্ট পাওয়া যায়নি</h3><p>এটি সরিয়ে ফেলা হয়েছে অথবা লিংক ভুল।</p>'
     . '<a class="btn-pill" href="'.BASE_URL.'/shop.php">শপে ফিরে যান</a></div></div>';
  include __DIR__.'/includes/partials_footer.php';
  exit;
}

increment_page_view('product');
/* প্রোডাক্ট-ভিত্তিক ভিউ (কোন পণ্য কতবার দেখা হয়েছে) */
increment_page_view('product_'.$id);

$pid   = (int)$p['id'];
$stock = max(0, (int)$p['stock']);
$isOut = $stock <= 0;
$price = (float)$p['price'];
$old   = (float)($p['compare_at_price'] ?? 0);
$discountPct = ($old > $price && $old > 0) ? (int)round((($old - $price) / $old) * 100) : 0;

/* ---------- Gallery ---------- */
$gallery = [];
if (!empty($p['image']) && img_url($p['image']) !== img_fallback_url()) {
  $gallery[] = ['type'=>'image', 'url'=>img_url($p['image'])];
}
try {
  $gi = $pdo->prepare("SELECT image FROM product_images WHERE product_id=? ORDER BY id ASC");
  $gi->execute([$pid]);
  foreach ($gi->fetchAll(PDO::FETCH_COLUMN) as $g) {
    $gu = img_url($g);
    if ($gu && $gu !== img_fallback_url() && !in_array($gu, array_column($gallery, 'url'), true)) {
      $gallery[] = ['type'=>'image','url'=>$gu];
    }
  }
} catch (Throwable $e) {}
if (!$gallery) $gallery[] = ['type'=>'image','url'=>img_fallback_url()];

$videos = [];
try {
  $gv = $pdo->prepare("SELECT * FROM product_videos WHERE product_id=? ORDER BY id ASC");
  $gv->execute([$pid]);
  $videos = $gv->fetchAll();
} catch (Throwable $e) {}

/* ---------- Rating & sold (delivered orders) ---------- */
$avgRating = 0; $ratingCount = 0;
try {
  $rs = $pdo->prepare("SELECT AVG(rating) avg_r, COUNT(*) cnt FROM product_reviews WHERE product_id=? AND status='approved'");
  $rs->execute([$pid]);
  $row = $rs->fetch();
  $avgRating   = $row ? round((float)$row['avg_r'], 1) : 0;
  $ratingCount = $row ? (int)$row['cnt'] : 0;
} catch (Throwable $e) {}

$soldCount = (int)($p['sold_count'] ?? 0);
try {
  $ss = $pdo->prepare("SELECT COALESCE(SUM(oi.qty),0) FROM order_items oi JOIN orders o ON o.id=oi.order_id
                       WHERE oi.product_id=? AND o.status='delivered'");
  $ss->execute([$pid]);
  $soldCount = max($soldCount, (int)$ss->fetchColumn());
} catch (Throwable $e) {}

/* ---------- Reviews list ---------- */
$reviews = [];
try {
  $rv = $pdo->prepare("SELECT name, rating, comment, image, created_at FROM product_reviews
                       WHERE product_id=? AND status='approved' ORDER BY created_at DESC LIMIT 30");
  $rv->execute([$pid]);
  $reviews = $rv->fetchAll();
} catch (Throwable $e) {}

/* ---------- Can this user review? ---------- */
$canReview   = false;
$reviewNote  = '';
$alreadyReviewed = false;
$myCustomerId = (int)($_SESSION['customer_id'] ?? 0);
if ($myCustomerId > 0) {
  try {
    $chk = $pdo->prepare("SELECT COUNT(*) FROM orders o
                          JOIN order_items oi ON oi.order_id = o.id
                          WHERE o.customer_id = ? AND oi.product_id = ? AND o.status IN ('delivered','shipped','processing')");
    $chk->execute([$myCustomerId, $pid]);
    $canReview = (int)$chk->fetchColumn() > 0;

    if ($canReview) {
      /* ইতিমধ্যে রিভিউ দিয়েছেন? সেটাও এখানেই দেখে নিই — নাহলে ফর্ম দেখিয়েও
         সাবমিটে ব্লক করতে হয় (বিভ্রান্তিকর UX)। */
      $dup = $pdo->prepare("SELECT status FROM product_reviews WHERE product_id=? AND customer_id=? LIMIT 1");
      $dup->execute([$pid, $myCustomerId]);
      $dupStatus = $dup->fetchColumn();
      if ($dupStatus !== false && $dupStatus !== null) {
        $alreadyReviewed = true;
        $canReview = false;
        $reviewNote = ($dupStatus === 'approved')
          ? 'আপনার রিভিউ প্রকাশিত হয়েছে। ধন্যবাদ!'
          : 'আপনার রিভিউ পর্যালোচনার জন্য জমা আছে। অনুমোদনের পর প্রকাশিত হবে।';
      }
    } else {
      $reviewNote = 'রিভিউ দিতে হলে আগে এই পণ্যটি কিনতে হবে।';
    }
  } catch (Throwable $e) {
    $reviewNote = 'রিভিউ সিস্টেম এখন উপলব্ধ নয়।';
  }
} else {
  $reviewNote = 'রিভিউ দিতে <a href="'.BASE_URL.'/account.php">লগইন করুন</a>।';
}

/* ---------- Related ---------- */
$related = [];
if (!empty($p['category_id'])) {
  try {
    $rq = $pdo->prepare("SELECT * FROM products WHERE active=1 AND category_id=? AND id<>? ORDER BY id DESC LIMIT 8");
    $rq->execute([(int)$p['category_id'], $pid]);
    $related = $rq->fetchAll();
  } catch (Throwable $e) {}
}

/* ---------- Wishlist state ---------- */
$inWishlist = false;
if ($myCustomerId > 0) {
  try {
    $wq = $pdo->prepare("SELECT 1 FROM wishlists WHERE customer_id=? AND product_id=? LIMIT 1");
    $wq->execute([$myCustomerId, $pid]);
    $inWishlist = (bool)$wq->fetchColumn();
  } catch (Throwable $e) {}
}

$flash = $_SESSION['review_flash'] ?? ''; unset($_SESSION['review_flash']);

include __DIR__.'/includes/partials_header.php';
?>

<div class="wrap">
  <nav style="font-size:.78rem;color:#94a3b8;margin:8px 0 14px">
    <a href="<?php echo BASE_URL; ?>/index.php">হোম</a>
    <?php if (!empty($p['cat_name'])): ?>
      › <a href="<?php echo BASE_URL; ?>/category_products.php?id=<?php echo (int)$p['category_id']; ?>"><?php echo h($p['cat_name']); ?></a>
    <?php endif; ?>
    › <span style="color:#475569"><?php echo h(mb_substr($p['name'],0,40)); ?></span>
  </nav>

  <div class="pd-wrap">
    <!-- Gallery -->
    <div class="pd-gallery">
      <div class="pd-main">
        <img id="pdMainImg" src="<?php echo h($gallery[0]['url']); ?>" alt="<?php echo h($p['name']); ?>" width="600" height="600">
        <?php if ($discountPct > 0): ?>
          <span class="pc-badge" style="top:12px;left:12px;font-size:12px;padding:5px 11px;background:#ff5252"><?php echo $discountPct; ?>% OFF</span>
        <?php endif; ?>
        <?php if ($isOut): ?>
          <span class="pc-out-badge" style="top:12px;right:12px;font-size:12px;padding:5px 11px">স্টক আউট</span>
        <?php endif; ?>
      </div>

      <?php if (count($gallery) > 1 || $videos): ?>
        <div class="pd-thumbs">
          <?php foreach ($gallery as $i => $g): ?>
            <button type="button" class="<?php echo $i===0?'active':''; ?>"
                    onclick="pdSelectImage(this,'<?php echo h($g['url']); ?>')"
                    aria-label="ছবি <?php echo $i+1; ?>">
              <img src="<?php echo h($g['url']); ?>" alt="" loading="lazy">
            </button>
          <?php endforeach; ?>
          <?php foreach ($videos as $v): ?>
            <?php if (($v['type'] ?? 'file') === 'youtube'): ?>
              <button type="button" onclick="pdShowVideo('<?php echo h($v['url']); ?>')" aria-label="ভিডিও">
                <span style="display:flex;align-items:center;justify-content:center;width:100%;height:100%;background:#0f172a;color:#fff"><i class="fa-solid fa-play"></i></span>
              </button>
            <?php else: ?>
              <button type="button" onclick="pdShowVideo('<?php echo h($v['url']); ?>','file')" aria-label="ভিডিও">
                <span style="display:flex;align-items:center;justify-content:center;width:100%;height:100%;background:#0f172a;color:#fff"><i class="fa-solid fa-play"></i></span>
              </button>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Info -->
    <div class="pd-info">
      <h1><?php echo h($p['name']); ?></h1>

      <div class="pd-meta">
        <?php if (!empty($p['cat_name'])): ?>
          <span class="badge-chip" style="background:#eef2ff;color:#4338ca"><?php echo h($p['cat_name']); ?></span>
        <?php endif; ?>
        <?php if (!empty($p['sku'])): ?><span>SKU: <?php echo h($p['sku']); ?></span><?php endif; ?>
        <?php if ($isOut): ?>
          <span class="badge-chip stock-out">স্টক আউট</span>
        <?php elseif ($stock <= 5): ?>
          <span class="badge-chip low">স্টকে মাত্র <?php echo $stock; ?> টি</span>
        <?php else: ?>
          <span style="color:#15803d;font-weight:700">স্টকে আছে (<?php echo $stock; ?> টি)</span>
        <?php endif; ?>
      </div>

      <div class="pd-meta">
        <?php if ($ratingCount > 0): ?>
          <span style="color:#f59e0b;font-size:.9rem">
            <?php echo str_repeat('★', (int)round($avgRating)) . str_repeat('☆', 5 - (int)round($avgRating)); ?>
          </span>
          <span><?php echo $avgRating; ?> রেটিং (<?php echo $ratingCount; ?>)</span>
        <?php else: ?>
          <span>এখনো রিভিউ নেই</span>
        <?php endif; ?>
        <?php if ($soldCount > 0): ?>
          <span>|</span><span><?php echo $soldCount; ?> Sold</span>
        <?php endif; ?>
      </div>

      <div class="pd-price">
        <span class="now"><?php echo money_bd($price); ?></span>
        <?php if ($old > $price): ?>
          <span class="was"><?php echo money_bd($old); ?></span>
          <span class="off"><?php echo $discountPct; ?>% ছাড়</span>
        <?php endif; ?>
      </div>

      <?php if (!$isOut): ?>
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">
          <span style="font-size:.82rem;font-weight:700;color:#475569">পরিমাণ:</span>
          <div class="qty-box">
            <button type="button" onclick="pdQty(-1)" aria-label="কমান">−</button>
            <input id="pdQty" type="number" value="1" min="1" max="<?php echo $stock; ?>" inputmode="numeric" aria-label="পরিমাণ">
            <button type="button" onclick="pdQty(1)" aria-label="বাড়ান">+</button>
          </div>
        </div>
      <?php endif; ?>

      <div class="pd-actions">
        <button type="button" class="btn-pill green" id="btnAdd"
                data-product-id="<?php echo $pid; ?>"
                onclick="return addToCart(this.dataset.productId, event, this)"
                <?php echo $isOut ? 'disabled' : ''; ?>>
          <i class="fa-solid <?php echo $isOut ? 'fa-ban' : 'fa-cart-plus'; ?>"></i>
          <?php echo $isOut ? 'স্টক নেই' : 'Add to Cart'; ?>
        </button>

        <?php if (!$isOut): ?>
          <a class="btn-pill order" href="<?php echo BASE_URL; ?>/checkout.php?buy=<?php echo $pid; ?>&qty=1" id="btnBuy">
            <i class="fa-solid fa-bolt"></i> Order Now
          </a>
        <?php endif; ?>

        <button type="button" class="btn-pill ghost" id="btnWish"
                onclick="toggleWishlist(<?php echo $pid; ?>, this)"
                aria-pressed="<?php echo $inWishlist ? 'true' : 'false'; ?>">
          <i class="<?php echo $inWishlist ? 'fa-solid' : 'fa-regular'; ?> fa-heart" style="<?php echo $inWishlist ? 'color:#e11d48' : ''; ?>"></i>
          <span><?php echo $inWishlist ? 'উইশলিস্টে আছে' : 'উইশলিস্ট'; ?></span>
        </button>
      </div>

      <div class="notice" style="margin-top:14px;font-size:.78rem">
        <i class="fa-solid fa-truck-fast"></i> সারা দেশে ডেলিভারি — ক্যাশ অন ডেলিভারি সুবিধা
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <div class="pd-tabs" role="tablist">
    <button type="button" class="active" data-tab="desc" role="tab">বিবরণ</button>
    <?php if (!empty($p['specifications'])): ?>
      <button type="button" data-tab="spec" role="tab">স্পেসিফিকেশন</button>
    <?php endif; ?>
    <button type="button" data-tab="reviews" role="tab">রিভিউ (<?php echo $ratingCount; ?>)</button>
  </div>

  <div class="pd-tab-panel" id="tab-desc">
    <?php if (!empty($p['description'])): ?>
      <?php echo nl2br(h($p['description'])); ?>
    <?php else: ?>
      <p style="color:#94a3b8">এই পণ্যের জন্য কোনো বিবরণ যোগ করা হয়নি।</p>
    <?php endif; ?>
  </div>

  <?php if (!empty($p['specifications'])): ?>
    <div class="pd-tab-panel" id="tab-spec" style="display:none">
      <?php
      $specs = $p['specifications'];
      $lines = preg_split('/\r\n|\r|\n/', $specs);
      $hasColon = false;
      foreach ($lines as $l) { if (strpos($l, ':') !== false) { $hasColon = true; break; } }
      ?>
      <?php if ($hasColon): ?>
        <table class="spec-table"><tbody>
          <?php foreach ($lines as $l):
            if (trim($l) === '') continue;
            $parts = explode(':', $l, 2);
            $k = trim($parts[0]); $v = trim($parts[1] ?? '');
          ?>
            <tr><th><?php echo h($k); ?></th><td><?php echo h($v); ?></td></tr>
          <?php endforeach; ?>
        </tbody></table>
      <?php else: ?>
        <?php echo nl2br(h($specs)); ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="pd-tab-panel" id="tab-reviews" style="display:none">
    <?php if ($flash): ?>
      <div class="notice <?php echo flash_is_ok($flash) ? 'ok' : 'err'; ?>">
        <?php echo h(flash_message($flash)); ?>
      </div>
    <?php endif; ?>

    <h3 style="font-size:.95rem;font-weight:800;margin-bottom:12px">কাস্টমার রিভিউ (<?php echo $ratingCount; ?>)</h3>

    <?php if (!$reviews): ?>
      <div class="notice">এখনো কোনো রিভিউ নেই। প্রথম রিভিউটি আপনিই দিন!</div>
    <?php else: ?>
      <?php foreach ($reviews as $r): ?>
        <div class="review-card">
          <div class="review-head">
            <span class="review-stars"><?php echo str_repeat('★', (int)$r['rating']) . str_repeat('☆', 5 - (int)$r['rating']); ?></span>
            <strong style="font-size:.85rem"><?php echo h($r['name']); ?></strong>
            <span class="review-date"><?php echo date('d M, Y', strtotime($r['created_at'])); ?></span>
          </div>
          <div class="review-body"><?php echo nl2br(h($r['comment'])); ?></div>
          <?php if (!empty($r['image'])): ?>
            <img src="<?php echo h($r['image']); ?>" alt="" style="max-height:100px;border-radius:8px;margin-top:8px" loading="lazy"
                 onerror="this.onerror=null;this.src='<?php echo h(img_fallback_url()); ?>'">
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <div class="card-box" style="margin-top:16px">
      <h3>রিভিউ লিখুন</h3>
      <?php if ($canReview): ?>
        <form method="post" action="<?php echo BASE_URL; ?>/review_submit.php" enctype="multipart/form-data" id="reviewForm">
          <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf'] ?? ''); ?>">
          <input type="hidden" name="product_id" value="<?php echo $pid; ?>">

          <div class="field">
            <label>আপনার রেটিং</label>
            <div class="star-input">
              <?php for ($s = 5; $s >= 1; $s--): ?>
                <input type="radio" name="rating" id="star<?php echo $s; ?>" value="<?php echo $s; ?>" <?php echo $s===5?'checked':''; ?>>
                <label for="star<?php echo $s; ?>" title="<?php echo $s; ?> স্টার">★</label>
              <?php endfor; ?>
            </div>
          </div>

          <div class="field">
            <label for="rvName">আপনার নাম *</label>
            <input id="rvName" name="name" required maxlength="120"
                   value="<?php echo h($_SESSION['customer_name'] ?? ''); ?>">
          </div>

          <div class="field">
            <label for="rvComment">আপনার রিভিউ *</label>
            <textarea id="rvComment" name="comment" rows="4" required maxlength="2000"></textarea>
          </div>

          <div class="field">
            <label for="rvImage">ছবি (ঐচ্ছিক)</label>
            <input id="rvImage" type="file" name="image" accept="image/jpeg,image/png,image/webp">
            <div class="help">সর্বোচ্চ 2MB · JPG/PNG/WEBP</div>
          </div>

          <button class="btn-pill" type="submit"><i class="fa-solid fa-paper-plane"></i> রিভিউ জমা দিন</button>
          <div class="help" style="margin-top:8px">রিভিউ অ্যাডমিন অনুমোদনের পর সাইটে দেখা যাবে।</div>
        </form>
      <?php else: ?>
        <div class="notice"><?php echo $reviewNote; ?></div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($related): ?>
    <div class="section-head">
      <div><h2>একই ক্যাটেগরির আরও পণ্য</h2></div>
      <?php if (!empty($p['category_id'])): ?>
        <a class="see-all" href="<?php echo BASE_URL; ?>/category_products.php?id=<?php echo (int)$p['category_id']; ?>">সব দেখুন</a>
      <?php endif; ?>
    </div>
    <div class="product-grid">
      <?php foreach ($related as $i => $card): ?>
        <?php include __DIR__.'/includes/partials_product_card.php'; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<script>
function pdSelectImage(btn, url){
  document.getElementById('pdMainImg').src = url;
  document.querySelectorAll('.pd-thumbs button').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
}
function pdShowVideo(url, kind){
  var main = document.getElementById('pdMainImg');
  if (kind === 'file') {
    main.outerHTML = '<video id="pdMainImg" controls autoplay style="width:100%;aspect-ratio:1/1;object-fit:contain;background:#000"><source src="'+url+'"></video>';
  } else {
    var id = url;
    var m = url.match(/[?&]v=([^&]+)/) || url.match(/(?:youtu\.be\/|embed\/)([\w-]{6,})/);
    if (m) id = m[1];
    main.outerHTML = '<iframe id="pdMainImg" src="https://www.youtube.com/embed/'+encodeURIComponent(id)+'?autoplay=1" style="width:100%;aspect-ratio:1/1;border:0;background:#000" allow="autoplay; fullscreen" allowfullscreen></iframe>';
  }
}
function pdQty(delta){
  var i = document.getElementById('pdQty');
  if (!i) return;
  var max = parseInt(i.getAttribute('max') || '1', 10) || 1;
  var v = Math.min(max, Math.max(1, (parseInt(i.value, 10) || 1) + delta));
  i.value = v;
  var buy = document.getElementById('btnBuy');
  if (buy) buy.href = '<?php echo BASE_URL; ?>/checkout.php?buy=<?php echo $pid; ?>&qty=' + v;
  var add = document.getElementById('btnAdd');
  if (add) add.dataset.qty = v;
}
document.getElementById('pdQty')?.addEventListener('change', function(){
  var max = parseInt(this.getAttribute('max') || '1', 10) || 1;
  this.value = Math.min(max, Math.max(1, parseInt(this.value, 10) || 1));
  pdQty(0);
});

document.querySelectorAll('.pd-tabs button').forEach(function(b){
  b.addEventListener('click', function(){
    document.querySelectorAll('.pd-tabs button').forEach(x => x.classList.remove('active'));
    b.classList.add('active');
    document.querySelectorAll('.pd-tab-panel').forEach(p => p.style.display = 'none');
    var el = document.getElementById('tab-' + b.dataset.tab);
    if (el) el.style.display = '';
  });
});
<?php if ($flash): ?>
document.querySelector('.pd-tabs button[data-tab="reviews"]')?.click();
<?php endif; ?>

function toggleWishlist(pid, btn){
  var fd = new URLSearchParams();
  fd.set('product_id', String(pid));
  var meta = document.querySelector('meta[name="csrf-token"]');
  if (meta && meta.content) fd.set('csrf', meta.content);
  fetch('<?php echo BASE_URL; ?>/wishlist_toggle.php', {
    method:'POST',
    headers:{'X-Requested-With':'fetch','Content-Type':'application/x-www-form-urlencoded'},
    body: fd.toString()
  }).then(r => r.json()).then(function(d){
    if (!d || !d.ok){
      if (d && d.login_required) { window.location.href = '<?php echo BASE_URL; ?>/account.php'; return; }
      estorePopup(false, 'ত্রুটি', (d && d.message) || 'উইশলিস্টে যোগ করা যায়নি।');
      return;
    }
    var icon = btn.querySelector('i');
    var label = btn.querySelector('span');
    if (d.in_wishlist) {
      icon.className = 'fa-solid fa-heart'; icon.style.color = '#e11d48';
      label.textContent = 'উইশলিস্টে আছে';
      btn.setAttribute('aria-pressed','true');
    } else {
      icon.className = 'fa-regular fa-heart'; icon.style.color = '';
      label.textContent = 'উইশলিস্ট';
      btn.setAttribute('aria-pressed','false');
    }
  }).catch(function(){ estoreToast('উইশলিস্ট আপডেট ব্যর্থ', 'err'); });
}
</script>

<?php include __DIR__.'/includes/partials_footer.php'; ?>
