/**
 * tools/verify_product_images.js — admin/product_form.php: ছবি আপলোডে ডিফল্ট (default.jpg) প্লেসহোল্ডার নিজে সরে যায়, ক্রম ঠিক থাকে
 * node tools/verify_product_images.js [url]
 * "[T]" পণ্য ও অস্থায়ী অ্যাডমিন সেশন নিজে বানায়-মোছে; আসল পণ্য বদলায় না।
 */
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const BASE = process.argv[2] || 'http://estore.test';
const MYSQL = process.env.MYSQL_BIN || 'mysql';
const ROOT = path.join(__dirname, '..');
const PDIR = path.join(ROOT, 'uploads', 'products_image');
const SESS_DIR = 'C:/laragon/tmp';
let pass = 0, fail = 0;
const ok = (m) => { pass++; console.log('  PASS  ' + m); };
const bad = (m) => { fail++; console.log('  FAIL  ' + m); };
const check = (c, m) => (c ? ok(m) : bad(m));
const sql = (q) => execFileSync(MYSQL, ['-uroot', '-N', '--default-character-set=utf8mb4', 'estore'], { encoding: 'utf8', input: q + ';\n' }).replace(/\r/g, '').trim();
const SID = require('crypto').randomBytes(13).toString('hex');
const CSRF = 'a'.repeat(32);

function cleanup() {
  try {
    const ids = sql("SELECT id FROM products WHERE name LIKE '[T]%'").split('\n').filter(Boolean);
    if (ids.length) {
      sql(`DELETE FROM home_section_products WHERE product_id IN (${ids.join(',')})`);
      sql(`DELETE FROM products WHERE id IN (${ids.join(',')})`);
      for (const f of fs.readdirSync(PDIR)) if (ids.some((i) => f.startsWith('product_' + i + '_'))) { try { fs.unlinkSync(path.join(PDIR, f)); } catch {} }
    }
  } catch (e) { console.log('cleanup: ' + e.message); }
  try { fs.unlinkSync(`${SESS_DIR}/sess_${SID}`); } catch {}
}

const imgs = (pid) => sql(`SELECT image FROM product_images WHERE product_id=${pid} ORDER BY sort_order, id`).split('\n').filter(Boolean);
const sorts = (pid) => sql(`SELECT sort_order FROM product_images WHERE product_id=${pid} ORDER BY sort_order, id`).split('\n').filter(Boolean).map(Number);
const mainOf = (pid) => sql(`SELECT image FROM products WHERE id=${pid}`);

async function save(pid, files, extra = {}) {
  const fd = new FormData();
  fd.set('csrf', CSRF); fd.set('save_product', '1'); fd.set('id', String(pid));
  fd.set('name', '[T] Img Test'); fd.set('category_id', sql('SELECT id FROM categories ORDER BY id LIMIT 1'));
  fd.set('price', '100'); fd.set('compare_at_price', ''); fd.set('stock', '5'); fd.set('active', '1');
  for (const [k, v] of Object.entries(extra)) fd.set(k, v);
  for (const f of files) fd.append('images[]', new Blob([fs.readFileSync(f.path)], { type: 'image/jpeg' }), f.name);
  if (!files.length) fd.append('images[]', new Blob([]), '');
  return fetch(BASE + '/admin/product_form.php', { method: 'POST', redirect: 'manual', headers: { Cookie: 'PHPSESSID=' + SID }, body: fd });
}

(async () => {
  cleanup();
  const before = sql('SELECT COUNT(*) FROM products');
  try {
    fs.writeFileSync(`${SESS_DIR}/sess_${SID}`, `admin_id|i:1;admin_username|s:4:"test";admin_role_name|s:11:"Super Admin";admin_permissions|a:1:{i:0;s:15:"manage_products";}csrf|s:32:"${CSRF}";__sid_rotated|i:${Math.floor(Date.now() / 1000)};`);
    const src = path.join(PDIR, fs.readdirSync(PDIR).find((f) => /^product_.*\.jpg$/i.test(f)));
    const jpg = (n) => ({ path: src, name: n });
    const cat = sql('SELECT id FROM categories ORDER BY id LIMIT 1');
    const mk = () => { sql(`INSERT INTO products (category_id, name, price, stock, active, image) VALUES (${cat}, '[T] Img Test', 100, 5, 1, '/uploads/products_image/default.jpg')`); const id = sql("SELECT MAX(id) FROM products WHERE name='[T] Img Test'"); sql(`INSERT INTO product_images (product_id, image, sort_order) VALUES (${id}, '/uploads/products_image/default.jpg', 0)`); return id; };
    const defaultFile = path.join(PDIR, 'default.jpg');
    const defaultExisted = fs.existsSync(defaultFile);

    console.log('\n=== ডিফল্ট ছবি আছে এমন পণ্যে নতুন ছবি আপলোড (পরিষ্কার সমস্যাটি) ===');
    const A = mk();
    check(imgs(A).length === 1 && /default\.jpg$/.test(imgs(A)[0]), 'শুরুতে শুধু default.jpg');
    let r = await save(A, [jpg('a.jpg'), jpg('b.jpg')]);
    check(r.status === 302, 'সেভ সফল (redirect)');
    let list = imgs(A);
    check(list.length === 2 && !list.some((i) => /default\.jpg$/.test(i)), 'ডিফল্ট সারি নিজে সরেছে; ছবি ২টি, হাতে ডিলিট লাগেনি: ' + list.length);
    check(/product_\d+_\d+_img0/.test(mainOf(A)) && mainOf(A) === list[0], 'মূল ছবি এখন নতুন ছবি (default.jpg নয়)');
    check(fs.existsSync(path.join(ROOT, list[0])) && fs.existsSync(path.join(ROOT, list[1])), 'নতুন ছবির ফাইল ডিস্কে আছে');
    check(fs.existsSync(defaultFile) === defaultExisted, 'default.jpg ফাইলটি নিজে অক্ষত (শুধু ডাটাবেস সারি সরেছে)');
    check(JSON.stringify(sorts(A)) === '[0,1]', 'ক্রম 0,1');

    console.log('\n=== ছবি না দিয়ে সেভ ===');
    const B = mk();
    r = await save(B, []);
    check(imgs(B).length === 1 && /default\.jpg$/.test(imgs(B)[0]) && /default\.jpg$/.test(mainOf(B)), 'ছবি না দিলে default.jpg-ই থাকে (ছবিহীন পণ্য ভাঙে না)');

    console.log('\n=== আগের ছবি আছে, আরও ছবি যোগ ===');
    r = await save(A, [jpg('c.jpg')]);
    list = imgs(A);
    const s2 = sorts(A);
    check(list.length === 3, 'এখন মোট ৩টি');
    check(s2[0] < s2[1] && s2[1] < s2[2], 'ক্রম বাড়তে থাকে, ধাক্কা লাগে না: ' + s2.join(','));
    check(mainOf(A) === list[0] && /_img0\./.test(list[0]), 'মূল ছবি বদলায়নি; নতুনটি শেষে');
    check(!list.some((i) => /default\.jpg$/.test(i)), 'ডিফল্ট ফেরেনি');

    console.log('\n=== ডিফল্টসহ নতুন পণ্য বানানো ===');
    r = await save(0, [jpg('n.jpg')], { name: '[T] Img New' });
    const N = sql("SELECT id FROM products WHERE name='[T] Img New'");
    check(!!N && imgs(N).length === 1 && !/default\.jpg$/.test(imgs(N)[0]), 'ছবিসহ নতুন পণ্যে ডিফল্ট নেই');
    r = await save(0, [], { name: '[T] Img None' });
    const Z = sql("SELECT id FROM products WHERE name='[T] Img None'");
    check(!!Z && /default\.jpg$/.test(mainOf(Z)) && imgs(Z).length === 1, 'ছবি ছাড়া নতুন পণ্যে default.jpg বসে');
    console.log('\n=== ছবি মুছে ফেললে ===');
    const first = sql(`SELECT id FROM product_images WHERE product_id=${A} ORDER BY sort_order, id LIMIT 1`);
    r = await fetch(`${BASE}/admin/product_form.php?id=${A}&delimg=${first}`, { redirect: 'manual', headers: { Cookie: 'PHPSESSID=' + SID } });
    check(imgs(A).length === 2 && mainOf(A) === imgs(A)[0], 'একটি ছবি মুছলে বাকিগুলো থাকে, মূল ছবি ঠিক হয়');
  } catch (e) { console.error('ERROR: ' + e.message); fail++; }
  finally { cleanup(); }
  check(sql('SELECT COUNT(*) FROM products') === before, 'টেস্ট শেষে পণ্যের সংখ্যা আগের মতো');
  console.log((fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exitCode = fail === 0 ? 0 : 1;
})();
