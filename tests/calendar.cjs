const assert = require('node:assert/strict');
const {create} = require('../assets/calendar.js');
const data = require('../includes/calendar-data.json');
const cases = require('./calendar-cases.json');
for (const test of cases) assert.equal(create(data,test.source,test.target,test.dictionary || {})(test.input),test.expected,JSON.stringify(test));
let checks = cases.length;
for (const source of Object.keys(data)) for (const target of Object.keys(data)) {
  if (source === target) continue;
  const translate = create(data,source,target);
  for (const group of ['months','days']) data[source][group].forEach((names,i) => {
    const expected = data[target][group][i][0];
    const result = translate(names[0]);
    assert.equal(result.toLowerCase(),expected.toLowerCase(),`${source}:${target}:${names[0]}`);
    checks++;
  });
}
console.log(`PASS: ${checks} JavaScript calendar checks.`);
