// Persistent assignment inbox; no external push or mailbox access in the browser.
const PitboardNotifications = {
  timer: null, session: 0, request: 0, page: 1, unreadOnly: false,
  items: [], hasMore: false, loading: false, owner: null,

  start() {
    this.stop();
    this.owner = App.currentUser?.id;
    this.refreshBadge();
    this.timer = setInterval(() => {
      if (!document.hidden) this.refreshBadge();
    }, 30000);
  },

  stop() {
    clearInterval(this.timer);
    this.timer = null;
    this.session++;
    this.request++;
    this.owner = null;
    this.items = [];
    this.loading = false;
    this.unreadOnly = false;
    const view = document.getElementById('view-notifications');
    if (view) view.innerHTML = '';
    this.badge(0);
  },

  current(session) { return session === this.session && this.owner === App.currentUser?.id; },

  badge(count) {
    const badge = document.getElementById('notification-count');
    const button = document.getElementById('nav-notifications');
    if (badge) { badge.textContent = count > 99 ? '99+' : String(count); badge.hidden = !count; }
    button?.setAttribute('aria-label', count ? `Meldingen, ${count} ongelezen` : 'Meldingen');
  },

  async refreshBadge() {
    const session = this.session;
    if (!this.owner) return;
    const result = await api('/api/notifications', { silentError: true });
    if (!this.current(session)) return;
    if (result && Number.isInteger(result.unread_count)) {
      this.badge(result.unread_count);
      document.getElementById('nav-notifications')?.removeAttribute('title');
    }
    else document.getElementById('nav-notifications')?.setAttribute('title', 'Meldingen konden niet worden bijgewerkt. Open het overzicht om opnieuw te proberen.');
  },

  async render(append = false) {
    const view = document.getElementById('view-notifications');
    const session = this.session;
    const request = ++this.request;
    if (!append) { this.page = 1; this.items = []; }
    this.loading = true;
    if (!append) view.innerHTML = '<div class="page-header"><h2>Meldingen</h2></div><p role="status">Meldingen laden…</p>';
    const result = await api(`/api/notifications?page=${this.page}&unread=${this.unreadOnly ? 1 : 0}`, { silentError: true });
    if (!this.current(session) || request !== this.request || App.currentView !== 'notifications') return;
    this.loading = false;
    if (result?.ready === false) {
      view.innerHTML = `<div class="page-header"><h2>Meldingen</h2></div><p>De meldingenopslag is nog niet voorbereid.</p>
        ${result.can_initialize ? '<p>Deze beheeractie voegt uitsluitend de twee nieuwe meldingentabellen toe. Bestaande taken, projecten en andere gegevens blijven behouden.</p><button class="btn btn-primary" onclick="PitboardNotifications.initialize()">Meldingenopslag voorbereiden</button>' : '<p>Vraag een beheerder om Meldingen te openen en de opslag voor te bereiden.</p>'}`;
      return;
    }
    if (!result || !Array.isArray(result.items)) {
      view.innerHTML = '<div class="page-header"><h2>Meldingen</h2></div><p role="alert">Je meldingen konden niet worden geladen.</p><button class="btn btn-outline" onclick="PitboardNotifications.render()">Opnieuw proberen</button>';
      return;
    }
    this.items = append ? [...this.items, ...result.items] : result.items;
    this.hasMore = result.has_more;
    this.badge(result.unread_count);
    this.paint();
  },

  paint() {
    const view = document.getElementById('view-notifications');
    view.innerHTML = `
      <div class="page-header"><div><h2>Meldingen</h2><p class="notification-intro">Nieuwe taken en projecten die aan jou zijn gekoppeld.</p></div>
        <button class="btn btn-outline" onclick="PitboardNotifications.preferences()"><i class="fas fa-sliders"></i> Voorkeuren</button></div>
      <div class="notification-toolbar">
        <div><button class="btn ${!this.unreadOnly ? 'btn-primary' : 'btn-outline'}" aria-pressed="${!this.unreadOnly}" onclick="PitboardNotifications.filter(false)">Alles</button>
        <button class="btn ${this.unreadOnly ? 'btn-primary' : 'btn-outline'}" aria-pressed="${this.unreadOnly}" onclick="PitboardNotifications.filter(true)">Ongelezen</button></div>
        <div><button class="btn btn-outline" onclick="PitboardNotifications.render()" aria-label="Meldingen vernieuwen"><i class="fas fa-rotate"></i></button>
        <button class="btn btn-outline" onclick="PitboardNotifications.readAll()">Alles als gelezen</button></div>
      </div>
      <div class="notification-list">
        ${this.items.length ? this.items.map(item => `
          <article class="notification-card ${item.read_at ? '' : 'notification-unread'}">
            <div class="notification-kind" aria-hidden="true"><i class="fas fa-${item.kind === 'task' ? 'tasks' : 'folder'}"></i></div>
            <div class="notification-copy"><span class="notification-label">${item.kind === 'task' ? 'Nieuwe taak voor jou' : 'Toegevoegd aan project'}${item.read_at ? '' : ' · Nieuw'}</span>
              <h3><button class="notification-link" data-open-notification="${escHtml(item.id)}">${escHtml(item.title)}</button></h3>
              <p>${escHtml(item.actor_name)} heeft ${item.kind === 'task' ? 'deze taak aan je toegewezen' : 'je aan dit project toegevoegd'}.</p>
              <small>${escHtml(this.date(item.created_at))}${item.deadline ? ` · Deadline: ${escHtml(this.date(item.deadline, true))}` : ''}</small>
            </div>
            ${item.read_at ? '' : `<button class="btn btn-outline btn-sm" data-read-notification="${escHtml(item.id)}">Als gelezen</button>`}
          </article>`).join('') : '<div class="notification-empty"><i class="far fa-bell" aria-hidden="true"></i><h3>Je bent bij</h3><p>Hier verschijnen nieuwe toewijzingen aan jou.</p></div>'}
      </div>
      ${this.hasMore ? '<button class="btn btn-outline" onclick="PitboardNotifications.more()">Meer meldingen</button>' : ''}`;
    view.querySelectorAll('[data-open-notification]').forEach(button => button.addEventListener('click', () => this.open(button.dataset.openNotification)));
    view.querySelectorAll('[data-read-notification]').forEach(button => button.addEventListener('click', () => this.read(button.dataset.readNotification)));
  },

  date(value, dayOnly = false) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';
    return date.toLocaleString('nl-NL', dayOnly ? { day: 'numeric', month: 'long', year: 'numeric' } : { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
  },
  filter(unread) { this.unreadOnly = unread; this.render(); },
  more() { if (!this.loading && this.hasMore) { this.page++; this.render(true); } },

  async read(id) {
    const session = this.session;
    const result = await api(`/api/notifications/${encodeURIComponent(id)}/read`, { method: 'POST' });
    if (result && this.current(session)) { this.refreshBadge(); if (App.currentView === 'notifications') this.render(); }
  },
  async readAll() {
    const session = this.session;
    const result = await api('/api/notifications/read-all', { method: 'POST' });
    if (result && this.current(session)) { this.refreshBadge(); if (App.currentView === 'notifications') this.render(); }
  },

  async open(id) {
    const session = this.session;
    const item = await api(`/api/notifications/${encodeURIComponent(id)}`);
    if (!item || !this.current(session)) return false;
    const view = item.kind === 'task' ? 'tasks' : 'projects';
    navigateTo(view);
    if (App.currentView !== view) return false;
    if (item.kind === 'task') {
      if (await openEditTaskModal(item.target_id, () => this.current(session) && App.currentView === view) === false) return false;
    } else {
      const projects = await api('/api/projects');
      if (!projects || !this.current(session) || App.currentView !== view) return false;
      if (!projects.some(project => project.id === item.target_id)) { toast('Dit project is niet meer beschikbaar.', 'error'); return false; }
      App.projects = projects;
      await openProjectDetail(item.target_id);
    }
    if (this.current(session)) await this.read(id);
    return true;
  },

  async openFromLink() {
    const url = new URL(location.href);
    const id = url.searchParams.get('melding');
    if (!id) return false;
    // Login happens before this; only an owned server-side notification resolves a target.
    navigateTo('notifications');
    await this.open(id);
    url.searchParams.delete('melding');
    history.replaceState(null, '', url);
    return true;
  },

  async preferences() {
    const session = this.session;
    const preferences = await api('/api/notifications/preferences');
    if (!preferences || !this.current(session)) return;
    if (preferences.ready === false) { this.render(); return; }
    openModal('Mijn meldingsvoorkeuren', `
      <p>Meldingen blijven altijd bewaard in Pitboard. Kies welke nieuwe toewijzingen je ook per e-mail wilt ontvangen.</p>
      <p class="notification-email-status">${preferences.email_active ? `E-mails worden verstuurd vanuit ${escHtml(preferences.sender_name || 'BBQuality Pitboard')}.` : 'E-mailverzending is nog niet geactiveerd. Je voorkeuren worden alvast bewaard; meldingen in Pitboard werken wel.'}</p>
      ${preferences.email_attention_count ? `<p role="alert">Bij ${Number(preferences.email_attention_count)} e-mailmeldingen is verzending niet bevestigd. Laat dit controleren voordat opnieuw wordt verstuurd. De meldingen in Pitboard blijven beschikbaar.</p>` : ''}
      <label class="notification-preference"><input type="checkbox" id="notification-task-email" ${preferences.task_email ? 'checked' : ''}> Nieuwe taken per e-mail</label>
      <label class="notification-preference"><input type="checkbox" id="notification-project-email" ${preferences.project_email ? 'checked' : ''}> Toegevoegd aan een project per e-mail</label>`,
      '<button class="btn btn-outline" onclick="closeModal()">Annuleren</button><button class="btn btn-primary" onclick="PitboardNotifications.savePreferences()">Opslaan</button>');
  },

  async savePreferences() {
    const session = this.session;
    const result = await api('/api/notifications/preferences', { method: 'PUT', body: {
      task_email: document.getElementById('notification-task-email').checked,
      project_email: document.getElementById('notification-project-email').checked,
    } });
    if (result && this.current(session)) { closeModal(); toast('Je meldingsvoorkeuren zijn opgeslagen.', 'success'); }
  },

  async initialize() {
    if (this.loading || !confirm('Alleen de twee nieuwe meldingentabellen toevoegen? Bestaande taken, projecten en andere gegevens blijven behouden.')) return;
    const session = this.session;
    this.loading = true;
    const result = await api('/api/notifications/initialize', { method: 'POST', body: { confirm: true } });
    if (!this.current(session)) return;
    this.loading = false;
    if (result) { this.refreshBadge(); this.render(); }
  },
};

document.addEventListener('visibilitychange', () => { if (!document.hidden) PitboardNotifications.refreshBadge(); });
