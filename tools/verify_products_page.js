/**
 * tools/verify_products_page.js — admin/products.php: পেজিনেশন (প্রতি পৃষ্ঠায় 10/20/50/100), হোম-সেকশন ব্যাজ, CSV, ছবি প্রিভিউ, কপি (নতুন ট্যাবে ফর্ম)
 * node tools/verify_products_page.js [url]
 * "[T]" পণ্য, ছবির ফাইল ও অস্থায়ী অ্যাডমিন সেশন নিজে বানায়-মোছে। আসল পণ্য/সেকশন বদলায় না।
 */
const { spawn, execFileSync } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');
const BASE = process.argv[2] || 'http://estore.test';
const PORT = Number(process.env.PORT_PP || 9498);
const CHROME = process.env.NAV_BROWSER || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const MYSQL = process.env.MYSQL_BIN || 'mysql';
const ROOT = path.join(__dirname, '..');
const PDIR = path.join(ROOT, 'uploads', 'products_image');
const SESS_DIR = 'C:/laragon/tmp';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
let pass = 0, fail = 0;
const ok = (m) => { pass++; console.log('  PASS  ' + m); };
const bad = (m) => { fail++; console.log('  FAIL  ' + m); };
const check = (c, m) => (c ? ok(m) : bad(m));
const sql = (q) => execFileSync(MYSQL, ['-uroot', '-N', '--default-character-set=utf8mb4', 'estore'], { encoding: 'utf8', input: q + ';\n' }).replace(/\r/g, '').trim();
const SID = require('crypto').randomBytes(13).toString('hex');
const CSID = require('crypto').randomBytes(13).toString('hex');
const CK = { Cookie: 'PHPSESSID=' + SID };
const CSRF = 'a'.repeat(32);
const rowsIn = (h) => (h.match(/<tr id="row-\d+"/g) || []).length;
const get = (p, h = CK) => fetch(BASE + p, { redirect: 'manual', headers: h });
const post = (p, body, h = CK) => fetch(BASE + p, { method: 'POST', redirect: 'manual', headers: { ...h, 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(body).toString() });

function cleanup() {
  try {
    const ids = sql("SELECT id FROM products WHERE name LIKE '[T]%' OR name LIKE '=[T]%'").split('\n').filter(Boolean);
    if (ids.length) {
      sql(`DELETE FROM home_section_products WHERE product_id IN (${ids.join(',')})`);
      sql(`DELETE FROM products WHERE id IN (${ids.join(',')})`);
      for (const f of fs.readdirSync(PDIR)) if (ids.some((i) => f.startsWith('product_' + i + '_')) || f.startsWith('test_t_')) { try { fs.unlinkSync(path.join(PDIR, f)); } catch {} }
    }
    for (const f of fs.readdirSync(PDIR)) if (f.startsWith('test_t_')) { try { fs.unlinkSync(path.join(PDIR, f)); } catch {} }
  } catch (e) { console.log('cleanup: ' + e.message); }
  for (const s of [SID, CSID]) try { fs.unlinkSync(`${SESS_DIR}/sess_${s}`); } catch {}
}

function send(ws, id, method, params = {}) {
  return new Promise((resolve, reject) => {
    const to = setTimeout(() => reject(new Error('timeout: ' + method)), 30000);
    const onMsg = (ev) => { let m; try { m = JSON.parse(ev.data); } catch { return; } if (m.id === id) { clearTimeout(to); ws.removeEventListener('message', onMsg); m.error ? reject(new Error(JSON.stringify(m.error))) : resolve(m.result); } };
    ws.addEventListener('message', onMsg);
    ws.send(JSON.stringify({ id, method, params }));
  });
}

(async () => {
  cleanup();
  let chrome;
  const before = { products: sql('SELECT COUNT(*) FROM products'), hsp: sql('SELECT COUNT(*) FROM home_section_products') };
  try {
    const sess = (sid, role) => fs.writeFileSync(`${SESS_DIR}/sess_${sid}`, `admin_id|i:1;admin_username|s:4:"test";admin_role_name|s:${role.length}:"${role}";admin_permissions|a:1:{i:0;s:15:"manage_products";}csrf|s:32:"${CSRF}";__sid_rotated|i:${Math.floor(Date.now() / 1000)};`);
    sess(SID, 'Super Admin');
    const cat = sql('SELECT id FROM categories ORDER BY id LIMIT 1');
    const sec = sql('SELECT id FROM home_sections ORDER BY id LIMIT 1');
    const secTitle = sql(`SELECT title FROM home_sections WHERE id=${sec}`);

    const srcImg = path.join(PDIR, fs.readdirSync(PDIR).find((f) => /\.jpg$/i.test(f)));
    fs.copyFileSync(srcImg, path.join(PDIR, 'test_t_a.jpg')); fs.copyFileSync(srcImg, path.join(PDIR, 'test_t_b.jpg'));
    for (let i = 1; i <= 55; i++) sql(`INSERT INTO products (category_id, name, price, stock, active, sku) VALUES (${cat}, '[T] P${String(i).padStart(2, '0')}', ${100 + i}, ${i}, ${i % 2}, ${i === 1 ? "'T-SKU-1'" : 'NULL'})`);
    sql(`INSERT INTO products (category_id, name, price, stock, active, description, tags) VALUES (${cat}, '=[T] HYPERLINK("x")', 5, 3, 1, 'desc', 'a,b')`);
    const SRC = sql("SELECT id FROM products WHERE name='[T] P01'");
    const srcFile = path.join(PDIR, 'test_t_a.jpg');
    sql(`INSERT INTO product_images (product_id, image, sort_order) VALUES (${SRC}, '/uploads/products_image/test_t_a.jpg', 0), (${SRC}, '/uploads/products_image/test_t_b.jpg', 1)`);
    sql(`UPDATE products SET image='/uploads/products_image/test_t_a.jpg', sold_count=9, is_featured=1 WHERE id=${SRC}`);
    sql(`INSERT INTO home_section_products (section_id, product_id, sort_order) VALUES (${sec}, ${SRC}, 0)`);

    console.log('\n=== পেজিনেশন ===');
    const q = encodeURIComponent('[T]');
    let h = await (await get(`/admin/products.php?q=${q}`)).text();
    check(rowsIn(h) === 20, 'ডিফল্টে প্রতি পৃষ্ঠায় ২০টি (' + rowsIn(h) + ')');
    check(h.includes('id="pgInfo"') && /মোট 56টি/.test(h.normalize('NFC')) && /1–20/.test(h), 'তথ্য-লাইন: মোট 56টি, 1–20 দেখানো হচ্ছে');
    for (const [per, want] of [[10, 10], [50, 50], [100, 56]]) {
      h = await (await get(`/admin/products.php?q=${q}&per=${per}`)).text();
      check(rowsIn(h) === want, `per=${per} → ${want}টি সারি (${rowsIn(h)})`);
    }
    h = await (await get(`/admin/products.php?q=${q}&per=7`)).text();
    check(rowsIn(h) === 20, 'অবৈধ per=7 → ডিফল্ট ২০');
    h = await (await get(`/admin/products.php?q=${q}&per=50&page=2`)).text();
    check(rowsIn(h) === 6 && /51–56/.test(h), 'per=50, পৃষ্ঠা ২ → ৬টি (51–56)');
    h = await (await get(`/admin/products.php?q=${q}&per=50&page=999`)).text();
    check(rowsIn(h) === 6, 'পৃষ্ঠা ৯৯৯ → শেষ পৃষ্ঠায় নামে, ফাঁকা নয়');
    h = await (await get(`/admin/products.php?q=${q}&page=-3`)).text();
    check(rowsIn(h) === 20, 'পৃষ্ঠা -৩ → প্রথম পৃষ্ঠা');
    h = await (await get(`/admin/products.php?q=${q}&per=10`)).text();
    check(/page-link' href='products\.php\?[^']*per=10[^']*&page=2/.test(h) || /per=10[^']*page=2/.test(h), 'পৃষ্ঠা-লিংকে per ও ফিল্টার থেকে যায়');
    for (const bad of ['q[]=1', 'cat[]=1', 'status[]=1', 'per[]=1', 'page[]=1', 'export[]=csv']) {
      const r = await get(`/admin/products.php?${bad}`);
      const t = await r.text();
      check(r.status === 200 && !/fatal|uncaught|warning:/i.test(t), `অ্যারে ইনপুট ${bad} → ক্র্যাশ নেই`);
    }
    h = await (await get(`/admin/products.php?q=${q}&status=inactive&per=100`)).text();
    check(rowsIn(h) === 27, 'status=inactive ফিল্টার সঠিক (' + rowsIn(h) + ')');

    console.log('\n=== হোম সেকশন ব্যাজ ===');
    h = (await (await get(`/admin/products.php?q=${encodeURIComponent('[T] P01')}`)).text()).normalize('NFC');
    check(h.includes('sec-tag') && h.includes(secTitle.normalize('NFC')), `P01 এর নামের নিচে সেকশন ব্যাজ "${secTitle}"`);
    h = await (await get(`/admin/products.php?q=${encodeURIComponent('[T] P02')}`)).text();
    check(!h.includes('sec-tag"'), 'সেকশনে না থাকা পণ্যে ব্যাজ নেই');
    const secs = sql('SELECT id FROM home_sections ORDER BY sort_order, id').split(String.fromCharCode(10)).filter(Boolean);
    const other = secs.find((x) => x !== sec);
    if (other) {
      sql(`INSERT INTO home_section_products (section_id, product_id, sort_order) VALUES (${other}, ${SRC}, 0)`);
      h = await (await get(`/admin/products.php?q=${encodeURIComponent('[T] P01')}`)).text();
      const cols = [...h.matchAll(/class="badge sec-tag" style="background:(#[0-9a-f]{6});color:(#[0-9a-f]{6})"/g)].map((m) => m[1] + '/' + m[2]);
      check(cols.length === 2 && cols[0] !== cols[1], 'দুই সেকশনের ব্যাজ দুই আলাদা রঙে: ' + cols.join(' , '));
      h = await (await get(`/admin/products.php?q=${encodeURIComponent('[T] P01')}`)).text();
      const again = [...h.matchAll(/style="background:(#[0-9a-f]{6});color:(#[0-9a-f]{6})"/g)].map((m) => m[1] + '/' + m[2]);
      check(JSON.stringify(again) === JSON.stringify(cols), 'প্রতিবার একই সেকশন একই রঙ');
    }
    check(/class="badge sec-tag" style="background:#[0-9a-f]{6};color:#[0-9a-f]{6}"/.test(h), 'ব্যাজে রঙ (পটভূমি ও লেখা) বসেছে');

    console.log('\n=== CSV ===');
    let r = await get(`/admin/products.php?q=${q}&export=csv`);
    const csv = Buffer.from(await r.arrayBuffer());
    check(r.status === 200 && /text\/csv/.test(r.headers.get('content-type') || '') && /attachment; filename="products_\d{8}_\d{6}\.csv"/.test(r.headers.get('content-disposition') || ''), 'CSV হেডার ঠিক');
    check(csv[0] === 0xef && csv[1] === 0xbb && csv[2] === 0xbf, 'UTF-8 BOM আছে (Excel-এ বাংলা ঠিক দেখাবে)');
    const lines = csv.toString('utf8').replace(/^\uFEFF/, '').split(/\r?\n/).filter(Boolean);
    check(lines.length === 1 + 56, 'ফিল্টারের সব ৫৬টি পণ্য (পৃষ্ঠা ছাড়িয়ে): ' + (lines.length - 1));
    check(lines[0].startsWith('ID,') && lines[0].includes('SKU'), 'শিরোনাম সারি');
    check(lines.some((l) => l.includes("'=[T] HYPERLINK")) && !lines.some((l) => /(^|,)"?=\[T\]/.test(l)), 'ফর্মুলা-ইনজেকশন আটকানো (= দিয়ে শুরু নাম আগে \' পেল)');
    r = await get(`/admin/products.php?q=${q}&status=active&export=csv`);
    const l2 = (await r.text()).split(/\r?\n/).filter(Boolean);
    check(l2.length === 1 + 29 && l2.slice(1).every((l) => /,Active,/.test(l)), 'ফিল্টার মানে: শুধু Active (' + (l2.length - 1) + 'টি)');
    r = await get(`/admin/products.php?q=${q}&export=csv`, {});
    check(r.status === 302, 'লগইন ছাড়া CSV বন্ধ');
    sess(CSID, 'Manager'); fs.writeFileSync(`${SESS_DIR}/sess_${CSID}`, `admin_id|i:2;admin_username|s:1:"m";admin_role_name|s:7:"Manager";admin_permissions|a:1:{i:0;s:14:"view_dashboard";}csrf|s:32:"${CSRF}";__sid_rotated|i:${Math.floor(Date.now() / 1000)};`);
    r = await get(`/admin/products.php?export=csv`, { Cookie: 'PHPSESSID=' + CSID });
    check(!(await r.text()).includes('ID,'), 'manage_products পারমিশন ছাড়া CSV পায় না');

    console.log('\n=== কপি (নতুন ট্যাবে ফর্ম; Save করলে তবেই তৈরি) ===');
    const cnt0 = sql('SELECT COUNT(*) FROM products');
    const formOf = async (qs) => { const r = await get('/admin/product_form.php' + qs); return { status: r.status, html: (await r.text()).normalize('NFC') }; };
    let F = await formOf(`?copy=${SRC}`);
    check(F.status === 200 && F.html.includes('id="copyBanner"') && F.html.includes('পণ্য কপি'), 'copy=ID → কপি-ফর্ম খোলে, ব্যানার আছে');
    check(F.html.includes('value="[T] P01 (কপি)"'.normalize('NFC')), 'নামে " (কপি)" যোগ হয়েছে');
    check(/name="price"[^>]*value="101\.00"/.test(F.html) && /name="stock"[^>]*value="1"/.test(F.html), 'দাম ও স্টক ভরা আছে');
    check(/name="id" value="0"/.test(F.html) && F.html.includes(`name="copy_from" value="${SRC}"`), 'ফর্মের id=0 (নতুন পণ্য হিসেবে যাবে), copy_from আছে');
    check((F.html.match(/id="copyImgs"[\s\S]*?<\/div>\s*<div class="form-check/)?.[0].match(/<img /g) || []).length === 2 && F.html.includes('name="copy_images"'), 'মূল পণ্যের ২টি ছবি দেখায়, "ছবি কপি" চেকবক্স আছে');
    check(sql('SELECT COUNT(*) FROM products') === cnt0, 'ফর্ম খোলায় ডাটাবেসে কিছুই তৈরি হয়নি');
    for (const bad of ['0', 'abc', '99999999', '1%20OR%201=1', '[]=1']) {
      F = await formOf(bad === '[]=1' ? '?copy[]=1' : `?copy=${bad}`);
      check(F.status === 200 && !F.html.includes('id="copyBanner"') && !/fatal|warning:/i.test(F.html), `copy=${bad} → সাধারণ নতুন-পণ্য ফর্ম, ক্র্যাশ নয়`);
    }
    r = await get(`/admin/product_form.php?copy=${SRC}`, {});
    check(r.status === 302, 'লগইন ছাড়া কপি-ফর্ম বন্ধ');
    r = await get(`/admin/product_form.php?copy=${SRC}`, { Cookie: 'PHPSESSID=' + CSID });
    check(r.status !== 200 || !(await r.text()).includes('copyBanner'), 'পারমিশন ছাড়া অ্যাডমিন কপি-ফর্ম পায় না');
    check(sql('SELECT COUNT(*) FROM products') === cnt0, 'এখনো কোনো পণ্য তৈরি হয়নি');

    const saveForm = (extra, files = [], withCsrf = true) => {
      const fd = new FormData();
      if (withCsrf) fd.set('csrf', CSRF);
      fd.set('save_product', '1'); fd.set('id', '0'); fd.set('name', '[T] Copy Saved'); fd.set('category_id', cat);
      fd.set('price', '150'); fd.set('compare_at_price', ''); fd.set('stock', '7'); fd.set('active', '1');
      for (const [k, v] of Object.entries(extra)) fd.set(k, v);
      for (const f of files) fd.append('images[]', new Blob([fs.readFileSync(f)], { type: 'image/jpeg' }), path.basename(f));
      if (!files.length) fd.append('images[]', new Blob([]), '');
      return fetch(BASE + '/admin/product_form.php', { method: 'POST', redirect: 'manual', headers: { Cookie: 'PHPSESSID=' + SID }, body: fd });
    };
    const imgsOf = (pid) => sql(`SELECT image FROM product_images WHERE product_id=${pid} ORDER BY sort_order, id`).split(String.fromCharCode(10)).filter(Boolean);
    const byName = (n) => sql(`SELECT id FROM products WHERE name='${n}' ORDER BY id DESC LIMIT 1`);

    r = await saveForm({ copy_from: SRC, copy_images: '1' }, [], false);
    check(sql('SELECT COUNT(*) FROM products') === cnt0, 'CSRF ছাড়া Save → পণ্য তৈরি হয় না');
    r = await saveForm({ copy_from: SRC, copy_images: '1' });
    const C1 = byName('[T] Copy Saved');
    check(r.status === 302 && !!C1 && sql('SELECT COUNT(*) FROM products') === String(Number(cnt0) + 1), 'Save চাপলে তবেই নতুন পণ্য তৈরি (id=' + C1 + ')');
    const row = sql(`SELECT price, stock, sku IS NULL, sold_count, is_featured FROM products WHERE id=${C1}`).split('\t');
    check(row[0] === '150.00' && row[1] === '7' && row[2] === '1' && row[3] === '0' && row[4] === '0', 'ফর্মে বদলানো দাম/স্টক সেভ হয়েছে; SKU খালি, বিক্রি ০, Featured বন্ধ');
    const i1 = imgsOf(C1);
    check(i1.length === 2 && i1.every((i) => i.includes(`product_${C1}_`) && /_cp\d\./.test(i)) && i1.every((i) => fs.existsSync(path.join(ROOT, i))), 'মূল পণ্যের ছবি ২টি নতুন নামে আলাদা ফাইল হিসেবে কপি হয়েছে');
    check(sql(`SELECT image FROM products WHERE id=${C1}`) === i1[0], 'মূল ছবি = প্রথম কপি-ছবি');
    check(imgsOf(SRC).length === 2 && fs.existsSync(path.join(PDIR, 'test_t_a.jpg')), 'আসল পণ্যের ছবি অক্ষত');

    r = await saveForm({ copy_from: SRC, name: '[T] Copy NoImg' });
    const C2 = byName('[T] Copy NoImg');
    check(!!C2 && imgsOf(C2).length === 1 && /default\.jpg$/.test(imgsOf(C2)[0]), 'ছবি-কপি চেকবক্স টিক না দিলে ছবি কপি হয় না (default.jpg)');

    r = await saveForm({ copy_from: SRC, copy_images: '1', name: '[T] Copy Plus' }, [srcFile]);
    const C3 = byName('[T] Copy Plus');
    const i3 = imgsOf(C3);
    check(!!C3 && i3.length === 3 && /_cp0\./.test(i3[0]) && /_cp1\./.test(i3[1]) && /_img0\./.test(i3[2]), 'কপি করা ২টির পরে নতুন আপলোড ছবি বসে (মোট ৩): ' + i3.length);
    check(sql(`SELECT GROUP_CONCAT(sort_order ORDER BY sort_order) FROM product_images WHERE product_id=${C3}`) === '0,1,2', 'ক্রম 0,1,2');

    r = await saveForm({ copy_from: 'abc', copy_images: '1', name: '[T] Copy Bad' });
    const C4 = byName('[T] Copy Bad');
    check(!!C4 && imgsOf(C4).length === 1 && /default\.jpg$/.test(imgsOf(C4)[0]), 'copy_from অবৈধ হলে উপেক্ষা, সাধারণ নতুন পণ্য');
    r = await saveForm({ copy_from: '99999999', copy_images: '1', name: '[T] Copy Ghost' });
    check(!!byName('[T] Copy Ghost'), 'অজানা copy_from হলেও ক্র্যাশ নয়, পণ্য তৈরি হয় (ছবি ছাড়া)');

    const ed = sql("SELECT id FROM products WHERE name='[T] P02'");
    const fdE = new FormData();
    fdE.set('csrf', CSRF); fdE.set('save_product', '1'); fdE.set('id', ed); fdE.set('name', '[T] P02'); fdE.set('category_id', cat); fdE.set('price', '102'); fdE.set('compare_at_price', ''); fdE.set('stock', '2'); fdE.set('copy_from', SRC); fdE.set('copy_images', '1'); fdE.append('images[]', new Blob([]), '');
    await fetch(BASE + '/admin/product_form.php', { method: 'POST', redirect: 'manual', headers: { Cookie: 'PHPSESSID=' + SID }, body: fdE });
    check(imgsOf(ed).every((i) => !/_cp\d/.test(i)), 'বিদ্যমান পণ্য edit-এ copy_from কাজ করে না (ছবি জোড়া লাগে না)');

    const dl = await post('/admin/product_delete.php', { csrf: CSRF, id: C1 }, { ...CK, 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' });
    check((await dl.json().catch(() => ({}))).ok === true, 'কপি-করা পণ্য ডিলিট হয়');
    check(fs.existsSync(path.join(PDIR, 'test_t_a.jpg')) && fs.existsSync(path.join(PDIR, 'test_t_b.jpg')), 'কপি মুছলেও আসল পণ্যের ছবি-ফাইল থাকে (ফাইল শেয়ার হয়নি)');
    check(sql("SELECT COUNT(*) FROM information_schema.tables WHERE 1=0") === '0' && !fs.existsSync(path.join(ROOT, 'admin', 'product_duplicate.php')), 'পুরোনো product_duplicate.php এন্ডপয়েন্ট নেই');

    console.log('\n=== ব্রাউজার (ছবি প্রিভিউ, কপি বাটন, লেআউট) ===');
    chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, '--no-sandbox', '--disable-gpu', '--hide-scrollbars', '--user-data-dir=' + os.tmpdir() + '\\pp_prof_' + Date.now(), 'about:blank'], { stdio: 'ignore' });
    let ready = false;
    for (let i = 0; i < 40; i++) { try { await fetch(`http://127.0.0.1:${PORT}/json/version`); ready = true; break; } catch { await sleep(250); } }
    if (!ready) throw new Error('DevTools নেই');
    const t = await fetch(`http://127.0.0.1:${PORT}/json/new?about:blank`, { method: 'PUT' }).then((x) => x.json());
    const ws = new WebSocket(t.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.addEventListener('open', res, { once: true }); ws.addEventListener('error', rej, { once: true }); });
    let id = 1; const call = (m, p) => send(ws, id++, m, p);
    await call('Page.enable'); await call('Runtime.enable'); await call('Network.enable'); await call('Network.setCacheDisabled', { cacheDisabled: true });
    await call('Network.setCookie', { name: 'PHPSESSID', value: SID, domain: new URL(BASE).hostname, path: '/' });
    let dialogs = 0;
    ws.addEventListener('message', (e) => { const m = JSON.parse(e.data); if (m.method === 'Page.javascriptDialogOpening') { dialogs++; call('Page.handleJavaScriptDialog', { accept: true }).catch(() => {}); } });
    const ev = async (expr) => { const x = await call('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true }); if (x.exceptionDetails) throw new Error(x.exceptionDetails.exception?.description || x.exceptionDetails.text); return x.result.value; };
    const go = async (p, w) => { await call('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 600 }); await call('Page.navigate', { url: BASE + p }); await sleep(2000); };
    for (const w of [1326, 390]) {
      await go(`/admin/products.php?q=${q}&per=10`, w);
      const m = await ev(`(()=>({hs:document.documentElement.scrollWidth>innerWidth+1,rows:document.querySelectorAll('#rowsBody tr').length,dup:document.querySelectorAll('.btn-dup').length,per:document.getElementById('perPage').value,csv:!!document.getElementById('exportCsv')}))()`);
      check(!m.hs && m.rows === 10 && m.dup === 10 && m.per === '10' && m.csv, `${w}px: overflow নেই, ১০ সারি, প্রতিটিতে কপি বাটন, per ড্রপডাউন ও CSV বাটন আছে`);
      const shot = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
      fs.writeFileSync(path.join(os.tmpdir(), `products_${w}.png`), Buffer.from(shot.data, 'base64'));
    }
    await go(`/admin/products.php?q=${encodeURIComponent('[T] P01')}`, 1326);
    await ev("document.querySelector('.thumb-zoom').click()"); await sleep(700);
    const modal = await ev("(()=>{const m=document.getElementById('imgModal');const i=document.getElementById('imgModalImg');return {shown:m.classList.contains('show'),src:i.src,title:document.getElementById('imgModalTitle').textContent}})()");
    check(modal.shown && /test_t_a\.jpg/.test(modal.src) && modal.title === '[T] P01', 'ছবিতে ক্লিক → বড় প্রিভিউ মোডাল (' + modal.title + ')');
    await ev("document.querySelector('#imgModal .btn-close').click()"); await sleep(500);
    check(!(await ev("document.getElementById('imgModal').classList.contains('show')")), 'মোডাল বন্ধ হয়');
    const cb = sql('SELECT COUNT(*) FROM products');
    const lnk = await ev("(()=>{const a=document.querySelector('.btn-dup');return {tag:a.tagName,href:a.getAttribute('href'),target:a.target,rel:a.rel}})()");
    check(lnk.tag === 'A' && lnk.target === '_blank' && /product_form\.php\?copy=\d+/.test(lnk.href) && /noopener/.test(lnk.rel), 'কপি বাটন নতুন ট্যাবের লিংক (target=_blank, noopener): ' + lnk.href);
    const urlBefore = await ev('location.href');
    let opened = null;
    await call('Runtime.evaluate', { expression: "document.querySelector('.btn-dup').click()", userGesture: true }); await sleep(2500);
    check(dialogs === 0, 'কপি চাপলে কোনো নিশ্চিতকরণ পপআপ নেই');
    check((await ev('location.href')) === urlBefore, 'আসল ট্যাব একই পেজে থাকে');
    const tabs = await fetch(`http://127.0.0.1:${PORT}/json`).then((x) => x.json());
    opened = (tabs.find((x) => x.type === 'page' && /product_form\.php\?copy=/.test(x.url)) || {}).url || null;
    check(!!opened, 'নতুন ট্যাব খুলেছে: ' + opened);
    check(sql('SELECT COUNT(*) FROM products') === cb, 'ক্লিকে ডাটাবেসে কোনো পণ্য তৈরি হয়নি');
    await call('Page.navigate', { url: BASE + '/admin/' + lnk.href });
    await sleep(2000);
    const ui = await ev("(()=>({banner:!!document.getElementById('copyBanner'),name:document.querySelector('input[name=name]').value,hs:document.documentElement.scrollWidth>innerWidth+1,btn:document.querySelector('button[name=save_product]').textContent.trim(),chk:!!document.getElementById('copyImagesChk')}))()");
    check(ui.banner && /\(কপি\)$/.test(ui.name.normalize('NFC')) && ui.btn === 'Save' && ui.chk && !ui.hs, 'কপি-ফর্ম ব্রাউজারে: ব্যানার, নাম "' + ui.name + '", বাটন Save, ছবি-টিক, overflow নেই');
    await call('Emulation.setDeviceMetricsOverride', { width: 390, height: 900, deviceScaleFactor: 1, mobile: true });
    await sleep(600);
    const shot = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
    fs.writeFileSync(path.join(os.tmpdir(), 'copy_form_390.png'), Buffer.from(shot.data, 'base64'));
    check(!(await ev('document.documentElement.scrollWidth>innerWidth+1')), 'মোবাইলেও কপি-ফর্মে overflow নেই');
    check(sql('SELECT COUNT(*) FROM products') === cb, 'ফর্ম খুলে রাখলেও কিছু তৈরি হয়নি (Save না চাপা পর্যন্ত)');
    ws.close();
  } catch (e) { console.error('ERROR: ' + e.message); fail++; }
  finally { try { chrome && chrome.kill(); } catch {} cleanup(); }
  check(sql('SELECT COUNT(*) FROM products') === before.products && sql('SELECT COUNT(*) FROM home_section_products') === before.hsp, 'টেস্ট শেষে পণ্য ও সেকশন-সংযোগ আগের সংখ্যায় ফিরেছে');
  check(!fs.readdirSync(PDIR).some((f) => f.startsWith('test_t_')), 'অস্থায়ী ছবি মুছেছে');
  console.log((fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exitCode = fail === 0 ? 0 : 1;
})();
