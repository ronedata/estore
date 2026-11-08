<?php
// includes/schema_v2.php
// eStore-এর নতুন ফিচারের স্কিমা (sliders, customers, reviews, wishlists, pages, subscribers)
// এবং বিদ্যমান টেবিলে новые কলাম। সব additive — পুরনো ডেটা অক্ষত থাকে।
// config.php থেকে ensure_estore_schema() কল হয়।

if (!function_exists('ensure_estore_schema')) {

  function ensure_estore_schema(PDO $pdo, bool $force = false): bool {
    static $done = false;
    if ($done && !$force) return true;

    // একবার চালালেই যথেষ্ট — সেটিংসে ভার্সন রাখি
    try {
      $stm = $pdo->prepare("SELECT value FROM settings WHERE `key`='schema_v2_at' LIMIT 1");
      $stm->execute();
      $applied = $stm->fetchColumn();
      if ($applied && !$force) { $done = true; return true; }
    } catch (Throwable $e) {
      // settings টেবিল না থাকলে এগিয়ে যাই
    }

    try {
      /* ---------- নতুন টেবিল: sliders ---------- */
      $pdo->exec("CREATE TABLE IF NOT EXISTS sliders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        placement ENUM('hero','side') NOT NULL DEFAULT 'hero',
        image VARCHAR(400) NOT NULL,
        title VARCHAR(255) NULL,
        subtitle VARCHAR(255) NULL,
        btn_label VARCHAR(60) DEFAULT 'Shop Now',
        btn_bg VARCHAR(20) DEFAULT '#ff5252',
        link_type ENUM('product','category','url') DEFAULT 'product',
        link_value VARCHAR(255) NULL,
        theme VARCHAR(30) NULL,
        sort_order INT NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_placement_active (placement, active, sort_order)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      /* ---------- নতুন টেবিল: customers ---------- */
      $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        mobile VARCHAR(20) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        name VARCHAR(120) NULL,
        email VARCHAR(160) NULL,
        address TEXT NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_mobile (mobile)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      /* ---------- নতুন টেবিল: product_reviews ---------- */
      $pdo->exec("CREATE TABLE IF NOT EXISTS product_reviews (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id INT NOT NULL,
        customer_id INT NULL,
        name VARCHAR(120) NOT NULL,
        rating TINYINT NOT NULL DEFAULT 5,
        comment TEXT NOT NULL,
        image VARCHAR(400) NULL,
        status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_product_status (product_id, status),
        CONSTRAINT fk_review_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      /* ---------- নতুন টেবিল: wishlists ---------- */
      $pdo->exec("CREATE TABLE IF NOT EXISTS wishlists (
        id INT AUTO_INCREMENT PRIMARY KEY,
        customer_id INT NOT NULL,
        product_id INT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_customer_product (customer_id, product_id),
        KEY idx_customer (customer_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      /* ---------- নতুন টেবিল: pages ---------- */
      $pdo->exec("CREATE TABLE IF NOT EXISTS pages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(60) NOT NULL,
        title VARCHAR(200) NOT NULL,
        content LONGTEXT NULL,
        meta_description VARCHAR(255) NULL,
        active TINYINT(1) NOT NULL DEFAULT 1,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_slug (slug)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      /* ---------- নতুন টেবিল: subscribers ---------- */
      $pdo->exec("CREATE TABLE IF NOT EXISTS subscribers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_email (email)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      /* ---------- বিদ্যমান টেবিলে নতুন কলাম ---------- */
      estore_add_column($pdo, 'products', 'sku', "VARCHAR(40) NULL AFTER name");
      estore_add_column($pdo, 'products', 'sold_count', "INT NOT NULL DEFAULT 0");
      estore_add_column($pdo, 'products', 'is_featured', "TINYINT(1) NOT NULL DEFAULT 0");
      estore_add_column($pdo, 'products', 'badge_color', "VARCHAR(20) NULL");

      estore_add_column($pdo, 'categories', 'image', "VARCHAR(400) NULL AFTER name");
      estore_add_column($pdo, 'categories', 'sort_order', "INT NOT NULL DEFAULT 0");
      estore_add_column($pdo, 'categories', 'active', "TINYINT(1) NOT NULL DEFAULT 1");

      estore_add_column($pdo, 'payment_methods', 'logo', "VARCHAR(400) NULL");

      estore_add_column($pdo, 'orders', 'customer_id', "INT NULL");

      /* ---------- ডিফল্ট সেটিংস ---------- */
      $defaults = [
        'hero_autoplay_ms'   => '2500',
        'animations_enabled' => '1',
        'theme_primary'      => '#6c5ce7',
        'theme_secondary'    => '#ff7675',
        'sold_count_label'   => 'Delivered',
        'info1_title'        => 'Fast Delivery',
        'info1_sub'          => 'Within 3 to 5 Days',
        'info2_title'        => 'Cash on Delivery',
        'info2_sub'          => 'Pay After Receiving Product',
        'info3_title'        => '100% Authentic',
        'info3_sub'          => 'Quality Guarantee',
        'social_facebook'    => '',
        'social_instagram'   => '',
        'social_whatsapp'    => '',
        'store_address'      => '',
      ];
      $ins = $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `key`=`key`");
      foreach ($defaults as $k => $v) {
        try { $ins->execute([$k, $v]); } catch (Throwable $e) { /* ignore */ }
      }

      /* ---------- ডিফল্ট পেজ ---------- */
      $pageIns = $pdo->prepare("INSERT INTO pages(slug,title,content,meta_description) VALUES(?,?,?,?)
                                ON DUPLICATE KEY UPDATE title=VALUES(title)");
      $pageIns->execute(['about', 'About Us', '<p>আমাদের সম্পর্কে তথ্য এখানে যোগ করুন।</p>', 'About our store']);
      $pageIns->execute(['privacy', 'Privacy Policy', '<p>প্রাইভেসি পলিসি এখানে যোগ করুন।</p>', 'Privacy policy']);
      $pageIns->execute(['terms', 'Terms & Conditions', '<p>শর্তাবলী এখানে যোগ করুন।</p>', 'Terms and conditions']);
      $pageIns->execute(['contact', 'Contact Us', '<p>যোগাযোগের তথ্য এখানে যোগ করুন।</p>', 'Contact us']);

      /* ---------- নতুন পারমিশন ---------- */
      $permIns = $pdo->prepare("INSERT INTO permissions(name, description) VALUES(?,?)
                                ON DUPLICATE KEY UPDATE description=VALUES(description)");
      foreach ([
        ['manage_sliders',   'Can manage hero sliders and side banners'],
        ['manage_reviews',   'Can moderate product reviews'],
        ['manage_customers', 'Can view and manage customers'],
        ['manage_pages',     'Can edit static pages (about, privacy, terms, contact)'],
        ['view_page_views',  'Can view page views report'],
      ] as $p) {
        try { $permIns->execute($p); } catch (Throwable $e) { /* ignore */ }
      }

      // Super Admin (role_id=1) কে নতুন পারমিশন দিন
      try {
        $hasRolePerm = $pdo->query("SHOW TABLES LIKE 'role_permissions'")->fetchColumn();
        if ($hasRolePerm) {
          $pdo->exec("INSERT IGNORE INTO role_permissions(role_id, permission_id)
                      SELECT 1, id FROM permissions
                      WHERE name IN ('manage_sliders','manage_reviews','manage_customers','manage_pages','view_page_views')");
        }
      } catch (Throwable $e) { /* ignore */ }

      /* ---------- মার্কার ---------- */
      $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES('schema_v2_at',?)
                     ON DUPLICATE KEY UPDATE value=VALUES(value)")
          ->execute([date('Y-m-d H:i:s')]);

      $done = true;
      return true;
    } catch (Throwable $e) {
      error_log('[estore schema] ' . $e->getMessage());
      return false;
    }
  }

  /** কলাম না থাকলে যোগ করে (নিরাপদ, idempotent) */
  function estore_add_column(PDO $pdo, string $table, string $column, string $definition): void {
    try {
      $stm = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($column));
      if ($stm && $stm->fetch()) return;
      $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    } catch (Throwable $e) {
      error_log("[estore schema] $table.$column: " . $e->getMessage());
    }
  }
}
