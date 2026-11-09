(function () {
  'use strict';

  function store(mode) {
    try { return mode === 'session' ? window.sessionStorage : window.localStorage; } catch (e) { return null; }
  }

  function isDismissed(el) {
    var mode = el.getAttribute('data-ann-reshow'), key = el.getAttribute('data-ann-key');
    if (mode === 'always' || !key) return false;
    var st = store(mode), v;
    try { v = st && st.getItem(key); } catch (e) { return false; }
    if (!v) return false;
    if (mode === 'days') {
      var days = parseInt(el.getAttribute('data-ann-days'), 10) || 7;
      return (Date.now() - parseInt(v, 10)) < days * 86400000;
    }
    return true;
  }

  function dismiss(el) {
    var mode = el.getAttribute('data-ann-reshow'), key = el.getAttribute('data-ann-key');
    if (mode === 'always' || !key) return;
    var st = store(mode);
    try { if (st) st.setItem(key, String(Date.now())); } catch (e) {}
  }

  var bar = document.getElementById('annBar');
  if (bar) {
    if (isDismissed(bar)) {
      bar.parentNode.removeChild(bar);
    } else {
      var bx = document.getElementById('annBarClose');
      if (bx) bx.addEventListener('click', function () {
        dismiss(bar);
        if (bar.parentNode) bar.parentNode.removeChild(bar);
      });
    }
  }

  function initPopup() {
    var pop = document.getElementById('annPop');
    if (!pop || isDismissed(pop)) return;
    var box = pop.querySelector('.ann-pop-box');
    var delay = Math.max(0, parseInt(pop.getAttribute('data-ann-delay'), 10) || 0);
    var lastFocus = null;

    function focusables() {
      return [].slice.call(pop.querySelectorAll('a[href],button:not([disabled])')).filter(function (n) { return n.offsetParent !== null; });
    }
    function onKey(e) {
      if (e.key === 'Escape') { close(); return; }
      if (e.key !== 'Tab') return;
      var f = focusables();
      if (!f.length) { e.preventDefault(); box.focus(); return; }
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && (document.activeElement === first || document.activeElement === box)) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
    function open() {
      lastFocus = document.activeElement;
      pop.hidden = false;
      document.documentElement.classList.add('ann-lock');
      requestAnimationFrame(function () { pop.classList.add('is-open'); });
      document.addEventListener('keydown', onKey);
      box.focus();
    }
    function close() {
      dismiss(pop);
      pop.classList.remove('is-open');
      pop.hidden = true;
      document.documentElement.classList.remove('ann-lock');
      document.removeEventListener('keydown', onKey);
      if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
    }

    pop.addEventListener('click', function (e) {
      var t = e.target;
      while (t && t !== pop) {
        if (t.hasAttribute && t.hasAttribute('data-ann-close')) {
          close();
          return;
        }
        t = t.parentNode;
      }
    });

    setTimeout(open, delay * 1000);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initPopup);
  else initPopup();
})();
