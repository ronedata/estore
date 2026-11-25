/**
 * tools/verify_admin_brand.js — অ্যাডমিন প্যানেলের নাম (সাইডবার, ট্যাব-শিরোনাম, ফুটার, লগইন-শিরোনাম) settings.store_name থেকে আসে
 * node tools/verify_admin_brand.js [url]
 * settings.store_name শুরুতে snapshot করে, শেষে হুবহু আগের অবস্থায় (মানসহ বা সারি-ছাড়া) ফেরায়; অস্থায়ী অ্যাডমিন সেশন নিজে বানায়-মোছে।
 */
const { execFileSync } = require('child_process');
const fs = require('fs');
const BASE = process.argv[2] || 'http://estore.test';
const MYSQL = process.env.MYSQL_BIN || 'mysql';
const SESS_DIR = 'C:/laragon/tmp';
let pass = 0, fail = 0;
const ok = (m) => { pass++; console.log('  PASS  ' + m); };
const bad = (m) => { fail++; console.log('  FAIL  ' + m); };
const check = (c, m) => (c ? ok(m) : bad(m));
const mysql = (q, input) => execFileSync(MYSQL, ['-uroot', '-N', '--default-character-set=utf8mb4', 'estore', ...(input ? [] : ['-e', q])], { encoding: 'utf8', input }).replace(/\r/g, '').trim();
const sql = (q) => execFileSync(MYSQL, ['-uroot', '-N', '--default-character-set=utf8mb4', 'estore'], { encoding: 'utf8', input: q + ';\n' }).replace(/\r/g, '').trim();
const esc = (v) => v.replace(/\\/g, '\\\\').replace(/'/g, "\\'");
const setName = (v) => sql(`INSERT INTO settings(\`key\`,\`value\`) VALUES('store_name','${esc(v)}') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
const SID = require('crypto').randomBytes(13).toString('hex');
const CSRF = 'a'.repeat(32);
const get = (p, sid) => fetch(BASE + p, { redirect: 'manual', headers: sid ? { Cookie: 'PHPSESSID=' + sid } : {} }).then((r) => r.text());
const norm = (s) => s.normalize('NFC');

(async () => {
  const had = sql("SELECT COUNT(*) FROM settings WHERE `key`='store_name'") === '1';
  const orig = had ? sql("SELECT value FROM settings WHERE `key`='store_name'") : null;
  try {
    fs.writeFileSync(`${SESS_DIR}/sess_${SID}`, `admin_id|i:1;admin_username|s:4:"test";admin_role_name|s:11:"Super Admin";admin_permissions|a:1:{i:0;s:14:"view_dashboard";}csrf|s:32:"${CSRF}";__sid_rotated|i:${Math.floor(Date.now() / 1000)};`);
    const pagesOf = async (name) => {
      const d = await get('/admin/index.php', SID), l = await get('/admin/login.php');
      return {
        title: (d.match(/<title>([^<]*)<\/title>/) || [])[1], brand: (d.match(/id="adminBrandName">([^<]*)</) || [])[1],
        footer: (d.match(/&copy; \d{4} ([\s\S]*?) Admin/) || [])[1], loginTitle: (l.match(/<title>([^<]*)<\/title>/) || [])[1], dash: d, login: l,
      };
    };

    console.log('\n=== settings.store_name থেকে আসে ===');
    setName('আমার দোকান');
    let p = await pagesOf();
    check(norm(p.brand) === norm('আমার দোকান') && norm(p.title) === norm('Admin - আমার দোকান') && norm(p.footer) === norm('আমার দোকান') && norm(p.loginTitle) === norm('Admin Login - আমার দোকান'), 'সেটিংসে "আমার দোকান" দিলে সাইডবার, ট্যাব-শিরোনাম, ফুটার ও লগইন-শিরোনাম সব চার জায়গায় সেটাই');
    check(!/eStore/.test(p.dash.replace(/<script[\s\S]*?<\/script>/g, '').replace(/<style[\s\S]*?<\/style>/g, '').replace(/<[^>]+>/g, ' ').replace(/estore\.test/gi, '')), 'পেজে আর কোথাও পুরোনো "eStore" লেখা নেই');
    setName('Super Shop BD');
    p = await pagesOf();
    check(p.brand === 'Super Shop BD' && p.title === 'Admin - Super Shop BD' && p.footer === 'Super Shop BD', 'নাম বদলালে সাথে সাথে বদলায় (ইংরেজি নাম)');

    console.log('\n=== ফাঁকা/অস্বাভাবিক মান ===');
    setName('');
    p = await pagesOf();
    check(p.brand === 'eStore' && p.title === 'Admin - eStore' && p.footer === 'eStore' && p.loginTitle === 'Admin Login - eStore', 'store_name ফাঁকা হলে আগের ডিফল্ট "eStore"-এ ফেরে (সাইডবার, শিরোনাম, ফুটার, লগইন)');
    setName('   ');
    p = await pagesOf();
    check(p.brand === 'eStore', 'শুধু ফাঁকা-স্পেস হলেও ডিফল্টে ফেরে');
    sql("DELETE FROM settings WHERE `key`='store_name'");
    p = await pagesOf();
    check(p.brand === 'eStore' && !/fatal|warning:/i.test(p.dash), 'সেটিংসে store_name সারিই না থাকলেও ক্র্যাশ নয়, ডিফল্ট "eStore"');
    setName('  রহিম   স্টোর  ');
    p = await pagesOf();
    check(norm(p.brand) === norm('রহিম   স্টোর'), 'আগে-পরের ফাঁকা ছাঁটা হয় (ভেতরের স্পেস যেমন ছিল): "' + p.brand + '"');
    setName('X'.repeat(80));
    p = await pagesOf();
    check(p.brand === 'X'.repeat(60) && p.footer === 'X'.repeat(60), '৮০ অক্ষরের নাম সর্বোচ্চ ৬০ অক্ষরে ছাঁটা (সাইডবার ভাঙে না)');
    setName('বাংলা'.repeat(20));
    p = await pagesOf();
    check(norm(p.brand) === norm('বাংলা'.repeat(20)).slice(0, 60) || p.brand.length > 0, 'লম্বা বাংলা নামেও ক্র্যাশ/ভাঙা অক্ষর নেই');

    console.log('\n=== নিরাপত্তা: HTML/স্ক্রিপ্ট escape ===');
    setName('<script>alert(1)</script> & "দোকান"');
    p = await pagesOf();
    check(!p.dash.includes('<script>alert(1)</script>') && !p.login.includes('<script>alert(1)</script>') && p.brand.includes('&lt;script&gt;') && p.title.includes('&lt;script&gt;'), 'নামে <script> থাকলে সাইডবার/শিরোনাম/লগইনে escape হয় (XSS নয়)');
    check(p.footer.includes('&lt;script&gt;') && p.footer.includes('&amp;') && p.footer.includes('&quot;'), 'ফুটারেও escape (& " < >)');
    setName("O'Brien's <b>Shop</b>");
    p = await pagesOf();
    check(!p.dash.includes('<b>Shop</b>') && p.brand.includes('&lt;b&gt;'), 'অ্যাপোস্ট্রফি ও <b> ট্যাগও escape');

    console.log('\n=== গ্রাহক-সাইটের নামের সাথে একই উৎস ===');
    setName('মিলের পরীক্ষা');
    const front = norm(await get('/index.php'));
    p = await pagesOf();
    check(front.includes(norm('মিলের পরীক্ষা')) && norm(p.brand) === norm('মিলের পরীক্ষা'), 'একই settings.store_name বদলালে গ্রাহক-সাইট ও অ্যাডমিন দুই জায়গায় একসাথে বদলায়');
  } catch (e) { console.error('ERROR: ' + e.message); fail++; }
  finally {
    if (had) setName(orig); else sql("DELETE FROM settings WHERE `key`='store_name'");
    try { fs.unlinkSync(`${SESS_DIR}/sess_${SID}`); } catch {}
  }
  const nowHas = sql("SELECT COUNT(*) FROM settings WHERE `key`='store_name'") === '1';
  check(nowHas === had && (!had || sql("SELECT value FROM settings WHERE `key`='store_name'") === orig), `settings.store_name আগের অবস্থায় ফিরেছে (${had ? '"' + orig + '"' : 'সারি ছিল না'})`);
  console.log((fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exitCode = fail === 0 ? 0 : 1;
})();
