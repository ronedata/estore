<?php
/**
 * চেক করে দেখে যে বর্তমান ইউজারের নির্দিষ্ট পারমিশন আছে কি না।
 * Super Admin সব পারমিশন পায়।
 * @param string $permission পারমিশনের নাম (e.g., 'manage_products')
 * @return bool
 */
function can(string $permission): bool {
  if (empty($_SESSION['admin_permissions']) || !is_array($_SESSION['admin_permissions'])) {
    return false;
  }
  // Super Admin has all permissions
  if (isset($_SESSION['admin_role_name']) && $_SESSION['admin_role_name'] === 'Super Admin') {
    return true;
  }
  return in_array($permission, $_SESSION['admin_permissions'], true);
}

/**
 * ইউজারের পারমিশন অনুযায়ী প্রথম অ্যাক্সেসযোগ্য পেজের URL খুঁজে বের করে।
 * @return string|null
 */
function get_first_allowed_page(): ?string {
    $menu_map = [
        'view_dashboard'        => '/admin/index.php',
        'manage_products'       => '/admin/products.php',
        'manage_products_quick' => '/admin/product_add_for_mobile.php',
        'manage_categories'     => '/admin/categories.php',
        'manage_sliders'        => '/admin/sliders.php',
        'manage_home_sections'  => '/admin/home_sections.php',
        'manage_reviews'        => '/admin/reviews.php',
        'manage_customers'      => '/admin/customers.php',
        'manage_pages'          => '/admin/pages.php',
        'view_page_views'       => '/admin/page_views.php',
        'manage_orders'         => '/admin/orders.php',
        'manage_admin_users'    => '/admin/users.php',
        'view_stock_report'     => '/admin/stock_report.php',
        'payments'              => '/admin/payments.php',
        'settings'              => '/admin/settings.php',
    ];

    // Super Admin সবসময় ড্যাশবোর্ডে যাবে
    if (isset($_SESSION['admin_role_name']) && $_SESSION['admin_role_name'] === 'Super Admin') {
        return BASE_URL . $menu_map['view_dashboard'];
    }

    if (empty($_SESSION['admin_permissions'])) {
        return null;
    }

    foreach ($menu_map as $permission => $url) {
        if (in_array($permission, $_SESSION['admin_permissions'], true)) {
            return BASE_URL . $url;
        }
    }
    return null; // কোনো পেজেই অ্যাক্সেস নেই
}
