// Runs the PSO Sys File Compare drop zone's Alpine component (extracted from the rendered Blade view,
// path given as the first argument) against stubbed XMLHttpRequest / $wire. The component is wrapped
// the way Alpine wraps x-data: a deep reactive Proxy, so array items read back out are proxies, not the
// original objects. Without that, an identity comparison on a finished upload never matches and the
// list fills up with "uploading" entries until the file limit is reached after only four files.
const fs = require('fs');
const assert = require('assert');
const src = fs.readFileSync(process.argv[2], 'utf8').replace(/^const component = /, 'return ').replace(/;\s*$/, '');

function reactive(root) {
  const cache = new WeakMap();
  const wrap = (value) => {
    if (typeof value !== 'object' || value === null) return value;
    if (cache.has(value)) return cache.get(value);
    const proxy = new Proxy(value, { get: (t, k, r) => wrap(Reflect.get(t, k, r)), set: (t, k, v) => Reflect.set(t, k, v) });
    cache.set(value, proxy);
    return proxy;
  };
  return wrap(root);
}

function makeComponent(existing = 0) {
  const calls = [];
  class FakeXHR {
    constructor() { this.upload = {}; }
    open() {} setRequestHeader() {}
    send(body) {
      calls.push(body.get('file').name);
      const next = (global.__responses || []).shift();
      if (next) { this.status = next.status; this.responseText = typeof next.body === 'string' ? next.body : JSON.stringify(next.body); setTimeout(() => this.onload(), 0); return; }
      this.status = 201; this.responseText = JSON.stringify({ id: 'x'.repeat(26), name: body.get('file').name });
      setTimeout(() => this.onload(), 0);
    }
  }
  const environments = {};
  const $wire = {
    formData: { environments },
    addUploadedFile: async (kind, id, name) => { environments['k' + Object.keys(environments).length] = { name }; },
  };
  for (let i = 0; i < existing; i++) environments['seed' + i] = {};
  const FormDataStub = class { constructor() { this.m = new Map(); } append(k, v) { this.m.set(k, v); } get(k) { return this.m.get(k); } };
  const raw = new Function('$wire', 'document', 'XMLHttpRequest', 'FormData', src)($wire, { querySelector: () => ({ content: 't' }) }, FakeXHR, FormDataStub);
  raw.$refs = {};
  return { component: reactive(raw), calls, environments };
}
const file = (n) => ({ name: n, size: 100 });

(async () => {
  // 1. the real limit: ten files dropped at once -> 8 accepted, 2 refused with the plain message
  let t = makeComponent();
  await t.component.pick(Array.from({ length: 10 }, (_, i) => file('f' + i + '.xml')));
  assert.strictEqual(t.calls.length, 8, 'eight should be sent');
  assert.deepStrictEqual(t.component.uploads.map((u) => u.error), ['f8.xml: you can compare up to 8 files at a time.', 'f9.xml: you can compare up to 8 files at a time.']);
  console.log('1 limit of 8 enforced, finished uploads left the list: OK');

  // 2. files already in the list count towards the limit
  t = makeComponent(6);
  await t.component.pick(['a.xml', 'b.xml', 'c.xml'].map(file));
  assert.strictEqual(t.calls.length, 2);
  assert.match(t.component.uploads[0].error, /up to 8 files/);
  console.log('2 existing files count: OK');

  // 3. local rejections never reach the server and Dismiss removes exactly that one
  t = makeComponent();
  await t.component.pick([file('notes.txt'), { name: 'huge.xml', size: 26 * 1024 * 1024 }, file('ok.xml')]);
  assert.strictEqual(t.calls.length, 1);
  assert.strictEqual(t.component.uploads.length, 2);
  t.component.remove(t.component.uploads[0].id);
  assert.strictEqual(t.component.uploads.length, 1);
  assert.match(t.component.uploads[0].error, /larger than 25 MB/);
  console.log('3 local rejections + Dismiss: OK');

  // 4. server messages are shown as given and a failed upload does not block later ones
  global.__responses = [{ status: 422, body: { message: 'bad.xml: not a DsSystemData export.' } }, { status: 413, body: '<html>nginx</html>' }];
  t = makeComponent();
  await t.component.pick(['bad.xml', 'big.xml', 'good.xml'].map(file));
  assert.deepStrictEqual(t.component.uploads.map((u) => u.error), ['bad.xml: not a DsSystemData export.', 'big.xml: the file is larger than the server allows.']);
  assert.strictEqual(Object.keys(t.environments).length, 1, 'the good file still went in');
  console.log('4 server errors do not block the next file: OK');
  console.log('ALL OK');
})().catch((e) => { console.error('FAIL:', e.message); process.exit(1); });
