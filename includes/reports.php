<?php
// includes/reports.php — অর্ডার/বিক্রয় রিপোর্টের হিসাব (helper)
// নিয়ম: বাতিল (cancelled) অর্ডার রাজস্ব, ডিসকাউন্ট ও ডেলিভারি চার্জের হিসাবে ধরা হয় না; মোট অর্ডার গণনায় সব স্ট্যাটাস আছে

if (!function_exists('rp_parse_range')) {

  function rp_statuses(): array { return ['pending', 'processing', 'shipped', 'delivered', 'cancelled']; }

  function rp_status_label(string $s): string {
    return ['pending' => 'Pending', 'processing' => 'Processing', 'shipped' => 'Shipped', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'][$s] ?? $s;
  }

  function rp_valid_date($v): ?string {
    if (!is_string($v)) return null;
    $v = trim($v);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) return null;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) && (int)$m[1] >= 2000 && (int)$m[1] <= 2100 ? $v : null;
  }

  /**
   * ফিল্টার (GET) → তারিখ-সীমা। ভুল মান হলে চালু মাসে ফিরে যায় এবং 'error' বার্তা দেয়।
   * @return array{type:string,year:int,month:int,from:string,to:string,label:string,error:?string}
   */
  function rp_parse_range(array $get, ?int $now = null): array {
    $now = $now ?? time();
    $curY = (int)date('Y', $now); $curM = (int)date('n', $now);
    $type = is_string($get['type'] ?? null) && in_array($get['type'], ['today', 'monthly', 'yearly', 'custom'], true) ? $get['type'] : 'monthly';
    $year = (is_string($get['year'] ?? null) && ctype_digit($get['year']) && (int)$get['year'] >= 2000 && (int)$get['year'] <= 2100) ? (int)$get['year'] : $curY;
    $month = (is_string($get['month'] ?? null) && ctype_digit($get['month']) && (int)$get['month'] >= 1 && (int)$get['month'] <= 12) ? (int)$get['month'] : $curM;
    $error = null;

    if ($type === 'custom') {
      $f = rp_valid_date($get['from'] ?? null); $t = rp_valid_date($get['to'] ?? null);
      if ($f === null || $t === null) {
        $error = 'তারিখ সঠিক নয় (YYYY-MM-DD)। চালু মাসের রিপোর্ট দেখানো হচ্ছে।';
        $type = 'monthly'; $year = $curY; $month = $curM;
      } else {
        if ($f > $t) { [$f, $t] = [$t, $f]; }
        return ['type' => 'custom', 'year' => $year, 'month' => $month, 'from' => $f, 'to' => $t,
                'label' => $f . ' থেকে ' . $t, 'error' => null];
      }
    }
    if ($type === 'today') {
      $d = date('Y-m-d', $now);
      return ['type' => 'today', 'year' => $year, 'month' => $month, 'from' => $d, 'to' => $d, 'label' => 'আজ — ' . date('d M Y', $now), 'error' => null];
    }
    if ($type === 'yearly') {
      return ['type' => 'yearly', 'year' => $year, 'month' => $month, 'from' => sprintf('%04d-01-01', $year), 'to' => sprintf('%04d-12-31', $year), 'label' => $year . ' সাল', 'error' => null];
    }
    $first = sprintf('%04d-%02d-01', $year, $month);
    $last = date('Y-m-t', strtotime($first));
    return ['type' => 'monthly', 'year' => $year, 'month' => $month, 'from' => $first, 'to' => $last, 'label' => date('F Y', strtotime($first)), 'error' => $error];
  }

  /** @return array{0:string,1:array} WHERE অংশ ও প্যারামিটার (created_at সূচক-বান্ধব) */
  function rp_where(array $range, string $status = 'all', string $alias = 'o'): array {
    $sql = "$alias.created_at >= ? AND $alias.created_at < ?";
    $params = [$range['from'] . ' 00:00:00', date('Y-m-d', strtotime($range['to'] . ' +1 day')) . ' 00:00:00'];
    if (in_array($status, rp_statuses(), true)) { $sql .= " AND $alias.status = ?"; $params[] = $status; }
    return [$sql, $params];
  }

  /** @return array{orders:int,valid:int,cancelled:int,revenue:float,discount:float,shipping:float,subtotal:float,avg:float} */
  function rp_summary(PDO $pdo, array $range, string $status = 'all'): array {
    [$w, $p] = rp_where($range, $status);
    $st = $pdo->prepare("SELECT COUNT(*),
        COALESCE(SUM(o.status<>'cancelled'),0),
        COALESCE(SUM(o.status='cancelled'),0),
        COALESCE(SUM(CASE WHEN o.status<>'cancelled' THEN o.total END),0),
        COALESCE(SUM(CASE WHEN o.status<>'cancelled' THEN o.discount END),0),
        COALESCE(SUM(CASE WHEN o.status<>'cancelled' THEN o.shipping_fee END),0),
        COALESCE(SUM(CASE WHEN o.status<>'cancelled' THEN o.subtotal END),0)
      FROM orders o WHERE $w");
    $st->execute($p);
    $r = $st->fetch(PDO::FETCH_NUM);
    $valid = (int)$r[1];
    return ['orders' => (int)$r[0], 'valid' => $valid, 'cancelled' => (int)$r[2],
            'revenue' => round((float)$r[3], 2), 'discount' => round((float)$r[4], 2), 'shipping' => round((float)$r[5], 2),
            'subtotal' => round((float)$r[6], 2), 'avg' => $valid > 0 ? round((float)$r[3] / $valid, 2) : 0.0];
  }

  /** @return array<string,array{count:int,sum:float}> */
  function rp_by_status(PDO $pdo, array $range): array {
    [$w, $p] = rp_where($range, 'all');
    $st = $pdo->prepare("SELECT o.status, COUNT(*), COALESCE(SUM(o.total),0) FROM orders o WHERE $w GROUP BY o.status");
    $st->execute($p);
    $out = array_fill_keys(rp_statuses(), ['count' => 0, 'sum' => 0.0]);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$s, $c, $sum]) $out[$s] = ['count' => (int)$c, 'sum' => round((float)$sum, 2)];
    return $out;
  }

  function rp_orders_count(PDO $pdo, array $range, string $status): int {
    [$w, $p] = rp_where($range, $status);
    $st = $pdo->prepare("SELECT COUNT(*) FROM orders o WHERE $w");
    $st->execute($p);
    return (int)$st->fetchColumn();
  }

  function rp_orders_page(PDO $pdo, array $range, string $status, int $page, int $per): array {
    [$w, $p] = rp_where($range, $status);
    $per = max(1, min(200, $per)); $page = max(1, $page);
    $st = $pdo->prepare("SELECT o.id, o.order_code, o.customer_name, o.mobile, o.total, o.discount, o.shipping_fee, o.status, o.created_at, o.shipping_district, o.payment_method
      FROM orders o WHERE $w ORDER BY o.created_at DESC, o.id DESC LIMIT " . (int)$per . " OFFSET " . (int)(($page - 1) * $per));
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }

  /** পণ্য-বিক্রি ক্যাটেগরি অনুযায়ী (বাতিল বাদ; ডিসকাউন্ট/ডেলিভারি আগে) */
  function rp_sales_by_category(PDO $pdo, array $range): array {
    [$w, $p] = rp_where($range, 'all');
    $st = $pdo->prepare("SELECT COALESCE(c.name, 'ক্যাটেগরি নেই') AS name, COALESCE(SUM(oi.price*oi.qty),0) AS amount, COALESCE(SUM(oi.qty),0) AS qty
      FROM order_items oi
      JOIN orders o ON o.id = oi.order_id
      LEFT JOIN products pr ON pr.id = oi.product_id
      LEFT JOIN categories c ON c.id = pr.category_id
      WHERE o.status <> 'cancelled' AND $w
      GROUP BY c.id, c.name ORDER BY amount DESC");
    $st->execute($p);
    return array_map(fn($r) => ['name' => $r['name'], 'amount' => round((float)$r['amount'], 2), 'qty' => (int)$r['qty']], $st->fetchAll(PDO::FETCH_ASSOC));
  }

  /** শেষ N মাসের (চালু মাসসহ) রাজস্ব ও অর্ডার সংখ্যা; ফাঁকা মাসও থাকে */
  function rp_monthly_series(PDO $pdo, int $months = 6, ?int $now = null): array {
    $now = $now ?? time();
    $months = max(1, min(24, $months));
    $series = [];
    for ($i = $months - 1; $i >= 0; $i--) {
      $key = date('Y-m', strtotime(date('Y-m-01', $now) . " -$i month"));
      $series[$key] = ['key' => $key, 'label' => date('M y', strtotime($key . '-01')), 'revenue' => 0.0, 'orders' => 0];
    }
    $from = array_key_first($series) . '-01 00:00:00';
    $st = $pdo->prepare("SELECT DATE_FORMAT(created_at,'%Y-%m') k, COUNT(*), COALESCE(SUM(total),0)
      FROM orders WHERE status<>'cancelled' AND created_at >= ? GROUP BY k");
    $st->execute([$from]);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$k, $c, $sum]) {
      if (isset($series[$k])) { $series[$k]['orders'] = (int)$c; $series[$k]['revenue'] = round((float)$sum, 2); }
    }
    return array_values($series);
  }

  function rp_old_pending(PDO $pdo, int $days = 3, ?int $now = null): int {
    $now = $now ?? time();
    $st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE status='pending' AND created_at < ?");
    $st->execute([date('Y-m-d H:i:s', $now - $days * 86400)]);
    return (int)$st->fetchColumn();
  }

  /** CSV-এ ফর্মুলা-ইনজেকশন আটকানো (= + - @ ট্যাব দিয়ে শুরু হলে আগে ') */
  function rp_csv_safe($v): string {
    $v = (string)$v;
    return ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) ? "'" . $v : $v;
  }

  /** ফিল্টার-পরামিটার লিংকে রাখার জন্য (পৃষ্ঠা/এক্সপোর্ট বাদে) */
  function rp_keep_params(array $range, string $status): array {
    $q = ['type' => $range['type']];
    if ($range['type'] === 'monthly') { $q['year'] = $range['year']; $q['month'] = $range['month']; }
    elseif ($range['type'] === 'yearly') { $q['year'] = $range['year']; }
    elseif ($range['type'] === 'custom') { $q['from'] = $range['from']; $q['to'] = $range['to']; }
    if ($status !== 'all') $q['status'] = $status;
    return $q;
  }
}
