/* FABRIOZA feature popup.
 *
 * One popup, three differentiators: custom fabric, duty paid, and who carries
 * the risk if a parcel goes missing. Deliberately ONE and not one per offer -
 * two popups competing for the same visitor is hostile, and Google treats
 * stacked interstitials as a mobile ranking problem rather than a marketing
 * choice.
 *
 * Behaviour, in the order it matters:
 *   - shows on whichever comes first: 25s dwell, 50% scroll, or exit intent
 *     (desktop only - there is no cursor to leave the viewport on a phone)
 *   - once per visitor per 7 days, and never twice in one session
 *   - MOBILE GETS A BOTTOM SHEET, not a full-screen modal. An interstitial
 *     that covers a phone screen is the specific pattern Google demotes.
 *   - Esc and the X close it, focus is trapped while open and returned after
 *   - every view, click and close is counted server-side
 *
 * Storage is wrapped in try/catch throughout: in a private window or with
 * site data blocked, localStorage throws on access rather than returning
 * null, and an uncaught throw here would take the popup down on every page.
 */
(function () {
  'use strict';

  var KEY = 'fab_popup_seen_at';
  var DAYS = 7;
  var DWELL_MS = 25000;
  var SCROLL_AT = 0.5;
  var TARGET = '/custom-fabric-development';

  // Pages where a popup is the wrong thing to do: the page it advertises,
  // anywhere someone is mid-enquiry, and the tools.
  var BLOCKED = ['/custom-fabric-development', '/contact', '/team-designer',
                 '/thank-you', '/checkout', '/payment'];
  var path = location.pathname.replace(/\/+$/, '') || '/';
  for (var i = 0; i < BLOCKED.length; i++) {
    if (path === BLOCKED[i] || path.indexOf(BLOCKED[i] + '/') === 0) { return; }
  }
  if (path.indexOf('/admin') === 0) { return; }

  function recently() {
    try {
      var t = parseInt(localStorage.getItem(KEY) || '0', 10);
      return t && (Date.now() - t) < DAYS * 86400000;
    } catch (e) { return false; }   // storage blocked: treat as not seen
  }
  function remember() {
    try { localStorage.setItem(KEY, String(Date.now())); } catch (e) {}
  }
  if (recently()) { return; }

  var shown = false, el = null, lastFocus = null;

  function stat(ev) {
    try {
      var b = new Blob([JSON.stringify({ event: ev, path: path })],
                       { type: 'application/json' });
      // sendBeacon survives the page being closed, which is exactly when a
      // "closed" or "clicked" event would otherwise be lost.
      if (navigator.sendBeacon) { navigator.sendBeacon('/api/popup-stat.php', b); }
      else { fetch('/api/popup-stat.php', { method: 'POST', body: b, keepalive: true }); }
    } catch (e) {}
  }

  function close(reason) {
    if (!el) { return; }
    stat(reason || 'closed');
    document.removeEventListener('keydown', onKey, true);
    el.remove(); el = null;
    if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
  }

  function onKey(e) {
    if (e.key === 'Escape') { e.preventDefault(); close('closed_esc'); return; }
    if (e.key !== 'Tab' || !el) { return; }
    var f = el.querySelectorAll('a[href], button');
    if (!f.length) { return; }
    var first = f[0], last = f[f.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }

  function show() {
    if (shown || el || recently()) { return; }
    shown = true;
    remember();
    lastFocus = document.activeElement;

    var mobile = window.matchMedia('(max-width: 768px)').matches;
    el = document.createElement('div');
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    el.setAttribute('aria-labelledby', 'fabPopTitle');

    var shell = mobile
      ? 'position:fixed;left:0;right:0;bottom:0;z-index:9998;background:#fff;' +
        'border-radius:16px 16px 0 0;box-shadow:0 -8px 30px rgba(0,0,0,.22);' +
        'padding:18px 18px calc(18px + env(safe-area-inset-bottom,0px));' +
        'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;' +
        'max-height:72vh;overflow:auto'
      : 'position:fixed;right:22px;bottom:22px;z-index:9998;width:380px;max-width:calc(100vw - 44px);' +
        'background:#fff;border-radius:14px;box-shadow:0 14px 44px rgba(0,0,0,.26);padding:20px;' +
        'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif';
    el.setAttribute('style', shell);

    el.innerHTML =
      '<button type="button" aria-label="Close" data-x style="position:absolute;top:8px;right:10px;' +
      'background:none;border:0;font-size:22px;line-height:1;color:#6b6b6b;cursor:pointer;padding:4px 8px">&times;</button>' +
      '<p id="fabPopTitle" style="margin:0 6px 8px 0;font-size:1.12rem;font-weight:700;color:#0e2744">' +
      'Building a bigger collection?</p>' +
      '<p style="margin:0 0 10px;font-size:.93rem;color:#333;line-height:1.5">' +
      'Get fresh fabric developed only for your brand &mdash; 300+ pieces, ready in ' +
      '4&ndash;6 working days.</p>' +
      '<ul style="margin:0 0 12px;padding:0;list-style:none;font-size:.88rem;color:#333;line-height:1.75">' +
      '<li><strong style="color:#0b3d2e">Your own fabric</strong> &mdash; composition, GSM and Pantone, exclusive to you</li>' +
      '<li><strong style="color:#0b3d2e">Duty paid</strong> &mdash; nothing to pay when it arrives</li>' +
      '<li><strong style="color:#0b3d2e">Lost in transit?</strong> &mdash; we remake and reship at our cost</li>' +
      '</ul>' +
      '<a data-go href="' + TARGET + '" style="display:inline-block;background:#4A7C59;color:#fff;' +
      'text-decoration:none;border-radius:9px;padding:10px 16px;font-size:.95rem">See how it works</a> ' +
      '<button type="button" data-x style="background:none;border:0;color:#6b6b6b;font-size:.9rem;' +
      'cursor:pointer;padding:10px 6px">Not now</button>';

    document.body.appendChild(el);
    stat('view');

    el.querySelectorAll('[data-x]').forEach(function (b) {
      b.addEventListener('click', function () { close('closed'); });
    });
    var go = el.querySelector('[data-go]');
    go.addEventListener('click', function () { stat('click'); });
    document.addEventListener('keydown', onKey, true);
    try { go.focus({ preventScroll: true }); } catch (e) {}
  }

  var timer = setTimeout(show, DWELL_MS);

  function onScroll() {
    var h = document.documentElement.scrollHeight - window.innerHeight;
    if (h > 0 && (window.scrollY / h) >= SCROLL_AT) {
      window.removeEventListener('scroll', onScroll);
      clearTimeout(timer);
      show();
    }
  }
  window.addEventListener('scroll', onScroll, { passive: true });

  // Exit intent: desktop only, and only a real exit through the TOP of the
  // viewport. Watching every edge fires on anyone reaching for a scrollbar.
  if (!window.matchMedia('(max-width: 768px)').matches) {
    document.addEventListener('mouseout', function (e) {
      if (!e.relatedTarget && e.clientY <= 0) { clearTimeout(timer); show(); }
    });
  }
})();
