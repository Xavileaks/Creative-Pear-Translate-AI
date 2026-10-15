(() => {
  'use strict';
  const inline = new Set(['A','ABBR','B','BDI','BDO','CITE','DEL','EM','I','INS','MARK','Q','S','SMALL','SPAN','STRONG','SUB','SUP','U']);
  const pattern = /\[cptai_(?:br|tag)_\d+\]/g;
  const markers = text => text.match(pattern) || [];
  function valid(original,translated) {
    if (JSON.stringify(markers(original)) !== JSON.stringify(markers(translated))) return false;
    const from = original.split(pattern), to = translated.split(pattern);
    return from.every((part,i) => part.trim() || !to[i].trim());
  }
  function build(tokens) {
    if (tokens.length > 150) return null;
    const balance = [], lines = ['']; let brs = 0, human = '';
    for (const token of tokens) {
      if (token.kind === 'br') { brs++; human += '\n'; lines.push(''); }
      else if (token.kind === 'text') {
        if (token.raw.includes('[cptai_')) return null;
        human += token.raw; lines[lines.length-1] += token.raw;
      } else if (token.close) { if (balance.pop() !== token.tag) return null; }
      else balance.push(token.tag);
    }
    if (!brs || brs > 32 || balance.length || lines.filter(line=>line.trim()).length < 2 || !/\p{L}/u.test(human)) return null;
    tokens = [...tokens];
    while (tokens.length >= 2 && tokens[0].kind === 'tag' && !tokens[0].close && tokens.at(-1).kind === 'tag') {
      let depth = 0, end = 0;
      for (const [i,token] of tokens.entries()) {
        if (token.kind !== 'tag') continue;
        depth += token.close ? -1 : 1;
        if (!depth) { end = i; break; }
      }
      if (end !== tokens.length-1) break;
      tokens.shift(); tokens.pop();
    }
    let original = '', br = 0, tag = 0; const slots = [[]], tokensUsed = [];
    for (const token of tokens) {
      if (token.kind === 'text') { original += token.raw; slots.at(-1).push(token); }
      else {
        const marker = token.kind === 'br' ? `[cptai_br_${++br}]` : `[cptai_tag_${++tag}]`;
        original += token.kind === 'br' ? ` ${marker} ` : marker;
        tokensUsed.push(marker); slots.push([]);
      }
    }
    original = original.replace(/[ \t\r\n\f]+/g,' ').replace(/^ +| +$/g,'');
    if (new TextEncoder().encode(original).length > 3500) return null;
    return {original,slots,markers:tokensUsed};
  }
  function collect(root,excluded,textFor = node=>node.nodeValue) {
    const groups = []; let run = [];
    const flush = () => { const group = build(run); run = []; if (group) groups.push(group); };
    function visit(node) {
      if (node.nodeType === Node.TEXT_NODE) { run.push({kind:'text',id:node,raw:textFor(node)}); return; }
      if (node.nodeType === Node.COMMENT_NODE) return;
      if (node.nodeType === Node.ELEMENT_NODE) {
        if (node.matches(excluded)) { flush(); return; }
        const tag = node.tagName;
        if (tag === 'BR') { run.push({kind:'br'}); return; }
        if (inline.has(tag)) run.push({kind:'tag',tag,close:false}); else flush();
        for (const child of node.childNodes) visit(child);
        if (inline.has(tag)) run.push({kind:'tag',tag,close:true}); else flush();
      } else { flush(); for (const child of node.childNodes || []) visit(child); flush(); }
    }
    visit(root); flush(); return groups;
  }
  function replacements(group,translated) {
    if (!valid(group.original,translated)) return new Map();
    const parts = translated.split(pattern), result = new Map();
    group.slots.forEach((slot,i) => {
      let text = parts[i];
      if (!i || group.markers[i-1]?.startsWith('[cptai_br_')) text = text.trimStart();
      if (i === group.slots.length-1 || group.markers[i]?.startsWith('[cptai_br_')) text = text.trimEnd();
      slot.forEach((token,j) => result.set(token.id,j ? '' : text));
    });
    return result;
  }
  window.CPTAIBreaks = {inline,markers,valid,build,collect,replacements};
})();
