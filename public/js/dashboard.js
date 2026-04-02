// ========== Dashboard ==========
async function renderDashboard() {
  const container = document.getElementById('view-dashboard');
  const [stats, tasks, calItems, notes, projects] = await Promise.all([
    api('/api/dashboard/stats'),
    api('/api/tasks'),
    api('/api/calendar?start=' + todayStr() + '&end=' + new Date(Date.now() + 14 * 86400000).toISOString().split('T')[0]),
    api('/api/notes'),
    api('/api/projects'),
  ]);
  if (!stats || !tasks) return;

  const today = todayStr();
  const overdueTasks = tasks.filter(t => t.deadline && t.deadline.split('T')[0] < today && t.status !== 'klaar');
  const todayTasks = tasks.filter(t => t.deadline && t.deadline.split('T')[0] === today && t.status !== 'klaar');
  const upcomingCal = (calItems || []).slice(0, 8);
  const recentNotes = (notes || []).slice(0, 4);
  const activeProjects = (projects || []).filter(p => p.status === 'actief').slice(0, 6);

  container.innerHTML = `
    <div class="page-header">
      <h2>Dashboard</h2>
      <div class="page-header-actions">
        <button class="btn btn-primary" onclick="openQuickTaskModal()"><i class="fas fa-plus"></i> Nieuwe taak</button>
        <button class="btn btn-outline" onclick="navigateTo('calendar')"><i class="fas fa-calendar-plus"></i> Kalender item</button>
      </div>
    </div>

    <div class="stats-grid">
      <div class="stat-card clickable" onclick="navigateTo('projects')">
        <div class="stat-icon blue"><i class="fas fa-folder"></i></div>
        <div><div class="stat-value">${stats.totaal_projecten}</div><div class="stat-label">Projecten</div></div>
      </div>
      <div class="stat-card clickable" onclick="navigateTo('tasks')">
        <div class="stat-icon purple"><i class="fas fa-tasks"></i></div>
        <div><div class="stat-value">${stats.actieve_taken}</div><div class="stat-label">Actieve taken</div></div>
      </div>
      <div class="stat-card clickable" onclick="navigateTo('tasks')" ${stats.taken_verlopen > 0 ? 'style="border:2px solid var(--danger)"' : ''}>
        <div class="stat-icon red"><i class="fas fa-exclamation-triangle"></i></div>
        <div><div class="stat-value">${stats.taken_verlopen}</div><div class="stat-label">Verlopen</div></div>
      </div>
    </div>

    <!-- Main dashboard grid -->
    <div class="dash-grid">
      <!-- Left: Kanban -->
      <div class="dash-main">
        <div class="dash-section">
          <div class="dash-section-header">
            <h3><i class="fas fa-tasks"></i> Taken overzicht</h3>
            <div class="dash-section-actions">
              <select id="dash-filter-project" onchange="filterDashboardTasks()" class="filter-inline">
                <option value="">Alle projecten</option>
                ${App.projects.map(p => `<option value="${p.id}">${p.naam}</option>`).join('')}
              </select>
              <select id="dash-filter-user" onchange="filterDashboardTasks()" class="filter-inline">
                <option value="">Alle personen</option>
                ${App.users.map(u => `<option value="${u.id}">${u.naam}</option>`).join('')}
              </select>
            </div>
          </div>
          <div class="kanban-board" id="dashboard-kanban"></div>
        </div>
      </div>

      <!-- Right sidebar -->
      <div class="dash-sidebar-right">
        <!-- Overdue -->
        ${overdueTasks.length > 0 ? `
        <div class="dash-panel dash-panel-danger">
          <div class="dash-panel-header"><h4><i class="fas fa-exclamation-circle"></i> Verlopen taken</h4></div>
          <div class="dash-panel-body">
            ${overdueTasks.slice(0, 5).map(t => `
              <div class="dash-task-item" onclick="openEditTaskModal('${t.id}')">
                <div class="dash-task-title">${escHtml(t.titel)}</div>
                <div class="dash-task-meta">
                  <span class="tag priority-${t.prioriteit}">${t.prioriteit}</span>
                  <span class="dash-task-date overdue"><i class="fas fa-clock"></i> ${formatDate(t.deadline)}</span>
                </div>
              </div>
            `).join('')}
            ${overdueTasks.length > 5 ? `<div class="dash-more" onclick="navigateTo('tasks')">+${overdueTasks.length - 5} meer...</div>` : ''}
          </div>
        </div>` : ''}

        <!-- Today -->
        <div class="dash-panel">
          <div class="dash-panel-header"><h4><i class="fas fa-calendar-day"></i> Vandaag</h4><span class="badge">${todayTasks.length}</span></div>
          <div class="dash-panel-body">
            ${todayTasks.length === 0 ? '<div class="dash-empty">Geen taken voor vandaag</div>' : ''}
            ${todayTasks.slice(0, 6).map(t => `
              <div class="dash-task-item" onclick="openEditTaskModal('${t.id}')">
                <div class="dash-task-title">${escHtml(t.titel)}</div>
                <div class="dash-task-meta">
                  <span class="tag priority-${t.prioriteit}">${t.prioriteit}</span>
                  ${t.project_naam ? `<span style="color:${t.project_kleur || '#64748B'}"><i class="fas fa-folder"></i> ${escHtml(t.project_naam)}</span>` : ''}
                  ${t.toegewezen_naam ? `<span class="kanban-card-assignee" style="background:${t.user_kleur || '#3B82F6'}" title="${escHtml(t.toegewezen_naam)}">${t.toegewezen_naam.charAt(0)}</span>` : ''}
                </div>
              </div>
            `).join('')}
          </div>
        </div>

        <!-- Upcoming Calendar -->
        <div class="dash-panel">
          <div class="dash-panel-header"><h4><i class="fas fa-calendar-alt"></i> Komende kalender items</h4><button class="btn btn-sm btn-outline" onclick="navigateTo('calendar')">Bekijk alles</button></div>
          <div class="dash-panel-body">
            ${upcomingCal.length === 0 ? '<div class="dash-empty">Geen komende items</div>' : ''}
            ${upcomingCal.map(c => `
              <div class="dash-cal-item" onclick="navigateTo('calendar')">
                <div class="dash-cal-dot" style="background:${c.kleur || calTypeColor(c.type)}"></div>
                <div class="dash-cal-info">
                  <div class="dash-cal-title">${escHtml(c.titel)}</div>
                  <div class="dash-cal-date">${formatDateTime(c.datum_start)} · <span class="dash-cal-type">${c.type}</span></div>
                </div>
              </div>
            `).join('')}
          </div>
        </div>

        <!-- Recent Notes -->
        <div class="dash-panel">
          <div class="dash-panel-header"><h4><i class="fas fa-sticky-note"></i> Recente notities</h4><button class="btn btn-sm btn-outline" onclick="navigateTo('notes')">Bekijk alles</button></div>
          <div class="dash-panel-body">
            ${recentNotes.length === 0 ? '<div class="dash-empty">Geen notities</div>' : ''}
            ${recentNotes.map(n => `
              <div class="dash-note-item" style="border-left:3px solid ${n.kleur || '#FEF3C7'}" onclick="navigateTo('notes')">
                <div class="dash-note-title">${escHtml(n.titel)}</div>
                <div class="dash-note-preview">${escHtml((n.inhoud || '').substring(0, 80))}${(n.inhoud || '').length > 80 ? '...' : ''}</div>
              </div>
            `).join('')}
          </div>
        </div>

      </div>
    </div>

    <!-- Actieve projecten - full width -->
    <div class="dash-section" style="margin-top:24px">
      <div class="dash-section-header">
        <h3><i class="fas fa-folder"></i> Actieve projecten</h3>
        <button class="btn btn-sm btn-outline" onclick="navigateTo('projects')">Bekijk alles</button>
      </div>
      <div class="dash-projects-grid">
        ${activeProjects.map(p => {
          const progress = p.aantal_taken > 0 ? Math.round((p.taken_klaar / p.aantal_taken) * 100) : 0;
          return `
          <div class="dash-project-card" onclick="navigateTo('projects')" style="border-top:3px solid ${p.kleur || '#ea484b'}">
            <div class="dash-project-name">${escHtml(p.naam)}</div>
            <div class="dash-project-desc">${escHtml((p.beschrijving || '').substring(0, 60))}</div>
            <div class="dash-project-progress">
              <div class="progress-bar" style="flex:1"><div class="progress-fill" style="width:${progress}%"></div></div>
              <span>${p.taken_klaar || 0}/${p.aantal_taken || 0} taken</span>
            </div>
            <div class="dash-project-meta">
              <span class="tag priority-${p.prioriteit}">${p.prioriteit}</span>
              ${p.deadline ? `<span><i class="fas fa-clock"></i> ${formatDate(p.deadline)}</span>` : ''}
            </div>
          </div>`;
        }).join('')}
      </div>
    </div>
  `;

  App.dashboardTasks = tasks.filter(t => t.status !== 'klaar');
  filterDashboardTasks();
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
  if (userId) filtered = filtered.filter(t => t.toegewezen_aan === userId);

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

  return `
    <div class="kanban-card" data-id="${t.id}" style="border-left-color:${borderColor}">
      <div class="kanban-card-title">${escHtml(t.titel)}</div>
      <div class="kanban-card-meta">
        <span class="tag priority-${t.prioriteit}">${t.prioriteit}</span>
        ${t.project_naam ? `<span style="color:${t.project_kleur || '#64748B'}"><i class="fas fa-folder"></i> ${escHtml(t.project_naam)}</span>` : ''}
        ${deadline ? `<span style="${isOverdue ? 'color:var(--danger);font-weight:600' : ''}"><i class="fas fa-clock"></i> ${deadline}</span>` : ''}
        ${t.toegewezen_naam ? `<span class="kanban-card-assignee" style="background:${t.user_kleur || '#3B82F6'}" title="${escHtml(t.toegewezen_naam)}">${t.toegewezen_naam.charAt(0)}</span>` : ''}
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
        <select id="qt-user">${userSelectOptions()}</select>
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
    toegewezen_aan: document.getElementById('qt-user').value || null,
    deadline: document.getElementById('qt-deadline').value || null,
    beschrijving: document.getElementById('qt-beschrijving').value,
    kleur,
  }});
  if (result) {
    closeModal();
    toast('Taak aangemaakt', 'success');
    renderDashboard();
  }
}

function escHtml(str) {
  if (!str) return '';
  return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
