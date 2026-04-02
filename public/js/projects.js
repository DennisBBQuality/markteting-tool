// ========== Projects ==========
async function renderProjects() {
  const container = document.getElementById('view-projects');
  const projects = await api('/api/projects');
  if (!projects) return;
  App.projects = projects;

  container.innerHTML = `
    <div class="page-header">
      <h2>Projecten</h2>
      <div class="page-header-actions">
        <button class="btn btn-primary" onclick="openProjectModal()"><i class="fas fa-plus"></i> Nieuw project</button>
      </div>
    </div>
    <div class="filters-bar">
      <select id="proj-filter-status" onchange="renderProjectGrid()">
        <option value="">Alle statussen</option>
        <option value="actief">Actief</option>
        <option value="gepauzeerd">Gepauzeerd</option>
        <option value="afgerond">Afgerond</option>
        <option value="gearchiveerd">Gearchiveerd</option>
      </select>
      <select id="proj-filter-priority" onchange="renderProjectGrid()">
        <option value="">Alle prioriteiten</option>
        <option value="urgent">Urgent</option>
        <option value="hoog">Hoog</option>
        <option value="normaal">Normaal</option>
        <option value="laag">Laag</option>
      </select>
      <input type="text" class="filter-search" placeholder="Zoeken..." id="proj-filter-search" oninput="renderProjectGrid()">
    </div>
    <div class="projects-grid" id="projects-grid"></div>
  `;
  renderProjectGrid();
}

function renderProjectGrid() {
  const status = document.getElementById('proj-filter-status')?.value;
  const priority = document.getElementById('proj-filter-priority')?.value;
  const search = document.getElementById('proj-filter-search')?.value.toLowerCase();

  let filtered = App.projects;
  if (status) filtered = filtered.filter(p => p.status === status);
  if (priority) filtered = filtered.filter(p => p.prioriteit === priority);
  if (search) filtered = filtered.filter(p => p.naam.toLowerCase().includes(search) || (p.beschrijving || '').toLowerCase().includes(search));

  const grid = document.getElementById('projects-grid');
  if (!grid) return;

  if (filtered.length === 0) {
    grid.innerHTML = `<div class="empty-state"><i class="fas fa-folder-open"></i><p>Geen projecten gevonden</p></div>`;
    return;
  }

  grid.innerHTML = filtered.map(p => {
    const progress = p.aantal_taken > 0 ? Math.round((p.taken_klaar / p.aantal_taken) * 100) : 0;
    const medewerkers = p.medewerkers || [];
    return `
      <div class="project-card" style="border-top-color:${p.kleur || '#3B82F6'}" onclick="openProjectDetail('${p.id}')">
        <div class="project-card-header">
          <div class="project-card-title">${escHtml(p.naam)}</div>
          <span class="project-card-status status-${p.status}">${p.status}</span>
        </div>
        <div class="project-card-desc">${escHtml(p.beschrijving || 'Geen beschrijving')}</div>
        <div class="project-card-footer">
          <div class="project-progress">
            <div class="progress-bar"><div class="progress-fill" style="width:${progress}%"></div></div>
            <span>${p.taken_klaar || 0}/${p.aantal_taken || 0}</span>
          </div>
          <span class="tag priority-${p.prioriteit}">${p.prioriteit}</span>
          ${p.deadline ? `<span><i class="fas fa-clock"></i> ${formatDate(p.deadline)}</span>` : ''}
          ${medewerkers.length > 0 ? `<span class="kanban-card-assignees">${medewerkers.map(u=>`<span class="kanban-card-assignee" style="background:${u.kleur||'#3B82F6'}" title="${escHtml(u.naam)}">${u.naam.charAt(0)}</span>`).join('')}</span>` : ''}
        </div>
      </div>`;
  }).join('');
}

function openProjectModal(project) {
  const p = project || {};
  const isEdit = !!p.id;
  openModal(isEdit ? 'Project bewerken' : 'Nieuw project', `
    <div class="form-group">
      <label>Naam</label>
      <div class="input-with-emoji">
        <input type="text" id="proj-naam" value="${escHtml(p.naam || '')}" required style="padding-right: 36px;">
        <button type="button" class="btn-icon emoji-trigger" onclick="toggleEmojiPicker('proj-name-emoji')" title="Emoji"><i class="far fa-smile"></i></button>
        ${createEmojiPicker('proj-naam', 'proj-name-emoji')}
      </div>
    </div>
    <div class="form-group form-group-with-emoji">
      <label>Beschrijving</label>
      <div class="input-with-emoji">
        <textarea id="proj-beschrijving" rows="3" style="padding-right: 36px;">${escHtml(p.beschrijving || '')}</textarea>
        <button type="button" class="btn-icon emoji-trigger" onclick="toggleEmojiPicker('proj-desc-emoji')" title="Emoji"><i class="far fa-smile"></i></button>
        ${createEmojiPicker('proj-beschrijving', 'proj-desc-emoji')}
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Status</label>
        <select id="proj-status">
          ${['actief','gepauzeerd','afgerond','gearchiveerd'].map(s =>
            `<option value="${s}" ${p.status === s ? 'selected' : ''}>${s}</option>`
          ).join('')}
        </select>
      </div>
      <div class="form-group">
        <label>Prioriteit</label>
        <select id="proj-prioriteit">
          ${['laag','normaal','hoog','urgent'].map(s =>
            `<option value="${s}" ${p.prioriteit === s ? 'selected' : ''}>${s}</option>`
          ).join('')}
        </select>
      </div>
    </div>
    <div class="form-group">
      <label>Deadline</label>
      <input type="date" id="proj-deadline" value="${toInputDate(p.deadline)}">
    </div>
    <div class="form-group">
      <label>Teamleden</label>
      <div id="proj-medewerkers">${userCheckboxGroup((p.medewerkers||[]).map(u=>u.id))}</div>
    </div>
    <div class="form-group">
      <label>Kleur</label>
      ${colorPickerHtml(p.kleur || '#3B82F6', App.colors)}
    </div>
    ${isEdit ? attachmentsHtml('project_id', p.id) : ''}
  `, `
    ${isEdit ? `<button class="btn btn-danger" onclick="deleteProject('${p.id}')"><i class="fas fa-trash"></i> Verwijderen</button>` : ''}
    <button class="btn btn-outline" onclick="closeModal()">Annuleren</button>
    <button class="btn btn-primary" onclick="saveProject('${p.id || ''}')">${isEdit ? 'Opslaan' : 'Aanmaken'}</button>
  `);
  if (isEdit) loadAttachments('project_id', p.id);
}

async function saveProject(id) {
  const naam = document.getElementById('proj-naam').value.trim();
  if (!naam) { toast('Voer een naam in', 'error'); return; }
  const kleur = getSelectedColor(document.getElementById('modal-body'));
  const body = {
    naam,
    beschrijving: document.getElementById('proj-beschrijving').value,
    status: document.getElementById('proj-status').value,
    prioriteit: document.getElementById('proj-prioriteit').value,
    deadline: document.getElementById('proj-deadline').value || null,
    kleur,
    medewerkers: getSelectedUserIds('proj-medewerkers'),
  };
  const result = id
    ? await api(`/api/projects/${id}`, { method: 'PUT', body })
    : await api('/api/projects', { method: 'POST', body });
  if (result) {
    closeModal();
    toast(id ? 'Project bijgewerkt' : 'Project aangemaakt', 'success');
    await loadGlobalData();
    renderProjects();
  }
}

async function deleteProject(id) {
  if (!confirm('Weet je zeker dat je dit project wilt verwijderen? Alle taken worden ook verwijderd.')) return;
  await api(`/api/projects/${id}`, { method: 'DELETE' });
  closeModal();
  toast('Project verwijderd', 'success');
  await loadGlobalData();
  renderProjects();
}

async function openProjectDetail(id) {
  const project = App.projects.find(p => p.id === id);
  if (!project) return;
  openProjectModal(project);
}
