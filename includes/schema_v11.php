<?php
// includes/schema_v11.php — অ্যাডমিন লগইন সুরক্ষা: login_attempts (চেষ্টার লগ), login_locks (লক)
// additive only; marker: settings.schema_v11_at

if (!function_exists('ensure_estore_schema_v11')) {

  function ensure_estore_schema_v11(PDO $pdo, bool $force = false): bool {
    static $done = false;
    if ($done && !$force) return true;

    try {
      $stm = $pdo->prepare("SELECT value FROM settings WHERE `key`='schema_v11_at' LIMIT 1");
      $stm->execute();
      if ($stm->fetchColumn() && !$force) { $done = true; return true; }
    } catch (Throwable $e) {}

    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ip         VARCHAR(45)  NOT NULL,
        username   VARCHAR(100) NOT NULL DEFAULT '',
        result     VARCHAR(8)   NOT NULL DEFAULT 'fail',
        created_at DATETIME     NOT NULL,
        PRIMARY KEY (id),
        KEY idx_ip   (ip, result, created_at),
        KEY idx_user (username, result, created_at),
        KEY idx_time (created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      $pdo->exec("CREATE TABLE IF NOT EXISTS login_locks (
        id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        kind         VARCHAR(8)   NOT NULL,
        subject      VARCHAR(100) NOT NULL,
        locked_until DATETIME     NOT NULL,
        created_at   DATETIME     NOT NULL,
        PRIMARY KEY (id),
        KEY idx_subject (kind, subject, locked_until)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES('schema_v11_at',?)
                     ON DUPLICATE KEY UPDATE value=VALUES(value)")
          ->execute([date('Y-m-d H:i:s')]);

      $done = true;
      return true;
    } catch (Throwable $e) {
      error_log('[estore schema v11] ' . $e->getMessage());
      return false;
    }
  }
}
