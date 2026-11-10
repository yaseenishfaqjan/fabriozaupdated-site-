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
})();
