<?php
// includes/order_discount.php — অর্ডারে অ্যাডমিন ডিসকাউন্ট (helper)
// নিয়ম: মোট = সাবটোটাল − ডিসকাউন্ট + ডেলিভারি; ডিসকাউন্ট ০ থেকে সাবটোটাল পর্যন্ত; সার্ভারে আবার হিসাব হয়

if (!defined('OD_NOTE_MAX')) define('OD_NOTE_MAX', 160);
if (!defined('OD_MAX_AMOUNT')) define('OD_MAX_AMOUNT', 10000000);

if (!function_exists('od_parse_number')) {

  /** '' → 0.0; সংখ্যা (≥০) → float (২ দশমিক); অবৈধ → false */
  function od_parse_number($v) {
    if ($v === null) return 0.0;
    if (!is_scalar($v)) return false;
    $v = trim((string)$v);
    if ($v === '') return 0.0;
    if (!is_numeric($v)) return false;
    $f = (float)$v;
    if (!is_finite($f) || $f < 0 || $f > OD_MAX_AMOUNT) return false;
    return round($f, 2);
  }

  /** শুধু ফরম্যাট যাচাই (সাবটোটাল জানার আগে): [ত্রুটি|null] */
  function od_validate_input($type, $value): ?string {
    $v = od_parse_number($value);
    if ($v === false) return 'ডিসকাউন্টের মান সঠিক নয় (০ বা ধনাত্মক সংখ্যা দিন)।';
    if ($type === 'percent' && $v > 100) return 'ডিসকাউন্ট শতাংশ ১০০-এর বেশি হতে পারবে না।';
    return null;
  }

  /**
   * চূড়ান্ত ডিসকাউন্ট টাকা হিসাব (সাবটোটালের ওপর)।
   * @return array{0:?float,1:bool,2:?string} [টাকা|null, সাবটোটালে ছাঁটা হয়েছে কি, ত্রুটি]
   */
  function od_resolve($type, $value, float $subtotal): array {
    $err = od_validate_input($type, $value);
    if ($err !== null) return [null, false, $err];
    $v = od_parse_number($value);
    $amt = ($type === 'percent') ? round($subtotal * $v / 100, 2) : $v;
    $clamped = false;
    $cap = round(max(0.0, $subtotal), 2);
    if ($amt > $cap) { $amt = $cap; $clamped = true; }
    return [round($amt, 2), $clamped, null];
  }

  function od_net_total(float $subtotal, float $discount, float $shipping): float {
    return max(0.0, round($subtotal - $discount + $shipping, 2));
  }

  function od_note_clean($s): string {
    $s = is_scalar($s) ? trim(preg_replace('/\s+/u', ' ', (string)$s)) : '';
    return function_exists('mb_substr') ? mb_substr($s, 0, OD_NOTE_MAX, 'UTF-8') : substr($s, 0, OD_NOTE_MAX);
  }
}
