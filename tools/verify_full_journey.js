/**
 * tools/verify_full_journey.js — পুরো ব্যবসার প্রবাহ আসল ফর্ম/এন্ডপয়েন্টে: পণ্য এন্ট্রি → সরবরাহকারী → ক্রয় (বাকি/কিস্তি/একসাথে) →
 * গ্রাহকের আসল কার্ট+চেকআউট অর্ডার → স্টক/ক্রয়মূল্য-snapshot → অ্যাডমিন স্ট্যাটাস/ডিসকাউন্ট → কুরিয়ার (নকল সার্ভার) → বাতিল (স্টক ফেরা)
 * → খরচ → রিপোর্ট/P&L/ড্যাশবোর্ডের সংখ্যা হাতে-হিসাবের সাথে মেলানো।
 * node tools/verify_full_journey.js [url]
 * সব ডেটা "[T]"; শেষে সব মোছে, steadfast সেটিংস ফেরায়, আসল স্টক ও সারি-সংখ্যা অপরিবর্তিত কি যাচাই করে।
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
const eq = (a, b, m) => (String(a) === String(b) ? ok(m + ` (${a})`) : bad(m + ` — পাওয়া ${a}, আশা ${b}`));
const sql = (q) => execFileSync(MYSQL, ['-uroot', '-N', '--default-character-set=utf8mb4', 'estore'], { encoding: 'utf8', input: q + ';\n' }).replace(/\r/g, '').trim();
const esc = (v) => String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
const CSRF = 'a'.repeat(32);
const today = new Date().toISOString().slice(0, 10);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const num = (s) => Number(String(s).replace(/[^0-9.\-]/g, ''));
const norm = (s) => s.normalize('NFC');

const sid = require('crypto').randomBytes(13).toString('hex');
const KEYS = ['steadfast_enabled', 'steadfast_auto_status', 'steadfast_base_url', 'steadfast_api_key', 'steadfast_secret_key'];
const A = (p, opt = {}) => fetch(BASE + p, { redirect: 'manual', ...opt, headers: { Cookie: 'PHPSESSID=' + sid, ...(opt.headers || {}) } });
const aget = async (p) => { const r = await A(p); return { st: r.status, loc: r.headers.get('location'), t: await r.text() }; };
const apost = async (p, data) => { const r = await A(p, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(data).toString() }); return { st: r.status, loc: r.headers.get('location'), t: await r.text() }; };
const mock = (p, body, method = 'GET') => fetch(`http://127.0.0.1:${PORT}${p}`, { method, body: body ? JSON.stringify(body) : undefined, headers: { 'Content-Type': 'application/json' } }).then((r) => r.json());

/* গ্রাহক (আসল ব্রাউজারের মতো কুকি + CSRF মেটা) */
const cust = { cookie: '', csrf: '' };
const cfetch = async (p, opt = {}) => {
  const r = await fetch(BASE + p, { redirect: 'manual', ...opt, headers: { Cookie: cust.cookie, ...(opt.headers || {}) } });
  const sc = r.headers.getSetCookie ? r.headers.getSetCookie() : [];
  for (const c of sc) { const m = c.match(/^PHPSESSID=([^;]+)/); if (m) cust.cookie = 'PHPSESSID=' + m[1]; }
  return r;
};
const custInit = async () => { const r = await cfetch('/index.php'); const t = await r.text(); cust.csrf = (t.match(/name="csrf-token" content="([^"]+)"/) || [])[1] || ''; return cust.csrf; };
const cartAdd = async (pid, qty) => { const r = await cfetch('/cart_add.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ csrf: cust.csrf, product_id: String(pid), qty: String(qty) }).toString() }); return r.json(); };
const placeOrder = async (f) => { const r = await cfetch('/place_order.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ csrf: cust.csrf, payment_method: 'COD', ...f }).toString() }); return { st: r.status, loc: r.headers.get('location') }; };

(async () => {
  const snap = {};
  for (const k of KEYS) snap[k] = sql(`SELECT COUNT(*) FROM settings WHERE \`key\`='${k}'`) === '1' ? sql(`SELECT value FROM settings WHERE \`key\`='${k}'`) : null;
  const setS = (k, v) => sql(`INSERT INTO settings(\`key\`,\`value\`) VALUES('${k}','${esc(v)}') ON DUPLICATE KEY UPDATE value=VALUES(value)`);
  const logMax = Number(sql('SELECT COALESCE(MAX(id),0) FROM courier_logs'));
  const base = { prods: sql("SELECT COUNT(*) FROM products WHERE name NOT LIKE '[T]%'"), stock: sql("SELECT COALESCE(SUM(stock),0) FROM products WHERE name NOT LIKE '[T]%'"), orders: sql("SELECT COUNT(*) FROM orders WHERE customer_name NOT LIKE '[T]%'"), sup: sql('SELECT COUNT(*) FROM suppliers WHERE name NOT LIKE \'[T]%\''), exp: sql("SELECT COUNT(*) FROM expenses WHERE title NOT LIKE '[T]%'") };
  const cleanup = () => {
    const q = (s) => { try { sql(s); } catch (e) {} };
    q("DELETE FROM courier_logs WHERE order_id IN (SELECT id FROM orders WHERE customer_name LIKE '[T]%')");
    q(`DELETE FROM courier_logs WHERE id > ${logMax}`);
    q("DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE customer_name LIKE '[T]%')");
    q("DELETE FROM orders WHERE customer_name LIKE '[T]%'");
    q("DELETE FROM purchase_payments WHERE purchase_id IN (SELECT id FROM purchases WHERE supplier_id IN (SELECT id FROM suppliers WHERE name LIKE '[T]%'))");
    q("DELETE FROM purchase_items WHERE purchase_id IN (SELECT id FROM purchases WHERE supplier_id IN (SELECT id FROM suppliers WHERE name LIKE '[T]%'))");
    q("DELETE FROM purchases WHERE supplier_id IN (SELECT id FROM suppliers WHERE name LIKE '[T]%')");
    q("DELETE FROM suppliers WHERE name LIKE '[T]%'");
    q("DELETE FROM expenses WHERE title LIKE '[T]%'");
    q("DELETE FROM home_section_products WHERE product_id IN (SELECT id FROM products WHERE name LIKE '[T]%')");
    q("DELETE FROM product_images WHERE product_id IN (SELECT id FROM products WHERE name LIKE '[T]%')");
    q("DELETE FROM products WHERE name LIKE '[T]%'");
  };
  let proc = null;
  try {
    cleanup();
    fs.writeFileSync(`${SESS_DIR}/sess_${sid}`, `admin_id|i:1;admin_username|s:4:"test";admin_role_name|s:11:"Super Admin";admin_permissions|a:1:{i:0;s:14:"view_dashboard";}csrf|s:32:"${CSRF}";__sid_rotated|i:${Math.floor(Date.now() / 1000)};`);
    const cat = sql('SELECT id FROM categories ORDER BY id LIMIT 1');
    const dhaka = sql("SELECT id FROM delivery_districts WHERE name_bn='ঢাকা' LIMIT 1");
    const ctotal = (id) => Number(sql(`SELECT total FROM orders WHERE id=${id}`));

    console.log('\n=== ১) অ্যাডমিন: পণ্য এন্ট্রি (আসল product_form) ===');
    const saveProduct = async (name, price, stock, cost) => {
      const fd = new FormData();
      fd.set('csrf', CSRF); fd.set('save_product', '1'); fd.set('id', '0'); fd.set('name', name); fd.set('category_id', cat); fd.set('price', String(price)); fd.set('compare_at_price', ''); fd.set('stock', String(stock)); fd.set('active', '1');
      if (cost !== undefined) fd.set('cost_price', String(cost));
      fd.append('images[]', new Blob([]), '');
      const r = await A('/admin/product_form.php', { method: 'POST', body: fd });
      return { st: r.status, id: sql(`SELECT id FROM products WHERE name='${esc(name)}' ORDER BY id DESC LIMIT 1`) };
    };
    const pa = await saveProduct('[T] জার্নি পণ্য-ক', 500, 0);
    const pb = await saveProduct('[T] জার্নি পণ্য-খ', 200, 5, 120);
    check(pa.id && pb.id, 'দুটি পণ্য সেভ হয়েছে (ক: দাম ৫০০ স্টক ০; খ: দাম ২০০ স্টক ৫, ক্রয়মূল্য ১২০)');
    const A_ = pa.id, B_ = pb.id;
    eq(sql(`SELECT COALESCE(cost_price,'NULL') FROM products WHERE id=${A_}`), 'NULL', 'ক-এর ক্রয়মূল্য অজানা (NULL), ০ নয়');
    eq(sql(`SELECT cost_price FROM products WHERE id=${B_}`), '120.00', 'খ-এর ক্রয়মূল্য ১২০');
    let pg = await aget('/admin/products.php?q=' + encodeURIComponent('[T] জার্নি'));
    check(pg.st === 200 && norm(pg.t).includes(norm('জার্নি পণ্য-ক')) && norm(pg.t).includes(norm('জার্নি পণ্য-খ')), 'পণ্য তালিকায় দুটোই দেখা যায়');
    let sp = await (await fetch(BASE + '/product.php?id=' + A_)).text();
    check(/স্টক|stock|শেষ|Out/i.test(sp) || sp.length > 2000, 'গ্রাহকের পণ্য-পেজ খোলে (স্টক ০ পণ্যও)');

    console.log('\n=== ২) সরবরাহকারী ও ক্রয় ===');
    await apost('/admin/suppliers.php', { csrf: CSRF, action: 'add', name: '[T] জার্নি সাপ্লায়ার', phone: '01711111111', address: 'ঢাকা', note: 'টেস্ট' });
    const S1 = sql("SELECT id FROM suppliers WHERE name='[T] জার্নি সাপ্লায়ার'");
    check(!!S1, 'সরবরাহকারী যোগ হয়েছে');
    const buy = async (lines, paid, inv) => {
      const body = new URLSearchParams(); body.set('csrf', CSRF); body.set('supplier_id', S1); body.set('invoice_no', inv); body.set('purchase_date', today); body.set('paid', String(paid)); body.set('method', 'ক্যাশ');
      for (const [p, q, c] of lines) { body.append('product_id[]', p); body.append('qty[]', String(q)); body.append('unit_cost[]', String(c)); }
      const r = await A('/admin/purchase_form.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() });
      return { st: r.status, id: ((r.headers.get('location') || '').match(/id=(\d+)/) || [])[1] };
    };
    const p1 = await buy([[A_, 10, 300], [B_, 5, 100]], 1000, 'JRN-1');
    check(!!p1.id, 'ক্রয়-১ সংরক্ষিত: ক ×১০ @৩০০, খ ×৫ @১০০ = ৩৫০০, পরিশোধ ১০০০');
    eq(sql(`SELECT stock FROM products WHERE id=${A_}`), 10, 'ক-এর স্টক ০ → ১০');
    eq(sql(`SELECT stock FROM products WHERE id=${B_}`), 10, 'খ-এর স্টক ৫ → ১০');
    eq(sql(`SELECT cost_price FROM products WHERE id=${A_}`), '300.00', 'ক-এর ক্রয়মূল্য (আগে অজানা) = ৩০০');
    eq(sql(`SELECT cost_price FROM products WHERE id=${B_}`), '110.00', 'খ-এর গড় = (৫×১২০ + ৫×১০০)/১০ = ১১০');
    const p2 = await buy([[A_, 10, 400]], 0, 'JRN-2');
    eq(sql(`SELECT cost_price FROM products WHERE id=${A_}`), '350.00', 'ক-এর গড় = (১০×৩০০ + ১০×৪০০)/২০ = ৩৫০');
    eq(sql(`SELECT stock FROM products WHERE id=${A_}`), 20, 'ক-এর স্টক ২০');
    const due = (id) => Number(sql(`SELECT total - COALESCE((SELECT SUM(amount) FROM purchase_payments WHERE purchase_id=${id}),0) FROM purchases WHERE id=${id}`));
    eq(due(p1.id), 2500, 'ক্রয়-১ বাকি ২৫০০'); eq(due(p2.id), 4000, 'ক্রয়-২ বাকি ৪০০০ (পুরো বাকি)');
    const dupe = await buy([[A_, 1, 10]], 0, 'JRN-1');
    check(!dupe.id && sql(`SELECT COUNT(*) FROM purchases WHERE supplier_id=${S1}`) === '2', 'একই সরবরাহকারীর একই ইনভয়েস নম্বর দ্বিতীয়বার নেয় না');
    let r = await apost('/admin/purchase_view.php', { csrf: CSRF, action: 'pay', purchase_id: p1.id, amount: '1500', paid_date: today, method: 'ক্যাশ' });
    eq(due(p1.id), 1000, 'কিস্তি ১৫০০ দেওয়ার পর ক্রয়-১ বাকি ১০০০');
    r = await apost('/admin/purchase_view.php', { csrf: CSRF, action: 'pay', purchase_id: p1.id, amount: '5000', paid_date: today });
    eq(due(p1.id), 1000, 'বাকির বেশি কিস্তি নেয় না (বাকি অপরিবর্তিত)');
    r = await apost('/admin/supplier_view.php?id=' + S1, { csrf: CSRF, action: 'pay_all', supplier_id: S1, amount: '2000', paid_date: today, method: 'ব্যাংক' });
    eq(due(p1.id), 0, 'একসাথে ২০০০: পুরোনো (ক্রয়-১) আগে — বাকি ০');
    eq(due(p2.id), 3000, 'বাকি ১০০০ ক্রয়-২-এ — বাকি ৩০০০');
    let sv = await aget('/admin/supplier_view.php?id=' + S1);
    check(/3,?000/.test(sv.t), 'সরবরাহকারী পেজে মোট বাকি ৩০০০ দেখায়');
    const p3 = await buy([[B_, 1, 50]], 0, 'JRN-3');
    eq(sql(`SELECT stock FROM products WHERE id=${B_}`), 11, 'ক্রয়-৩ (খ ×১): স্টক ১১');
    await apost('/admin/purchase_view.php', { csrf: CSRF, action: 'void', purchase_id: p3.id });
    eq(sql(`SELECT stock FROM products WHERE id=${B_}`), 10, 'ক্রয়-৩ বাতিল: স্টক ১০-এ ফেরে');
    eq(sql(`SELECT status FROM purchases WHERE id=${p3.id}`), 'cancelled', 'ক্রয়-৩ cancelled');
    await apost('/admin/purchase_view.php', { csrf: CSRF, action: 'void', purchase_id: p1.id });
    check(sql(`SELECT status FROM purchases WHERE id=${p1.id}`) !== 'cancelled', 'পরিশোধ আছে এমন ক্রয় বাতিল হয় না');

    console.log('\n=== ৩) গ্রাহকের আসল অর্ডার (কার্ট → চেকআউট) ===');
    await custInit();
    check(cust.csrf.length >= 16, 'গ্রাহক সেশন ও CSRF মেটা পাওয়া গেছে');
    let j = await cartAdd(A_, 2); check(j.ok === true, 'কার্টে ক ×২ যোগ');
    j = await cartAdd(B_, 1); check(j.ok === true, 'কার্টে খ ×১ যোগ');
    const cartHtml = await (await cfetch('/checkout.php')).text();
    check(/জার্নি পণ্য-ক/.test(norm(cartHtml)) && /1,200|1200/.test(cartHtml), 'চেকআউটে দুই পণ্য ও সাবটোটাল ১২০০');
    const fee = Number(sql("SELECT COALESCE(fee, (SELECT value FROM settings WHERE `key`='delivery_global')) FROM delivery_districts WHERE name_bn='ঢাকা' LIMIT 1"));
    const po = await placeOrder({ customer_name: '[T] জার্নি ক্রেতা', mobile: '01712345678', address: '[T] বাড়ি ৫, রোড ৩, ধানমন্ডি', district_id: dhaka });
    check(po.st === 302 && /success\.php\?t=[0-9a-f]{32}/.test(po.loc || ''), 'অর্ডার সফল → success.php?t=<গোপন কোড> এ গেছে');
    const O1 = sql("SELECT id FROM orders WHERE customer_name='[T] জার্নি ক্রেতা' ORDER BY id DESC LIMIT 1");
    eq(sql(`SELECT subtotal FROM orders WHERE id=${O1}`), '1200.00', 'অর্ডারের সাবটোটাল ১২০০');
    eq(ctotal(O1), 1200 + fee, `মোট = ১২০০ + ডেলিভারি (${fee})`);
    eq(sql(`SELECT stock FROM products WHERE id=${A_}`), 18, 'অর্ডারে ক-এর স্টক ২০ → ১৮');
    eq(sql(`SELECT stock FROM products WHERE id=${B_}`), 9, 'খ-এর স্টক ১০ → ৯');
    eq(sql(`SELECT GROUP_CONCAT(CONCAT(product_id,':',cost) ORDER BY product_id) FROM order_items WHERE order_id=${O1}`), `${A_}:350.00,${B_}:104.55`, 'অর্ডার-আইটেমে ক্রয়মূল্য snapshot (ক ৩৫০, খ ১০৪.৫৫ — বাতিল ক্রয়-৩ এর গড় ফেরানো হয় না, নিয়ম অনুযায়ী)');
    const succ = await (await cfetch('/' + po.loc.replace(BASE, '').replace(/^\//, ''))).text();
    check(/ORD\d+/.test(succ), 'success পেজে অর্ডার কোড দেখায়');
    // খালি কার্টে আবার অর্ডার হয় না
    const po2 = await placeOrder({ customer_name: '[T] জার্নি খালি', mobile: '01712345678', address: '[T] x', district_id: dhaka });
    check(/cart\.php/.test(po2.loc || '') && sql("SELECT COUNT(*) FROM orders WHERE customer_name='[T] জার্নি খালি'") === '0', 'কার্ট খালি হলে (অর্ডারের পর) দ্বিতীয় অর্ডার হয় না');
    // স্টকের বেশি: কার্টে তোলার সময় স্টকে ছাঁটা হয়; চেকআউটের মুহূর্তে স্টক কমে গেলে অর্ডার আটকায়
    await custInit();
    j = await cartAdd(B_, 50);
    check(j.ok === false && Number(j.qty) === 9, 'স্টকের (৯) বেশি চাইলে কার্টে সর্বোচ্চ ৯ রাখে, বার্তা দেয়');
    await custInit(); await cartAdd(B_, 2);
    sql(`UPDATE products SET stock=1 WHERE id=${B_}`);
    await placeOrder({ customer_name: '[T] জার্নি বেশি', mobile: '01712345678', address: '[T] x', district_id: dhaka });
    check(sql("SELECT COUNT(*) FROM orders WHERE customer_name='[T] জার্নি বেশি'") === '0' && sql(`SELECT stock FROM products WHERE id=${B_}`) === '1', 'চেকআউটের সময় স্টক কমে গেলে অর্ডার হয় না, স্টক অক্ষত');
    sql(`UPDATE products SET stock=9 WHERE id=${B_}`);
    // দ্বিতীয় অর্ডার (বাতিল হবে)
    await custInit(); await cartAdd(A_, 1);
    await placeOrder({ customer_name: '[T] জার্নি বাতিল', mobile: '01798765432', address: '[T] y', district_id: dhaka });
    const O2 = sql("SELECT id FROM orders WHERE customer_name='[T] জার্নি বাতিল' ORDER BY id DESC LIMIT 1");
    check(!!O2 && sql(`SELECT stock FROM products WHERE id=${A_}`) === '17', 'দ্বিতীয় অর্ডার (ক ×১): স্টক ১৭');

    console.log('\n=== ৪) অ্যাডমিন: অর্ডার ব্যবস্থাপনা ===');
    const setStatus = (id, st) => apost('/admin/orders.php', { csrf: CSRF, update_status: '1', order_id: id, status: st });
    await setStatus(O2, 'cancelled');
    eq(sql(`SELECT stock FROM products WHERE id=${A_}`), 18, 'O2 বাতিলে ক-এর স্টক ১৮-এ ফেরে');
    await setStatus(O1, 'processing');
    eq(sql(`SELECT status FROM orders WHERE id=${O1}`), 'processing', 'O1 → processing');
    const itemId = sql(`SELECT id FROM order_items WHERE order_id=${O1} AND product_id=${A_}`);
    r = await apost(`/admin/order_edit.php?id=${O1}`, { csrf: CSRF, save: '1', customer_name: '[T] জার্নি ক্রেতা', mobile: '01712345678', address: '[T] বাড়ি ৫, রোড ৩, ধানমন্ডি', district_id: dhaka, shipping_fee: String(fee), status: 'processing', discount_type: 'amount', discount_value: '100', discount_note: 'টেস্ট ছাড়', ['qty[' + itemId + ']']: '2', ['qty[' + sql(`SELECT id FROM order_items WHERE order_id=${O1} AND product_id=${B_}`) + ']']: '1' });
    eq(sql(`SELECT discount FROM orders WHERE id=${O1}`), '100.00', 'ডিসকাউন্ট ১০০ বসেছে');
    eq(ctotal(O1), 1200 - 100 + fee, 'মোট = ১২০০ − ১০০ + ডেলিভারি');
    const cv = await (await cfetch(`/single_order_details.php?t=${(po.loc.match(/t=([0-9a-f]{32})/) || [])[1]}`)).text();
    check(!/টেস্ট ছাড়/.test(norm(cv)), 'গ্রাহক-পেজে ডিসকাউন্টের কারণ দেখায় না (লগইন ছাড়া পেজ খোলেই না)');
    // স্টক সংখ্যা বাড়ালে স্টক কমে
    r = await apost(`/admin/order_edit.php?id=${O1}`, { csrf: CSRF, save: '1', customer_name: '[T] জার্নি ক্রেতা', mobile: '01712345678', address: '[T] বাড়ি ৫, রোড ৩, ধানমন্ডি', district_id: dhaka, shipping_fee: String(fee), status: 'processing', discount_type: 'amount', discount_value: '100', ['qty[' + itemId + ']']: '3', ['qty[' + sql(`SELECT id FROM order_items WHERE order_id=${O1} AND product_id=${B_}`) + ']']: '1' });
    eq(sql(`SELECT stock FROM products WHERE id=${A_}`), 17, 'ক-এর পরিমাণ ২→৩ করলে স্টক ১৮ → ১৭');
    eq(ctotal(O1), 1700 - 100 + fee, 'মোট নতুন সাবটোটালে (১৭০০ − ১০০ + ডেলিভারি)');
    const O1total = ctotal(O1);

    console.log('\n=== ৫) কুরিয়ার (নকল সার্ভার) ===');
    proc = spawn(process.env.PHP_BIN || 'php', ['-S', `127.0.0.1:${PORT}`, path.join(__dirname, 'mock_steadfast.php')], { stdio: 'ignore' });
    for (let i = 0; i < 40; i++) { try { await mock('/__reset', {}, 'POST'); break; } catch { await sleep(250); } }
    r = await apost('/admin/courier_action.php', { csrf: CSRF, id: O1, action: 'send' });
    check(sql(`SELECT COALESCE(courier_consignment_id,'') FROM orders WHERE id=${O1}`) === '' && (await mock('/__log')).requests.length === 0, 'কুরিয়ার বন্ধ থাকায় পাঠানো হয়নি, বাইরে কল যায়নি');
    r = await apost('/admin/courier.php', { csrf: CSRF, action: 'save', enabled: '1', base_url: MOCK, api_key: 'test-key', secret_key: 'test-secret', auto_status: '1' });
    r = await apost('/admin/courier.php', { csrf: CSRF, action: 'test' });
    check(/1,234\.50/.test((await aget('/admin/courier.php')).t), 'সংযোগ পরীক্ষা সফল (ব্যালেন্স)');
    await apost('/admin/courier_action.php', { csrf: CSRF, id: O1, action: 'send' });
    const sent = (await mock('/__log')).requests.find((q) => q.path === '/create_order');
    check(sent && sent.body.recipient_phone === '01712345678' && sent.body.cod_amount === O1total && sent.body.invoice === sql(`SELECT order_code FROM orders WHERE id=${O1}`), `কুরিয়ারে সঠিক তথ্য গেছে: ফোন, COD ৳${O1total}, ইনভয়েস = অর্ডার কোড`);
    eq(sql(`SELECT status FROM orders WHERE id=${O1}`), 'shipped', 'auto-status চালু: পাঠানোর পর অর্ডার Shipped');
    const cid = sql(`SELECT courier_consignment_id FROM orders WHERE id=${O1}`);
    r = await apost(`/admin/order_edit.php?id=${O1}`, { csrf: CSRF, save: '1', customer_name: '[T] জার্নি ক্রেতা', mobile: '01712345678', address: 'বদলানো ঠিকানা', district_id: dhaka, shipping_fee: String(fee), status: 'shipped', ['qty[' + itemId + ']']: '3', ['qty[' + sql(`SELECT id FROM order_items WHERE order_id=${O1} AND product_id=${B_}`) + ']']: '1' });
    check(sql(`SELECT address FROM orders WHERE id=${O1}`) !== 'বদলানো ঠিকানা', 'পাঠানো অর্ডারের ঠিকানা বদলানো যায় না');
    await mock('/__set', { cid, status: 'delivered' }, 'POST');
    await apost('/admin/courier_action.php', { csrf: CSRF, id: O1, action: 'sync' });
    eq(sql(`SELECT status FROM orders WHERE id=${O1}`), 'delivered', 'কুরিয়ারে delivered → অর্ডার Delivered');
    eq(sql(`SELECT stock FROM products WHERE id=${A_}`), 17, 'Delivered হলেও স্টক বদলায়নি');
    // দ্বিতীয় অর্ডার: কুরিয়ারে বাতিল → স্টক ফেরে
    await custInit(); await cartAdd(B_, 2);
    await placeOrder({ customer_name: '[T] জার্নি কুরিয়ার-বাতিল', mobile: '01799999999', address: '[T] z', district_id: dhaka });
    const O3 = sql("SELECT id FROM orders WHERE customer_name='[T] জার্নি কুরিয়ার-বাতিল' ORDER BY id DESC LIMIT 1");
    eq(sql(`SELECT stock FROM products WHERE id=${B_}`), 7, 'O3 (খ ×২): স্টক ৯ → ৭');
    await apost('/admin/courier_action.php', { csrf: CSRF, id: O3, action: 'send' });
    await mock('/__set', { cid: sql(`SELECT courier_consignment_id FROM orders WHERE id=${O3}`), status: 'cancelled' }, 'POST');
    await apost('/admin/courier_action.php', { csrf: CSRF, id: O3, action: 'sync' });
    eq(sql(`SELECT status FROM orders WHERE id=${O3}`), 'cancelled', 'কুরিয়ারে cancelled → O3 Cancelled');
    eq(sql(`SELECT stock FROM products WHERE id=${B_}`), 9, 'O3 বাতিলে খ-এর স্টক ৭ → ৯ ফেরে');
    check(Number(sql(`SELECT COUNT(*) FROM courier_logs WHERE order_id IN (${O1},${O3})`)) >= 4, 'কুরিয়ার লগ জমেছে');

    console.log('\n=== ৬) খরচ ===');
    await apost('/admin/expenses.php', { csrf: CSRF, action: 'add', title: '[T] জার্নি খরচ', amount: '150', expense_date: today, category: '[T]ক্যাট', note: 'টেস্ট' });
    eq(sql("SELECT amount FROM expenses WHERE title='[T] জার্নি খরচ'"), '150.00', 'খরচ ১৫০ যোগ হয়েছে');

    console.log('\n=== ৭) রিপোর্ট ও ড্যাশবোর্ড হাতে-হিসাবের সাথে মেলানো ===');
    // শুধু আজকের [T] ডেটা — আজকের মোট থেকে আসল ডেটা বাদ দিয়ে আমাদের অংশ বের করা: আজকের আসল (non-[T]) যোগফল
    const realToday = (col) => Number(sql(`SELECT COALESCE(SUM(${col}),0) FROM orders WHERE DATE(created_at)=CURDATE() AND status<>'cancelled' AND customer_name NOT LIKE '[T]%'`));
    const myRevenue = O1total;
    const cogs = 3 * 350 + 1 * 104.55;
    const rep = await aget(`/admin/report_orders.php?type=custom&from=${today}&to=${today}&status=all`);
    check(rep.st === 200, 'বিক্রয় রিপোর্ট খোলে');
    const expectedRevenue = realToday('total') + myRevenue;
    check(rep.t.replace(/,/g, '').includes(String(Math.round(expectedRevenue))), `বিক্রয় রিপোর্টে আজকের রাজস্ব ৳${expectedRevenue} (আসল + আমার ${myRevenue}, বাতিল O2/O3 বাদ)`);
    const pl = await aget(`/admin/report_profit.php?type=custom&from=${today}&to=${today}`);
    check(pl.st === 200 && /id="kNet"/.test(pl.t), 'লাভ-ক্ষতি রিপোর্ট খোলে');
    const cogsDb = Number(sql("SELECT COALESCE(SUM(oi.cost*oi.qty),0) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE DATE(o.created_at)=CURDATE() AND o.status<>'cancelled' AND oi.cost IS NOT NULL"));
    check(pl.t.replace(/,/g, '').includes(String(Math.round(cogsDb))), `P&L-এ COGS ৳${cogsDb} (আমার অংশ ${cogs}) দেখায়`);
    const expDb = Number(sql("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date=CURDATE()"));
    check(pl.t.replace(/,/g, '').includes(String(Math.round(expDb))), `P&L-এ আজকের খরচ ৳${expDb} (আমার ১৫০ সহ)`);
    const ov = await aget(`/admin/order_view.php?id=${O1}`);
    const profit = Math.round(O1total - cogs);
    check(new RegExp('id="ovProfit"[^>]*>[\\s\\S]{0,200}' + String(profit).replace(/(\d)(?=(\d{3})+$)/, '$1,?')).test(ov.t), `অর্ডার-ভিউয়ের লাভ = মোট − ক্রয়মূল্য = ৳${profit}`);
    const dash = await aget('/admin/index.php');
    check(dash.st === 200 && /id="kOrdersToday"/.test(dash.t) && !/fatal error|warning:/i.test(dash.t), 'ড্যাশবোর্ড ক্র্যাশ ছাড়া খোলে');
    const ordToday = Number(sql("SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE()"));
    check(new RegExp('id="kOrdersToday"[^>]*>\\s*' + ordToday + '\\b').test(dash.t), `ড্যাশবোর্ডে আজকের অর্ডার ${ordToday}টি (বাতিল সহ)`);
    const stk = await aget('/admin/stock_report.php?low_only=0&q=' + encodeURIComponent('[T] জার্নি'));
    check(stk.st === 200 && norm(stk.t).includes(norm('জার্নি পণ্য')), 'স্টক রিপোর্টে পণ্য দেখায়');
    const csv = await aget(`/admin/report_orders.php?type=custom&from=${today}&to=${today}&export=csv`);
    check(norm(csv.t).includes(norm('জার্নি ক্রেতা')), 'CSV-তে আমার অর্ডার আছে');
    const pur = await aget(`/admin/report_purchases.php?type=custom&from=${today}&to=${today}`);
    check(pur.st === 200 && norm(pur.t).includes(norm('জার্নি সাপ্লায়ার')), 'ক্রয় রিপোর্টে সরবরাহকারী দেখায়');
    const exr = await aget(`/admin/report_expenses.php?type=custom&from=${today}&to=${today}`);
    check(exr.st === 200 && norm(exr.t).includes(norm('জার্নি খরচ')), 'খরচ রিপোর্টে এন্ট্রি দেখায়');

    console.log('\n=== ৮) গ্রাহক ট্র্যাকিং ===');
    const tr = await (await fetch(BASE + '/track_orders.php?m=01712345678')).text();
    check(/ORD\d+/.test(tr) && !/জার্নি ক্রেতা/.test(norm(tr)), 'মোবাইল দিয়ে অর্ডার কোড দেখা যায়, ক্রেতার নাম নয়');
  } catch (e) { console.error('ERROR: ' + e.stack); fail++; }
  finally {
    if (proc) { try { execFileSync('taskkill', ['/F', '/T', '/PID', String(proc.pid)], { stdio: 'ignore' }); } catch {} }
    for (const k of KEYS) { if (snap[k] === null) { try { sql(`DELETE FROM settings WHERE \`key\`='${k}'`); } catch {} } else setS(k, snap[k]); }
    cleanup();
    try { fs.unlinkSync(`${SESS_DIR}/sess_${sid}`); } catch {}
    try { fs.unlinkSync(path.join(require('os').tmpdir(), 'mock_sf_state.json')); } catch {}
  }
  console.log('\n=== পরিষ্কার ও আসল ডেটা অক্ষত ===');
  const aft = { prods: sql("SELECT COUNT(*) FROM products WHERE name NOT LIKE '[T]%'"), stock: sql("SELECT COALESCE(SUM(stock),0) FROM products WHERE name NOT LIKE '[T]%'"), orders: sql("SELECT COUNT(*) FROM orders WHERE customer_name NOT LIKE '[T]%'"), sup: sql('SELECT COUNT(*) FROM suppliers WHERE name NOT LIKE \'[T]%\''), exp: sql("SELECT COUNT(*) FROM expenses WHERE title NOT LIKE '[T]%'") };
  check(JSON.stringify(aft) === JSON.stringify(base), 'আসল পণ্য/স্টক/অর্ডার/সরবরাহকারী/খরচ অপরিবর্তিত ' + JSON.stringify(aft));
  check(sql("SELECT COUNT(*) FROM orders WHERE customer_name LIKE '[T]%'") === '0' && sql("SELECT COUNT(*) FROM products WHERE name LIKE '[T]%'") === '0' && sql("SELECT COUNT(*) FROM suppliers WHERE name LIKE '[T]%'") === '0', '[T] ডেটা সব মুছেছে');
  let same = true;
  for (const k of KEYS) { const had = sql(`SELECT COUNT(*) FROM settings WHERE \`key\`='${k}'`) === '1'; if ((had ? sql(`SELECT value FROM settings WHERE \`key\`='${k}'`) : null) !== snap[k]) same = false; }
  check(same, 'steadfast সেটিংস আগের অবস্থায়');
  console.log((fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exit(fail === 0 ? 0 : 1);
})();
