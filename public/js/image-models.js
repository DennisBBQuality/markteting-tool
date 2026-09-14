// Shared picker. Refresh only replaces this small component, never uploaded photos or form fields.
const ImageModelPicker = {
  states: new Map(),

  async load(scope, refresh = false) {
    const element = document.getElementById(`image-model-picker-${scope}`);
    if (!element) return;
    const previous = this.states.get(scope);
    const state = previous?.element === element ? previous : { element, selected: null, accepted: false };
    if (state.loading) return;
    this.states.set(scope, state);
    state.loading = true;
    state.error = '';
    this.render(scope);
    const data = await api(refresh ? '/api/images/models/refresh' : '/api/images/models', {
      method: refresh ? 'POST' : 'GET', silentError: true,
      onError: message => { state.error = message; },
    });
    if (this.states.get(scope) !== state || document.getElementById(element.id) !== element) return;
    state.loading = false;
    if (data) {
      state.data = data;
      // Preserve an intentional selection even when it disappears from the new catalog.
      state.selected ??= data.default_model;
    }
    this.render(scope);
  },

  selection(scope) {
    const state = this.states.get(scope);
    const entry = state?.data?.models.find(model => model.id === state.selected);
    if (!state || state.loading || !entry || entry.available !== true || (entry.experimental && !state.accepted)) return null;
    return { image_model: entry.id, accept_experimental: Boolean(state.accepted) };
  },

  choose(scope, value) {
    const state = this.states.get(scope);
    state.selected = value;
    state.accepted = false;
    state.message = '';
    this.render(scope);
  },

  accept(scope, checked) {
    this.states.get(scope).accepted = checked;
    this.render(scope);
  },

  render(scope) {
    const state = this.states.get(scope);
    if (!state?.element.isConnected) return;
    const data = state.data;
    const entry = data?.models.find(model => model.id === state.selected);
    const unavailable = data && (!entry || entry.available !== true);
    state.element.innerHTML = `
      <label for="image-model-${scope}">${scope === 'settings' ? 'Standaard afbeeldingsmodel voor het team' : 'Afbeeldingsmodel voor deze fotoset'}</label>
      <div class="image-model-row">
        <select id="image-model-${scope}" aria-describedby="image-model-help-${scope}" ${state.loading || !data ? 'disabled' : ''}
          onchange="ImageModelPicker.choose('${scope}', this.value)">
          ${data ? data.models.map(model => `<option value="${escHtml(model.id)}" ${model.id === state.selected ? 'selected' : ''} ${model.available !== true ? 'disabled' : ''}>${escHtml(model.label)}${model.experimental ? ' — nieuw, nog niet getest' : ''}${model.available === false ? ' — niet beschikbaar' : model.available === null ? ' — toegang onbekend' : ''}</option>`).join('') : '<option>Modellen ophalen…</option>'}
          ${data && !entry ? `<option selected disabled>${escHtml(state.selected)} — niet meer in de lijst</option>` : ''}
        </select>
        <button type="button" class="btn btn-outline" ${state.loading ? 'disabled' : ''} onclick="ImageModelPicker.load('${scope}', true)">
          <i class="fas fa-${state.loading ? 'spinner fa-spin' : 'rotate'}"></i> ${state.loading ? 'Ophalen…' : 'Lijst vernieuwen'}
        </button>
      </div>
      <small id="image-model-help-${scope}">Nieuwe GPT Image-modellen worden bij openen automatisch opgehaald zodra de lijst ouder is dan 24 uur. Je keuze verandert nooit vanzelf. Nieuwe fotosets bewaren hun model ook voor nabewerkingen.</small>
      ${data?.checked_at ? `<small>Laatst opgehaald: ${escHtml(new Date(data.checked_at).toLocaleString('nl-NL'))}. Beschikbaarheid is geen garantie voor beeldkwaliteit of ondersteuning van alle instellingen.</small>` : ''}
      ${data?.warning ? `<p class="image-model-notice" role="status">${escHtml(data.warning)}</p>` : ''}
      ${unavailable ? '<p class="image-model-notice" role="status">Het gekozen model is niet beschikbaar of de toegang is nog niet gecontroleerd. Kies zelf een beschikbaar model of vernieuw de lijst.</p>' : ''}
      ${entry?.experimental ? `<label class="image-model-confirm"><input type="checkbox" ${state.accepted ? 'checked' : ''} onchange="ImageModelPicker.accept('${scope}', this.checked)"> Ik wil dit nieuwe model testen. Beeldinstellingen, kwaliteit, snelheid en kosten zijn nog niet gecontroleerd voor Pitboard.</label>` : ''}
      ${state.error ? `<p class="image-model-notice" role="alert">${escHtml(state.error)}</p>` : ''}
      ${scope === 'settings' ? `<button type="button" class="btn btn-primary" ${!this.selection(scope) || state.saving ? 'disabled' : ''} onclick="ImageModelPicker.saveDefault()">${state.saving ? 'Opslaan…' : 'Standaardmodel opslaan'}</button>` : ''}
      ${state.message ? `<p role="status">${escHtml(state.message)}</p>` : ''}
    `;
    if (scope === 'generator' && typeof updateProductImageForm === 'function') updateProductImageForm();
  },

  async saveDefault() {
    const state = this.states.get('settings');
    const selection = this.selection('settings');
    if (!selection || state.saving) return;
    state.saving = true;
    state.error = '';
    state.message = '';
    this.render('settings');
    const result = await api('/api/settings/ai/openai/image-model', {
      method: 'PUT', body: selection, silentError: true,
      onError: message => { state.error = message; },
    });
    state.saving = false;
    if (result) {
      state.message = result.bericht;
      const label = document.getElementById('openai-active-model');
      if (label) label.textContent = result.model;
    }
    this.render('settings');
  },
};
