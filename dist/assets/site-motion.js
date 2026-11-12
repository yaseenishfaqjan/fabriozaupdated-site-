/* Site-wide scroll motion. Loaded deferred on every static page.
 *
 * FAILS OPEN BY DESIGN. The hidden state lives only under the .fz-reveal
 * class, which exists nowhere in the HTML - this file adds it at runtime and
 * only after confirming IntersectionObserver exists and reduced motion is
 * off. If this script never runs, never parses, or is blocked, the page is
 * exactly what it was. A reveal effect that fails closed hides the content
 * of a site whose traffic comes from search.
 *
 * It also refuses to touch anything already on screen at load: above-the-fold
 * content is revealed immediately rather than faded in, so the first paint is
 * never delayed and the largest contentful element is never animated.
 */
(function () {
  'use strict';

  var reduced = window.matchMedia &&
                window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  // The nav shadow is independent of reveal, and is safe even with reduced
  // motion (the stylesheet drops its transition in that case).
  var nav = document.querySelector('.fz-nav');
  if (nav) {
    var ticking = false;
    var onScroll = function () {
      if (ticking) return;
      ticking = true;
      window.requestAnimationFrame(function () {
        nav.classList.toggle('fz-stuck', window.pageYOffset > 8);
        ticking = false;
      });
    };
    window.addEventListener('scroll', onScroll, {passive: true});
    onScroll();
  }

  if (reduced || !('IntersectionObserver' in window)) return;

  // Target list taken from what this site's markup ACTUALLY contains,
  // surveyed across all 160 pages - not from the class names a project of
  // this kind usually has. The first version guessed `main > section` and
  // `.feature`/`.step`, and marked ZERO elements on /what-we-make: there is
  // no <main> anywhere on the site, and 85 pages have no <section> either.
  // Counts below are pages carrying that class.
  var SELECTOR = [
    'body > section',        //  75
    '.faq-item',             // 130
    '.cta-box',              // 113
    '.related-posts',        // 100
    '.section-header',
    '.product-card', '.cat-card', '.capability-card', '.card',
    '.price-card', '.tier', '.hero-stat',
    'h2'                     // the one landmark present on nearly every page
  ].join(',');

  var nodes;
  try {
    nodes = document.querySelectorAll(SELECTOR);
  } catch (e) {
    return;                       // a selector this browser dislikes: do nothing
  }
  if (!nodes.length) return;

  var vh = window.innerHeight || document.documentElement.clientHeight;
  var marked = [];
  var staggerGroup = null, staggerIndex = 0;

  for (var i = 0; i < nodes.length && i < 400; i++) {   // bounded: some pages carry 40+ cards
    var el = nodes[i];
    if (el.closest('.fz-nav')) continue;                 // never the top bar
    if (el.getAttribute('data-fz-seen')) continue;       // a node matching two selectors
    // An h2 inside an already-marked section would fade in on top of its
    // parent fading in. Marking runs in document order, so a marked
    // ancestor always exists by now if there is going to be one.
    if (el.parentNode && el.parentNode.closest && el.parentNode.closest('.fz-reveal')) continue;
    var r = el.getBoundingClientRect();
    if (r.top < vh * 0.9) continue;                      // already visible: leave it alone
    el.setAttribute('data-fz-seen', '1');

    // stagger only within a run of siblings, capped at 5
    if (el.parentNode !== staggerGroup) { staggerGroup = el.parentNode; staggerIndex = 0; }
    staggerIndex++;
    if (staggerIndex > 1 && staggerIndex <= 6) el.classList.add('fz-d' + (staggerIndex - 1));

    el.classList.add('fz-reveal');
    marked.push(el);
  }
  if (!marked.length) return;

  var io = new IntersectionObserver(function (entries) {
    for (var j = 0; j < entries.length; j++) {
      if (!entries[j].isIntersecting) continue;
      entries[j].target.classList.add('fz-in');
      io.unobserve(entries[j].target);
    }
  }, {rootMargin: '0px 0px -8% 0px', threshold: 0.01});

  for (var k = 0; k < marked.length; k++) io.observe(marked[k]);

  // Failsafe. If anything goes wrong - a browser quirk, a page that resizes
  // its own layout, an element inside a container that never scrolls - the
  // content must not stay hidden. After 3 seconds everything is shown
  // whether or not the observer ever fired.
  window.setTimeout(function () {
    for (var m = 0; m < marked.length; m++) marked[m].classList.add('fz-in');
    io.disconnect();
  }, 3000);

  /* ==================================================================
     MAGNETIC BUTTONS
     The cursor pulls the button a few pixels toward itself. Tiny, but it
     is what makes a control feel physical rather than painted on.
     Pointer-fine only: on a touch screen there is no cursor to track, and
     a transform applied on tap would stick.
     ================================================================== */
  var MAG_SEL = '.fz-nav__cta,.btn-primary,.btn-secondary,.btn,.lq-btn,.cta-btn,.header-cta';
  var MAG_PULL = 0.28;   /* fraction of the distance from centre */
  var MAG_MAX = 7;       /* px. Past ~8 the label starts to detach from
                            the box it sits in and reads as a glitch. */
  var mEl = null, mRect = null, mRaf = 0, mX = 0, mY = 0;

  function magFrame() {
    mRaf = 0;
    if (!mEl || !mRect) return;
    var dx = (mX - (mRect.left + mRect.width / 2)) * MAG_PULL;
    var dy = (mY - (mRect.top + mRect.height / 2)) * MAG_PULL;
    if (dx > MAG_MAX) dx = MAG_MAX; else if (dx < -MAG_MAX) dx = -MAG_MAX;
    if (dy > MAG_MAX) dy = MAG_MAX; else if (dy < -MAG_MAX) dy = -MAG_MAX;
    mEl.style.transform = 'translate(' + dx.toFixed(1) + 'px,' + dy.toFixed(1) + 'px) scale(1.03)';
  }

  function magClear() {
    if (!mEl) return;
    mEl.style.transform = '';
    mEl.classList.remove('fz-magnetic');
    mEl = null; mRect = null;
  }

  function magInit() {
    if (reduced || !window.matchMedia ||
        !window.matchMedia('(hover:hover) and (pointer:fine)').matches) return;
    document.addEventListener('pointermove', function (e) {
      var el = e.target && e.target.closest ? e.target.closest(MAG_SEL) : null;
      if (!el) { magClear(); return; }
      if (el !== mEl) {
        magClear();
        mEl = el;
        mRect = el.getBoundingClientRect();
        el.classList.add('fz-magnetic');
      }
      mX = e.clientX; mY = e.clientY;
      if (!mRaf) mRaf = window.requestAnimationFrame(magFrame);
    }, { passive: true });
    /* A cached rectangle is wrong the moment the page moves. */
    window.addEventListener('scroll', magClear, { passive: true });
    window.addEventListener('resize', magClear, { passive: true });
  }

  /* ==================================================================
     HERO PARALLAX
     The hero's content drifts slower than the page, which is what reads
     as depth. Transform only - never top/margin - so it stays on the
     compositor and never triggers layout.

     MODULE 4's REQUIREMENT IS THE IMPORTANT PART HERE: the scroll handler
     does nothing at all unless a hero is actually on screen. An
     IntersectionObserver flips a boolean; once the hero is scrolled past,
     the work stops entirely rather than running for the whole page.
     ================================================================== */
  var PARA_MAX = 26;     /* px of drift. Deliberately small: these are 145
                            different hero layouts and a large offset would
                            collide with something on one of them. */
  var heroes = [], heroVisible = 0, pRaf = 0;

  function paraFrame() {
    pRaf = 0;
    for (var i = 0; i < heroes.length; i++) {
      var h = heroes[i];
      if (!h.vis) continue;
      var r = h.el.getBoundingClientRect();
      if (r.bottom < 0 || r.top > (window.innerHeight || 0)) continue;
      /* 0 at the top of travel, 1 once the hero has left upward */
      var prog = -r.top / (r.height || 1);
      if (prog < 0) prog = 0; else if (prog > 1) prog = 1;
      h.inner.style.transform = 'translate3d(0,' + (prog * PARA_MAX).toFixed(1) + 'px,0)';
      h.inner.style.opacity = (1 - prog * 0.55).toFixed(3);
    }
  }

  function paraInit() {
    if (reduced || !('IntersectionObserver' in window)) return;
    var nodes = document.querySelectorAll('.hero,.article-hero');
    for (var i = 0; i < nodes.length && i < 4; i++) {
      var el = nodes[i];
      /* Parallax the hero's CONTENT, not the hero box itself: moving the
         box would drag its background away from the section below it. */
      var inner = el.querySelector('.container') || el.firstElementChild;
      if (!inner || inner.nodeType !== 1) continue;
      heroes.push({ el: el, inner: inner, vis: false });
    }
    if (!heroes.length) return;

    var io = new IntersectionObserver(function (entries) {
      for (var j = 0; j < entries.length; j++) {
        for (var k = 0; k < heroes.length; k++) {
          if (heroes[k].el !== entries[j].target) continue;
          var was = heroes[k].vis;
          heroes[k].vis = entries[j].isIntersecting;
          heroVisible += (heroes[k].vis ? 1 : 0) - (was ? 1 : 0);
          /* will-change is a PROMISE TO THE BROWSER, not a speed switch.
             Left on permanently it holds a compositor layer per element
             for the life of the page, which is how "optimisation" turns
             into memory pressure on a phone. It goes on when the element
             is about to move and comes straight off again. */
          heroes[k].inner.style.willChange = heroes[k].vis ? 'transform,opacity' : 'auto';
          if (!heroes[k].vis) {
            heroes[k].inner.style.transform = '';
            heroes[k].inner.style.opacity = '';
          }
        }
      }
    }, { threshold: [0, 0.01, 1] });

    for (var m = 0; m < heroes.length; m++) io.observe(heroes[m].el);

    window.addEventListener('scroll', function () {
      if (heroVisible < 1) return;   /* nothing on screen: do no work */
      if (!pRaf) pRaf = window.requestAnimationFrame(paraFrame);
    }, { passive: true });
  }

  magInit();
  paraInit();

})();
