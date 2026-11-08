<?php
// includes/schema_v5.php — contact_messages (contact form)
// additive only; পুরনো ডেটা অক্ষত থাকে

if (!function_exists('ensure_estore_schema_v5')) {

  function ensure_estore_schema_v5(PDO $pdo, bool $force = false): bool {
    static $done = false;
    if ($done && !$force) return true;

    try {
      $stm = $pdo->prepare("SELECT value FROM settings WHERE `key`='schema_v5_at' LIMIT 1");
      $stm->execute();
      if ($stm->fetchColumn() && !$force) { $done = true; return true; }
    } catch (Throwable $e) {}

    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS contact_messages (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT,
        name VARCHAR(120) NOT NULL,
        contact VARCHAR(160) NOT NULL DEFAULT '',
        message TEXT NOT NULL,
        ip VARCHAR(64) NOT NULL DEFAULT '',
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_read (is_read),
        KEY idx_created (created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

      $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES('schema_v5_at',?)
                     ON DUPLICATE KEY UPDATE value=VALUES(value)")
          ->execute([date('Y-m-d H:i:s')]);

      $done = true;
      return true;
    } catch (Throwable $e) {
      error_log('[estore schema v5] ' . $e->getMessage());
      return false;
    }
  }
}
