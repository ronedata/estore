<?php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/auth_functions.php'; // ফাংশনগুলো এখানে লোড হবে
if (empty($_SESSION['admin_id'])) {
  $next = urlencode($_SERVER['REQUEST_URI'] ?? (BASE_URL.'/admin/index.php'));
  header('Location: '. (BASE_URL.'/admin/login.php?next='.$next));
  exit;
}

/**
 * একটি পেজকে পারমিশন দিয়ে সুরক্ষিত করে। পারমিশন না থাকলে ড্যাশবোর্ডে রিডাইরেক্ট করে।
 * @param string $permission
 */
function gate(string $permission): void {
  if (!can($permission)) {
    $_SESSION['flash'] = ['ok' => false, 'msg' => 'আপনার এই পেজটি দেখার অনুমতি নেই।'];
    header('Location: ' . BASE_URL . '/admin/index.php');
    exit;
  }
}
?>
