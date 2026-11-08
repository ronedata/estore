/**
 * tools/verify_home_sections.js — হোম সেকশন ম্যানেজার end-to-end (HTTP, ব্রাউজার ছাড়া)
 *   ADMIN_COOKIE="PHPSESSID=..." node tools/verify_home_sections.js [url]
 * (টেস্ট সেশন: php tools/make_admin_session.php create)
 *
 * নিজেই টেস্ট সেকশন/পণ্য বানায়, যাচাই করে, শেষে সব মুছে আগের অবস্থায় ফেরে।
 */
const BASE = (process.argv[2] || 'http://estore.test').replace(/\/$/, '');
const COOKIE = process.env.ADMIN_COOKIE || '';
if (!COOKIE) { console.error('ADMIN_COOKIE লাগবে'); process.exit(2); }

let pass = 0, fail = 0;
const ok = (c, m, extra = '') => { if (c) { pass++; console.log('  PASS  ' + m); } else { fail++; console.log('  FAIL  ' + m + ' ' + extra); } };

async function req(path, { method = 'GET', form = null, cookie = true } = {}) {
  const headers = {};
  if (cookie) headers.Cookie = COOKIE;
  let body;
  if (form) {
    headers['Content-Type'] = 'application/x-www-form-urlencoded';
    body = form instanceof URLSearchParams ? form : new URLSearchParams(form);
  }
  const r = await fetch(BASE + path, { method, headers, body, redirect: 'manual' });
  return { status: r.status, loc: r.headers.get('location') || '', text: await r.text() };
}
const csrfOf = (html) => (html.match(/name="csrf" value="([^"]+)"/) || [])[1] || '';
const sectionsOnHome = (html) => [...html.matchAll(/data-section-id="(\d+)"/g)].map((m) => +m[1]);
const cardsIn = (html, id) => {
  const start = html.indexOf(`data-section-id="${id}"`);
  if (start < 0) return -1;
  const end = html.indexOf('</section>', start);
  return (html.slice(start, end).match(/<article class="fruit-card/g) || []).length;
};
const listRows = (html) => [...html.matchAll(/<tr data-id="(\d+)">/g)].map((m) => +m[1]);
const flashOf = (html) => ((html.match(/id="hsFlash">\s*([^<]+)</) || [])[1] || '').trim();

(async () => {
  let csrf = '';
  const created = [];
  let testProduct = 0;

  const page = async () => { const r = await req('/admin/home_sections.php'); csrf = csrfOf(r.text) || csrf; return r; };
  const post = (path, f) => req(path, { method: 'POST', form: { csrf, ...f } });

  console.log('== অ্যাক্সেস ==');
  const anon = await req('/admin/home_sections.php', { cookie: false });
  ok(anon.status === 302 && /login/.test(anon.loc), 'লগইন ছাড়া অ্যাডমিন পেজে ঢোকা যায় না');
  let r = await page();
  ok(r.status === 200, 'তালিকা পেজ 200');
  ok(/হোম সেকশন/.test(r.text), 'সাইডবারে/শিরোনামে "হোম সেকশন" আছে');
  ok(!/Warning|Fatal error|Notice:/i.test(r.text), 'তালিকায় PHP warning নেই');
  const baseRows = listRows(r.text);
  ok(baseRows.length >= 2, `শুরুতে অন্তত ২টি সেকশন (${baseRows.length})`);
  const baseHome = sectionsOnHome((await req('/', { cookie: false })).text);

  console.log('== CSRF / ভুল ইনপুট ==');
  r = await req('/admin/home_sections.php', { method: 'POST', form: { action: 'delete', id: String(baseRows[0]) } });
  ok(r.status === 302 && listRows((await page()).text).length === baseRows.length, 'CSRF ছাড়া POST কিছু বদলায় না');
  const arr = new URLSearchParams(); arr.append('csrf[]', '1'); arr.append('action', 'delete'); arr.append('id', String(baseRows[0]));
  r = await req('/admin/home_sections.php', { method: 'POST', form: arr });
  ok(r.status === 302 && !/Fatal|TypeError/i.test(r.text), 'csrf[]=1 → fatal/path leak নেই');
  r = await req('/admin/home_section_form.php?id[]=1');
  ok(r.status === 200 && !/Fatal|TypeError|Warning/i.test(r.text), 'id[]=1 → fatal/warning নেই');
  r = await post('/admin/home_sections.php', { action: ['x'], id: ['1'] });
  ok(r.status === 302, 'action[]/id[] → শান্তভাবে ফেরত');

  console.log('== সেকশন তৈরি + হোমে দেখা ==');
  r = await post('/admin/home_section_form.php', { action: 'save', title: 'TEST Hot Deals', subtitle: 'টেস্ট', type: 'latest', product_limit: '4', show_see_all: '1', active: '1' });
  ok(r.status === 302 && /home_sections\.php/.test(r.loc), 'নতুন সেকশন সেভ → তালিকায় রিডাইরেক্ট');
  let rows = listRows((await page()).text);
  const newId = rows.find((x) => !baseRows.includes(x));
  ok(!!newId, 'তালিকায় নতুন সেকশন এসেছে');
  created.push(newId);
  let home = (await req('/', { cookie: false })).text;
  ok(sectionsOnHome(home).includes(newId), 'হোমপেজে নতুন সেকশন দেখা যাচ্ছে');
  ok(cardsIn(home, newId) === 4, `limit=4 → হোমে ঠিক ৪টি পণ্য (${cardsIn(home, newId)})`);
  ok(home.includes('TEST Hot Deals'), 'হোমে শিরোনাম দেখা যায়');
  ok(home.includes(`/shop.php?section=${newId}`), '"সব দেখুন" লিংক section=ID');

  console.log('== পণ্য সংখ্যা বদল ==');
  r = await post(`/admin/home_section_form.php?id=${newId}`, { action: 'save', title: 'TEST Hot Deals', subtitle: '', type: 'latest', product_limit: '7', show_see_all: '1', active: '1' });
  home = (await req('/', { cookie: false })).text;
  ok(cardsIn(home, newId) === 7, `limit=7 → হোমে ৭টি (${cardsIn(home, newId)})`);
  r = await post(`/admin/home_section_form.php?id=${newId}`, { action: 'save', title: 'TEST Hot Deals', type: 'latest', product_limit: '0', active: '1' });
  ok(r.status === 200 && /মধ্যে হতে হবে/.test(r.text), 'limit=0 → ফর্মে ত্রুটি');
  r = await post(`/admin/home_section_form.php?id=${newId}`, { action: 'save', title: 'TEST Hot Deals', type: 'latest', product_limit: '99', active: '1' });
  ok(r.status === 200 && /মধ্যে হতে হবে/.test(r.text), 'limit=99 → ফর্মে ত্রুটি');
  home = (await req('/', { cookie: false })).text;
  ok(cardsIn(home, newId) === 7, 'ভুল ইনপুটে আগের limit অক্ষত');

  console.log('== ক্রম (র‍্যাঙ্ক) ==');
  rows = listRows((await page()).text);
  ok(rows[rows.length - 1] === newId, 'নতুন সেকশন শেষে');
  await post('/admin/home_sections.php', { action: 'up', id: String(newId) });
  rows = listRows((await page()).text);
  ok(rows[rows.length - 2] === newId, '↑ চাপলে এক ধাপ ওপরে');
  const idxList = rows.indexOf(newId);
  home = (await req('/', { cookie: false })).text;
  const homeOrder = sectionsOnHome(home);
  ok(homeOrder.indexOf(newId) === homeOrder.length - 2, 'হোমপেজেও একই ক্রম');
  ok(JSON.stringify(homeOrder) === JSON.stringify(rows.filter((x) => homeOrder.includes(x))), 'হোমের ক্রম = অ্যাডমিন তালিকার ক্রম');
  await post('/admin/home_sections.php', { action: 'down', id: String(newId) });
  rows = listRows((await page()).text);
  ok(rows[rows.length - 1] === newId, '↓ চাপলে আবার শেষে');
  await post('/admin/home_sections.php', { action: 'up', id: String(rows[0]) });
  ok(JSON.stringify(listRows((await page()).text)) === JSON.stringify(rows), 'প্রথমটিকে ↑ দিলে অপরিবর্তিত');

  console.log('== চালু/বন্ধ ==');
  await post('/admin/home_sections.php', { action: 'toggle', id: String(newId) });
  home = (await req('/', { cookie: false })).text;
  ok(!sectionsOnHome(home).includes(newId), 'বন্ধ করলে হোম থেকে সরে যায়');
  r = await req(`/shop.php?section=${newId}`, { cookie: false });
  ok(!/TEST Hot Deals/.test(r.text), 'বন্ধ সেকশনের shop.php?section= ফলব্যাক (সব পণ্য)');
  await post('/admin/home_sections.php', { action: 'toggle', id: String(newId) });
  home = (await req('/', { cookie: false })).text;
  ok(sectionsOnHome(home).includes(newId), 'আবার চালু করলে ফিরে আসে');

  console.log('== shop.php?section=ID ==');
  r = await req(`/shop.php?section=${newId}`, { cookie: false });
  ok(r.status === 200 && /TEST Hot Deals/.test(r.text), 'শপ পেজে সেকশনের নাম শিরোনাম');
  ok(/24 টি পণ্য|\d+ টি পণ্য পাওয়া গেছে/.test(r.text) && !/Warning|Fatal/i.test(r.text), 'শপ পেজ লোড, warning নেই');

  console.log('== সর্বোচ্চ ৮ ==');
  for (let n = rows.length; n < 8; n++) {
    const x = await post('/admin/home_section_form.php', { action: 'save', title: `TEST fill ${n}`, type: 'latest', product_limit: '3', active: '1' });
    if (x.status !== 302) break;
  }
  r = await page();
  rows = listRows(r.text);
  for (const id of rows) if (!baseRows.includes(id) && !created.includes(id)) created.push(id);
  ok(rows.length === 8, `৮টি সেকশনে পৌঁছেছে (${rows.length})`);
  ok(/id="btnNewSection"[^>]*disabled|disabled[^>]*id="btnNewSection"/.test(r.text), '৮টি হলে "নতুন সেকশন" বাটন বন্ধ');
  r = await post('/admin/home_section_form.php', { action: 'save', title: 'TEST ninth', type: 'latest', product_limit: '3', active: '1' });
  ok(r.status === 200 && /সর্বোচ্চ 8|সর্বোচ্চ ৮/.test(r.text), '৯ম সেকশন → ত্রুটি বার্তা');
  ok(listRows((await page()).text).length === 8, '৯ম সেকশন তৈরি হয়নি');

  console.log('== ন্যূনতম ২ ==');
  for (const id of [...created]) { await post('/admin/home_sections.php', { action: 'delete', id: String(id) }); }
  rows = listRows((await page()).text);
  ok(rows.length === baseRows.length, `টেস্ট সেকশন মুছে মূল সংখ্যায় ফিরেছে (${rows.length})`);
  created.length = 0;

  if (rows.length === 2) {
    r = await post('/admin/home_sections.php', { action: 'delete', id: String(rows[0]) });
    const p = await page();
    ok(listRows(p.text).length === 2 && /রাখতেই হবে/.test(flashOf(p.text) || p.text), '২টি থাকলে মোছা আটকায়');
    await post('/admin/home_sections.php', { action: 'toggle', id: String(rows[0]) });
    home = (await req('/', { cookie: false })).text;
    ok(sectionsOnHome(home).length >= 1 && /চালু থাকতেই হবে|রাখতেই হবে/.test((await page()).text), 'দুটোর একটিও বন্ধ করা যায় না');
  }

  console.log('== প্রোডাক্ট ফর্ম: সেকশন টগল ==');
  r = await post('/admin/home_section_form.php', { action: 'save', title: 'TEST Manual', type: 'manual', product_limit: '5', new_default: '1', active: '1' });
  rows = listRows((await page()).text);
  const manualId = rows.find((x) => !baseRows.includes(x));
  created.push(manualId);
  r = await req('/admin/product_form.php');
  ok(r.status === 200 && r.text.includes('name="section_ids[]"'), 'পণ্য ফর্মে section_ids[] টগল আছে');
  ok(r.text.includes('TEST Manual'), 'নতুন সেকশন টগল-তালিকায় এসেছে');
  ok(!/name="is_featured"|name="is_new_arrival"/.test(r.text), 'পুরোনো হার্ডকোড টগল আর নেই');
  ok(new RegExp(`id="hsec${manualId}"[^>]*checked`).test(r.text), 'new_default=1 সেকশনে নতুন পণ্যে আগে থেকে টিক');
  r = await req('/admin/product_add_for_mobile.php');
  ok(r.status === 200 && r.text.includes('name="section_ids[]"'), 'মোবাইল ফর্মেও টগল আছে');

  console.log('== পণ্য সেভ → সেকশনে ==');
  const mobileCsrf = csrfOf(r.text);
  const cat = (r.text.match(/<option value="(\d+)"/) || [])[1];
  const form = new URLSearchParams({ csrf: mobileCsrf, name: 'TEST সেকশন পণ্য', price: '123', category_id: cat, stock: '5', save_product: '1' });
  form.append('section_ids[]', String(manualId));
  form.append('section_ids[]', 'abc');
  form.append('section_ids[]', '999999');
  r = await req('/admin/product_add_for_mobile.php', { method: 'POST', form });
  ok(r.status === 302, 'মোবাইল ফর্ম থেকে পণ্য সেভ');
  const q = await req(`/admin/home_section_form.php?id=${manualId}`);
  const m = q.text.match(/<td>#(\d+)<\/td>\s*<td>TEST সেকশন পণ্য/);
  ok(!!m, 'পণ্যটি manual সেকশনের তালিকায় এসেছে (ভুল ID বাদ)');
  testProduct = m ? +m[1] : 0;
  home = (await req('/', { cookie: false })).text;
  ok(sectionsOnHome(home).includes(manualId) && cardsIn(home, manualId) === 1, 'হোমে manual সেকশনে ১টি পণ্য');

  ok(testProduct > 0, 'টেস্ট পণ্যের id পাওয়া গেছে');
  const pf = await req(`/admin/product_form.php?id=${testProduct}`);
  ok(new RegExp(`id="hsec${manualId}"[^>]*checked`).test(pf.text), 'এডিট ফর্মে বর্তমান সংযোগ টিক করা');

  console.log('== প্রোডাক্ট ফর্ম (product_form.php) সেভ/এডিট ==');
  {
    const pfPage = await req('/admin/product_form.php');
    const pfCsrf = csrfOf(pfPage.text);
    const cat2 = (pfPage.text.match(/name="category_id"[\s\S]*?<option value="(\d+)"/) || [])[1];
    const base = { csrf: pfCsrf, id: '0', name: 'TEST ফর্ম পণ্য', category_id: cat2, price: '55', compare_at_price: '', stock: '3', tags: '', specifications: '', description: '', active: '1', badge_color: '', youtube_url: '', save_product: '1' };
    const f1 = new URLSearchParams(base); f1.append('section_ids[]', String(manualId));
    r = await req('/admin/product_form.php', { method: 'POST', form: f1 });
    ok(r.status === 302, `product_form নতুন পণ্য সেভ (${r.status})`);
    let q2 = await req(`/admin/home_section_form.php?id=${manualId}`);
    const mm = q2.text.match(/<td>#(\d+)<\/td>\s*<td>TEST ফর্ম পণ্য/);
    ok(!!mm, 'product_form থেকে সেভ করা পণ্য সেকশনে এসেছে');
    const pid2 = mm ? +mm[1] : 0;
    if (pid2) {
      const f2b = new URLSearchParams({ ...base, id: String(pid2), name: 'TEST ফর্ম পণ্য' });
      r = await req('/admin/product_form.php', { method: 'POST', form: f2b });
      q2 = await req(`/admin/home_section_form.php?id=${manualId}`);
      ok(!/<td>#\d+<\/td>\s*<td>TEST ফর্ম পণ্য/.test(q2.text), 'এডিটে টিক তুলে সেভ → সেকশন থেকে সরে গেছে');
      const f2c = new URLSearchParams({ ...base, id: String(pid2) }); f2c.append('section_ids[]', String(manualId));
      f2c.append('section_ids[]', "1' OR '1'='1");
      r = await req('/admin/product_form.php', { method: 'POST', form: f2c });
      q2 = await req(`/admin/home_section_form.php?id=${manualId}`);
      ok(/<td>#\d+<\/td>\s*<td>TEST ফর্ম পণ্য/.test(q2.text), 'আবার টিক দিয়ে সেভ → ফিরে এসেছে (SQL-ধাঁচের ইনপুট ক্ষতিহীন)');
      const f2d = new URLSearchParams({ ...base, id: String(pid2) }); f2d.append('section_ids', 'notarray');
      r = await req('/admin/product_form.php', { method: 'POST', form: f2d });
      ok(r.status === 302 && !/Fatal|TypeError/i.test(r.text), 'section_ids স্ট্রিং (অ্যারে নয়) → fatal নেই');
      r = await post('/admin/product_delete.php', { id: String(pid2) });
    }
  }

  console.log('== সেকশন পেজ থেকে পণ্য বাদ ==');
  const f2 = await req(`/admin/home_section_form.php?id=${manualId}`);
  await post(`/admin/home_section_form.php?id=${manualId}`, { action: 'remove_product', product_id: String(testProduct) });
  home = (await req('/', { cookie: false })).text;
  ok(!sectionsOnHome(home).includes(manualId), 'পণ্য বাদ দিলে ফাঁকা সেকশন হোম থেকে লুকায়');
  await post(`/admin/home_section_form.php?id=${manualId}`, { action: 'add_product', product_id: String(testProduct) });
  home = (await req('/', { cookie: false })).text;
  ok(cardsIn(home, manualId) === 1, 'আবার যোগ করলে ফিরে আসে');

  console.log('== পণ্য মুছলে সংযোগ মুছে যায় ==');
  r = await post('/admin/product_delete.php', { id: String(testProduct) });
  ok(r.status === 200 || r.status === 302, `পণ্য ডিলিট (${r.status})`);
  home = (await req('/', { cookie: false })).text;
  ok(!sectionsOnHome(home).includes(manualId), 'পণ্য মুছলে সেকশন আবার ফাঁকা');
  testProduct = 0;

  console.log('== পরিষ্কার ==');
  for (const id of created) await post('/admin/home_sections.php', { action: 'delete', id: String(id) });
  rows = listRows((await page()).text);
  ok(JSON.stringify(rows) === JSON.stringify(baseRows), 'সেকশন তালিকা শুরুর মতোই');
  home = (await req('/', { cookie: false })).text;
  ok(JSON.stringify(sectionsOnHome(home)) === JSON.stringify(baseHome), 'হোমপেজ শুরুর মতোই');

  console.log(`\nমোট: ${pass + fail} | PASS ${pass} | FAIL ${fail}`);
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
