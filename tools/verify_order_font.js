/**
 * tools/verify_order_font.js — অর্ডার ডিটেইলস ও অর্ডার ট্র্যাকিং পেজের সংখ্যা/ফন্ট যাচাই (headless Chrome)
 * node tools/verify_order_font.js [url]
 * বাংলা অঙ্ক (সাইটের বাকি অংশের সাথে অমিল ও ছোট আকারে ১/৬, ৪/৮ গুলিয়ে যায়), ফলব্যাক ফন্ট (এমোজি), overflow দেখে। ডাটাবেস বদলায় না।
 */
const { spawn, execFileSync } = require('child_process');
const os = require('os');
const BASE = process.argv[2] || 'http://estore.test';
const PORT = Number(process.env.PORT_OF || 9489);
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
  const row = sql("SELECT o.id, o.mobile FROM orders o JOIN order_items i ON i.order_id=o.id ORDER BY o.id DESC LIMIT 1").split('\t');
  const OID = row[0], MOB = row[1];
  const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, '--no-sandbox', '--disable-gpu', '--hide-scrollbars', '--user-data-dir=' + os.tmpdir() + '\\of_prof_' + Date.now(), 'about:blank'], { stdio: 'ignore' });
  try {
    let ready = false;
    for (let i = 0; i < 40; i++) { try { await fetch(`http://127.0.0.1:${PORT}/json/version`); ready = true; break; } catch { await sleep(250); } }
    if (!ready) throw new Error('DevTools নেই');
    const t = await fetch(`http://127.0.0.1:${PORT}/json/new?about:blank`, { method: 'PUT' }).then((r) => r.json());
    const ws = new WebSocket(t.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.addEventListener('open', res, { once: true }); ws.addEventListener('error', rej, { once: true }); });
    let id = 1;
    const call = (m, p) => send(ws, id++, m, p);
    await call('Page.enable'); await call('Runtime.enable'); await call('DOM.enable'); await call('CSS.enable'); await call('Network.enable'); await call('Network.setCacheDisabled', { cacheDisabled: true });
    const ev = async (expr) => {
      const r = await call('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
      if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
      return r.result.value;
    };
    const go = async (path, w) => { await call('Emulation.setDeviceMetricsOverride', { width: w, height: 900, deviceScaleFactor: 1, mobile: w < 600 }); await call('Page.navigate', { url: BASE + path }); await sleep(1800); };
    const fontsOf = async (rootSel) => {
      const doc = await call('DOM.getDocument', { depth: -1 });
      const all = await call('DOM.querySelectorAll', { nodeId: doc.root.nodeId, selector: rootSel + ' *' });
      const set = new Set();
      for (const nid of all.nodeIds) { try { (await call('CSS.getPlatformFontsForNode', { nodeId: nid })).fonts.forEach((f) => set.add(f.familyName)); } catch {} }
      return [...set];
    };
    const BN = /[০-৯]/;

    for (const w of [1326, 390]) {
      console.log(`\n=== অর্ডার ডিটেইলস #${OID} (${w}px) ===`);
      await go(`/single_order_details.php?id=${OID}`, w);
      const d = await ev(`(()=>{const o=document.getElementById('odv');const q=[...o.querySelectorAll('td[data-label="পরিমাণ"]')].map(e=>e.textContent.trim());const dt=[...o.querySelectorAll('dt')].find(e=>e.textContent.trim()==='তারিখ');return {text:o.innerText,qty:q,date:dt?dt.nextElementSibling.textContent.trim():null,hs:document.documentElement.scrollWidth>innerWidth+1,btnIcon:!!o.querySelector('.actions .btn i.fa-box-open'),emoji:/[\\u{1F300}-\\u{1FAFF}\\u{2600}-\\u{27BF}]/u.test(o.innerText)}})()`);
      check(!BN.test(d.text), 'পেজে কোথাও বাংলা অঙ্ক নেই (টাকা, মোবাইল, পরিমাণ, তারিখ সব ইংরেজি অঙ্কে)');
      check(d.qty.length > 0 && d.qty.every((x) => /^\d+$/.test(x)), 'পরিমাণ ইংরেজি অঙ্কে: ' + d.qty.join(', '));
      check(/^\d{2} [A-Z][a-z]{2} \d{4}, \d{1,2}:\d{2} (AM|PM)$/.test(d.date || ''), 'তারিখ পরিষ্কার ফরম্যাটে: "' + d.date + '"');
      check(!d.emoji && d.btnIcon, '"সব অর্ডার" বাটনে এমোজির বদলে Font Awesome আইকন');
      check(!d.hs, 'horizontal overflow নেই');
      const fonts = await fontsOf('#odv');
      check(!fonts.some((n) => /Emoji|Times|Segoe|Arial|Nirmala/i.test(n)), 'ফলব্যাক ফন্ট (Emoji/Times/Segoe) নেই; ব্যবহৃত: ' + fonts.join(', '));
      if (w === 390) {
        const num = await ev(`(()=>{const c=[...document.querySelectorAll('#odv td[data-label="পরিমাণ"]')][0];return parseFloat(getComputedStyle(c).fontSize)})()`);
        check(num >= 14, `মোবাইলে পরিমাণের ফন্ট ${num}px (পড়ার মতো)`);
      }
      const s = await call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
      require('fs').writeFileSync(os.tmpdir() + `\\order_${w}.png`, Buffer.from(s.data, 'base64'));
    }

    console.log('\n=== অর্ডার পাওয়া যায়নি পেজ ===');
    await go('/single_order_details.php?id=99999999', 1326);
    const nfText = String(await ev('document.body.innerText')).normalize('NFC');
    check(nfText.includes('অর্ডার পাওয়া যায়নি'.normalize('NFC')) && !/fatal|warning:/i.test(nfText), 'অবৈধ id-তে সুন্দর বার্তা, ক্র্যাশ নয়');

    for (const w of [1326, 390]) {
      console.log(`\n=== অর্ডার ট্র্যাকিং (${w}px) ===`);
      await go(`/track_orders.php?m=${MOB}`, w);
      const tr = await ev(`(()=>{const o=document.getElementById('trk');const ds=[...o.querySelectorAll('td[data-label="তারিখ"]')].map(e=>e.textContent.trim());return {text:o.innerText,dates:ds,hs:document.documentElement.scrollWidth>innerWidth+1}})()`);
      check(tr.dates.length > 0 && tr.dates.every((x) => /^\d{2} [A-Z][a-z]{2} \d{4}, \d{1,2}:\d{2} (AM|PM)$/.test(x)), 'তারিখ ইংরেজি অঙ্কে, পরিষ্কার ফরম্যাটে (যেমন ' + tr.dates[0] + ')');
      check(!BN.test(tr.text), 'পেজে বাংলা অঙ্ক নেই');
      check(!tr.hs, 'horizontal overflow নেই');
    }

    ws.close();
  } catch (e) { console.error('ERROR: ' + e.message); fail++; }
  finally { chrome.kill(); }

  console.log((fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exitCode = fail === 0 ? 0 : 1;
})();
