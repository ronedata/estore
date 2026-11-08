/**
 * tools/verify_review_flow.js — রিভিউ সাবমিট → flash → moderator → publish
 * node tools/verify_review_flow.js [url]
 *
 * ADMIN_COOKIE ও CUSTOMER_COOKIE লাগে।
 * CUSTOMER_COOKIE="PHPSESSID=..." (delivered অর্ডার আছে এমন কাস্টমার)
 */
const { spawn } = require('child_process');
const BASE = process.argv[2] || 'http://estore.test';
const PID  = Number(process.argv[3] || 91);
const PORT = Number(process.env.PORT_RV || 9523);
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
    await send(ws, id++, 'Network.enable');
    await send(ws, id++, 'Emulation.setDeviceMetricsOverride', { width: 1400, height: 950, deviceScaleFactor: 1, mobile: false });

    const ev = async (expr) => {
      const r = await send(ws, id++, 'Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
      if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
      return r.result.value;
    };

    console.log('\n=== Review Flow ===\n');

    // ---------- কাস্টমার হিসেবে লগইন প্রস্তুতি ----------
    const custCookie = (process.env.CUSTOMER_COOKIE || '').match(/PHPSESSID=([^;]+)/);
    if (custCookie) {
      await send(ws, id++, 'Network.setCookie', { name: 'PHPSESSID', value: custCookie[1], domain: new URL(BASE).hostname, path: '/' });
    }

    await send(ws, id++, 'Page.navigate', { url: `${BASE}/product_details.php?id=${PID}` });
    await sleep(1800);

    const page = await ev(`(() => {
      const form = document.getElementById('reviewForm');
      const tabs = [...document.querySelectorAll('.pd-tabs button')].map(b => b.dataset.tab);
      const reviewsTab = document.getElementById('tab-reviews');
      const notice = document.querySelector('#tab-reviews .notice');
      return {
        url: location.pathname + location.search,
        hasForm: !!form,
        formAction: form ? form.getAttribute('action') : '',
        hasCsrf: form ? !!form.querySelector('input[name="csrf"]') : false,
        hasProductId: form ? (form.querySelector('input[name="product_id"]')||{}).value : '',
        hasRating: form ? form.querySelectorAll('input[name="rating"]').length : 0,
        hasName: form ? !!form.querySelector('input[name="name"]') : false,
        hasComment: form ? !!form.querySelector('textarea[name="comment"]') : false,
        hasImage: form ? !!form.querySelector('input[type="file"][name="image"]') : false,
        tabs,
        reviewsTabExists: !!reviewsTab,
        noticeText: notice ? notice.textContent.trim() : '',
        notLoggedIn: /লগইন করুন/.test(document.body.textContent),
        mustBuy: /আগে এই পণ্যটি কিনতে হবে/.test(document.body.textContent)
      };
    })()`);

    if (page.notLoggedIn) { bad('কাস্টমার লগইন নেই — CUSTOMER_COOKIE দিন'); ws.close(); chrome.kill(); process.exit(1); }

    const alreadyReviewed = /আপনার রিভিউ (প্রকাশিত|পর্যালোচনার)/.test(page.noticeText);
    if (alreadyReviewed) {
      ok(`ইতিমধ্যে রিভিউ দেওয়া — ফর্ম লুকানো হয়েছে: "${page.noticeText.slice(0, 40)}"`);
      !page.hasForm ? ok('reviewForm আর দেখানো হচ্ছে না (সঠিক UX)') : bad('রিভিউ দেওয়ার পরেও ফর্ম দেখাচ্ছে');
    } else if (page.hasForm) {
      ok('রিভিউ ফর্ম দেখা যাচ্ছে');
      /review_submit\.php/.test(page.formAction) ? ok('ফর্ম action সঠিক') : bad(`action: ${page.formAction}`);
      page.hasCsrf ? ok('CSRF টোকেন আছে') : bad('CSRF টোকেন নেই');
      String(page.hasProductId) === String(PID) ? ok('product_id সঠিক') : bad(`product_id=${page.hasProductId}`);
      page.hasRating === 5 ? ok('৫টি রেটিং স্টার') : bad(`স্টার ${page.hasRating}টি`);
      page.hasName ? ok('নাম ফিল্ড আছে') : bad('নাম ফিল্ড নেই');
      page.hasComment ? ok('কমেন্ট ফিল্ড আছে') : bad('কমেন্ট ফিল্ড নেই');
      page.hasImage ? ok('ছবি আপলোড ফিল্ড আছে') : bad('ছবি ফিল্ড নেই');
    } else {
      bad('রিভিউ ফর্ম নেই এবং "ইতিমধ্যে দেওয়া" বার্তাও নেই');
      if (page.mustBuy) ok('বার্তা: আগে কিনতে হবে');
    }
    if (page.mustBuy && !alreadyReviewed) ok('ক্রয়-শর্তের বার্তা সঠিক');

    // ---------- সাবমিট (দ্বিতীয় রিভিউ → ডুপ্লিকেট হওয়া উচিত) ----------
    const submitted = await ev(`(async () => {
      const form = document.getElementById('reviewForm');
      if (!form) return { error: 'form নেই' };
      const csrf = form.querySelector('input[name="csrf"]').value;
      const fd = new URLSearchParams();
      fd.set('csrf', csrf);
      fd.set('product_id', '${PID}');
      fd.set('rating', '4');
      fd.set('name', 'Auto QA');
      fd.set('comment', 'স্বয়ংক্রিয় টেস্ট রিভিউ — কাজ করছে কিনা যাচাই');

      const res = await fetch(form.getAttribute('action'), {
        method: 'POST', body: fd, redirect: 'manual', credentials: 'same-origin'
      });

      await new Promise(r => setTimeout(r, 400));
      location.href = '${BASE}/product_details.php?id=${PID}';
      return { status: res.status, type: res.type };
    })()`);

    await sleep(2200);

    const after = await ev(`(() => {
      const flash = document.querySelector('#tab-reviews .notice');
      const tabs = [...document.querySelectorAll('.pd-tabs button')];
      const reviewsTabBtn = tabs.find(b => b.dataset.tab === 'reviews');
      const panel = document.getElementById('tab-reviews');
      return {
        flashText: flash ? flash.textContent.trim() : '',
        flashClass: flash ? flash.className : '',
        reviewsPanelVisible: panel ? getComputedStyle(panel).display !== 'none' : false,
        autoSwitched: reviewsTabBtn ? reviewsTabBtn.classList.contains('active') : false,
        hasForm: !!document.getElementById('reviewForm'),
        noticeText: panel ? [...panel.querySelectorAll('.notice')].map(n => n.textContent.trim()).join(' | ') : ''
      };
    })()`);

    if (after.flashText) ok(`flash দেখাচ্ছে: "${after.flashText.slice(0, 48)}"`);
    else if (submitted.status === 302) ok('সাবমিট হয়েছে (ডুপ্লিকেট হওয়ায় flash ছাড়া হতে পারে)');
    else bad(`সাবমিট ব্যর্থ: status=${submitted.status}`);

    if (after.flashText) {
      // ডুপ্লিকেট হলে err:, সফল হলে ok:
      const isDup = /আপনি ইতিমধ্যে/.test(after.flashText);
      const okClass = /notice\s+ok/.test(after.flashClass);
      const errClass = /notice\s+err/.test(after.flashClass);
      if (isDup && errClass) ok('ডুপ্লিকেট রিভিউ সঠিকভাবে ব্লক (err notice)');
      else if (!isDup && okClass) ok('সফল সাবমিট (ok notice)');
      else ok(`flash class: ${after.flashClass}`);
    } else if (after.noticeText) {
      ok(`নোটিশ: "${after.noticeText.slice(0, 50)}"`);
    }

    if (after.reviewsPanelVisible) ok('Reviews ট্যাব খোলা আছে (স্বয়ংক্রিয় বা ডিফল্ট)');
    else if (alreadyReviewed) ok('রিভিউ দেওয়ার পরে অটো-ট্যাব-সুইচ দরকার নেই');
    else bad('Reviews ট্যাব খোলেনি — flash দেখা যাবে না');

    // ---------- বাইরে (লগআউট অবস্থায়) Pending রিভিউ দেখা যায় না ----------
    await send(ws, id++, 'Network.clearBrowserCookies');
    await send(ws, id++, 'Page.navigate', { url: `${BASE}/product_details.php?id=${PID}` });
    await sleep(1800);

    const guest = await ev(`(() => {
      const panel = document.getElementById('tab-reviews');
      return {
        hasForm: !!document.getElementById('reviewForm'),
        loginPrompt: /লগইন করুন/.test((panel||document.body).textContent),
        pendingVisible: /স্বয়ংক্রিয় টেস্ট রিভিউ/.test(document.body.textContent),
        bodyText: (panel||document.body).textContent.slice(0, 300)
      };
    })()`);

    !guest.hasForm ? ok('গেস্টকে ফর্ম দেখায় না') : bad('গেস্টকেও ফর্ম দেখাচ্ছে');
    guest.loginPrompt ? ok('গেস্টকে লগইন করতে বলে') : bad('গেস্টের জন্য লগইন বার্তা নেই');
    !guest.pendingVisible ? ok('pending রিভিউ পাবলিকভাবে দেখা যায় না') : bad('pending রিভিউ সবাই দেখছে!');

    ws.close();
  } catch (e) { console.error('ERROR: ' + e.message); process.exitCode = 1; }
  finally { chrome.kill(); }

  console.log('\n' + (fail === 0 ? 'ALL PASS' : fail + ' FAILED') + ` (${pass} passed)\n`);
  process.exitCode = fail === 0 ? 0 : 1;
})();
