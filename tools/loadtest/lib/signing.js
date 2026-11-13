// RFC 9421 request signing as an Aloha server does it (the directory's signature profile, see
// src/Http/Signature/MessageSigner.php and docs/server-integration.md). Runs in k6 and in Node:
// every primitive is passed in, so this file imports nothing.
//
//   deps = {
//     nacl,                       // tweetnacl (lib/nacl-fast.js)
//     sha256: (data, enc) => …,   // data: string or Uint8Array; enc: 'hex' | 'base64' | 'base64url' (unpadded)
//     base64: (bytes) => …,       // standard base64 with padding
//     randomNonce: () => …,       // 16 random bytes as unpadded base64url
//   }

export function hexToBytes(hex) {
  const out = new Uint8Array(hex.length / 2);
  for (let i = 0; i < out.length; i++) out[i] = parseInt(hex.substr(i * 2, 2), 16);
  return out;
}

function utf8(text) {
  // Encode a JavaScript string as UTF-8 bytes (no TextEncoder in k6).
  const bytes = [];
  for (let i = 0; i < text.length; i++) {
    let c = text.charCodeAt(i);
    if (c >= 0xd800 && c <= 0xdbff && i + 1 < text.length) {
      c = 0x10000 + ((c - 0xd800) << 10) + (text.charCodeAt(++i) - 0xdc00);
    }
    if (c < 0x80) bytes.push(c);
    else if (c < 0x800) bytes.push(0xc0 | (c >> 6), 0x80 | (c & 63));
    else if (c < 0x10000) bytes.push(0xe0 | (c >> 12), 0x80 | ((c >> 6) & 63), 0x80 | (c & 63));
    else bytes.push(0xf0 | (c >> 18), 0x80 | ((c >> 12) & 63), 0x80 | ((c >> 6) & 63), 0x80 | (c & 63));
  }
  return new Uint8Array(bytes);
}

// The key pair of a fake server created by `bin/console app:loadtest:seed`.
export function serverKeys(deps, seed, domain) {
  const pair = deps.nacl.sign.keyPair.fromSeed(hexToBytes(deps.sha256(`aloha-loadtest:${seed}:${domain}`, 'hex')));
  return { secretKey: pair.secretKey, publicKey: pair.publicKey, keyId: deps.sha256(pair.publicKey, 'base64url') };
}

function quoted(value) {
  return `"${String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"')}"`;
}

// Returns the headers (Signature-Input, Signature and, with a body, Content-Digest).
export function signRequest(deps, keys, { method, authority, path, body = '', created }) {
  const [pathOnly, query] = path.split(/\?(.*)/s, 2);
  const components = ['@method', '@authority', '@path'];
  const values = { '@method': method.toUpperCase(), '@authority': authority.toLowerCase(), '@path': pathOnly };
  if (query !== undefined) {
    components.push('@query');
    values['@query'] = `?${query}`;
  }
  const headers = {};
  if (body !== '') {
    headers['Content-Digest'] = `sha-256=:${deps.sha256(body, 'base64')}:`;
    components.push('content-digest');
    values['content-digest'] = headers['Content-Digest'];
  }
  const params = `(${components.map(quoted).join(' ')})`
    + `;created=${created}`
    + `;keyid=${quoted(keys.keyId)}`
    + `;alg=${quoted('ed25519')}`
    + `;tag=${quoted('aloha-directory')}`
    + `;nonce=${quoted(deps.randomNonce())}`;
  const base = components.map((c) => `${quoted(c)}: ${values[c]}`).concat([`"@signature-params": ${params}`]).join('\n');
  const signature = deps.nacl.sign.detached(utf8(base), keys.secretKey);
  headers['Signature-Input'] = `dir=${params}`;
  headers['Signature'] = `dir=:${deps.base64(signature)}:`;
  return headers;
}

export function heartbeat(domain, now, i, minVersion) {
  return {
    domain,
    software: { name: 'aloha', version: minVersion, nextcloud: '33.0.2' },
    signup: { open: true, mode: 'approval', available_accounts: 50 + (i % 500), approval_backlog: i % 7 },
    usage: { users_total: 1000 + i + Math.floor(now / 3600), users_active_month: 500 + (i % 300) },
    search: { accounts: false, hashtags: false, statuses: false, trends: false, load: 0 },
    health: { queue_lag_seconds: 3, federation_ok: true },
    profile_etag: 'W/"loadtest"',
    sent_at: new Date(now * 1000).toISOString().replace(/\.\d{3}Z$/, 'Z'),
  };
}
