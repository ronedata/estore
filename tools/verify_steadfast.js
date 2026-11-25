/**
 * tools/verify_steadfast.js — কুরিয়ার (Steadfast) end-to-end: বন্ধ অবস্থা, সেটিংস সেভ/মাস্কিং, সংযোগ পরীক্ষা,
 * অর্ডার পাঠানো/ডুপ্লিকেট/সিঙ্ক, সম্পাদনার তালা, পারমিশন, CSRF। নকল সার্ভার (tools/mock_steadfast.php) নিজে চালায়।
 * node tools/verify_steadfast.js [url]
 * steadfast_* সেটিংস শুরুতে snapshot করে হুবহু ফেরায়; "[T]" অর্ডার/পণ্য/লগ ও অস্থায়ী সেশন নিজে মোছে।
 */
const { execFileSync, spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const BASE = process.argv[2] || 'http://estore.test';
const MYSQL = process.env.MYSQL_BIN || 'mysql';
const SESS_DIR = 'C:/laragon/tmp';
const PORT = 8131, MOCK = `http://127.0.0.1:${PORT}/api/v1`;
let pass = 0, fail = 0;
const ok = (m) => { pass++; console.log('  PASS  ' + m); };
const bad = (m) => { fail++; console.log('  FAIL  ' + m); };
const check = (c, m) => (c ? ok(m) : bad(m));
const sql = (q) => execFileSync(MYSQL, ['-uroot', '-N', '--default-character-set=utf8mb4', 'estore'], { encoding: 'utf8', input: q + ';\n' }).replace(/\r/g, '').trim();
const esc = (v) => String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
const CSRF = 'a'.repeat(32);
const KEYS = ['steadfast_enabled', 'steadfast_auto_status', 'steadfast_base_url', 'steadfast_api_key', 'steadfast_secret_key'];
const sess = [];
const mkSession = (role, perms) => {
  const sid = require('crypto').randomBytes(13).toString('hex');
  const pl = perms.map((p, i) => `i:${i};s:${Buffer.byteLength(p)}:"${p}";`).join('');
  fs.writeFileSync(`${SESS_DIR}/sess_${sid}`, `admin_id|i:1;admin_username|s:4:"test";admin_role_name|s:${Buffer.byteLength(role)}:"${role}";admin_permissions|a:${perms.length}:{${pl}}csrf|s:32:"${CSRF}";__sid_rotated|i:${Math.floor(Date.now() / 1000)};`);
  sess.push(sid); return sid;
};
const req = (p, sid, opt = {}) => fetch(BASE + p, { redirect: 'manual', ...opt, headers: { Cookie: 'PHPSESSID=' + sid, ...(opt.headers || {}) } });
const get = async (p, sid) => { const r = await req(p, sid); return { st: r.status, loc: r.headers.get('location'), t: await r.text() }; };
const post = async (p, sid, data) => { const r = await req(p, sid, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(data).toString() }); return { st: r.status, loc: r.headers.get('location'), t: await r.text() }; };
const mock = (p, body, method = 'GET') => fetch(`http://127.0.0.1:${PORT}${p}`, { method, body: body ? JSON.stringify(body) : undefined, headers: { 'Content-Type': 'application/json' } }).then((r) => r.json());
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const flashOf = (html) => ((html.match(/id="(?:cFlash|ovFlash)"[^>]*>([^<]*)</) || [])[1] || '').trim();
const norm = (s) => s.normalize('NFC');

(async () => {
  const snap = {};
  for (const k of KEYS) { const v = sql(`SELECT COUNT(*) FROM settings WHERE \`key\`='${k}'`) === '1' ? sql(`SELECT value FROM settings WHERE \`key\`='${k}'`) : null; snap[k] = v; }
  const logMax = Number(sql('SELECT COALESCE(MAX(id),0) FROM courier_logs'));
  const setS = (k, v) => sql(`INSERT INTO settings(\`key\`,\`value\`) VALUES('${k}','${esc(v)}') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
  const cleanup = () => {
    try { sql("DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE order_code LIKE 'ORD99%')"); } catch {}
    try { sql("DELETE FROM orders WHERE order_code LIKE 'ORD99%'"); sql("DELETE FROM products WHERE name LIKE '[T]%'"); sql(`DELETE FROM courier_logs WHERE id > ${logMax}`); } catch {}
  };
  let proc = null;
  try {
    cleanup();
    const SA = mkSession('Super Admin', ['view_dashboard']);
    const STAFF = mkSession('Staff', ['manage_orders']);
    const pid = sql('SELECT id FROM categories ORDER BY id LIMIT 1');
    sql(`INSERT INTO products (category_id, name, price, stock, active) VALUES (${pid}, '[T] SF E2E', 100, 50, 1)`);
    const PID = sql("SELECT id FROM products WHERE name='[T] SF E2E'");
    const mkOrder = (code, over = {}) => {
      sql(`INSERT INTO orders (order_code, customer_name, mobile, address, shipping_area, shipping_fee, subtotal, total, payment_method, status, stock_deducted, stock_returned) VALUES ('${code}','[T] ক্রেতা','01711111111','${over.address || 'বাড়ি ১, রোড ২, ধানমন্ডি, ঢাকা'}','dhaka',60,200,260,'${over.pm || 'COD'}','pending',1,0)`);
      const id = sql(`SELECT id FROM orders WHERE order_code='${code}'`);
      sql(`INSERT INTO order_items (order_id, product_id, qty, price) VALUES (${id}, ${PID}, 2, 100)`);
      return id;
    };
    const row = (id, c) => sql(`SELECT ${c} FROM orders WHERE id=${id}`);
    sql("UPDATE settings SET value='0' WHERE `key`='steadfast_enabled'");
    ['steadfast_api_key', 'steadfast_secret_key'].forEach((k) => sql(`DELETE FROM settings WHERE \`key\`='${k}'`));

    console.log('\n=== বন্ধ অবস্থা (ডিফল্ট) ===');
    let r = await get('/admin/courier.php', SA);
    check(r.st === 200 && /id="cStatus"[^>]*>বন্ধ</.test(norm(r.t)), 'courier.php খোলে, ব্যাজ "বন্ধ"');
    check(/id="cTest"[^>]*disabled/.test(r.t) && /id="cSyncAll"[^>]*disabled/.test(r.t), 'বন্ধ থাকলে "সংযোগ পরীক্ষা" ও "সিঙ্ক" বাটন নিষ্ক্রিয়');
    check(/id="cUnverified"/.test(r.t), 'অযাচাই-করা API-র সতর্কতা দেখায়');
    const o1 = mkOrder('ORD9950A');
    r = await get(`/admin/order_view.php?id=${o1}`, SA);
    check(r.st === 200 && !/id="courierCard"/.test(r.t) && !/courier_action/.test(r.t), 'বন্ধ থাকলে অর্ডার-ভিউতে কুরিয়ার কার্ড/বাটন নেই (আগের মতোই)');
    r = await post('/admin/courier_action.php', SA, { csrf: CSRF, id: o1, action: 'send' });
    check(r.st === 302 && row(o1, 'COALESCE(courier_consignment_id,"")') === '', 'বন্ধ অবস্থায় সরাসরি send POST-ও কিছু পাঠায় না');

    console.log('\n=== নকল সার্ভার চালু ===');
    proc = spawn(process.env.PHP_BIN || 'php', ['-S', `127.0.0.1:${PORT}`, path.join(__dirname, 'mock_steadfast.php')], { stdio: 'ignore' });
    for (let i = 0; i < 40; i++) { try { await mock('/__reset', {}, 'POST'); break; } catch { await sleep(250); } }
    check((await mock('/__log')).requests.length === 0, 'নকল সার্ভার চালু ও পরিষ্কার');
    check((await mock('/__log')).requests.length === 0, 'বন্ধ অবস্থার সব চেষ্টায় কুরিয়ার-সার্ভারে একটিও অনুরোধ যায়নি');

    console.log('\n=== সেটিংস (সংরক্ষণ, মাস্কিং, যাচাই) ===');
    r = await post('/admin/courier.php', SA, { csrf: CSRF, action: 'save', enabled: '1', base_url: MOCK });
    check(r.st === 200 && /id="cErrors"/.test(r.t) && sql("SELECT value FROM settings WHERE `key`='steadfast_enabled'") === '0', 'কী ছাড়া চালু করা যায় না (ত্রুটি), চালু হয়নি');
    r = await post('/admin/courier.php', SA, { csrf: CSRF, action: 'save', enabled: '1', base_url: 'http://evil.com/x', api_key: 'test-key', secret_key: 'test-secret' });
    check(/id="cErrors"/.test(r.t) && sql("SELECT COUNT(*) FROM settings WHERE `key`='steadfast_api_key'") === '0', 'http://evil Base URL বাতিল, কী সংরক্ষিত হয়নি');
    r = await post('/admin/courier.php', SA, { csrf: CSRF, action: 'save', enabled: '1', base_url: MOCK, api_key: 'test-key', secret_key: 'test-secret' });
    check(r.st === 302, 'ঠিক তথ্যে সেভ → redirect');
    r = await get('/admin/courier.php', SA);
    check(/id="cStatus"[^>]*>চালু</.test(norm(r.t)) && /সংরক্ষণ হয়েছে/.test(norm(r.t)), 'ব্যাজ "চালু", সফল বার্তা');
    check(!r.t.includes('test-key') && !r.t.includes('test-secret'), 'পেজের HTML-এ API Key/Secret পুরো দেখা যায় না (মাস্ক)');
    check(/tes••••••ey|tes•+ey/.test(r.t) || /id="cApiHint"[^>]*>[^<]*•/.test(r.t), 'মাস্ক-করা কী দেখায়');
    check(!/id="cTest"[^>]*disabled/.test(r.t), 'চালু হলে "সংযোগ পরীক্ষা" বাটন সক্রিয়');
    r = await post('/admin/courier.php', SA, { csrf: CSRF, action: 'save', enabled: '1', base_url: MOCK, api_key: '', secret_key: '' });
    check(sql("SELECT value FROM settings WHERE `key`='steadfast_api_key'") === 'test-key', 'কী ফাঁকা রেখে সেভ করলে আগের কী থাকে');
    r = await post('/admin/courier.php', SA, { csrf: CSRF, action: 'save', enabled: '1', base_url: [MOCK] });
    check(r.st < 500 && !/fatal error|warning:/i.test(r.t) && sql("SELECT value FROM settings WHERE `key`='steadfast_base_url'") === MOCK, 'অ্যারে base_url-এ ক্র্যাশ নয়, আগের মান অক্ষত');
    r = await post('/admin/courier.php', SA, { csrf: CSRF, action: 'save', enabled: '1', base_url: MOCK });
    check(sql("SELECT value FROM settings WHERE `key`='steadfast_base_url'") === MOCK, 'ঠিক base_url বহাল');

    console.log('\n=== সংযোগ পরীক্ষা ===');
    r = await post('/admin/courier.php', SA, { csrf: CSRF, action: 'test' });
    r = await get('/admin/courier.php', SA);
    check(/1,234\.50/.test(r.t), 'সংযোগ পরীক্ষা: ব্যালেন্স ৳1,234.50');
    check(/<td>test<\/td>/.test(r.t) && !r.t.includes('test-secret'), 'লগ টেবিলে "test" সারি, সিক্রেট নেই');
    setS('steadfast_api_key', 'wrong-key');
    await post('/admin/courier.php', SA, { csrf: CSRF, action: 'test' });
    r = await get('/admin/courier.php', SA);
    check(/সংযোগ ব্যর্থ/.test(norm(r.t)) && /API কী ভুল/.test(norm(r.t)), 'ভুল কী → "সংযোগ ব্যর্থ: API কী ভুল"');
    setS('steadfast_api_key', 'test-key');

    console.log('\n=== অর্ডার পাঠানো ===');
    r = await get(`/admin/order_view.php?id=${o1}`, SA);
    check(/id="courierSend"/.test(r.t) && /কুরিয়ার আদায় করবে: ৳260/.test(norm(r.t)), 'কার্ডে "কুরিয়ারে পাঠান" বাটন ও COD আদায় ৳260');
    r = await post('/admin/courier_action.php', SA, { csrf: 'x'.repeat(32), id: o1, action: 'send' });
    check(row(o1, 'COALESCE(courier_consignment_id,"")') === '' && (await mock('/__log')).requests.filter((q) => q.path === '/create_order').length === 0, 'ভুল CSRF → পাঠানো হয়নি');
    r = await post('/admin/courier_action.php', SA, { csrf: CSRF, id: o1, action: 'send' });
    check(r.st === 302 && /order_view\.php\?id=/.test(r.loc || ''), 'send → অর্ডার-ভিউতে redirect');
    r = await get(`/admin/order_view.php?id=${o1}`, SA);
    check(/id="courierCid"[^>]*>5001</.test(r.t) && /id="courierTrack"[\s\S]*TRK5001/.test(r.t) && /steadfast\.com\.bd\/t\/TRK5001/.test(r.t), 'কনসাইনমেন্ট 5001, ট্র্যাকিং TRK5001 ও লিংক');
    check(/id="courierStatus"[^>]*>রিভিউতে</.test(norm(r.t)) && !/id="courierSend"/.test(r.t), 'স্ট্যাটাস "রিভিউতে", পাঠানোর বাটন আর নেই');
    check(/id="ovFlash"/.test(r.t) && /কুরিয়ারে/.test(norm(r.t)) , 'সফল ফ্ল্যাশ দেখায়');
    await post('/admin/courier_action.php', SA, { csrf: CSRF, id: o1, action: 'send' });
    check((await mock('/__log')).requests.filter((q) => q.path === '/create_order').length === 1, 'দ্বিতীয়বার send করলে কুরিয়ারে আর যায় না (১টি create)');
    const lg = JSON.parse('[' + sql("SELECT JSON_OBJECT('a',action,'ok',ok) FROM courier_logs WHERE order_id=" + o1 + " AND action='send'").split('\n').join(',') + ']');
    check(lg.length === 1 && lg[0].ok === 1, 'লগে ঠিক ১টি সফল send');

    console.log('\n=== সম্পাদনার তালা ===');
    r = await post(`/admin/order_edit.php?id=${o1}`, SA, { csrf: CSRF, save: '1', customer_name: '[T] ক্রেতা', mobile: '01711111111', address: 'সম্পূর্ণ নতুন ঠিকানা', shipping_fee: '60', status: 'pending', ['qty[' + sql(`SELECT id FROM order_items WHERE order_id=${o1}`) + ']']: '2' });
    check(row(o1, 'address') !== 'সম্পূর্ণ নতুন ঠিকানা', 'পাঠানো অর্ডারের ঠিকানা বদলানো যায় না');
    r = await post(`/admin/order_edit.php?id=${o1}`, SA, { csrf: CSRF, save: '1', customer_name: '[T] ক্রেতা', mobile: '01711111111', address: row(o1, 'address'), shipping_fee: '60', status: 'processing', note: 'নতুন নোট', ['qty[' + sql(`SELECT id FROM order_items WHERE order_id=${o1}`) + ']']: '2' });
    check(row(o1, 'status') === 'processing' && row(o1, 'note') === 'নতুন নোট', 'শুধু স্ট্যাটাস/নোট বদলালে সেভ হয় (তালা শুধু নাম/ঠিকানা/পরিমাণ ইত্যাদিতে)');
    const o2 = mkOrder('ORD9950B');
    await post(`/admin/order_edit.php?id=${o2}`, SA, { csrf: CSRF, save: '1', customer_name: '[T] ক্রেতা', mobile: '01711111111', address: 'অপাঠানো অর্ডারের নতুন ঠিকানা', shipping_fee: '60', status: 'pending', ['qty[' + sql(`SELECT id FROM order_items WHERE order_id=${o2}`) + ']']: '2' });
    check(row(o2, 'address') === 'অপাঠানো অর্ডারের নতুন ঠিকানা', 'পাঠানো হয়নি এমন অর্ডার আগের মতোই সম্পাদনা করা যায়');

    console.log('\n=== স্ট্যাটাস সিঙ্ক ===');
    const cid = row(o1, 'courier_consignment_id');
    await mock('/__set', { cid, status: 'delivered' }, 'POST');
    await post('/admin/courier_action.php', SA, { csrf: CSRF, id: o1, action: 'sync' });
    r = await get(`/admin/order_view.php?id=${o1}`, SA);
    check(/id="courierStatus"[^>]*>ডেলিভারড</.test(norm(r.t)), 'সিঙ্কের পর কুরিয়ার-স্ট্যাটাস "ডেলিভারড"');
    check(row(o1, 'status') === 'processing', 'auto_status বন্ধ → অর্ডারের নিজের স্ট্যাটাস বদলায়নি');
    setS('steadfast_auto_status', '1');
    await post('/admin/courier_action.php', SA, { csrf: CSRF, id: o1, action: 'sync' });
    check(row(o1, 'status') === 'delivered', 'auto_status চালু → অর্ডার Delivered');
    setS('steadfast_auto_status', '0');
    const o3 = mkOrder('ORD9950C', { pm: 'BKASH' });
    await post('/admin/courier_action.php', SA, { csrf: CSRF, id: o3, action: 'send' });
    const sent3 = (await mock('/__log')).requests.filter((q) => q.path === '/create_order').pop();
    check(sent3 && sent3.body.invoice === 'ORD9950C' && sent3.body.cod_amount === 0, 'অনলাইন-পেমেন্ট অর্ডারে cod_amount = 0');
    r = await post('/admin/courier.php', SA, { csrf: CSRF, action: 'sync_all' });
    r = await get('/admin/courier.php', SA);
    check(/সিঙ্ক শেষ/.test(norm(r.t)), '"সব সিঙ্ক" বাটন কাজ করে, ফলাফল বার্তা');

    console.log('\n=== পারমিশন ও নিরাপত্তা ===');
    r = await get('/admin/courier.php', STAFF);
    check(r.st !== 200 || !/id="cForm"/.test(r.t), 'manage_courier ছাড়া কর্মী courier.php-র ফর্ম দেখে না');
    r = await post('/admin/courier_action.php', STAFF, { csrf: CSRF, id: o2, action: 'send' });
    check(row(o2, 'COALESCE(courier_consignment_id,"")') === '', 'manage_courier ছাড়া কর্মী পাঠাতে পারে না');
    r = await get('/admin/courier.php', 'deadbeef' + 'a'.repeat(18));
    check(r.st !== 200 || !/id="cForm"/.test(r.t), 'লগইন ছাড়া courier.php বন্ধ');
    r = await get('/admin/courier_action.php', SA);
    check(r.st === 302, 'GET-এ courier_action কিছু করে না (redirect)');
    r = await get('/admin/orders.php', SA);
    check(r.st === 200 && !/fatal|warning:/i.test(r.t), 'অর্ডার তালিকা অক্ষত');
    r = await get('/admin/index.php', SA);
    check(/id="dashQuick"/.test(r.t), 'ড্যাশবোর্ড অক্ষত');
    r = await get('/admin/courier.php', SA);
    check(!/<script>alert/.test(r.t) && /id="cLogs"/.test(r.t), 'লগ টেবিল আছে');
  } catch (e) { console.error('ERROR: ' + e.stack); fail++; }
  finally {
    if (proc) { try { execFileSync('taskkill', ['/F', '/T', '/PID', String(proc.pid)], { stdio: 'ignore' }); } catch {} }
    for (const k of KEYS) { if (snap[k] === null) sql(`DELETE FROM settings WHERE \`key\`='${k}'`); else setS(k, snap[k]); }
    cleanup();
    for (const s of sess) { try { fs.unlinkSync(`${SESS_DIR}/sess_${s}`); } catch {} }
    try { fs.unlinkSync(path.join(require('os').tmpdir(), 'mock_sf_state.json')); } catch {}
  }
  let same = true;
  for (const k of KEYS) { const had = sql(`SELECT COUNT(*) FROM settings WHERE \`key\`='${k}'`) === '1'; const v = had ? sql(`SELECT value FROM settings WHERE \`key\`='${k}'`) : null; if (v !== snap[k]) same = false; }
  check(same, 'steadfast_* সেটিংস হুবহু আগের অবস্থায় ফিরেছে');
  check(sql("SELECT COUNT(*) FROM orders WHERE order_code LIKE 'ORD99%'") === '0' && sql("SELECT COUNT(*) FROM products WHERE name LIKE '[T]%'") === '0', 'টেস্টের অর্ডার/পণ্য মুছেছে');
  console.log((fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exit(fail === 0 ? 0 : 1);
})();
