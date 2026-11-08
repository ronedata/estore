<?php
// account.php — customer login / registration
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/customer_auth.php';

if (current_customer()) { header('Location: '.BASE_URL.'/account_dashboard.php'); exit; }

$pdo = get_pdo();
if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(16)); }

$errors  = [];
$mode    = $_POST['mode'] ?? 'login';
$nextUrl = $_GET['next'] ?? ($_POST['next'] ?? '');
if (!is_string($nextUrl) || preg_match('~^(https?:)?//~i', $nextUrl)) $nextUrl = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!csrf_verify()) {
    $errors[] = 'সেশন মেয়াদোত্তীর্ণ। আবার চেষ্টা করুন।';
  } else {
    $mobile = normalize_mobile((string)($_POST['mobile'] ?? ''));
    $pass   = (string)($_POST['password'] ?? '');

    if (!$mobile) $errors[] = 'বৈধ মোবাইল নম্বর দিন (যেমন 017XXXXXXXX)।';

    if (!$errors && $mode === 'login') {
      $stm = $pdo->prepare("SELECT * FROM customers WHERE mobile=? LIMIT 1");
      $stm->execute([$mobile]);
      $cust = $stm->fetch();

      if (!$cust || !password_verify($pass, $cust['password_hash'])) {
        $errors[] = 'মোবাইল নম্বর বা পাসওয়ার্ড ভুল।';
      } elseif (!(int)$cust['active']) {
        $errors[] = 'আপনার অ্যাকাউন্ট বন্ধ আছে। যোগাযোগ করুন।';
      } else {
        session_regenerate_id(true);
        $_SESSION['customer_id']   = (int)$cust['id'];
        $_SESSION['customer_name'] = $cust['name'] ?: $cust['mobile'];
        header('Location: '.($nextUrl !== '' ? $nextUrl : BASE_URL.'/account_dashboard.php'));
        exit;
      }
    }

    if (!$errors && $mode === 'register') {
      $name = trim((string)($_POST['name'] ?? ''));
      if (strlen($pass) < 6) $errors[] = 'পাসওয়ার্ড কমপক্ষে ৬ অক্ষরের হতে হবে।';
      if (mb_strlen($name) > 120) $errors[] = 'নাম অনেক বড়।';

      if (!$errors) {
        $chk = $pdo->prepare("SELECT id FROM customers WHERE mobile=?");
        $chk->execute([$mobile]);
        if ($chk->fetchColumn()) {
          $errors[] = 'এই মোবাইল নম্বর দিয়ে ইতিমধ্যে অ্যাকাউন্ট আছে। লগইন করুন।';
          $mode = 'login';
        } else {
          $hash = password_hash($pass, PASSWORD_DEFAULT);
          $ins = $pdo->prepare("INSERT INTO customers(mobile, password_hash, name) VALUES(?,?,?)");
          $ins->execute([$mobile, $hash, $name !== '' ? $name : null]);
          $cid = (int)$pdo->lastInsertId();

          session_regenerate_id(true);
          $_SESSION['customer_id']   = $cid;
          $_SESSION['customer_name'] = $name !== '' ? $name : $mobile;
          header('Location: '.($nextUrl !== '' ? $nextUrl : BASE_URL.'/account_dashboard.php'));
          exit;
        }
      }
    }
  }
}

include __DIR__.'/includes/partials_header.php';
?>
<div class="wrap" style="max-width:460px">
  <div style="text-align:center;margin:20px 0 22px">
    <div style="width:66px;height:66px;border-radius:50%;background:#f0edff;color:var(--primary-purple);display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:26px">
      <i class="fa-solid fa-user"></i>
    </div>
    <h2 style="font-size:1.15rem;font-weight:800">আমার অ্যাকাউন্ট</h2>
    <p style="font-size:.82rem;color:var(--text-muted);margin-top:5px">
      অর্ডার ট্র্যাক করতে ও আগের অর্ডার দেখতে লগইন করুন।
    </p>
  </div>

  <?php if ($errors): ?>
    <div class="notice err">
      <ul style="margin:0;padding-left:18px">
        <?php foreach ($errors as $e): ?><li><?php echo h($e); ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="card-box">
    <div style="display:flex;gap:6px;margin-bottom:16px">
      <button type="button" class="btn-pill" id="tabLogin" style="flex:1">লগইন</button>
      <button type="button" class="btn-pill ghost" id="tabRegister" style="flex:1">রেজিস্ট্রেশন</button>
    </div>

    <!-- Login -->
    <form method="post" id="formLogin" <?php echo $mode === 'register' ? 'style="display:none"' : ''; ?>>
      <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
      <input type="hidden" name="mode" value="login">
      <input type="hidden" name="next" value="<?php echo h($nextUrl); ?>">

      <div class="field">
        <label for="lmobile">মোবাইল নম্বর</label>
        <input id="lmobile" name="mobile" type="tel" inputmode="numeric" autocomplete="tel"
               placeholder="01XXXXXXXXX" required
               value="<?php echo h($_POST['mode'] ?? '') === 'login' ? h($_POST['mobile'] ?? '') : ''; ?>">
      </div>
      <div class="field">
        <label for="lpass">পাসওয়ার্ড</label>
        <input id="lpass" name="password" type="password" autocomplete="current-password" required>
      </div>

      <button class="btn-pill wide" type="submit"><i class="fa-solid fa-right-to-bracket"></i> লগইন</button>
    </form>

    <!-- Register -->
    <form method="post" id="formRegister" <?php echo $mode === 'register' ? '' : 'style="display:none"'; ?>>
      <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf']); ?>">
      <input type="hidden" name="mode" value="register">
      <input type="hidden" name="next" value="<?php echo h($nextUrl); ?>">

      <div class="field">
        <label for="rmobile">মোবাইল নম্বর</label>
        <input id="rmobile" name="mobile" type="tel" inputmode="numeric" autocomplete="tel"
               placeholder="01XXXXXXXXX" required
               value="<?php echo h($_POST['mode'] ?? '') === 'register' ? h($_POST['mobile'] ?? '') : ''; ?>">
      </div>
      <div class="field">
        <label for="rname">নাম (ঐচ্ছিক)</label>
        <input id="rname" name="name" autocomplete="name" maxlength="120"
               value="<?php echo h($_POST['mode'] ?? '') === 'register' ? h($_POST['name'] ?? '') : ''; ?>">
      </div>
      <div class="field">
        <label for="rpass">পাসওয়ার্ড</label>
        <input id="rpass" name="password" type="password" autocomplete="new-password" minlength="6" required>
        <div class="help">কমপক্ষে ৬ অক্ষর</div>
      </div>

      <button class="btn-pill wide" type="submit"><i class="fa-solid fa-user-plus"></i> রেজিস্ট্রেশন করুন</button>
    </form>
  </div>

  <p style="text-align:center;font-size:.8rem;color:var(--text-muted);margin-bottom:20px">
    অ্যাকাউন্ট ছাড়াও অর্ডার করতে পারবেন —
    <a href="<?php echo BASE_URL; ?>/track_orders.php" style="color:var(--primary-purple);font-weight:700">মোবাইল দিয়ে ট্র্যাক করুন</a>
  </p>
</div>

<script>
(function(){
  var btnL = document.getElementById('tabLogin');
  var btnR = document.getElementById('tabRegister');
  var fL = document.getElementById('formLogin');
  var fR = document.getElementById('formRegister');
  function show(which){
    var login = which === 'login';
    fL.style.display = login ? '' : 'none';
    fR.style.display = login ? 'none' : '';
    btnL.className = 'btn-pill' + (login ? '' : ' ghost');
    btnR.className = 'btn-pill' + (login ? ' ghost' : '');
  }
  btnL.addEventListener('click', function(){ show('login'); });
  btnR.addEventListener('click', function(){ show('register'); });
})();
</script>

<?php include __DIR__.'/includes/partials_footer.php'; ?>
