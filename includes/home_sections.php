<?php
/**
 * includes/home_sections.php — হোমপেজ সেকশনের helper (index, shop, admin সবাই ব্যবহার করে)
 *
 * নিয়ম: সর্বোচ্চ HS_MAX টি সেকশন, অন্তত HS_MIN টি থাকতেই হবে (মোছা/বন্ধ আটকানো হয়)।
 * সব লেখা (create/update/delete/move) ট্রানজাকশনে, সীমা যাচাই ট্রানজাকশনের ভেতরে।
 */

if (!defined('HS_MAX')) {
  define('HS_MAX', 8);
  define('HS_MIN', 2);
  define('HS_LIMIT_MIN', 1);
  define('HS_LIMIT_MAX', 48);
}

if (!function_exists('hs_types')) {

  /** type => [লেবেল, বর্ণনা] */
  function hs_types(): array {
    return [
      'manual'     => ['হাতে বাছাই',     'প্রোডাক্ট ফর্ম থেকে টিক দিয়ে পণ্য বাছাই'],
      'category'   => ['ক্যাটেগরি',      'একটি ক্যাটেগরির পণ্য নিজে থেকেই আসবে'],
      'discount'   => ['সর্বোচ্চ ছাড়',   'সবচেয়ে বেশি ছাড়ের পণ্য নিজে থেকেই আসবে'],
      'latest'     => ['সর্বশেষ পণ্য',    'সবশেষে যোগ হওয়া পণ্য নিজে থেকেই আসবে'],
      'bestseller' => ['বেস্ট সেলার',     'সবচেয়ে বেশি বিক্রি হওয়া পণ্য নিজে থেকেই আসবে'],
    ];
  }

  function hs_sold_sub(): string {
    return "(SELECT COALESCE(SUM(oi.qty),0) FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE oi.product_id = p.id AND o.status = 'delivered')";
  }

  /* ---------- পড়া ---------- */

  function hs_all(PDO $pdo, bool $onlyActive = false): array {
    $sql = "SELECT s.*, (SELECT COUNT(*) FROM home_section_products sp WHERE sp.section_id = s.id) AS manual_count
            FROM home_sections s " . ($onlyActive ? "WHERE s.active = 1 " : "") . "ORDER BY s.sort_order ASC, s.id ASC";
    return $pdo->query($sql)->fetchAll();
  }

  function hs_get(PDO $pdo, int $id): ?array {
    if ($id <= 0) return null;
    $st = $pdo->prepare("SELECT * FROM home_sections WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
  }

  /** manual + active সেকশন (প্রোডাক্ট ফর্মের টগল তালিকা) */
  function hs_manual_sections(PDO $pdo): array {
    return $pdo->query("SELECT id, title, subtitle, new_default FROM home_sections
                        WHERE type = 'manual' AND active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();
  }

  /** একটি পণ্য এখন কোন কোন সেকশনে আছে → section_id এর তালিকা */
  function hs_product_section_ids(PDO $pdo, int $productId): array {
    $st = $pdo->prepare("SELECT section_id FROM home_section_products WHERE product_id = ?");
    $st->execute([$productId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
  }

  /**
   * সেকশনের পণ্য-কুয়েরির অংশ: [joinSql, whereSql, orderSql, params]
   * (index ও shop.php দুজনেই ব্যবহার করে)
   */
  function hs_query_parts(array $sec, bool $named = false): array {
    $type = (string)($sec['type'] ?? 'manual');
    $join = '';
    $where = 'p.active = 1';
    $params = [];
    // $named=true হলে :hs_cat / :hs_sid (যে কুয়েরিতে আগে থেকেই named প্যারামিটার আছে, যেমন shop.php)
    $ph = fn(string $n) => $named ? ':' . $n : '?';
    $put = function (string $n, $v) use (&$params, $named) { if ($named) $params[':' . $n] = $v; else $params[] = $v; };

    switch ($type) {
      case 'category':
        $where .= ' AND p.category_id = ' . $ph('hs_cat');
        $put('hs_cat', (int)($sec['category_id'] ?? 0));
        $order = 'p.id DESC';
        break;
      case 'discount':
        $where .= ' AND p.compare_at_price > p.price AND p.compare_at_price > 0';
        $order = '((p.compare_at_price - p.price) / p.compare_at_price) DESC, p.id DESC';
        break;
      case 'latest':
        $order = 'p.id DESC';
        break;
      case 'bestseller':
        $order = 'GREATEST(COALESCE(p.sold_count,0), ' . hs_sold_sub() . ') DESC, p.id DESC';
        break;
      case 'manual':
      default:
        $join = 'JOIN home_section_products sp ON sp.product_id = p.id AND sp.section_id = ' . $ph('hs_sid');
        $put('hs_sid', (int)($sec['id'] ?? 0));
        $order = 'sp.sort_order ASC, p.id DESC';
        break;
    }
    return [$join, $where, $order, $params];
  }

  /** হোমপেজের জন্য সেকশনের পণ্য (limit = product_limit) */
  function hs_products(PDO $pdo, array $sec): array {
    $limit = max(HS_LIMIT_MIN, min(HS_LIMIT_MAX, (int)($sec['product_limit'] ?? 12)));
    [$join, $where, $order, $params] = hs_query_parts($sec);
    $st = $pdo->prepare("
      SELECT p.*, c.name AS cat_name, " . hs_sold_sub() . " AS sold_dynamic
      FROM products p
      $join
      LEFT JOIN categories c ON c.id = p.category_id
      WHERE $where
      ORDER BY $order
      LIMIT $limit");
    $st->execute($params);
    $rows = $st->fetchAll();
    foreach ($rows as &$p) {
      $p['sold_count'] = max((int)($p['sold_count'] ?? 0), (int)($p['sold_dynamic'] ?? 0));
    }
    unset($p);
    return $rows;
  }

  /** "সব দেখুন" লিংক */
  function hs_see_all_url(array $sec): string {
    return BASE_URL . '/shop.php?section=' . (int)$sec['id'];
  }

  /* ---------- যাচাই ---------- */

  /**
   * POST ইনপুট → পরিষ্কার ডেটা। ভুল হলে [null, 'বার্তা'].
   * array/অপ্রত্যাশিত ইনপুট শান্তভাবে reject (fatal নয়)।
   */
  function hs_clean_input(PDO $pdo, array $in): array {
    foreach (['title','subtitle','type','category_id','product_limit'] as $k) {
      if (isset($in[$k]) && !is_scalar($in[$k])) return [null, 'ইনপুট সঠিক নয়।'];
    }
    $title = trim((string)($in['title'] ?? ''));
    $sub   = trim((string)($in['subtitle'] ?? ''));
    $type  = (string)($in['type'] ?? 'manual');
    $limit = (int)($in['product_limit'] ?? 12);
    $cat   = (int)($in['category_id'] ?? 0);

    if ($title === '') return [null, 'সেকশনের শিরোনাম দিন।'];
    if (mb_strlen($title) > 120) return [null, 'শিরোনাম ১২০ অক্ষরের বেশি হতে পারবে না।'];
    if (mb_strlen($sub) > 255)   return [null, 'সাবটাইটেল ২৫৫ অক্ষরের বেশি হতে পারবে না।'];
    if (!isset(hs_types()[$type])) return [null, 'সেকশনের ধরন সঠিক নয়।'];
    if ($limit < HS_LIMIT_MIN || $limit > HS_LIMIT_MAX) {
      return [null, 'পণ্য সংখ্যা ' . HS_LIMIT_MIN . ' থেকে ' . HS_LIMIT_MAX . '-এর মধ্যে হতে হবে।'];
    }
    if ($type === 'category') {
      $st = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE id = ?");
      $st->execute([$cat]);
      if ($cat <= 0 || !(int)$st->fetchColumn()) return [null, 'একটি ক্যাটেগরি বাছুন।'];
    } else {
      $cat = null;
    }

    return [[
      'title'         => $title,
      'subtitle'      => $sub !== '' ? $sub : null,
      'type'          => $type,
      'category_id'   => $cat,
      'product_limit' => $limit,
      'show_see_all'  => !empty($in['show_see_all']) ? 1 : 0,
      'new_default'   => ($type === 'manual' && !empty($in['new_default'])) ? 1 : 0,
      'active'        => !empty($in['active']) ? 1 : 0,
    ], null];
  }

  /* ---------- লেখা (সব ট্রানজাকশনে) ---------- */

  /** @return array [bool ok, string msg, int id] */
  function hs_create(PDO $pdo, array $d): array {
    try {
      $pdo->beginTransaction();
      $cnt = (int)$pdo->query("SELECT COUNT(*) FROM home_sections FOR UPDATE")->fetchColumn();
      if ($cnt >= HS_MAX) {
        $pdo->rollBack();
        return [false, 'সর্বোচ্চ ' . HS_MAX . 'টি সেকশন রাখা যায়। আগে একটি মুছুন।', 0];
      }
      $max = (int)$pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM home_sections")->fetchColumn();
      $pdo->prepare("INSERT INTO home_sections(title,subtitle,type,category_id,product_limit,sort_order,active,show_see_all,new_default)
                     VALUES(?,?,?,?,?,?,?,?,?)")
          ->execute([$d['title'],$d['subtitle'],$d['type'],$d['category_id'],$d['product_limit'],$max+1,$d['active'],$d['show_see_all'],(int)($d['new_default'] ?? 0)]);
      $id = (int)$pdo->lastInsertId();
      $pdo->commit();
      return [true, 'সেকশন যোগ হয়েছে।', $id];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      error_log('[home_sections create] ' . $e->getMessage());
      return [false, 'সেকশন সংরক্ষণ করা যায়নি।', 0];
    }
  }

  /** @return array [bool ok, string msg] */
  function hs_update(PDO $pdo, int $id, array $d): array {
    try {
      $pdo->beginTransaction();
      $cur = $pdo->prepare("SELECT * FROM home_sections WHERE id = ? FOR UPDATE");
      $cur->execute([$id]);
      $row = $cur->fetch();
      if (!$row) { $pdo->rollBack(); return [false, 'সেকশন পাওয়া যায়নি।']; }

      if ((int)$row['active'] === 1 && (int)$d['active'] === 0) {
        $act = (int)$pdo->query("SELECT COUNT(*) FROM home_sections WHERE active = 1 FOR UPDATE")->fetchColumn();
        if ($act <= HS_MIN) {
          $pdo->rollBack();
          return [false, 'অন্তত ' . HS_MIN . 'টি সেকশন চালু থাকতেই হবে।'];
        }
      }
      $pdo->prepare("UPDATE home_sections SET title=?, subtitle=?, type=?, category_id=?, product_limit=?, active=?, show_see_all=?, new_default=? WHERE id=?")
          ->execute([$d['title'],$d['subtitle'],$d['type'],$d['category_id'],$d['product_limit'],$d['active'],$d['show_see_all'],(int)($d['new_default'] ?? 0),$id]);
      $pdo->commit();
      return [true, 'সেকশন আপডেট হয়েছে।'];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      error_log('[home_sections update] ' . $e->getMessage());
      return [false, 'সেকশন সংরক্ষণ করা যায়নি।'];
    }
  }

  function hs_toggle(PDO $pdo, int $id): array {
    $row = hs_get($pdo, $id);
    if (!$row) return [false, 'সেকশন পাওয়া যায়নি।'];
    $d = [
      'title' => $row['title'], 'subtitle' => $row['subtitle'], 'type' => $row['type'],
      'category_id' => $row['category_id'], 'product_limit' => (int)$row['product_limit'],
      'show_see_all' => (int)$row['show_see_all'], 'new_default' => (int)$row['new_default'],
      'active' => (int)$row['active'] ? 0 : 1,
    ];
    [$ok, $msg] = hs_update($pdo, $id, $d);
    return [$ok, $ok ? 'স্ট্যাটাস আপডেট হয়েছে।' : $msg];
  }

  function hs_delete(PDO $pdo, int $id): array {
    try {
      $pdo->beginTransaction();
      $cnt = (int)$pdo->query("SELECT COUNT(*) FROM home_sections FOR UPDATE")->fetchColumn();
      if ($cnt <= HS_MIN) {
        $pdo->rollBack();
        return [false, 'অন্তত ' . HS_MIN . 'টি সেকশন রাখতেই হবে, তাই মোছা যাবে না।'];
      }
      $row = $pdo->prepare("SELECT active FROM home_sections WHERE id = ? FOR UPDATE");
      $row->execute([$id]);
      $r = $row->fetch();
      if (!$r) { $pdo->rollBack(); return [false, 'সেকশন পাওয়া যায়নি।']; }
      if ((int)$r['active'] === 1) {
        $act = (int)$pdo->query("SELECT COUNT(*) FROM home_sections WHERE active = 1")->fetchColumn();
        if ($act <= HS_MIN) {
          $pdo->rollBack();
          return [false, 'অন্তত ' . HS_MIN . 'টি সেকশন চালু থাকতেই হবে, তাই মোছা যাবে না।'];
        }
      }
      $pdo->prepare("DELETE FROM home_section_products WHERE section_id = ?")->execute([$id]);
      $pdo->prepare("DELETE FROM home_sections WHERE id = ?")->execute([$id]);
      hs_renumber($pdo);
      $pdo->commit();
      return [true, 'সেকশন মুছে ফেলা হয়েছে।'];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      error_log('[home_sections delete] ' . $e->getMessage());
      return [false, 'সেকশন মোছা যায়নি।'];
    }
  }

  /** ক্রম ১..n নতুন করে বসায় (বর্তমান ক্রম ধরে) */
  function hs_renumber(PDO $pdo): void {
    $ids = $pdo->query("SELECT id FROM home_sections ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_COLUMN);
    $u = $pdo->prepare("UPDATE home_sections SET sort_order = ? WHERE id = ?");
    foreach ($ids as $i => $sid) $u->execute([$i + 1, (int)$sid]);
  }

  /** dir = 'up' | 'down' — পাশের সেকশনের সাথে জায়গা বদল */
  function hs_move(PDO $pdo, int $id, string $dir): array {
    if (!in_array($dir, ['up', 'down'], true)) return [false, 'দিক সঠিক নয়।'];
    try {
      $pdo->beginTransaction();
      $pdo->query("SELECT id FROM home_sections FOR UPDATE")->fetchAll();
      hs_renumber($pdo);
      $ids = array_map('intval', $pdo->query("SELECT id FROM home_sections ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_COLUMN));
      $pos = array_search($id, $ids, true);
      if ($pos === false) { $pdo->rollBack(); return [false, 'সেকশন পাওয়া যায়নি।']; }
      $to = $dir === 'up' ? $pos - 1 : $pos + 1;
      if ($to < 0 || $to >= count($ids)) { $pdo->commit(); return [true, 'ক্রম অপরিবর্তিত।']; }
      [$ids[$pos], $ids[$to]] = [$ids[$to], $ids[$pos]];
      $u = $pdo->prepare("UPDATE home_sections SET sort_order = ? WHERE id = ?");
      foreach ($ids as $i => $sid) $u->execute([$i + 1, $sid]);
      $pdo->commit();
      return [true, 'ক্রম আপডেট হয়েছে।'];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      error_log('[home_sections move] ' . $e->getMessage());
      return [false, 'ক্রম বদলানো যায়নি।'];
    }
  }

  /* ---------- পণ্য ↔ সেকশন সংযোগ ---------- */

  /**
   * একটি পণ্যের manual সেকশন সংযোগ সেট করে।
   * শুধু active manual সেকশনগুলো বদলায় (বন্ধ সেকশনের সংযোগ অক্ষত থাকে)।
   * $rawIds যেকোনো ইনপুট হতে পারে — অ-array/অ-সংখ্যা বাদ যায়।
   */
  function hs_set_product_sections(PDO $pdo, int $productId, $rawIds): void {
    if ($productId <= 0) return;
    $chosen = [];
    if (is_array($rawIds)) {
      foreach ($rawIds as $v) {
        if (is_scalar($v) && ctype_digit((string)$v) && (int)$v > 0) $chosen[(int)$v] = true;
      }
    }
    $managed = array_map(fn($s) => (int)$s['id'], hs_manual_sections($pdo));
    if (!$managed) return;

    $own = !$pdo->inTransaction();
    try {
      if ($own) $pdo->beginTransaction();
      $in = implode(',', array_fill(0, count($managed), '?'));
      $pdo->prepare("DELETE FROM home_section_products WHERE product_id = ? AND section_id IN ($in)")
          ->execute(array_merge([$productId], $managed));
      $ins = $pdo->prepare("INSERT IGNORE INTO home_section_products(section_id, product_id, sort_order) VALUES(?,?,0)");
      foreach ($managed as $sid) {
        if (isset($chosen[$sid])) $ins->execute([$sid, $productId]);
      }
      if ($own) $pdo->commit();
    } catch (Throwable $e) {
      if ($own && $pdo->inTransaction()) $pdo->rollBack();
      error_log('[home_sections set_product_sections] ' . $e->getMessage());
      if (!$own) throw $e;
    }
  }

  /** পণ্য মুছলে সব সংযোগ মুছে দেয় */
  function hs_remove_product(PDO $pdo, int $productId): void {
    try {
      $pdo->prepare("DELETE FROM home_section_products WHERE product_id = ?")->execute([$productId]);
    } catch (Throwable $e) {
      error_log('[home_sections remove_product] ' . $e->getMessage());
    }
  }
}
