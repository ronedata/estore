<?php
// includes/schema_v16.php — কুরিয়ার (Steadfast) কাঠামো: orders-এ কুরিয়ার-কলাম, courier_logs, manage_courier পারমিশন, সেটিংসের ডিফল্ট (বন্ধ)
// additive only; marker: settings.schema_v16_at; এটি চালালে কোনো কুরিয়ার-সংযোগ চালু হয় না (steadfast_enabled = 0)

if (!function_exists('ensure_estore_schema_v16')) {

  function ensure_estore_schema_v16(PDO $pdo, bool $force = false): bool {
    static $done = false;
    if ($done && !$force) return true;

    try {
      $stm = $pdo->prepare("SELECT value FROM settings WHERE `key`='schema_v16_at' LIMIT 1");
      $stm->execute();
      if ($stm->fetchColumn() && !$force) { $done = true; return true; }
    } catch (Throwable $e) {}

    try {
      $cols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
      $add = [
        'courier_consignment_id' => "VARCHAR(40) NULL DEFAULT NULL",
        'courier_tracking_code'  => "VARCHAR(60) NULL DEFAULT NULL",
        'courier_status'         => "VARCHAR(40) NULL DEFAULT NULL",
        'courier_sent_at'        => "DATETIME NULL DEFAULT NULL",
        'courier_synced_at'      => "DATETIME NULL DEFAULT NULL",
      ];
      foreach ($add as $c => $def) {
        if (!in_array($c, $cols, true)) $pdo->exec("ALTER TABLE orders ADD COLUMN $c $def");
      }
      $idx = $pdo->query("SHOW INDEX FROM orders WHERE Key_name='idx_courier_cid'")->fetch();
      if (!$idx) $pdo->exec("ALTER TABLE orders ADD KEY idx_courier_cid (courier_consignment_id)");

      $pdo->exec("CREATE TABLE IF NOT EXISTS courier_logs (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        order_id   INT NULL,
        action     VARCHAR(20)  NOT NULL,
        ok         TINYINT(1)   NOT NULL DEFAULT 0,
        http_code  INT NULL,
        message    VARCHAR(255) NULL,
        created_by VARCHAR(60)  NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_order (order_id),
        KEY idx_time (created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      try {
        $pdo->prepare("INSERT INTO permissions(name, description) VALUES(?,?)
                       ON DUPLICATE KEY UPDATE description=VALUES(description)")
            ->execute(['manage_courier', 'Can configure the courier connection and send orders to the courier']);
        $hasRolePerm = $pdo->query("SHOW TABLES LIKE 'role_permissions'")->fetchColumn();
        if ($hasRolePerm) {
          $pdo->exec("INSERT IGNORE INTO role_permissions(role_id, permission_id)
                      SELECT 1, id FROM permissions WHERE name = 'manage_courier'");
        }
      } catch (Throwable $e) { error_log('[estore schema v16] permission: ' . $e->getMessage()); }

      /* ডিফল্ট: বন্ধ। বিদ্যমান মান (যদি কেউ আগে বসিয়ে থাকে) বদলানো হয় না */
      $ins = $pdo->prepare("INSERT IGNORE INTO settings(`key`,`value`) VALUES(?,?)");
      $ins->execute(['steadfast_enabled', '0']);
      $ins->execute(['steadfast_auto_status', '0']);

      $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES('schema_v16_at',?)
                     ON DUPLICATE KEY UPDATE value=VALUES(value)")
          ->execute([date('Y-m-d H:i:s')]);

      $done = true;
      return true;
    } catch (Throwable $e) {
      error_log('[estore schema v16] ' . $e->getMessage());
      return false;
    }
  }
}
