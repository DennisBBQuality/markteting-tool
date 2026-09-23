const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const context = { Intl, Date, escHtml: value => String(value || '').replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;') };
vm.createContext(context);
vm.runInContext(fs.readFileSync(path.join(__dirname, '../../public/js/trunkrs.js'), 'utf8') + '\nthis.Trunkrs = Trunkrs;', context);
const ui = context.Trunkrs;

test('one collection contains cancellations, without blaming the customer', () => {
  const html = ui.rowsHtml([{trunkrs_number:'TEST-1', barcode:'TEST-BAR', status:'EXCEPTION_SHIPMENT_CANCELLED_BY_SENDER', reason_code:''}]);
  assert.match(html, /Geannuleerd door afzender/);
  assert.doesNotMatch(html, /EXCEPTION_SHIPMENT_CANCELLED_BY_SENDER/);
  assert.doesNotMatch(html, /Geannuleerd door klant/);
});
test('unconfigured tile never shows a zero count', () => {
  const html = ui.tileHtml({report:null, warnings:['Nog niet gekoppeld']});
  assert.match(html, /Niet bezorgd Trunkrs/);
  assert.match(html, /Nog geen rapport/);
  assert.doesNotMatch(html, /<strong>0<\/strong>/);
});
test('dashboard shows a compact waiting tile only without a configured connection or report', () => {
  const html = ui.tileHtml({configured:false, report:null, warnings:['Toestemming ontbreekt.']});
  assert.match(html, /trunkrs-compact/);
  assert.match(html, /Wacht op koppeling/);
  assert.match(html, /Toestemming ontbreekt/);
  const existing = ui.tileHtml({configured:false, warnings:[], report:{id:'test', report_date:'2026-09-09', shipment_count:1}, shipments:[]});
  assert.doesNotMatch(existing, /trunkrs-compact/);
});
test('unknown statuses stay literal and attachment content cannot inject html', () => {
  const html = ui.rowsHtml([{trunkrs_number:'<script>bad()</script>', barcode:'TEST', status:'UNKNOWN', reason_code:'<img onerror="bad()">'}]);
  assert.match(html, /UNKNOWN/);
  assert.doesNotMatch(html, /<script>|<img/);
});
test('dates are displayed in Amsterdam time, independent of laptop timezone', () => {
  assert.match(ui.date('2026-09-10T04:02:03Z', true), /06:02/);
  assert.equal(ui.date(null), 'Nog niet bekend');
});
test('stale warning is visible alongside last good report', () => {
  const html = ui.tileHtml({warnings:['Een nieuwer rapport ontbreekt.'], report:{id:'test', report_date:'2026-09-09', shipment_count:1}, shipments:[{trunkrs_number:'TEST-OLD',barcode:'TEST',status:'UNKNOWN'}]});
  assert.match(html, /Een nieuwer rapport ontbreekt/);
  assert.match(html, /TEST-OLD/);
  assert.doesNotMatch(html, /Mail ontvangen|Laatste geslaagde mapcontrole|servercontrole/);
});

test('a seven-day selector shows available delivery days and omits technical timestamps', () => {
  const report = {id:'new', report_date:'2026-09-22', shipment_count:1, received_at:'2026-09-23T04:00:00Z'};
  const html = ui.tileHtml({report, available_reports:[report, {id:'old', report_date:'2026-09-21', shipment_count:2}], shipments:[], warnings:[]});
  assert.match(html, /Bezorgdag Trunkrs-rapport/);
  assert.match(html, /value="new" selected/);
  assert.match(html, /value="old"/);
  assert.doesNotMatch(html, /Mail ontvangen|Ingelezen|mapcontrole|2026-09-23T04:00:00Z/);
});

test('opening the dashboard resets the selected day to the newest imported report', async () => {
  ui.selectedReportId = 'old';
  context.clearInterval = () => {};
  context.setInterval = () => 1;
  context.document = {getElementById:() => ({isConnected:true, innerHTML:'', getClientRects:() => [1]})};
  const request = ui.request;
  let requestedPath;
  ui.request = async path => { requestedPath = path; return {configured:true, report:null, warnings:[]}; };
  await ui.mount();
  assert.equal(ui.selectedReportId, null);
  assert.equal(requestedPath, '/api/trunkrs/summary');
  ui.request = request;
});

test('network failures preserve the last visible rows and add a persistent notice', async () => {
  const tile = {isConnected:true, innerHTML:'TEST-EXISTING-ROWS', querySelector:() => null, prepend: node => { tile.notice = node; }};
  context.document = {getElementById:() => tile, createElement:() => ({})};
  const request = ui.request;
  ui.request = async () => { throw new Error('offline'); };
  await ui.refresh();
  assert.equal(tile.innerHTML, 'TEST-EXISTING-ROWS');
  assert.match(tile.notice.textContent, /niet opnieuw gecontroleerd/);
  ui.request = request;
});

test('expired authentication clears protected rows instead of leaving them displayed', async () => {
  const tile = {isConnected:true, innerHTML:'TEST-PRIVATE-ROW'};
  context.document = {getElementById:() => tile};
  context.clearInterval = () => {};
  const request = ui.request;
  ui.request = async () => { const e = new Error('expired'); e.status = 401; throw e; };
  await ui.refresh();
  assert.doesNotMatch(tile.innerHTML, /TEST-PRIVATE-ROW/);
  assert.match(tile.innerHTML, /Log opnieuw in/);
  ui.request = request;
});

test('closing a report dialog during loading prevents a late response reopening it', () => {
  ui.modalVersion = 1;
  context.document = {getElementById:id => id === 'modal-overlay' ? {classList:{contains:() => true}} : {}};
  assert.equal(ui.modalPending(1), false);
  assert.equal(ui.modalPending(2), false);
});
