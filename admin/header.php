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
  <title>Admin - <?php echo h(admin_store_name()); ?></title>
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
  <script>document.documentElement.classList.add('js');</script>

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

    /* মেনু-গ্রুপ (ভাঁজ-করা); JS না চললে সব খোলা থাকে */
    .navy .nav-group{margin-bottom:4px}
    .navy .nav-group-toggle{
      width:100%; display:flex; align-items:center; gap:10px; background:transparent; border:0;
      color:#94a3b8; padding:9px 12px; border-radius:10px; font-size:.78rem; text-transform:none;
      letter-spacing:.2px; cursor:pointer; text-align:left;
    }
    .navy .nav-group-toggle:hover{ background:var(--sidebar-hover); color:#fff; }
    .navy .nav-group-toggle .ng-icon{ width:1.25em; text-align:center; font-size:.95rem; }
    .navy .nav-group-toggle .ng-label{ flex:1; font-weight:700; }
    .navy .nav-group-toggle .ng-caret{ font-size:.7rem; transition:transform .2s ease; }
    .navy .nav-group.open > .nav-group-toggle .ng-caret{ transform:rotate(180deg); }
    .navy .nav-group.has-active > .nav-group-toggle{ color:#fff; }
    .navy .nav-group-body{ padding-left:6px; border-left:1px solid rgba(148,163,184,.25); margin-left:18px; }
    .navy .nav-group-body a{ padding:8px 10px; margin-bottom:3px; font-weight:500; font-size:.92rem; }
    .js .navy .nav-group:not(.open) > .nav-group-body{ display:none; }

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
      <span id="adminBrandName"><?php echo h(admin_store_name()); ?></span>
    </a>

    <nav class="navy" id="adminNav">
      <?php
      /* মেনু-গ্রুপ: can = পারমিশন (বা 'super' = শুধু Super Admin); files = সক্রিয় মেনুর ফাইল-তালিকা */
      $isSuperAdmin = (($_SESSION['admin_role_name'] ?? '') === 'Super Admin');
      $NAV_GROUPS = [
        ['key' => 'main', 'label' => null, 'icon' => '', 'items' => [
          ['can' => 'view_dashboard', 'files' => ['index.php'], 'href' => 'index.php', 'icon' => 'fa-gauge-high', 'label' => 'ড্যাশবোর্ড'],
        ]],
        ['key' => 'sales', 'label' => 'বিক্রয়', 'icon' => 'fa-cart-shopping', 'items' => [
          ['can' => 'manage_orders', 'files' => ['orders.php','order_view.php','order_print.php','order_edit.php'], 'href' => 'orders.php', 'icon' => 'fa-list-check', 'label' => 'অর্ডার ব্যবস্থাপনা'],
          ['can' => 'payments', 'files' => ['payments.php'], 'href' => 'payments.php', 'icon' => 'fa-credit-card', 'label' => 'পেমেন্ট'],
          ['can' => 'manage_delivery', 'files' => ['delivery.php'], 'href' => 'delivery.php', 'icon' => 'fa-truck-fast', 'label' => 'ডেলিভারি চার্জ'],
          ['can' => 'manage_courier', 'files' => ['courier.php'], 'href' => 'courier.php', 'icon' => 'fa-truck-fast', 'label' => 'কুরিয়ার'],
          ['can' => 'manage_customers', 'files' => ['customers.php'], 'href' => 'customers.php', 'icon' => 'fa-user-group', 'label' => 'কাস্টমার'],
          ['can' => 'manage_reviews', 'files' => ['reviews.php'], 'href' => 'reviews.php', 'icon' => 'fa-star-half-stroke', 'label' => 'রিভিউ'],
          ['can' => 'manage_pages', 'files' => ['messages.php'], 'href' => 'messages.php', 'icon' => 'fa-envelope', 'label' => 'মেসেজ'],
        ]],
        ['key' => 'catalog', 'label' => 'পণ্য ও ক্যাটালগ', 'icon' => 'fa-boxes-stacked', 'items' => [
          ['can' => 'manage_products', 'files' => ['products.php','product_form.php'], 'href' => 'products.php', 'icon' => 'fa-boxes-stacked', 'label' => 'পণ্য ব্যবস্থাপনা'],
          ['can' => 'manage_products_quick', 'files' => ['product_add_for_mobile.php'], 'href' => 'product_add_for_mobile.php', 'icon' => 'fa-mobile-screen-button', 'label' => 'দ্রুত পণ্য যোগ'],
          ['can' => 'manage_categories', 'files' => ['categories.php'], 'href' => 'categories.php', 'icon' => 'fa-tags', 'label' => 'ক্যাটেগরি'],
        ]],
        ['key' => 'accounts', 'label' => 'ক্রয় ও হিসাব', 'icon' => 'fa-scale-balanced', 'items' => [
          ['can' => 'manage_purchases', 'files' => ['purchases.php','purchase_form.php','purchase_view.php'], 'href' => 'purchases.php', 'icon' => 'fa-cart-flatbed', 'label' => 'ক্রয়'],
          ['can' => 'manage_purchases', 'files' => ['suppliers.php','supplier_view.php'], 'href' => 'suppliers.php', 'icon' => 'fa-truck-ramp-box', 'label' => 'সরবরাহকারী'],
          ['can' => 'manage_expenses', 'files' => ['expenses.php'], 'href' => 'expenses.php', 'icon' => 'fa-wallet', 'label' => 'খরচ'],
          ['can' => 'view_stock_report', 'files' => ['stock_report.php'], 'href' => 'stock_report.php', 'icon' => 'fa-warehouse', 'label' => 'স্টক রিপোর্ট'],
        ]],
        ['key' => 'reports', 'label' => 'রিপোর্ট', 'icon' => 'fa-chart-line', 'items' => [
          ['can' => 'view_reports', 'files' => ['report_orders.php','report_profit.php','report_purchases.php','report_expenses.php'], 'href' => 'report_orders.php', 'icon' => 'fa-chart-line', 'label' => 'বিক্রয় রিপোর্ট'],
          ['can' => 'view_page_views', 'files' => ['page_views.php'], 'href' => 'page_views.php', 'icon' => 'fa-chart-simple', 'label' => 'Page Views'],
        ]],
        ['key' => 'site', 'label' => 'সাইট সাজানো', 'icon' => 'fa-palette', 'items' => [
          ['can' => 'manage_sliders', 'files' => ['sliders.php','slider_form.php'], 'href' => 'sliders.php', 'icon' => 'fa-images', 'label' => 'স্লাইডার'],
          ['can' => 'manage_home_sections', 'files' => ['home_sections.php','home_section_form.php'], 'href' => 'home_sections.php', 'icon' => 'fa-table-cells-large', 'label' => 'হোম সেকশন'],
          ['can' => 'manage_announcements', 'files' => ['announcements.php','announcement_form.php'], 'href' => 'announcements.php', 'icon' => 'fa-bullhorn', 'label' => 'ঘোষণা'],
          ['can' => 'manage_pages', 'files' => ['pages.php'], 'href' => 'pages.php', 'icon' => 'fa-file-lines', 'label' => 'পেজ'],
        ]],
        ['key' => 'system', 'label' => 'সিস্টেম', 'icon' => 'fa-gear', 'items' => [
          ['can' => 'settings', 'files' => ['settings.php'], 'href' => 'settings.php', 'icon' => 'fa-gear', 'label' => 'সেটিংস'],
          ['can' => 'manage_admin_users', 'files' => ['users.php','roles.php','role_form.php','permissions.php','permission_form.php'], 'href' => 'users.php', 'icon' => 'fa-users-gear', 'label' => 'ইউজার ও রোল'],
          ['can' => 'super', 'files' => ['login_security.php'], 'href' => 'login_security.php', 'icon' => 'fa-user-lock', 'label' => 'লগইন সুরক্ষা'],
          ['can' => 'super', 'files' => ['security.php'], 'href' => 'security.php', 'icon' => 'fa-shield-halved', 'label' => 'সিকিউরিটি'],
          ['can' => 'super', 'files' => ['backup.php'], 'href' => 'backup.php', 'icon' => 'fa-cloud-arrow-down', 'label' => 'ব্যাকআপ'],
        ]],
      ];
      foreach ($NAV_GROUPS as $grp):
        $vis = [];
        foreach ($grp['items'] as $it) {
          $okAccess = $it['can'] === 'super' ? $isSuperAdmin : can($it['can']);
          if ($okAccess) $vis[] = $it;
        }
        if (!$vis) continue;
        $hasActive = false;
        foreach ($vis as $it) if (in_array($SELF, $it['files'], true)) { $hasActive = true; break; }
        if ($grp['label'] === null):
          foreach ($vis as $it): ?>
      <a class="<?php echo is_active($it['files']); ?>" href="<?php echo BASE_URL; ?>/admin/<?php echo h($it['href']); ?>">
        <i class="fa-solid <?php echo h($it['icon']); ?>"></i>
        <span><?php echo h($it['label']); ?></span>
      </a>
          <?php endforeach;
        else: ?>
      <div class="nav-group<?php echo $hasActive ? ' open has-active' : ''; ?>" data-group="<?php echo h($grp['key']); ?>">
        <button type="button" class="nav-group-toggle" aria-expanded="<?php echo $hasActive ? 'true' : 'false'; ?>" aria-controls="ng-<?php echo h($grp['key']); ?>">
          <i class="fa-solid <?php echo h($grp['icon']); ?> ng-icon"></i>
          <b class="ng-label"><?php echo h($grp['label']); ?></b>
          <i class="fa-solid fa-chevron-down ng-caret"></i>
        </button>
        <div class="nav-group-body" id="ng-<?php echo h($grp['key']); ?>">
          <?php foreach ($vis as $it): ?>
          <a class="<?php echo is_active($it['files']); ?>" href="<?php echo BASE_URL; ?>/admin/<?php echo h($it['href']); ?>">
            <i class="fa-solid <?php echo h($it['icon']); ?>"></i>
            <span><?php echo h($it['label']); ?></span>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
        <?php endif;
      endforeach; ?>
      <hr class="border-secondary">

      <a href="<?php echo BASE_URL; ?>/admin/logout.php">
        <i class="fa-solid fa-right-from-bracket"></i>
        <span>লগআউট</span>
      </a>
    </nav>
  </aside>

  <script>
  (function(){
    var KEY = 'adminNavOpen';
    var nav = document.getElementById('adminNav');
    if (!nav) return;
    var saved = [];
    try { saved = JSON.parse(localStorage.getItem(KEY) || '[]'); if (!Array.isArray(saved)) saved = []; } catch (e) { saved = []; }
    nav.querySelectorAll('.nav-group').forEach(function(g){
      if (saved.indexOf(g.dataset.group) !== -1) { g.classList.add('open'); g.querySelector('.nav-group-toggle').setAttribute('aria-expanded', 'true'); }
      g.querySelector('.nav-group-toggle').addEventListener('click', function(){
        var open = g.classList.toggle('open');
        this.setAttribute('aria-expanded', open ? 'true' : 'false');
        var now = [];
        nav.querySelectorAll('.nav-group.open').forEach(function(x){ if (!x.classList.contains('has-active')) now.push(x.dataset.group); });
        try { localStorage.setItem(KEY, JSON.stringify(now)); } catch (e) {}
      });
    });
  })();
  </script>

  <!-- Backdrop for mobile -->
  <div id="sidebarBackdrop" class="backdrop d-lg-none"></div>

  <!-- Content -->
  <main class="content p-3 p-lg-4">
