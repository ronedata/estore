<?php
// includes/order_access.php — অর্ডারের তথ্য কে দেখতে পারবে: গোপন লিংক-কোড, মোবাইল+অর্ডার কোড দিয়ে ট্র্যাকিং, চেষ্টার সীমা
// নীতি: অর্ডার আইডি (১, ২, ৩…) দিয়ে কখনো ক্রেতার তথ্য খোলা হয় না — আইডি আন্দাজ করা সহজ

if (!defined('OA_TRACK_MAX_FAIL')) define('OA_TRACK_MAX_FAIL', 10);
if (!defined('OA_TRACK_WINDOW_MIN')) define('OA_TRACK_WINDOW_MIN', 10);

if (!function_exists('oa_new_token')) {

  function oa_new_token(): string { return bin2hex(random_bytes(16)); }

  function oa_valid_token($t): bool { return is_string($t) && (bool)preg_match('/^[a-f0-9]{32}\z/', $t); }

  function oa_by_token(PDO $pdo, $t): ?array {
    if (!oa_valid_token($t)) return null;
    try {
      $st = $pdo->prepare("SELECT * FROM orders WHERE public_token=? LIMIT 1");
      $st->execute([$t]);
      return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return null; }
  }

  /** পুরোনো অর্ডারে কোড না থাকলে বসায় (মাইগ্রেশন ফেল করলেও লিংক যেন ভাঙে না) */
  function oa_ensure_token(PDO $pdo, array $order): ?string {
    if (!empty($order['public_token'])) return (string)$order['public_token'];
    try {
      $tok = oa_new_token();
      $pdo->prepare("UPDATE orders SET public_token=? WHERE id=? AND (public_token IS NULL OR public_token='')")->execute([$tok, (int)$order['id']]);
      $st = $pdo->prepare("SELECT public_token FROM orders WHERE id=?");
      $st->execute([(int)$order['id']]);
      $v = $st->fetchColumn();
      return $v ? (string)$v : null;
    } catch (Throwable $e) { return null; }
  }

  function oa_details_url(string $token): string {
    return (defined('BASE_URL') ? BASE_URL : '') . '/single_order_details.php?t=' . $token;
  }

  /** @return string|null স্থানীয় ফরম্যাট 01XXXXXXXXX অথবা null */
  function oa_norm_mobile($raw): ?string {
    if (!is_scalar($raw)) return null;
    $d = preg_replace('/\D+/', '', (string)$raw);
    if ($d === '' || strlen($d) > 15) return null;
    if (strpos($d, '8801') === 0 && strlen($d) === 13) $d = '0' . substr($d, 3);
    elseif (strlen($d) === 10 && $d[0] === '1') $d = '0' . $d;
    return preg_match('/^01[3-9]\d{8}$/', $d) ? $d : null;
  }

  /** "ORD0000034", "ord0000034", "#ORD-0000034", "34", "ORD34" → ORD0000034; নইলে null */
  function oa_norm_code($raw): ?string {
    if (!is_scalar($raw)) return null;
    $c = strtoupper(preg_replace('/[\s#\-_]+/', '', (string)$raw));
    if ($c === '') return null;
    if (preg_match('/^(?:ORD)?(\d{1,7})$/', $c, $m)) return 'ORD' . str_pad($m[1], 7, '0', STR_PAD_LEFT);
    return null;
  }

  /** মোবাইল + অর্ডার কোড দুটোই মিললে অর্ডার, নইলে null (কোন অংশ ভুল তা বলে না) */
  function oa_find(PDO $pdo, $mobileRaw, $codeRaw): ?array {
    $m = oa_norm_mobile($mobileRaw); $c = oa_norm_code($codeRaw);
    if ($m === null || $c === null) return null;
    try {
      $st = $pdo->prepare("SELECT * FROM orders WHERE order_code=? AND mobile IN (?,?,?) LIMIT 1");
      $st->execute([$c, $m, '+88' . $m, '88' . $m]);
      return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) { return null; }
  }

  /* ---------- ট্র্যাকিংয়ে ভুল চেষ্টার সীমা (আইপি-ভিত্তিক) ---------- */

  /** @return array{blocked:bool,wait:int,fails:int} */
  function oa_limit_check(PDO $pdo, string $ip, ?int $now = null, int $max = OA_TRACK_MAX_FAIL, int $minutes = OA_TRACK_WINDOW_MIN): array {
    $now = $now ?? time();
    try {
      $st = $pdo->prepare("SELECT COUNT(*), MIN(created_at) FROM tracking_attempts WHERE ip=? AND created_at > ?");
      $st->execute([$ip, date('Y-m-d H:i:s', $now - $minutes * 60)]);
      [$n, $first] = $st->fetch(PDO::FETCH_NUM);
      $n = (int)$n;
      if ($n >= $max && $first) {
        return ['blocked' => true, 'wait' => max(1, strtotime($first) + $minutes * 60 - $now), 'fails' => $n];
      }
      return ['blocked' => false, 'wait' => 0, 'fails' => $n];
    } catch (Throwable $e) { return ['blocked' => false, 'wait' => 0, 'fails' => 0]; }
  }

  function oa_limit_fail(PDO $pdo, string $ip, ?int $now = null): void {
    try {
      $pdo->prepare("INSERT INTO tracking_attempts(ip, created_at) VALUES(?,?)")->execute([$ip, date('Y-m-d H:i:s', $now ?? time())]);
      if (mt_rand(1, 50) === 1) $pdo->prepare("DELETE FROM tracking_attempts WHERE created_at < ?")->execute([date('Y-m-d H:i:s', time() - 86400)]);
    } catch (Throwable $e) {}
  }

  /** লগইন করা ক্রেতা কি এই অর্ডারের মালিক */
  function oa_owner_can_view(array $order, ?array $session = null): bool {
    $s = $session ?? $_SESSION;
    $cid = (int)($s['customer_id'] ?? 0);
    return $cid > 0 && (int)($order['customer_id'] ?? 0) === $cid;
  }

  /**
   * অর্ডার পেজের জন্য অর্ডার খোঁজা: গোপন কোড (t) সবার জন্য; আইডি (id) শুধু লগইন করা মালিকের জন্য।
   */
  function oa_load_for_view(PDO $pdo, array $get, ?array $session = null): ?array {
    if (isset($get['t'])) return oa_by_token($pdo, $get['t']);
    if (isset($get['id']) && is_scalar($get['id']) && ctype_digit((string)$get['id'])) {
      try {
        $st = $pdo->prepare("SELECT * FROM orders WHERE id=? LIMIT 1");
        $st->execute([(int)$get['id']]);
        $o = $st->fetch(PDO::FETCH_ASSOC);
        return ($o && oa_owner_can_view($o, $session)) ? $o : null;
      } catch (Throwable $e) { return null; }
    }
    return null;
  }

  /** অর্ডারের বিস্তারিত: লগইন করা ক্রেতা শুধু নিজের অর্ডার (customer_id অথবা একই মোবাইল) দেখতে পারবে */
  function oa_load_for_customer(PDO $pdo, array $get, array $customer): ?array {
    $o = null;
    if (isset($get['t'])) $o = oa_by_token($pdo, $get['t']);
    elseif (isset($get['id']) && is_scalar($get['id']) && ctype_digit((string)$get['id'])) {
      try { $st = $pdo->prepare("SELECT * FROM orders WHERE id=? LIMIT 1"); $st->execute([(int)$get['id']]); $o = $st->fetch(PDO::FETCH_ASSOC) ?: null; }
      catch (Throwable $e) { $o = null; }
    }
    if (!$o) return null;
    $cid = (int)($customer['id'] ?? 0);
    if ($cid > 0 && (int)($o['customer_id'] ?? 0) === $cid) return $o;
    $cm = oa_norm_mobile($customer['mobile'] ?? ''); $om = oa_norm_mobile($o['mobile'] ?? '');
    return ($cm !== null && $cm === $om) ? $o : null;
  }
}
