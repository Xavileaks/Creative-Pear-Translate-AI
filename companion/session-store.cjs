'use strict';
// Local credentials only: DPAPI CurrentUser on Windows, owner-only files on Unix.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const {spawnSync} = require('node:child_process');

function atomicWrite(file, value) {
  fs.mkdirSync(path.dirname(file), {recursive:true, mode:0o700});
  if (fs.lstatSync(path.dirname(file)).isSymbolicLink()) throw new Error('Unsafe credential directory');
  if (process.platform !== 'win32') fs.chmodSync(path.dirname(file),0o700);
  if (fs.existsSync(file) && fs.lstatSync(file).isSymbolicLink()) throw new Error('Unsafe credential destination');
  const temporary = file + '.' + crypto.randomBytes(12).toString('hex') + '.tmp';
  let descriptor;
  try {
    descriptor = fs.openSync(temporary, 'wx', 0o600);
    fs.writeFileSync(descriptor, value); fs.fsyncSync(descriptor); fs.closeSync(descriptor); descriptor = undefined;
    fs.renameSync(temporary, file);
  } finally {
    if (descriptor !== undefined) fs.closeSync(descriptor);
    if (fs.existsSync(temporary)) fs.unlinkSync(temporary);
  }
}

function dpapi(value, decrypt = false) {
  const executable = path.join(process.env.SystemRoot || 'C:\\Windows', 'System32', 'WindowsPowerShell', 'v1.0', 'powershell.exe');
  const script = '[Console]::InputEncoding=[Text.UTF8Encoding]::new($false); [Console]::OutputEncoding=[Text.UTF8Encoding]::new($false); Add-Type -AssemblyName System.Security; $inputValue=[Console]::In.ReadToEnd(); ' + (decrypt
    ? '$bytes=[Convert]::FromBase64String($inputValue); $plain=[Security.Cryptography.ProtectedData]::Unprotect($bytes,$null,[Security.Cryptography.DataProtectionScope]::CurrentUser); [Console]::Write([Text.Encoding]::UTF8.GetString($plain))'
    : '$bytes=[Text.Encoding]::UTF8.GetBytes($inputValue); $sealed=[Security.Cryptography.ProtectedData]::Protect($bytes,$null,[Security.Cryptography.DataProtectionScope]::CurrentUser); [Console]::Write([Convert]::ToBase64String($sealed))');
  const result = spawnSync(executable, ['-NoProfile','-NonInteractive','-Command',script], {input:value, encoding:'utf8', windowsHide:true, timeout:20000, maxBuffer:200000});
  if (result.status !== 0 || result.error || !result.stdout) throw new Error('No se pudo acceder al almacén protegido de Windows.');
  return result.stdout;
}

function sessionStore(metadataFile, {platform = process.platform, protect = value => dpapi(value), unprotect = value => dpapi(value,true)} = {}) {
  const fileFor = clientId => metadataFile.replace(/\.json$/, '') + '-' + crypto.createHash('sha256').update(clientId).digest('hex').slice(0,32) + '.session';
  return {
    save(session) {
      const record = {version:1,issuer:'https://auth.openai.com',clientId:session.clientId,subject:session.subject,email:session.email,tokens:session.tokens,expires:session.expires,revocation:session.revocation};
      const value = JSON.stringify(record);
      atomicWrite(fileFor(session.clientId), JSON.stringify(platform === 'win32' ? {format:'dpapi-current-user',data:protect(value)} : {format:'owner-only',data:value}));
    },
    load(profile) {
      if (!profile) return null;
      const file = fileFor(profile.clientId);
      if (!fs.existsSync(file)) return null;
      if (fs.lstatSync(file).isSymbolicLink() || fs.statSync(file).size > 200000) throw new Error('El archivo de sesión no es válido.');
      if (platform !== 'win32' && (fs.statSync(file).mode & 0o077)) throw new Error('El archivo de sesión debe ser privado (0600).');
      const envelope = JSON.parse(fs.readFileSync(file,'utf8'));
      if (platform === 'win32' && envelope.format !== 'dpapi-current-user') throw new Error('La sesión debe estar protegida por Windows.');
      if (platform !== 'win32' && envelope.format !== 'owner-only') throw new Error('El formato de sesión no es compatible.');
      const record = JSON.parse(platform === 'win32' ? unprotect(envelope.data) : envelope.data);
      if (record.version !== 1 || record.issuer !== 'https://auth.openai.com' || record.clientId !== profile.clientId || record.subject !== profile.subject || !Number.isFinite(record.expires) || !record.tokens) throw new Error('La sesión no corresponde a esta cuenta.');
      return record;
    },
    clear(profile) { const file = fileFor(profile.clientId); if (fs.existsSync(file)) fs.unlinkSync(file); },
    fileFor,
  };
}
module.exports = {atomicWrite, sessionStore, dpapi};
