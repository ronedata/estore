/* assets/js/district-picker.js — জেলা বাছাই: <select> কে খোঁজা-যোগ্য বাছাই-বক্সে (combobox) রূপ দেয়।
   চেকআউট (ক্রেতা) ও admin/order_edit.php (অ্যাডমিন) দুই জায়গায় ব্যবহৃত।
   আসল <select> লুকানো থাকে ও ফর্মে সেটাই জমা হয়; বাছলে 'change' ইভেন্ট ছোড়ে। JS না চললে সাধারণ select-ই থাকে।
   প্রতি অপশনে: data-en (English নাম), data-alt (বিকল্প বানান), data-fee (কার্যকর চার্জ); বিভাগ = <optgroup label>।
   ব্যবহার: DistrictPicker.enhance(selectEl, {buttonId, labelEl, showFees, fmtMoney, removeRequired, admin, blankPlain, title}) */
(function (global) {
  'use strict';

  var CSS = [
    '.dsel{position:relative}',
    '.dsel-btn{width:100%;min-height:46px;display:flex;align-items:center;justify-content:space-between;gap:8px;text-align:left;',
    '  padding:11px 13px;border:1px solid var(--border-color,#e5e7eb);border-radius:10px;background:#fff;font-size:16px;font-family:inherit;',
    '  color:var(--text-main,#0f172a);cursor:pointer;transition:border-color .15s,box-shadow .15s}',
    '.dsel-btn .dsel-val{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
    '.dsel-btn .dsel-val.ph{color:#94a3b8}',
    '.dsel-btn i{color:#94a3b8;font-size:.8rem;transition:transform .15s}',
    '.dsel.open .dsel-btn i{transform:rotate(180deg)}',
    '.dsel-btn:focus-visible,.dsel.open .dsel-btn{border-color:var(--brand-blue,#0284c7);box-shadow:0 0 0 3px rgba(2,132,199,.13);outline:none}',
    '.dsel.dsel-admin .dsel-btn{min-height:38px;padding:.375rem .75rem;border:1px solid #dee2e6;border-radius:.375rem;font-size:1rem;line-height:1.5}',
    '.dsel.dsel-admin .dsel-btn:focus-visible,.dsel.dsel-admin.open .dsel-btn{border-color:#86b7fe;box-shadow:0 0 0 .25rem rgba(13,110,253,.25)}',
    '.dsel-panel{position:fixed;z-index:2000;display:flex;flex-direction:column;background:#fff;border:1px solid var(--border-color,#e5e7eb);',
    '  border-radius:12px;box-shadow:0 16px 40px rgba(15,23,42,.2);overflow:hidden;box-sizing:border-box;font-family:inherit}',
    '.dsel-panel[hidden],.dsel-backdrop[hidden],.dsel-empty[hidden],.dsel-list[hidden]{display:none}',
    '.dsel-head{display:none}',
    '.dsel-search{position:relative;padding:10px;border-bottom:1px solid #eef2f7;flex:0 0 auto}',
    '.dsel-search i{position:absolute;left:23px;top:50%;transform:translateY(-50%);color:#94a3b8;pointer-events:none;font-size:.85rem}',
    '.dsel-search input{width:100%;box-sizing:border-box;padding:11px 13px 11px 36px;border:1px solid var(--border-color,#e5e7eb);border-radius:10px;',
    '  font-size:16px;font-family:inherit;color:var(--text-main,#0f172a);background:#fff;outline:none;-webkit-appearance:none;appearance:none}',
    '.dsel-search input:focus{border-color:var(--brand-blue,#0284c7);box-shadow:0 0 0 3px rgba(2,132,199,.13)}',
    '.dsel-list{list-style:none;margin:0;padding:6px;overflow-y:auto;flex:1 1 auto;min-height:0;overscroll-behavior:contain;-webkit-overflow-scrolling:touch}',
    '.dsel-group{font-size:.7rem;font-weight:800;color:#64748b;padding:9px 10px 4px;background:#fff;position:sticky;top:-6px;z-index:1}',
    '.dsel-opt{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:10px 12px;border-radius:9px;cursor:pointer;min-height:42px}',
    '.dsel-opt .txt{min-width:0;display:flex;flex-direction:column}',
    '.dsel-opt .nm{font-weight:700;font-size:.92rem;color:var(--text-main,#0f172a)}',
    '.dsel-opt .sub{font-size:.7rem;color:#94a3b8}',
    '.dsel-opt .fee{font-size:.78rem;font-weight:800;color:#475569;white-space:nowrap;flex:0 0 auto}',
    '.dsel-opt .fee.free{color:#2e7d32}',
    '.dsel-opt.active{background:#eff6ff}',
    '.dsel-opt[aria-selected="true"]{background:#f0f9ff;box-shadow:inset 3px 0 0 var(--brand-blue,#0284c7)}',
    '.dsel-opt[aria-selected="true"] .nm{color:var(--brand-blue,#0284c7)}',
    '.dsel-empty{padding:22px 12px;text-align:center;color:#94a3b8;font-size:.88rem}',
    '.dsel-sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}',
    'html.dsel-lock{overflow:hidden}',
    '@media (max-width:640px){',
    '  .dsel-panel{inset:0;width:auto;max-height:none!important;height:100vh;height:100dvh;border:0;border-radius:0;box-shadow:none;left:0!important;top:0!important;bottom:0!important}',
    '  .dsel-head{display:flex;align-items:center;justify-content:space-between;padding:calc(12px + env(safe-area-inset-top,0px)) 14px 8px;flex:0 0 auto}',
    '  .dsel-head strong{font-size:1.05rem}',
    '  .dsel-close{width:40px;height:40px;border:0;border-radius:50%;background:#f1f5f9;color:#334155;cursor:pointer;font-size:1rem}',
    '  .dsel-opt{min-height:50px;padding:11px 12px}',
    '  .dsel-opt .nm{font-size:1rem}',
    '  .dsel-list{padding-bottom:calc(14px + env(safe-area-inset-bottom,0px))}',
    '}'
  ].join('\n');

  function injectCss() {
    if (document.getElementById('dselCss')) return;
    var st = document.createElement('style');
    st.id = 'dselCss';
    st.textContent = CSS;
    document.head.appendChild(st);
  }

  function norm(s) { return String(s || '').toLowerCase().replace(/['’`.]/g, '').replace(/\s+/g, ' ').trim(); }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  function enhance(sel, o) {
    o = o || {};
    if (!sel || sel.dataset.dselDone === '1') return null;

    var items = [];
    sel.querySelectorAll('optgroup').forEach(function (g) {
      var div = g.label.replace(/\s*বিভাগ$/, '');
      g.querySelectorAll('option').forEach(function (op) {
        var bn = op.textContent.trim(), en = op.dataset.en || '', alt = op.dataset.alt || '';
        items.push({
          id: op.value, bn: bn, en: en, division: div, fee: Number(op.dataset.fee || 0),
          hay: norm(bn + ' ' + en + ' ' + alt), names: [norm(bn), norm(en)].concat(norm(alt).split(' '))
        });
      });
    });
    if (!items.length) return null;
    sel.dataset.dselDone = '1';
    injectCss();

    var fmt = o.fmtMoney || function (n) { return '৳' + Math.round(n).toLocaleString('en-US'); };
    var blankOpt = null;
    for (var i = 0; i < sel.options.length; i++) { if (sel.options[i].value === '') { blankOpt = sel.options[i]; break; } }
    var blankText = blankOpt ? blankOpt.textContent.trim() : 'জেলা বাছুন';
    var btnId = o.buttonId || 'districtBtn';

    if (o.removeRequired !== false) sel.required = false;
    sel.hidden = true;
    sel.tabIndex = -1;

    var wrap = document.createElement('div');
    wrap.className = 'dsel' + (o.admin ? ' dsel-admin' : '');
    wrap.innerHTML =
      '<button type="button" class="dsel-btn" id="' + btnId + '" aria-haspopup="listbox" aria-expanded="false" aria-controls="dselPanel">' +
      '<span class="dsel-val ph"></span><i class="fa-solid fa-chevron-down" aria-hidden="true"></i></button>';
    sel.parentNode.insertBefore(wrap, sel.nextSibling);
    var btn = wrap.querySelector('.dsel-btn'), valEl = wrap.querySelector('.dsel-val');
    if (o.labelEl) o.labelEl.setAttribute('for', btnId);

    var panel = document.createElement('div');
    panel.className = 'dsel-panel'; panel.id = 'dselPanel'; panel.hidden = true;
    panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', o.title || 'জেলা বাছুন');
    panel.innerHTML =
      '<div class="dsel-head"><strong>' + esc(o.title || 'জেলা বাছুন') + '</strong><button type="button" class="dsel-close" aria-label="বন্ধ করুন"><i class="fa-solid fa-xmark"></i></button></div>' +
      '<div class="dsel-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>' +
      '<input type="search" id="dselInput" role="combobox" aria-expanded="true" aria-controls="dselList" aria-autocomplete="list" ' +
      'placeholder="' + esc(o.placeholder || 'জেলার নাম লিখুন (বাংলা বা English)') + '" autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="done"></div>' +
      '<ul class="dsel-list" id="dselList" role="listbox" aria-label="জেলা"></ul>' +
      '<div class="dsel-empty" hidden>কোনো জেলা পাওয়া যায়নি</div>' +
      '<div class="dsel-sr" aria-live="polite"></div>';
    document.body.appendChild(panel);
    var backdrop = document.createElement('div');
    backdrop.className = 'dsel-backdrop'; backdrop.hidden = true;
    document.body.appendChild(backdrop);

    var input = panel.querySelector('#dselInput'), list = panel.querySelector('#dselList');
    var empty = panel.querySelector('.dsel-empty'), sr = panel.querySelector('.dsel-sr');
    var mq = window.matchMedia('(max-width: 640px)');
    var shown = [], active = -1, isOpen = false;

    function feeTxt(it) {
      if (!o.showFees) return '';
      return it.fee === 0 ? '<span class="fee free">ফ্রি</span>' : '<span class="fee">' + fmt(it.fee) + '</span>';
    }
    function setLabel() {
      var cur = items.filter(function (x) { return x.id === sel.value; })[0];
      if (cur) { valEl.textContent = cur.bn; valEl.classList.remove('ph'); }
      else { valEl.textContent = blankText; valEl.classList.toggle('ph', !o.blankPlain); }
    }

    function render(q) {
      q = norm(q);
      var rows;
      if (q === '') rows = items.slice();
      else {
        rows = items.filter(function (x) { return x.hay.indexOf(q) !== -1; });
        rows.sort(function (a, b) {
          var as = a.names.some(function (n) { return n.indexOf(q) === 0; }) ? 0 : 1;
          var bs = b.names.some(function (n) { return n.indexOf(q) === 0; }) ? 0 : 1;
          return as - bs;
        });
      }
      shown = rows;
      var html = '', lastDiv = null;
      rows.forEach(function (it, idx) {
        if (q === '' && it.division !== lastDiv) { html += '<li class="dsel-group" role="presentation">' + esc(it.division) + ' বিভাগ</li>'; lastDiv = it.division; }
        var selected = it.id === sel.value;
        html += '<li class="dsel-opt" role="option" id="dselo' + it.id + '" data-idx="' + idx + '" aria-selected="' + (selected ? 'true' : 'false') + '">' +
          '<span class="txt"><span class="nm">' + esc(it.bn) + '</span><span class="sub">' + esc(it.en) + (q !== '' ? ' · ' + esc(it.division) : '') + '</span></span>' + feeTxt(it) + '</li>';
      });
      list.innerHTML = html;
      empty.hidden = rows.length > 0;
      list.hidden = rows.length === 0;
      sr.textContent = rows.length ? rows.length + 'টি জেলা পাওয়া গেছে' : 'কোনো জেলা পাওয়া যায়নি';
      var ai = -1;
      if (q === '') ai = rows.findIndex(function (x) { return x.id === sel.value; });
      setActive(ai >= 0 ? ai : (rows.length ? 0 : -1), ai >= 0);
    }
    function setActive(i, scroll) {
      active = i;
      list.querySelectorAll('.dsel-opt.active').forEach(function (e) { e.classList.remove('active'); });
      if (i < 0 || !shown[i]) { input.removeAttribute('aria-activedescendant'); return; }
      var el = document.getElementById('dselo' + shown[i].id);
      if (!el) return;
      el.classList.add('active');
      input.setAttribute('aria-activedescendant', el.id);
      if (scroll !== false) el.scrollIntoView({ block: 'nearest' });
    }

    function place() {
      if (mq.matches) { panel.style.cssText = ''; return; }
      var r = btn.getBoundingClientRect(), vh = window.innerHeight, vw = window.innerWidth, gap = 6;
      var pw = Math.max(r.width, o.minWidth || 0);
      var pl = Math.max(8, Math.min(r.left, vw - pw - 8));
      var below = vh - r.bottom - gap - 8, above = r.top - gap - 8;
      var up = below < 240 && above > below;
      var maxH = Math.max(180, Math.min(380, up ? above : below));
      panel.style.left = pl + 'px';
      panel.style.width = pw + 'px';
      panel.style.maxHeight = maxH + 'px';
      if (up) { panel.style.top = 'auto'; panel.style.bottom = (vh - r.top + gap) + 'px'; }
      else { panel.style.bottom = 'auto'; panel.style.top = (r.bottom + gap) + 'px'; }
    }

    function open() {
      if (isOpen) return;
      isOpen = true;
      panel.hidden = false;
      wrap.classList.add('open');
      btn.setAttribute('aria-expanded', 'true');
      if (mq.matches) document.documentElement.classList.add('dsel-lock');
      input.value = '';
      render('');
      place();
      input.focus({ preventScroll: true });
    }
    function close(refocus) {
      if (!isOpen) return;
      isOpen = false;
      panel.hidden = true;
      backdrop.hidden = true;
      wrap.classList.remove('open');
      btn.setAttribute('aria-expanded', 'false');
      document.documentElement.classList.remove('dsel-lock');
      if (refocus !== false) btn.focus({ preventScroll: true });
    }
    function choose(i) {
      var it = shown[i];
      if (!it) return;
      sel.value = it.id;
      sel.dispatchEvent(new Event('change', { bubbles: true }));
      setLabel();
      close(true);
    }

    btn.addEventListener('click', function () { isOpen ? close() : open(); });
    btn.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); open(); }
    });
    panel.querySelector('.dsel-close').addEventListener('click', function () { close(); });
    backdrop.addEventListener('click', function () { close(); });
    input.addEventListener('input', function () { render(input.value); });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') { e.preventDefault(); if (shown.length) setActive(Math.min(shown.length - 1, active + 1)); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); if (shown.length) setActive(Math.max(0, active - 1)); }
      else if (e.key === 'Home') { if (shown.length) { e.preventDefault(); setActive(0); } }
      else if (e.key === 'End') { if (shown.length) { e.preventDefault(); setActive(shown.length - 1); } }
      else if (e.key === 'Enter') { e.preventDefault(); if (active >= 0) choose(active); }
      else if (e.key === 'Escape') { e.preventDefault(); close(); }
      else if (e.key === 'Tab') { close(false); }
    });
    list.addEventListener('click', function (e) {
      var li = e.target.closest ? e.target.closest('.dsel-opt') : null;
      if (li) choose(Number(li.dataset.idx));
    });
    list.addEventListener('mousemove', function (e) {
      var li = e.target.closest ? e.target.closest('.dsel-opt') : null;
      if (li && Number(li.dataset.idx) !== active) setActive(Number(li.dataset.idx), false);
    });
    document.addEventListener('mousedown', function (e) {
      if (isOpen && !mq.matches && !panel.contains(e.target) && !wrap.contains(e.target)) close(false);
    });
    window.addEventListener('resize', function () { if (isOpen) place(); });
    window.addEventListener('scroll', function () { if (isOpen && !mq.matches) place(); }, true);
    sel.addEventListener('change', setLabel);
    setLabel();

    return { open: open, close: close, refresh: setLabel };
  }

  global.DistrictPicker = { enhance: enhance };
})(window);
