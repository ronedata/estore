<?php require_once __DIR__.'/includes/config.php'; header('Content-Type: application/json');
$c = 0; if(!empty($_SESSION['cart'])) foreach($_SESSION['cart'] as $q){ $c += (int)$q; }
echo json_encode(['count'=>$c]);
