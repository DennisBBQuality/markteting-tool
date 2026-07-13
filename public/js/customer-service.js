// ========== Customer Service ========== 
const CustomerServiceState = {
  tickets: [],
  meta: { page: 1, per_page: 25, total: 0, last_page: 1 },
  selectedTicketId: null,
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
    <article class="cs-ticket-card" data-ticket-id="${escHtml(ticket.id)}">
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
    <span>Pagina ${escHtml(meta.page)} van ${escHtml(meta.last_page)}</span>
    <button class="btn btn-secondary btn-sm" onclick="customerServiceGoToPage(${meta.page + 1})" ${meta.page >= meta.last_page ? 'disabled' : ''}>
      Volgende <i class="fas fa-chevron-right"></i>
    </button>`;
}

function customerServiceGoToPage(page) {
  if (page < 1 || page > CustomerServiceState.meta.last_page) return;
  loadCustomerServiceTickets(page);
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
