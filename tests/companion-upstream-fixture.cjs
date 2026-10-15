'use strict';
// Preloaded only by worker-lifecycle.cjs. All HTTP is fake and no headers are logged.
const fs=require('node:fs');
global.fetch=async(url,options={})=>{
  const value=String(url);
  if(value==='https://api.openai.com/v1/models') return Response.json({models:[{slug:'fake-model',visibility:'list'}]});
  if(value.startsWith('https://worker-fixture.invalid/wp-json/cptai/v1/plan/')) {
    fs.appendFileSync(process.env.CPTAI_TEST_TRACE,'poll\n');
    if(fs.existsSync(process.env.CPTAI_TEST_TRACE+'.unlinked')) return Response.json({code:'cptai_link'},{status:401});
    if(fs.existsSync(process.env.CPTAI_TEST_TRACE+'.maintenance')) {
      const remaining=Number(fs.readFileSync(process.env.CPTAI_TEST_TRACE+'.maintenance','utf8'));
      if(remaining>0) { fs.writeFileSync(process.env.CPTAI_TEST_TRACE+'.maintenance',String(remaining-1));return new Response('Updating WordPress',{status:503}); }
    }
    if(!fs.existsSync(process.env.CPTAI_TEST_TRACE+'.recovered')) {
      fs.writeFileSync(process.env.CPTAI_TEST_TRACE+'.recovered','1');
      return new Response('<html>Updating WordPress</html>',{status:503,headers:{'content-type':'text/html'}});
    }
    return Response.json({job:null});
  }
  if(value==='https://auth.openai.com/test-revoke') { fs.appendFileSync(process.env.CPTAI_TEST_TRACE,'revoke\n'); return new Response('',{status:200}); }
  if(value==='https://auth.openai.com/api/accounts/oauth/token') return Response.json({error:'invalid_grant'},{status:400});
  throw new Error('Unexpected upstream: no real network permitted');
};
