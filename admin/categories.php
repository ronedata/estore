<?php
// PHP লজিক আগে, HTML পরে
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/image_processor.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_categories');
$pdo = get_pdo();

/* ---------- helpers ---------- */
function cat_upload_dir(): string {
  $dir = dirname(__DIR__).'/uploads/categories';
  if (!is_dir($dir)) @mkdir($dir, 0777, true);
  return $dir;
}

/** শুধু uploads/categories এর ভেতরের ফাইল ডিলিট হবে (path traversal বন্ধ) */
function cat_unlink(string $url): void {
  $url = trim($url);
  if ($url === '') return;
  $rel = ltrim($url, '/');
  if (stripos($rel, 'uploads/categories/') !== 0) return;
  $base = str_replace('/', DIRECTORY_SEPARATOR, $rel);
  $abs  = dirname(__DIR__).DIRECTORY_SEPARATOR.$base;
  $real = realpath($abs);
  $root = realpath(cat_upload_dir());
  if ($real && $root && strpos($real, $root) === 0 && is_file($real)) @unlink($real);
}

/** @return array{0:?string,1:?string} [imageUrl, error] */
function cat_handle_upload(string $field): array {
  if (empty($_FILES[$field]['name'])) return [null, null];

  $f = $_FILES[$field];
  if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    return [null, 'ইমেজ আপলোড ব্যর্থ (কোড '.$f['error'].')।'];
  }
  if ((int)$f['size'] > 2 * 1024 * 1024) {
    return [null, 'ইমেজ ২MB এর কম হতে হবে।'];
  }

  $info = @getimagesize($f['tmp_name']);
  $allowed = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG  => 'png',
    IMAGETYPE_WEBP => 'webp',
    IMAGETYPE_GIF  => 'gif',
  ];
  if (!$info || !isset($allowed[$info[2]])) {
    return [null, 'শুধু JPG, PNG, WEBP, GIF ইমেজ গ্রহণ করা হয়।'];
  }

  $ext  = $allowed[$info[2]];
  $name = 'cat_'.date('YmdHis').'_'.bin2hex(random_bytes(4)).'.'.$ext;
  $dest = cat_upload_dir().DIRECTORY_SEPARATOR.$name;

  /* ক্যাটেগরি আইকন — বর্গাকার 400×400 (রেটিনার জন্য যথেষ্ট) */
  [$tw, $th] = estore_category_target_size();
  $proc = estore_process_image($f['tmp_name'], $dest, $tw, $th, '#ffffff');

  if (empty($proc['ok'])) {
    return [null, 'ইমেজ প্রসেস করা যায়নি: ' . ($proc['error'] ?: 'অজানা ত্রুটি')];
  }
  return ['uploads/categories/'.$name, null];
}

/* ---------- POST ---------- */
$errors   = [];
$imageUrl = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify()) {
    $_SESSION['flash'] = ['ok' => false, 'msg' => 'CSRF token mismatch'];
    header('Location: categories.php'); exit;
  }

  /* ডিলিট */
  if (isset($_POST['delete_id'])) {
    $id = (int)$_POST['delete_id'];
    $stm = $pdo->prepare("SELECT image FROM categories WHERE id=?");
    $stm->execute([$id]);
    $old = (string)($stm->fetchColumn() ?: '');
    $pdo->prepare("DELETE FROM categories WHERE id=?")->execute([$id]);
    cat_unlink($old);
    $_SESSION['flash'] = ['ok' => true, 'msg' => 'ক্যাটেগরি ডিলিট হয়েছে'];
    header('Location: categories.php'); exit;
  }

  $id         = (int)($_POST['id'] ?? 0);
  $name       = trim($_POST['name'] ?? '');
  $sort_order = (int)($_POST['sort_order'] ?? 0);
  $active     = !empty($_POST['active']) ? 1 : 0;
  $removeImg  = !empty($_POST['remove_image']);

  /* আগের ইমেজ */
  $oldImage = '';
  if ($id > 0) {
    $stm = $pdo->prepare("SELECT image FROM categories WHERE id=?");
    $stm->execute([$id]);
    $oldImage = (string)($stm->fetchColumn() ?: '');
  }
  $imageUrl = $oldImage;

  [$newImage, $upErr] = cat_handle_upload('image');
  if ($upErr) $errors[] = $upErr;

  if ($name === '') $errors[] = 'ক্যাটেগরির নাম আবশ্যক।';

  if (!$errors) {
    $finalImage = $imageUrl;
    if ($newImage !== null)      $finalImage = $newImage;
    elseif ($removeImg)          $finalImage = '';

    try {
      if ($id > 0) {
        $pdo->prepare("UPDATE categories SET name=?, image=?, sort_order=?, active=? WHERE id=?")
            ->execute([$name, ($finalImage ?: null), $sort_order, $active, $id]);
        if ($oldImage !== '' && $oldImage !== $finalImage) cat_unlink($oldImage);
        $_SESSION['flash'] = ['ok' => true, 'msg' => 'ক্যাটেগরি আপডেট হয়েছে'];
      } else {
        $pdo->prepare("INSERT INTO categories(name, image, sort_order, active) VALUES(?,?,?,?)")
            ->execute([$name, ($finalImage ?: null), $sort_order, $active]);
        $_SESSION['flash'] = ['ok' => true, 'msg' => 'নতুন ক্যাটেগরি যোগ হয়েছে'];
      }
      header('Location: categories.php'); exit;
    } catch (Throwable $e) {
      $errors[] = 'সংরক্ষণ ব্যর্থ: '.$e->getMessage();
      if ($newImage !== null && $newImage !== $oldImage) cat_unlink($newImage);
    }
  }
}

/* ---------- GET ---------- */
$rows = $pdo->query("SELECT * FROM categories ORDER BY sort_order, id DESC")->fetchAll();
$edit = null;
if (isset($_GET['edit'])) {
  $stm = $pdo->prepare("SELECT * FROM categories WHERE id=?");
  $stm->execute([(int)$_GET['edit']]);
  $edit = $stm->fetch() ?: null;
}

/* ভ্যালিডেশন এরর হলে ফর্মে আগের ইনপুট ধরে রাখো */
if (!empty($errors)) {
  $edit = $edit ?: [];
  $edit['id']         = (int)($_POST['id'] ?? 0);
  $edit['name']       = trim($_POST['name'] ?? '');
  $edit['sort_order'] = (int)($_POST['sort_order'] ?? 0);
  $edit['active']     = !empty($_POST['active']) ? 1 : 0;
  $edit['image']      = $imageUrl ?? '';
}
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

require_once __DIR__.'/header.php';
?>
<div class="row g-3">
  <div class="col-12 col-md-5">
    <div class="card"><div class="card-body">
      <h5 class="card-title mb-3"><?php echo $edit ? 'ক্যাটেগরি এডিট' : 'ক্যাটেগরি যুক্ত করুন'; ?></h5>

      <?php if ($flash): ?>
        <div class="alert <?php echo !empty($flash['ok']) ? 'alert-success' : 'alert-danger'; ?> py-2">
          <?php echo h($flash['msg'] ?? ''); ?>
        </div>
      <?php endif; ?>
      <?php if (!empty($errors)): ?>
        <div class="alert alert-danger py-2">
          <ul class="mb-0 ps-3">
            <?php foreach ($errors as $e): ?><li><?php echo h($e); ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <form method="post" enctype="multipart/form-data">
        <?php echo csrf_field(); ?>
        <input type="hidden" name="id" value="<?php echo (int)($edit['id'] ?? 0); ?>">

        <div class="mb-3">
          <label class="form-label">নাম</label>
          <input name="name" class="form-control" required value="<?php echo h($edit['name'] ?? ''); ?>">
        </div>

        <div class="mb-3">
          <label class="form-label">ইমেজ <span class="text-muted small">(JPG/PNG/WEBP/GIF, সর্বোচ্চ ২MB)</span></label>

          <div class="border rounded-3 p-3 mb-2" style="background:#f8fafc;border-color:#e2e8f0!important">
            <div class="d-flex align-items-start gap-3 flex-wrap">
              <div class="flex-shrink-0 text-center">
                <div style="width:64px;height:64px;border:2px dashed #94a3b8;border-radius:50%;
                            display:flex;align-items:center;justify-content:center;
                            background:#fff;font-size:10.5px;font-weight:700;color:#475569;line-height:1.2">
                  400<br>×<br>400
                </div>
                <div class="small text-muted mt-1" style="font-size:10px">বর্গাকার</div>
              </div>

              <div class="flex-grow-1" style="min-width:200px">
                <div class="fw-bold mb-1" style="font-size:13.5px">
                  <i class="fa-solid fa-circle-info text-primary"></i>
                  ছবি <strong>৪০০ × ৪০০ px</strong> (বর্গাকার) দিন
                </div>
                <ul class="mb-2 ps-3 text-muted" style="font-size:11.5px;line-height:1.6">
                  <li>ফরম্যাট: <strong>JPG, PNG, WEBP, GIF</strong> · সর্বোচ্চ <strong>২MB</strong></li>
                  <li>বড় বা অসম অনুপাতের ছবি → <strong>স্বয়ংক্রিয় center-crop</strong></li>
                  <li>ছোট ছবি → <strong>সাদা ক্যানভাসে মাঝ বরাবর</strong> বসবে</li>
                  <li>লোগো/আইকন হলে <strong>কেন্দ্রের ৮০%-এর ভেতরে</strong> রাখুন</li>
                </ul>
                <div class="small" style="font-size:11.5px;color:#0369a1;background:#e0f2fe;border:1px solid #bae6fd;border-radius:8px;padding:6px 9px">
                  <i class="fa-solid fa-wand-magic-sparkles"></i>
                  আপলোডের পর ছবি অটোমেটিক <strong>৪০০×৪০০</strong> বানানো হবে।
                </div>
              </div>
            </div>
          </div>

          <input type="file" name="image" id="catImageInput" class="form-control" accept="image/jpeg,image/png,image/webp,image/gif">
        </div>

        <div class="mb-3">
          <img id="catImagePreview"
               src="<?php echo h($edit['image'] ?? ''); ?>"
               alt=""
               style="width:96px;height:96px;object-fit:cover;border-radius:12px;border:1px solid #e5e7eb;<?php echo empty($edit['image']) ? 'display:none' : ''; ?>">
          <?php if (!empty($edit['image'])): ?>
            <div class="form-check mt-2">
              <input class="form-check-input" type="checkbox" name="remove_image" value="1" id="catRemoveImage">
              <label class="form-check-label small" for="catRemoveImage">বর্তমান ইমেজ সরান</label>
            </div>
          <?php endif; ?>
        </div>

        <div class="row g-2 mb-3">
          <div class="col-6">
            <label class="form-label">ক্রম</label>
            <input type="number" name="sort_order" class="form-control" value="<?php echo (int)($edit['sort_order'] ?? 0); ?>">
          </div>
          <div class="col-6">
            <label class="form-label d-block">স্ট্যাটাস</label>
            <div class="form-check form-switch mt-2">
              <input class="form-check-input" type="checkbox" name="active" value="1" id="catActive"
                     <?php echo (!isset($edit['active']) || (int)$edit['active'] === 1) ? 'checked' : ''; ?>>
              <label class="form-check-label" for="catActive">সক্রিয়</label>
            </div>
          </div>
        </div>

        <button class="btn btn-primary"><?php echo $edit ? 'Update' : 'Save'; ?></button>
        <?php if ($edit): ?><a href="categories.php" class="btn btn-secondary ms-2">ক্যান্সেল</a><?php endif; ?>
      </form>
    </div></div>
  </div>

  <div class="col-12 col-md-7">
    <div class="card"><div class="card-body">
      <h5 class="card-title mb-3">ক্যাটেগরি লিস্ট</h5>
      <div class="table-responsive">
      <table class="table table-striped table-hover align-middle mb-0">
        <thead><tr><th>ID</th><th>ইমেজ</th><th>Name</th><th>ক্রম</th><th>স্ট্যাটাস</th><th class="text-end">Action</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
          <tr>
            <td><?php echo (int)$r['id']; ?></td>
            <td>
              <?php if (!empty($r['image'])): ?>
                <img src="<?php echo h(img_url($r['image'])); ?>" alt="<?php echo h($r['name']); ?>"
                     style="width:44px;height:44px;object-fit:cover;border-radius:8px;border:1px solid #e5e7eb"
                     onerror="this.onerror=null;this.src='<?php echo h(img_fallback_url()); ?>'">
              <?php else: ?>
                <span class="text-muted small">—</span>
              <?php endif; ?>
            </td>
            <td><?php echo h($r['name']); ?></td>
            <td><?php echo (int)$r['sort_order']; ?></td>
            <td>
              <?php if ((int)$r['active'] === 1): ?>
                <span class="badge bg-success">সক্রিয়</span>
              <?php else: ?>
                <span class="badge bg-secondary">নিষ্ক্রিয়</span>
              <?php endif; ?>
            </td>
            <td class="text-end">
              <a class="btn btn-sm btn-outline-primary" href="?edit=<?php echo (int)$r['id']; ?>">Edit</a>
              <form method="post" class="d-inline" onsubmit="return confirm('Delete category?')">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="delete_id" value="<?php echo (int)$r['id']; ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div></div>
  </div>
</div>

<script>
document.getElementById('catImageInput')?.addEventListener('change', function () {
  var file = this.files && this.files[0];
  var img = document.getElementById('catImagePreview');
  if (!img) return;
  if (!file) { return; }
  img.src = URL.createObjectURL(file);
  img.style.display = 'block';
});
</script>
<?php require __DIR__.'/footer.php'; ?>
