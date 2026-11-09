/**
 * tools/verify_announcements.js — ঘোষণা বার ও পপআপ ফ্রন্ট-এন্ড যাচাই (headless Chrome)
 * node tools/verify_announcements.js [url]
 * নিজে টেস্ট ঘোষণা ([T] দিয়ে শুরু) বানায় ও শেষে মুছে ফেলে; mysql CLI (root) লাগে।
 */
const { spawn, execFileSync } = require('child_process');
const BASE = process.argv[2] || 'http://estore.test';
const PORT = Number(process.env.PORT_AN || 9473);
const CHROME = process.env.NAV_BROWSER || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const MYSQL = process.env.MYSQL_BIN || 'mysql';
const DB = process.env.DB_NAME || 'estore';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let pass = 0, fail = 0;
const ok = (m) => { pass++; console.log('  PASS  ' + m); };
const bad = (m) => { fail++; console.log('  FAIL  ' + m); };
const check = (c, m) => (c ? ok(m) : bad(m));

const sql = (q) => execFileSync(MYSQL, ['-uroot', '--default-character-set=utf8mb4', DB], { encoding: 'utf8', input: q + ';\n' });
const cleanup = () => { try { sql("DELETE FROM announcements WHERE message LIKE '[T]%' OR title LIKE '[T]%'"); } catch (e) {} };

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
  cleanup();
  sql("INSERT INTO announcements(kind,message,link_url,color,closable,reshow,reshow_days,active) VALUES('bar','[T] ঈদ অফার: ১০০০৳+ অর্ডারে ফ্রি ডেলিভারি','shop.php','orange',1,'days',7,1)");
  sql("INSERT INTO announcements(kind,title,message,link_url,btn_label,delay_sec,reshow,reshow_days,color,active) VALUES('popup','[T] ঈদ সেল!','সব পণ্যে ১০% ছাড়','shop.php','এখনই কিনুন',2,'never',7,'green',1)");
  const popupId = sql("SELECT MAX(id) FROM announcements WHERE kind='popup'").trim().split('\n').pop();

  const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, '--no-sandbox', '--disable-gpu', '--hide-scrollbars', '--user-data-dir=' + require('os').tmpdir() + '\\ann_prof_' + Date.now(), 'about:blank'], { stdio: 'ignore' });
  try {
    let ready = false;
    for (let i = 0; i < 40; i++) { try { await fetch(`http://127.0.0.1:${PORT}/json/version`); ready = true; break; } catch { await sleep(250); } }
    if (!ready) throw new Error('DevTools নেই');
    const t = await fetch(`http://127.0.0.1:${PORT}/json/new?about:blank`, { method: 'PUT' }).then((r) => r.json());
    const ws = new WebSocket(t.webSocketDebuggerUrl);
    await new Promise((res, rej) => { ws.addEventListener('open', res, { once: true }); ws.addEventListener('error', rej, { once: true }); });

    let id = 1;
    await send(ws, id++, 'Page.enable'); await send(ws, id++, 'Runtime.enable');
    await send(ws, id++, 'Network.enable'); await send(ws, id++, 'Network.setCacheDisabled', { cacheDisabled: true });
    const ev = async (expr) => {
      const r = await send(ws, id++, 'Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
      if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
      return r.result.value;
    };
    const go = async (path) => { await send(ws, id++, 'Page.navigate', { url: BASE + path }); await sleep(900); };
    const popHidden = () => ev("(()=>{const p=document.getElementById('annPop');return p?p.hidden:null})()");
    const resetStore = () => ev('localStorage.clear();sessionStorage.clear();true');
    const setPopup = (set) => sql(`UPDATE announcements SET ${set} WHERE id=${popupId}`);
    const setBar = (set) => sql(`UPDATE announcements SET ${set} WHERE kind='bar' AND message LIKE '[T]%'`);
    const press = (key, code) => send(ws, id++, 'Input.dispatchKeyEvent', { type: 'keyDown', key, code, windowsVirtualKeyCode: key === 'Escape' ? 27 : 0 });

    for (const [label, w, mobile] of [['ডেস্কটপ 1280', 1280, false], ['মোবাইল 390', 390, true]]) {
      console.log(`\n=== ${label}px ===`);
      await send(ws, id++, 'Emulation.setDeviceMetricsOverride', { width: w, height: 800, deviceScaleFactor: 1, mobile });
      setPopup("delay_sec=2, reshow='never', active=1"); setBar("reshow='days', closable=1, active=1");
      await go('/shop.php'); await resetStore();

      await go('/index.php');
      check(await ev("!!document.getElementById('annBar')"), 'হোমে বার আছে');
      check(await popHidden() === true, 'delay-র আগে পপআপ লুকানো');
      await sleep(2000);
      check(await popHidden() === false, 'delay (২ সেকেন্ড) পর পপআপ এল');
      const g = await ev(`(()=>{const b=document.querySelector('.ann-pop-box').getBoundingClientRect();const bar=document.getElementById('annBar').getBoundingClientRect();
        return {l:b.left,r:b.right,t:b.top,bt:b.bottom,w:innerWidth,h:innerHeight,lock:document.documentElement.classList.contains('ann-lock'),
          focus:document.activeElement===document.querySelector('.ann-pop-box'),hs:document.documentElement.scrollWidth>innerWidth+1,
          barW:Math.round(bar.width),barH:Math.round(bar.height),barBg:getComputedStyle(document.getElementById('annBar')).backgroundColor,
          btn:!!document.querySelector('.ann-pop-btn'),title:document.querySelector('.ann-pop-body h3')?.textContent}})()`);
      check(g.l >= 0 && g.r <= g.w && g.t >= 0 && g.bt <= g.h, 'পপআপ বক্স viewport-এর ভেতরে');
      check(g.lock, 'ব্যাকগ্রাউন্ড স্ক্রল বন্ধ');
      check(g.focus, 'ফোকাস পপআপ বক্সে');
      check(!g.hs, 'horizontal overflow নেই');
      check(g.barW >= w - 1 && g.barH >= 30, `বার পুরো প্রস্থে (${g.barW}×${g.barH})`);
      check(g.barBg === 'rgb(255, 112, 67)', 'বারের রং কমলা');
      check(g.btn && g.title && g.title.includes('ঈদ সেল'), 'পপআপে শিরোনাম ও বাটন');
      const shot = await send(ws, id++, 'Page.captureScreenshot', { format: 'png' });
      require('fs').writeFileSync(require('os').tmpdir() + `\\ann_${mobile ? 'mobile' : 'desktop'}.png`, Buffer.from(shot.data, 'base64'));

      await press('Escape', 'Escape'); await sleep(200);
      check(await popHidden() === true, 'Esc দিলে পপআপ বন্ধ');
      check(await ev("!document.documentElement.classList.contains('ann-lock')"), 'বন্ধে স্ক্রল ফিরেছে');
      await go('/index.php'); await sleep(2400);
      check(await popHidden() === true, '"আর দেখাবে না" — রিলোডে আসেনি');

      await go('/shop.php'); await sleep(2400);
      check(await ev("!!document.getElementById('annBar')"), 'অন্য পেজেও বার আছে');
      check(await ev("!document.getElementById('annPop')"), 'অন্য পেজে পপআপ নেই (শুধু হোম)');
      await ev("document.getElementById('annBarClose').click()");
      check(await ev("!document.getElementById('annBar')"), 'বারের ✕ চাপলে বার সরে গেল');
      await go('/cart.php');
      check(await ev("!document.getElementById('annBar')"), 'বার অন্য পেজেও আর আসে না (days)');

      // --- নিয়ন্ত্রণ: delay ---
      await resetStore(); setPopup("delay_sec=0, reshow='never'");
      await go('/index.php'); await sleep(400);
      check(await popHidden() === false, 'delay=০ হলে সাথে সাথে আসে');
      await resetStore(); setPopup("delay_sec=4, reshow='never'");
      await go('/index.php'); await sleep(2000);
      const early = await popHidden(); await sleep(2600);
      check(early === true && await popHidden() === false, 'delay=৪ হলে ২ সেকেন্ডে লুকানো, ৪+ এ এল');

      // --- নিয়ন্ত্রণ: কেটে দিলে আবার দেখাবে কি না ---
      const closeIt = async () => { await ev("document.querySelector('#annPop .ann-pop-x').click()"); await sleep(150); };
      await resetStore(); setPopup("delay_sec=0, reshow='always'");
      await go('/index.php'); await sleep(400); await closeIt();
      await go('/index.php'); await sleep(400);
      check(await popHidden() === false, 'reshow=always: কেটে দিলেও রিলোডে আবার আসে');

      await resetStore(); setPopup("delay_sec=0, reshow='session'");
      await go('/index.php'); await sleep(400); await closeIt();
      await go('/index.php'); await sleep(400);
      check(await popHidden() === true, 'reshow=session: একই সেশনে রিলোডে আসে না');
      await ev("sessionStorage.clear(); true"); await go('/index.php'); await sleep(400);
      check(await popHidden() === false, 'reshow=session: সেশন নতুন হলে (sessionStorage খালি) আবার আসে');

      await resetStore(); setPopup("delay_sec=0, reshow='days', reshow_days=7");
      await go('/index.php'); await sleep(400); await closeIt();
      await go('/index.php'); await sleep(400);
      check(await popHidden() === true, 'reshow=days: ৭ দিনের মধ্যে আসে না');
      await ev(`(()=>{for(const k of Object.keys(localStorage)) if(k.startsWith('ann_')) localStorage.setItem(k, String(Date.now()-8*86400000)); return true})()`);
      await go('/index.php'); await sleep(400);
      check(await popHidden() === false, 'reshow=days: ৮ দিন পর আবার আসে');

      await resetStore(); setPopup("delay_sec=0, reshow='never'");
      await go('/index.php'); await sleep(400); await closeIt();
      await go('/index.php'); await sleep(400);
      check(await popHidden() === true, 'reshow=never: আসে না');
      sql(`UPDATE announcements SET message='[T] সম্পাদিত লেখা', updated_at=NOW()+INTERVAL 1 MINUTE WHERE id=${popupId}`);
      await go('/index.php'); await sleep(400);
      check(await popHidden() === false, 'লেখা এডিট করলে আগে কেটে দেওয়া দর্শকও আবার দেখে');

      // --- ব্যাকড্রপ ক্লিকে বন্ধ ---
      await resetStore(); setPopup("delay_sec=0, reshow='never', message='[T] সব পণ্যে ১০% ছাড়'");
      await go('/index.php'); await sleep(400);
      await ev("document.querySelector('#annPop .ann-pop-backdrop').click()"); await sleep(150);
      check(await popHidden() === true, 'ব্যাকড্রপে ক্লিকে বন্ধ');

      // --- ছবি-ছাড়া/বার-ছাড়া ও নিষ্ক্রিয় ---
      await resetStore(); setPopup("active=0"); setBar("active=0");
      await go('/index.php'); await sleep(800);
      check(await ev("!document.getElementById('annBar') && !document.getElementById('annPop')"), 'নিষ্ক্রিয় করলে বার-পপআপ কিছুই আসে না');
      console.log('');
    }
    ws.close();
  } catch (e) { console.error('ERROR: ' + e.message); fail++; }
  finally { chrome.kill(); cleanup(); }

  console.log((fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exitCode = fail === 0 ? 0 : 1;
})();
