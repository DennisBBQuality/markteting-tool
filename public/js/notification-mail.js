// Admin-only sender setup. Tokens stay encrypted on the server, never in browser storage.
const PitboardNotificationMail = {
  data: null, busy: false, request: 0,

  reset() { this.data = null; this.busy = false; this.request++; },

  current(session) {
    return PitboardNotifications.current(session) && App.currentUser?.rol === 'admin';
  },

  async open() {
    if (App.currentUser?.rol !== 'admin') return;
    const session = PitboardNotifications.session;
    const request = ++this.request;
    const data = await api('/api/notification-mail');
    if (!data || !this.current(session) || request !== this.request) return;
    this.data = data;
    this.busy = false;
    this.draw();
  },

  draw() {
    const d = this.data;
    const footer = '<button class="btn btn-outline" onclick="closeModal()">Sluiten</button>';
    if (!d.ready) {
      openModal('E-mail vanuit BBQuality Pitboard', '<div id="notification-mail-setup"><p>De aparte, beveiligde verzendopslag is nog niet voorbereid. Bestaande taken, projecten en de Trunkrs-koppeling blijven ongewijzigd.</p><button class="btn btn-primary" onclick="PitboardNotificationMail.initialize()">Verzendopslag voorbereiden</button></div>', footer);
      return;
    }
    const f = d.fields || {};
    const input = (key, label, placeholder = '') => `<div class="form-group"><label for="notification-mail-${key}">${label}</label><input class="form-control" id="notification-mail-${key}" value="${escHtml(f[key] || '')}" placeholder="${escHtml(placeholder)}" autocomplete="off"></div>`;
    const testState = d.test_state === 'accepted'
      ? `<p>Microsoft heeft het proefbericht aangenomen voor ${escHtml(d.test_recipient || '')}. Dit bewijst nog geen ontvangst. Controlecode: <code>${escHtml(d.test_id || '')}</code>.</p>`
      : d.test_state === 'uncertain' ? '<p role="alert">De vorige proefverzending is niet bevestigd. Controleer eerst je postvak voordat je opnieuw verstuurt.</p>' : '';
    openModal('E-mail vanuit BBQuality Pitboard', `<div id="notification-mail-setup">
      <p class="notification-email-status">${d.enabled ? 'E-mailmeldingen zijn actief.' : 'E-mailmeldingen staan uit. Meldingen in Pitboard blijven beschikbaar.'}</p>
      <p>Afzender: <strong>BBQuality Pitboard</strong>. Gebruik een gedeeld Microsoft 365-postvak, geen extra betaald gebruikersaccount. De naam van het postvak moet in Microsoft ook BBQuality Pitboard zijn.</p>
      ${!d.runtime_allowed ? '<p role="alert">In deze omgeving wordt niets verzonden en is aanmelden niet mogelijk. Rond dit af op de beveiligde live-omgeving.</p>' : ''}
      <details><summary>Eenmalige inrichting in Microsoft</summary>
        <ol><li>Maak het gedeelde postvak <strong>pitboard@bbquality.nl</strong> met weergavenaam <strong>BBQuality Pitboard</strong>. Gebruik dit niet voor direct aanmelden.</li>
        <li>Geef het bestaande gebruikersaccount <strong>Verzenden als</strong> voor dit postvak. Kies niet “Verzenden namens”.</li>
        <li>Gebruik een aparte app-registratie <strong>BBQuality Pitboard - Meldingen</strong>, uitsluitend voor deze organisatie. Laat de Trunkrs-app ongewijzigd.</li>
        <li>Voeg alleen de gedelegeerde Microsoft Graph-machtigingen <code>Mail.Send.Shared</code>, <code>User.Read</code> en <code>offline_access</code> toe en laat deze goedkeuren. Schakel openbare clientstromen in voor de apparaatcode-aanmelding. Er is geen clientgeheim nodig.</li>
        <li>Vul hieronder de tenant-id, toepassings-id en object-id van het bestaande gebruikersaccount in.</li></ol>
        <p>De koppeling leest geen e-mail. Microsoft kan met deze machtiging wel verzenden vanuit postvakken waarvoor het account verzendrechten heeft; Pitboard gebruikt alleen de ingestelde afzender. Een standaard gedeeld postvak tot 50 GB heeft doorgaans geen eigen licentie nodig; het bestaande gebruikersaccount heeft wel een Exchange Online-licentie nodig.</p>
      </details>
      <h3>1. Afzender instellen</h3>
      ${input('sender_address', 'E-mailadres gedeeld postvak', 'pitboard@bbquality.nl')}
      ${input('tenant_id', 'Tenant-id')}${input('client_id', 'Toepassings-id van de aparte meldingen-app')}${input('delegate_user_id', 'Object-id van het bestaande gebruikersaccount')}
      <button class="btn btn-outline" onclick="PitboardNotificationMail.save()">Instellingen opslaan</button>
      <p>Opslaan schakelt verzending uit en wist alleen deze verzendaanmelding. Daarna opnieuw verbinden en testen.</p>
      <h3>2. Microsoft verbinden</h3>
      <p>${d.connected ? 'Microsoft is verbonden. Controleer nu de afzender met een proefbericht.' : 'Nog niet verbonden.'}</p>
      ${d.pending ? `<p>Open <a href="https://microsoft.com/devicelogin" target="_blank" rel="noopener noreferrer">Microsoft aanmelden</a> en voer code <strong>${escHtml(d.pending.user_code)}</strong> in. Meld je aan als het ingestelde bestaande gebruikersaccount, niet als het gedeelde postvak.</p><button class="btn btn-outline" onclick="PitboardNotificationMail.act('poll')">Aanmelding controleren</button>`
        : `<label class="notification-preference"><input id="notification-mail-consent" type="checkbox"> Ik wil deze aparte koppeling toegang geven om als het gedeelde postvak te verzenden en deze verbinding te behouden.</label><button class="btn btn-outline" ${!d.revision || !d.runtime_allowed ? 'disabled' : ''} onclick="PitboardNotificationMail.start()">Verbinden met Microsoft</button>`}
      <h3>3. Proefbericht controleren</h3>
      ${testState}
      <p>Een proefbericht gaat alleen naar het e-mailadres van jouw ingelogde Pitboard-account. Geen bericht naar collega's.</p>
      <button class="btn btn-outline" ${!d.connected || !d.runtime_allowed ? 'disabled' : ''} onclick="PitboardNotificationMail.test()">Stuur één proefbericht naar mijzelf</button>
      ${d.can_confirm_test && !d.enabled ? '<label class="notification-preference"><input id="notification-mail-received" type="checkbox"> Ik heb het proefbericht met bovenstaande controlecode ontvangen. De afzender is BBQuality Pitboard met het ingestelde adres, zonder “namens” een persoon.</label><button class="btn btn-primary" onclick="PitboardNotificationMail.enable()">E-mailmeldingen activeren</button>' : ''}
      <h3>Stoppen</h3><p>Stoppen verwijdert alleen deze verzendaanmelding uit Pitboard. De Trunkrs-import en bestaande meldingen blijven behouden. Microsoft-toestemming kun je daarnaast zelf in Entra intrekken.</p>
      <button class="btn btn-outline" ${!d.revision ? 'disabled' : ''} onclick="PitboardNotificationMail.stop()">Verzendkoppeling stoppen</button>
      <p id="notification-mail-action-status" role="status"></p>
    </div>`, footer);
  },

  async act(action, body = {}, method = 'POST') {
    if (this.busy || !document.getElementById('notification-mail-setup')) return;
    const session = PitboardNotifications.session;
    if (!this.current(session)) return;
    if (['start', 'poll', 'test', 'enable'].includes(action) && !this.unchanged()) {
      toast('Sla gewijzigde instellingen eerst op. Daarna opnieuw verbinden en testen.', 'error');
      return;
    }
    this.busy = true;
    const status = document.getElementById('notification-mail-action-status');
    if (status) status.textContent = 'Bezig…';
    const result = await api('/api/notification-mail' + (action ? '/' + action : ''), { method, body: { ...body, revision: this.data.revision } });
    if (!this.current(session)) return;
    this.busy = false;
    if (!document.getElementById('notification-mail-setup')) return;
    if (!result && method === 'PUT') {
      if (status) status.textContent = 'Opslaan is niet gelukt. Je invoer is behouden. Controleer de velden of heropen dit scherm als een andere beheerder de instellingen heeft gewijzigd.';
      return;
    }
    if (result?.message) toast(result.message, 'success');
    // Also reload after failure: the server may have consumed a test attempt before an uncertain response.
    await this.open();
  },

  unchanged() {
    return ['sender_address', 'tenant_id', 'client_id', 'delegate_user_id'].every(key =>
      (document.getElementById('notification-mail-' + key)?.value || '').trim() === (this.data.fields?.[key] || ''));
  },

  initialize() {
    if (confirm('Alleen de aparte verzendopslag toevoegen? Bestaande gegevens blijven behouden.')) return this.act('initialize', { confirm: true });
  },
  save() {
    const fields = {};
    for (const key of ['sender_address', 'tenant_id', 'client_id', 'delegate_user_id']) fields[key] = document.getElementById('notification-mail-' + key).value.trim();
    if (this.data.connected && !confirm('De huidige verzendaanmelding wissen en verzending uitschakelen? Daarna moet je opnieuw verbinden en testen.')) return;
    return this.act('', fields, 'PUT');
  },
  start() {
    if (!document.getElementById('notification-mail-consent')?.checked) { toast('Bevestig eerst waarvoor je de koppeling wilt gebruiken.', 'error'); return; }
    return this.act('start', { consent: true });
  },
  test() {
    if (confirm('Eén proefbericht naar het e-mailadres van je eigen Pitboard-account versturen? Controleer bij een eerdere onzekere poging eerst je postvak.')) return this.act('test', { confirm: true });
  },
  enable() {
    if (!document.getElementById('notification-mail-received')?.checked) { toast('Controleer eerst de ontvangst en de juiste afzender.', 'error'); return; }
    return this.act('enable', { received_correct_sender: true, test_id: this.data.test_id });
  },
  stop() {
    if (confirm('Deze verzendkoppeling stoppen? Meldingen in Pitboard en de Trunkrs-import blijven werken.')) return this.act('stop');
  },
};
