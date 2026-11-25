/**
 * tools/verify_purchases.js — সরবরাহকারী, ক্রয়, পরিশোধ (কিস্তি/একসাথে), বাতিল end-to-end (fetch + headless Chrome)
 * node tools/verify_purchases.js [url]
 * "[T]" সরবরাহকারী/পণ্য/ক্রয় ও অস্থায়ী অ্যাডমিন সেশন নিজে বানায়-মোছে; আসল পণ্য/স্টক বদলায় না।
 */
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const BASE = process.argv[2] || 'http://estore.test';
const PORT = Number(process.env.PORT_PU || 9501);
const CHROME = process.env.NAV_BROWSER || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const MYSQL = process.env.MYSQL_BIN || 'mysql';
const SESS_DIR = 'C:/laragon/tmp';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let pass = 0, fail = 0;
const ok = (m) => { pass++; console.log('  PASS  ' + m); };
const bad = (m) => { fail++; console.log('  FAIL  ' + m); };
const check = (c, m) => (c ? ok(m) : bad(m));
const NL = String.fromCharCode(10);
const sql = (q) => execFileSync(MYSQL, ['-uroot', '-N', '--default-character-set=utf8mb4', 'estore'], { encoding: 'utf8', input: q + ';\n' }).replace(/\r/g, '').trim();
const rid = () => require('crypto').randomBytes(13).toString('hex');
const S = { admin: rid(), pur: rid(), nopur: rid() };
const CSRF = 'a'.repeat(32);
const mkSess = (sid, role, perms, id) => fs.writeFileSync(`${SESS_DIR}/sess_${sid}`,
  `admin_id|i:${id};admin_username|s:2:"tt";admin_role_name|s:${role.length}:"${role}";admin_permissions|a:${perms.length}:{${perms.map((p, i) => `i:${i};s:${p.length}:"${p}";`).join('')}}csrf|s:32:"${CSRF}";__sid_rotated|i:${Math.floor(Date.now() / 1000)};`);
const H = (sid) => (sid ? { Cookie: 'PHPSESSID=' + sid } : {});
const get = (p, sid) => fetch(BASE + p, { redirect: 'manual', headers: H(sid) });
const text = async (p, sid) => (await get(p, sid)).text();
/** pairs: [[k,v],...] — একই কী বহুবার (product_id[] ইত্যাদি) পাঠানো যায় */
const post = (p, pairs, sid) => { const u = new URLSearchParams(); for (const [k, v] of pairs) u.append(k, v); return fetch(BASE + p, { method: 'POST', redirect: 'manual', headers: { ...H(sid), 'Content-Type': 'application/x-www-form-urlencoded' }, body: u.toString() }); };
const obj = (o) => Object.entries(o);
const idv = (h, id) => { const m = h.match(new RegExp(`id="${id}"[^>]*>([^<]*)<`)); return m ? m[1].trim() : null; };
const money2 = (n) => '৳' + Number(n).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const money0 = (n) => '৳' + Math.round(n).toLocaleString('en-US');
const today = new Date().toISOString().slice(0, 10);

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
    sql("DELETE FROM purchase_payments WHERE purchase_id IN (SELECT id FROM purchases WHERE supplier_id IN (SELECT id FROM suppliers WHERE name LIKE '[T]%'))");
    sql("DELETE FROM purchase_items WHERE purchase_id IN (SELECT id FROM purchases WHERE supplier_id IN (SELECT id FROM suppliers WHERE name LIKE '[T]%'))");
    sql("DELETE FROM purchases WHERE supplier_id IN (SELECT id FROM suppliers WHERE name LIKE '[T]%')");
    sql("DELETE FROM suppliers WHERE name LIKE '[T]%' OR name LIKE '%<script>%'");
    sql("DELETE FROM products WHERE name LIKE '[T]%'");
  } catch (e) { console.log('cleanup: ' + e.message); }
  for (const s of Object.values(S)) try { fs.unlinkSync(`${SESS_DIR}/sess_${s}`); } catch {}
}

(async () => {
  cleanup();
  let chrome;
  const before = { sup: sql('SELECT COUNT(*) FROM suppliers'), pur: sql('SELECT COUNT(*) FROM purchases'), items: sql('SELECT COUNT(*) FROM purchase_items'), pay: sql('SELECT COUNT(*) FROM purchase_payments'), prods: sql('SELECT COUNT(*) FROM products'), realStock: sql('SELECT COALESCE(SUM(stock),0) FROM products') };
  try {
    mkSess(S.admin, 'Super Admin', ['view_dashboard'], 1);
    mkSess(S.pur, 'Manager', ['view_dashboard', 'manage_purchases'], 2);
    mkSess(S.nopur, 'Manager', ['view_dashboard', 'manage_products'], 3);
    const cat = sql('SELECT id FROM categories ORDER BY id LIMIT 1');
    sql(`INSERT INTO products (category_id, name, price, stock, active, cost_price) VALUES (${cat}, '[T] PA', 500, 10, 1, 100), (${cat}, '[T] PB', 500, 0, 1, NULL), (${cat}, '[T] PC', 500, 5, 1, NULL)`);
    const PA = sql("SELECT id FROM products WHERE name='[T] PA'"), PB = sql("SELECT id FROM products WHERE name='[T] PB'"), PC = sql("SELECT id FROM products WHERE name='[T] PC'");
    const stockOf = (p) => Number(sql(`SELECT stock FROM products WHERE id=${p}`));
    const costOf = (p) => sql(`SELECT cost_price FROM products WHERE id=${p}`);

    console.log('\n=== অ্যাক্সেস ===');
    const pages = ['suppliers', 'purchases', 'purchase_form', 'purchase_view', 'supplier_view'];
    for (const pg of pages) {
      let r = await get(`/admin/${pg}.php`);
      check(r.status === 302, `${pg}: লগইন ছাড়া বন্ধ`);
      r = await get(`/admin/${pg}.php`, S.nopur);
      const t = await r.text();
      check(r.status !== 200 || !/id="(suForm|puFilter|pfForm|pvKpis|svKpis)"/.test(t), `${pg}: manage_purchases ছাড়া অ্যাডমিন পায় না (${r.status})`);
      r = await get(`/admin/${pg}.php`, S.pur);
      check(r.status === 200, `${pg}: manage_purchases পারমিশনে খোলে`);
    }
    let h = await text('/admin/index.php', S.pur);
    check(/>ক্রয়<\/span>/.test(h) && />সরবরাহকারী<\/span>/.test(h), 'সাইডবারে "ক্রয়" ও "সরবরাহকারী" মেনু আছে');
    h = await text('/admin/index.php', S.nopur);
    check(!/>সরবরাহকারী<\/span>/.test(h), 'পারমিশন ছাড়া মেনু নেই');
    let r = await post('/admin/suppliers.php', obj({ csrf: CSRF, action: 'add', name: '[T] Blocked' }), S.nopur);
    check(sql("SELECT COUNT(*) FROM suppliers WHERE name='[T] Blocked'") === '0', 'পারমিশন ছাড়া POST-এ সরবরাহকারী যোগ হয় না');

    console.log('\n=== সরবরাহকারী ===');
    const cntS = () => Number(sql("SELECT COUNT(*) FROM suppliers WHERE name LIKE '[T]%' OR name LIKE '%<script>%'"));
    let c0 = cntS();
    r = await post('/admin/suppliers.php', obj({ csrf: CSRF, action: 'add', name: '[T] রহিম ট্রেডার্স', phone: '01711111111', address: 'ঢাকা', note: 'প্রধান' }), S.admin);
    check(r.status === 302 && cntS() === c0 + 1, 'বৈধ সরবরাহকারী যোগ (redirect)');
    const S1 = sql("SELECT id FROM suppliers WHERE name='[T] রহিম ট্রেডার্স'");
    c0 = cntS();
    for (const [l, patch] of [['নাম ফাঁকা', { name: '' }], ['নাম ১২১ অক্ষর', { name: '[T]' + 'ক'.repeat(118) }], ['ফোনে অক্ষর', { phone: '017abc' }], ['নোট ২৫৬', { note: 'x'.repeat(256) }]]) {
      r = await post('/admin/suppliers.php', obj({ csrf: CSRF, action: 'add', name: '[T] X', ...patch }), S.admin);
      check(cntS() === c0 && r.status === 200 && (await r.text()).includes('id="suErrors"'), `অবৈধ (${l}) → যোগ হয় না, ত্রুটি দেখায়`);
    }
    r = await post('/admin/suppliers.php', [['csrf', CSRF], ['action', 'add'], ['name[]', 'x'], ['phone[]', 'y']], S.admin);
    check(r.status !== 500 && cntS() === c0, 'অ্যারে ইনপুটে ক্র্যাশ নয়');
    r = await post('/admin/suppliers.php', obj({ action: 'add', name: '[T] NoCsrf' }), S.admin);
    check(cntS() === c0, 'CSRF ছাড়া যোগ হয় না');
    await post('/admin/suppliers.php', obj({ csrf: CSRF, action: 'add', name: '[T] <script>alert(1)</script>' }), S.admin);
    h = await text('/admin/suppliers.php', S.admin);
    check(!h.includes('<script>alert(1)</script>') && h.includes('&lt;script&gt;'), 'নামে HTML থাকলে escape হয়');
    sql("DELETE FROM suppliers WHERE name LIKE '%<script>%'");
    h = await text(`/admin/suppliers.php?edit=${S1}`, S.admin);
    check(/name="action" value="update"/.test(h) && h.includes('value="[T] রহিম ট্রেডার্স"'), 'Edit লিংকে ফর্ম মান-ভরা');
    await post('/admin/suppliers.php', obj({ csrf: CSRF, action: 'update', id: S1, name: '[T] রহিম ট্রেডার্স (নতুন)', phone: '01800000000' }), S.admin);
    check(sql(`SELECT phone FROM suppliers WHERE id=${S1}`) === '01800000000' && sql(`SELECT name FROM suppliers WHERE id=${S1}`).includes('নতুন'), 'আপডেট সফল');
    await post('/admin/suppliers.php', obj({ csrf: CSRF, action: 'update', id: S1, name: '' }), S.admin);
    check(sql(`SELECT name FROM suppliers WHERE id=${S1}`).includes('নতুন'), 'অবৈধ আপডেটে আগের মান অক্ষত');
    await post('/admin/suppliers.php', obj({ csrf: CSRF, action: 'add', name: '[T] করিম এন্টারপ্রাইজ' }), S.admin);
    const S2 = sql("SELECT id FROM suppliers WHERE name='[T] করিম এন্টারপ্রাইজ'");
    await post('/admin/suppliers.php', obj({ csrf: CSRF, action: 'toggle', id: S2 }), S.admin);
    check(sql(`SELECT active FROM suppliers WHERE id=${S2}`) === '0', 'নিষ্ক্রিয় করা যায়');
    h = await text('/admin/purchase_form.php', S.admin);
    const supSel = (h.match(/<select name="supplier_id"[\s\S]*?<\/select>/) || [''])[0];
    check(!supSel.includes(`value="${S2}"`) && supSel.includes(`value="${S1}"`), 'নিষ্ক্রিয় সরবরাহকারী ক্রয়-ফর্মের তালিকায় আসে না');
    await post('/admin/suppliers.php', obj({ csrf: CSRF, action: 'toggle', id: S2 }), S.admin);
    check(sql(`SELECT active FROM suppliers WHERE id=${S2}`) === '1', 'আবার সক্রিয় হয়');
    r = await get(`/admin/suppliers.php?action=delete&id=${S2}`, S.admin);
    check(sql(`SELECT COUNT(*) FROM suppliers WHERE id=${S2}`) === '1', 'GET দিয়ে মোছা যায় না');
    h = await text('/admin/suppliers.php?q=' + encodeURIComponent('করিম'), S.admin);
    check(h.includes('[T] করিম') && !h.includes('[T] রহিম'), 'খোঁজা কাজ করে');
    for (const q of ['q[]=x', "q=x'%20OR%201=1", 'edit[]=1', 'edit=abc']) {
      const rr = await get('/admin/suppliers.php?' + q, S.admin); const tt = await rr.text();
      check(rr.status === 200 && !/fatal|uncaught|warning:|SQLSTATE/i.test(tt), `ইনপুট "${q}" → ক্র্যাশ নেই`);
    }

    console.log('\n=== নতুন ক্রয় (আসল ফর্ম-পোস্ট) ===');
    h = await text('/admin/purchase_form.php', S.admin);
    check(h.includes('id="pfForm"') && h.includes('name="product_id[]"') && h.includes('name="qty[]"') && h.includes('name="unit_cost[]"') && h.includes(`>[T] PA<`), 'ক্রয়-ফর্মে সরবরাহকারী, পণ্যের সারি, পরিশোধ ঘর আছে');
    const buy = (extra = {}, lines = [[PA, '10', '200'], [PB, '4', '50'], [PC, '5', '60']], sid = S.admin) => post('/admin/purchase_form.php', [['csrf', CSRF], ...obj({ supplier_id: S1, invoice_no: 'INV-A', purchase_date: '2019-01-10', paid: '1000', method: 'ক্যাশ', note: '', ...extra }), ...lines.flatMap(([p, q, c]) => [['product_id[]', p], ['qty[]', q], ['unit_cost[]', c]])], sid);
    const cntP = () => Number(sql("SELECT COUNT(*) FROM purchases WHERE supplier_id IN (SELECT id FROM suppliers WHERE name LIKE '[T]%')"));
    let p0 = cntP();
    const stA = stockOf(PA);
    r = await buy();
    const loc = r.headers.get('location') || '';
    const P1 = (loc.match(/purchase_view\.php\?id=(\d+)/) || [])[1];
    check(r.status === 302 && !!P1 && cntP() === p0 + 1, 'ক্রয় সংরক্ষণ → বিস্তারিত পেজে redirect (#' + P1 + ')');
    check(stockOf(PA) === stA + 10 && costOf(PA) === '150.00', `PA: স্টক ${stA}→${stockOf(PA)}, গড় ক্রয়মূল্য (10×100+10×200)/20 = ${costOf(PA)}`);
    check(stockOf(PB) === 4 && costOf(PB) === '50.00', 'PB: স্টক ০→৪, ক্রয়মূল্য 50 (আগে স্টক ছিল না)');
    check(stockOf(PC) === 10 && costOf(PC) === '60.00', 'PC: স্টক ৫→১০, আগের ক্রয়মূল্য অজানা → 60');
    check(sql(`SELECT total FROM purchases WHERE id=${P1}`) === '2500.00' && sql(`SELECT COUNT(*) FROM purchase_items WHERE purchase_id=${P1}`) === '3', 'মোট 2500, ৩টি আইটেম');
    check(sql(`SELECT amount FROM purchase_payments WHERE purchase_id=${P1}`) === '1000.00' && sql(`SELECT created_by FROM purchases WHERE id=${P1}`) === 'tt', 'শুরুর পরিশোধ 1000 সংরক্ষিত, যোগকারীর নাম');
    h = await text(`/admin/purchase_view.php?id=${P1}`, S.admin);
    check(idv(h, 'vTotal') === money2(2500) && idv(h, 'vPaid') === money2(1000) && idv(h, 'vDue') === money2(1500) && /id="pvStatus">বাকি/.test(h), 'বিস্তারিত: মোট ৳2,500.00, পরিশোধ ৳1,000.00, বাকি ৳1,500.00, অবস্থা "বাকি"');
    check(h.includes('[T] PA') && h.includes('id="pvPayForm"') && h.includes('id="pvVoidInfo"'), 'আইটেম, পরিশোধ-ফর্ম আছে; পরিশোধ থাকায় বাতিল বন্ধ বার্তা');

    p0 = cntP(); const st1 = [stockOf(PA), stockOf(PB), stockOf(PC)].join();
    const rej = async (label, extra, lines) => {
      const rr = await buy(extra, lines);
      const tt = await rr.text();
      check(rr.status === 200 && tt.includes('id="pfErrors"') && cntP() === p0 && [stockOf(PA), stockOf(PB), stockOf(PC)].join() === st1, `অবৈধ ক্রয় (${label}) → ত্রুটি দেখায়, কিছু লেখা হয়নি (স্টক অক্ষত)`);
      return tt;
    };
    await rej('ইনভয়েস ডুপ্লিকেট', { invoice_no: 'INV-A', purchase_date: '2019-01-11' });
    await rej('সরবরাহকারী বাছা হয়নি', { supplier_id: '' });
    await rej('ভবিষ্যৎ তারিখ', { purchase_date: '2099-01-01', invoice_no: 'F1' });
    await rej('তারিখ ভুল', { purchase_date: '2019-02-30', invoice_no: 'F2' });
    await rej('পরিশোধ > মোট', { paid: '9999999', invoice_no: 'F3' });
    await rej('পরিশোধ ঋণাত্মক', { paid: '-1', invoice_no: 'F4' });
    await rej('পরিমাণ ০', { invoice_no: 'F5' }, [[PA, '0', '10']]);
    await rej('পরিমাণ দশমিক', { invoice_no: 'F6' }, [[PA, '1.5', '10']]);
    await rej('ক্রয়-দাম ঋণাত্মক', { invoice_no: 'F7' }, [[PA, '1', '-5']]);
    await rej('পণ্য বাছা হয়নি (পরিমাণ আছে)', { invoice_no: 'F8' }, [['', '3', '10']]);
    await rej('পণ্য অজানা', { invoice_no: 'F9' }, [['99999999', '3', '10']]);
    await rej('কোনো আইটেম নেই', { invoice_no: 'F10' }, [['', '', '']]);
    const tx = await rej('ত্রুটিতে ফর্মের মান ফেরত', { invoice_no: 'KEEP-ME', purchase_date: '2019-04-04', paid: '77', note: 'নোট রাখো' }, [[PA, '0', '12.5']]);
    check(tx.includes('value="KEEP-ME"') && tx.includes('value="2019-04-04"') && tx.includes('value="77"') && tx.includes('value="12.5"') && /<option value="\d+" selected>\[T\] PA/.test(tx), 'ত্রুটির পর ফর্মে ইনভয়েস, তারিখ, পরিশোধ, দাম ও বাছা পণ্য আগের মতো থাকে');
    r = await post('/admin/purchase_form.php', obj({ supplier_id: S1, invoice_no: 'NOCSRF', purchase_date: '2019-01-12', 'product_id[]': PA, 'qty[]': '1', 'unit_cost[]': '5' }), S.admin);
    check(cntP() === p0, 'CSRF ছাড়া ক্রয় হয় না');
    r = await post('/admin/purchase_form.php', [['csrf', CSRF], ['supplier_id[]', S1], ['product_id', PA], ['qty', '1'], ['unit_cost', '5'], ['purchase_date[]', '2019-01-01']], S.admin);
    check(r.status !== 500 && cntP() === p0, 'অ্যারে/ভুল-আকারের ইনপুটে ক্র্যাশ নয়, ক্রয় হয় না');
    r = await post('/admin/purchase_form.php', [['csrf', CSRF], ...obj({ supplier_id: S1, invoice_no: 'LONG', purchase_date: '2019-01-12' }), ...Array.from({ length: 101 }, () => [['product_id[]', PA], ['qty[]', '1'], ['unit_cost[]', '1']]).flat()], S.admin);
    check(cntP() === p0, '১০১টি আইটেম → বাতিল');
    r = await buy({ invoice_no: 'NOPERM' }, undefined, S.nopur);
    check(cntP() === p0, 'পারমিশন ছাড়া ক্রয় হয় না');

    console.log('\n=== পরিশোধ: কিস্তি ও পুরোটা ===');
    const pay = (pid, o, sid = S.admin) => post('/admin/purchase_view.php?id=' + pid, obj({ csrf: CSRF, action: 'pay', purchase_id: pid, amount: '100', paid_date: today, method: 'ব্যাংক', note: 'কিস্তি', ...o }), sid);
    const paidOf = (pid) => Number(sql(`SELECT COALESCE(SUM(amount),0) FROM purchase_payments WHERE purchase_id=${pid}`));
    r = await pay(P1, { amount: '400' });
    check(r.status === 302 && paidOf(P1) === 1400, 'কিস্তি ৪০০ → পরিশোধ 1400');
    h = await text(`/admin/purchase_view.php?id=${P1}`, S.admin);
    check(idv(h, 'vDue') === money2(1100) && h.includes('কিস্তি'), 'বাকি ৳1,100.00; ইতিহাসে নোট দেখায়');
    await pay(P1, { amount: '1100.01' });
    check(paidOf(P1) === 1400, 'বাকির বেশি (1100.01) → ব্যর্থ, পরিশোধ অপরিবর্তিত');
    for (const [l, o] of [['০', { amount: '0' }], ['ঋণাত্মক', { amount: '-5' }], ['অক্ষর', { amount: 'abc' }], ['ফাঁকা', { amount: '' }], ['ভবিষ্যৎ তারিখ', { paid_date: '2099-01-01' }], ['ভুল তারিখ', { paid_date: '2019-13-01' }]]) {
      await pay(P1, o);
      check(paidOf(P1) === 1400, `অবৈধ পরিশোধ (${l}) → লেখা হয়নি`);
    }
    await post('/admin/purchase_view.php?id=' + P1, [['csrf', CSRF], ['action', 'pay'], ['purchase_id', P1], ['amount[]', '5'], ['paid_date[]', today]], S.admin);
    check(paidOf(P1) === 1400, 'অ্যারে ইনপুটে ক্র্যাশ নয়, লেখা হয়নি');
    await post('/admin/purchase_view.php?id=' + P1, obj({ action: 'pay', purchase_id: P1, amount: '5', paid_date: today }), S.admin);
    check(paidOf(P1) === 1400, 'CSRF ছাড়া পরিশোধ হয় না');
    await pay(P1, { amount: '1100' });
    check(paidOf(P1) === 2500, 'পুরো বাকি 1100 একসাথে → পরিশোধ 2500');
    h = await text(`/admin/purchase_view.php?id=${P1}`, S.admin);
    check(idv(h, 'vDue') === money2(0) && /id="pvStatus">শোধ/.test(h) && !h.includes('id="pvPayForm"'), 'বাকি ০, অবস্থা "শোধ", পরিশোধ-ফর্ম নেই');
    await pay(P1, { amount: '1' });
    check(paidOf(P1) === 2500, 'পুরো-শোধ ক্রয়ে আরও পরিশোধ হয় না');
    await pay(P1, { amount: '10' }, S.nopur);
    check(paidOf(P1) === 2500, 'পারমিশন ছাড়া পরিশোধ হয় না');

    console.log('\n=== সরবরাহকারীকে একসাথে পরিশোধ ===');
    await buy({ supplier_id: S2, invoice_no: 'L1', purchase_date: '2019-01-01', paid: '40', method: '' }, [[PA, '1', '100']]);
    await buy({ supplier_id: S2, invoice_no: 'L2', purchase_date: '2019-02-01', paid: '', method: '' }, [[PA, '1', '200']]);
    await buy({ supplier_id: S2, invoice_no: 'L3', purchase_date: '2019-03-01', paid: '', method: '' }, [[PA, '1', '300']]);
    const L = ['L1', 'L2', 'L3'].map((n) => sql(`SELECT id FROM purchases WHERE supplier_id=${S2} AND invoice_no='${n}'`));
    const dueOf = (pid) => Number(sql(`SELECT total - COALESCE((SELECT SUM(amount) FROM purchase_payments WHERE purchase_id=${pid}),0) FROM purchases WHERE id=${pid}`));
    h = await text(`/admin/supplier_view.php?id=${S2}`, S.admin);
    check(idv(h, 'sTotal') === money2(600) && idv(h, 'sPaid') === money2(40) && idv(h, 'sDue') === money2(560) && h.includes('id="svPayCard"'), 'সরবরাহকারীর পেজ: মোট ৳600.00, পরিশোধ ৳40.00, বাকি ৳560.00');
    const payAll = (o, sid = S.admin) => post('/admin/supplier_view.php?id=' + S2, obj({ csrf: CSRF, action: 'pay_all', supplier_id: S2, amount: '250', paid_date: today, method: 'ব্যাংক', note: 'চেক ১২৩', ...o }), sid);
    await payAll({ amount: '560.01' });
    check(dueOf(L[0]) === 60 && dueOf(L[1]) === 200 && dueOf(L[2]) === 300, 'মোট বাকির বেশি (560.01) → কিছুই লেখা হয়নি');
    for (const [l, o] of [['০', { amount: '0' }], ['ঋণাত্মক', { amount: '-1' }], ['অক্ষর', { amount: 'x' }], ['ভবিষ্যৎ তারিখ', { paid_date: '2099-01-01' }]]) {
      await payAll(o);
      check(dueOf(L[0]) === 60 && dueOf(L[1]) === 200 && dueOf(L[2]) === 300, `অবৈধ একসাথে-পরিশোধ (${l}) → লেখা হয়নি`);
    }
    await payAll({}, S.nopur);
    check(dueOf(L[0]) === 60, 'পারমিশন ছাড়া একসাথে-পরিশোধ হয় না');
    r = await payAll({});
    check(r.status === 302 && dueOf(L[0]) === 0 && dueOf(L[1]) === 10 && dueOf(L[2]) === 300, 'একসাথে ২৫০: পুরোনো থেকে L1 60, L2 190 → বাকি L1=0, L2=10, L3=300');
    h = await text(`/admin/supplier_view.php?id=${S2}`, S.admin);
    check(idv(h, 'sDue') === money2(310), 'সরবরাহকারীর বাকি ৳310.00');
    check(sql(`SELECT COUNT(*) FROM purchase_payments WHERE purchase_id IN (${L.join(',')}) AND note LIKE '%একসাথে পরিশোধ%' AND note LIKE '%চেক ১২৩%'`) === '2', 'বণ্টিত দুই পরিশোধ-সারিতে নোট "চেক ১২৩ — একসাথে পরিশোধ"');
    check(Number(sql(`SELECT COALESCE(SUM(total - COALESCE((SELECT SUM(amount) FROM purchase_payments x WHERE x.purchase_id=p.id),0)),0) FROM purchases p WHERE supplier_id=${S1} AND status='active'`)) === 0, 'অন্য সরবরাহকারীর হিসাব অপ্রভাবিত');
    await payAll({ amount: '310', note: '' });
    check(dueOf(L[1]) === 0 && dueOf(L[2]) === 0, 'বাকি ৩১০ একসাথে → সব ক্রয় শোধ');
    h = await text(`/admin/supplier_view.php?id=${S2}`, S.admin);
    check(!h.includes('id="svPayCard"') && idv(h, 'sDue') === money2(0), 'সব শোধ হলে একসাথে-পরিশোধ কার্ড নেই');

    console.log('\n=== বাতিল ও পরিশোধ-সারি মোছা ===');
    const st0 = stockOf(PA);
    await buy({ invoice_no: 'V1', purchase_date: '2019-06-01', paid: '', method: '' }, [[PA, '3', '10']]);
    const V = sql(`SELECT id FROM purchases WHERE supplier_id=${S1} AND invoice_no='V1'`);
    check(stockOf(PA) === st0 + 3, 'ক্রয়ে স্টক +৩');
    await pay(V, { amount: '10' });
    const voidPost = (pid, sid = S.admin) => post('/admin/purchase_view.php?id=' + pid, obj({ csrf: CSRF, action: 'void', purchase_id: pid }), sid);
    await voidPost(V);
    check(sql(`SELECT status FROM purchases WHERE id=${V}`) === 'active' && stockOf(PA) === st0 + 3, 'পরিশোধ থাকলে বাতিল হয় না, স্টক অক্ষত');
    const payRow = sql(`SELECT id FROM purchase_payments WHERE purchase_id=${V}`);
    r = await get(`/admin/purchase_view.php?id=${V}&action=delete_payment&payment_id=${payRow}`, S.admin);
    check(sql(`SELECT COUNT(*) FROM purchase_payments WHERE id=${payRow}`) === '1', 'GET দিয়ে পরিশোধ মোছা যায় না');
    await post('/admin/purchase_view.php?id=' + P1, obj({ csrf: CSRF, action: 'delete_payment', purchase_id: P1, payment_id: payRow }), S.admin);
    check(sql(`SELECT COUNT(*) FROM purchase_payments WHERE id=${payRow}`) === '1', 'অন্য ক্রয়ের পরিশোধ-আইডি দিয়ে মোছা যায় না');
    await post('/admin/purchase_view.php?id=' + V, obj({ csrf: CSRF, action: 'delete_payment', purchase_id: V, payment_id: payRow }), S.nopur);
    check(sql(`SELECT COUNT(*) FROM purchase_payments WHERE id=${payRow}`) === '1', 'পারমিশন ছাড়া পরিশোধ মোছা যায় না');
    await post('/admin/purchase_view.php?id=' + V, obj({ csrf: CSRF, action: 'delete_payment', purchase_id: V, payment_id: payRow }), S.admin);
    check(sql(`SELECT COUNT(*) FROM purchase_payments WHERE id=${payRow}`) === '0', 'সঠিক POST-এ পরিশোধ মোছে (বাকি আবার বাড়ে)');
    await voidPost(V, S.nopur);
    check(sql(`SELECT status FROM purchases WHERE id=${V}`) === 'active', 'পারমিশন ছাড়া বাতিল হয় না');
    await post('/admin/purchase_view.php?id=' + V, obj({ action: 'void', purchase_id: V }), S.admin);
    check(sql(`SELECT status FROM purchases WHERE id=${V}`) === 'active', 'CSRF ছাড়া বাতিল হয় না');
    const costBefore = costOf(PA);
    await voidPost(V);
    check(sql(`SELECT status FROM purchases WHERE id=${V}`) === 'cancelled' && stockOf(PA) === st0 && costOf(PA) === costBefore, 'বাতিল সফল: স্টক ফেরে (' + st0 + '), গড় ক্রয়মূল্য অপরিবর্তিত (' + costBefore + ')');
    await voidPost(V);
    check(stockOf(PA) === st0, 'আবার বাতিল → স্টক দ্বিতীয়বার কমে না');
    await pay(V, { amount: '5' });
    check(paidOf(V) === 0, 'বাতিল ক্রয়ে পরিশোধ হয় না');
    h = await text(`/admin/purchase_view.php?id=${V}`, S.admin);
    check(/id="pvStatus">বাতিল/.test(h) && !h.includes('id="pvPayForm"') && !h.includes('id="pvVoidBtn"'), 'বাতিল ক্রয়ের পেজ: অবস্থা "বাতিল", পরিশোধ/বাতিল বাটন নেই');
    r = await get('/admin/purchase_view.php?id=999999999', S.admin);
    h = await r.text();
    check(r.status === 200 && h.includes('id="pvMissing"'), 'অজানা ক্রয় → সুন্দর বার্তা');
    for (const q of ['id[]=1', 'id=abc', 'id=-1', "id=1'%20OR%201=1"]) {
      const rr = await get('/admin/purchase_view.php?' + q, S.admin); const tt = await rr.text();
      check(rr.status === 200 && !/fatal|uncaught|warning:|SQLSTATE/i.test(tt), `purchase_view ইনপুট "${q}" → ক্র্যাশ নেই`);
    }
    h = await (await get('/admin/supplier_view.php?id=999999999', S.admin)).text();
    check(h.includes('id="svMissing"'), 'অজানা সরবরাহকারী → সুন্দর বার্তা');
    r = await post('/admin/suppliers.php', obj({ csrf: CSRF, action: 'delete', id: S1 }), S.admin);
    check(sql(`SELECT COUNT(*) FROM suppliers WHERE id=${S1}`) === '1', 'ক্রয়-ইতিহাসওয়ালা সরবরাহকারী মোছা যায় না');
    h = await text('/admin/suppliers.php', S.admin);
    check(!new RegExp(`data-id="${S1}"[^>]*>[\\s\\S]*?btn-del`).test((h.match(new RegExp(`<tr data-id="${S1}">[\\s\\S]*?</tr>`)) || [''])[0]), 'ক্রয়ওয়ালা সরবরাহকারীর সারিতে Delete বাটন নেই');

    console.log('\n=== তালিকা, ফিল্টার, KPI (২০১৯) ===');
    for (let i = 0; i < 28; i++) sql(`INSERT INTO purchases (supplier_id, invoice_no, purchase_date, total, status) VALUES (${S1}, 'BULK${i}', '2018-06-${String(1 + (i % 28)).padStart(2, '0')}', ${10 + i}, 'active')`);
    h = await text('/admin/purchases.php?type=yearly&year=2018', S.admin);
    const nrows = (hh) => (hh.match(/<tr data-id=/g) || []).length;
    check(nrows(h) === 25 && /page=2/.test(h) && idv(h, 'kTotal') === money0(Array.from({ length: 28 }, (_, i) => 10 + i).reduce((a, b) => a + b, 0)), '২০১৮: প্রথম পৃষ্ঠায় ২৫টি, মোট ক্রয় সঠিক');
    check(nrows(await text('/admin/purchases.php?type=yearly&year=2018&page=2', S.admin)) === 3 && nrows(await text('/admin/purchases.php?type=yearly&year=2018&page=99', S.admin)) === 3, 'পৃষ্ঠা ২-এ ৩টি; পৃষ্ঠা ৯৯ → শেষ পৃষ্ঠা');
    h = await text('/admin/purchases.php?type=monthly&year=2019&month=1', S.admin);
    const janTotal = Number(sql("SELECT COALESCE(SUM(total),0) FROM purchases p JOIN suppliers s ON s.id=p.supplier_id WHERE p.purchase_date BETWEEN '2019-01-01' AND '2019-01-31' AND p.status='active' AND s.name LIKE '[T]%'"));
    check(nrows(h) === 2 && idv(h, 'kTotal') === money0(janTotal), 'জানুয়ারি ২০১৯: ২টি ক্রয় (P1, L1), মোট ডাটাবেসের সাথে মেলে (' + idv(h, 'kTotal') + ')');
    h = await text(`/admin/purchases.php?type=all&supplier=${S2}`, S.admin);
    check(nrows(h) === 3 && idv(h, 'kTotal') === money0(600) && idv(h, 'kPaid') === money0(600) && idv(h, 'kDue') === money0(0), 'সরবরাহকারী ফিল্টার (সব সময়): ৩টি, মোট ৳600, পরিশোধ ৳600, বাকি ৳0');
    h = await text(`/admin/purchases.php?type=all&supplier=${S1}&status=cancelled`, S.admin);
    check(nrows(h) === 1 && h.includes('বাতিল'), 'status=cancelled: শুধু বাতিলটি');
    h = await text(`/admin/purchases.php?type=all&supplier=${S1}&status=due`, S.admin);
    const dueSql = Number(sql(`SELECT COUNT(*) FROM purchases p WHERE supplier_id=${S1} AND status='active' AND total > COALESCE((SELECT SUM(amount) FROM purchase_payments x WHERE x.purchase_id=p.id),0)`));
    check(dueSql >= 28 && (idv(h, 'puCount') || '').startsWith(dueSql + 'টি') && !h.includes('badge bg-success">শোধ'), 'status=due: বাকি থাকা সক্রিয় ক্রয়গুলোই (' + dueSql + 'টি), কোনো "শোধ" নেই');
    h = await text(`/admin/purchases.php?type=all&supplier=${S1}&status=paid`, S.admin);
    check(nrows(h) === 1 && h.includes('INV-A'), 'status=paid: পুরো শোধ ১টি (INV-A)');
    h = await text('/admin/purchases.php?type=all&q=INV-A', S.admin);
    check(nrows(h) === 1, 'ইনভয়েস দিয়ে খোঁজা');
    const globalDue = Number(sql("SELECT COALESCE(SUM(total),0) - COALESCE((SELECT SUM(x.amount) FROM purchase_payments x JOIN purchases q ON q.id=x.purchase_id WHERE q.status='active'),0) FROM purchases WHERE status='active'"));
    h = await text('/admin/purchases.php', S.admin);
    check(idv(h, 'kGlobalDue') === money0(globalDue), 'সব মিলিয়ে মোট বাকি (দায়) ডাটাবেসের সাথে মেলে: ' + idv(h, 'kGlobalDue'));
    for (const q of ['type[]=x', 'supplier[]=1', 'status[]=due', 'q[]=a', 'page[]=2', 'year[]=2019', 'type=custom&from=bad&to=bad', "q=x'%20OR%201=1"]) {
      const rr = await get('/admin/purchases.php?' + q, S.admin); const tt = await rr.text();
      check(rr.status === 200 && !/fatal|uncaught|warning:|SQLSTATE/i.test(tt), `purchases ইনপুট "${q}" → ক্র্যাশ নেই`);
    }
    h = await text('/admin/stock_report.php?low_only=0&q=' + encodeURIComponent('[T] PA'), S.admin);
    check(h.includes(money0(Number(costOf(PA)))), 'স্টক রিপোর্টে পণ্যের গড় ক্রয়মূল্য (' + money0(Number(costOf(PA))) + ') দেখায়');

    console.log('\n=== ব্রাউজার: ক্রয়-ফর্মের JS ও লেআউট ===');
    chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, '--no-sandbox', '--disable-gpu', '--hide-scrollbars', '--user-data-dir=' + os.tmpdir() + '\\pu_prof_' + Date.now(), 'about:blank'], { stdio: 'ignore' });
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
    const go = async (p, w, wait = 1800) => { await call('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 600 }); await call('Page.navigate', { url: BASE + p }); await sleep(wait); };
    const fire = (sel, val) => `(()=>{const e=document.querySelector('${sel}');e.value='${val}';e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}));})()`;
    for (const w of [1326, 390]) {
      await go('/admin/purchase_form.php?supplier=' + S1, w);
      const m = await ev("(()=>({hs:document.documentElement.scrollWidth>innerWidth+1,sup:document.getElementById('pfSupplier').value,rows:document.querySelectorAll('.pf-line').length,save:!!document.getElementById('pfSave')}))()");
      check(!m.hs && m.sup === S1 && m.rows === 1 && m.save, `ক্রয়-ফর্ম ${w}px: overflow নেই, ?supplier= দিয়ে সরবরাহকারী আগে-বাছা, ১টি সারি`);
      const shot = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
      fs.writeFileSync(path.join(os.tmpdir(), `purchase_form_${w}.png`), Buffer.from(shot.data, 'base64'));
    }
    await go('/admin/purchase_form.php?supplier=' + S1, 1326);
    await ev(fire('.pf-prod', PA));
    const hint = await ev("document.querySelector('.pf-info').textContent");
    const costPre = await ev("document.querySelector('.pf-cost').value");
    check(/স্টক \d+/.test(hint) && /ক্রয়মূল্য/.test(hint) && Number(costPre) === Number(costOf(PA)), 'পণ্য বাছলে স্টক/গড় ক্রয়মূল্য দেখায় ও ক্রয়-দাম আগে-ভরা: ' + hint);
    await ev(fire('.pf-qty', '4')); await ev(fire('.pf-cost', '25'));
    check((await ev("document.querySelector('.pf-line-total').textContent")) === '৳100' && (await ev("document.getElementById('pfTotal').textContent")) === '৳100', 'লাইন মোট ও মোট রিয়েল-টাইম (4 × 25 = ৳100)');
    await ev("document.getElementById('pfAdd').click()");
    check((await ev("document.querySelectorAll('.pf-line').length")) === 2 && (await ev("document.querySelectorAll('.pf-line')[1].querySelector('.pf-qty').value")) === '', '"আরেকটি পণ্য" নতুন ফাঁকা সারি যোগ করে');
    await ev("(()=>{const l=document.querySelectorAll('.pf-line')[1];const e=l.querySelector('.pf-prod');e.value='" + PB + "';e.dispatchEvent(new Event('change',{bubbles:true}));const q=l.querySelector('.pf-qty');q.value='2';q.dispatchEvent(new Event('input',{bubbles:true}));const c=l.querySelector('.pf-cost');c.value='50.5';c.dispatchEvent(new Event('input',{bubbles:true}));})()");
    check((await ev("document.getElementById('pfTotal').textContent")) === '৳201', 'দুই সারির মোট ৳100 + ৳101 = ৳201');
    await ev("document.getElementById('pfPayAll').click()");
    check((await ev("document.getElementById('pfPaid').value")) === '201.00' && (await ev("document.getElementById('pfDue').textContent")) === '৳0', '"পুরোটা এখনই পরিশোধ" → পরিশোধ 201.00, বাকি ৳0');
    await ev("document.getElementById('pfPayNone').click()");
    check((await ev("document.getElementById('pfPaid').value")) === '' && (await ev("document.getElementById('pfDue').textContent")) === '৳201', '"সবটা বাকি" → পরিশোধ ফাঁকা, বাকি ৳201');
    await ev(fire('#pfPaid', '50'));
    check((await ev("document.getElementById('pfDue').textContent")) === '৳151', 'পরিশোধ ৫০ → বাকি ৳151');
    await ev("document.querySelectorAll('.pf-del')[1].click()");
    check((await ev("document.querySelectorAll('.pf-line').length")) === 1 && (await ev("document.getElementById('pfTotal').textContent")) === '৳100', 'সারি সরালে মোট আবার ৳100');
    await ev("document.querySelectorAll('.pf-del')[0].click()");
    check((await ev("document.querySelectorAll('.pf-line').length")) === 1 && (await ev("document.querySelector('.pf-qty').value")) === '', 'শেষ সারি সরালে ফাঁকা হয় (সারি থাকে)');
    await ev(fire('.pf-prod', PA)); await ev(fire('.pf-qty', '2')); await ev(fire('.pf-cost', '40'));
    await ev(fire('#pfPaid', '30'));
    await ev("document.querySelector('input[name=invoice_no]').value='UI-1'");
    const stUi = stockOf(PA);
    await ev("document.getElementById('pfSave').click()"); await sleep(2500);
    const urlNow = await ev('location.pathname + location.search');
    check(/purchase_view\.php\?id=\d+/.test(urlNow), 'UI থেকে Save → বিস্তারিত পেজ: ' + urlNow);
    check(stockOf(PA) === stUi + 2 && sql("SELECT total FROM purchases WHERE invoice_no='UI-1'") === '80.00' && paidOf(sql("SELECT id FROM purchases WHERE invoice_no='UI-1'")) === 30, 'UI ক্রয়: স্টক +২, মোট 80, পরিশোধ 30');
    const UI = sql("SELECT id FROM purchases WHERE invoice_no='UI-1'");
    for (const w of [1326, 390]) {
      await go(`/admin/purchase_view.php?id=${UI}`, w);
      const m = await ev("(()=>({hs:document.documentElement.scrollWidth>innerWidth+1,k:document.querySelectorAll('#pvKpis .kpi-card').length}))()");
      check(!m.hs && m.k === 3, `ক্রয়-বিস্তারিত ${w}px: overflow নেই, ৩টি কার্ড`);
      const shot = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
      fs.writeFileSync(path.join(os.tmpdir(), `purchase_view_${w}.png`), Buffer.from(shot.data, 'base64'));
      await go('/admin/purchases.php?type=all', w);
      check(!(await ev('document.documentElement.scrollWidth>innerWidth+1')), `ক্রয়-তালিকা ${w}px: overflow নেই`);
      await go('/admin/suppliers.php', w);
      check(!(await ev('document.documentElement.scrollWidth>innerWidth+1')), `সরবরাহকারী ${w}px: overflow নেই`);
      const shot2 = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
      fs.writeFileSync(path.join(os.tmpdir(), `suppliers_${w}.png`), Buffer.from(shot2.data, 'base64'));
    }
    await go(`/admin/purchase_view.php?id=${UI}`, 1326);
    await ev("document.getElementById('pvFull').click()");
    check((await ev("document.getElementById('pvAmount').value")) === '50.00', '"পুরো বাকি" বাটনে পরিমাণ 50.00 বসে');
    await ev("document.querySelector('.btn-delpay').click()"); await sleep(700);
    const md = await ev("(()=>({shown:document.getElementById('delPayModal').classList.contains('show'),amt:document.getElementById('delPayAmt').textContent}))()");
    check(md.shown && md.amt === '৳30.00', 'পরিশোধ "মুছুন" চাপলে নিশ্চিতকরণ মোডাল (' + md.amt + ')');
    check(paidOf(UI) === 30, 'মোডাল খোলায় কিছু মোছা হয়নি');
    await ev("document.getElementById('delPayConfirm').click()"); await sleep(1800);
    check(paidOf(UI) === 0, 'নিশ্চিত করলে পরিশোধ মোছে');
    await go(`/admin/purchase_view.php?id=${UI}`, 1326);
    await ev("document.getElementById('pvVoidBtn').click()"); await sleep(700);
    check(await ev("document.getElementById('voidModal').classList.contains('show')"), '"ক্রয় বাতিল করুন" চাপলে মোডাল');
    await ev("document.getElementById('voidConfirm').click()"); await sleep(1800);
    check(sql(`SELECT status FROM purchases WHERE id=${UI}`) === 'cancelled' && stockOf(PA) === stUi, 'নিশ্চিত করলে বাতিল, স্টক ফেরে');
    ws.close();
  } catch (e) { console.error('ERROR: ' + e.message); fail++; }
  finally { try { chrome && chrome.kill(); } catch {} cleanup(); }
  const after = { sup: sql('SELECT COUNT(*) FROM suppliers'), pur: sql('SELECT COUNT(*) FROM purchases'), items: sql('SELECT COUNT(*) FROM purchase_items'), pay: sql('SELECT COUNT(*) FROM purchase_payments'), prods: sql('SELECT COUNT(*) FROM products'), realStock: sql('SELECT COALESCE(SUM(stock),0) FROM products') };
  check(JSON.stringify(after) === JSON.stringify(before), 'টেস্ট শেষে সরবরাহকারী/ক্রয়/আইটেম/পরিশোধ/পণ্য/মোট-স্টক আগের মতো');
  console.log((fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exitCode = fail === 0 ? 0 : 1;
})();
