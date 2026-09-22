const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('public/js/trunkrs-settings.js', 'utf8');

function setup() {
  const fields = {tenant_id: 'tenant', client_id: 'client', reader_user_id: 'reader', mailbox: 'owner@example.test', folder_id: 'report-folder'};
  const elements = {};
  const node = id => elements[id] ||= {value: fields[id.replace('trunkrs-', '')] || '', checked: false, textContent: '', innerHTML: '', querySelectorAll: () => [], addEventListener() {}};
  const calls = [];
  const state = {ready: true, fields, revision: 'revision', status: {configured: false, enabled: false, warnings: []}};
  const context = vm.createContext({App: {currentUser: {id: 'test-admin'}}, document: {getElementById: node}, escHtml: v => String(v).replaceAll('<','&lt;').replaceAll('"','&quot;'),
    api: async (url, options) => { calls.push([url, options]); return state; }, confirm: () => true});
  vm.runInContext(source + '\nthis.settings = TrunkrsSettings;', context);
  return {settings: context.settings, elements, node, calls, state, context};
}

test('setup renders explicit access warning and honest scheduler status', async () => {
  const t = setup(); await t.settings.load();
  assert.match(t.node('trunkrs-settings').innerHTML, /hele eigen mailbox/);
  assert.match(t.node('trunkrs-settings').innerHTML, /serverplanning nog niet bevestigd/);
  assert.doesNotMatch(source, /localStorage|sessionStorage|refresh_token|access_token|setInterval/);
});
test('connecting requires consent and unchanged saved fields', async () => {
  const t = setup(); await t.settings.load(); await t.settings.action('connect');
  assert.equal(t.calls.length, 1);
  t.node('trunkrs-consent').checked = true;
  t.node('trunkrs-mailbox').value = 'changed@example.test';
  await t.settings.action('connect');
  assert.equal(t.calls.length, 1);
  assert.match(t.node('trunkrs-feedback').textContent, /Sla de gewijzigde/);
});
test('provider links cannot redirect the user and codes are escaped', async () => {
  const t = setup(); await t.settings.load();
  t.settings.pending({user_code: '<script>', verification_uri: 'https://evil.example.test', expires_at: 1790000000, retry_after: 5});
  assert.doesNotMatch(t.node('trunkrs-login').innerHTML, /evil.example|<script>/);
  assert.match(t.node('trunkrs-login').innerHTML, /https:\/\/microsoft.com\/devicelogin/);
});
test('a failed save leaves inputs and a persistent error in place', async () => {
  const t = setup(); await t.settings.load();
  t.context.api = async (url, options) => { options.onError('Niet opgeslagen'); return null; };
  t.node('trunkrs-mailbox').value = 'new@example.test';
  await t.settings.action('save');
  assert.equal(t.node('trunkrs-mailbox').value, 'new@example.test');
  assert.equal(t.node('trunkrs-feedback').textContent, 'Niet opgeslagen');
  assert.equal(t.settings.busy, false);
});
test('missing storage offers a fixed confirmed initialization action', async () => {
  const t = setup(); t.state.ready = false; t.state.message = 'Opslag ontbreekt';
  await t.settings.load();
  assert.match(t.node('trunkrs-settings').innerHTML, /Trunkrs-opslag voorbereiden/);
  t.context.confirm = () => false;
  await t.settings.action('initialize');
  assert.equal(t.calls.length, 1);
  t.context.confirm = () => true;
  await t.settings.action('initialize');
  assert.equal(t.calls[1][0], '/api/settings/trunkrs/initialize');
  assert.equal(JSON.stringify(t.calls[1][1].body), '{"confirm":true}');
  assert.equal(t.settings.busy, false);
});
