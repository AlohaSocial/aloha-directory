#!/usr/bin/env node
// Reference verifier for Aloha Directory lists — the algorithm every client must implement.
// No dependencies; Node.js 20+.
//
//   node tools/verify-list.mjs --root <kid>=<base64url public key> --keys keys.json --list servers.json \
//        [--name servers] [--last-serial 1700000000] [--now 2026-11-13T12:00:00Z]
//
// Exit code 0 when the list verifies, 1 otherwise. See docs/lists.md.

import { createPublicKey, verify } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { parseArgs } from 'node:util';

const MAX_ENVELOPE_BYTES = 2_000_000;
const MAX_SIGNATURES = 8;
const CLOCK_SKEW_MS = 5 * 60 * 1000;

class VerificationError extends Error {}

function b64urlDecode(text) {
  if (typeof text !== 'string' || !/^[A-Za-z0-9_-]*$/.test(text)) throw new VerificationError('invalid base64url');
  return Buffer.from(text, 'base64url');
}

function ed25519Key(rawBase64url) {
  const raw = b64urlDecode(rawBase64url);
  if (raw.length !== 32) throw new VerificationError('public key must be 32 bytes');
  return createPublicKey({ key: { kty: 'OKP', crv: 'Ed25519', x: rawBase64url }, format: 'jwk' });
}

/**
 * Verifies one envelope against a map of trusted keys (kid → KeyObject) and returns the payload.
 */
function verifyEnvelope(json, trustedKeys, expectedList, lastSerial, now) {
  if (json.length > MAX_ENVELOPE_BYTES) throw new VerificationError('envelope too large');
  let envelope;
  try { envelope = JSON.parse(json); } catch { throw new VerificationError('envelope is not JSON'); }
  if (typeof envelope?.payload !== 'string' || !Array.isArray(envelope.signatures)) throw new VerificationError('malformed envelope');
  if (envelope.signatures.length === 0 || envelope.signatures.length > MAX_SIGNATURES) throw new VerificationError('bad signature count');

  // 1. The signature covers the exact payload bytes (RFC 8785 canonical JSON).
  const payloadBytes = b64urlDecode(envelope.payload);
  const valid = envelope.signatures.some((s) => {
    if (s?.alg !== 'Ed25519' || typeof s.kid !== 'string' || !trustedKeys.has(s.kid)) return false; // unknown/revoked: ignore
    try { return verify(null, payloadBytes, trustedKeys.get(s.kid), b64urlDecode(s.sig)); } catch { return false; }
  });
  if (!valid) throw new VerificationError('no valid signature from a trusted key');

  // 2. Only now parse the payload, and check what it claims.
  const payload = JSON.parse(payloadBytes.toString('utf8'));
  if (payload.list !== expectedList) throw new VerificationError(`expected list "${expectedList}"`);
  if (payload.version !== 1) throw new VerificationError('unsupported list version');
  if (!Number.isSafeInteger(payload.serial) || payload.serial < 1) throw new VerificationError('missing serial');
  // 3. Serials only go up: a lower or equal serial is a replay of an old list.
  if (lastSerial !== null && payload.serial <= lastSerial) throw new VerificationError('serial is not higher than the last accepted one (replay)');
  const issued = Date.parse(payload.issued_at);
  const expires = Date.parse(payload.expires_at);
  if (Number.isNaN(issued) || Number.isNaN(expires)) throw new VerificationError('invalid timestamps');
  if (issued > now + CLOCK_SKEW_MS) throw new VerificationError('list issued in the future');
  return payload;
}

export function verifyKeySet(json, rootKeys, lastSerial = null, now = Date.now()) {
  const payload = verifyEnvelope(json, rootKeys, 'keys', lastSerial, now);
  const listKeys = new Map();
  for (const key of payload.keys ?? []) {
    if (key.alg !== 'Ed25519' || key.status !== 'active') continue;
    if (key.not_after && Date.parse(key.not_after) < now) continue;
    listKeys.set(key.kid, ed25519Key(key.public_key));
  }
  if (listKeys.size === 0) throw new VerificationError('key set has no active list key');
  return { payload, listKeys };
}

export function verifyList(json, listKeys, name, lastSerial = null, now = Date.now()) {
  const payload = verifyEnvelope(json, listKeys, name, lastSerial, now);
  return {
    payload,
    // 4. Expired lists stay usable but are marked old; after 90 days apps stop recommending servers.
    expired: Date.parse(payload.expires_at) < now,
    tooOld: Date.parse(payload.issued_at) + 90 * 86400 * 1000 < now,
  };
}

if (import.meta.url === `file://${process.argv[1]}`) {
  const { values } = parseArgs({
    options: {
      root: { type: 'string', multiple: true },
      keys: { type: 'string' },
      list: { type: 'string' },
      name: { type: 'string', default: 'servers' },
      'last-serial': { type: 'string' },
      now: { type: 'string' },
    },
  });
  try {
    if (!values.root?.length || !values.keys || !values.list) throw new VerificationError('usage: --root kid=key --keys keys.json --list servers.json [--name servers]');
    const roots = new Map(values.root.map((item) => { const [kid, key] = item.split('='); return [kid, ed25519Key(key)]; }));
    const now = values.now ? Date.parse(values.now) : Date.now();
    const { listKeys } = verifyKeySet(readFileSync(values.keys, 'utf8'), roots, null, now);
    const last = values['last-serial'] ? Number(values['last-serial']) : null;
    const { payload, expired, tooOld } = verifyList(readFileSync(values.list, 'utf8'), listKeys, values.name, last, now);
    console.log(`OK ${values.name}.json serial ${payload.serial}, issued ${payload.issued_at}, ${payload.entries.length} entries${expired ? ' (EXPIRED)' : ''}${tooOld ? ' (TOO OLD)' : ''}`);
  } catch (error) {
    console.error(`FAILED: ${error.message}`);
    process.exit(1);
  }
}
