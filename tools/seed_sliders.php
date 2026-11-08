<?php
/**
 * tools/seed_sliders.php — নতুন হিরো স্লাইডার ও সাইড কার্ড সিড করে
 *   php tools/seed_sliders.php [--dry-run]
 *
 * - পুরোনো active স্লাইডার deactivate করে (মুছে ফেলে না — rollback সহজ)
 * - picsum.photos থেকে ছবি এনে estore_process_image() দিয়ে 1200x675 / 600x375 বানায়
 * - একই টাইটেল থাকলে স্কিপ (idempotent)
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/image_processor.php';

$dryRun = in_array('--dry-run', $argv, true);
$pdo = get_pdo();

$dir = __DIR__ . '/../uploads/sliders';
if (!is_dir($dir)) { @mkdir($dir, 0777, true); }

$items = [
  /* placement, key, title, subtitle, btn_label, btn_bg, link_type, link_value, theme, sort, w, h */
  ['hero', 'htoy',  'নতুন খেলনার সম্ভার',
   'সেরা দামে অরিজিনাল খেলনা — সারা দেশে দ্রুত ডেলিভারি',
   'কালেকশন দেখুন', '#0284c7', 'category', '2', 'purple', 1, 1200, 675],

  ['hero', 'hkids', 'বাচ্চাদের জন্য বিশেষ ছাড়',
   'নির্বাচিত পোশাক ও অ্যাক্সেসরিজে ৩০% পর্যন্ত ছাড়',
   'অফার দেখুন', '#ff7043', 'category', '1', 'orange', 2, 1200, 675],

  ['hero', 'hship', 'ফ্রি ডেলিভারি অফার',
   '৳২০০০ বা তার বেশি অর্ডারে সারা দেশে ফ্রি শিপিং',
   'শপিং শুরু করুন', '#2e7d32', 'url', '/shop.php', 'green', 3, 1200, 675],

  ['side', 'srcar', 'রিমোট কন্ট্রোল কার',
   'নতুন কালেকশন',
   'কিনুন', '#7b1fa2', 'product', '91', 'purple', 1, 600, 375],

  ['side', 'swatch', 'কিডস স্মার্ট ওয়াচ',
   'বেস্ট সেলার',
   'কিনুন', '#0288d1', 'product', '95', 'blue', 2, 600, 375],
];

$added = 0; $skipped = 0; $failed = 0;

if (!$dryRun) {
  /* পুরোনো স্লাইডার নিষ্ক্রিয় */
  $old = (int)$pdo->query("SELECT COUNT(*) FROM sliders WHERE active=1")->fetchColumn();
  if ($old > 0) {
    $pdo->exec("UPDATE sliders SET active=0 WHERE active=1");
    echo "DEACT  পুরোনো $old টি স্লাইডার নিষ্ক্রিয় করা হয়েছে (ডেটা অটুট)\n";
  }
} else {
  $old = (int)$pdo->query("SELECT COUNT(*) FROM sliders WHERE active=1")->fetchColumn();
  echo "DRY    পুরোনো $old টি স্লাইডার নিষ্ক্রিয় হবে\n";
}

foreach ($items as $it) {
  [$placement, $key, $title, $subtitle, $btn, $btnBg, $linkType, $linkValue, $theme, $sort, $tw, $th] = $it;

  if (in_array('url', [$linkType], true) === false && $linkType === 'product') {
    $chk = $pdo->prepare("SELECT 1 FROM products WHERE id=?");
    $chk->execute([(int)$linkValue]);
    if (!$chk->fetchColumn()) {
      echo "SKIP   $title — প্রোডাক্ট #$linkValue নেই\n";
      $skipped++; continue;
    }
  }

  $exists = $pdo->prepare("SELECT id FROM sliders WHERE title=? AND placement=? LIMIT 1");
  $exists->execute([$title, $placement]);
  if ($row = $exists->fetch()) {
    echo "SKIP   $title — আগেই আছে (id={$row['id']})\n";
    $skipped++; continue;
  }

  /* ---------- ছবি ---------- */
  $fname = 'slider_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.jpg';
  $dest  = rtrim(str_replace('\\', '/', $dir), '/') . '/' . $fname;
  $tmp   = sys_get_temp_dir() . '/sl_' . $key . '.jpg';
  $imgOk = false;

  try {
    $src = "https://picsum.photos/seed/estore-$key/{$tw}/{$th}";
    $ctx = stream_context_create(['http' => ['timeout' => 40, 'user_agent' => 'estore-seed/1.0']]);
    $data = @file_get_contents($src, false, $ctx);
    if ($data && strlen($data) > 5000) {
      file_put_contents($tmp, $data);
      $info = @getimagesize($tmp);
      if ($info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        if ($dryRun) { $imgOk = true; }
        else {
          $res = estore_process_image($tmp, $dest, $tw, $th, '#ffffff');
          $imgOk = !empty($res['ok']);
        }
      }
      @unlink($tmp);
    }
  } catch (Throwable $e) { $imgOk = false; }

  $imagePath = $imgOk ? ('uploads/sliders/' . $fname) : 'uploads/default.jpg';

  if ($dryRun) {
    echo "DRY    [$placement] $title — {$tw}x{$th} img=" . ($imgOk ? 'OK' : 'fallback') . "\n";
    $added++; continue;
  }

  try {
    $ins = $pdo->prepare("INSERT INTO sliders
      (placement, image, title, subtitle, btn_label, btn_bg, link_type, link_value, theme, sort_order, active, btn_size, btn_pos_x, btn_pos_y)
      VALUES (?,?,?,?,?,?,?,?,?,?,1,'md','left','bottom')");
    $ins->execute([$placement, $imagePath, $title, $subtitle, $btn, $btnBg, $linkType, $linkValue, $theme, $sort]);
    $nid = (int)$pdo->lastInsertId();
    echo "ADD    #$nid [$placement] $title — img " . ($imgOk ? 'OK' : 'fallback') . "\n";
    $added++;
  } catch (Throwable $e) {
    echo "FAIL   $title — " . $e->getMessage() . "\n";
    if ($imgOk && is_file($dest)) @unlink($dest);
    $failed++;
  }
}

echo "\n--- যোগ: $added | স্কিপ: $skipped | ব্যর্থ: $failed ---\n";
echo "active sliders: " . $pdo->query("SELECT COUNT(*) FROM sliders WHERE active=1")->fetchColumn() . "\n";
exit($failed > 0 ? 1 : 0);
