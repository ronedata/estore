<?php
// index.php — eStore home page (hero, categories, featured, new arrivals, info, payment)
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/functions.php';
increment_page_view('index');

$pdo = get_pdo();

/* ---------- Settings ---------- */
$S = [];
try {
  $S = $pdo->query("SELECT `key`,`value` FROM settings WHERE `key` IN
    ('store_name','store_about','hero_autoplay_ms','home_heading','home_notice',
     'info1_title','info1_sub','info2_title','info2_sub','info3_title','info3_sub')")
    ->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
} catch (Throwable $e) { $S = []; }
$heroDelay = max(1500, (int)($S['hero_autoplay_ms'] ?? 2500));

/* ---------- Hero sliders ---------- */
$heroSlides = [];
$sideCards  = [];
try {
  $stm = $pdo->query("SELECT * FROM sliders WHERE active=1 ORDER BY sort_order ASC, id DESC LIMIT 20");
  foreach ($stm->fetchAll() as $s) {
    if ($s['placement'] === 'hero') $heroSlides[] = $s; else $sideCards[] = $s;
  }
} catch (Throwable $e) { /* sliders টেবিল না থাকলে ফাঁকা */ }

function slider_url(array $s): string {
  $v = (string)($s['link_value'] ?? '');
  if ($s['link_type'] === 'product'  && $v !== '') return BASE_URL.'/product_details.php?id='.(int)$v;
  if ($s['link_type'] === 'category' && $v !== '') return BASE_URL.'/category_products.php?id='.(int)$v;
  if ($s['link_type'] === 'url'      && $v !== '') return $v;
  return BASE_URL.'/shop.php';
}

/* ---------- Categories ---------- */
$categories = [];
try {
  $categories = $pdo->query("SELECT id, name, image FROM categories WHERE active=1 ORDER BY sort_order, name LIMIT 16")->fetchAll();
} catch (Throwable $e) {
  try { $categories = $pdo->query("SELECT id, name, NULL AS image FROM categories ORDER BY name LIMIT 16")->fetchAll(); } catch (Throwable $e2) {}
}

/* ---------- হোম সেকশন (admin/home_sections.php থেকে ক্রম, ধরন ও পণ্য সংখ্যা) ---------- */
require_once __DIR__.'/includes/home_sections.php';
$homeSections = [];
try {
  foreach (hs_all($pdo, true) as $hsec) {
    $hsec['products'] = hs_products($pdo, $hsec);
    if ($hsec['products']) $homeSections[] = $hsec;   // পণ্য না থাকলে সেকশন লুকানো
  }
} catch (Throwable $e) {
  /* home_sections টেবিল না থাকলে সাধারণ ফলব্যাক */
  [$fallbackProducts] = get_products($pdo, '', '', 18);
  $homeSections = $fallbackProducts ? [[
    'id' => 0, 'title' => 'New Arrivals', 'subtitle' => 'সদ্য যোগ হওয়া নতুন সংগ্রহ',
    'show_see_all' => 0, 'products' => $fallbackProducts,
  ]] : [];
}

/* ---------- Payment methods ---------- */
$payMethods = [];
try {
  $payMethods = $pdo->query("SELECT code, label, logo FROM payment_methods WHERE is_enabled=1 ORDER BY sort_order, id")->fetchAll();
} catch (Throwable $e) {
  $payMethods = [['code'=>'COD','label'=>'Cash on Delivery','logo'=>null]];
}
$payIcons = [
  'COD'     => 'fa-money-bill-wave',
  'BKASH'   => 'fa-mobile-screen',
  'NAGAD'   => 'fa-mobile-screen-button',
  'ROCKET'  => 'fa-rocket',
  'VISA'    => 'fa-cc-visa',
  'MASTER'  => 'fa-cc-mastercard',
];

/* ---------- Hero fallback: slider না থাকলে প্রোডাক্ট থেকে ---------- */
$heroPool = [];
if (!$heroSlides) {
  try { $heroPool = $pdo->query("SELECT id, name, image FROM products WHERE active = 1 ORDER BY id DESC LIMIT 6")->fetchAll(); }
  catch (Throwable $e) { $heroPool = []; }
}
if (!$heroSlides && $heroPool) {
  foreach (array_slice($heroPool, 0, 4) as $p) {
    if (empty($p['image'])) continue;
    $heroSlides[] = [
      'image'      => $p['image'],
      'title'      => $p['name'],
      'btn_label'  => 'Shop Now',
      'btn_bg'     => '#ff7043',
      'btn_size'   => 'md',
      'btn_pos_x'  => 'center',
      'btn_pos_y'  => 'bottom',
      'link_type'  => 'product',
      'link_value' => $p['id'],
      'theme'      => 'purple',
    ];
  }
  if (!$sideCards && count($heroPool) > 4) {
    foreach (array_slice($heroPool, 4, 2) as $i => $p) {
      if (empty($p['image'])) continue;
      $sideCards[] = [
        'image'      => $p['image'],
        'title'      => $p['name'],
        'btn_label'  => 'Shop Now',
        'btn_size'   => 'md',
        'btn_pos_x'  => 'center',
        'btn_pos_y'  => 'bottom',
        'link_type'  => 'product',
        'link_value' => $p['id'],
        'theme'      => $i === 0 ? 'purple' : 'green',
      ];
    }
  }
}

include __DIR__.'/includes/partials_header.php';
?>

<?php if ($heroSlides): ?>
<section class="hero-grid-container">
  <div class="hero-main-slider">
    <div class="swiper hero-swiper">
      <div class="swiper-wrapper">
        <?php foreach ($heroSlides as $s): ?>
          <?php
            $bsz = (string)($s['btn_size'] ?? 'md');
            $bpx = (string)($s['btn_pos_x'] ?? 'center');
            $bpy = (string)($s['btn_pos_y'] ?? 'bottom');
            $bSize = in_array($bsz, ['sm','md','lg'], true) ? $bsz : 'md';
            $bX    = in_array($bpx, ['left','center','right'], true) ? $bpx : 'center';
            $bY    = in_array($bpy, ['top','middle','bottom'], true) ? $bpy : 'bottom';
            $sTitle = trim((string)($s['title'] ?? ''));
            $sSub   = trim((string)($s['subtitle'] ?? ''));
            $showTxt = ((int)($s['show_text'] ?? 0) === 1) && ($sTitle !== '' || $sSub !== '');
          ?>
          <div class="swiper-slide">
            <div class="hero-card-content at-<?php echo $bX; ?> at-<?php echo $bY; ?>">
              <a class="hero-img" href="<?php echo h(slider_url($s)); ?>">
                <img src="<?php echo h(img_url($s['image'])); ?>" alt="<?php echo h($s['title'] ?: 'ব্যানার'); ?>"
                     loading="<?php echo $s === $heroSlides[0] ? 'eager' : 'lazy'; ?>" decoding="async"
                     onerror="this.onerror=null;this.src='<?php echo h(img_fallback_url()); ?>'">
                <?php if ($showTxt): ?><span class="hero-scrim" aria-hidden="true"></span><?php endif; ?>
              </a>
              <?php if ($showTxt): ?>
                <div class="hero-body">
                  <div class="hero-copy">
                    <?php if ($sTitle !== ''): ?>
                      <h2 class="hero-copy-title"><?php echo h($sTitle); ?></h2>
                    <?php endif; ?>
                    <?php if ($sSub !== ''): ?>
                      <p class="hero-copy-sub"><?php echo h($sSub); ?></p>
                    <?php endif; ?>
                  </div>
                  <div class="hero-action">
                    <a class="btn-hero-shop is-<?php echo $bSize; ?>" href="<?php echo h(slider_url($s)); ?>"
                       style="background:<?php echo h($s['btn_bg'] ?: '#ff7043'); ?>">
                      <?php echo h($s['btn_label'] ?: 'Shop Now'); ?> <i class="fa-solid fa-chevron-right"></i>
                    </a>
                  </div>
                </div>
              <?php else: ?>
                <div class="hero-action">
                  <a class="btn-hero-shop is-<?php echo $bSize; ?>" href="<?php echo h(slider_url($s)); ?>"
                     style="background:<?php echo h($s['btn_bg'] ?: '#ff7043'); ?>">
                    <?php echo h($s['btn_label'] ?: 'Shop Now'); ?> <i class="fa-solid fa-chevron-right"></i>
                  </a>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (count($heroSlides) > 1): ?><div class="swiper-pagination"></div><?php endif; ?>
    </div>
  </div>

  <?php if ($sideCards): ?>
    <div class="hero-side-cards">
      <?php foreach (array_slice($sideCards, 0, 2) as $s): ?>
        <?php
          $ssz = (string)($s['btn_size'] ?? 'md');
          $spx = (string)($s['btn_pos_x'] ?? 'center');
          $spy = (string)($s['btn_pos_y'] ?? 'bottom');
          $sSize = in_array($ssz, ['sm','md','lg'], true) ? $ssz : 'md';
          $sX    = in_array($spx, ['left','center','right'], true) ? $spx : 'center';
          $sY    = in_array($spy, ['top','middle','bottom'], true) ? $spy : 'bottom';
          $sdTitle = trim((string)($s['title'] ?? ''));
          $sdSub   = trim((string)($s['subtitle'] ?? ''));
          $sdShow  = ((int)($s['show_text'] ?? 0) === 1) && ($sdTitle !== '' || $sdSub !== '');
        ?>
        <div class="side-card theme-<?php echo h($s['theme'] ?: 'purple'); ?> at-<?php echo $sX; ?> at-<?php echo $sY; ?>">
          <a class="side-img" href="<?php echo h(slider_url($s)); ?>">
            <img src="<?php echo h(img_url($s['image'])); ?>" alt="<?php echo h($s['title'] ?: 'ব্যানার'); ?>" loading="lazy" decoding="async"
                 onerror="this.onerror=null;this.src='<?php echo h(img_fallback_url()); ?>'">
            <?php if ($sdShow): ?><span class="hero-scrim" aria-hidden="true"></span><?php endif; ?>
          </a>
          <?php if ($sdShow): ?>
            <div class="hero-body">
              <div class="hero-copy side-copy">
                <?php if ($sdTitle !== ''): ?>
                  <h3 class="hero-copy-title"><?php echo h($sdTitle); ?></h3>
                <?php endif; ?>
                <?php if ($sdSub !== ''): ?>
                  <p class="hero-copy-sub"><?php echo h($sdSub); ?></p>
                <?php endif; ?>
              </div>
              <div class="side-action">
                <a class="btn-side-shop is-<?php echo $sSize; ?>" href="<?php echo h(slider_url($s)); ?>">
                  <?php echo h($s['btn_label'] ?: 'Shop Now'); ?> <i class="fa-solid fa-chevron-right"></i>
                </a>
              </div>
            </div>
          <?php else: ?>
            <div class="side-action">
              <a class="btn-side-shop is-<?php echo $sSize; ?>" href="<?php echo h(slider_url($s)); ?>">
                <?php echo h($s['btn_label'] ?: 'Shop Now'); ?> <i class="fa-solid fa-chevron-right"></i>
              </a>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php else: ?>
<section class="wrap reveal" style="margin-top:14px">
  <div class="hero-empty">
    <h2><?php echo h($S['home_heading'] ?? ($S['store_name'] ?? APP_NAME)); ?>-এ স্বাগতম</h2>
    <p><?php echo h($S['home_notice'] ?? 'সেরা দামে অরিজিনাল পণ্য, ক্যাশ অন ডেলিভারি সুবিধা।'); ?></p>
    <a href="<?php echo BASE_URL; ?>/shop.php" class="btn-pill" style="margin-top:12px">
      <i class="fa-solid fa-store"></i> শপিং শুরু করুন
    </a>
  </div>
</section>
<?php endif; ?>

<?php if ($categories): ?>
<section class="categories-section reveal" aria-label="ক্যাটেগরি">
  <?php foreach ($categories as $c): ?>
    <a class="category-item" href="<?php echo BASE_URL; ?>/category_products.php?id=<?php echo (int)$c['id']; ?>">
      <div class="category-icon-box">
        <?php if (!empty($c['image'])): ?>
          <img src="<?php echo h(img_url($c['image'])); ?>" alt="<?php echo h($c['name']); ?>" loading="lazy" decoding="async"
               onerror="this.onerror=null;this.src='<?php echo h(img_fallback_url()); ?>'">
        <?php else: ?>
          <i class="fa-solid fa-tag"></i>
        <?php endif; ?>
      </div>
      <span><?php echo h($c['name']); ?></span>
    </a>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php foreach ($homeSections as $hsec): ?>
<section class="home-section" data-section-id="<?php echo (int)$hsec['id']; ?>">
  <div class="section-title-wrapper reveal">
    <h2><?php echo h($hsec['title']); ?></h2>
    <?php if (trim((string)($hsec['subtitle'] ?? '')) !== ''): ?>
      <p><?php echo h($hsec['subtitle']); ?></p>
    <?php endif; ?>
  </div>
  <div class="products-grid-container">
    <?php foreach ($hsec['products'] as $i => $card): ?>
      <?php include __DIR__.'/includes/partials_product_card.php'; ?>
    <?php endforeach; ?>
  </div>
  <?php if (!empty($hsec['show_see_all']) && !empty($hsec['id'])): ?>
    <div class="section-see-all-wrap reveal">
      <a class="section-see-all" href="<?php echo h(hs_see_all_url($hsec)); ?>">
        সব দেখুন <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
        <span class="sr-only">— <?php echo h($hsec['title']); ?></span>
      </a>
    </div>
  <?php endif; ?>
</section>
<?php endforeach; ?>

<?php if (!$homeSections): ?>
<div class="wrap">
  <div class="empty-state">
    <i class="fa-solid fa-box-open"></i>
    <h3>এখনো কোনো পণ্য নেই</h3>
    <p>অ্যাডমিন প্যানেল থেকে প্রোডাক্ট যোগ করুন।</p>
    <a href="<?php echo BASE_URL; ?>/admin/login.php" class="btn-pill">অ্যাডমিন লগইন</a>
  </div>
</div>
<?php endif; ?>

<section class="info-bar-section reveal">
  <div class="info-box">
    <i class="fa-solid fa-truck-fast"></i>
    <div>
      <h4><?php echo h($S['info1_title'] ?? 'দ্রুত ডেলিভারি'); ?></h4>
      <p><?php echo h($S['info1_sub'] ?? '৩–৫ দিনের মধ্যে'); ?></p>
    </div>
  </div>
  <div class="info-box">
    <i class="fa-solid fa-hand-holding-dollar"></i>
    <div>
      <h4><?php echo h($S['info2_title'] ?? 'ক্যাশ অন ডেলিভারি'); ?></h4>
      <p><?php echo h($S['info2_sub'] ?? 'পণ্য হাতে পেয়ে পেমেন্ট'); ?></p>
    </div>
  </div>
  <div class="info-box">
    <i class="fa-solid fa-shield-halved"></i>
    <div>
      <h4><?php echo h($S['info3_title'] ?? '১০০% অরিজিনাল'); ?></h4>
      <p><?php echo h($S['info3_sub'] ?? 'কোয়ালিটি গ্যারান্টি'); ?></p>
    </div>
  </div>
</section>

<section class="payment-service-section reveal">
  <div class="service-left">
    <div class="service-icon-box"><i class="fa-solid fa-credit-card"></i></div>
    <div>
      <h3>পেমেন্ট মেথড</h3>
      <p>ক্যাশ অন ডেলিভারি ও ডিজিটাল পেমেন্ট</p>
    </div>
  </div>
  <div class="payment-logos">
    <?php foreach ($payMethods as $m): ?>
      <div class="pay-card" title="<?php echo h($m['label']); ?>">
        <?php if (!empty($m['logo'])): ?>
          <img src="<?php echo h($m['logo']); ?>" alt="<?php echo h($m['label']); ?>">
        <?php else: ?>
          <i class="fa-solid <?php echo $payIcons[strtoupper($m['code'])] ?? 'fa-money-bill'; ?>"></i>
          <span><?php echo h($m['code']); ?></span>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</section>

<?php if (count($heroSlides) > 1): ?>
<script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js" defer></script>
<script>
window.addEventListener('DOMContentLoaded', function(){
  var el = document.querySelector('.hero-swiper');
  if (!el || typeof Swiper === 'undefined') return;
  var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  new Swiper(el, {
    loop: true,
    speed: 600,
    autoplay: reduce ? false : { delay: <?php echo $heroDelay; ?>, disableOnInteraction: false },
    pagination: { el: '.hero-swiper .swiper-pagination', clickable: true },
    a11y: { enabled: true }
  });
});
</script>
<?php endif; ?>

<?php include __DIR__.'/includes/partials_footer.php'; ?>
