<?php
/**
 * tools/test_session_security.php — সেশন কুকি ফ্ল্যাগ যাচাই (CLI, headers ছাড়া)
 * আসল কুকি হেডার যাচাই curl দিয়ে করা হয়; এখানে ini মান যাচাই।
 */
require __DIR__.'/../includes/config.php';

$checks = [
  'session.use_strict_mode'    => ini_get('session.use_strict_mode'),
  'session.use_only_cookies'   => ini_get('session.use_only_cookies'),
  'session.cookie_httponly'    => ini_get('session.cookie_httponly'),
  'session.cookie_samesite'    => ini_get('session.cookie_samesite'),
  'session.name'               => session_name(),
  'session status'             => session_status() === PHP_SESSION_ACTIVE ? 'active' : 'inactive',
];

$expect = [
  'session.use_strict_mode'  => '1',
  'session.use_only_cookies' => '1',
  'session.cookie_httponly'  => '1',
  'session.cookie_samesite'  => 'Lax',
];

$fail = 0;
foreach ($checks as $k => $v) {
  $ok = isset($expect[$k]) ? ($v === $expect[$k]) : true;
  if (!$ok) $fail++;
  printf("%-28s %-12s %s\n", $k, var_export($v, true), $ok ? 'PASS' : 'FAIL');
}

echo "\n" . ($fail === 0 ? "SESSION CONFIG OK\n" : "$fail FAILED\n");
exit($fail === 0 ? 0 : 1);
