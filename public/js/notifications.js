// Persistent assignment inbox; no external push or mailbox access in the browser.
const PitboardNotifications = {
  timer: null, session: 0, request: 0, countRequest: 0, page: 1, unreadOnly: true, popupOpen: false,
  items: [], hasMore: false, loading: false, owner: null, unreadCount: 0,

  unmount() { this.request++; this.loading = false; this.page = 1; this.popupOpen = false; },

  showPopup() {
    if (!this.owner || App.currentView !== 'dashboard') return;
    openModal('Meldingen', '<div id="notification-popup"><p role="status">Meldingen laden…</p></div>',
      '<button class="btn btn-outline" onclick="PitboardNotifications.preferences()">Voorkeuren</button><button class="btn btn-primary" onclick="closeModal()">Sluiten</button>');
    this.popupOpen = true;
    this.unreadOnly = true;
    this.items = [];
    document.getElementById('modal-title')?.setAttribute('tabindex', '-1');
    document.getElementById('modal-title')?.focus();
    return this.render();
  },

  start() {
    this.stop();
    this.owner = App.currentUser?.id;
    this.refreshBadge();
    this.timer = setInterval(() => {
      if (!document.hidden) this.refreshBadge();
    }, 30000);
  },

  stop() {
    if (typeof PitboardNotificationMail !== 'undefined') PitboardNotificationMail.reset();
    clearInterval(this.timer);
    this.timer = null;
    this.session++;
    this.request++;
    this.owner = null;
    this.items = [];
    this.loading = false;
    this.unreadOnly = true;
    this.popupOpen = false;
    const view = document.getElementById('notification-popup');
    if (view) view.innerHTML = '';
    this.badge(0);
  },

  current(session) { return session === this.session && this.owner === App.currentUser?.id; },

  badge(count) {
    this.unreadCount = Number.isInteger(count) && count >= 0 ? count : 0;
    const badge = document.getElementById('notification-count');
    if (badge) { badge.textContent = String(this.unreadCount); badge.setAttribute('aria-label', `${this.unreadCount} ongeopende meldingen`); }
    document.getElementById('dashboard-notification-button')?.setAttribute('aria-label', `Meldingen, ${this.unreadCount} ongeopend`);
  },

  async refreshBadge() {
    const session = this.session;
    const countRequest = ++this.countRequest;
    if (!this.owner) return;
    if (this.popupOpen && this.page === 1 && !this.loading) {
      return this.render(false, true);
    }
    const result = await api('/api/notifications', { silentError: true });
    if (!this.current(session) || countRequest !== this.countRequest) return;
    if (result && Number.isInteger(result.unread_count)) {
      this.badge(result.unread_count);
    }
  },

  heading() {
    return '<p class="notification-intro">Open de bijbehorende taak of het project om de melding als gelezen te markeren, of kies Markeer als gelezen.</p>';
  },

  async render(append = false, quiet = false) {
    const view = document.getElementById('notification-popup');
    if (!this.popupOpen || !view || App.currentView !== 'dashboard' || !this.owner) return;
    const session = this.session;
    const request = ++this.request;
    const countRequest = ++this.countRequest;
    if (!append) this.page = 1;
    this.loading = true;
    if (!append && !quiet) view.innerHTML = this.heading() + '<p role="status">Meldingen laden…</p>';
    const result = await api(`/api/notifications?page=${this.page}&unread=${this.unreadOnly ? 1 : 0}`, { silentError: true });
    if (!this.current(session) || request !== this.request || !this.popupOpen || view !== document.getElementById('notification-popup') || App.currentView !== 'dashboard') return;
    this.loading = false;
    if (result?.ready === false) {
      view.innerHTML = `<div class="notification-scroll"><p>De meldingenopslag is nog niet voorbereid.</p>
        ${result.can_initialize ? '<p>Deze beheeractie voegt uitsluitend de twee nieuwe meldingentabellen toe. Bestaande taken, projecten en andere gegevens blijven behouden.</p><button class="btn btn-primary" onclick="PitboardNotifications.initialize()">Meldingenopslag voorbereiden</button>' : '<p>Vraag een beheerder om de meldingenopslag via het dashboard voor te bereiden.</p>'}</div>`;
      return;
    }
    if (!result || !Array.isArray(result.items)) {
      if (append) this.page--;
      view.innerHTML = this.heading() + '<p role="alert">Je meldingen konden niet worden bijgewerkt.</p><button class="btn btn-outline" onclick="PitboardNotifications.render()">Opnieuw proberen</button>';
      return;
    }
    const unchanged = quiet && JSON.stringify(this.items) === JSON.stringify(result.items) && this.hasMore === result.has_more;
    this.items = append ? [...new Map([...this.items, ...result.items].map(item => [item.id, item])).values()] : result.items;
    this.hasMore = result.has_more;
    if (countRequest === this.countRequest) this.badge(result.unread_count);
    if (!unchanged || !view.querySelector('.notification-list')) this.paint();
  },

  paint() {
    const view = document.getElementById('notification-popup');
    if (!this.popupOpen || !view) return;
    view.innerHTML = `
      ${this.heading()}
      <div class="notification-toolbar">
        <div><button class="btn ${!this.unreadOnly ? 'btn-primary' : 'btn-outline'}" aria-pressed="${!this.unreadOnly}" onclick="PitboardNotifications.filter(false)">Alles</button>
        <button class="btn ${this.unreadOnly ? 'btn-primary' : 'btn-outline'}" aria-pressed="${this.unreadOnly}" onclick="PitboardNotifications.filter(true)">Ongeopend</button></div>
        <div><button class="btn btn-outline" onclick="PitboardNotifications.render()" aria-label="Meldingen vernieuwen"><i class="fas fa-rotate"></i></button>
        </div>
      </div>
      <div class="notification-scroll"><div class="notification-list">
        ${this.items.length ? this.items.map(item => `
          <article class="notification-card ${item.read_at ? '' : 'notification-unread'}">
            <div class="notification-kind" aria-hidden="true"><i class="fas fa-${item.kind === 'task' ? 'tasks' : 'folder'}"></i></div>
            <div class="notification-copy"><span class="notification-label">${item.kind === 'task' ? 'Nieuwe taak voor jou' : 'Toegevoegd aan project'}${item.read_at ? '' : ' · Nieuw'}</span>
              <h3><button class="notification-link" data-open-notification="${escHtml(item.id)}">${escHtml(item.title)}</button></h3>
              <p>${escHtml(item.actor_name)} heeft ${item.kind === 'task' ? 'deze taak aan je toegewezen' : 'je aan dit project toegevoegd'}.</p>
              <small>${escHtml(this.date(item.created_at))}${item.deadline ? ` · Deadline: ${escHtml(this.date(item.deadline, true))}` : ''}</small>
              ${item.read_at ? '' : `<button class="btn btn-outline btn-sm" data-read-notification="${escHtml(item.id)}">Markeer als gelezen</button>`}
            </div>
          </article>`).join('') : '<div class="notification-empty"><i class="far fa-bell" aria-hidden="true"></i><h3>Je bent bij</h3><p>Hier verschijnen nieuwe toewijzingen aan jou.</p></div>'}
      </div>
      ${this.hasMore ? '<button class="btn btn-outline" onclick="PitboardNotifications.more()">Meer meldingen</button>' : ''}</div>`;
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
    return this.markRead(`/api/notifications/${encodeURIComponent(id)}/read`);
  },
  async readAll() {
    return this.markRead('/api/notifications/read-all');
  },
  async readTarget(kind, targetId) {
    if (!this.owner || this.owner !== App.currentUser?.id) return false;
    return this.markRead('/api/notifications/read-target', { kind, target_id: targetId });
  },
  async markRead(url, body) {
    if (!this.owner || this.owner !== App.currentUser?.id) return false;
    const session = this.session;
    ++this.countRequest;
    const result = await api(url, { method: 'POST', body });
    if (!this.current(session)) return false;
    // Invalidate polls started before or during this mutation, then fetch fresh state.
    ++this.countRequest;
    ++this.request;
    this.loading = false;
    if (result && Number.isInteger(result.unread_count)) this.badge(result.unread_count);
    if (this.popupOpen) await this.render(); else await this.refreshBadge();
    return !!result?.ok;
  },

  async open(id) {
    const session = this.session;
    const popupRequest = this.popupOpen ? this.request : null;
    const item = await api(`/api/notifications/${encodeURIComponent(id)}`);
    if (!item || !this.current(session)) return false;
    if (popupRequest !== null && (!this.popupOpen || popupRequest !== this.request)) return false;
    const view = item.kind === 'task' ? 'tasks' : 'projects';
    navigateTo(view);
    if (App.currentView !== view) return false;
    closeModal();
    if (item.kind === 'task') {
      if (await openEditTaskModal(item.target_id, () => this.current(session) && App.currentView === view) === false) return false;
    } else {
      const projects = await api('/api/projects');
      if (!projects || !this.current(session) || App.currentView !== view) return false;
      if (!projects.some(project => project.id === item.target_id)) { toast('Dit project is niet meer beschikbaar.', 'error'); return false; }
      App.projects = projects;
      await openProjectDetail(item.target_id);
    }
    return this.current(session) ? this.read(id) : false;
  },

  async openFromLink() {
    const url = new URL(location.href);
    const id = url.searchParams.get('melding');
    if (!id) return false;
    // Login happens before this; only an owned server-side notification resolves a target.
    navigateTo('dashboard');
    if (!await this.open(id)) return false;
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
      <label class="notification-preference"><input type="checkbox" id="notification-project-email" ${preferences.project_email ? 'checked' : ''}> Toegevoegd aan een project per e-mail</label>
      ${App.currentUser?.rol === 'admin' ? '<hr><button class="btn btn-outline" onclick="PitboardNotificationMail.open()">Afzender en Microsoft-koppeling beheren</button>' : ''}`,
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
