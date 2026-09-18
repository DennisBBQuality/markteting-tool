const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const { File, Blob } = require('node:buffer');
const { FormData } = globalThis;

function harness() {
  const fields = new Map();
  const field = id => {
    if (!fields.has(id)) fields.set(id, {
      value: '', innerHTML: '', textContent: '', disabled: false, isConnected: true,
      classList: { add() {}, remove() {}, toggle() {} }, focus() { this.focused = true; },
    });
    return fields.get(id);
  };
  const messages = [];
  let urls = 0;
  const context = vm.createContext({
    document: { getElementById: field, querySelectorAll: () => [] }, navigator: { clipboard: { read: async () => [] } },
    sessionStorage: { removeItem() {} },
    File, Date, URLSearchParams, URL: { createObjectURL: () => `blob:${++urls}`, revokeObjectURL() {} },
    toast: text => messages.push(text), escHtml: String, formatFileSize: String,
    ImageModelPicker: { selection: () => ({ image_model: 'test-model' }) },
  });
  vm.runInContext(fs.readFileSync('public/js/converter.js', 'utf8'), context);
  field('product-image-name').value = 'Testproduct';
  field('product-image-quantity').value = '1';
  return { context, field, messages, run: code => vm.runInContext(code, context) };
}
const file = (name = 'test.png', type = 'image/png', size = 8) => new File([new Uint8Array(size)], name, { type });
const item = (type = 'image/png', size = 8) => ({ types: [type], getType: async () => new Blob([new Uint8Array(size)], { type }) });
const pasteEvent = (files, editable = false) => ({ clipboardData: { files }, target: { closest: () => editable }, preventDefault() { this.prevented = true; } });

test('rate-limited generation preserves input and results, explains wait and never retries a paid POST', async () => {
  for (const retryAfter of ['42', null, 'invalid']) {
    const h = harness();
    h.context.uploads = [file()];
    h.run('handleProductImageFiles(uploads); productImageState.results = [{asset_id: 1}]');
    const calls = [];
    Object.assign(h.context, { FormData, getCookie: () => 'test-csrf', fetch: async (url, options) => {
      calls.push([url, options]);
      return {status: 429, headers: {get: () => retryAfter}, json: () => { throw Error('Could be a non-JSON 429'); }};
    }});
    await h.run('startProductImageGeneration()');
    assert.equal(calls.length, 1);
    assert.equal(calls[0][1].method, 'POST');
    assert.equal(calls[0][1].headers.Accept, 'application/json');
    assert.equal(h.run('productImageState.files.length'), 1);
    assert.equal(h.run('productImageState.results[0].asset_id'), 1);
    assert.equal(h.run('productImageState.requestId'), null);
    assert.equal(h.run('productImageState.generating'), false);
    assert.equal(h.field('product-image-generate-btn').disabled, false);
    assert.equal(h.field('product-image-name').value, 'Testproduct');
    assert.match(h.field('product-image-status').innerHTML, /geen nieuwe foto-opdracht gestart/);
    assert.match(h.messages[0], retryAfter === '42' ? /42 seconden/ : /Wacht even/);
  }
});

test('recovery links only accept a photoset UUID and do not start generation', () => {
  const h = harness();
  const id = '11111111-2222-7333-8444-555555555555';
  assert.equal(h.run(`productImageRecoveryId('?image_request=${id}')`), id);
  for (const value of ['', '?image_request=../private', '?image_request=https://example.test', '?image_request=bad']) {
    h.context.search = value;
    assert.equal(h.run('productImageRecoveryId(search)'), null);
  }
  assert.equal(h.run('productImageState.generating'), false);
});

test('WEBP requires a descriptive SEO name and still enforces sauce label review', () => {
  const h = harness();
  const opened = [];
  h.context.openImageSeoEditor = id => opened.push(id);
  h.context.event = {preventDefault() { this.prevented = true; }};
  for (const filename of ['', 'saus-op-tafel-2.webp']) {
    h.context.filename = filename;
    h.run('productImageState.results = [{asset_id: 1, metadata: {filename}}]');
    assert.equal(h.run('prepareProductImageDownload(event, 1)'), false);
    assert.equal(h.context.event.prevented, true);
  }
  assert.deepEqual(opened, [1, 1]);
  h.run('productImageState.results = [{asset_id: 1, seo: {ready: true}, metadata: {filename: "saus-op-houten-tafel.webp"}}]');
  assert.equal(h.run('prepareProductImageDownload(event, 1)'), true);
  h.run('productImageState.results[0].needs_label_review = true');
  assert.equal(h.run('prepareProductImageDownload(event, 1)'), false);
  h.field('product-label-approved-1').checked = true;
  assert.equal(h.run('prepareProductImageDownload(event, 1)'), true);
});

test('paste button adds a local image preview and enables generation without changing product data', async () => {
  const h = harness();
  h.context.navigator.clipboard.read = async () => [item()];
  await h.run('pasteProductImageFromClipboard()');
  assert.equal(h.run('productImageState.files.length'), 1);
  assert.equal(h.run('productImageState.files[0].type'), 'image/png');
  assert.match(h.run('productImageState.files[0].name'), /^geplakte-afbeelding-.*\.png$/);
  assert.match(h.field('product-image-selection').innerHTML, /blob:1/);
  assert.equal(h.field('product-image-generate-btn').disabled, false);
  assert.equal(h.field('product-image-name').value, 'Testproduct');
  assert.equal(h.run('productImageState.mainIndex'), 0);
  assert.match(h.field('product-image-paste-status').textContent, /Afbeelding toegevoegd/);
});

test('one image per clipboard item; text and HTML are never requested', async () => {
  const h = harness(); const requested = [];
  h.context.navigator.clipboard.read = async () => [{ types: ['text/plain', 'text/html', 'image/jpeg', 'image/png'], getType: async type => {
    requested.push(type); return new Blob(['pixels'], { type });
  } }, { types: ['text/html'], getType: () => { throw Error('must not read'); } }];
  await h.run('pasteProductImageFromClipboard()');
  assert.deepEqual(requested, ['image/png']);
  assert.equal(h.run('productImageState.files.length'), 1);
});

test('upload and paste share limits, preserve the main photo and never clear existing results on rejection', async () => {
  const h = harness();
  h.context.uploads = [file('a.jpg', 'image/jpeg'), file('b.webp', 'image/webp'), file('c.png'), file('d.png')];
  h.run('handleProductImageFiles(uploads); setProductImageMain(1)');
  h.context.navigator.clipboard.read = async () => [item(), item()];
  await h.run('pasteProductImageFromClipboard()');
  assert.equal(h.run('productImageState.files.length'), 5);
  assert.equal(h.run('productImageState.mainIndex'), 1);
  assert.match(h.field('product-image-paste-status').textContent, /maximaal vijf/);
  h.context.navigator.clipboard.read = () => { throw Error('must not access full selection'); };
  h.run('productImageState.results = ["keep-result"]');
  await h.run('pasteProductImageFromClipboard()');
  assert.equal(h.run('productImageState.results[0]'), 'keep-result');
});

test('too large or empty images show persistent validation feedback', async () => {
  for (const size of [0, 10 * 1024 * 1024 + 1]) {
    const h = harness(); h.context.navigator.clipboard.read = async () => [item('image/png', size)];
    await h.run('pasteProductImageFromClipboard()');
    assert.equal(h.run('productImageState.files.length'), 0);
    assert.match(h.field('product-image-paste-status').textContent, /is leeg|groter dan 10 MB/);
    assert.equal(h.field('product-image-paste-btn').disabled, false);
  }
});

test('missing images and unsupported clipboard formats explain how to copy the image itself', async () => {
  for (const items of [[], [item('text/plain')], [item('image/svg+xml')]]) {
    const h = harness(); h.context.navigator.clipboard.read = async () => items;
    await h.run('pasteProductImageFromClipboard()');
    assert.equal(h.run('productImageState.files.length'), 0);
    assert.match(h.field('product-image-paste-status').textContent, /Afbeelding kopiëren/);
  }
});

test('permission denial and unsupported browsers offer a focused keyboard-paste fallback', async () => {
  for (const clipboard of [undefined, { read: async () => { throw Error('denied'); } }]) {
    const h = harness(); h.context.navigator.clipboard = clipboard;
    await h.run('pasteProductImageFromClipboard()');
    assert.match(h.field('product-image-paste-status').textContent, /Cmd\+V.*Ctrl\+V/);
    assert.equal(h.field('product-image-dropzone').focused, true);
    assert.equal(h.field('product-image-paste-btn').disabled, false);
  }
});

test('busy clipboard read cannot run twice or start generation; a stale response cannot affect a new view', async () => {
  const h = harness(); let finish; let reads = 0;
  h.context.navigator.clipboard.read = () => { reads++; return new Promise(resolve => { finish = resolve; }); };
  const pending = h.run('pasteProductImageFromClipboard()');
  await h.run('pasteProductImageFromClipboard()');
  assert.equal(reads, 1);
  assert.equal(h.field('product-image-generate-btn').disabled, true);
  h.run('productImageState = {files: [], previewUrls: [], generating: false}');
  finish([item()]); await pending;
  assert.equal(h.run('productImageState.files.length'), 0);
});

test('a stale getType response is ignored and read failures leave previous files intact', async () => {
  const h = harness(); let finish;
  h.context.navigator.clipboard.read = async () => [{ types: ['image/png'], getType: () => new Promise(resolve => { finish = resolve; }) }];
  const pending = h.run('pasteProductImageFromClipboard()');
  await new Promise(resolve => setImmediate(resolve));
  h.field('product-image-paste-btn').isConnected = false;
  finish(new Blob(['pixels'], { type: 'image/png' })); await pending;
  assert.equal(h.run('productImageState.files.length'), 0);
  const h2 = harness(); h2.context.uploads = [file()]; h2.run('handleProductImageFiles(uploads)');
  h2.context.navigator.clipboard.read = async () => [{ types: ['image/png'], getType: async () => { throw Error('failed'); } }];
  await h2.run('pasteProductImageFromClipboard()');
  assert.equal(h2.run('productImageState.files.length'), 1);
  assert.equal(h2.field('product-image-generate-btn').disabled, false);
});

test('keyboard paste adds images, not text or links, and leaves editable fields alone', () => {
  const h = harness();
  h.context.event = pasteEvent([file()]); h.run('handleProductImagePaste(event)');
  assert.equal(h.context.event.prevented, true);
  assert.equal(h.run('productImageState.files.length'), 1);
  for (const event of [pasteEvent([]), pasteEvent([file()], true)]) {
    h.context.event = event; h.run('handleProductImagePaste(event)');
    assert.equal(event.prevented, undefined);
    assert.equal(h.run('productImageState.files.length'), 1);
  }
});

test('uploads and keyboard paste cannot alter a running photoset; unsupported images are rejected', async () => {
  const h = harness(); h.run('productImageState.generating = true');
  h.context.event = pasteEvent([file()]); h.run('handleProductImagePaste(event)');
  h.context.navigator.clipboard.read = () => { throw Error('must not access while generating'); };
  await h.run('pasteProductImageFromClipboard()');
  assert.equal(h.run('productImageState.files.length'), 0);
  assert.match(h.field('product-image-paste-status').textContent, /Wacht/);
  h.run('productImageState.generating = false');
  h.context.event = pasteEvent([file('bad.svg', 'image/svg+xml')]); h.run('handleProductImagePaste(event)');
  assert.equal(h.run('productImageState.files.length'), 0);
  assert.match(h.field('product-image-paste-status').textContent, /geen JPG/);
});

test('removing a pasted photo releases its preview and upload duplicate handling is preserved', () => {
  const h = harness(); let released;
  h.context.URL.revokeObjectURL = url => { released = url; };
  h.context.uploads = [file(), file()]; h.run('handleProductImageFiles(uploads)');
  assert.equal(h.run('productImageState.files.length'), 1);
  h.run('removeProductImageFile(0)');
  assert.equal(released, 'blob:1');
  assert.equal(h.run('productImageState.files.length'), 0);
  assert.equal(h.field('product-image-generate-btn').disabled, true);
});

test('fish selection keeps references and name, shows five photos and remains selected after completion', () => {
  const h = harness(); h.context.uploads = [file()];
  h.run("handleProductImageFiles(uploads); setProductImageType('fish')");
  assert.equal(h.run('productImageState.productType'), 'fish');
  assert.equal(h.run('productImageState.files.length'), 1);
  assert.equal(h.field('product-image-name').value, 'Testproduct');
  assert.match(h.field('product-image-generate-btn').innerHTML, /Maak 5 productfoto/);
  assert.equal(h.field('product-image-generate-btn').disabled, false);
  h.run('finishProductImageRequest()');
  assert.match(h.field('product-image-generate-btn').innerHTML, /Maak 5 productfoto/);
});

test('polling accepts legacy four and new five-photo sets; sauce and bundle remain two', async () => {
  for (const [type, count, valid, expected] of [['fish', 1, true, 1], ['meat', 7, true, 7], ['fish', 4, true], ['fish', 5, true, 5], ['fish', 4, false, 5], ['fish', 2, false], ['meat', 5, true, 5], ['meat', 4, true], ['sauce', 2, true], ['bundle', 2, true]]) {
    const h = harness();
    h.context.fetch = async () => ({ ok: true, status: 200, json: async () => ({ status: 'completed', expected_count: expected, context: { product_type: type }, results: Array.from({ length: count }, () => ({seo: {ready: true}, metadata: {filename: 'test.webp', alt: 'Testfoto', title: 'Test', caption: 'Foto.', description: 'Testfoto op tafel.'}})) }) });
    h.context.renderProductImageResults = () => {};
    h.context.showProductImageError = error => { h.context.error = error; };
    h.run('productImageState.requestId = "test"; productImageState.pollFailures = 3');
    // Error counter resets after a valid server response, so capture any scheduled retry.
    h.context.setTimeout = () => { h.context.retry = true; return 1; };
    await h.run('pollProductImageRequest("test")');
    assert.equal(h.run('productImageState.results.length'), count);
    assert.equal(Boolean(h.context.retry), !valid);
  }
});

test('variant selection counts 0 to 7, survives type changes and cannot change during generation', () => {
  const h = harness(); h.context.uploads = [file()]; h.run('handleProductImageFiles(uploads)');
  assert.equal(h.run('selectedProductImageCount()'), 5);
  h.run("setProductImageVariant('oven', true); setProductImageVariant('airfryer', true)");
  assert.equal(h.run('selectedProductImageCount()'), 7);
  h.run("setProductImageVariant('oven', true); setProductImageVariant('bogus', true)");
  assert.equal(h.run('selectedProductImageCount()'), 7);
  h.run("setProductImageType('sauce')"); assert.equal(h.run('selectedProductImageCount()'), 2);
  h.run("setProductImageType('fish')"); assert.equal(h.run('selectedProductImageCount()'), 7);
  h.run("productImageState.generating = true; updateProductImageForm(); setProductImageVariant('pan', false)");
  assert.equal(h.field('product-image-variants').disabled, true);
  assert.equal(h.run('selectedProductImageCount()'), 7);
  h.run("productImageState.generating = false; PRODUCT_IMAGE_VARIANTS.forEach(v => setProductImageVariant(v.id, false))");
  assert.equal(h.run('selectedProductImageCount()'), 0);
  assert.equal(h.field('product-image-generate-btn').disabled, true);
  assert.match(h.field('product-image-variant-summary').textContent, /minimaal één/);
  h.run("setProductImageVariant('oven', true)");
  assert.match(h.field('product-image-generate-btn').innerHTML, /Maak 1 productfoto$/);
  assert.equal(h.field('product-image-generate-btn').disabled, false);
});

test('photos without complete SEO never announce success; failed SEO preserves recovery', async () => {
  for (const status of ['processing_seo', 'seo_failed', 'completed']) {
    const h = harness();
    h.context.renderProductImageResults = () => {};
    h.context.setTimeout = () => { h.context.retry = true; return 1; };
    h.context.sessionStorage.removeItem = () => { h.context.removed = true; };
    h.context.fetch = async () => ({ok: true, status: 200, json: async () => ({status, progress: 90, progress_step: 'processing_seo', results: [{asset_id: 1, seo: {ready: false, status: status === 'processing_seo' ? 'queued' : 'failed'}, metadata: {filename: ''}}]})});
    h.run('productImageState.requestId = "test"; productImageState.generating = true');
    await h.run('pollProductImageRequest("test")');
    assert.equal(h.messages.length, 0);
    assert.equal(h.context.removed, undefined);
    assert.equal(h.run('productImageState.results.length'), 1);
    assert.equal(Boolean(h.context.retry), status === 'processing_seo');
    if (status !== 'processing_seo') assert.match(h.field('product-image-status').innerHTML, /SEO is nog niet compleet/);
  }
});

test('ready flag alone cannot bypass incomplete fields', () => {
  const h = harness();
  h.run('productImageState.results = [{seo: {ready: true}, metadata: {filename: "test.webp", alt: "Foto", title: "Test", caption: "Foto.", description: " "}}]');
  assert.equal(h.run('productImagesSeoReady()'), false);
});

test('request submits only selected groups; a failed start unlocks controls and preserves choices', async () => {
  const h = harness(); h.context.uploads = [file()]; h.context.FormData = FormData;
  h.context.getCookie = () => 'test-token';
  h.run("handleProductImageFiles(uploads); productImageState.variantGroups = ['oven', 'airfryer']; updateProductImageForm()");
  let submitted;
  h.context.fetch = async (url, options) => { submitted = options.body; return { ok: false, status: 422, json: async () => ({ message: 'Voorbeeldfout' }) }; };
  await h.run('startProductImageGeneration()');
  assert.deepEqual(submitted.getAll('variant_groups[]'), ['oven', 'airfryer']);
  assert.equal(h.run('productImageState.generating'), false);
  assert.equal(h.field('product-image-variants').disabled, false);
  assert.equal(h.run('selectedProductImageCount()'), 2);
  assert.match(h.field('product-image-status').innerHTML, /Voorbeeldfout/);
  assert.equal(h.run('productImageState.files.length'), 1);
  h.run("setProductImageType('sauce')");
  await h.run('startProductImageGeneration()');
  assert.deepEqual(submitted.getAll('variant_groups[]'), []);
});
