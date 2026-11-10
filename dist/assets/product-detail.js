/*!
 * FABRIOZA - product detail view.
 *
 * THE PROBLEM THIS SOLVES. 349 product cards across 13 collection pages
 * were plain <div>s. A buyer could see a garment, a price and a SKU and
 * could not click any of it - no detail, no bigger image, no way to ask
 * about that specific product. Every one of those cards was a dead end.
 *
 * ONE FILE, LOADED BY EVERY COLLECTION PAGE. The card markup is identical
 * across all 13, so this reads what is already in the DOM rather than
 * carrying its own copy of the catalogue. Nothing here needs updating
 * when a product changes: edit the card, the panel follows.
 *
 * NO 3D, DELIBERATELY. Each product has exactly one photograph - the
 * files that look like alternate angles are blog images. A fake 3D spin
 * built from one flat photo looks like what it is. What a buyer actually
 * needs is a bigger picture, the full spec, and a way to ask about THAT
 * garment, so that is what this does. Real 360 needs real photography.
 *
 * Vanilla, no dependencies, no build step: these pages are static HTML
 * and there is no front-end source in the repo to rebuild.
 */
(function () {
  'use strict';

  var WHATSAPP = '923124815601';   // already used by the floating button
  var EMAIL = 'info@fabrioza.com';
  var reduceMotion = window.matchMedia &&
      window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- styles ------------------------------------------------- */
  /* Injected rather than shipped as a separate file: one request fewer on
     a site whose owner's customers already report it as slow. */
  var CSS = [
    '.fz-card-clickable{cursor:pointer;transition:transform .18s ease,box-shadow .18s ease}',
    '.fz-card-clickable:hover{transform:translateY(-4px)}',
    '.fz-card-clickable:focus-visible{outline:3px solid #4a8f5b;outline-offset:3px}',
    '.fz-view{position:absolute;left:12px;bottom:12px;font:600 12px/1 system-ui,sans-serif;',
    'letter-spacing:.04em;text-transform:uppercase;padding:7px 11px;border-radius:999px;',
    'background:rgba(0,0,0,.62);color:#fff;opacity:0;transition:opacity .18s ease;pointer-events:none}',
    '.fz-card-clickable:hover .fz-view,.fz-card-clickable:focus-visible .fz-view{opacity:1}',
    '.fz-ov{position:fixed;inset:0;z-index:9998;background:rgba(8,10,12,.78);',
    'backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center;padding:20px}',
    '.fz-dlg{position:relative;z-index:9999;max-width:980px;width:100%;max-height:92vh;overflow:auto;',
    'background:#16181b;color:#eef0f2;border-radius:16px;box-shadow:0 24px 70px rgba(0,0,0,.6);',
    'display:grid;grid-template-columns:minmax(0,1.05fr) minmax(0,1fr);gap:0}',
    '@media(max-width:760px){.fz-dlg{grid-template-columns:1fr;max-height:94vh}}',
    '.fz-img{background:#fff;display:flex;align-items:center;justify-content:center;padding:18px;',
    'border-radius:16px 0 0 16px}',
    '@media(max-width:760px){.fz-img{border-radius:16px 16px 0 0;padding:12px}}',
    '.fz-img img{max-width:100%;max-height:62vh;object-fit:contain;cursor:zoom-in;',
    'transition:transform .25s ease}',
    '.fz-img img.fz-zoom{transform:scale(1.7);cursor:zoom-out}',
    '.fz-body{padding:26px 28px 24px;display:flex;flex-direction:column;gap:12px}',
    '.fz-sku{font:600 11px/1 ui-monospace,monospace;letter-spacing:.09em;color:#9aa3ad}',
    '.fz-title{font:700 25px/1.22 Georgia,serif;margin:0;color:#fff}',
    '.fz-desc{font:400 15px/1.6 system-ui,sans-serif;color:#c3c9d0;margin:0}',
    '.fz-facts{display:flex;flex-wrap:wrap;gap:8px;margin:2px 0 4px}',
    '.fz-fact{font:600 13px/1 system-ui,sans-serif;padding:9px 12px;border-radius:9px;',
    'background:#20242a;color:#dfe4e9;border:1px solid #2c3138}',
    '.fz-fact b{color:#fff;font-weight:700}',
    '.fz-note{font:400 13px/1.55 system-ui,sans-serif;color:#9aa3ad;margin:0}',
    '.fz-cta{display:flex;flex-wrap:wrap;gap:10px;margin-top:6px}',
    '.fz-btn{font:600 14px/1 system-ui,sans-serif;padding:13px 17px;border-radius:10px;',
    'text-decoration:none;display:inline-flex;align-items:center;gap:8px;border:1px solid transparent}',
    '.fz-btn-1{background:#2f7d4f;color:#fff}.fz-btn-1:hover{background:#36905b}',
    '.fz-btn-2{background:transparent;color:#dfe4e9;border-color:#343a42}',
    '.fz-btn-2:hover{border-color:#4a525c}',
    '.fz-x{position:absolute;top:10px;right:12px;z-index:2;background:rgba(0,0,0,.5);color:#fff;',
    'border:0;width:38px;height:38px;border-radius:50%;font-size:21px;line-height:1;cursor:pointer}',
    '.fz-x:hover{background:rgba(0,0,0,.75)}',
    '.fz-x:focus-visible{outline:3px solid #4a8f5b;outline-offset:2px}',
    reduceMotion ? '' :
      '.fz-ov{animation:fzIn .16s ease}.fz-dlg{animation:fzUp .2s cubic-bezier(.2,.7,.3,1)}' +
      '@keyframes fzIn{from{opacity:0}to{opacity:1}}' +
      '@keyframes fzUp{from{opacity:0;transform:translateY(14px) scale(.985)}to{opacity:1;transform:none}}'
  ].join('');

  function injectCss() {
    if (document.getElementById('fz-pd-css')) return;
    var st = document.createElement('style');
    st.id = 'fz-pd-css';
    st.textContent = CSS;
    document.head.appendChild(st);
  }

  /* ---------- read a card -------------------------------------------- */
  function txt(card, sel) {
    var el = card.querySelector(sel);
    return el ? el.textContent.trim() : '';
  }

  function readCard(card) {
    var img = card.querySelector('img');
    var sku = txt(card, '.sku').replace(/^SKU:\s*/i, '');
    return {
      sku: sku,
      name: txt(card, 'h3') || (img && img.alt) || 'Product',
      desc: txt(card, '.desc'),
      price: txt(card, '.price'),
      moq: txt(card, '.moq'),
      tag: txt(card, '.tag'),
      img: img ? img.getAttribute('src') : '',
      alt: img ? (img.getAttribute('alt') || '') : ''
    };
  }

  /* ---------- the panel ---------------------------------------------- */
  var lastFocus = null;
  var overlay = null;

  function close() {
    if (!overlay) return;
    overlay.remove();
    overlay = null;
    document.documentElement.style.overflow = '';
    if (history.replaceState) {
      history.replaceState(null, '', location.pathname + location.search);
    }
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }

  function open(data, trigger) {
    close();
    lastFocus = trigger || null;
    injectCss();

    var msg = 'Hi FABRIOZA, I would like a quote for ' + data.name +
              (data.sku ? ' (SKU ' + data.sku + ')' : '') + '.';

    overlay = document.createElement('div');
    overlay.className = 'fz-ov';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-label', data.name);

    var facts = '';
    if (data.price) facts += '<span class="fz-fact">Price <b>' + esc(data.price) + '</b></span>';
    if (data.moq)   facts += '<span class="fz-fact">' + esc(data.moq) + '</span>';
    if (data.tag)   facts += '<span class="fz-fact">' + esc(data.tag) + '</span>';

    overlay.innerHTML =
      '<div class="fz-dlg">' +
        '<button class="fz-x" aria-label="Close">&times;</button>' +
        '<div class="fz-img">' +
          (data.img ? '<img src="' + esc(data.img) + '" alt="' + esc(data.alt || data.name) + '">' : '') +
        '</div>' +
        '<div class="fz-body">' +
          (data.sku ? '<span class="fz-sku">SKU ' + esc(data.sku) + '</span>' : '') +
          '<h2 class="fz-title">' + esc(data.name) + '</h2>' +
          (data.desc ? '<p class="fz-desc">' + esc(data.desc) + '</p>' : '') +
          '<div class="fz-facts">' + facts + '</div>' +
          '<p class="fz-note">Minimum 20 pieces per style for a trial run; the MOQ above is the ' +
          'bulk-pricing quantity. Your own labels and tags, sampled before production.</p>' +
          '<div class="fz-cta">' +
            '<a class="fz-btn fz-btn-1" target="_blank" rel="noopener" href="https://wa.me/' +
              WHATSAPP + '?text=' + encodeURIComponent(msg) + '">Ask about this product</a>' +
            '<a class="fz-btn fz-btn-2" href="mailto:' + EMAIL + '?subject=' +
              encodeURIComponent('Quote request: ' + data.name + (data.sku ? ' (' + data.sku + ')' : '')) +
              '&body=' + encodeURIComponent(msg) + '">Email a quote request</a>' +
            '<a class="fz-btn fz-btn-2" href="/pricing">See full price list</a>' +
          '</div>' +
          '<p class="fz-note">Tap the photo to zoom.</p>' +
        '</div>' +
      '</div>';

    document.body.appendChild(overlay);
    document.documentElement.style.overflow = 'hidden';

    if (data.sku && history.replaceState) {
      history.replaceState(null, '', '#p-' + data.sku);
    }

    var pic = overlay.querySelector('.fz-img img');
    if (pic) {
      pic.addEventListener('click', function () { pic.classList.toggle('fz-zoom'); });
    }
    overlay.querySelector('.fz-x').addEventListener('click', close);
    overlay.addEventListener('mousedown', function (e) {
      if (e.target === overlay) close();
    });

    /* focus management: the dialog must not leak focus to the page behind */
    var focusables = overlay.querySelectorAll('button,a[href]');
    if (focusables.length) focusables[0].focus();
    overlay.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') { close(); return; }
      if (e.key !== 'Tab' || focusables.length < 2) return;
      var first = focusables[0], last = focusables[focusables.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    });
  }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ---------- wire the cards ------------------------------------------ */
  function init() {
    var cards = document.querySelectorAll('.product-card');
    if (!cards.length) return;
    injectCss();

    var bySku = {};
    Array.prototype.forEach.call(cards, function (card) {
      var data = readCard(card);
      if (data.sku) bySku[data.sku] = { data: data, card: card };

      card.classList.add('fz-card-clickable');
      card.setAttribute('role', 'button');
      card.setAttribute('tabindex', '0');
      card.setAttribute('aria-label', data.name + ' - view details');

      var imgWrap = card.querySelector('img');
      if (imgWrap && imgWrap.parentNode === card) {
        card.style.position = card.style.position || 'relative';
        var hint = document.createElement('span');
        hint.className = 'fz-view';
        hint.textContent = 'View details';
        card.appendChild(hint);
      }

      card.addEventListener('click', function (e) {
        /* a real link inside the card keeps its own behaviour */
        if (e.target.closest('a')) return;
        open(data, card);
      });
      card.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          open(data, card);
        }
      });
    });

    /* deep link: /intimate-apparel#p-FIA-001 opens that product, so a
       buyer can be sent straight to one garment */
    function fromHash() {
      var m = /^#p-(.+)$/.exec(location.hash || '');
      if (!m) return;
      var hit = bySku[decodeURIComponent(m[1])];
      if (hit) open(hit.data, hit.card);
    }
    fromHash();
    window.addEventListener('hashchange', fromHash);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
