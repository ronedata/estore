<?php
// includes/schema_v7.php — dynamic home sections (home_sections, home_section_products)
// additive only; পুরোনো is_featured / is_new_arrival কলাম ও settings key অক্ষত থাকে

if (!function_exists('ensure_estore_schema_v7')) {

  function ensure_estore_schema_v7(PDO $pdo, bool $force = false): bool {
    static $done = false;
    if ($done && !$force) return true;

    try {
      $stm = $pdo->prepare("SELECT value FROM settings WHERE `key`='schema_v7_at' LIMIT 1");
      $stm->execute();
      if ($stm->fetchColumn() && !$force) { $done = true; return true; }
    } catch (Throwable $e) {}

    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS home_sections (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        title         VARCHAR(120) NOT NULL,
        subtitle      VARCHAR(255) NULL,
        type          VARCHAR(20)  NOT NULL DEFAULT 'manual',
        category_id   INT NULL,
        product_limit INT NOT NULL DEFAULT 12,
        sort_order    INT NOT NULL DEFAULT 0,
        active        TINYINT(1) NOT NULL DEFAULT 1,
        show_see_all  TINYINT(1) NOT NULL DEFAULT 1,
        new_default   TINYINT(1) NOT NULL DEFAULT 0,
        created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_active_sort (active, sort_order)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      $pdo->exec("CREATE TABLE IF NOT EXISTS home_section_products (
        section_id INT UNSIGNED NOT NULL,
        product_id INT NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        PRIMARY KEY (section_id, product_id),
        KEY idx_product (product_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      /* ---------- বিদ্যমান দুই সেকশন সিড (হোমপেজ আগের মতোই দেখাবে) ---------- */
      $count = (int)$pdo->query("SELECT COUNT(*) FROM home_sections")->fetchColumn();
      if ($count === 0) {
        $S = $pdo->query("SELECT `key`,`value` FROM settings WHERE `key` IN
          ('featured_title','featured_subtitle','newest_title','newest_subtitle')")
          ->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        $pick = function (string $k, string $def) use ($S): string {
          $v = trim((string)($S[$k] ?? ''));
          return $v !== '' ? $v : $def;
        };

        $ins = $pdo->prepare("INSERT INTO home_sections(title,subtitle,type,product_limit,sort_order,active,show_see_all,new_default)
                              VALUES(?,?,'manual',18,?,1,1,?)");

        $ins->execute([
          $pick('featured_title', 'Featured Products'),
          $pick('featured_subtitle', 'আপনার জন্য বাছাই করা সেরা পণ্য'),
          1,
          0,
        ]);
        $featId = (int)$pdo->lastInsertId();

        $ins->execute([
          $pick('newest_title', 'New Arrivals'),
          $pick('newest_subtitle', 'সদ্য যোগ হওয়া নতুন সংগ্রহ'),
          2,
          1,
        ]);
        $newId = (int)$pdo->lastInsertId();

        try {
          $pdo->prepare("INSERT IGNORE INTO home_section_products(section_id, product_id, sort_order)
                         SELECT ?, id, 0 FROM products WHERE is_featured = 1")->execute([$featId]);
        } catch (Throwable $e) { error_log('[estore schema v7] featured copy: ' . $e->getMessage()); }

        try {
          $pdo->prepare("INSERT IGNORE INTO home_section_products(section_id, product_id, sort_order)
                         SELECT ?, id, 0 FROM products WHERE is_new_arrival = 1 OR is_new_arrival IS NULL")->execute([$newId]);
        } catch (Throwable $e) { error_log('[estore schema v7] new copy: ' . $e->getMessage()); }
      }

      /* ---------- পারমিশন ---------- */
      try {
        $pdo->prepare("INSERT INTO permissions(name, description) VALUES(?,?)
                       ON DUPLICATE KEY UPDATE description=VALUES(description)")
            ->execute(['manage_home_sections', 'Can manage homepage sections (add, remove, reorder, product count)']);
        $hasRolePerm = $pdo->query("SHOW TABLES LIKE 'role_permissions'")->fetchColumn();
        if ($hasRolePerm) {
          $pdo->exec("INSERT IGNORE INTO role_permissions(role_id, permission_id)
                      SELECT 1, id FROM permissions WHERE name = 'manage_home_sections'");
        }
      } catch (Throwable $e) { error_log('[estore schema v7] permission: ' . $e->getMessage()); }

      $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES('schema_v7_at',?)
                     ON DUPLICATE KEY UPDATE value=VALUES(value)")
          ->execute([date('Y-m-d H:i:s')]);

      $done = true;
      return true;
    } catch (Throwable $e) {
      error_log('[estore schema v7] ' . $e->getMessage());
      return false;
    }
  }
}
