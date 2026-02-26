// ========== Notes ==========
async function renderNotes() {
  const container = document.getElementById('view-notes');

  container.innerHTML = `
    <div class="page-header">
      <h2>Notities</h2>
      <div class="page-header-actions">
        <button class="btn btn-primary" onclick="openNoteModal()"><i class="fas fa-plus"></i> Nieuwe notitie</button>
      </div>
    </div>
    <div class="filters-bar">
      <select id="note-filter-project" onchange="loadNotes()">
        <option value="">Alle projecten</option>
        ${App.projects.map(p => `<option value="${p.id}">${p.naam}</option>`).join('')}
      </select>
      <input type="text" class="filter-search" placeholder="Zoeken..." id="note-filter-search" oninput="loadNotes()">
    </div>
    <div class="notes-grid" id="notes-grid"></div>
  `;
  loadNotes();
}

async function loadNotes() {
  const params = new URLSearchParams();
  const project = document.getElementById('note-filter-project')?.value;
  const search = document.getElementById('note-filter-search')?.value;
  if (project) params.set('project_id', project);
  if (search) params.set('search', search);

  const notes = await api(`/api/notes?${params}`);
  if (!notes) return;

  const grid = document.getElementById('notes-grid');
  if (!grid) return;

  if (notes.length === 0) {
    grid.innerHTML = `<div class="empty-state"><i class="fas fa-sticky-note"></i><p>Geen notities gevonden</p><button class="btn btn-primary" onclick="openNoteModal()"><i class="fas fa-plus"></i> Eerste notitie maken</button></div>`;
    return;
  }

  grid.innerHTML = notes.map(n => `
    <div class="note-card" style="background:${n.kleur || '#FEF3C7'}" onclick="openNoteModal(${escAttr(JSON.stringify(n))})">
      <div class="note-card-title">${escHtml(n.titel)}</div>
      <div class="note-card-content">${escHtml(n.inhoud || '')}</div>
      <div class="note-card-footer">
        <span>${n.aangemaakt_door_naam || ''}</span>
        <span>${formatDate(n.created_at)}</span>
        ${n.project_naam ? `<span><i class="fas fa-folder"></i> ${escHtml(n.project_naam)}</span>` : ''}
      </div>
    </div>
  `).join('');
}

function escAttr(str) {
  return str.replace(/'/g, '&#39;').replace(/"/g, '&quot;');
}

function openNoteModal(note) {
  const n = note || {};
  const isEdit = !!n.id;
  openModal(isEdit ? 'Notitie bewerken' : 'Nieuwe notitie', `
    <div class="form-group">
      <label>Titel</label>
      <div class="input-with-emoji">
        <input type="text" id="note-titel" value="${escHtml(n.titel || '')}" required style="padding-right: 36px;">
        <button type="button" class="btn-icon emoji-trigger" onclick="toggleEmojiPicker('note-title-emoji')" title="Emoji"><i class="far fa-smile"></i></button>
        ${createEmojiPicker('note-titel', 'note-title-emoji')}
      </div>
    </div>
    <div class="form-group form-group-with-emoji">
      <label>Inhoud</label>
      <div class="input-with-emoji">
        <textarea id="note-inhoud" rows="8" style="padding-right: 36px;">${escHtml(n.inhoud || '')}</textarea>
        <button type="button" class="btn-icon emoji-trigger" onclick="toggleEmojiPicker('note-content-emoji')" title="Emoji"><i class="far fa-smile"></i></button>
        ${createEmojiPicker('note-inhoud', 'note-content-emoji')}
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Project</label>
        <select id="note-project">${projectSelectOptions(n.project_id)}</select>
      </div>
    </div>
    <div class="form-group">
      <label>Kleur</label>
      ${colorPickerHtml(n.kleur || '#FEF3C7', App.noteColors)}
    </div>
    ${isEdit ? attachmentsHtml('note_id', n.id) : ''}
  `, `
    ${isEdit ? `<button class="btn btn-danger" onclick="deleteNote('${n.id}')"><i class="fas fa-trash"></i></button>` : ''}
    <button class="btn btn-outline" onclick="closeModal()">Annuleren</button>
    <button class="btn btn-primary" onclick="saveNote('${n.id || ''}')">${isEdit ? 'Opslaan' : 'Aanmaken'}</button>
  `);
  if (isEdit) loadAttachments('note_id', n.id);
}

async function saveNote(id) {
  const titel = document.getElementById('note-titel').value.trim();
  if (!titel) { toast('Voer een titel in', 'error'); return; }
  const kleur = getSelectedColor(document.getElementById('modal-body'));
  const body = {
    titel,
    inhoud: document.getElementById('note-inhoud').value,
    project_id: document.getElementById('note-project').value || null,
    kleur,
  };
  const result = id
    ? await api(`/api/notes/${id}`, { method: 'PUT', body })
    : await api('/api/notes', { method: 'POST', body });
  if (result) {
    closeModal();
    toast(id ? 'Notitie bijgewerkt' : 'Notitie aangemaakt', 'success');
    loadNotes();
  }
}

async function deleteNote(id) {
  if (!confirm('Weet je zeker dat je deze notitie wilt verwijderen?')) return;
  await api(`/api/notes/${id}`, { method: 'DELETE' });
  closeModal();
  toast('Notitie verwijderd', 'success');
  loadNotes();
}
