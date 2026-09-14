const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
function harness() {
  const elements = new Map();
  const context = {Intl, Date, URLSearchParams, clearInterval,
    App: {currentUser: {id:'me', naam:'TEST User'}, currentView:'dashboard'},
    document: {getElementById(id) { if (!elements.has(id)) elements.set(id, {innerHTML:'', textContent:''}); return elements.get(id); }},
    api: async () => [],
  };
  vm.createContext(context);
  vm.runInContext(fs.readFileSync('public/js/dashboard.js', 'utf8') + '\nthis.Dashboard = Dashboard;', context);
  return {c:context, elements};
}
test('personal tasks exclude others, unassigned and completed; shared assignments count', () => {
  const {c} = harness();
  const tasks = [
    {id:'mine', titel:'Mine', deadline:'2026-09-15', toegewezenen:[{id:'me'},{id:'other'}]},
    {id:'earlier', titel:'Earlier', deadline:'2026-09-14', toegewezenen:[{id:'me'}]},
    {id:'other', titel:'Other', toegewezenen:[{id:'other'}]},
    {id:'none', titel:'None'},
    {id:'done', titel:'Done', status:'klaar', toegewezenen:[{id:'me'}]},
  ];
  assert.deepEqual(Array.from(c.dashboardPersonalTasks(tasks, 'me'), t => t.id), ['earlier','mine']);
  assert.equal(c.dashboardPersonalTasks(tasks, null).length, 0);
});
test('switching person ignores slower previous response and keeps selected-person link', async () => {
  const {c,elements}=harness(); const requests=[];
  c.api=url=>new Promise(resolve=>requests.push({url,resolve}));
  const first=c.Dashboard.loadTasks('me'), second=c.Dashboard.loadTasks('other');
  assert.equal(requests[1].url,'/api/tasks?toegewezen_aan=other');
  requests[1].resolve([{id:'other-task',titel:'Colleague task',status:'todo',toegewezenen:[{id:'other'}]}]);
  await second;
  requests[0].resolve([{id:'old',titel:'Stale task',status:'todo',toegewezenen:[{id:'me'}]}]);
  await first;
  assert.match(elements.get('dashboard-my-tasks').innerHTML,/Colleague task/);
  assert.doesNotMatch(elements.get('dashboard-my-tasks').innerHTML,/Stale task/);
  assert.equal(elements.get('dashboard-task-count').textContent,'1 open');
  c.navigateTo=()=>{}; c.Dashboard.myTasks(); assert.equal(c.App.taskUserFilter,'other');
});
test('task tile renders more than five tasks in its scrollable list', () => {
  const {c}=harness();
  const html=c.dashboardTasksHtml(Array.from({length:8},(_,i)=>({id:String(i),titel:'Task '+i,status:'todo'})));
  assert.equal((html.match(/class="dashboard-row dashboard-task"/g)||[]).length,8);
});
test('notes use creator identity and latest edit; projects never show progress bars', () => {
  const {c} = harness();
  const notes = [{id:'other',aangemaakt_door:'other'}, {id:'mine',aangemaakt_door:'me'}, {id:'new',aangemaakt_door:'me',updated_at:'2026-09-14'}];
  assert.deepEqual(Array.from(c.dashboardPersonalNotes(notes, 'me'), n => n.id), ['new','mine']);
  const html = c.dashboardProjectsHtml([{id:'test',naam:'Test <script>',aantal_taken:5,taken_klaar:3}]);
  assert.doesNotMatch(html, /progress|60%|<script>/);
  assert.match(html, /&lt;script&gt;/);
});
test('dashboard requests session-scoped lists and handles partial failure without empty success', async () => {
  const {c,elements} = harness(); const urls=[];
  c.api = async url => { urls.push(url); return url.includes('notes') ? null : []; };
  await c.renderDashboard();
  assert.doesNotMatch(elements.get('view-dashboard').innerHTML, /Kalender openen/);
  assert.ok(urls.includes('/api/tasks?mine=1') && urls.includes('/api/notes?mine=1'));
  assert.match(elements.get('dashboard-my-tasks').innerHTML, /Geen open taken/);
  assert.match(elements.get('dashboard-my-notes').innerHTML, /role="alert"/);
});
test('late response after user change cannot fill another users dashboard', async () => {
  const {c,elements} = harness(); const resolves=[];
  c.api = () => new Promise(resolve => resolves.push(resolve));
  const pending=c.renderDashboard();
  c.App.currentUser={id:'other'};
  resolves.forEach(resolve => resolve([{id:'secret',titel:'PRIVATE',toegewezenen:[{id:'me'}],aangemaakt_door:'me'}]));
  await pending;
  assert.doesNotMatch(elements.get('dashboard-my-tasks')?.innerHTML || '', /PRIVATE/);
});
test('my-list links pass personal filters and dispose destroys old calendar', () => {
  const {c} = harness(); const views=[]; let destroyed=false;
  c.navigateTo = view => views.push(view);
  c.Dashboard.myTasks(); c.Dashboard.myNotes();
  assert.equal(c.App.taskUserFilter, 'me'); assert.equal(c.App.notesMineFilter, true);
  assert.deepEqual(views, ['tasks','notes']);
  c.Dashboard.calendar={destroy(){destroyed=true;}}; c.Dashboard.dispose();
  assert.ok(destroyed); assert.equal(c.Dashboard.calendar, null);
});
test('calendar uses local date bounds and does not allow accidental dragging', async () => {
  const {c} = harness(); let options; let url;
  c.FullCalendar={Calendar: class {constructor(el,o){options=o;} render(){}}};
  c.api=async path => {url=path;return [];};
  c.mountDashboardCalendar(c.Dashboard.version,'me');
  assert.equal(options.editable,false); assert.equal(options.firstDay,1); assert.equal(options.initialView,'timeGridWeek');
  await options.events({start:new Date(2026,8,14),end:new Date(2026,8,21)},()=>{},()=>{});
  assert.match(url,/start=2026-09-14&end=2026-09-21&overlap=1/);
});
