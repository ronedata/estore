<?php
// POST হ্যান্ডলিং আগে করতে হবে, তাই header.php পরে লোড হবে
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$pdo = get_pdo();
gate('manage_admin_users');
$id = (int)($_GET['id'] ?? 0);
$role = null;
$role_permissions = [];

$all_permissions = $pdo->query("SELECT * FROM permissions ORDER BY name")->fetchAll();

if ($id > 0) {
    $stm = $pdo->prepare("SELECT * FROM roles WHERE id = ?");
    $stm->execute([$id]);
    $role = $stm->fetch();

    $perm_stm = $pdo->prepare("SELECT permission_id FROM role_permissions WHERE role_id = ?");
    $perm_stm->execute([$id]);
    $role_permissions = $perm_stm->fetchAll(PDO::FETCH_COLUMN);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $_SESSION['flash'] = ['ok' => false, 'msg' => 'CSRF token mismatch'];
        header('Location: role_form.php' . ($id > 0 ? '?id=' . $id : ''));
        exit;
    }
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $permissions = $_POST['permissions'] ?? [];

    if ($name) {
        try {
            $pdo->beginTransaction();

            if ($id > 0) {
                // Update existing role
                $pdo->prepare("UPDATE roles SET name = ?, description = ? WHERE id = ?")->execute([$name, $description, $id]);
                $new_id = $id;
            } else {
                // Insert new role
                $pdo->prepare("INSERT INTO roles (name, description) VALUES (?, ?)")->execute([$name, $description]);
                $new_id = $pdo->lastInsertId();
            }

            // Super Admin role cannot be modified
            if (!$role || $role['name'] !== 'Super Admin') {
                // Update permissions
                $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$new_id]);
                if (!empty($permissions)) {
                    $insert_stm = $pdo->prepare("INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)");
                    foreach ($permissions as $perm_id) {
                        $insert_stm->execute([$new_id, (int)$perm_id]);
                    }
                }
            }

            $pdo->commit();
            $_SESSION['flash'] = ['ok' => true, 'msg' => 'রোল সফলভাবে সেভ হয়েছে।'];
            header('Location: roles.php');
            exit;

        } catch (Throwable $e) {
            $pdo->rollBack();
            $_SESSION['flash'] = ['ok' => false, 'msg' => 'সেভ করা যায়নি। Error: ' . $e->getMessage()];
        }
    }
}

// এখন HTML আউটপুট শুরু হবে
require_once __DIR__.'/permission_labels.php';
require_once __DIR__.'/header.php';

$is_super_admin_role = ($role && $role['name'] === 'Super Admin');
$grouped_perms = permission_grouped($all_permissions);
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="m-0"><?php echo $id > 0 ? 'রোল এডিট' : 'নতুন রোল'; ?></h5>
    <a href="roles.php" class="btn btn-outline-secondary">ফিরে যান</a>
</div>

<div class="card">
    <div class="card-body">
        <form method="post">
            <?php echo csrf_field(); ?>
            <div class="mb-3">
                <label for="name" class="form-label">Role Name</label>
                <input type="text" name="name" id="name" class="form-control" value="<?php echo h($role['name'] ?? ''); ?>" required <?php if ($is_super_admin_role) echo 'readonly'; ?>>
            </div>
            <div class="mb-3">
                <label for="description" class="form-label">Description</label>
                <textarea name="description" id="description" class="form-control"><?php echo h($role['description'] ?? ''); ?></textarea>
            </div>

            <h6 class="mt-4">Permissions</h6>
            <?php if ($is_super_admin_role): ?>
                <div class="alert alert-info">Super Admin-এর সব পারমিশন রয়েছে এবং এটি পরিবর্তন করা যাবে না।</div>
            <?php else: ?>
                <?php
                  $groupMeta = [
                    'সাধারণ'   => ['#e0f2fe', '#0284c7'],
                    'ক্যাটালগ'  => ['#dcfce7', '#16a34a'],
                    'কনটেন্ট'  => ['#fef3c7', '#d97706'],
                    'বিক্রয়'    => ['#ede9fe', '#7c3aed'],
                    'রিপোর্ট'   => ['#ffe4e6', '#be123c'],
                    'সিস্টেম'   => ['#f1f5f9', '#475569'],
                    'অন্যান্য'  => ['#f1f5f9', '#475569'],
                  ];
                ?>
                <div class="d-flex gap-2 mb-2 flex-wrap">
                  <button type="button" class="btn btn-sm btn-outline-secondary" id="permSelectAll">সব নির্বাচন</button>
                  <button type="button" class="btn btn-sm btn-outline-secondary" id="permClearAll">সব বাদ দিন</button>
                </div>

                <?php foreach ($grouped_perms as $group => $perms): ?>
                  <?php [$bg, $fg] = $groupMeta[$group] ?? $groupMeta['অন্যান্য']; ?>
                  <div class="card mb-2">
                    <div class="card-header py-2 d-flex align-items-center gap-2" style="background:<?php echo $bg; ?>;color:<?php echo $fg; ?>">
                      <strong style="font-size:.86rem"><?php echo h($group); ?></strong>
                      <span class="badge" style="background:<?php echo $fg; ?>"><?php echo count($perms); ?></span>
                    </div>
                    <div class="card-body py-2">
                      <div class="row">
                        <?php foreach ($perms as $perm): ?>
                          <div class="col-md-4 col-sm-6">
                            <div class="form-check">
                              <input class="form-check-input" type="checkbox" name="permissions[]"
                                     value="<?php echo (int)$perm['id']; ?>" id="perm-<?php echo (int)$perm['id']; ?>"
                                     <?php echo in_array($perm['id'], $role_permissions) ? 'checked' : ''; ?>>
                              <label class="form-check-label" for="perm-<?php echo (int)$perm['id']; ?>">
                                <i class="fa-solid <?php echo h(permission_icon((string)$perm['name'])); ?> text-muted me-1" style="font-size:.78rem"></i>
                                <?php echo h(permission_label((string)$perm['name'])); ?>
                                <div class="text-muted" style="font-size:.7rem"><code><?php echo h($perm['name']); ?></code></div>
                              </label>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>

                <script>
                document.getElementById('permSelectAll')?.addEventListener('click', function(){
                  document.querySelectorAll('input[name="permissions[]"]').forEach(function(cb){ cb.checked = true; });
                });
                document.getElementById('permClearAll')?.addEventListener('click', function(){
                  document.querySelectorAll('input[name="permissions[]"]').forEach(function(cb){ cb.checked = false; });
                });
                </script>
            <?php endif; ?>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary">Save Role</button>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__.'/footer.php'; ?>