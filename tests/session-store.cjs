'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {sessionStore} = require('../companion/session-store.cjs');
const {control,acquireRuntime} = require('../companion/runtime.cjs');
const {refresh,resilientSessionRequest,SafeError} = require('../companion/cptai-companion.cjs');
let checks=0; const check=fn=>{fn();checks++;};
(async()=>{
  const root=fs.mkdtempSync(path.join(os.tmpdir(),'cptai-session-test-')); const file=path.join(root,'profile.json');
  const session={clientId:'oaiapp_test',subject:'fake-test-user',email:'fake@example.invalid',tokens:{access_token:'fake-access-only',refresh_token:'fake-refresh-only',scope:'resource.invoke chatgpt.tokens.use.direct',token_type:'bearer',expires_in:3600},expires:Date.now()+3600000,revocation:'https://auth.openai.com/revoke'};
  const originalFetch=global.fetch;
  try {
    const store=sessionStore(file);
    check(()=>assert.equal(store.load(session),null));
    store.save(session);
    check(()=>assert.deepEqual(store.load(session).tokens,session.tokens));
    check(()=>assert.equal(store.load({...session,clientId:'oaiapp_other'}),null));
    check(()=>assert.throws(()=>store.load({...session,subject:'another-user'})));
    if(process.platform==='win32') check(()=>assert.ok(!fs.readFileSync(store.fileFor(session.clientId),'utf8').includes('fake-refresh-only')));
    else check(()=>assert.equal(fs.statSync(store.fileFor(session.clientId)).mode&0o077,0));
    let count=0, saved=0;
    session.expires=0;
    global.fetch=async()=>{count++; await new Promise(resolve=>setTimeout(resolve,10)); return Response.json({access_token:'fake-new-access',refresh_token:'fake-rotated-refresh',token_type:'bearer',expires_in:3600});};
    await Promise.all([refresh(session,value=>{saved++;store.save(value);}),refresh(session,value=>{saved++;store.save(value);})]);
    check(()=>assert.equal(count,1)); check(()=>assert.equal(saved,1));
    check(()=>assert.equal(store.load(session).tokens.refresh_token,'fake-rotated-refresh'));
    let attempt=0; const delays=[];
    await resilientSessionRequest(async()=>{if(++attempt<3)throw Object.assign(new SafeError('network'),{status:503});},()=>false,async ms=>delays.push(ms),()=>{});
    check(()=>assert.equal(attempt,3)); check(()=>assert.deepEqual(delays,[2000,4000]));
    check(()=>assert.equal(store.load(session).tokens.refresh_token,'fake-rotated-refresh'));
    await assert.rejects(()=>resilientSessionRequest(async()=>{throw new SafeError('auth');},()=>false,async()=>{throw new Error('Must not retry auth');},()=>{}),e=>e.code==='auth'); checks++;
    const events=[];
    const release=await acquireRuntime(file,async operation=>{events.push(operation);return {running:true};});
    try {
      check(()=>assert.ok(fs.existsSync(file+'.worker')));
      check(()=>assert.ok(!fs.readFileSync(file+'.worker','utf8').includes('fake-refresh')));
      const state=await control(file); check(()=>assert.deepEqual(state,{running:true}));
      await assert.rejects(()=>acquireRuntime(file,async()=>({}))); checks++;
      await control(file,'stop');
      // Unix probes the existing socket before rejecting a duplicate; Windows
      // rejects the second named-pipe listener directly. Both must keep one owner.
      check(()=>assert.equal(events[0],'status'));
      check(()=>assert.equal(events.at(-1),'stop'));
      check(()=>assert.equal(events.filter(operation=>operation==='stop').length,1));
      check(()=>assert.ok(events.every(operation=>['status','stop'].includes(operation))));
    } finally { await release(); }
    check(()=>assert.equal(fs.existsSync(file+'.worker'),false));
    check(()=>assert.equal(store.load(session).tokens.refresh_token,'fake-rotated-refresh'));
    store.clear(session); check(()=>assert.equal(store.load(session),null));
    const closed=await control(file); check(()=>assert.equal(closed,null));
  } finally { global.fetch=originalFetch; assert.equal(path.dirname(path.resolve(root)),path.resolve(os.tmpdir())); fs.rmSync(root,{recursive:true,force:true}); }
  console.log(`PASS: ${checks} protected session/runtime checks; fake credentials only.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
