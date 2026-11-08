<?php
// PHP লজিক (ডিলিট এবং রিডাইরেক্ট) HTML আউটপুটের আগে আনতে হবে
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

gate('manage_admin_users');
$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!csrf_verify()) {
        $_SESSION['flash'] = ['ok' => false, 'msg' => 'CSRF token mismatch'];
        header('Location: roles.php'); exit;
    }
    $id = (int)$_POST['delete_id'];
    // Super Admin role cannot be deleted
    $role = $pdo->prepare("SELECT name FROM roles WHERE id = ?");
    $role->execute([$id]);
    if ($role->fetchColumn() !== 'Super Admin') {
        $pdo->prepare("DELETE FROM roles WHERE id = ?")->execute([$id]);
    }
    header('Location: roles.php');
    exit;
}

$roles = $pdo->query("SELECT * FROM roles ORDER BY name")->fetchAll();

// এখন HTML আউটপুট শুরু হবে
require_once __DIR__.'/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="m-0">রোল ম্যানেজমেন্ট</h5>
    <div class="d-flex gap-2">
        <a href="permissions.php" class="btn btn-outline-info">পারমিশন ম্যানেজ করুন</a>
        <a href="role_form.php" class="btn btn-primary">নতুন রোল তৈরি</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <table class="table table-striped align-middle">
            <thead>
                <tr><th>ID</th><th>Name</th><th>Description</th><th class="text-end">Action</th></tr>
            </thead>
            <tbody>
            <?php foreach ($roles as $role): ?>
                <tr>
                    <td><?php echo (int)$role['id']; ?></td>
                    <td><?php echo h($role['name']); ?></td>
                    <td><?php echo h($role['description']); ?></td>
                    <td class="text-end">
                        <a href="role_form.php?id=<?php echo (int)$role['id']; ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                        <?php if ($role['name'] !== 'Super Admin'): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Are you sure?')">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="delete_id" value="<?php echo (int)$role['id']; ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__.'/footer.php'; ?>