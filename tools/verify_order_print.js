/**
 * tools/verify_order_print.js — admin/order_print.php: স্ক্রিন ও প্রিন্টে কার্ড-বিন্যাস, তারিখ-ফরম্যাট, ১ পাতা যাচাই (headless Chrome)
 * node tools/verify_order_print.js [url]       (অস্থায়ী অ্যাডমিন সেশন নিজে বানায়-মোছে; ডাটাবেস বদলায় না)
 */
const { spawn, execFileSync } = require('child_process');
const os = require('os');
const path = require('path');
const BASE = process.argv[2] || 'http://estore.test';
const PORT = Number(process.env.PORT_OP || 9495);
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
    const to = setTimeout(() => reject(new Error('timeout: ' + method)), 25000);
    const onMsg = (ev) => {
      let m; try { m = JSON.parse(ev.data); } catch { return; }
      if (m.id === id) { clearTimeout(to); ws.removeEventListener('message', onMsg); m.error ? reject(new Error(JSON.stringify(m.error))) : resolve(m.result); }
    };
    ws.addEventListener('message', onMsg);
    ws.send(JSON.stringify({ id, method, params }));
  });
}

(async () => {
  const OID = sql("SELECT o.id FROM orders o JOIN order_items i ON i.order_id=o.id GROUP BY o.id ORDER BY o.id DESC LIMIT 1");
  const sid = php('tools/make_admin_session.php', 'create').split('=').pop().trim();
  const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, '--no-sandbox', '--disable-gpu', '--hide-scrollbars', '--user-data-dir=' + os.tmpdir() + '\\op_prof_' + Date.now(), 'about:blank'], { stdio: 'ignore' });
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
    const load = async (w, media) => {
      await call('Emulation.setDeviceMetricsOverride', { width: w, height: 1000, deviceScaleFactor: 1, mobile: w < 600 });
      await call('Emulation.setEmulatedMedia', { media: media || '' });
      await call('Page.navigate', { url: `${BASE}/admin/order_print.php?id=${OID}` }); await sleep(1500);
    };
    const cards = () => ev(`(()=>{const c=[...document.querySelectorAll('.row.g-3 .card-mini')].map(e=>e.getBoundingClientRect());if(c.length<2)return null;const a=c[0],b=c[1];return {sideBySide:Math.abs(a.top-b.top)<4&&a.right<=b.left+1,stacked:b.top>=a.bottom-1,wA:Math.round(a.width),wB:Math.round(b.width),hs:document.documentElement.scrollWidth>innerWidth+1,titles:[...document.querySelectorAll('.row.g-3 .card-mini h6')].map(h=>h.textContent.trim())}})()`);
    const BN = /[০-৯]/;

    for (const [label, w, media, want] of [
      ['স্ক্রিন ডেস্কটপ 1326px', 1326, '', 'side'],
      ['স্ক্রিন ট্যাবলেট 800px', 800, '', 'side'],
      ['স্ক্রিন মোবাইল 390px', 390, '', 'stack'],
      ['প্রিন্ট A4 (794px)', 794, 'print', 'side'],
      ['প্রিন্ট সরু পাতা (700px)', 700, 'print', 'side'],
      ['প্রিন্ট খুব সরু (600px)', 600, 'print', 'side'],
    ]) {
      console.log(`\n=== ${label} ===`);
      await load(w, media);
      const c = await cards();
      check(!!c && c.titles.length === 2, 'দুটি কার্ড আছে: ' + (c ? c.titles.join(' + ') : '—'));
      if (want === 'side') check(c.sideBySide, `কাস্টমার তথ্য ও অর্ডার তথ্য পাশাপাশি (${c.wA}px + ${c.wB}px)`);
      else check(c.stacked && c.wA > 300, `ছোট স্ক্রিনে নিচে নিচে, পুরো চওড়ায় (${c.wA}px)`);
      check(!c.hs, 'horizontal overflow নেই');
      if (!media) {
        const txt = await ev('document.body.innerText');
        check(!BN.test(txt), 'পেজে বাংলা অঙ্ক নেই (তারিখ, টাকা সব ইংরেজি অঙ্কে)');
        const dt = (txt.match(/\d{2} [A-Z][a-z]{2} \d{4}, \d{1,2}:\d{2} (AM|PM)/) || [''])[0];
        check(dt !== '', 'তারিখ পরিষ্কার ফরম্যাটে: "' + dt + '"');
      }
      if (media === 'print') {
        const hidden = await ev("[...document.querySelectorAll('.no-print')].every(e=>getComputedStyle(e).display==='none')");
        check(hidden, 'প্রিন্টে বাটন/অ্যাকশন বার লুকানো');
      }
    }

    console.log('\n=== আসল PDF (Chrome-এর প্রিন্ট) ===');
    await load(1326, '');
    const pdf = await call('Page.printToPDF', { paperWidth: 8.27, paperHeight: 11.69, marginTop: 0.47, marginBottom: 0.47, marginLeft: 0.47, marginRight: 0.47, printBackground: true, preferCSSPageSize: true });
    const buf = Buffer.from(pdf.data, 'base64');
    const pages = (buf.toString('latin1').match(/\/Type\s*\/Page[^s]/g) || []).length;
    check(buf.length > 2000 && buf.slice(0, 4).toString() === '%PDF', 'PDF তৈরি হলো (' + Math.round(buf.length / 1024) + ' KB)');
    check(pages === 1, `ইনভয়েস ১ পাতায় ধরেছে (পাতা: ${pages})`);
    require('fs').writeFileSync(os.tmpdir() + '\\order_print.pdf', buf);

    // প্রিন্ট-মিডিয়ায় স্ক্রিনশট (চোখে দেখার জন্য)
    await load(794, 'print');
    const s = await call('Page.captureScreenshot', { format: 'png' });
    require('fs').writeFileSync(os.tmpdir() + '\\order_print_a4.png', Buffer.from(s.data, 'base64'));
    ws.close();
  } catch (e) { console.error('ERROR: ' + e.message); fail++; }
  finally { chrome.kill(); try { php('tools/make_admin_session.php', 'destroy', sid); } catch {} }

  console.log((fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exitCode = fail === 0 ? 0 : 1;
})();
