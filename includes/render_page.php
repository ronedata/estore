<?php
// includes/render_page.php — renders a static page from the `pages` table (mFeex style)
require_once __DIR__.'/config.php';

/**
 * স্ট্যাটিক পেজ রেন্ডার করে।
 *
 * @param string $slug          pages.slug
 * @param string $fallbackTitle হিরো শিরোনাম (pages.title না থাকলে)
 * @param string $fallbackBody  fallback HTML (pages.content না থাকলে)
 * @param array  $opts          hero_sub, hero_tagline, narrow(bool), show_back(bool)
 */
function render_static_page(string $slug, string $fallbackTitle, string $fallbackBody, array $opts = []): void {
  $pdo = get_pdo();

  /* স্টোর সেটিংস — কনটেন্টের প্লেসহোল্ডার রেন্ডার-টাইমে রিপ্লেস হবে */
  $storeName = setting('store_name', APP_NAME) ?: APP_NAME;

  $placeholders = [
    '{STORE}'        => $storeName,
    '{{store_name}}' => $storeName,
    '{STORE_NAME}'   => $storeName,

    '{PHONE}'        => (string)setting('store_phone', ''),
    '{{store_phone}}'=> (string)setting('store_phone', ''),

    '{EMAIL}'        => (string)setting('store_email', ''),
    '{{store_email}}'=> (string)setting('store_email', ''),

    '{ADDRESS}'      => (string)setting('store_address', ''),
    '{{store_address}}' => (string)setting('store_address', ''),

    '{YEAR}'         => date('Y'),
  ];

  $page = null;
  try {
    $stm = $pdo->prepare("SELECT * FROM pages WHERE slug=? AND active=1 LIMIT 1");
    $stm->execute([$slug]);
    $page = $stm->fetch();
  } catch (Throwable $e) {}

  $title   = trim((string)($page['title'] ?? '')) ?: $fallbackTitle;
  $content = trim((string)($page['content'] ?? '')) !== '' ? (string)$page['content'] : $fallbackBody;

  /* প্লেসহোল্ডার রিপ্লেস (title + content + hero sub, তিনটাতেই) */
  $title   = strtr($title, $placeholders);
  $content = strtr($content, $placeholders);

  /* hero sub: opts > pages.meta_description > ফাঁকা */
  $heroSub  = $opts['hero_sub'] ?? '';
  if ($heroSub === '') $heroSub = trim((string)($page['meta_description'] ?? ''));
  $heroSub  = strtr($heroSub, $placeholders);

  $narrow   = $opts['narrow']       ?? true;
  $showBack = $opts['show_back']    ?? true;

  include __DIR__.'/partials_header.php';
  ?>
  <section class="pg-hero">
    <h1><?php echo h($title); ?></h1>
    <?php if ($heroSub !== ''): ?><p><?php echo h($heroSub); ?></p><?php endif; ?>
  </section>

  <div class="pg-wrap<?php echo $narrow ? ' pg-narrow' : ''; ?>">
    <div class="pg-card">
      <?php
      /* বেসিক HTML অনুমোদিত; script/iframe/on* সব সরিয়ে ফেলা হয় */
      $safe = strip_tags($content, '<p><br><b><strong><i><em><u><h2><h3><h4><ul><ol><li><a><img><table><thead><tbody><tr><th><td><th><blockquote><span><div><hr>');
      $safe = preg_replace('/\son\w+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $safe);
      $safe = preg_replace('/\s(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2/i', ' $1="#"', $safe);
      echo $safe;
      ?>
    </div>

    <?php if ($showBack): ?>
      <div class="pg-back">
        <a class="btn-pill ghost" href="<?php echo BASE_URL; ?>/index.php">
          <i class="fa-solid fa-arrow-left"></i> হোম পেজে ফিরে যান
        </a>
      </div>
    <?php endif; ?>
  </div>
  <?php
  include __DIR__.'/partials_footer.php';
}
