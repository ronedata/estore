<?php
// PHP লজিক আগে, HTML পরে
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_admin_users'); // <-- পারমিশন চেক

$pdo=get_pdo();
$msg='';
$roles = $pdo->query("SELECT id, name FROM roles ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
  $_SESSION['flash'] = ['ok' => false, 'msg' => 'CSRF token mismatch'];
  header('Location: users.php'); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_id'])){
  $id=(int)$_POST['delete_id'];
  if($id!== (int)($_SESSION['admin_id'] ?? 0)){
    $pdo->prepare("DELETE FROM admin_users WHERE id=?")->execute([$id]);
  }
  header('Location: users.php'); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && !isset($_POST['update_role']) && !isset($_POST['delete_id'])){
  $username=trim($_POST['username'] ?? '');
  $password=$_POST['password'] ?? '';
  $role_id = (int)($_POST['role_id'] ?? 0);
  $role_id = $role_id > 0 ? $role_id : null;

  if($username && $password){
    $hash=password_hash($password, PASSWORD_BCRYPT);
    try{
      $pdo->prepare("INSERT INTO admin_users(username, password_hash, role_id) VALUES(?,?,?)")->execute([$username, $hash, $role_id]);
      $msg='নতুন ইউজার যোগ হয়েছে';
    }catch(Throwable $e){ $msg='ইউজারনেমটি নেওয়া আছে'; }
  } else { $msg='সব ফিল্ড দিন'; }
}
if (isset($_POST['update_role'])) {
    $user_id = (int)$_POST['user_id'];
    $new_role_id = (int)$_POST['role_id'] > 0 ? (int)$_POST['role_id'] : null;
    $pdo->prepare("UPDATE admin_users SET role_id = ? WHERE id = ?")->execute([$new_role_id, $user_id]);
    $msg = 'ইউজারের রোল আপডেট হয়েছে।';
}

$rows=$pdo->query("SELECT u.id, u.username, u.created_at, u.role_id, r.name as role_name FROM admin_users u LEFT JOIN roles r ON u.role_id = r.id ORDER BY u.id DESC")->fetchAll();

// এখন HTML আউটপুট শুরু হবে
require_once __DIR__.'/header.php';
?>
<div class="row g-3">
  <div class="col-md-5">
    <div class="card"><div class="card-body">
      <h5 class="card-title mb-3">নতুন অ্যাডমিন</h5>
      <?php if($msg): ?><div class="alert alert-info py-2"><?php echo h($msg); ?></div><?php endif; ?>
      <form method="post">
        <?php echo csrf_field(); ?>
        <div class="mb-3"><label class="form-label">Username</label><input name="username" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">Password</label><input type="password" name="password" class="form-control" required></div>
        <div class="mb-3"><label class="form-label">Role</label>
            <select name="role_id" class="form-select">
                <option value="0">-- কোনো রোল নেই --</option>
                <?php foreach ($roles as $role): ?>
                    <option value="<?php echo (int)$role['id']; ?>"><?php echo h($role['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary">Add User</button>
      </form>
    </div></div>
  </div>
  <div class="col-md-7">
    <div class="card"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="card-title m-0">ইউজার লিস্ট</h5>
        <a href="roles.php" class="btn btn-sm btn-outline-primary">রোল ম্যানেজ করুন</a>
      </div>
      <table class="table table-striped align-middle mb-0">
        <thead><tr><th>ID</th><th>Username</th><th>Role</th><th class="text-end">Action</th></tr></thead>
        <tbody>
          <?php foreach($rows as $r): ?>
          <tr>
            <td><?php echo (int)$r['id']; ?></td>
            <td><?php echo h($r['username']); ?></td>
            <td>
                <form method="post" class="d-flex gap-1">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="user_id" value="<?php echo (int)$r['id']; ?>">
                    <select name="role_id" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="0">-- রোল নেই --</option>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?php echo (int)$role['id']; ?>" <?php echo (int)$r['role_id'] === (int)$role['id'] ? 'selected' : ''; ?>>
                                <?php echo h($role['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="update_role" value="1">
                </form>
            </td>
            <td class="text-end">
              <?php if((int)$r['id'] !== (int)($_SESSION['admin_id'] ?? 0)): ?>
                <form method="post" class="d-inline" onsubmit="return confirm('Delete user?')">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="delete_id" value="<?php echo (int)$r['id']; ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                </form>
              <?php else: ?>
                <span class="text-muted small">current</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div></div>
  </div>
</div>
<?php require __DIR__.'/footer.php'; ?>
