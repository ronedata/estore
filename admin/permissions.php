<?php
// admin/permissions.php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

gate('manage_admin_users');
$pdo = get_pdo();

// Handle Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!csrf_verify()) {
        $_SESSION['flash'] = ['ok' => false, 'msg' => 'CSRF token mismatch'];
        header('Location: permissions.php'); exit;
    }
    $id = (int)$_POST['delete_id'];
    try {
        // Foreign key constraint থাকায় ব্যবহৃত পারমিশন ডিলিট হবে না।
        $pdo->prepare("DELETE FROM permissions WHERE id = ?")->execute([$id]);
        $_SESSION['flash'] = ['ok' => true, 'msg' => 'পারমিশন সফলভাবে ডিলিট হয়েছে।'];
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'foreign key constraint fails')) {
            $_SESSION['flash'] = ['ok' => false, 'msg' => 'পারমিশনটি কোনো রোলে ব্যবহৃত হচ্ছে, তাই ডিলিট করা সম্ভব নয়।'];
        } else {
            $_SESSION['flash'] = ['ok' => false, 'msg' => 'ডিলিট করা যায়নি। Error: ' . $e->getMessage()];
        }
    }
    header('Location: permissions.php');
    exit;
}

$permissions = $pdo->query("SELECT * FROM permissions ORDER BY name")->fetchAll();

require_once __DIR__.'/permission_labels.php';
$grouped  = permission_grouped($permissions);
$unmapped = permission_unmapped($permissions);

require_once __DIR__.'/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h5 class="m-0">পারমিশন ম্যানেজমেন্ট</h5>
        <div class="text-muted small">প্রতিটি পারমিশন সাইডবারের কোন মেনু নিয়ন্ত্রণ করে</div>
    </div>
    <a href="permission_form.php" class="btn btn-primary">নতুন পারমিশন যোগ করুন</a>
</div>

<?php if ($unmapped): ?>
  <div class="alert alert-warning">
    <strong><i class="fa-solid fa-triangle-exclamation"></i> <?php echo count($unmapped); ?>টি পারমিশন সাইডবারের সাথে মেলানো যায়নি:</strong>
    <?php foreach ($unmapped as $u): ?><code class="ms-1"><?php echo h($u['name']); ?></code><?php endforeach; ?>
    <div class="small mt-1"><code>admin/permission_labels.php</code>-এ এন্ট্রি যোগ করলে নাম দেখাবে।</div>
  </div>
<?php endif; ?>

<?php foreach ($grouped as $group => $rows): ?>
<div class="card mb-3">
    <div class="card-header bg-light d-flex justify-content-between align-items-center">
        <strong><?php echo h($group); ?></strong>
        <span class="badge text-bg-secondary"><?php echo count($rows); ?></span>
    </div>
    <div class="card-body p-0">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:52px">ID</th>
                    <th style="width:230px">মেনু নাম</th>
                    <th style="width:210px">Identifier</th>
                    <th>Description</th>
                    <th class="text-end" style="width:150px">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $perm):
              $pname = (string)$perm['name'];
              $known = isset(permission_label_map()[$pname]);
            ?>
                <tr>
                    <td class="text-muted"><?php echo (int)$perm['id']; ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <span class="d-inline-flex align-items-center justify-content-center"
                                  style="width:28px;height:28px;border-radius:8px;background:#e0f2fe;color:#0284c7;font-size:.78rem">
                                <i class="fa-solid <?php echo h(permission_icon($pname)); ?>"></i>
                            </span>
                            <span class="fw-semibold"><?php echo h(permission_label($pname)); ?></span>
                        </div>
                    </td>
                    <td><code><?php echo h($pname); ?></code></td>
                    <td class="text-muted small"><?php echo h($perm['description']); ?></td>
                    <td class="text-end">
                        <a href="permission_form.php?id=<?php echo (int)$perm['id']; ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                        <form method="post" class="d-inline" onsubmit="return confirm('এই পারমিশনটি ডিলিট করতে চান?')">
                            <?php echo csrf_field(); ?>
                            <input type="hidden" name="delete_id" value="<?php echo (int)$perm['id']; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<?php require_once __DIR__.'/footer.php'; ?>
