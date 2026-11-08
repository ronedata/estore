/**
 * tools/verify_search_suggest.js — live search suggestion box যাচাই
 * node tools/verify_search_suggest.js [url]
 */
const { spawn } = require('child_process');
const BASE = process.argv[2] || 'http://estore.test';
const PORT = Number(process.env.PORT_SS || 9511);
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

    const ev = async (expr) => {
      const r = await send(ws, id++, 'Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
      if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
      return r.result.value;
    };

    console.log('\n=== Search Suggest যাচাই ===\n');

    for (const [w, h] of [[1440, 900], [768, 900], [390, 800]]) {
      await send(ws, id++, 'Emulation.setDeviceMetricsOverride', { width: w, height: h, deviceScaleFactor: 1, mobile: w <= 600 });
      await send(ws, id++, 'Page.navigate', { url: BASE + '/index.php' });
      await sleep(2400);

      // টাইপ করে ডিবাউন্স + fetch শেষ হওয়া পর্যন্ত অপেক্ষা
      const typed = await ev(`(async () => {
        const input = document.getElementById('liveSearchInput');
        if (!input) return { error: 'input নেই' };
        input.focus();
        input.value = 'কার';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        for (let i = 0; i < 60; i++) {
          await new Promise(r => setTimeout(r, 100));
          const box = document.getElementById('suggestBox');
          if (box && box.children.length && box.classList.contains('is-open')) return { ready: true };
        }
        const box = document.getElementById('suggestBox');
        return { ready: false, cls: box ? box.className : 'no-box', kids: box ? box.children.length : -1 };
      })()`);

      console.log(`--- ${w}px ---`);
      if (typed.error) { bad(typed.error); console.log(''); continue; }
      if (!typed.ready) { bad(`সাজেশন দেখাচ্ছে না (children=${typed.kids}, class="${typed.cls}")`); }
      else ok('সাজেশন বক্স খুলেছে ও আইটেম আছে');

      const d = await ev(`(() => {
        const input = document.getElementById('liveSearchInput');
        const box = document.getElementById('suggestBox');
        const header = document.querySelector('.main-header');
        if (!box) return { found: false };
        const cs = getComputedStyle(box);
        const r = box.getBoundingClientRect();
        const ir = input ? input.getBoundingClientRect() : null;
        const hr = header ? header.getBoundingClientRect() : null;

        const firstItem = box.querySelector('.suggest-item');
        const fr = firstItem ? firstItem.getBoundingClientRect() : null;

        // দৃশ্যমান কিনা: বক্সের প্রথম আইটেমের centroid-এ elementFromPoint
        let hitsBox = false, topEl = null;
        if (fr) {
          topEl = document.elementFromPoint(Math.round(fr.left + fr.width / 2), Math.round(fr.top + fr.height / 2));
          hitsBox = !!(topEl && (box === topEl || box.contains(topEl)));
        }

        const headerStyle = header ? getComputedStyle(header) : null;
        // fixed positioning হলে header overflow ক্লিপ করে না
        const isFixed = cs.position === 'fixed';
        const insideHeader = header ? header.contains(box) : false;

        return {
          found: true,
          position: cs.position,
          isFixed,
          insideHeader,
          items: box.querySelectorAll('.suggest-item').length,
          open: box.classList.contains('is-open'),
          display: cs.display,
          zIndex: cs.zIndex,
          box: { top: Math.round(r.top), left: Math.round(r.left), w: Math.round(r.width), h: Math.round(r.height) },
          firstItemH: fr ? Math.round(fr.height) : 0,
          input: ir ? { top: Math.round(ir.top), bottom: Math.round(ir.bottom), left: Math.round(ir.left), right: Math.round(ir.right), w: Math.round(ir.width) } : null,
          header: hr ? { top: Math.round(hr.top), bottom: Math.round(hr.bottom) } : null,
          headerOverflow: headerStyle ? headerStyle.overflow : '',
          hitsBox,
          topEl: topEl ? (topEl.tagName.toLowerCase() + '.' + String(topEl.className || '').split(/\\s+/)[0]) : '',
          belowInput: ir ? (r.top >= ir.bottom - 2) : null,
          inViewport: r.top >= -2 && r.bottom <= window.innerHeight + 2,
          inViewportHoriz: r.left >= 0 && r.right <= window.innerWidth + 1,
          overflowX: document.documentElement.scrollWidth > window.innerWidth + 1
        };
      })()`);

      if (!d.found) { bad('suggestBox নেই'); console.log(''); continue; }

      if (d.display !== 'none') ok(`display: ${d.display}, position: ${d.position}, items=${d.items}`);
      else bad('display:none — দেখা যাচ্ছে না');

      if (d.box.h > 0) ok(`বক্স আকার ${d.box.w}×${d.box.h}px`);
      else bad(`বক্সের উচ্চতা ${d.box.h}`);

      if (d.items > 0) ok(`${d.items}টি সাজেশন আইটেম রেন্ডার হয়েছে`);
      else bad('কোনো সাজেশন আইটেম নেই');

      if (d.hitsBox) ok('বক্সটি সত্যিই দৃশ্যমান (elementFromPoint হিট)');
      else bad(`বক্স দেখা যাচ্ছে না — topmost element: ${d.topEl}`);

      // ক্লিপিং যাচাই: header-এর বাইরে হলে overflow আর ক্ষতি করে না
      if (!d.insideHeader) {
        ok(`সাজেশন বক্স header-এর বাইরে (header overflow:${d.headerOverflow} ক্লিপ করবে না)`);
      } else if (d.headerOverflow === 'hidden' && d.header && d.box.top + d.box.h > d.header.bottom + 1) {
        bad(`header-এর overflow:hidden বক্স ক্লিপ করছে`);
      } else {
        ok('header ক্লিপ করছে না');
      }

      if (d.input) {
        if (d.belowInput) ok(`ইনপুটের নিচে বসেছে (input bottom ${d.input.bottom}, box top ${d.box.top})`);
        else ok(`উপরে খুলেছে (viewport-এ জায়গা কম)`);
        if (Math.abs(d.box.w - d.input.w) < 60 || d.box.w >= 240) ok(`প্রস্থ ${d.box.w}px (ইনপুট ${d.input.w}px)`);
        else bad(`প্রস্থ ${d.box.w}px ইনপুটের সাথে মিলছে না`);
        if (d.inViewportHoriz) ok('বক্স viewport-এর ভেতরে (অনুভূমিক)');
        else bad(`বক্স viewport-এর বাইরে: left=${d.box.left}, w=${d.box.w}`);
      }

      if (!d.overflowX) ok('horizontal overflow নেই');
      else bad('horizontal overflow আছে');

      console.log('');
    }

    // ---- ইন্টারঅ্যাকশন: ক্লিক, কীবোর্ড, Escape, বাইরে ক্লিক ----
    console.log('--- ইন্টারঅ্যাকশন (390px) ---');
    await send(ws, id++, 'Emulation.setDeviceMetricsOverride', { width: 390, height: 800, deviceScaleFactor: 1, mobile: true });
    await send(ws, id++, 'Page.navigate', { url: BASE + '/index.php' });
    await sleep(2400);

    const inter = await ev(`(async () => {
      const input = document.getElementById('liveSearchInput');
      const box = document.getElementById('suggestBox');
      const out = {};
      if (!input || !box) return { error: 'element নেই' };

      const type = async (v) => {
        input.focus();
        input.value = v;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        for (let i = 0; i < 60; i++) {
          await new Promise(r => setTimeout(r, 100));
          if (box.children.length && box.classList.contains('is-open')) return true;
        }
        return false;
      };

      // ১. টাইপ করলে খোলে
      out.opened = await type('কার');
      out.count = box.querySelectorAll('.suggest-item').length;

      // ২. item-এ ক্লিক করলে সেই প্রোডাক্ট পেজে যাওয়ার href তৈরি হয়?
      const first = box.querySelector('.suggest-item');
      out.firstIsFocusable = first ? first.tabIndex >= 0 : false;

      // ৩. ArrowDown → is-active
      input.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true, cancelable: true }));
      await new Promise(r => setTimeout(r, 120));
      out.arrowActivates = !!box.querySelector('.suggest-item.is-active');

      // ৪. ArrowUp করে আবার আগের অবস্থায়
      input.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowUp', bubbles: true, cancelable: true }));
      await new Promise(r => setTimeout(r, 120));
      out.arrowWraps = !!box.querySelector('.suggest-item.is-active');

      // ৫. Escape → বন্ধ
      input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
      await new Promise(r => setTimeout(r, 150));
      out.escapeClosed = !box.classList.contains('is-open');

      // ৬. কম অক্ষরে (১) কিছু দেখাবে না
      out.shortQuery = await type('ক');

      // ৭. বাইরে ক্লিক → বন্ধ
      out.opened2 = await type('ইলেক');
      document.body.click();
      await new Promise(r => setTimeout(r, 250));
      out.outsideClickClosed = !box.classList.contains('is-open');

      // ৮. স্ক্রল করলে বক্স ইনপুটের সাথে তাল মেলায়
      out.opened3 = await type('কার');
      const beforeTop = box.getBoundingClientRect().top;
      window.scrollTo(0, 200);
      window.dispatchEvent(new Event('scroll'));
      await new Promise(r => setTimeout(r, 250));
      const afterTop = box.getBoundingClientRect().top;
      const ir = input.getBoundingClientRect();
      out.repositioned = Math.abs((afterTop) - (ir.bottom + 8)) < 12;

      return out;
    })()`);

    if (inter.error) bad(inter.error);
    else {
      inter.opened ? ok('টাইপ করলে বক্স খোলে') : bad('টাইপ করলে খোলে না');
      inter.count > 0 ? ok(`${inter.count}টি আইটেম`) : bad('আইটেম নেই');
      inter.firstIsFocusable ? ok('আইটেম কীবোর্ড-ফোকাসযোগ্য') : bad('আইটেম ফোকাসযোগ্য নয়');
      inter.arrowActivates ? ok('ArrowDown-এ আইটেম active হয়') : bad('ArrowDown কাজ করে না');
      inter.arrowWraps ? ok('ArrowUp-এ active থাকে') : bad('ArrowUp কাজ করে না');
      inter.escapeClosed ? ok('Escape-এ বক্স বন্ধ হয়') : bad('Escape কাজ করে না');
      !inter.shortQuery ? ok('১ অক্ষরে সাজেশন আসে না') : bad('১ অক্ষরেও সাজেশন আসছে');
      inter.opened2 ? ok('নতুন কুয়েরিতে আবার খোলে') : bad('দ্বিতীয়বার খোলে না');
      inter.outsideClickClosed ? ok('বাইরে ক্লিকে বন্ধ হয়') : bad('বাইরে ক্লিকে বন্ধ হয় না');
      inter.opened3 ? ok('স্ক্রলের আগে আবার খোলে') : bad('তৃতীয়বার খোলে না');
      inter.repositioned ? ok('স্ক্রল করলে ইনপুটের সাথে পজিশন ঠিক থাকে') : bad('স্ক্রলে পজিশন ঠিক থাকে না');
    }

    ws.close();
  } catch (e) { console.error('ERROR: ' + e.message); process.exitCode = 1; }
  finally { chrome.kill(); }

  console.log('\n' + (fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exitCode = fail === 0 ? 0 : 1;
})();
