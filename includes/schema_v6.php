<?php
// includes/schema_v6.php — slider text overlay toggle (show_text)
// additive only; পুরোনো ডেটা অক্ষত থাকে

if (!function_exists('ensure_estore_schema_v6')) {

  function ensure_estore_schema_v6(PDO $pdo, bool $force = false): bool {
    static $done = false;
    if ($done && !$force) return true;

    try {
      $stm = $pdo->prepare("SELECT value FROM settings WHERE `key`='schema_v6_at' LIMIT 1");
      $stm->execute();
      if ($stm->fetchColumn() && !$force) { $done = true; return true; }
    } catch (Throwable $e) {}

    try {
      estore_add_column_v6($pdo, 'sliders', 'show_text', "TINYINT(1) NOT NULL DEFAULT 0");

      $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES('schema_v6_at',?)
                     ON DUPLICATE KEY UPDATE value=VALUES(value)")
          ->execute([date('Y-m-d H:i:s')]);

      $done = true;
      return true;
    } catch (Throwable $e) {
      error_log('[estore schema v6] ' . $e->getMessage());
      return false;
    }
  }

  function estore_add_column_v6(PDO $pdo, string $table, string $column, string $definition): void {
    try {
      $stm = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($column));
      if ($stm && $stm->fetch()) return;
      $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    } catch (Throwable $e) {
      error_log("[estore schema v6] $table.$column: " . $e->getMessage());
    }
  }
}
