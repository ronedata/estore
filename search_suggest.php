<?php
require_once __DIR__.'/includes/config.php';
header('Content-Type: application/json; charset=UTF-8');

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) { echo json_encode([]); exit; }

$pdo = get_pdo();
/* LIKE সার্চ (প্রয়োজনে FULLTEXT ব্যবহার করতে পারেন) */
$sql = "SELECT id, name, price, image
        FROM products
        WHERE active=1 AND (name LIKE ? OR description LIKE ?)
        ORDER BY id DESC LIMIT 8";
$like = '%'.$q.'%';
$stm  = $pdo->prepare($sql);
$stm->execute([$like, $like]);
$rows = $stm->fetchAll();

echo json_encode(array_map(function($r){
  return [
    'id'    => (int)$r['id'],
    'name'  => $r['name'],
    'price' => (float)$r['price'],
    'image' => img_url($r['image'] ?? ''),
  ];
}, $rows));
