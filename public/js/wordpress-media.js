// Media-only bridge: never send credentials to WordPress from the browser.
const WordPressMedia = {
  states: new Map(),
  busy: new Set(),
  batch: false,

  async settings() {
    const node = document.getElementById('wordpress-settings');
    const owner = App.currentUser?.id;
    if (!node || App.currentUser?.rol !== 'admin') return;
    const data = await api('/api/settings/wordpress');
    if (!data || !node.isConnected || App.currentUser?.id !== owner) return;
    node.innerHTML = `<h3>WordPress — mediatheek</h3>
      <p><strong>${data.destination === 'local' ? 'Lokale testwebsite' : 'Echte webshop'}: ${escHtml(data.url)}</strong></p>
      <p>Alleen foto's en SEO naar de mediatheek. Geen productteksten, productkoppelingen of publicatie van pagina's. Media op de echte webshop zijn wel via hun eigen URL openbaar.</p>
      ${!data.available ? '<p>De database-uitbreiding is nog niet toegepast.</p>' : `
      <p>${data.configured ? 'Applicatiewachtwoord versleuteld opgeslagen.' : 'Nog geen applicatiewachtwoord ingesteld.'} ${data.enabled ? 'Uploads actief.' : 'Uploads uitgeschakeld.'}</p>
      <div class="form-group"><label for="wordpress-username">WordPress-gebruikersnaam</label><input id="wordpress-username" value="${escHtml(data.username)}" autocomplete="off"></div>
      <div class="form-group"><label for="wordpress-password">WordPress-applicatiewachtwoord (niet het gewone inlogwachtwoord)</label><input id="wordpress-password" type="password" autocomplete="new-password" placeholder="Plak hier je applicatiewachtwoord; leeg laten behoudt het bestaande" spellcheck="false"></div>
      <p>Gebruik de rol ‘Pitboard — alleen media’ uit de meegeleverde plugin. Het wachtwoord wordt nooit teruggestuurd naar de browser.</p>
      <label><input type="checkbox" id="wordpress-public"> Ik begrijp dat uploads op de echte webshop een openbare media-URL krijgen.</label><br>
      <label><input type="checkbox" id="wordpress-enabled" ${data.enabled ? 'checked' : ''} ${!data.tested_at ? 'disabled' : ''}> Uploadknoppen activeren voor deze omgeving (pas na geslaagde test)</label>
      <div class="form-actions"><button class="btn btn-primary" onclick="WordPressMedia.saveSettings(this)">Opslaan</button>
      <button class="btn btn-outline" onclick="WordPressMedia.test(this)" ${!data.configured ? 'disabled' : ''}>Verbinding en rechten testen</button>
      ${data.configured ? '<button class="btn btn-outline" onclick="WordPressMedia.disconnect()">Verbinding uitzetten en wachtwoord verwijderen</button>' : ''}</div>`}`;
  },

  async saveSettings(button) {
    if (!document.getElementById('wordpress-public').checked) return toast('Bevestig eerst de uitleg over openbare media.', 'error');
    button.disabled = true;
    const input = document.getElementById('wordpress-password');
    const body = { username: document.getElementById('wordpress-username').value.trim(), password: input.value,
      enabled: document.getElementById('wordpress-enabled').checked, confirm_public: true };
    input.value = '';
    try {
      const data = await api('/api/settings/wordpress', { method: 'PUT', body });
      if (data) { toast(data.enabled ? 'Uploadknoppen actief voor deze omgeving.' : 'Opgeslagen. Test de verbinding voordat je uploads activeert.', 'success'); await this.settings(); }
    } finally { body.password = ''; button.disabled = false; }
  },

  async test(button) {
    button.disabled = true;
    try {
      const data = await api('/api/settings/wordpress/test', { method: 'POST', body: {} });
      if (data) toast(data.message, 'success');
      await this.settings();
    } finally { button.disabled = false; }
  },

  async disconnect() {
    if (!confirm('De koppeling uitzetten en het opgeslagen wachtwoord verwijderen? Reeds verzonden foto’s blijven in WordPress.')) return;
    const data = await api('/api/settings/wordpress', { method: 'DELETE' });
    if (data) await this.settings();
  },

  async refresh() {
    const owner = App.currentUser?.id;
    const photos = [...productImageState.results];
    await Promise.all(photos.map(async photo => {
      const node = document.getElementById(`wordpress-media-${Number(photo.asset_id)}`);
      if (!node) return;
      const state = await api(`/api/images/assets/${Number(photo.asset_id)}/wordpress`);
      if (!state || !node.isConnected || owner !== App.currentUser?.id || Number(state.version) !== Number(photo.version)) return;
      this.states.set(Number(photo.asset_id), state);
      this.draw(Number(photo.asset_id), state);
    }));
  },

  draw(id, state) {
    const node = document.getElementById(`wordpress-media-${id}`);
    if (!node) return;
    const busy = this.busy.has(id);
    node.innerHTML = !state.enabled ? '<p>WordPress-upload is nog niet actief. Een admin kan de koppeling instellen.</p>' : `
      <p><strong>Mediatheek — ${state.destination === 'local' ? 'lokale testwebsite' : 'echte webshop (openbare media)'}</strong></p>
      <label><input type="checkbox" onchange="WordPressMedia.approve(${id}, this.checked)" ${state.approved ? 'checked' : ''} ${!state.ready || busy ? 'disabled' : ''}> Ik heb deze foto, eventuele etiketten én de SEO gecontroleerd en keur deze versie goed.</label>
      <div class="form-actions"><button class="btn btn-outline btn-sm" onclick="WordPressMedia.upload(${id})" ${!state.ready || !state.approved || busy || state.uploaded ? 'disabled' : ''}>${busy ? 'Wordt verstuurd…' : state.uploaded ? 'In mediatheek ✓' : state.attachment_id ? 'SEO bijwerken in mediatheek' : 'Naar mediatheek'}</button>
      ${state.url ? `<a class="btn btn-outline btn-sm" href="${escHtml(state.url)}" target="_blank" rel="noopener noreferrer">Bekijk verzonden foto</a>` : ''}</div>
      <p>${!state.ready ? 'Rond eerst de SEO af.' : escHtml(state.message || 'Na een foto- of SEO-wijziging moet je opnieuw goedkeuren.')}</p>`;
  },

  async approve(id, approved) {
    const state = this.states.get(id);
    if (!state || this.busy.has(id)) return;
    this.busy.add(id); this.draw(id, state);
    try {
      const data = await api(`/api/images/assets/${id}/wordpress/approval`, { method: 'PUT', body: { approved, fingerprint: state.fingerprint } });
      if (data) this.states.set(id, data);
    } finally { this.busy.delete(id); await this.refresh(); }
  },

  async upload(id) {
    const state = this.states.get(id);
    if (!state?.approved || !state.ready || state.uploaded || this.busy.has(id)) return false;
    this.busy.add(id); this.draw(id, state);
    try {
      const data = await api(`/api/images/assets/${id}/wordpress`, { method: 'POST', body: { fingerprint: state.fingerprint } });
      if (data) { this.states.set(id, data); toast(data.message || 'Upload bevestigd.', 'success'); }
      return !!data;
    } finally { this.busy.delete(id); await this.refresh(); }
  },

  async uploadApproved(button) {
    if (this.batch) return;
    this.batch = true; button.disabled = true;
    const owner = App.currentUser?.id;
    const ids = productImageState.results.map(photo => Number(photo.asset_id));
    try {
      let sent = 0;
      for (const id of ids) {
        if (owner !== App.currentUser?.id || !productImageState.results.some(photo => Number(photo.asset_id) === id)) break;
        const state = this.states.get(id);
        if (!state?.approved || !state.ready || state.uploaded || !state.enabled) continue;
        if (!await this.upload(id)) break; // Stop on uncertainty; do not repeat a whole batch.
        sent++;
      }
      if (!sent) toast('Geen nieuwe goedgekeurde uploads. Controleer de foto, SEO en koppeling.', 'info');
    } finally { this.batch = false; button.disabled = false; }
  },
};
