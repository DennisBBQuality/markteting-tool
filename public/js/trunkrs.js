// Read-only carrier reports. Browser refresh never starts the mailbox import.
const Trunkrs = {
  timer: null,
  mountVersion: 0,
  modalVersion: 0,
  refreshVersion: 0,
  selectedReportId: null,
  async mount() {
    const version = ++this.mountVersion;
    clearInterval(this.timer);
    this.selectedReportId = null;
    await this.refresh();
    if (version !== this.mountVersion) return;
    this.timer = setInterval(() => {
      const tile = document.getElementById('trunkrs-tile');
      if (!tile || !tile.getClientRects().length) return;
      this.refresh();
    }, 60000);
  },
  date(value, withTime = false) {
    if (!value) return 'Nog niet bekend';
    const date = new Date(value.length === 10 ? `${value}T12:00:00Z` : value);
    if (Number.isNaN(date.getTime())) return 'Nog niet bekend';
    return new Intl.DateTimeFormat('nl-NL', {
      timeZone: 'Europe/Amsterdam', day: 'numeric', month: 'short', year: 'numeric',
      ...(withTime ? { hour: '2-digit', minute: '2-digit' } : {}),
    }).format(date);
  },
  status(code) {
    return code === 'EXCEPTION_SHIPMENT_CANCELLED_BY_SENDER'
      ? 'Geannuleerd door afzender' : code;
  },
  async request(path) {
    // Local error boundary: a report outage must not break the rest of the dashboard.
    const response = await fetch(path, { headers: { Accept: 'application/json' }, credentials: 'same-origin', cache: 'no-store', signal: AbortSignal.timeout(15000) });
    if (!response.ok) {
      const error = new Error('Rapport niet bereikbaar');
      error.status = response.status;
      throw error;
    }
    return response.json();
  },
  async refresh() {
    const tile = document.getElementById('trunkrs-tile');
    if (!tile) return;
    const version = ++this.refreshVersion;
    try {
      const path = this.selectedReportId
        ? `/api/trunkrs/summary?report_id=${encodeURIComponent(this.selectedReportId)}` : '/api/trunkrs/summary';
      const data = await this.request(path);
      if (!tile.isConnected || version !== this.refreshVersion) return;
      if (this.selectedReportId && data.report?.id !== this.selectedReportId) this.selectedReportId = null;
      tile.innerHTML = this.tileHtml(data);
    } catch (error) {
      if (!tile.isConnected || version !== this.refreshVersion) return;
      if (error.status === 401) {
        tile.innerHTML = '<div class="trunkrs-body">Log opnieuw in om de rapporten te bekijken.</div>';
        clearInterval(this.timer);
        if (typeof showLogin === 'function') showLogin();
        return;
      }
      // Do not wipe previously visible rows after a failed browser request.
      let notice = tile.querySelector('.trunkrs-fetch-error');
      if (!notice) {
        notice = document.createElement('p');
        notice.className = 'trunkrs-notice trunkrs-fetch-error';
        tile.prepend(notice);
      }
      notice.textContent = 'Het overzicht kon niet worden vernieuwd. Eventuele eerdere gegevens hieronder zijn niet opnieuw gecontroleerd.';
    }
  },
  selectReport(id) {
    this.selectedReportId = id || null;
    this.refresh();
  },
  tileHtml(data) {
    const report = data.report;
    const choices = data.available_reports || (report ? [report] : []);
    if (data.configured === false && !report) {
      return `<div class="trunkrs-compact"><i class="fas fa-truck" aria-hidden="true"></i>
        <div><h3>Niet bezorgd Trunkrs</h3><small>Nog geen rapport ingelezen</small></div>
        <details><summary>Wacht op koppeling</summary>
          ${(data.warnings || []).map(w => `<p>${escHtml(w)}</p>`).join('')}
          <p>De Microsoft 365-koppeling is nog niet actief.</p>
        </details></div>`;
    }
    return `<div class="dash-section-header">
      <h3><i class="fas fa-truck"></i> Niet bezorgd Trunkrs</h3>
      <div class="dash-section-actions">
        ${choices.length ? `<label class="trunkrs-day-picker">Bezorgdag
          <select aria-label="Bezorgdag Trunkrs-rapport" onchange="Trunkrs.selectReport(this.value)">
            ${choices.map(item => `<option value="${escHtml(item.id)}" ${item.id === report?.id ? 'selected' : ''}>${escHtml(this.date(item.report_date))}</option>`).join('')}
          </select></label>` : ''}
        <button class="btn btn-sm btn-outline" onclick="Trunkrs.refresh()" aria-label="Trunkrs-overzicht vernieuwen"><i class="fas fa-rotate"></i></button>
      </div>
    </div>
    <div class="trunkrs-body">
      ${(data.warnings || []).map(w => `<p class="trunkrs-notice">${escHtml(w)}</p>`).join('')}
      ${report ? `<div class="trunkrs-summary"><strong>${report.shipment_count}</strong><div>
        <b>Zendingen in dit rapport</b><p>Bezorgdatum: ${escHtml(this.date(report.report_date))} · Inclusief annuleringen</p>
      </div></div>
      ${this.rowsHtml(data.shipments)}
      ${report.shipment_count > 5 ? `<button class="btn btn-sm btn-outline" onclick="Trunkrs.openReport('${escHtml(report.id)}')">Alle ${report.shipment_count} zendingen bekijken</button>` : ''}
      `
      : `<div class="trunkrs-empty"><i class="fas fa-inbox"></i><div><b>Nog geen rapport ingelezen</b><p>Zodra de online koppeling actief is, verschijnt het vervoerdersoverzicht hier automatisch. Ook wanneer alle laptops uitstaan.</p></div></div>`}
    </div>`;
  },
  rowsHtml(rows) {
    if (!rows.length) return '<p>Het bestand bevat geen zendingen.</p>';
    return `<div class="trunkrs-table-scroll"><table class="trunkrs-table"><thead><tr>
      <th>Trunkrs-nummer</th><th>Barcode</th><th>Status</th><th>Redencode</th>
      </tr></thead><tbody>${rows.map(row => `<tr>
      <td>${escHtml(row.trunkrs_number)}</td><td>${escHtml(row.barcode)}</td>
      <td>${escHtml(this.status(row.status))}</td>
      <td>${escHtml(row.reason_code || 'Niet opgegeven')}</td>
      </tr>`).join('')}</tbody></table></div>`;
  },
  async openReports(page = 1) {
    const version = ++this.modalVersion;
    openModal('Niet bezorgd Trunkrs — rapporten', `<p id="trunkrs-loading-${version}">Rapporten laden…</p>`, '<button class="btn btn-outline" onclick="closeModal()">Sluiten</button>');
    try {
      const data = await this.request(`/api/trunkrs/reports?page=${page}`);
      if (!this.modalPending(version)) return;
      openModal('Niet bezorgd Trunkrs — rapporten', `<p>Alle statussen staan samen in één overzicht per rapport. Een annulering door de afzender kan ook door BBQuality zijn gedaan.</p>
        <div class="trunkrs-report-list">${data.reports.length ? data.reports.map(r => `<button class="btn btn-outline" onclick="Trunkrs.openReport('${escHtml(r.id)}')">
          Bezorgdatum ${escHtml(this.date(r.report_date))} · ${r.shipment_count} zendingen
        </button>`).join('') : '<p>Nog geen rapporten beschikbaar.</p>'}</div>`,
        `${page > 1 ? `<button class="btn btn-outline" onclick="Trunkrs.openReports(${page - 1})">Vorige</button>` : ''}
        ${page < data.last_page ? `<button class="btn btn-outline" onclick="Trunkrs.openReports(${page + 1})">Volgende</button>` : ''}
        <button class="btn btn-outline" onclick="closeModal()">Sluiten</button>`);
    } catch (_) {
      if (!this.modalPending(version)) return;
      openModal('Niet bezorgd Trunkrs', '<p role="alert">Rapporten konden niet worden geladen. Probeer het later opnieuw.</p>', '<button class="btn btn-outline" onclick="closeModal()">Sluiten</button>');
    }
  },
  async openReport(id, page = 1) {
    const version = ++this.modalVersion;
    openModal('Niet bezorgd Trunkrs', `<p id="trunkrs-loading-${version}">Zendingen laden…</p>`, '<button class="btn btn-outline" onclick="closeModal()">Sluiten</button>');
    try {
      const data = await this.request(`/api/trunkrs/reports/${encodeURIComponent(id)}?page=${page}`);
      if (!this.modalPending(version)) return;
      openModal('Niet bezorgd Trunkrs', `<p>Bezorgdatum: ${escHtml(this.date(data.report.report_date))} · ${data.report.shipment_count} zendingen, inclusief annuleringen.</p>
        ${this.rowsHtml(data.shipments)}`,
        `${page > 1 ? `<button class="btn btn-outline" onclick="Trunkrs.openReport('${escHtml(id)}', ${page - 1})">Vorige</button>` : ''}
        ${page < data.last_page ? `<button class="btn btn-outline" onclick="Trunkrs.openReport('${escHtml(id)}', ${page + 1})">Volgende</button>` : ''}
        <button class="btn btn-outline" onclick="closeModal()">Sluiten</button>`);
    } catch (_) {
      if (!this.modalPending(version)) return;
      openModal('Niet bezorgd Trunkrs', '<p role="alert">Dit rapport kon niet worden geladen.</p>', '<button class="btn btn-outline" onclick="closeModal()">Sluiten</button>');
    }
  },
  modalPending(version) {
    return this.modalVersion === version && document.getElementById(`trunkrs-loading-${version}`)
      && !document.getElementById('modal-overlay').classList.contains('hidden');
  },
};
