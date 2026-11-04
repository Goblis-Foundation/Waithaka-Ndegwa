/* First-article navigation only. No requests, cookies, storage or publishing writes. */
(() => {
  'use strict';
  const article = document.querySelector('[data-inaugural-story]');
  if (!article) return;
  const details = article.querySelector('.story-index-details');
  const links = [...article.querySelectorAll('[data-story-link]')];
  const mobile = window.matchMedia('(max-width: 900px)');
  const adapt = () => { if (details) details.open = !mobile.matches; };
  adapt();
  mobile.addEventListener?.('change', adapt);
  const fromHash = hash => {
    try { return document.getElementById(decodeURIComponent(hash.slice(1))); }
    catch (_) { return null; }
  };
  const mark = id => links.forEach(link => {
    if (link.hash === '#' + id) link.setAttribute('aria-current', 'location');
    else link.removeAttribute('aria-current');
  });
  links.forEach(link => link.addEventListener('click', () => {
    const target = fromHash(link.hash);
    if (!target) return;
    if (details && mobile.matches) details.open = false;
    target.tabIndex = -1;
    target.focus({preventScroll: true});
    mark(target.id);
  }));
  if ('IntersectionObserver' in window) {
    const observer = new IntersectionObserver(entries => {
      const visible = entries.filter(entry => entry.isIntersecting).sort((a,b) => a.boundingClientRect.top - b.boundingClientRect.top);
      if (visible[0]) mark(visible[0].target.id);
    }, {rootMargin: '-110px 0px -50% 0px', threshold: 0});
    links.forEach(link => { const heading = fromHash(link.hash); if (heading) observer.observe(heading); });
  }
  const target = fromHash(location.hash);
  if (target && article.contains(target)) mark(target.id);
})();
