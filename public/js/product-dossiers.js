// ========== Productstudio / productdossiers ==========
let productDossierState = createProductDossierState();

function createProductDossierState() {
  return {
    dossiers: [], currentId: null, pendingLabels: [], labelImages: [], previewUrls: [], analysis: null,
    nutritionMeta: {}, ingredientSource: 'onbekend', allergenSource: 'onbekend',
    generatedContent: null, contentHistory: [], aiActive: false, saving: false, generating: false,
    analyzing: false, estimating: false, generation: null, pollTimer: null, dirty: false, savedSignature: null,
    options: { categories: [], cuts: [], selections: [] }, inferredChoices: {},
  };
}

const DOSSIER_FACT_FIELDS = [
  ['brand', 'Merk', 'Bijvoorbeeld BBQuality of El Rancho'],
  ['origin', 'Herkomst', 'Land, regio of vangstgebied'],
  ['supplier', 'Leverancier', 'Alleen invullen wanneer bekend'],
  ['details', 'Belangrijke productdetails', 'Wat maakt dit product bijzonder? Bijvoorbeeld whole packer, getrimd, huid of bekende smaken. Alleen invullen wat jullie weten.'],
  ['storage', 'Bewaren en ontdooien', 'Bewaarcondities en instructies'],
];

const DOSSIER_NUTRITION_FIELDS = [
  ['basis', 'Basis', 'Per 100 g of per 100 ml'], ['energie_kj', 'Energie (kJ)', 'Bijvoorbeeld 850 kJ'],
  ['energie_kcal', 'Energie (kcal)', 'Bijvoorbeeld 205 kcal'], ['vetten', 'Vetten', 'Bijvoorbeeld 12 g'],
  ['verzadigde_vetten', 'Waarvan verzadigd', 'Bijvoorbeeld 4,2 g'], ['koolhydraten', 'Koolhydraten', 'Bijvoorbeeld 3,1 g'],
  ['suikers', 'Waarvan suikers', 'Bijvoorbeeld 1,2 g'], ['eiwitten', 'Eiwitten', 'Bijvoorbeeld 21 g'],
  ['zout', 'Zout', 'Bijvoorbeeld 1,1 g'],
];

async function renderProductDossiers() {
  const container = document.getElementById('view-product-dossiers');
  clearTimeout(productDossierState.pollTimer);
  productDossierState.previewUrls.forEach(url => URL.revokeObjectURL(url));
  productDossierState = createProductDossierState();
  container.innerHTML = `
    <div class="page-header">
      <div><h2><i class="fas fa-box-open" style="color:var(--primary)"></i> Productstudio</h2><p style="color:var(--text-light);font-size:14px;">Van etiket en productfeiten naar een controleerbare BBQuality-productpagina.</p></div>
      <button class="btn btn-primary" type="button" onclick="newProductDossier()"><i class="fas fa-plus"></i> Nieuw product</button>
    </div>
    <div class="product-studio-notice"><i class="fas fa-file-alt"></i><div><strong>Productstudio · alleen concepten</strong><span>Er wordt niets naar WordPress verstuurd.</span></div><button type="button" class="btn btn-outline btn-sm" onclick="openDossierToneProfile()">Onze schrijfstijl</button></div>
    <div id="product-dossier-ai-status" class="product-dossier-ai-status hidden"></div>
    <div class="product-dossier-layout">
      <aside class="product-dossier-sidebar">
        <div class="product-dossier-sidebar-head"><strong>Recente concepten</strong><span id="product-dossier-count">0</span></div>
        <div id="product-dossier-list" class="product-dossier-list"><div class="product-dossier-list-empty">Concepten laden…</div></div>
      </aside>
      <main class="product-dossier-editor">
        <div class="product-dossier-editor-head"><div><span class="product-image-eyebrow">Productdossier</span><h3 id="product-dossier-editor-title">Nieuw product</h3></div><span class="dossier-save-state" id="dossier-save-state"><i class="fas fa-circle"></i> Nog niet opgeslagen</span></div>
        <ol class="product-dossier-steps">${['Bronnen','Productgegevens','Pagina-inhoud'].map((label,index) => `<li><button type="button" onclick="goToDossierStep(${index+1})"><b>${index+1}</b><span>${label}</span></button></li>`).join('')}</ol><div id="dossier-operation-status" class="hidden" role="status" aria-live="polite"></div>

        <section class="dossier-step-card" id="dossier-step-1">
          <div class="dossier-step-heading"><span>1</span><div><h4>Start met de productnaam en het etiket</h4><p>De productnaam vul je altijd zelf in. Etiketfoto’s zijn niet verplicht, maar leveren vaak direct ingrediënten, allergenen en voedingswaarden op.</p></div></div>
          <div class="dossier-source-grid">
            <div>
              <div class="form-group"><label for="dossier-product-name">Productnaam <b class="required-mark">verplicht</b></label><input id="dossier-product-name" type="text" maxlength="200" placeholder="Bijvoorbeeld Black Angus brisket El Rancho Uruguay" oninput="updateDossierTitle(); autoSelectDossierChoices()" required></div>
              <div class="form-group"><label for="dossier-product-type">Producttype <span>(optioneel)</span></label><select id="dossier-product-type"><option value="">Nog onbekend</option><option value="meat">Rauw vlees</option><option value="fish">Vis</option><option value="sauce">Saus</option><option value="rub">Rub / kruidenmix</option><option value="prepared">Samengesteld product</option><option value="bundle">Totaalpakket</option><option value="other">Anders</option></select></div>
            </div>
            <div>
              <div class="dossier-label-dropzone" id="dossier-label-dropzone" role="button" tabindex="0" onclick="document.getElementById('dossier-label-input').click()" onkeydown="handleDossierLabelKeydown(event)"><i class="fas fa-camera"></i><strong>Etiketfoto’s toevoegen</strong><span>Voorkant, achterkant of meerdere zijden · maximaal 4 foto’s</span><small>Optioneel · JPG, PNG of WEBP · maximaal 10 MB per foto</small></div>
              <input id="dossier-label-input" type="file" multiple accept="image/jpeg,image/png,image/webp" hidden onchange="handleDossierLabels(this.files)"><div id="dossier-label-preview" class="dossier-label-preview"></div>
            </div>
          </div>
          <div class="dossier-inline-actions"><button class="btn btn-primary" id="dossier-analyze-btn" type="button" onclick="analyzeProductDossier()"><i class="fas fa-wand-magic-sparkles"></i> Etiket analyseren</button><span>Alleen nodig wanneer je etiketfoto’s hebt toegevoegd. Zonder etiket kun je direct verder met stap 2.</span></div>
          <div id="dossier-analysis-result" class="dossier-analysis-result hidden"></div>
        </section>

        <section class="dossier-step-card" id="dossier-step-2">
          <div class="dossier-step-heading dossier-heading-with-action"><span>2</span><div><h4>Controleer de productgegevens</h4><p>Herkomst is nodig om stap 3 te maken. De overige lege waarden blijven bewust onbekend.</p></div><button class="btn btn-outline btn-sm" type="button" onclick="openDossierOptionManager()"><i class="fas fa-list-check"></i> Keuzelijsten beheren</button></div>
          <div class="dossier-fields-grid">
            <div class="form-group"><label for="dossier-category">Categorie <span>(optioneel)</span></label><select id="dossier-category"><option value="">Nog onbekend</option></select></div>
            <div class="form-group"><label for="dossier-cut">Snit <span>(optioneel)</span></label><select id="dossier-cut"><option value="">Nog onbekend</option></select></div>
            <div class="form-group"><label for="dossier-selection">Selectie <span>(optioneel)</span></label><select id="dossier-selection"><option value="">Geen selectie</option></select></div>
            ${DOSSIER_FACT_FIELDS.map(([key,label,placeholder]) => `<div class="form-group ${['storage','details'].includes(key) ? 'dossier-field-wide' : ''}"><label for="dossier-fact-${key}">${label} ${key === 'origin' ? '<b class="required-mark">nodig voor stap 3</b>' : '<span>(optioneel)</span>'}</label>${['storage','details'].includes(key) ? `<textarea id="dossier-fact-${key}" rows="3" maxlength="5000" placeholder="${placeholder}"></textarea>` : `<input id="dossier-fact-${key}" type="text" maxlength="1000" placeholder="${placeholder}">`}</div>`).join('')}
          </div>

          <div class="dossier-subsection-head"><div><h5>Tip van onze vakman</h5><p>Bereidingsadvies komt uitsluitend uit deze expertstip; AI verzint nooit een uitspraak of expert.</p></div></div>
          <div class="dossier-fields-grid">
            <div class="form-group"><label for="dossier-expert-name">Naam expert <span>(optioneel)</span></label><input id="dossier-expert-name" type="text" maxlength="160"></div>
            <div class="form-group"><label for="dossier-expert-role">Functie / specialisme <span>(optioneel)</span></label><input id="dossier-expert-role" type="text" maxlength="200"></div>
            <div class="form-group dossier-field-wide"><label for="dossier-expert-tip">Expertstip en bereidingsadvies <span>(optioneel)</span></label><textarea id="dossier-expert-tip" rows="4" maxlength="5000" placeholder="Persoonlijk advies van de slager of BBQ-expert"></textarea></div>
          </div>
          <div class="expert-approval-note"><i class="fas fa-shield-check"></i><span>AI mag de aangeleverde tip redigeren, maar zet nooit zelfstandig een uitspraak op naam van een medewerker.</span></div>
          <div class="dossier-two-columns dossier-expert-assets">${[['photo','Foto van de vakman'],['signature','Persoonlijke handtekening']].map(([kind,label]) => `<div class="form-group"><label for="dossier-expert-${kind}">${label} (optioneel)</label><input type="file" id="dossier-expert-${kind}" accept="image/png,image/jpeg,image/webp" onchange="uploadDossierExpertAsset('${kind}',this.files[0])"><div id="dossier-expert-preview-${kind}"></div></div>`).join('')}</div>
          <label class="expert-approval-note"><input type="checkbox" id="dossier-expert-approved"> De genoemde vakman heeft deze tip en zijn persoonlijke presentatie goedgekeurd.</label>

          <div class="dossier-subsection-head"><div><h5>Voedingswaarden, ingrediënten en allergenen</h5><p>Etiketgegevens hebben voorrang. Ontbrekende waarden worden waar verantwoord als AI-schatting gemarkeerd.</p></div><button class="btn btn-outline btn-sm" id="dossier-estimate-nutrition-btn" type="button" onclick="estimateDossierNutrition()"><i class="fas fa-calculator"></i> Ontbrekende gegevens aanvullen</button></div>
          <div class="dossier-two-columns">
            <div class="form-group"><label for="dossier-ingredients">Ingrediënten <span>(optioneel)</span></label><textarea id="dossier-ingredients" rows="5" maxlength="20000" placeholder="Letterlijk van het etiket of duidelijk gemarkeerd als AI-schatting"></textarea><div id="dossier-ingredients-source" class="field-source-badge unknown">Bron onbekend</div></div>
            <div class="form-group"><label for="dossier-allergens">Allergenen <span>(optioneel)</span></label><textarea id="dossier-allergens" rows="5" maxlength="5000" placeholder="Van het etiket of automatisch afgeleid uit de ingrediënten"></textarea><div id="dossier-allergens-source" class="field-source-badge unknown">Bron onbekend</div></div>
          </div>
          <div id="dossier-nutrition-source" class="nutrition-source-badge unknown" data-source="onbekend"><i class="fas fa-circle-question"></i> Bron nog onbekend</div>
          <div class="dossier-nutrition-grid">${DOSSIER_NUTRITION_FIELDS.map(([key,label,placeholder]) => `<div class="form-group"><label for="dossier-nutrition-${key}">${label}</label><input id="dossier-nutrition-${key}" type="text" maxlength="100" placeholder="${placeholder}"></div>`).join('')}</div>
          <div id="dossier-nutrition-warning" class="dossier-estimate-warning hidden"></div>
          <div id="dossier-composition-warning" class="dossier-estimate-warning hidden"></div>
          <details class="dossier-review-confirmations"><summary>Gegevens gecontroleerd voor de productpagina</summary><p>Vink alleen aan na controle met een etiket, receptuur of leveranciersspecificatie. Een AI-schatting op zichzelf is geen bevestiging. Dit is niet nodig om een concept op te slaan.</p>${[['ingredients','Ingrediënten'],['allergens','Allergenen'],['nutrition','Voedingswaarden']].map(([key,label]) => `<label><input type="checkbox" id="dossier-reviewed-${key}"> ${label} gecontroleerd en bevestigd</label>`).join('')}</details>
        </section>

        <section class="dossier-step-card" id="dossier-step-3">
          <div class="dossier-step-heading"><span>3</span><div><h4>BBQuality-productpagina</h4><p>Na opslaan maakt AI de korte tekst, uitgebreide secties, 5–7 losse FAQ’s en SEO/GEO-gegevens. Elk onderdeel is apart te kopiëren.</p></div></div>
          <div id="dossier-generated-content" class="dossier-generated-content"><div class="dossier-generated-empty"><i class="fas fa-file-lines"></i><strong>Nog geen pagina-inhoud gemaakt</strong><span>Vul productnaam en herkomst in en kies hieronder “Opslaan en pagina maken”.</span></div></div>
          <div class="wordpress-preview-row"><div><i class="fab fa-wordpress"></i><span><strong>WordPress-concept</strong><small>De inhoud is gestructureerd voorbereid op de toekomstige API-koppeling.</small></span></div><div class="dossier-export-actions"><button class="btn btn-outline" type="button" onclick="exportProductDossier('json')">Concept (JSON)</button><button class="btn btn-outline" type="button" onclick="exportProductDossier('html')">Producttekst (HTML)</button></div></div>
          <div class="dossier-subsection-head"><div><h5>Productfoto’s</h5><p>Maak en controleer foto’s bij Afbeeldingen. Koppel daar de geslaagde fotoset aan dit dossier.</p></div><button class="btn btn-outline" onclick="navigateTo('converter')">Naar Afbeeldingen</button></div><div id="dossier-linked-images"></div>
        </section>

        <div class="dossier-footer-actions"><span><i class="fas fa-circle-info"></i> Alleen de productnaam is nodig om een concept tussentijds op te slaan.</span><div><button class="btn btn-outline btn-lg" id="dossier-save-only-btn" type="button" onclick="saveProductDossier()"><i class="fas fa-save"></i> Alleen concept opslaan</button><button class="btn btn-primary btn-lg" id="dossier-generate-btn" type="button" onclick="finishProductDossier()"><i class="fas fa-wand-magic-sparkles"></i> Opslaan en pagina maken</button></div></div>
      </main>
    </div>`;
  initDossierLabelDropzone();
  await Promise.all([loadProductDossierList(), loadProductDossierAiStatus(), loadProductDossierOptions()]);
  initDossierEditing();
  const lastId = sessionStorage.getItem(dossierStorageKey('last'));
  if (lastId && productDossierState.dossiers.some(item => item.id === lastId)) await openProductDossier(lastId);
  else restoreDossierBrowserDraft();
}

async function loadProductDossierAiStatus() {
  const status = await api('/api/product-dossiers/ai-status');
  productDossierState.aiActive = Boolean(status?.active);
  const container = document.getElementById('product-dossier-ai-status');
  if (!container) return;
  const aiButtons = ['dossier-analyze-btn','dossier-estimate-nutrition-btn','dossier-generate-btn'].map(id => document.getElementById(id)).filter(Boolean);
  if (productDossierState.aiActive) {
    container.className = 'product-dossier-ai-status connected';
    container.innerHTML = '<i class="fas fa-circle-check"></i><div><strong>AI-koppeling ingesteld</strong><span>Bereikbaarheid en API-tegoed worden bij de opdracht gecontroleerd.</span></div>';
    aiButtons.forEach(button => { button.disabled = false; button.title = ''; }); updateDossierAnalyzeButton();
    return;
  }
  const isAdmin = App.currentUser?.rol === 'admin';
  container.className = 'product-dossier-ai-status disconnected';
  container.innerHTML = `<i class="fas fa-link-slash"></i><div><strong>AI-koppeling nog niet ingesteld</strong><span>Je kunt dossiers en etiketten wel opslaan. Voor analyse en pagina-inhoud is een OpenAI-koppeling nodig.</span></div>${isAdmin ? '<button class="btn btn-outline btn-sm" type="button" onclick="navigateTo(\'settings\')"><i class="fas fa-gear"></i> Open AI-instellingen</button>' : '<small>Vraag een beheerder om de AI-koppeling in te stellen.</small>'}`;
  aiButtons.forEach(button => { button.disabled = true; button.title = 'Stel eerst de OpenAI-koppeling in'; }); updateDossierAnalyzeButton();
}

async function loadProductDossierOptions(preserve = {}) {
  const data = await api('/api/product-dossier-options');
  if (!data) return;
  productDossierState.options = {
    categories: Array.isArray(data.categories) ? data.categories : [],
    cuts: Array.isArray(data.cuts) ? data.cuts : [],
    selections: Array.isArray(data.selections) ? data.selections : [],
  };
  populateDossierSelect('dossier-category', productDossierState.options.categories, preserve.category, 'Nog onbekend');
  populateDossierSelect('dossier-cut', productDossierState.options.cuts, preserve.cut, 'Nog onbekend');
  populateDossierSelect('dossier-selection', productDossierState.options.selections, preserve.selection, 'Geen selectie');
}

function populateDossierSelect(id, options, preservedValue, emptyLabel) {
  const select = document.getElementById(id); if (!select) return;
  const value = preservedValue ?? select.value;
  const hasValue = value && options.some(option => option.label === value);
  select.innerHTML = `<option value="">${emptyLabel}</option>${hasValue || !value ? '' : `<option value="${escHtml(value)}">${escHtml(value)} (bewaard)</option>`}${options.map(option => `<option value="${escHtml(option.label)}">${escHtml(option.label)}</option>`).join('')}`;
  select.value = value || '';
}

function openDossierOptionManager() {
  const labels = { categories: 'Categorieën', cuts: 'Snits', selections: 'Selecties' };
  const keys = ['categories','cuts','selections'];
  openModal('Keuzelijsten beheren', `<div class="dossier-option-manager">${keys.map(key => `<section><h4>${labels[key]}</h4><div class="dossier-option-add"><input id="dossier-option-new-${key}" type="text" placeholder="Nieuwe keuze"><button class="btn btn-primary btn-sm" type="button" onclick="addDossierOption('${key}')"><i class="fas fa-plus"></i> Toevoegen</button></div><div class="dossier-option-list">${productDossierState.options[key].map(option => `<span>${escHtml(option.label)}<button type="button" title="Verwijderen" onclick="deleteDossierOption(${option.id})"><i class="fas fa-xmark"></i></button></span>`).join('')}</div></section>`).join('')}</div>`, '<button class="btn btn-outline" type="button" onclick="closeModal()">Sluiten</button>');
}

async function addDossierOption(key) {
  const input = document.getElementById(`dossier-option-new-${key}`), label = input?.value.trim();
  if (!label) return;
  const type = ({categories:'category',cuts:'cut',selections:'selection'})[key];
  const created = await api('/api/product-dossier-options', { method:'POST', body:{type,label} });
  if (!created) return;
  await loadProductDossierOptions(currentChoiceValues()); openDossierOptionManager(); toast('Keuze toegevoegd.', 'success');
}

async function deleteDossierOption(id) {
  const removed = await api(`/api/product-dossier-options/${id}`, {method:'DELETE'}); if (!removed) return;
  await loadProductDossierOptions(currentChoiceValues()); openDossierOptionManager(); toast('Keuze verwijderd.', 'success');
}

function currentChoiceValues() {
  return { category:document.getElementById('dossier-category')?.value || '', cut:document.getElementById('dossier-cut')?.value || '', selection:document.getElementById('dossier-selection')?.value || '' };
}

function initDossierLabelDropzone() {
  const dropzone = document.getElementById('dossier-label-dropzone'); if (!dropzone) return;
  dropzone.addEventListener('dragover', event => { event.preventDefault(); dropzone.classList.add('dragover'); });
  dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));
  dropzone.addEventListener('drop', event => { event.preventDefault(); dropzone.classList.remove('dragover'); handleDossierLabels(event.dataTransfer.files); });
}

function handleDossierLabelKeydown(event) {
  if (!['Enter', ' '].includes(event.key)) return;
  event.preventDefault(); document.getElementById('dossier-label-input')?.click();
}

function handleDossierLabels(fileList) {
  const files = Array.from(fileList || []), allowed = ['image/jpeg','image/png','image/webp'];
  productDossierState.previewUrls.forEach(url => URL.revokeObjectURL(url));
  productDossierState.pendingLabels = []; productDossierState.previewUrls = [];
  for (const file of files.slice(0, 4)) {
    if (!allowed.includes(file.type)) { toast(`${file.name} is geen ondersteunde afbeelding.`, 'error'); continue; }
    if (file.size > 10 * 1024 * 1024) { toast(`${file.name} is groter dan 10 MB.`, 'error'); continue; }
    productDossierState.pendingLabels.push(file); productDossierState.previewUrls.push(URL.createObjectURL(file));
  }
  if (files.length > 4) toast('Je kunt maximaal vier etiketfoto’s tegelijk gebruiken.', 'error');
  const input = document.getElementById('dossier-label-input'); if (input) input.value = '';
  renderDossierLabelPreview(); updateDossierAnalyzeButton();
}

function renderDossierLabelPreview(savedImages = []) {
  const container = document.getElementById('dossier-label-preview'); if (!container) return;
  if (productDossierState.pendingLabels.length) {
    container.innerHTML = productDossierState.pendingLabels.map((file,index) => `<div><img src="${productDossierState.previewUrls[index]}" alt="Etiketfoto ${index + 1}"><span>${escHtml(file.name)}</span></div>`).join(''); return;
  }
  container.innerHTML = savedImages.map(image => `<div class="saved"><img src="${escHtml(image.url || '')}" alt="Opgeslagen etiketfoto"><span>${escHtml(image.original_name)}</span></div>`).join('');
}

async function loadProductDossierList() {
  const data = await api('/api/product-dossiers'); productDossierState.dossiers = Array.isArray(data) ? data : []; renderProductDossierList();
}

function renderProductDossierList() {
  const list = document.getElementById('product-dossier-list'), count = document.getElementById('product-dossier-count'); if (!list || !count) return;
  count.textContent = String(productDossierState.dossiers.length);
  if (!productDossierState.dossiers.length) { list.innerHTML = '<div class="product-dossier-list-empty"><i class="fas fa-box-open"></i><span>Nog geen concepten</span><small>Sla rechts je eerste productdossier op.</small></div>'; return; }
  list.innerHTML = productDossierState.dossiers.map(item => `<button type="button" class="product-dossier-list-item ${item.id === productDossierState.currentId ? 'active' : ''}" onclick="openProductDossier('${item.id}')"><span class="dossier-list-icon"><i class="fas ${dossierTypeIcon(item.product_type)}"></i></span><span><strong>${escHtml(item.product_name)}</strong><small>${escHtml(dossierTypeLabel(item.product_type))} · ${formatDateTime(item.updated_at)}</small></span>${item.status === 'controle' ? '<i class="fas fa-file-circle-check dossier-analyzed"></i>' : item.analysis_status === 'geanalyseerd' ? '<i class="fas fa-circle-check dossier-analyzed"></i>' : ''}</button>`).join('');
}

function dossierTypeLabel(type) { return ({meat:'Rauw vlees',fish:'Vis',sauce:'Saus',rub:'Rub',prepared:'Samengesteld',bundle:'Totaalpakket',other:'Anders'})[type] || 'Type onbekend'; }
function dossierTypeIcon(type) { return ({meat:'fa-drumstick-bite',fish:'fa-fish',sauce:'fa-bottle-droplet',rub:'fa-mortar-pestle',prepared:'fa-utensils',bundle:'fa-box',other:'fa-tag'})[type] || 'fa-box-open'; }

async function newProductDossier() {
  if (dossierForegroundBusy()) return;
  if (productDossierState.dirty && document.getElementById('dossier-product-name')?.value.trim()) {
    const saved = await saveProductDossier({silent:true}); if (!saved) return;
  }
  if (productDossierState.pendingLabels.length && !confirm('Deze etiketfoto’s zijn nog niet opgeslagen. Toch een nieuw product openen?')) return;
  rememberDossierBrowserDraft(); clearTimeout(productDossierState.pollTimer);
  const { options, aiActive, dossiers } = productDossierState;
  productDossierState.previewUrls.forEach(url => URL.revokeObjectURL(url));
  productDossierState = {...createProductDossierState(), options, aiActive, dossiers};
  document.querySelectorAll('.product-dossier-editor input:not([type="file"]), .product-dossier-editor textarea').forEach(field => { field.value = ''; });
  document.querySelectorAll('.product-dossier-editor select').forEach(select => { select.value = ''; });
  document.querySelectorAll('.product-dossier-editor input[type="checkbox"]').forEach(input => { input.checked = false; });
  renderDossierExpertAssets(); document.getElementById('dossier-linked-images').innerHTML = '';
  renderDossierLabelPreview(); renderDossierAnalysis(null); setNutritionSource('onbekend'); setFieldSource('ingredients','onbekend'); setFieldSource('allergens','onbekend'); renderGeneratedProductContent(null);
  showDossierOperation(''); setDossierButtonsBusy(false); updateDossierAnalyzeButton();
  document.getElementById('dossier-save-state').textContent = 'Nog niet opgeslagen';
  sessionStorage.removeItem(dossierStorageKey('last')); updateDossierTitle(); renderProductDossierList(); restoreDossierBrowserDraft(); goToDossierStep(1);
}

async function openProductDossier(id) {
  if (dossierForegroundBusy()) return;
  if (productDossierState.pendingLabels.length && !confirm('Deze etiketfoto’s zijn nog niet opgeslagen. Toch een ander concept openen?')) return;
  rememberDossierBrowserDraft(); clearTimeout(productDossierState.pollTimer);
  const dossier = await dossierApi('/api/product-dossiers/' + encodeURIComponent(id)); if (!dossier) return;
  productDossierState.pendingLabels = [];
  productDossierState.previewUrls.forEach(url => URL.revokeObjectURL(url)); productDossierState.previewUrls = [];
  populateProductDossier(dossier); renderProductDossierList(); restoreDossierBrowserDraft();
  resumeDossierGeneration(dossier);
}

function populateProductDossier(dossier) {
  const data = dossier.data || {}, facts = data.facts || {};
  ['ingredients','allergens','nutrition'].forEach(key => { document.getElementById('dossier-reviewed-' + key).checked = Boolean(data.reviewed?.[key]); });
  productDossierState.currentId = dossier.id;
  productDossierState.updatedAt = dossier.updated_at || productDossierState.updatedAt;
  productDossierState.revision = dossier.revision || productDossierState.revision;
  productDossierState.analysis = dossier.label_analysis || null;
  if (dossier.id) sessionStorage.setItem(dossierStorageKey('last'), dossier.id);
  productDossierState.contentHistory = data.content_history || [];
  productDossierState.generation = dossier.generation || null;
  productDossierState.labelImages = Array.isArray(dossier.label_images) ? dossier.label_images : [];
  productDossierState.generatedContent = data.content || null;
  productDossierState.nutritionMeta = { assumptions:data.nutrition?.assumptions || [], warning:data.nutrition?.warning || null };
  const compositionWarnings = Array.isArray(data.composition_warnings) ? data.composition_warnings : [];
  const compositionWarningBox = document.getElementById('dossier-composition-warning');
  compositionWarningBox.className = compositionWarnings.length ? 'dossier-estimate-warning' : 'hidden';
  compositionWarningBox.innerHTML = compositionWarnings.length ? `<strong>Controle van de samenstelling</strong><ul>${compositionWarnings.map(item => `<li>${escHtml(item)}</li>`).join('')}</ul>` : '';
  setInputValue('dossier-product-name', dossier.product_name); setInputValue('dossier-product-type', dossier.product_type);
  DOSSIER_FACT_FIELDS.forEach(([key]) => setInputValue(`dossier-fact-${key}`, facts[key] ?? (key === 'supplier' ? facts.producer : null)));
  populateDossierSelect('dossier-category', productDossierState.options.categories, facts.category || '', 'Nog onbekend');
  populateDossierSelect('dossier-cut', productDossierState.options.cuts, facts.cut || facts.species_breed_cut || inferDossierOption('cuts', dossier.product_name) || '', 'Nog onbekend');
  populateDossierSelect('dossier-selection', productDossierState.options.selections, facts.selection || inferDossierOption('selections', dossier.product_name) || '', 'Geen selectie');
  setInputValue('dossier-ingredients', data.ingredients); setInputValue('dossier-allergens', data.allergens);
  setFieldSource('ingredients', data.ingredients_source_status || (data.ingredients ? 'onbekend' : 'onbekend'));
  setFieldSource('allergens', data.allergens_source_status || (data.allergens ? 'onbekend' : 'onbekend'));
  DOSSIER_NUTRITION_FIELDS.forEach(([key]) => setInputValue(`dossier-nutrition-${key}`, data.nutrition?.[key]));
  setInputValue('dossier-expert-name', data.expert?.name); setInputValue('dossier-expert-role', data.expert?.role); setInputValue('dossier-expert-tip', data.expert?.tip);
  document.getElementById('dossier-expert-approved').checked = Boolean(data.expert?.approved);
  productDossierState.expertAssets = dossier.expert_assets || {};
  renderDossierExpertAssets();
  const imageBox = document.getElementById('dossier-linked-images');
  productDossierState.imageRequests = dossier.image_requests || [];
  if (imageBox) imageBox.innerHTML = (dossier.image_requests || []).map(item => `<button class="btn btn-outline" onclick="openDossierImageSet('${item.id}')">${Number(item.count)} foto’s · ${escHtml(formatDateTime(item.updated_at))}</button>`).join('') || '<p class="generated-panel-intro">Nog geen fotoset gekoppeld.</p>';
  renderDossierLabelPreview(productDossierState.labelImages); renderDossierAnalysis(dossier.label_analysis, dossier.analysis_error); setNutritionSource(data.nutrition?.source_status || 'onbekend', data.nutrition); renderGeneratedProductContent(data.content || null); updateDossierAnalyzeButton();
  document.getElementById('dossier-save-state').innerHTML = `<i class="fas fa-circle-check"></i> Opgeslagen ${formatDateTime(dossier.updated_at)}`; updateDossierTitle();
  productDossierState.dirty = false; productDossierState.savedSignature = dossierFormSignature();
}

function setInputValue(id, value) { const element = document.getElementById(id); if (element) element.value = value ?? ''; }
function updateDossierTitle() { const name = document.getElementById('dossier-product-name')?.value.trim() || 'Nieuw product'; const title = document.getElementById('product-dossier-editor-title'); if (title) title.textContent = name; }

function inferDossierOption(key, productName) {
  const normalizedName = String(productName || '').toLocaleLowerCase('nl-NL');
  return [...(productDossierState.options[key] || [])]
    .sort((a,b) => b.label.length - a.label.length)
    .find(option => normalizedName.includes(option.label.toLocaleLowerCase('nl-NL')))?.label || null;
}

function autoSelectDossierChoices() {
  const productName = document.getElementById('dossier-product-name')?.value || '';
  const cut = document.getElementById('dossier-cut'), selection = document.getElementById('dossier-selection');
  [[cut,'cuts'],[selection,'selections']].forEach(([field,key]) => {
    if (field && (!field.value || field.value === productDossierState.inferredChoices[key])) {
      field.value = inferDossierOption(key, productName) || '';
      productDossierState.inferredChoices[key] = field.value;
    }
  });
}

function collectProductDossierData() {
  const facts = {}, nutrition = {};
  DOSSIER_FACT_FIELDS.forEach(([key]) => { facts[key] = document.getElementById(`dossier-fact-${key}`)?.value.trim() || null; });
  facts.category = document.getElementById('dossier-category')?.value || null;
  facts.cut = document.getElementById('dossier-cut')?.value || null;
  facts.selection = document.getElementById('dossier-selection')?.value || null;
  DOSSIER_NUTRITION_FIELDS.forEach(([key]) => { nutrition[key] = document.getElementById(`dossier-nutrition-${key}`)?.value.trim() || null; });
  nutrition.source_status = document.getElementById('dossier-nutrition-source')?.dataset.source || 'onbekend';
  nutrition.assumptions = productDossierState.nutritionMeta.assumptions || [];
  nutrition.warning = productDossierState.nutritionMeta.warning || null;
  nutrition.field_sources = {...(productDossierState.nutritionMeta.field_sources || {})};
  return {
    facts,
    ingredients: document.getElementById('dossier-ingredients')?.value.trim() || null,
    ingredients_source_status: productDossierState.ingredientSource,
    allergens: document.getElementById('dossier-allergens')?.value.trim() || null,
    allergens_source_status: productDossierState.allergenSource,
    nutrition,
    expert: { name:document.getElementById('dossier-expert-name')?.value.trim() || null, role:document.getElementById('dossier-expert-role')?.value.trim() || null, tip:document.getElementById('dossier-expert-tip')?.value.trim() || null, approved:Boolean(document.getElementById('dossier-expert-approved')?.checked) },
    content: productDossierState.generatedContent,
    reviewed: Object.fromEntries(['ingredients','allergens','nutrition'].map(key => [key,Boolean(document.getElementById('dossier-reviewed-' + key)?.checked)])),
  };
}

function validateProductName() {
  const input = document.getElementById('dossier-product-name');
  if (input?.value.trim()) { input.classList.remove('input-error'); return true; }
  input?.classList.add('input-error'); input?.focus(); toast('Vul eerst zelf de productnaam in.', 'error'); return false;
}

async function saveProductDossier(options = {}) {
  if (productDossierState.saving || !validateProductName()) return null;
  productDossierState.saving = true; setDossierButtonsBusy(true, options.generating ? 'Product opslaan…' : 'Concept opslaan…');
  const data = collectProductDossierData(), payload = { status:'concept', product_type:document.getElementById('dossier-product-type')?.value || null, product_name:document.getElementById('dossier-product-name')?.value.trim(), data };
  if (productDossierState.currentId && productDossierState.updatedAt) payload.expected_updated_at = productDossierState.updatedAt;
  if (productDossierState.currentId && productDossierState.revision) payload.expected_revision = productDossierState.revision;
  const url = productDossierState.currentId ? `/api/product-dossiers/${encodeURIComponent(productDossierState.currentId)}` : '/api/product-dossiers';
  let saved = await dossierApi(url, { method:productDossierState.currentId ? 'PUT' : 'POST', body:payload });
  if (saved?.id) productDossierState.currentId = saved.id;
  if (saved && productDossierState.pendingLabels.length) {
    const uploaded = await uploadPendingDossierLabels(saved);
    if (!uploaded) {
      productDossierState.saving = false; setDossierButtonsBusy(false); updateDossierAnalyzeButton();
      return null;
    }
    saved = uploaded;
  }
  productDossierState.saving = false; setDossierButtonsBusy(false); if (!saved) return null;
  productDossierState.currentId = saved.id; productDossierState.pendingLabels = []; productDossierState.previewUrls.forEach(url => URL.revokeObjectURL(url)); productDossierState.previewUrls = [];
  populateProductDossier(saved); sessionStorage.removeItem(dossierStorageKey(saved.id)); sessionStorage.removeItem(dossierStorageKey('new')); await loadProductDossierList(); if (!options.silent) { showDossierOperation('Productdossier als concept opgeslagen.', 'success'); toast('Productdossier als concept opgeslagen.', 'success'); } return saved;
}

async function uploadPendingDossierLabels(saved) {
  let uploaded = saved;

  // Verstuur iedere foto afzonderlijk. Zo kan een set van vier etiketten niet
  // worden geweigerd door een serverlimiet die voor de hele aanvraag geldt.
  for (let index = 0; index < productDossierState.pendingLabels.length; index += 1) {
    const formData = new FormData();
    formData.append('labels[]', productDossierState.pendingLabels[index]);
    formData.append('append', index === 0 ? '0' : '1');
    uploaded = await apiUpload(`/api/product-dossiers/${encodeURIComponent(saved.id)}/labels`, formData);
    if (!uploaded) return null;
    productDossierState.labelImages = Array.isArray(uploaded.label_images) ? uploaded.label_images : [];
  }

  return uploaded;
}

function setDossierButtonsBusy(busy, label = '') {
  document.querySelectorAll('.product-dossier-editor input, .product-dossier-editor select, .product-dossier-editor textarea').forEach(input => { input.disabled = busy || productDossierState.analyzing || productDossierState.estimating; });
  const save = document.getElementById('dossier-save-only-btn'), generate = document.getElementById('dossier-generate-btn');
  if (save) { save.disabled = busy || productDossierState.analyzing || productDossierState.estimating; save.innerHTML = busy ? `<i class="fas fa-spinner fa-spin"></i> ${label}` : '<i class="fas fa-save"></i> Alleen concept opslaan'; }
  if (generate) { generate.disabled = busy || productDossierState.generating || productDossierState.analyzing || productDossierState.estimating || !productDossierState.aiActive; if (!productDossierState.generating) generate.innerHTML = '<i class="fas fa-wand-magic-sparkles"></i> Opslaan en pagina maken'; }
  const estimate = document.getElementById('dossier-estimate-nutrition-btn');
  if (estimate) estimate.disabled = busy || dossierForegroundBusy() || productDossierState.generating || !productDossierState.aiActive;
  updateDossierAnalyzeButton();
}

async function analyzeProductDossier() {
  if (dossierForegroundBusy() || !productDossierState.aiActive) return;
  if (!productDossierState.pendingLabels.length && !productDossierState.labelImages.length) {
    showDossierOperation('Zonder etiket kun je verder met stap 2. Daar kun je ontbrekende gegevens laten aanvullen.', 'info'); return;
  }
  productDossierState.analyzing = true; updateDossierAnalyzeButton();
  showDossierOperation('Etiket lezen… Je invoer wordt eerst opgeslagen.', 'busy');
  try {
    const saved = await saveProductDossier({silent:true}); if (!saved) return;
    const result = await dossierApi('/api/product-dossiers/' + encodeURIComponent(saved.id) + '/generate-page', {method:'POST',body:{operation:'label'}});
    if (!result) return;
    resumeDossierGeneration(result.dossier);
  } finally {
    productDossierState.analyzing = false; resetAnalyzeButton(document.getElementById('dossier-analyze-btn')); setDossierButtonsBusy(false); updateDossierAnalyzeButton();
  }
}

function resetAnalyzeButton(button) { if (button) { button.disabled = !productDossierState.aiActive; button.innerHTML = '<i class="fas fa-wand-magic-sparkles"></i> Etiket analyseren'; } }

function updateDossierAnalyzeButton() {
  const button = document.getElementById('dossier-analyze-btn'); if (!button) return;
  const hasLabels = productDossierState.pendingLabels.length > 0 || productDossierState.labelImages.length > 0;
  button.disabled = !productDossierState.aiActive || !hasLabels || dossierForegroundBusy() || productDossierState.generating;
  button.title = hasLabels ? '' : 'Voeg eerst een etiketfoto toe; zonder etiket kun je deze stap overslaan';
}

function applyProductLabelAnalysis(analysis) {
  const map = {brand:'merk',origin:'herkomst',supplier:'producent',storage:'bewaaradvies'};
  Object.entries(map).forEach(([field,source]) => fillIfEmpty(`dossier-fact-${field}`, analysis[source]));
  if (fillIfEmpty('dossier-ingredients', analysis['ingrediënten'])) setFieldSource('ingredients', analysis['ingrediënten_bron'] || 'etiket');
  if (fillIfEmpty('dossier-allergens', analysis.allergenen)) setFieldSource('allergens', analysis.allergenen_bron || 'etiket');
  Object.entries(analysis.voedingswaarden || {}).forEach(([field,value]) => fillIfEmpty(`dossier-nutrition-${field}`, value));
  if (Object.values(analysis.voedingswaarden || {}).some(Boolean)) setNutritionSource('etiket');
  const selectedType = document.getElementById('dossier-product-type');
  if (selectedType && !selectedType.value && analysis.producttype) {
    const normalized = String(analysis.producttype).toLowerCase();
    if (normalized.includes('saus')) selectedType.value = 'sauce'; else if (normalized.includes('rub') || normalized.includes('kruid')) selectedType.value = 'rub'; else if (normalized.includes('vis')) selectedType.value = 'fish'; else if (normalized.includes('vlees')) selectedType.value = 'meat';
  }
}

function fillIfEmpty(id,value) { const input = document.getElementById(id); if (input && !input.value.trim() && value) { input.value = value; return true; } return false; }

function renderDossierAnalysis(analysis,error=null) {
  const container = document.getElementById('dossier-analysis-result'); if (!container) return;
  if (error) { container.className = 'dossier-analysis-result error'; container.innerHTML = `<i class="fas fa-triangle-exclamation"></i><div><strong>Analyse niet voltooid</strong><p>${escHtml(error)}</p></div>`; return; }
  if (!analysis) { container.className = 'dossier-analysis-result hidden'; container.innerHTML = ''; return; }
  const warnings = Array.isArray(analysis.waarschuwingen) ? analysis.waarschuwingen : [], claims = Array.isArray(analysis.claims) ? analysis.claims : [];
  container.className = 'dossier-analysis-result'; container.innerHTML = `<i class="fas fa-circle-check"></i><div><strong>Etiket geanalyseerd</strong><p>Productnaam is bewust niet aangepast. Andere gevonden gegevens zijn alleen in lege velden ingevuld.</p>${claims.length ? `<small><b>Gevonden claims:</b> ${claims.map(item => escHtml(item)).join(', ')}</small>` : ''}${warnings.length ? `<ul>${warnings.map(item => `<li>${escHtml(item)}</li>`).join('')}</ul>` : ''}</div>`;
}

async function estimateDossierNutrition() {
  if (dossierForegroundBusy() || !productDossierState.aiActive || productDossierState.generating) return;
  productDossierState.estimating = true;
  const button = document.getElementById('dossier-estimate-nutrition-btn'); button.disabled = true;
  showDossierOperation('Ontbrekende ingrediënten, allergenen en voedingswaarden aanvullen… Bestaande waarden blijven behouden.', 'busy');
  try {
    const saved = await saveProductDossier({silent:true}); if (!saved) return;
    const result = await dossierApi('/api/product-dossiers/' + encodeURIComponent(saved.id) + '/generate-page', {method:'POST',body:{operation:'supplement'}});
    if (!result) return;
    resumeDossierGeneration(result.dossier);
  } finally {
    productDossierState.estimating = false; button.disabled = !productDossierState.aiActive; setDossierButtonsBusy(false);
  }
}

function resetNutritionButton(button) { if (button) { button.disabled = !productDossierState.aiActive; button.innerHTML = '<i class="fas fa-calculator"></i> Voedingswaarden schatten'; } }

function setNutritionSource(source,nutrition={}) {
  const normalized = source === 'label' ? 'etiket' : source;
  const badge = document.getElementById('dossier-nutrition-source'), warning = document.getElementById('dossier-nutrition-warning'); if (!badge || !warning) return; badge.dataset.source = normalized;
  if (normalized === 'etiket') { productDossierState.nutritionMeta = {}; badge.className = 'nutrition-source-badge verified'; badge.innerHTML = '<i class="fas fa-tag"></i> Overgenomen uit etiket'; warning.classList.add('hidden'); }
  else if (normalized === 'ai_schatting') { const assumptions = Array.isArray(nutrition.assumptions) ? nutrition.assumptions : []; productDossierState.nutritionMeta = { assumptions, warning:nutrition.warning || null }; badge.className = 'nutrition-source-badge estimated'; badge.innerHTML = '<i class="fas fa-wand-magic-sparkles"></i> AI-schatting · controle vereist'; warning.className = 'dossier-estimate-warning'; warning.innerHTML = `<strong>Niet gebruiken als vastgestelde etiketwaarde.</strong><span>${escHtml(nutrition.warning || 'Deze waarden zijn geschat op basis van de beschikbare productinformatie.')}</span>${assumptions.length ? `<ul>${assumptions.map(item => `<li>${escHtml(item)}</li>`).join('')}</ul>` : ''}`; }
  else if (['handmatig','gemengd'].includes(normalized)) { productDossierState.nutritionMeta = nutrition; badge.className = 'nutrition-source-badge estimated'; badge.textContent = normalized === 'gemengd' ? 'Meerdere bronnen · controleer de waarden' : 'Handmatig ingevuld · controleren'; warning.className = 'dossier-estimate-warning'; warning.textContent = nutrition.warning || 'Controleer de waarden en hun bron voordat je publiceert.'; }
  else { productDossierState.nutritionMeta = {}; badge.className = 'nutrition-source-badge unknown'; badge.innerHTML = '<i class="fas fa-circle-question"></i> Bron nog onbekend'; warning.classList.add('hidden'); }
  productDossierState.nutritionMeta.field_sources = {...(nutrition.field_sources || {})};
}

function setFieldSource(field, source) {
  if (field === 'ingredients') productDossierState.ingredientSource = source;
  if (field === 'allergens') productDossierState.allergenSource = source;
  const badge = document.getElementById(`dossier-${field}-source`); if (!badge) return;
  const labels = { handmatig:'Handmatig ingevuld · controleren', etiket:'Uit etiket', ai_schatting:'AI-schatting · controleren', afgeleid_van_ingrediënten:'Afgeleid uit ingrediënten', onbekend:'Bron onbekend' };
  badge.className = `field-source-badge ${source === 'etiket' ? 'verified' : ['ai_schatting','afgeleid_van_ingrediënten'].includes(source) ? 'estimated' : 'unknown'}`;
  badge.textContent = labels[source] || labels.onbekend;
}

async function finishProductDossier() {
  if (productDossierState.generating || dossierForegroundBusy() || !validateProductName()) return;
  if (!document.getElementById('dossier-fact-origin')?.value.trim()) { goToDossierStep(2); document.getElementById('dossier-fact-origin')?.focus(); showDossierOperation('Vul eerst de herkomst in voordat stap 3 wordt gemaakt.', 'error'); return; }
  if (!productDossierState.aiActive) return;
  productDossierState.generating = true;
  showDossierOperation('Product opslaan en pagina-opdracht starten…', 'busy');
  const saved = await saveProductDossier({silent:true,generating:true});
  if (!saved) { resetGenerateButton(); return; }
  const result = await dossierApi('/api/product-dossiers/' + encodeURIComponent(saved.id) + '/generate-page', {method:'POST',body:{}});
  if (!result?.dossier) { resetGenerateButton(); return; }
  resumeDossierGeneration(result.dossier); goToDossierStep(3);
}

function resetGenerateButton() {
  productDossierState.generating = false; const button = document.getElementById('dossier-generate-btn');
  if (button) { button.disabled = !productDossierState.aiActive; button.innerHTML = '<i class="fas fa-wand-magic-sparkles"></i> Opslaan en pagina maken'; }
  setDossierButtonsBusy(false);
}

function renderGeneratedProductContent(content) {
  const container = document.getElementById('dossier-generated-content'); if (!container) return;
  productDossierState.generatedContent = content || null;
  if (!content?.short_description) { container.innerHTML = '<div class="dossier-generated-empty"><i class="fas fa-file-lines"></i><strong>Nog geen pagina-inhoud gemaakt</strong><span>Vul productnaam en herkomst in en kies hieronder “Opslaan en pagina maken”.</span></div>'; return; }
  const sections = Array.isArray(content.sections) ? content.sections : [], faqs = Array.isArray(content.faqs) ? content.faqs : [], seo = content.seo || {}, checks = Array.isArray(content.control_points) ? content.control_points : [];
  container.innerHTML = `
    ${(content.generation_version || 1) < 5 ? '<div class="dossier-estimate-warning">Eerdere tekstversie. Maak een nieuwe pagina om het huidige schrijfprofiel te gebruiken.</div>' : ''}
    ${content.input_changed_during_generation ? '<div class="dossier-estimate-warning">Productgegevens zijn gewijzigd tijdens het schrijven. Controleer deze tekst opnieuw.</div>' : ''}
    <div class="generated-toolbar"><div><strong>Concept gereed voor controle</strong><span>${sections.length} tekstsecties · ${faqs.length} veelgestelde vragen</span></div><button class="btn btn-outline btn-sm" type="button" onclick="copyGeneratedProductPage()"><i class="fas fa-copy"></i> Alles kopiëren</button><button class="btn btn-outline btn-sm" type="button" onclick="editDossierContent()">Tekst bewerken</button><button class="btn btn-outline btn-sm" type="button" onclick="openDossierHistory()">Eerdere versies</button></div>
    <nav class="generated-overview-tabs" aria-label="Onderdelen van de productpagina">
      <button type="button" class="active" data-generated-tab="copy" onclick="showGeneratedPanel('copy')"><i class="fas fa-pen-nib"></i><span><b>Producttekst</b><small>Korte en uitgebreide tekst</small></span></button>
      <button type="button" data-generated-tab="faq" onclick="showGeneratedPanel('faq')"><i class="fas fa-circle-question"></i><span><b>FAQ</b><small>${faqs.length} vragen en antwoorden</small></span></button>
      <button type="button" data-generated-tab="seo" onclick="showGeneratedPanel('seo')"><i class="fas fa-magnifying-glass"></i><span><b>SEO & GEO</b><small>Zoekmachines en LLM's</small></span></button>
      <button type="button" data-generated-tab="check" onclick="showGeneratedPanel('check')"><i class="fas fa-clipboard-check"></i><span><b>Controle</b><small>${checks.length || 0} aandachtspunten</small></span></button>
    </nav>
    <section class="generated-panel" data-generated-panel="copy">
      <div class="generated-panel-heading"><div><span>Onderdeel 1</span><h5>Producttekst voor de PDP</h5></div><button class="btn btn-outline btn-sm" type="button" onclick="copyGeneratedPart('copy')"><i class="fas fa-copy"></i> Volledige producttekst</button></div>
      <article class="generated-card generated-short-copy"><header><span>Korte producttekst</span><button type="button" onclick="copyGeneratedPart('short')"><i class="fas fa-copy"></i> Kopieer</button></header><p>${formatGeneratedText(content.short_description)}</p></article>
      <div class="generated-copy-flow">${sections.map((section,index) => `<article class="generated-card"><header><span>Tekstblok ${index + 1}</span><button type="button" onclick="copyGeneratedPart('section',${index})"><i class="fas fa-copy"></i> Kopieer</button></header><h5>${escHtml(section.kop)}</h5><p>${formatGeneratedText(section.tekst)}</p></article>`).join('')}</div>
    </section>
    <section class="generated-panel hidden" data-generated-panel="faq">
      <div class="generated-panel-heading"><div><span>Onderdeel 2</span><h5>Veelgestelde vragen</h5></div><button class="btn btn-outline btn-sm" type="button" onclick="copyGeneratedPart('faqs')"><i class="fas fa-copy"></i> Alle FAQ's</button></div>
      <div class="generated-faq-list">${faqs.map((faq,index) => `<details class="generated-faq" ${index === 0 ? 'open' : ''}><summary><span><b>Vraag ${index + 1}</b>${escHtml(faq.vraag)}</span><i class="fas fa-chevron-down"></i></summary><div><p>${formatGeneratedText(faq.antwoord)}</p><button type="button" onclick="copyGeneratedPart('question',${index})">Kopieer vraag</button><button type="button" onclick="copyGeneratedPart('answer',${index})">Kopieer antwoord</button><button type="button" onclick="copyGeneratedPart('faq',${index})"><i class="fas fa-copy"></i> Kopieer vraag en antwoord</button></div></details>`).join('')}</div>
    </section>
    <section class="generated-panel hidden" data-generated-panel="seo">
      <div class="generated-panel-heading"><div><span>Onderdeel 3</span><h5>SEO & vindbaarheid voor LLM's</h5></div></div>
      <p class="generated-panel-intro">Deze gegevens worden later rechtstreeks gebruikt door de WordPress-koppeling. De samenvatting is productinformatie, geen speciale LLM-code. Vindbaarheid vraagt ook een toegankelijke, indexeerbare productpagina; aanbevelingen zijn niet te garanderen.</p>
      <div class="generated-seo-grid">${[['SEO-titel','titel'],['Metaomschrijving','metaomschrijving'],['Slug','slug'],['Entiteitssamenvatting','entiteitssamenvatting']].map(([label,key]) => `<article><span>${label}</span><p>${formatGeneratedText(seo[key] || '')}</p><button type="button" onclick="copyGeneratedPart('seo','${key}')"><i class="fas fa-copy"></i></button></article>`).join('')}</div>
    </section>
    <section class="generated-panel hidden" data-generated-panel="check">
      <div class="generated-panel-heading"><div><span>Onderdeel 4 · alleen intern</span><h5>Controle vóór publicatie</h5></div></div>
      ${checks.length ? `<div class="generated-checks"><strong><i class="fas fa-triangle-exclamation"></i> Controleer alleen deze punten</strong><ul>${checks.map(item => `<li>${escHtml(item)}</li>`).join('')}</ul></div>` : '<div class="generated-checks complete"><strong><i class="fas fa-circle-check"></i> AI heeft geen aanvullende controlepunten gemeld</strong></div>'}
    </section>`;
}

function showGeneratedPanel(panel) {
  document.querySelectorAll('[data-generated-panel]').forEach(element => element.classList.toggle('hidden', element.dataset.generatedPanel !== panel));
  document.querySelectorAll('[data-generated-tab]').forEach(element => element.classList.toggle('active', element.dataset.generatedTab === panel));
}

function formatGeneratedText(value) { return escHtml(String(value || '')).replace(/\n/g, '<br>'); }

function generatedPartText(type, index) {
  const content = productDossierState.generatedContent || {};
  if (type === 'short') return content.short_description || '';
  if (type === 'copy') return [content.short_description, ...(content.sections || []).map(item => `${item.kop}\n\n${item.tekst}`)].filter(Boolean).join('\n\n');
  if (type === 'faqs') return (content.faqs || []).map(item => `${item.vraag}\n\n${item.antwoord}`).join('\n\n');
  if (type === 'section') { const item = content.sections?.[index]; return item ? `${item.kop}\n\n${item.tekst}` : ''; }
  if (type === 'faq') { const item = content.faqs?.[index]; return item ? `${item.vraag}\n\n${item.antwoord}` : ''; }
  if (type === 'question') return content.faqs?.[index]?.vraag || '';
  if (type === 'answer') return content.faqs?.[index]?.antwoord || '';
  if (type === 'seo') return content.seo?.[index] || '';
  return '';
}

async function copyGeneratedPart(type,index) {
  const value = generatedPartText(type,index); if (!value) return;
  await navigator.clipboard.writeText(value); toast('Onderdeel gekopieerd.', 'success');
}

async function copyGeneratedProductPage() {
  const content = productDossierState.generatedContent || {}, parts = [];
  if (content.short_description) parts.push(`KORTE PRODUCTTEKST\n${content.short_description}`);
  (content.sections || []).forEach(item => parts.push(`${String(item.kop || '').toUpperCase()}\n${item.tekst || ''}`));
  if (content.faqs?.length) parts.push(`VEELGESTELDE VRAGEN\n${content.faqs.map(item => `${item.vraag}\n${item.antwoord}`).join('\n\n')}`);
  if (content.seo) parts.push(`SEO\nTitel: ${content.seo.titel || ''}\nMetaomschrijving: ${content.seo.metaomschrijving || ''}\nSlug: ${content.seo.slug || ''}\nEntiteitssamenvatting: ${content.seo.entiteitssamenvatting || ''}`);
  await navigator.clipboard.writeText(parts.join('\n\n')); toast('De volledige productpagina is gekopieerd.', 'success');
}

// Durable server progress; browser drafts are isolated per local session and user.
function dossierForegroundBusy() { return productDossierState.saving || productDossierState.analyzing || productDossierState.estimating; }
function dossierStorageKey(id) { return `bbquality-studio:${App.currentUser?.id || 'local'}:${id}`; }
function dossierFormSignature() {
  return JSON.stringify({product_name:document.getElementById('dossier-product-name')?.value, product_type:document.getElementById('dossier-product-type')?.value, data:collectProductDossierData()});
}
function showDossierOperation(message, type = 'info') {
  const box = document.getElementById('dossier-operation-status'); if (!box) return;
  box.className = message ? `dossier-operation ${type}` : 'hidden';
  box.innerHTML = message ? `<i class="fas ${type === 'busy' ? 'fa-spinner fa-spin' : type === 'error' ? 'fa-triangle-exclamation' : 'fa-circle-info'}"></i><span>${escHtml(message)}</span>` : '';
}
function dossierApi(url, options = {}) {
  return api(url, {...options, onError:message => { showDossierOperation(message, 'error'); options.onError?.(message); }});
}
function goToDossierStep(step) {
  document.getElementById(`dossier-step-${step}`)?.scrollIntoView({behavior:'smooth',block:'start'});
  document.querySelectorAll('.product-dossier-steps li').forEach((item,index) => item.classList.toggle('active', index === step - 1));
}
function openDossierImageSet(id) {
  rememberDossierBrowserDraft(); sessionStorage.setItem(PRODUCT_IMAGE_REQUEST_KEY, id); navigateTo('converter');
}
function initDossierEditing() {
  document.querySelector('.product-dossier-editor')?.addEventListener('input', event => {
    if (!event.target.matches('input,textarea,select') || event.target.type === 'file') return;
    if (event.target.id === 'dossier-cut') delete productDossierState.inferredChoices.cuts;
    if (event.target.id === 'dossier-selection') delete productDossierState.inferredChoices.selections;
    if (['dossier-expert-name','dossier-expert-role','dossier-expert-tip'].includes(event.target.id)) document.getElementById('dossier-expert-approved').checked = false;
    if (event.target.id === 'dossier-ingredients') setFieldSource('ingredients', 'handmatig');
    if (event.target.id === 'dossier-allergens') setFieldSource('allergens', 'handmatig');
    if (event.target.id === 'dossier-ingredients') { document.getElementById('dossier-reviewed-ingredients').checked = false; document.getElementById('dossier-reviewed-allergens').checked = false; }
    if (event.target.id === 'dossier-allergens') document.getElementById('dossier-reviewed-allergens').checked = false;
    if (event.target.id.startsWith('dossier-nutrition-')) {
      document.getElementById('dossier-reviewed-nutrition').checked = false;
      const field = event.target.id.replace('dossier-nutrition-', '');
      const nutrition = collectProductDossierData().nutrition;
      nutrition.field_sources[field] = 'handmatig';
      setNutritionSource('gemengd', nutrition);
    }
    productDossierState.dirty = true;
    document.getElementById('dossier-save-state').textContent = 'Niet-opgeslagen wijzigingen · browserherstel actief';
    rememberDossierBrowserDraft();
  });
}
function rememberDossierBrowserDraft() {
  if (!productDossierState.dirty || !document.getElementById('dossier-product-name')) return;
  try { sessionStorage.setItem(dossierStorageKey(productDossierState.currentId || 'new'), dossierFormSignature()); } catch (_) { /* Server save stays available if browser storage is full. */ }
}
function restoreDossierBrowserDraft() {
  let draft;
  try { draft = JSON.parse(sessionStorage.getItem(dossierStorageKey(productDossierState.currentId || 'new')) || 'null'); } catch (_) { return; }
  if (!draft?.data) return;
  const currentId = productDossierState.currentId;
  const restoredData = {...draft.data, content_history:productDossierState.contentHistory};
  if (!draft.data.content?.edited_at) restoredData.content = productDossierState.generatedContent;
  const saved = { id:currentId, product_name:draft.product_name, product_type:draft.product_type, data:restoredData, image_requests:productDossierState.imageRequests, expert_assets:productDossierState.expertAssets, label_images:productDossierState.labelImages, label_analysis:productDossierState.analysis, generation:productDossierState.generation };
  // Restore typed fields, never local file selections or credentials.
  populateProductDossier(saved); productDossierState.currentId = currentId; productDossierState.dirty = true;
  document.getElementById('dossier-save-state').textContent = 'Browserconcept hersteld · nog opslaan';
  showDossierOperation('Niet-opgeslagen tekst is uit deze browsersessie hersteld. Controleer de gegevens en sla het concept op.', 'info');
}
function resumeDossierGeneration(dossier) {
  clearTimeout(productDossierState.pollTimer);
  const generation = dossier.generation || {};
  productDossierState.generation = generation;
  if (!['queued','processing'].includes(generation.status)) {
    resetGenerateButton();
    if (generation.status === 'failed') showDossierOperation(generation.error || 'De opdracht is mislukt. Je eerdere tekst is bewaard.', 'error');
    return;
  }
  productDossierState.generating = true; setDossierButtonsBusy(false); updateDossierAnalyzeButton();
  const button = document.getElementById('dossier-generate-btn');
  if (button) button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> AI werkt op de achtergrond…';
  const seconds = Math.max(0, Math.floor((Date.now() - Date.parse(generation.requested_at)) / 1000));
  showDossierOperation((generation.status === 'queued' ? 'De opdracht staat in de wachtrij.' : 'AI verwerkt de productgegevens en controleert het resultaat.') + ` ${Math.floor(seconds/60)}:${String(seconds%60).padStart(2,'0')} verstreken. Je kunt dit concept later opnieuw openen.`, 'busy');
  productDossierState.pollTimer = setTimeout(() => pollDossierGeneration(dossier.id), 3000);
}
async function pollDossierGeneration(id) {
  if (id !== productDossierState.currentId || App.currentView !== 'product-dossiers') return;
  const result = await api('/api/product-dossiers/' + encodeURIComponent(id), {silentError:true});
  if (id !== productDossierState.currentId) return;
  if (!result) {
    showDossierOperation('De verbinding is even weg. Je opdracht blijft op de server staan; de status wordt opnieuw opgehaald.', 'info');
    productDossierState.pollTimer = setTimeout(() => pollDossierGeneration(id), 10000); return;
  }
  if (result.generation?.status === 'completed') {
    if (!productDossierState.dirty) populateProductDossier(result);
    else {
      productDossierState.contentHistory = result.data?.content_history || [];
      productDossierState.updatedAt = result.updated_at;
      productDossierState.revision = result.revision;
      renderGeneratedProductContent(result.data?.content);
      rememberDossierBrowserDraft();
    }
    resetGenerateButton(); updateDossierAnalyzeButton(); await loadProductDossierList();
    const messages = {label:'Etiket verwerkt. Controleer de ingevulde gegevens en bronmeldingen.',supplement:'Ontbrekende gegevens zijn beoordeeld. Controleer de schattingen en eventuele meldingen over onbekende samenstelling.',page:'De productpagina is klaar. Controleer de tekst en voorgestelde gegevens vóór publicatie.'};
    showDossierOperation(productDossierState.dirty ? 'De opdracht is klaar. Je niet-opgeslagen wijzigingen zijn behouden; controleer de tekst vóór opslaan.' : (messages[result.generation.operation] || messages.page), 'success');
    return;
  }
  resumeDossierGeneration(result);
}
async function openDossierToneProfile() {
  const profile = await dossierApi('/api/product-dossiers/tone-profile'); if (!profile) return;
  openModal('Onze BBQuality-schrijfstijl', `<p><strong>${escHtml(profile.name)}</strong> · versie ${Number(profile.version)}</p><ul>${(profile.principles || []).map(item => `<li>${escHtml(item)}</li>`).join('')}</ul><h4>Echte PDP-voorbeelden</h4><p>Deze voorbeelden bepalen de toon. Productclaims worden niet overgenomen naar andere producten.</p>${(profile.sources || []).map(item => `<article class="dossier-style-source"><a href="${escHtml(item.url)}" target="_blank" rel="noopener">${escHtml(item.title)}</a>${item.excerpt ? `<blockquote>${escHtml(item.excerpt)}</blockquote>` : ''}<p>${escHtml(item.lesson)}</p></article>`).join('')}`, '<button class="btn btn-outline" onclick="closeModal()">Sluiten</button>');
}
function editDossierContent() {
  const c = productDossierState.generatedContent; if (!c) return;
  const field = (id,label,value,rows=4) => `<div class="form-group"><label for="${id}">${label}</label><textarea id="${id}" rows="${rows}">${escHtml(value || '')}</textarea></div>`;
  openModal('Producttekst bewerken', field('edit-dossier-short','Korte producttekst',c.short_description) + (c.sections || []).map((x,i) => field(`edit-dossier-heading-${i}`,`Tussenkop ${i+1}`,x.kop,1)+field(`edit-dossier-section-${i}`,'Tekst',x.tekst)).join('') + '<h4>Veelgestelde vragen</h4>' + (c.faqs || []).map((x,i) => field(`edit-dossier-question-${i}`,`Vraag ${i+1}`,x.vraag,2)+field(`edit-dossier-answer-${i}`,'Antwoord',x.antwoord)).join('') + '<h4>SEO</h4>' + Object.entries(c.seo || {}).map(([key,value]) => field(`edit-dossier-seo-${key}`,key,value,2)).join(''), '<button class="btn btn-outline" onclick="closeModal()">Annuleren</button><button class="btn btn-primary" onclick="applyDossierContentEdit()">Wijzigingen overnemen</button>');
}
function applyDossierContentEdit() {
  const c = structuredClone(productDossierState.generatedContent), value=id => document.getElementById(id)?.value.trim() || '';
  c.short_description = value('edit-dossier-short');
  c.sections = c.sections.map((x,i) => ({kop:value(`edit-dossier-heading-${i}`),tekst:value(`edit-dossier-section-${i}`)}));
  c.faqs = c.faqs.map((x,i) => ({vraag:value(`edit-dossier-question-${i}`),antwoord:value(`edit-dossier-answer-${i}`)}));
  Object.keys(c.seo || {}).forEach(key => { c.seo[key] = value(`edit-dossier-seo-${key}`); });
  c.edited_at = new Date().toISOString();
  renderGeneratedProductContent(c); productDossierState.dirty = true; rememberDossierBrowserDraft(); closeModal();
  showDossierOperation('Tekst aangepast. Kies “Alleen concept opslaan” om deze versie te bewaren.', 'info');
}
function openDossierHistory() {
  const history = productDossierState.contentHistory;
  openModal('Eerdere tekstversies', history.length ? history.map((item,index) => `<article class="dossier-style-source"><strong>${escHtml(formatDateTime(item.edited_at || item.generated_at))}</strong><p>${escHtml(item.short_description)}</p><button class="btn btn-outline btn-sm" onclick="restoreDossierContent(${index})">Deze versie terugzetten</button></article>`).reverse().join('') : '<p>Er zijn nog geen eerdere versies. Bij opnieuw schrijven of een opgeslagen tekstwijziging wordt de vorige versie bewaard (maximaal 10).</p>', '<button class="btn btn-outline" onclick="closeModal()">Sluiten</button>');
}
function restoreDossierContent(index) {
  const content = productDossierState.contentHistory[index]; if (!content) return;
  renderGeneratedProductContent(structuredClone(content)); productDossierState.dirty = true; rememberDossierBrowserDraft(); closeModal();
  showDossierOperation('Eerdere tekst teruggezet in de editor. Sla het concept op om dit te bewaren.', 'info');
}
async function exportProductDossier(format) {
  const saved = await saveProductDossier({silent:true}); if (!saved) return;
  const link = document.createElement('a'); link.href = `/api/product-dossiers/${encodeURIComponent(saved.id)}/export?format=${format}`; link.click();
}
window.addEventListener('beforeunload', event => {
  rememberDossierBrowserDraft();
  if (productDossierState.pendingLabels.length) { event.preventDefault(); event.returnValue = ''; }
});

function renderDossierExpertAssets() {
  ['photo','signature'].forEach(kind => {
    const box = document.getElementById('dossier-expert-preview-' + kind), asset = productDossierState.expertAssets?.[kind];
    if (box) box.innerHTML = asset ? `<img src="${escHtml(asset.url)}" alt="${kind === 'photo' ? 'Foto vakman' : 'Handtekening vakman'}" style="max-width:160px;max-height:120px;object-fit:contain;margin-top:8px"><p>${escHtml(asset.original_name)}</p>` : '';
  });
}
async function uploadDossierExpertAsset(kind, file) {
  if (!file || dossierForegroundBusy()) return;
  if (!['image/png','image/jpeg','image/webp'].includes(file.type) || file.size > 5*1024*1024) { showDossierOperation('Gebruik een JPG, PNG of WEBP kleiner dan 5 MB.', 'error'); return; }
  const saved = await saveProductDossier({silent:true}); if (!saved) return;
  const form = new FormData(); form.append('file', file);
  const uploaded = await apiUpload('/api/product-dossiers/' + saved.id + '/expert-assets/' + kind, form);
  if (uploaded) { populateProductDossier(uploaded); showDossierOperation('Persoonlijke afbeelding opgeslagen. Laat de vakman de tip en presentatie goedkeuren.', 'success'); }
}
