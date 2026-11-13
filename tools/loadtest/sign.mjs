#!/usr/bin/env node
// Signs one request with the load-test signing code, for checking it against the PHP verifier
// (tests/Functional/LoadtestTest.php) or for debugging with curl. Node.js 20+.
//
//   node tools/loadtest/sign.mjs <seed> <domain> <method> <authority> <path> <created> [body]
//
// Prints {"headers": {...}, "key_id": "...", "public_key": "<base64url>"}.

import { createHash, randomBytes } from 'node:crypto';
import { createRequire } from 'node:module';
import { serverKeys, signRequest } from './lib/signing.js';

const nacl = createRequire(import.meta.url)('./lib/nacl-fast.js');
const deps = {
  nacl,
  sha256: (data, enc) => createHash('sha256').update(typeof data === 'string' ? Buffer.from(data, 'utf8') : Buffer.from(data)).digest(enc),
  base64: (bytes) => Buffer.from(bytes).toString('base64'),
  randomNonce: () => randomBytes(16).toString('base64url'),
};

const [seed, domain, method, authority, path, created, body = ''] = process.argv.slice(2);
if (!created) {
  console.error('usage: sign.mjs <seed> <domain> <method> <authority> <path> <created> [body]');
  process.exit(2);
}
const keys = serverKeys(deps, seed, domain);
const headers = signRequest(deps, keys, { method, authority, path, body, created: Number(created) });
console.log(JSON.stringify({ headers, key_id: keys.keyId, public_key: Buffer.from(keys.publicKey).toString('base64url') }));
