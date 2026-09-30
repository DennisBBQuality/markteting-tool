const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/js/app.js', 'utf8');
const script = source.slice(source.indexOf('// ========== Attachments'), source.indexOf('// ========== Select helpers'));

function harness(attachments = []) {
  const list = {innerHTML: ''};
  const elements = [];
  const element = () => ({dataset: {}, hidden: false, events: {}, setAttribute() {},
    addEventListener(name, fn) { this.events[name] = fn; },
    remove() { this.removed = true; }, focus() { this.focused = true; },
    showModal() { this.open = true; }, close() { this.open = false; this.events.close(); },
    click() { this.clicked = true; }, querySelector(selector) { return this.children[selector]; },
    children: Object.fromEntries(['strong', 'img', '[role="status"]', '.attachment-preview-header button', '.attachment-preview-footer button'].map(key => [key, {}])),
  });
  const context = {document: {getElementById: () => list, createElement: () => { const el = element(); elements.push(el); return el; }, body: {appendChild() {}}},
    api: async () => attachments, escHtml: text => text.replaceAll('&', '&amp;').replaceAll('<', '&lt;'),
    fetch: async () => ({ok: true, blob: async () => 'TEST'}), URL: {createObjectURL: () => 'blob:TEST', revokeObjectURL() {}}, setTimeout() {},
    toast: text => { context.error = text; },
  };
  vm.createContext(context); vm.runInContext(script, context);
  return {context, list, elements};
}

test('all attachment lists use extensionless routes and image buttons with escaped names', async () => {
  for (const type of ['project_id', 'task_id', 'calendar_item_id', 'note_id']) {
    const h = harness([{id: 'TEST', mimetype: 'image/jpeg', originele_naam: '<TEST "foto">.jpg'}]);
    await h.context.loadAttachments(type, 'TEST');
    assert.match(h.list.innerHTML, /\/api\/attachments\/TEST\/preview/);
    assert.match(h.list.innerHTML, /\/api\/attachments\/TEST\/download/);
    assert.match(h.list.innerHTML, /openAttachmentPreview\(this\)/);
    assert.match(h.list.innerHTML, /&lt;TEST &quot;foto&quot;/);
    assert.doesNotMatch(h.list.innerHTML, /\/uploads\//);
  }
});

test('documents do not get an image preview', async () => {
  const h = harness([{id: 'TEST', mimetype: 'application/pdf', originele_naam: 'TEST.pdf'}]);
  await h.context.loadAttachments('project_id', 'TEST');
  assert.doesNotMatch(h.list.innerHTML, /openAttachmentPreview|<img/);
});

test('preview is separate, loads an image and restores focus without replacing the edit form', () => {
  const h = harness(); h.list.innerHTML = 'UNSAVED FORM';
  const trigger = {dataset: {name: 'TEST.jpg', previewUrl: '/preview', downloadUrl: '/download'}, focus() { this.focused = true; }};
  h.context.openAttachmentPreview(trigger);
  const dialog = h.elements[0]; const photo = dialog.children.img;
  assert.equal(dialog.open, true); assert.equal(photo.src, '/preview');
  photo.onload(); assert.equal(photo.hidden, false);
  assert.equal(dialog.children['[role="status"]'].hidden, true);
  let stopped = false; dialog.events.keydown({key: 'Escape', stopPropagation() { stopped = true; }});
  assert.equal(stopped, true);
  dialog.close(); assert.equal(trigger.focused, true); assert.equal(dialog.removed, true);
  assert.equal(h.list.innerHTML, 'UNSAVED FORM');
});

test('preview gives an understandable error for missing files', () => {
  const h = harness(); h.context.openAttachmentPreview({dataset: {name: 'TEST.jpg'}, focus() {}});
  const dialog = h.elements[0]; dialog.children.img.onerror();
  assert.equal(dialog.children.img.hidden, true);
  assert.match(dialog.children['[role="status"]'].textContent, /ontbreekt mogelijk/);
});

test('download preserves the original filename and reports failed requests', async () => {
  const h = harness(); await h.context.downloadAttachment('/download', 'TEST foto.jpg');
  assert.equal(h.elements[0].download, 'TEST foto.jpg'); assert.equal(h.elements[0].clicked, true);
  h.context.fetch = async () => ({ok: false, status: 404});
  await h.context.downloadAttachment('/download', 'TEST foto.jpg');
  assert.match(h.context.error, /ontbreekt mogelijk/);
  h.context.fetch = async () => ({ok: false, status: 401});
  await h.context.downloadAttachment('/download', 'TEST foto.jpg');
  assert.match(h.context.error, /Log opnieuw in/);
});
