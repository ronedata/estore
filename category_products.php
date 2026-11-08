<?php
// category_products.php — products of one category with pagination
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/functions.php';

$pdo = get_pdo();
$cat  = (int)($_GET['id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$cs = $pdo->prepare("SELECT id, name, image FROM categories WHERE id=?");
$cs->execute([$cat]);
$category = $cs->fetch();

if (!$category) {
  http_response_code(404);
  include __DIR__.'/includes/partials_header.php';
  echo '<div class="wrap"><div class="empty-state"><i class="fa-solid fa-triangle-exclamation"></i>'
     . '<h3>ক্যাটেগরি পাওয়া যায়নি</h3><p>লিংকটি সঠিক নয়।</p>'
     . '<a class="btn-pill" href="'.BASE_URL.'/shop.php">সব প্রোডাক্ট</a></div></div>';
  include __DIR__.'/includes/partials_footer.php';
  exit;
}

increment_page_view('category_'.$cat);

$soldSub = "(SELECT COALESCE(SUM(oi.qty),0) FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE oi.product_id = p.id AND o.status='delivered')";

$c = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE p.active=1 AND p.category_id=?");
$c->execute([$cat]);
$total = (int)$c->fetchColumn();

$pages  = max(1, (int)ceil($total / $perPage));
$page   = min($page, $pages);
$offset = ($page - 1) * $perPage;

$stm = $pdo->prepare("
  SELECT p.*, c.name AS cat_name, $soldSub AS sold_dynamic
  FROM products p
  LEFT JOIN categories c ON c.id = p.category_id
  WHERE p.active = 1 AND p.category_id = ?
  ORDER BY p.id DESC
  LIMIT $perPage OFFSET $offset
");
$stm->execute([$cat]);
$products = $stm->fetchAll();

foreach ($products as &$p) {
  $p['sold_count'] = max((int)($p['sold_count'] ?? 0), (int)($p['sold_dynamic'] ?? 0));
}
unset($p);

include __DIR__.'/includes/partials_header.php';
?>
<div class="wrap">
  <div class="page-title-bar" style="margin-top:8px">
    <div style="display:flex;align-items:center;gap:12px">
      <?php if (!empty($category['image'])): ?>
        <img src="<?php echo h(img_url($category['image'])); ?>" alt=""
             style="width:52px;height:52px;border-radius:50%;object-fit:cover" loading="lazy"
             onerror="this.onerror=null;this.src='<?php echo h(img_fallback_url()); ?>'">
      <?php endif; ?>
      <div>
        <h2><?php echo h($category['name']); ?></h2>
        <p>মোট <?php echo $total; ?> টি পণ্য</p>
      </div>
    </div>
    <a class="see-all" href="<?php echo BASE_URL; ?>/shop.php">সব প্রোডাক্ট <i class="fa-solid fa-arrow-right"></i></a>
  </div>

  <?php if (!$products): ?>
    <div class="empty-state">
      <i class="fa-solid fa-box-open"></i>
      <h3>এই ক্যাটেগরিতে এখনো পণ্য নেই</h3>
      <a class="btn-pill" href="<?php echo BASE_URL; ?>/shop.php">অন্য প্রোডাক্ট দেখুন</a>
    </div>
  <?php else: ?>
    <div class="product-grid">
      <?php foreach ($products as $i => $card): ?>
        <?php include __DIR__.'/includes/partials_product_card.php'; ?>
      <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
      <nav class="pagination">
        <?php if ($page > 1): ?><a href="?id=<?php echo $cat; ?>&page=<?php echo $page-1; ?>">‹</a><?php endif; ?>
        <?php for ($p = max(1,$page-2); $p <= min($pages,$page+2); $p++): ?>
          <?php echo $p === $page ? '<span class="current">'.$p.'</span>' : '<a href="?id='.$cat.'&page='.$p.'">'.$p.'</a>'; ?>
        <?php endfor; ?>
        <?php if ($page < $pages): ?><a href="?id=<?php echo $cat; ?>&page=<?php echo $page+1; ?>">›</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php include __DIR__.'/includes/partials_footer.php'; ?>
