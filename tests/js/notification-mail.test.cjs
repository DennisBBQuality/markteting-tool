const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function harness() {
  const elements = new Map([['notification-mail-setup', {}], ['notification-mail-action-status', {}]]);
  const calls = [];
  const context = {
    App: {currentUser: {id: 'TEST-admin', rol: 'admin'}},
    PitboardNotifications: {session: 1, current(s) {return s === this.session;}},
    document: {getElementById: id => elements.get(id)},
    escHtml: v => String(v ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;'),
    api: async (url, options) => {calls.push({url, options}); return {ready: true, fields: {}, revision: 'TEST-revision', enabled: false};},
    openModal(...args) {calls.push({modal: args});}, toast(...args) {calls.push({toast: args});},
    closeModal() {}, confirm: () => true,
  };
  vm.createContext(context);
  vm.runInContext(fs.readFileSync('public/js/notification-mail.js', 'utf8') + '\nthis.ui = PitboardNotificationMail;', context);
  return {context, ui: context.ui, elements, calls};
}

test('only admin can open sender settings and stale responses after logout are ignored', async () => {
  const h = harness();
  h.context.App.currentUser.rol = 'lid';
  await h.ui.open();
  assert.equal(h.calls.length, 0);
  h.context.App.currentUser.rol = 'admin';
  let resolve;
  h.context.api = () => new Promise(r => resolve = r);
  const pending = h.ui.open();
  h.context.PitboardNotifications.session++;
  h.ui.reset();
  resolve({ready: true, fields: {sender_address: 'PRIVATE'}});
  await pending;
  assert.equal(h.ui.data, null);
  assert.equal(h.calls.length, 0);
});

test('sender form escapes data, distinguishes acceptance and never enables an unverified sender', () => {
  const h = harness();
  h.ui.data = {ready: true, fields: {sender_address: '\"><img src=x>'}, enabled: false, test_state: 'accepted', test_recipient: '<script>bad</script>', test_id: 'TEST-id'};
  h.ui.draw();
  const html = h.calls.at(-1).modal[1];
  assert.doesNotMatch(html, /<img|<script>/);
  assert.match(html, /geen ontvangst/);
  assert.doesNotMatch(html, /E-mailmeldingen activeren/);
  assert.match(html, /Trunkrs-app ongewijzigd/);
  assert.match(html, /disabled/);
});

test('connection and activation require explicit checkboxes', async () => {
  const h = harness();
  await h.ui.open();
  const before = h.calls.filter(x => x.url).length;
  await h.ui.start();
  await h.ui.enable();
  assert.equal(h.calls.filter(x => x.url).length, before);
  h.elements.set('notification-mail-consent', {checked: true});
  await h.ui.start();
  const request = h.calls.find(x => x.url?.endsWith('/start'));
  assert.equal(request.options.body.consent, true);
  assert.equal(request.options.body.revision, 'TEST-revision');
});

test('test send cannot double-submit and refreshes state after uncertain response', async () => {
  const h = harness();
  await h.ui.open();
  let resolve;
  h.context.api = (url, options) => {h.calls.push({url, options}); return new Promise(r => resolve = r);};
  const first = h.ui.test();
  await h.ui.test();
  assert.equal(h.calls.filter(x => x.url?.endsWith('/test')).length, 1);
  h.context.api = async (url) => {h.calls.push({url}); return {ready: true, fields: {}, revision: 'NEW', test_state: 'uncertain'};};
  resolve(null);
  await first;
  assert.equal(h.ui.data.revision, 'NEW');
  assert.match(h.calls.at(-1).modal[1], /vorige proefverzending is niet bevestigd/);
});

test('closing modal during an action cannot reopen it', async () => {
  const h = harness();
  await h.ui.open();
  let resolve;
  h.context.api = () => new Promise(r => resolve = r);
  const request = h.ui.stop();
  h.elements.delete('notification-mail-setup');
  resolve({ok: true});
  await request;
  assert.equal(h.calls.filter(x => x.modal).length, 1);
});

test('unsaved fields block connecting and failed save preserves the form', async () => {
  const h = harness();
  await h.ui.open();
  h.elements.set('notification-mail-consent', {checked: true});
  for (const key of ['sender_address', 'tenant_id', 'client_id', 'delegate_user_id']) h.elements.set('notification-mail-' + key, {value: 'CHANGED'});
  await h.ui.start();
  assert.equal(h.calls.filter(x => x.url?.endsWith('/start')).length, 0);
  h.context.api = async () => null;
  await h.ui.save();
  assert.equal(h.elements.get('notification-mail-sender_address').value, 'CHANGED');
  assert.match(h.elements.get('notification-mail-action-status').textContent, /invoer is behouden/);
  assert.equal(h.calls.filter(x => x.modal).length, 1);
});
