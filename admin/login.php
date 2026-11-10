<?php
require_once __DIR__.'/../includes/config.php';
if (!empty($_SESSION['admin_id'])) { header('Location: '.(BASE_URL.'/admin/index.php')); exit; }
require_once __DIR__.'/auth_functions.php'; // ফাংশনগুলো এখানে লোড হবে
require_once __DIR__.'/../includes/login_security.php';
$error = '';
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  // CSRF
  if (!csrf_verify()) {
    $error = 'সেশন মেয়াদোত্তীর্ণ। আবার চেষ্টা করুন।';
  }
  $username = trim($_POST['username'] ?? '');
  $password = $_POST['password'] ?? '';
  if (!$error && $username && $password) {
    $pdo = get_pdo();
    /* লগইন সুরক্ষা: লক থাকলে পাসওয়ার্ড যাচাইই হয় না */
    $lsCfg  = ls_load($pdo);
    $lsIp   = ls_client_ip($lsCfg['ip_source']);
    $lsLock = ls_active_lock($pdo, $lsIp, $username, $lsCfg);
    if (mt_rand(1, 50) === 1) ls_purge($pdo, (int)$lsCfg['retention_days']);
    $u = null;
    if ($lsLock) {
      ls_record($pdo, $lsIp, $username, 'blocked');
      $error = 'অনেকবার ভুল চেষ্টা হয়েছে। ' . ls_wait_text($lsLock['until']) . ' পরে আবার চেষ্টা করুন।';
    } else {
      $lsSlow = ls_slow_seconds($pdo, $username, $lsCfg);
      if ($lsSlow > 0) sleep($lsSlow);
      $stm = $pdo->prepare("SELECT * FROM admin_users WHERE username=? LIMIT 1");
      $stm->execute([$username]);
      $u = $stm->fetch();
    }
    if (!$lsLock && $u && password_verify($password, $u['password_hash'])) {
      ls_on_success($pdo, $lsIp, $username);
      // --- পারমিশন সিস্টেমের জন্য সংযোজন ---
      // ইউজারের রোল এবং পারমিশন লোড করা
      $permissions = [];
      $role_name = null;
      if (!empty($u['role_id'])) {
        $role_stm = $pdo->prepare(
          "SELECT r.name AS role_name, p.name AS permission_name
           FROM roles r
           LEFT JOIN role_permissions rp ON r.id = rp.role_id
           LEFT JOIN permissions p ON rp.permission_id = p.id
           WHERE r.id = ?"
        );
        $role_stm->execute([$u['role_id']]);
        $results = $role_stm->fetchAll();
        if ($results) {
          $role_name = $results[0]['role_name'];
          $permissions = array_column($results, 'permission_name');
        }
      }

      // Session fixation প্রতিরোধ
      session_regenerate_id(true);

      $_SESSION['admin_id'] = (int)$u['id'];
      $_SESSION['admin_username'] = $u['username'];
      $_SESSION['admin_role_name'] = $role_name;
      $_SESSION['admin_permissions'] = $permissions; // পারমিশন অ্যারে সেশনে রাখা হলো

      // --- নতুন রিডাইরেক্ট লজিক ---
      $first_page = get_first_allowed_page();

      if ($first_page) {
          // open redirect প্রতিরোধ: শুধু লোকাল /admin/... পাথ অনুমোদিত
          $next = $_POST['next'] ?? $_GET['next'] ?? '';
          if (!is_string($next) || $next === '' || preg_match('~^(https?:)?//~i', $next) || strpos($next, '/') !== 0) {
              $next = $first_page;
          }
          header('Location: ' . $next);
          exit;
      } else {
          // কোনো পেজেই অ্যাক্সেস না থাকলে লগআউট করে দিন
          session_destroy();
          $error = 'আপনার কোনো পেজ দেখার অনুমতি নেই।';
      }
    } elseif (!$lsLock) {
      $lsF = ls_on_fail($pdo, $lsIp, $username, $lsCfg);
      if ($lsF['locked']) {
        $error = 'অনেকবার ভুল চেষ্টা হয়েছে। ' . ls_wait_text($lsF['until']) . ' পরে আবার চেষ্টা করুন।';
      } else {
        $error = 'ইউজারনেম/পাসওয়ার্ড মিলছে না';
        if (!empty($lsCfg['show_remaining']) && $lsF['remaining'] !== null) $error .= ' (আর ' . $lsF['remaining'] . ' বার চেষ্টা করতে পারবেন)';
      }
    }
  } else { $error = 'সব ফিল্ড পূরণ করুন'; }
}
?>
<!doctype html><html lang="bn"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Admin Login - <?php echo APP_NAME; ?></title>
  <?php
    $loginFavicon = '';
    try {
      $stFav = get_pdo()->prepare("SELECT value FROM settings WHERE `key`='store_favicon' LIMIT 1");
      $stFav->execute();
      $loginFavicon = img_url((string)$stFav->fetchColumn(), '');
    } catch (Throwable $e) { $loginFavicon = ''; }
    if ($loginFavicon === '') $loginFavicon = BASE_URL . '/assets/favicon.ico';
  ?>
  <link rel="icon" href="<?php echo htmlspecialchars($loginFavicon, ENT_QUOTES, 'UTF-8'); ?>">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center" style="min-height:100vh">
<div class="container">
  <div class="row justify-content-center">
    <div class="col-12 col-sm-10 col-md-6 col-lg-4">
      <div class="card shadow-sm">
        <div class="card-body">
          <h4 class="mb-3 text-center">Admin Login</h4>
          <?php if($error): ?><div class="alert alert-danger py-2"><?php echo h($error); ?></div><?php endif; ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
            <input type="hidden" name="next" value="<?php echo h($_GET['next'] ?? ''); ?>">
            <div class="mb-3"><label class="form-label">Username</label><input name="username" class="form-control" required></div>
            <div class="mb-3"><label class="form-label">Password</label><input type="password" name="password" class="form-control" required></div>
            <button class="btn btn-primary w-100">Login</button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body></html>
