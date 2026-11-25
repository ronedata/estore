<?php
// includes/purchases.php — সরবরাহকারী, ক্রয়, ক্রয়ের পরিশোধ (helper)
// নিয়ম: ক্রয় সংরক্ষণে স্টক বাড়ে ও পণ্যের ক্রয়মূল্য গড়ে (weighted average) হালনাগাদ হয়, সবই একটি ট্রানজাকশনে;
//        বাকি = মোট − পরিশোধ (পরিশোধ-সারির যোগফল থেকে হিসাব, আলাদা জমা-সংখ্যা নেই); পরিশোধ কিস্তিতে বা একাধিক ক্রয় মিলিয়ে একসাথে;
//        ক্রয় বাতিল শুধু পরিশোধ ০ থাকলে (স্টক ফেরে, গড় ক্রয়মূল্য ফেরে না); অঙ্ক সেন্টে (পূর্ণসংখ্যা) তুলনা হয়

require_once __DIR__ . '/costs.php';
require_once __DIR__ . '/reports.php';

if (!defined('PU_MAX_LINES')) define('PU_MAX_LINES', 100);
if (!defined('PU_MAX_QTY')) define('PU_MAX_QTY', 100000);
if (!defined('PU_MAX_LINE')) define('PU_MAX_LINE', 1000000000);
if (!defined('PU_MAX_TOTAL')) define('PU_MAX_TOTAL', 5000000000);

if (!defined('PU_FROM')) define('PU_FROM', "FROM purchases pu LEFT JOIN suppliers s ON s.id = pu.supplier_id
                   LEFT JOIN (SELECT purchase_id, SUM(amount) paid FROM purchase_payments GROUP BY purchase_id) py ON py.purchase_id = pu.id");

if (!function_exists('pu_cents')) {

  function pu_cents($v): int { return (int)round(((float)$v) * 100); }
  function pu_money(int $c): float { return round($c / 100, 2); }

  function pu_text($v, int $max): ?string {
    if ($v === null) return '';
    if (!is_scalar($v)) return null;
    $s = trim(preg_replace('/\s+/u', ' ', (string)$v));
    return function_exists('mb_strlen') && mb_strlen($s, 'UTF-8') > $max ? null : $s;
  }

  /* ===================== সরবরাহকারী ===================== */

  /** @return array{0:?array,1:string[]} */
  function pu_supplier_clean(array $in): array {
    $err = [];
    $name = pu_text($in['name'] ?? '', 120);
    if ($name === null) $err[] = 'নাম সঠিক নয় বা ১২০ অক্ষরের বেশি।';
    elseif ($name === '') $err[] = 'সরবরাহকারীর নাম দিন।';
    $phone = pu_text($in['phone'] ?? '', 30);
    if ($phone === null || ($phone !== '' && !preg_match('/^[0-9+\-\s()]+$/', $phone))) $err[] = 'ফোন নম্বর সঠিক নয় (সংখ্যা, +, -, স্পেস)।';
    $addr = pu_text($in['address'] ?? '', 255);
    if ($addr === null) $err[] = 'ঠিকানা ২৫৫ অক্ষরের বেশি বা সঠিক নয়।';
    $note = pu_text($in['note'] ?? '', 255);
    if ($note === null) $err[] = 'নোট ২৫৫ অক্ষরের বেশি বা সঠিক নয়।';
    if ($err) return [null, $err];
    return [['name' => $name, 'phone' => $phone === '' ? null : $phone, 'address' => $addr === '' ? null : $addr, 'note' => $note === '' ? null : $note], []];
  }

  function pu_supplier_add(PDO $pdo, array $c): int {
    $pdo->prepare("INSERT INTO suppliers (name, phone, address, note) VALUES (?,?,?,?)")->execute([$c['name'], $c['phone'], $c['address'], $c['note']]);
    return (int)$pdo->lastInsertId();
  }

  function pu_supplier_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM suppliers WHERE id=?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }

  function pu_supplier_update(PDO $pdo, int $id, array $c): bool {
    if (!pu_supplier_get($pdo, $id)) return false;
    $pdo->prepare("UPDATE suppliers SET name=?, phone=?, address=?, note=? WHERE id=?")->execute([$c['name'], $c['phone'], $c['address'], $c['note'], $id]);
    return true;
  }

  function pu_supplier_set_active(PDO $pdo, int $id, bool $active): bool {
    if (!pu_supplier_get($pdo, $id)) return false;
    $pdo->prepare("UPDATE suppliers SET active=? WHERE id=?")->execute([$active ? 1 : 0, $id]);
    return true;
  }

  /** @return array{0:bool,1:string} */
  function pu_supplier_delete(PDO $pdo, int $id): array {
    if (!pu_supplier_get($pdo, $id)) return [false, 'সরবরাহকারী পাওয়া যায়নি।'];
    $st = $pdo->prepare("SELECT COUNT(*) FROM purchases WHERE supplier_id=?");
    $st->execute([$id]);
    if ((int)$st->fetchColumn() > 0) return [false, 'এই সরবরাহকারীর ক্রয়ের ইতিহাস আছে, মোছা যাবে না — "নিষ্ক্রিয়" করুন।'];
    $pdo->prepare("DELETE FROM suppliers WHERE id=?")->execute([$id]);
    return [true, ''];
  }

  /** সরবরাহকারী + (বাতিল বাদে) ক্রয়-সংখ্যা, মোট, পরিশোধ, বাকি */
  function pu_suppliers_with_dues(PDO $pdo, ?string $q = null, bool $onlyActive = false): array {
    $w = '1=1'; $p = [];
    if ($q !== null && $q !== '') { $w .= " AND (s.name LIKE ? OR s.phone LIKE ?)"; $p[] = "%$q%"; $p[] = "%$q%"; }
    if ($onlyActive) $w .= " AND s.active=1";
    $st = $pdo->prepare("SELECT s.*, COALESCE(pc.cnt,0) AS cnt, COALESCE(pc.total,0) AS total, COALESCE(pp.paid,0) AS paid
      FROM suppliers s
      LEFT JOIN (SELECT supplier_id, COUNT(*) cnt, SUM(total) total FROM purchases WHERE status='active' GROUP BY supplier_id) pc ON pc.supplier_id = s.id
      LEFT JOIN (SELECT pu.supplier_id, SUM(x.amount) paid FROM purchase_payments x JOIN purchases pu ON pu.id = x.purchase_id WHERE pu.status='active' GROUP BY pu.supplier_id) pp ON pp.supplier_id = s.id
      WHERE $w ORDER BY s.active DESC, s.name");
    $st->execute($p);
    return array_map(function ($r) {
      $r['total'] = round((float)$r['total'], 2); $r['paid'] = round((float)$r['paid'], 2);
      $r['due'] = pu_money(pu_cents($r['total']) - pu_cents($r['paid']));
      $r['cnt'] = (int)$r['cnt'];
      return $r;
    }, $st->fetchAll(PDO::FETCH_ASSOC));
  }

  /* ===================== ক্রয়: যাচাই ===================== */

  /**
   * ফর্ম ইনপুট যাচাই। $in: supplier_id, invoice_no, purchase_date, product_id[], qty[], unit_cost[], paid, method, note
   * @return array{0:?array,1:string[]}
   */
  function pu_clean_purchase(PDO $pdo, array $in, ?int $now = null): array {
    $now = $now ?? time();
    $err = [];

    $sid = is_string($in['supplier_id'] ?? null) && ctype_digit($in['supplier_id']) ? (int)$in['supplier_id'] : 0;
    $sup = $sid > 0 ? pu_supplier_get($pdo, $sid) : null;
    if (!$sup) $err[] = 'সরবরাহকারী বাছুন।';
    elseif (!(int)$sup['active']) $err[] = 'এই সরবরাহকারী নিষ্ক্রিয় — আগে সক্রিয় করুন।';

    $inv = pu_text($in['invoice_no'] ?? '', 60);
    if ($inv === null) $err[] = 'ইনভয়েস নম্বর ৬০ অক্ষরের বেশি বা সঠিক নয়।';
    elseif ($inv !== '' && $sup) {
      $d = $pdo->prepare("SELECT COUNT(*) FROM purchases WHERE supplier_id=? AND invoice_no=? AND status='active'");
      $d->execute([$sid, $inv]);
      if ((int)$d->fetchColumn() > 0) $err[] = 'এই সরবরাহকারীর এই ইনভয়েস নম্বর আগেই এন্ট্রি করা আছে।';
    }

    $date = rp_valid_date($in['purchase_date'] ?? null);
    if ($date === null) $err[] = 'তারিখ সঠিক নয় (YYYY-MM-DD)।';
    elseif ($date > date('Y-m-d', $now)) $err[] = 'ভবিষ্যতের তারিখ দেওয়া যাবে না।';

    $note = pu_text($in['note'] ?? '', 255);
    if ($note === null) $err[] = 'নোট ২৫৫ অক্ষরের বেশি বা সঠিক নয়।';

    /* আইটেম */
    $pids = $in['product_id'] ?? []; $qtys = $in['qty'] ?? []; $costs = $in['unit_cost'] ?? [];
    $items = []; $totalC = 0;
    if (!is_array($pids) || !is_array($qtys) || !is_array($costs) || count($pids) !== count($qtys) || count($pids) !== count($costs)) {
      $err[] = 'আইটেমের তথ্য সঠিক নয়।';
    } elseif (count($pids) > PU_MAX_LINES) {
      $err[] = 'একটি ক্রয়ে সর্বোচ্চ ' . PU_MAX_LINES . 'টি আইটেম।';
    } else {
      $find = $pdo->prepare("SELECT id, name FROM products WHERE id=?");
      $n = 0;
      foreach ($pids as $i => $pidRaw) {
        $qRaw = $qtys[$i]; $cRaw = $costs[$i];
        if (!is_scalar($pidRaw) || !is_scalar($qRaw) || !is_scalar($cRaw)) { $err[] = 'আইটেম ' . ($i + 1) . ': তথ্য সঠিক নয়।'; continue; }
        if (trim((string)$pidRaw) === '' && trim((string)$qRaw) === '' && trim((string)$cRaw) === '') continue; // ফাঁকা সারি
        $n++;
        $pid = ctype_digit((string)$pidRaw) ? (int)$pidRaw : 0;
        $find->execute([$pid]);
        $prod = $pid > 0 ? $find->fetch(PDO::FETCH_ASSOC) : false;
        if (!$prod) { $err[] = "আইটেম $n: পণ্য বাছুন।"; continue; }
        $qs = trim((string)$qRaw);
        if (!ctype_digit($qs) || (int)$qs < 1 || (int)$qs > PU_MAX_QTY) { $err[] = "আইটেম $n: পরিমাণ ১ থেকে " . PU_MAX_QTY . " এর মধ্যে পূর্ণসংখ্যা দিন।"; continue; }
        $unit = cs_parse_money($cRaw);
        if ($unit === false || $unit === null) { $err[] = "আইটেম $n: ক্রয়-দাম সঠিক নয় (০ বা বেশি সংখ্যা)।"; continue; }
        $lineC = pu_cents((int)$qs * $unit);
        if ($lineC > PU_MAX_LINE * 100) { $err[] = "আইটেম $n: লাইনের মোট অনেক বড়।"; continue; }
        $totalC += $lineC;
        $items[] = ['product_id' => (int)$prod['id'], 'name' => (string)$prod['name'], 'qty' => (int)$qs, 'unit_cost' => $unit, 'line_total' => pu_money($lineC)];
      }
      if (!$items && !$err) $err[] = 'অন্তত একটি আইটেম দিন।';
      if ($totalC > PU_MAX_TOTAL * 100) $err[] = 'ক্রয়ের মোট অনেক বড়।';
    }

    /* শুরুর পরিশোধ */
    $paid = cs_parse_money($in['paid'] ?? null);
    if ($paid === false) { $err[] = 'পরিশোধের পরিমাণ সঠিক নয়।'; $paid = 0.0; }
    $paid = $paid ?? 0.0;
    if (!$err && pu_cents($paid) > $totalC) $err[] = 'পরিশোধ মোট ক্রয়ের চেয়ে বেশি হতে পারবে না।';
    $method = pu_text($in['method'] ?? '', 30);
    if ($method === null) { $err[] = 'পরিশোধের মাধ্যম ৩০ অক্ষরের বেশি বা সঠিক নয়।'; $method = ''; }

    if ($err) return [null, $err];
    return [['supplier_id' => $sid, 'invoice_no' => $inv === '' ? null : $inv, 'purchase_date' => $date, 'items' => $items,
             'total' => pu_money($totalC), 'paid' => $paid, 'method' => $method === '' ? null : $method, 'note' => $note === '' ? null : $note], []];
  }

  /* ===================== ক্রয়: সংরক্ষণ ===================== */

  /**
   * ক্রয় সংরক্ষণ: purchases + purchase_items + (পরিশোধ>০ হলে) পরিশোধ-সারি + স্টক বাড়া + গড় ক্রয়মূল্য — সবই একটি ট্রানজাকশনে।
   * @return int নতুন ক্রয়ের আইডি; ব্যর্থ হলে 0 (কিছুই লেখা হয় না)
   */
  function pu_create(PDO $pdo, array $c, ?string $by = null): int {
    $hasCost = cs_has_column($pdo, 'products', 'cost_price');
    try {
      $pdo->beginTransaction();

      $ids = array_values(array_unique(array_column($c['items'], 'product_id')));
      sort($ids);
      $ph = implode(',', array_fill(0, count($ids), '?'));
      $lock = $pdo->prepare("SELECT id, stock, " . ($hasCost ? 'cost_price' : 'NULL AS cost_price') . " FROM products WHERE id IN ($ph) ORDER BY id FOR UPDATE");
      $lock->execute($ids);
      $state = [];
      foreach ($lock->fetchAll(PDO::FETCH_ASSOC) as $r) $state[(int)$r['id']] = ['stock' => (int)$r['stock'], 'cost' => $r['cost_price'] === null ? null : (float)$r['cost_price']];

      $pdo->prepare("INSERT INTO purchases (supplier_id, invoice_no, purchase_date, total, note, status, created_by) VALUES (?,?,?,?,?, 'active', ?)")
          ->execute([$c['supplier_id'], $c['invoice_no'], $c['purchase_date'], $c['total'], $c['note'], $by !== null ? mb_substr($by, 0, 60) : null]);
      $pid = (int)$pdo->lastInsertId();

      $insItem = $pdo->prepare("INSERT INTO purchase_items (purchase_id, product_id, product_name, qty, unit_cost, line_total) VALUES (?,?,?,?,?,?)");
      $updP = $pdo->prepare("UPDATE products SET stock=?" . ($hasCost ? ", cost_price=?" : "") . " WHERE id=?");
      foreach ($c['items'] as $it) {
        $insItem->execute([$pid, $it['product_id'], mb_substr($it['name'], 0, 255), $it['qty'], $it['unit_cost'], $it['line_total']]);
        if (!isset($state[$it['product_id']])) continue; // পণ্য ইতিমধ্যে মুছে গেছে — শুধু ইতিহাস থাকে
        $s = &$state[$it['product_id']];
        $newCost = cs_weighted_avg($s['stock'], $s['cost'], $it['qty'], (float)$it['unit_cost']);
        $s['stock'] += $it['qty']; $s['cost'] = $newCost;
        unset($s);
        $hasCost ? $updP->execute([$state[$it['product_id']]['stock'], $newCost, $it['product_id']]) : $updP->execute([$state[$it['product_id']]['stock'], $it['product_id']]);
      }

      if ($c['paid'] > 0) {
        $pdo->prepare("INSERT INTO purchase_payments (purchase_id, amount, paid_date, method, note, created_by) VALUES (?,?,?,?,?,?)")
            ->execute([$pid, $c['paid'], $c['purchase_date'], $c['method'], 'ক্রয়ের সময়ের পরিশোধ', $by !== null ? mb_substr($by, 0, 60) : null]);
      }
      $pdo->commit();
      return $pid;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      error_log('[purchases] create: ' . $e->getMessage());
      return 0;
    }
  }

  /* ===================== ক্রয়: পড়া ===================== */

  function pu_paid(PDO $pdo, int $purchaseId): float {
    $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM purchase_payments WHERE purchase_id=?");
    $st->execute([$purchaseId]);
    return round((float)$st->fetchColumn(), 2);
  }

  /** @return ?array{purchase:array,items:array,payments:array} */
  function pu_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT p.*, s.name AS supplier_name, s.phone AS supplier_phone FROM purchases p LEFT JOIN suppliers s ON s.id = p.supplier_id WHERE p.id=?");
    $st->execute([$id]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) return null;
    $i = $pdo->prepare("SELECT * FROM purchase_items WHERE purchase_id=? ORDER BY id"); $i->execute([$id]);
    $y = $pdo->prepare("SELECT * FROM purchase_payments WHERE purchase_id=? ORDER BY paid_date, id"); $y->execute([$id]);
    $pays = $y->fetchAll(PDO::FETCH_ASSOC);
    $p['paid'] = round(array_sum(array_map(fn($r) => (float)$r['amount'], $pays)), 2);
    $p['due'] = pu_money(pu_cents($p['total']) - pu_cents($p['paid']));
    return ['purchase' => $p, 'items' => $i->fetchAll(PDO::FETCH_ASSOC), 'payments' => $pays];
  }

  /** @return array{0:string,1:array} */
  function pu_where(?array $range, ?int $supplierId, string $status, ?string $q): array {
    $w = '1=1'; $p = [];
    if ($range) { $w .= " AND pu.purchase_date >= ? AND pu.purchase_date <= ?"; $p[] = $range['from']; $p[] = $range['to']; }
    if ($supplierId) { $w .= " AND pu.supplier_id = ?"; $p[] = $supplierId; }
    if ($q !== null && $q !== '') { $w .= " AND (pu.invoice_no LIKE ? OR s.name LIKE ?)"; $p[] = "%$q%"; $p[] = "%$q%"; }
    switch ($status) {
      case 'active':    $w .= " AND pu.status='active'"; break;
      case 'cancelled': $w .= " AND pu.status='cancelled'"; break;
      case 'due':       $w .= " AND pu.status='active' AND pu.total > COALESCE(py.paid,0)"; break;
      case 'paid':      $w .= " AND pu.status='active' AND pu.total <= COALESCE(py.paid,0)"; break;
    }
    return [$w, $p];
  }

  function pu_count(PDO $pdo, ?array $range, ?int $supplierId, string $status, ?string $q): int {
    [$w, $p] = pu_where($range, $supplierId, $status, $q);
    $st = $pdo->prepare("SELECT COUNT(*) " . PU_FROM . " WHERE $w");
    $st->execute($p);
    return (int)$st->fetchColumn();
  }

  function pu_list(PDO $pdo, ?array $range, ?int $supplierId, string $status, ?string $q, int $page, int $per): array {
    [$w, $p] = pu_where($range, $supplierId, $status, $q);
    $per = max(1, min(200, $per)); $page = max(1, $page);
    $st = $pdo->prepare("SELECT pu.*, s.name AS supplier_name, COALESCE(py.paid,0) AS paid, (SELECT COUNT(*) FROM purchase_items i WHERE i.purchase_id = pu.id) AS item_count " . PU_FROM .
      " WHERE $w ORDER BY pu.purchase_date DESC, pu.id DESC LIMIT " . (int)$per . " OFFSET " . (int)(($page - 1) * $per));
    $st->execute($p);
    return array_map(function ($r) { $r['paid'] = round((float)$r['paid'], 2); $r['due'] = pu_money(pu_cents($r['total']) - pu_cents($r['paid'])); return $r; }, $st->fetchAll(PDO::FETCH_ASSOC));
  }

  /** বাতিল বাদে মোট ক্রয়, পরিশোধ, বাকি (সময় দিলে ক্রয়ের তারিখ অনুযায়ী; না দিলে সব) */
  function pu_totals(PDO $pdo, ?array $range = null, ?int $supplierId = null): array {
    [$w, $p] = pu_where($range, $supplierId, 'active', null);
    $st = $pdo->prepare("SELECT COUNT(*), COALESCE(SUM(pu.total),0), COALESCE(SUM(LEAST(pu.total, COALESCE(py.paid,0))),0), COALESCE(SUM(py.paid),0) " . PU_FROM . " WHERE $w");
    $st->execute($p);
    [$n, $total, , $paid] = $st->fetch(PDO::FETCH_NUM);
    $totalC = pu_cents($total); $paidC = pu_cents($paid);
    return ['count' => (int)$n, 'total' => pu_money($totalC), 'paid' => pu_money($paidC), 'due' => pu_money($totalC - $paidC)];
  }

  /* ===================== পরিশোধ ===================== */

  /** একটি ক্রয়ে পরিশোধ (কিস্তি বা পুরোটা)। @return array{0:bool,1:string} */
  function pu_add_payment(PDO $pdo, int $purchaseId, $amount, $date, $method = '', $note = '', ?string $by = null, ?int $now = null): array {
    $now = $now ?? time();
    $amt = cs_parse_money($amount);
    if ($amt === false || $amt === null || $amt <= 0) return [false, 'পরিশোধের পরিমাণ ০-এর বেশি সংখ্যা হতে হবে।'];
    $d = rp_valid_date($date);
    if ($d === null) return [false, 'তারিখ সঠিক নয়।'];
    if ($d > date('Y-m-d', $now)) return [false, 'ভবিষ্যতের তারিখ দেওয়া যাবে না।'];
    $m = pu_text($method, 30); $n = pu_text($note, 255);
    if ($m === null || $n === null) return [false, 'মাধ্যম/নোট সঠিক নয় বা অনেক বড়।'];
    try {
      $pdo->beginTransaction();
      $lk = $pdo->prepare("SELECT total, status FROM purchases WHERE id=? FOR UPDATE");
      $lk->execute([$purchaseId]);
      $pu = $lk->fetch(PDO::FETCH_ASSOC);
      if (!$pu) { $pdo->rollBack(); return [false, 'ক্রয়টি পাওয়া যায়নি।']; }
      if ($pu['status'] !== 'active') { $pdo->rollBack(); return [false, 'বাতিল ক্রয়ে পরিশোধ করা যায় না।']; }
      $dueC = pu_cents($pu['total']) - pu_cents(pu_paid($pdo, $purchaseId));
      if (pu_cents($amt) > $dueC) { $pdo->rollBack(); return [false, 'পরিশোধ বাকির (' . number_format(pu_money($dueC), 2) . ') চেয়ে বেশি হতে পারবে না।']; }
      $pdo->prepare("INSERT INTO purchase_payments (purchase_id, amount, paid_date, method, note, created_by) VALUES (?,?,?,?,?,?)")
          ->execute([$purchaseId, $amt, $d, $m === '' ? null : $m, $n === '' ? null : $n, $by !== null ? mb_substr($by, 0, 60) : null]);
      $pdo->commit();
      return [true, ''];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      error_log('[purchases] payment: ' . $e->getMessage());
      return [false, 'পরিশোধ সংরক্ষণ করা যায়নি।'];
    }
  }

  /** ভুল পরিশোধ-সারি মোছা (বাকি আবার বাড়ে)। @return array{0:bool,1:string} */
  function pu_delete_payment(PDO $pdo, int $paymentId): array {
    $st = $pdo->prepare("DELETE FROM purchase_payments WHERE id=?");
    $st->execute([$paymentId]);
    return $st->rowCount() > 0 ? [true, ''] : [false, 'পরিশোধটি পাওয়া যায়নি।'];
  }

  /**
   * সরবরাহকারীকে একসাথে পরিশোধ: পুরোনো বাকি ক্রয় থেকে শুরু করে (তারিখ, আইডি ক্রমে) বণ্টন হয়।
   * @return array{0:bool,1:string,2:array} [সফল, বার্তা, বণ্টন: [[purchase_id, amount], ...]]
   */
  function pu_pay_supplier(PDO $pdo, int $supplierId, $amount, $date, $method = '', $note = '', ?string $by = null, ?int $now = null): array {
    $now = $now ?? time();
    $amt = cs_parse_money($amount);
    if ($amt === false || $amt === null || $amt <= 0) return [false, 'পরিশোধের পরিমাণ ০-এর বেশি সংখ্যা হতে হবে।', []];
    $d = rp_valid_date($date);
    if ($d === null) return [false, 'তারিখ সঠিক নয়।', []];
    if ($d > date('Y-m-d', $now)) return [false, 'ভবিষ্যতের তারিখ দেওয়া যাবে না।', []];
    $m = pu_text($method, 30); $n = pu_text($note, 255);
    if ($m === null || $n === null) return [false, 'মাধ্যম/নোট সঠিক নয় বা অনেক বড়।', []];
    if (!pu_supplier_get($pdo, $supplierId)) return [false, 'সরবরাহকারী পাওয়া যায়নি।', []];
    try {
      $pdo->beginTransaction();
      $lk = $pdo->prepare("SELECT id, total FROM purchases WHERE supplier_id=? AND status='active' ORDER BY purchase_date, id FOR UPDATE");
      $lk->execute([$supplierId]);
      $left = pu_cents($amt); $alloc = [];
      $ins = $pdo->prepare("INSERT INTO purchase_payments (purchase_id, amount, paid_date, method, note, created_by) VALUES (?,?,?,?,?,?)");
      $dueTotal = 0; $dues = [];
      foreach ($lk->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $dueC = pu_cents($r['total']) - pu_cents(pu_paid($pdo, (int)$r['id']));
        if ($dueC > 0) { $dues[] = [(int)$r['id'], $dueC]; $dueTotal += $dueC; }
      }
      if ($left > $dueTotal) { $pdo->rollBack(); return [false, 'পরিশোধ মোট বাকির (' . number_format(pu_money($dueTotal), 2) . ') চেয়ে বেশি হতে পারবে না।', []]; }
      $noteTxt = ($n !== '' ? $n . ' — ' : '') . 'একসাথে পরিশোধ';
      foreach ($dues as [$id, $dueC]) {
        if ($left <= 0) break;
        $part = min($left, $dueC);
        $ins->execute([$id, pu_money($part), $d, $m === '' ? null : $m, mb_substr($noteTxt, 0, 255), $by !== null ? mb_substr($by, 0, 60) : null]);
        $alloc[] = [$id, pu_money($part)];
        $left -= $part;
      }
      $pdo->commit();
      return [true, '', $alloc];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      error_log('[purchases] pay_supplier: ' . $e->getMessage());
      return [false, 'পরিশোধ সংরক্ষণ করা যায়নি।', []];
    }
  }

  /* ===================== বাতিল ===================== */

  /**
   * ক্রয় বাতিল: শুধু পরিশোধ ০ থাকলে। স্টক ফেরে (০-এর নিচে নামে না); গড় ক্রয়মূল্য ফেরানো হয় না।
   * @return array{0:bool,1:string,2:int} [সফল, বার্তা, স্টক-কম পড়া পণ্যের সংখ্যা]
   */
  function pu_void(PDO $pdo, int $id): array {
    try {
      $pdo->beginTransaction();
      $lk = $pdo->prepare("SELECT status FROM purchases WHERE id=? FOR UPDATE");
      $lk->execute([$id]);
      $pu = $lk->fetch(PDO::FETCH_ASSOC);
      if (!$pu) { $pdo->rollBack(); return [false, 'ক্রয়টি পাওয়া যায়নি।', 0]; }
      if ($pu['status'] !== 'active') { $pdo->rollBack(); return [false, 'ক্রয়টি আগেই বাতিল।', 0]; }
      if (pu_cents(pu_paid($pdo, $id)) > 0) { $pdo->rollBack(); return [false, 'পরিশোধ করা আছে — আগে পরিশোধ-সারি মুছুন, তারপর বাতিল করুন।', 0]; }
      $it = $pdo->prepare("SELECT product_id, qty FROM purchase_items WHERE purchase_id=?");
      $it->execute([$id]);
      $short = 0;
      $sel = $pdo->prepare("SELECT stock FROM products WHERE id=? FOR UPDATE");
      $upd = $pdo->prepare("UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id=?");
      foreach ($it->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if ($r['product_id'] === null) continue;
        $sel->execute([(int)$r['product_id']]);
        $cur = $sel->fetchColumn();
        if ($cur === false) continue;
        if ((int)$cur < (int)$r['qty']) $short++;
        $upd->execute([(int)$r['qty'], (int)$r['product_id']]);
      }
      $pdo->prepare("UPDATE purchases SET status='cancelled', cancelled_at=NOW() WHERE id=?")->execute([$id]);
      $pdo->commit();
      return [true, '', $short];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      error_log('[purchases] void: ' . $e->getMessage());
      return [false, 'বাতিল করা যায়নি।', 0];
    }
  }
}
