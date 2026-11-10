/**
 * tools/verify_discount.js — অ্যাডমিন অর্ডার ডিসকাউন্ট end-to-end (headless Chrome)
 * node tools/verify_discount.js [url]
 * নিজে একটি টেস্ট অর্ডার ([T]) বানায়, অ্যাডমিন সেশন দিয়ে ডিসকাউন্ট দেয়/বদলায়/ভুল মান পাঠায়, ক্রেতা ও অ্যাডমিন পেজ দেখে;
 * শেষে অর্ডার মুছে স্টক ফেরায়, সেশন মোছে। mysql CLI (root) ও php লাগে।
 */
const { spawn, execFileSync } = require('child_process');
const os = require('os');
const path = require('path');
const BASE = process.argv[2] || 'http://estore.test';
const PORT = Number(process.env.PORT_DS || 9493);
const CHROME = process.env.NAV_BROWSER || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const MYSQL = process.env.MYSQL_BIN || 'mysql';
const DB = process.env.DB_NAME || 'estore';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let pass = 0, fail = 0;
const ok = (m) => { pass++; console.log('  PASS  ' + m); };
const bad = (m) => { fail++; console.log('  FAIL  ' + m); };
const check = (c, m) => (c ? ok(m) : bad(m));
const sql = (q) => execFileSync(MYSQL, ['-uroot', '-N', '--default-character-set=utf8mb4', DB], { encoding: 'utf8', input: q + ';\n' }).trim();
const php = (...a) => execFileSync('php', a, { encoding: 'utf8', cwd: path.join(__dirname, '..') }).trim();
const near = (a, b) => Math.abs(Number(a) - Number(b)) < 0.005;

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
  const stock0 = Number(sql(`SELECT stock FROM products WHERE id=${PID}`));
  const DID = sql("SELECT id FROM delivery_districts WHERE name_en='Dhaka'");
  let OID = null, sid = null;

  const cleanup = () => {
    const step = (fn) => { try { fn(); } catch (e) { console.error('cleanup ত্রুটি: ' + String(e.message).split('\n')[0]); } };
    if (OID) step(() => { sql(`DELETE FROM order_items WHERE order_id=${OID}`); sql(`DELETE FROM orders WHERE id=${OID} AND customer_name LIKE '[T]%'`); });
    step(() => sql(`UPDATE products SET stock=${stock0} WHERE id=${PID}`));
    step(() => sql('ALTER TABLE orders AUTO_INCREMENT=1'));
    if (sid) step(() => php('tools/make_admin_session.php', 'destroy', sid));
  };

  const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, '--no-sandbox', '--disable-gpu', '--hide-scrollbars', '--user-data-dir=' + os.tmpdir() + '\\ds_prof_' + Date.now(), 'about:blank'], { stdio: 'ignore' });
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
    const go = async (p, w = 1000) => { await call('Page.navigate', { url: BASE + p }); await sleep(w); };
    const row = () => { const r = sql(`SELECT discount, IFNULL(discount_note,'<NULL>'), IFNULL(discount_by,'<NULL>'), subtotal, shipping_fee, total, customer_name FROM orders WHERE id=${OID}`).split('\t'); return { disc: Number(r[0]), note: r[1], by: r[2], sub: Number(r[3]), ship: Number(r[4]), total: Number(r[5]), name: r[6] }; };
    const consistent = (r) => near(r.total, Math.max(0, r.sub - r.disc + r.ship));
    const BN = /[০-৯]/;

    // ---------- টেস্ট অর্ডার বানাই (ক্রেতার চেকআউট দিয়ে) ----------
    await call('Emulation.setDeviceMetricsOverride', { width: 1326, height: 900, deviceScaleFactor: 1, mobile: false });
    await go('/'); await ev('localStorage.clear();1');
    await go(`/checkout.php?buy=${PID}&qty=3`, 1300);
    await ev(`(()=>{const s=document.getElementById('cDistrict');s.value='${DID}';s.dispatchEvent(new Event('change',{bubbles:true}));const f=document.getElementById('checkoutForm');f.customer_name.value='[T] Discount Test';f.mobile.value='01755555555';f.address.value='[T] addr';1})()`);
    await ev("document.querySelector('#checkoutForm button[type=submit]').click()"); await sleep(2200);
    const u = await ev('location.pathname + location.search');
    OID = sql(`SELECT id FROM orders WHERE public_token='${(u.match(/t=([a-f0-9]{32})/) || [])[1] || 'none'}'`);
    const TOK = sql(`SELECT public_token FROM orders WHERE id=${OID}`);
    check(!!OID, 'টেস্ট অর্ডার তৈরি (#' + OID + ')');
    if (!OID) throw new Error('অর্ডার তৈরি হয়নি');
    const r0 = row();
    check(r0.disc === 0 && r0.note === '<NULL>' && r0.by === '<NULL>', 'নতুন অর্ডারে ডিসকাউন্ট ০ (ডিফল্ট), কারণ/দাতা খালি');
    check(consistent(r0), `শুরুর মোট = সাবটোটাল + ডেলিভারি (${r0.sub} + ${r0.ship} = ${r0.total})`);

    sid = php('tools/make_admin_session.php', 'create').split('=').pop().trim();
    await call('Network.setCookie', { name: 'PHPSESSID', value: sid, domain: new URL(BASE).hostname, path: '/' });
    const editUrl = `/admin/order_edit.php?id=${OID}&return=`;

    console.log('\n=== অ্যাডমিন ফর্ম ===');
    await go(editUrl, 1500);
    const f0 = await ev(`(()=>({type:!!document.getElementById('discType'),val:document.getElementById('discVal').value,note:!!document.getElementById('discNote'),opts:[...document.getElementById('discType').options].map(o=>o.value+':'+o.textContent.trim()),hidden:document.getElementById('sumDiscount').hidden,min:document.getElementById('discVal').min,hint:document.getElementById('discHint').textContent.trim().length>0}))()`);
    check(f0.type && f0.note && f0.opts.join() === 'amount:৳ টাকা,percent:% শতাংশ', 'ডিসকাউন্ট ঘর: ধরন (৳ / %), মান, কারণ — ' + f0.opts.join(' '));
    check(f0.val === '' && f0.hidden && f0.min === '0', 'শুরুতে মান ফাঁকা, সারাংশে ডিসকাউন্ট সারি লুকানো, min=0');

    // ---------- ক) টাকা ----------
    console.log('\n=== ক) টাকায় ডিসকাউন্ট ===');
    const D1 = 100;
    await ev(`(()=>{const t=document.getElementById('discType'),v=document.getElementById('discVal');t.value='amount';t.dispatchEvent(new Event('change',{bubbles:true}));v.value='${D1}';v.dispatchEvent(new Event('input',{bubbles:true}));document.getElementById('discNote').value='[T] নিয়মিত ক্রেতা';1})()`);
    await sleep(200);
    const live1 = await ev(`(()=>({disc:document.getElementById('sumDiscount').textContent.trim(),hidden:document.getElementById('sumDiscount').hidden,tot:document.getElementById('sumTotal').textContent.trim(),upd:getComputedStyle(document.getElementById('updatePriceButton')).display!=='none',save:document.getElementById('saveButton').disabled}))()`);
    const fmt = (n) => '৳' + Math.round(n).toLocaleString('en-US');
    check(!live1.hidden && live1.disc === '−' + fmt(D1), `লাইভ: সারাংশে "${live1.disc}"`);
    check(live1.tot === fmt(r0.sub - D1 + r0.ship), `লাইভ মোট = ${fmt(r0.sub - D1 + r0.ship)} (${live1.tot})`);
    check(live1.upd && live1.save, '"Update Price" দেখায়, Save আপাতত বন্ধ (আগের নিয়ম)');
    await ev("document.getElementById('updatePriceButton').click()"); await sleep(1800);
    let r1 = row();
    check(near(r1.disc, D1), 'ডাটাবেসে ডিসকাউন্ট ' + r1.disc);
    check(near(r1.total, r0.sub - D1 + r0.ship) && consistent(r1), `মোট সার্ভারে নতুন করে হিসাব: ${r1.total}`);
    check(r1.note === '[T] নিয়মিত ক্রেতা' && r1.by === 'test', `কারণ ও দাতা সংরক্ষিত ("${r1.note}", দাতা: ${r1.by})`);
    check(near(r1.sub, r0.sub) && near(r1.ship, r0.ship), 'সাবটোটাল ও শিপিং ফি অপরিবর্তিত');
    const f1 = await ev(`(()=>({val:document.getElementById('discVal').value,type:document.getElementById('discType').value,by:document.body.innerText.includes('দিয়েছেন: test'),flash:(document.querySelector('.alert')||{}).textContent||''}))()`);
    check(f1.val === String(D1) && f1.type === 'amount', 'রিলোডে ফর্মে ডিসকাউন্ট ' + f1.val + ' বসে আছে');
    check(f1.by, '"দিয়েছেন: test" দেখায়');

    console.log('\n=== Save চাপলে ডিসকাউন্ট থাকে ===');
    await ev("document.querySelector('input[name=customer_name]').value='[T] Discount Test 2';1");
    await ev("document.getElementById('saveButton').click()"); await sleep(2000);
    const r1b = row();
    check(near(r1b.disc, D1) && r1b.name === '[T] Discount Test 2' && consistent(r1b), 'Save-এ নাম বদলাল, ডিসকাউন্ট ও মোট অক্ষত (' + r1b.disc + ' / ' + r1b.total + ')');

    // ---------- খ) শতাংশ ----------
    console.log('\n=== খ) শতাংশে ডিসকাউন্ট ===');
    await go(editUrl, 1400);
    await ev(`(()=>{const t=document.getElementById('discType'),v=document.getElementById('discVal');t.value='percent';t.dispatchEvent(new Event('change',{bubbles:true}));v.value='10';v.dispatchEvent(new Event('input',{bubbles:true}));1})()`);
    await sleep(200);
    const expectPct = Math.round(r0.sub * 10) / 100;
    const live2 = await ev("(()=>({disc:document.getElementById('sumDiscount').textContent.trim(),hint:document.getElementById('discHint').textContent.trim()}))()");
    check(live2.disc === '−' + fmt(expectPct) && live2.hint.includes('10%'), `লাইভ: ১০% = ${fmt(expectPct)}, হিন্ট "${live2.hint}"`);
    await ev("document.getElementById('updatePriceButton').click()"); await sleep(1800);
    const r2 = row();
    check(near(r2.disc, expectPct) && consistent(r2), `ডাটাবেসে ১০% = ${r2.disc} টাকা (শতাংশ নয়, টাকার অঙ্কই জমা)`);
    const f2 = await ev("(()=>({val:document.getElementById('discVal').value,type:document.getElementById('discType').value}))()");
    check(f2.type === 'amount' && near(f2.val, expectPct), 'রিলোডে টাকার ধরনে ' + f2.val + ' দেখায়');

    // ---------- গ) ভুল মান — সার্ভার ----------
    console.log('\n=== গ) ভুল/জাল মান (সার্ভারে যাচাই) ===');
    await go(editUrl, 1400);
    const post = (over, extra = '') => ev(`(async()=>{const form=document.querySelector('form[method=post], form');const fd=new FormData(form);fd.set('update_price','1');${over};const r=await fetch(location.href,{method:'POST',body:new URLSearchParams(fd),redirect:'follow'});const t=await r.text();const m=t.match(/alert-(success|danger)[^>]*>\\s*([^<]*)/);return {status:r.status,flash:m?m[2].trim():''}})()`);
    const base = row();
    let res = await post("fd.set('discount_type','percent');fd.set('discount_value','150')");
    check(near(row().disc, base.disc) && /১০০/.test(res.flash.normalize('NFC')) , 'শতাংশ ১৫০ → বাতিল, ডিসকাউন্ট অপরিবর্তিত; বার্তা: "' + res.flash + '"');
    res = await post("fd.set('discount_type','amount');fd.set('discount_value','-50')");
    check(near(row().disc, base.disc) && res.flash.length > 5, 'ঋণাত্মক (-৫০) → বাতিল; বার্তা: "' + res.flash + '"');
    res = await post("fd.set('discount_type','amount');fd.set('discount_value','abc')");
    check(near(row().disc, base.disc), 'অক্ষর ("abc") → বাতিল');
    res = await post("fd.set('discount_type','amount');fd.delete('discount_value');fd.append('discount_value[]','5')");
    check(near(row().disc, base.disc) && res.status === 200, 'অ্যারে (discount_value[]) → ক্র্যাশ নয়, বাতিল');
    res = await post("fd.set('discount_type','amount');fd.set('discount_value','99999999')");
    check(near(row().disc, base.disc), 'সীমার বেশি (৯৯,৯৯,৯৯,৯৯৯) → বাতিল');
    res = await post("fd.set('discount_type','amount');fd.set('discount_value','999999')");
    const r3 = row();
    check(near(r3.disc, r3.sub) && near(r3.total, r3.ship) && consistent(r3), `সাবটোটালের বেশি (৯,৯৯,৯৯৯) → সাবটোটালে (${r3.sub}) ছাঁটা, মোট = শুধু ডেলিভারি ${r3.total}`);
    check(res.flash.normalize('NFC').includes('সমান'), 'ছাঁটার বার্তা: "' + res.flash + '"');
    res = await post("fd.set('discount_type','percent');fd.set('discount_value','100')");
    const r3b = row();
    check(near(r3b.disc, r3b.sub) && r3b.total >= 0, '১০০% → পুরো সাবটোটাল, মোট ঋণাত্মক নয় (' + r3b.total + ')');
    res = await post("fd.set('discount_type','amount');fd.set('discount_value','50');fd.set('discount_note','x'.repeat(400))");
    const r3c = row();
    check(near(r3c.disc, 50) && r3c.note.length === 160, 'কারণ ১৬০ অক্ষরে কাটা (' + r3c.note.length + ')');
    const tok = await ev("document.querySelector('input[name=csrf]').value");
    const noCsrf = await ev(`(async()=>{const fd=new FormData(document.querySelector('form'));fd.delete('csrf');fd.set('update_price','1');fd.set('discount_type','amount');fd.set('discount_value','7');await fetch(location.href,{method:'POST',body:new URLSearchParams(fd)});return 1})()`);
    check(near(row().disc, 50), 'CSRF টোকেন ছাড়া অনুরোধে ডিসকাউন্ট বদলায় না');

    // ---------- ঘ) ক্রেতা ও অ্যাডমিনের পেজে দেখানো ----------
    console.log('\n=== ঘ) পেজে দেখানো ===');
    await go(editUrl, 1200);
    await ev(`(()=>{const t=document.getElementById('discType'),v=document.getElementById('discVal');t.value='amount';t.dispatchEvent(new Event('change',{bubbles:true}));v.value='${D1}';v.dispatchEvent(new Event('input',{bubbles:true}));document.getElementById('discNote').value='[T] গোপন কারণ';1})()`);
    await ev("document.getElementById('updatePriceButton').click()"); await sleep(1800);
    const fin = row();
    check(near(fin.disc, D1) && consistent(fin), 'শেষ অবস্থা: ডিসকাউন্ট ' + fin.disc + ', মোট ' + fin.total);

    sql(`UPDATE orders SET customer_id=4 WHERE id=${OID}`);
    const CSID = require('crypto').randomBytes(13).toString('hex');
    require('fs').writeFileSync(`C:/laragon/tmp/sess_${CSID}`, `customer_id|i:4;csrf|s:32:"${'a'.repeat(32)}";__sid_rotated|i:${Math.floor(Date.now() / 1000)};`);
    await call('Network.setCookie', { name: 'PHPSESSID', value: CSID, domain: new URL(BASE).hostname, path: '/' });
    for (const w of [1326, 390]) {
      await call('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 600 });
      await go(`/single_order_details.php?t=${TOK}`, 1500);
      const c = await ev(`(()=>{const o=document.getElementById('odv');const txt=o.innerText;const rows=[...o.querySelectorAll('.disc-val')].filter(e=>e.offsetParent!==null).map(e=>e.textContent.trim());return {txt,rows,hs:document.documentElement.scrollWidth>innerWidth+1}})()`);
      check(c.rows.length >= 1 && c.rows.every((x) => x === '−' + fmt(D1)), `ক্রেতার পেজ (${w}px): "ডিসকাউন্ট ${c.rows[0]}" দেখায়`);
      check(!c.txt.includes('গোপন কারণ') && !c.txt.includes('দিয়েছেন'), `ক্রেতার পেজে কারণ/দাতার নাম দেখায় না (${w}px)`);
      check(!BN.test(c.txt) && !c.hs, `বাংলা অঙ্ক/overflow নেই (${w}px)`);
      const sumOk = await ev(`(()=>{const t=document.getElementById('odv').innerText.replace(/[৳,−]/g,'');return t.includes('${Math.round(fin.total)}')})()`);
      check(sumOk, `মোট ${fmt(fin.total)} পেজে আছে (${w}px)`);
    }
    await call('Network.setCookie', { name: 'PHPSESSID', value: sid, domain: new URL(BASE).hostname, path: '/' });
    await call('Emulation.setDeviceMetricsOverride', { width: 1326, height: 900, deviceScaleFactor: 1, mobile: false });
    await go(`/admin/order_view.php?id=${OID}`, 1300);
    const av = await ev('document.body.innerText');
    check(av.includes('ডিসকাউন্ট') && av.includes('গোপন কারণ') && av.includes('দিয়েছেন: test'), 'অ্যাডমিন order_view: ডিসকাউন্ট + কারণ + দাতা');
    await go(`/admin/order_print.php?id=${OID}`, 1300);
    const ap = await ev('document.body.innerText');
    check(ap.includes('ডিসকাউন্ট') && ap.includes(fmt(D1).replace('৳', '')), 'অ্যাডমিন প্রিন্ট: ডিসকাউন্ট লাইন');

    console.log('\n=== ঙ) ডিসকাউন্ট তুলে নেওয়া ===');
    await go(editUrl, 1300);
    await ev(`(()=>{const v=document.getElementById('discVal');v.value='';v.dispatchEvent(new Event('input',{bubbles:true}));1})()`);
    const live0 = await ev("document.getElementById('sumDiscount').hidden");
    check(live0 === true, 'মান মুছলে সারাংশের ডিসকাউন্ট সারি লুকায়');
    await ev("document.getElementById('updatePriceButton').click()"); await sleep(1800);
    const r5 = row();
    check(r5.disc === 0 && r5.note === '<NULL>' && r5.by === '<NULL>' && consistent(r5) && near(r5.total, r0.sub + r0.ship), 'ফাঁকা করলে ডিসকাউন্ট ০, কারণ/দাতা মুছে, মোট আগের অবস্থায় ফিরল (' + r5.total + ')');
    await call('Network.setCookie', { name: 'PHPSESSID', value: CSID, domain: new URL(BASE).hostname, path: '/' });
    await go(`/single_order_details.php?t=${TOK}`, 1300);
    check(!(await ev("document.getElementById('odv').innerText")).includes('ডিসকাউন্ট'), 'ডিসকাউন্ট ০ হলে ক্রেতার পেজে সারিই আসে না');
    const OLDTOK = sql(`SELECT public_token FROM orders WHERE id<>${OID} AND mobile=(SELECT mobile FROM customers WHERE id=4) AND discount=0 ORDER BY id DESC LIMIT 1`);
    await go(`/single_order_details.php?t=${OLDTOK}`, 1300);
    check(!(await ev("document.getElementById('odv').innerText")).includes('ডিসকাউন্ট'), 'পুরোনো অর্ডারে (ডিসকাউন্ট ছাড়া) পেজ আগের মতো, নতুন সারি নেই');

    try { require('fs').unlinkSync(`C:/laragon/tmp/sess_${CSID}`); } catch {}
    ws.close();
  } catch (e) { console.error('ERROR: ' + e.message); fail++; }
  finally { chrome.kill(); cleanup(); }

  const left = sql("SELECT COUNT(*) FROM orders WHERE customer_name LIKE '[T]%'");
  console.log(left === '0' ? '  (টেস্ট অর্ডার মুছে ফেলা হয়েছে, স্টক ফেরানো, সেশন মোছা)' : `  সতর্কতা: ${left}টি [T] অর্ডার রয়ে গেছে`);
  console.log((fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exitCode = fail === 0 ? 0 : 1;
})();
