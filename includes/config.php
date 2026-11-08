<?php
// config.php
// ---------- TIMEZONE (Bangladesh) ----------
date_default_timezone_set('Asia/Dhaka'); // PHP time -> Asia/Dhaka (GMT+6)

/* ==========================================================================
   এরর হ্যান্ডলিং — লোকালে স্ক্রিনে, লাইভে শুধু লগে (path/stack trace leak বন্ধ)
   ========================================================================== */
if (!defined('APP_DEBUG')) {
  $__host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
  $__host = preg_replace('~:\d+$~', '', $__host);
  $__is_local = PHP_SAPI === 'cli'
    || $__host === '' || $__host === 'localhost' || $__host === '127.0.0.1' || $__host === '[::1]'
    || substr($__host, -5) === '.test' || substr($__host, -6) === '.local' || substr($__host, -4) === '.dev'
    || (bool)preg_match('~^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)~', $__host);
  define('APP_DEBUG', $__is_local);
  unset($__host, $__is_local);
}

@ini_set('display_errors', APP_DEBUG ? '1' : '0');
@ini_set('display_startup_errors', APP_DEBUG ? '1' : '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

$__log_dir = dirname(__DIR__) . '/logs';
if (APP_DEBUG && !is_dir($__log_dir)) { @mkdir($__log_dir, 0775, true); }
if (is_dir($__log_dir) && is_writable($__log_dir)) { @ini_set('error_log', $__log_dir . '/php_error.log'); }
unset($__log_dir);

/* ফ্যাটাল এররেও ব্রাউজারে কোনো পাথ/ট্রেস যাবে না */
if (!APP_DEBUG) {
  register_shutdown_function(function () {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
    if (PHP_SAPI === 'cli') return;
    if (!headers_sent()) { http_response_code(500); header('Content-Type: text/html; charset=utf-8'); }
    echo '<!doctype html><html lang="bn"><head><meta charset="utf-8"><title>সাময়িক সমস্যা</title>'
       . '<meta name="viewport" content="width=device-width,initial-scale=1"></head>'
       . '<body style="font-family:system-ui,-apple-system,sans-serif;text-align:center;padding:64px 20px;color:#0f172a">'
       . '<h1 style="font-size:22px">সাময়িক সমস্যা</h1>'
       . '<p style="color:#475569">দুঃখিত, একটি অপ্রত্যাশিত ত্রুটি ঘটেছে। কিছুক্ষণ পর আবার চেষ্টা করুন।</p>'
       . '</body></html>';
  });
}


// ---------- DB CONFIG ----------
define('DB_HOST','localhost');
define('DB_PORT','3306');
define('DB_NAME','estore');
define('DB_USER','root');
define('DB_PASS','');

// ---------- APP CONFIG ----------
define('APP_NAME','eStore');
//define('SHOP_PHONE','');

/*
 * BASE_URL স্বয়ংক্রিয়ভাবে নির্ণয়:
 *  - লোকাল রুট (C:\laragon\www\estore)  → ''
 *  - সাবফোল্ডার (public_html/shop)      → '/shop'
 * ম্যানুয়ালি ঠিক করতে চাইলে নিচের $APP_BASE_URL এ লিখে দিন (যেমন '/shop')।
 */
$APP_BASE_URL = null;   // null = অটো-ডিটেক্ট

if ($APP_BASE_URL === null) {
  $APP_BASE_URL = '';
  $docRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath($_SERVER['DOCUMENT_ROOT']) : false;
  $appRoot = realpath(dirname(__DIR__));

  if ($docRoot && $appRoot) {
    $docRoot = rtrim(str_replace('\\', '/', $docRoot), '/');
    $appRoot = rtrim(str_replace('\\', '/', $appRoot), '/');

    if (stripos($appRoot, $docRoot) === 0) {
      $APP_BASE_URL = substr($appRoot, strlen($docRoot));
    }
  }

  /* CLI বা অস্বাভাবিক পরিবেশে ব্যাকআপ: SCRIPT_NAME থেকে */
  if ($APP_BASE_URL === '' && PHP_SAPI === 'cli') {
    $APP_BASE_URL = '';
  }
}

define('BASE_URL', rtrim($APP_BASE_URL, '/'));
unset($APP_BASE_URL);

define('UPLOAD_DIR', dirname(__DIR__) . '/uploads');
define('UPLOAD_URL', BASE_URL . '/uploads');

/* প্রোডাক্ট ইমেজ/ভিডিও আলাদা ফোল্ডারে */
define('PRODUCT_UPLOAD_DIR', UPLOAD_DIR . '/products_image');
define('PRODUCT_UPLOAD_URL', UPLOAD_URL . '/products_image');

if (!is_dir(UPLOAD_DIR)) { @mkdir(UPLOAD_DIR, 0777, true); }
if (!is_dir(PRODUCT_UPLOAD_DIR)) { @mkdir(PRODUCT_UPLOAD_DIR, 0777, true); }

// ---------- PDO ----------
function get_pdo(){
  static $pdo=null;
  if($pdo===null){
    $dsn='mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4';
    $pdo=new PDO($dsn, DB_USER, DB_PASS, [
      PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    ]);
    // MySQL session time zone = +06:00 (Asia/Dhaka)
    // নোট: কিছু সার্ভারে 'Asia/Dhaka' নাম কাজ নাও করতে পারে; তাই অফসেট ইউজ করা হলো।
    $pdo->exec("SET time_zone = '+06:00'");
  }
  return $pdo;
}

/* ==========================================================================
   সেশন সুরক্ষা — HttpOnly / Secure / SameSite + fixation প্রতিরোধ
   ========================================================================== */
if (session_status() === PHP_SESSION_NONE) {

  $__https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
          || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);

  @ini_set('session.use_strict_mode', '1');   // অচেনা সেশন আইডি গ্রহণ নয় (fixation)
  @ini_set('session.use_only_cookies', '1');  // URL-এ SID নয়
  @ini_set('session.cookie_httponly', '1');   // JS থেকে কুকি পড়া যাবে না
  @ini_set('session.cookie_samesite', 'Lax'); // ক্রস-সাইট POST-এ কুকি যাবে না

  if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params([
      'lifetime' => 0,
      'path'     => '/',
      'domain'   => '',
      'secure'   => $__https,   // HTTPS হলে true, লোকাল HTTP-তে false
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
  } else {
    session_set_cookie_params(0, '/; samesite=Lax', '', $__https, true);
  }

  session_start();

  /* সেশন আইডি নিয়মিত রিনিউ (৩০ মিনিটে একবার)
     নতুন (খালি) সেশনে regenerate করার দরকার নেই — তখন অকারণে দ্বিতীয়
     Set-Cookie হেডার যেত। শুধু পুরোনো সেশনেই rotate করি। */
  $__fresh_session = empty($_SESSION);
  if ($__fresh_session) {
    $_SESSION['__sid_rotated'] = time();
  } elseif (empty($_SESSION['__sid_rotated']) || (time() - (int)$_SESSION['__sid_rotated']) > 1800) {
    if (!headers_sent()) @session_regenerate_id(true);
    $_SESSION['__sid_rotated'] = time();
  }
  unset($__fresh_session);

  unset($__https);
}

function h($s){ return htmlspecialchars(is_scalar($s) ? (string)$s : '', ENT_QUOTES, 'UTF-8'); }
function money_bd($n){ return '৳'.number_format((float)$n, 0); }
function today(){ return date('Y-m-d'); }

/* ---------- CSRF (frontend forms) ---------- */
if (!function_exists('csrf_token')) {
  function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
  }
}
if (!function_exists('csrf_field')) {
  function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="'.h(csrf_token()).'">';
  }
}
if (!function_exists('csrf_verify')) {
  function csrf_verify(): bool {
    $expected = $_SESSION['csrf'] ?? '';
    $given    = $_POST['csrf'] ?? '';
    /* csrf[]=1 পাঠালে cast-এ TypeError/warning হতো — string না হলে সরাসরি reject */
    if (!is_string($expected) || !is_string($given)) return false;
    /* দুটোরই খালি হলে hash_equals('','') === true হয়ে CSRF bypass হয়ে যেত */
    if ($expected === '' || $given === '') return false;
    return hash_equals($expected, $given);
  }
}

/* ---------- frontend flash helpers ----------
   ফরম্যাট: 'ok:বার্তা' বা 'err:বার্তা'
   ok: = ৩ অক্ষর, err: = ৪ অক্ষর — তাই substring ফিক্সড অফসেট দিয়ে করা যায় না। */
if (!function_exists('flash_is_ok')) {
  function flash_is_ok(string $flash): bool {
    return str_starts_with($flash, 'ok:');
  }
}
if (!function_exists('flash_message')) {
  function flash_message(string $flash): string {
    if (str_starts_with($flash, 'ok:'))  return substr($flash, 3);
    if (str_starts_with($flash, 'err:')) return substr($flash, 4);
    return $flash;
  }
}

/**
 * ইমেজ URL রিজলভ করে — ফাইল disk-এ না থাকলে fallback (default.jpg) দেয়।
 * DB-তে path কখনো '/uploads/x.jpg', কখনো 'uploads/x.jpg' — দুটোই হ্যান্ডেল করে।
 */
if (!function_exists('img_url')) {
  function img_url($path, ?string $fallback = null): string {
    /* $fallback === null  → default.jpg
       $fallback === ''    → ফাইল না থাকলে খালি string (logo/favicon এর জন্য) */
    $fallback = $fallback === null ? (BASE_URL.'/uploads/default.jpg') : $fallback;
    $path = trim((string)$path);
    if ($path === '') return $fallback;
    if (preg_match('~^(https?:)?//~i', $path) || stripos($path, 'data:') === 0) return $path;

    $root = dirname(__DIR__);
    $rel  = ltrim($path, '/');

    if (stripos($rel, 'uploads/') === 0) {
      $abs = $root.'/'.$rel;
    } else {
      $abs = UPLOAD_DIR.'/'.$rel;
      $rel = 'uploads/'.$rel;
    }

    if (is_file($abs)) return BASE_URL.'/'.$rel;

    /* একই নামের ফাইল uploads/ রুটে থাকলে সেটি ব্যবহার করি */
    $base = basename($rel);
    if (is_file(UPLOAD_DIR.'/'.$base)) return BASE_URL.'/uploads/'.$base;

    return $fallback;
  }
}

/** <img ... onerror> এর জন্য নিরাপদ ডিফল্ট */
function img_fallback_url(): string {
  return BASE_URL.'/uploads/default.jpg';
}

// (optional) BD time-এ current datetime চাইলে
if (!function_exists('now_sql')) {
  function now_sql(){ return date('Y-m-d H:i:s'); } // Asia/Dhaka time
}

// ---------- settings helpers ----------
function setting($key, $default=''){
  static $pdo = null; if ($pdo === null) $pdo = get_pdo();
  $stm = $pdo->prepare("SELECT value FROM settings WHERE `key`=? LIMIT 1");
  $stm->execute([$key]);
  $row = $stm->fetch();
  return $row ? $row['value'] : $default;
}

function save_setting($key, $value){
  static $pdo = null; if ($pdo === null) $pdo = get_pdo();
  $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)")
      ->execute([$key, $value]);
}
// ---------- page view helpers ----------
function increment_page_view($page){
  static $pdo = null; if ($pdo === null) $pdo = get_pdo();
  $pdo->prepare("INSERT INTO page_views(page, views) VALUES(?,1) ON DUPLICATE KEY UPDATE views=views+1")
      ->execute([$page]);
}

function get_page_views($page){
  static $pdo = null; if ($pdo === null) $pdo = get_pdo();
  $stm = $pdo->prepare("SELECT views FROM page_views WHERE page=? LIMIT 1");
  $stm->execute([$page]);
  return (int)($stm->fetchColumn() ?: 0);
}

// ---------- payment helpers ----------
function payment_methods_catalog(): array {
  static $pdo = null; if ($pdo === null) $pdo = get_pdo();
  static $catalog = null;
  if ($catalog === null) {
    try {
      $rows = $pdo->query("SELECT * FROM payment_methods ORDER BY sort_order ASC, id ASC")->fetchAll();
      $catalog = [];
      foreach ($rows as $row) {
        $catalog[$row['code']] = $row;
      }
    } catch (Throwable $e) {
      // Fallback to a minimal default if table doesn't exist yet
      return [
        'COD' => [
          'code' => 'COD',
          'label' => 'Cash on Delivery',
          'description' => 'Receive the parcel first, then pay in cash.',
          'is_enabled' => 1,
        ]
      ];
    }
  }
  return $catalog;
}

function payment_method_label(string $code): string {
  $catalog = payment_methods_catalog();
  $code = strtoupper(trim($code));
  return $catalog[$code]['label'] ?? ucfirst(strtolower(str_replace('_', ' ', $code)));
}

function enabled_payment_methods(): array {
  $catalog = payment_methods_catalog();
  $enabled = [];
  foreach ($catalog as $code => $method) {
    if (!empty($method['is_enabled'])) {
      $enabled[$code] = $method;
    }
  }
  return $enabled;
}

// ---------- মেইনটেন্যান্স মোড চেক লজিক (সংশোধিত কোড) ----------
function check_maintenance_mode(){
  // 1. অ্যাডমিন ডিরেক্টরি বাইপাস চেক (domain/admin/**)
  // যদি বর্তমানে রিকোয়েস্টটি /admin/ ডিরেক্টরির কোনো ফাইলের জন্য হয়, তাহলে বাইপাস।
  if (strpos($_SERVER['PHP_SELF'], '/admin/') !== false) {
    return;
  }
  
  // 2. অ্যাডমিন সেশন বাইপাস চেক (যদি অ্যাডমিন ডিরেক্টরির বাইরেও কাজ করে)
  // সাধারণত, অ্যাডমিন ডিরেক্টরি বাইপাস করাই যথেষ্ট। তবে অতিরিক্ত সুরক্ষার জন্য এটি রাখা যেতে পারে।
  if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['admin_id'])) {
    return; 
  }

  try {
    $is_maintenance = (int)setting('maintenance_enabled', 0);
    
    if ($is_maintenance === 1) {
      // HTTP 503 (Service Unavailable) রেসপন্স কোড সেট করা
      http_response_code(503); 
      
      // maintenance.php ফাইলটি লোড করা
      require_once __DIR__ . '/maintenance.php'; 
      
      // অন্য কোনো কোড যেন এক্সিকিউট না হয়
      exit; 
    }
  } catch (Throwable $e) {
    // ডেটাবেস কানেকশন বা অন্য কোনো সমস্যা হলে, সাইট চলতে থাকবে।
  }
}

// config.php ফাইলের শেষে এই ফাংশনটি কল করুন
check_maintenance_mode();
// ---------- মেইনটেন্যান্স মোড চেক লজিক (সংশোধিত কোড শেষ) ----------

// ---------- stock helpers (order <-> product stock sync) ----------
require_once __DIR__ . '/stock_helpers.php';

// ---------- eStore V2 schema (sliders, customers, reviews, wishlists, pages) ----------
require_once __DIR__ . '/schema_v2.php';
try { ensure_estore_schema(get_pdo()); } catch (Throwable $e) { /* DB unavailable */ }

// ---------- eStore V3 schema (slider button size/position) ----------
require_once __DIR__ . '/schema_v3.php';
try { ensure_estore_schema_v3(get_pdo()); } catch (Throwable $e) { /* DB unavailable */ }

// ---------- eStore V4 schema (products.is_new_arrival) ----------
require_once __DIR__ . '/schema_v4.php';
try { ensure_estore_schema_v4(get_pdo()); } catch (Throwable $e) { /* DB unavailable */ }

// ---------- eStore V5 schema (contact_messages) ----------
require_once __DIR__ . '/schema_v5.php';
try { ensure_estore_schema_v5(get_pdo()); } catch (Throwable $e) { /* DB unavailable */ }

// ---------- eStore V6 schema (sliders.show_text) ----------
require_once __DIR__ . '/schema_v6.php';
try { ensure_estore_schema_v6(get_pdo()); } catch (Throwable $e) { /* DB unavailable */ }

// ---------- eStore V7 schema (home_sections, home_section_products) ----------
require_once __DIR__ . '/schema_v7.php';
try { ensure_estore_schema_v7(get_pdo()); } catch (Throwable $e) { /* DB unavailable */ }

?>
