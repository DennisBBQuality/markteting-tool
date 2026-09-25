const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function harness() {
  const elements = new Map();
  const element = id => {
    if (!elements.has(id)) elements.set(id, {innerHTML: '', textContent: '', hidden: false, focus() {}, setAttribute(name, value) {this[name] = value;}, removeAttribute(name) {delete this[name];}, querySelectorAll: () => [], querySelector: () => null});
    return elements.get(id);
  };
  const calls = [];
  const context = {
    App: {currentUser: {id: 'TEST-user'}, currentView: 'dashboard'},
    document: {hidden: false, getElementById: element, addEventListener() {}},
    clearInterval() {}, setInterval() {return 1;}, URL,
    location: {href: 'https://pitboard.example.test/?melding=TEST-owned'},
    history: {replaceState(...args) {calls.push(['history', ...args]);}},
    escHtml: value => String(value ?? '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;'),
    api: async url => {calls.push(url); return {items: [], unread_count: 0, has_more: false};},
    openModal(...args) { calls.push(['modal', ...args]); }, closeModal() {}, toast() {},
    navigateTo(view) {context.App.currentView = view;},
    openEditTaskModal: async () => true, openProjectDetail: async () => true,
  };
  vm.createContext(context);
  vm.runInContext(fs.readFileSync('public/js/notifications.js', 'utf8') + '\nthis.ui = PitboardNotifications;', context);
  context.ui.owner = 'TEST-user';
  context.ui.popupOpen = true;
  return {context, ui: context.ui, elements, element, calls};
}

test('empty and unread views stay usable and preferences are available to ordinary members', async () => {
  const h = harness();
  await h.ui.render();
  assert.match(h.element('notification-popup').innerHTML, /Je bent bij/);
  assert.match(h.element('notification-popup').innerHTML, /Ongeopend/);
  h.context.api = async () => ({task_email: true, project_email: false, email_active: false});
  await h.ui.preferences();
  assert.match(h.calls.at(-1)[2], /nog niet geactiveerd/);
});

test('notification data is escaped and unread badge is accessible', () => {
  const h = harness();
  h.ui.items = [{id: 'TEST', kind: 'task', title: '<img src=x onerror=bad()>', actor_name: '<script>bad()</script>', created_at: '2026-09-24T09:00:00Z'}];
  h.ui.paint();
  assert.doesNotMatch(h.element('notification-popup').innerHTML, /<img|<script>/);
  assert.match(h.element('notification-popup').innerHTML, /&lt;img/);
  h.ui.badge(120);
  assert.equal(h.element('notification-count').textContent, '120');
  assert.match(h.element('notification-count')['aria-label'], /120 ongeopende/);
});

test('late inbox and badge responses cannot leak after logout or account switching', async () => {
  const h = harness();
  let resolve;
  h.context.api = () => new Promise(r => {resolve = r;});
  const render = h.ui.render();
  h.ui.stop(); h.context.App.currentUser = {id: 'OTHER'};
  resolve({items: [{title: 'PRIVATE'}], unread_count: 8});
  await render;
  assert.equal(h.element('notification-popup').innerHTML, '');
  assert.equal(h.element('notification-count').textContent, '0');
});

test('late request does not overwrite a newer filter response', async () => {
  const h = harness();
  let resolve;
  h.context.api = () => new Promise(r => {resolve = r;});
  const first = h.ui.render();
  h.context.api = async () => ({items: [], unread_count: 0});
  await h.ui.render();
  resolve({items: [{title: 'OLD'}], unread_count: 1});
  await first;
  assert.doesNotMatch(h.element('notification-popup').innerHTML, /OLD/);
});

test('owned email link resolves via server and is marked read only after opening', async () => {
  const h = harness();
  h.context.api = async url => { h.calls.push(url); return {ok: true, kind: 'task', target_id: 'TEST-task', unread_count: 0}; };
  assert.equal(await h.ui.openFromLink(), true);
  assert.equal(h.context.App.currentView, 'tasks');
  assert.equal(h.calls[0], '/api/notifications/TEST-owned');
  assert.ok(h.calls.includes('/api/notifications/TEST-owned/read'));
  assert.ok(h.calls.some(x => Array.isArray(x) && x[0] === 'history'));
});

test('blocked navigation and removed targets never mark notifications as read', async () => {
  const h = harness();
  h.context.api = async url => {h.calls.push(url); return {kind: 'task', target_id: 'TEST-task'};};
  h.context.navigateTo = () => {};
  assert.equal(await h.ui.open('TEST-owned'), false);
  assert.equal(h.calls.length, 1);
  h.context.navigateTo = view => {h.context.App.currentView = view;};
  h.context.openEditTaskModal = async () => false;
  assert.equal(await h.ui.open('TEST-owned'), false);
  assert.equal(h.calls.length, 2);
});

test('failed refresh is an error state, not a reassuring empty inbox', async () => {
  const h = harness();
  h.context.api = async () => null;
  await h.ui.render();
  assert.match(h.element('notification-popup').innerHTML, /niet worden bijgewerkt/);
  assert.doesNotMatch(h.element('notification-popup').innerHTML, /Je bent bij/);
});

test('missing storage offers initialization only to admins and requires confirmation', async () => {
  const h = harness();
  h.context.api = async () => ({ready: false, can_initialize: false});
  await h.ui.render();
  assert.match(h.element('notification-popup').innerHTML, /Vraag een beheerder/);
  assert.doesNotMatch(h.element('notification-popup').innerHTML, /onclick="PitboardNotifications.initialize/);
  h.context.api = async () => ({ready: false, can_initialize: true});
  await h.ui.render();
  assert.match(h.element('notification-popup').innerHTML, /Meldingenopslag voorbereiden/);
  h.context.confirm = () => false;
  h.context.api = async url => {h.calls.push(url); return null;};
  await h.ui.initialize();
  assert.equal(h.calls.length, 0);
});

test('dashboard polls the selected filter quietly and does not reset expanded pages', async () => {
  const h = harness();
  h.ui.unreadOnly = true;
  await h.ui.refreshBadge();
  assert.equal(h.calls[0], '/api/notifications?page=1&unread=1');
  h.ui.page = 2;
  await h.ui.refreshBadge();
  assert.equal(h.calls.at(-1), '/api/notifications');
  assert.equal(h.ui.page, 2);
});

test('leaving and returning to dashboard invalidates the earlier inbox response', async () => {
  const h = harness();
  let resolve;
  h.context.api = () => new Promise(r => resolve = r);
  const pending = h.ui.render();
  h.ui.unmount();
  h.ui.popupOpen = true;
  h.context.api = async () => ({items: [], unread_count: 0});
  await h.ui.render();
  resolve({items: [{id: 'TEST-old', title: 'STALE'}], unread_count: 1});
  await pending;
  assert.doesNotMatch(h.element('notification-popup').innerHTML, /STALE/);
});

test('header button opens unread popup without marking anything read', async () => {
  const h = harness();
  h.ui.popupOpen = false;
  await h.ui.showPopup();
  assert.equal(h.ui.popupOpen, true);
  assert.ok(h.calls.some(c => Array.isArray(c) && c[0] === 'modal' && c[1] === 'Meldingen'));
  assert.ok(h.calls.includes('/api/notifications?page=1&unread=1'));
  assert.ok(!h.calls.some(c => typeof c === 'string' && c.endsWith('/read')));
  assert.doesNotMatch(h.element('notification-popup').innerHTML, /readAll|data-read-notification/);
  const dashboard = fs.readFileSync('public/js/dashboard.js', 'utf8');
  assert.match(dashboard, /id="dashboard-notification-button"/);
  assert.doesNotMatch(dashboard, /data-widget="notifications"/);
});

test('closing popup rejects delayed list and target responses', async () => {
  const h = harness();
  let resolve;
  h.context.api = () => new Promise(r => resolve = r);
  const pending = h.ui.render();
  h.ui.unmount();
  resolve({items: [{title: 'LATE'}], unread_count: 3});
  await pending;
  assert.doesNotMatch(h.element('notification-popup').innerHTML, /LATE/);
  h.ui.popupOpen = true;
  const opening = h.ui.open('TEST-task');
  h.ui.unmount();
  resolve({kind: 'task', target_id: 'TEST-task'});
  assert.equal(await opening, false);
  assert.equal(h.context.App.currentView, 'dashboard');
});

test('closed popup polls only count and zero remains a visible number', async () => {
  const h = harness();
  h.ui.popupOpen = false;
  await h.ui.refreshBadge();
  assert.equal(h.calls[0], '/api/notifications');
  assert.equal(h.element('notification-count').textContent, '0');
  assert.equal(h.element('notification-count').hidden, false);
});

test('an old badge poll cannot resurrect a notification after reading it', async () => {
  const h = harness();
  h.ui.popupOpen = false;
  let resolveOld;
  h.context.api = () => new Promise(resolve => resolveOld = resolve);
  const oldPoll = h.ui.refreshBadge();
  h.context.api = async () => ({ok: true, unread_count: 0});
  await h.ui.readTarget('task', 'TEST-task');
  resolveOld({unread_count: 1});
  await oldPoll;
  assert.equal(h.ui.unreadCount, 0);
});

test('badge polls finishing out of order keep the newest count', async () => {
  const h = harness();
  h.ui.popupOpen = false;
  let resolveOld;
  h.context.api = () => new Promise(resolve => resolveOld = resolve);
  const oldPoll = h.ui.refreshBadge();
  h.context.api = async () => ({unread_count: 0});
  await h.ui.refreshBadge();
  resolveOld({unread_count: 1});
  await oldPoll;
  assert.equal(h.ui.unreadCount, 0);
});

test('a removed target still has an explicit read action and account switches cannot write', async () => {
  const h = harness();
  h.ui.items = [{id: 'TEST-deleted', kind: 'task', title: 'TEST removed', created_at: '2026-09-25T10:00:00Z'}];
  h.ui.paint();
  assert.match(h.element('notification-popup').innerHTML, /data-read-notification="TEST-deleted"/);
  h.context.App.currentUser = {id: 'OTHER'};
  assert.equal(await h.ui.readTarget('task', 'TEST-task'), false);
  assert.equal(h.calls.length, 0);
});

test('failed email-link resolution preserves the link for a later login', async () => {
  const h = harness();
  h.context.api = async () => null;
  assert.equal(await h.ui.openFromLink(), false);
  assert.ok(!h.calls.some(call => Array.isArray(call) && call[0] === 'history'));
});
