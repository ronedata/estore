/**
 * tools/verify_backup.js — অ্যাডমিন ব্যাকআপ end-to-end
 * node tools/verify_backup.js [url]
 * আসল ডাউনলোড (fetch) → SQL অস্থায়ী ডাটাবেস estore_bk_verify-এ ইমপোর্ট ও মিলানো; জিপ খুলে যাচাই; Super Admin ছাড়া বন্ধ; CSRF।
 * অস্থায়ী অ্যাডমিন সেশন নিজে বানায়-মোছে; আসল ডাটাবেসে শুধু settings.backup_last_* লেখে ও আগের মান ফেরায়।
 */
const { execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const BASE = process.argv[2] || 'http://estore.test';
const MYSQL = process.env.MYSQL_BIN || 'mysql';
const DB = process.env.DB_NAME || 'estore';
const TMPDB = 'estore_bk_verify';
const SESS_DIR = 'C:/laragon/tmp';
let pass = 0, fail = 0;
const ok = (m) => { pass++; console.log('  PASS  ' + m); };
const bad = (m) => { fail++; console.log('  FAIL  ' + m); };
const check = (c, m) => (c ? ok(m) : bad(m));
const sql = (q, db = DB) => execFileSync(MYSQL, ['-uroot', '-N', '--default-character-set=utf8mb4', db], { encoding: 'utf8', input: q + ';\n' }).replace(/\r/g, '').trim();
const sh = (cmd, args) => execFileSync(cmd, args, { encoding: 'utf8' });

const SID = require('crypto').randomBytes(13).toString('hex');
const CSID = require('crypto').randomBytes(13).toString('hex');
const sess = (sid, role, perms) => fs.writeFileSync(`${SESS_DIR}/sess_${sid}`,
  `admin_id|i:1;admin_username|s:4:"test";admin_role_name|s:${role.length}:"${role}";admin_permissions|a:${perms.length}:{${perms.map((p, i) => `i:${i};s:${p.length}:"${p}";`).join('')}}csrf|s:32:"${'a'.repeat(32)}";__sid_rotated|i:${Math.floor(Date.now() / 1000)};`);
const CSRF = 'a'.repeat(32);
const csrfName = () => {
  const src = fs.readFileSync(path.join(__dirname, '..', 'includes', 'config.php'), 'utf8');
  const m = src.match(/function csrf_field\(\)[\s\S]*?name=["']([a-z_]+)["']/);
  return m ? m[1] : 'csrf';
};
const post = (pathq, body, sid) => fetch(BASE + pathq, { method: 'POST', redirect: 'manual', headers: { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: 'PHPSESSID=' + sid }, body: new URLSearchParams(body).toString() });

(async () => {
  const snap = {};
  for (const k of ['backup_last_db', 'backup_last_code', 'backup_last_uploads']) snap[k] = sql(`SELECT value FROM settings WHERE \`key\`='${k}'`);
  const work = fs.mkdtempSync(path.join(os.tmpdir(), 'bkv_'));
  try {
    sess(SID, 'Super Admin', ['view_dashboard']);
    sess(CSID, 'Manager', ['view_dashboard', 'settings']);
    const CN = csrfName();
    const T = { [CN]: CSRF };

    console.log('\n=== অ্যাক্সেস ===');
    let r = await fetch(BASE + '/admin/backup.php', { redirect: 'manual' });
    check(r.status === 302, 'লগইন ছাড়া পেজ বন্ধ (redirect ' + r.status + ')');
    r = await fetch(BASE + '/admin/backup.php', { redirect: 'manual', headers: { Cookie: 'PHPSESSID=' + CSID } });
    let html = await r.text();
    check(r.status === 403 && !html.includes('id="bkDbFull"'), 'Super Admin নয় এমন অ্যাডমিনের জন্য 403, বাটন নেই');
    r = await post('/admin/backup.php', { ...T, action: 'db_full' }, CSID);
    let b = await r.text();
    check(!b.includes('CREATE TABLE') && r.status === 403, 'Super Admin নয়, তাই POST-এও ডাউনলোড হয় না');
    r = await post('/admin/backup.php', { action: 'db_full' }, SID);
    b = await r.text();
    check(r.status === 302 && !b.includes('CREATE TABLE'), 'CSRF ছাড়া POST → ডাউনলোড নয়');
    r = await post('/admin/backup.php', { [CN + '[]']: '1', action: 'db_full' }, SID);
    check(!(await r.text()).includes('CREATE TABLE'), 'CSRF অ্যারে দিলে ডাউনলোড নয়, ক্র্যাশ নয়');
    r = await post('/admin/backup.php', { ...T, action: 'hack' }, SID);
    check(r.status === 302, 'অজানা অ্যাকশন বাতিল');

    console.log('\n=== পেজ ===');
    r = await fetch(BASE + '/admin/backup.php', { headers: { Cookie: 'PHPSESSID=' + SID } });
    html = await r.text();
    check(r.status === 200 && ['bkDbFull', 'bkDbDemo', 'bkZipCode', 'bkZipUploads'].every((i) => html.includes('id="' + i + '"')), 'পেজে ৪টি ডাউনলোড বাটন আছে');
    check(/id="bkDbInfo">\d+টি টেবিল/.test(html.normalize('NFC')) || html.includes('id="bkDbInfo"'), 'ডাটাবেসের সারাংশ আছে');
    check(!/fatal|warning:|notice:/i.test(html), 'পেজে PHP ত্রুটি নেই');
    check(html.includes('href="' + BASE.replace(/^https?:\/\/[^/]+/, '') + '/admin/backup.php"') || html.includes('/admin/backup.php"'), 'সাইডবারে ব্যাকআপ মেনু আছে');

    console.log('\n=== সম্পূর্ণ ডাটাবেস ডাউনলোড ===');
    r = await post('/admin/backup.php', { ...T, action: 'db_full' }, SID);
    check(r.status === 200 && /attachment; filename="estore_db_\d{8}_\d{6}\.sql"/.test(r.headers.get('content-disposition') || ''), 'ফাইল-নাম ঠিক, attachment');
    check(/no-store/.test(r.headers.get('cache-control') || ''), 'Cache-Control: no-store');
    const full = Buffer.from(await r.arrayBuffer()).toString('utf8');
    fs.writeFileSync(path.join(work, 'full.sql'), full);
    check(full.includes('CREATE TABLE `orders`') && full.includes('DROP TABLE IF EXISTS `orders`'), 'কাঠামো আছে');
    sql(`DROP DATABASE IF EXISTS ${TMPDB}; CREATE DATABASE ${TMPDB} CHARACTER SET utf8mb4`, 'mysql');
    execFileSync(MYSQL, ['-uroot', '--default-character-set=utf8mb4', TMPDB], { input: full, encoding: 'utf8', maxBuffer: 1 << 28 });
    const tables = sql('SHOW TABLES', DB).split('\n').filter(Boolean);
    const tables2 = sql('SHOW TABLES', TMPDB).split('\n').filter(Boolean);
    check(tables.length === tables2.length && tables.every((t) => tables2.includes(t)), `ইমপোর্টে সব টেবিল ফিরেছে (${tables2.length}টি)`);
    let diff = [];
    for (const t of tables) {
      const a = sql(`SELECT COUNT(*) FROM \`${t}\``, DB), c = sql(`SELECT COUNT(*) FROM \`${t}\``, TMPDB);
      if (a !== c) diff.push(`${t}: ${a}≠${c}`);
    }
    check(diff.length === 0, 'প্রতিটি টেবিলের সারি সংখ্যা মূলের সমান' + (diff.length ? ' — ' + diff.join(', ') : ''));
    const cks = (db) => ['products', 'categories', 'settings', 'orders', 'delivery_districts'].map((t) => sql(`CHECKSUM TABLE \`${t}\``, db).split('\t')[1]).join(',');
    check(cks(DB) === cks(TMPDB), 'চেকসাম মেলে (বাংলা টেক্সট, NULL, কোটসহ হুবহু)');
    check(sql(`SELECT COUNT(*) FROM admin_users`, TMPDB) === sql(`SELECT COUNT(*) FROM admin_users`, DB), 'সম্পূর্ণ ডাম্পে অ্যাডমিন ইউজার আছে');

    console.log('\n=== ডেমো ডাটাবেস ডাউনলোড ===');
    r = await post('/admin/backup.php', { ...T, action: 'db_demo' }, SID);
    const demo = Buffer.from(await r.arrayBuffer()).toString('utf8');
    check(r.status === 200 && /filename="estore_demo_/.test(r.headers.get('content-disposition') || ''), 'ডেমো ফাইল-নাম ঠিক');
    sql(`DROP DATABASE IF EXISTS ${TMPDB}; CREATE DATABASE ${TMPDB} CHARACTER SET utf8mb4`, 'mysql');
    execFileSync(MYSQL, ['-uroot', '--default-character-set=utf8mb4', TMPDB], { input: demo, encoding: 'utf8', maxBuffer: 1 << 28 });
    check(sql('SHOW TABLES', TMPDB).split('\n').filter(Boolean).length === tables.length, 'ডেমোতেও সব টেবিলের কাঠামো আছে');
    const empties = ['customers', 'orders', 'order_items', 'contact_messages', 'product_reviews', 'page_views', 'subscribers', 'wishlists', 'admin_users', 'announcements', 'login_attempts', 'login_locks', 'tracking_attempts'];
    check(empties.every((t) => sql(`SELECT COUNT(*) FROM \`${t}\``, TMPDB) === '0'), 'ব্যক্তিগত ১৩টি টেবিল খালি');
    check(sql(`SELECT COUNT(*) FROM settings WHERE \`key\` IN ('store_phone','store_email')`, TMPDB) === '0', 'store_phone/store_email নেই');
    check(sql('SELECT COUNT(*) FROM products', TMPDB) === sql('SELECT COUNT(*) FROM products', DB), 'পণ্য সব আছে');
    check(!/\$2y\$/.test(demo), 'ডেমোতে পাসওয়ার্ড-হ্যাশ নেই');

    console.log('\n=== জিপ ===');
    const unzipList = (zip) => {
      const out = execFileSync('powershell', ['-NoProfile', '-Command', `Add-Type -A System.IO.Compression.FileSystem; [IO.Compression.ZipFile]::OpenRead('${zip.replace(/\//g, '\\')}').Entries | ForEach-Object { $_.FullName }`], { encoding: 'utf8' });
      return out.split(/\r?\n/).filter(Boolean);
    };
    r = await post('/admin/backup.php', { ...T, action: 'zip_code' }, SID);
    check(r.status === 200 && /filename="estore_code_\d{8}_\d{6}\.zip"/.test(r.headers.get('content-disposition') || ''), 'কোড জিপ: ফাইল-নাম ঠিক');
    const buf = Buffer.from(await r.arrayBuffer());
    check(buf.slice(0, 2).toString() === 'PK' && Number(r.headers.get('content-length')) === buf.length, 'বৈধ জিপ, Content-Length মেলে (' + buf.length + ' B)');
    const zc = path.join(work, 'code.zip'); fs.writeFileSync(zc, buf);
    const ent = unzipList(zc);
    check(ent.includes('index.php') && ent.includes('admin/backup.php') && ent.includes('includes/backup.php') && ent.includes('.htaccess'), 'index.php, admin/, includes/, .htaccess আছে (' + ent.length + 'টি ফাইল)');
    check(!ent.some((e) => /^(uploads|logs|database|tools|docs|\.git)\//.test(e)), 'uploads/logs/database/tools/docs/.git নেই');
    check(!ent.includes('includes/config.php') && !ent.includes('includes/live.config.php'), 'config ফাইল (ডাটাবেস পাসওয়ার্ড) জিপে নেই');
    check(!ent.some((e) => /\.(sql|log|env|zip)$/i.test(e)), '.sql/.log/.env/.zip নেই');
    const local = fs.readdirSync(path.join(__dirname, '..')).filter((f) => f.endsWith('.php')).length;
    check(ent.filter((e) => !e.includes('/') && e.endsWith('.php')).length === local, 'রুটের সব ' + local + 'টি .php ফাইল আছে');

    r = await post('/admin/backup.php', { ...T, action: 'zip_uploads' }, SID);
    const ub = Buffer.from(await r.arrayBuffer());
    check(r.status === 200 && ub.slice(0, 2).toString() === 'PK', 'ছবির জিপ বৈধ');
    const zu = path.join(work, 'up.zip'); fs.writeFileSync(zu, ub);
    const ue = unzipList(zu);
    const upCount = fs.readdirSync(path.join(__dirname, '..', 'uploads'), { recursive: true, withFileTypes: true }).filter((d) => d.isFile()).length;
    check(ue.length === upCount && ue.every((e) => e.startsWith('uploads/')), `ছবির জিপে uploads/-এর সব ${upCount}টি ফাইল, আর কিছু নয়`);

    console.log('\n=== শেষ ব্যাকআপের তারিখ ===');
    const stamp = (k) => sql(`SELECT value FROM settings WHERE \`key\`='backup_last_${k}'`);
    check(['db', 'code', 'uploads'].every((k) => /^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/.test(stamp(k))), 'তিন ধরনের শেষ ব্যাকআপের সময় রেকর্ড হয়েছে');
    sql("DELETE FROM settings WHERE `key`='backup_last_db'");
    html = await (await fetch(BASE + '/admin/backup.php', { headers: { Cookie: 'PHPSESSID=' + SID } })).text();
    check(html.includes('id="bkStale"'), 'ডাটাবেস ব্যাকআপ কখনো না নিলে সতর্কতা দেখায়');
    sql(`INSERT INTO settings(\`key\`,\`value\`) VALUES('backup_last_db', DATE_SUB(NOW(), INTERVAL 8 DAY)) ON DUPLICATE KEY UPDATE value=VALUES(value)`);
    html = await (await fetch(BASE + '/admin/backup.php', { headers: { Cookie: 'PHPSESSID=' + SID } })).text();
    check(html.includes('id="bkStale"'), '৮ দিন আগের হলে সতর্কতা');
    sql(`UPDATE settings SET value=NOW() WHERE \`key\`='backup_last_db'`);
    html = await (await fetch(BASE + '/admin/backup.php', { headers: { Cookie: 'PHPSESSID=' + SID } })).text();
    check(!html.includes('id="bkStale"'), 'আজ নিলে সতর্কতা নেই');
  } catch (e) { console.error('ERROR: ' + e.message); fail++; }
  finally {
    for (const [k, v] of Object.entries(snap)) {
      if (v) sql(`INSERT INTO settings(\`key\`,\`value\`) VALUES('${k}','${v}') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
      else sql(`DELETE FROM settings WHERE \`key\`='${k}'`);
    }
    try { sql(`DROP DATABASE IF EXISTS ${TMPDB}`, 'mysql'); } catch {}
    for (const s of [SID, CSID]) try { fs.unlinkSync(`${SESS_DIR}/sess_${s}`); } catch {}
    try { fs.rmSync(work, { recursive: true, force: true }); } catch {}
  }
  console.log((fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exitCode = fail === 0 ? 0 : 1;
})();
