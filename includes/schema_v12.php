<?php
// includes/schema_v12.php — অর্ডারের গোপন লিংক-কোড (orders.public_token) ও ট্র্যাকিং-চেষ্টার লগ (tracking_attempts)
// additive only; পুরোনো সব অর্ডারে এলোমেলো কোড বসানো হয়; marker: settings.schema_v12_at

if (!function_exists('ensure_estore_schema_v12')) {

  function ensure_estore_schema_v12(PDO $pdo, bool $force = false): bool {
    static $done = false;
    if ($done && !$force) return true;

    try {
      $stm = $pdo->prepare("SELECT value FROM settings WHERE `key`='schema_v12_at' LIMIT 1");
      $stm->execute();
      if ($stm->fetchColumn() && !$force) { $done = true; return true; }
    } catch (Throwable $e) {}

    try {
      $cols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
      if (!in_array('public_token', $cols, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN public_token CHAR(32) NULL DEFAULT NULL");
      }
      $idx = $pdo->query("SHOW INDEX FROM orders WHERE Key_name='uq_public_token'")->fetch();
      if (!$idx) $pdo->exec("ALTER TABLE orders ADD UNIQUE KEY uq_public_token (public_token)");

      /* যেসব অর্ডারে কোড নেই (পুরোনো) তাদের এলোমেলো, অনন্য কোড */
      $ids = $pdo->query("SELECT id FROM orders WHERE public_token IS NULL OR public_token=''")->fetchAll(PDO::FETCH_COLUMN);
      if ($ids) {
        $up = $pdo->prepare("UPDATE orders SET public_token=? WHERE id=? AND (public_token IS NULL OR public_token='')");
        foreach ($ids as $oid) {
          for ($try = 0; $try < 5; $try++) {
            try { $up->execute([bin2hex(random_bytes(16)), (int)$oid]); break; }
            catch (Throwable $e) { /* অনন্যতা-সংঘর্ষ হলে আবার */ }
          }
        }
      }

      $pdo->exec("CREATE TABLE IF NOT EXISTS tracking_attempts (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        ip         VARCHAR(45) NOT NULL,
        created_at DATETIME    NOT NULL,
        PRIMARY KEY (id),
        KEY idx_ip_time (ip, created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES('schema_v12_at',?)
                     ON DUPLICATE KEY UPDATE value=VALUES(value)")
          ->execute([date('Y-m-d H:i:s')]);

      $done = true;
      return true;
    } catch (Throwable $e) {
      error_log('[estore schema v12] ' . $e->getMessage());
      return false;
    }
  }
}
