/**
 * tools/test_cart_wishlist.js — cart/wishlist রিমুভ ফাংশনাল টেস্ট
 * আগের বাগ: cart.php-এর remove বাটনে CSRF টোকেন ছিল না → আইটেম মুছছিল না।
 *
 *   node tools/test_cart_wishlist.js [baseUrl]
 */
const BASE = process.argv[2] || 'http://estore.test';
const pass = (m) => console.log('  PASS  ' + m);
const fail = (m) => { console.log('  FAIL  ' + m); failures++; };
let failures = 0;

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

(async () => {
  console.log('\n=== Cart / Wishlist Functional Test ===\n');

  // ---- সেশন + CSRF টোকেন ----
  const shop = await fetch(BASE + '/shop.php');
  const cookie = (shop.headers.getSetCookie ? shop.headers.getSetCookie() : [])
    .map((c) => c.split(';')[0]).join('; ');
  const html = await shop.text();
  const token = (html.match(/name="csrf-token" content="([a-f0-9]+)"/) || [])[1];
  if (!token) { fail('csrf-token পাওয়া যায়নি'); process.exit(1); }
  pass(`csrf-token পাওয়া গেছে (${token.slice(0, 8)}…)`);

  const H = { 'Content-Type': 'application/x-www-form-urlencoded', Cookie: cookie };
  const HX = { ...H, 'X-Requested-With': 'fetch' };

  const pid = (html.match(/data-product-id="(\d+)"/) || [])[1];
  if (!pid) { fail('প্রোডাক্ট আইডি পাওয়া যায়নি'); process.exit(1); }

  // ---- ১. কার্টে যোগ ----
  const addRes = await fetch(BASE + '/cart_add.php', {
    method: 'POST', headers: HX,
    body: `product_id=${pid}&qty=1&csrf=${token}`,
    redirect: 'manual',
  });
  const addJson = await addRes.json().catch(() => ({}));
  if (addJson.ok) pass(`cart_add → ok (count=${addJson.count})`);
  else fail(`cart_add → ${JSON.stringify(addJson)}`);

  let cartHtml = await (await fetch(BASE + '/cart.php', { headers: { Cookie: cookie } })).text();
  const inCart = cartHtml.includes(`name="qty[${pid}]"`);
  if (inCart) pass('কার্টে আইটেম দেখা যাচ্ছে');
  else fail('কার্টে আইটেম নেই');

  // ---- ২. cart.php পেজে csrf meta আছে (JS-এর জন্য) ----
  if (/name="csrf-token" content="[a-f0-9]{32}"/.test(cartHtml)) pass('cart.php-এ csrf-token meta আছে');
  else fail('cart.php-এ csrf-token meta নেই — remove বাটন ভাঙবে');

  // ---- ৩. CSRF ছাড়া remove → ব্লক ----
  const noCsrf = await fetch(BASE + '/cart_remove.php', {
    method: 'POST', headers: HX,
    body: `product_id=${pid}&next=cart.php`, redirect: 'manual',
  });
  cartHtml = await (await fetch(BASE + '/cart.php', { headers: { Cookie: cookie } })).text();
  if (noCsrf.status === 403 && cartHtml.includes(`name="qty[${pid}]"`)) {
    pass('CSRF ছাড়া remove → 403 এবং আইটেম অটুট');
  } else {
    fail(`CSRF ছাড়া remove → status=${noCsrf.status}`);
  }

  // ---- ৪. সঠিক টোকেনে remove → আইটেম যায় ----
  const rmRes = await fetch(BASE + '/cart_remove.php', {
    method: 'POST', headers: HX,
    body: `product_id=${pid}&next=cart.php&csrf=${token}`, redirect: 'manual',
  });
  cartHtml = await (await fetch(BASE + '/cart.php', { headers: { Cookie: cookie } })).text();
  const gone = !cartHtml.includes(`name="qty[${pid}]"`);
  if (rmRes.status === 200 && gone) pass('সঠিক টোকেনে remove → আইটেম মুছে গেছে');
  else fail(`remove ব্যর্থ — status=${rmRes.status}, gone=${gone}`);

  // ---- ৫. waitlist: লগইন ছাড়া remove → 401 JSON, redirect নয় ----
  const wlRes = await fetch(BASE + '/wishlist.php', {
    method: 'POST', headers: { ...H, 'X-Requested-With': 'XMLHttpRequest' },
    body: `action=remove&product_id=${pid}&csrf=${token}`, redirect: 'manual',
  });
  const wlJson = await wlRes.json().catch(() => ({}));
  if (wlRes.status === 401 && wlJson.login_required) pass('wishlist remove (লগইন ছাড়া) → 401 JSON');
  else fail(`wishlist remove → status=${wlRes.status}, ${JSON.stringify(wlJson)}`);

  // ---- ৬. wishlist পেজ array next-এ ফ্যাটাল দেয় না ----
  const pages = ['/cart_update.php', '/cart_remove.php'];
  for (const p of pages) {
    const r = await fetch(BASE + p, {
      method: 'POST', headers: HX,
      body: `csrf=${token}&next[]=x&product_id=${pid}&qty[${pid}]=1`, redirect: 'manual',
    });
    const body = await r.text().catch(() => '');
    const leaked = ['Fatal error', 'Uncaught', 'laragon'].some((n) => body.includes(n));
    if (!leaked) pass(`${p} next[]=x → ফ্যাটাল/পাথ লিক নেই (status=${r.status})`);
    else fail(`${p} next[]=x → ফ্যাটাল এরর/পাথ লিক`);
  }

  console.log(`\n${failures === 0 ? 'ALL PASS' : failures + ' FAILED'}\n`);
  process.exit(failures === 0 ? 0 : 1);
})();
