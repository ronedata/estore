<?php
// includes/schema_v15.php — সরবরাহকারী, ক্রয়, ক্রয়ের আইটেম, ক্রয়ের পরিশোধ + manage_purchases পারমিশন
// additive only; marker: settings.schema_v15_at

if (!function_exists('ensure_estore_schema_v15')) {

  function ensure_estore_schema_v15(PDO $pdo, bool $force = false): bool {
    static $done = false;
    if ($done && !$force) return true;

    try {
      $stm = $pdo->prepare("SELECT value FROM settings WHERE `key`='schema_v15_at' LIMIT 1");
      $stm->execute();
      if ($stm->fetchColumn() && !$force) { $done = true; return true; }
    } catch (Throwable $e) {}

    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS suppliers (
        id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name       VARCHAR(120) NOT NULL,
        phone      VARCHAR(30)  NULL,
        address    VARCHAR(255) NULL,
        note       VARCHAR(255) NULL,
        active     TINYINT(1)   NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_name (name)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      $pdo->exec("CREATE TABLE IF NOT EXISTS purchases (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        supplier_id   INT UNSIGNED NOT NULL,
        invoice_no    VARCHAR(60)  NULL,
        purchase_date DATE NOT NULL,
        total         DECIMAL(12,2) NOT NULL DEFAULT 0,
        note          VARCHAR(255) NULL,
        status        VARCHAR(10)  NOT NULL DEFAULT 'active',
        created_by    VARCHAR(60)  NULL,
        cancelled_at  DATETIME NULL,
        created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_supplier (supplier_id),
        KEY idx_date (purchase_date),
        KEY idx_status (status)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_items (
        id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        purchase_id  INT UNSIGNED NOT NULL,
        product_id   INT NULL,
        product_name VARCHAR(255) NOT NULL,
        qty          INT NOT NULL,
        unit_cost    DECIMAL(12,2) NOT NULL,
        line_total   DECIMAL(12,2) NOT NULL,
        PRIMARY KEY (id),
        KEY idx_purchase (purchase_id),
        KEY idx_product (product_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      $pdo->exec("CREATE TABLE IF NOT EXISTS purchase_payments (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        purchase_id INT UNSIGNED NOT NULL,
        amount      DECIMAL(12,2) NOT NULL,
        paid_date   DATE NOT NULL,
        method      VARCHAR(30)  NULL,
        note        VARCHAR(255) NULL,
        created_by  VARCHAR(60)  NULL,
        created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_purchase (purchase_id),
        KEY idx_date (paid_date)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      try {
        $pdo->prepare("INSERT INTO permissions(name, description) VALUES(?,?)
                       ON DUPLICATE KEY UPDATE description=VALUES(description)")
            ->execute(['manage_purchases', 'Can manage suppliers, purchases and supplier payments']);
        $hasRolePerm = $pdo->query("SHOW TABLES LIKE 'role_permissions'")->fetchColumn();
        if ($hasRolePerm) {
          $pdo->exec("INSERT IGNORE INTO role_permissions(role_id, permission_id)
                      SELECT 1, id FROM permissions WHERE name = 'manage_purchases'");
        }
      } catch (Throwable $e) { error_log('[estore schema v15] permission: ' . $e->getMessage()); }

      $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES('schema_v15_at',?)
                     ON DUPLICATE KEY UPDATE value=VALUES(value)")
          ->execute([date('Y-m-d H:i:s')]);

      $done = true;
      return true;
    } catch (Throwable $e) {
      error_log('[estore schema v15] ' . $e->getMessage());
      return false;
    }
  }
}
