// ========== Global State ==========
const App = {
  currentUser: null,
  currentView: 'dashboard',
  users: [],
  projects: [],
  tasks: [],
  colors: ['#3B82F6','#EF4444','#10B981','#F59E0B','#8B5CF6','#EC4899','#06B6D4','#F97316','#6366F1','#14B8A6','#E11D48','#84CC16'],
  noteColors: ['#FEF3C7','#DBEAFE','#D1FAE5','#FEE2E2','#EDE9FE','#FCE7F3','#CFFAFE','#FFF7ED'],
};

// ========== Locale ==========
const NL = {
  dateFormat: { day: '2-digit', month: '2-digit', year: 'numeric' },
  dateTimeFormat: { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' },
};

function formatDate(str) {
  if (!str) return '-';
  return new Date(str).toLocaleDateString('nl-NL', NL.dateFormat);
}

function escHtml(str) {
  if (!str) return '';
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}
function formatDateTime(str) {
  if (!str) return '-';
  return new Date(str).toLocaleDateString('nl-NL', NL.dateTimeFormat);
}
function toInputDate(str) {
  if (!str) return '';
  return str.split('T')[0];
}
function todayStr() {
  return new Date().toISOString().split('T')[0];
}

// ========== API Helper ==========
async function api(url, options = {}) {
  const res = await fetch(url, {
    headers: { 'Content-Type': 'application/json', ...options.headers },
    ...options,
    body: options.body ? JSON.stringify(options.body) : undefined,
  });
  if (res.status === 401 && !url.includes('/auth/')) {
    showLogin();
    return null;
  }
  const data = await res.json();
  if (!res.ok) {
    toast(data.error || 'Er ging iets mis', 'error');
    return null;
  }
  return data;
}

async function apiUpload(url, formData) {
  const res = await fetch(url, { method: 'POST', body: formData });
  if (!res.ok) {
    toast('Upload mislukt', 'error');
    return null;
  }
  return await res.json();
}

// ========== Auth ==========
async function checkAuth() {
  const user = await api('/api/auth/me');
  if (user) {
    App.currentUser = user;
    showApp();
  } else {
    showLogin();
  }
}

function showLogin() {
  document.getElementById('login-screen').classList.remove('hidden');
  document.getElementById('app').classList.add('hidden');
}

function showApp() {
  document.getElementById('login-screen').classList.add('hidden');
  document.getElementById('app').classList.remove('hidden');
  document.getElementById('user-name').textContent = App.currentUser.naam;
  document.getElementById('user-role').textContent = App.currentUser.rol;
  const avatar = document.getElementById('user-avatar');
  avatar.textContent = App.currentUser.naam.charAt(0).toUpperCase();
  avatar.style.background = App.currentUser.kleur || '#3B82F6';

  // Show settings only for admin
  document.getElementById('nav-settings').style.display = App.currentUser.rol === 'admin' ? '' : 'none';

  loadGlobalData().then(() => {
    navigateTo('dashboard');
    // Start global notification polling for unread count
    startGlobalNotificationPolling();
  });
}

// ========== Global Notification Polling ==========
let globalNotificationInterval = null;

function startGlobalNotificationPolling() {
  // Initial fetch
  if (typeof fetchUnreadCount === 'function') {
    fetchUnreadCount();
  }

  // Poll every 30 seconds when not in chat view
  if (globalNotificationInterval) clearInterval(globalNotificationInterval);
  globalNotificationInterval = setInterval(() => {
    if (App.currentView !== 'chat' && typeof fetchUnreadCount === 'function') {
      fetchUnreadCount();
    }
  }, 30000);
}

async function loadGlobalData() {
  const [users, projects] = await Promise.all([
    api('/api/users'),
    api('/api/projects'),
  ]);
  if (users) App.users = users.filter(u => u.actief);
  if (projects) App.projects = projects;
}

// ========== Navigation ==========
function navigateTo(view) {
  App.currentView = view;
  document.querySelectorAll('.view').forEach(v => v.classList.add('hidden'));
  document.getElementById(`view-${view}`).classList.remove('hidden');
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.querySelector(`[data-view="${view}"]`)?.classList.add('active');

  // Stop chat polling when leaving chat view
  if (view !== 'chat' && typeof cleanupChat === 'function') {
    cleanupChat();
  }

  switch(view) {
    case 'dashboard': renderDashboard(); break;
    case 'projects': renderProjects(); break;
    case 'tasks': renderTasks(); break;
    case 'calendar': renderCalendar(); break;
    case 'notes': renderNotes(); break;
    case 'chat': renderChat(); break;
    case 'converter': renderConverter(); break;
    case 'settings': renderSettings(); break;
  }
}

// ========== Modal ==========
function openModal(title, bodyHtml, footerHtml) {
  document.getElementById('modal-title').textContent = title;
  document.getElementById('modal-body').innerHTML = bodyHtml;
  document.getElementById('modal-footer').innerHTML = footerHtml || '';
  document.getElementById('modal-overlay').classList.remove('hidden');
}

function closeModal() {
  document.getElementById('modal-overlay').classList.add('hidden');
}

// Close modal on overlay click
document.getElementById('modal-overlay').addEventListener('click', (e) => {
  if (e.target === e.currentTarget) closeModal();
});

// ========== Toast ==========
function toast(msg, type = 'info') {
  const container = document.getElementById('toast-container');
  const el = document.createElement('div');
  el.className = `toast toast-${type}`;
  el.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i> ${msg}`;
  container.appendChild(el);
  setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, 3000);
}

// ========== Color Picker HTML ==========
function colorPickerHtml(selected, colors, name = 'kleur') {
  return `<div class="color-options">${colors.map(c =>
    `<div class="color-option ${c === selected ? 'active' : ''}" style="background:${c}" data-color="${c}" onclick="selectColor(this, '${name}')"></div>`
  ).join('')}<input type="color" name="${name}" value="${selected || '#3B82F6'}" style="width:28px;height:28px;border:none;padding:0;cursor:pointer;" onchange="document.querySelectorAll('.color-option[data-color]').forEach(o=>o.classList.remove('active'));this.closest('.color-options').dataset.selected=this.value;"></div>`;
}

function selectColor(el, name) {
  el.closest('.color-options').querySelectorAll('.color-option').forEach(o => o.classList.remove('active'));
  el.classList.add('active');
  const input = el.closest('.color-options').querySelector(`input[name="${name}"]`);
  if (input) input.value = el.dataset.color;
}

function getSelectedColor(container, name = 'kleur') {
  const active = container.querySelector(`.color-options .color-option.active`);
  if (active) return active.dataset.color;
  const input = container.querySelector(`input[name="${name}"]`);
  return input ? input.value : '#3B82F6';
}

// ========== Attachments ==========
function attachmentsHtml(entityType, entityId) {
  return `
    <div class="attachments-section">
      <label style="font-size:13px;font-weight:600;">Bijlagen</label>
      <div id="attachments-list-${entityId}" class="attachments-list"></div>
      <div class="upload-area" onclick="document.getElementById('file-input-${entityId}').click()">
        <i class="fas fa-cloud-upload-alt"></i>
        Klik of sleep bestanden hierheen
      </div>
      <input type="file" id="file-input-${entityId}" style="display:none" multiple onchange="uploadFiles(this, '${entityType}', '${entityId}')">
    </div>`;
}

async function loadAttachments(entityType, entityId) {
  const atts = await api(`/api/attachments?${entityType}=${entityId}`);
  if (!atts) return;
  const list = document.getElementById(`attachments-list-${entityId}`);
  if (!list) return;
  list.innerHTML = atts.map(a => {
    const isImage = a.mimetype && a.mimetype.startsWith('image/');
    return `<div class="attachment-item">
      ${isImage ? `<img src="/uploads/${a.bestandsnaam}" alt="">` : `<i class="fas fa-file"></i>`}
      <span>${a.originele_naam}</span>
      <a href="/uploads/${a.bestandsnaam}" target="_blank" class="btn-icon"><i class="fas fa-download"></i></a>
      <button class="btn-icon" onclick="deleteAttachment('${a.id}', '${entityType}', '${entityId}')"><i class="fas fa-trash"></i></button>
    </div>`;
  }).join('');
}

async function uploadFiles(input, entityType, entityId) {
  for (const file of input.files) {
    const fd = new FormData();
    fd.append('bestand', file);
    fd.append(entityType, entityId);
    await apiUpload('/api/attachments', fd);
  }
  toast('Bestanden geüpload', 'success');
  loadAttachments(entityType, entityId);
  input.value = '';
}

async function deleteAttachment(id, entityType, entityId) {
  await api(`/api/attachments/${id}`, { method: 'DELETE' });
  loadAttachments(entityType, entityId);
}

// ========== Select helpers ==========
function projectSelectOptions(selected) {
  return `<option value="">Geen project</option>${App.projects.map(p =>
    `<option value="${p.id}" ${p.id === selected ? 'selected' : ''}>${p.naam}</option>`
  ).join('')}`;
}

function userSelectOptions(selected) {
  return `<option value="">Niemand</option>${App.users.map(u =>
    `<option value="${u.id}" ${u.id === selected ? 'selected' : ''}>${u.naam}</option>`
  ).join('')}`;
}

function userCheckboxGroup(selectedIds = []) {
  if (!App.users || App.users.length === 0) return '<div class="user-multiselect"><div class="ums-trigger" onclick="toggleUserMultiselect(this)"><span class="ums-placeholder">Geen gebruikers</span></div></div>';
  const selectedUsers = App.users.filter(u => selectedIds.includes(u.id));
  const preview = selectedUsers.length === 0
    ? '<span class="ums-placeholder">Selecteer personen...</span>'
    : `<span class="ums-badges">${selectedUsers.map(u => `<span class="ums-badge" style="background:${u.kleur || '#3B82F6'}" title="${escHtml(u.naam)}">${u.naam.charAt(0)}</span>`).join('')}</span><span class="ums-count">${selectedUsers.length} geselecteerd</span>`;
  return `<div class="user-multiselect">
    <div class="ums-trigger" onclick="toggleUserMultiselect(this)">${preview}<i class="fas fa-chevron-down ums-arrow"></i></div>
    <div class="ums-dropdown">${App.users.map(u =>
      `<label class="ums-option" onclick="event.stopPropagation()"><input type="checkbox" value="${u.id}" ${selectedIds.includes(u.id) ? 'checked' : ''} onchange="updateUserMultiselect(this)"><span class="ums-option-badge" style="background:${u.kleur || '#3B82F6'}">${u.naam.charAt(0)}</span><span class="ums-option-name">${escHtml(u.naam)}</span></label>`
    ).join('')}</div>
  </div>`;
}

function toggleUserMultiselect(trigger) {
  const dropdown = trigger.nextElementSibling;
  const isOpen = dropdown.classList.contains('open');
  document.querySelectorAll('.ums-dropdown.open').forEach(d => d.classList.remove('open'));
  if (!isOpen) dropdown.classList.add('open');
}

function updateUserMultiselect(checkbox) {
  const container = checkbox.closest('.user-multiselect');
  const checked = [...container.querySelectorAll('.ums-dropdown input:checked')];
  const trigger = container.querySelector('.ums-trigger');
  const arrow = '<i class="fas fa-chevron-down ums-arrow"></i>';
  if (checked.length === 0) {
    trigger.innerHTML = '<span class="ums-placeholder">Selecteer personen...</span>' + arrow;
  } else {
    const badges = checked.map(cb => {
      const u = App.users.find(u => u.id === cb.value);
      return u ? `<span class="ums-badge" style="background:${u.kleur || '#3B82F6'}" title="${escHtml(u.naam)}">${u.naam.charAt(0)}</span>` : '';
    }).join('');
    trigger.innerHTML = `<span class="ums-badges">${badges}</span><span class="ums-count">${checked.length} geselecteerd</span>${arrow}`;
  }
}

function getSelectedUserIds(containerId) {
  return [...document.querySelectorAll(`#${containerId} .ums-dropdown input:checked`)].map(cb => cb.value);
}

// Sluit dropdowns bij klik buiten
document.addEventListener('click', function(e) {
  if (!e.target.closest('.user-multiselect')) {
    document.querySelectorAll('.ums-dropdown.open').forEach(d => d.classList.remove('open'));
  }
});

// ========== Emoji Picker ==========
const emojiCategories = {
  'Smileys': ['😀','😃','😄','😁','😅','😂','🤣','😊','😇','🙂','😉','😌','😍','🥰','😘','😗','😋','😛','😜','🤪','😝','🤑','🤗','🤭','🤫','🤔','🤐','🤨','😐','😑','😶','😏','😒','🙄','😬','😮‍💨','🤥','😌','😔','😪','🤤','😴','😷','🤒','🤕','🤢','🤮','🤧','🥵','🥶','🥴','😵','🤯','🤠','🥳','🥸','😎','🤓','🧐','😕','😟','🙁','☹️','😮','😯','😲','😳','🥺','😦','😧','😨','😰','😥','😢','😭','😱','😖','😣','😞','😓','😩','😫','🥱','😤','😡','😠','🤬','😈','👿','💀','☠️','💩','🤡','👹','👺','👻','👽','👾','🤖'],
  'Gebaren': ['👋','🤚','🖐️','✋','🖖','👌','🤌','🤏','✌️','🤞','🤟','🤘','🤙','👈','👉','👆','🖕','👇','☝️','👍','👎','✊','👊','🤛','🤜','👏','🙌','👐','🤲','🤝','🙏','✍️','💪','🦾','🦿','🦵','🦶','👂','🦻','👃','🧠','🫀','🫁','🦷','🦴','👀','👁️','👅','👄'],
  'Mensen': ['👶','🧒','👦','👧','🧑','👱','👨','🧔','👩','🧓','👴','👵','🙍','🙎','🙅','🙆','💁','🙋','🧏','🙇','🤦','🤷','👮','🕵️','💂','🥷','👷','🤴','👸','👳','👲','🧕','🤵','👰','🤰','🤱','👼','🎅','🤶','🦸','🦹','🧙','🧚','🧛','🧜','🧝','🧞','🧟','💆','💇','🚶','🧍','🧎','🏃','💃','🕺','👯','🧖','🧗','🤸','🏌️','🏇','⛷️','🏂','🏋️','🤼','🤽','🤾','🤺','⛹️','🏊','🚣','🧘','🛀','🛌'],
  'Dieren': ['🐶','🐱','🐭','🐹','🐰','🦊','🐻','🐼','🐻‍❄️','🐨','🐯','🦁','🐮','🐷','🐽','🐸','🐵','🙈','🙉','🙊','🐒','🐔','🐧','🐦','🐤','🐣','🐥','🦆','🦅','🦉','🦇','🐺','🐗','🐴','🦄','🐝','🪱','🐛','🦋','🐌','🐞','🐜','🪰','🪲','🪳','🦟','🦗','🕷️','🦂','🐢','🐍','🦎','🦖','🦕','🐙','🦑','🦐','🦞','🦀','🐡','🐠','🐟','🐬','🐳','🐋','🦈','🐊','🐅','🐆','🦓','🦍','🦧','🦣','🐘','🦛','🦏','🐪','🐫','🦒','🦘','🦬','🐃','🐂','🐄','🐎','🐖','🐏','🐑','🦙','🐐','🦌','🐕','🐩','🦮','🐕‍🦺','🐈','🐈‍⬛','🪶','🐓','🦃','🦤','🦚','🦜','🦢','🦩','🕊️','🐇','🦝','🦨','🦡','🦫','🦦','🦥','🐁','🐀','🐿️','🦔'],
  'Eten': ['🍏','🍎','🍐','🍊','🍋','🍌','🍉','🍇','🍓','🫐','🍈','🍒','🍑','🥭','🍍','🥥','🥝','🍅','🍆','🥑','🥦','🥬','🥒','🌶️','🫑','🌽','🥕','🫒','🧄','🧅','🥔','🍠','🥐','🥯','🍞','🥖','🥨','🧀','🥚','🍳','🧈','🥞','🧇','🥓','🥩','🍗','🍖','🦴','🌭','🍔','🍟','🍕','🫓','🥪','🥙','🧆','🌮','🌯','🫔','🥗','🥘','🫕','🥫','🍝','🍜','🍲','🍛','🍣','🍱','🥟','🦪','🍤','🍙','🍚','🍘','🍥','🥠','🥮','🍢','🍡','🍧','🍨','🍦','🥧','🧁','🍰','🎂','🍮','🍭','🍬','🍫','🍿','🍩','🍪','🌰','🥜','🍯','🥛','🍼','🫖','☕','🍵','🧃','🥤','🧋','🍶','🍺','🍻','🥂','🍷','🥃','🍸','🍹','🧉','🍾','🧊','🥄','🍴','🍽️','🥣','🥡','🥢','🧂'],
  'Activiteiten': ['⚽','🏀','🏈','⚾','🥎','🎾','🏐','🏉','🥏','🎱','🪀','🏓','🏸','🏒','🏑','🥍','🏏','🪃','🥅','⛳','🪁','🏹','🎣','🤿','🥊','🥋','🎽','🛹','🛼','🛷','⛸️','🥌','🎿','⛷️','🏂','🪂','🏋️','🤼','🤸','🤺','⛹️','🤾','🏌️','🏇','🧘','🏄','🏊','🤽','🚣','🧗','🚵','🚴','🏆','🥇','🥈','🥉','🏅','🎖️','🏵️','🎗️','🎫','🎟️','🎪','🤹','🎭','🩰','🎨','🎬','🎤','🎧','🎼','🎹','🥁','🪘','🎷','🎺','🪗','🎸','🪕','🎻','🎲','♟️','🎯','🎳','🎮','🎰','🧩'],
  'Reizen': ['🚗','🚕','🚙','🚌','🚎','🏎️','🚓','🚑','🚒','🚐','🛻','🚚','🚛','🚜','🦯','🦽','🦼','🛴','🚲','🛵','🏍️','🛺','🚨','🚔','🚍','🚘','🚖','🚡','🚠','🚟','🚃','🚋','🚞','🚝','🚄','🚅','🚈','🚂','🚆','🚇','🚊','🚉','✈️','🛫','🛬','🛩️','💺','🛰️','🚀','🛸','🚁','🛶','⛵','🚤','🛥️','🛳️','⛴️','🚢','⚓','🪝','⛽','🚧','🚦','🚥','🚏','🗺️','🗿','🗽','🗼','🏰','🏯','🏟️','🎡','🎢','🎠','⛲','⛱️','🏖️','🏝️','🏜️','🌋','⛰️','🏔️','🗻','🏕️','⛺','🛖','🏠','🏡','🏘️','🏚️','🏗️','🏭','🏢','🏬','🏣','🏤','🏥','🏦','🏨','🏪','🏫','🏩','💒','🏛️','⛪','🕌','🕍','🛕','🕋','⛩️','🛤️','🛣️','🗾','🎑','🏞️','🌅','🌄','🌠','🎇','🎆','🌇','🌆','🏙️','🌃','🌌','🌉','🌁'],
  'Objecten': ['⌚','📱','📲','💻','⌨️','🖥️','🖨️','🖱️','🖲️','🕹️','🗜️','💽','💾','💿','📀','📼','📷','📸','📹','🎥','📽️','🎞️','📞','☎️','📟','📠','📺','📻','🎙️','🎚️','🎛️','🧭','⏱️','⏲️','⏰','🕰️','⌛','⏳','📡','🔋','🔌','💡','🔦','🕯️','🪔','🧯','🛢️','💸','💵','💴','💶','💷','🪙','💰','💳','💎','⚖️','🪜','🧰','🪛','🔧','🔨','⚒️','🛠️','⛏️','🪚','🔩','⚙️','🪤','🧱','⛓️','🧲','🔫','💣','🧨','🪓','🔪','🗡️','⚔️','🛡️','🚬','⚰️','🪦','⚱️','🏺','🔮','📿','🧿','💈','⚗️','🔭','🔬','🕳️','🩹','🩺','💊','💉','🩸','🧬','🦠','🧫','🧪','🌡️','🧹','🪠','🧺','🧻','🚽','🚰','🚿','🛁','🛀','🧼','🪥','🪒','🧽','🪣','🧴','🛎️','🔑','🗝️','🚪','🪑','🛋️','🛏️','🛌','🧸','🪆','🖼️','🪞','🪟','🛍️','🛒','🎁','🎈','🎏','🎀','🪄','🪅','🎊','🎉','🎎','🏮','🎐','🧧','✉️','📩','📨','📧','💌','📥','📤','📦','🏷️','🪧','📪','📫','📬','📭','📮','📯','📜','📃','📄','📑','🧾','📊','📈','📉','🗒️','🗓️','📆','📅','🗑️','📇','🗃️','🗳️','🗄️','📋','📁','📂','🗂️','🗞️','📰','📓','📔','📒','📕','📗','📘','📙','📚','📖','🔖','🧷','🔗','📎','🖇️','📐','📏','🧮','📌','📍','✂️','🖊️','🖋️','✒️','🖌️','🖍️','📝','✏️','🔍','🔎','🔏','🔐','🔒','🔓'],
  'Symbolen': ['❤️','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❣️','💕','💞','💓','💗','💖','💘','💝','💟','☮️','✝️','☪️','🕉️','☸️','✡️','🔯','🕎','☯️','☦️','🛐','⛎','♈','♉','♊','♋','♌','♍','♎','♏','♐','♑','♒','♓','🆔','⚛️','🉑','☢️','☣️','📴','📳','🈶','🈚','🈸','🈺','🈷️','✴️','🆚','💮','🉐','㊙️','㊗️','🈴','🈵','🈹','🈲','🅰️','🅱️','🆎','🆑','🅾️','🆘','❌','⭕','🛑','⛔','📛','🚫','💯','💢','♨️','🚷','🚯','🚳','🚱','🔞','📵','🚭','❗','❕','❓','❔','‼️','⁉️','🔅','🔆','〽️','⚠️','🚸','🔱','⚜️','🔰','♻️','✅','🈯','💹','❇️','✳️','❎','🌐','💠','Ⓜ️','🌀','💤','🏧','🚾','♿','🅿️','🛗','🈳','🈂️','🛂','🛃','🛄','🛅','🚹','🚺','🚼','⚧️','🚻','🚮','🎦','📶','🈁','🔣','ℹ️','🔤','🔡','🔠','🆖','🆗','🆙','🆒','🆕','🆓','0️⃣','1️⃣','2️⃣','3️⃣','4️⃣','5️⃣','6️⃣','7️⃣','8️⃣','9️⃣','🔟','🔢','#️⃣','*️⃣','⏏️','▶️','⏸️','⏯️','⏹️','⏺️','⏭️','⏮️','⏩','⏪','⏫','⏬','◀️','🔼','🔽','➡️','⬅️','⬆️','⬇️','↗️','↘️','↙️','↖️','↕️','↔️','↪️','↩️','⤴️','⤵️','🔀','🔁','🔂','🔄','🔃','🎵','🎶','➕','➖','➗','✖️','🟰','♾️','💲','💱','™️','©️','®️','〰️','➰','➿','🔚','🔙','🔛','🔝','🔜','✔️','☑️','🔘','🔴','🟠','🟡','🟢','🔵','🟣','⚫','⚪','🟤','🔺','🔻','🔸','🔹','🔶','🔷','🔳','🔲','▪️','▫️','◾','◽','◼️','◻️','🟥','🟧','🟨','🟩','🟦','🟪','⬛','⬜','🟫','🔈','🔇','🔉','🔊','🔔','🔕','📣','📢','👁️‍🗨️','💬','💭','🗯️','♠️','♣️','♥️','♦️','🃏','🎴','🀄','🕐','🕑','🕒','🕓','🕔','🕕','🕖','🕗','🕘','🕙','🕚','🕛','🕜','🕝','🕞','🕟','🕠','🕡','🕢','🕣','🕤','🕥','🕦','🕧'],
  'Vlaggen': ['🏳️','🏴','🏴‍☠️','🏁','🚩','🎌','🏳️‍🌈','🏳️‍⚧️','🇦🇫','🇦🇱','🇩🇿','🇦🇩','🇦🇴','🇦🇬','🇦🇷','🇦🇲','🇦🇺','🇦🇹','🇦🇿','🇧🇸','🇧🇭','🇧🇩','🇧🇧','🇧🇾','🇧🇪','🇧🇿','🇧🇯','🇧🇹','🇧🇴','🇧🇦','🇧🇼','🇧🇷','🇧🇳','🇧🇬','🇧🇫','🇧🇮','🇰🇭','🇨🇲','🇨🇦','🇨🇻','🇨🇫','🇹🇩','🇨🇱','🇨🇳','🇨🇴','🇰🇲','🇨🇬','🇨🇩','🇨🇷','🇨🇮','🇭🇷','🇨🇺','🇨🇾','🇨🇿','🇩🇰','🇩🇯','🇩🇲','🇩🇴','🇪🇨','🇪🇬','🇸🇻','🇬🇶','🇪🇷','🇪🇪','🇸🇿','🇪🇹','🇫🇯','🇫🇮','🇫🇷','🇬🇦','🇬🇲','🇬🇪','🇩🇪','🇬🇭','🇬🇷','🇬🇩','🇬🇹','🇬🇳','🇬🇼','🇬🇾','🇭🇹','🇭🇳','🇭🇺','🇮🇸','🇮🇳','🇮🇩','🇮🇷','🇮🇶','🇮🇪','🇮🇱','🇮🇹','🇯🇲','🇯🇵','🇯🇴','🇰🇿','🇰🇪','🇰🇮','🇽🇰','🇰🇼','🇰🇬','🇱🇦','🇱🇻','🇱🇧','🇱🇸','🇱🇷','🇱🇾','🇱🇮','🇱🇹','🇱🇺','🇲🇬','🇲🇼','🇲🇾','🇲🇻','🇲🇱','🇲🇹','🇲🇭','🇲🇷','🇲🇺','🇲🇽','🇫🇲','🇲🇩','🇲🇨','🇲🇳','🇲🇪','🇲🇦','🇲🇿','🇲🇲','🇳🇦','🇳🇷','🇳🇵','🇳🇱','🇳🇿','🇳🇮','🇳🇪','🇳🇬','🇰🇵','🇲🇰','🇳🇴','🇴🇲','🇵🇰','🇵🇼','🇵🇸','🇵🇦','🇵🇬','🇵🇾','🇵🇪','🇵🇭','🇵🇱','🇵🇹','🇶🇦','🇷🇴','🇷🇺','🇷🇼','🇼🇸','🇸🇲','🇸🇹','🇸🇦','🇸🇳','🇷🇸','🇸🇨','🇸🇱','🇸🇬','🇸🇰','🇸🇮','🇸🇧','🇸🇴','🇿🇦','🇰🇷','🇸🇸','🇪🇸','🇱🇰','🇸🇩','🇸🇷','🇸🇪','🇨🇭','🇸🇾','🇹🇼','🇹🇯','🇹🇿','🇹🇭','🇹🇱','🇹🇬','🇹🇴','🇹🇹','🇹🇳','🇹🇷','🇹🇲','🇹🇻','🇺🇬','🇺🇦','🇦🇪','🇬🇧','🇺🇸','🇺🇾','🇺🇿','🇻🇺','🇻🇦','🇻🇪','🇻🇳','🇾🇪','🇿🇲','🇿🇼']
};

// Frequently used emojis (stored in localStorage)
function getRecentEmojis() {
  try {
    return JSON.parse(localStorage.getItem('recentEmojis') || '[]').slice(0, 24);
  } catch { return []; }
}

function addRecentEmoji(emoji) {
  let recent = getRecentEmojis();
  recent = [emoji, ...recent.filter(e => e !== emoji)].slice(0, 24);
  localStorage.setItem('recentEmojis', JSON.stringify(recent));
}

// Create emoji picker HTML for a specific input
function createEmojiPicker(targetInputId, pickerId) {
  const recent = getRecentEmojis();
  const categoryNames = Object.keys(emojiCategories);

  return `
    <div class="emoji-picker" id="${pickerId}">
      <div class="emoji-picker-header">
        <input type="text" class="emoji-search" placeholder="Zoek emoji..." oninput="filterEmojis('${pickerId}', this.value)">
      </div>
      <div class="emoji-picker-tabs">
        ${recent.length > 0 ? `<button class="emoji-tab active" data-category="recent" onclick="showEmojiCategory('${pickerId}', 'recent', this)">🕐</button>` : ''}
        <button class="emoji-tab ${recent.length === 0 ? 'active' : ''}" data-category="Smileys" onclick="showEmojiCategory('${pickerId}', 'Smileys', this)">😀</button>
        <button class="emoji-tab" data-category="Gebaren" onclick="showEmojiCategory('${pickerId}', 'Gebaren', this)">👋</button>
        <button class="emoji-tab" data-category="Mensen" onclick="showEmojiCategory('${pickerId}', 'Mensen', this)">👨</button>
        <button class="emoji-tab" data-category="Dieren" onclick="showEmojiCategory('${pickerId}', 'Dieren', this)">🐶</button>
        <button class="emoji-tab" data-category="Eten" onclick="showEmojiCategory('${pickerId}', 'Eten', this)">🍕</button>
        <button class="emoji-tab" data-category="Activiteiten" onclick="showEmojiCategory('${pickerId}', 'Activiteiten', this)">⚽</button>
        <button class="emoji-tab" data-category="Reizen" onclick="showEmojiCategory('${pickerId}', 'Reizen', this)">🚗</button>
        <button class="emoji-tab" data-category="Objecten" onclick="showEmojiCategory('${pickerId}', 'Objecten', this)">💡</button>
        <button class="emoji-tab" data-category="Symbolen" onclick="showEmojiCategory('${pickerId}', 'Symbolen', this)">❤️</button>
        <button class="emoji-tab" data-category="Vlaggen" onclick="showEmojiCategory('${pickerId}', 'Vlaggen', this)">🏳️</button>
      </div>
      <div class="emoji-picker-content" data-target="${targetInputId}">
        ${recent.length > 0 ? `
          <div class="emoji-category" data-category="recent">
            <div class="emoji-category-title">Recent gebruikt</div>
            <div class="emoji-grid">
              ${recent.map(e => `<button class="emoji-btn" onclick="insertEmoji('${targetInputId}', '${e}', '${pickerId}')">${e}</button>`).join('')}
            </div>
          </div>
        ` : ''}
        ${categoryNames.map((cat, i) => `
          <div class="emoji-category ${(recent.length === 0 && i === 0) || (recent.length > 0 && i > 0) ? '' : ''}" data-category="${cat}" style="${(recent.length > 0 || i > 0) ? 'display:none' : ''}">
            <div class="emoji-category-title">${cat}</div>
            <div class="emoji-grid">
              ${emojiCategories[cat].map(e => `<button class="emoji-btn" onclick="insertEmoji('${targetInputId}', '${e}', '${pickerId}')">${e}</button>`).join('')}
            </div>
          </div>
        `).join('')}
      </div>
    </div>
  `;
}

function showEmojiCategory(pickerId, category, tabEl) {
  const picker = document.getElementById(pickerId);
  if (!picker) return;

  // Update tabs
  picker.querySelectorAll('.emoji-tab').forEach(t => t.classList.remove('active'));
  tabEl.classList.add('active');

  // Show selected category
  picker.querySelectorAll('.emoji-category').forEach(c => {
    c.style.display = c.dataset.category === category ? '' : 'none';
  });

  // Clear search
  const searchInput = picker.querySelector('.emoji-search');
  if (searchInput) searchInput.value = '';
}

function filterEmojis(pickerId, query) {
  const picker = document.getElementById(pickerId);
  if (!picker) return;

  const q = query.toLowerCase().trim();

  if (!q) {
    // Show first category when search is cleared
    picker.querySelectorAll('.emoji-category').forEach((c, i) => {
      c.style.display = i === 0 ? '' : 'none';
    });
    picker.querySelectorAll('.emoji-tab').forEach((t, i) => {
      t.classList.toggle('active', i === 0);
    });
    return;
  }

  // Hide all categories and tabs
  picker.querySelectorAll('.emoji-tab').forEach(t => t.classList.remove('active'));

  // Show all matching emojis
  picker.querySelectorAll('.emoji-category').forEach(cat => {
    const btns = cat.querySelectorAll('.emoji-btn');
    let hasVisible = false;
    btns.forEach(btn => {
      // Simple search - show if emoji exists in query or category name contains query
      const catName = cat.dataset.category.toLowerCase();
      const matches = catName.includes(q);
      btn.style.display = matches ? '' : 'none';
      if (matches) hasVisible = true;
    });
    cat.style.display = hasVisible ? '' : 'none';
  });
}

function insertEmoji(targetInputId, emoji, pickerId) {
  const input = document.getElementById(targetInputId);
  if (!input) return;

  const start = input.selectionStart || 0;
  const end = input.selectionEnd || 0;
  const text = input.value;

  input.value = text.substring(0, start) + emoji + text.substring(end);
  input.focus();

  const newPos = start + emoji.length;
  input.setSelectionRange(newPos, newPos);

  // Trigger input event for any listeners
  input.dispatchEvent(new Event('input', { bubbles: true }));

  // Add to recent
  addRecentEmoji(emoji);

  // Close picker
  toggleEmojiPicker(pickerId, false);
}

function toggleEmojiPicker(pickerId, show) {
  const picker = document.getElementById(pickerId);
  if (!picker) return;

  if (show === undefined) {
    picker.classList.toggle('hidden');
  } else {
    picker.classList.toggle('hidden', !show);
  }
}

// Helper to create emoji button for inputs
function emojiButtonHtml(targetInputId, pickerId) {
  return `
    <button type="button" class="btn-icon emoji-trigger" onclick="toggleEmojiPicker('${pickerId}')" title="Emoji invoegen">
      <i class="far fa-smile"></i>
    </button>
  `;
}

// Close emoji picker when clicking outside
document.addEventListener('click', (e) => {
  if (!e.target.closest('.emoji-picker') && !e.target.closest('.emoji-trigger')) {
    document.querySelectorAll('.emoji-picker').forEach(p => p.classList.add('hidden'));
  }
});

// ========== Init ==========
document.getElementById('login-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const email = document.getElementById('login-email').value;
  const wachtwoord = document.getElementById('login-password').value;
  const user = await api('/api/auth/login', { method: 'POST', body: { email, wachtwoord } });
  if (user) {
    App.currentUser = user;
    showApp();
  } else {
    document.getElementById('login-error').textContent = 'Ongeldige inloggegevens';
  }
});

document.getElementById('logout-btn').addEventListener('click', async () => {
  await api('/api/auth/logout', { method: 'POST' });
  App.currentUser = null;
  showLogin();
});

document.querySelectorAll('.nav-item[data-view]').forEach(el => {
  el.addEventListener('click', (e) => {
    e.preventDefault();
    navigateTo(el.dataset.view);
  });
});

// Keyboard shortcut: Escape closes modal
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') closeModal();
});

// Start
checkAuth();
