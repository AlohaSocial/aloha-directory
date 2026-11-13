// k6 load test of the Aloha Directory (docs/loadtest.md). Run against a STAGING copy only:
//
//   LOADTEST_SEED=… bin/console app:loadtest:seed --servers=5000 --not-production
//   k6 run -e BASE_URL=https://staging-directory.example.org -e SEED="$LOADTEST_SEED" -e SERVERS=5000 \
//          -e SUFFIX=.loadtest.staging-directory.example.org tools/loadtest/directory.js
//
// Scenarios (rates per second, all overridable with -e):
//   heartbeats  HEARTBEAT_RATE  signed PUT /heartbeat, each fake server in turn
//   status      STATUS_RATE     signed GET /status
//   lists       LIST_RATE       GET /lists/v1/servers.json with If-None-Match, as the apps do
//   pages       PAGE_RATE       GET / and a server page, as visitors do
// DURATION (default 5m) applies to every scenario. The thresholds below are the response-time
// targets; k6 exits non-zero when one is missed.

import http from 'k6/http';
import crypto from 'k6/crypto';
import encoding from 'k6/encoding';
import { check, fail } from 'k6';import { Counter } from 'k6/metrics';
import { heartbeat, serverKeys, signRequest } from './lib/signing.js';

// tweetnacl (public domain) is a CommonJS/UMD file: evaluate it with a module object. It looks for
// a random source when it loads; signing and key derivation need none.
const nacl = (() => {
  const module = { exports: {} };
  // eslint-disable-next-line no-new-func
  new Function('module', 'self', 'require', open('./lib/nacl-fast.js'))(module, { crypto: { getRandomValues: (a) => a } }, undefined);
  return module.exports;
})();

const BASE_URL = (__ENV.BASE_URL || 'http://localhost:8080').replace(/\/$/, '');
const AUTHORITY = (__ENV.AUTHORITY || BASE_URL.replace(/^https?:\/\//, '')).toLowerCase();
const SEED = __ENV.SEED || '';
const SERVERS = parseInt(__ENV.SERVERS || '1000', 10);
const SUFFIX = __ENV.SUFFIX || '';
const MIN_VERSION = __ENV.MIN_VERSION || '0.27.0';
const DURATION = __ENV.DURATION || '5m';
const rate = (name, fallback) => parseInt(__ENV[name] || String(fallback), 10);
const HEARTBEAT_RATE = rate('HEARTBEAT_RATE', 10);

if (SEED.length < 16 || SUFFIX === '') {
  fail('Set SEED (as given to app:loadtest:seed) and SUFFIX (printed by it).');
}
// The directory accepts one heartbeat per server every 5 minutes.
if (SERVERS / HEARTBEAT_RATE < 300) {
  fail(`HEARTBEAT_RATE ${HEARTBEAT_RATE}/s is too high for ${SERVERS} servers: each would beat more often than every 5 minutes. Seed more servers or lower the rate.`);
}

const deps = {
  nacl,
  sha256: (data, enc) => crypto.sha256(typeof data === 'string' ? data : data.buffer, enc === 'base64url' ? 'base64rawurl' : enc),
  base64: (bytes) => encoding.b64encode(bytes.buffer, 'std'),
  randomNonce: () => encoding.b64encode(crypto.randomBytes(16), 'rawurl'),
};

const domainOf = (n) => `lt${String(n).padStart(5, '0')}${SUFFIX}`;
const keyCache = {};
const keysOf = (domain) => (keyCache[domain] ||= serverKeys(deps, SEED, domain));
const unexpected = new Counter('unexpected_status');

const scenario = (exec, perSecond) => ({
  executor: 'constant-arrival-rate', exec, rate: perSecond, timeUnit: '1s', duration: DURATION,
  preAllocatedVUs: Math.max(5, perSecond * 2), maxVUs: Math.max(20, perSecond * 10),
});

export const options = {
  scenarios: {
    heartbeats: scenario('heartbeats', HEARTBEAT_RATE),
    status: scenario('status', rate('STATUS_RATE', 5)),
    lists: scenario('lists', rate('LIST_RATE', 50)),
    pages: scenario('pages', rate('PAGE_RATE', 10)),
  },
  thresholds: {
    // Targets (docs/loadtest.md). PHP endpoints include signature verification and a database write.
    'http_req_duration{scenario:heartbeats}': ['p(95)<250', 'p(99)<600'],
    'http_req_duration{scenario:status}': ['p(95)<200', 'p(99)<500'],
    // Static files from nginx or a CDN.
    'http_req_duration{scenario:lists}': ['p(95)<50', 'p(99)<150'],
    'http_req_duration{scenario:pages}': ['p(95)<50', 'p(99)<150'],
    http_req_failed: ['rate<0.01'],
    unexpected_status: ['count<1'],
    checks: ['rate>0.99'],
  },
};

function signedRequest(method, path, body) {
  const domain = path.split('/')[4];
  const headers = signRequest(deps, keysOf(domain), { method, authority: AUTHORITY, path, body, created: Math.floor(Date.now() / 1000) });
  headers['Content-Type'] = 'application/json';
  headers.Accept = 'application/json';
  return http.request(method, `${BASE_URL}${path}`, body === '' ? null : body, { headers, tags: { name: `${method} ${path.replace(/\/servers\/[^/]+/, '/servers/{domain}')}` } });
}

export function heartbeats() {
  // Every iteration takes the next server, so each one beats every SERVERS / HEARTBEAT_RATE seconds.
  const n = (exec.scenario.iterationInTest % SERVERS) + 1;
  const domain = domainOf(n);
  const body = JSON.stringify(heartbeat(domain, Math.floor(Date.now() / 1000), n, MIN_VERSION));
  const res = signedRequest('PUT', `/api/v1/servers/${domain}/heartbeat`, body);
  if (!check(res, { 'heartbeat accepted': (r) => r.status === 200 })) unexpected.add(1, { status: String(res.status) });
}

export function status() {
  const domain = domainOf(Math.floor(Math.random() * SERVERS) + 1);
  const res = signedRequest('GET', `/api/v1/servers/${domain}/status`, '');
  if (!check(res, { 'status 200': (r) => r.status === 200 })) unexpected.add(1, { status: String(res.status) });
}

let etag = null;
export function lists() {
  const res = http.get(`${BASE_URL}/lists/v1/servers.json`, { headers: etag ? { 'If-None-Match': etag } : {}, tags: { name: 'servers.json' } });
  if (res.status === 200 && res.headers.Etag) etag = res.headers.Etag;
  check(res, { 'list 200 or 304': (r) => r.status === 200 || r.status === 304 });
}

export function pages() {
  const res = Math.random() < 0.5
    ? http.get(`${BASE_URL}/`, { tags: { name: 'home' } })
    : http.get(`${BASE_URL}/servers/${domainOf(Math.floor(Math.random() * Math.min(SERVERS, 50)) + 1)}/`, { tags: { name: 'server page' } });
  check(res, { 'page 200': (r) => r.status === 200 || r.status === 404 });
}
