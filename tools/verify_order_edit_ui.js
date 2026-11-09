/**
 * tools/verify_order_edit_ui.js — admin/order_edit.php-এর পরিমাণ-বক্স ও সংখ্যা-ফরম্যাট যাচাই (headless Chrome)
 * node tools/verify_order_edit_ui.js [url]      (অস্থায়ী Super Admin সেশন নিজে বানায়/মুছে; ডাটাবেস বদলায় না, Save চাপে না)
 */
const { spawn, execFileSync } = require('child_process');
const os = require('os');
const path = require('path');
const BASE = process.argv[2] || 'http://estore.test';
const PORT = Number(process.env.PORT_OE || 9491);
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
  const OID = sql("SELECT o.id FROM orders o JOIN order_items i ON i.order_id=o.id WHERE o.status='pending' GROUP BY o.id HAVING COUNT(*)>=1 ORDER BY o.id DESC LIMIT 1");
  const sid = php('tools/make_admin_session.php', 'create').split('=').pop().trim();
  const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, '--no-sandbox', '--disable-gpu', '--hide-scrollbars', '--user-data-dir=' + os.tmpdir() + '\\oe_prof_' + Date.now(), 'about:blank'], { stdio: 'ignore' });
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
    await call('Network.setCookie', { name: 'PHPSESSID', value: sid, domain: new URL(BASE).hostname, path: '/' });
    const ev = async (expr) => {
      const r = await call('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
      if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
      return r.result.value;
    };
    const BN = /[০-৯]/;

    for (const w of [1326, 390]) {
      console.log(`\n=== অর্ডার এডিট #${OID} (${w}px) ===`);
      await call('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 600 });
      await call('Page.navigate', { url: `${BASE}/admin/order_edit.php?id=${OID}&return=` }); await sleep(1600);
      const g = await ev(`(()=>{const q=[...document.querySelectorAll('#itemsTable .qtyBox')].map(b=>{const r=b.getBoundingClientRect(),c=b.closest('td').getBoundingClientRect();return {w:Math.round(r.width),cellW:Math.round(c.width),h:Math.round(r.height),right:r.right<=c.right+1,inView:r.right<=innerWidth+1&&r.left>=0}});
        const inp=document.querySelector('#itemsTable .qtyInput');
        return {boxes:q,spin:getComputedStyle(inp).appearance||getComputedStyle(inp).MozAppearance,hs:document.documentElement.scrollWidth>innerWidth+1,
          table:document.getElementById('itemsTable').innerText,summary:document.querySelector('.summary-kv')?document.querySelector('.summary-kv').innerText:'' }})()`);
      check(g.boxes.length > 0 && g.boxes.every((b) => b.w <= 140), `পরিমাণ-বক্স আর পুরো ঘর জুড়ে নয় — চওড়া ${g.boxes.map((b) => b.w).join('/')}px (ঘর ${g.boxes[0].cellW}px; আগে ঘরের সমান লম্বা বার)`);
      check(g.boxes.every((b) => b.inView && b.right), 'বক্স ঘরের ও স্ক্রিনের ভেতরে');
      check(g.boxes.every((b) => b.h >= 36), 'বক্সের উচ্চতা ' + g.boxes[0].h + 'px');
      check(!BN.test(g.table) && !BN.test(g.summary), 'টেবিল ও সারাংশে বাংলা অঙ্ক নেই (JS হালনাগাদের পরেও ইংরেজি অঙ্ক)');
      check(!g.hs, 'horizontal overflow নেই');
      if (w === 1326) {
        const before = await ev("document.getElementById('sumSubtotal').textContent.trim()");
        await ev("(()=>{document.querySelector('#itemsTable .btnInc').click();1})()"); await sleep(200);
        const after = await ev(`(()=>({sub:document.getElementById('sumSubtotal').textContent.trim(),tot:document.getElementById('sumTotal').textContent.trim(),line:document.querySelector('#itemsTable .lineCell').textContent.trim(),dirty:!!document.getElementById('updatePriceButton')&&getComputedStyle(document.getElementById('updatePriceButton')).display!=='none'}))()`);
        check(after.sub !== before && /^৳[\d,]+$/.test(after.sub) && /^৳[\d,]+$/.test(after.tot) && /^৳[\d,]+$/.test(after.line), `+ চাপলে সারাংশ ও লাইন বদলায়, ইংরেজি অঙ্কে (${before} → ${after.sub})`);
        await ev("(()=>{document.querySelector('#itemsTable .btnDec').click();1})()");
      }
      // ---- ডেলিভারির জেলা: খোঁজা-যোগ্য বাছাই ----
      const dp0 = await ev(`(()=>{const s=document.getElementById('districtSel'),b=document.getElementById('districtBtn');const r=b?b.getBoundingClientRect():null;const l=s.closest('.col-md-6').querySelector('label');return {btn:!!b,hidden:s.hidden,label:b?b.querySelector('.dsel-val').textContent.trim():null,forAttr:l.getAttribute('for'),h:r?Math.round(r.height):0,border:b?getComputedStyle(b).borderTopColor:'',inCol:r?(r.right<=s.closest('.col-md-6').getBoundingClientRect().right+1):false}})()`);
      check(dp0.btn && dp0.hidden, 'জেলার জায়গায় খোঁজা-যোগ্য বাছাই-বক্স (আসল select লুকানো)');
      check(dp0.label === 'ঢাকা', 'বর্তমান জেলা (ঢাকা) বাক্সে দেখায়: "' + dp0.label + '"');
      check(dp0.forAttr === 'districtBtn' && dp0.h >= 36 && dp0.inCol, `লেবেল বাক্সের সাথে যুক্ত, উচ্চতা ${dp0.h}px, কলামের ভেতরে`);
      const pOpen = () => ev("(()=>{const p=document.getElementById('dselPanel');return !!p&&!p.hidden})()");
      const typeQ = async (q) => { await ev(`(()=>{const i=document.getElementById('dselInput');i.value=${JSON.stringify(q)};i.dispatchEvent(new Event('input',{bubbles:true}));return 1})()`); await sleep(120); return ev("[...document.querySelectorAll('#dselList .dsel-opt .nm')].map(e=>e.textContent.trim())"); };
      await ev("document.getElementById('districtBtn').click()"); await sleep(250);
      check(await pOpen(), 'বাক্সে ক্লিকে প্যানেল খোলে');
      const dg = await ev(`(()=>{const p=document.getElementById('dselPanel').getBoundingClientRect();return {foc:document.activeElement===document.getElementById('dselInput'),n:document.querySelectorAll('#dselList .dsel-opt').length,g:document.querySelectorAll('#dselList .dsel-group').length,inView:p.left>=0&&p.right<=innerWidth&&p.top>=0&&p.bottom<=innerHeight+1,full:Math.abs(p.width-innerWidth)<=1&&Math.abs(p.height-innerHeight)<=2,sel:document.querySelector('#dselList .dsel-opt[aria-selected=true] .nm')?.textContent.trim()}})()`);
      check(dg.foc && dg.n === 64 && dg.g === 8, `৬৪ জেলা, ৮ বিভাগ, সার্চ বক্সে ফোকাস (${dg.n}/${dg.g})`);
      check(dg.inView, 'প্যানেল স্ক্রিনের ভেতরে' + (w < 600 ? ' (ফুল-স্ক্রিন শিট: ' + dg.full + ')' : ''));
      if (w < 600) check(dg.full, 'মোবাইলে ফুল-স্ক্রিন শিট');
      check(dg.sel === 'ঢাকা', 'বর্তমান জেলা তালিকায় চিহ্নিত (aria-selected)');
      let r1 = await typeQ('chitt');  check(r1.length === 1 && r1[0] === 'চট্টগ্রাম', 'English/পুরোনো বানানে খোঁজা: "chitt" → চট্টগ্রাম');
      r1 = await typeQ('গাজী');       check(r1.length === 1 && r1[0] === 'গাজীপুর', 'বাংলায় খোঁজা: "গাজী" → গাজীপুর');
      r1 = await typeQ('sylhet');     check(r1.length === 1 && r1[0] === 'সিলেট', '"sylhet" → সিলেট');
      r1 = await typeQ('zzzz');       check(r1.length === 0 && await ev("!document.querySelector('.dsel-empty').hidden"), 'না মিললে "কোনো জেলা পাওয়া যায়নি"');
      const feeBefore = await ev("document.getElementById('shippingFee').value");
      await typeQ('chitt');
      await ev("document.querySelector('#dselList .dsel-opt').click()"); await sleep(250);
      const ch = await ev(`(()=>{const s=document.getElementById('districtSel');const o=s.selectedOptions[0];const f=document.getElementById('shippingFee').value;const fd=new FormData(s.form).get('district_id');return {txt:o.textContent.trim(),lab:document.querySelector('#districtBtn .dsel-val').textContent.trim(),fee:f,optFee:o.dataset.fee,posted:fd,val:s.value,open:!document.getElementById('dselPanel').hidden}})()`);
      check(ch.txt === 'চট্টগ্রাম' && ch.lab === 'চট্টগ্রাম' && !ch.open, 'বাছলে আসল select ও বাক্সের লেখা "চট্টগ্রাম", প্যানেল বন্ধ');
      check(Number(ch.fee) === Number(ch.optFee), `জেলা বদলালে শিপিং ফি নিজে বসে (${feeBefore} → ${ch.fee})`);
      check(ch.posted === ch.val && ch.posted !== '', 'ফর্ম জমায় district_id=' + ch.posted + ' যায় (লুকানো select-ই জমা দেয়)');
      await ev("document.getElementById('districtBtn').click()"); await sleep(200);
      await call('Input.dispatchKeyEvent', { type: 'rawKeyDown', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
      await call('Input.dispatchKeyEvent', { type: 'keyUp', key: 'Escape', code: 'Escape', windowsVirtualKeyCode: 27 });
      await sleep(150);
      check(!(await pOpen()), 'Esc দিলে প্যানেল বন্ধ');
      await ev("(()=>{const s=document.getElementById('districtSel');s.value='';s.dispatchEvent(new Event('change',{bubbles:true}));1})()");

      const s = await call('Page.captureScreenshot', { format: 'png' });
      require('fs').writeFileSync(os.tmpdir() + `\\order_edit_${w}.png`, Buffer.from(s.data, 'base64'));
    }
    ws.close();
  } catch (e) { console.error('ERROR: ' + e.message); fail++; }
  finally { chrome.kill(); try { php('tools/make_admin_session.php', 'destroy', sid); } catch {} }

  console.log((fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exitCode = fail === 0 ? 0 : 1;
})();
