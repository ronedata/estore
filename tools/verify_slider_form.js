/**
 * tools/verify_slider_form.js — slider_form-এর show_text checkbox যাচাই
 * node tools/verify_slider_form.js [url] [id]
 */
const { spawn } = require('child_process');
const BASE = process.argv[2] || 'http://estore.test';
const SLIDER_ID = Number(process.argv[3] || 9);
const PORT = Number(process.env.PORT_SF || 9487);
const CHROME = process.env.NAV_BROWSER || 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

let pass = 0, fail = 0;
const ok = (m) => { pass++; console.log('  PASS  ' + m); };
const bad = (m) => { fail++; console.log('  FAIL  ' + m); };

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
  const chrome = spawn(CHROME, ['--headless=new', `--remote-debugging-port=${PORT}`, '--no-sandbox', '--disable-gpu', '--hide-scrollbars', 'about:blank'], { stdio: 'ignore' });
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
    await send(ws, id++, 'Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });

    const ev = async (expr) => {
      const r = await send(ws, id++, 'Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
      if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
      return r.result.value;
    };

    console.log('\n=== Slider Form: show_text checkbox ===\n');

    // admin সেশন কুকি (ঐচ্ছিক) — ADMIN_COOKIE="PHPSESSID=..." দিলে ব্যবহার হবে
    const cookie = process.env.ADMIN_COOKIE || '';
    if (cookie) {
      const m = cookie.match(/PHPSESSID=([^;]+)/);
      if (m) {
        await send(ws, id++, 'Network.enable');
        await send(ws, id++, 'Network.setCookie', {
          name: 'PHPSESSID', value: m[1], domain: new URL(BASE).hostname, path: '/'
        });
      }
    }

    await send(ws, id++, 'Page.navigate', { url: `${BASE}/admin/slider_form.php?id=${SLIDER_ID}` });
    await sleep(2500);

    const d = await ev(`(() => {
      const chk = document.getElementById('showTextChk');
      if (!chk) return { found: false };
      const cs = getComputedStyle(chk);
      const r = chk.getBoundingClientRect();
      const title = document.getElementById('slideTitle');
      const sub = document.getElementById('slideSubtitle');
      const label = document.querySelector('label[for="showTextChk"]');
      return {
        found: true,
        type: chk.type, role: chk.getAttribute('role'), name: chk.name, value: chk.value,
        checked: chk.checked,
        w: Math.round(r.width), h: Math.round(r.height),
        visible: r.width > 0 && r.height > 0 && cs.display !== 'none' && cs.visibility !== 'hidden',
        labelText: label ? label.textContent.trim() : '',
        titleDisabled: title ? title.disabled : null,
        subDisabled: sub ? sub.disabled : null,
        hint: (document.getElementById('textModeHint') || {}).textContent || '',
        previewShown: (() => { const el = document.getElementById('btnPreviewText'); return el ? getComputedStyle(el).display !== 'none' : null; })(),
        scrimShown: (() => { const el = document.getElementById('btnPreviewScrim'); return el ? getComputedStyle(el).display !== 'none' : null; })(),
        oldSelectPresent: !!document.getElementById('showTextSelect')
      };
    })()`);

    if (!d.found) throw new Error('showTextChk পাওয়া যায়নি');

    d.type === 'checkbox' ? ok('এলিমেন্ট checkbox') : bad(`type=${d.type}`);
    d.role === 'switch' ? ok('Bootstrap switch স্টাইল (role=switch)') : bad(`role=${d.role}`);
    if (d.visible && d.h >= 16) ok(`দৃশ্যমান, ${d.w}×${d.h}px`);
    else bad('checkbox অদৃশ্য/অতি ছোট');
    if (/ছবির ওপর টাইটেল/.test(d.labelText)) ok(`লেবেল: "${d.labelText}"`);
    else bad(`লেবেল: "${d.labelText}"`);
    if (!d.oldSelectPresent) ok('পুরোনো select সরানো হয়েছে');
    else bad('পুরোনো select-ও আছে');

    ok(`বর্তমান state: ${d.checked ? 'চালু (checked)' : 'বন্ধ (unchecked)'}`);
    if (d.titleDisabled === !d.checked) ok(`টাইটেল ইনপুট ${d.checked ? 'সক্রিয়' : 'নিষ্ক্রিয়'} — state অনুযায়ী সঠিক`);
    else bad(`টাইটেল ইনপুট disabled=${d.titleDisabled}, checked=${d.checked}`);
    if (d.subDisabled === !d.checked) ok(`সাবটাইটেল ইনপুট ${d.checked ? 'সক্রিয়' : 'নিষ্ক্রিয়'} — সঠিক`);
    else bad(`সাবটাইটেল ইনপুট disabled=${d.subDisabled}`);

    if (/চালু|বন্ধ/.test(d.hint)) ok(`হিন্ট: "${d.hint.trim()}"`);
    else bad(`হিন্ট: "${d.hint}"`);

    // টগল করে লাইভ প্রিভিউ দেখা যায় কিনা
    const toggled = await ev(`(() => {
      const chk = document.getElementById('showTextChk');
      const before = chk.checked;
      chk.click();
      const after = chk.checked;
      const title = document.getElementById('slideTitle');
      const wrap = document.getElementById('btnPreviewText');
      const scrim = document.getElementById('btnPreviewScrim');
      return {
        before, after,
        changed: before !== after,
        titleDisabled: title.disabled,
        wrapVisible: getComputedStyle(wrap).display !== 'none',
        scrimVisible: getComputedStyle(scrim).display !== 'none'
      };
    })()`);

    if (toggled.changed) ok('ক্লিকে state বদলায়');
    else bad('ক্লিকে state বদলায় না');
    if (toggled.titleDisabled === !toggled.after) ok(`টগল-পর ইনপুট ${toggled.after ? 'সক্রিয়' : 'নিষ্ক্রিয়'} — সঠিক`);
    else bad(`টগল-পর ইনপুট disabled=${toggled.titleDisabled}, checked=${toggled.after}`);
    if (!toggled.wrapVisible && !toggled.scrimVisible) ok('বন্ধ করলে প্রিভিউর টেক্সট ও স্ক্রিম লুকায়');
    else bad('বন্ধ করলেও প্রিভিউতে টেক্সট/স্ক্রিম আছে');

    // চেকবক্সে লেবেলে ক্লিক করলে টগল হয় (বড় টার্গেট)
    const labelClick = await ev(`(() => {
      const chk = document.getElementById('showTextChk');
      const before = chk.checked;
      document.querySelector('label[for="showTextChk"]').click();
      return { changed: before !== chk.checked };
    })()`);
    if (labelClick.changed) ok('লেবেলে ক্লিক করলেও টগল হয়');
    else bad('লেবেলে ক্লিক কাজ করে না');

    ws.close();
  } catch (e) { console.error('ERROR: ' + e.message); process.exitCode = 1; }
  finally { chrome.kill(); }

  console.log('\n' + (fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exitCode = fail === 0 ? 0 : 1;
})();
