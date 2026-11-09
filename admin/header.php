<?php
// admin/header.php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__ . '/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* Fallback esc helper (যদি গ্লোবালি সংজ্ঞায়িত না থাকে) */
if (!function_exists('h')) {
  function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

/* Active class helper */
$SELF = basename($_SERVER['PHP_SELF']);
function is_active($files){
  $self = basename($_SERVER['PHP_SELF']);
  if (is_array($files)) { return in_array($self, $files, true) ? 'active' : ''; }
  return $self === $files ? 'active' : '';
}

/* Pagination helper */
function render_pagination(int $current_page, int $total_pages, array $params = []): void {
  if ($total_pages <= 1) return;

  // page প্যারামিটারটি বাদ দিয়ে বাকিগুলো নিয়ে query string তৈরি করুন
  unset($params['page']);
  $query_string = http_build_query($params);
  $base_url = basename($_SERVER['PHP_SELF']) . '?' . $query_string;

  // কতগুলো পেজ নম্বর দেখানো হবে
  $window = 2;
  $start = max(1, $current_page - $window);
  $end = min($total_pages, $current_page + $window);

  echo '<nav aria-label="Page navigation"><ul class="pagination justify-content-center">';

  // Previous button
  $prev_disabled = $current_page <= 1 ? 'disabled' : '';
  echo "<li class='page-item {$prev_disabled}'><a class='page-link' href='{$base_url}&page=".($current_page - 1)."'>&laquo;</a></li>";

  if ($start > 1) {
    echo "<li class='page-item'><a class='page-link' href='{$base_url}&page=1'>1</a></li>";
    if ($start > 2) echo "<li class='page-item disabled'><span class='page-link'>...</span></li>";
  }

  for ($i = $start; $i <= $end; $i++) {
    $active = $i === $current_page ? 'active' : '';
    echo "<li class='page-item {$active}'><a class='page-link' href='{$base_url}&page={$i}'>{$i}</a></li>";
  }

  if ($end < $total_pages) {
    if ($end < $total_pages - 1) echo "<li class='page-item disabled'><span class='page-link'>...</span></li>";
    echo "<li class='page-item'><a class='page-link' href='{$base_url}&page={$total_pages}'>{$total_pages}</a></li>";
  }

  // Next button
  $next_disabled = $current_page >= $total_pages ? 'disabled' : '';
  echo "<li class='page-item {$next_disabled}'><a class='page-link' href='{$base_url}&page=".($current_page + 1)."'>&raquo;</a></li>";
  echo '</ul></nav>';
}
?>
<!doctype html>
<html lang="bn">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin - <?php echo h(defined('APP_NAME') ? APP_NAME : 'Shop'); ?></title>
  <?php
    $adminFavicon = '';
    try {
      $stFav = get_pdo()->prepare("SELECT value FROM settings WHERE `key`='store_favicon' LIMIT 1");
      $stFav->execute();
      $adminFavicon = img_url((string)$stFav->fetchColumn(), '');
    } catch (Throwable $e) { $adminFavicon = ''; }
    if ($adminFavicon === '') $adminFavicon = BASE_URL . '/assets/favicon.ico';
  ?>
  <link rel="icon" href="<?php echo h($adminFavicon); ?>">

  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

  <!-- Font Awesome (CDN) -->
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

  <style>
    :root{
      --sidebar-bg:#0f172a;
      --sidebar-hover:#1e293b;
      --sidebar-text:#cbd5e1;
      --sidebar-active:#ffffff;
    }
    body{overflow-x:hidden;background:#f6f8fb}
    .layout{
      display:grid; grid-template-columns:240px 1fr; min-height:100vh;
    }
    /* Topbar (mobile) */
    .topbar{
      background:var(--sidebar-bg); color:#fff; height:56px;
      display:flex; align-items:center; padding:0 12px;
    }
    .topbar .btn-nav{
      border:0; background:transparent; color:#fff; font-size:1.25rem;
    }

    /* Sidebar */
    .sidebar{
      background:var(--sidebar-bg); color:#fff; position:sticky; top:0; height:100vh;
      padding:16px; overflow-y:auto; overscroll-behavior:contain;
      scrollbar-width:thin; scrollbar-color:rgba(148,163,184,.55) transparent;
      display:flex; flex-direction:column;
    }
    .sidebar::-webkit-scrollbar{width:8px}
    .sidebar::-webkit-scrollbar-track{background:transparent}
    .sidebar::-webkit-scrollbar-thumb{background:rgba(148,163,184,.45);border-radius:8px}
    .sidebar::-webkit-scrollbar-thumb:hover{background:rgba(148,163,184,.7)}
    /* মেনু অংশটাই স্ক্রল হবে; ব্র্যান্ড উপরে স্থির থাকবে */
    .sidebar .navy{flex:1 1 auto; min-height:0; overflow-y:auto; overscroll-behavior:contain;
      scrollbar-width:thin; scrollbar-color:rgba(148,163,184,.55) transparent; margin-bottom:0}
    .sidebar .navy::-webkit-scrollbar{width:8px}
    .sidebar .navy::-webkit-scrollbar-track{background:transparent}
    .sidebar .navy::-webkit-scrollbar-thumb{background:rgba(148,163,184,.4);border-radius:8px}
    .brand{flex:0 0 auto}
    .brand{
      color:#fff; font-weight:700; display:flex; align-items:center; gap:8px;
      text-decoration:none; margin-bottom:14px;
    }
    .brand i{color:#60a5fa}

    .navy a{
      color:var(--sidebar-text); text-decoration:none; display:flex; align-items:center; gap:10px;
      padding:10px 12px; border-radius:10px; margin-bottom:6px; font-weight:600;
    }
    .navy a i{ width:1.25em; text-align:center; }
    .navy a:hover{ background:var(--sidebar-hover); color:#fff; }
    .navy a.active{ background:var(--sidebar-hover); color:var(--sidebar-active); }

    /* Mobile: sidebar becomes offcanvas-like */
    @media(max-width:992px){
      .layout{ grid-template-columns:1fr; }
      .sidebar{
        position:fixed; left:-260px; top:56px; height:calc(100vh - 56px);
        width:240px; z-index:1040; transition:left .25s ease; border-right:1px solid rgba(255,255,255,.08);
      }
      .sidebar.show{ left:0; }
      .content{ padding-top:56px; }
      .backdrop{
        content:""; position:fixed; inset:56px 0 0 0; background:rgba(0,0,0,.35);
        display:none; z-index:1039;
      }
      .backdrop.show{ display:block; }
    }

    /* Utilities */
    .thumb{width:64px;height:64px;object-fit:cover;border:1px solid #e5e7eb;border-radius:8px;background:#fff}
  </style>
</head>
<body>

<!-- Topbar (mobile) -->
<div class="topbar d-lg-none">
  <button class="btn-nav" id="btnSidebarToggle" aria-label="Toggle menu">
    <i class="fa-solid fa-bars"></i>
  </button>
  <div class="ms-2 fw-semibold d-flex align-items-center gap-2">
    <i class="fa-solid fa-gauge-high"></i>
    <span>Admin Panel</span>
  </div>
  <div class="ms-auto small d-flex align-items-center gap-2">
    <i class="fa-regular fa-user"></i>
    <span><?php echo h($_SESSION['admin_username'] ?? ''); ?></span>
  </div>
</div>

<div class="layout">
  <!-- Sidebar -->
  <aside class="sidebar">
    <a class="brand" href="<?php echo BASE_URL; ?>/admin/index.php">
      <i class="fa-solid fa-star"></i>
      <span><?php echo h(defined('APP_NAME') ? APP_NAME : 'Shop'); ?></span>
    </a>

    <nav class="navy">
      <?php if (can('view_dashboard')): ?>
        <a class="<?php echo is_active('index.php'); ?>"
           href="<?php echo BASE_URL; ?>/admin/index.php">
          <i class="fa-solid fa-gauge-high"></i>
          <span>ড্যাশবোর্ড</span>
        </a>
      <?php endif; ?>

      <?php if (can('manage_products')): ?>
        <a class="<?php echo is_active(['products.php','product_form.php']); ?>"
           href="<?php echo BASE_URL; ?>/admin/products.php">
          <i class="fa-solid fa-boxes-stacked"></i>
          <span>পণ্য ব্যবস্থাপনা</span>
        </a>
      <?php endif; ?>

      <?php if (can('manage_products_quick')): ?>
        <a class="<?php echo is_active('product_add_for_mobile.php'); ?>"
           href="<?php echo BASE_URL; ?>/admin/product_add_for_mobile.php">
          <i class="fa-solid fa-mobile-screen-button"></i>
          <span>দ্রুত পণ্য যোগ</span>
        </a>
      <?php endif; ?>

      <?php if (can('manage_categories')): ?>
        <a class="<?php echo is_active('categories.php'); ?>"
           href="<?php echo BASE_URL; ?>/admin/categories.php">
          <i class="fa-solid fa-tags"></i>
          <span>ক্যাটেগরি</span>
        </a>
      <?php endif; ?>

      <?php if (can('manage_sliders')): ?>
        <a class="<?php echo is_active(['sliders.php','slider_form.php']); ?>"
           href="<?php echo BASE_URL; ?>/admin/sliders.php">
          <i class="fa-solid fa-images"></i>
          <span>স্লাইডার</span>
        </a>
      <?php endif; ?>

      <?php if (can('manage_home_sections')): ?>
        <a class="<?php echo is_active(['home_sections.php','home_section_form.php']); ?>"
           href="<?php echo BASE_URL; ?>/admin/home_sections.php">
          <i class="fa-solid fa-table-cells-large"></i>
          <span>হোম সেকশন</span>
        </a>
      <?php endif; ?>

      <?php if (can('manage_announcements')): ?>
        <a class="<?php echo is_active(['announcements.php','announcement_form.php']); ?>"
           href="<?php echo BASE_URL; ?>/admin/announcements.php">
          <i class="fa-solid fa-bullhorn"></i>
          <span>ঘোষণা</span>
        </a>
      <?php endif; ?>

      <?php if (can('manage_reviews')): ?>
        <a class="<?php echo is_active('reviews.php'); ?>"
           href="<?php echo BASE_URL; ?>/admin/reviews.php">
          <i class="fa-solid fa-star-half-stroke"></i>
          <span>রিভিউ</span>
        </a>
      <?php endif; ?>

      <?php if (can('manage_customers')): ?>
        <a class="<?php echo is_active('customers.php'); ?>"
           href="<?php echo BASE_URL; ?>/admin/customers.php">
          <i class="fa-solid fa-user-group"></i>
          <span>কাস্টমার</span>
        </a>
      <?php endif; ?>

      <?php if (can('manage_pages')): ?>
        <a class="<?php echo is_active('pages.php'); ?>"
           href="<?php echo BASE_URL; ?>/admin/pages.php">
          <i class="fa-solid fa-file-lines"></i>
          <span>পেজ</span>
        </a>
        <a class="<?php echo is_active('messages.php'); ?>"
           href="<?php echo BASE_URL; ?>/admin/messages.php">
          <i class="fa-solid fa-envelope"></i>
          <span>মেসেজ</span>
        </a>
      <?php endif; ?>

      <?php if (($_SESSION['admin_role_name'] ?? '') === 'Super Admin'): ?>
        <a class="<?php echo is_active('security.php'); ?>"
           href="<?php echo BASE_URL; ?>/admin/security.php">
          <i class="fa-solid fa-shield-halved"></i>
          <span>সিকিউরিটি</span>
        </a>
      <?php endif; ?>

      <?php if (can('view_page_views')): ?>
        <a class="<?php echo is_active('page_views.php'); ?>"
           href="<?php echo BASE_URL; ?>/admin/page_views.php">
          <i class="fa-solid fa-chart-simple"></i>
          <span>Page Views</span>
        </a>
      <?php endif; ?>

      <?php if (can('manage_orders')): ?>
        <a class="<?php echo is_active(['orders.php','order_view.php','order_print.php']); ?>"
           href="<?php echo BASE_URL; ?>/admin/orders.php">
          <i class="fa-solid fa-list-check"></i>
          <span>অর্ডার ব্যবস্থাপনা</span>
        </a>
      <?php endif; ?>

      <?php if (can('manage_admin_users')): ?>
        <a class="<?php echo is_active(['users.php', 'roles.php', 'role_form.php']); ?>"
           href="<?php echo BASE_URL; ?>/admin/users.php">
          <i class="fa-solid fa-users-gear"></i>
          <span>ইউজার ও রোল</span>
        </a>
      <?php endif; ?>

      <?php if (can('view_stock_report')): ?>
        <a class="<?php echo is_active('stock_report.php'); ?>"
           href="<?php echo BASE_URL; ?>/admin/stock_report.php">
          <i class="fa-solid fa-warehouse"></i>
          <span>স্টক রিপোর্ট</span>
        </a>
      <?php endif; ?>

      <?php if (can('payments')): ?>
        <a class="<?php echo is_active('payments.php'); ?>"
           href="<?php echo BASE_URL; ?>/admin/payments.php">
          <i class="fa-solid fa-credit-card"></i>
          <span>পেমেন্ট</span>
        </a>
      <?php endif; ?>

      <?php if (can('manage_delivery')): ?>
        <a class="<?php echo is_active('delivery.php'); ?>"
           href="<?php echo BASE_URL; ?>/admin/delivery.php">
          <i class="fa-solid fa-truck-fast"></i>
          <span>ডেলিভারি চার্জ</span>
        </a>
      <?php endif; ?>
      <?php if (can('settings')): ?>
        <a class="<?php echo is_active('settings'); ?>"
           href="<?php echo BASE_URL; ?>/admin/settings.php">
          <i class="fa-solid fa-gear"></i>
          <span>সেটিংস</span>
        </a>
      <?php endif; ?>
      <hr class="border-secondary">

      <a href="<?php echo BASE_URL; ?>/admin/logout.php">
        <i class="fa-solid fa-right-from-bracket"></i>
        <span>লগআউট</span>
      </a>
    </nav>
  </aside>

  <!-- Backdrop for mobile -->
  <div id="sidebarBackdrop" class="backdrop d-lg-none"></div>

  <!-- Content -->
  <main class="content p-3 p-lg-4">
