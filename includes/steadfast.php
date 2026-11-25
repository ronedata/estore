<?php
// includes/steadfast.php — কুরিয়ার (Steadfast) সংযোগ: সেটিংস, অর্ডার পাঠানো, স্ট্যাটাস সিঙ্ক, ব্যালেন্স, লগ
//
// ⚠ API-র নির্দিষ্ট তথ্য (base URL, হেডারের নাম, এন্ডপয়েন্ট, ফিল্ড, স্ট্যাটাসের নাম) সবই নিচের sf_profile()-এ — এক জায়গায়।
//   এগুলো **অফিসিয়াল ডকুমেন্ট থেকে যাচাই করা নয়** (ডকুমেন্ট পাওয়া যায়নি; প্রচলিত ধারণা অনুযায়ী বসানো)।
//   আসল কী পাওয়ার পর "সংযোগ পরীক্ষা" ও একটি ছোট অর্ডার দিয়ে মিলিয়ে নিন; অমিল থাকলে শুধু sf_profile() বদলালেই চলবে।
// ডিফল্ট: **বন্ধ** (settings.steadfast_enabled = 0) — বন্ধ বা কী না থাকলে কোড কখনো বাইরে কল করে না।
// রেসপন্স পার্সিং ঢিলে (একাধিক সম্ভাব্য পাথ), যাতে ছোট ফরম্যাট-পার্থক্যে ভাঙে না।

require_once __DIR__ . '/costs.php';
require_once __DIR__ . '/order_access.php';

if (!function_exists('sf_profile')) {

  function sf_profile(): array {
    return [
      'verified'     => false,
      'default_base' => 'https://portal.packzy.com/api/v1',
      'headers'      => ['key' => 'Api-Key', 'secret' => 'Secret-Key'],
      'create'       => ['POST', '/create_order'],
      'status'       => ['GET', '/status_by_cid/{id}'],
      'balance'      => ['GET', '/get_balance'],
      'payload'      => ['invoice' => 'invoice', 'name' => 'recipient_name', 'phone' => 'recipient_phone', 'address' => 'recipient_address', 'cod' => 'cod_amount', 'note' => 'note'],
      'response'     => [
        'consignment_id' => ['consignment.consignment_id', 'consignment_id', 'data.consignment_id'],
        'tracking_code'  => ['consignment.tracking_code', 'tracking_code', 'data.tracking_code'],
        'status_text'    => ['consignment.status', 'status_text'],
        'delivery_status' => ['delivery_status', 'data.delivery_status', 'consignment.status'],
        'balance'        => ['current_balance', 'data.current_balance', 'balance'],
        'message'        => ['message', 'error', 'errors.0'],
      ],
      'tracking_url' => 'https://steadfast.com.bd/t/{code}',
      'status_map'   => ['delivered' => 'delivered', 'cancelled' => 'cancelled'],
      'final'        => ['delivered', 'cancelled', 'partial_delivered'],
      'limits'       => ['name' => 100, 'address' => 250, 'note' => 200, 'invoice' => 40],
    ];
  }

  function sf_status_label(?string $s): string {
    $s = (string)$s;
    if ($s === '') return '—';
    $approval = false;
    if (substr($s, -17) === '_approval_pending') { $approval = true; $s = substr($s, 0, -17); }
    $map = ['pending' => 'পেন্ডিং', 'in_review' => 'রিভিউতে', 'delivered' => 'ডেলিভারড', 'partial_delivered' => 'আংশিক ডেলিভারড', 'cancelled' => 'বাতিল',
            'hold' => 'হোল্ড', 'unknown' => 'অজানা', 'sending' => 'পাঠানো হচ্ছে…'];
    return ($map[$s] ?? $s) . ($approval ? ' (অনুমোদন বাকি)' : '');
  }

  function sf_status_class(?string $s): string {
    $s = (string)$s;
    if (strpos($s, 'delivered') === 0) return 'bg-success';
    if (strpos($s, 'cancelled') === 0) return 'bg-secondary';
    if ($s === 'hold') return 'bg-warning text-dark';
    if ($s === 'sending') return 'bg-info text-dark';
    return 'bg-primary';
  }

  /** orders-এ কুরিয়ার-কলাম আছে কি (মাইগ্রেশন চলেছে কি) */
  function sf_columns_ok(PDO $pdo): bool { return cs_has_column($pdo, 'orders', 'courier_consignment_id'); }

  /** http শুধু লোকাল (টেস্ট-সার্ভারের জন্য); বাকি সব https। @return ?string স্বাভাবিক করা URL */
  function sf_validate_base_url($u): ?string {
    if (!is_string($u)) return null;
    $u = trim($u);
    if ($u === '' || strlen($u) > 200) return null;
    $p = parse_url($u);
    if (!$p || empty($p['scheme']) || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment'])) return null;
    $scheme = strtolower($p['scheme']); $host = strtolower($p['host']);
    if ($scheme === 'https') { /* ঠিক */ }
    elseif ($scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost'], true)) { /* শুধু লোকাল টেস্ট */ }
    else return null;
    if (!preg_match('/^[a-z0-9.\-]+$/', $host)) return null;
    return rtrim($u, '/');
  }

  function sf_mask($k): string {
    $k = (string)$k;
    if ($k === '') return '—';
    $n = strlen($k);
    if ($n <= 6) return str_repeat('•', $n);
    return substr($k, 0, 3) . str_repeat('•', 6) . substr($k, -2);
  }

  /** @return array{enabled:bool,base_url:string,api_key:string,secret:string,auto_status:bool} */
  function sf_settings(PDO $pdo): array {
    $get = function (string $k) use ($pdo): string {
      try { $s = $pdo->prepare("SELECT value FROM settings WHERE `key`=? LIMIT 1"); $s->execute([$k]); $v = $s->fetchColumn(); return $v === false ? '' : (string)$v; }
      catch (Throwable $e) { return ''; }
    };
    $base = sf_validate_base_url($get('steadfast_base_url')) ?? sf_profile()['default_base'];
    return ['enabled' => $get('steadfast_enabled') === '1', 'base_url' => $base, 'api_key' => $get('steadfast_api_key'),
            'secret' => $get('steadfast_secret_key'), 'auto_status' => $get('steadfast_auto_status') === '1'];
  }

  /** চালু + দুটি কী আছে + কলাম আছে — তবেই বাইরে কল চলে */
  function sf_ready(PDO $pdo): bool {
    $s = sf_settings($pdo);
    return $s['enabled'] && $s['api_key'] !== '' && $s['secret'] !== '' && sf_columns_ok($pdo);
  }

  function sf_log(PDO $pdo, ?int $orderId, string $action, bool $ok, ?int $http, string $msg, ?string $by = null): void {
    try {
      $pdo->prepare("INSERT INTO courier_logs (order_id, action, ok, http_code, message, created_by) VALUES (?,?,?,?,?,?)")
          ->execute([$orderId, $action, $ok ? 1 : 0, $http, mb_substr($msg, 0, 255), $by !== null ? mb_substr($by, 0, 60) : null]);
      if (mt_rand(1, 40) === 1) $pdo->exec("DELETE FROM courier_logs WHERE created_at < (NOW() - INTERVAL 90 DAY)");
    } catch (Throwable $e) {}
  }

  /** নেস্টেড পথ ("a.b.c") থেকে মান; না পেলে null */
  function sf_pick($data, array $paths) {
    foreach ($paths as $path) {
      $cur = $data;
      foreach (explode('.', $path) as $seg) {
        if (is_array($cur) && array_key_exists($seg, $cur)) $cur = $cur[$seg]; else { $cur = null; break; }
      }
      if ($cur !== null && $cur !== '' && !is_array($cur)) return $cur;
    }
    return null;
  }

  /** সরাসরি HTTP (cURL)। @return array{ok:bool,http:int,body:string,error:string} */
  function sf_http(string $method, string $url, array $headers, ?array $json = null, int $timeout = 15): array {
    if (!function_exists('curl_init')) return ['ok' => false, 'http' => 0, 'body' => '', 'error' => 'এই সার্ভারে cURL চালু নেই'];
    $ch = curl_init($url);
    $h = [];
    foreach ($headers as $k => $v) $h[] = $k . ': ' . $v;
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
    ]);
    if ($json !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
    $body = curl_exec($ch);
    $err = curl_error($ch); $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) return ['ok' => false, 'http' => $http, 'body' => '', 'error' => $err !== '' ? $err : 'সংযোগ ব্যর্থ'];
    return ['ok' => true, 'http' => $http, 'body' => (string)$body, 'error' => ''];
  }

  /**
   * API কল। $which: create|status|balance। @return array{ok:bool,http:int,data:?array,error:string}
   */
  function sf_call(PDO $pdo, string $which, array $params = [], ?array $json = null): array {
    $s = sf_settings($pdo);
    if (!$s['enabled'] || $s['api_key'] === '' || $s['secret'] === '') return ['ok' => false, 'http' => 0, 'data' => null, 'error' => 'কুরিয়ার চালু নেই বা API কী দেওয়া নেই'];
    $P = sf_profile();
    if (!isset($P[$which])) return ['ok' => false, 'http' => 0, 'data' => null, 'error' => 'অজানা কল'];
    [$method, $path] = $P[$which];
    foreach ($params as $k => $v) $path = str_replace('{' . $k . '}', rawurlencode((string)$v), $path);
    $url = $s['base_url'] . $path;
    $r = sf_http($method, $url, [$P['headers']['key'] => $s['api_key'], $P['headers']['secret'] => $s['secret'], 'Accept' => 'application/json', 'Content-Type' => 'application/json'], $json);
    $scrub = function (string $t) use ($s): string {
      foreach ([$s['api_key'], $s['secret']] as $secret) if ($secret !== '') $t = str_replace($secret, '[কী]', $t);
      return mb_substr(trim(strip_tags($t)), 0, 200);
    };
    if (!$r['ok']) return ['ok' => false, 'http' => $r['http'], 'data' => null, 'error' => $scrub('কুরিয়ারে সংযোগ হয়নি: ' . $r['error'])];
    $data = json_decode($r['body'], true);
    if (!is_array($data)) return ['ok' => false, 'http' => $r['http'], 'data' => null, 'error' => 'কুরিয়ারের উত্তর বোঝা যায়নি (HTTP ' . $r['http'] . ')'];
    $msg = sf_pick($data, $P['response']['message']);
    if ($r['http'] === 401 || $r['http'] === 403) return ['ok' => false, 'http' => $r['http'], 'data' => $data, 'error' => 'API কী ভুল বা অনুমতি নেই (HTTP ' . $r['http'] . ')'];
    if ($r['http'] < 200 || $r['http'] > 299) return ['ok' => false, 'http' => $r['http'], 'data' => $data, 'error' => $scrub('কুরিয়ার ত্রুটি (HTTP ' . $r['http'] . ')' . ($msg !== null ? ': ' . $msg : ''))];
    if (isset($data['status']) && is_numeric($data['status']) && (int)$data['status'] !== 200) return ['ok' => false, 'http' => $r['http'], 'data' => $data, 'error' => $scrub('কুরিয়ার ত্রুটি' . ($msg !== null ? ': ' . $msg : ''))];
    return ['ok' => true, 'http' => $r['http'], 'data' => $data, 'error' => ''];
  }

  function sf_cod_amount(array $o): float {
    return strtoupper(trim((string)($o['payment_method'] ?? ''))) === 'COD' ? round((float)($o['total'] ?? 0), 2) : 0.0;
  }

  /** @return array{0:?array,1:string[]} [পাঠানোর payload|null, ত্রুটি] */
  function sf_build_payload(array $o): array {
    $P = sf_profile(); $L = $P['limits']; $F = $P['payload']; $err = [];
    $name = trim(preg_replace('/\s+/u', ' ', (string)($o['customer_name'] ?? '')));
    if ($name === '') $err[] = 'গ্রাহকের নাম নেই';
    elseif (mb_strlen($name) > $L['name']) $err[] = 'নাম ' . $L['name'] . ' অক্ষরের বেশি';
    $phone = oa_norm_mobile($o['mobile'] ?? '');
    if ($phone === null) $err[] = 'মোবাইল নম্বর সঠিক নয় (01XXXXXXXXX)';
    $addr = trim(preg_replace('/\s+/u', ' ', (string)($o['address'] ?? '')));
    if ($addr === '') $err[] = 'ঠিকানা নেই';
    elseif (mb_strlen($addr) > $L['address']) $err[] = 'ঠিকানা ' . $L['address'] . ' অক্ষরের বেশি';
    $inv = (string)($o['order_code'] ?? '');
    if ($inv === '' || !preg_match('/^[A-Za-z0-9_\-]+$/', $inv) || strlen($inv) > $L['invoice']) $err[] = 'অর্ডার কোড (ইনভয়েস) সঠিক নয়';
    if ($err) return [null, $err];
    $note = trim(preg_replace('/\s+/u', ' ', (string)($o['note'] ?? '')));
    return [[$F['invoice'] => $inv, $F['name'] => $name, $F['phone'] => $phone, $F['address'] => $addr, $F['cod'] => sf_cod_amount($o), $F['note'] => mb_substr($note, 0, $L['note'])], []];
  }

  function sf_tracking_url(?string $code): string {
    $code = (string)$code;
    return $code === '' ? '' : str_replace('{code}', rawurlencode($code), sf_profile()['tracking_url']);
  }

  function sf_order_sent(array $o): bool { return trim((string)($o['courier_consignment_id'] ?? '')) !== ''; }

  function sf_get_order(PDO $pdo, int $id): ?array {
    $s = $pdo->prepare("SELECT * FROM orders WHERE id=?");
    $s->execute([$id]);
    return $s->fetch(PDO::FETCH_ASSOC) ?: null;
  }

  /** আমাদের স্ট্যাটাস বদল (স্টক-নিয়মসহ, অর্ডার-স্ট্যাটাস পেজের মতোই); শুধু সামনের দিকে। */
  function sf_apply_status(PDO $pdo, int $orderId, string $new): bool {
    $allowedFrom = ['shipped' => ['pending', 'processing'], 'delivered' => ['pending', 'processing', 'shipped'], 'cancelled' => ['pending', 'processing', 'shipped']];
    if (!isset($allowedFrom[$new])) return false;
    try {
      $pdo->beginTransaction();
      ensure_stock_tracking($pdo);
      $st = $pdo->prepare("SELECT * FROM orders WHERE id=? FOR UPDATE");
      $st->execute([$orderId]);
      $o = $st->fetch(PDO::FETCH_ASSOC);
      if (!$o || !in_array((string)$o['status'], $allowedFrom[$new], true)) { $pdo->rollBack(); return false; }
      sync_order_stock_status($pdo, $o, $new);
      $pdo->prepare("UPDATE orders SET status=? WHERE id=?")->execute([$new, $orderId]);
      $pdo->commit();
      return true;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      error_log('[steadfast] apply_status: ' . $e->getMessage());
      return false;
    }
  }

  /**
   * অর্ডার কুরিয়ারে পাঠানো। ডুপ্লিকেট আটকাতে আগে "দখল" (courier_status='sending') নেয়।
   * @return array{0:bool,1:string}
   */
  function sf_send_order(PDO $pdo, int $orderId, ?string $by = null): array {
    if (!sf_ready($pdo)) return [false, 'কুরিয়ার চালু নেই বা API কী দেওয়া নেই'];
    $o = sf_get_order($pdo, $orderId);
    if (!$o) return [false, 'অর্ডার পাওয়া যায়নি'];
    if (sf_order_sent($o)) return [false, 'এই অর্ডার আগেই কুরিয়ারে পাঠানো হয়েছে'];
    if (in_array((string)$o['status'], ['cancelled', 'delivered'], true)) return [false, ($o['status'] === 'cancelled' ? 'বাতিল' : 'ডেলিভারড') . ' অর্ডার কুরিয়ারে পাঠানো যায় না'];
    [$payload, $errs] = sf_build_payload($o);
    if ($payload === null) return [false, 'পাঠানো যাচ্ছে না — ' . implode('; ', $errs)];

    $claim = $pdo->prepare("UPDATE orders SET courier_status='sending', courier_synced_at=NOW()
      WHERE id=? AND (courier_consignment_id IS NULL OR courier_consignment_id='')
        AND (courier_status IS NULL OR courier_status<>'sending' OR courier_synced_at < (NOW() - INTERVAL 5 MINUTE))");
    $claim->execute([$orderId]);
    if ($claim->rowCount() < 1) return [false, 'এই অর্ডার এখন অন্য কেউ পাঠাচ্ছে বা পাঠানো হয়ে গেছে'];

    $r = sf_call($pdo, 'create', [], $payload);
    $P = sf_profile();
    $cid = $r['ok'] ? sf_pick($r['data'], $P['response']['consignment_id']) : null;
    if (!$r['ok'] || $cid === null) {
      $pdo->prepare("UPDATE orders SET courier_status=NULL WHERE id=? AND courier_status='sending' AND (courier_consignment_id IS NULL OR courier_consignment_id='')")->execute([$orderId]);
      $msg = $r['ok'] ? 'কুরিয়ারের উত্তরে কনসাইনমেন্ট আইডি পাওয়া যায়নি' : $r['error'];
      sf_log($pdo, $orderId, 'send', false, $r['http'], $msg, $by);
      return [false, $msg];
    }
    $track = sf_pick($r['data'], $P['response']['tracking_code']);
    $stat = sf_pick($r['data'], $P['response']['status_text']);
    try {
      $pdo->prepare("UPDATE orders SET courier_consignment_id=?, courier_tracking_code=?, courier_status=?, courier_sent_at=NOW(), courier_synced_at=NOW() WHERE id=?")
          ->execute([mb_substr((string)$cid, 0, 40), $track !== null ? mb_substr((string)$track, 0, 60) : null, $stat !== null ? mb_substr((string)$stat, 0, 40) : 'in_review', $orderId]);
    } catch (Throwable $e) {
      sf_log($pdo, $orderId, 'send', false, $r['http'], 'কুরিয়ারে তৈরি হয়েছে (কনসাইনমেন্ট ' . $cid . ') কিন্তু আমাদের ডাটাবেসে লেখা যায়নি — হাতে মিলিয়ে নিন', $by);
      return [false, 'কুরিয়ারে তৈরি হয়েছে (কনসাইনমেন্ট ' . $cid . ') কিন্তু সংরক্ষণ করা যায়নি — লগ দেখুন'];
    }
    sf_log($pdo, $orderId, 'send', true, $r['http'], 'পাঠানো হয়েছে — কনসাইনমেন্ট ' . $cid . ($track !== null ? ', ট্র্যাকিং ' . $track : ''), $by);
    if (sf_settings($pdo)['auto_status']) sf_apply_status($pdo, $orderId, 'shipped');
    return [true, 'কুরিয়ারে পাঠানো হয়েছে (কনসাইনমেন্ট ' . $cid . ($track !== null ? ', ট্র্যাকিং ' . $track : '') . ')'];
  }

  /** একটি অর্ডারের স্ট্যাটাস কুরিয়ার থেকে এনে সংরক্ষণ। @return array{0:bool,1:string,2:?string} */
  function sf_sync_order(PDO $pdo, int $orderId, ?string $by = null): array {
    if (!sf_ready($pdo)) return [false, 'কুরিয়ার চালু নেই বা API কী দেওয়া নেই', null];
    $o = sf_get_order($pdo, $orderId);
    if (!$o) return [false, 'অর্ডার পাওয়া যায়নি', null];
    if (!sf_order_sent($o)) return [false, 'এই অর্ডার এখনো কুরিয়ারে পাঠানো হয়নি', null];
    $r = sf_call($pdo, 'status', ['id' => $o['courier_consignment_id']]);
    $P = sf_profile();
    $ds = $r['ok'] ? sf_pick($r['data'], $P['response']['delivery_status']) : null;
    if (!$r['ok'] || $ds === null) {
      $msg = $r['ok'] ? 'কুরিয়ারের উত্তরে স্ট্যাটাস পাওয়া যায়নি' : $r['error'];
      sf_log($pdo, $orderId, 'sync', false, $r['http'], $msg, $by);
      return [false, $msg, null];
    }
    $ds = mb_substr((string)$ds, 0, 40);
    $pdo->prepare("UPDATE orders SET courier_status=?, courier_synced_at=NOW() WHERE id=?")->execute([$ds, $orderId]);
    $note = '';
    if (sf_settings($pdo)['auto_status'] && isset($P['status_map'][$ds])) {
      $changed = sf_apply_status($pdo, $orderId, $P['status_map'][$ds]);
      $note = $changed ? ' — অর্ডারের স্ট্যাটাস "' . $P['status_map'][$ds] . '" করা হয়েছে' : '';
    }
    sf_log($pdo, $orderId, 'sync', true, $r['http'], 'স্ট্যাটাস: ' . $ds . $note, $by);
    return [true, 'কুরিয়ারের স্ট্যাটাস: ' . sf_status_label($ds) . $note, $ds];
  }

  /** সব "চলমান" পাঠানো অর্ডারের স্ট্যাটাস (সর্বোচ্চ $limit টি)। @return array{ok:int,fail:int,total:int} */
  function sf_sync_all(PDO $pdo, ?string $by = null, int $limit = 30): array {
    $res = ['ok' => 0, 'fail' => 0, 'total' => 0];
    if (!sf_ready($pdo)) return $res;
    $fin = sf_profile()['final'];
    $ph = implode(',', array_fill(0, count($fin), '?'));
    $st = $pdo->prepare("SELECT id FROM orders WHERE courier_consignment_id IS NOT NULL AND courier_consignment_id<>'' AND (courier_status IS NULL OR courier_status NOT IN ($ph)) ORDER BY courier_synced_at IS NULL DESC, courier_synced_at ASC LIMIT " . max(1, min(100, $limit)));
    $st->execute($fin);
    @set_time_limit(120);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $oid) {
      $res['total']++;
      [$ok] = sf_sync_order($pdo, (int)$oid, $by);
      $ok ? $res['ok']++ : $res['fail']++;
    }
    return $res;
  }

  /** কুরিয়ারের ব্যালেন্স (সংযোগ পরীক্ষা)। @return array{ok:bool,balance:?float,error:string} */
  function sf_balance(PDO $pdo, ?string $by = null): array {
    $r = sf_call($pdo, 'balance');
    $b = $r['ok'] ? sf_pick($r['data'], sf_profile()['response']['balance']) : null;
    if (!$r['ok'] || $b === null || !is_numeric($b)) {
      $msg = $r['ok'] ? 'কুরিয়ারের উত্তরে ব্যালেন্স পাওয়া যায়নি' : $r['error'];
      sf_log($pdo, null, 'test', false, $r['http'], $msg, $by);
      return ['ok' => false, 'balance' => null, 'error' => $msg];
    }
    sf_log($pdo, null, 'test', true, $r['http'], 'সংযোগ ঠিক — ব্যালেন্স ' . $b, $by);
    return ['ok' => true, 'balance' => (float)$b, 'error' => ''];
  }

  /**
   * সেটিংস সংরক্ষণ। কী ফাঁকা রাখলে আগেরটা থাকে; "clear_keys" দিলে মোছে।
   * @return array{0:bool,1:string[]}
   */
  function sf_save_settings(PDO $pdo, array $in): array {
    $err = [];
    $cur = sf_settings($pdo);
    $enabled = isset($in['enabled']) && $in['enabled'] === '1';
    $auto = isset($in['auto_status']) && $in['auto_status'] === '1';
    $rawBase = is_string($in['base_url'] ?? '') ? trim((string)($in['base_url'] ?? '')) : null;
    $base = $rawBase === null ? null : ($rawBase === '' ? sf_profile()['default_base'] : sf_validate_base_url($rawBase));
    if ($base === null) $err[] = 'Base URL সঠিক নয় (https:// দিয়ে শুরু করুন)।';
    $keyOk = fn($v) => is_string($v) && preg_match('/^[A-Za-z0-9_\-:.]{6,128}$/', $v);
    $api = $cur['api_key']; $sec = $cur['secret'];
    if (isset($in['clear_keys']) && $in['clear_keys'] === '1') { $api = ''; $sec = ''; }
    $newApi = $in['api_key'] ?? ''; $newSec = $in['secret_key'] ?? '';
    if (!is_scalar($newApi) || !is_scalar($newSec)) $err[] = 'কী সঠিক নয়।';
    else {
      $newApi = trim((string)$newApi); $newSec = trim((string)$newSec);
      if ($newApi !== '') { if ($keyOk($newApi)) $api = $newApi; else $err[] = 'API Key সঠিক নয় (অক্ষর, সংখ্যা, - _ . : ; ৬–১২৮ অক্ষর)।'; }
      if ($newSec !== '') { if ($keyOk($newSec)) $sec = $newSec; else $err[] = 'Secret Key সঠিক নয় (অক্ষর, সংখ্যা, - _ . : ; ৬–১২৮ অক্ষর)।'; }
    }
    if ($enabled && ($api === '' || $sec === '') && !$err) $err[] = 'চালু করার আগে API Key ও Secret Key দুটোই দিন।';
    if ($enabled && !sf_columns_ok($pdo) && !$err) $err[] = 'ডাটাবেস মাইগ্রেশন চলেনি — হোমপেজ একবার খুলে আবার চেষ্টা করুন।';
    if ($err) return [false, $err];
    $put = $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)");
    $put->execute(['steadfast_enabled', $enabled ? '1' : '0']);
    $put->execute(['steadfast_auto_status', $auto ? '1' : '0']);
    $put->execute(['steadfast_base_url', $base]);
    $put->execute(['steadfast_api_key', $api]);
    $put->execute(['steadfast_secret_key', $sec]);
    return [true, []];
  }

  /** order_edit-এ পাঠানো অর্ডারের কোন ক্ষেত্র বদলানো যাবে না — তুলনা করে বদলের তালিকা */
  function sf_locked_changes(PDO $pdo, array $order, array $post): array {
    $changes = [];
    $norm = fn($v) => trim(preg_replace('/\s+/u', ' ', (string)$v));
    foreach (['customer_name' => 'নাম', 'mobile' => 'মোবাইল', 'address' => 'ঠিকানা'] as $k => $label) {
      if (array_key_exists($k, $post) && is_scalar($post[$k]) && $norm($post[$k]) !== $norm($order[$k] ?? '')) $changes[] = $label;
    }
    if (isset($post['shipping_fee']) && is_scalar($post['shipping_fee']) && abs((float)$post['shipping_fee'] - (float)$order['shipping_fee']) > 0.004) $changes[] = 'ডেলিভারি চার্জ';
    $dv = $post['discount_value'] ?? '';
    if (is_scalar($dv) && trim((string)$dv) !== '') {
      $postedAmt = (($post['discount_type'] ?? '') === 'percent') ? round((float)$order['subtotal'] * (float)$dv / 100, 2) : round((float)$dv, 2);
      if (abs($postedAmt - (float)$order['discount']) > 0.004) $changes[] = 'ডিসকাউন্ট';
    } elseif ((float)$order['discount'] > 0 && array_key_exists('discount_value', $post)) $changes[] = 'ডিসকাউন্ট';
    $it = $pdo->prepare("SELECT id, qty FROM order_items WHERE order_id=?");
    $it->execute([(int)$order['id']]);
    $cur = []; foreach ($it->fetchAll(PDO::FETCH_ASSOC) as $r) $cur[(int)$r['id']] = (int)$r['qty'];
    $q = $post['qty'] ?? [];
    if (is_array($q)) foreach ($q as $iid => $v) if (isset($cur[(int)$iid]) && is_scalar($v) && max(1, (int)$v) !== $cur[(int)$iid]) { $changes[] = 'পণ্যের পরিমাণ'; break; }
    if (!empty($post['remove_items']) && is_array($post['remove_items'])) $changes[] = 'পণ্য সরানো';
    if (!empty($post['new_qty']) && is_array($post['new_qty'])) $changes[] = 'নতুন পণ্য যোগ';
    return array_values(array_unique($changes));
  }
}
