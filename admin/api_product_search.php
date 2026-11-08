<?php
// admin/api_product_search.php
require_once __DIR__.'/../includes/config.php';
require_once __DIR__ . '/auth.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Basic permission check
gate('manage_orders');

$q = trim($_GET['q'] ?? '');

if (strlen($q) < 2) {
  echo json_encode([]);
  exit;
}

$pdo = get_pdo();
$sql = "SELECT id, name, price
        FROM products
        WHERE name LIKE ? AND active = 1
        ORDER BY name
        LIMIT 10";
$stm = $pdo->prepare($sql);
$stm->execute(["%$q%"]);
$products = $stm->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($products);