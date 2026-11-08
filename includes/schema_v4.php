<?php
// includes/schema_v4.php — products.is_new_arrival (New Arrivals checkbox)
// additive only; পুরনো ডেটা অক্ষত থাকে

if (!function_exists('ensure_estore_schema_v4')) {

  function ensure_estore_schema_v4(PDO $pdo, bool $force = false): bool {
    static $done = false;
    if ($done && !$force) return true;

    try {
      $stm = $pdo->prepare("SELECT value FROM settings WHERE `key`='schema_v4_at' LIMIT 1");
      $stm->execute();
      if ($stm->fetchColumn() && !$force) { $done = true; return true; }
    } catch (Throwable $e) { /* settings না থাকলে এগিয়ে যাই */ }

    try {
      estore_add_column_v4($pdo, 'products', 'is_new_arrival', "TINYINT(1) NOT NULL DEFAULT 1");

      /* বিদ্যমান সব active প্রোডাক্ট ডিফল্টভাবে New Arrivals-এ থাকবে */
      try { $pdo->exec("UPDATE products SET is_new_arrival=1 WHERE is_new_arrival IS NULL"); } catch (Throwable $e) {}

      $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES('schema_v4_at',?)
                     ON DUPLICATE KEY UPDATE value=VALUES(value)")
          ->execute([date('Y-m-d H:i:s')]);

      $done = true;
      return true;
    } catch (Throwable $e) {
      error_log('[estore schema v4] ' . $e->getMessage());
      return false;
    }
  }

  function estore_add_column_v4(PDO $pdo, string $table, string $column, string $definition): void {
    try {
      $stm = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($column));
      if ($stm && $stm->fetch()) return;
      $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    } catch (Throwable $e) {
      error_log("[estore schema v4] $table.$column: " . $e->getMessage());
    }
  }
}
