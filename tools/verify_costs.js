/**
 * tools/verify_costs.js — ক্রয়মূল্য, অর্ডার-আইটেমে snapshot, স্টক রিপোর্টের মূল্য, খরচ পেজ end-to-end
 * node tools/verify_costs.js [url]
 * "[T]" পণ্য/অর্ডার/খরচ ও অস্থায়ী অ্যাডমিন সেশন নিজে বানায়-মোছে; আসল পণ্য/স্টক বদলায় না।
 */
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const BASE = process.argv[2] || 'http://estore.test';
const PORT = Number(process.env.PORT_CO || 9500);
const CHROME = process.env.NAV_BROWSER || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const MYSQL = process.env.MYSQL_BIN || 'mysql';
const SESS_DIR = 'C:/laragon/tmp';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let pass = 0, fail = 0;
const ok = (m) => { pass++; console.log('  PASS  ' + m); };
const bad = (m) => { fail++; console.log('  FAIL  ' + m); };
const check = (c, m) => (c ? ok(m) : bad(m));
const sql = (q) => execFileSync(MYSQL, ['-uroot', '-N', '--default-character-set=utf8mb4', 'estore'], { encoding: 'utf8', input: q + ';\n' }).replace(/\r/g, '').trim();
const rid = () => require('crypto').randomBytes(13).toString('hex');
const S = { admin: rid(), exp: rid(), noexp: rid() };
const CSRF = 'a'.repeat(32);
const mkSess = (sid, role, perms, id) => fs.writeFileSync(`${SESS_DIR}/sess_${sid}`,
  `admin_id|i:${id};admin_username|s:2:"tt";admin_role_name|s:${role.length}:"${role}";admin_permissions|a:${perms.length}:{${perms.map((p, i) => `i:${i};s:${p.length}:"${p}";`).join('')}}csrf|s:32:"${CSRF}";__sid_rotated|i:${Math.floor(Date.now() / 1000)};`);
const H = (sid) => (sid ? { Cookie: 'PHPSESSID=' + sid } : {});
const get = (p, sid) => fetch(BASE + p, { redirect: 'manual', headers: H(sid) });
const text = async (p, sid) => (await get(p, sid)).text();
const form = (p, body, sid) => fetch(BASE + p, { method: 'POST', redirect: 'manual', headers: { ...H(sid), 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(body).toString() });
const idv = (h, id) => { const m = h.match(new RegExp(`id="${id}">([^<]*)<`)); return m ? m[1].trim() : null; };
const money = (n) => '৳' + Math.round(n).toLocaleString('en-US');
const NL = String.fromCharCode(10);

function send(ws, id, method, params = {}) {
  return new Promise((resolve, reject) => {
    const to = setTimeout(() => reject(new Error('timeout: ' + method)), 30000);
    const onMsg = (ev) => { let m; try { m = JSON.parse(ev.data); } catch { return; } if (m.id === id) { clearTimeout(to); ws.removeEventListener('message', onMsg); m.error ? reject(new Error(JSON.stringify(m.error))) : resolve(m.result); } };
    ws.addEventListener('message', onMsg);
    ws.send(JSON.stringify({ id, method, params }));
  });
}
function cleanup() {
  try {
    sql("DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE customer_name LIKE '[T]%')");
    sql("DELETE FROM order_items WHERE product_id IN (SELECT id FROM products WHERE name LIKE '[T]%')");
    sql("DELETE FROM orders WHERE customer_name LIKE '[T]%'");
    sql("DELETE FROM home_section_products WHERE product_id IN (SELECT id FROM products WHERE name LIKE '[T]%')");
    sql("DELETE FROM products WHERE name LIKE '[T]%'");
    sql("DELETE FROM expenses WHERE title LIKE '[T]%' OR title LIKE '=[T]%' OR title LIKE '%<script>%'");
  } catch (e) { console.log('cleanup: ' + e.message); }
  for (const s of Object.values(S)) try { fs.unlinkSync(`${SESS_DIR}/sess_${s}`); } catch {}
}

(async () => {
  cleanup();
  let chrome;
  const before = { products: sql('SELECT COUNT(*) FROM products'), orders: sql('SELECT COUNT(*) FROM orders'), exp: sql('SELECT COUNT(*) FROM expenses'), realStock: sql("SELECT COALESCE(SUM(stock),0) FROM products WHERE name NOT LIKE '[T]%'") };
  try {
    mkSess(S.admin, 'Super Admin', ['view_dashboard'], 1);
    mkSess(S.exp, 'Manager', ['view_dashboard', 'manage_expenses'], 2);
    mkSess(S.noexp, 'Manager', ['view_dashboard', 'manage_products'], 3);
    const cat = sql('SELECT id FROM categories ORDER BY id LIMIT 1');

    console.log('\n=== পণ্য ফর্মে ক্রয়মূল্য ===');
    let h = await text('/admin/product_form.php', S.admin);
    check(h.includes('name="cost_price"') && h.includes('id="costPrice"'), 'পণ্য ফর্মে "ক্রয়মূল্য" ঘর আছে');
    const save = (fields, sid = S.admin) => { const fd = new FormData(); fd.set('csrf', CSRF); fd.set('save_product', '1'); fd.set('id', '0'); fd.set('name', '[T] Cost Product'); fd.set('category_id', cat); fd.set('price', '100'); fd.set('compare_at_price', ''); fd.set('stock', '8'); fd.set('active', '1'); for (const [k, v] of Object.entries(fields)) fd.set(k, v); fd.append('images[]', new Blob([]), ''); return fetch(BASE + '/admin/product_form.php', { method: 'POST', redirect: 'manual', headers: H(sid), body: fd }); };
    const costOf = (pid) => sql(`SELECT cost_price FROM products WHERE id=${pid}`);
    await save({ cost_price: '120.5' });
    const P = sql("SELECT id FROM products WHERE name='[T] Cost Product' ORDER BY id DESC LIMIT 1");
    check(!!P && costOf(P) === '120.50', 'নতুন পণ্যে ক্রয়মূল্য 120.5 সেভ → ' + costOf(P));
    await save({ id: P, cost_price: '99.999' });
    check(costOf(P) === '100.00', 'edit-এ ক্রয়মূল্য বদলায়, ২ দশমিকে গোল: ' + costOf(P));
    await save({ id: P, cost_price: '' });
    check(costOf(P) === 'NULL', 'ফাঁকা দিলে NULL (অজানা, ০ নয়)');
    await save({ id: P, cost_price: '0' });
    check(costOf(P) === '0.00', '০ দিলে ০ (ফ্রি পণ্য, অজানা নয়)');
    await save({ id: P, cost_price: '75' });
    for (const badv of ['abc', '-5', '10000001', '1e9', '12abc', '১২৩']) {
      const r = await save({ id: P, cost_price: badv, name: '[T] Should Not Save' });
      check(r.status === 302 && costOf(P) === '75.00' && sql(`SELECT name FROM products WHERE id=${P}`) === '[T] Cost Product', `অবৈধ ক্রয়মূল্য "${badv}" → কিছুই সেভ হয় না (পণ্য অক্ষত)`);
    }
    const fdNo = new FormData(); fdNo.set('csrf', CSRF); fdNo.set('save_product', '1'); fdNo.set('id', P); fdNo.set('name', '[T] Cost Product'); fdNo.set('category_id', cat); fdNo.set('price', '110'); fdNo.set('compare_at_price', ''); fdNo.set('stock', '8'); fdNo.append('images[]', new Blob([]), '');
    await fetch(BASE + '/admin/product_form.php', { method: 'POST', redirect: 'manual', headers: H(S.admin), body: fdNo });
    check(costOf(P) === '75.00' && sql(`SELECT price FROM products WHERE id=${P}`) === '110.00', 'পুরোনো ফর্ম (cost_price ক্ষেত্র নেই) সেভ করলে ক্রয়মূল্য মুছে যায় না');
    const fdArr = new FormData(); fdArr.set('csrf', CSRF); fdArr.set('save_product', '1'); fdArr.set('id', P); fdArr.set('name', '[T] Cost Product'); fdArr.set('category_id', cat); fdArr.set('price', '110'); fdArr.set('compare_at_price', ''); fdArr.set('stock', '8'); fdArr.append('cost_price[]', '5'); fdArr.append('images[]', new Blob([]), '');
    const rA = await fetch(BASE + '/admin/product_form.php', { method: 'POST', redirect: 'manual', headers: H(S.admin), body: fdArr });
    check(rA.status === 302 && costOf(P) === '75.00', 'cost_price[] অ্যারে → বাতিল, ক্র্যাশ নয়');
    h = await text(`/admin/product_form.php?id=${P}`, S.admin);
    check(/name="cost_price"[^>]*value="75\.00"/.test(h), 'edit ফর্মে বর্তমান ক্রয়মূল্য ভরা');
    h = await text(`/admin/product_form.php?copy=${P}`, S.admin);
    check(/name="cost_price"[^>]*value="75\.00"/.test(h) && h.includes('id="copyBanner"'), 'কপি-ফর্মে ক্রয়মূল্যও ভরা থাকে');

    console.log('\n=== দ্রুত পণ্য যোগ (মোবাইল) ===');
    h = await text('/admin/product_add_for_mobile.php', S.admin);
    check(h.includes('name="cost_price"'), 'দ্রুত যোগ ফর্মে ক্রয়মূল্য (ঐচ্ছিক) ঘর আছে');
    const mq = (extra) => form('/admin/product_add_for_mobile.php', { csrf: CSRF, save_product: '1', name: '[T] Quick', price: '50', category_id: cat, stock: '3', ...extra }, S.admin);
    await mq({ cost_price: '20.25' });
    const Q = sql("SELECT id FROM products WHERE name='[T] Quick' ORDER BY id DESC LIMIT 1");
    check(!!Q && costOf(Q) === '20.25', 'দ্রুত যোগে ক্রয়মূল্য সেভ: ' + costOf(Q));
    const cntQ = sql("SELECT COUNT(*) FROM products WHERE name='[T] Quick'");
    await mq({ cost_price: 'abc' });
    check(sql("SELECT COUNT(*) FROM products WHERE name='[T] Quick'") === cntQ, 'অবৈধ ক্রয়মূল্যে পণ্য তৈরি হয় না');
    await mq({ name: '[T] Quick2' });
    const Q2 = sql("SELECT id FROM products WHERE name='[T] Quick2'");
    check(!!Q2 && costOf(Q2) === 'NULL', 'ক্রয়মূল্য না দিলে NULL (অজানা)');

    console.log('\n=== অর্ডারে ক্রয়মূল্য snapshot (আসল চেকআউট) ===');
    sql(`UPDATE products SET cost_price=40, stock=50, price=100, active=1 WHERE id=${P}`);
    chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, '--no-sandbox', '--disable-gpu', '--hide-scrollbars', '--user-data-dir=' + os.tmpdir() + '\\co_prof_' + Date.now(), 'about:blank'], { stdio: 'ignore' });
    let ready = false;
    for (let i = 0; i < 40; i++) { try { await fetch(`http://127.0.0.1:${PORT}/json/version`); ready = true; break; } catch { await sleep(250); } }
    if (!ready) throw new Error('DevTools নেই');
    const t = await fetch(`http://127.0.0.1:${PORT}/json/new?about:blank`, { method: 'PUT' }).then((x) => x.json());
    const ws = new WebSocket(t.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.addEventListener('open', res, { once: true }); ws.addEventListener('error', rej, { once: true }); });
    let id = 1; const call = (m, p) => send(ws, id++, m, p);
    await call('Page.enable'); await call('Runtime.enable'); await call('Network.enable'); await call('Network.setCacheDisabled', { cacheDisabled: true });
    const ev = async (expr) => { const x = await call('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true }); if (x.exceptionDetails) throw new Error(x.exceptionDetails.exception?.description || x.exceptionDetails.text); return x.result.value; };
    const go = async (p, w = 1326, wait = 1800) => { await call('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 600 }); await call('Page.navigate', { url: BASE + p }); await sleep(wait); };
    const DID = sql("SELECT id FROM delivery_districts WHERE name_en='Dhaka'");
    await call('Network.setCookie', { name: 'PHPSESSID', value: 'none', domain: new URL(BASE).hostname, path: '/' });
    await go('/'); await ev('localStorage.clear();1');
    await go(`/checkout.php?buy=${P}&qty=3`, 1300);
    await ev(`(()=>{const s=document.getElementById('cDistrict');s.value='${DID}';s.dispatchEvent(new Event('change',{bubbles:true}));const f=document.getElementById('checkoutForm');f.customer_name.value='[T] Cost Order';f.mobile.value='01755555555';f.address.value='[T] addr';1})()`);
    await ev("document.querySelector('#checkoutForm button[type=submit]').click()"); await sleep(2500);
    const OID = sql("SELECT id FROM orders WHERE customer_name='[T] Cost Order' ORDER BY id DESC LIMIT 1");
    check(!!OID, 'টেস্ট অর্ডার তৈরি (#' + OID + ')');
    const itemRow = () => sql(`SELECT id, qty, cost FROM order_items WHERE order_id=${OID} ORDER BY id`).split(NL).filter(Boolean).map((l) => l.split('\t'));
    let items = itemRow();
    check(items.length === 1 && items[0][1] === '3' && items[0][2] === '40.00', 'অর্ডারের আইটেমে ক্রয়মূল্য 40 বসেছে (পরিমাণ ৩): ' + items[0]?.join('/'));
    sql(`UPDATE products SET cost_price=95 WHERE id=${P}`);
    check(itemRow()[0][2] === '40.00', 'পরে পণ্যের ক্রয়মূল্য 95 হলেও পুরোনো অর্ডারের আইটেমে 40-ই থাকে');

    console.log('\n=== অ্যাডমিন order_edit-এ নতুন আইটেম ===');
    const oe = (extra) => form('/admin/order_edit.php?id=' + OID, { csrf: CSRF, save: '1', customer_name: '[T] Cost Order', mobile: '01755555555', address: '[T] addr', status: 'pending', shipping_fee: '60', [`qty[${itemRow()[0][0]}]`]: '3', ...extra }, S.admin);
    await oe({ [`new_qty[${Q}]`]: '2' });
    items = itemRow();
    check(items.length === 2, 'অ্যাডমিন নতুন আইটেম যোগ করেছে (মোট ২টি)');
    const newIt = items.find((x) => x[1] === '2');
    check(!!newIt && newIt[2] === '20.25', 'নতুন আইটেমে ওই মুহূর্তের ক্রয়মূল্য (20.25) বসেছে: ' + newIt?.join('/'));
    check(items.find((x) => x[1] === '3')[2] === '40.00', 'আগের আইটেমের ক্রয়মূল্য 40 অক্ষত (সম্পাদনায় বদলায়নি)');
    await oe({ [`qty[${itemRow()[0][0]}]`]: '5' });
    check(itemRow().find((x) => x[1] === '5')?.[2] === '40.00', 'পরিমাণ বদলালে ক্রয়মূল্য snapshot একই থাকে');
    await oe({ [`new_qty[${Q2}]`]: '1' });
    check(itemRow().find((x) => x[1] === '1') && itemRow().find((x) => x[1] === '1')[2] === 'NULL', 'ক্রয়মূল্য-অজানা পণ্য যোগে cost NULL (০ নয়)');
    ws.close();

    console.log('\n=== স্টক রিপোর্ট (ক্রয়মূল্য ও ইনভেন্টরি) ===');
    sql("INSERT INTO products (category_id, name, price, stock, active, cost_price) VALUES (" + cat + ", '[T] Stk A', 100, 10, 1, 40), (" + cat + ", '[T] Stk B', 200, 5, 1, NULL), (" + cat + ", '[T] Stk C', 50, 0, 1, 10)");
    h = await text('/admin/stock_report.php?low_only=0&q=' + encodeURIComponent('[T] Stk'), S.admin);
    const rows = [...h.matchAll(/<tr class="[^"]*">([\s\S]*?)<\/tr>/g)].map((m) => m[1]);
    const rowOf = (name) => rows.find((r) => r.includes(name)) || '';
    const cells = (r) => [...r.matchAll(/data-label="([^"]+)"[^>]*>([\s\S]*?)<\/td>/g)].reduce((o, m) => (o[m[1]] = m[2].replace(/<[^>]+>/g, '').trim(), o), {});
    const a = cells(rowOf('[T] Stk A')), b = cells(rowOf('[T] Stk B')), c = cells(rowOf('[T] Stk C'));
    check(a['ক্রয়মূল্য'] === '৳40' && a['ক্রয়-ভ্যালু'] === '৳400' && a['ভ্যালু'] === '৳1,000', 'A: ক্রয়মূল্য ৳40, ক্রয়-ভ্যালু 10×40 = ৳400, বিক্রয়-ভ্যালু ৳1,000');
    check(b['ক্রয়মূল্য'] === '—' && b['ক্রয়-ভ্যালু'] === '—', 'B (ক্রয়মূল্য অজানা): "—" দেখায়, ০ নয়');
    check(c['ক্রয়-ভ্যালু'] === '৳0' && c['ক্রয়মূল্য'] === '৳10', 'C (স্টক ০): ক্রয়-ভ্যালু ৳0');
    check(idv(h, 'mSku') === '3' && idv(h, 'mUnits') === '15', 'ফিল্টার-কার্ড: ৩টি পণ্য, ১৫ ইউনিট');
    const inv = sql("SELECT COALESCE(SUM(CASE WHEN cost_price IS NOT NULL THEN stock*cost_price END),0), COALESCE(SUM(stock*price),0), COALESCE(SUM(CASE WHEN cost_price IS NOT NULL THEN stock*price END),0), COALESCE(SUM(stock>0 AND cost_price IS NULL),0) FROM products").split('\t').map(Number);
    check(idv(h, 'invCost') === money(inv[0]) && idv(h, 'invRetail') === money(inv[1]), `সব-পণ্যের ইনভেন্টরি: ক্রয়মূল্যে ${money(inv[0])}, বিক্রয়মূল্যে ${money(inv[1])} (ডাটাবেসের যোগফলের সাথে মেলে)`);
    check(idv(h, 'invProfit') === money(inv[2] - inv[0]), 'সম্ভাব্য লাভ = (ক্রয়মূল্য জানা পণ্যের বিক্রয় − ক্রয়): ' + idv(h, 'invProfit'));
    check(idv(h, 'invNoCost') === String(inv[3]) && h.includes('id="invNote"'), '"ক্রয়মূল্য অজানা" সংখ্যা ও সতর্কবার্তা: ' + idv(h, 'invNoCost'));
    const rCsv = await get('/admin/stock_report.php?low_only=0&export=csv&q=' + encodeURIComponent('[T] Stk'), S.admin);
    const csvTxt = (await rCsv.text()).replace(/^\uFEFF/, '').split(/\r?\n/).filter(Boolean);
    check(csvTxt[0].includes('ক্রয়মূল্য') && csvTxt[0].includes('ক্রয়-ভ্যালু'), 'CSV-তে ক্রয়মূল্য ও ক্রয়-ভ্যালু কলাম');
    const lineA = csvTxt.find((l) => l.includes('[T] Stk A')), lineB = csvTxt.find((l) => l.includes('[T] Stk B'));
    check(/,40.00,400,/.test(lineA) && /,,,/.test(lineB.replace(/"/g, '')) , 'CSV: A-তে 40/400, B-তে (অজানা) ফাঁকা');

    console.log('\n=== খরচ: অ্যাক্সেস ===');
    let r = await get('/admin/expenses.php');
    check(r.status === 302, 'লগইন ছাড়া বন্ধ');
    r = await get('/admin/expenses.php', S.noexp);
    h = await r.text();
    check(r.status !== 200 || !h.includes('id="exForm"'), 'manage_expenses ছাড়া অ্যাডমিন পায় না (' + r.status + ')');
    r = await form('/admin/expenses.php', { csrf: CSRF, action: 'add', title: '[T] Blocked', amount: '5', expense_date: new Date().toISOString().slice(0, 10) }, S.noexp);
    check(sql("SELECT COUNT(*) FROM expenses WHERE title='[T] Blocked'") === '0', 'পারমিশন ছাড়া POST-এ খরচ যোগ হয় না');
    r = await get('/admin/expenses.php?export=csv', S.noexp);
    check(!(await r.text()).includes('তারিখ,শিরোনাম'), 'পারমিশন ছাড়া CSV পায় না');
    r = await get('/admin/expenses.php', S.exp);
    h = await r.text();
    check(r.status === 200 && h.includes('id="exForm"') && />খরচ<\/span>/.test(h), 'manage_expenses পারমিশনে পায়, সাইডবারে "খরচ" মেনু আছে');
    h = await text('/admin/index.php', S.noexp);
    check(!/>খরচ<\/span>/.test(h), 'পারমিশন ছাড়া সাইডবারে "খরচ" মেনু নেই');

    console.log('\n=== খরচ: যোগ/যাচাই/XSS ===');
    const today = new Date().toISOString().slice(0, 10);
    const add = (f) => form('/admin/expenses.php', { csrf: CSRF, action: 'add', title: '[T] টেস্ট খরচ', amount: '250', expense_date: today, category: 'টেস্ট', note: '', ...f }, S.admin);
    const cntE = () => sql("SELECT COUNT(*) FROM expenses WHERE title LIKE '[T]%' OR title LIKE '=[T]%' OR title LIKE '%<script>%'");
    let c0 = Number(cntE());
    r = await add({});
    check(r.status === 302 && Number(cntE()) === c0 + 1, 'বৈধ খরচ যোগ হয় (redirect)');
    const E1 = sql("SELECT id FROM expenses WHERE title='[T] টেস্ট খরচ' ORDER BY id DESC LIMIT 1");
    check(sql(`SELECT amount FROM expenses WHERE id=${E1}`) === '250.00' && sql(`SELECT created_by FROM expenses WHERE id=${E1}`) === 'tt', 'পরিমাণ ও যোগকারীর নাম সংরক্ষিত');
    c0 = Number(cntE());
    for (const [label, patch] of [['পরিমাণ ০', { amount: '0' }], ['ঋণাত্মক', { amount: '-5' }], ['অক্ষর', { amount: 'abc' }], ['ভবিষ্যৎ তারিখ', { expense_date: '2099-01-01' }], ['ভুল তারিখ', { expense_date: '2026-02-30' }], ['শিরোনাম ফাঁকা', { title: '' }], ['শিরোনাম ১৬১ অক্ষর', { title: '[T]' + 'ক'.repeat(158) }], ['নোট ২৫৬', { note: 'x'.repeat(256) }]]) {
      const rr = await add(patch);
      const t2 = await rr.text();
      check(Number(cntE()) === c0 && rr.status === 200 && t2.includes('id="exErrors"'), `অবৈধ (${label}) → যোগ হয় না, ফর্মে ত্রুটি দেখায়`);
    }
    r = await form('/admin/expenses.php', { csrf: CSRF, action: 'add', 'title[]': 'x', 'amount[]': '5', 'expense_date[]': today }, S.admin);
    check(r.status !== 500 && Number(cntE()) === c0, 'অ্যারে ইনপুটে ক্র্যাশ নয়, যোগ হয় না');
    r = await form('/admin/expenses.php', { action: 'add', title: '[T] NoCsrf', amount: '5', expense_date: today }, S.admin);
    check(Number(cntE()) === c0, 'CSRF ছাড়া যোগ হয় না');
    r = await add({ title: '[T] <script>alert(1)</script>', note: '"><img src=x onerror=alert(2)>' });
    h = await text('/admin/expenses.php?type=today', S.admin);
    check(!h.includes('<script>alert(1)</script>') && !h.includes('<img src=x') && h.includes('&lt;script&gt;'), 'শিরোনাম/নোটে HTML থাকলে escape হয় (XSS নয়)');
    r = await add({ title: '=[T] ফর্মুলা', amount: '10' });

    console.log('\n=== খরচ: সম্পাদনা, KPI, ফিল্টার, CSV ===');
    h = await text(`/admin/expenses.php?edit=${E1}`, S.admin);
    check(/name="action" value="update"/.test(h) && h.includes('value="[T] টেস্ট খরচ"') && /name="amount"[^>]*value="250\.00"/.test(h), 'Edit লিংকে ফর্ম মান-ভরা, অ্যাকশন update');
    await form('/admin/expenses.php', { csrf: CSRF, action: 'update', id: E1, title: '[T] টেস্ট খরচ (সংশোধিত)', amount: '300', expense_date: today, category: 'টেস্ট', note: 'বদলানো' }, S.admin);
    check(sql(`SELECT amount FROM expenses WHERE id=${E1}`) === '300.00' && sql(`SELECT title FROM expenses WHERE id=${E1}`).includes('সংশোধিত'), 'আপডেট সফল');
    await form('/admin/expenses.php', { csrf: CSRF, action: 'update', id: E1, title: '[T] x', amount: '-1', expense_date: today }, S.admin);
    check(sql(`SELECT amount FROM expenses WHERE id=${E1}`) === '300.00', 'অবৈধ আপডেটে আগের মান অক্ষত');
    r = await form('/admin/expenses.php', { csrf: CSRF, action: 'update', id: '999999999', title: '[T] y', amount: '5', expense_date: today }, S.admin);
    check(r.status === 302 && sql("SELECT COUNT(*) FROM expenses WHERE title='[T] y'") === '0', 'অজানা আইডি আপডেট → কিছু তৈরি হয় না');

    h = await text('/admin/expenses.php?type=today', S.admin);
    const sumToday = Number(sql(`SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date='${today}'`));
    check(idv(h, 'kToday') === money(sumToday), 'আজকের খরচ ডাটাবেসের সাথে মেলে: ' + idv(h, 'kToday'));
    const sumMonth = Number(sql("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01') AND expense_date <= LAST_DAY(CURDATE())"));
    check(idv(h, 'kMonth') === money(sumMonth), 'এই মাসের খরচ মেলে: ' + idv(h, 'kMonth'));
    sql("INSERT INTO expenses (title, amount, expense_date, category) VALUES ('[T] Old1', 1000, '2019-03-10', 'ভাড়া'), ('[T] Old2', 500.5, '2019-03-31', 'ইউটিলিটি'), ('[T] Old3', 200, '2019-04-01', NULL)");
    h = await text('/admin/expenses.php?type=monthly&year=2019&month=3', S.admin);
    check(idv(h, 'kFiltered') === money(1500.5) && /2টি এন্ট্রি/.test(h.normalize('NFC')), 'মার্চ ২০১৯ ফিল্টার: ৳1,501 (২টি), ১ এপ্রিল বাদ');
    h = await text('/admin/expenses.php?type=monthly&year=2019&month=3&cat=' + encodeURIComponent('ভাড়া'), S.admin);
    check(idv(h, 'kFiltered') === money(1000), 'ক্যাটেগরি=ভাড়া → ৳1,000');
    h = await text('/admin/expenses.php?type=yearly&year=2019&cat=__none', S.admin);
    check(idv(h, 'kFiltered') === money(200), '"ক্যাটেগরি নেই" ফিল্টার → ৳200');
    check(/id="exByCat"/.test(h) || (await text('/admin/expenses.php?type=yearly&year=2019', S.admin)).includes('id="exByCat"'), 'ক্যাটেগরি অনুযায়ী খরচের বার আছে');
    for (const q of ['type[]=x', 'cat[]=a', 'page[]=2', 'edit[]=1', 'export[]=csv', 'year[]=2019', 'type=custom&from=bad&to=bad', "cat=x'%20OR%201=1"]) {
      const rr = await get('/admin/expenses.php?' + q, S.admin);
      const tt = await rr.text();
      check(rr.status === 200 && !/fatal|uncaught|warning:|SQLSTATE/i.test(tt), `ইনপুট "${q}" → ক্র্যাশ নেই`);
    }
    rr = await get('/admin/expenses.php?type=monthly&year=2019&month=3&export=csv', S.admin);
    const cb = Buffer.from(await rr.arrayBuffer());
    check(cb[0] === 0xef && cb[1] === 0xbb && cb[2] === 0xbf && /attachment; filename="expenses_2019-03-01_2019-03-31\.csv"/.test(rr.headers.get('content-disposition') || ''), 'CSV: BOM ও ফাইল-নাম');
    const cl = cb.toString('utf8').replace(/^\uFEFF/, '').split(/\r?\n/).filter(Boolean);
    check(cl.length === 1 + 2 + 1 && cl[cl.length - 1].startsWith('মোট') && cl[cl.length - 1].includes('1500.5'), 'CSV: শিরোনাম + ২টি সারি + মোট (1500.5)');
    r = await get('/admin/expenses.php?type=today&export=csv', S.admin);
    const todayCsv = (await r.text());
    check(todayCsv.includes("'=[T] ফর্মুলা") && !/(^|,)"?=\[T\]/m.test(todayCsv), 'CSV ফর্মুলা-ইনজেকশন আটকানো');
    for (let i = 0; i < 27; i++) sql(`INSERT INTO expenses (title, amount, expense_date) VALUES ('[T] Bulk${i}', ${i + 1}, '2018-06-${String(1 + (i % 28)).padStart(2, '0')}')`);
    h = await text('/admin/expenses.php?type=yearly&year=2018', S.admin);
    const nrows = (hh) => (hh.match(/<tr data-id=/g) || []).length;
    check(nrows(h) === 25 && /page=2/.test(h), 'পেজিনেশন: প্রথম পৃষ্ঠায় ২৫টি, পৃষ্ঠা-লিংক আছে');
    check(nrows(await text('/admin/expenses.php?type=yearly&year=2018&page=2', S.admin)) === 2, 'পৃষ্ঠা ২-এ বাকি ২টি');
    check(nrows(await text('/admin/expenses.php?type=yearly&year=2018&page=99', S.admin)) === 2, 'পৃষ্ঠা ৯৯ → শেষ পৃষ্ঠা');

    console.log('\n=== খরচ: মোছা ===');
    const del = sql("SELECT id FROM expenses WHERE title='[T] Old3'");
    r = await get(`/admin/expenses.php?action=delete&id=${del}`, S.admin);
    check(sql(`SELECT COUNT(*) FROM expenses WHERE id=${del}`) === '1', 'GET দিয়ে মোছা যায় না');
    r = await form('/admin/expenses.php', { action: 'delete', id: del }, S.admin);
    check(sql(`SELECT COUNT(*) FROM expenses WHERE id=${del}`) === '1', 'CSRF ছাড়া মোছা যায় না');
    r = await form('/admin/expenses.php', { csrf: CSRF, action: 'delete', id: del }, S.admin);
    check(sql(`SELECT COUNT(*) FROM expenses WHERE id=${del}`) === '0', 'সঠিক POST-এ মোছে');
    r = await form('/admin/expenses.php', { csrf: CSRF, action: 'delete', id: del }, S.admin);
    check(r.status === 302, 'আবার/অজানা আইডি মুছলে ক্র্যাশ নয়');

    console.log('\n=== ব্রাউজার ===');
    chrome.kill(); chrome = null;
    chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, '--no-sandbox', '--disable-gpu', '--hide-scrollbars', '--user-data-dir=' + os.tmpdir() + '\\co2_prof_' + Date.now(), 'about:blank'], { stdio: 'ignore' });
    ready = false;
    for (let i = 0; i < 40; i++) { try { await fetch(`http://127.0.0.1:${PORT}/json/version`); ready = true; break; } catch { await sleep(250); } }
    if (!ready) throw new Error('DevTools নেই');
    const t2 = await fetch(`http://127.0.0.1:${PORT}/json/new?about:blank`, { method: 'PUT' }).then((x) => x.json());
    const ws2 = new WebSocket(t2.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws2.addEventListener('open', res, { once: true }); ws2.addEventListener('error', rej, { once: true }); });
    id = 1; const call2 = (m, p) => send(ws2, id++, m, p);
    await call2('Page.enable'); await call2('Runtime.enable'); await call2('Network.enable'); await call2('Network.setCacheDisabled', { cacheDisabled: true });
    await call2('Network.setCookie', { name: 'PHPSESSID', value: S.admin, domain: new URL(BASE).hostname, path: '/' });
    const ev2 = async (expr) => { const x = await call2('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true }); if (x.exceptionDetails) throw new Error(x.exceptionDetails.exception?.description || x.exceptionDetails.text); return x.result.value; };
    const go2 = async (p, w) => { await call2('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 600 }); await call2('Page.navigate', { url: BASE + p }); await sleep(1800); };
    for (const w of [1326, 390]) {
      await go2('/admin/expenses.php?type=yearly&year=2018', w);
      const m = await ev2("(()=>({hs:document.documentElement.scrollWidth>innerWidth+1,k:document.querySelectorAll('#exKpis .kpi-card').length,rows:document.querySelectorAll('#exTable tbody tr[data-id]').length,form:!!document.getElementById('exForm'),cust:getComputedStyle(document.querySelector('.ex-custom')).display}))()");
      check(!m.hs && m.k === 4 && m.rows === 25 && m.form && m.cust === 'none', `খরচ ${w}px: overflow নেই, ৪টি কার্ড, ২৫ সারি, ফর্ম আছে, কাস্টম ঘর লুকানো`);
      const shot = await call2('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
      fs.writeFileSync(path.join(os.tmpdir(), `expenses_${w}.png`), Buffer.from(shot.data, 'base64'));
      await go2('/admin/stock_report.php?low_only=0', w);
      const m2 = await ev2("(()=>({hs:document.documentElement.scrollWidth>innerWidth+1,inv:document.querySelectorAll('#invRow .metric').length}))()");
      check(!m2.hs && m2.inv === 4, `স্টক রিপোর্ট ${w}px: overflow নেই, ইনভেন্টরির ৪টি কার্ড`);
      const shot2 = await call2('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
      fs.writeFileSync(path.join(os.tmpdir(), `stock_${w}.png`), Buffer.from(shot2.data, 'base64'));
    }
    await go2('/admin/expenses.php?type=yearly&year=2018', 1326);
    const delBefore = sql("SELECT COUNT(*) FROM expenses WHERE title LIKE '[T] Bulk%'");
    await ev2("document.querySelector('#exTable .btn-del').click()"); await sleep(700);
    const md = await ev2("(()=>({shown:document.getElementById('delModal').classList.contains('show'),title:document.getElementById('delTitle').textContent,id:document.getElementById('delId').value}))()");
    check(md.shown && md.title.startsWith('[T] Bulk') && /^\d+$/.test(md.id), 'Delete চাপলে নিশ্চিতকরণ মোডাল (' + md.title + ')');
    check(sql("SELECT COUNT(*) FROM expenses WHERE title LIKE '[T] Bulk%'") === delBefore, 'মোডাল খোলায় কিছু মোছা হয়নি');
    await ev2("document.getElementById('delConfirm').click()"); await sleep(1800);
    check(sql("SELECT COUNT(*) FROM expenses WHERE title LIKE '[T] Bulk%'") === String(Number(delBefore) - 1), 'নিশ্চিত করলে একটি খরচ মোছে');
    await ev2("(()=>{const s=document.getElementById('exType');s.value='custom';s.dispatchEvent(new Event('change'))})()");
    check((await ev2("getComputedStyle(document.querySelector('.ex-custom')).display")) !== 'none', '"কাস্টম" বাছলে শুরু/শেষ তারিখ ঘর আসে');
    ws2.close();
  } catch (e) { console.error('ERROR: ' + e.message); fail++; }
  finally { try { chrome && chrome.kill(); } catch {} cleanup(); }
  check(sql('SELECT COUNT(*) FROM products') === before.products && sql('SELECT COUNT(*) FROM orders') === before.orders && sql('SELECT COUNT(*) FROM expenses') === before.exp, 'টেস্ট শেষে পণ্য/অর্ডার/খরচের সংখ্যা আগের মতো');
  check(sql("SELECT COALESCE(SUM(stock),0) FROM products") === before.realStock, 'আসল পণ্যের মোট স্টক অপরিবর্তিত (' + before.realStock + ')');
  console.log((fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exitCode = fail === 0 ? 0 : 1;
})();
