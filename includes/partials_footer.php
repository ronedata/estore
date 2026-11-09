<?php
// includes/partials_footer.php — rainbow glass footer + bottom nav + scripts
$pdo_f = get_pdo();

$F = [];
try {
  $F = $pdo_f->query("SELECT `key`,`value` FROM settings
                      WHERE `key` IN (
                        'store_name','store_logo','store_phone','store_email','store_about',
                        'store_address','delivery_dhaka','delivery_nationwide','cod_note',
                        'social_facebook','social_instagram','social_whatsapp'
                      )")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
} catch (Throwable $e) { $F = []; }

$store_name = $F['store_name'] ?? APP_NAME;
$store_logo = img_url($F['store_logo'] ?? '', '');
$phone      = $F['store_phone'] ?? '';
$email      = $F['store_email'] ?? '';
$about      = $F['store_about'] ?? '';
$address    = $F['store_address'] ?? '';
$wa         = $F['social_whatsapp'] ?? '';
$waDigits   = preg_replace('/\D+/', '', $wa);

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$cartCount = 0;
if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
  foreach ($_SESSION['cart'] as $q) { if ((int)$q > 0) $cartCount++; } /* ব্যাজ = আলাদা পণ্যের সংখ্যা (পরিমাণের যোগফল নয়) */
}
function nav_active(array $pages, string $current): string {
  return in_array($current, $pages, true) ? 'active' : '';
}
?>
</main>

<!-- ===== Footer ===== -->
<footer class="site-footer" id="interactiveFooter">
  <div class="footer-spotlight" id="footerSpotlight"></div>
  <div class="orb-glow-1"></div>
  <div class="orb-glow-2"></div>

  <div class="footer-container">
    <div class="footer-glass-box">
      <div class="footer-grid">

        <div class="footer-brand">
          <?php if ($store_logo): ?>
            <img src="<?php echo h($store_logo); ?>" alt="<?php echo h($store_name); ?>" class="footer-logo">
          <?php else: ?>
            <div class="brand-title"><?php echo h($store_name); ?></div>
          <?php endif; ?>
          <p><?php echo h(mb_substr($about, 0, 140)); ?><?php echo mb_strlen($about) > 140 ? '…' : ''; ?></p>
          <span class="footer-guarantee"><i class="fa-solid fa-shield-halved"></i> ১০০% অরিজিনাল প্রোডাক্ট গ্যারান্টি</span>
        </div>

        <div class="footer-contact-grid">
          <?php if ($address): ?>
            <div class="contact-card">
              <div class="icon-wrapper"><i class="fa-solid fa-location-dot"></i></div>
              <div class="contact-info">
                <label class="animated-label">লোকেশন</label>
                <span><?php echo h($address); ?></span>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($phone): ?>
            <a href="tel:<?php echo h($phone); ?>" class="contact-card">
              <div class="icon-wrapper"><i class="fa-solid fa-phone"></i></div>
              <div class="contact-info">
                <label class="animated-label">কল করুন</label>
                <span><?php echo h($phone); ?></span>
              </div>
            </a>
          <?php endif; ?>

          <?php if ($email): ?>
            <a href="mailto:<?php echo h($email); ?>" class="contact-card">
              <div class="icon-wrapper"><i class="fa-solid fa-envelope"></i></div>
              <div class="contact-info">
                <label class="animated-label">ইমেইল</label>
                <span><?php echo h($email); ?></span>
              </div>
            </a>
          <?php endif; ?>

          <?php if ($waDigits): ?>
            <a href="https://wa.me/<?php echo h($waDigits); ?>" target="_blank" rel="noopener" class="contact-card">
              <div class="icon-wrapper" style="animation-delay:-3s"><i class="fa-brands fa-whatsapp"></i></div>
              <div class="contact-info">
                <label class="animated-label" style="animation-delay:-3s">WhatsApp</label>
                <span><?php echo h($phone ?: $waDigits); ?></span>
              </div>
            </a>
          <?php endif; ?>
        </div>
      </div>

      <div class="footer-bottom">
        <p>&copy; <?php echo date('Y'); ?> <?php echo h($store_name); ?>. সর্বস্বত্ব সংরক্ষিত।</p>
        <div class="footer-developer">
          <a href="<?php echo BASE_URL; ?>/shop.php">শপিং শুরু করুন</a>
        </div>
      </div>
    </div>
  </div>
</footer>

<!-- ===== Bottom nav (mobile only) ===== -->
<nav class="bottom-nav" aria-label="মোবাইল নেভিগেশন">
  <a href="<?php echo BASE_URL; ?>/index.php" class="<?php echo nav_active(['index.php'], $currentPage); ?>">
    <i class="fa-solid fa-house"></i><span>হোম</span>
  </a>
  <a href="<?php echo BASE_URL; ?>/shop.php" class="<?php echo nav_active(['shop.php','category_products.php'], $currentPage); ?>">
    <i class="fa-solid fa-layer-group"></i><span>ক্যাটেগরি</span>
  </a>
  <a href="<?php echo BASE_URL; ?>/deals.php" class="<?php echo nav_active(['deals.php'], $currentPage); ?>">
    <i class="fa-solid fa-tags"></i><span>ডিলস</span>
  </a>
  <a href="<?php echo BASE_URL; ?>/cart.php" class="<?php echo nav_active(['cart.php'], $currentPage); ?>">
    <div class="nav-icon-wrapper"><i class="fa-solid fa-bag-shopping"></i></div>
    <span>কার্ট</span>
    <span class="bn-badge <?php echo $cartCount > 0 ? 'show' : ''; ?>" id="bnCartCount"><?php echo $cartCount; ?></span>
  </a>
  <a href="<?php echo BASE_URL; ?>/account.php" class="<?php echo nav_active(['account.php','account_dashboard.php'], $currentPage); ?>">
    <i class="fa-regular fa-user"></i><span>অ্যাকাউন্ট</span>
  </a>
</nav>

<script>
document.addEventListener('DOMContentLoaded', function(){
  var footer = document.getElementById('interactiveFooter');
  if (footer) {
    footer.addEventListener('mousemove', function(e){
      var rect = footer.getBoundingClientRect();
      footer.style.setProperty('--mouse-x', (e.clientX - rect.left) + 'px');
      footer.style.setProperty('--mouse-y', (e.clientY - rect.top) + 'px');
    });
  }
});
</script>
<?php if (($currentPage ?? '') === 'index.php' && function_exists('ann_render_popup')) { ann_render_popup(get_pdo()); } ?>
<script src="<?php echo BASE_URL; ?>/assets/js/home.js"></script>
</body>
</html>
