const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');

function harness() {
  const source = fs.readFileSync(path.join(__dirname, '../../public/js/app.js'), 'utf8');
  const navigation = source.slice(source.indexOf('// ========== Navigation'), source.indexOf('// ========== Modal'));
  const views = ['dashboard', 'projects', 'tasks', 'calendar', 'notes', 'converter', 'product-dossiers', 'settings'];
  const element = () => {
    const classes = new Set();
    return {classList: {add: c => classes.add(c), remove: c => classes.delete(c), contains: c => classes.has(c)}};
  };
  const elements = new Map(views.map(view => [`view-${view}`, element()]));
  const nav = new Map(views.map(view => [view, element()]));
  const rendered = [];
  const context = {
    App: {currentView: 'dashboard', currentUser: {id: 'TEST-admin', rol: 'admin'}},
    document: {
      getElementById: id => elements.get(id) || null,
      querySelectorAll: selector => [...(selector === '.view' ? elements : nav).values()],
      querySelector: selector => nav.get(selector.match(/data-view="([^"]+)"/)[1]),
    },
  };
  for (const view of views) {
    const name = view.split('-').map(part => part[0].toUpperCase() + part.slice(1)).join('');
    context[`render${name}`] = () => rendered.push(view);
  }
  vm.createContext(context);
  vm.runInContext(navigation, context);
  return {context, elements, nav, rendered, navigate: context.navigateTo};
}

test('removed customer-service view safely returns to dashboard without its script', () => {
  const h = harness();
  h.navigate('customer-service');
  assert.equal(h.context.App.currentView, 'dashboard');
  assert.deepEqual(h.rendered, ['dashboard']);
  assert.equal(h.elements.get('view-dashboard').classList.contains('hidden'), false);
  assert.equal(h.nav.get('dashboard').classList.contains('active'), true);
});

test('all remaining sections still render and activate their own navigation', () => {
  const h = harness();
  for (const view of h.nav.keys()) {
    h.navigate(view);
    assert.equal(h.context.App.currentView, view);
    assert.equal(h.rendered.at(-1), view);
    assert.equal(h.elements.get(`view-${view}`).classList.contains('hidden'), false);
    assert.equal(h.nav.get(view).classList.contains('active'), true);
    assert.equal([...h.elements.values()].filter(el => !el.classList.contains('hidden')).length, 1);
  }
});

test('members and managers cannot navigate directly to admin settings', () => {
  for (const rol of ['lid', 'manager']) {
    const h = harness();
    h.context.App.currentUser.rol = rol;
    h.navigate('settings');
    assert.equal(h.context.App.currentView, 'dashboard');
    assert.deepEqual(h.rendered, ['dashboard']);
  }
});

test('fallback navigation preserves productstudio unsaved-upload and busy protections', () => {
  const h = harness();
  h.navigate('product-dossiers');
  Object.assign(h.context, {
    productDossierState: {pendingLabels: ['unsaved-label']},
    dossierForegroundBusy: () => false,
    confirm: () => false,
  });
  h.navigate('customer-service');
  assert.equal(h.context.App.currentView, 'product-dossiers');
  assert.equal(h.elements.get('view-product-dossiers').classList.contains('hidden'), false);

  let warned = false;
  h.context.dossierForegroundBusy = () => true;
  h.context.toast = () => { warned = true; };
  h.navigate('customer-service');
  assert.equal(warned, true);
  assert.equal(h.context.App.currentView, 'product-dossiers');
});
