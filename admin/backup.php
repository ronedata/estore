<?php
// admin/backup.php — ডাটাবেস ও প্রোজেক্ট ব্যাকআপ ডাউনলোড (শুধু Super Admin)
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/backup.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('settings');

if (($_SESSION['admin_role_name'] ?? '') !== 'Super Admin') {
  http_response_code(403);
  require_once __DIR__.'/header.php';
  echo '<div class="alert alert-danger">এই পেজ শুধুমাত্র Super Admin-এর জন্য।</div>';
  require_once __DIR__.'/footer.php';
  exit;
}

$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
  if (!csrf_verify()) {
    $_SESSION['flash'] = ['ok'=>false, 'msg'=>'সিকিউরিটি টোকেন মিলেনি'];
    header('Location: backup.php'); exit;
  }

  if ($action === 'db_full' || $action === 'db_demo') {
    $demo = $action === 'db_demo';
    $name = 'estore_' . ($demo ? 'demo' : 'db') . '_' . date('Ymd_His') . '.sql';
    bk_mark($pdo, 'db');
    bk_start_download($name, 'application/sql; charset=utf-8');
    bk_dump_sql($pdo, $demo, function ($s) { echo $s; flush(); });
    exit;
  }

  if ($action === 'zip_code' || $action === 'zip_uploads') {
    $mode = $action === 'zip_code' ? 'code' : 'uploads';
    if (!class_exists('ZipArchive')) {
      $_SESSION['flash'] = ['ok'=>false, 'msg'=>'এই সার্ভারে ZipArchive চালু নেই।'];
      header('Location: backup.php'); exit;
    }
    $files = bk_collect($mode);
    if (!$files) {
      $_SESSION['flash'] = ['ok'=>false, 'msg'=>'জিপ করার মতো কোনো ফাইল পাওয়া যায়নি।'];
      header('Location: backup.php'); exit;
    }
    @set_time_limit(0);
    $tmp = tempnam(sys_get_temp_dir(), 'estbk');
    $zip = new ZipArchive();
    if ($tmp === false || $zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
      $_SESSION['flash'] = ['ok'=>false, 'msg'=>'অস্থায়ী জিপ ফাইল বানানো যায়নি।'];
      header('Location: backup.php'); exit;
    }
    foreach ($files as $rel => $abs) $zip->addFile($abs, $rel);
    $closed = $zip->close();
    if (!$closed || !is_file($tmp)) {
      @unlink($tmp);
      $_SESSION['flash'] = ['ok'=>false, 'msg'=>'জিপ তৈরি ব্যর্থ হয়েছে (জায়গা বা সময় কম হতে পারে)।'];
      header('Location: backup.php'); exit;
    }
    bk_mark($pdo, $mode === 'code' ? 'code' : 'uploads');
    bk_start_download('estore_' . $mode . '_' . date('Ymd_His') . '.zip', 'application/zip');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
  }

  $_SESSION['flash'] = ['ok'=>false, 'msg'=>'অজানা অ্যাকশন।'];
  header('Location: backup.php'); exit;
}

$sum  = bk_db_summary($pdo);
$code = bk_collect('code'); $upl = bk_collect('uploads');
$codeBytes = 0; foreach ($code as $a) $codeBytes += (int)@filesize($a);
$uplBytes  = 0; foreach ($upl as $a) $uplBytes += (int)@filesize($a);
$last = ['db' => bk_last($pdo, 'db'), 'code' => bk_last($pdo, 'code'), 'uploads' => bk_last($pdo, 'uploads')];
$zipOk = class_exists('ZipArchive');
$fmt = function (?string $w) { return $w ? date('d M Y, g:i A', strtotime($w)) : 'এখনো নেওয়া হয়নি'; };

require_once __DIR__.'/header.php';
?>
<div class="mb-3">
  <h4 class="mb-1">ব্যাকআপ</h4>
  <div class="text-muted small">ডাটাবেস ও প্রোজেক্ট ফাইল নিজের কম্পিউটারে ডাউনলোড করে রাখুন। ফাইল সার্ভারে জমা থাকে না — সরাসরি ডাউনলোড হয়।</div>
</div>

<?php if (!empty($_SESSION['flash'])): $f=$_SESSION['flash']; unset($_SESSION['flash']); ?>
  <div class="alert <?php echo !empty($f['ok'])?'alert-success':'alert-danger'; ?> py-2" id="bkFlash"><?php echo h($f['msg'] ?? ''); ?></div>
<?php endif; ?>

<?php if (bk_stale($last['db'])): ?>
  <div class="alert alert-warning py-2" id="bkStale"><i class="fa-solid fa-triangle-exclamation"></i> ডাটাবেসের ব্যাকআপ <?php echo $last['db'] ? '৭ দিনের বেশি আগে' : 'এখনো'; ?> নেওয়া হয়েছে<?php echo $last['db'] ? '' : ' বলে রেকর্ড নেই'; ?> — আজই একটি নিয়ে রাখুন।</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="card h-100"><div class="card-body">
      <h5 class="card-title"><i class="fa-solid fa-database"></i> ডাটাবেস ব্যাকআপ</h5>
      <div class="small text-muted mb-2">
        <span id="bkDbInfo"><?php echo (int)$sum['tables']; ?>টি টেবিল, প্রায় <?php echo number_format($sum['rows']); ?> সারি, <?php echo h(bk_human($sum['bytes'])); ?></span><br>
        শেষ ব্যাকআপ: <strong id="bkLastDb"><?php echo h($fmt($last['db'])); ?></strong>
      </div>
      <form method="post" class="mb-2">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="db_full">
        <button class="btn btn-primary w-100" id="bkDbFull"><i class="fa-solid fa-download"></i> সম্পূর্ণ ডাটাবেস (.sql)</button>
        <div class="form-text">সব ডেটা — গ্রাহক, অর্ডার, অ্যাডমিন পাসওয়ার্ড-হ্যাশসহ। <strong>নিজের কাছে নিরাপদে রাখুন, কাউকে পাঠাবেন না।</strong></div>
      </form>
      <form method="post">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="action" value="db_demo">
        <button class="btn btn-outline-secondary w-100" id="bkDbDemo"><i class="fa-solid fa-download"></i> ডেমো ডাটাবেস (.sql)</button>
        <div class="form-text">গ্রাহক, অর্ডার, মেসেজ, রিভিউ, অ্যাডমিন ইউজার, ঘোষণা ও লগ বাদ — শুধু কাঠামো। GitHub বা অন্যকে দেখানোর জন্য নিরাপদ।</div>
      </form>
    </div></div>
  </div>

  <div class="col-12 col-lg-6">
    <div class="card h-100"><div class="card-body">
      <h5 class="card-title"><i class="fa-solid fa-file-zipper"></i> প্রোজেক্ট ব্যাকআপ</h5>
      <?php if (!$zipOk): ?>
        <div class="alert alert-warning py-2 mb-0">এই সার্ভারে ZipArchive চালু নেই, তাই জিপ ব্যাকআপ করা যাবে না।</div>
      <?php else: ?>
        <form method="post" class="mb-3">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="zip_code">
          <div class="small text-muted mb-2">কোড: <span id="bkCodeInfo"><?php echo count($code); ?>টি ফাইল, <?php echo h(bk_human($codeBytes)); ?></span> · শেষ: <strong><?php echo h($fmt($last['code'])); ?></strong></div>
          <button class="btn btn-primary w-100" id="bkZipCode"><i class="fa-solid fa-download"></i> সাইটের কোড (.zip)</button>
          <div class="form-text"><code>uploads</code>, <code>logs</code>, <code>database</code>, <code>tools</code>, <code>docs</code> এবং <strong>config ফাইল (ডাটাবেস পাসওয়ার্ড)</strong> জিপে যায় না।</div>
        </form>
        <form method="post">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="action" value="zip_uploads">
          <div class="small text-muted mb-2">ছবি (uploads): <span id="bkUplInfo"><?php echo count($upl); ?>টি ফাইল, <?php echo h(bk_human($uplBytes)); ?></span> · শেষ: <strong><?php echo h($fmt($last['uploads'])); ?></strong></div>
          <button class="btn btn-outline-secondary w-100" id="bkZipUploads"><i class="fa-solid fa-download"></i> আপলোড করা ছবি (.zip)</button>
          <div class="form-text">পণ্য, স্লাইডার, ক্যাটেগরি ও লোগোর ছবি।</div>
        </form>
      <?php endif; ?>
    </div></div>
  </div>
</div>

<div class="card mt-3"><div class="card-body small text-muted">
  <strong>রিস্টোর:</strong> ডাটাবেস ফেরাতে phpMyAdmin → Import (ফাইলে <code>DROP TABLE IF EXISTS</code> আছে, তাই বর্তমান ডেটা মুছে যাবে — ইমপোর্টের আগে আরেকটি ব্যাকআপ নিন)। কোড/ছবি ফেরাতে জিপ খুলে FTP/File Manager-এ আপলোড করুন।<br>
  <strong>পরামর্শ:</strong> সপ্তাহে অন্তত একবার ডাটাবেস ব্যাকআপ নিন এবং ফাইলটি অন্তত দুই জায়গায় (কম্পিউটার + ক্লাউড) রাখুন।
</div></div>
<?php require_once __DIR__.'/footer.php'; ?>
