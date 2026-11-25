<?php
// includes/expenses.php — খরচ (expenses) মডিউলের helper
require_once __DIR__ . '/reports.php';
require_once __DIR__ . '/costs.php';

if (!defined('EX_TITLE_MAX')) define('EX_TITLE_MAX', 160);
if (!defined('EX_CAT_MAX')) define('EX_CAT_MAX', 60);
if (!defined('EX_NOTE_MAX')) define('EX_NOTE_MAX', 255);

if (!function_exists('ex_clean')) {

  function ex_text($v, int $max): ?string {
    if ($v === null) return '';
    if (!is_scalar($v)) return null;
    $s = trim(preg_replace('/\s+/u', ' ', (string)$v));
    return function_exists('mb_strlen') && mb_strlen($s, 'UTF-8') > $max ? null : $s;
  }

  /**
   * ফর্ম ইনপুট যাচাই। @return array{0:?array,1:string[]} [পরিষ্কার ডেটা|null, ত্রুটির তালিকা]
   */
  function ex_clean(array $in, ?int $now = null): array {
    $now = $now ?? time();
    $err = [];
    $title = ex_text($in['title'] ?? '', EX_TITLE_MAX);
    if ($title === null) $err[] = 'শিরোনাম সঠিক নয় বা ' . EX_TITLE_MAX . ' অক্ষরের বেশি।';
    elseif ($title === '') $err[] = 'খরচের শিরোনাম দিন।';

    $amt = cs_parse_money($in['amount'] ?? null);
    if ($amt === false || $amt === null) $err[] = 'পরিমাণ সঠিক নয় (০-এর বেশি সংখ্যা দিন)।';
    elseif ($amt <= 0) $err[] = 'পরিমাণ ০-এর বেশি হতে হবে।';

    $date = rp_valid_date($in['expense_date'] ?? null);
    if ($date === null) $err[] = 'তারিখ সঠিক নয় (YYYY-MM-DD)।';
    elseif ($date > date('Y-m-d', $now)) $err[] = 'ভবিষ্যতের তারিখ দেওয়া যাবে না।';

    $cat = ex_text($in['category'] ?? '', EX_CAT_MAX);
    if ($cat === null) $err[] = 'ক্যাটেগরি ' . EX_CAT_MAX . ' অক্ষরের বেশি বা সঠিক নয়।';
    $note = ex_text($in['note'] ?? '', EX_NOTE_MAX);
    if ($note === null) $err[] = 'নোট ' . EX_NOTE_MAX . ' অক্ষরের বেশি বা সঠিক নয়।';

    if ($err) return [null, $err];
    return [['title' => $title, 'amount' => $amt, 'expense_date' => $date, 'category' => $cat === '' ? null : $cat, 'note' => $note === '' ? null : $note], []];
  }

  function ex_add(PDO $pdo, array $c, ?string $by): int {
    $pdo->prepare("INSERT INTO expenses (title, amount, expense_date, category, note, created_by) VALUES (?,?,?,?,?,?)")
        ->execute([$c['title'], $c['amount'], $c['expense_date'], $c['category'], $c['note'], $by !== null ? mb_substr($by, 0, 60) : null]);
    return (int)$pdo->lastInsertId();
  }

  function ex_update(PDO $pdo, int $id, array $c): bool {
    $st = $pdo->prepare("UPDATE expenses SET title=?, amount=?, expense_date=?, category=?, note=? WHERE id=?");
    $st->execute([$c['title'], $c['amount'], $c['expense_date'], $c['category'], $c['note'], $id]);
    return (bool)$pdo->query("SELECT 1 FROM expenses WHERE id=" . (int)$id)->fetchColumn();
  }

  function ex_delete(PDO $pdo, int $id): bool {
    $st = $pdo->prepare("DELETE FROM expenses WHERE id=?");
    $st->execute([$id]);
    return $st->rowCount() > 0;
  }

  function ex_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM expenses WHERE id=?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }

  /** @return array{0:string,1:array} */
  function ex_where(array $range, ?string $cat): array {
    $sql = "e.expense_date >= ? AND e.expense_date <= ?";
    $p = [$range['from'], $range['to']];
    if ($cat !== null && $cat !== '') {
      if ($cat === '__none') { $sql .= " AND e.category IS NULL"; }
      else { $sql .= " AND e.category = ?"; $p[] = $cat; }
    }
    return [$sql, $p];
  }

  function ex_total(PDO $pdo, array $range, ?string $cat = null): float {
    [$w, $p] = ex_where($range, $cat);
    $st = $pdo->prepare("SELECT COALESCE(SUM(e.amount),0) FROM expenses e WHERE $w");
    $st->execute($p);
    return round((float)$st->fetchColumn(), 2);
  }

  function ex_count(PDO $pdo, array $range, ?string $cat = null): int {
    [$w, $p] = ex_where($range, $cat);
    $st = $pdo->prepare("SELECT COUNT(*) FROM expenses e WHERE $w");
    $st->execute($p);
    return (int)$st->fetchColumn();
  }

  function ex_list(PDO $pdo, array $range, ?string $cat, int $page, int $per): array {
    [$w, $p] = ex_where($range, $cat);
    $per = max(1, min(200, $per)); $page = max(1, $page);
    $st = $pdo->prepare("SELECT e.* FROM expenses e WHERE $w ORDER BY e.expense_date DESC, e.id DESC LIMIT " . (int)$per . " OFFSET " . (int)(($page - 1) * $per));
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }

  /** ক্যাটেগরি অনুযায়ী যোগফল (ফিল্টারের সময়ে) */
  function ex_by_category(PDO $pdo, array $range): array {
    [$w, $p] = ex_where($range, null);
    $st = $pdo->prepare("SELECT COALESCE(e.category,'ক্যাটেগরি নেই') n, COALESCE(SUM(e.amount),0) s, COUNT(*) c FROM expenses e WHERE $w GROUP BY e.category ORDER BY s DESC");
    $st->execute($p);
    return array_map(fn($r) => ['name' => $r['n'], 'sum' => round((float)$r['s'], 2), 'count' => (int)$r['c']], $st->fetchAll(PDO::FETCH_ASSOC));
  }

  function ex_categories(PDO $pdo): array {
    return $pdo->query("SELECT category FROM expenses WHERE category IS NOT NULL GROUP BY category ORDER BY COUNT(*) DESC, category LIMIT 50")->fetchAll(PDO::FETCH_COLUMN);
  }

  /** আজ / এই মাস / এই বছর */
  function ex_overview(PDO $pdo, ?int $now = null): array {
    $now = $now ?? time();
    $d = date('Y-m-d', $now);
    return [
      'today' => ex_total($pdo, ['from' => $d, 'to' => $d]),
      'month' => ex_total($pdo, ['from' => date('Y-m-01', $now), 'to' => date('Y-m-t', $now)]),
      'year'  => ex_total($pdo, ['from' => date('Y-01-01', $now), 'to' => date('Y-12-31', $now)]),
    ];
  }
}
