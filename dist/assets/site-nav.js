/* Mobile drawer for the shared site nav. One file for every page.
   Kept dependency-free and deferred: this runs on 160 pages and the
   complaint that started this work was that the site is slow. */
(function () {
  var nav = document.querySelector('.fz-nav');
  if (!nav) return;
  var btn = nav.querySelector('.fz-nav__toggle');
  var menu = nav.querySelector('.fz-nav__menu');
  if (!btn || !menu) return;

  function setOpen(open) {
    menu.classList.toggle('is-open', open);
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }
  btn.addEventListener('click', function () {
    setOpen(btn.getAttribute('aria-expanded') !== 'true');
  });
  // Escape closes and returns focus to the control that opened it
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && btn.getAttribute('aria-expanded') === 'true') {
      setOpen(false); btn.focus();
    }
  });
  // a tap outside the drawer closes it
  document.addEventListener('click', function (e) {
    if (btn.getAttribute('aria-expanded') === 'true' && !nav.contains(e.target)) setOpen(false);
  });
  // reset when resizing back to desktop, or the drawer stays latched open
  var t;
  window.addEventListener('resize', function () {
    clearTimeout(t);
    t = setTimeout(function () { if (window.innerWidth > 900) setOpen(false); }, 120);
  });
})();
