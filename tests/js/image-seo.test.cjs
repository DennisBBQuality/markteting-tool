const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

function harness() {
  const fields = new Map();
  const get = id => { if (!fields.has(id)) fields.set(id, { value: '', textContent: '', disabled: false }); return fields.get(id); };
  const calls = [], timers = [];
  const metadata = { filename: 'test.webp', alt: 'Testfoto', title: 'Test', caption: 'Serveersuggestie.', description: 'Testbeschrijving.' };
  const context = { document: { getElementById: get }, escHtml: x => String(x), openModal: (title, html) => { context.modal = html; }, closeModal: () => {},
    productImageState: { completedRequestId: 'request', results: [{asset_id: 1, version: 1, metadata, seo: {revision: 0}}] },
    setTimeout: f => timers.push(f), confirm: () => true, navigator: {clipboard: {writeText: async text => { context.copied = text; }}},
    api: async (url, options = {}) => { calls.push([url, options]); return context.response; },
    response: {asset_id: 1, version: 1, metadata, seo: {revision: 0, source: 'none', status: 'idle'}} };
  vm.createContext(context);
  vm.runInContext(fs.readFileSync('public/js/image-seo.js', 'utf8'), context);
  return { context, calls, timers, get, run: code => vm.runInContext(code, context) };
}

test('editor saves five fields with image version and optimistic revision; copies readable text', async () => {
  const h = harness(); await h.run('openImageSeoEditor(1)');
  h.get('image-seo-filename').value = 'eigen-foto.webp';
  h.run('imageSeoEditor.dirty = true');
  h.context.response = {...h.context.response, metadata: {...h.context.response.metadata, filename: 'eigen-foto.webp'}, seo: {revision: 1, source: 'manual', status: 'completed'}};
  await h.run('saveImageSeo()');
  assert.equal(h.calls[1][1].body.image_version, 1);
  assert.equal(h.calls[1][1].body.revision, 0);
  assert.equal(h.calls[1][1].body.fields.filename, 'eigen-foto.webp');
  assert.equal(Object.keys(h.calls[1][1].body.fields).length, 5);
  assert.equal(h.run('imageSeoEditor.dirty'), false);
  await h.run('copyImageSeo("all")');
  assert.match(h.context.copied, /Bestandsnaam: eigen-foto.webp/);
  assert.match(h.context.copied, /Bijschrift:/);
});

test('late polling never overwrites dirty fields and stale image versions disable saving', async () => {
  const h = harness(); await h.run('openImageSeoEditor(1)');
  h.get('image-seo-alt').value = 'Mijn correctie'; h.run('imageSeoEditor.dirty = true');
  h.context.response = {...h.context.response, metadata: {...h.context.response.metadata, alt: 'Nieuwe AI-tekst'}, seo: {revision: 1, source: 'ai', status: 'completed'}};
  await h.run('refreshImageSeo(imageSeoEditor)');
  assert.equal(h.get('image-seo-alt').value, 'Mijn correctie');
  assert.equal(h.run('imageSeoEditor.revision'), 0);
  assert.match(h.get('image-seo-status').textContent, /nieuwe SEO/);
  h.context.response.version = 2;
  await h.run('refreshImageSeo(imageSeoEditor)');
  assert.equal(h.get('image-seo-save').disabled, true);
  assert.equal(h.get('image-seo-alt').value, 'Mijn correctie');
});

test('manual regeneration requires confirmation and sends explicit replacement', async () => {
  const h = harness(); h.context.response.seo = {revision: 2, source: 'manual', status: 'completed'};
  await h.run('openImageSeoEditor(1)');
  h.context.confirm = () => false;
  await h.run('generateImageSeo()'); assert.equal(h.calls.length, 1);
  h.context.confirm = () => true;
  h.context.response.seo.status = 'queued';
  await h.run('generateImageSeo()');
  assert.equal(h.calls[1][1].body.replace_manual, true);
  assert.equal(h.calls[1][1].body.revision, 2);
  assert.equal(h.get('image-seo-generate').disabled, true);
  assert.equal(h.timers.length, 1);
});

test('save error is persistent, keeps input and dirty state; closing prevents late response changes', async () => {
  const h = harness(); await h.run('openImageSeoEditor(1)');
  h.get('image-seo-alt').value = 'Niet verliezen'; h.run('imageSeoEditor.dirty = true');
  h.context.api = async (url, options) => { options.onError('Verbinding verbroken'); return null; };
  await h.run('saveImageSeo()');
  assert.equal(h.get('image-seo-alt').value, 'Niet verliezen');
  assert.equal(h.run('imageSeoEditor.dirty'), true);
  assert.match(h.get('image-seo-status').textContent, /Verbinding verbroken/);
  h.context.api = () => new Promise(resolve => { h.context.resolve = resolve; });
  const pending = h.run('refreshImageSeo(imageSeoEditor)');
  h.run('closeImageSeo()');
  h.context.resolve(h.context.response); await pending;
  assert.equal(h.get('image-seo-alt').value, 'Niet verliezen');
});

test('product-focused editor explains optional caption and saves an empty string without filler', async () => {
  const h = harness();
  h.context.response = {...h.context.response, metadata: {...h.context.response.metadata, caption: ''},
    seo: {revision: 1, ready: true, source: 'ai', status: 'completed', optional_fields: ['caption']}};
  h.context.productImageState.results[0].seo.optional_fields = ['caption'];
  await h.run('openImageSeoEditor(1)');
  assert.match(h.context.modal, /Bijschrift \(optioneel\)/);
  assert.match(h.context.modal, /geen decor of sfeer/);
  assert.doesNotMatch(h.context.modal, /gekozen bereidingswijze in alle vijf/);
  assert.equal(h.get('image-seo-caption').value, '');
  assert.match(h.get('image-seo-status').textContent, /opgeslagen/);
  assert.doesNotMatch(h.get('image-seo-status').textContent, /alle vijf/i);
  await h.run('saveImageSeo()');
  assert.equal(h.calls[1][1].body.fields.caption, '');
  await h.run('copyImageSeo("all")');
  assert.match(h.context.copied, /Bijschrift: \n/);
});

test('fresh server policy updates guidance without replacing dirty caption', async () => {
  const h = harness(); await h.run('openImageSeoEditor(1)');
  assert.match(h.context.modal, /gekozen bereidingswijze in alle vijf/);
  h.get('image-seo-caption').value = 'Mijn eigen bijschrift';
  h.run('imageSeoEditor.dirty = true');
  h.context.response.seo.optional_fields = ['caption'];
  await h.run('refreshImageSeo(imageSeoEditor)');
  assert.equal(h.get('image-seo-label-caption').textContent, 'Bijschrift (optioneel)');
  assert.match(h.get('image-seo-guidance').textContent, /Productgerichte SEO/);
  assert.equal(h.get('image-seo-caption').value, 'Mijn eigen bijschrift');
});
