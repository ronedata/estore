<?php
// includes/login_security.php — অ্যাডমিন লগইন সুরক্ষা: ব্যর্থ-চেষ্টার লক (আইপি / ইউজারনেম), ধীর করা, সাদা তালিকা
// নীতি: ডাটাবেস/টেবিলে সমস্যা হলে লগইন আটকায় না (fail-open) — সুরক্ষা কখনো সবাইকে বাইরে রাখবে না

if (!defined('LS_RANGES')) define('LS_RANGES', [
    'ip_max'         => [1, 100],
    'ip_minutes'     => [1, 1440],
    'user_max'       => [1, 200],
    'user_minutes'   => [1, 1440],
    'window_minutes' => [1, 1440],
    'slow_after'     => [0, 500],
    'slow_seconds'   => [1, 10],
    'retention_days' => [1, 365],
]);

if (!function_exists('ls_defaults')) {

  function ls_defaults(): array {
    return [
      'enabled'           => 1,
      'ip_lock_enabled'   => 1,
      'ip_max'            => 5,
      'ip_minutes'        => 15,
      'user_lock_enabled' => 0,
      'user_max'          => 10,
      'user_minutes'      => 15,
      'window_minutes'    => 15,
      'slow_after'        => 20,
      'slow_seconds'      => 3,
      'ip_source'         => 'auto',
      'whitelist'         => '',
      'show_remaining'    => 1,
      'retention_days'    => 30,
    ];
  }

  function ls_ip_sources(): array {
    return [
      'auto'             => ['স্বয়ংক্রিয় (প্রস্তাবিত)', 'সরাসরি আইপি ব্যক্তিগত/অভ্যন্তরীণ ঠিকানা হলে (= প্রক্সির পেছনে) X-Forwarded-For-এর শেষ আইপি, নইলে সরাসরি আইপি। জাল হেডারে লক এড়ানো যায় না'],
      'remote_addr'      => ['REMOTE_ADDR', 'সার্ভারের দেখা সরাসরি সংযোগ (সবচেয়ে নিরাপদ; প্রক্সির পেছনে হলে সবার আইপি এক দেখাতে পারে)'],
      'xff_last'         => ['X-Forwarded-For (শেষের আইপি)', 'একটি বিশ্বস্ত প্রক্সির পেছনে থাকলে: প্রক্সি যে আইপি দেখেছে সেটা (ভুয়া করা কঠিন)'],
      'x_real_ip'        => ['X-Real-IP', 'প্রক্সি এই হেডার বসালে'],
      'cf_connecting_ip' => ['CF-Connecting-IP', 'Cloudflare ব্যবহার করলে'],
      'xff_first'        => ['X-Forwarded-For (প্রথম আইপি)', 'ঝুঁকিপূর্ণ: আক্রমণকারী নিজে বদলাতে পারে, লক এড়ানো সহজ'],
    ];
  }



  function ls_norm_user($u): string {
    $u = is_scalar($u) ? trim((string)$u) : '';
    $u = function_exists('mb_strtolower') ? mb_strtolower($u, 'UTF-8') : strtolower($u);
    return function_exists('mb_substr') ? mb_substr($u, 0, 100, 'UTF-8') : substr($u, 0, 100);
  }

  /* ---------- সেটিংস ---------- */

  function ls_load(PDO $pdo): array {
    $cfg = ls_defaults();
    try {
      $st = $pdo->query("SELECT `key`,`value` FROM settings WHERE `key` LIKE 'ls\\_%'");
      foreach ($st->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
        $name = substr($k, 3);
        if (!array_key_exists($name, $cfg)) continue;
        if (in_array($name, ['ip_source', 'whitelist'], true)) $cfg[$name] = (string)$v;
        else $cfg[$name] = (int)$v;
      }
    } catch (Throwable $e) {}
    if (!isset(ls_ip_sources()[$cfg['ip_source']])) $cfg['ip_source'] = 'auto';
    return $cfg;
  }

  /** @return array{0:?array,1:array} [পরিষ্কার সেটিংস|null, ত্রুটির তালিকা] */
  function ls_validate(array $in): array {
    $errors = [];
    $clean = ls_defaults();
    foreach (['enabled', 'ip_lock_enabled', 'user_lock_enabled', 'show_remaining'] as $k) {
      $clean[$k] = !empty($in[$k]) ? 1 : 0;
    }
    $labels = [
      'ip_max' => 'আইপি-লকের চেষ্টার সংখ্যা', 'ip_minutes' => 'আইপি-লকের সময় (মিনিট)',
      'user_max' => 'ইউজারনেম-লকের চেষ্টার সংখ্যা', 'user_minutes' => 'ইউজারনেম-লকের সময় (মিনিট)',
      'window_minutes' => 'গণনার সময়সীমা (মিনিট)', 'slow_after' => 'ধীর করার শুরু (বার)',
      'slow_seconds' => 'ধীর করার সময় (সেকেন্ড)', 'retention_days' => 'লগ রাখার দিন',
    ];
    foreach (LS_RANGES as $k => [$min, $max]) {
      $v = $in[$k] ?? null;
      if (!is_scalar($v) || trim((string)$v) === '' || !preg_match('/^\d+$/', trim((string)$v))) {
        $errors[] = $labels[$k] . ': ' . $min . ' থেকে ' . $max . '-এর মধ্যে একটি পূর্ণ সংখ্যা দিন।';
        continue;
      }
      $n = (int)trim((string)$v);
      if ($n < $min || $n > $max) { $errors[] = $labels[$k] . ': ' . $min . ' থেকে ' . $max . '-এর মধ্যে হতে হবে।'; continue; }
      $clean[$k] = $n;
    }
    $src = is_scalar($in['ip_source'] ?? null) ? (string)$in['ip_source'] : '';
    if (!isset(ls_ip_sources()[$src])) $errors[] = 'আইপি শনাক্তকরণের উৎস সঠিক নয়।';
    else $clean['ip_source'] = $src;

    $lines = preg_split('/[\r\n,;\s]+/', is_scalar($in['whitelist'] ?? null) ? (string)$in['whitelist'] : '', -1, PREG_SPLIT_NO_EMPTY);
    $good = [];
    foreach ($lines as $ip) {
      if (!filter_var($ip, FILTER_VALIDATE_IP)) { $errors[] = 'সাদা তালিকায় অবৈধ আইপি: ' . mb_substr($ip, 0, 45, 'UTF-8'); continue; }
      $good[$ip] = true;
    }
    if (count($good) > 50) $errors[] = 'সাদা তালিকায় সর্বোচ্চ ৫০টি আইপি রাখা যায়।';
    $clean['whitelist'] = implode("\n", array_keys($good));

    return $errors ? [null, $errors] : [$clean, []];
  }

  function ls_save(PDO $pdo, array $clean): bool {
    try {
      $st = $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)");
      foreach ($clean as $k => $v) $st->execute(['ls_' . $k, (string)$v]);
      return true;
    } catch (Throwable $e) { error_log('[login_security save] ' . $e->getMessage()); return false; }
  }

  /* ---------- আইপি শনাক্তকরণ ---------- */

  function ls_valid_ip($v): ?string {
    $v = is_scalar($v) ? trim((string)$v) : '';
    return ($v !== '' && filter_var($v, FILTER_VALIDATE_IP)) ? $v : null;
  }

  /** প্রতিটি উৎসে সার্ভার কী দেখছে (অ্যাডমিন পেজে দেখানোর জন্য) */
  function ls_candidates(?array $server = null): array {
    $s = $server ?? $_SERVER;
    $xff = is_scalar($s['HTTP_X_FORWARDED_FOR'] ?? null) ? trim((string)$s['HTTP_X_FORWARDED_FOR']) : '';
    $parts = $xff !== '' ? array_values(array_filter(array_map('trim', explode(',', $xff)), 'strlen')) : [];
    return [
      'remote_addr'      => ls_valid_ip($s['REMOTE_ADDR'] ?? null),
      'xff_last'         => $parts ? ls_valid_ip(end($parts)) : null,
      'xff_first'        => $parts ? ls_valid_ip($parts[0]) : null,
      'x_real_ip'        => ls_valid_ip($s['HTTP_X_REAL_IP'] ?? null),
      'cf_connecting_ip' => ls_valid_ip($s['HTTP_CF_CONNECTING_IP'] ?? null),
    ];
  }

  /** ব্যক্তিগত/লুপব্যাক/সংরক্ষিত ঠিকানা (প্রক্সি বা লোকাল) */
  function ls_is_private_ip(string $ip): bool {
    return !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
  }

  /**
   * নির্বাচিত উৎস থেকে আইপি; না পেলে REMOTE_ADDR; তাও না পেলে '0.0.0.0'।
   * 'auto': সরাসরি সংযোগ ব্যক্তিগত ঠিকানা হলে (প্রক্সি) X-Forwarded-For-এর শেষ আইপি, নইলে সরাসরি আইপি —
   * সরাসরি সংযোগ সরাসরি ইন্টারনেট থেকে এলে আক্রমণকারীর বসানো হেডার কখনো বিশ্বাস করা হয় না।
   */
  function ls_client_ip(string $source = 'auto', ?array $server = null): string {
    $c = ls_candidates($server);
    $remote = $c['remote_addr'];
    if ($source === 'auto') {
      if ($remote !== null && ls_is_private_ip($remote) && $c['xff_last'] !== null) return $c['xff_last'];
      return $remote ?? '0.0.0.0';
    }
    return ($c[$source] ?? $remote ?? '0.0.0.0') ?: '0.0.0.0';
  }

  function ls_whitelist(array $cfg): array {
    return array_values(array_filter(preg_split('/\s+/', (string)$cfg['whitelist'], -1, PREG_SPLIT_NO_EMPTY)));
  }

  function ls_is_whitelisted(string $ip, array $cfg): bool {
    return in_array($ip, ls_whitelist($cfg), true);
  }

  /* ---------- লক ---------- */

  /** চালু থাকা লক আছে কি: [ধরন ('ip'|'user'), until (timestamp)] অথবা null */
  function ls_active_lock(PDO $pdo, string $ip, string $username, array $cfg, ?int $now = null): ?array {
    if (empty($cfg['enabled']) || ls_is_whitelisted($ip, $cfg)) return null;
    $now = $now ?? time();
    try {
      $best = null;
      $st = $pdo->prepare("SELECT MAX(locked_until) FROM login_locks WHERE kind=? AND subject=? AND locked_until > ?");
      $nowS = date('Y-m-d H:i:s', $now);
      if (!empty($cfg['ip_lock_enabled'])) {
        $st->execute(['ip', $ip, $nowS]);
        $u = $st->fetchColumn();
        if ($u) $best = ['ip', strtotime($u)];
      }
      $un = ls_norm_user($username);
      if (!empty($cfg['user_lock_enabled']) && $un !== '') {
        $st->execute(['user', $un, $nowS]);
        $u = $st->fetchColumn();
        if ($u && (!$best || strtotime($u) > $best[1])) $best = ['user', strtotime($u)];
      }
      return $best ? ['by' => $best[0], 'until' => $best[1]] : null;
    } catch (Throwable $e) { return null; }
  }

  function ls_record(PDO $pdo, string $ip, string $username, string $result, ?int $now = null): void {
    try {
      $pdo->prepare("INSERT INTO login_attempts(ip, username, result, created_at) VALUES(?,?,?,?)")
          ->execute([$ip, ls_norm_user($username), in_array($result, ['fail', 'ok', 'blocked', 'failx'], true) ? $result : 'fail', date('Y-m-d H:i:s', $now ?? time())]);
    } catch (Throwable $e) {}
  }

  /** শেষ লকের মেয়াদ-শেষ ও গণনার সময়সীমা — এর পরের ব্যর্থ চেষ্টা গোনা হয় */
  function ls_fail_count(PDO $pdo, string $kind, string $subject, array $cfg, ?int $now = null): int {
    $now = $now ?? time();
    try {
      $cut = $now - (int)$cfg['window_minutes'] * 60;
      $st = $pdo->prepare("SELECT MAX(locked_until) FROM login_locks WHERE kind=? AND subject=?");
      $st->execute([$kind, $subject]);
      $lastUntil = $st->fetchColumn();
      if ($lastUntil) $cut = max($cut, strtotime($lastUntil));
      $col = $kind === 'ip' ? 'ip' : 'username';
      $st = $pdo->prepare("SELECT COUNT(*) FROM login_attempts WHERE $col=? AND result='fail' AND created_at > ?");
      $st->execute([$subject, date('Y-m-d H:i:s', $cut)]);
      return (int)$st->fetchColumn();
    } catch (Throwable $e) { return 0; }
  }

  /**
   * ব্যর্থ চেষ্টা নথিভুক্ত করে, সীমা ছুঁলে লক বসায়।
   * @return array{locked:bool,by:?string,until:?int,remaining:?int}
   */
  function ls_on_fail(PDO $pdo, string $ip, string $username, array $cfg, ?int $now = null): array {
    $out = ['locked' => false, 'by' => null, 'until' => null, 'remaining' => null];
    if (empty($cfg['enabled']) || ls_is_whitelisted($ip, $cfg)) return $out;
    $now = $now ?? time();
    ls_record($pdo, $ip, $username, 'fail', $now);
    try {
      $rem = [];
      $mk = $pdo->prepare("INSERT INTO login_locks(kind, subject, locked_until, created_at) VALUES(?,?,?,?)");
      $un = ls_norm_user($username);
      if (!empty($cfg['ip_lock_enabled'])) {
        $n = ls_fail_count($pdo, 'ip', $ip, $cfg, $now);
        if ($n >= (int)$cfg['ip_max']) {
          $until = $now + (int)$cfg['ip_minutes'] * 60;
          $mk->execute(['ip', $ip, date('Y-m-d H:i:s', $until), date('Y-m-d H:i:s', $now)]);
          $out = ['locked' => true, 'by' => 'ip', 'until' => $until, 'remaining' => 0];
        } else $rem[] = (int)$cfg['ip_max'] - $n;
      }
      if (!empty($cfg['user_lock_enabled']) && $un !== '') {
        $n = ls_fail_count($pdo, 'user', $un, $cfg, $now);
        if ($n >= (int)$cfg['user_max']) {
          $until = $now + (int)$cfg['user_minutes'] * 60;
          $mk->execute(['user', $un, date('Y-m-d H:i:s', $until), date('Y-m-d H:i:s', $now)]);
          if (!$out['locked'] || $until > $out['until']) $out = ['locked' => true, 'by' => 'user', 'until' => $until, 'remaining' => 0];
        } else $rem[] = (int)$cfg['user_max'] - $n;
      }
      if (!$out['locked'] && $rem) $out['remaining'] = max(0, min($rem));
    } catch (Throwable $e) {}
    return $out;
  }

  /**
   * সফল লগইনে শুধু **এই আইপির** ব্যর্থ-গণনা শূন্য (রেকর্ড মোছে না — 'failx' চিহ্নিত, লগে থাকে, গণনায় ধরা হয় না)।
   * ইউজারনেম ধরে মোছা হয় না: নইলে আসল অ্যাডমিনের সফল লগইনে অন্য আইপির (আক্রমণকারীর) ভুলও মুছে যেত।
   */
  function ls_on_success(PDO $pdo, string $ip, string $username, ?int $now = null): void {
    ls_record($pdo, $ip, $username, 'ok', $now);
    try {
      $pdo->prepare("UPDATE login_attempts SET result='failx' WHERE result='fail' AND ip=?")->execute([$ip]);
    } catch (Throwable $e) {}
  }

  /** ধীর করার সেকেন্ড (ইউজারনেমে বহু ব্যর্থ চেষ্টা হয়ে থাকলে), নইলে ০ */
  function ls_slow_seconds(PDO $pdo, string $username, array $cfg, ?int $now = null): int {
    if (empty($cfg['enabled']) || (int)$cfg['slow_after'] <= 0) return 0;
    $un = ls_norm_user($username);
    if ($un === '') return 0;
    $n = ls_fail_count($pdo, 'user', $un, ['window_minutes' => $cfg['window_minutes']], $now);
    return $n >= (int)$cfg['slow_after'] ? (int)$cfg['slow_seconds'] : 0;
  }

  function ls_purge(PDO $pdo, int $days): int {
    try {
      $cut = date('Y-m-d H:i:s', time() - max(1, $days) * 86400);
      $a = $pdo->prepare("DELETE FROM login_attempts WHERE created_at < ?"); $a->execute([$cut]);
      $b = $pdo->prepare("DELETE FROM login_locks WHERE locked_until < ?"); $b->execute([$cut]);
      return (int)$a->rowCount() + (int)$b->rowCount();
    } catch (Throwable $e) { return 0; }
  }

  /* ---------- অ্যাডমিন পেজের জন্য ---------- */

  function ls_locks(PDO $pdo, ?int $now = null): array {
    try {
      $st = $pdo->prepare("SELECT kind, subject, MAX(locked_until) AS until_at, MIN(created_at) AS since FROM login_locks WHERE locked_until > ? GROUP BY kind, subject ORDER BY until_at DESC");
      $st->execute([date('Y-m-d H:i:s', $now ?? time())]);
      return $st->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
  }

  function ls_recent(PDO $pdo, int $limit = 50): array {
    try {
      $st = $pdo->prepare("SELECT ip, username, result, created_at FROM login_attempts ORDER BY id DESC LIMIT " . max(1, min(200, $limit)));
      $st->execute();
      return $st->fetchAll() ?: [];
    } catch (Throwable $e) { return []; }
  }

  /** একটি আইপি/ইউজারনেমের লক ও ব্যর্থ-গণনা মুছে খুলে দেয় */
  function ls_unlock(PDO $pdo, string $kind, string $subject): bool {
    if (!in_array($kind, ['ip', 'user'], true) || $subject === '') return false;
    try {
      $pdo->prepare("DELETE FROM login_locks WHERE kind=? AND subject=?")->execute([$kind, $subject]);
      $col = $kind === 'ip' ? 'ip' : 'username';
      $pdo->prepare("UPDATE login_attempts SET result='failx' WHERE result='fail' AND $col=?")->execute([$subject]);
      return true;
    } catch (Throwable $e) { return false; }
  }

  function ls_unlock_all(PDO $pdo): bool {
    try {
      $pdo->exec("DELETE FROM login_locks");
      $pdo->exec("UPDATE login_attempts SET result='failx' WHERE result='fail'");
      return true;
    } catch (Throwable $e) { return false; }
  }

  function ls_wait_text(int $until, ?int $now = null): string {
    $sec = max(1, $until - ($now ?? time()));
    return $sec >= 90 ? ceil($sec / 60) . ' মিনিট' : $sec . ' সেকেন্ড';
  }
}
