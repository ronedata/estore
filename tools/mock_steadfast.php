<?php
// tools/mock_steadfast.php — Steadfast-এর নকল সার্ভার (শুধু টেস্টের জন্য; আসল API নয়)
//   php -S 127.0.0.1:8131 tools/mock_steadfast.php
// ঠিক কী: Api-Key=test-key, Secret-Key=test-secret
// বিশেষ ইনভয়েস (ব্যর্থতা নকল করতে): ...FAILX → HTTP 422; ...SLOWX → ৪ সেকেন্ড দেরি; ...HTMLX → HTML উত্তর; ...NOCID → কনসাইনমেন্ট আইডি ছাড়া;
//   ...ST400 → HTTP 200 কিন্তু JSON status=400 (যেমন ব্যালেন্স কম)
// নিয়ন্ত্রণ: POST /__set {cid,status} | POST /__reset | GET /__log (পাঠানো অনুরোধের তালিকা)

$file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mock_sf_state.json';
$state = is_file($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
$state += ['next' => 5000, 'cons' => [], 'requests' => []];
$save = function () use (&$state, $file) { file_put_contents($file, json_encode($state)); };
$out = function (int $code, $body, bool $json = true) {
  http_response_code($code);
  if ($json) { header('Content-Type: application/json'); echo json_encode($body, JSON_UNESCAPED_UNICODE); } else { header('Content-Type: text/html'); echo $body; }
  exit;
};

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = preg_replace('~^/api/v1~', '', $path);
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
$hdr = function (string $n) { $k = 'HTTP_' . strtoupper(str_replace('-', '_', $n)); return $_SERVER[$k] ?? ''; };

if ($path === '/__reset') { $state = ['next' => 5000, 'cons' => [], 'requests' => []]; $save(); $out(200, ['ok' => true]); }
if ($path === '/__log') $out(200, ['requests' => $state['requests'], 'cons' => $state['cons']]);
if ($path === '/__set' && $method === 'POST') {
  $cid = (string)($body['cid'] ?? '');
  if (!isset($state['cons'][$cid])) $out(404, ['ok' => false]);
  $state['cons'][$cid]['status'] = (string)($body['status'] ?? 'pending'); $save(); $out(200, ['ok' => true]);
}

$authOk = $hdr('Api-Key') === 'test-key' && $hdr('Secret-Key') === 'test-secret';
$state['requests'][] = ['method' => $method, 'path' => $path, 'body' => $body, 'auth' => $authOk, 'accept' => $hdr('Accept'), 'ctype' => $hdr('Content-Type')];
$state['requests'] = array_slice($state['requests'], -200);
$save();
if (!$authOk) $out(401, ['status' => 401, 'message' => 'Unauthorized']);

if ($path === '/get_balance' && $method === 'GET') $out(200, ['status' => 200, 'current_balance' => 1234.5]);

if ($path === '/create_order' && $method === 'POST') {
  if (!is_array($body)) $out(400, ['status' => 400, 'message' => 'Invalid JSON']);
  $inv = (string)($body['invoice'] ?? '');
  if (substr($inv, -5) === 'SLOWX') sleep(4);
  if (substr($inv, -5) === 'HTMLX') $out(200, '<html><body>Maintenance</body></html>', false);
  if (substr($inv, -5) === 'FAILX') $out(422, ['status' => 422, 'message' => 'The recipient phone must be 11 digits.', 'errors' => ['recipient_phone' => ['bad']]]);
  if (substr($inv, -5) === 'ST400') $out(200, ['status' => 400, 'message' => 'Insufficient balance']);
  foreach (['invoice', 'recipient_name', 'recipient_phone', 'recipient_address'] as $f) if (!isset($body[$f]) || trim((string)$body[$f]) === '') $out(422, ['status' => 422, 'message' => "The $f field is required."]);
  if (!preg_match('/^01[3-9]\d{8}$/', (string)$body['recipient_phone'])) $out(422, ['status' => 422, 'message' => 'The recipient phone must be 11 digits.']);
  if (!isset($body['cod_amount']) || !is_numeric($body['cod_amount']) || $body['cod_amount'] < 0) $out(422, ['status' => 422, 'message' => 'The cod amount is invalid.']);
  foreach ($state['cons'] as $c) if ($c['invoice'] === $inv) $out(400, ['status' => 400, 'message' => 'Invoice already exists.']);
  if (substr($inv, -5) === 'NOCID') $out(200, ['status' => 200, 'message' => 'ok', 'consignment' => []]);
  $cid = ++$state['next'];
  $c = ['consignment_id' => $cid, 'invoice' => $inv, 'tracking_code' => 'TRK' . $cid, 'recipient_name' => $body['recipient_name'], 'recipient_phone' => $body['recipient_phone'],
        'recipient_address' => $body['recipient_address'], 'cod_amount' => $body['cod_amount'], 'status' => 'in_review', 'note' => $body['note'] ?? null];
  $state['cons'][(string)$cid] = $c; $save();
  $out(200, ['status' => 200, 'message' => 'Consignment has been created successfully.', 'consignment' => $c]);
}

if (preg_match('~^/status_by_cid/(\d+)$~', $path, $m) && $method === 'GET') {
  if (!isset($state['cons'][$m[1]])) $out(404, ['status' => 404, 'message' => 'Consignment not found']);
  $out(200, ['status' => 200, 'delivery_status' => $state['cons'][$m[1]]['status']]);
}

$out(404, ['status' => 404, 'message' => 'Not found']);
