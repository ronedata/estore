<?php
// includes/schema_v8.php — ঘোষণা (announcements): বার ও পপআপ
// additive only; marker: settings.schema_v8_at

if (!function_exists('ensure_estore_schema_v8')) {

  function ensure_estore_schema_v8(PDO $pdo, bool $force = false): bool {
    static $done = false;
    if ($done && !$force) return true;

    try {
      $stm = $pdo->prepare("SELECT value FROM settings WHERE `key`='schema_v8_at' LIMIT 1");
      $stm->execute();
      if ($stm->fetchColumn() && !$force) { $done = true; return true; }
    } catch (Throwable $e) {}

    try {
      $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
        id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        kind        VARCHAR(10)  NOT NULL DEFAULT 'bar',
        title       VARCHAR(160) NULL,
        message     TEXT NULL,
        link_url    VARCHAR(500) NULL,
        btn_label   VARCHAR(60)  NULL,
        image       VARCHAR(255) NULL,
        color       VARCHAR(10)  NOT NULL DEFAULT 'blue',
        closable    TINYINT(1)   NOT NULL DEFAULT 1,
        delay_sec   INT          NOT NULL DEFAULT 3,
        reshow      VARCHAR(10)  NOT NULL DEFAULT 'days',
        reshow_days INT          NOT NULL DEFAULT 7,
        start_at    DATETIME NULL,
        end_at      DATETIME NULL,
        active      TINYINT(1)   NOT NULL DEFAULT 1,
        created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_kind_active (kind, active)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

      try {
        $pdo->prepare("INSERT INTO permissions(name, description) VALUES(?,?)
                       ON DUPLICATE KEY UPDATE description=VALUES(description)")
            ->execute(['manage_announcements', 'Can manage announcement bar and popup']);
        $hasRolePerm = $pdo->query("SHOW TABLES LIKE 'role_permissions'")->fetchColumn();
        if ($hasRolePerm) {
          $pdo->exec("INSERT IGNORE INTO role_permissions(role_id, permission_id)
                      SELECT 1, id FROM permissions WHERE name = 'manage_announcements'");
        }
      } catch (Throwable $e) { error_log('[estore schema v8] permission: ' . $e->getMessage()); }

      $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES('schema_v8_at',?)
                     ON DUPLICATE KEY UPDATE value=VALUES(value)")
          ->execute([date('Y-m-d H:i:s')]);

      $done = true;
      return true;
    } catch (Throwable $e) {
      error_log('[estore schema v8] ' . $e->getMessage());
      return false;
    }
  }
}
