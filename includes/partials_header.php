<?php
// includes/partials_header.php — glass header + sidebar drawer + bottom nav (mFeex style)
require_once __DIR__.'/config.php';

$pdo_h = get_pdo();

$S = [];
try {
  $S = $pdo_h->query("SELECT `key`,`value` FROM settings
                      WHERE `key` IN (
                        'store_name','store_logo','store_favicon','store_phone','store_email',
                        'store_about','delivery_dhaka','delivery_nationwide','cod_note',
                        'social_facebook','social_instagram','social_whatsapp','store_address',
                        'theme_primary','theme_secondary'
                      )")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
} catch (Throwable $e) { $S = []; }

$store_name  = $S['store_name']  ?? APP_NAME;
$store_logo  = img_url($S['store_logo'] ?? '', '');
$favicon     = img_url($S['store_favicon'] ?? '', '');
$theme_pri   = $S['theme_primary'] ?? '#0284c7';
$theme_sec   = $S['theme_secondary'] ?? '#ff7043';

$qParam   = trim($_GET['q'] ?? '');
$currentPage = basename($_SERVER['PHP_SELF'] ?? 'index.php');
$pageTitle = isset($pageTitle) && $pageTitle !== '' ? $pageTitle : '';
$cartCount = 0;
if (!empty($_SESSION['cart']) && is_array($_SESSION['cart'])) {
  foreach ($_SESSION['cart'] as $q) { $cartCount += (int)$q; }
}
?>
<!doctype html>
<html lang="bn">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="csrf-token" content="<?php echo h(csrf_token()); ?>">
  <title><?php echo h($pageTitle ? $pageTitle.' — '.$store_name : $store_name); ?></title>
  <?php if ($favicon): ?>
    <link rel="icon" href="<?php echo h($favicon); ?>">
  <?php endif; ?>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Hind+Siliguri:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/theme.css">
  <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/components.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css" media="print" onload="this.media='all'">

  <style>
    :root{ --primary-purple: <?php echo h($theme_pri); ?>; --brand-blue: <?php echo h($theme_pri); ?>; --accent-orange: <?php echo h($theme_sec); ?>; }
  </style>
</head>
<body class="mfeex-theme">

<!-- ===== Header ===== -->
<header class="main-header">
  <div class="header-container">
    <div class="brand-area">
      <button class="icon-btn" id="menuToggle" aria-label="মেনু খুলুন" aria-expanded="false" aria-controls="sidebarDrawer">
        <i class="fa-solid fa-bars"></i>
      </button>
      <a href="<?php echo BASE_URL; ?>/index.php" aria-label="<?php echo h($store_name); ?> হোম">
        <?php if ($store_logo): ?>
          <img src="<?php echo h($store_logo); ?>" alt="<?php echo h($store_name); ?>" class="site-logo">
        <?php else: ?>
          <span class="site-title"><?php echo h($store_name); ?></span>
        <?php endif; ?>
      </a>
    </div>

    <div class="search-box">
      <i class="fa-solid fa-magnifying-glass search-icon"></i>
      <input type="search" id="liveSearchInput" name="q" value="<?php echo h($qParam); ?>"
             placeholder="প্রোডাক্ট খুঁজুন..." autocomplete="off"
             aria-label="প্রোডাক্ট খুঁজুন" role="combobox" aria-expanded="false" aria-controls="suggestBox">
    </div>

    <div class="header-actions">
      <a href="<?php echo BASE_URL; ?>/cart.php" class="icon-btn" aria-label="কার্ট">
        <i class="fa-solid fa-bag-shopping"></i>
        <span class="cart-badge <?php echo $cartCount > 0 ? 'show' : ''; ?>" id="cartCount"><?php echo $cartCount; ?></span>
      </a>
    </div>
  </div>
</header>

<!-- Live search suggestion (header-এর বাইরে — overflow:hidden ক্লিপ এড়াতে) -->
<div id="suggestBox" class="suggest-box" role="listbox" aria-label="সার্চ সাজেশন"></div>

<!-- ===== Sidebar Drawer ===== -->
<div class="sidebar-overlay" id="sidebarOverlay" hidden></div>
<aside class="sidebar-drawer" id="sidebarDrawer" aria-hidden="true" aria-label="প্রধান মেনু">
  <div class="sidebar-header">
    <h3 class="sidebar-title">মেনু</h3>
    <button class="sidebar-close-btn" id="closeSidebar" aria-label="মেনু বন্ধ করুন">
      <i class="fa-solid fa-xmark"></i>
    </button>
  </div>

  <ul class="sidebar-menu">
    <li><a href="<?php echo BASE_URL; ?>/index.php"><i class="fa-solid fa-house"></i><span>হোম</span></a></li>
    <li><a href="<?php echo BASE_URL; ?>/shop.php"><i class="fa-solid fa-store"></i><span>সব প্রোডাক্ট</span></a></li>
    <li><a href="<?php echo BASE_URL; ?>/deals.php"><i class="fa-solid fa-fire"></i><span>হট ডিলস</span></a></li>
    <li><a href="<?php echo BASE_URL; ?>/wishlist.php"><i class="fa-solid fa-heart"></i><span>উইশলিস্ট</span></a></li>
    <li><a href="<?php echo BASE_URL; ?>/track_orders.php"><i class="fa-solid fa-truck-fast"></i><span>অর্ডার ট্র্যাক</span></a></li>
    <li><a href="<?php echo BASE_URL; ?>/account.php"><i class="fa-solid fa-user"></i><span>আমার অ্যাকাউন্ট</span></a></li>
    <li><a href="<?php echo BASE_URL; ?>/about.php"><i class="fa-solid fa-circle-info"></i><span>আমাদের সম্পর্কে</span></a></li>
    <li><a href="<?php echo BASE_URL; ?>/privacy_policy.php"><i class="fa-solid fa-user-shield"></i><span>প্রাইভেসি পলিসি</span></a></li>
    <li><a href="<?php echo BASE_URL; ?>/terms.php"><i class="fa-solid fa-file-contract"></i><span>শর্তাবলী</span></a></li>
    <li><a href="<?php echo BASE_URL; ?>/contact.php"><i class="fa-solid fa-headset"></i><span>যোগাযোগ</span></a></li>
  </ul>
</aside>

<!-- ===== Cart popup ===== -->
<div class="cart-popup-overlay" id="cartPopupOverlay" role="dialog" aria-modal="true" aria-labelledby="cartPopupTitle">
  <div class="cart-popup">
    <div class="cart-popup-icon ok" id="cartPopupIcon"><i class="fa-solid fa-check"></i></div>
    <h3 id="cartPopupTitle">সফল</h3>
    <p id="cartPopupMsg">কার্টে যোগ হয়েছে</p>
    <div class="cart-popup-actions">
      <a href="<?php echo BASE_URL; ?>/cart.php" class="btn-pill">কার্ট দেখুন</a>
      <button type="button" class="btn-pill ghost" id="cartPopupClose">ঠিক আছে</button>
    </div>
  </div>
</div>

<div class="toast-stack" id="toastStack" aria-live="polite"></div>

<main class="wrap" style="padding-top:4px">
