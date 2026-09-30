const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/js/converter.js', 'utf8');
const script = source.slice(source.indexOf('async function fetchAndDownloadWebp'), source.indexOf('function formatFileSize'));
function harness() {
  const links = [], notices = [], copied = [], requests = [];
  class TestURL extends URL { static createObjectURL() { return 'blob:TEST'; } static revokeObjectURL() {} }
  const context = {
    URL: TestURL, Blob, Uint8Array, window: {location: {origin: 'https://example.test'}},
    Image: class { async decode() {} }, setTimeout(fn, ms) { if (ms < 60000) fn(); },
    document: {body: {appendChild() {}}, createElement() { const link = {click() { this.clicked = true; }, remove() {}}; links.push(link); return link; }},
    converterState: {results: [{origineel: 'TEST.jpg', geconverteerd: 'TEST.webp', download_url: '/api/convert/download?bestand=stored.webp'}], seoData: {}},
    navigator: {clipboard: {async writeText(text) { copied.push(text); }}}, toast: (message, type) => notices.push({message, type}),
    fetch: async url => { requests.push(url); return {ok: true, blob: async () => new Blob(['RIFF1234WEBPcontent'], {type: 'image/webp'})}; },
  };
  vm.createContext(context); vm.runInContext(script, context);
  return {context, links, notices, copied, requests};
}
test('verified download uses extensionless URL and preserves SEO filename', async () => {
  const h = harness(); h.context.converterState.seoData[0] = {bestandsnaam: 'TEST keuze.webp'};
  await h.context.downloadWebpFile(0);
  const url = new URL(h.requests[0]);
  assert.equal(url.pathname, '/api/convert/download'); assert.equal(url.searchParams.get('bestand'), 'stored.webp');
  assert.equal(url.searchParams.get('naam'), 'TEST keuze.webp'); assert.equal(h.links[0].download, 'TEST keuze.webp');
  assert.equal(h.links[0].clicked, true); assert.equal(h.copied.length, 1);
});
test('legacy conversion result is routed around static-extension interception', async () => {
  const h = harness(); h.context.converterState.results[0].download_url = '/api/convert/download/TEST%20foto-123.webp';
  await h.context.downloadWebpFile(0);
  const url = new URL(h.requests[0]); assert.equal(url.pathname, '/api/convert/download'); assert.equal(url.searchParams.get('bestand'), 'TEST foto-123.webp');
});
test('404, authentication, server and network failures never create a file or copy metadata', async () => {
  for (const status of [404, 401, 403, 500, 'network']) {
    const h = harness(); h.context.converterState.seoData[0] = {bestandsnaam: 'TEST.webp'};
    h.context.fetch = async () => { if (status === 'network') throw new Error('network'); return {ok: false, status}; };
    await h.context.downloadWebpFile(0);
    assert.equal(h.links.length, 0); assert.equal(h.copied.length, 0); assert.equal(h.notices.at(-1).type, 'error');
  }
});
test('HTML, mislabeled responses and truncated images cannot be downloaded as WEBP', async () => {
  for (const [type, content, broken] of [['text/html', '<html>login</html>', false], ['image/webp', '<html>error</html>', false], ['image/webp', 'RIFF1234WEBPbroken', true]]) {
    const h = harness(); h.context.fetch = async () => ({ok: true, blob: async () => new Blob([content], {type})});
    if (broken) h.context.Image = class { async decode() { throw new Error('broken'); } };
    await h.context.downloadWebpFile(0); assert.equal(h.links.length, 0); assert.equal(h.notices.at(-1).type, 'error');
  }
});
test('batch reports partial failure and copies metadata only for successful downloads', async () => {
  const h = harness(); const state = h.context.converterState;
  state.results.push({...state.results[0], origineel: 'TEST2.jpg'});
  state.seoData = {0: {bestandsnaam: 'SUCCESS.webp'}, 1: {bestandsnaam: 'FAILED.webp'}};
  const goodFetch = h.context.fetch; let calls = 0;
  h.context.fetch = async url => ++calls === 1 ? goodFetch(url) : {ok: false, status: 404};
  await h.context.downloadAllWebp(); assert.equal(h.links.length, 1);
  assert.match(h.copied[0], /SUCCESS/); assert.doesNotMatch(h.copied[0], /FAILED/);
  assert.match(h.notices.at(-1).message, /1 download\(s\) gestart; 1 mislukt/);
});
test('external download links are rejected before fetching', async () => {
  const h = harness(); h.context.converterState.results[0].download_url = 'https://other.test/file';
  await h.context.downloadWebpFile(0); assert.equal(h.requests.length, 0); assert.equal(h.links.length, 0);
});
