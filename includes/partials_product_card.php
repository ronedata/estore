<?php
/**
 * includes/partials_product_card.php
 * mFeex-style product card (fruit-card) — index, shop, category, deals,
 * product_details (related), wishlist সবাই ব্যবহার করে।
 *
 * প্রয়োজন: $card (products রো + ঐচ্ছিক cat_name, sold_count)
 * ঐচ্ছিক:  $cardAttrs, $cardIndex
 */
if (!isset($card) || !is_array($card)) return;

$cardIndex  = isset($cardIndex) ? (int)$cardIndex : 0;
$cardAttrs  = $cardAttrs ?? '';

$pid    = (int)$card['id'];
$name   = (string)($card['name'] ?? '');
$price  = (float)($card['price'] ?? 0);
$old    = (float)($card['compare_at_price'] ?? 0);
$stock  = max(0, (int)($card['stock'] ?? 0));
$isOut  = ($stock <= 0);
$img    = img_url($card['image'] ?? '');
$sold   = (int)($card['sold_count'] ?? 0);

$discountPct = 0;
if ($old > $price && $old > 0) {
  $discountPct = (int)round((($old - $price) / $old) * 100);
}

/* ৮ রঙের সাইকেল (রেফারেন্স ডিজাইনের মতো) */
$palette = ['#ff5252','#ff9800','#2196f3','#e91e63','#4caf50','#9c27b0','#00bcd4','#ff5722'];
$accent  = $card['badge_color'] ?: $palette[$cardIndex % count($palette)];
$detailUrl = BASE_URL.'/product_details.php?id='.$pid;
?>
<article class="fruit-card <?php echo $isOut ? 'is-out' : ''; ?> reveal <?php echo h($cardAttrs); ?>">
  <?php if ($discountPct > 0): ?>
    <span class="discount-badge" style="background-color:<?php echo h($accent); ?>"><?php echo $discountPct; ?>% OFF</span>
  <?php endif; ?>
  <?php if ($isOut): ?>
    <span class="stock-badge">স্টক নেই</span>
  <?php endif; ?>

  <a href="<?php echo h($detailUrl); ?>" class="fruit-img-link" aria-label="<?php echo h($name); ?>">
    <div class="fruit-img">
      <img src="<?php echo h($img); ?>" alt="<?php echo h($name); ?>" loading="lazy" decoding="async"
           width="300" height="300" onerror="this.onerror=null;this.src='<?php echo h(img_fallback_url()); ?>'">
    </div>
  </a>

  <h3><a href="<?php echo h($detailUrl); ?>"><?php echo h($name); ?></a></h3>

  <div class="fruit-price">
    <span class="main-price"><?php echo money_bd($price); ?></span>
    <?php if ($old > $price): ?><span class="strike-price"><?php echo money_bd($old); ?></span><?php endif; ?>
    <?php if ($sold > 0): ?><span class="fruit-sold"><?php echo $sold; ?> Delivered</span><?php endif; ?>
  </div>

  <button type="button"
          class="btn-add-cart"
          style="background-color:<?php echo h($isOut ? '#94a3b8' : $accent); ?>"
          data-product-id="<?php echo $pid; ?>"
          <?php echo $isOut ? 'disabled' : ''; ?>
          onclick="return addToCart(this.dataset.productId, event, this)">
    <i class="fa-solid <?php echo $isOut ? 'fa-ban' : 'fa-cart-shopping'; ?>"></i>
    <?php echo $isOut ? 'স্টক নেই' : 'Shop Now'; ?>
  </button>

  <?php if (!empty($cardRemoveWishlist)): ?>
    <button type="button" class="wl-remove-btn" title="উইশলিস্ট থেকে সরান"
            data-product-id="<?php echo $pid; ?>"
            onclick="return removeFromWishlist(this.dataset.productId, this)">
      <i class="fa-regular fa-trash-can"></i> সরান
    </button>
  <?php endif; ?>
</article>
