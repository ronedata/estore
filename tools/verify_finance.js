/**
 * tools/verify_finance.js — লাভ-ক্ষতি, ক্রয় রিপোর্ট, খরচ রিপোর্ট, অর্ডার-রিপোর্টের লাভ কলাম, অর্ডার-ভিউ লাভ, ড্যাশবোর্ড নিট লাভ end-to-end
 * node tools/verify_finance.js [url]
 * "[T]" অর্ডার/খরচ/ক্রয়/পণ্য (২০১৯ সালে) ও অস্থায়ী অ্যাডমিন সেশন নিজে বানায়-মোছে; আসল ডেটা বদলায় না।
 */
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const BASE = process.argv[2] || 'http://estore.test';
const PORT = Number(process.env.PORT_FI || 9502);
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
const S = { admin: rid(), rep: rid(), norep: rid() };
const CSRF = 'a'.repeat(32);
const mkSess = (sid, role, perms, id) => fs.writeFileSync(`${SESS_DIR}/sess_${sid}`,
  `admin_id|i:${id};admin_username|s:2:"tt";admin_role_name|s:${role.length}:"${role}";admin_permissions|a:${perms.length}:{${perms.map((p, i) => `i:${i};s:${p.length}:"${p}";`).join('')}}csrf|s:32:"${CSRF}";__sid_rotated|i:${Math.floor(Date.now() / 1000)};`);
const H = (sid) => (sid ? { Cookie: 'PHPSESSID=' + sid } : {});
const get = (p, sid) => fetch(BASE + p, { redirect: 'manual', headers: H(sid) });
const text = async (p, sid) => (await get(p, sid)).text();
const idv = (h, id) => { const m = h.match(new RegExp(`id="${id}"[^>]*>([^<]*)<`)); return m ? m[1].trim() : null; };
const money = (n) => (n < 0 ? '−' : '') + '৳' + Math.round(Math.abs(n)).toLocaleString('en-US');
const money2 = (n) => (n < 0 ? '−' : '') + '৳' + Math.abs(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const rowsIn = (h, tableId) => { const m = h.match(new RegExp(`id="${tableId}"[\\s\\S]*?</table>`)); return m ? (m[0].match(/<tr data-(?:id|key)=/g) || []).length : -1; };

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
    sql("DELETE FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE customer_name LIKE '[T]%' OR customer_name LIKE '=[T]%')");
    sql("DELETE FROM orders WHERE customer_name LIKE '[T]%' OR customer_name LIKE '=[T]%'");
    sql("DELETE FROM expenses WHERE title LIKE '[T]%' OR title LIKE '=[T]%'");
    sql("DELETE FROM purchase_payments WHERE purchase_id IN (SELECT id FROM purchases WHERE supplier_id IN (SELECT id FROM suppliers WHERE name LIKE '[T]%'))");
    sql("DELETE FROM purchase_items WHERE purchase_id IN (SELECT id FROM purchases WHERE supplier_id IN (SELECT id FROM suppliers WHERE name LIKE '[T]%'))");
    sql("DELETE FROM purchases WHERE supplier_id IN (SELECT id FROM suppliers WHERE name LIKE '[T]%')");
    sql("DELETE FROM suppliers WHERE name LIKE '[T]%'");
    sql("DELETE FROM products WHERE name LIKE '[T]%'");
  } catch (e) { console.log('cleanup: ' + e.message); }
  for (const s of Object.values(S)) try { fs.unlinkSync(`${SESS_DIR}/sess_${s}`); } catch {}
}

(async () => {
  cleanup();
  let chrome;
  const before = { orders: sql('SELECT COUNT(*) FROM orders'), exp: sql('SELECT COUNT(*) FROM expenses'), pur: sql('SELECT COUNT(*) FROM purchases'), prods: sql('SELECT COUNT(*) FROM products') };
  try {
    mkSess(S.admin, 'Super Admin', ['view_dashboard'], 1);
    mkSess(S.rep, 'Manager', ['view_dashboard', 'view_reports'], 2);
    mkSess(S.norep, 'Manager', ['view_dashboard', 'manage_orders'], 3);
    const cats = sql('SELECT id FROM categories ORDER BY id LIMIT 2').split(String.fromCharCode(10));
    sql(`INSERT INTO products (category_id, name, price, stock, active) VALUES (${cats[0]}, '[T] P1', 500, 10, 1), (${cats[1]}, '[T] P2', 500, 10, 1)`);
    const P1 = sql("SELECT id FROM products WHERE name='[T] P1'"), P2 = sql("SELECT id FROM products WHERE name='[T] P2'");
    const mkO = (code, when, status, sub, disc, ship, items, name = '[T] F') => {
      sql(`INSERT INTO orders (order_code, customer_name, mobile, address, shipping_area, shipping_fee, subtotal, discount, total, payment_method, status, created_at) VALUES ('${code}', '${name}', '01700000000', '[T]', 'dhaka', ${ship}, ${sub}, ${disc}, ${sub - disc + ship}, 'COD', '${status}', '${when}')`);
      const id = sql(`SELECT id FROM orders WHERE order_code='${code}'`);
      for (const [pid, q, pr, cost] of items) sql(`INSERT INTO order_items (order_id, product_id, qty, price, cost) VALUES (${id}, ${pid}, ${q}, ${pr}, ${cost === null ? 'NULL' : cost})`);
      return id;
    };
    const O1 = mkO('ORD9950001', '2019-03-05 10:00:00', 'delivered', 1040, 40, 60, [[P1, 2, 400, 250], [P2, 1, 240, 100]]);
    const O2 = mkO('ORD9950002', '2019-03-20 10:00:00', 'pending', 450, 0, 50, [[P1, 1, 450, 260]]);
    const O3 = mkO('ORD9950003', '2019-03-25 10:00:00', 'shipped', 900, 0, 60, [[P2, 3, 300, null]], '=[T] Formula');
    const O4 = mkO('ORD9950004', '2019-03-28 10:00:00', 'cancelled', 10000, 0, 70, [[P1, 10, 1000, 500]]);
    const O5 = mkO('ORD9950005', '2019-04-02 10:00:00', 'delivered', 500, 0, 0, [[P1, 1, 500, 300]]);
    const mkE = (t, a, d, c = 'টেস্ট') => sql(`INSERT INTO expenses (title, amount, expense_date, category) VALUES ('${t}', ${a}, '${d}', ${c === null ? 'NULL' : `'${c}'`})`);
    mkE('[T] E1', 200, '2019-03-10'); mkE('[T] E2', 300, '2019-03-31'); mkE('[T] E3', 100, '2019-04-01', null);
    sql("INSERT INTO suppliers (name) VALUES ('[T] S1'), ('[T] S2')");
    const S1 = sql("SELECT id FROM suppliers WHERE name='[T] S1'"), S2 = sql("SELECT id FROM suppliers WHERE name='[T] S2'");
    const mkPu = (sid, inv, date, total, status, items, pays) => {
      sql(`INSERT INTO purchases (supplier_id, invoice_no, purchase_date, total, status) VALUES (${sid}, '${inv}', '${date}', ${total}, '${status}')`);
      const id = sql(`SELECT id FROM purchases WHERE supplier_id=${sid} AND invoice_no='${inv}'`);
      for (const [pid, nm, q, c] of items) sql(`INSERT INTO purchase_items (purchase_id, product_id, product_name, qty, unit_cost, line_total) VALUES (${id}, ${pid}, '${nm}', ${q}, ${c}, ${q * c})`);
      for (const [d, a] of pays) sql(`INSERT INTO purchase_payments (purchase_id, amount, paid_date) VALUES (${id}, ${a}, '${d}')`);
    };
    mkPu(S1, 'A', '2019-03-10', 1000, 'active', [[P1, '[T] P1', 10, 100]], [['2019-03-12', 400]]);
    mkPu(S1, 'B', '2019-03-20', 500, 'active', [[P1, '[T] P1', 5, 100]], []);
    mkPu(S2, 'C', '2019-04-02', 300, 'active', [[P2, '[T] P2', 3, 100]], [['2019-04-02', 300]]);
    mkPu(S1, 'D', '2019-03-15', 999, 'cancelled', [[P2, '[T] P2', 9, 111]], [['2019-03-16', 50]]);
    const MAR = 'type=monthly&year=2019&month=3';
    const pages = ['report_profit', 'report_purchases', 'report_expenses', 'report_orders'];

    console.log('\n=== অ্যাক্সেস ও ট্যাব ===');
    for (const pg of pages) {
      let r = await get(`/admin/${pg}.php?${MAR}`);
      check(r.status === 302, `${pg}: লগইন ছাড়া বন্ধ`);
      r = await get(`/admin/${pg}.php?${MAR}`, S.norep);
      check(r.status !== 200 || !/id="(plKpis|prKpis|erKpis|rpKpis)"/.test(await r.text()), `${pg}: view_reports ছাড়া অ্যাডমিন পায় না`);
      r = await get(`/admin/${pg}.php?${MAR}`, S.norep);
      r = await get(`/admin/${pg}.php?export=csv&${MAR}`, S.norep);
      check(!(await r.text()).includes('তারিখ'), `${pg}: পারমিশন ছাড়া CSV-ও নয়`);
      r = await get(`/admin/${pg}.php?${MAR}`, S.rep);
      const h = await r.text();
      check(r.status === 200 && h.includes('id="rpTabs"') && (h.match(/nav-link/g) || []).length === 4, `${pg}: পারমিশনে খোলে; ট্যাব ৪টি (স্টক-পারমিশন না থাকায় "স্টক" ট্যাব নেই)`);
      const act = (h.match(/nav-link py-1 px-3 active" href="([^"]+)"/) || [])[1];
      check(act === pg + '.php', `${pg}: সক্রিয় ট্যাব ঠিক (${act})`);
    }
    let h = await text(`/admin/report_profit.php?${MAR}`, S.admin);
    check((h.match(/nav-link/g) || []).length === 5, 'Super Admin-এর ৫টি ট্যাব (স্টকসহ)');

    console.log('\n=== লাভ-ক্ষতি: মার্চ ২০১৯ (বাতিল বাদে সব) ===');
    check(idv(h, 'kRevenue') === money(2520) && idv(h, 'kGross') === money(1660) && idv(h, 'kExpenses') === money(500) && idv(h, 'kNet') === money(1160), 'কার্ড: রাজস্ব ৳2,520, মোট লাভ ৳1,660, খরচ ৳500, নিট ৳1,160');
    check(idv(h, 'sNetSales') === money2(2350) && idv(h, 'sDelivery') === money2(170) && idv(h, 'sRevenue') === money2(2520) && idv(h, 'sCogs') === money2(860) && idv(h, 'sGross') === money2(1660) && idv(h, 'sExpenses') === money2(500) && idv(h, 'sNet') === money2(1160), 'বিবরণী: বিক্রয় 2350 + ডেলিভারি 170 = রাজস্ব 2520; COGS 860; মোট লাভ 1660; খরচ 500; নিট 1160');
    check(h.includes('id="plUnknown"') && idv(h, 'plUnknownLines') === '1' && idv(h, 'plUnknownSales') === money(900) && idv(h, 'plKnownMargin') === money(630), 'ক্রয়মূল্য-অজানা সতর্কতা: ১টি আইটেম, বিক্রি ৳900, জানা-আইটেমে মার্জিন ৳630');
    check(/text-success/.test(h.match(/id="kNet"[^>]*/)[0] || '') || /class="kpi-value text-success" id="kNet"/.test(h), 'লাভ হলে নিট সংখ্যা সবুজ');
    check(rowsIn(h, 'plMonths') === -1, 'একমাসের সময়ে "মাস অনুযায়ী" টেবিল নেই');
    const catRows = (h.match(/id="plCats"[\s\S]*?<\/table>/) || [''])[0];
    check(/490/.test(catRows) && /140/.test(catRows), 'ক্যাটেগরি অনুযায়ী মার্জিন: 490 ও 140');
    const prodRows = (h.match(/id="plProducts"[\s\S]*?<\/table>/) || [''])[0];
    check(prodRows.includes('[T] P1') && prodRows.includes('[T] P2') && prodRows.indexOf('[T] P1') < prodRows.indexOf('[T] P2'), 'শীর্ষ পণ্য: P1 (490) তারপর P2 (140)');
    h = await text(`/admin/report_profit.php?${MAR}&basis=delivered`, S.admin);
    check(idv(h, 'kRevenue') === money(1060) && idv(h, 'kGross') === money(460) && idv(h, 'kNet') === money(-40) && !h.includes('id="plUnknown"'), 'শুধু Delivered: রাজস্ব ৳1,060, মোট লাভ ৳460, নিট −৳40, অজানা-সতর্কতা নেই');
    check(/class="kpi-value text-danger" id="kNet"/.test(h) && /id="sNet" class="text-danger"/.test(h), 'ক্ষতি হলে নিট সংখ্যা লাল');
    check(h.includes('শুধু Delivered অর্ডার') && /<option value="delivered" selected>/.test(h), 'শিরোনামে ও ড্রপডাউনে বেসিস দেখায়');
    h = await text('/admin/report_profit.php?type=monthly&year=2019&month=4', S.admin);
    check(idv(h, 'kRevenue') === money(500) && idv(h, 'kNet') === money(100), 'এপ্রিল: রাজস্ব ৳500, নিট ৳100 (মার্চের কিছু ঢোকেনি)');
    h = await text('/admin/report_profit.php?type=yearly&year=2019', S.admin);
    check(rowsIn(h, 'plMonths') === 12 && idv(h, 'kNet') === money(1260), 'বার্ষিক ২০১৯: ১২টি মাসের সারি, নিট ৳1,260');
    check(/data-key="2019-03"[\s\S]*?৳2,520[\s\S]*?৳860[\s\S]*?৳500[\s\S]*?৳1,160/.test(h), 'মাস-টেবিলে মার্চ: রাজস্ব 2520, COGS 860, খরচ 500, লাভ 1160');
    h = await text('/admin/report_profit.php?type=monthly&year=2019&month=8', S.admin);
    check(idv(h, 'kRevenue') === money(0) && idv(h, 'kNet') === money(0) && !h.includes('id="plUnknown"') && /ক্রয়মূল্য-জানা কোনো বিক্রি নেই/.test(h), 'ফাঁকা মাসে সব ৳0, কোনো সতর্কতা/ক্র্যাশ নেই');
    h = await text('/admin/report_profit.php?type=custom&from=2019-02-30&to=x', S.admin);
    check(h.includes('id="plError"') && h.includes('id="plKpis"'), 'ভুল তারিখ → সতর্কতা + চালু মাস, ভাঙে না');
    for (const q of ['type[]=x', 'basis[]=delivered', 'year[]=2019', 'month[]=3', 'from[]=a&type=custom', 'export[]=csv', "basis=delivered'%20OR%201=1", 'type=%00']) {
      const rr = await get('/admin/report_profit.php?' + q, S.admin); const tt = await rr.text();
      check(rr.status === 200 && !/fatal|uncaught|warning:|SQLSTATE/i.test(tt), `profit ইনপুট "${q}" → ক্র্যাশ নেই`);
    }
    let r = await get(`/admin/report_profit.php?export=csv&${MAR}`, S.admin);
    const buf = Buffer.from(await r.arrayBuffer());
    const csv = buf.toString('utf8').replace(/^\uFEFF/, '').replace(/"/g, '');
    check(buf[0] === 0xef && /attachment; filename="profit_loss_2019-03-01_2019-03-31\.csv"/.test(r.headers.get('content-disposition') || '') && csv.includes('নিট লাভ/(ক্ষতি),1160') && csv.includes('রাজস্ব,2520') && csv.includes('বিক্রিত পণ্যের ক্রয়মূল্য (COGS),860'), 'CSV: BOM, ফাইল-নাম, রাজস্ব 2520 / COGS 860 / নিট 1160');
    check(csv.includes('ক্রয়মূল্য অজানা আইটেম (সংখ্যা),1') && csv.includes('Mar 2019'), 'CSV-তে অজানা-আইটেম সংখ্যা ও মাসভিত্তিক সারি');

    console.log('\n=== ক্রয় রিপোর্ট ===');
    h = await text(`/admin/report_purchases.php?${MAR}`, S.admin);
    check(idv(h, 'kTotal') === money(1500) && idv(h, 'kPaid') === money(400) && idv(h, 'kDue') === money(1100) && idv(h, 'kPaidIn') === money(400), 'মার্চ: ক্রয় ৳1,500, পরিশোধ ৳400, বাকি ৳1,100, এ-সময়ে দেওয়া ৳400 (বাতিল D-র ৫০ ধরা হয়নি)');
    const supTbl = h.match(/id="prSuppliers"[\s\S]*?<\/table>/)[0];
    check((supTbl.match(/<tr>/g) || []).length === 2 && /\[T\] S1/.test(supTbl) && !/\[T\] S2/.test(supTbl), 'সরবরাহকারী অনুযায়ী: শুধু S1 (বাতিল D ও এপ্রিলের S2 বাদ)');
    const pp = h.match(/id="prProducts"[\s\S]*?<\/table>/)[0];
    check(pp.includes('[T] P1') && /15/.test(pp) && !pp.includes('[T] P2'), 'কেনা পণ্য: P1 পরিমাণ 15 (বাতিল D-র P2 নেই)');
    check(rowsIn(h, 'prTable') === 2 && !h.match(/id="prTable"[\s\S]*?<\/table>/)[0].includes('বাতিল'), 'তালিকা: ২টি সক্রিয় ক্রয় (ডিফল্টে বাতিল নেই)');
    h = await text(`/admin/report_purchases.php?${MAR}&status=cancelled`, S.admin);
    check(rowsIn(h, 'prTable') === 1 && h.includes('বাতিল'), 'status=cancelled → বাতিল ক্রয় ১টি');
    h = await text(`/admin/report_purchases.php?${MAR}&status=due`, S.admin);
    check(rowsIn(h, 'prTable') === 2, 'status=due → বাকি-থাকা ২টি (A, B)');
    h = await text(`/admin/report_purchases.php?${MAR}&status=paid`, S.admin);
    check(rowsIn(h, 'prTable') === 0, 'status=paid → মার্চে পুরো-শোধ নেই');
    h = await text(`/admin/report_purchases.php?${MAR}&supplier=${S1}`, S.admin);
    check(!h.includes('id="prSuppliers"') && rowsIn(h, 'prTable') === 2 && idv(h, 'kTotal') === money(1500), 'সরবরাহকারী ফিল্টারে "সরবরাহকারী অনুযায়ী" টেবিল থাকে না, তালিকা/KPI সীমিত');
    h = await text(`/admin/report_purchases.php?${MAR}&supplier=${S2}`, S.admin);
    check(idv(h, 'kTotal') === money(0) && rowsIn(h, 'prTable') === 0, 'S2 (মার্চে ক্রয় নেই) → ৳0');
    h = await text('/admin/report_purchases.php?type=yearly&year=2019', S.admin);
    check(idv(h, 'kTotal') === money(1800) && idv(h, 'kPaidIn') === money(700), 'বার্ষিক ২০১৯: ক্রয় ৳1,800 (1000+500+300), দেওয়া টাকা ৳700 (400+300)');
    for (const q of ['type[]=x', 'supplier[]=1', 'status[]=due', 'page[]=2', 'year[]=2019', 'export[]=csv', "status=due'%20OR%201=1", 'type=custom&from=bad&to=bad']) {
      const rr = await get('/admin/report_purchases.php?' + q, S.admin); const tt = await rr.text();
      check(rr.status === 200 && !/fatal|uncaught|warning:|SQLSTATE/i.test(tt), `purchases ইনপুট "${q}" → ক্র্যাশ নেই`);
    }
    r = await get(`/admin/report_purchases.php?export=csv&${MAR}`, S.admin);
    const pcsv = (await r.text()).replace(/^\uFEFF/, '').split(/\r?\n/).filter(Boolean);
    check(pcsv.length === 1 + 2 && pcsv.some((l) => l.includes(',A,')) && pcsv.some((l) => l.includes('বাকি')), 'CSV: শিরোনাম + ২টি সারি');

    console.log('\n=== খরচ রিপোর্ট ===');
    h = await text(`/admin/report_expenses.php?${MAR}`, S.admin);
    check(idv(h, 'kTotal') === money(500) && idv(h, 'kCount') === '2' && idv(h, 'kDaily') === money(500 / 31) && idv(h, 'kTopCat') === 'টেস্ট', 'মার্চ: মোট ৳500, এন্ট্রি ২, দৈনিক গড় ৳16, শীর্ষ ক্যাটেগরি "টেস্ট"');
    check(/id="erCats"/.test(h) && /100\.0%/.test(h), 'ক্যাটেগরি অনুযায়ী বারে শতাংশ (100.0%)');
    const top = h.match(/id="erTop"[\s\S]*?<\/table>/)[0];
    check(top.indexOf('[T] E2') > 0 && top.indexOf('[T] E2') < top.indexOf('[T] E1') && !top.includes('[T] E3'), 'বড় খরচ: E2 (300) তারপর E1 (200); ফিল্টারের বাইরের E3 নেই');
    check(rowsIn(h, 'erTable') === 2 && rowsIn(h, 'erTable') !== -1, 'তালিকায় ২টি খরচ');
    check(!h.includes('id="erMonths"'), 'একমাসে "মাস অনুযায়ী" নেই');
    h = await text('/admin/report_expenses.php?type=yearly&year=2019', S.admin);
    check(idv(h, 'kTotal') === money(600) && (h.match(/data-key="2019-\d\d"/g) || []).length === 12 && /data-key="2019-03"[\s\S]*?৳500/.test(h), 'বার্ষিক ২০১৯: মোট ৳600, ১২ মাসের বার, মার্চ ৳500');
    h = await text(`/admin/report_expenses.php?${MAR}&cat=__none`, S.admin);
    check(idv(h, 'kTotal') === money(0) && idv(h, 'kTopCat') === '—', 'ক্যাটেগরি=__none (মার্চে নেই) → ৳0');
    h = await text('/admin/report_expenses.php?type=yearly&year=2019&cat=__none', S.admin);
    check(idv(h, 'kTotal') === money(100), 'বার্ষিক + ক্যাটেগরিহীন → ৳100 (E3)');
    h = await text('/admin/report_expenses.php?type=monthly&year=2019&month=8', S.admin);
    check(idv(h, 'kTotal') === money(0) && /id="erNoCats"/.test(h) && /এই সময়ে কোনো খরচ নেই/.test(h), 'ফাঁকা মাস: ৳0 ও "কোনো খরচ নেই"');
    for (const q of ['type[]=x', 'cat[]=a', 'page[]=2', 'export[]=csv', "cat=x'%20OR%201=1", 'type=custom&from=bad&to=bad']) {
      const rr = await get('/admin/report_expenses.php?' + q, S.admin); const tt = await rr.text();
      check(rr.status === 200 && !/fatal|uncaught|warning:|SQLSTATE/i.test(tt), `expenses ইনপুট "${q}" → ক্র্যাশ নেই`);
    }
    r = await get(`/admin/report_expenses.php?export=csv&${MAR}`, S.admin);
    const ecsv = (await r.text()).replace(/^\uFEFF/, '').split(/\r?\n/).filter(Boolean);
    check(ecsv.length === 1 + 2 + 1 && ecsv[ecsv.length - 1].startsWith('মোট') && ecsv[ecsv.length - 1].includes('500'), 'CSV: শিরোনাম + ২ সারি + মোট 500');

    console.log('\n=== অর্ডার রিপোর্টে "লাভ" কলাম ও অর্ডার-ভিউ ===');
    h = await text(`/admin/report_orders.php?${MAR}`, S.admin);
    const trs = [...h.matchAll(/<tr>\s*<td data-label="অর্ডার">#(ORD\d+)<\/td>[\s\S]*?<td data-label="লাভ"([^>]*)>([^<]*)<\/td>/g)].reduce((o, m) => (o[m[1]] = { attrs: m[2], val: m[3].trim() }, o), {});
    check(h.includes('<th class="text-end">লাভ</th>'), 'অর্ডার তালিকায় "লাভ" কলাম আছে');
    check(trs['ORD9950001']?.val === '৳460' && /text-success/.test(trs['ORD9950001']?.attrs || ''), 'O1: লাভ ৳460 (সবুজ)');
    check(trs['ORD9950002']?.val === '৳240', 'O2: লাভ ৳240');
    check(trs['ORD9950003']?.val === '—' && /ক্রয়মূল্য অজানা/.test(trs['ORD9950003']?.attrs || ''), 'O3 (ক্রয়মূল্য অজানা): "—" ও কারণের টুলটিপ');
    check(trs['ORD9950004']?.val === '—', 'বাতিল অর্ডারে লাভ "—"');
    r = await get(`/admin/report_orders.php?export=csv&${MAR}`, S.admin);
    const ocsv = (await r.text()).replace(/^\uFEFF/, '').split(/\r?\n/).filter(Boolean);
    check(ocsv[0].includes('লাভ') && ocsv.find((l) => l.includes('ORD9950001'))?.includes(',460') && /ORD9950003.*,,/.test(ocsv.find((l) => l.includes('ORD9950003')) || ''), 'CSV-তে "লাভ" কলাম: O1 = 460, O3 ফাঁকা');
    check(ocsv.some((l) => l.includes("'=[T] Formula")), 'CSV ফর্মুলা-ইনজেকশন গার্ড আগের মতো কাজ করে');
    h = await text(`/admin/order_view.php?id=${O1}`, S.admin);
    check(/id="ovProfit"[^>]*>[\s\S]*?৳460/.test(h) && h.includes('ক্রয়মূল্য ৳600'), 'অর্ডার-ভিউ O1: লাভ ৳460 (মোট − ক্রয়মূল্য ৳600)');
    h = await text(`/admin/order_view.php?id=${O3}`, S.admin);
    check(/id="ovProfit"[^>]*>[\s\S]*?অজানা/.test(h) && h.includes('১টি আইটেমের ক্রয়মূল্য নেই'.replace('১', '1')), 'অর্ডার-ভিউ O3: লাভ "অজানা (1টি আইটেমের ক্রয়মূল্য নেই)"');
    h = await text(`/admin/order_view.php?id=${O1}`, S.norep);
    check(!h.includes('id="ovProfit"') && h.includes('ORD9950001'), 'view_reports ছাড়া অ্যাডমিন অর্ডার-ভিউ দেখে কিন্তু "লাভ" সারি নেই');

    console.log('\n=== ড্যাশবোর্ড: এই মাসের নিট লাভ ===');
    const q1 = (x) => Number(sql(x));
    const rev = q1("SELECT COALESCE(SUM(total),0) FROM orders WHERE status<>'cancelled' AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01') AND created_at < DATE_ADD(DATE_FORMAT(NOW(),'%Y-%m-01'), INTERVAL 1 MONTH)");
    const cogs = q1("SELECT COALESCE(SUM(oi.cost*oi.qty),0) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE oi.cost IS NOT NULL AND o.status<>'cancelled' AND o.created_at >= DATE_FORMAT(NOW(),'%Y-%m-01') AND o.created_at < DATE_ADD(DATE_FORMAT(NOW(),'%Y-%m-01'), INTERVAL 1 MONTH)");
    const exp = q1("SELECT COALESCE(SUM(amount),0) FROM expenses WHERE expense_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01') AND expense_date <= LAST_DAY(CURDATE())");
    h = await text('/admin/index.php', S.admin);
    check(idv(h, 'dMonthNet') === money(rev - cogs - exp), `ড্যাশবোর্ডের "এই মাসের নিট লাভ" হিসাবের সাথে মেলে: ${idv(h, 'dMonthNet')} = ${money(rev - cogs - exp)}`);
    check(h.includes('href="report_profit.php"'), 'কার্ডে লাভ-ক্ষতি রিপোর্টের লিংক');
    h = await text('/admin/index.php', S.norep);
    check(!h.includes('id="dMonthNet"'), 'view_reports ছাড়া ড্যাশবোর্ডে নিট লাভ কার্ড নেই');

    console.log('\n=== ব্রাউজার ===');
    chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, '--no-sandbox', '--disable-gpu', '--hide-scrollbars', '--user-data-dir=' + os.tmpdir() + '\\fi_prof_' + Date.now(), 'about:blank'], { stdio: 'ignore' });
    let ready = false;
    for (let i = 0; i < 40; i++) { try { await fetch(`http://127.0.0.1:${PORT}/json/version`); ready = true; break; } catch { await sleep(250); } }
    if (!ready) throw new Error('DevTools নেই');
    const t = await fetch(`http://127.0.0.1:${PORT}/json/new?about:blank`, { method: 'PUT' }).then((x) => x.json());
    const ws = new WebSocket(t.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.addEventListener('open', res, { once: true }); ws.addEventListener('error', rej, { once: true }); });
    let id = 1; const call = (m, p) => send(ws, id++, m, p);
    await call('Page.enable'); await call('Runtime.enable'); await call('Network.enable'); await call('Network.setCacheDisabled', { cacheDisabled: true });
    await call('Network.setCookie', { name: 'PHPSESSID', value: S.admin, domain: new URL(BASE).hostname, path: '/' });
    const ev = async (expr) => { const x = await call('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true }); if (x.exceptionDetails) throw new Error(x.exceptionDetails.exception?.description || x.exceptionDetails.text); return x.result.value; };
    const go = async (p, w) => { await call('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 600 }); await call('Page.navigate', { url: BASE + p }); await sleep(1800); };
    const specs = [['report_profit', 'plType', 'pl', 'plKpis'], ['report_purchases', 'prType', 'pr', 'prKpis'], ['report_expenses', 'erType', 'er', 'erKpis'], ['report_orders', 'rpType', 'rp', 'rpKpis']];
    for (const w of [1326, 390]) {
      for (const [pg, typeId, pre, kpis] of specs) {
        await go(`/admin/${pg}.php?${MAR}`, w);
        const m = await ev(`(()=>({hs:document.documentElement.scrollWidth>innerWidth+1,k:document.querySelectorAll('#${kpis} .kpi-card').length,vis:[...document.querySelectorAll('#${kpis} .kpi-value')].every(e=>e.getBoundingClientRect().width>0),tabs:document.querySelectorAll('#rpTabs .nav-link').length,cust:getComputedStyle(document.querySelector('.${pre}-custom')).display}))()`);
        check(!m.hs && m.k === 4 && m.vis && m.tabs === 5 && m.cust === 'none', `${pg} ${w}px: overflow নেই, ৪টি কার্ড দৃশ্যমান, ৫টি ট্যাব, মাসিকে কাস্টম ঘর লুকানো`);
        await ev(`(()=>{const s=document.getElementById('${typeId}');s.value='custom';s.dispatchEvent(new Event('change'))})()`);
        check((await ev(`getComputedStyle(document.querySelector('.${pre}-custom')).display`)) !== 'none', `${pg} ${w}px: "কাস্টম" বাছলে শুরু/শেষ ঘর আসে`);
        if (pg !== 'report_orders') { const shot = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false }); fs.writeFileSync(path.join(os.tmpdir(), `${pg}_${w}.png`), Buffer.from(shot.data, 'base64')); }
      }
    }
    await go('/admin/report_profit.php?type=yearly&year=2019', 1326);
    check((await ev("document.querySelectorAll('#plMonths tbody tr').length")) === 12, 'বার্ষিক লাভ-ক্ষতি: মাস-টেবিলে ১২ সারি (ব্রাউজারে)');
    await go('/admin/index.php', 1326);
    check(!(await ev('document.documentElement.scrollWidth>innerWidth+1')) && (await ev("document.querySelectorAll('#dashSales .kpi-card').length")) === 4, 'ড্যাশবোর্ড ১৩২৬px: ৪টি বিক্রয়-কার্ড, overflow নেই');
    const shotD = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
    fs.writeFileSync(path.join(os.tmpdir(), 'dash_fin_1326.png'), Buffer.from(shotD.data, 'base64'));
    await go('/admin/index.php', 390);
    check(!(await ev('document.documentElement.scrollWidth>innerWidth+1')), 'ড্যাশবোর্ড ৩৯০px: overflow নেই');
    ws.close();
  } catch (e) { console.error('ERROR: ' + e.message); fail++; }
  finally { try { chrome && chrome.kill(); } catch {} cleanup(); }
  const after = { orders: sql('SELECT COUNT(*) FROM orders'), exp: sql('SELECT COUNT(*) FROM expenses'), pur: sql('SELECT COUNT(*) FROM purchases'), prods: sql('SELECT COUNT(*) FROM products') };
  check(JSON.stringify(after) === JSON.stringify(before), 'টেস্ট শেষে অর্ডার/খরচ/ক্রয়/পণ্যের সংখ্যা আগের মতো');
  console.log((fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exitCode = fail === 0 ? 0 : 1;
})();
