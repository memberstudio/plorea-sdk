/* Plorea API documentation — shared behaviour.
 * Classic script (not a module) so the pages also work when opened from disk.
 * Builds the top bar, sidebar and on-page TOC; wires code tabs, copy buttons,
 * syntax highlighting, Mermaid diagrams and the theme toggle. */
(function () {
  'use strict';

  var NAV = [
    { title: 'Start here', items: [
      ['index.html', 'Overview'],
      ['changelog.html', 'What\'s new'],
      ['quickstart.html', 'Quickstart'],
      ['concepts.html', 'Core concepts'],
    ] },
    { title: 'Payments', items: [
      ['payment-lifecycle.html', 'Payment lifecycle and states'],
      ['payment-links.html', 'Payment links'],
      ['refunds-and-cancellations.html', 'Refunds and cancellations'],
      ['checkout.html', 'Embedded and native checkout'],
    ] },
    { title: 'Recurring billing', items: [
      ['payment-methods.html', 'Storing cards'],
      ['subscriptions.html', 'Subscriptions'],
      ['subscription-workflows.html', 'Subscription workflows'],
      ['dunning.html', 'Failed charges and dunning'],
    ] },
    { title: 'Integration', items: [
      ['webhooks.html', 'Webhooks'],
      ['errors.html', 'Errors and retries'],
      ['testing.html', 'Testing'],
      ['going-live.html', 'Going live'],
    ] },
    { title: 'Reference', items: [
      ['api-reference.html', 'API reference'],
      ['objects.html', 'Objects and statuses'],
      ['field-notes.html', 'Verified behaviour'],
      ['wishlist.html', 'Gaps and proposals'],
    ] },
  ];

  var LANG_KEY = 'plorea-docs-lang';
  var THEME_KEY = 'plorea-docs-theme';

  function store(key, value) {
    try {
      if (value === undefined) return localStorage.getItem(key);
      localStorage.setItem(key, value);
    } catch (e) { /* storage unavailable: fine */ }
    return null;
  }

  function el(tag, attrs, html) {
    var node = document.createElement(tag);
    if (attrs) Object.keys(attrs).forEach(function (k) { node.setAttribute(k, attrs[k]); });
    if (html !== undefined) node.innerHTML = html;
    return node;
  }

  function currentPage() {
    var path = location.pathname.split('/').pop();
    return path === '' ? 'index.html' : path;
  }

  // ---------- Theme ----------
  var savedTheme = store(THEME_KEY);
  if (savedTheme === 'light' || savedTheme === 'dark') document.documentElement.setAttribute('data-theme', savedTheme);

  function isDark() {
    var t = document.documentElement.getAttribute('data-theme');
    if (t) return t === 'dark';
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  }

  // ---------- Chrome ----------
  function buildTopbar() {
    var bar = el('header', { class: 'topbar' });
    bar.innerHTML =
      '<button class="icon-btn menu-btn" aria-label="Open navigation">☰</button>' +
      '<a class="brand" href="index.html"><span class="brand-mark">P</span>Plorea API <small>docs</small></a>' +
      '<span class="spacer"></span>' +
      '<span class="unofficial" title="Written by an integrator from observed behaviour and correspondence with Plorea">Community draft · not official</span>' +
      '<button class="icon-btn theme-btn" aria-label="Toggle dark mode">◐</button>';
    document.body.insertBefore(bar, document.body.firstChild);
    bar.querySelector('.menu-btn').addEventListener('click', function () {
      document.body.classList.toggle('nav-open');
    });
    bar.querySelector('.theme-btn').addEventListener('click', function () {
      var next = isDark() ? 'light' : 'dark';
      document.documentElement.setAttribute('data-theme', next);
      store(THEME_KEY, next);
      renderMermaid(true);
    });
  }

  function buildSidebar() {
    var side = document.getElementById('sidebar');
    if (!side) return;
    var page = currentPage();
    var html = '';
    NAV.forEach(function (group) {
      html += '<h4>' + group.title + '</h4>';
      group.items.forEach(function (item) {
        html += '<a href="' + item[0] + '"' + (item[0] === page ? ' class="active" aria-current="page"' : '') + '>' + item[1] + '</a>';
      });
    });
    side.innerHTML = html;
    side.addEventListener('click', function (e) {
      if (e.target.tagName === 'A') document.body.classList.remove('nav-open');
    });
  }

  function buildPager() {
    var doc = document.querySelector('.doc');
    if (!doc || doc.querySelector('.pager')) return;
    var flat = [];
    NAV.forEach(function (g) { g.items.forEach(function (i) { flat.push(i); }); });
    var idx = flat.findIndex(function (i) { return i[0] === currentPage(); });
    if (idx < 0) return;
    var pager = el('nav', { class: 'pager', 'aria-label': 'Pages' });
    var prev = flat[idx - 1], next = flat[idx + 1];
    pager.innerHTML =
      (prev ? '<a class="prev" href="' + prev[0] + '"><small>Previous</small>' + prev[1] + '</a>' : '<span></span>') +
      (next ? '<a class="next" href="' + next[0] + '"><small>Next</small>' + next[1] + '</a>' : '<span></span>');
    doc.appendChild(pager);
  }

  function slug(text) {
    return text.toLowerCase().replace(/[`'"’]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  }

  function buildToc() {
    var toc = document.getElementById('toc');
    var heads = document.querySelectorAll('.doc h2, .doc h3');
    var used = {};
    heads.forEach(function (h) {
      if (!h.id) {
        var base = slug(h.textContent) || 'section', id = base, n = 2;
        while (used[id] || document.getElementById(id)) id = base + '-' + n++;
        h.id = id;
      }
      used[h.id] = true;
      var a = el('a', { class: 'anchor', href: '#' + h.id, 'aria-label': 'Link to this section' }, '#');
      h.appendChild(a);
    });
    if (!toc || heads.length < 2) return;
    var html = '<p>On this page</p>';
    heads.forEach(function (h) {
      var text = h.textContent.replace(/#$/, '');
      html += '<a class="' + (h.tagName === 'H3' ? 'lvl3' : 'lvl2') + '" href="#' + h.id + '">' + escapeHtml(text) + '</a>';
    });
    toc.innerHTML = html;
    if (!('IntersectionObserver' in window)) return;
    var links = toc.querySelectorAll('a');
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        links.forEach(function (l) { l.classList.toggle('active', l.getAttribute('href') === '#' + entry.target.id); });
      });
    }, { rootMargin: '-70px 0px -70% 0px' });
    heads.forEach(function (h) { observer.observe(h); });
  }

  function escapeHtml(s) {
    return s.replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; });
  }

  // ---------- Code ----------
  function buildTabs() {
    var preferred = store(LANG_KEY);
    document.querySelectorAll('.tabs').forEach(function (group) {
      var tabs = Array.prototype.slice.call(group.querySelectorAll(':scope > .tab'));
      var bar = el('div', { class: 'tab-bar', role: 'tablist' });
      tabs.forEach(function (tab, i) {
        var label = tab.getAttribute('data-label') || 'Code ' + (i + 1);
        var btn = el('button', { role: 'tab', type: 'button', 'data-label': label }, escapeHtml(label));
        btn.addEventListener('click', function () { selectLabel(label, true); });
        bar.appendChild(btn);
      });
      group.insertBefore(bar, group.firstChild);
      var initial = tabs.some(function (t) { return t.getAttribute('data-label') === preferred; }) ? preferred : tabs[0] && tabs[0].getAttribute('data-label');
      select(group, initial);
    });
  }

  function select(group, label) {
    var tabs = group.querySelectorAll(':scope > .tab');
    var has = Array.prototype.some.call(tabs, function (t) { return t.getAttribute('data-label') === label; });
    if (!has) return;
    tabs.forEach(function (t) { t.hidden = t.getAttribute('data-label') !== label; });
    group.querySelectorAll(':scope > .tab-bar button').forEach(function (b) {
      b.setAttribute('aria-selected', b.getAttribute('data-label') === label ? 'true' : 'false');
    });
  }

  // Selecting a language in one block switches every block on the page.
  function selectLabel(label, remember) {
    document.querySelectorAll('.tabs').forEach(function (g) { select(g, label); });
    if (remember) store(LANG_KEY, label);
  }

  function addCopyButtons() {
    document.querySelectorAll('pre').forEach(function (pre) {
      if (pre.classList.contains('mermaid')) return;
      var btn = el('button', { class: 'copy-btn', type: 'button' }, 'Copy');
      btn.addEventListener('click', function () {
        var code = pre.querySelector('code') || pre;
        var text = code.innerText;
        var done = function () { btn.textContent = 'Copied'; setTimeout(function () { btn.textContent = 'Copy'; }, 1400); };
        if (navigator.clipboard) navigator.clipboard.writeText(text).then(done, function () {});
      });
      pre.appendChild(btn);
    });
  }

  function highlight() {
    if (!window.hljs) return;
    document.querySelectorAll('pre code').forEach(function (block) { window.hljs.highlightElement(block); });
  }

  // ---------- Mermaid ----------
  var mermaidSources = null;
  function renderMermaid(rerender) {
    var nodes = document.querySelectorAll('pre.mermaid');
    if (!nodes.length) return;
    if (!mermaidSources) mermaidSources = Array.prototype.map.call(nodes, function (n) {
      // A raw <br/> in the source parses as an element; keep it as text for Mermaid.
      n.querySelectorAll('br').forEach(function (br) { br.replaceWith(document.createTextNode('<br/>')); });
      return n.textContent;
    });
    var run = function () {
      nodes.forEach(function (n, i) {
        n.removeAttribute('data-processed');
        n.innerHTML = '';
        n.textContent = mermaidSources[i];
      });
      window.mermaid.initialize({
        startOnLoad: false,
        theme: isDark() ? 'dark' : 'neutral',
        securityLevel: 'strict',
        fontFamily: 'ui-sans-serif, -apple-system, Segoe UI, Roboto, Arial, sans-serif',
        flowchart: { htmlLabels: true, curve: 'basis' },
      });
      window.mermaid.run({ nodes: nodes });
    };
    if (window.mermaid) { run(); return; }
    if (rerender) return;
    var s = el('script', { src: 'https://cdn.jsdelivr.net/npm/mermaid@10.9.1/dist/mermaid.min.js', integrity: 'sha384-WmdflGW9aGfoBdHc4rRyWzYuAjEmDwMdGdiPNacbwfGKxBW/SO6guzuQ76qjnSlr', crossorigin: 'anonymous' });
    s.onload = run;
    document.head.appendChild(s);
  }

  function init() {
    buildTopbar();
    buildSidebar();
    buildToc();
    buildPager();
    buildTabs();
    highlight();
    addCopyButtons();
    renderMermaid(false);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
