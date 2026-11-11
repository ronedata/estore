<?php
// includes/backup.php — অ্যাডমিন ব্যাকআপ: ডাটাবেস (.sql স্ট্রিম) ও প্রোজেক্ট (.zip) — mysqldump/exec ছাড়া, শুধু PHP

if (!function_exists('bk_demo_skip_tables')) {

  /** ডেমো ডাম্পে যেসব টেবিলের শুধু কাঠামো যায়, ডেটা নয় (ব্যক্তিগত/নিরাপত্তা-সংবেদনশীল) */
  function bk_demo_skip_tables(): array {
    return ['customers', 'orders', 'order_items', 'contact_messages', 'product_reviews', 'page_views',
            'subscribers', 'wishlists', 'admin_users', 'announcements', 'login_attempts', 'login_locks', 'tracking_attempts'];
  }

  function bk_demo_skip_settings(): array { return ['store_phone', 'store_email']; }

  function bk_tables(PDO $pdo): array {
    return $pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
  }

  function bk_qid(string $name): string { return '`' . str_replace('`', '``', $name) . '`'; }

  /** @return array{tables:int,rows:int,bytes:int} আনুমানিক */
  function bk_db_summary(PDO $pdo): array {
    try {
      $r = $pdo->query("SELECT COUNT(*), COALESCE(SUM(table_rows),0), COALESCE(SUM(data_length+index_length),0) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'")->fetch(PDO::FETCH_NUM);
      return ['tables' => (int)$r[0], 'rows' => (int)$r[1], 'bytes' => (int)$r[2]];
    } catch (Throwable $e) { return ['tables' => 0, 'rows' => 0, 'bytes' => 0]; }
  }

  /** SQL ডাম্প $out($string) কলব্যাকে ধাপে ধাপে দেয় — মেমোরিতে পুরোটা জমে না */
  function bk_dump_sql(PDO $pdo, bool $demo, callable $out): array {
    $stats = ['tables' => 0, 'rows' => 0];
    $skip = $demo ? bk_demo_skip_tables() : [];
    $out("-- eStore ডাটাবেস ব্যাকআপ (" . ($demo ? 'ডেমো: ব্যক্তিগত টেবিলের ডেটা বাদ' : 'সম্পূর্ণ') . ")\n");
    $out("-- তৈরি: " . date('Y-m-d H:i:s') . "\n");
    $out("-- ইমপোর্ট: phpMyAdmin → Import। সতর্কতা: DROP TABLE IF EXISTS আছে — চালু ডাটাবেসে ইমপোর্ট করলে বর্তমান ডেটা মুছে যাবে।\n\n");
    $out("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");

    $buffered = $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY);
    foreach (bk_tables($pdo) as $t) {
      $q = bk_qid($t);
      $create = $pdo->query("SHOW CREATE TABLE $q")->fetch(PDO::FETCH_NUM);
      $out("DROP TABLE IF EXISTS $q;\n" . $create[1] . ";\n\n");
      $stats['tables']++;
      if (in_array($t, $skip, true)) continue;

      $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
      try {
        $st = $pdo->query("SELECT * FROM $q");
        $batch = []; $cols = null; $n = 0;
        $flush = function () use (&$batch, &$cols, $q, $out) {
          if (!$batch) return;
          $out("INSERT INTO $q ($cols) VALUES\n" . implode(",\n", $batch) . ";\n");
          $batch = [];
        };
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
          if ($demo && $t === 'settings' && in_array($row['key'] ?? '', bk_demo_skip_settings(), true)) continue;
          if ($cols === null) $cols = implode(',', array_map('bk_qid', array_keys($row)));
          $vals = [];
          foreach ($row as $v) $vals[] = $v === null ? 'NULL' : $pdo->quote((string)$v);
          $batch[] = '(' . implode(',', $vals) . ')';
          $n++; $stats['rows']++;
          if (count($batch) >= 100) $flush();
        }
        $flush();
        $st->closeCursor();
      } finally {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered);
      }
      if ($n) $out("\n");
    }
    $out("SET FOREIGN_KEY_CHECKS=1;\n-- শেষ\n");
    return $stats;
  }

  /** প্রোজেক্টের রুট (includes-এর এক ধাপ ওপর) */
  function bk_root(): string { return rtrim(str_replace('\\', '/', realpath(__DIR__ . '/..')), '/'); }

  function bk_zip_excluded_dirs(): array { return ['uploads', 'logs', 'database', 'tools', 'docs', '.git', '.github', 'node_modules', 'backups']; }

  function bk_zip_excluded_file(string $rel): bool {
    if (in_array($rel, ['includes/config.php', 'includes/live.config.php'], true)) return true;
    if (preg_match('~\.(sql|zip|log|bak|old|env)$~i', $rel)) return true;
    return false;
  }

  /** @return array<string,string> জিপের ভেতরের পাথ => আসল পাথ */
  function bk_collect(string $mode): array {
    $root = bk_root(); $files = [];
    $base = $mode === 'uploads' ? $root . '/uploads' : $root;
    if (!is_dir($base)) return $files;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY);
    foreach ($it as $f) {
      if (!$f->isFile() || $f->isLink()) continue;
      $abs = str_replace('\\', '/', $f->getPathname());
      $rel = ltrim(substr($abs, strlen($root)), '/');
      if ($mode === 'code') {
        $top = explode('/', $rel)[0];
        if (in_array($top, bk_zip_excluded_dirs(), true) || bk_zip_excluded_file($rel)) continue;
        if (strpos($rel, '.git') === 0 || strpos($rel, '/.git/') !== false) continue;
      }
      $files[$rel] = $abs;
    }
    ksort($files);
    return $files;
  }

  function bk_human(int $bytes): string {
    if ($bytes >= 1048576) return number_format($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return number_format($bytes / 1024, 0) . ' KB';
    return $bytes . ' B';
  }

  function bk_mark(PDO $pdo, string $kind): void {
    try {
      $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)")
          ->execute(['backup_last_' . $kind, date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {}
  }

  function bk_last(PDO $pdo, string $kind): ?string {
    try {
      $s = $pdo->prepare("SELECT value FROM settings WHERE `key`=? LIMIT 1");
      $s->execute(['backup_last_' . $kind]);
      $v = $s->fetchColumn();
      return $v ? (string)$v : null;
    } catch (Throwable $e) { return null; }
  }

  function bk_stale(?string $when, int $days = 7): bool {
    return $when === null || strtotime($when) < time() - $days * 86400;
  }

  function bk_start_download(string $filename, string $type): void {
    @set_time_limit(0);
    @ignore_user_abort(true);
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: ' . $type);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
  }
}
