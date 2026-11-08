<?php
// shop.php — all products with search, category filter, sort, pagination
require_once __DIR__.'/includes/config.php';
require_once __DIR__.'/includes/functions.php';
require_once __DIR__.'/includes/home_sections.php';
increment_page_view('shop');

$pdo = get_pdo();

$q        = trim($_GET['q'] ?? '');
$cat      = (int)($_GET['cat'] ?? 0);
$sort     = $_GET['sort'] ?? 'new';
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 20;
$filter   = ($_GET['filter'] ?? '') === 'featured' ? 'featured' : '';
$sectionId = is_scalar($_GET['section'] ?? null) ? (int)$_GET['section'] : 0;

/* হোম সেকশন: ?section=ID (সব দেখুন)। পুরোনো ?filter=featured লিংক → "Featured…" নামের সেকশনে */
$secRow = null;
try {
  if ($sectionId > 0) {
    $secRow = hs_get($pdo, $sectionId);
    if ($secRow && !(int)$secRow['active']) $secRow = null;
  } elseif ($filter === 'featured') {
    $secRow = $pdo->query("SELECT * FROM home_sections WHERE active=1 AND LOWER(title) LIKE 'featured%' ORDER BY sort_order, id LIMIT 1")->fetch() ?: null;
  }
} catch (Throwable $e) { $secRow = null; }

$secJoin = '';
$secOrder = null;
$where  = ['p.active = 1'];
$params = [];
if ($secRow) {
  [$secJoin, $secWhere, $secOrderSql, $secParams] = hs_query_parts($secRow, true);
  $where[] = $secWhere;
  $params += $secParams;
  if (!isset($_GET['sort'])) $secOrder = $secOrderSql;
}

if ($q !== '') {
  $where[] = '(p.name LIKE :q OR p.description LIKE :q)';
  $params[':q'] = '%'.$q.'%';
}
if ($cat > 0) {
  $where[] = 'p.category_id = :cat';
  $params[':cat'] = $cat;
}
if ($filter === 'featured' && !$secRow) {
  $where[] = 'p.is_featured = 1';
}
$whereSql = implode(' AND ', $where);

$orderSql = match ($sort) {
  'price_asc'  => 'p.price ASC',
  'price_desc' => 'p.price DESC',
  'discount'   => '((p.compare_at_price - p.price) / NULLIF(p.compare_at_price,0)) DESC',
  default      => $secOrder ?? 'p.id DESC',
};

$soldSub = "(SELECT COALESCE(SUM(oi.qty),0) FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE oi.product_id = p.id AND o.status='delivered')";

$countStm = $pdo->prepare("SELECT COUNT(*) FROM products p $secJoin WHERE $whereSql");
$countStm->execute($params);
$total = (int)$countStm->fetchColumn();

$pages = max(1, (int)ceil($total / $perPage));
$page  = min($page, $pages);
$offset = ($page - 1) * $perPage;

$stm = $pdo->prepare("
  SELECT p.*, c.name AS cat_name, $soldSub AS sold_dynamic
  FROM products p
  $secJoin
  LEFT JOIN categories c ON c.id = p.category_id
  WHERE $whereSql
  ORDER BY $orderSql
  LIMIT $perPage OFFSET $offset
");
$stm->execute($params);
$products = $stm->fetchAll();

foreach ($products as &$p) {
  $p['sold_count'] = max((int)($p['sold_count'] ?? 0), (int)($p['sold_dynamic'] ?? 0));
}
unset($p);

$catRow = null;
if ($cat > 0) {
  $cs = $pdo->prepare("SELECT id,name FROM categories WHERE id=?");
  $cs->execute([$cat]);
  $catRow = $cs->fetch() ?: null;
}

$allCats = [];
try { $allCats = $pdo->query("SELECT id,name FROM categories WHERE active=1 ORDER BY sort_order,name")->fetchAll(); }
catch (Throwable $e) { try { $allCats = $pdo->query("SELECT id,name FROM categories ORDER BY name")->fetchAll(); } catch (Throwable $e2) {} }

$pageTitle = $catRow ? $catRow['name']
  : ($q !== '' ? 'সার্চ: '.$q
  : ($secRow ? $secRow['title']
  : ($filter === 'featured' ? 'Featured Products' : 'সব প্রোডাক্ট')));

include __DIR__.'/includes/partials_header.php';
?>

<div class="wrap">
  <div class="page-title-bar" style="margin-top:8px">
    <div>
      <h2><?php echo h($pageTitle); ?></h2>
      <p><?php echo $total; ?> টি পণ্য পাওয়া গেছে</p>
    </div>
  </div>

  <div class="shop-toolbar">
    <form method="get" action="shop.php" style="display:flex;gap:8px;flex-wrap:wrap;flex:1;min-width:220px">
      <?php if ($q !== ''): ?><input type="hidden" name="q" value="<?php echo h($q); ?>"><?php endif; ?>
      <?php if ($filter === 'featured'): ?><input type="hidden" name="filter" value="featured"><?php endif; ?>
      <?php if ($secRow): ?><input type="hidden" name="section" value="<?php echo (int)$secRow['id']; ?>"><?php endif; ?>
      <select name="cat" onchange="this.form.submit()" aria-label="ক্যাটেগরি">
        <option value="0">সব ক্যাটেগরি</option>
        <?php foreach ($allCats as $c): ?>
          <option value="<?php echo (int)$c['id']; ?>" <?php echo $cat === (int)$c['id'] ? 'selected' : ''; ?>>
            <?php echo h($c['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select name="sort" onchange="this.form.submit()" aria-label="সাজান">
        <option value="new"        <?php echo $sort==='new'?'selected':''; ?>>নতুন আগে</option>
        <option value="price_asc"  <?php echo $sort==='price_asc'?'selected':''; ?>>কম দাম আগে</option>
        <option value="price_desc" <?php echo $sort==='price_desc'?'selected':''; ?>>বেশি দাম আগে</option>
        <option value="discount"   <?php echo $sort==='discount'?'selected':''; ?>>সর্বোচ্চ ছাড়</option>
      </select>

      <noscript><button class="btn-pill">ফিল্টার</button></noscript>
    </form>
  </div>

  <?php if (!$products): ?>
    <div class="empty-state">
      <i class="fa-solid fa-magnifying-glass"></i>
      <h3>কোনো পণ্য পাওয়া যায়নি</h3>
      <p>অন্য কিছু খুঁজে দেখুন অথবা ফিল্টার পরিবর্তন করুন।</p>
      <a class="btn-pill" href="<?php echo BASE_URL; ?>/shop.php">সব প্রোডাক্ট দেখুন</a>
    </div>
  <?php else: ?>
    <div class="product-grid">
      <?php foreach ($products as $i => $card): ?>
        <?php include __DIR__.'/includes/partials_product_card.php'; ?>
      <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
      <nav class="pagination" aria-label="পেজিনেশন">
        <?php
        $qsBase = ['q'=>$q, 'cat'=>$cat ?: '', 'sort'=>$sort, 'filter'=>$filter, 'section'=>$secRow ? (int)$secRow['id'] : ''];
        $mkUrl = function ($p) use ($qsBase) {
          $qs = array_filter($qsBase, fn($v) => $v !== '' && $v !== 0 && $v !== '0');
          $qs['page'] = $p;
          return 'shop.php?'.http_build_query($qs);
        };
        ?>
        <?php if ($page > 1): ?><a href="<?php echo h($mkUrl($page-1)); ?>" aria-label="আগের পেজ">‹</a><?php endif; ?>

        <?php
        $start = max(1, $page - 2);
        $end   = min($pages, $page + 2);
        if ($start > 1) { echo '<a href="'.h($mkUrl(1)).'">1</a>'; if ($start > 2) echo '<span>…</span>'; }
        for ($p = $start; $p <= $end; $p++) {
          echo $p === $page
            ? '<span class="current">'.$p.'</span>'
            : '<a href="'.h($mkUrl($p)).'">'.$p.'</a>';
        }
        if ($end < $pages) { if ($end < $pages - 1) echo '<span>…</span>'; echo '<a href="'.h($mkUrl($pages)).'">'.$pages.'</a>'; }
        ?>

        <?php if ($page < $pages): ?><a href="<?php echo h($mkUrl($page+1)); ?>" aria-label="পরের পেজ">›</a><?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php include __DIR__.'/includes/partials_footer.php'; ?>
