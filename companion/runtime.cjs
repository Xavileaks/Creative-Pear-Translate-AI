'use strict';
// Private local pipe; no public or localhost TCP server is opened by the worker.
const fs = require('node:fs');
const path = require('node:path');
const net = require('node:net');
const crypto = require('node:crypto');
const {atomicWrite} = require('./session-store.cjs');

function pipeFor(file) {
  const id = crypto.createHash('sha256').update(path.resolve(file)).digest('hex').slice(0,32);
  return process.platform === 'win32' ? '\\\\.\\pipe\\cptai-' + id : path.join(path.dirname(file),'cptai-' + id + '.sock');
}
async function control(file, operation = 'status') {
  let record;
  try { record = JSON.parse(fs.readFileSync(file + '.worker','utf8')); } catch { return null; }
  if (record.pipe !== pipeFor(file) || !/^[a-f0-9]{64}$/.test(record.nonce || '')) return null;
  return new Promise(resolve => {
    const socket = net.createConnection(record.pipe); let text = '';
    socket.setTimeout(15000, () => { socket.destroy(); resolve(null); });
    socket.on('connect',()=>socket.write(JSON.stringify({operation,nonce:record.nonce}) + '\n'));
    socket.on('data',chunk=>{ text += chunk; if (text.length>2000) { socket.destroy(); resolve(null); } });
    socket.on('error',()=>resolve(null));
    socket.on('end',()=>{ try { resolve(JSON.parse(text)); } catch { resolve(null); } });
  });
}
async function acquireRuntime(file, handler) {
  const pipe = pipeFor(file), nonce = crypto.randomBytes(32).toString('hex');
  fs.mkdirSync(path.dirname(file),{recursive:true,mode:0o700});
  if (process.platform !== 'win32' && fs.existsSync(pipe)) {
    if (await control(file)) throw new Error('El asistente de esta web ya está abierto.');
    if (!fs.lstatSync(pipe).isSocket()) throw new Error('El canal local no es válido.');
    fs.unlinkSync(pipe);
  }
  const server = net.createServer(socket=>{
    let text = '', used = false;
    socket.setTimeout(3000,()=>socket.destroy());
    socket.on('error',()=>{});
    socket.on('data',async chunk=>{
      if (used) return; text += chunk;
      if (text.length>1000) { used=true; socket.destroy(); return; }
      if (!text.includes('\n')) return; used=true;
      let input;
      try { input=JSON.parse(text.trim()); } catch { socket.destroy(); return; }
      if (input.nonce !== nonce || !['status','stop','logout'].includes(input.operation)) { socket.destroy(); return; }
      socket.setTimeout(15000);
      try { const result=await handler(input.operation); socket.end(JSON.stringify(result)); }
      catch { socket.end(JSON.stringify({error:true})); }
    });
  });
  await new Promise((resolve,reject)=>{ server.once('error',reject); server.listen(pipe,resolve); });
  if (process.platform !== 'win32') fs.chmodSync(pipe,0o600);
  try { atomicWrite(file + '.worker',JSON.stringify({pid:process.pid,pipe,nonce})); }
  catch(error) { server.close(); throw error; }
  return async () => {
    try { if (JSON.parse(fs.readFileSync(file + '.worker','utf8')).nonce===nonce) fs.unlinkSync(file + '.worker'); } catch {}
    await new Promise(resolve=>server.close(resolve));
  };
}
module.exports = {control,acquireRuntime,pipeFor};
