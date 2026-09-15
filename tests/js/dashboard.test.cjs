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
  c.FullCalendar={Calendar: class {constructor(el,o){options=o;} render(){} setOption(k,v){options[k]=v;}}};
  c.api=async path => {url=path;return [];};
  c.mountDashboardCalendar(c.Dashboard.version,'me');
  assert.equal(options.editable,false); assert.equal(options.firstDay,1); assert.equal(options.initialView,'timeGridWeek');
  await options.events({start:new Date(2026,8,14),end:new Date(2026,8,21)},()=>{},()=>{});
  assert.match(url,/start=2026-09-14&end=2026-09-21&overlap=1/);
  assert.equal(options.allDaySlot,true);
  assert.equal(options.dayMaxEvents,2);
  assert.equal(options.eventMaxStack,2);
  assert.equal(options.slotEventOverlap,false);
  assert.equal(options.moreLinkText(3),'+3 meer');
});

test('compact projects retain all projects, sort by deadline and show actual open counts without descriptions', () => {
  const {c}=harness();
  const projects=Array.from({length:7},(_,i)=>({id:String(i),naam:'Project '+i,beschrijving:'HIDDEN DESCRIPTION',deadline:i?'2026-09-18':null,aantal_taken:'8',taken_klaar:'3',medewerkers:[{naam:'<Sam>',kleur:'bad; color:red'}]}));
  const before=JSON.stringify(projects); const html=c.dashboardProjectsHtml(projects);
  assert.equal((html.match(/class="dashboard-project-open"/g)||[]).length,7);
  assert.doesNotMatch(html,/HIDDEN DESCRIPTION|progress|color:red/);
  assert.match(html,/Open taken/); assert.match(html,/dashboard-project-count">5/);
  assert.match(html,/&lt;Sam&gt;/);
  assert.ok(html.indexOf('Project 1')<html.indexOf('Project 0'));
  assert.equal(JSON.stringify(projects),before);
});

test('only explicit absence names are separated, not multi-day campaigns or Friday meetings', () => {
  const {c}=harness();
  for (const titel of ['Colin Vakantie','Britt vrij','Sam vrije dag','Verlof: Noor','Noor afwezig']) assert.equal(c.dashboardIsAbsence({titel}),true,titel);
  for (const titel of ['Vrijdag teamoverleg','Vakantiecampagne','Vakantie bespreken','Campagne kerst','Planning vrije dagen bespreken']) assert.equal(c.dashboardIsAbsence({titel}),false,titel);
  const item={id:'test',titel:'Campagne',datum_start:'2026-09-14T08:00:00',datum_eind:'2026-09-20T18:00:00'};
  assert.equal(c.dashboardCalendarEvent(item).allDay,false);
});

test('absence spans preserve final day, exclusive midnight and original record without writes', () => {
  const {c}=harness();
  const item={id:'test',titel:'Sam vakantie',datum_start:'2026-09-14T08:00:00',datum_eind:'2026-09-20T18:00:00'};
  const before=JSON.stringify(item); const event=c.dashboardCalendarEvent(item);
  assert.equal(event.allDay,true); assert.equal(event.start,'2026-09-14'); assert.equal(event.end,'2026-09-21');
  assert.equal(event.extendedProps.item,item); assert.equal(JSON.stringify(item),before);
  assert.equal(c.dashboardCalendarEvent({...item,datum_eind:'2026-09-21T00:00:00'}).end,'2026-09-21');
  assert.equal(c.dashboardCalendarEvent({...item,datum_eind:null}).end,'2026-09-15');
  const half=c.dashboardCalendarEvent({...item,titel:'Sam vrij',datum_eind:'2026-09-14T12:00:00'});
  assert.match(half.title,/08:00–12:00/); assert.equal(half.end,'2026-09-15');
  assert.equal(c.dashboardCalendarEvent({...item,datum_start:'invalid'}).allDay,false);
  assert.equal(c.dashboardCalendarEvent({...item,datum_eind:'2026-09-13T00:00:00'}).allDay,false);
});

test('absence date arithmetic remains correct across daylight-saving and year boundaries', () => {
  const {c}=harness();
  for (const [start,end,expected] of [['2026-10-24T08:00:00','2026-10-25T18:00:00','2026-10-26'],['2026-03-28T08:00:00','2026-03-29T18:00:00','2026-03-30'],['2026-12-31T08:00:00','2027-01-01T18:00:00','2027-01-02']]) {
    assert.equal(c.dashboardCalendarEvent({titel:'Sam vakantie',datum_start:start,datum_eind:end}).end,expected);
  }
});

test('working-day view expands for early, late and overnight appointments without hiding them', () => {
  const {c}=harness();
  assert.equal(c.dashboardCalendarHours([]).min,'08:00:00');
  assert.equal(c.dashboardCalendarHours([]).max,'18:00:00');
  const hours=c.dashboardCalendarHours([{start:'2026-09-14T06:30:00',end:'2026-09-14T19:30:00'}]);
  assert.equal(hours.min,'06:00:00'); assert.equal(hours.max,'20:00:00');
  assert.equal(c.dashboardCalendarHours([{start:'2026-09-14T23:00:00'}]).max,'24:00:00');
  assert.equal(c.dashboardCalendarHours([{start:'2026-09-14T23:00:00',end:'2026-09-15T01:00:00'}]).min,'00:00:00');
  assert.equal(c.dashboardCalendarHours([{allDay:true,start:'2026-09-14',end:'2026-09-21'}]).max,'18:00:00');
});
