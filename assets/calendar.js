(() => {
  'use strict';
  const escape = value => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  function mapping(data, source, target) {
    const map = new Map();
    if (!data[source] || !data[target] || source === target) return map;
    for (const group of ['months', 'days']) data[source][group].forEach((aliases, i) => {
      aliases.forEach((alias, j) => {
        const lower = alias.toLowerCase();
        if (map.has(lower)) return;
        const short = j === (group === 'months' ? 2 : 1);
        const destination = data[target][group][i];
        map.set(lower, {alias, plain:destination[short ? destination.length - 1 : 0], dated:destination[short ? destination.length - 1 : group === 'months' ? 1 : 0]});
      });
    });
    return map;
  }
  function pattern(map, source) {
    const words = [...map.keys()].sort((a,b) => b.length-a.length).map(escape).join('|');
    return new RegExp(['ja','zh'].includes(source) ? `(?:${words})` : `(?<![\\p{L}\\p{M}])(?:${words})(?![\\p{L}\\p{M}])`, 'giu');
  }
  function casing(word, replacement) {
    if (word.toUpperCase() === word.toLowerCase()) return replacement;
    if (word.toUpperCase() === word && word.toLowerCase() !== word) return replacement.toUpperCase();
    if (word.toLowerCase() === word) return replacement.toLowerCase();
    if (/^\p{Lu}/u.test(word)) return [...replacement][0].toUpperCase() + [...replacement].slice(1).join('').toLowerCase();
    return replacement;
  }
  function create(data, source, target, dictionary = {}) {
    const map = mapping(data, source, target);
    const reverse = mapping(data, target, source);
    const regex = map.size ? pattern(map, source) : null;
    const reverseRegex = reverse.size ? pattern(reverse, target) : null;
    const own = (key) => Object.prototype.hasOwnProperty.call(dictionary, key);
    const numbers = text => {
      if (regex) text = text.replace(regex, '');
      if (reverseRegex) text = text.replace(reverseRegex, '');
      return (text.match(/\p{N}+/gu) || []).map(n => n.replace(/^0+/, '') || '0').sort().join('|');
    };
    return text => {
      const manual = own(text) ? dictionary[text] : null;
      if (!regex) return manual ?? text;
      let count = 0;
      let rest = text.replace(regex, () => { count++; return ''; });
      rest = rest.replace(/(?<![\p{L}\p{M}])(?:de|del|of|on|at|a|las|às|um|den|le|el|am|pm|d)(?![\p{L}\p{M}])|[年月日号時分]/giu, '');
      if (!count || !/^[\p{N}\p{P}\p{Z}\s]*$/u.test(rest)) return manual ?? text;
      if (manual !== null && numbers(text) === numbers(manual)) return manual;
      const dated = /\p{N}/u.test(rest);
      return text.replace(regex, word => {
        const item = map.get(word.toLowerCase());
        const key = [word, item.alias, item.alias.toLowerCase()].find(own);
        let replacement = key === undefined ? item[dated ? 'dated' : 'plain'] : dictionary[key];
        if (numbers(word) !== numbers(replacement)) replacement = item[dated ? 'dated' : 'plain'];
        return casing(word, replacement);
      });
    };
  }
  if (typeof module !== 'undefined' && module.exports) module.exports = {create};
  else window.CPTAICalendar = {create};
})();
