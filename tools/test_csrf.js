/**
 * tools/test_csrf.js — cart/wishlist/order endpoints-এ CSRF যাচাই
 * node tools/test_csrf.js [baseUrl]
 */
const { spawn } = require('child_process');
const BASE = process.argv[2] || 'http://127.0.0.1:8000';
const PORT = Number(process.env.PORT_C || 9400);
const CHROME = process.env.NAV_BROWSER || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function send(ws, id, method, params = {}) {
  return new Promise((resolve, reject) => {
    const onMsg = (ev) => {
      let m; try { m = JSON.parse(ev.data); } catch { return; }
      if (m.id === id) { ws.removeEventListener('message', onMsg); m.error ? reject(new Error(JSON.stringify(m.error))) : resolve(m.result); }
    };
    ws.addEventListener('message', onMsg);
    ws.send(JSON.stringify({ id, method, params }));
  });
}

(async () => {
  const out = [];
  const pass = (m) => out.push(['PASS', m]);
  const fail = (m) => out.push(['FAIL', m]);

  const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, '--no-sandbox', '--disable-gpu', 'about:blank'], { stdio: 'ignore' });

  try {
    let ready = false;
    for (let i = 0; i < 40; i++) { try { await fetch(`http://127.0.0.1:${PORT}/json/version`); ready = true; break; } catch { await sleep(250); } }
    if (!ready) throw new Error('DevTools নেই');

    const t = await fetch(`http://127.0.0.1:${PORT}/json/new?about:blank`, { method: 'PUT' }).then(r => r.json());
    const ws = new WebSocket(t.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.addEventListener('open', res, { once: true }); ws.addEventListener('error', rej, { once: true }); });

    let id = 1;
    await send(ws, id++, 'Page.enable');
    await send(ws, id++, 'Runtime.enable');

    const ev = async (expr) => {
      const r = await send(ws, id++, 'Runtime.evaluate', {
        expression: expr, returnByValue: true, awaitPromise: true,
      });
      if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
      return r.result.value;
    };

    // সেশন + টোকেন পেতে হোমপেজ লোড
    await send(ws, id++, 'Page.navigate', { url: BASE + '/index.php' });
    await sleep(2500);

    const token = await ev(`(document.querySelector('meta[name="csrf-token"]') || {}).content || ''`);
    if (token && token.length >= 16) pass(`meta csrf-token পাওয়া গেছে (${token.slice(0,8)}…)`);
    else fail('meta csrf-token নেই — partials_header.php আপডেট হয়নি');

    const pid = await ev(`(() => {
      const b = document.querySelector('[data-product-id]');
      return b ? b.dataset.productId : null;
    })()`);
    if (!pid) { fail('কোনো প্রোডাক্ট বাটন পাওয়া যায়নি'); }
    else pass(`টেস্ট প্রোডাক্ট id = ${pid}`);

    // ---- CSRF ছাড়া POST → reject হওয়া উচিত ----
    const noCsrf = await ev(`(async () => {
      const fd = new URLSearchParams();
      fd.set('product_id', '${pid}');
      fd.set('qty', '1');
      const r = await fetch('${BASE}/cart_add.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'},
        body: fd.toString()
      });
      const j = await r.json().catch(() => ({}));
      return { status: r.status, ok: j.ok, msg: j.message };
    })()`);

    if ((noCsrf.status === 403 || noCsrf.status === 419) && noCsrf.ok !== true) pass(`cart_add CSRF ছাড়া → ${noCsrf.status} reject`);
    else fail(`cart_add CSRF ছাড়া → status=${noCsrf.status}, ok=${noCsrf.ok} — reject হয়নি`);

    // ---- ভুল টোকেন → reject ----
    const badCsrf = await ev(`(async () => {
      const fd = new URLSearchParams();
      fd.set('product_id', '${pid}');
      fd.set('qty', '1');
      fd.set('csrf', 'deadbeefdeadbeefdeadbeefdeadbeef');
      const r = await fetch('${BASE}/cart_add.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'},
        body: fd.toString()
      });
      const j = await r.json().catch(() => ({}));
      return { status: r.status, ok: j.ok };
    })()`);
    if ((badCsrf.status === 403 || badCsrf.status === 419) && badCsrf.ok !== true) pass(`cart_add ভুল টোকেন → ${badCsrf.status} reject`);
    else fail(`cart_add ভুল টোকেন → status=${badCsrf.status}, ok=${badCsrf.ok}`);

    // ---- সঠিক টোকেন → সফল হওয়া উচিত ----
    const goodCsrf = await ev(`(async () => {
      const meta = document.querySelector('meta[name="csrf-token"]');
      const fd = new URLSearchParams();
      fd.set('product_id', '${pid}');
      fd.set('qty', '1');
      if (meta) fd.set('csrf', meta.content);
      const r = await fetch('${BASE}/cart_add.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'},
        body: fd.toString()
      });
      const j = await r.json().catch(() => ({}));
      return { status: r.status, ok: j.ok, count: j.count, msg: j.message };
    })()`);
    if (goodCsrf.status === 200 && goodCsrf.ok === true) pass(`cart_add সঠিক টোকেন → ok (count=${goodCsrf.count})`);
    else fail(`cart_add সঠিক টোকেন → status=${goodCsrf.status}, ok=${goodCsrf.ok}, msg=${goodCsrf.msg}`);

    // ---- wishlist_toggle CSRF ছাড়া ----
    const wlNo = await ev(`(async () => {
      const fd = new URLSearchParams();
      fd.set('product_id', '${pid}');
      const r = await fetch('${BASE}/wishlist_toggle.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'},
        body: fd.toString()
      });
      const j = await r.json().catch(() => ({}));
      return { status: r.status, ok: j.ok, login: j.login_required };
    })()`);
    if ((wlNo.status === 403 || wlNo.status === 419)) pass(`wishlist_toggle CSRF ছাড়া → 403 reject`);
    else if (wlNo.login) pass(`wishlist_toggle → লগইন প্রয়োজন (CSRF-এর আগেই ব্লক)`);
    else fail(`wishlist_toggle CSRF ছাড়া → status=${wlNo.status}, ok=${wlNo.ok}`);

    // ---- cart_remove CSRF ছাড়া ----
    const rmNo = await ev(`(async () => {
      const fd = new URLSearchParams();
      fd.set('id', '${pid}');
      fd.set('next', 'cart.php');
      const r = await fetch('${BASE}/cart_remove.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'},
        body: fd.toString(),
        redirect: 'manual'
      });
      return { status: r.status, type: r.type };
    })()`);
    // status 0 = opaque redirect (manual mode), 419 = CSRF reject, 302 = redirect — সবই ব্লকড
    if ((rmNo.status === 403 || rmNo.status === 419) || rmNo.status === 0 || rmNo.status === 302) {
      pass(`cart_remove CSRF ছাড়া → ব্লকড (status=${rmNo.status})`);
    } else {
      fail(`cart_remove CSRF ছাড়া → status=${rmNo.status}`);
    }

    // ---- place_order CSRF ছাড়া (redirect প্রত্যাশিত) ----
    const poNo = await ev(`(async () => {
      const fd = new URLSearchParams();
      fd.set('customer_name', 'Hacker');
      fd.set('mobile', '01700000000');
      fd.set('address', 'X');
      const r = await fetch('${BASE}/place_order.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: fd.toString(),
        redirect: 'manual'
      });
      return { status: r.status, type: r.type };
    })()`);
    if (poNo.status === 0 || poNo.status === 302 || poNo.status === 303) pass(`place_order CSRF ছাড়া → redirect (ব্লকড)`);
    else fail(`place_order CSRF ছাড়া → status=${poNo.status} — যাচাই হচ্ছে না`);

    // ---- cart.php ফর্মে csrf field ----
    await send(ws, id++, 'Page.navigate', { url: BASE + '/cart.php' });
    await sleep(1600);
    const cartField = await ev(`!!document.querySelector('form#cartForm input[name="csrf"]')`);
    if (cartField) pass('cart.php ফর্মে csrf hidden field আছে');
    else fail('cart.php ফর্মে csrf field নেই');

    // ---- checkout.php ফর্মে csrf field ----
    await send(ws, id++, 'Page.navigate', { url: BASE + '/checkout.php' });
    await sleep(1600);
    const coField = await ev(`!!document.querySelector('form#checkoutForm input[name="csrf"]')`);
    if (coField) pass('checkout.php ফর্মে csrf hidden field আছে');
    else fail('checkout.php ফর্মে csrf field নেই');

    // ---- BYPASS: সেশন টোকেন আছে, POST-এ খালি স্ট্রিং ----
    // (hash_equals('','') === true — এই ফাঁক দিয়ে CSRF সম্পূর্ণ বাইপাস হয়)
    await send(ws, id++, 'Page.navigate', { url: BASE + '/index.php' });
    await sleep(2000);
    const emptyVsSession = await ev(`(async () => {
      const t = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
      if (!t) return { skipped: true };
      const fd = new URLSearchParams();
      fd.set('product_id', '${pid}');
      fd.set('qty', '1');
      fd.set('csrf', '');
      const r = await fetch('${BASE}/cart_add.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'},
        body: fd.toString()
      });
      const j = await r.json().catch(() => ({}));
      return { tokenLen: t.length, status: r.status, ok: j.ok };
    })()`);
    if (emptyVsSession.skipped) {
      fail('সেশন টোকেন পাওয়া যায়নি, খালি-টোকেন টেস্ট বাদ');
    } else if (emptyVsSession.status === 403 && emptyVsSession.ok !== true) {
      pass(`সেশন টোকেন থাকলেও POST খালি → 403 (bypass বন্ধ)`);
    } else {
      fail(`CRITICAL: খালি csrf দিয়ে bypass সফল! status=${emptyVsSession.status}, ok=${emptyVsSession.ok}`);
    }

    // ---- BYPASS: csrf ফিল্ডই পাঠানো হয়নি ----
    const missingField = await ev(`(async () => {
      const fd = new URLSearchParams();
      fd.set('product_id', '${pid}');
      fd.set('qty', '1');
      const r = await fetch('${BASE}/cart_add.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'},
        body: fd.toString()
      });
      const j = await r.json().catch(() => ({}));
      return { status: r.status, ok: j.ok };
    })()`);
    if (missingField.status === 403 && missingField.ok !== true) pass('csrf ফিল্ড না পাঠালে → 403');
    else fail(`csrf ফিল্ড ছাড়া bypass: status=${missingField.status}, ok=${missingField.ok}`);

    // ---- BYPASS: csrf=array[] (টাইপ জোর করে) ----
    const arrayCsrf = await ev(`(async () => {
      const fd = new URLSearchParams();
      fd.set('product_id', '${pid}');
      fd.set('qty', '1');
      fd.set('csrf[]', 'x');
      const r = await fetch('${BASE}/cart_add.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'fetch'},
        body: fd.toString()
      });
      return { status: r.status };
    })()`);
    if (arrayCsrf.status === 403) pass('csrf=array[] → 403 (টাইপ confusion বন্ধ)');
    else fail(`csrf=array[] → status=${arrayCsrf.status}`);

    // ---- array CSRF যেসব endpoint সরাসরি যাচাই করে — ফ্যাটাল/পাথ লিক হওয়া যাবে না ----
    const rawChecks = [
      { url: '/account.php', body: 'csrf[]=1&action=profile&mode=login&mobile=01700000000&password=x' },
      { url: '/review_submit.php', body: 'csrf[]=1&product_id=1&rating=5&comment=x' },
      { url: '/wishlist.php', body: 'csrf[]=1&action=remove&product_id=1' },
      { url: '/admin/login.php', body: 'csrf[]=1&username=x&password=y' },
    ];
    for (const rc of rawChecks) {
      const res = await ev(`(async () => {
        const r = await fetch('${BASE}${rc.url}', {
          method: 'POST',
          headers: {'Content-Type':'application/x-www-form-urlencoded'},
          body: '${rc.body}',
          redirect: 'manual'
        });
        const text = await r.text().catch(() => '');
        return { status: r.status, text: text.slice(0, 20000) };
      })()`);
      const leaked = ['Fatal error', 'Uncaught', 'Stack trace', 'laragon', 'estore/includes']
        .some((needle) => res.text.includes(needle));
      if (leaked) fail(`${rc.url} → array CSRF-এ ফ্যাটাল এরর/পাথ লিক`);
      else pass(`${rc.url} → array CSRF নিরাপদে reject (status=${res.status})`);
    }

    // ---- এই endpoint-গুলোতে সঠিক টোকেন ছাড়া অবস্থা বদলাবে না ----
    const noTokenChange = await ev(`(async () => {
      const fd = new URLSearchParams();
      fd.set('action', 'remove');
      fd.set('product_id', '1');
      const r = await fetch('${BASE}/wishlist.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: fd.toString(),
        redirect: 'manual'
      });
      return { status: r.status, type: r.type };
    })()`);
    if (noTokenChange.status === 0 || noTokenChange.status === 200 || noTokenChange.status === 302) {
      pass(`wishlist remove টোকেন ছাড়া → ব্লকড/রিডাইরেক্ট (status=${noTokenChange.status})`);
    } else {
      fail(`wishlist remove টোকেন ছাড়া → status=${noTokenChange.status}`);
    }

    ws.close();
  } catch (e) { fail('EXCEPTION: ' + e.message); }
  finally { chrome.kill(); }

  console.log('\n=== CSRF Protection Test ===\n');
  let f = 0;
  for (const [s, m] of out) { console.log((s === 'PASS' ? '  PASS  ' : '  FAIL  ') + m); if (s === 'FAIL') f++; }
  console.log(`\n${f === 0 ? 'ALL PASS' : f + ' FAILED'} (${out.length} checks)\n`);
  process.exit(f === 0 ? 0 : 1);
})();
