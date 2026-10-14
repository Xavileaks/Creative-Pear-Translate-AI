'use strict';
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const {Readable} = require('node:stream');
const {AUTH_WAIT_MS, jsonRequest, parseModels, validateConnection, endpointFor, validateIdentity, validateGrant, validateJob, translationPayload, parseStream, errorCode, callbackListener, authorize, refresh, retryableSiteError, resilientSiteRequest, SafeError} = require('../companion/cptai-companion.cjs');
let checks = 0;
function check(fn) { fn(); checks++; }
const connection = {version:1, endpoint:'https://example.org/wp-json/cptai/v1/plan/', secret:'a'.repeat(64), installation:'12345678-1234-1234-1234-123456789abc'};
check(() => assert.equal(AUTH_WAIT_MS, 900000));
check(() => assert.deepEqual(parseModels({models:[{slug:'model-z',visibility:'list'},{slug:'hidden',visibility:'hide'},{slug:'model-a',visibility:'list'},{slug:'model-z',visibility:'list'}]}), ['model-z','model-a']));
check(() => assert.deepEqual(parseModels({models:[{slug:'invalid id',visibility:'list'},null]}), []));
check(() => assert.throws(() => parseModels({data:[{id:'api-key-model'}]})));
check(() => assert.equal(validateConnection(connection).endpoint, connection.endpoint));
check(() => assert.equal(endpointFor(connection, 'poll'), connection.endpoint + 'poll'));
const plain = {...connection, endpoint:'https://example.org/?rest_route=/cptai/v1/plan/'};
check(() => assert.equal(new URL(endpointFor(plain, 'poll')).searchParams.get('rest_route'), '/cptai/v1/plan/poll'));
for (const endpoint of ['http://example.org/wp-json/cptai/v1/plan/', 'https://user:pass@example.org/wp-json/cptai/v1/plan/', 'file:///tmp/cptai/v1/plan/', 'https://example.org/other/']) check(() => assert.throws(() => validateConnection({...connection, endpoint})));
check(() => assert.doesNotThrow(() => validateConnection({...connection, endpoint:'http://127.0.0.1:8764/wp-json/cptai/v1/plan/'})));
check(() => assert.throws(() => validateConnection({...connection, secret:'invalid'})));
const {publicKey, privateKey} = crypto.generateKeyPairSync('rsa', {modulusLength:2048});
const jwk = {...publicKey.export({format:'jwk'}), kid:'test', alg:'RS256', use:'sig'};
const claims = {iss:'https://auth.openai.com', aud:'oaiapp_test', sub:'test-user', exp:2000, nonce:'nonce', email:'fake@example.invalid'};
const sign = (values, header = {alg:'RS256', kid:'test'}) => {
  const data = [header, values].map(value => Buffer.from(JSON.stringify(value)).toString('base64url')).join('.');
  return data + '.' + crypto.sign('RSA-SHA256', Buffer.from(data), privateKey).toString('base64url');
};
const verifyOptions = {clientId:'oaiapp_test', nonce:'nonce', subject:'test-user', now:1000};
check(() => assert.equal(validateIdentity(sign(claims), {keys:[jwk]}, verifyOptions).sub, 'test-user'));
for (const extra of [{iss:'https://evil.invalid'}, {aud:'wrong'}, {sub:'another'}, {nonce:'wrong'}, {exp:999}, {nbf:2000}, {aud:['oaiapp_test','another']}, {sub:''}]) check(() => assert.throws(() => validateIdentity(sign({...claims,...extra}), {keys:[jwk]}, verifyOptions)));
check(() => assert.throws(() => validateIdentity(sign(claims, {alg:'none', kid:'test'}), {keys:[jwk]}, verifyOptions)));
check(() => assert.throws(() => validateIdentity(sign(claims), {keys:[]}, verifyOptions)));
check(() => assert.throws(() => validateIdentity(sign(claims).replace(/.$/, '!'), {keys:[jwk]}, verifyOptions)));
const grant = {scope:'openid profile resource.invoke chatgpt.tokens.use.direct', token_type:'Bearer', access_token:'fake-access', refresh_token:'fake-refresh', expires_in:3600};
check(() => assert.doesNotThrow(() => validateGrant(grant)));
for (const extra of [{scope:'openid profile'}, {refresh_token:''}, {access_token:''}, {expires_in:0}, {token_type:'Basic'}]) check(() => assert.throws(() => validateGrant({...grant, ...extra})));
const job = {id:'b'.repeat(32), model:'test-model', source:'en', target:'es', strings:['Send %s']};
check(() => assert.doesNotThrow(() => validateJob(job, ['test-model'])));
for (const extra of [{model:'other'}, {source:'bad'}, {target:'en'}, {strings:['<script>']}, {strings:['x'.repeat(3501)]}, {strings:[]}, {strings:Array(11).fill('Test')}, {strings:[123]}]) check(() => assert.throws(() => validateJob({...job,...extra}, ['test-model'])));
const payload = translationPayload(job);
check(() => assert.equal(payload.stream, true));
check(() => assert.equal(payload.store, false));
check(() => assert.ok(Array.isArray(payload.input)));
check(() => assert.equal(payload.input[0].content, JSON.stringify(job.strings)));
for (const field of ['max_output_tokens','temperature','previous_response_id','background','tools']) check(() => assert.equal(payload[field], undefined));
check(() => assert.equal(errorCode(403, {error:{code:'subscription_sharing_user_not_eligible'}}), 'subscription_sharing_user_not_eligible'));
check(() => assert.equal(errorCode(401, {error:{message:'private-secret'}}), 'auth'));

(async () => {
  const frame = event => `data: ${JSON.stringify(event)}\r\n\r\n`;
  const done = frame({type:'response.completed', response:{status:'completed',output:[{content:[{type:'output_text',text:'["Enviar %s"]'}]}]}});
  const bytes = Buffer.from(frame({type:'response.output_text.delta',delta:'ignored'}) + done);
  const split = [...bytes].map(byte => Buffer.from([byte]));
  assert.deepEqual(await parseStream(Readable.from(split)), ['Enviar %s']); checks++;
  const deltaOnly = frame({type:'response.output_text.delta',item_id:'item',content_index:0,delta:'["Enviar '}) + frame({type:'response.output_text.delta',item_id:'item',content_index:0,delta:'%s"]'}) + frame({type:'response.output_text.done',item_id:'item',content_index:0,text:'["Enviar %s"]'}) + frame({type:'response.completed',response:{status:'completed',output:[]}});
  assert.deepEqual(await parseStream(Readable.from([Buffer.from(deltaOnly)])), ['Enviar %s']); checks++;
  const fencedResult = frame({type:'response.output_text.done',text:'```json\n["Enviar %s"]\n```'}) + frame({type:'response.completed',response:{status:'completed'}});
  assert.deepEqual(await parseStream(Readable.from([Buffer.from(fencedResult)])), ['Enviar %s']); checks++;
  for (const text of [frame({type:'response.failed',response:{error:{code:'subscription_sharing_usage_limit_exceeded'}}}), frame({type:'response.incomplete'}), frame({type:'response.output_text.delta',delta:'partial'}), done.replace('Enviar %s', 'broken\\"')]) {
    await assert.rejects(() => parseStream(Readable.from([Buffer.from(text)]))); checks++;
  }
  const listener = await callbackListener('valid-state', 4000);
  const invalid = await fetch(listener.redirect + '?state=wrong&code=fake');
  check(() => assert.equal(invalid.status, 400));
  const pending = listener.callback;
  await fetch(listener.redirect + '?state=valid-state&code=fake-code&client_id=oaiapp_test');
  const result = await pending;
  check(() => assert.equal(result.code, 'fake-code'));
  const replay = await fetch(listener.redirect + '?state=valid-state&code=fake-code&client_id=oaiapp_test');
  check(() => assert.equal(replay.status, 400));
  listener.close();

  // A slow sign-in still succeeds while the listener is open; expiry is actionable.
  const delayed = await callbackListener('delayed-state', 1500);
  await new Promise(resolve => setTimeout(resolve, 150));
  await fetch(delayed.redirect + '?state=delayed-state&code=fake-delay&client_id=oaiapp_test');
  const delayedResult = await delayed.callback;
  check(() => assert.equal(delayedResult.code, 'fake-delay'));
  delayed.close();
  const expired = await callbackListener('expired-state', 20);
  await assert.rejects(expired.callback, error => error.code === 'timeout'); checks++;
  expired.close();

  // Complete an OAuth exchange against a deterministic fake transport, real loopback and real RSA signatures.
  const originalFetch = global.fetch;
  let authUrl, posted;
  const mock = async (url, options = {}) => {
    const href = String(url);
    if (href.startsWith('http://127.0.0.1:')) return originalFetch(url, options);
    if (href.endsWith('openid-configuration')) return Response.json({issuer:'https://auth.openai.com', authorization_endpoint:'https://auth.openai.com/api/accounts/authorize', token_endpoint:'https://auth.openai.com/api/accounts/oauth/token', jwks_uri:'https://auth.openai.com/.well-known/jwks.json'});
    if (href.endsWith('/jwks.json')) return Response.json({keys:[jwk]});
    if (href.endsWith('/oauth/token')) {
      posted = new URLSearchParams(options.body);
      return Response.json({...grant, id_token:sign({...claims, nonce:authUrl.searchParams.get('nonce'), exp:Math.floor(Date.now()/1000)+3600})});
    }
    throw new Error('Unexpected test request');
  };
  global.fetch = mock;
  try {
    const session = await authorize(undefined, 'urn:uuid:12345678-1234-1234-1234-123456789abc', url => {
      authUrl = new URL(url);
      const callback = new URL(authUrl.searchParams.get('redirect_uri'));
      callback.searchParams.set('state', authUrl.searchParams.get('state')); callback.searchParams.set('code','fake-code'); callback.searchParams.set('client_id','oaiapp_test');
      originalFetch(callback).catch(() => {});
    });
    check(() => assert.equal(session.subject, 'test-user'));
    check(() => assert.equal(authUrl.searchParams.get('client_id'), 'dynamic_agent_client'));
    check(() => assert.equal(authUrl.searchParams.get('agent_name_hint'), 'Creative Pear Translate AI'));
    check(() => assert.equal(posted.get('client_id'), 'oaiapp_test'));
    check(() => assert.equal(posted.get('redirect_uri'), authUrl.searchParams.get('redirect_uri')));
    check(() => assert.equal(crypto.createHash('sha256').update(posted.get('code_verifier')).digest('base64url'), authUrl.searchParams.get('code_challenge')));
    check(() => assert.equal(posted.has('client_secret'), false));
    check(() => assert.equal(authUrl.searchParams.has('access_token'), false));
    session.expires = 0;
    await refresh(session);
    check(() => assert.equal(posted.get('grant_type'), 'refresh_token'));
    check(() => assert.equal(posted.get('client_id'), 'oaiapp_test'));
    check(() => assert.equal(posted.has('scope'), false));
    global.fetch = async () => Response.json({models:[{slug:'model-z',visibility:'list',instructions:'x'.repeat(500000)}]});
    const largeCatalog = await jsonRequest('https://api.openai.com/v1/models');
    check(() => assert.deepEqual(parseModels(largeCatalog), ['model-z']));
    await assert.rejects(() => jsonRequest('https://auth.openai.com/api/accounts/oauth/token')); checks++;
  } finally { global.fetch = originalFetch; }
  // Simulate update downtime and delivery retries; no real sleep or provider calls.
  for (const status of [404,408,429,500,502,503,504]) check(() => assert.equal(retryableSiteError(Object.assign(new SafeError('model'),{status})), true));
  for (const status of [400,401,403,409,410,413,422]) check(() => assert.equal(retryableSiteError(Object.assign(new SafeError('network'),{status})), false));
  check(() => assert.equal(retryableSiteError(new SafeError('auth')), false));
  check(() => assert.equal(retryableSiteError(new Error('arbitrary error')), false));
  let requests = 0; const delays = [], logs = [], deliveries = [];
  const delivery = {id:job.id,translations:['Enviar %s']};
  const recovered = await resilientSiteRequest(connection, 'complete', delivery, {
    wait:async ms => delays.push(ms), report:line => logs.push(line),
    request:async (site,operation,body) => {
      deliveries.push({site,operation,body});
      if (++requests <= 6) throw Object.assign(new SafeError(requests % 2 ? 'network' : 'format'), {status:requests % 2 ? 503 : 200});
      return {accepted:true};
    }
  });
  check(() => assert.deepEqual(recovered,{accepted:true}));
  check(() => assert.deepEqual(delays,[2000,4000,8000,16000,30000,30000]));
  check(() => assert.equal(logs.length,2));
  check(() => assert.ok(deliveries.every(item => item.operation==='complete' && item.body===delivery && item.site===connection)));
  check(() => assert.ok(!JSON.stringify(logs).includes(connection.secret)));
  for (const status of [401,403,409]) {
    let attempts=0;
    await assert.rejects(()=>resilientSiteRequest(connection,'poll',{}, {request:async()=>{attempts++;throw Object.assign(new SafeError('auth'),{status});},wait:async()=>{throw new Error('Must not retry');}}),error=>error.status===status); checks++;
    check(()=>assert.equal(attempts,1));
  }
  let stopping=false;
  await assert.rejects(()=>resilientSiteRequest(connection,'poll',{}, {request:async()=>{throw new SafeError('network');},wait:async()=>{stopping=true;},stopped:()=>stopping,report:()=>{}}),error=>error.code==='stopped'); checks++;
  global.fetch = async()=>new Response('<html>Updating WordPress</html>',{status:503,headers:{'content-type':'text/html'}});
  try {
    await assert.rejects(()=>jsonRequest('https://example.org/wp-json/cptai/v1/plan/poll'),error=>error.status===503 && retryableSiteError(error)); checks++;
  } finally { global.fetch=originalFetch; }
  console.log(`PASS: ${checks} companion checks; no real OpenAI requests or credentials.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
