<?php
// includes/schema_v3.php — slider button controls (size / position)
// additive only; পুরনো ডেটা অক্ষত থাকে

if (!function_exists('ensure_estore_schema_v3')) {

  function ensure_estore_schema_v3(PDO $pdo, bool $force = false): bool {
    static $done = false;
    if ($done && !$force) return true;

    try {
      $stm = $pdo->prepare("SELECT value FROM settings WHERE `key`='schema_v3_at' LIMIT 1");
      $stm->execute();
      if ($stm->fetchColumn() && !$force) { $done = true; return true; }
    } catch (Throwable $e) {
      // settings না থাকলে এগিয়ে যাই
    }

    try {
      estore_add_column_v3($pdo, 'sliders', 'btn_size',   "VARCHAR(10) NOT NULL DEFAULT 'md'");
      estore_add_column_v3($pdo, 'sliders', 'btn_pos_x',  "VARCHAR(10) NOT NULL DEFAULT 'center'");
      estore_add_column_v3($pdo, 'sliders', 'btn_pos_y',  "VARCHAR(10) NOT NULL DEFAULT 'bottom'");

      $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES('schema_v3_at',?)
                     ON DUPLICATE KEY UPDATE value=VALUES(value)")
          ->execute([date('Y-m-d H:i:s')]);

      $done = true;
      return true;
    } catch (Throwable $e) {
      error_log('[estore schema v3] ' . $e->getMessage());
      return false;
    }
  }

  function estore_add_column_v3(PDO $pdo, string $table, string $column, string $definition): void {
    try {
      $stm = $pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($column));
      if ($stm && $stm->fetch()) return;
      $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    } catch (Throwable $e) {
      error_log("[estore schema v3] $table.$column: " . $e->getMessage());
    }
  }
}
