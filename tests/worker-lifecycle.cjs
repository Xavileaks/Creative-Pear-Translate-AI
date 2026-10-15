'use strict';
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),assert=require('node:assert/strict');
const {spawn}=require('node:child_process');
const {metadataPath}=require('../companion/cptai-companion.cjs');
const {sessionStore,atomicWrite}=require('../companion/session-store.cjs');
const {control}=require('../companion/runtime.cjs');
const wait=ms=>new Promise(resolve=>setTimeout(resolve,ms));
let checks=0; const check=fn=>{fn();checks++;};
(async()=>{
  const root=fs.mkdtempSync(path.join(os.tmpdir(),'cptai-lifecycle-test-'));
  const previous=process.env.LOCALAPPDATA; process.env.LOCALAPPDATA=root;
  const connection={version:1,endpoint:'https://worker-fixture.invalid/wp-json/cptai/v1/plan/',secret:'a'.repeat(64),installation:'12345678-1234-1234-1234-123456789abc'};
  const file=metadataPath(connection), trace=path.join(root,'trace.txt'), config=path.join(root,'connection.json');
  const profile={clientId:'oaiapp_worker_test',subject:'fake-user',email:'fake@example.invalid'};
  const session={...profile,tokens:{access_token:'fake-access',refresh_token:'fake-refresh',scope:'resource.invoke chatgpt.tokens.use.direct',token_type:'bearer',expires_in:3600},expires:Date.now()+3600000,revocation:'https://auth.openai.com/test-revoke'};
  const store=sessionStore(file);
  const environment={...process.env,NODE_OPTIONS:'--require="'+path.join(__dirname,'companion-upstream-fixture.cjs').replace(/\\/g,'/')+'"',CPTAI_TEST_TRACE:trace};
  async function run(flag) {
    return new Promise((resolve,reject)=>{
      const child=spawn(process.execPath,[path.join(__dirname,'../companion/cptai-companion.cjs'),config,flag],{env:environment,windowsHide:true}); let output='';
      child.stdout.on('data',chunk=>output+=chunk);child.stderr.on('data',chunk=>output+=chunk);child.once('error',reject);
      child.once('exit',code=>code===0?resolve(output):reject(new Error('Fixture launcher failed: '+output)));
    });
  }
  async function until(predicate) { for(let i=0;i<100;i++){if(await predicate())return;await wait(100);}throw new Error('Fixture worker did not reach expected state'); }
  try {
    atomicWrite(config,JSON.stringify(connection)); atomicWrite(file,JSON.stringify({hostId:'urn:uuid:'+connection.installation,accounts:[profile],activeClientId:profile.clientId})); store.save(session);
    const first=await run('--launch');
    check(()=>assert.ok(first.includes('Sesión recuperada')));
    check(()=>assert.ok(first.includes('WordPress no responde temporalmente')));
    await until(async()=>Boolean(await control(file)));
    check(()=>assert.ok(fs.existsSync(file+'.worker')));
    check(()=>assert.ok(!first.includes('Abriendo la página oficial')));
    check(()=>assert.ok(fs.readFileSync(trace,'utf8').split('\n').filter(Boolean).length>=2));
    const again=await run('--launch');check(()=>assert.ok(again.includes('ya está abierto')));
    fs.writeFileSync(trace+'.maintenance','1');
    await until(async()=>fs.readFileSync(trace+'.maintenance','utf8')==='0');
    await until(async()=>fs.readFileSync(file.replace(/\.json$/,'.log'),'utf8').includes('Conexión con WordPress restablecida.'));
    check(()=>assert.ok(store.load(profile)));
    check(()=>assert.ok(!fs.readFileSync(trace,'utf8').includes('revoke')));
    await run('--stop'); await until(async()=>!(await control(file)));
    check(()=>assert.ok(store.load(profile)));
    check(()=>assert.ok(!fs.readFileSync(trace,'utf8').includes('revoke')));
    await run('--launch'); await until(async()=>Boolean(await control(file)));
    check(()=>assert.ok(store.load(profile)));
    fs.writeFileSync(trace+'.unlinked','1');
    await until(async()=>!(await control(file)));
    check(()=>assert.ok(store.load(profile)));
    check(()=>assert.ok(!fs.readFileSync(trace,'utf8').includes('revoke')));
    fs.unlinkSync(trace+'.unlinked');
    const relinked=await run('--launch'); await until(async()=>Boolean(await control(file)));
    check(()=>assert.ok(relinked.includes('Sesión recuperada')));
    await run('--logout'); await until(async()=>!fs.existsSync(store.fileFor(profile.clientId)));
    await until(async()=>!(await control(file)));
    check(()=>assert.ok(fs.readFileSync(trace,'utf8').includes('revoke')));
    check(()=>assert.equal(store.load(profile),null));
    store.save({...session,expires:0});
    await assert.rejects(()=>run('--background'),error=>error.message.includes('caducó o fue revocada')); checks++;
    check(()=>assert.equal(store.load(profile),null));
    check(()=>assert.ok(JSON.parse(fs.readFileSync(file,'utf8')).accounts.some(account=>account.clientId===profile.clientId)));
  } finally {
    await control(file,'stop');
    await until(async()=>!(await control(file)));
    if(previous===undefined) delete process.env.LOCALAPPDATA; else process.env.LOCALAPPDATA=previous;
    assert.equal(path.dirname(path.resolve(root)),path.resolve(os.tmpdir())); fs.rmSync(root,{recursive:true,force:true});
  }
  console.log(`PASS: ${checks} detached-worker lifecycle checks; fake HTTP/credentials only.`);
})().catch(error=>{console.error(error);process.exitCode=1;});
