/* Quote drawer - capture the enquiry on the page the buyer is looking at.
 *
 * WHY THIS EXISTS, measured against the live CRM on 12 Nov 2026:
 *   * 73 of ~89 web leads came from the HOMEPAGE (/#contact and /).
 *     All 160 other pages produced about 16 between them - because the
 *     homepage is the only page with a form on it. Every other page hands
 *     the visitor off to WhatsApp, their mail client, or back to the
 *     homepage, at the exact moment of peak intent.
 *   * 145 of 224 leads (65%) never said what they wanted to make, so the
 *     first reply is spent asking - and that round trip is where people
 *     go cold. On this drawer the product is already known.
 *   * 0 leads carried the ?from= page attribution. It is in the URL on
 *     every CTA and the CRM was storing source_page=/#contact, so the
 *     originating page was thrown away. The drawer sends the real path.
 *
 * IT FEEDS THE EXISTING PIPELINE, it does not bypass it. The same
 * /api/send-email.php endpoint, the same CSRF token from /api/csrf.php,
 * the same honeypot, GDPR consent, MX check, spam heuristics and
 * rate limit as the homepage form. A second lead path with weaker guards
 * would be a spam hole, and the CRM would hold two kinds of lead.
 *
 * PROGRESSIVE ENHANCEMENT. Every trigger is a real link that already works.
 * The drawer intercepts the click; if this script never loads, never parses
 * or throws, the link navigates to the homepage form exactly as before.
 * Nothing is removed, so there is no state in which a buyer cannot reach a
 * form.
 */
(function () {
  'use strict';

  var ENDPOINT = '/api/send-email.php';
  var CSRF_URL = '/api/csrf.php';
  var WHATSAPP = '923124815601';

  var drawer = null, lastFocus = null, csrfToken = null, sending = false;

  /* ---------- styles --------------------------------------------------- */
  function injectCss() {
    if (document.getElementById('fz-qd-css')) return;
    var s = document.createElement('style');
    s.id = 'fz-qd-css';
    s.textContent = [
      '.fz-qd-ov{position:fixed;inset:0;z-index:10000;background:rgba(8,10,12,.6);',
      'backdrop-filter:blur(2px);opacity:0;transition:opacity .25s ease}',
      '.fz-qd-ov.fz-qd-in{opacity:1}',
      '.fz-qd{position:fixed;top:0;right:0;bottom:0;z-index:10001;width:min(430px,100%);',
      'background:#16181b;color:#eef0f2;box-shadow:-20px 0 60px rgba(0,0,0,.5);',
      'display:flex;flex-direction:column;overflow-y:auto;',
      'font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;',
      /* the spring: a slight overshoot is what makes it feel physical */
      'transform:translateX(100%);transition:transform .42s cubic-bezier(.22,1.12,.36,1)}',
      '.fz-qd.fz-qd-in{transform:none}',
      '.fz-qd-hd{padding:22px 24px 14px;border-bottom:1px solid #262a30}',
      '.fz-qd-x{position:absolute;top:14px;right:14px;background:#20242a;border:0;color:#cfd5db;',
      'width:34px;height:34px;border-radius:50%;font-size:19px;line-height:1;cursor:pointer}',
      '.fz-qd-x:hover{background:#2b3036;color:#fff}',
      '.fz-qd-k{font:600 11px/1 ui-monospace,monospace;letter-spacing:.09em;color:#9aa3ad;',
      'text-transform:uppercase}',
      '.fz-qd-t{font:700 21px/1.25 Georgia,serif;margin:8px 0 0;color:#fff}',
      '.fz-qd-sub{font:400 13px/1.55 inherit;color:#aeb6bf;margin:7px 0 0}',
      '.fz-qd-bd{padding:18px 24px 26px;display:flex;flex-direction:column;gap:13px}',
      '.fz-qd-f{display:flex;flex-direction:column;gap:5px}',
      '.fz-qd-f label{font:600 12px/1 inherit;letter-spacing:.03em;color:#c3c9d0}',
      '.fz-qd-f input,.fz-qd-f textarea,.fz-qd-f select{background:#1c2026;border:1px solid #2d333a;',
      'border-radius:8px;color:#eef0f2;font:400 14px/1.4 inherit;padding:11px 12px;width:100%}',
      '.fz-qd-f input:focus,.fz-qd-f textarea:focus{outline:2px solid #4A7C59;outline-offset:1px;border-color:#4A7C59}',
      '.fz-qd-f textarea{min-height:76px;resize:vertical}',
      '.fz-qd-hp{position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden}',
      '.fz-qd-consent{display:flex;gap:9px;align-items:flex-start;font:400 12px/1.5 inherit;color:#aeb6bf}',
      '.fz-qd-consent input{width:16px;height:16px;margin-top:1px;flex:0 0 auto;accent-color:#4A7C59}',
      '.fz-qd-send{background:#4A7C59;color:#fff;border:0;border-radius:8px;padding:14px 18px;',
      'font:700 14px/1 inherit;letter-spacing:.03em;cursor:pointer;transition:background .18s}',
      '.fz-qd-send:hover:not(:disabled){background:#3d6749}',
      '.fz-qd-send:disabled{opacity:.6;cursor:default}',
      '.fz-qd-alt{text-align:center;font:400 12px/1.5 inherit;color:#8e969f;margin:2px 0 0}',
      '.fz-qd-alt a{color:#9ec4ab;text-decoration:underline}',
      '.fz-qd-msg{border-radius:8px;padding:12px 13px;font:500 13px/1.5 inherit;display:none}',
      '.fz-qd-msg.err{display:block;background:#3a1d1d;color:#ffc9c9;border:1px solid #5d2a2a}',
      '.fz-qd-msg.ok{display:block;background:#1d3a26;color:#bdf0cd;border:1px solid #2c5c3a}',
      '.fz-qd-done{padding:34px 24px;text-align:center}',
      '.fz-qd-done h3{font:700 20px/1.3 Georgia,serif;color:#fff;margin:0 0 9px}',
      '.fz-qd-done p{font:400 14px/1.6 inherit;color:#b9c0c8;margin:0}',
      '@media(prefers-reduced-motion:reduce){.fz-qd,.fz-qd-ov{transition:none}}'
    ].join('');
    document.head.appendChild(s);
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* The CSRF token is bound to the PHP session, so it is fetched same-origin
     and the cookie must ride along. Fetched when the drawer opens rather
     than at page load: a token collected minutes earlier against a session
     that has since rotated is a confusing failure at the moment of sending. */
  function getCsrf() {
    return fetch(CSRF_URL, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (j) { csrfToken = j && (j.csrf || j.token); return csrfToken; })
      .catch(function () { return null; });
  }

  function close() {
    if (!drawer) return;
    var ov = drawer.ov, panel = drawer.panel;
    ov.classList.remove('fz-qd-in');
    panel.classList.remove('fz-qd-in');
    var node = drawer;
    drawer = null;
    window.setTimeout(function () {
      if (node.ov.parentNode) node.ov.parentNode.removeChild(node.ov);
      if (node.panel.parentNode) node.panel.parentNode.removeChild(node.panel);
    }, 420);
    document.documentElement.style.overflow = '';
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }

  function open(ctx) {
    if (drawer) return;
    injectCss();

    /* close() detaches its nodes on a 420ms timer so the slide-out can be
       seen. Open another trigger inside that window - entirely normal, the
       product modal sits right behind this - and the old panel is still in
       the document, so two drawers overlap and querySelector finds the
       stale one. Anything left over goes now. */
    var stale = document.querySelectorAll('.fz-qd,.fz-qd-ov');
    for (var s = 0; s < stale.length; s++) {
      if (stale[s].parentNode) stale[s].parentNode.removeChild(stale[s]);
    }

    lastFocus = document.activeElement;

    var ov = document.createElement('div');
    ov.className = 'fz-qd-ov';

    var panel = document.createElement('aside');
    panel.className = 'fz-qd';
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    panel.setAttribute('aria-label', 'Request a quote');

    var heading = ctx.product
      ? esc(ctx.product)
      : 'Request a quote';
    var skuLine = ctx.sku ? '<div class="fz-qd-k">SKU ' + esc(ctx.sku) + '</div>' : '';
    var sub = ctx.product
      ? 'Tell us quantity and sizes and we will come back with a price, usually within 24 hours.'
      : 'Tell us what you need made. We reply with real prices, usually within 24 hours.';

    panel.innerHTML =
      '<button class="fz-qd-x" type="button" aria-label="Close">&times;</button>' +
      '<div class="fz-qd-hd">' + skuLine +
        '<h2 class="fz-qd-t">' + heading + '</h2>' +
        '<p class="fz-qd-sub">' + sub + '</p>' +
      '</div>' +
      '<form class="fz-qd-bd" novalidate>' +
        '<div class="fz-qd-msg" role="alert" aria-live="polite"></div>' +
        '<div class="fz-qd-f"><label for="fzqd-n">Your name *</label>' +
          '<input id="fzqd-n" name="name" type="text" autocomplete="name" required></div>' +
        '<div class="fz-qd-f"><label for="fzqd-e">Email *</label>' +
          '<input id="fzqd-e" name="email" type="email" autocomplete="email" required></div>' +
        '<div class="fz-qd-f"><label for="fzqd-q">How many pieces?</label>' +
          '<input id="fzqd-q" name="quantity" type="text" inputmode="numeric" ' +
          'placeholder="e.g. 50" autocomplete="off"></div>' +
        '<div class="fz-qd-f"><label for="fzqd-m">Anything else?</label>' +
          '<textarea id="fzqd-m" name="message" placeholder="Sizes, colours, fabric, deadline, your logo..."></textarea></div>' +
        /* Honeypot. Bots fill it, people never see it. */
        '<div class="fz-qd-hp" aria-hidden="true">' +
          '<label>Website<input name="website" type="text" tabindex="-1" autocomplete="off"></label></div>' +
        '<label class="fz-qd-consent"><input type="checkbox" name="gdpr" required>' +
          '<span>I agree that FABRIOZA may store these details to reply to my enquiry.</span></label>' +
        '<button class="fz-qd-send" type="submit">Send quote request</button>' +
        '<p class="fz-qd-alt">Prefer to chat? <a href="https://wa.me/' + WHATSAPP +
          '" rel="nofollow noopener" target="_blank">Message us on WhatsApp</a></p>' +
      '</form>';

    document.body.appendChild(ov);
    document.body.appendChild(panel);
    document.documentElement.style.overflow = 'hidden';
    drawer = { ov: ov, panel: panel, ctx: ctx };

    /* paint at the closed position, then animate in */
    void panel.offsetWidth;
    ov.classList.add('fz-qd-in');
    panel.classList.add('fz-qd-in');

    panel.querySelector('.fz-qd-x').addEventListener('click', close);
    ov.addEventListener('click', close);
    panel.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { close(); return; }
      if (e.key !== 'Tab') return;
      var f = panel.querySelectorAll('button,input,textarea,select,a[href]');
      if (!f.length) return;
      var first = f[0], last = f[f.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });

    window.setTimeout(function () {
      var n = panel.querySelector('#fzqd-n');
      if (n) n.focus();
    }, 120);

    getCsrf();
    panel.querySelector('form').addEventListener('submit', function (e) {
      e.preventDefault();
      submit(panel, ctx);
    });
  }

  function say(panel, cls, text) {
    var box = panel.querySelector('.fz-qd-msg');
    box.className = 'fz-qd-msg ' + cls;
    box.textContent = text;
  }

  function submit(panel, ctx) {
    if (sending) return;
    var form = panel.querySelector('form');
    var name = form.name.value.trim();
    var email = form.email.value.trim();

    if (!name) { say(panel, 'err', 'Please tell us your name.'); form.name.focus(); return; }
    if (!email || email.indexOf('@') < 1) {
      say(panel, 'err', 'Please enter a valid email so we can send the price.');
      form.email.focus(); return;
    }
    if (!form.gdpr.checked) {
      say(panel, 'err', 'Please tick the consent box so we may reply.');
      form.gdpr.focus(); return;
    }

    sending = true;
    var btn = panel.querySelector('.fz-qd-send');
    btn.disabled = true;
    btn.textContent = 'Sending...';

    /* The product and the page are the whole point of this drawer: they are
       exactly what 65% of leads arrive missing. */
    var note = form.message.value.trim();
    var body = ctx.product
      ? ('Enquiry about: ' + ctx.product + (ctx.sku ? ' (SKU ' + ctx.sku + ')' : '') +
         (note ? '\n\n' + note : ''))
      : (note || 'Quote request from ' + (ctx.page || 'the website') + '.');

    var payload = {
      name: name,
      email: email,
      quantity: form.quantity.value.trim(),
      message: body,
      product_type: ctx.product || 'Custom clothing',
      form_type: ctx.product ? 'Product Quote' : 'Quote Drawer',
      source_page: ctx.page || location.pathname,
      landing_page: location.pathname + location.search,
      referrer: document.referrer || '',
      website: form.website.value,        /* honeypot, sent as-is */
      gdpr_consent: true,
      csrf: csrfToken || ''
    };

    var send = function () {
      return fetch(ENDPOINT, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
      });
    };

    (csrfToken ? Promise.resolve(csrfToken) : getCsrf())
      .then(function (t) { payload.csrf = t || ''; return send(); })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
      .then(function (res) {
        sending = false;
        if (res.ok && res.j && res.j.success) {
          panel.querySelector('.fz-qd-hd').style.display = 'none';
          panel.querySelector('form').outerHTML =
            '<div class="fz-qd-done"><h3>Thank you &mdash; we have it.</h3>' +
            '<p>' + esc(res.j.message || 'We reply to every enquiry, usually within 24 hours.') +
            '</p></div>';
          return;
        }
        btn.disabled = false;
        btn.textContent = 'Send quote request';
        say(panel, 'err', (res.j && res.j.message) ||
          'Something went wrong sending that. Please email info@fabrioza.com.');
      })
      .catch(function () {
        sending = false;
        btn.disabled = false;
        btn.textContent = 'Send quote request';
        say(panel, 'err', 'We could not reach the server. Please email info@fabrioza.com.');
      });
  }

  /* ---------- triggers -------------------------------------------------- */
  function init() {
    /* Delegated, so it covers the product modal's button too - that markup
       does not exist until a card is clicked. */
    document.addEventListener('click', function (e) {
      var t = e.target && e.target.closest ?
              e.target.closest('[data-fz-quote],.fz-nav__cta') : null;
      if (!t) return;
      /* let people open the fallback link in a new tab as they expect */
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return;
      e.preventDefault();
      open({
        product: t.getAttribute('data-fz-quote-product') || '',
        sku: t.getAttribute('data-fz-quote-sku') || '',
        page: location.pathname
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  window.fzQuoteDrawer = { open: open, close: close };
})();
