// ========== Calendar ==========
let calendarInstance = null;

async function renderCalendar() {
  const container = document.getElementById('view-calendar');

  container.innerHTML = `
    <div class="page-header">
      <h2>Content Kalender</h2>
      <div class="page-header-actions">
        <button class="btn btn-primary" onclick="openCalendarModal()"><i class="fas fa-plus"></i> Nieuw item</button>
      </div>
    </div>
    <div class="filters-bar">
      <select id="cal-filter-type" onchange="calendarInstance && calendarInstance.refetchEvents()">
        <option value="">Alle types</option>
        <option value="content">Content</option>
        <option value="deadline">Deadline</option>
        <option value="meeting">Meeting</option>
        <option value="social">Social media</option>
        <option value="email">Email</option>
        <option value="blog">Blog</option>
      </select>
      <select id="cal-filter-project" onchange="calendarInstance && calendarInstance.refetchEvents()">
        <option value="">Alle projecten</option>
        ${App.projects.map(p => `<option value="${p.id}">${p.naam}</option>`).join('')}
      </select>
    </div>
    <div id="calendar-container"></div>
  `;

  const calEl = document.getElementById('calendar-container');
  calendarInstance = new FullCalendar.Calendar(calEl, {
    locale: 'nl',
    initialView: 'dayGridMonth',
    headerToolbar: {
      left: 'prev,next today',
      center: 'title',
      right: 'dayGridMonth,timeGridWeek,timeGridDay'
    },
    firstDay: 1,
    editable: true,
    selectable: true,
    eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
    slotLabelFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
    events: fetchCalendarEvents,
    dateClick: (info) => {
      openCalendarModal(null, info.dateStr);
    },
    eventClick: (info) => {
      openCalendarEditModal(info.event.id);
    },
    eventDrop: async (info) => {
      await api(`/api/calendar/${info.event.id}`, { method: 'PUT', body: {
        titel: info.event.title,
        datum_start: info.event.startStr,
        datum_eind: info.event.endStr || null,
        type: info.event.extendedProps.type,
        beschrijving: info.event.extendedProps.beschrijving,
        project_id: info.event.extendedProps.project_id,
        link: info.event.extendedProps.link,
        kleur: info.event.backgroundColor,
      }});
      toast('Item verplaatst', 'success');
    },
    eventResize: async (info) => {
      await api(`/api/calendar/${info.event.id}`, { method: 'PUT', body: {
        titel: info.event.title,
        datum_start: info.event.startStr,
        datum_eind: info.event.endStr || null,
        type: info.event.extendedProps.type,
        beschrijving: info.event.extendedProps.beschrijving,
        project_id: info.event.extendedProps.project_id,
        link: info.event.extendedProps.link,
        kleur: info.event.backgroundColor,
      }});
    },
  });
  calendarInstance.render();
}

async function fetchCalendarEvents(info, successCallback) {
  const params = new URLSearchParams({
    start: info.startStr,
    end: info.endStr,
  });
  const type = document.getElementById('cal-filter-type')?.value;
  const project = document.getElementById('cal-filter-project')?.value;
  if (type) params.set('type', type);
  if (project) params.set('project_id', project);

  const items = await api(`/api/calendar?${params}`);
  if (!items) { successCallback([]); return; }

  const typeColors = {
    content: '#3B82F6', deadline: '#EF4444', meeting: '#8B5CF6',
    social: '#EC4899', email: '#F59E0B', blog: '#10B981',
  };

  successCallback(items.map(item => ({
    id: item.id,
    title: (item.link ? '🔗 ' : '') + item.titel,
    start: item.datum_start,
    end: item.datum_eind || undefined,
    backgroundColor: item.kleur || typeColors[item.type] || '#3B82F6',
    borderColor: item.kleur || typeColors[item.type] || '#3B82F6',
    extendedProps: {
      beschrijving: item.beschrijving,
      type: item.type,
      project_id: item.project_id,
      project_naam: item.project_naam,
      link: item.link,
    },
  })));
}

const calTypeColors = {
  content: '#3B82F6', deadline: '#EF4444', meeting: '#8B5CF6',
  social: '#EC4899', email: '#F59E0B', blog: '#10B981',
};

function openCalendarModal(item, dateStr) {
  const c = item || {};
  const isEdit = !!c.id;

  // Detect if it's a Google Drive link
  const isGDrive = c.link && (c.link.includes('drive.google.com') || c.link.includes('docs.google.com'));

  openModal(isEdit ? 'Item bewerken' : 'Nieuw kalender item', `
    <div class="form-group">
      <label>Titel</label>
      <div class="input-with-emoji">
        <input type="text" id="cal-titel" value="${escHtml(c.titel || '')}" required style="padding-right: 36px;">
        <button type="button" class="btn-icon emoji-trigger" onclick="toggleEmojiPicker('cal-title-emoji')" title="Emoji"><i class="far fa-smile"></i></button>
        ${createEmojiPicker('cal-titel', 'cal-title-emoji')}
      </div>
    </div>
    <div class="form-group form-group-with-emoji">
      <label>Beschrijving</label>
      <div class="input-with-emoji">
        <textarea id="cal-beschrijving" rows="3" style="padding-right: 36px;">${escHtml(c.beschrijving || '')}</textarea>
        <button type="button" class="btn-icon emoji-trigger" onclick="toggleEmojiPicker('cal-desc-emoji')" title="Emoji"><i class="far fa-smile"></i></button>
        ${createEmojiPicker('cal-beschrijving', 'cal-desc-emoji')}
      </div>
    </div>

    <div class="form-group">
      <label><i class="fab fa-google-drive" style="color:#ea484b"></i> Google Drive / Link</label>
      <div class="link-input-wrapper">
        <input type="url" id="cal-link" value="${escHtml(c.link || '')}" placeholder="https://drive.google.com/drive/folders/...">
        ${c.link ? `<a href="${escHtml(c.link)}" target="_blank" class="link-open-btn" title="Open link"><i class="fas fa-external-link-alt"></i></a>` : ''}
      </div>
      <div class="link-hint">Plak hier een Google Drive folder-link, document-link of andere URL</div>
      ${isEdit && c.link ? `
      <div class="link-preview ${isGDrive ? 'gdrive' : ''}">
        <i class="${isGDrive ? 'fab fa-google-drive' : 'fas fa-link'}"></i>
        <a href="${escHtml(c.link)}" target="_blank">${isGDrive ? 'Open in Google Drive' : 'Open link'}</a>
      </div>` : ''}
    </div>

    <div class="form-row">
      <div class="form-group">
        <label>Type</label>
        <select id="cal-type" onchange="updateCalColor()">
          ${['content','deadline','meeting','social','email','blog'].map(t =>
            `<option value="${t}" ${c.type===t?'selected':''}>${t==='social'?'Social media':t.charAt(0).toUpperCase()+t.slice(1)}</option>`
          ).join('')}
        </select>
      </div>
      <div class="form-group">
        <label>Project</label>
        <select id="cal-project">${projectSelectOptions(c.project_id)}</select>
      </div>
    </div>
    <div class="form-row">
      <div class="form-group">
        <label>Start datum</label>
        <input type="datetime-local" id="cal-start" value="${c.datum_start || (dateStr ? dateStr + 'T09:00' : '')}">
      </div>
      <div class="form-group">
        <label>Eind datum</label>
        <input type="datetime-local" id="cal-eind" value="${c.datum_eind || ''}">
      </div>
    </div>
    <div class="form-group">
      <label>Kleur</label>
      ${colorPickerHtml(c.kleur || '#3B82F6', Object.values(calTypeColors))}
    </div>
    ${isEdit ? attachmentsHtml('calendar_item_id', c.id) : ''}
  `, `
    ${isEdit && c.link ? `<a href="${escHtml(c.link)}" target="_blank" class="btn btn-outline"><i class="${isGDrive ? 'fab fa-google-drive' : 'fas fa-external-link-alt'}"></i> ${isGDrive ? 'Google Drive' : 'Open link'}</a>` : ''}
    ${isEdit ? `<button class="btn btn-danger" onclick="deleteCalendarItem('${c.id}')"><i class="fas fa-trash"></i></button>` : ''}
    <button class="btn btn-outline" onclick="closeModal()">Annuleren</button>
    <button class="btn btn-primary" onclick="saveCalendarItem('${c.id || ''}')">${isEdit ? 'Opslaan' : 'Aanmaken'}</button>
  `);
  if (isEdit) loadAttachments('calendar_item_id', c.id);
}

function updateCalColor() {
  const type = document.getElementById('cal-type').value;
  const color = calTypeColors[type] || '#3B82F6';
  const colorOpts = document.querySelectorAll('#modal-body .color-option');
  colorOpts.forEach(o => {
    o.classList.toggle('active', o.dataset.color === color);
  });
}

async function openCalendarEditModal(id) {
  const items = await api(`/api/calendar?start=2000-01-01&end=2100-01-01`);
  const item = items?.find(i => i.id === id);
  if (item) openCalendarModal(item);
}

async function saveCalendarItem(id) {
  const titel = document.getElementById('cal-titel').value.trim();
  if (!titel) { toast('Voer een titel in', 'error'); return; }
  const kleur = getSelectedColor(document.getElementById('modal-body'));
  const body = {
    titel,
    beschrijving: document.getElementById('cal-beschrijving').value,
    type: document.getElementById('cal-type').value,
    project_id: document.getElementById('cal-project').value || null,
    datum_start: document.getElementById('cal-start').value,
    datum_eind: document.getElementById('cal-eind').value || null,
    link: document.getElementById('cal-link').value.trim() || null,
    kleur,
  };
  if (!body.datum_start) { toast('Voer een startdatum in', 'error'); return; }
  const result = id
    ? await api(`/api/calendar/${id}`, { method: 'PUT', body })
    : await api('/api/calendar', { method: 'POST', body });
  if (result) {
    closeModal();
    toast(id ? 'Item bijgewerkt' : 'Item aangemaakt', 'success');
    if (calendarInstance) calendarInstance.refetchEvents();
  }
}

async function deleteCalendarItem(id) {
  if (!confirm('Weet je zeker dat je dit item wilt verwijderen?')) return;
  await api(`/api/calendar/${id}`, { method: 'DELETE' });
  closeModal();
  toast('Item verwijderd', 'success');
  if (calendarInstance) calendarInstance.refetchEvents();
}
