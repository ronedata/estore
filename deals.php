<?php
// deals.php — highest discount products
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/functions.php';
increment_page_view('deals');

$pdo = get_pdo();
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 24;

$soldSub = "(SELECT COALESCE(SUM(oi.qty),0) FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE oi.product_id = p.id AND o.status='delivered')";

$total = (int)$pdo->query("SELECT COUNT(*) FROM products p WHERE p.active=1 AND p.compare_at_price > p.price")->fetchColumn();
$pages  = max(1, (int)ceil($total / $perPage));
$page   = min($page, $pages);
$offset = ($page - 1) * $perPage;

$stm = $pdo->query("
  SELECT p.*, c.name AS cat_name, $soldSub AS sold_dynamic,
         ROUND(((p.compare_at_price - p.price) / p.compare_at_price) * 100) AS discount_pct
  FROM products p
  LEFT JOIN categories c ON c.id = p.category_id
  WHERE p.active = 1 AND p.compare_at_price > p.price
  ORDER BY discount_pct DESC, p.id DESC
  LIMIT $perPage OFFSET $offset
");
$products = $stm->fetchAll();

foreach ($products as &$p) {
  $p['sold_count'] = max((int)($p['sold_count'] ?? 0), (int)($p['sold_dynamic'] ?? 0));
}
unset($p);

include __DIR__.'/includes/partials_header.php';
?>
<div class="wrap">
  <div class="page-title-bar" style="margin-top:8px">
    <div>
      <h2><i class="fa-solid fa-fire" style="color:#ff7675"></i> হট ডিলস</h2>
      <p>সর্বোচ্চ ছাড়ের পণ্য — <?php echo $total; ?> টি</p>
    </div>
  </div>

  <?php if (!$products): ?>
    <div class="empty-state">
      <i class="fa-solid fa-tags"></i>
      <h3>এখন কোনো ডিল নেই</h3>
      <p>শীঘ্রই নতুন অফার আসছে।</p>
      <a class="btn-pill" href="<?php echo BASE_URL; ?>/shop.php">সব প্রোডাক্ট দেখুন</a>
    </div>
  <?php else: ?>
    <div class="product-grid">
      <?php foreach ($products as $i => $card): ?>
        <?php include __DIR__.'/includes/partials_product_card.php'; ?>
      <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
      <nav class="pagination">
        <?php if ($page > 1): ?><a href="?page=<?php echo $page-1; ?>">‹</a><?php endif; ?>
        <?php for ($p = max(1,$page-2); $p <= min($pages,$page+2); $p++): ?>
          <?php echo $p === $page ? '<span class="current">'.$p.'</span>' : '<a href="?page='.$p.'">'.$p.'</a>'; ?>
        <?php endfor; ?>
        <?php if ($page < $pages): ?><a href="?page=<?php echo $page+1; ?>">›</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php include __DIR__.'/includes/partials_footer.php'; ?>
