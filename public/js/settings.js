// ========== Settings ==========
async function renderSettings() {
  const container = document.getElementById('view-settings');
  const [users, aiSettings] = await Promise.all([
    api('/api/users'),
    api('/api/settings/ai/openai'),
  ]);
  if (!users || !aiSettings) return;

  container.innerHTML = `
    <div class="page-header">
      <h2>Instellingen</h2>
    </div>

    <div class="settings-section">
      <h3>AI-koppelingen</h3>
      <div class="ai-settings-card">
        <div class="ai-settings-brand">
          <div class="ai-settings-logo">AI</div>
          <div>
            <h4>OpenAI — productfoto's</h4>
            <p>Voor twee bereide en twee rauwe productfoto's met ${escHtml(aiSettings.model)}.</p>
          </div>
          <span class="ai-connection-badge ${aiSettings.actief ? 'connected' : 'inactive'}">
            <i class="fas fa-${aiSettings.actief ? 'check-circle' : 'flask'}"></i>
            ${aiSettings.actief ? 'Actief' : 'Voorbeeldmodus'}
          </span>
        </div>

        <div class="ai-settings-body">
          <div class="ai-settings-current">
            <span>Huidige sleutel</span>
            <strong>${aiSettings.ingesteld ? escHtml(aiSettings.weergave) : 'Nog niet ingesteld'}</strong>
            ${aiSettings.bron === 'server' ? '<small>Beheerd via de server</small>' : ''}
          </div>

          <div class="ai-key-form">
            <label for="openai-api-key">Nieuwe OpenAI API-sleutel</label>
            <div class="ai-key-input-row">
              <input id="openai-api-key" type="password" autocomplete="new-password" spellcheck="false"
                placeholder="sk-..." aria-describedby="openai-key-help">
              <button class="btn btn-primary" type="button" onclick="saveOpenAiKey()">
                <i class="fas fa-shield-halved"></i> Opslaan en testen
              </button>
            </div>
            <p id="openai-key-help">De sleutel wordt versleuteld opgeslagen en daarna nooit meer volledig getoond.</p>
          </div>

          <div class="ai-settings-actions">
            <a class="btn btn-outline" href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">
              <i class="fas fa-key"></i> API-sleutel maken
            </a>
            ${aiSettings.ingesteld ? `
              <button class="btn btn-outline" type="button" onclick="testOpenAiConnection()">
                <i class="fas fa-plug-circle-check"></i> Verbinding testen
              </button>
              ${aiSettings.bron === 'app' ? `
                <button class="btn btn-outline btn-danger-outline" type="button" onclick="deleteOpenAiKey()">
                  <i class="fas fa-trash"></i> Sleutel verwijderen
                </button>
              ` : ''}
            ` : ''}
          </div>
        </div>
      </div>
    </div>

    <div class="settings-section">
      <h3>Gebruikersbeheer</h3>
      <button class="btn btn-primary" onclick="openUserModal()" style="margin-bottom:16px"><i class="fas fa-plus"></i> Nieuwe gebruiker</button>
      <div class="settings-table-scroll">
      <table class="users-table">
        <thead>
          <tr>
            <th>Naam</th>
            <th>Email</th>
            <th>Rol</th>
            <th>Kleur</th>
            <th>Status</th>
            <th>Acties</th>
          </tr>
        </thead>
        <tbody>
          ${users.map(u => `
            <tr>
              <td>
                <div style="display:flex;align-items:center;gap:8px">
                  <div class="user-avatar" style="background:${u.kleur || '#3B82F6'};width:28px;height:28px;font-size:12px">${u.naam.charAt(0).toUpperCase()}</div>
                  ${escHtml(u.naam)}
                </div>
              </td>
              <td>${escHtml(u.email)}</td>
              <td><span class="role-badge role-${u.rol}">${u.rol}</span></td>
              <td><div style="width:20px;height:20px;border-radius:50%;background:${u.kleur || '#3B82F6'}"></div></td>
              <td>${u.actief ? '<span style="color:var(--success)">Actief</span>' : '<span style="color:var(--text-light)">Inactief</span>'}</td>
              <td>
                <button class="btn btn-sm btn-outline" onclick='openUserModal(${escAttr(JSON.stringify(u))})'><i class="fas fa-pen"></i></button>
                ${u.id !== App.currentUser.id ? `<button class="btn btn-sm btn-outline" onclick="toggleUserActive('${u.id}', ${u.actief ? 0 : 1})"><i class="fas fa-${u.actief ? 'ban' : 'check'}"></i></button>` : ''}
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>
      </div>
    </div>
  `;
}

async function saveOpenAiKey() {
  const input = document.getElementById('openai-api-key');
  const apiKey = input?.value.trim();
  if (!apiKey) {
    toast('Plak eerst je OpenAI API-sleutel', 'error');
    input?.focus();
    return;
  }

  const result = await api('/api/settings/ai/openai', {
    method: 'PUT',
    body: { api_key: apiKey },
  });

  if (!result) return;
  input.value = '';
  toast(result.bericht, result.verbonden ? 'success' : 'info');
  await renderSettings();
}

async function testOpenAiConnection() {
  const result = await api('/api/settings/ai/openai/test', { method: 'POST' });
  if (!result) return;
  toast(result.bericht, result.verbonden ? 'success' : 'info');
}

async function deleteOpenAiKey() {
  if (!confirm('Weet je zeker dat je de OpenAI API-sleutel wilt verwijderen? De productfoto-generator gaat dan terug naar de voorbeeldmodus.')) return;

  const result = await api('/api/settings/ai/openai', { method: 'DELETE' });
  if (!result) return;
  toast(result.bericht, 'success');
  await renderSettings();
}

function openUserModal(user) {
  const u = user || {};
  const isEdit = !!u.id;
  openModal(isEdit ? 'Gebruiker bewerken' : 'Nieuwe gebruiker', `
    <div class="form-group">
      <label>Naam</label>
      <input type="text" id="user-naam" value="${escHtml(u.naam || '')}" required>
    </div>
    <div class="form-group">
      <label>Email</label>
      <input type="email" id="user-email" value="${escHtml(u.email || '')}" required>
    </div>
    <div class="form-group">
      <label>${isEdit ? 'Nieuw wachtwoord (leeg = niet wijzigen)' : 'Wachtwoord'}</label>
      <input type="password" id="user-wachtwoord" ${isEdit ? '' : 'required'}>
    </div>
    <div class="form-group">
      <label>Rol</label>
      <select id="user-rol">
        <option value="lid" ${u.rol === 'lid' ? 'selected' : ''}>Lid</option>
        <option value="manager" ${u.rol === 'manager' ? 'selected' : ''}>Manager</option>
        <option value="admin" ${u.rol === 'admin' ? 'selected' : ''}>Admin</option>
      </select>
    </div>
    <div class="form-group">
      <label>Kleur</label>
      ${colorPickerHtml(u.kleur || '#3B82F6', App.colors)}
    </div>
  `, `
    <button class="btn btn-outline" onclick="closeModal()">Annuleren</button>
    <button class="btn btn-primary" onclick="saveUser('${u.id || ''}')">${isEdit ? 'Opslaan' : 'Aanmaken'}</button>
  `);
}

async function saveUser(id) {
  const naam = document.getElementById('user-naam').value.trim();
  const email = document.getElementById('user-email').value.trim();
  const wachtwoord = document.getElementById('user-wachtwoord').value;
  const rol = document.getElementById('user-rol').value;
  const kleur = getSelectedColor(document.getElementById('modal-body'));

  if (!naam || !email) { toast('Vul alle velden in', 'error'); return; }
  if (!id && !wachtwoord) { toast('Voer een wachtwoord in', 'error'); return; }

  const body = { naam, email, rol, kleur, actief: true };
  if (wachtwoord) body.wachtwoord = wachtwoord;

  const result = id
    ? await api(`/api/users/${id}`, { method: 'PUT', body })
    : await api('/api/users', { method: 'POST', body });

  if (result) {
    closeModal();
    toast(id ? 'Gebruiker bijgewerkt' : 'Gebruiker aangemaakt', 'success');
    await loadGlobalData();
    renderSettings();
  }
}

async function toggleUserActive(id, actief) {
  const users = await api('/api/users');
  const user = users?.find(u => u.id === id);
  if (!user) return;
  await api(`/api/users/${id}`, { method: 'PUT', body: {
    naam: user.naam, email: user.email, rol: user.rol, kleur: user.kleur, actief: !!actief,
  }});
  toast(actief ? 'Gebruiker geactiveerd' : 'Gebruiker gedeactiveerd', 'success');
  await loadGlobalData();
  renderSettings();
}
