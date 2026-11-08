<?php
// admin/pages.php — edit static pages (about, privacy, terms, contact)
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_pages');

$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify()) { $_SESSION['flash']=['ok'=>false,'msg'=>'CSRF token mismatch']; header('Location: pages.php'); exit; }

  $id       = (int)($_POST['id'] ?? 0);
  $title    = trim($_POST['title'] ?? '');
  $content  = (string)($_POST['content'] ?? '');
  $meta     = trim($_POST['meta_description'] ?? '');
  $active   = !empty($_POST['active']) ? 1 : 0;

  if ($id > 0 && $title !== '') {
    $pdo->prepare("UPDATE pages SET title=?, content=?, meta_description=?, active=? WHERE id=?")
        ->execute([$title, $content, $meta, $active, $id]);
    $_SESSION['flash'] = ['ok'=>true,'msg'=>'পেজ সংরক্ষণ হয়েছে।'];
  } else {
    $_SESSION['flash'] = ['ok'=>false,'msg'=>'টাইটেল আবশ্যক।'];
  }
  header('Location: pages.php'.($id?"?edit=$id":'')); exit;
}

$rows = $pdo->query("SELECT * FROM pages ORDER BY id ASC")->fetchAll();
$editId = (int)($_GET['edit'] ?? ($rows[0]['id'] ?? 0));
$edit = null;
foreach ($rows as $r) { if ((int)$r['id'] === $editId) { $edit = $r; break; } }

/* ---------- Placeholder instructions (রেন্ডার টাইমে বসে) ---------- */
$placeholders = [
    '{STORE}'            => ['store_name',     'স্টোরের নাম'],
    '{{store_name}}'     => ['store_name',     'স্টোরের নাম (বিকল্প)'],
    '{STORE_NAME}'       => ['store_name',     'স্টোরের নাম (বিকল্প)'],
    '{PHONE}'            => ['store_phone',    'ফোন নম্বর'],
    '{{store_phone}}'    => ['store_phone',    'ফোন নম্বর (বিকল্প)'],
    '{EMAIL}'            => ['store_email',    'ইমেইল'],
    '{{store_email}}'    => ['store_email',    'ইমেইল (বিকল্প)'],
    '{ADDRESS}'          => ['store_address',  'ঠিকানা'],
    '{{store_address}}'  => ['store_address',  'ঠিকানা (বিকল্প)'],
    '{YEAR}'             => ['(স্বয়ংক্রিয়)', 'বর্তমান বছর'],
];

$currentValues = [];
try {
    $keys = ['store_name','store_phone','store_email','store_address'];
    $stm = $pdo->query("SELECT `key`,`value` FROM settings WHERE `key` IN ('store_name','store_phone','store_email','store_address')");
    $currentValues = $stm->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
} catch (Throwable $e) {}
if (empty($currentValues['store_name'])) $currentValues['store_name'] = APP_NAME;

require_once __DIR__.'/header.php';
?>
<h4 class="mb-1">স্ট্যাটিক পেজ</h4>
<div class="text-muted small mb-3">
  About / Privacy / Terms / Contact — ফ্রন্টএন্ড ড্রয়ার মেনু ও ফুটারে লিংক করা আছে।
</div>

<?php if (!empty($_SESSION['flash'])): $f=$_SESSION['flash']; unset($_SESSION['flash']); ?>
  <div class="alert <?php echo !empty($f['ok'])?'alert-success':'alert-danger'; ?> py-2">
    <?php echo h($f['msg'] ?? $f['message'] ?? ''); ?>
  </div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-md-4">
    <div class="card"><div class="card-body p-0">
      <div class="list-group list-group-flush">
        <?php foreach ($rows as $r): ?>
          <a class="list-group-item list-group-item-action d-flex justify-content-between align-items-center <?php echo ((int)$r['id']===$editId)?'active':''; ?>"
             href="pages.php?edit=<?php echo (int)$r['id']; ?>">
            <span>
              <strong><?php echo h($r['title']); ?></strong>
              <span class="d-block small opacity-75">/<?php echo h($r['slug']); ?>.php</span>
            </span>
            <?php if (!$r['active']): ?><span class="badge text-bg-secondary">off</span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div></div>

    <!-- ================= Placeholder guide ================= -->
    <div class="card mt-3">
      <div class="card-header py-2 fw-semibold small">
        <i class="fa-solid fa-code text-primary"></i> Placeholder (ডাইনামিক মান)
      </div>
      <div class="card-body p-2">
        <div class="small text-muted mb-2">
          কনটেন্টে নিচের কোডগুলো লিখুন — পেজ দেখানোর সময় স্বয়ংক্রিয়ভাবে আসল মান বসে যাবে।
          Settings থেকে মান বদলালে সব পেজে অটো আপডেট হবে।
        </div>
        <div class="table-responsive">
          <table class="table table-sm table-bordered align-middle mb-0" style="font-size:12px">
            <thead class="table-light">
              <tr>
                <th style="width:38%">Placeholder</th>
                <th>কী বসবে</th>
                <th style="width:26%">এখনকার মান</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($placeholders as $ph => $info): ?>
                <tr>
                  <td>
                    <code class="user-select-all" style="cursor:copy" title="ক্লিক করে কপি করুন"><?php echo h($ph); ?></code>
                  </td>
                  <td class="small"><?php echo h($info[1]); ?></td>
                  <td class="small text-truncate" style="max-width:130px"
                      title="<?php echo h((string)($currentValues[$info[0]] ?? '')); ?>">
                    <?php
                      $val = (string)($info[0] === '(স্বয়ংক্রিয়)' ? date('Y') : ($currentValues[$info[0]] ?? ''));
                      echo $val !== '' ? h($val) : '<span class="text-muted">—</span>';
                    ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <div class="d-grid mt-2">
          <a class="btn btn-sm btn-outline-primary" href="settings.php">
            <i class="fa-solid fa-gear"></i> Settings-এ মান পরিবর্তন করুন
          </a>
        </div>
      </div>
    </div>

    <!-- ================= HTML guide ================= -->
    <div class="card mt-3">
      <div class="card-header py-2 fw-semibold small">
        <i class="fa-solid fa-tags text-primary"></i> ব্যবহারযোগ্য HTML ও ক্লাস
      </div>
      <div class="card-body p-2 small">
        <div class="mb-2"><strong>অনুমোদিত ট্যাগ:</strong><br>
          <code>&lt;p&gt;</code> <code>&lt;h2&gt;</code> <code>&lt;h3&gt;</code>
          <code>&lt;ul&gt;</code> <code>&lt;ol&gt;</code> <code>&lt;li&gt;</code>
          <code>&lt;strong&gt;</code> <code>&lt;em&gt;</code> <code>&lt;br&gt;</code>
          <code>&lt;hr&gt;</code> <code>&lt;a&gt;</code> <code>&lt;img&gt;</code>
          <code>&lt;table&gt;</code> <code>&lt;div&gt;</code> <code>&lt;span&gt;</code>
        </div>
        <div class="mb-2"><strong>ডিজাইন ক্লাস (mFeex স্টাইল):</strong></div>
        <table class="table table-sm table-bordered mb-0" style="font-size:12px">
          <tbody>
            <tr><td><code>pg-text</code></td><td>সাধারণ প্যারাগ্রাফ</td></tr>
            <tr><td><code>pg-title</code></td><td>সেকশন শিরোনাম (নীল আন্ডারলাইন)</td></tr>
            <tr><td><code>pg-list</code></td><td>বুলেট লিস্ট</td></tr>
            <tr><td><code>pg-divider</code></td><td>পাতলা বিভাজক রেখা</td></tr>
            <tr><td><code>pg-features</code> + <code>pg-feature</code></td><td>আইকনসহ ফিচার গ্রিড</td></tr>
            <tr><td><code>pg-stats</code> + <code>pg-stat</code></td><td>পরিসংখ্যান ব্লক</td></tr>
          </tbody>
        </table>
        <div class="mt-2 text-muted">
          <i class="fa-solid fa-circle-info"></i> আইকন বসাতে Font Awesome ব্যবহার করুন, যেমন:
          <code>&lt;i class="fa-solid fa-truck"&gt;&lt;/i&gt;</code>
        </div>
        <div class="mt-2 text-danger">
          <i class="fa-solid fa-shield-halved"></i> <code>&lt;script&gt;</code>, <code>onclick</code>,
          <code>javascript:</code> নিরাপত্তার জন্য স্বয়ংক্রিয়ভাবে সরিয়ে ফেলা হয়।
        </div>
      </div>
    </div>
  </div>

  <div class="col-12 col-md-8">
    <?php if ($edit): ?>
      <div class="card"><div class="card-body">
        <form method="post">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="id" value="<?php echo (int)$edit['id']; ?>">

          <div class="mb-3">
            <label class="form-label">টাইটেল</label>
            <input name="title" class="form-control" required value="<?php echo h($edit['title']); ?>">
          </div>

          <div class="mb-3">
            <label class="form-label d-flex align-items-center justify-content-between flex-wrap gap-2">
              <span>কনটেন্ট</span>
              <span class="d-flex flex-wrap gap-1">
                <?php foreach (['{STORE}','{PHONE}','{EMAIL}','{ADDRESS}','{YEAR}'] as $ph): ?>
                  <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 ph-insert"
                          data-ph="<?php echo h($ph); ?>" style="font-size:11px;font-family:monospace">
                    <?php echo h($ph); ?>
                  </button>
                <?php endforeach; ?>
              </span>
            </label>
            <textarea name="content" id="pageContent" class="form-control" rows="14" style="font-family:monospace"><?php echo h($edit['content']); ?></textarea>
            <div class="form-text">
              উপরের বাটনে ক্লিক করলে কার্সরের জায়গায় placeholder বসে যাবে।
              বিস্তারিত নির্দেশনা বাম পাশের কার্ডে আছে।
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label">মেটা ডেসক্রিপশন</label>
            <input name="meta_description" class="form-control" value="<?php echo h($edit['meta_description']); ?>">
          </div>

          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" name="active" value="1" id="pageActive" <?php echo $edit['active']?'checked':''; ?>>
            <label class="form-check-label" for="pageActive">সক্রিয়</label>
          </div>

          <button class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> সংরক্ষণ</button>
          <a class="btn btn-outline-secondary" href="../<?php echo h($edit['slug']); ?>.php" target="_blank" rel="noopener">প্রিভিউ</a>
        </form>
      </div></div>
    <?php else: ?>
      <div class="alert alert-info">কোনো পেজ নেই।</div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__.'/footer.php'; ?>

<script>
/* Placeholder quick-insert buttons */
document.addEventListener('click', function (e) {
  var btn = e.target.closest('.ph-insert');
  if (!btn) return;
  e.preventDefault();

  var ta = document.getElementById('pageContent');
  if (!ta) return;

  var ph = btn.dataset.ph || '';
  var start = ta.selectionStart ?? ta.value.length;
  var end   = ta.selectionEnd ?? ta.value.length;

  ta.value = ta.value.slice(0, start) + ph + ta.value.slice(end);
  ta.focus();
  ta.selectionStart = ta.selectionEnd = start + ph.length;
  ta.dispatchEvent(new Event('input', { bubbles: true }));
});
</script>
