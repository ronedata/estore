<?php
// admin/settings_save.php
// লক্ষ্য: কোনো HTML আউটপুট নয়। header.php ইনক্লুড করবেন না।
require_once __DIR__ . '/auth.php';       // নিশ্চিত করুন: এখানে echo/HTML নেই
require_once __DIR__.'/../includes/config.php';  // DB, helpers
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('settings');

$pdo = get_pdo();
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

/**
 * ইনপুট টেক্সট UTF-8 কিনা যাচাই; mojibake হলে ঠিক করার চেষ্টা।
 * (কিছু ক্লায়েন্ট ফর্ম বাংলা টেক্সট double-encode করে পাঠায়)
 */
function sf_clean_text(string $v): string {
  if ($v === '' || preg_match('//u', $v)) {
    /* বৈধ UTF-8 — তবে double-encoded mojibake কি না দেখি (à¦ / à§ প্রিফিক্স) */
    if (strpos($v, 'à¦') === false && strpos($v, 'à§') === false) return $v;
    $fixed = @mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1');
    if (is_string($fixed) && $fixed !== '' && preg_match('//u', $fixed)) return $fixed;
    return $v;
  }
  /* অবৈধ UTF-8: ল্যাটিন-১ হিসেবে পড়ে রিকভার করার চেষ্টা */
  $fixed = @mb_convert_encoding($v, 'UTF-8', 'ISO-8859-1');
  return (is_string($fixed) && preg_match('//u', $fixed)) ? $fixed : $v;
}

/* Local helper for upload directory (using logic from product_form.php) */
function sf_uploads_dir_abs(): string {
  if (defined('UPLOAD_DIR') && UPLOAD_DIR) { $rp = realpath(UPLOAD_DIR); return $rp!==false?$rp:UPLOAD_DIR; }
  $fallback = __DIR__.'/../uploads'; $rp = realpath($fallback); return $rp!==false?$rp:$fallback;
}

/** লোগো/ফেভিকন সেভ হবে uploads/site_logo/ এর ভিতরে */
function sf_site_logo_dir_abs(): string {
  $dir = rtrim(str_replace('\\','/',sf_uploads_dir_abs()), '/').'/site_logo';
  if (!is_dir($dir)) @mkdir($dir, 0777, true);
  return $dir;
}
function sf_path_to_url(string $fname): string {
    return (defined('UPLOAD_URL')?rtrim(UPLOAD_URL,'/'):'').'/'.$fname;
}
/** শুধু site_logo (এবং লিগ্যাসি uploads রুট) এর ফাইল ডিলিট — path traversal বন্ধ */
function sf_delete_logo_file(string $url): void {
  $url = trim($url);
  if ($url === '' || strpos($url, 'default') !== false) return;

  $root = realpath(sf_uploads_dir_abs());
  $logoDir = realpath(sf_site_logo_dir_abs());
  $base = basename($url);
  $candidates = [
    rtrim(str_replace('\\','/',sf_site_logo_dir_abs()), '/').'/'.$base,
    rtrim(str_replace('\\','/',sf_uploads_dir_abs()), '/').'/'.$base,
  ];
  foreach ($candidates as $abs) {
    $real = realpath($abs);
    if (!$real || !is_file($real)) continue;
    $okLogo = $logoDir && strpos($real, $logoDir) === 0;
    $okRoot = $root && strpos($real, $root) === 0 && dirname($real) === $root; // শুধু রুটের ফাইল
    if ($okLogo || $okRoot) { @unlink($real); return; }
  }
}

/* Guard: POST only */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method Not Allowed');
}

/* CSRF */
if (!csrf_verify()) {
  $_SESSION['flash'] = ['ok'=>false, 'message'=>'Invalid CSRF token'];
  header('Location: settings.php');  // কোনো আউটপুট ছাড়াই রিডাইরেক্ট
  exit;
}

/* Allowed keys (whitelist) */
$allowed = [
  'store_name', 'store_phone', 'store_email', 'store_about',
  'cod_note',
  'maintenance_enabled',
  'discount_show_product', // ADDED
  'store_logo',             // NEW
  'store_favicon',          // NEW

  /* --- V2: theme, hero, info bar, social --- */
  'theme_primary', 'theme_secondary',
  'hero_autoplay_ms', 'animations_enabled',
  'home_layout',
  'info1_title', 'info1_sub',
  'info2_title', 'info2_sub',
  'info3_title', 'info3_sub',
  'store_address', 'social_facebook', 'social_instagram', 'social_whatsapp',
  'sold_count_label'
];

/* Normalize values */
$data = [];
$current_settings = $pdo->query("SELECT `key`,`value` FROM settings")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

foreach ($allowed as $k) {
  if (in_array($k, ['maintenance_enabled', 'discount_show_product', 'animations_enabled'], true)) {
    $data[$k] = isset($_POST[$k]) ? '1' : '0';
  } elseif ($k === 'hero_autoplay_ms') {
    $v = (int)($_POST[$k] ?? 2500);
    $data[$k] = (string)max(1500, min(15000, $v));
  } elseif (in_array($k, ['theme_primary','theme_secondary'], true)) {
    $v = trim((string)($_POST[$k] ?? ''));
    $data[$k] = preg_match('/^#[0-9a-f]{6}$/i', $v) ? $v : ($k === 'theme_primary' ? '#6c5ce7' : '#ff7675');
  } elseif (in_array($k, ['social_facebook','social_instagram'], true)) {
    $v = trim((string)($_POST[$k] ?? ''));
    $data[$k] = ($v === '' || preg_match('~^https?://~i', $v)) ? $v : '';
  } elseif (in_array($k, ['store_logo', 'store_favicon'], true)) {
    // File upload logic for logo/favicon → uploads/site_logo/
    if (!empty($_FILES[$k]['name']) && $_FILES[$k]['error'] === UPLOAD_ERR_OK && is_uploaded_file($_FILES[$k]['tmp_name'])) {
      $tmpPath = $_FILES[$k]['tmp_name'];
      $ext     = strtolower(pathinfo($_FILES[$k]['name'], PATHINFO_EXTENSION));

      /* ---------- ১. সাইজ লিমিট (৫MB) ---------- */
      $maxBytes = 5 * 1024 * 1024;
      if ((int)($_FILES[$k]['size'] ?? 0) > $maxBytes) {
        $_SESSION['flash'] = ['ok' => false, 'msg' => 'ফাইল ৫MB এর কম হতে হবে।'];
        $data[$k] = $current_settings[$k] ?? '';
        continue;
      }

      /* ---------- ২. আসল ইমেজ কিনা যাচাই (শুধু নামের এক্সটেনশন নয়) ---------- */
      $allowed = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_WEBP => 'webp',
        IMAGETYPE_GIF  => 'gif',
      ];

      $info  = @getimagesize($tmpPath);
      $isIco = false;

      /* ICO ফাইল getimagesize() পড়তে পারে না — ম্যাজিক বাইট দেখি */
      if ($ext === 'ico') {
        $fh = @fopen($tmpPath, 'rb');
        $head = $fh ? fread($fh, 4) : '';
        if ($fh) fclose($fh);
        $isIco = ($head === "\x00\x00\x01\x00");
      }

      if (!$isIco && (!$info || !isset($allowed[$info[2]]))) {
        $_SESSION['flash'] = ['ok' => false, 'msg' => 'শুধু বৈধ JPG, PNG, WEBP, GIF বা ICO ইমেজ গ্রহণ করা হয়।'];
        $data[$k] = $current_settings[$k] ?? '';
        continue;
      }

      /* ---------- ৩. এক্সটেনশন আসল টাইপ থেকে (ইউজারের দেওয়া নাম থেকে নয়) ---------- */
      $saveExt = $isIco ? 'ico' : $allowed[$info[2]];

      $fname = $k . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.' . $saveExt;
      $dir   = sf_site_logo_dir_abs();
      $dest  = rtrim(str_replace('\\','/',$dir), '/') . '/' . $fname;

      if (@move_uploaded_file($tmpPath, $dest)) {
        @chmod($dest, 0644);
        $data[$k] = sf_path_to_url('site_logo/'.$fname);

        // পুরনো ফাইল ডিলিট (site_logo বা লিগ্যাসি রুট)
        sf_delete_logo_file((string)($current_settings[$k] ?? ''));
      } else {
        $_SESSION['flash'] = ['ok' => false, 'msg' => 'ফাইল সংরক্ষণ করা যায়নি (ফোল্ডার পারমিশন দেখুন)।'];
        $data[$k] = $current_settings[$k] ?? '';
      }
    } elseif (isset($_POST["{$k}_remove"]) && $_POST["{$k}_remove"] === '1') {
      // Logic to remove the image entirely (set to empty string)
      sf_delete_logo_file((string)($current_settings[$k] ?? ''));
      $data[$k] = '';
    } else {
      // No new upload, keep existing value
      $data[$k] = $current_settings[$k] ?? '';
    }
  } else {
    // Normal text field — ফর্মে ফিল্ডটাই না থাকলে পুরনো মান অক্ষত রাখি
    if (!array_key_exists($k, $_POST)) {
      $data[$k] = $current_settings[$k] ?? '';
    } else {
      $data[$k] = sf_clean_text((string)$_POST[$k]);
    }
  }
}

/* Save (UPSERT) */
try {
  $pdo->beginTransaction();
  $sql = "INSERT INTO settings(`key`,`value`) VALUES(?,?)
          ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)";
  $stm = $pdo->prepare($sql);
  foreach ($data as $k => $v) { $stm->execute([$k, $v]); }
  $pdo->commit();

  // optional flash — আগে থেকে কোনো এরর flash থাকলে সেটাই রাখি
  if (empty($_SESSION['flash'])) {
    $_SESSION['flash'] = ['ok'=>true, 'message'=>'সেটিংস সফলভাবে সেভ হয়েছে'];
  }
  header('Location: settings.php?saved=1');
  exit;
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $_SESSION['flash'] = ['ok'=>false, 'message'=>'সেভ ব্যর্থ হয়েছে'];
  header('Location: settings.php?error=1');
  exit;
}