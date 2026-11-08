<?php
require_once __DIR__.'/../includes/config.php';

/* অ্যাডমিন সেশন সম্পূর্ণ মুছে ফেলা (কাস্টমার সেশন থাকলে তা অটুট) */
foreach ([
  'admin_id', 'admin_username', 'admin_role_name', 'admin_permissions'
] as $k) {
  unset($_SESSION[$k]);
}

/* অ্যাডমিন সেশন কুকি মুছে দিন */
if (ini_get('session.use_cookies')) {
  $p = session_get_cookie_params();
  setcookie(session_name(), '', time() - 42000,
    $p['path'], $p['domain'], !empty($p['secure']), !empty($p['httponly']));
}

/* কাস্টমার সেশন না থাকলে পুরো সেশন ধ্বংস */
if (empty($_SESSION['customer_id'])) {
  $_SESSION = [];
  session_destroy();
} else {
  session_regenerate_id(true);
}

header('Location: '.BASE_URL.'/admin/login.php');
exit;
