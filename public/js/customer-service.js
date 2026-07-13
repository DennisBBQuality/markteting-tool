// ========== Customer Service ========== 
const CustomerServiceState = {
  tickets: [],
  meta: { page: 1, per_page: 25, total: 0, last_page: 1 },
  selectedTicketId: null,
  detail: null,
  activeTab: 'timeline',
  conflict: null,
  isSubmitting: false,
  messageClientId: null,
  drafts: { direction: 'inkomend', message: '', note: '' },
  detailPollTimer: null,
};

function renderCustomerService() {
  const container = document.getElementById('view-customer-service');
  container.innerHTML = `
    <div class="cs-page-header">
      <div>
        <h1>Klantenservice</h1>
        <p>Handmatige testtickets voor het klantenserviceteam</p>
      </div>
      <button type="button" class="btn btn-primary" onclick="openCustomerServiceTicketModal()"><i class="fas fa-plus"></i> Nieuw testticket</button>
    </div>
    <div class="cs-layout">
      <aside class="cs-filters" aria-label="Ticketfilters">
        <div class="cs-panel-heading">
          <h2>Inbox</h2>
          <span class="cs-count" id="cs-total-count">0</span>
        </div>
        <p class="cs-muted">Filters en zoeken worden in een volgende stap beschikbaar.</p>
      </aside>
      <section class="cs-list-panel" aria-label="Ticketlijst">
        <div class="cs-panel-heading">
          <h2>Tickets</h2>
          <span class="cs-muted">Nieuwste bericht eerst</span>
        </div>
        <div id="cs-ticket-list" class="cs-ticket-list"></div>
        <div id="cs-pagination" class="cs-pagination"></div>
      </section>
      <section id="cs-detail" class="cs-detail" aria-label="Ticketdetail">
        ${customerServiceDetailPlaceholder()}
      </section>
    </div>`;

  loadCustomerServiceTickets(1);
}

function cleanupCustomerService() {
  if (CustomerServiceState.detailPollTimer) {
    clearInterval(CustomerServiceState.detailPollTimer);
    CustomerServiceState.detailPollTimer = null;
  }
}

async function loadCustomerServiceTickets(page = 1) {
  const list = document.getElementById('cs-ticket-list');
  if (!list) return;

  list.innerHTML = customerServiceListLoading();
  document.getElementById('cs-pagination').innerHTML = '';

  const result = await api(`/api/customer-service/tickets?page=${page}&per_page=25`);
  if (!document.getElementById('cs-ticket-list')) return;

  if (!result) {
    list.innerHTML = customerServiceListError();
    return;
  }

  CustomerServiceState.tickets = result.data;
  CustomerServiceState.meta = result.meta;
  document.getElementById('cs-total-count').textContent = String(result.meta.total);
  renderCustomerServiceTicketList();
  renderCustomerServicePagination();
}

function renderCustomerServiceTicketList() {
  const list = document.getElementById('cs-ticket-list');
  if (!list) return;

  if (CustomerServiceState.tickets.length === 0) {
    list.innerHTML = `
      <div class="cs-empty-state">
        <i class="fas fa-inbox"></i>
        <h3>Nog geen tickets</h3>
        <p>Maak later een testticket aan om te beginnen.</p>
      </div>`;
    return;
  }

  list.innerHTML = CustomerServiceState.tickets.map(ticket => `
    <article class="cs-ticket-card${ticket.id === CustomerServiceState.selectedTicketId ? ' selected' : ''}" data-ticket-id="${escHtml(ticket.id)}" role="button" tabindex="0">
      <div class="cs-ticket-card-topline">
        <strong>${escHtml(ticket.ticketnummer)}</strong>
        <span class="cs-time">${escHtml(formatDateTime(ticket.laatste_bericht_op || ticket.created_at))}</span>
      </div>
      <h3>${escHtml(ticket.onderwerp)}</h3>
      <p>${escHtml(ticket.klant_naam)}</p>
      <div class="cs-ticket-card-meta">
        ${customerServiceStatusBadge(ticket.status)}
        ${customerServicePriorityBadge(ticket.prioriteit)}
        <span class="cs-assignee"><i class="fas fa-user"></i> ${escHtml(ticket.behandelaar?.naam || 'Niet toegewezen')}</span>
      </div>
    </article>`).join('');

  list.querySelectorAll('.cs-ticket-card').forEach(card => {
    const selectTicket = () => customerServiceSelectTicket(card.dataset.ticketId);
    card.addEventListener('click', selectTicket);
    card.addEventListener('keydown', event => {
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        selectTicket();
      }
    });
  });
}

function renderCustomerServicePagination() {
  const pagination = document.getElementById('cs-pagination');
  if (!pagination) return;

  const meta = CustomerServiceState.meta;
  if (meta.last_page <= 1) {
    pagination.innerHTML = '';
    return;
  }

  pagination.innerHTML = `
    <button class="btn btn-secondary btn-sm" onclick="customerServiceGoToPage(${meta.page - 1})" ${meta.page <= 1 ? 'disabled' : ''}>
      <i class="fas fa-chevron-left"></i> Vorige
    </button>
    <span>Pagina ${escHtml(String(meta.page))} van ${escHtml(String(meta.last_page))}</span>
    <button class="btn btn-secondary btn-sm" onclick="customerServiceGoToPage(${meta.page + 1})" ${meta.page >= meta.last_page ? 'disabled' : ''}>
      Volgende <i class="fas fa-chevron-right"></i>
    </button>`;
}

function customerServiceGoToPage(page) {
  if (page < 1 || page > CustomerServiceState.meta.last_page) return;
  loadCustomerServiceTickets(page);
}

async function customerServiceSelectTicket(ticketId) {
  CustomerServiceState.selectedTicketId = ticketId;
  CustomerServiceState.detail = null;
  CustomerServiceState.activeTab = 'timeline';
  CustomerServiceState.conflict = null;
  CustomerServiceState.messageClientId = null;
  CustomerServiceState.drafts = { direction: 'inkomend', message: '', note: '' };
  renderCustomerServiceTicketList();

  const detail = document.getElementById('cs-detail');
  if (!detail) return;
  detail.innerHTML = customerServiceDetailLoading();

  const [ticket, messages, notes, activities] = await Promise.all([
    api(`/api/customer-service/tickets/${ticketId}`),
    api(`/api/customer-service/tickets/${ticketId}/messages`),
    api(`/api/customer-service/tickets/${ticketId}/notes`),
    api(`/api/customer-service/tickets/${ticketId}/activities`),
  ]);

  if (CustomerServiceState.selectedTicketId !== ticketId || !document.getElementById('cs-detail')) return;
  if (!ticket || !messages || !notes || !activities) {
    detail.innerHTML = customerServiceDetailError();
    return;
  }

  CustomerServiceState.detail = { ticket, messages, notes, activities };
  renderCustomerServiceDetail();
}

function renderCustomerServiceDetail() {
  const container = document.getElementById('cs-detail');
  const detail = CustomerServiceState.detail;
  if (!container || !detail) return;

  const ticket = detail.ticket;
  const assignee = ticket.behandelaar;
  container.innerHTML = `
    ${customerServiceConflictBanner()}
    <header class="cs-detail-header">
      <div class="cs-detail-eyebrow">
        <strong>${escHtml(ticket.ticketnummer)}</strong>
        <span>Versie ${escHtml(String(ticket.versie))}</span>
      </div>
      <h2>${escHtml(ticket.onderwerp)}</h2>
      <div class="cs-customer-card">
        <i class="fas fa-user-circle"></i>
        <div>
          <strong>${escHtml(ticket.klant_naam)}</strong>
          <a href="mailto:${encodeURIComponent(ticket.klant_email)}">${escHtml(ticket.klant_email)}</a>
        </div>
      </div>
      <div class="cs-detail-meta">
        ${customerServiceStatusBadge(ticket.status)}
        ${customerServicePriorityBadge(ticket.prioriteit)}
        <span class="cs-detail-assignee">
          <span class="cs-avatar" style="background:${customerServiceSafeColor(assignee?.kleur)}">${escHtml((assignee?.naam || '?').charAt(0).toUpperCase())}</span>
          ${escHtml(assignee?.naam || 'Niet toegewezen')}
        </span>
      </div>
      <div class="cs-ticket-actions">
        ${customerServiceClaimAction(ticket)}
        ${customerServiceStatusSelect(ticket)}
        ${customerServicePrioritySelect(ticket)}
      </div>
    </header>
    <nav class="cs-detail-tabs" aria-label="Ticketdetail tabs">
      ${customerServiceDetailTabButton('timeline', 'Tijdlijn', detail.messages.length)}
      ${customerServiceDetailTabButton('notes', 'Notities', detail.notes.length)}
      ${customerServiceDetailTabButton('history', 'Historie', detail.activities.length)}
    </nav>
    <div id="cs-detail-tab-content" class="cs-detail-tab-content"></div>`;

  renderCustomerServiceDetailTab();
  customerServiceRestoreDrafts();
}

function customerServiceDetailTabButton(tab, label, count) {
  const active = CustomerServiceState.activeTab === tab;
  return `<button type="button" class="cs-detail-tab${active ? ' active' : ''}" data-cs-tab="${tab}" onclick="customerServiceSwitchTab('${tab}')" aria-selected="${active}">${label} <span>${escHtml(String(count))}</span></button>`;
}

function customerServiceSwitchTab(tab) {
  if (!['timeline', 'notes', 'history'].includes(tab) || !CustomerServiceState.detail) return;
  customerServiceCaptureDrafts();
  CustomerServiceState.activeTab = tab;
  renderCustomerServiceDetail();
}

function renderCustomerServiceDetailTab() {
  const container = document.getElementById('cs-detail-tab-content');
  const detail = CustomerServiceState.detail;
  if (!container || !detail) return;

  if (CustomerServiceState.activeTab === 'notes') {
    container.innerHTML = customerServiceNotesHtml(detail.notes);
  } else if (CustomerServiceState.activeTab === 'history') {
    container.innerHTML = customerServiceActivitiesHtml(detail.activities);
  } else {
    container.innerHTML = customerServiceTimelineHtml(detail.messages);
  }
}

function customerServiceTimelineHtml(messages) {
  if (messages.length === 0) return `${customerServiceTabEmpty('fa-comments', 'Nog geen berichten')}${customerServiceMessageComposer()}`;

  const timeline = `<div class="cs-timeline">${messages.map(message => {
    const outgoing = message.richting === 'uitgaand';
    const author = outgoing ? (message.auteur?.naam || 'Medewerker') : CustomerServiceState.detail.ticket.klant_naam;
    return `
      <article class="cs-message ${outgoing ? 'outgoing' : 'incoming'}">
        <div class="cs-message-heading">
          <strong>${escHtml(author)}</strong>
          <span>${outgoing ? 'Uitgaand' : 'Inkomend'} · ${escHtml(formatDateTime(message.created_at))}</span>
        </div>
        <div class="cs-message-body">${escHtml(message.inhoud)}</div>
      </article>`;
  }).join('')}</div>`;

  return `${timeline}${customerServiceMessageComposer()}`;
}

function customerServiceNotesHtml(notes) {
  if (notes.length === 0) return `${customerServiceTabEmpty('fa-sticky-note', 'Nog geen interne notities')}${customerServiceNoteComposer()}`;

  const noteList = `<div class="cs-note-list">${notes.map(note => `
    <article class="cs-note">
      <div class="cs-note-heading"><strong>${escHtml(note.auteur?.naam || 'Onbekende medewerker')}</strong><span>${escHtml(formatDateTime(note.created_at))}</span></div>
      <div class="cs-note-body">${escHtml(note.inhoud)}</div>
    </article>`).join('')}</div>`;

  return `${noteList}${customerServiceNoteComposer()}`;
}

function customerServiceActivitiesHtml(activities) {
  if (activities.length === 0) return customerServiceTabEmpty('fa-history', 'Nog geen activiteiten');

  return `<ol class="cs-activity-list">${activities.map(activity => `
    <li>
      <span class="cs-activity-icon"><i class="fas ${customerServiceActivityIcon(activity.actie)}"></i></span>
      <div>
        <strong>${escHtml(customerServiceActivityLabel(activity.actie))}</strong>
        <p>${escHtml(activity.gebruiker?.naam || 'Systeem')} · ${escHtml(formatDateTime(activity.created_at))}</p>
        ${customerServiceActivityDetails(activity)}
      </div>
    </li>`).join('')}</ol>`;
}

function customerServiceActivityLabel(action) {
  const labels = {
    ticket_aangemaakt: 'Ticket aangemaakt',
    status_gewijzigd: 'Status gewijzigd',
    prioriteit_gewijzigd: 'Prioriteit gewijzigd',
    ticket_geclaimd: 'Ticket geclaimd',
    ticket_vrijgegeven: 'Ticket vrijgegeven',
    bericht_toegevoegd: 'Bericht toegevoegd',
    notitie_toegevoegd: 'Notitie toegevoegd',
  };
  return labels[action] || 'Activiteit';
}

function customerServiceActivityIcon(action) {
  const icons = {
    ticket_aangemaakt: 'fa-plus',
    status_gewijzigd: 'fa-exchange-alt',
    prioriteit_gewijzigd: 'fa-flag',
    ticket_geclaimd: 'fa-user-check',
    ticket_vrijgegeven: 'fa-user-times',
    bericht_toegevoegd: 'fa-comment',
    notitie_toegevoegd: 'fa-sticky-note',
  };
  return icons[action] || 'fa-circle';
}

function customerServiceActivityDetails(activity) {
  const details = activity.details || {};
  if (activity.actie === 'status_gewijzigd' && details.van && details.naar) {
    return `<small>${escHtml(details.van)} → ${escHtml(details.naar)}</small>`;
  }
  if (activity.actie === 'prioriteit_gewijzigd' && details.van && details.naar) {
    return `<small>${escHtml(details.van)} → ${escHtml(details.naar)}</small>`;
  }
  return '';
}

function customerServiceTabEmpty(icon, text) {
  return `<div class="cs-empty-state cs-tab-empty"><i class="fas ${icon}"></i><p>${escHtml(text)}</p></div>`;
}

function customerServiceSafeColor(color) {
  return /^#[0-9a-f]{6}$/i.test(color || '') ? color : '#6B7280';
}

function customerServiceClaimAction(ticket) {
  if (ticket.status === 'afgehandeld') return '';
  if (!ticket.behandelaar) {
    return `<button type="button" class="btn btn-primary btn-sm" onclick="customerServiceClaim()"><i class="fas fa-hand-paper"></i> Claim</button>`;
  }
  if (ticket.behandelaar.id === App.currentUser.id) {
    return `<button type="button" class="btn btn-secondary btn-sm" onclick="customerServiceRelease()"><i class="fas fa-user-minus"></i> Vrijgeven</button>`;
  }
  return '';
}

function customerServiceStatusSelect(ticket) {
  const transitions = {
    nieuw: ticket.behandelaar ? ['in_behandeling', 'afgehandeld'] : ['afgehandeld'],
    in_behandeling: ['wachten_op_klant', 'afgehandeld'],
    wachten_op_klant: ['in_behandeling', 'afgehandeld'],
    afgehandeld: ['in_behandeling'],
  };
  const labels = {
    in_behandeling: 'In behandeling',
    wachten_op_klant: 'Wachten op klant',
    afgehandeld: 'Afgehandeld',
  };
  const options = (transitions[ticket.status] || [])
    .map(status => `<option value="${status}">${labels[status]}</option>`)
    .join('');

  return `
    <label class="cs-action-select">
      <span>Status</span>
      <select id="cs-status-select" class="form-control" onchange="customerServiceChangeStatus(this.value)">
        <option value="">Wijzigen...</option>
        ${options}
      </select>
    </label>`;
}

function customerServicePrioritySelect(ticket) {
  const labels = { laag: 'Laag', normaal: 'Normaal', hoog: 'Hoog', urgent: 'Urgent' };
  const options = Object.entries(labels)
    .filter(([priority]) => priority !== ticket.prioriteit)
    .map(([priority, label]) => `<option value="${priority}">${label}</option>`)
    .join('');

  return `
    <label class="cs-action-select">
      <span>Prioriteit</span>
      <select id="cs-priority-select" class="form-control" onchange="customerServiceChangePriority(this.value)">
        <option value="">Wijzigen...</option>
        ${options}
      </select>
    </label>`;
}

function customerServiceMessageComposer() {
  const ticket = CustomerServiceState.detail.ticket;
  const closed = ticket.status === 'afgehandeld';
  const canSendOutgoing = ticket.behandelaar?.id === App.currentUser.id;
  CustomerServiceState.messageClientId ||= crypto.randomUUID();

  return `
    <form class="cs-composer" onsubmit="customerServiceSubmitMessage(event)">
      <div class="cs-composer-heading">
        <strong>Testbericht toevoegen</strong>
        <select id="cs-message-direction" class="form-control" ${closed ? 'disabled' : ''}>
          <option value="inkomend">Inkomend klantbericht</option>
          <option value="uitgaand" ${canSendOutgoing ? '' : 'disabled'}>Uitgaand teamantwoord</option>
        </select>
      </div>
      <textarea id="cs-message-content" class="form-control" rows="4" maxlength="10000" placeholder="Schrijf een handmatig testbericht..." ${closed ? 'disabled' : ''}></textarea>
      ${closed
        ? '<p class="cs-composer-hint"><i class="fas fa-lock"></i> Heropen het ticket om een bericht toe te voegen.</p>'
        : !canSendOutgoing
          ? '<p class="cs-composer-hint"><i class="fas fa-info-circle"></i> Alleen de behandelaar kan een uitgaand antwoord plaatsen. Inkomende testberichten blijven beschikbaar.</p>'
          : ''}
      <button id="cs-message-submit" type="submit" class="btn btn-primary btn-sm" ${closed ? 'disabled' : ''}><i class="fas fa-paper-plane"></i> Bericht plaatsen</button>
    </form>`;
}

function customerServiceNoteComposer() {
  const closed = CustomerServiceState.detail.ticket.status === 'afgehandeld';
  return `
    <form class="cs-composer cs-note-composer" onsubmit="customerServiceSubmitNote(event)">
      <strong>Interne notitie toevoegen</strong>
      <textarea id="cs-note-content" class="form-control" rows="4" maxlength="10000" placeholder="Alleen zichtbaar voor medewerkers..." ${closed ? 'disabled' : ''}></textarea>
      ${closed ? '<p class="cs-composer-hint"><i class="fas fa-lock"></i> Heropen het ticket om een notitie toe te voegen.</p>' : ''}
      <button id="cs-note-submit" type="submit" class="btn btn-primary btn-sm" ${closed ? 'disabled' : ''}><i class="fas fa-plus"></i> Notitie plaatsen</button>
    </form>`;
}

function customerServiceCaptureDrafts() {
  const direction = document.getElementById('cs-message-direction');
  const message = document.getElementById('cs-message-content');
  const note = document.getElementById('cs-note-content');
  if (direction) CustomerServiceState.drafts.direction = direction.value;
  if (message) CustomerServiceState.drafts.message = message.value;
  if (note) CustomerServiceState.drafts.note = note.value;
}

function customerServiceRestoreDrafts() {
  const direction = document.getElementById('cs-message-direction');
  const message = document.getElementById('cs-message-content');
  const note = document.getElementById('cs-note-content');
  if (direction && [...direction.options].some(option => option.value === CustomerServiceState.drafts.direction && !option.disabled)) {
    direction.value = CustomerServiceState.drafts.direction;
  }
  if (message) message.value = CustomerServiceState.drafts.message;
  if (note) note.value = CustomerServiceState.drafts.note;
}

function customerServiceConflictBanner() {
  if (!CustomerServiceState.conflict) return '';
  return `
    <div class="cs-conflict-banner" role="alert">
      <i class="fas fa-exclamation-triangle"></i>
      <span>${escHtml(CustomerServiceState.conflict.error || 'Dit ticket is intussen gewijzigd.')}</span>
      <button type="button" class="btn btn-sm btn-secondary" onclick="customerServiceRefreshConflict()">Vernieuwen</button>
    </div>`;
}

async function customerServiceRequest(url, options = {}) {
  try {
    const response = await fetch(url, {
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      ...options,
      body: options.body ? JSON.stringify(options.body) : undefined,
    });
    const data = await response.json();
    if (response.status === 401) showLogin();
    return { ok: response.ok, status: response.status, data };
  } catch (error) {
    toast('De server is niet bereikbaar.', 'error');
    return { ok: false, status: 0, data: { error: 'De server is niet bereikbaar.' } };
  }
}

async function customerServiceClaim() {
  await customerServiceTicketMutation('claim', 'POST', { versie: CustomerServiceState.detail.ticket.versie }, 'Ticket geclaimd');
}

async function customerServiceRelease() {
  await customerServiceTicketMutation('release', 'POST', { versie: CustomerServiceState.detail.ticket.versie }, 'Ticket vrijgegeven');
}

async function customerServiceChangeStatus(status) {
  if (!status) return;
  await customerServiceTicketMutation('status', 'PUT', {
    status,
    versie: CustomerServiceState.detail.ticket.versie,
  }, 'Status bijgewerkt');
}

async function customerServiceChangePriority(prioriteit) {
  if (!prioriteit) return;
  await customerServiceTicketMutation('priority', 'PUT', {
    prioriteit,
    versie: CustomerServiceState.detail.ticket.versie,
  }, 'Prioriteit bijgewerkt');
}

async function customerServiceTicketMutation(endpoint, method, body, successMessage) {
  if (CustomerServiceState.isSubmitting || !CustomerServiceState.detail) return;
  CustomerServiceState.isSubmitting = true;
  customerServiceSetActionControlsDisabled(true);
  const ticketId = CustomerServiceState.detail.ticket.id;
  const result = await customerServiceRequest(`/api/customer-service/tickets/${ticketId}/${endpoint}`, { method, body });
  CustomerServiceState.isSubmitting = false;

  if (!result.ok) {
    customerServiceHandleMutationError(result);
    return;
  }

  toast(successMessage, 'success');
  await customerServiceRefreshAfterMutation(ticketId, CustomerServiceState.activeTab);
}

function customerServiceSetActionControlsDisabled(disabled) {
  document.querySelectorAll('.cs-ticket-actions button, .cs-ticket-actions select').forEach(control => {
    control.disabled = disabled;
  });
}

async function customerServiceSubmitMessage(event) {
  event.preventDefault();
  if (CustomerServiceState.isSubmitting || !CustomerServiceState.detail) return;
  customerServiceCaptureDrafts();
  const content = CustomerServiceState.drafts.message.trim();
  if (!content) {
    toast('Vul eerst een bericht in.', 'error');
    return;
  }

  CustomerServiceState.isSubmitting = true;
  const button = document.getElementById('cs-message-submit');
  if (button) {
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Plaatsen...';
  }

  const ticketId = CustomerServiceState.detail.ticket.id;
  const result = await customerServiceRequest(`/api/customer-service/tickets/${ticketId}/messages`, {
    method: 'POST',
    body: {
      richting: CustomerServiceState.drafts.direction,
      inhoud: content,
      client_message_id: CustomerServiceState.messageClientId,
      versie: CustomerServiceState.detail.ticket.versie,
    },
  });
  CustomerServiceState.isSubmitting = false;

  if (!result.ok) {
    customerServiceHandleMutationError(result);
    return;
  }

  CustomerServiceState.drafts.message = '';
  CustomerServiceState.messageClientId = crypto.randomUUID();
  toast('Testbericht geplaatst', 'success');
  await customerServiceRefreshAfterMutation(ticketId, 'timeline');
}

async function customerServiceSubmitNote(event) {
  event.preventDefault();
  if (CustomerServiceState.isSubmitting || !CustomerServiceState.detail) return;
  customerServiceCaptureDrafts();
  const content = CustomerServiceState.drafts.note.trim();
  if (!content) {
    toast('Vul eerst een notitie in.', 'error');
    return;
  }

  CustomerServiceState.isSubmitting = true;
  const button = document.getElementById('cs-note-submit');
  if (button) {
    button.disabled = true;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Plaatsen...';
  }

  const ticketId = CustomerServiceState.detail.ticket.id;
  const result = await customerServiceRequest(`/api/customer-service/tickets/${ticketId}/notes`, {
    method: 'POST',
    body: { inhoud: content, versie: CustomerServiceState.detail.ticket.versie },
  });
  CustomerServiceState.isSubmitting = false;

  if (!result.ok) {
    customerServiceHandleMutationError(result);
    return;
  }

  CustomerServiceState.drafts.note = '';
  toast('Interne notitie geplaatst', 'success');
  await customerServiceRefreshAfterMutation(ticketId, 'notes');
}

function customerServiceHandleMutationError(result) {
  if (result.status === 409) {
    customerServiceCaptureDrafts();
    if (result.data.ticket && CustomerServiceState.detail) {
      CustomerServiceState.detail.ticket = result.data.ticket;
    }
    CustomerServiceState.conflict = result.data;
    renderCustomerServiceDetail();
    toast(escHtml(result.data.error || 'Het ticket is intussen gewijzigd.'), 'error');
    return;
  }

  customerServiceSetActionControlsDisabled(false);
  const message = customerServiceErrorMessage(result.data);
  toast(escHtml(message), 'error');
  if (CustomerServiceState.detail) renderCustomerServiceDetail();
}

function customerServiceErrorMessage(data) {
  const firstValidationError = data?.errors ? Object.values(data.errors).flat()[0] : null;
  return firstValidationError || data?.error || data?.message || 'De actie kon niet worden uitgevoerd.';
}

async function customerServiceRefreshConflict() {
  if (!CustomerServiceState.detail) return;
  customerServiceCaptureDrafts();
  const ticketId = CustomerServiceState.detail.ticket.id;
  const activeTab = CustomerServiceState.activeTab;
  CustomerServiceState.conflict = null;
  await customerServiceLoadDetail(ticketId, activeTab);
}

async function customerServiceRefreshAfterMutation(ticketId, activeTab) {
  CustomerServiceState.conflict = null;
  await loadCustomerServiceTickets(CustomerServiceState.meta.page);
  await customerServiceLoadDetail(ticketId, activeTab);
}

async function customerServiceLoadDetail(ticketId, activeTab = 'timeline') {
  const detailContainer = document.getElementById('cs-detail');
  if (!detailContainer) return;
  CustomerServiceState.selectedTicketId = ticketId;
  CustomerServiceState.activeTab = activeTab;
  detailContainer.innerHTML = customerServiceDetailLoading();

  const [ticket, messages, notes, activities] = await Promise.all([
    api(`/api/customer-service/tickets/${ticketId}`),
    api(`/api/customer-service/tickets/${ticketId}/messages`),
    api(`/api/customer-service/tickets/${ticketId}/notes`),
    api(`/api/customer-service/tickets/${ticketId}/activities`),
  ]);
  if (!ticket || !messages || !notes || !activities || CustomerServiceState.selectedTicketId !== ticketId) {
    detailContainer.innerHTML = customerServiceDetailError();
    return;
  }

  CustomerServiceState.detail = { ticket, messages, notes, activities };
  renderCustomerServiceTicketList();
  renderCustomerServiceDetail();
}

function openCustomerServiceTicketModal() {
  openModal('Nieuw testticket', `
    <form id="cs-new-ticket-form" onsubmit="customerServiceCreateTicket(event)">
      <div id="cs-new-ticket-error" class="cs-form-error hidden" role="alert"></div>
      <div class="form-group"><label for="cs-new-subject">Onderwerp</label><input id="cs-new-subject" class="form-control" maxlength="255" required></div>
      <div class="form-group"><label for="cs-new-customer">Fictieve klantnaam</label><input id="cs-new-customer" class="form-control" maxlength="255" required></div>
      <div class="form-group"><label for="cs-new-email">Fictief e-mailadres</label><input id="cs-new-email" type="email" class="form-control" maxlength="255" placeholder="klant@example.test" required></div>
      <div class="form-group"><label for="cs-new-priority">Prioriteit</label><select id="cs-new-priority" class="form-control"><option value="laag">Laag</option><option value="normaal" selected>Normaal</option><option value="hoog">Hoog</option><option value="urgent">Urgent</option></select></div>
      <div class="form-group"><label for="cs-new-message">Eerste inkomende testbericht</label><textarea id="cs-new-message" class="form-control" rows="5" maxlength="10000" required></textarea></div>
    </form>`, `
    <button type="button" class="btn btn-secondary" onclick="closeModal()">Annuleren</button>
    <button id="cs-create-ticket-submit" type="submit" form="cs-new-ticket-form" class="btn btn-primary">Testticket aanmaken</button>`);
}

async function customerServiceCreateTicket(event) {
  event.preventDefault();
  if (CustomerServiceState.isSubmitting) return;
  CustomerServiceState.isSubmitting = true;
  const button = document.getElementById('cs-create-ticket-submit');
  button.disabled = true;
  button.textContent = 'Aanmaken...';

  const result = await customerServiceRequest('/api/customer-service/tickets', {
    method: 'POST',
    body: {
      onderwerp: document.getElementById('cs-new-subject').value.trim(),
      klant_naam: document.getElementById('cs-new-customer').value.trim(),
      klant_email: document.getElementById('cs-new-email').value.trim(),
      prioriteit: document.getElementById('cs-new-priority').value,
      eerste_bericht: document.getElementById('cs-new-message').value.trim(),
    },
  });
  CustomerServiceState.isSubmitting = false;

  if (!result.ok) {
    const error = document.getElementById('cs-new-ticket-error');
    error.textContent = customerServiceErrorMessage(result.data);
    error.classList.remove('hidden');
    button.disabled = false;
    button.textContent = 'Testticket aanmaken';
    return;
  }

  closeModal();
  toast('Testticket aangemaakt', 'success');
  await loadCustomerServiceTickets(1);
  await customerServiceSelectTicket(result.data.id);
}

function customerServiceStatusBadge(status) {
  const labels = {
    nieuw: 'Nieuw',
    in_behandeling: 'In behandeling',
    wachten_op_klant: 'Wachten op klant',
    afgehandeld: 'Afgehandeld',
  };
  const safeStatus = Object.hasOwn(labels, status) ? status : 'nieuw';
  return `<span class="cs-badge cs-status-${safeStatus}">${escHtml(labels[safeStatus])}</span>`;
}

function customerServicePriorityBadge(priority) {
  const labels = { laag: 'Laag', normaal: 'Normaal', hoog: 'Hoog', urgent: 'Urgent' };
  const safePriority = Object.hasOwn(labels, priority) ? priority : 'normaal';
  return `<span class="cs-badge cs-priority-${safePriority}">${escHtml(labels[safePriority])}</span>`;
}

function customerServiceDetailPlaceholder() {
  return `
    <div class="cs-empty-state cs-detail-placeholder">
      <i class="fas fa-ticket-alt"></i>
      <h3>Selecteer een ticket uit de lijst</h3>
      <p>Het volledige ticketdetail verschijnt hier.</p>
    </div>`;
}

function customerServiceDetailLoading() {
  return `<div class="cs-loading cs-detail-placeholder"><i class="fas fa-spinner fa-spin"></i><span>Ticket laden...</span></div>`;
}

function customerServiceDetailError() {
  return `
    <div class="cs-empty-state cs-detail-placeholder cs-error-state">
      <i class="fas fa-exclamation-triangle"></i>
      <h3>Ticket kon niet worden geladen</h3>
      <button class="btn btn-secondary" onclick="customerServiceSelectTicket(CustomerServiceState.selectedTicketId)">Opnieuw proberen</button>
    </div>`;
}

function customerServiceListLoading() {
  return `<div class="cs-loading"><i class="fas fa-spinner fa-spin"></i><span>Tickets laden...</span></div>`;
}

function customerServiceListError() {
  return `
    <div class="cs-empty-state cs-error-state">
      <i class="fas fa-exclamation-triangle"></i>
      <h3>Tickets konden niet worden geladen</h3>
      <button class="btn btn-secondary" onclick="loadCustomerServiceTickets(CustomerServiceState.meta.page)">Opnieuw proberen</button>
    </div>`;
}
