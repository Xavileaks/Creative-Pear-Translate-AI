(() => {
  'use strict';
  const map = window.CPTAIImages;
  if (!map || !Object.keys(map).length) return;
  const replacements = new Set(Object.values(map));
  const attrs = ['src','data-src','data-lazy-src','data-original','srcset','data-srcset','data-lazy-srcset'];
  const backgrounds = ['data-bg','data-background','data-background-image'];
  const resolve = raw => { try { return new URL(raw, location.href).href; } catch { return ''; } };
  const css = value => value.replace(/url\(\s*(["']?)([^)"']+)\1\s*\)/gi, (full, quote, raw) => {
    const replacement = map[resolve(raw.trim())];
    return replacement ? `url("${replacement}")` : full;
  });
  const set = (element, attr, value) => { if (element.getAttribute(attr) !== value) element.setAttribute(attr,value); };
  function image(element) {
    if (!element.matches('img,source,input[type="image"]')) return;
    let replacement;
    for (const attr of attrs) {
      for (const candidate of (element.getAttribute(attr) || '').split(',')) {
        const url = resolve(candidate.trim().split(/\s+/)[0]);
        if (map[url] || replacements.has(url)) { replacement = map[url] || url; break; }
      }
      if (replacement) break;
    }
    if (!replacement) return;
    for (const attr of attrs) if (element.hasAttribute(attr)) set(element,attr,replacement);
    if (element.matches('img')) {
      for (const source of element.closest('picture')?.querySelectorAll('source') || []) {
        for (const attr of ['srcset','data-srcset','data-lazy-srcset']) if (source.hasAttribute(attr)) set(source,attr,replacement);
      }
    }
  }
  function visit(element) {
    image(element);
    for (const attr of backgrounds) {
      const raw = element.getAttribute(attr);
      if (raw && map[resolve(raw)]) set(element,attr,map[resolve(raw)]);
    }
    // Elementor backgrounds are often declared in external CSS. Override only
    // background-image; all sizing, positioning, overlays and other styles stay.
    const raw = getComputedStyle(element).backgroundImage;
    if (raw && raw !== 'none') {
      const translated = css(raw);
      if (raw !== translated) element.style.setProperty('background-image',translated,'important');
    }
  }
  function walk(root) {
    if (root.nodeType === Node.ELEMENT_NODE) visit(root);
    for (const element of root.querySelectorAll?.('*') || []) visit(element);
  }
  walk(document);
  // Stylesheets can finish loading after the footer script executes.
  document.addEventListener('load', event => { if (event.target.matches?.('link[rel="stylesheet"]')) walk(document); }, true);
  const pending = new Set(); let queued = false;
  new MutationObserver(records => {
    for (const record of records) {
      if (record.type === 'childList') { for (const node of record.addedNodes) if (node.nodeType === Node.ELEMENT_NODE) pending.add(node); }
      else if (record.type === 'attributes') pending.add(record.target);
    }
    if (!queued && pending.size) {
      queued = true;
      requestAnimationFrame(() => { queued = false; for (const node of pending) if (node.isConnected) walk(node); pending.clear(); });
    }
  }).observe(document.documentElement,{subtree:true,childList:true,attributes:true,attributeFilter:[...attrs,...backgrounds,'style','class']});
})();
