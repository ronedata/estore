<?php
// admin/courier_action.php — একটি অর্ডার কুরিয়ারে পাঠানো / স্ট্যাটাস মেলানো (POST + CSRF)
require_once __DIR__.'/../includes/config.php';
require_once __DIR__.'/../includes/steadfast.php';
require_once __DIR__.'/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
gate('manage_courier');

$pdo = get_pdo();
$id = is_string($_POST['id'] ?? null) && ctype_digit($_POST['id']) ? (int)$_POST['id'] : 0;
$back = $id > 0 ? 'order_view.php?id=' . $id : 'orders.php';
$flash = function (bool $ok, string $msg) { $_SESSION['flash'] = ['ok' => $ok, 'msg' => $msg]; };

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: orders.php'); exit; }
if (!csrf_verify()) { $flash(false, 'সিকিউরিটি টোকেন মিলেনি'); header('Location: ' . $back); exit; }
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$by = (string)($_SESSION['admin_username'] ?? '');

if ($id <= 0) { $flash(false, 'অর্ডার পাওয়া যায়নি।'); header('Location: orders.php'); exit; }
if ($action === 'send') { [$ok, $msg] = sf_send_order($pdo, $id, $by); $flash($ok, $msg); }
elseif ($action === 'sync') { [$ok, $msg] = sf_sync_order($pdo, $id, $by); $flash($ok, $msg); }
else { $flash(false, 'অজানা অ্যাকশন।'); }
header('Location: ' . $back); exit;
