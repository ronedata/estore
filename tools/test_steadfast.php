<?php
// tools/test_steadfast.php — Steadfast কুরিয়ার লজিক (includes/steadfast.php) — নকল সার্ভার (tools/mock_steadfast.php) দিয়ে
// php tools/test_steadfast.php   (steadfast_* সেটিংস শুরুতে snapshot করে শেষে ফেরায়; "[T]" অর্ডার/পণ্য নিজে মোছে; আসল Steadfast-এ কিছু যায় না)
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/steadfast.php';

$pdo = get_pdo();
$pass = 0; $fail = 0;
function t(string $name, bool $cond): void {
  global $pass, $fail;
  if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $fail++; echo "  FAIL  $name\n"; }
}
function near($a, $b): bool { return abs((float)$a - (float)$b) < 0.005; }

/* ---------- সেটিংস snapshot ---------- */
$KEYS = ['steadfast_enabled', 'steadfast_auto_status', 'steadfast_base_url', 'steadfast_api_key', 'steadfast_secret_key'];
$snap = [];
foreach ($KEYS as $k) { $s = $pdo->prepare("SELECT value FROM settings WHERE `key`=?"); $s->execute([$k]); $v = $s->fetchColumn(); $snap[$k] = $v === false ? null : $v; }
$setS = function (array $kv) use ($pdo) { foreach ($kv as $k => $v) $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)")->execute([$k, $v]); };
$restore = function () use ($pdo, $snap) { foreach ($snap as $k => $v) { if ($v === null) $pdo->prepare("DELETE FROM settings WHERE `key`=?")->execute([$k]); else $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)")->execute([$k, $v]); } };
$logMax = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM courier_logs")->fetchColumn();
$clean = function () use ($pdo, $logMax) {
  $pdo->exec("DELETE FROM courier_logs WHERE id > $logMax");
  $pdo->exec("DELETE FROM courier_logs WHERE order_id IN (SELECT id FROM orders WHERE (customer_name LIKE '[T]%' OR order_code LIKE 'ORD99%'))");
  $pdo->exec("DELETE FROM order_items WHERE order_id IN (SELECT id FROM (SELECT id FROM orders WHERE (customer_name LIKE '[T]%' OR order_code LIKE 'ORD99%')) x)");
  $pdo->exec("DELETE FROM orders WHERE (customer_name LIKE '[T]%' OR order_code LIKE 'ORD99%')");
  $pdo->exec("DELETE FROM products WHERE name LIKE '[T]%'");
};
$clean();

/* ---------- নকল সার্ভার চালু ---------- */
$PORT = 8131; $MOCK = 'http://127.0.0.1:' . $PORT . '/api/v1';
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$PORT", __DIR__ . '/mock_steadfast.php'], [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/mock_sf_out.log', 'w'], 2 => ['file', sys_get_temp_dir() . '/mock_sf_err.log', 'w']], $pipes);
$up = false;
for ($i = 0; $i < 40; $i++) { $c = @fsockopen('127.0.0.1', $PORT, $en, $es, 0.3); if ($c) { fclose($c); $up = true; break; } usleep(250000); }
if (!$up) { echo "  FAIL  নকল সার্ভার চালু হয়নি\n"; exit(1); }
$mock = function (string $path, ?array $body = null, string $method = 'GET') use ($PORT) {
  $ch = curl_init("http://127.0.0.1:$PORT$path");
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 10, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
  if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
  $r = curl_exec($ch); curl_close($ch);
  return json_decode((string)$r, true);
};
$mock('/__reset', [], 'POST');
$reqs = fn() => $mock('/__log')['requests'];
$creates = fn() => array_values(array_filter($reqs(), fn($r) => $r['path'] === '/create_order'));
$ON = ['steadfast_enabled' => '1', 'steadfast_base_url' => $MOCK, 'steadfast_api_key' => 'test-key', 'steadfast_secret_key' => 'test-secret', 'steadfast_auto_status' => '0'];

$cat = (int)$pdo->query("SELECT id FROM categories ORDER BY id LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO products (category_id, name, price, stock, active) VALUES (?, '[T] SF Prod', 100, 10, 1)")->execute([$cat]);
$PID = (int)$pdo->lastInsertId();
$seq = 0;
$mkO = function (array $over = []) use ($pdo, $PID, &$seq): int {
  $seq++;
  $d = array_merge(['code' => 'ORD99300' . str_pad((string)$seq, 2, '0', STR_PAD_LEFT), 'name' => '[T] রহিম', 'mobile' => '01711111111', 'address' => 'বাড়ি ১২, রোড ৫, ধানমন্ডি, ঢাকা', 'pm' => 'COD', 'total' => 560, 'status' => 'pending', 'note' => 'বিকেলে দিন', 'ded' => 1], $over);
  $pdo->prepare("INSERT INTO orders (order_code, customer_name, mobile, address, shipping_area, shipping_fee, subtotal, total, payment_method, note, status, stock_deducted, stock_returned) VALUES (?,?,?,?, 'dhaka', 60, ?, ?, ?, ?, ?, ?, 0)")
      ->execute([$d['code'], $d['name'], $d['mobile'], $d['address'], $d['total'] - 60, $d['total'], $d['pm'], $d['note'], $d['status'], $d['ded']]);
  $id = (int)$pdo->lastInsertId();
  $pdo->prepare("INSERT INTO order_items (order_id, product_id, qty, price) VALUES (?,?,2,200)")->execute([$id, $PID]);
  return $id;
};
$ord = fn(int $id) => sf_get_order($pdo, $id);
$stock = fn() => (int)$pdo->query("SELECT stock FROM products WHERE id=$PID")->fetchColumn();

try {
  echo "\n=== মাইগ্রেশন v16 ===\n";
  foreach (['courier_consignment_id', 'courier_tracking_code', 'courier_status', 'courier_sent_at', 'courier_synced_at'] as $c) t("orders.$c কলাম আছে", (bool)$pdo->query("SHOW COLUMNS FROM orders LIKE '$c'")->fetch());
  t('courier_logs টেবিল ও manage_courier পারমিশন (Super Admin-এ যুক্ত)', (bool)$pdo->query("SHOW TABLES LIKE 'courier_logs'")->fetchColumn() && (bool)$pdo->query("SELECT 1 FROM role_permissions rp JOIN permissions p ON p.id=rp.permission_id WHERE p.name='manage_courier' AND rp.role_id=1")->fetchColumn());
  t('schema_v16_at আছে; আবার চালালে ত্রুটি নেই ও ডিফল্ট steadfast_enabled বদলায় না', ensure_estore_schema_v16($pdo, true) === true && (bool)$pdo->query("SELECT 1 FROM settings WHERE `key`='schema_v16_at'")->fetchColumn());
  $setS(['steadfast_enabled' => '0']); ensure_estore_schema_v16($pdo, true);
  t('মাইগ্রেশন আবার চালালে বিদ্যমান steadfast_enabled=0 থাকে (INSERT IGNORE)', $pdo->query("SELECT value FROM settings WHERE `key`='steadfast_enabled'")->fetchColumn() === '0');
  t('কুরিয়ার-কলাম ছাড়া বিদ্যমান অর্ডারে নতুন কলামগুলো NULL (ডেটা অক্ষত)', (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE courier_consignment_id IS NOT NULL AND customer_name NOT LIKE '[T]%'")->fetchColumn() === 0);

  echo "\n=== বন্ধ অবস্থায় কখনো বাইরে কল নয় ===\n";
  $setS(['steadfast_enabled' => '0', 'steadfast_base_url' => $MOCK, 'steadfast_api_key' => 'test-key', 'steadfast_secret_key' => 'test-secret']);
  $o0 = $mkO();
  t('বন্ধ (enabled=0) থাকলে sf_ready false, কী থাকলেও', sf_ready($pdo) === false);
  [$ok, $msg] = sf_send_order($pdo, $o0);
  t('বন্ধ থাকলে পাঠানো যায় না: "' . $msg . '"', $ok === false && strpos($msg, 'চালু নেই') !== false);
  t('বন্ধ থাকলে সিঙ্ক/ব্যালেন্স/sf_call-ও যায় না', sf_sync_order($pdo, $o0)[0] === false && sf_balance($pdo)['ok'] === false && sf_call($pdo, 'balance')['ok'] === false && sf_sync_all($pdo)['total'] === 0);
  t('নকল সার্ভারে একটিও অনুরোধ পৌঁছায়নি (বাইরে কল শূন্য)', count($reqs()) === 0);
  $setS(['steadfast_enabled' => '1', 'steadfast_api_key' => '', 'steadfast_secret_key' => '']);
  t('চালু কিন্তু কী নেই → sf_ready false, অনুরোধ যায় না', sf_ready($pdo) === false && sf_send_order($pdo, $o0)[0] === false && count($reqs()) === 0);
  $setS(['steadfast_api_key' => 'test-key', 'steadfast_secret_key' => '']);
  t('শুধু এক কী থাকলেও (Secret নেই) যায় না', sf_ready($pdo) === false && count($reqs()) === 0);
  $setS($ON);
  t('চালু + দুই কী + কলাম → sf_ready true', sf_ready($pdo) === true);

  echo "\n=== খুঁটিনাটি হেল্পার ===\n";
  t('sf_pick: নেস্টেড পাথ, পরের বিকল্প, ফাঁকা/অ্যারে উপেক্ষা', sf_pick(['a' => ['b' => 5]], ['a.b']) === 5 && sf_pick(['x' => 1], ['a.b', 'x']) === 1 && sf_pick(['a' => ''], ['a']) === null && sf_pick(['a' => [1]], ['a']) === null && sf_pick([], ['a']) === null);
  foreach (['https://portal.packzy.com/api/v1', 'https://portal.packzy.com/api/v1/', 'http://127.0.0.1:8131/api/v1', 'http://localhost/x'] as $u) t("বৈধ Base URL: $u", sf_validate_base_url($u) !== null);
  t('শেষের / ছাঁটা হয়', sf_validate_base_url('https://x.com/api/') === 'https://x.com/api');
  foreach (['http://evil.com/api', 'ftp://x.com', 'javascript:alert(1)', 'https://user:pw@x.com', 'https://x.com/a?b=1', 'https://x.com/#f', '', ' ', 'https://', 'https://bad host/x', 'https://x.com/' . str_repeat('a', 200), ['x'], null, 'file:///etc/passwd', 'http://169.254.169.254/'] as $u) t('অবৈধ Base URL: ' . substr(json_encode($u), 0, 48), sf_validate_base_url($u) === null);
  t('sf_mask: লুকানো (প্রথম ৩ + শেষ ২), ছোট কী পুরো ঢাকা, ফাঁকা "—"', sf_mask('abcdefghij') === 'abc••••••ij' && sf_mask('abc') === '•••' && sf_mask('') === '—' && strpos(sf_mask('SECRETKEY123456'), 'RETKEY1234') === false);
  t('sf_status_label: বাংলা লেবেল, অনুমোদন-বাকি, অজানা মান যেমন', sf_status_label('delivered') === 'ডেলিভারড' && sf_status_label('in_review') === 'রিভিউতে' && sf_status_label('delivered_approval_pending') === 'ডেলিভারড (অনুমোদন বাকি)' && sf_status_label('xyz') === 'xyz' && sf_status_label(null) === '—');
  t('sf_cod_amount: COD → মোট; অনলাইন পেমেন্ট → ০; ছোট/বড় হাতের COD', near(sf_cod_amount(['payment_method' => 'COD', 'total' => 560.5]), 560.5) && sf_cod_amount(['payment_method' => 'cod ', 'total' => 100]) == 100 && sf_cod_amount(['payment_method' => 'BKASH', 'total' => 100]) == 0 && sf_cod_amount([]) == 0);
  t('sf_tracking_url: কোড escape, ফাঁকা কোডে ফাঁকা', sf_tracking_url('TRK 1/2') === 'https://steadfast.com.bd/t/TRK%201%2F2' && sf_tracking_url('') === '' && sf_tracking_url(null) === '');

  echo "\n=== payload যাচাই ===\n";
  [$pl, $er] = sf_build_payload(['order_code' => 'ORD0000034', 'customer_name' => '  রহিম   উদ্দিন ', 'mobile' => '+8801711111111', 'address' => ' বাড়ি ১২,  ঢাকা ', 'payment_method' => 'COD', 'total' => 560, 'note' => str_repeat('ক', 300)]);
  t('ঠিক অর্ডার → payload: invoice, নাম (ফাঁকা ছাঁটা), ফোন 01711111111, ঠিকানা, cod_amount 560', $pl !== null && $pl['invoice'] === 'ORD0000034' && $pl['recipient_name'] === 'রহিম উদ্দিন' && $pl['recipient_phone'] === '01711111111' && $pl['recipient_address'] === 'বাড়ি ১২, ঢাকা' && near($pl['cod_amount'], 560));
  t('নোট ২০০ অক্ষরে ছাঁটা', mb_strlen($pl['note']) === 200);
  $base = ['order_code' => 'ORD1', 'customer_name' => 'নাম', 'mobile' => '01711111111', 'address' => 'ঠিকানা', 'payment_method' => 'COD', 'total' => 10];
  foreach ([['নাম ফাঁকা', ['customer_name' => ' ']], ['নাম ১০১ অক্ষর', ['customer_name' => str_repeat('ক', 101)]], ['মোবাইল ভুল', ['mobile' => '12345']], ['মোবাইল ফাঁকা', ['mobile' => '']], ['ঠিকানা ফাঁকা', ['address' => '']], ['ঠিকানা ২৫১ অক্ষর', ['address' => str_repeat('ক', 251)]], ['ইনভয়েসে অবৈধ অক্ষর', ['order_code' => 'ORD 1/2']], ['ইনভয়েস ফাঁকা', ['order_code' => '']], ['ইনভয়েস ৪১ অক্ষর', ['order_code' => str_repeat('A', 41)]]] as [$l, $p]) {
    [$x, $e] = sf_build_payload(array_merge($base, $p));
    t("অবৈধ ($l) → payload নেই, কারণ বলে", $x === null && count($e) >= 1);
  }
  [$x, $e] = sf_build_payload([]);
  t('খালি অর্ডার-অ্যারে → ক্র্যাশ নয়, একাধিক ত্রুটি', $x === null && count($e) >= 3);
  [$x] = sf_build_payload(array_merge($base, ['payment_method' => 'BKASH']));
  t('অনলাইন পেমেন্ট → cod_amount 0', $x !== null && $x['cod_amount'] == 0);

  echo "\n=== সেটিংস সংরক্ষণ ও যাচাই ===\n";
  $setS(['steadfast_enabled' => '0', 'steadfast_api_key' => '', 'steadfast_secret_key' => '', 'steadfast_base_url' => '', 'steadfast_auto_status' => '0']);
  [$ok, $er] = sf_save_settings($pdo, ['enabled' => '1']);
  t('কী ছাড়া চালু করা যায় না (ত্রুটি), কিছু সংরক্ষিত হয় না', $ok === false && $pdo->query("SELECT value FROM settings WHERE `key`='steadfast_enabled'")->fetchColumn() === '0');
  [$ok] = sf_save_settings($pdo, ['enabled' => '1', 'api_key' => 'only-api-key']);
  t('শুধু API Key দিলে (Secret নেই) চালু করা যায় না', $ok === false);
  [$ok] = sf_save_settings($pdo, ['api_key' => 'my-api-key-123', 'secret_key' => 'my-secret-456', 'base_url' => $MOCK]);
  t('কী সংরক্ষণ (বন্ধ অবস্থায়) সফল', $ok === true && sf_settings($pdo)['api_key'] === 'my-api-key-123' && sf_settings($pdo)['enabled'] === false);
  [$ok] = sf_save_settings($pdo, ['enabled' => '1', 'base_url' => $MOCK, 'api_key' => '', 'secret_key' => '']);
  t('কী ফাঁকা রেখে সেভ → আগের কী থাকে; চালু করা যায়', $ok === true && sf_settings($pdo)['api_key'] === 'my-api-key-123' && sf_settings($pdo)['secret'] === 'my-secret-456' && sf_settings($pdo)['enabled'] === true);
  [$ok] = sf_save_settings($pdo, ['enabled' => '1', 'clear_keys' => '1', 'base_url' => $MOCK]);
  t('"কী মুছুন" দিয়ে চালু রাখা যায় না', $ok === false && sf_settings($pdo)['api_key'] === 'my-api-key-123');
  [$ok] = sf_save_settings($pdo, ['clear_keys' => '1', 'base_url' => $MOCK]);
  t('কী মোছা (বন্ধ করে) সফল', $ok === true && sf_settings($pdo)['api_key'] === '' && sf_settings($pdo)['secret'] === '' && sf_settings($pdo)['enabled'] === false);
  foreach ([['Base URL http://evil', ['base_url' => 'http://evil.com/x']], ['API Key-এ স্পেস/<>', ['api_key' => 'bad key<>']], ['Secret ছোট', ['secret_key' => 'ab']], ['API Key অ্যারে', ['api_key' => ['x']]], ['Base URL অ্যারে', ['base_url' => ['x']]]] as [$l, $in]) {
    $before = json_encode(sf_settings($pdo));
    [$ok] = sf_save_settings($pdo, $in);
    t("অবৈধ সেটিংস ($l) → বাতিল, সব-অথবা-কিছুই-না (কিছু বদলায়নি)", $ok === false && json_encode(sf_settings($pdo)) === $before);
  }
  [$ok] = sf_save_settings($pdo, ['base_url' => '', 'auto_status' => '1']);
  t('Base URL ফাঁকা → ডিফল্ট; auto_status চালু হয়', $ok && sf_settings($pdo)['base_url'] === sf_profile()['default_base'] && sf_settings($pdo)['auto_status'] === true);
  $setS($ON);

  echo "\n=== সংযোগ পরীক্ষা (ব্যালেন্স) ===\n";
  $b = sf_balance($pdo);
  t('ঠিক কী → ব্যালেন্স 1234.5', $b['ok'] && near($b['balance'], 1234.5));
  $setS(['steadfast_api_key' => 'wrong-key']);
  $b = sf_balance($pdo);
  t('ভুল কী → ব্যর্থ, বার্তা "API কী ভুল" (HTTP 401); কীর মান বার্তায় নেই', !$b['ok'] && strpos($b['error'], 'API কী ভুল') !== false && strpos($b['error'], 'wrong-key') === false);
  $setS($ON);
  $setS(['steadfast_base_url' => 'http://127.0.0.1:9/api/v1']);
  $b = sf_balance($pdo);
  t('বন্ধ-পোর্ট (সংযোগ হয় না) → ব্যর্থ, "সংযোগ হয়নি", ক্র্যাশ নয়', !$b['ok'] && strpos($b['error'], 'সংযোগ হয়নি') !== false);
  $setS($ON);
  $r = sf_http('GET', "http://127.0.0.1:$PORT/api/v1/get_balance", ['Api-Key' => 'test-key', 'Secret-Key' => 'test-secret'], null, 5);
  t('sf_http সরাসরি: ok ও JSON', $r['ok'] && $r['http'] === 200 && strpos($r['body'], 'current_balance') !== false);

  echo "\n=== অর্ডার পাঠানো (সফল) ===\n";
  $mock('/__reset', [], 'POST');
  $o1 = $mkO(['total' => 560, 'pm' => 'COD', 'note' => 'বিকেলে দিন']);
  [$ok, $msg] = sf_send_order($pdo, $o1, 'tester');
  $r1 = $ord($o1);
  t('পাঠানো সফল: ' . $msg, $ok === true && sf_order_sent($r1));
  t('কনসাইনমেন্ট আইডি, ট্র্যাকিং কোড, স্ট্যাটাস in_review, সময় সংরক্ষিত', $r1['courier_consignment_id'] === '5001' && $r1['courier_tracking_code'] === 'TRK5001' && $r1['courier_status'] === 'in_review' && $r1['courier_sent_at'] !== null && $r1['courier_synced_at'] !== null);
  $c1 = $creates();
  t('নকল সার্ভার ঠিক ১টি create পেয়েছে', count($c1) === 1);
  $b1 = $c1[0]['body'];
  t('পাঠানো তথ্য: invoice=অর্ডার কোড, নাম, ফোন 01711111111, ঠিকানা, cod_amount 560, নোট', $b1['invoice'] === $r1['order_code'] && $b1['recipient_name'] === '[T] রহিম' && $b1['recipient_phone'] === '01711111111' && strpos($b1['recipient_address'], 'ধানমন্ডি') !== false && near($b1['cod_amount'], 560) && $b1['note'] === 'বিকেলে দিন');
  t('হেডার ঠিক: Api-Key/Secret-Key গৃহীত, Accept ও Content-Type JSON', $c1[0]['auth'] === true && $c1[0]['accept'] === 'application/json' && $c1[0]['ctype'] === 'application/json');
  $lg = $pdo->query("SELECT ok, http_code, message, created_by FROM courier_logs WHERE order_id=$o1 AND action='send'")->fetch(PDO::FETCH_ASSOC);
  t('লগে সফল সারি: 200, কনসাইনমেন্ট ও ট্র্যাকিং, যোগকারী "tester"; কী/সিক্রেট লগে নেই', $lg && (int)$lg['ok'] === 1 && (int)$lg['http_code'] === 200 && strpos($lg['message'], '5001') !== false && $lg['created_by'] === 'tester' && strpos(json_encode($lg), 'test-secret') === false && strpos(json_encode($lg), 'test-key') === false);
  t('auto_status বন্ধ → অর্ডারের স্ট্যাটাস বদলায়নি (pending)', $r1['status'] === 'pending');

  echo "\n=== ডুপ্লিকেট পাঠানো আটকানো ===\n";
  [$ok, $msg] = sf_send_order($pdo, $o1);
  t('দ্বিতীয়বার পাঠানো যায় না: "' . $msg . '"', $ok === false && strpos($msg, 'আগেই') !== false && count($creates()) === 1);
  $o2 = $mkO();
  $pdo->exec("UPDATE orders SET courier_status='sending', courier_synced_at=NOW() WHERE id=$o2");
  [$ok, $msg] = sf_send_order($pdo, $o2);
  t('"পাঠানো হচ্ছে" (দখল নেওয়া, তাজা) অর্ডার আবার পাঠানো যায় না (দুই ট্যাবে একসাথে ক্লিক)', $ok === false && strpos($msg, 'অন্য কেউ') !== false && count($creates()) === 1);
  $pdo->exec("UPDATE orders SET courier_synced_at = NOW() - INTERVAL 10 MINUTE WHERE id=$o2");
  [$ok] = sf_send_order($pdo, $o2);
  t('৫ মিনিটের পুরোনো আটকে-থাকা দখল (ক্র্যাশের পর) ছাড়ানো যায়, পাঠানো সফল', $ok === true && count($creates()) === 2);
  $claimAffected = (function () use ($pdo, $o1) { $s = $pdo->prepare("UPDATE orders SET courier_status='sending' WHERE id=? AND (courier_consignment_id IS NULL OR courier_consignment_id='')"); $s->execute([$o1]); return $s->rowCount(); })();
  t('পাঠানো অর্ডারে "দখল" শর্ত কখনো মেলে না (কনসাইনমেন্ট থাকলে)', $claimAffected === 0);

  echo "\n=== পাঠানো ব্যর্থ হলে অর্ডার অক্ষত, আবার চেষ্টা করা যায় ===\n";
  $mock('/__reset', [], 'POST');
  $failCases = [
    ['FAILX', 'HTTP 422', 'কুরিয়ার ত্রুটি (HTTP 422)'],
    ['ST400', 'JSON status=400', 'কুরিয়ার ত্রুটি'],
    ['HTMLX', 'HTML উত্তর', 'বোঝা যায়নি'],
    ['NOCID', 'আইডি ছাড়া', 'কনসাইনমেন্ট আইডি পাওয়া যায়নি'],
  ];
  foreach ($failCases as [$suffix, $label, $needle]) {
    $oid = $mkO(['code' => 'ORD9931' . $suffix]);
    [$ok, $msg] = sf_send_order($pdo, $oid, 't');
    $r = $ord($oid);
    t("$label: ব্যর্থ ('" . mb_substr($msg, 0, 40) . "…'), কনসাইনমেন্ট নেই, দখল ছাড়া (courier_status NULL), অর্ডারের স্ট্যাটাস অক্ষত", $ok === false && strpos($msg, $needle) !== false && !sf_order_sent($r) && $r['courier_status'] === null && $r['status'] === 'pending');
    $lg = $pdo->query("SELECT ok, message FROM courier_logs WHERE order_id=$oid AND action='send'")->fetch(PDO::FETCH_ASSOC);
    t("$label: লগে ব্যর্থ-সারি (ok=0) আছে", $lg && (int)$lg['ok'] === 0 && $lg['message'] !== '');
  }
  $oid = $mkO(['code' => 'ORD9932RETRY']);
  $setS(['steadfast_api_key' => 'wrong-key']);
  [$ok, $msg] = sf_send_order($pdo, $oid);
  t('ভুল কী → ব্যর্থ ("API কী ভুল"), দখল ছাড়া', !$ok && strpos($msg, 'API কী ভুল') !== false && $ord($oid)['courier_status'] === null);
  $setS($ON);
  [$ok] = sf_send_order($pdo, $oid);
  t('কী ঠিক করে আবার চেষ্টা → সফল (আগের ব্যর্থতা আটকে রাখেনি)', $ok === true && sf_order_sent($ord($oid)));
  $setS(['steadfast_base_url' => 'http://127.0.0.1:9/api/v1']);
  $oid2 = $mkO(['code' => 'ORD9933DOWN']);
  [$ok, $msg] = sf_send_order($pdo, $oid2);
  t('কুরিয়ার সার্ভার বন্ধ → "সংযোগ হয়নি", দখল ছাড়া, অর্ডার অক্ষত', !$ok && strpos($msg, 'সংযোগ হয়নি') !== false && $ord($oid2)['courier_status'] === null && $ord($oid2)['status'] === 'pending');
  $setS($ON);

  echo "\n=== পাঠানোর আগের শর্ত ===\n";
  $before = count($creates());
  foreach ([['বাতিল অর্ডার', ['status' => 'cancelled'], 'বাতিল'], ['ডেলিভারড অর্ডার', ['status' => 'delivered'], 'ডেলিভারড'], ['মোবাইল ভুল', ['mobile' => '123'], 'মোবাইল'], ['ঠিকানা ফাঁকা', ['address' => ''], 'ঠিকানা'], ['নাম ফাঁকা', ['name' => ' '], 'নাম']] as [$l, $over, $needle]) {
    $oid = $mkO(array_merge(['code' => 'ORD9934X' . mt_rand(10, 99)], $over));
    [$ok, $msg] = sf_send_order($pdo, $oid);
    t("$l → পাঠানো হয় না ('$msg'), কুরিয়ারে কিছু যায়নি", !$ok && strpos($msg, $needle) !== false && count($creates()) === $before);
  }
  t('অজানা অর্ডার আইডি → ব্যর্থ, ক্র্যাশ নয়', sf_send_order($pdo, 999999999)[0] === false && sf_sync_order($pdo, 999999999)[0] === false);
  $o3 = $mkO(['pm' => 'BKASH', 'total' => 700, 'code' => 'ORD9935ONLINE']);
  sf_send_order($pdo, $o3);
  $allC = $creates(); $lastBody = end($allC)['body'];
  t('অনলাইন-পেমেন্ট (BKASH) অর্ডারে cod_amount = 0', $lastBody['invoice'] === 'ORD9935ONLINE' && $lastBody['cod_amount'] == 0);

  echo "\n=== স্ট্যাটাস সিঙ্ক ===\n";
  $mock('/__reset', [], 'POST');
  $s1 = $mkO(['code' => 'ORD9936S1']); $s2 = $mkO(['code' => 'ORD9936S2']); $s3 = $mkO(['code' => 'ORD9936S3']); $s4 = $mkO(['code' => 'ORD9936S4']);
  foreach ([$s1, $s2, $s3, $s4] as $x) sf_send_order($pdo, $x);
  $cid = fn($oid) => $ord($oid)['courier_consignment_id'];
  [$ok, $msg, $ds] = sf_sync_order($pdo, $s1, 't');
  t('শুরুতে স্ট্যাটাস in_review ("রিভিউতে"), সিঙ্ক সফল: ' . $msg, $ok && $ds === 'in_review' && strpos($msg, 'রিভিউতে') !== false);
  $mock('/__set', ['cid' => $cid($s1), 'status' => 'delivered'], 'POST');
  [$ok, $msg, $ds] = sf_sync_order($pdo, $s1);
  t('নকল সার্ভারে delivered করার পর সিঙ্ক → courier_status=delivered, synced_at হালনাগাদ', $ok && $ds === 'delivered' && $ord($s1)['courier_status'] === 'delivered');
  t('auto_status বন্ধ থাকলে অর্ডার-স্ট্যাটাস বদলায় না (pending), মেসেজে স্ট্যাটাস-বদলের কথা নেই', $ord($s1)['status'] === 'pending' && strpos($msg, 'করা হয়েছে') === false);
  $lgs = (int)$pdo->query("SELECT COUNT(*) FROM courier_logs WHERE order_id=$s1 AND action='sync' AND ok=1")->fetchColumn();
  t('প্রতিটি সিঙ্কের লগ আছে (২টি)', $lgs === 2);
  $mock('/__set', ['cid' => $cid($s2), 'status' => 'hold'], 'POST');
  $mock('/__set', ['cid' => $cid($s3), 'status' => 'delivered_approval_pending'], 'POST');
  $mock('/__set', ['cid' => $cid($s4), 'status' => 'weird_new_status'], 'POST');
  sf_sync_order($pdo, $s2); sf_sync_order($pdo, $s3); [$okW, $mW, $dW] = sf_sync_order($pdo, $s4);
  t('hold / delivered_approval_pending / অজানা-নতুন স্ট্যাটাস — যেমন সংরক্ষিত, ক্র্যাশ নয়', $ord($s2)['courier_status'] === 'hold' && $ord($s3)['courier_status'] === 'delivered_approval_pending' && $okW && $dW === 'weird_new_status');
  $setS(['steadfast_auto_status' => '1']);
  $mock('/__set', ['cid' => $cid($s3), 'status' => 'delivered_approval_pending'], 'POST');
  sf_sync_order($pdo, $s3);
  t('approval_pending স্ট্যাটাসে অর্ডার ডেলিভারড হয় না (শুধু নিশ্চিত "delivered" হলে)', $ord($s3)['status'] === 'pending');
  $stBefore = $stock();
  [$ok, $msg] = sf_sync_order($pdo, $s1);
  t('auto_status চালু: delivered সিঙ্কে অর্ডার-স্ট্যাটাস Delivered হয়; মেসেজে জানায়', $ok && $ord($s1)['status'] === 'delivered' && strpos($msg, 'delivered') !== false);
  t('Delivered করায় স্টক বদলায়নি (ডেলিভারিতে আবার কাটে না)', $stock() === $stBefore);
  $mock('/__set', ['cid' => $cid($s2), 'status' => 'cancelled'], 'POST');
  $stBefore = $stock();
  [$ok, $msg] = sf_sync_order($pdo, $s2);
  t('auto_status চালু: cancelled সিঙ্কে অর্ডার Cancelled হয় এবং **স্টক ফিরে আসে** (+২)', $ok && $ord($s2)['status'] === 'cancelled' && $stock() === $stBefore + 2);
  $mock('/__set', ['cid' => $cid($s2), 'status' => 'cancelled'], 'POST');
  $stBefore = $stock();
  sf_sync_order($pdo, $s2);
  t('আবার সিঙ্ক করলে স্টক দ্বিতীয়বার ফেরে না', $stock() === $stBefore);
  $mock('/__set', ['cid' => $cid($s1), 'status' => 'cancelled'], 'POST');
  sf_sync_order($pdo, $s1);
  t('আগেই Delivered অর্ডার পরে "cancelled" সিঙ্কে উল্টো বদলায় না (শুধু সামনের দিকে)', $ord($s1)['status'] === 'delivered');
  $setS(['steadfast_auto_status' => '0']);
  [$ok, $msg] = sf_sync_order($pdo, $o0);
  t('পাঠানো হয়নি এমন অর্ডারের সিঙ্ক → ব্যর্থ বার্তা', !$ok && strpos($msg, 'এখনো') !== false);
  $pdo->exec("UPDATE orders SET courier_consignment_id='999999' WHERE id=$o0");
  [$ok, $msg] = sf_sync_order($pdo, $o0);
  t('কুরিয়ারে অজানা কনসাইনমেন্ট → ব্যর্থ ("HTTP 404"), অর্ডার অক্ষত', !$ok && strpos($msg, '404') !== false && $ord($o0)['status'] === 'pending');
  $pdo->exec("UPDATE orders SET courier_consignment_id=NULL WHERE id=$o0");

  echo "\n=== সব-সিঙ্ক (sf_sync_all) ===\n";
  $mock('/__reset', [], 'POST');
  $pdo->exec("DELETE FROM courier_logs WHERE order_id IN (SELECT id FROM orders WHERE (customer_name LIKE '[T]%' OR order_code LIKE 'ORD99%'))");
  $pdo->exec("DELETE FROM order_items WHERE order_id IN (SELECT id FROM (SELECT id FROM orders WHERE (customer_name LIKE '[T]%' OR order_code LIKE 'ORD99%')) x)");
  $pdo->exec("DELETE FROM orders WHERE (customer_name LIKE '[T]%' OR order_code LIKE 'ORD99%')");
  $a = $mkO(['code' => 'ORD9937A']); $b = $mkO(['code' => 'ORD9937B']); $c = $mkO(['code' => 'ORD9937C']);
  foreach ([$a, $b, $c] as $x) sf_send_order($pdo, $x);
  $mock('/__set', ['cid' => $ord($b)['courier_consignment_id'], 'status' => 'delivered'], 'POST');
  sf_sync_order($pdo, $b);
  $pdo->exec("UPDATE orders SET courier_synced_at = NOW() - INTERVAL 2 HOUR WHERE id IN ($a,$c)");
  $mock('/__set', ['cid' => $ord($a)['courier_consignment_id'], 'status' => 'hold'], 'POST');
  $n0 = count($reqs());
  $res = sf_sync_all($pdo, 'tester');
  $newReq = array_slice($reqs(), $n0);
  t('sf_sync_all: শুধু চলমান ২টি (A, C) সিঙ্ক; Delivered B বাদ', $res['total'] === 2 && $res['ok'] === 2 && $res['fail'] === 0 && count($newReq) === 2);
  t('A-র স্ট্যাটাস hold হয়েছে', $ord($a)['courier_status'] === 'hold');
  $setS(['steadfast_api_key' => 'wrong-key']);
  $res = sf_sync_all($pdo);
  t('ভুল কী → সব ব্যর্থ গণনা (fail=২), ক্র্যাশ নয়', $res['total'] === 2 && $res['fail'] === 2 && $res['ok'] === 0);
  $setS($ON);

  echo "\n=== স্বয়ংক্রিয় স্ট্যাটাস: পাঠানোর পর Shipped ===\n";
  $setS(['steadfast_auto_status' => '1']);
  $p1 = $mkO(['code' => 'ORD9938P1']); $p2 = $mkO(['code' => 'ORD9938P2', 'status' => 'processing']);
  sf_send_order($pdo, $p1); sf_send_order($pdo, $p2);
  t('auto_status চালু: পাঠানোর পর pending/processing → shipped', $ord($p1)['status'] === 'shipped' && $ord($p2)['status'] === 'shipped');
  $setS(['steadfast_auto_status' => '0']);

  echo "\n=== পাঠানো অর্ডার সম্পাদনার তালা (sf_locked_changes) ===\n";
  $oL = $mkO(['code' => 'ORD9939LOCK']);
  $row = $ord($oL);
  $itemId = (int)$pdo->query("SELECT id FROM order_items WHERE order_id=$oL")->fetchColumn();
  $same = ['customer_name' => $row['customer_name'], 'mobile' => $row['mobile'], 'address' => $row['address'], 'shipping_fee' => '60', 'qty' => [$itemId => '2'], 'note' => 'নতুন নোট', 'status' => 'processing', 'discount_value' => '', 'discount_type' => 'amount'];
  t('অপরিবর্তিত ফর্ম (শুধু নোট/স্ট্যাটাস বদল) → কোনো বদল নেই', sf_locked_changes($pdo, $row, $same) === []);
  foreach ([['নাম', ['customer_name' => 'অন্য নাম']], ['মোবাইল', ['mobile' => '01899999999']], ['ঠিকানা', ['address' => 'নতুন ঠিকানা']], ['ডেলিভারি চার্জ', ['shipping_fee' => '120']], ['পণ্যের পরিমাণ', ['qty' => [$itemId => '5']]], ['পণ্য সরানো', ['remove_items' => [$itemId]]], ['নতুন পণ্য যোগ', ['new_qty' => [$PID => '1']]], ['ডিসকাউন্ট', ['discount_value' => '50']]] as [$label, $patch]) {
    t("বদল ধরা পড়ে: $label", in_array($label, sf_locked_changes($pdo, $row, array_merge($same, $patch)), true));
  }
  t('ফাঁকা-স্পেস/লাইন-ভাঙা ছাড়া একই ঠিকানা বদল ধরা হয় না', sf_locked_changes($pdo, $row, array_merge($same, ['address' => '  ' . str_replace(' ', "  ", $row['address']) . ' '])) === []);
  t('অ্যারে/অদ্ভুত ইনপুটে ক্র্যাশ নয়', is_array(sf_locked_changes($pdo, $row, ['customer_name' => ['x'], 'qty' => 'bad', 'remove_items' => 'x', 'shipping_fee' => ['1']])));

  echo "\n=== লগ ===\n";
  $leak = (int)$pdo->query("SELECT COUNT(*) FROM courier_logs WHERE message LIKE '%test-secret%' OR message LIKE '%wrong-key%' OR message LIKE '%my-secret%'")->fetchColumn();
  t('কোনো লগ-বার্তায় API কী/সিক্রেট নেই', $leak === 0);
  t('সব লগ-বার্তা ২৫৫ অক্ষরের মধ্যে', (int)$pdo->query("SELECT COUNT(*) FROM courier_logs WHERE CHAR_LENGTH(message) > 255")->fetchColumn() === 0);
} catch (Throwable $e) {
  echo "  FAIL  অপ্রত্যাশিত ত্রুটি: " . $e->getMessage() . ' @' . $e->getLine() . "\n"; $fail++;
} finally {
  $restore();
  $clean();
  if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
  @unlink(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mock_sf_state.json');
}
$okRestore = true;
foreach ($snap as $k => $v) { $s = $pdo->prepare("SELECT value FROM settings WHERE `key`=?"); $s->execute([$k]); $cur = $s->fetchColumn(); if (($cur === false ? null : $cur) !== $v) $okRestore = false; }
t('steadfast_* সেটিংস হুবহু আগের অবস্থায় ফিরেছে', $okRestore);
t('টেস্টের [T] অর্ডার/পণ্য মুছেছে', (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE (customer_name LIKE '[T]%' OR order_code LIKE 'ORD99%')")->fetchColumn() === 0 && (int)$pdo->query("SELECT COUNT(*) FROM products WHERE name LIKE '[T]%'")->fetchColumn() === 0);
echo "\n" . ($fail === 0 ? 'ALL PASS' : "$fail FAILED") . " ($pass passed)\n";
exit($fail === 0 ? 0 : 1);
