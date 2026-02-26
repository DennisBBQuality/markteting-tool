// ========== Tasks ==========
async function renderTasks() {
  const container = document.getElementById('view-tasks');

  container.innerHTML = `
    <div class="page-header">
      <h2>Taken</h2>
      <div class="page-header-actions">
        <button class="btn btn-primary" onclick="openQuickTaskModal()"><i class="fas fa-plus"></i> Nieuwe taak</button>
      </div>
    </div>
    <div class="filters-bar">
      <select id="task-filter-project" onchange="loadAndRenderTasks()">
        <option value="">Alle projecten</option>
        ${App.projects.map(p => `<option value="${p.id}">${p.naam}</option>`).join('')}
      </select>
      <select id="task-filter-user" onchange="loadAndRenderTasks()">
        <option value="">Alle personen</option>
        ${App.users.map(u => `<option value="${u.id}">${u.naam}</option>`).join('')}
      </select>
      <select id="task-filter-priority" onchange="loadAndRenderTasks()">
        <option value="">Alle prioriteiten</option>
        <option value="urgent">Urgent</option>
        <option value="hoog">Hoog</option>
        <option value="normaal">Normaal</option>
        <option value="laag">Laag</option>
      </select>
      <input type="text" class="filter-search" placeholder="Zoeken..." id="task-filter-search" oninput="loadAndRenderTasks()">
    </div>
    <div class="kanban-board" id="tasks-kanban"></div>
  `;
  loadAndRenderTasks();
}

async function loadAndRenderTasks() {
  const params = new URLSearchParams();
  const project = document.getElementById('task-filter-project')?.value;
  const user = document.getElementById('task-filter-user')?.value;
  const priority = document.getElementById('task-filter-priority')?.value;
  const search = document.getElementById('task-filter-search')?.value;
  if (project) params.set('project_id', project);
  if (user) params.set('toegewezen_aan', user);
  if (priority) params.set('prioriteit', priority);
  if (search) params.set('search', search);

  const tasks = await api(`/api/tasks?${params}`);
  if (!tasks) return;

  const statuses = [
    { key: 'todo', label: 'Te doen', icon: 'circle' },
    { key: 'bezig', label: 'Bezig', icon: 'spinner' },
    { key: 'review', label: 'Review', icon: 'eye' },
    { key: 'klaar', label: 'Klaar', icon: 'check-circle' },
  ];

  const board = document.getElementById('tasks-kanban');
  if (!board) return;

  board.innerHTML = statuses.map(s => {
    const statusTasks = tasks.filter(t => t.status === s.key);
    return `
      <div class="kanban-column">
        <div class="kanban-column-header">
          <span><i class="fas fa-${s.icon}"></i> ${s.label}</span>
          <span class="badge">${statusTasks.length}</span>
        </div>
        <div class="kanban-list" data-status="${s.key}" id="tasks-kanban-${s.key}">
          ${statusTasks.map(t => kanbanCardHtml(t)).join('')}
        </div>
      </div>`;
  }).join('');

  // Init drag & drop
  statuses.forEach(s => {
    const el = document.getElementById(`tasks-kanban-${s.key}`);
    if (el) {
      new Sortable(el, {
        group: 'tasks',
        animation: 150,
        ghostClass: 'sortable-ghost',
        dragClass: 'sortable-drag',
        onEnd: handleTaskDragEnd,
      });
    }
  });
}

async function handleTaskDragEnd(evt) {
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

async function openEditTaskModal(id) {
  const tasks = await api('/api/tasks');
  const t = tasks?.find(t => t.id === id);
  if (!t) return;

  openModal('Taak bewerken', `
    <div class="form-group">
      <label>Titel</label>
      <div class="input-with-emoji">
        <input type="text" id="et-titel" value="${escHtml(t.titel)}" style="padding-right: 36px;">
        <button type="button" class="btn-icon emoji-trigger" onclick="toggleEmojiPicker('task-title-emoji')" title="Emoji"><i class="far fa-smile"></i></button>
        ${createEmojiPicker('et-titel', 'task-title-emoji')}
      </div>
    </div>
    <div class="form-group form-group-with-emoji">
      <label>Beschrijving</label>
      <div class="input-with-emoji">
        <textarea id="et-beschrijving" rows="3" style="padding-right: 36px;">${escHtml(t.beschrijving || '')}</textarea>
        <button type="button" class="btn-icon emoji-trigger" onclick="toggleEmojiPicker('task-desc-emoji')" title="Emoji"><i class="far fa-smile"></i></button>
        ${createEmojiPicker('et-beschrijving', 'task-desc-emoji')}
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Project</label>
        <select id="et-project">${projectSelectOptions(t.project_id)}</select>
      </div>
      <div class="form-group">
        <label>Status</label>
        <select id="et-status">
          ${['todo','bezig','review','klaar'].map(s => `<option value="${s}" ${t.status===s?'selected':''}>${s==='todo'?'Te doen':s==='bezig'?'Bezig':s==='review'?'Review':'Klaar'}</option>`).join('')}
        </select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Prioriteit</label>
        <select id="et-prioriteit">
          ${['laag','normaal','hoog','urgent'].map(s => `<option value="${s}" ${t.prioriteit===s?'selected':''}>${s}</option>`).join('')}
        </select>
      </div>
      <div class="form-group">
        <label>Toegewezen aan</label>
        <select id="et-user">${userSelectOptions(t.toegewezen_aan)}</select>
      </div>
    </div>
    <div class="form-group">
      <label>Deadline</label>
      <input type="date" id="et-deadline" value="${toInputDate(t.deadline)}">
    </div>
    <div class="form-group">
      <label>Kleur</label>
      ${colorPickerHtml(t.kleur || '#3B82F6', App.colors)}
    </div>
    ${attachmentsHtml('task_id', t.id)}
  `, `
    <button class="btn btn-danger" onclick="deleteTask('${t.id}')"><i class="fas fa-trash"></i> Verwijderen</button>
    <button class="btn btn-outline" onclick="closeModal()">Annuleren</button>
    <button class="btn btn-primary" onclick="saveEditTask('${t.id}')">Opslaan</button>
  `);
  loadAttachments('task_id', t.id);
}

async function saveEditTask(id) {
  const titel = document.getElementById('et-titel').value.trim();
  if (!titel) { toast('Voer een titel in', 'error'); return; }
  const kleur = getSelectedColor(document.getElementById('modal-body'));
  const result = await api(`/api/tasks/${id}`, { method: 'PUT', body: {
    titel,
    beschrijving: document.getElementById('et-beschrijving').value,
    project_id: document.getElementById('et-project').value || null,
    status: document.getElementById('et-status').value,
    prioriteit: document.getElementById('et-prioriteit').value,
    toegewezen_aan: document.getElementById('et-user').value || null,
    deadline: document.getElementById('et-deadline').value || null,
    kleur,
  }});
  if (result) {
    closeModal();
    toast('Taak bijgewerkt', 'success');
    if (App.currentView === 'dashboard') renderDashboard();
    else renderTasks();
  }
}

async function deleteTask(id) {
  if (!confirm('Weet je zeker dat je deze taak wilt verwijderen?')) return;
  await api(`/api/tasks/${id}`, { method: 'DELETE' });
  closeModal();
  toast('Taak verwijderd', 'success');
  if (App.currentView === 'dashboard') renderDashboard();
  else renderTasks();
}
