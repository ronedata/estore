<?php
// includes/schema_v14.php — ক্রয়মূল্য ও খরচ
//   products.cost_price (গড় ক্রয়মূল্য, NULL = অজানা), order_items.cost (অর্ডারের সময়ের ক্রয়মূল্য snapshot),
//   expenses টেবিল, manage_expenses পারমিশন
// additive only; marker: settings.schema_v14_at

if (!function_exists('ensure_estore_schema_v14')) {

  function ensure_estore_schema_v14(PDO $pdo, bool $force = false): bool {
    static $done = false;
    if ($done && !$force) return true;

    try {
      $stm = $pdo->prepare("SELECT value FROM settings WHERE `key`='schema_v14_at' LIMIT 1");
      $stm->execute();
      if ($stm->fetchColumn() && !$force) { $done = true; return true; }
    } catch (Throwable $e) {}

    try {
      $has = function (string $table, string $col) use ($pdo): bool {
        return in_array($col, $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN), true);
      };
      if (!$has('products', 'cost_price')) {
        $pdo->exec("ALTER TABLE products ADD COLUMN cost_price DECIMAL(12,2) NULL DEFAULT NULL");
      }
      if (!$has('order_items', 'cost')) {
        $pdo->exec("ALTER TABLE order_items ADD COLUMN cost DECIMAL(12,2) NULL DEFAULT NULL");
      }

      $pdo->exec("CREATE TABLE IF NOT EXISTS expenses (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        title        VARCHAR(160) NOT NULL,
        amount       DECIMAL(12,2) NOT NULL,
        expense_date DATE NOT NULL,
        category     VARCHAR(60) NULL,
        note         VARCHAR(255) NULL,
        created_by   VARCHAR(60) NULL,
        created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_date (expense_date),
        KEY idx_category (category)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      try {
        $pdo->prepare("INSERT INTO permissions(name, description) VALUES(?,?)
                       ON DUPLICATE KEY UPDATE description=VALUES(description)")
            ->execute(['manage_expenses', 'Can add, edit and delete expenses']);
        $hasRolePerm = $pdo->query("SHOW TABLES LIKE 'role_permissions'")->fetchColumn();
        if ($hasRolePerm) {
          $pdo->exec("INSERT IGNORE INTO role_permissions(role_id, permission_id)
                      SELECT 1, id FROM permissions WHERE name = 'manage_expenses'");
        }
      } catch (Throwable $e) { error_log('[estore schema v14] permission: ' . $e->getMessage()); }

      $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES('schema_v14_at',?)
                     ON DUPLICATE KEY UPDATE value=VALUES(value)")
          ->execute([date('Y-m-d H:i:s')]);

      $done = true;
      return true;
    } catch (Throwable $e) {
      error_log('[estore schema v14] ' . $e->getMessage());
      return false;
    }
  }
}
