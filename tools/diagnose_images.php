<?php
/**
 * tools/diagnose_images.php
 * লাইভ সার্ভারে ইমেজ না দেখার কারণ নির্ণয়।
 * ব্যবহার: ব্রাউজারে https://site.com/tools/diagnose_images.php
 * শেষে টুলটি ডিলিট করে দিন।
 */
require __DIR__.'/../includes/config.php';

header('Content-Type: text/html; charset=utf-8');
$pdo = get_pdo();

function chk(bool $ok): string { return $ok ? '<span style="color:#15803d;font-weight:700">OK</span>' : '<span style="color:#b91c1c;font-weight:700">FAIL</span>'; }

$rows = [];
try {
  $rows = $pdo->query("SELECT id,name,image FROM products ORDER BY id DESC LIMIT 15")->fetchAll();
} catch (Throwable $e) {}

$files = [];
foreach (['', 'products_image', 'site_logo', 'categories', 'sliders'] as $sub) {
  $dir = UPLOAD_DIR . ($sub ? '/' . $sub : '');
  $files[$sub ?: '(root)'] = [
    'path'  => $dir,
    'exists'=> is_dir($dir),
    'writable' => is_dir($dir) ? is_writable($dir) : false,
    'count' => is_dir($dir) ? count(glob($dir.'/*')) : 0,
  ];
}
?>
<!doctype html><html><head><meta charset="utf-8"><title>Image Diagnostics</title>
<style>
body{font-family:system-ui,Segoe UI,sans-serif;padding:20px;background:#f8fafc;color:#0f172a;font-size:14px}
h2{font-size:17px;margin:22px 0 8px}
table{border-collapse:collapse;width:100%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:16px}
th,td{border:1px solid #e2e8f0;padding:7px 9px;text-align:left;vertical-align:top}
th{background:#f1f5f9;font-size:12px;text-transform:uppercase;letter-spacing:.03em}
code{background:#f1f5f9;padding:1px 5px;border-radius:4px;font-size:12px;word-break:break-all}
img{width:52px;height:52px;object-fit:cover;border-radius:6px;border:1px solid #e2e8f0;background:#fff}
.small{font-size:12px;color:#64748b}
</style></head><body>

<h1 style="font-size:20px;margin:0 0 4px">Image Diagnostics</h1>
<div class="small">এই ফাইলটি কাজ শেষে মুছে ফেলুন: <code>tools/diagnose_images.php</code></div>

<h2>1. পরিবেশ</h2>
<table>
<tr><th style="width:220px">Variable</th><th>Value</th></tr>
<tr><td>BASE_URL</td><td><code><?= h(var_export(BASE_URL, true)) ?></code></td></tr>
<tr><td>DOCUMENT_ROOT</td><td><code><?= h($_SERVER['DOCUMENT_ROOT'] ?? '-') ?></code></td></tr>
<tr><td>SCRIPT_NAME</td><td><code><?= h($_SERVER['SCRIPT_NAME'] ?? '-') ?></code></td></tr>
<tr><td>App root (disk)</td><td><code><?= h(dirname(__DIR__)) ?></code></td></tr>
<tr><td>UPLOAD_DIR</td><td><code><?= h(UPLOAD_DIR) ?></code></td></tr>
<tr><td>UPLOAD_URL</td><td><code><?= h(UPLOAD_URL) ?></code></td></tr>
<tr><td>PRODUCT_UPLOAD_DIR</td><td><code><?= h(PRODUCT_UPLOAD_DIR) ?></code></td></tr>
<tr><td>PRODUCT_UPLOAD_URL</td><td><code><?= h(PRODUCT_UPLOAD_URL) ?></code></td></tr>
<tr><td>PHP version</td><td><?= h(PHP_VERSION) ?></td></tr>
<tr><td>HTTPS</td><td><?= chk(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ?></td></tr>
</table>

<h2>2. ফোল্ডার পারমিশন</h2>
<table>
<tr><th>ফোল্ডার</th><th>Path</th><th>Exists</th><th>Writable</th><th>Files</th></tr>
<?php foreach ($files as $name => $f): ?>
<tr>
  <td><strong><?= h($name) ?></strong></td>
  <td><code><?= h($f['path']) ?></code></td>
  <td><?= chk($f['exists']) ?></td>
  <td><?= chk($f['writable']) ?></td>
  <td><?= (int)$f['count'] ?></td>
</tr>
<?php endforeach; ?>
</table>

<h2>3. প্রোডাক্ট ইমেজ (DB vs Disk vs URL)</h2>
<table>
<tr><th style="width:44px">ID</th><th>Name</th><th>DB image</th><th>Resolved URL</th><th>Disk</th><th>HTTP</th><th>Preview</th></tr>
<?php foreach ($rows as $r):
  $db   = (string)$r['image'];
  $url  = img_url($db);
  $abs  = null;
  $rel  = ltrim($db, '/');
  if ($rel !== '') {
    if (stripos($rel, 'uploads/') === 0) $abs = dirname(__DIR__).'/'.$rel;
    else $abs = UPLOAD_DIR.'/'.$rel;
  }
  $diskOk = $abs ? is_file($abs) : false;
?>
<tr>
  <td><?= (int)$r['id'] ?></td>
  <td class="small"><?= h(mb_substr((string)$r['name'], 0, 42)) ?></td>
  <td><code><?= h($db !== '' ? $db : '(empty)') ?></code></td>
  <td><code><?= h($url) ?></code></td>
  <td><?= chk($diskOk) ?></td>
  <td><?= chk($url !== '' && !preg_match('~/default\.jpg$~', $url)) ?></td>
  <td><img src="<?= h($url) ?>" alt="" onerror="this.style.opacity=.25;this.title='LOAD FAIL'"></td>
</tr>
<?php endforeach; ?>
</table>

<h2>4. product_images টেবিল</h2>
<?php
$pi = [];
try { $pi = $pdo->query("SELECT product_id,image,sort_order FROM product_images ORDER BY product_id DESC, sort_order, id LIMIT 40")->fetchAll(); } catch (Throwable $e) {}
?>
<table>
<tr><th>product_id</th><th>image</th><th>sort</th><th>Disk</th><th>Preview</th></tr>
<?php foreach ($pi as $r):
  $rel = ltrim((string)$r['image'], '/');
  $abs = stripos($rel,'uploads/')===0 ? dirname(__DIR__).'/'.$rel : UPLOAD_DIR.'/'.$rel;
  $isDefault = (bool)preg_match('~/default\.jpg$~', (string)$r['image']);
?>
<tr>
  <td><?= (int)$r['product_id'] ?></td>
  <td><code><?= h($r['image']) ?></code> <?= $isDefault ? '<span class="small" style="color:#b45309">← default row</span>' : '' ?></td>
  <td><?= (int)$r['sort_order'] ?></td>
  <td><?= chk(is_file($abs)) ?></td>
  <td><img src="<?= h(img_url($r['image'])) ?>" alt=""></td>
</tr>
<?php endforeach; ?>
</table>

<h2>5. Direct URL test</h2>
<?php
$probe = [];
$any = null;
foreach ($rows as $r) { if (trim((string)$r['image']) !== '') { $any = trim((string)$r['image']); break; } }
if ($any !== null) { $probe[] = img_url($any); }
$probe[] = BASE_URL.'/uploads/default.jpg';
?>
<table>
<?php foreach (array_unique($probe) as $u):
  $full = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://')
        . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $u;
  $code = '-';
  if (function_exists('curl_init')) {
    $ch = curl_init($full);
    curl_setopt_array($ch, [CURLOPT_NOBODY=>true, CURLOPT_RETURNTRANSFER=>true, CURLOPT_TIMEOUT=>10, CURLOPT_SSL_VERIFYPEER=>false]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
  }
?>
<tr><td><code><?= h($full) ?></code></td><td>HTTP <strong><?= h((string)$code) ?></strong></td></tr>
<?php endforeach; ?>
</table>

</body></html>
