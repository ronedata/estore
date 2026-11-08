<?php
/**
 * tools/seed_products.php — ১২টি ডেমো প্রোডাক্ট যোগ করে (ইমেজসহ)
 *   php tools/seed_products.php [--dry-run]
 *
 * ছবি: picsum.photos (CC0/স্ট্রিট ফটো) → 700x700-এ center-crop/pad
 * আবার চালালে একই SKU থাকলে স্কিপ করবে (idempotent)।
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/image_processor.php';

$dryRun = in_array('--dry-run', $argv, true);
$pdo = get_pdo();

$catById = [];
foreach ($pdo->query("SELECT id, name FROM categories")->fetchAll() as $c) {
  $catById[(int)$c['id']] = $c['name'];
}
if (!$catById) {
  fwrite(STDERR, "কোনো ক্যাটাগরি নেই\n");
  exit(1);
}

/* key => [name, category_id, price, compare, stock, tags, featured, new_arrival, badge, description] */
$items = [
  ['ktshirt', 'বাচ্চাদের কটন টি-শার্ট', 1, 450, 620, 60, 'Cotton, Kids, Summer', 1, 1, '#ff5252',
   'নরম ১০০% কটন, বাচ্চাদের ত্বকের জন্য নিরাপদ। ২–১০ বছরের জন্য উপলব্ধ।'],
  ['kdenim', 'কিডস ডেনিম জ্যাকেট', 1, 1250, 1650, 35, 'Denim, Winter, Free Shipping', 1, 1, '#2196f3',
   'স্ট্রেচেবল ডেনিম, ভেতরে নরম লাইনিং। শীতের জন্য আরামদায়ক।'],
  ['kpant', 'বেবি কটন প্যান্ট', 1, 380, 0, 80, 'Cotton, Baby, Daily', 0, 1, '#4caf50',
   'ইলাস্টিক কোমর, আরামদায়ক ফিট। ১–৫ বছরের বাচ্চাদের জন্য।'],
  ['khoodie', 'বাচ্চাদের হুডি', 1, 890, 1150, 45, 'Hoodie, Winter, Kids', 1, 0, '#ff9800',
   'মোটা ফ্লিস ভেতরের হুডি, ঠান্ডায় উষ্ণ থাকার জন্য।'],
  ['rccar', 'রিমোট কন্ট্রোল কার', 2, 1490, 1990, 25, 'RC, Toy Car, Free Shipping, Hot', 1, 1, '#e91e63',
   'রিচার্জেবল ব্যাটারি, ২.৪GHz রিমোট, ২০ মিটার রেঞ্জ। ৩+ বয়সের জন্য।'],
  ['robot', 'মিনি রোবট টয়', 2, 2100, 2600, 18, 'Robot, LED, Toy', 1, 1, '#9c27b0',
   'LED চোখ, সাউন্ড ইফেক্ট, হাত-পা নড়ে। ব্যাটারি Included নয়।'],
  ['piano', 'টয় ইলেকট্রনিক পিয়ানো', 2, 950, 1250, 40, 'Music, Piano, Kids', 0, 1, '#00bcd4',
   '৩৭ কি, ডেমো মিউজিক, রেকর্ড ও প্লে। শিশুর সংগীত শেখার জন্য।'],
  ['torch', 'কিডস টর্চ লাইট', 2, 320, 0, 55, 'LED, Torch, Utility', 0, 1, '#ff5722',
   'ছোট হাতের জন্য উপযোগী, উজ্জ্বল LED, ব্যাটারি সহ।'],
  ['watch', 'কিডস স্মার্ট ওয়াচ', 3, 1800, 2250, 22, 'Watch, Digital, Free Shipping', 1, 1, '#0284c7',
   'ডিজিটাল ডিসপ্লে, জলরোধী, স্টেপ কাউন্টার ও অ্যালার্ম।'],
  ['sungan', 'বাচ্চাদের সানগ্লাস', 3, 290, 380, 70, 'Sunglasses, UV, Kids', 0, 1, '#f59e0b',
   'UV400 প্রোটেকশন, ফ্লেক্সিবল ফ্রেম — সহজে ভাঙে না।'],
  ['bag', 'মিনি স্কুল ব্যাকপ্যাক', 3, 750, 950, 50, 'Backpack, School, Kids', 1, 0, '#16a34a',
   'হালকা ওয়াটার-রেজিস্ট্যান্ট ফ্যাব্রিক, দুই পাশে বোতল পকেট।'],
  ['bottle', 'কিডস ওয়াটার বোতল', 3, 420, 0, 60, 'Bottle, BPA Free, Kids', 0, 1, '#8b5cf6',
   'BPA-মুক্ত প্লাস্টিক, লিক-প্রুফ ঢাকনা, ৫০০ মিলি।'],
];

$dir = PRODUCT_UPLOAD_DIR;
if (!is_dir($dir)) { @mkdir($dir, 0777, true); }

$added = 0; $skipped = 0; $failed = 0;

foreach ($items as $it) {
  [$key, $name, $catId, $price, $compare, $stock, $tags, $feat, $isNew, $badge, $desc] = $it;

  if (!isset($catById[$catId])) {
    echo "SKIP  $name — category #$catId নেই\n";
    $skipped++;
    continue;
  }

  $sku = 'SEED-' . strtoupper($key);
  $chk = $pdo->prepare("SELECT id, image FROM products WHERE sku=? LIMIT 1");
  $chk->execute([$sku]);
  if ($row = $chk->fetch()) {
    echo "SKIP  $name — আগেই আছে (id={$row['id']})\n";
    $skipped++;
    continue;
  }

  /* ---------- ইমেজ ---------- */
  $fname = 'seed_' . $key . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.jpg';
  $dest  = rtrim(str_replace('\\', '/', $dir), '/') . '/' . $fname;
  $tmp   = sys_get_temp_dir() . '/seed_' . $key . '.jpg';
  $imageOk = false;

  try {
    $src = 'https://picsum.photos/seed/estore-' . $key . '/700/700';
    $ctx = stream_context_create(['http' => ['timeout' => 35, 'user_agent' => 'estore-seed/1.0']]);
    $data = @file_get_contents($src, false, $ctx);
    if ($data && strlen($data) > 5000) {
      file_put_contents($tmp, $data);
      $info = @getimagesize($tmp);
      if ($info && in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
        if ($dryRun) {
          $imageOk = true;
        } else {
          $res = estore_process_image($tmp, $dest, 700, 700, '#ffffff');
          $imageOk = !empty($res['ok']);
        }
      }
      @unlink($tmp);
    }
  } catch (Throwable $e) {
    $imageOk = false;
  }

  /* ব্যর্থ হলে default.jpg ব্যবহার */
  $imagePath = $imageOk ? (PRODUCT_UPLOAD_URL . '/' . $fname) : (UPLOAD_URL . '/default.jpg');

  if ($dryRun) {
    echo "DRY   $name — cat={$catById[$catId]} price=$price stock=$stock img=" . ($imageOk ? 'downloaded' : 'fallback') . "\n";
    $added++;
    continue;
  }

  try {
    $ins = $pdo->prepare("INSERT INTO products
      (category_id, name, sku, description, price, compare_at_price, stock, tags, image,
       active, sold_count, is_featured, badge_color, is_new_arrival)
      VALUES (?,?,?,?,?,?,?,?,?,1,0,?,?,?)");
    $ins->execute([
      $catId, $name, $sku, $desc,
      $price, $compare > 0 ? $compare : null, $stock, $tags, $imagePath,
      $feat, $badge, $isNew,
    ]);
    $nid = (int)$pdo->lastInsertId();

    /* হোম সেকশন সংযোগ: featured → "Featured…" সেকশন, new → new_default সেকশন */
    try {
      $secLink = $pdo->prepare("INSERT IGNORE INTO home_section_products(section_id, product_id, sort_order) VALUES(?,?,0)");
      if ($feat) {
        $fs = (int)$pdo->query("SELECT id FROM home_sections WHERE type='manual' AND LOWER(title) LIKE 'featured%' ORDER BY sort_order, id LIMIT 1")->fetchColumn();
        if ($fs) $secLink->execute([$fs, $nid]);
      }
      if ($isNew) {
        $ns = (int)$pdo->query("SELECT id FROM home_sections WHERE type='manual' AND new_default=1 ORDER BY sort_order, id LIMIT 1")->fetchColumn();
        if ($ns) $secLink->execute([$ns, $nid]);
      }
    } catch (Throwable $e) { /* home_sections টেবিল না থাকলে বাদ */ }

    echo "ADD   #$nid $name — {$catById[$catId]} · ৳$price · stock $stock · img " . ($imageOk ? 'OK' : 'fallback') . "\n";
    $added++;
  } catch (Throwable $e) {
    echo "FAIL  $name — " . $e->getMessage() . "\n";
    if ($imageOk && is_file($dest)) @unlink($dest);
    $failed++;
  }
}

echo "\n--- যোগ হয়েছে: $added | স্কিপ: $skipped | ব্যর্থ: $failed ---\n";
echo "মোট প্রোডাক্ট: " . $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn() . "\n";
exit($failed > 0 ? 1 : 0);
