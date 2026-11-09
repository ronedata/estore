/**
 * tools/verify_cart_qty.js — কার্টের পরিমাণ সেভ ও চেকআউটে যাওয়া + চেকআউট সামারির লেখা যাচাই (headless Chrome)
 * node tools/verify_cart_qty.js [url]
 * ডাটাবেস বদলায় না (শুধু ব্রাউজারের সেশন-কার্ট); mysql CLI দিয়ে শুধু পণ্যের দাম/স্টক পড়ে।
 */
const { spawn, execFileSync } = require('child_process');
const os = require('os');
const BASE = process.argv[2] || 'http://estore.test';
const PORT = Number(process.env.PORT_CQ || 9487);
const CHROME = process.env.NAV_BROWSER || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const MYSQL = process.env.MYSQL_BIN || 'mysql';
const DB = process.env.DB_NAME || 'estore';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let pass = 0, fail = 0;
const ok = (m) => { pass++; console.log('  PASS  ' + m); };
const bad = (m) => { fail++; console.log('  FAIL  ' + m); };
const check = (c, m) => (c ? ok(m) : bad(m));
const sql = (q) => execFileSync(MYSQL, ['-uroot', '-N', '--default-character-set=utf8mb4', DB], { encoding: 'utf8', input: q + ';\n' }).trim();

function send(ws, id, method, params = {}) {
  return new Promise((resolve, reject) => {
    const to = setTimeout(() => reject(new Error('timeout: ' + method)), 20000);
    const onMsg = (ev) => {
      let m; try { m = JSON.parse(ev.data); } catch { return; }
      if (m.id === id) { clearTimeout(to); ws.removeEventListener('message', onMsg); m.error ? reject(new Error(JSON.stringify(m.error))) : resolve(m.result); }
    };
    ws.addEventListener('message', onMsg);
    ws.send(JSON.stringify({ id, method, params }));
  });
}

(async () => {
  const PID = sql("SELECT id FROM products WHERE active=1 AND stock>10 AND (tags IS NULL OR tags NOT LIKE '%free%') ORDER BY id LIMIT 1");
  const price = Number(sql(`SELECT price FROM products WHERE id=${PID}`));
  const stock = Number(sql(`SELECT stock FROM products WHERE id=${PID}`));
  const money = (n) => '৳' + Math.round(n).toLocaleString('en-US');

  const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, '--no-sandbox', '--disable-gpu', '--hide-scrollbars', '--user-data-dir=' + os.tmpdir() + '\\cq_prof_' + Date.now(), 'about:blank'], { stdio: 'ignore' });
  try {
    let ready = false;
    for (let i = 0; i < 40; i++) { try { await fetch(`http://127.0.0.1:${PORT}/json/version`); ready = true; break; } catch { await sleep(250); } }
    if (!ready) throw new Error('DevTools নেই');
    const t = await fetch(`http://127.0.0.1:${PORT}/json/new?about:blank`, { method: 'PUT' }).then((r) => r.json());
    const ws = new WebSocket(t.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.addEventListener('open', res, { once: true }); ws.addEventListener('error', rej, { once: true }); });
    let id = 1;
    const call = (m, p) => send(ws, id++, m, p);
    await call('Page.enable'); await call('Runtime.enable'); await call('Network.enable'); await call('Network.setCacheDisabled', { cacheDisabled: true });
    const ev = async (expr) => {
      const r = await call('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
      if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
      return r.result.value;
    };
    const go = async (path, wait = 900) => { await call('Page.navigate', { url: BASE + path }); await sleep(wait); };
    const qtyVal = () => ev("document.querySelector('#cartForm .qtyInput').value");
    const plus = (n) => ev(`(()=>{const b=document.querySelector('#cartForm .qty-box button:last-child');for(let i=0;i<${n};i++)b.click();return 1})()`);
    const minus = (n) => ev(`(()=>{const b=document.querySelector('#cartForm .qty-box button:first-child');for(let i=0;i<${n};i++)b.click();return 1})()`);

    await call('Emulation.setDeviceMetricsOverride', { width: 1326, height: 800, deviceScaleFactor: 1, mobile: false });
    await go('/'); await ev('localStorage.clear();1');

    console.log('\n=== কার্ট: পরিমাণ বাড়ালে সেভ হয় ===');
    await go(`/checkout.php?buy=${PID}&qty=1`);
    await go('/cart.php');
    check(await qtyVal() === '1', 'শুরুতে কার্টে পরিমাণ ১');
    await plus(4);
    check(await qtyVal() === '5', '+ চেপে ৫ করা হলো');
    check(await ev("document.getElementById('cartSubtotal').textContent.trim()") === money(price * 5), `সাবটোটাল পেজে সঙ্গে সঙ্গে হালনাগাদ (${money(price * 5)})`);
    await sleep(1000);
    await go('/cart.php');
    check(await qtyVal() === '5', '"আপডেট" বাটন না চেপেই পেজ রিলোডে পরিমাণ ৫ই আছে (স্বয়ংক্রিয় সেভ)');
    check(await ev("document.getElementById('cartSubtotal').textContent.trim()") === money(price * 5), 'রিলোডে সাবটোটালও ঠিক');

    console.log('\n=== কার্ট: "চেকআউট করুন" চাপলে নতুন পরিমাণ যায় ===');
    await plus(1);
    check(await qtyVal() === '6', 'আরেকবার + (৬)');
    await ev("document.getElementById('cartCheckoutBtn').click()"); await sleep(1500);
    check(await ev('location.pathname') === '/checkout.php', 'চেকআউট পেজে গেল');
    const co = await ev(`(()=>({sub:document.getElementById('sumSubtotal').textContent.trim(),dataSub:document.getElementById('sumSubtotal').dataset.subtotal,qty:document.querySelector('#coSummary .co-it-qty').textContent.trim(),line:document.querySelector('#coSummary .co-it-price').textContent.trim()}))()`);
    check(Number(co.dataSub) === price * 6, `চেকআউটে সাবটোটাল ${price} × ৬ = ${price * 6} (আগে ১ যেত)`);
    check(co.qty.includes('6') && co.qty.includes('×'), 'সামারিতে "' + co.qty + '"');
    check(co.line === money(price * 6), 'লাইন-মোট ' + co.line);

    console.log('\n=== চেকআউট সামারির লেখা ও ফন্ট ===');
    const txt = await ev("document.getElementById('coSummary').innerText");
    check(!/[০-৯]/.test(txt), 'সামারিতে বাংলা অঙ্ক নেই (সাইটের বাকি অংশের মতো ইংরেজি অঙ্ক)');
    check(!/[\u066C\u066B]/.test(txt), 'সামারিতে আরবি বিভাজক "٬" নেই (এটাই ভাঙা ফন্টের কারণ ছিল)');
    check(/৳[\d,]+/.test(txt), 'টাকার চিহ্নসহ সংখ্যা: ' + (txt.match(/৳[\d,]+/) || [''])[0]);
    const fs = await ev(`(()=>{const g=s=>parseFloat(getComputedStyle(document.querySelector(s)).fontSize);return {name:g('#coSummary .co-it-name'),qty:g('#coSummary .co-it-qty'),price:g('#coSummary .co-it-price'),row:g('#coSummary .sum-row'),total:g('#coSummary .sum-row.total'),qtyColor:getComputedStyle(document.querySelector('#coSummary .co-it-qty')).color}})()`);
    check(fs.qty >= 12.5, `পরিমাণ-লেখা পড়ার মতো বড় (${fs.qty}px, আগে ১১.৫px)`);
    check(fs.qty < fs.name && fs.price >= 14 && fs.total > fs.row, `ফন্ট-ক্রম ঠিক: নাম ${fs.name} > পরিমাণ ${fs.qty}; মূল্য ${fs.price}; মোট ${fs.total} > সারি ${fs.row}`);
    check(fs.qtyColor !== 'rgb(148, 163, 184)', 'পরিমাণ-লেখা আর আগের মতো ফ্যাকাশে নয় (' + fs.qtyColor + ')');
    await ev("(()=>{const s=document.getElementById('cDistrict');s.value=s.options[2].value;s.dispatchEvent(new Event('change',{bubbles:true}));1})()"); await sleep(200);
    const dyn = await ev("document.getElementById('sumShip').textContent.trim()+' | '+document.getElementById('sumTotal').textContent.trim()");
    check(!/[০-৯٬]/.test(dyn) && /৳[\d,]+ \| ৳[\d,]+/.test(dyn), 'JS-এ হালনাগাদ হওয়া চার্জ/মোটও ইংরেজি অঙ্কে: ' + dyn);
    const fontsUsed = await (async () => {
      await call('DOM.enable'); await call('CSS.enable');
      const doc = await call('DOM.getDocument', { depth: -1 });
      const out = [];
      for (const sel of ['#sumSubtotal', '#sumTotal', '#coSummary .co-it-price', '#coSummary .co-it-qty']) {
        const n = await call('DOM.querySelector', { nodeId: doc.root.nodeId, selector: sel });
        const f = await call('CSS.getPlatformFontsForNode', { nodeId: n.nodeId });
        out.push(...f.fonts.map((x) => x.familyName));
      }
      return [...new Set(out)];
    })();
    check(!fontsUsed.some((n) => /Times|Segoe|Arial|Nirmala/i.test(n)), 'সামারির সংখ্যায় ফলব্যাক ফন্ট (Times/Segoe) নেই; ব্যবহৃত: ' + fontsUsed.join(', '));

    console.log('\n=== কার্ট: অন্যান্য আচরণ ===');
    await go('/cart.php');
    check(await qtyVal() === '6', 'চেকআউট থেকে ফিরে কার্টে ৬ই আছে');
    await minus(2);
    check(await qtyVal() === '4', '− চেপে ৪');
    await sleep(900); await go('/cart.php');
    check(await qtyVal() === '4', '− করলেও সেভ হয় (রিলোডে ৪)');
    await ev("(()=>{const i=document.querySelector('#cartForm .qtyInput');i.value='99999';i.dispatchEvent(new Event('change',{bubbles:true}));1})()"); await sleep(900);
    const cap = await qtyVal();
    check(Number(cap) === stock, `স্টকের (${stock}) বেশি লিখলে স্টকেই আটকায় (${cap})`);
    await go('/cart.php');
    check(Number(await qtyVal()) === stock, 'রিলোডেও স্টকের সীমায়');
    await ev("(()=>{const i=document.querySelector('#cartForm .qtyInput');i.value='3';i.dispatchEvent(new Event('change',{bubbles:true}));1})()");
    await ev("document.getElementById('cartUpdateBtn').click()"); await sleep(1200);
    check(await ev('location.pathname') === '/cart.php' && await qtyVal() === '3', '"পরিমাণ আপডেট করুন" বাটনও আগের মতো কাজ করে (৩)');
    const forged = await ev(`fetch('/cart_update.php',{method:'POST',body:new URLSearchParams({'qty[${PID}]':'50'}),redirect:'manual'}).then(r=>r.status)`);
    await go('/cart.php');
    check(await qtyVal() === '3', 'CSRF টোকেন ছাড়া জাল অনুরোধে পরিমাণ বদলায় না (' + forged + ')');

    console.log('\n=== কার্ট: দুটি পণ্য — সারির দাম ও হেডার ব্যাজ লাইভ ===');
    const PID2 = sql(`SELECT id FROM products WHERE active=1 AND stock>10 AND id<>${PID} AND (tags IS NULL OR tags NOT LIKE '%free%') ORDER BY id LIMIT 1`);
    await go('/cart.php');
    await ev(`fetch('/cart_add.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf:document.querySelector('meta[name=csrf-token]').content,product_id:'${PID2}',qty:'1'})}).then(r=>r.json())`);
    await go('/cart.php');
    const rows = () => ev(`[...document.querySelectorAll('#cartForm .cart-row')].map(r=>({unit:r.querySelector('.cr-price').textContent.trim(),line:r.querySelector('.cr-line').textContent.trim(),qty:r.querySelector('.qtyInput').value,price:Number(r.querySelector('.qtyInput').dataset.price)}))`);
    const badge = () => ev("(document.getElementById('cartCount')||{}).textContent");
    let rw = await rows();
    check(rw.length === 2, 'কার্টে দুটি পণ্য');
    check(rw.every((r) => r.unit === money(r.price)), 'সারিতে ইউনিট-দাম দেখায় (' + rw.map((r) => r.unit).join(', ') + ') — আগে লাইন-মোট দেখাত');
    check(rw.every((r) => r.line === money(r.price * Number(r.qty))), 'আলাদা লাইন-মোট দেখায় (' + rw.map((r) => r.line).join(', ') + ')');
    const kinds = rw.length;
    const unitsAll = rw.reduce((a, r) => a + Number(r.qty), 0);
    check(await badge() === String(kinds), `হেডার ব্যাজ = আলাদা পণ্যের সংখ্যা (${kinds}), পরিমাণের যোগফল (${unitsAll}) নয়`);
    await ev("(()=>{const b=document.querySelectorAll('#cartForm .qty-box')[1].querySelector('button:last-child');for(let i=0;i<4;i++)b.click();return 1})()");
    rw = await rows();
    const exp = rw[1].price * 5;
    check(rw[1].qty === '5' && rw[1].line === money(exp), `+ চাপতেই লাইন-মোট সঙ্গে সঙ্গে বদলায় → ${rw[1].line} (${rw[1].price} × ৫)`);
    check(rw[1].unit === money(rw[1].price), 'ইউনিট-দাম অপরিবর্তিত থাকে (' + rw[1].unit + ')');
    check(rw[0].line === money(rw[0].price * Number(rw[0].qty)), 'অন্য সারির লাইন-মোট ছোঁয়া হয়নি');
    const units1 = rw.reduce((a, r) => a + Number(r.qty), 0);
    check(units1 > kinds && await badge() === String(kinds), `পরিমাণ বাড়ালেও (মোট ${units1}টি) ব্যাজ অপরিবর্তিত = ${kinds}`);
    check(await ev("document.getElementById('cartSubtotal').textContent.trim()") === money(rw.reduce((a, r) => a + r.price * Number(r.qty), 0)), 'সাবটোটাল = সব লাইনের যোগফল');
    await sleep(1100);
    check(await badge() === String(kinds), 'সার্ভার থেকে আনা ব্যাজও একই (' + kinds + ')');
    await go('/cart.php');
    rw = await rows();
    check(rw[1].qty === '5' && rw[1].line === money(rw[1].price * 5) && await badge() === String(kinds), 'রিলোডের পরেও সারি ও ব্যাজ একই');
    await ev("(()=>{const b=document.querySelectorAll('#cartForm .qty-box')[1].querySelector('button:first-child');for(let i=0;i<4;i++)b.click();return 1})()");
    check(await badge() === String(kinds) && (await rows())[1].qty === '1', '− চাপলেও ব্যাজ অপরিবর্তিত');
    const addJson = (pid) => ev(`fetch('/cart_add.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf:document.querySelector('meta[name=csrf-token]').content,product_id:'${pid}',qty:'1'})}).then(r=>r.json())`);
    const again = await addJson(PID);
    check(again.count === kinds, `একই পণ্য আবার কার্টে যোগ করলে ব্যাজ বাড়ে না (সার্ভারের উত্তর count=${again.count})`);
    const PID3 = sql(`SELECT id FROM products WHERE active=1 AND stock>10 AND id NOT IN (${PID},${PID2}) AND (tags IS NULL OR tags NOT LIKE '%free%') ORDER BY id LIMIT 1`);
    const added = await addJson(PID3);
    check(added.count === kinds + 1, `নতুন আলাদা পণ্য যোগ করলে ব্যাজ ${kinds} → ${added.count}`);
    const cnt = await ev("fetch('/cart_count.php').then(r=>r.json())");
    check(cnt.count === kinds + 1, 'cart_count.php একই সংখ্যা দেয় (' + cnt.count + ')');
    await go('/cart.php');
    check(await badge() === String(kinds + 1) && await ev("(document.getElementById('bnCartCount')||{}).textContent") === String(kinds + 1), 'হেডার ও নিচের নেভিগেশন (bottom-nav) দুই ব্যাজেই ' + (kinds + 1));
    await ev(`(async()=>{const tok=document.querySelector('meta[name=csrf-token]').content;await fetch('/cart_remove.php',{method:'POST',headers:{'X-Requested-With':'fetch','Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf:tok,product_id:'${PID3}'})});1})()`);
    await go('/cart.php');
    check(await badge() === String(kinds), 'অতিরিক্ত পণ্য সরালে ব্যাজ আবার ' + kinds);
    await sleep(900);

    console.log('\n=== কার্ট: "সরান" বাটন ===');
    await go('/cart.php');
    const rb = await ev(`(()=>{const b=document.querySelector('.cart-row .cr-remove'),r=b.getBoundingClientRect(),c=getComputedStyle(b);return {h:Math.round(r.height),w:Math.round(r.width),radius:c.borderRadius,bg:c.backgroundColor,color:c.color,border:c.borderTopColor,icon:!!b.querySelector('i'),txt:b.textContent.trim(),label:b.getAttribute('aria-label')||'',cursor:c.cursor}})()`);
    check(rb.h >= 34, `বাটনের উচ্চতা ${rb.h}px (আঙুলে চাপার মতো; আগে ~১৮px)`);
    check(rb.bg === 'rgb(254, 242, 242)' && rb.color === 'rgb(220, 38, 38)', 'নরম-লাল পিল: ব্যাকগ্রাউন্ড ' + rb.bg + ', লেখা ' + rb.color);
    check(parseFloat(rb.radius) >= 17, 'পিল আকার (radius ' + rb.radius + ')');
    check(rb.icon && rb.txt === 'সরান', 'ট্র্যাশ আইকন + "সরান" লেখা');
    check(rb.label.includes('সরান') && rb.label.length > 8, 'স্ক্রিন-রিডারের জন্য পণ্যের নামসহ aria-label: ' + rb.label);
    check(rb.cursor === 'pointer', 'কার্সর pointer');
    await ev("document.querySelector('.cart-row .cr-title').focus()");
    await call('Input.dispatchKeyEvent', { type: 'rawKeyDown', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
    await call('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Tab', code: 'Tab', windowsVirtualKeyCode: 9 });
    await sleep(150);
    const ring = await ev("(()=>{const b=document.querySelector('.cart-row .cr-remove');return {on:document.activeElement===b,vis:b.matches(':focus-visible'),shadow:getComputedStyle(b).boxShadow}})()");
    check(ring.on && ring.vis && ring.shadow !== 'none', 'আসল Tab চাপলে "সরান"-এ ফোকাস আসে ও রিং দেখায় (' + ring.shadow.slice(0, 28) + '…)');
    const nBefore = (await rows()).length;
    check(nBefore === 2, 'সরানোর আগে ২টি পণ্য');
    await ev("(()=>{const b=document.querySelectorAll('#cartForm .qty-box')[1].querySelector('button:last-child');b.click();document.querySelectorAll('.cart-row .cr-remove')[1].click();return 1})()");
    const busy = await ev("(()=>{const b=document.querySelectorAll('.cart-row .cr-remove')[1];return {dis:b.disabled,txt:b.textContent.trim(),spin:!!b.querySelector('.fa-spin')}})()");
    check(busy.dis && busy.spin && busy.txt.includes('সরানো হচ্ছে'), 'চাপার সাথে সাথে "সরানো হচ্ছে…" ও নিষ্ক্রিয় (দুবার চাপা যায় না)');
    await sleep(1800);
    const after = await rows();
    check(after.length === 1, 'দ্বিতীয় পণ্য কার্ট থেকে সরে গেল');
    await sleep(900);
    await go('/cart.php');
    check((await rows()).length === 1, 'পরিমাণ বদলানোর সাথে সাথেই সরালেও পণ্য আবার ফিরে আসে না (স্বয়ংক্রিয় সেভ বাতিল)');
    check(await badge() === String((await rows()).length), 'সরানোর পর হেডার ব্যাজ = বাকি পণ্যের সংখ্যা');

    console.log('\n=== মোবাইল ৩৯০ ===');
    await call('Emulation.setDeviceMetricsOverride', { width: 390, height: 800, deviceScaleFactor: 1, mobile: true });
    await go('/cart.php');
    const m = await ev(`(()=>{const b=document.getElementById('cartCheckoutBtn').getBoundingClientRect();return {w:Math.round(b.width),h:Math.round(b.height),inView:b.left>=0&&b.right<=innerWidth,hs:document.documentElement.scrollWidth>innerWidth+1,bg:getComputedStyle(document.getElementById('cartCheckoutBtn')).backgroundImage!=='none'||getComputedStyle(document.getElementById('cartCheckoutBtn')).backgroundColor!=='rgba(0, 0, 0, 0)'}})()`);
    check(m.inView && m.w > 250 && m.h >= 44, `চেকআউট বাটন পূর্ণ প্রস্থ (${m.w}×${m.h}px)`);
    check(!m.hs, 'কার্টে horizontal overflow নেই');
    check(m.bg, 'বাটনে ব্যাকগ্রাউন্ড রং আছে (লিংকের মতোই)');
    await go(`/checkout.php`);
    const mc = await ev(`(()=>{const s=document.getElementById('coSummary').getBoundingClientRect();return {inView:s.left>=0&&s.right<=innerWidth,hs:document.documentElement.scrollWidth>innerWidth+1}})()`);
    check(mc.inView && !mc.hs, 'চেকআউট সামারি মোবাইলে স্ক্রিনের ভেতরে, overflow নেই');

    ws.close();
  } catch (e) { console.error('ERROR: ' + e.message); fail++; }
  finally { chrome.kill(); }

  console.log((fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exitCode = fail === 0 ? 0 : 1;
})();
