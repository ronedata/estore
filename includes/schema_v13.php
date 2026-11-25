<?php
// includes/schema_v13.php — রিপোর্ট দেখার পারমিশন (view_reports)
// additive only; নতুন টেবিল/কলাম নেই; marker: settings.schema_v13_at

if (!function_exists('ensure_estore_schema_v13')) {

  function ensure_estore_schema_v13(PDO $pdo, bool $force = false): bool {
    static $done = false;
    if ($done && !$force) return true;

    try {
      $stm = $pdo->prepare("SELECT value FROM settings WHERE `key`='schema_v13_at' LIMIT 1");
      $stm->execute();
      if ($stm->fetchColumn() && !$force) { $done = true; return true; }
    } catch (Throwable $e) {}

    try {
      try {
        $pdo->prepare("INSERT INTO permissions(name, description) VALUES(?,?)
                       ON DUPLICATE KEY UPDATE description=VALUES(description)")
            ->execute(['view_reports', 'Can view sales and order reports']);
        $hasRolePerm = $pdo->query("SHOW TABLES LIKE 'role_permissions'")->fetchColumn();
        if ($hasRolePerm) {
          $pdo->exec("INSERT IGNORE INTO role_permissions(role_id, permission_id)
                      SELECT 1, id FROM permissions WHERE name = 'view_reports'");
        }
      } catch (Throwable $e) { error_log('[estore schema v13] permission: ' . $e->getMessage()); }

      $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES('schema_v13_at',?)
                     ON DUPLICATE KEY UPDATE value=VALUES(value)")
          ->execute([date('Y-m-d H:i:s')]);

      $done = true;
      return true;
    } catch (Throwable $e) {
      error_log('[estore schema v13] ' . $e->getMessage());
      return false;
    }
  }
}
