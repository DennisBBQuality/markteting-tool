const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const { File, Blob } = require('node:buffer');

function harness() {
  const fields = new Map();
  const field = id => {
    if (!fields.has(id)) fields.set(id, {
      value: '', innerHTML: '', textContent: '', disabled: false, isConnected: true,
      classList: { add() {} }, focus() { this.focused = true; },
    });
    return fields.get(id);
  };
  const messages = [];
  let urls = 0;
  const context = vm.createContext({
    document: { getElementById: field }, navigator: { clipboard: { read: async () => [] } },
    File, Date, URL: { createObjectURL: () => `blob:${++urls}`, revokeObjectURL() {} },
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
