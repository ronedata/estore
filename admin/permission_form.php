<?php
// admin/permission_form.php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$pdo = get_pdo();
gate('manage_admin_users');

$id = (int)($_GET['id'] ?? 0);
$permission = null;

if ($id > 0) {
    $stm = $pdo->prepare("SELECT * FROM permissions WHERE id = ?");
    $stm->execute([$id]);
    $permission = $stm->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $_SESSION['flash'] = ['ok' => false, 'msg' => 'CSRF token mismatch'];
        header('Location: permission_form.php' . ($id > 0 ? '?id=' . $id : ''));
        exit;
    }
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    // Basic validation
    if (empty($name) || !preg_match('/^[a-z0-9_]+$/', $name)) {
        $_SESSION['flash'] = ['ok' => false, 'msg' => 'পারমিশনের নাম শুধুমাত্র ছোট হাতের অক্ষর, সংখ্যা এবং আন্ডারস্কোর (_) দিয়ে হতে হবে।'];
    } else {
        try {
            if ($id > 0) {
                // Update existing permission
                $pdo->prepare("UPDATE permissions SET name = ?, description = ? WHERE id = ?")->execute([$name, $description, $id]);
            } else {
                // Insert new permission
                $pdo->prepare("INSERT INTO permissions (name, description) VALUES (?, ?)")->execute([$name, $description]);
            }
            $_SESSION['flash'] = ['ok' => true, 'msg' => 'পারমিশন সফলভাবে সেভ হয়েছে।'];
            header('Location: permissions.php');
            exit;
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry')) {
                $_SESSION['flash'] = ['ok' => false, 'msg' => 'এই নামের পারমিশন ইতিমধ্যে আছে।'];
            } else {
                $_SESSION['flash'] = ['ok' => false, 'msg' => 'সেভ করা যায়নি। Error: ' . $e->getMessage()];
            }
        }
    }
    // Redirect back to the form on error to show the message
    header('Location: permission_form.php' . ($id > 0 ? '?id=' . $id : ''));
    exit;
}

require_once __DIR__.'/permission_labels.php';
require_once __DIR__.'/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="m-0"><?php echo $id > 0 ? 'পারমিশন এডিট' : 'নতুন পারমিশন'; ?></h5>
    <a href="permissions.php" class="btn btn-outline-secondary">ফিরে যান</a>
</div>

<div class="card">
    <div class="card-body">
        <form method="post">
            <?php echo csrf_field(); ?>
            <div class="mb-3">
                <label for="name" class="form-label">Name (Identifier)</label>
                <input type="text" name="name" id="name" class="form-control" value="<?php echo h($permission['name'] ?? ''); ?>" required pattern="[a-z0-9_]+" title="Only lowercase letters, numbers, and underscores are allowed.">
                <div class="form-text">কোডে ব্যবহারের জন্য ইউনিক নাম, যেমন: <code>manage_coupons</code>। স্পেস বা বড় হাতের অক্ষর ব্যবহার করা যাবে না।</div>
                <div id="labelPreview" class="mt-2 small text-muted"></div>
            </div>
            <div class="mb-3">
                <label for="description" class="form-label">Description</label>
                <textarea name="description" id="description" class="form-control" placeholder="এই পারমিশন কী কাজ করে তার সংক্ষিপ্ত বিবরণ..."><?php echo h($permission['description'] ?? ''); ?></textarea>
            </div>
            <div class="alert alert-light border small">
              <i class="fa-solid fa-circle-info text-primary"></i>
              সাইডবারে নতুন মেনু যোগ করলে <code>admin/permission_labels.php</code>-ও আপডেট করুন —
              নাহলে এই পারমিশনের বাংলা নাম ও গ্রুপ দেখাবে না (identifier-ই দেখাবে)।
            </div>
            <div class="mt-4">
                <button type="submit" class="btn btn-primary">Save Permission</button>
            </div>
        </form>
    </div>
</div>

<script>
(function(){
  const input = document.getElementById('name');
  const preview = document.getElementById('labelPreview');
  if (!input || !preview) return;

  const MAP = <?php echo json_encode(
    array_map(fn($v) => ['label'=>$v['label'], 'group'=>$v['group']], permission_label_map()),
    JSON_UNESCAPED_UNICODE
  ); ?>;

  function render() {
    const n = (input.value || '').trim();
    if (!n) { preview.textContent = ''; return; }
    const hit = MAP[n];
    if (hit) {
      preview.innerHTML = '<i class="fa-solid fa-circle-check text-success"></i> মেনু নাম: <strong>' +
        hit.label.replace(/[<>&]/g, '') + '</strong> · গ্রুপ: ' + hit.group.replace(/[<>&]/g, '');
    } else {
      preview.innerHTML = '<i class="fa-solid fa-circle-exclamation text-warning"></i> ম্যাপে নেই —.identifier হিসেবেই দেখাবে।';
    }
  }
  input.addEventListener('input', render);
  render();
})();
</script>

<?php require_once __DIR__.'/footer.php'; ?>
