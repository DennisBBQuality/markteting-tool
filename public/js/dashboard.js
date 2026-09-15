// ========== Personal week dashboard ==========
const Dashboard = {
  calendar: null,
  version: 0,
  notes: [],
  projects: [],
  date: null,
  taskPerson: null,
  taskRequest: 0,
  dispose() {
    this.version++;
    this.taskRequest++;
    if (typeof DashboardLayout !== 'undefined') DashboardLayout.dispose();
    if (this.calendar) this.calendar.destroy();
    this.calendar = null;
    this.notes = [];
    this.projects = [];
    if (typeof Trunkrs !== 'undefined') {
      clearInterval(Trunkrs.timer);
      Trunkrs.mountVersion++;
    }
  },
  isCurrent(version, userId) {
    return this.version === version && App.currentUser?.id === userId && App.currentView === 'dashboard';
  },
  changeWeek(action) {
    this.calendar?.[action]();
  },
  myTasks() {
    App.taskUserFilter = this.taskPerson || App.currentUser?.id || '';
    navigateTo('tasks');
  },
  async loadTasks(person) {
    const userId=App.currentUser?.id, version=this.version, request=++this.taskRequest;
    this.taskPerson=person || userId;
    const element=document.getElementById('dashboard-my-tasks');
    element.innerHTML=dashboardEmpty('Taken laden…');
    const count=document.getElementById('dashboard-task-count');
    if (count) count.textContent='Laden…';
    const url=this.taskPerson===userId?'/api/tasks?mine=1':'/api/tasks?toegewezen_aan='+encodeURIComponent(this.taskPerson);
    const tasks=await api(url,{silentError:true});
    if (!this.isCurrent(version,userId) || request!==this.taskRequest) return;
    if (!Array.isArray(tasks)) { element.innerHTML=dashboardError('Dashboard.loadTasks(Dashboard.taskPerson)'); if (count) count.textContent='Niet geladen'; return; }
    const personal=dashboardPersonalTasks(tasks,this.taskPerson);
    element.innerHTML=personal.length?dashboardTasksHtml(personal):dashboardEmpty('Geen open taken voor deze persoon.');
    if (count) count.textContent=personal.length+' open';
  },
  myNotes() {
    App.notesMineFilter = true;
    navigateTo('notes');
  },
  openNote(id) {
    const note = this.notes.find(n => n.id === id && n.aangemaakt_door === App.currentUser?.id);
    if (note) openNoteModal(note);
  },
  openProject(id) {
    const project = this.projects.find(p => p.id === id);
    if (project) {
      navigateTo('projects');
      openProjectModal(project);
    }
  },
};

function dashboardDate(value, options = {day: 'numeric', month: 'short'}) {
  if (!value) return '';
  const date = new Date(value.length === 10 ? value + 'T12:00:00' : value);
  return Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat('nl-NL', options).format(date);
}

function dashboardLocalDate(date) {
  return [date.getFullYear(), String(date.getMonth() + 1).padStart(2, '0'), String(date.getDate()).padStart(2, '0')].join('-');
}

function dashboardPersonalTasks(tasks, userId) {
  if (!userId) return [];
  return tasks.filter(t => t.status !== 'klaar' && (t.toegewezenen || []).some(u => u.id === userId))
    .sort((a, b) => (a.deadline || '9999').localeCompare(b.deadline || '9999') || a.titel.localeCompare(b.titel));
}

function dashboardPersonalNotes(notes, userId) {
  return userId ? notes.filter(n => n.aangemaakt_door === userId)
    .sort((a, b) => (b.updated_at || b.created_at || '').localeCompare(a.updated_at || a.created_at || '')) : [];
}

function dashboardEmpty(text) {
  return `<p class="dashboard-empty">${text}</p>`;
}

function dashboardError(retry) {
  return `<div class="dashboard-error" role="alert">Dit onderdeel kon niet worden geladen.
    <button class="btn btn-sm btn-outline" onclick="${retry}">Opnieuw proberen</button></div>`;
}

function dashboardTasksHtml(tasks) {
  const status = {todo: 'Te doen', bezig: 'Bezig', review: 'Review'};
  const today = dashboardLocalDate(new Date());
  return tasks.length ? tasks.map(t => `
    <button class="dashboard-row dashboard-task" onclick="openEditTaskModal('${escHtml(t.id)}')">
      <i class="far fa-circle dashboard-task-icon" aria-hidden="true"></i>
      <span class="dashboard-row-text"><strong>${escHtml(t.titel)}</strong></span>
      <span class="dashboard-status dashboard-status-${status[t.status] ? t.status : 'todo'}">${status[t.status] || 'Te doen'}</span>
      <span class="dashboard-date ${t.deadline && t.deadline.slice(0, 10) < today ? 'is-overdue' : ''}">${dashboardDate(t.deadline) || 'Geen datum'}</span>
    </button>`).join('') : dashboardEmpty('Er staan geen open taken op jouw naam.');
}

function dashboardProjectsHtml(projects) {
  if (!projects.length) return dashboardEmpty('Er zijn nog geen actieve projecten.');
  const rows = [...projects].sort((a,b) => (a.deadline || '9999').localeCompare(b.deadline || '9999') || a.naam.localeCompare(b.naam, 'nl'));
  return `<table class="dashboard-project-table"><thead><tr><th>Project</th><th>Deadline</th><th>Team</th><th>Open taken</th></tr></thead><tbody>${rows.map(p => {
    const team = Array.isArray(p.medewerkers) ? p.medewerkers : [];
    const count = p.aantal_taken != null && p.taken_klaar != null && Number.isFinite(Number(p.aantal_taken)) && Number.isFinite(Number(p.taken_klaar))
      ? Math.max(0, Number(p.aantal_taken) - Number(p.taken_klaar)) : '–';
    return `<tr>
      <td><button class="dashboard-project-open" onclick="Dashboard.openProject('${escHtml(p.id)}')" title="${escHtml(p.naam)}">
        <i class="fas fa-folder dashboard-accent" aria-hidden="true"></i><strong>${escHtml(p.naam)}</strong>
        <i class="fas fa-chevron-right dashboard-chevron" aria-hidden="true"></i></button></td>
      <td class="dashboard-project-date">${dashboardDate(p.deadline) || 'Geen datum'}</td>
      <td><span class="dashboard-project-team" aria-label="${escHtml(team.map(u => u.naam).join(', ') || 'Geen team ingesteld')}" title="${escHtml(team.map(u => u.naam).join(', ') || 'Geen team ingesteld')}">${team.slice(0,3).map(u => `<span class="dashboard-project-avatar" style="background:${/^#[0-9a-f]{6}$/i.test(u.kleur || '') ? u.kleur : '#64748b'}" aria-hidden="true">${escHtml((u.naam || '?').trim().slice(0,1).toUpperCase())}</span>`).join('')}${team.length > 3 ? `<span class="dashboard-project-extra">+${team.length - 3}</span>` : ''}${team.length ? '' : '<span class="dashboard-muted">Geen team</span>'}</span></td>
      <td class="dashboard-project-count">${count}</td>
    </tr>`;
  }).join('')}</tbody></table>`;
}

// Display-only compatibility for existing titles such as "Colin Vakantie" and
// "Britt vrij". Do not infer absence from duration, colour or a generic keyword
// inside a meeting title. Stored dates and the full calendar remain untouched.
function dashboardIsAbsence(item) {
  if (item.is_vacation != null) return item.is_vacation === true || item.is_vacation === 1;
  const title = (item.titel || '').trim();
  return ['vakantie', 'verlof', 'afwezig'].includes(item.type)
    || /(?:^|\s)(?:vakantie|verlof|afwezig|vrij|vrije dag(?:en)?)$/i.test(title)
    || /^(?:vakantie|verlof|afwezig)\s*[:–-]\s*\S/i.test(title);
}

function dashboardCalendarEvent(item) {
  const color = /^#[0-9a-f]{6}$/i.test(item.kleur || '') ? item.kleur : calTypeColor(item.type);
  const event = {id:item.id, title:item.titel, start:item.datum_start, end:item.datum_eind || undefined,
    allDay:false, backgroundColor:color + '20', borderColor:color, textColor:'#2C1810', extendedProps:{item}};
  if (!dashboardIsAbsence(item)) return event;
  const start = new Date(item.datum_start);
  if (Number.isNaN(start.getTime())) return event;
  const end = new Date(item.datum_eind || item.datum_start);
  if (Number.isNaN(end.getTime()) || end < start) return event;
  // FullCalendar's all-day end is exclusive: preserve exact-midnight ends,
  // include the final occupied date otherwise. Calendar arithmetic is DST-safe.
  if (end <= start || end.getHours() || end.getMinutes() || end.getSeconds()) end.setDate(end.getDate() + 1);
  if (dashboardLocalDate(end) === dashboardLocalDate(start)) end.setDate(end.getDate() + 1);
  event.allDay = true;
  event.start = dashboardLocalDate(start);
  event.end = dashboardLocalDate(end);
  event.backgroundColor = '#f3e9dc';
  event.borderColor = '#c7ab85';
  event.classNames = ['dashboard-absence-event'];
  // A half day must not appear to be an entire day off.
  const originalEnd = new Date(item.datum_eind || item.datum_start);
  if (dashboardLocalDate(start) === dashboardLocalDate(originalEnd) && (start.getHours() || start.getMinutes() || originalEnd.getHours() || originalEnd.getMinutes())) {
    const time = date => new Intl.DateTimeFormat('nl-NL',{hour:'2-digit',minute:'2-digit'}).format(date);
    event.title += ` · ${time(start)}${item.datum_eind ? '–' + time(originalEnd) : ''}`;
  }
  return event;
}

function dashboardCalendarHours(events) {
  let min = 8, max = 18;
  for (const event of events.filter(e => !e.allDay)) {
    const start = new Date(event.start), end = new Date(event.end || event.start);
    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) continue;
    if (dashboardLocalDate(start) !== dashboardLocalDate(end)) return {min:'00:00:00',max:'24:00:00'};
    min = Math.min(min, start.getHours());
    max = Math.max(max, event.end ? Math.ceil(end.getHours() + end.getMinutes()/60) : start.getHours()+1);
  }
  return {min:String(min).padStart(2,'0')+':00:00',max:String(max).padStart(2,'0')+':00:00'};
}

function dashboardNotesHtml(notes) {
  return notes.length ? notes.slice(0, 4).map(n => `
    <button class="dashboard-row" onclick="Dashboard.openNote('${escHtml(n.id)}')">
      <i class="far fa-file-alt dashboard-note-icon" aria-hidden="true"></i>
      <span class="dashboard-row-text"><strong>${escHtml(n.titel)}</strong>
        <small>${dashboardDate(n.updated_at || n.created_at)} · ${escHtml(n.aangemaakt_door_naam || App.currentUser?.naam || '')}</small></span>
    </button>`).join('') : dashboardEmpty('Er staan nog geen notities op jouw naam.');
}

async function renderDashboard() {
  Dashboard.dispose();
  const version = Dashboard.version;
  const userId = App.currentUser?.id;
  const container = document.getElementById('view-dashboard');
  if (!container || !userId) return;
  container.innerHTML=dashboardEmpty('Dashboard laden…');
  if (typeof DashboardLayout !== 'undefined') {
    if (!await DashboardLayout.load(version,userId)) return;
  }
  if (!Dashboard.isCurrent(version,userId)) return;
  Dashboard.taskPerson=userId;
  container.innerHTML = `
    <div class="page-header dashboard-header">
      <h2>Dashboard</h2>
      <div class="page-header-actions">
        <span class="dashboard-today"><i class="far fa-calendar" aria-hidden="true"></i> ${dashboardDate(new Date().toISOString(), {weekday:'long', day:'numeric', month:'long', year:'numeric'})}</span>
        <button class="btn btn-primary" onclick="openQuickTaskModal()"><i class="fas fa-plus" aria-hidden="true"></i> Nieuwe taak</button>
        <button id="dashboard-customize" class="btn btn-outline" onclick="DashboardLayout.edit()"><i class="fas fa-sliders" aria-hidden="true"></i> Dashboard aanpassen</button>
      </div>
    </div>
    <div id="dashboard-layout-status" role="status"></div>
    <section id="dashboard-layout-editor" hidden aria-label="Dashboard aanpassen"></section>
    <div id="dashboard-widget-grid">
    <section data-widget="calendar" class="dashboard-week dashboard-tile" aria-label="Weekkalender">
      <header class="dashboard-week-header">
        <div><h3>Deze week</h3><p id="dashboard-week-title" aria-live="polite"></p></div>
        <div class="dashboard-week-actions">
          <button class="btn btn-outline" onclick="openCalendarModal()"><i class="fas fa-plus" aria-hidden="true"></i> Nieuw item</button>
          <button class="btn btn-outline" aria-label="Vorige week" onclick="Dashboard.changeWeek('prev')"><i class="fas fa-chevron-left" aria-hidden="true"></i></button>
          <button class="btn btn-outline" onclick="Dashboard.changeWeek('today')">Vandaag</button>
          <button class="btn btn-outline" aria-label="Volgende week" onclick="Dashboard.changeWeek('next')"><i class="fas fa-chevron-right" aria-hidden="true"></i></button>
        </div>
      </header>
      <div id="dashboard-calendar-status" role="status"></div>
      <div class="dashboard-calendar-scroll"><div id="dashboard-calendar"></div></div>
    </section>
      <section data-widget="tasks" class="dashboard-tile" aria-labelledby="dashboard-tasks-title">
        <header class="dashboard-tile-header">
          <i class="fas fa-tasks dashboard-accent" aria-hidden="true"></i>
          <div><h3 id="dashboard-tasks-title">Openstaande taken</h3><p id="dashboard-task-count">Toegewezen aan jou</p></div>
          <button class="dashboard-link" onclick="Dashboard.myTasks()">Bekijk taken <i class="fas fa-arrow-right" aria-hidden="true"></i></button>
        </header>
        <label class="dashboard-person-label" for="dashboard-task-person">Persoon</label>
        <select id="dashboard-task-person" onchange="Dashboard.loadTasks(this.value)">${[App.currentUser, ...(App.users || []).filter(u=>u.id!==userId)].map(u=>`<option value="${escHtml(u.id)}" ${u.id===userId?'selected':''}>${escHtml(u.naam)}${u.id===userId?' (ik)':''}</option>`).join('')}</select>
        <div id="dashboard-my-tasks" class="dashboard-list" aria-live="polite">${dashboardEmpty('Taken laden…')}</div>
      </section>
      <section data-widget="projects" class="dashboard-tile" aria-labelledby="dashboard-projects-title">
        <header class="dashboard-tile-header">
          <i class="fas fa-folder dashboard-accent" aria-hidden="true"></i>
          <div><h3 id="dashboard-projects-title">Actieve projecten</h3></div>
          <button class="dashboard-link" onclick="navigateTo('projects')">Alle projecten <i class="fas fa-arrow-right" aria-hidden="true"></i></button>
        </header>
        <div id="dashboard-active-projects" class="dashboard-list" aria-live="polite">${dashboardEmpty('Projecten laden…')}</div>
      </section>
      <section data-widget="notes" class="dashboard-tile" aria-labelledby="dashboard-notes-title">
        <header class="dashboard-tile-header">
          <i class="fas fa-sticky-note dashboard-accent" aria-hidden="true"></i>
          <div><h3 id="dashboard-notes-title">Mijn notities</h3><p>Op jouw naam</p></div>
          <button class="dashboard-link" onclick="Dashboard.myNotes()">Al mijn notities <i class="fas fa-arrow-right" aria-hidden="true"></i></button>
        </header>
        <div id="dashboard-my-notes" class="dashboard-list" aria-live="polite">${dashboardEmpty('Notities laden…')}</div>
      </section>
    <section data-widget="trunkrs" class="dashboard-tile trunkrs-tile" id="trunkrs-tile" aria-label="Niet bezorgd Trunkrs">
      <div class="dash-section-header"><h3><i class="fas fa-truck" aria-hidden="true"></i> Niet bezorgd Trunkrs</h3></div>
      <div class="trunkrs-body">Overzicht laden…</div>
    </section></div>`;
  if (typeof DashboardLayout !== 'undefined') DashboardLayout.mount();
  mountDashboardCalendar(version, userId);
  if (typeof DashboardLayout !== 'undefined') DashboardLayout.resizeCalendar();
  if (typeof Trunkrs !== 'undefined') Trunkrs.mount();
  await Promise.all([Dashboard.loadTasks(userId), ...[
    ['notes', '/api/notes?mine=1', 'dashboard-my-notes'],
    ['projects', '/api/projects', 'dashboard-active-projects'],
  ].map(async ([kind, url, id]) => {
    const data = await api(url, {silentError: true});
    if (!Dashboard.isCurrent(version, userId)) return;
    const element = document.getElementById(id);
    if (!element) return;
    if (!Array.isArray(data)) { element.innerHTML = dashboardError('renderDashboard()'); return; }
    if (kind === 'tasks') element.innerHTML = dashboardTasksHtml(dashboardPersonalTasks(data, userId));
    if (kind === 'notes') {
      Dashboard.notes = dashboardPersonalNotes(data, userId);
      element.innerHTML = dashboardNotesHtml(Dashboard.notes);
    }
    if (kind === 'projects') {
      Dashboard.projects = data.filter(p => p.status === 'actief');
      element.innerHTML = dashboardProjectsHtml(Dashboard.projects);
    }
  })]);
}

function mountDashboardCalendar(version, userId) {
  const element = document.getElementById('dashboard-calendar');
  const status = document.getElementById('dashboard-calendar-status');
  if (typeof FullCalendar === 'undefined') {
    status.innerHTML = dashboardError('renderDashboard()');
    return;
  }
  Dashboard.calendar = new FullCalendar.Calendar(element, {
    locale: 'nl', initialView: 'timeGridWeek', initialDate: Dashboard.date || undefined,
    firstDay: 1, headerToolbar: false, height: 450,
    allDaySlot: true, allDayText: 'Afwezig', nowIndicator: true,
    dayMaxEvents: 2, eventMaxStack: 2, slotEventOverlap: false,
    moreLinkText: n => `+${n} meer`, moreLinkClick: 'popover',
    slotMinTime: '08:00:00', slotMaxTime: '18:00:00', scrollTime: '08:00:00', expandRows: true,
    slotDuration: '01:00:00', slotLabelInterval: '01:00:00',
    dayHeaderFormat: {weekday: 'short', day: 'numeric', month: 'short'},
    eventTimeFormat: {hour: '2-digit', minute: '2-digit', hour12: false},
    slotLabelFormat: {hour: '2-digit', minute: '2-digit', hour12: false},
    editable: false, selectable: false,
    dateClick(info) {
      if (!Dashboard.isCurrent(version, userId) || (typeof DashboardLayout !== 'undefined' && DashboardLayout.editing)) return;
      openCalendarModal(null, info.dateStr, info.allDay);
    },
    datesSet(info) {
      Dashboard.date = dashboardLocalDate(info.start);
      const end = new Date(info.end); end.setDate(end.getDate() - 1);
      document.getElementById('dashboard-week-title').textContent =
        dashboardDate(info.start.toISOString()) + ' – ' + dashboardDate(end.toISOString(), {day:'numeric', month:'long', year:'numeric'});
    },
    async events(info, success, failure) {
      status.textContent = 'Kalender laden…';
      const params = new URLSearchParams({start: dashboardLocalDate(info.start), end: dashboardLocalDate(info.end), overlap:'1'});
      const items = await api('/api/calendar?' + params, {silentError:true});
      if (!Dashboard.isCurrent(version, userId)) return;
      if (!Array.isArray(items)) {
        status.innerHTML = dashboardError("Dashboard.calendar.refetchEvents()");
        failure(new Error('Kalender kon niet worden geladen'));
        return;
      }
      status.textContent = '';
      const events = items.map(dashboardCalendarEvent);
      // Start with a readable working day, but never hide an early/late event.
      const hours = dashboardCalendarHours(events);
      Dashboard.calendar.setOption('slotMinTime', hours.min);
      Dashboard.calendar.setOption('slotMaxTime', hours.max);
      success(events);
    },
    eventClick(info) { openCalendarModal(info.event.extendedProps.item); },
    eventDidMount(info) {
      info.el.setAttribute('tabindex', '0');
      info.el.setAttribute('role', 'button');
      const item = info.event.extendedProps.item;
      const format = {weekday:'long',day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'};
      const label = item.titel + ', ' + dashboardDate(item.datum_start, format) + (item.datum_eind ? ' – ' + dashboardDate(item.datum_eind, format) : '');
      info.el.setAttribute('aria-label', label);
      info.el.setAttribute('title', label);
      info.el.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); openCalendarModal(info.event.extendedProps.item); }
      });
    },
  });
  Dashboard.calendar.render();
}

function calTypeColor(type) {
  const colors = { content:'#3B82F6', deadline:'#EF4444', meeting:'#8B5CF6', social:'#EC4899', email:'#F59E0B', blog:'#10B981' };
  return colors[type] || '#3B82F6';
}

function filterDashboardTasks() {
  const projectId = document.getElementById('dash-filter-project')?.value;
  const userId = document.getElementById('dash-filter-user')?.value;

  let filtered = App.dashboardTasks || [];
  if (projectId) filtered = filtered.filter(t => t.project_id === projectId);
  if (userId) filtered = filtered.filter(t => (t.toegewezenen||[]).some(u => u.id === userId));

  renderDashboardKanban(filtered);
}

function renderDashboardKanban(tasks) {
  const statuses = [
    { key: 'todo', label: 'Te doen', icon: 'circle' },
    { key: 'bezig', label: 'Bezig', icon: 'spinner' },
    { key: 'review', label: 'Review', icon: 'eye' },
    { key: 'klaar', label: 'Klaar', icon: 'check-circle' },
  ];

  const board = document.getElementById('dashboard-kanban');
  if (!board) return;

  board.innerHTML = statuses.map(s => {
    const statusTasks = tasks.filter(t => t.status === s.key);
    return `
      <div class="kanban-column">
        <div class="kanban-column-header">
          <span><i class="fas fa-${s.icon}"></i> ${s.label}</span>
          <span class="badge">${statusTasks.length}</span>
        </div>
        <div class="kanban-list" data-status="${s.key}" id="dash-kanban-${s.key}">
          ${statusTasks.map(t => kanbanCardHtml(t)).join('')}
        </div>
      </div>`;
  }).join('');

  statuses.forEach(s => {
    const el = document.getElementById(`dash-kanban-${s.key}`);
    if (el) {
      new Sortable(el, {
        group: 'dashboard-tasks',
        animation: 150,
        ghostClass: 'sortable-ghost',
        dragClass: 'sortable-drag',
        onEnd: handleDashboardDragEnd,
      });
    }
  });
}

function kanbanCardHtml(t) {
  const borderColor = t.kleur || t.project_kleur || '#E2E8F0';
  const deadline = t.deadline ? formatDate(t.deadline) : '';
  const isOverdue = t.deadline && t.deadline.split('T')[0] < todayStr() && t.status !== 'klaar';
  const assignees = t.toegewezenen || [];

  return `
    <div class="kanban-card" data-id="${t.id}" style="border-left-color:${borderColor}">
      <div class="kanban-card-title">${escHtml(t.titel)}</div>
      <div class="kanban-card-meta">
        <span class="tag priority-${t.prioriteit}">${t.prioriteit}</span>
        ${t.project_naam ? `<span style="color:${t.project_kleur || '#64748B'}"><i class="fas fa-folder"></i> ${escHtml(t.project_naam)}</span>` : ''}
        ${deadline ? `<span style="${isOverdue ? 'color:var(--danger);font-weight:600' : ''}"><i class="fas fa-clock"></i> ${deadline}</span>` : ''}
        ${assignees.length > 0 ? `<span class="kanban-card-assignees">${assignees.map(u=>`<span class="kanban-card-assignee" style="background:${u.kleur||'#3B82F6'}" title="${escHtml(u.naam)}">${u.naam.charAt(0)}</span>`).join('')}</span>` : ''}
        <span class="kanban-card-actions">
          <button class="btn-icon" onclick="openEditTaskModal('${t.id}')"><i class="fas fa-pen"></i></button>
          <button class="btn-icon" onclick="deleteTask('${t.id}')"><i class="fas fa-trash"></i></button>
        </span>
      </div>
    </div>`;
}

async function handleDashboardDragEnd(evt) {
  const newStatus = evt.to.dataset.status;
  const items = [];
  evt.to.querySelectorAll('.kanban-card').forEach((card, i) => {
    items.push({ id: card.dataset.id, status: newStatus, positie: i });
  });
  if (evt.from !== evt.to) {
    const oldStatus = evt.from.dataset.status;
    evt.from.querySelectorAll('.kanban-card').forEach((card, i) => {
      items.push({ id: card.dataset.id, status: oldStatus, positie: i });
    });
  }
  await api('/api/tasks/reorder/batch', { method: 'PUT', body: { tasks: items } });
}

function openQuickTaskModal() {
  openModal('Nieuwe taak', `
    <div class="form-group">
      <label>Titel</label>
      <div class="input-with-emoji">
        <input type="text" id="qt-titel" required style="padding-right: 36px;">
        <button type="button" class="btn-icon emoji-trigger" onclick="toggleEmojiPicker('qt-title-emoji')" title="Emoji"><i class="far fa-smile"></i></button>
        ${createEmojiPicker('qt-titel', 'qt-title-emoji')}
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Project</label>
        <select id="qt-project">${projectSelectOptions()}</select>
      </div>
      <div class="form-group">
        <label>Prioriteit</label>
        <select id="qt-prioriteit">
          <option value="laag">Laag</option>
          <option value="normaal" selected>Normaal</option>
          <option value="hoog">Hoog</option>
          <option value="urgent">Urgent</option>
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Toegewezen aan</label>
        <div id="qt-users">${userCheckboxGroup(App.currentUser?.id ? [App.currentUser.id] : [])}</div>
      </div>
      <div class="form-group">
        <label>Deadline</label>
        <input type="date" id="qt-deadline">
      </div>
    </div>
    <div class="form-group form-group-with-emoji">
      <label>Beschrijving</label>
      <div class="input-with-emoji">
        <textarea id="qt-beschrijving" rows="3" style="padding-right: 36px;"></textarea>
        <button type="button" class="btn-icon emoji-trigger" onclick="toggleEmojiPicker('qt-desc-emoji')" title="Emoji"><i class="far fa-smile"></i></button>
        ${createEmojiPicker('qt-beschrijving', 'qt-desc-emoji')}
      </div>
    </div>
    <div class="form-group">
      <label>Kleur</label>
      ${colorPickerHtml('#3B82F6', App.colors)}
    </div>
  `, `
    <button class="btn btn-outline" onclick="closeModal()">Annuleren</button>
    <button class="btn btn-primary" onclick="saveQuickTask()">Opslaan</button>
  `);
}

async function saveQuickTask() {
  const titel = document.getElementById('qt-titel').value.trim();
  if (!titel) { toast('Voer een titel in', 'error'); return; }
  const kleur = getSelectedColor(document.getElementById('modal-body'));
  const result = await api('/api/tasks', { method: 'POST', body: {
    titel,
    project_id: document.getElementById('qt-project').value || null,
    prioriteit: document.getElementById('qt-prioriteit').value,
    toegewezen_aan: getSelectedUserIds('qt-users'),
    deadline: document.getElementById('qt-deadline').value || null,
    beschrijving: document.getElementById('qt-beschrijving').value,
    kleur,
  }});
  if (result) {
    closeModal();
    toast('Taak aangemaakt', 'success');
    if (App.currentView === 'dashboard') renderDashboard();
    else if (App.currentView === 'tasks') loadAndRenderTasks();
  }
}

function escHtml(str) {
  if (!str) return '';
  return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
