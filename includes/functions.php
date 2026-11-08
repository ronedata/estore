<?php

function get_products($pdo, $qParam, $catParam, $initialLimit) {
    $initialLimit = (int)$initialLimit;
    $where    = ["p.active = 1"];
    $params   = [];

    if ($qParam !== '') {
        $where[] = "(p.name LIKE :q OR p.description LIKE :q)";
        $params[':q'] = '%' . $qParam . '%';
    }
    if ($catParam !== '' && (int)$catParam > 0) {
        $where[] = "p.category_id = :cat";
        $params[':cat'] = (int)$catParam;
    }

    $whereSql = implode(' AND ', $where);

    $stmC = $pdo->prepare("SELECT COUNT(*) c FROM products p WHERE $whereSql");
    $stmC->execute($params);
    $total = (int)$stmC->fetch()['c'];

    $stm = $pdo->prepare("
      SELECT p.*, c.name AS cat_name
      FROM products p
      LEFT JOIN categories c ON c.id = p.category_id
      WHERE $whereSql
      ORDER BY p.id DESC
      LIMIT $initialLimit
    ");
    $stm->execute($params);
    $products = $stm->fetchAll();

    return [$products, $total];
}
