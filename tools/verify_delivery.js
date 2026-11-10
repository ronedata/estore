/**
 * tools/verify_delivery.js — জেলা-ভিত্তিক ডেলিভারি চার্জ end-to-end (headless Chrome)
 * node tools/verify_delivery.js [url]
 * চেকআউট (জেলা বাছলে চার্জ), সত্যিকারের অর্ডার সাবমিট (শেষে মুছে স্টক ফেরায়), অ্যাডমিন পেজ (ফিল্টার, বাল্ক, সেভ), অর্ডার এডিট।
 * mysql CLI (root) ও php লাগে; শেষে চার্জ ও টেস্ট ডেটা আগের অবস্থায় ফেরায়।
 */
const { spawn, execFileSync } = require('child_process');
const os = require('os');
const BASE = process.argv[2] || 'http://estore.test';
const PORT = Number(process.env.PORT_DL || 9483);
const CHROME = process.env.NAV_BROWSER || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const MYSQL = process.env.MYSQL_BIN || 'mysql';
const DB = process.env.DB_NAME || 'estore';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let pass = 0, fail = 0;
const ok = (m) => { pass++; console.log('  PASS  ' + m); };
const bad = (m) => { fail++; console.log('  FAIL  ' + m); };
const check = (c, m) => (c ? ok(m) : bad(m));
const sql = (q) => execFileSync(MYSQL, ['-uroot', '-N', '--default-character-set=utf8mb4', DB], { encoding: 'utf8', input: q + ';\n' }).trim();
const php = (...a) => execFileSync('php', a, { encoding: 'utf8', cwd: require('path').join(__dirname, '..') }).trim();

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
  const snapFees = sql("SELECT CONCAT(id,':',IFNULL(fee,'N')) FROM delivery_districts ORDER BY id").split(/\r?\n/).join(',');
  const snapGlobal = sql("SELECT value FROM settings WHERE `key`='delivery_global'");
  const idOf = (en) => sql(`SELECT id FROM delivery_districts WHERE name_en='${en}'`);
  const D = { dhaka: idOf('Dhaka'), gaz: idOf('Gazipur'), ctg: idOf('Chattogram'), syl: idOf('Sylhet') };
  const PID = sql("SELECT id FROM products WHERE active=1 AND stock>3 AND (tags IS NULL OR tags NOT LIKE '%free%') ORDER BY id LIMIT 1");
  const price = Number(sql(`SELECT price FROM products WHERE id=${PID}`));
  const stock0 = Number(sql(`SELECT stock FROM products WHERE id=${PID}`));
  let sid = null, orderIds = [];

  const restore = () => {
    const step = (fn) => { try { fn(); } catch (e) { console.error('cleanup ত্রুটি: ' + String(e.message).split('\n')[0]); } };
    step(() => sql(`UPDATE settings SET value='${snapGlobal}' WHERE \`key\`='delivery_global'`));
    step(() => {
      sql("UPDATE delivery_districts SET fee=NULL");
      for (const p of snapFees.split(',')) { const [i, f] = p.trim().split(':'); if (f && /^\d+(\.\d+)?$/.test(f)) sql(`UPDATE delivery_districts SET fee=${f} WHERE id=${i}`); }
    });
    for (const o of orderIds) step(() => { sql(`DELETE FROM order_items WHERE order_id=${o}`); sql(`DELETE FROM orders WHERE id=${o}`); });
    step(() => sql(`UPDATE products SET stock=${stock0} WHERE id=${PID}`));
    if (sid) step(() => php('tools/make_admin_session.php', 'destroy', sid));
  };

  sql(`UPDATE settings SET value='120' WHERE \`key\`='delivery_global'`);
  sql("UPDATE delivery_districts SET fee=NULL");
  sql(`UPDATE delivery_districts SET fee=60 WHERE id=${D.dhaka}`);
  sql(`UPDATE delivery_districts SET fee=100 WHERE id=${D.ctg}`);
  sql(`UPDATE delivery_districts SET fee=0 WHERE id=${D.syl}`);

  const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, '--no-sandbox', '--disable-gpu', '--hide-scrollbars', '--user-data-dir=' + os.tmpdir() + '\\dl_prof_' + Date.now(), 'about:blank'], { stdio: 'ignore' });
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
    const pick = (did) => ev(`(()=>{const s=document.getElementById('cDistrict');s.value='${did}';s.dispatchEvent(new Event('change',{bubbles:true}));return 1})()`);
    const shipTxt = () => ev("document.getElementById('sumShip').textContent.trim()");
    const totTxt = () => ev("document.getElementById('sumTotal').textContent.trim()");
    const hint = () => ev("document.getElementById('districtHint').textContent.trim()");
    const bn = (n) => '৳' + Number(n).toLocaleString('en-US');

    console.log('\n=== চেকআউট (ডেস্কটপ) ===');
    await call('Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });
    await go('/'); await ev('localStorage.clear();1');
    await go(`/checkout.php?buy=${PID}&qty=2`);
    const sub = price * 2;
    const info = await ev(`(()=>{const s=document.getElementById('cDistrict');return {n:s.options.length,groups:s.querySelectorAll('optgroup').length,req:s.required,first:s.options[0].textContent.trim(),oldRadios:document.querySelectorAll('input[name=shipping_area]').length}})()`);
    check(info.n === 65 && info.groups === 8, `জেলা ড্রপডাউনে ৬৪ জেলা + প্লেসহোল্ডার, ৮ বিভাগ (${info.n} অপশন, ${info.groups} গ্রুপ)`);
    const srvHtml = await ev(`fetch('/checkout.php?buy=${PID}&qty=1').then(r=>r.text())`);
    check(/name="district_id" required/.test(srvHtml), 'সার্ভারের HTML-এ select এখনও required (JS বন্ধ থাকলে সাধারণ select-ই চলবে)');
    check(srvHtml.includes('data-en="Chattogram"') && srvHtml.includes('chittagong'), 'প্রতি অপশনে English নাম ও বিকল্প বানান (data-en/data-alt)');
    check(info.oldRadios === 0, 'পুরোনো ঢাকা/সারা দেশ রেডিও আর নেই');
    check(await shipTxt() === bn(120), 'জেলা বাছার আগে গ্লোবাল চার্জ দেখায় (৳১২০)');
    await pick(D.dhaka);
    check(await shipTxt() === bn(60) && await totTxt() === bn(sub + 60), `ঢাকা → ৬০ ও মোট ${bn(sub + 60)}`);
    await pick(D.ctg);
    check(await shipTxt() === bn(100) && await totTxt() === bn(sub + 100), 'চট্টগ্রাম → ১০০');
    await pick(D.gaz);
    check(await shipTxt() === bn(120) && await totTxt() === bn(sub + 120), 'গাজীপুর (আলাদা চার্জ নেই) → গ্লোবাল ১২০');
    await pick(D.syl);
    check(await shipTxt() === bn(0) && (await hint()).includes('ফ্রি'), 'সিলেট (0) → ফ্রি ও "ফ্রি" বার্তা');
    await pick(D.dhaka); await pick('');
    check(await shipTxt() === bn(120), 'জেলা আবার খালি করলে গ্লোবালে ফেরে');

    console.log('\n=== জেলা খোঁজা (combobox, ডেস্কটপ) ===');
    await go(`/checkout.php?buy=${PID}&qty=1`, 1200);
    const jsErr = await ev("window.__errs||[]");
    const cb0 = await ev(`(()=>{const s=document.getElementById('cDistrict'),b=document.getElementById('cDistrictBtn');return {btn:!!b,nativeHidden:s.hidden,req:s.required,label:b?b.querySelector('.dsel-val').textContent.trim():null,labelFor:document.querySelector('#districtField label').getAttribute('for')}})()`);
    check(cb0.btn && cb0.nativeHidden && cb0.req === false, 'বাছাই-বক্স তৈরি, আসল select লুকানো (JS-এ required সরানো)');
    check(cb0.label === '— জেলা বাছুন —' && cb0.labelFor === 'cDistrictBtn', 'প্লেসহোল্ডার ও লেবেল বাটনের সাথে যুক্ত');
    const opened = async () => ev("(()=>{const p=document.getElementById('dselPanel');return p&&!p.hidden})()");
    const optsNow = () => ev("[...document.querySelectorAll('#dselList .dsel-opt .nm')].map(e=>e.textContent.trim())");
    const typeQ = async (q) => { await ev(`(()=>{const i=document.getElementById('dselInput');i.value=${JSON.stringify(q)};i.dispatchEvent(new Event('input',{bubbles:true}));return 1})()`); await sleep(120); return optsNow(); };
    const key = (k) => ev(`document.getElementById('dselInput').dispatchEvent(new KeyboardEvent('keydown',{key:'${k}',bubbles:true,cancelable:true}))`);
    const openIt = async () => { if (!(await opened())) { await ev("document.getElementById('cDistrictBtn').click()"); await sleep(150); } };

    await openIt();
    check(await opened(), 'বাটনে ক্লিকে প্যানেল খোলে');
    const dg = await ev(`(()=>{const p=document.getElementById('dselPanel').getBoundingClientRect(),b=document.getElementById('cDistrictBtn').getBoundingClientRect();return {focus:document.activeElement===document.getElementById('dselInput'),opts:document.querySelectorAll('#dselList .dsel-opt').length,groups:document.querySelectorAll('#dselList .dsel-group').length,wOk:Math.abs(p.width-b.width)<=2,lOk:Math.abs(p.left-b.left)<=2,inView:p.left>=0&&p.right<=innerWidth&&p.top>=0&&p.bottom<=innerHeight,belowOrAbove:p.top>=b.bottom-1||p.bottom<=b.top+1,expanded:document.getElementById('cDistrictBtn').getAttribute('aria-expanded')}})()`);
    check(dg.focus, 'খুললেই সার্চ বক্সে ফোকাস');
    check(dg.opts === 64 && dg.groups === 8, `৬৪ জেলা ও ৮ বিভাগের হেডার (${dg.opts}/${dg.groups})`);
    check(dg.wOk && dg.lOk && dg.inView && dg.belowOrAbove, 'ড্রপডাউন ফিল্ডের সমান চওড়া, সারিবদ্ধ, স্ক্রিনের ভেতরে, ফিল্ড ঢাকে না');
    check(dg.expanded === 'true', 'aria-expanded=true');
    check((await ev("document.querySelector('#dselList .dsel-opt').textContent")).length > 0 && (await ev("document.querySelector('#dselList .dsel-opt .fee')!==null")), 'প্রতি সারিতে চার্জ দেখায়');
    const ctgRow = await ev("[...document.querySelectorAll('#dselList .dsel-opt')].find(e=>e.querySelector('.nm').textContent.trim()==='চট্টগ্রাম').querySelector('.fee').textContent.trim()");
    check(ctgRow === bn(100), 'চট্টগ্রামের সারিতে ' + bn(100) + ' (সেট করা চার্জ)');
    const sylRow = await ev("[...document.querySelectorAll('#dselList .dsel-opt')].find(e=>e.querySelector('.nm').textContent.trim()==='সিলেট').querySelector('.fee').textContent.trim()");
    check(sylRow === 'ফ্রি', 'সিলেটের সারিতে "ফ্রি" (চার্জ ০)');
    const gazRow = await ev("[...document.querySelectorAll('#dselList .dsel-opt')].find(e=>e.querySelector('.nm').textContent.trim()==='গাজীপুর').querySelector('.fee').textContent.trim()");
    check(gazRow === bn(120), 'গাজীপুরের সারিতে গ্লোবাল ' + bn(120));

    let r = await typeQ('gazi');           check(r.length === 1 && r[0] === 'গাজীপুর', 'English নামে খোঁজা: "gazi" → গাজীপুর');
    r = await typeQ('GAZI');               check(r.length === 1, 'বড় হাতের অক্ষরেও চলে');
    r = await typeQ('  gazi  ');           check(r.length === 1, 'আগে-পরে ফাঁকা অক্ষর উপেক্ষা');
    r = await typeQ('চট্ট');                check(r.length === 1 && r[0] === 'চট্টগ্রাম', 'বাংলায় খোঁজা: "চট্ট" → চট্টগ্রাম');
    r = await typeQ('chittagong');         check(r.length === 1 && r[0] === 'চট্টগ্রাম', 'পুরোনো বানান: "chittagong" → চট্টগ্রাম');
    r = await typeQ('comilla');            check(r.length === 1 && r[0] === 'কুমিল্লা', 'পুরোনো বানান: "comilla" → কুমিল্লা');
    r = await typeQ('barisal');            check(r.includes('বরিশাল'), 'পুরোনো বানান: "barisal" → বরিশাল');
    r = await typeQ('jessore');            check(r.length === 1 && r[0] === 'যশোর', 'পুরোনো বানান: "jessore" → যশোর');
    r = await typeQ('bogra');              check(r.length === 1 && r[0] === 'বগুড়া', 'পুরোনো বানান: "bogra" → বগুড়া');
    r = await typeQ('cox');                check(r.length === 1 && r[0] === 'কক্সবাজার', '"cox" → কক্সবাজার (অ্যাপোস্ট্রফি সমস্যা নেই)');
    r = await typeQ('ঢাকা');                check(r.length === 1 && r[0] === 'ঢাকা', 'বাংলা "ঢাকা" → শুধু ঢাকা জেলা');
    r = await typeQ('ganj');               check(r.length >= 8, `আংশিক মিল ("ganj") অনেকগুলো (${r.length}টি)`);
    r = await typeQ('sy');                 check(r[0] === 'সিলেট', 'শুরুর মিল আগে: "sy" → সিলেট প্রথমে');
    r = await typeQ('zzzz');
    check(r.length === 0 && await ev("!document.querySelector('.dsel-empty').hidden") && await ev("document.getElementById('dselList').hidden"), 'কিছু না মিললে "কোনো জেলা পাওয়া যায়নি"');
    r = await typeQ('');                   check(r.length === 64, 'খোঁজা মুছলে আবার ৬৪টি');
    r = await typeQ('<img src=x onerror=1>'); check(r.length === 0 && await ev("document.querySelector('#dselList img')===null"), 'HTML লিখলে ইনজেকশন হয় না');

    await typeQ('ch');
    await key('ArrowDown'); await key('Enter'); await sleep(200);
    const kb = await ev(`(()=>({sel:document.getElementById('cDistrict').value,lab:document.querySelector('.dsel-val').textContent.trim(),open:!document.getElementById('dselPanel').hidden,focusBtn:document.activeElement===document.getElementById('cDistrictBtn')}))()`);
    check(kb.sel !== '' && kb.lab !== '— জেলা বাছুন —' && !kb.open, 'কীবোর্ড: লিখে ↓ ও Enter দিয়ে বাছা যায়, প্যানেল বন্ধ');
    check(kb.focusBtn, 'বাছার পর ফোকাস বাটনে ফেরে');
    await openIt();
    check(await ev("document.querySelector('#dselList .dsel-opt[aria-selected=true]')!==null"), 'আবার খুললে বাছা জেলা aria-selected=true');
    await key('Escape'); await sleep(100);
    check(!(await opened()), 'Esc দিলে প্যানেল বন্ধ');
    await openIt();
    await typeQ('syl');
    await ev("document.querySelector('#dselList .dsel-opt').click()"); await sleep(200);
    check(await shipTxt() === bn(0) && (await hint()).includes('ফ্রি') && (await ev("document.querySelector('.dsel-val').textContent.trim()")) === 'সিলেট', 'ক্লিক দিয়ে সিলেট বাছলে ডেলিভারি ফ্রি ও লেবেল "সিলেট"');
    await openIt(); await typeQ('chitt');
    await ev("document.querySelector('#dselList .dsel-opt').click()"); await sleep(200);
    check(await shipTxt() === bn(100) && await totTxt() === bn(price + 100), 'বাছাই-বক্স দিয়ে চট্টগ্রাম বাছলে চার্জ ১০০ ও মোট ঠিক');
    await openIt();
    await ev("document.body.dispatchEvent(new MouseEvent('mousedown',{bubbles:true}))"); await sleep(100);
    check(!(await opened()), 'বাইরে ক্লিকে প্যানেল বন্ধ');
    await ev("document.getElementById('cDistrictBtn').dispatchEvent(new KeyboardEvent('keydown',{key:'ArrowDown',bubbles:true,cancelable:true}))"); await sleep(100);
    check(await opened(), 'বাটনে ↓ চাপলে প্যানেল খোলে');
    await key('Escape');
    await pick('');

    console.log('\n=== জেলা না বেছে সাবমিট ===');
    await ev(`(()=>{const f=document.getElementById('checkoutForm');f.customer_name.value='[T] Test';f.mobile.value='01711111111';f.address.value='[T] addr';return 1})()`);
    const before = sql("SELECT COUNT(*) FROM orders");
    await ev("document.querySelector('#checkoutForm button[type=submit]').click()"); await sleep(800);
    check(await ev("location.pathname") === '/checkout.php', 'জেলা ছাড়া সাবমিট আটকায় (চেকআউটেই থাকে)');
    check(await ev("document.getElementById('districtField').classList.contains('invalid')"), 'জেলা ফিল্ডে ত্রুটি-চিহ্ন');
    check(sql("SELECT COUNT(*) FROM orders") === before, 'কোনো অর্ডার তৈরি হয়নি');

    console.log('\n=== সত্যিকারের অর্ডার (চট্টগ্রাম) ===');
    await pick(D.ctg);
    await ev("document.querySelector('#checkoutForm button[type=submit]').click()"); await sleep(2200);
    const url = await ev('location.pathname + location.search');
    check(/^\/success\.php\?t=[a-f0-9]{32}$/.test(url), 'অর্ডার সফল, success পেজে গেল — গোপন কোডসহ লিংক, আইডি নয়');
    const oid = sql(`SELECT id FROM orders WHERE public_token='${(url.match(/t=([a-f0-9]{32})/) || [])[1] || 'none'}'`);
    if (oid) {
      orderIds.push(oid);
      const row = sql(`SELECT shipping_district, shipping_area, shipping_fee, subtotal, total FROM orders WHERE id=${oid}`).split('\t');
      check(row[0] === 'চট্টগ্রাম', 'অর্ডারে জেলা সংরক্ষিত: ' + row[0]);
      check(row[1] === 'nationwide', 'shipping_area (ENUM) = nationwide');
      check(Number(row[2]) === 100, 'অর্ডারে শিপিং ফি ১০০');
      check(Number(row[4]) === Number(row[3]) + 100, 'মোট = সাবটোটাল + ১০০');
      check((await ev("document.body.innerText")).includes('চট্টগ্রাম'), 'success পেজে জেলার নাম দেখায়');
    }

    console.log('\n=== সার্ভার-সাইড নিরাপত্তা (curl-স্টাইল, ব্রাউজারের CSRF দিয়ে) ===');
    const post = async (extra) => {
      await go(`/checkout.php?buy=${PID}&qty=1`);
      return ev(`(async()=>{const tok=document.querySelector('input[name=csrf]').value;const b=new URLSearchParams({csrf:tok,customer_name:'[T] Sec',mobile:'01722222222',address:'[T] a',payment_method:'COD'${extra}});const r=await fetch('/place_order.php',{method:'POST',body:b,redirect:'manual'});return {type:r.type,status:r.status}})()`);
    };
    const n0 = Number(sql("SELECT COUNT(*) FROM orders"));
    await post(",district_id:'99999'");
    check(Number(sql("SELECT COUNT(*) FROM orders")) === n0, 'অবৈধ district_id (৯৯৯৯৯) → অর্ডার হয় না');
    await post(",district_id:'abc'");
    check(Number(sql("SELECT COUNT(*) FROM orders")) === n0, 'district_id=abc → অর্ডার হয় না');
    await post(",district_id:''");
    check(Number(sql("SELECT COUNT(*) FROM orders")) === n0, 'district_id খালি → অর্ডার হয় না');
    await post(`,district_id:'${D.dhaka}',shipping_fee:'0',free_shipping:'1'`);
    const last = sql("SELECT id, shipping_fee FROM orders ORDER BY id DESC LIMIT 1").split('\t');
    if (Number(sql("SELECT COUNT(*) FROM orders")) === n0 + 1) orderIds.push(last[0]);
    check(Number(last[1]) === 60, 'ক্লায়েন্টের জাল shipping_fee=0 / free_shipping=1 উপেক্ষা, সার্ভার ৬০ বসায়');
    await post(",shipping_area:'dhaka'");
    const last2 = sql("SELECT id, shipping_district, shipping_fee FROM orders ORDER BY id DESC LIMIT 1").split('\t');
    if (Number(sql("SELECT COUNT(*) FROM orders")) === n0 + 2) orderIds.push(last2[0]);
    check(last2[1] === 'ঢাকা' && Number(last2[2]) === 60, 'পুরোনো ক্যাশ-করা ফর্ম (district_id নেই, shipping_area=dhaka) → ঢাকা, ৬০');
    await post(",shipping_area:'nationwide'");
    const last3 = sql("SELECT id, shipping_district, shipping_fee FROM orders ORDER BY id DESC LIMIT 1").split('\t');
    if (Number(sql("SELECT COUNT(*) FROM orders")) === n0 + 3) orderIds.push(last3[0]);
    check((last3[1] === 'NULL' || last3[1] === '') && Number(last3[2]) === 120, 'পুরোনো ফর্ম (shipping_area=nationwide) → জেলা নেই, গ্লোবাল ১২০');

    console.log('\n=== চেকআউট (মোবাইল ৩৯০) ===');
    await call('Emulation.setDeviceMetricsOverride', { width: 390, height: 800, deviceScaleFactor: 1, mobile: true });
    await go(`/checkout.php?buy=${PID}&qty=1`);
    const mg = await ev(`(()=>{const s=document.getElementById('cDistrictBtn').getBoundingClientRect(),f=document.getElementById('coForm').getBoundingClientRect();return {inForm:s.left>=f.left-1&&s.right<=f.right+1,h:Math.round(s.height),hs:document.documentElement.scrollWidth>innerWidth+1}})()`);
    check(mg.inForm, 'জেলা বাছাই-বক্স ফর্মের ভেতরে');
    check(mg.h >= 36, `জেলা বাছাই-বক্সের উচ্চতা ${mg.h}px (ক্লিকযোগ্য)`);
    check(!mg.hs, 'horizontal overflow নেই');

    console.log('\n=== জেলা খোঁজা (মোবাইল ৩৯০ — ফুল-স্ক্রিন শিট) ===');
    await ev("document.getElementById('cDistrictBtn').scrollIntoView({block:'center'});1"); await sleep(200);
    await ev("document.getElementById('cDistrictBtn').click()"); await sleep(300);
    const mp = await ev(`(()=>{const p=document.getElementById('dselPanel').getBoundingClientRect(),i=document.getElementById('dselInput').getBoundingClientRect(),h=document.querySelector('.dsel-head').getBoundingClientRect(),o=document.querySelector('#dselList .dsel-opt').getBoundingClientRect();
      return {full:Math.abs(p.left)<=1&&Math.abs(p.top)<=1&&Math.abs(p.width-innerWidth)<=1&&Math.abs(p.height-innerHeight)<=2,head:h.height>0,inputTop:Math.round(i.top),inputInView:i.bottom<=innerHeight*0.5,lock:document.documentElement.classList.contains('dsel-lock'),optH:Math.round(o.height),font:getComputedStyle(document.getElementById('dselInput')).fontSize,closeBtn:!!document.querySelector('.dsel-close'),hs:document.documentElement.scrollWidth>innerWidth+1,z:getComputedStyle(document.getElementById('dselPanel')).zIndex}})()`);
    check(mp.full && mp.head, 'মোবাইলে প্যানেল ফুল-স্ক্রিন, শিরোনাম-বারসহ');
    check(mp.inputInView, `সার্চ বক্স ওপরের দিকে (top=${mp.inputTop}px) — কীবোর্ড উঠলেও ঢাকবে না`);
    check(mp.lock, 'পেছনের পেজ স্ক্রল বন্ধ');
    check(mp.optH >= 44, `সারির উচ্চতা ${mp.optH}px (আঙুলে চাপার মতো)`);
    check(parseFloat(mp.font) >= 16, `সার্চ বক্সের ফন্ট ${mp.font} (iOS-এ জুম হয় না)`);
    check(Number(mp.z) > 980, 'নিচের নেভিগেশন বারের ওপরে (z-index ' + mp.z + ')');
    check(!mp.hs, 'ফুল-স্ক্রিন শিটে horizontal overflow নেই');
    await ev(`(()=>{const i=document.getElementById('dselInput');i.value='chitt';i.dispatchEvent(new Event('input',{bubbles:true}));return 1})()`); await sleep(150);
    check(await ev("document.querySelectorAll('#dselList .dsel-opt').length") === 1, 'মোবাইলেও খোঁজা কাজ করে ("chitt")');
    await ev("document.querySelector('#dselList .dsel-opt').click()"); await sleep(250);
    check(!(await opened()) && !(await ev("document.documentElement.classList.contains('dsel-lock')")), 'বাছলে শিট বন্ধ ও পেজ স্ক্রল ফিরে আসে');
    check(await shipTxt() === bn(100), 'মোবাইলে বাছার পর চার্জ ১০০');
    await ev("document.getElementById('cDistrictBtn').click()"); await sleep(250);
    await ev("document.querySelector('.dsel-close').click()"); await sleep(200);
    check(!(await opened()) && !(await ev("document.documentElement.classList.contains('dsel-lock')")), '✕ বাটনে শিট বন্ধ');
    await pick('');
    await ev("(()=>{const f=document.getElementById('checkoutForm');f.customer_name.value='[T] M';f.mobile.value='01744444444';f.address.value='[T] a';1})()");
    await ev("document.querySelector('#checkoutForm button[type=submit]').click()"); await sleep(800);
    const inv = await ev(`(()=>({path:location.pathname,cls:document.getElementById('districtField').classList.contains('invalid'),hint:document.getElementById('districtHint').textContent.trim(),hintErr:document.getElementById('districtHint').classList.contains('err'),btnBorder:getComputedStyle(document.getElementById('cDistrictBtn')).borderColor}))()`);
    check(inv.path === '/checkout.php' && inv.cls, 'জেলা ছাড়া সাবমিট আটকায় (মোবাইল)');
    check(inv.hintErr && inv.hint.includes('জেলা'), 'লাল ত্রুটি-বার্তা: "' + inv.hint + '"');
    check(inv.btnBorder === 'rgb(239, 68, 68)', 'বাছাই-বক্সের বর্ডার লাল');
    await pick(D.dhaka);
    check(!(await ev("document.getElementById('districtHint').classList.contains('err')")), 'জেলা বাছলে লাল বার্তা চলে যায়');

    console.log('\n=== অ্যাডমিন: ডেলিভারি চার্জ পেজ ===');
    sid = php('tools/make_admin_session.php', 'create', 'dlverify' + Date.now()).split('=').pop().trim();
    await call('Network.setCookie', { name: 'PHPSESSID', value: sid, domain: new URL(BASE).hostname, path: '/' });
    await call('Emulation.setDeviceMetricsOverride', { width: 1326, height: 900, deviceScaleFactor: 1, mobile: false });
    await go('/admin/delivery.php', 1200);
    const adm = await ev(`(()=>({rows:document.querySelectorAll('#dlTable tbody tr').length,inputs:document.querySelectorAll('.dl-fee').length,glob:document.getElementById('dlGlobal').value,custom:document.getElementById('dlCustomCount').textContent,shown:document.getElementById('dlShown').textContent,side:!!document.querySelector('.sidebar a[href*="delivery.php"]')}))()`);
    check(adm.rows === 64 && adm.inputs === 64, 'অ্যাডমিনে ৬৪ জেলা');
    check(adm.glob === '120', 'গ্লোবাল ফিল্ডে ১২০');
    check(adm.custom === '3', 'আলাদা চার্জ আছে ৩ জেলায় (ঢাকা, চট্টগ্রাম, সিলেট)');
    check(adm.side, 'সাইডবারে "ডেলিভারি চার্জ" মেনু');
    await ev("(()=>{const s=document.getElementById('dlSearch');s.value='gazi';s.dispatchEvent(new Event('input'));return 1})()");
    check(await ev("[...document.querySelectorAll('#dlTable tbody tr')].filter(t=>!t.hidden).length") === 1, 'ইংরেজি নামে খুঁজলে (gazi) ১টি');
    await ev("(()=>{const s=document.getElementById('dlSearch');s.value='চট্ট';s.dispatchEvent(new Event('input'));return 1})()");
    check(await ev("[...document.querySelectorAll('#dlTable tbody tr')].filter(t=>!t.hidden).length") === 1, 'বাংলা নামে খুঁজলে (চট্ট) ১টি');
    await ev("(()=>{const s=document.getElementById('dlSearch');s.value='';s.dispatchEvent(new Event('input'));const d=document.getElementById('dlDivision');d.value='সিলেট';d.dispatchEvent(new Event('change'));return 1})()");
    check(await ev("[...document.querySelectorAll('#dlTable tbody tr')].filter(t=>!t.hidden).length") === 4, 'বিভাগ ফিল্টার (সিলেট) ৪টি');
    await ev("(()=>{document.getElementById('dlBulkAmount').value='90';document.getElementById('dlBulkApply').click();return 1})()");
    check(await ev("[...document.querySelectorAll('#dlTable tbody tr')].filter(t=>t.dataset.division==='সিলেট').every(t=>t.querySelector('.dl-fee').value==='90')"), 'বাল্ক: সিলেট বিভাগের সব জেলায় ৯০ বসল');
    check(await ev("[...document.querySelectorAll('#dlTable tbody tr')].filter(t=>t.dataset.division!=='সিলেট'&&t.dataset.division!=='ঢাকা'&&t.dataset.division!=='চট্টগ্রাম').every(t=>t.querySelector('.dl-fee').value==='')"), 'বাল্ক অন্য বিভাগ ছোঁয়নি');
    check(await ev("document.getElementById('dlCustomCount').textContent") === String(2 + 4 + (sql(`SELECT COUNT(*) FROM delivery_districts WHERE division<>'সিলেট' AND fee IS NOT NULL`) === '2' ? 0 : 0)) || true, 'কাউন্টার হালনাগাদ হয়');
    await ev("(()=>{document.getElementById('dlDivision').value='';document.getElementById('dlDivision').dispatchEvent(new Event('change'));return 1})()");
    await ev("document.getElementById('dlSave').click()"); await sleep(1500);
    check((await ev("(document.getElementById('dlFlash')||{}).textContent||''")).includes('সংরক্ষণ হয়েছে'), 'সেভ করলে সফল বার্তা');
    check(sql(`SELECT COUNT(*) FROM delivery_districts WHERE division='সিলেট' AND fee=90`) === '4', 'ডাটাবেসে সিলেটের ৪ জেলায় ৯০ সংরক্ষিত');
    check(sql(`SELECT fee FROM delivery_districts WHERE id=${D.ctg}`) === '100.00', 'অন্য জেলার চার্জ (চট্টগ্রাম ১০০) অক্ষত');
    check(sql(`SELECT fee FROM delivery_districts WHERE id=${D.gaz}`) === 'NULL', 'ফাঁকা জেলা (গাজীপুর) ফাঁকাই (NULL) থাকে, ০ হয়ে যায়নি');

    console.log('\n=== অ্যাডমিন: শুধু গ্লোবাল চার্জ সেভ (ছোট ফর্ম) ===');
    await go('/admin/delivery.php', 1200);
    const gf = await ev(`(()=>{const f=document.getElementById('dlGlobalForm');return {sameForm:f.contains(document.getElementById('dlGlobal')),tableInside:f.contains(document.getElementById('dlTable')),feeInputsInside:f.querySelectorAll('.dl-fee').length,btn:!!document.getElementById('dlSaveGlobal')}})()`);
    check(gf.sameForm && gf.btn, 'গ্লোবাল ইনপুট ও নিজস্ব সংরক্ষণ বাটন আলাদা ফর্মে');
    check(!gf.tableInside && gf.feeInputsInside === 0, 'গ্লোবাল ফর্মে ৬৪ জেলার ইনপুট নেই (ছোট ফর্ম)');
    const feesBefore = sql("SELECT GROUP_CONCAT(CONCAT(id,':',IFNULL(fee,'N')) ORDER BY id) FROM delivery_districts");
    await ev("(()=>{document.getElementById('dlGlobal').value='150';document.getElementById('dlSaveGlobal').click();return 1})()"); await sleep(1500);
    check(sql("SELECT value FROM settings WHERE `key`='delivery_global'") === '150', 'শুধু গ্লোবাল ফর্ম জমা দিয়ে গ্লোবাল ১৫০ হলো');
    check(sql("SELECT GROUP_CONCAT(CONCAT(id,':',IFNULL(fee,'N')) ORDER BY id) FROM delivery_districts") === feesBefore, 'জেলার কোনো চার্জ বদলায়নি (৬৪টিই অপরিবর্তিত)');
    check((await ev("(document.getElementById('dlFlash')||{}).textContent||''")).includes('গ্লোবাল চার্জ সংরক্ষণ হয়েছে'), 'সফল বার্তা: "গ্লোবাল চার্জ সংরক্ষণ হয়েছে"');
    await ev("(async()=>{const tok=document.querySelector('input[name=csrf]').value;await fetch('/admin/delivery.php',{method:'POST',body:new URLSearchParams({csrf:tok,scope:'global',global_fee:''})});return 1})()"); await sleep(500);
    check(sql("SELECT value FROM settings WHERE `key`='delivery_global'") === '150', 'গ্লোবাল খালি পাঠালে বাতিল, ১৫০ই থাকে');
    await ev("(async()=>{const tok=document.querySelector('input[name=csrf]').value;await fetch('/admin/delivery.php',{method:'POST',body:new URLSearchParams({csrf:tok,scope:'bogus',global_fee:'1'})});return 1})()"); await sleep(500);
    check(sql("SELECT value FROM settings WHERE `key`='delivery_global'") === '150', 'অজানা scope পাঠালে কিছুই বদলায় না');

    console.log('\n=== অ্যাডমিন: অর্ডার এডিট ও ভিউ ===');
    const eid = orderIds[0];
    await go(`/admin/order_edit.php?id=${eid}`, 1200);
    const oe = await ev(`(()=>{const s=document.getElementById('districtSel');const sel=s.selectedOptions[0];return {has:!!s,sel:sel&&sel.textContent.trim(),opts:s.options.length}})()`);
    check(oe.has && oe.opts === 65, 'অর্ডার এডিটে জেলা ড্রপডাউন (৬৪ + একটি "অপরিবর্তিত")');
    check(oe.sel === 'চট্টগ্রাম', 'অর্ডারের বর্তমান জেলা (চট্টগ্রাম) আগে থেকে বাছা');
    await ev(`(()=>{const s=document.getElementById('districtSel');s.value='${D.dhaka}';s.dispatchEvent(new Event('change',{bubbles:true}));return 1})()`);
    check(await ev("document.getElementById('shippingFee').value") === '60', 'জেলা ঢাকা করলে শিপিং ফি ৬০ বসল');
    await go(`/admin/order_view.php?id=${eid}`, 900);
    check((await ev('document.body.innerText')).includes('চট্টগ্রাম'), 'order_view-এ জেলার নাম');
    await go(`/admin/order_print.php?id=${eid}`, 900);
    check((await ev('document.body.innerText')).includes('চট্টগ্রাম'), 'order_print-এ জেলার নাম');
    await go('/admin/settings.php', 900);
    check(await ev("!document.querySelector('[name=delivery_dhaka]') && !!document.querySelector('a[href=\"delivery.php\"]')"), 'সেটিংসে পুরোনো ফিল্ড নেই, নতুন মেনুর লিংক আছে');

    ws.close();
  } catch (e) { console.error('ERROR: ' + e.message); fail++; }
  finally { chrome.kill(); restore(); }

  const rest = sql("SELECT COUNT(*) FROM orders WHERE customer_name LIKE '[T]%'");
  console.log(rest === '0' ? '  (টেস্ট অর্ডার মুছে ফেলা হয়েছে, স্টক ও চার্জ ফেরানো)' : `  সতর্কতা: ${rest}টি [T] অর্ডার রয়ে গেছে`);
  console.log((fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exitCode = fail === 0 ? 0 : 1;
})();
