<?php
// includes/schema_v9.php — জেলা-ভিত্তিক ডেলিভারি চার্জ (delivery_districts, orders.shipping_district)
// additive only: orders.shipping_area ENUM অক্ষত; পুরোনো delivery_dhaka / delivery_nationwide settings অক্ষত

if (!function_exists('ensure_estore_schema_v9')) {

  function ensure_estore_schema_v9(PDO $pdo, bool $force = false): bool {
    static $done = false;
    if ($done && !$force) return true;

    try {
      $stm = $pdo->prepare("SELECT value FROM settings WHERE `key`='schema_v9_at' LIMIT 1");
      $stm->execute();
      if ($stm->fetchColumn() && !$force) { $done = true; return true; }
    } catch (Throwable $e) {}

    try {
      require_once __DIR__ . '/delivery.php';

      $pdo->exec("CREATE TABLE IF NOT EXISTS delivery_districts (
        id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        division   VARCHAR(40)  NOT NULL,
        name_bn    VARCHAR(60)  NOT NULL,
        name_en    VARCHAR(60)  NOT NULL,
        fee        DECIMAL(10,2) NULL DEFAULT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        UNIQUE KEY uq_name_en (name_en),
        KEY idx_division (division)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      $hasCol = $pdo->query("SHOW COLUMNS FROM orders LIKE 'shipping_district'")->fetch();
      if (!$hasCol) $pdo->exec("ALTER TABLE orders ADD COLUMN shipping_district VARCHAR(60) NULL DEFAULT NULL AFTER shipping_area");

      /* ---------- সিড: আজকের আচরণ হুবহু থাকে ----------
         ঢাকা জেলা = আগের delivery_dhaka; গ্লোবাল = আগের delivery_nationwide; বাকি ৬৩ জেলা ফাঁকা (গ্লোবাল) */
      $count = (int)$pdo->query("SELECT COUNT(*) FROM delivery_districts")->fetchColumn();
      if ($count === 0) {
        $old = $pdo->query("SELECT `key`,`value` FROM settings WHERE `key` IN ('delivery_dhaka','delivery_nationwide')")
                   ->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
        $dhakaFee = (isset($old['delivery_dhaka']) && is_numeric($old['delivery_dhaka'])) ? (float)$old['delivery_dhaka'] : 60.0;

        $ins = $pdo->prepare("INSERT INTO delivery_districts(division,name_bn,name_en,fee,sort_order) VALUES(?,?,?,?,?)");
        $n = 0;
        foreach (dl_districts_seed() as $division => $list) {
          foreach ($list as [$bn, $en]) {
            $n++;
            $ins->execute([$division, $bn, $en, $en === 'Dhaka' ? $dhakaFee : null, $n]);
          }
        }

        $hasGlobal = $pdo->query("SELECT COUNT(*) FROM settings WHERE `key`='delivery_global'")->fetchColumn();
        if (!$hasGlobal) {
          $g = (isset($old['delivery_nationwide']) && is_numeric($old['delivery_nationwide'])) ? (float)$old['delivery_nationwide'] : (float)DL_DEFAULT_GLOBAL;
          $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES('delivery_global',?)")->execute([(string)$g]);
        }
      }

      try {
        $pdo->prepare("INSERT INTO permissions(name, description) VALUES(?,?)
                       ON DUPLICATE KEY UPDATE description=VALUES(description)")
            ->execute(['manage_delivery', 'Can manage district-wise delivery charges']);
        $hasRolePerm = $pdo->query("SHOW TABLES LIKE 'role_permissions'")->fetchColumn();
        if ($hasRolePerm) {
          $pdo->exec("INSERT IGNORE INTO role_permissions(role_id, permission_id)
                      SELECT 1, id FROM permissions WHERE name = 'manage_delivery'");
        }
      } catch (Throwable $e) { error_log('[estore schema v9] permission: ' . $e->getMessage()); }

      $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES('schema_v9_at',?)
                     ON DUPLICATE KEY UPDATE value=VALUES(value)")
          ->execute([date('Y-m-d H:i:s')]);

      $done = true;
      return true;
    } catch (Throwable $e) {
      error_log('[estore schema v9] ' . $e->getMessage());
      return false;
    }
  }
}
