<?php
// includes/delivery.php — জেলা-ভিত্তিক ডেলিভারি চার্জ (helper)
// নিয়ম: জেলার নিজস্ব চার্জ থাকলে সেটা; ফাঁকা (NULL) হলে গ্লোবাল; 0 মানে ওই জেলায় ফ্রি।

if (!defined('DL_FEE_MAX')) define('DL_FEE_MAX', 100000);
if (!defined('DL_DEFAULT_GLOBAL')) define('DL_DEFAULT_GLOBAL', 120);

if (!function_exists('dl_districts_seed')) {

  /** [বিভাগ => [[বাংলা নাম, English name], ...]] — ৮ বিভাগ, ৬৪ জেলা */
  function dl_districts_seed(): array {
    return [
      'ঢাকা' => [['ঢাকা','Dhaka'],['ফরিদপুর','Faridpur'],['গাজীপুর','Gazipur'],['গোপালগঞ্জ','Gopalganj'],['কিশোরগঞ্জ','Kishoreganj'],['মাদারীপুর','Madaripur'],['মানিকগঞ্জ','Manikganj'],['মুন্সিগঞ্জ','Munshiganj'],['নারায়ণগঞ্জ','Narayanganj'],['নরসিংদী','Narsingdi'],['রাজবাড়ী','Rajbari'],['শরীয়তপুর','Shariatpur'],['টাঙ্গাইল','Tangail']],
      'চট্টগ্রাম' => [['বান্দরবান','Bandarban'],['ব্রাহ্মণবাড়িয়া','Brahmanbaria'],['চাঁদপুর','Chandpur'],['চট্টগ্রাম','Chattogram'],['কুমিল্লা','Cumilla'],['কক্সবাজার',"Cox's Bazar"],['ফেনী','Feni'],['খাগড়াছড়ি','Khagrachhari'],['লক্ষ্মীপুর','Lakshmipur'],['নোয়াখালী','Noakhali'],['রাঙ্গামাটি','Rangamati']],
      'রাজশাহী' => [['বগুড়া','Bogura'],['জয়পুরহাট','Joypurhat'],['নওগাঁ','Naogaon'],['নাটোর','Natore'],['চাঁপাইনবাবগঞ্জ','Chapainawabganj'],['পাবনা','Pabna'],['রাজশাহী','Rajshahi'],['সিরাজগঞ্জ','Sirajganj']],
      'খুলনা' => [['বাগেরহাট','Bagerhat'],['চুয়াডাঙ্গা','Chuadanga'],['যশোর','Jashore'],['ঝিনাইদহ','Jhenaidah'],['খুলনা','Khulna'],['কুষ্টিয়া','Kushtia'],['মাগুরা','Magura'],['মেহেরপুর','Meherpur'],['নড়াইল','Narail'],['সাতক্ষীরা','Satkhira']],
      'বরিশাল' => [['বরগুনা','Barguna'],['বরিশাল','Barishal'],['ভোলা','Bhola'],['ঝালকাঠি','Jhalokathi'],['পটুয়াখালী','Patuakhali'],['পিরোজপুর','Pirojpur']],
      'সিলেট' => [['হবিগঞ্জ','Habiganj'],['মৌলভীবাজার','Moulvibazar'],['সুনামগঞ্জ','Sunamganj'],['সিলেট','Sylhet']],
      'রংপুর' => [['দিনাজপুর','Dinajpur'],['গাইবান্ধা','Gaibandha'],['কুড়িগ্রাম','Kurigram'],['লালমনিরহাট','Lalmonirhat'],['নীলফামারী','Nilphamari'],['পঞ্চগড়','Panchagarh'],['রংপুর','Rangpur'],['ঠাকুরগাঁও','Thakurgaon']],
      'ময়মনসিংহ' => [['জামালপুর','Jamalpur'],['ময়মনসিংহ','Mymensingh'],['নেত্রকোণা','Netrokona'],['শেরপুর','Sherpur']],
    ];
  }

  function dl_divisions(): array { return array_keys(dl_districts_seed()); }

  /** খোঁজার জন্য প্রচলিত বিকল্প বানান (ছোট হাতের, স্পেস দিয়ে আলাদা) — English নাম => বিকল্পগুলো */
  function dl_aliases(string $en): string {
    static $map = [
      'Dhaka' => 'dacca', 'Chattogram' => 'chittagong ctg', 'Cumilla' => 'comilla', 'Barishal' => 'barisal',
      'Jashore' => 'jessore', 'Bogura' => 'bogra', 'Jhalokathi' => 'jhalakati jhalakathi jhalokati',
      'Moulvibazar' => 'maulvibazar moulvibazar', 'Netrokona' => 'netrakona', 'Chapainawabganj' => 'nawabganj chapai chapainawabgonj',
      'Brahmanbaria' => 'baria brahmonbaria', 'Narsingdi' => 'narsinghdi narshingdi', 'Munshiganj' => 'munshigonj',
      'Gopalganj' => 'gopalgonj', 'Kishoreganj' => 'kishorgonj kishoregonj', 'Narayanganj' => 'narayangonj',
      "Cox's Bazar" => 'coxsbazar coxbazar', 'Khagrachhari' => 'khagrachari', 'Lakshmipur' => 'laxmipur lakshmipur',
      'Thakurgaon' => 'thakurgoan', 'Pirojpur' => 'perojpur', 'Lalmonirhat' => 'lalmonirhat', 'Gaibandha' => 'gaibanda',
      'Habiganj' => 'hobiganj habigonj', 'Sunamganj' => 'sunamgonj', 'Satkhira' => 'shatkhira', 'Mymensingh' => 'mymensing',
      'Naogaon' => 'noagaon', 'Joypurhat' => 'jaipurhat', 'Panchagarh' => 'panchagor', 'Rangamati' => 'rangamati',
      'Bandarban' => 'bandorban', 'Tangail' => 'tangail', 'Jhenaidah' => 'jhenidah jhenaidaha', 'Chuadanga' => 'chuadanga',
    ];
    return $map[$en] ?? '';
  }

  /** সব জেলা, বিভাগের ক্রমে */
  function dl_all(PDO $pdo): array {
    try { return $pdo->query("SELECT * FROM delivery_districts ORDER BY sort_order ASC, id ASC")->fetchAll() ?: []; }
    catch (Throwable $e) { return []; }
  }

  function dl_get(PDO $pdo, int $id): ?array {
    if ($id < 1) return null;
    try {
      $st = $pdo->prepare("SELECT * FROM delivery_districts WHERE id = ?");
      $st->execute([$id]);
      return $st->fetch() ?: null;
    } catch (Throwable $e) { return null; }
  }

  function dl_global(PDO $pdo): float {
    try {
      $st = $pdo->prepare("SELECT value FROM settings WHERE `key`='delivery_global' LIMIT 1");
      $st->execute();
      $v = $st->fetchColumn();
      if ($v !== false && is_numeric($v)) return (float)$v;
    } catch (Throwable $e) {}
    return (float)DL_DEFAULT_GLOBAL;
  }

  /** জেলার কার্যকর চার্জ: নিজস্ব (০ সহ) থাকলে সেটা, নইলে গ্লোবাল */
  function dl_effective_fee(?array $district, float $global): float {
    if ($district && $district['fee'] !== null && $district['fee'] !== '') return (float)$district['fee'];
    return $global;
  }

  /** orders.shipping_area (ENUM) এর সাথে সামঞ্জস্য: ঢাকা জেলা → dhaka, বাকি → nationwide */
  function dl_area_of(?array $district): string {
    return ($district && (string)$district['name_en'] === 'Dhaka') ? 'dhaka' : 'nationwide';
  }

  /**
   * ডেলিভারি চার্জের একমাত্র হিসাব — place_order.php এটাই ব্যবহার করে।
   * @return array{fee:float,district:?array,area:string,source:string}
   *   source: free (সব পণ্যে Free ট্যাগ) | district | global
   */
  function dl_quote(PDO $pdo, ?int $districtId, bool $allFree): array {
    $d = $districtId ? dl_get($pdo, $districtId) : null;
    $global = dl_global($pdo);
    $area = dl_area_of($d);
    if ($allFree) return ['fee' => 0.0, 'district' => $d, 'area' => $area, 'source' => 'free'];
    $own = $d && $d['fee'] !== null && $d['fee'] !== '';
    return ['fee' => dl_effective_fee($d, $global), 'district' => $d, 'area' => $area, 'source' => $own ? 'district' : 'global'];
  }

  /** অর্ডারে দেখানোর এলাকার নাম: জেলা থাকলে সেটা, নইলে পুরোনো ENUM */
  function dl_order_area_label(array $o): string {
    $dist = trim((string)($o['shipping_district'] ?? ''));
    if ($dist !== '') return $dist;
    $a = (string)($o['shipping_area'] ?? '');
    return $a === 'dhaka' ? 'ঢাকা' : ($a === 'nationwide' ? 'সারা দেশ' : $a);
  }

  /** '' → null (গ্লোবাল), সংখ্যা → float, অবৈধ → false */
  function dl_parse_fee($v) {
    if ($v === null) return null;
    if (!is_scalar($v)) return false;
    $v = trim((string)$v);
    if ($v === '') return null;
    if (!is_numeric($v)) return false;
    $f = (float)$v;
    if ($f < 0 || $f > DL_FEE_MAX) return false;
    return round($f, 2);
  }

  /** শুধু গ্লোবাল চার্জ সেভ (জেলার চার্জ ছোঁয় না) */
  function dl_save_global(PDO $pdo, $global): array {
    $g = dl_parse_fee($global);
    if ($g === false || $g === null) return [false, 'গ্লোবাল চার্জ ০ থেকে ' . number_format(DL_FEE_MAX) . ' টাকার মধ্যে একটি সংখ্যা হতে হবে।'];
    try {
      $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES('delivery_global',?) ON DUPLICATE KEY UPDATE value=VALUES(value)")
          ->execute([(string)$g]);
      return [true, 'গ্লোবাল চার্জ সংরক্ষণ হয়েছে।'];
    } catch (Throwable $e) {
      error_log('[delivery save global] ' . $e->getMessage());
      return [false, 'সংরক্ষণ করা যায়নি।'];
    }
  }

  /** শুধু জেলা-ভিত্তিক চার্জ সেভ (গ্লোবাল ছোঁয় না); সব-অথবা-কিছুই-না */
  function dl_save_fees(PDO $pdo, array $fees): array {
    $valid = [];
    foreach (dl_all($pdo) as $d) $valid[(int)$d['id']] = $d['name_bn'];
    $clean = [];
    foreach ($fees as $id => $val) {
      $id = (int)$id;
      if (!isset($valid[$id])) continue;
      $f = dl_parse_fee($val);
      if ($f === false) return [false, '“' . $valid[$id] . '” জেলার চার্জ সঠিক নয় (ফাঁকা, অথবা ০ থেকে ' . number_format(DL_FEE_MAX) . ')।'];
      $clean[$id] = $f;
    }
    try {
      $pdo->beginTransaction();
      $up = $pdo->prepare("UPDATE delivery_districts SET fee = ? WHERE id = ?");
      foreach ($clean as $id => $f) $up->execute([$f, $id]);
      $pdo->commit();
      return [true, 'জেলার চার্জ সংরক্ষণ হয়েছে।'];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      error_log('[delivery save fees] ' . $e->getMessage());
      return [false, 'সংরক্ষণ করা যায়নি।'];
    }
  }

  /**
   * গ্লোবাল চার্জ ও জেলা-ভিত্তিক চার্জ একসাথে সেভ (ট্রানজাকশনে)
   * @param array $fees [districtId => ইনপুট]
   * @return array{0:bool,1:string}
   */
  function dl_save(PDO $pdo, $global, array $fees): array {
    $g = dl_parse_fee($global);
    if ($g === false || $g === null) return [false, 'গ্লোবাল চার্জ ০ থেকে ' . number_format(DL_FEE_MAX) . ' টাকার মধ্যে একটি সংখ্যা হতে হবে।'];

    $valid = [];
    foreach (dl_all($pdo) as $d) $valid[(int)$d['id']] = $d['name_bn'];
    $clean = [];
    foreach ($fees as $id => $val) {
      $id = (int)$id;
      if (!isset($valid[$id])) continue;
      $f = dl_parse_fee($val);
      if ($f === false) return [false, '“' . $valid[$id] . '” জেলার চার্জ সঠিক নয় (ফাঁকা, অথবা ০ থেকে ' . number_format(DL_FEE_MAX) . ')।'];
      $clean[$id] = $f;
    }

    try {
      $pdo->beginTransaction();
      $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES('delivery_global',?) ON DUPLICATE KEY UPDATE value=VALUES(value)")
          ->execute([(string)$g]);
      $up = $pdo->prepare("UPDATE delivery_districts SET fee = ? WHERE id = ?");
      foreach ($clean as $id => $f) $up->execute([$f, $id]);
      $pdo->commit();
      return [true, 'ডেলিভারি চার্জ সংরক্ষণ হয়েছে।'];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      error_log('[delivery save] ' . $e->getMessage());
      return [false, 'সংরক্ষণ করা যায়নি।'];
    }
  }
}
