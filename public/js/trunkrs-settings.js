// Admin-only setup; no access/refresh/device token ever reaches this script.
const TrunkrsSettings = {
  state: null,
  busy: false,
  generation: 0,
  async load() {
    const element = document.getElementById('trunkrs-settings');
    if (!element) return;
    const generation = ++this.generation;
    const userId = App.currentUser?.id;
    element.textContent = 'Trunkrs-instellingen laden…';
    const data = await api('/api/settings/trunkrs');
    if (generation !== this.generation || userId !== App.currentUser?.id || element !== document.getElementById('trunkrs-settings')) return;
    if (!data) { element.textContent = 'Trunkrs-instellingen konden niet worden geladen. Heropen Instellingen.'; return; }
    this.state = data;
    if (!data.ready) {
      element.innerHTML = `<h3>Trunkrs — Niet bezorgd</h3><p>${escHtml(data.message)}</p>
        <p>De beheerder kan uitsluitend de ontbrekende Trunkrs-tabellen toevoegen. Bestaande taken, projecten, kalender, notities en rapporten worden niet vervangen.</p>
        <button type="button" class="btn btn-primary" id="trunkrs-initialize">Trunkrs-opslag voorbereiden</button>
        <p id="trunkrs-feedback" role="status"></p>`;
      document.getElementById('trunkrs-initialize').addEventListener('click', () => this.action('initialize'));
      return;
    }
    const labels = { tenant_id: 'Microsoft tenant-ID', client_id: 'Toepassings-ID (client-ID)',
      reader_user_id: 'Object-ID van de mailboxgebruiker', mailbox: 'E-mailadres van de bestaande mailbox', folder_id: 'Microsoft map-ID van Trunkrs not deliverd' };
    element.innerHTML = `<h3>Trunkrs — Niet bezorgd</h3>
      <p>Gebruik je bestaande Microsoft-mailbox. Er is geen extra betaald account nodig.</p>
      <p>Rapportmap: <strong>Postvak IN / Klantenservice / Trunkrs not deliverd</strong>. Alleen rapporten van data@trunkrs.nl met het afgesproken onderwerp worden verwerkt.</p>
      <div class="form-group"><strong>${data.status.configured ? 'Microsoft verbonden' : 'Nog niet verbonden'} · ${data.status.enabled ? 'Inlezen ingeschakeld' : 'Inlezen uitgeschakeld'}</strong>
        <p>Laatste geslaagde controle: ${escHtml(data.status.last_checked_at || 'nog niet uitgevoerd')}</p>
        <p>${data.scheduler_recent ? 'Serverplanning recent aangeroepen.' : 'Automatische serverplanning nog niet bevestigd. Een handmatige controle bewijst geen automatische verwerking.'}</p>
        ${(data.status.warnings || []).map(w => `<p>${escHtml(w)}</p>`).join('')}
      </div>
      ${Object.entries(labels).map(([key, label]) => `<div class="form-group"><label for="trunkrs-${key}">${label}</label>
        <input id="trunkrs-${key}" type="${key === 'mailbox' ? 'email' : 'text'}" autocomplete="off" spellcheck="false" value="${escHtml(data.fields[key] || '')}"></div>`).join('')}
      <p>Opslaan verbreekt een bestaande verbinding. Daarna verbind je opnieuw. Rapporten en andere Pitboard-gegevens blijven behouden.</p>
      <button class="btn btn-outline" type="button" data-trunkrs="save">Gegevens opslaan</button>
      <div class="form-group" style="margin-top:16px"><label><input type="checkbox" id="trunkrs-consent" style="width:auto;margin-right:8px">
        Ik geef toestemming om deze mailbox te koppelen en rapporten in te lezen. Microsoft Mail.Read geeft technisch leestoegang tot mijn hele eigen mailbox; het Pitboard beperkt het lezen tot de opgegeven rapportmap. Geen e-mail verzenden, wijzigen of verwijderen. Toegang blijft beschikbaar tot ik deze intrek of Microsoft opnieuw aanmelden vereist.</label></div>
      <div class="ai-settings-actions">
        <button class="btn btn-primary" type="button" data-trunkrs="connect" ${data.revision ? '' : 'disabled'}>Verbinden met Microsoft</button>
        <button class="btn btn-outline" type="button" data-trunkrs="sync" ${data.status.configured && data.status.enabled ? '' : 'disabled'}>Rapporten nu controleren</button>
        <button class="btn btn-outline" type="button" data-trunkrs="refresh">Status vernieuwen</button>
        <button class="btn btn-outline" type="button" data-trunkrs="stop" ${data.revision ? '' : 'disabled'}>Koppeling stoppen / aanmelding annuleren</button>
      </div>
      <div id="trunkrs-login" style="margin-top:16px"></div>
      <p id="trunkrs-feedback" role="status" aria-live="polite"></p>`;
    element.querySelectorAll('[data-trunkrs]').forEach(button => button.addEventListener('click', () => this.action(button.dataset.trunkrs)));
    if (data.pending) this.pending(data.pending);
  },
  pending(data) {
    document.getElementById('trunkrs-login').innerHTML = `<p>Open Microsoft en vul deze tijdelijke code in: <strong>${escHtml(data.user_code)}</strong>.</p>
      <p>Meld aan met <strong>${escHtml(this.state.fields.mailbox)}</strong>, niet met het beheeraccount. Geef de code niet aan anderen.</p>
      <a class="btn btn-outline" href="https://microsoft.com/devicelogin" target="_blank" rel="noopener noreferrer">Microsoft-aanmelding openen</a>
      <button class="btn btn-primary" type="button" id="trunkrs-finish">Ik ben aangemeld — verbinding controleren</button>
      <p>Geldig tot ${escHtml(new Date(data.expires_at * 1000).toLocaleTimeString('nl-NL'))}. Wacht bij een lopende aanmelding minimaal ${Number(data.retry_after) || 5} seconden.</p>`;
    document.getElementById('trunkrs-finish').addEventListener('click', () => this.action('poll'));
  },
  async action(kind) {
    if (this.busy) return;
    if (kind === 'refresh') { await this.load(); return; }
    if (kind === 'stop' && !confirm('Stop de Trunkrs-koppeling en verwijder de lokaal bewaarde toegangstokens? Bestaande rapporten blijven behouden.')) return;
    if (kind === 'save' && this.state.status.configured && !confirm('De instellingen opslaan verbreekt de huidige Microsoft-verbinding. Doorgaan?')) return;
    let body;
    if (kind === 'initialize') {
      if (!confirm('Uitsluitend de ontbrekende Trunkrs-tabellen toevoegen? Bestaande gegevens worden niet vervangen.')) return;
      body = {confirm: true};
    }
    if (kind === 'save') {
      body = {revision: this.state.revision};
      Object.keys(this.state.fields).forEach(key => { body[key] = document.getElementById(`trunkrs-${key}`).value.trim(); });
    }
    if (kind === 'connect') {
      if (!document.getElementById('trunkrs-consent').checked) {
        document.getElementById('trunkrs-feedback').textContent = 'Bevestig eerst de beschreven leestoegang.'; return;
      }
      if (Object.keys(this.state.fields).some(key => document.getElementById(`trunkrs-${key}`).value.trim() !== this.state.fields[key])) {
        document.getElementById('trunkrs-feedback').textContent = 'Sla de gewijzigde gegevens eerst op.'; return;
      }
      body = {consent: true, revision: this.state.revision};
    }
    this.busy = true;
    const feedback = document.getElementById('trunkrs-feedback');
    feedback.textContent = 'Bezig…';
    try {
      const result = await api(`/api/settings/trunkrs${kind === 'save' ? '' : '/' + kind}`, {
        method: kind === 'save' ? 'PUT' : 'POST', body,
        onError: message => { feedback.textContent = message; },
      });
      if (!result) return;
      if (result.state === 'pending') {
        this.pending(result);
        feedback.textContent = 'Microsoft-aanmelding is nog niet afgerond. Rond deze af en controleer daarna opnieuw.';
      } else {
        await this.load();
        document.getElementById('trunkrs-feedback').textContent = result.message || 'Opgeslagen.';
      }
    } finally { this.busy = false; }
  },
};
