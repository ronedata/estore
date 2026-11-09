<?php
// includes/schema_v10.php — অর্ডারে অ্যাডমিন ডিসকাউন্ট (orders.discount, discount_note, discount_by)
// additive only: পুরোনো অর্ডারে discount = 0; total আগের মতোই (নিট) সংখ্যা

if (!function_exists('ensure_estore_schema_v10')) {

  function ensure_estore_schema_v10(PDO $pdo, bool $force = false): bool {
    static $done = false;
    if ($done && !$force) return true;

    try {
      $stm = $pdo->prepare("SELECT value FROM settings WHERE `key`='schema_v10_at' LIMIT 1");
      $stm->execute();
      if ($stm->fetchColumn() && !$force) { $done = true; return true; }
    } catch (Throwable $e) {}

    try {
      $cols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
      if (!in_array('discount', $cols, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN discount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER shipping_fee");
      }
      if (!in_array('discount_note', $cols, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN discount_note VARCHAR(160) NULL DEFAULT NULL AFTER discount");
      }
      if (!in_array('discount_by', $cols, true)) {
        $pdo->exec("ALTER TABLE orders ADD COLUMN discount_by VARCHAR(60) NULL DEFAULT NULL AFTER discount_note");
      }

      $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES('schema_v10_at',?)
                     ON DUPLICATE KEY UPDATE value=VALUES(value)")
          ->execute([date('Y-m-d H:i:s')]);

      $done = true;
      return true;
    } catch (Throwable $e) {
      error_log('[estore schema v10] ' . $e->getMessage());
      return false;
    }
  }
}
