// ========== Settings ==========
async function renderSettings() {
  const container = document.getElementById('view-settings');
  const users = await api('/api/users');
  if (!users) return;

  container.innerHTML = `
    <div class="page-header">
      <h2>Instellingen</h2>
    </div>

    <div class="settings-section">
      <h3>Gebruikersbeheer</h3>
      <button class="btn btn-primary" onclick="openUserModal()" style="margin-bottom:16px"><i class="fas fa-plus"></i> Nieuwe gebruiker</button>
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
  `;
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
