// ========== Image Converter ==========
let converterState = {
  files: [],
  results: [],
  converting: false,
  seoData: {}, // { index: { beschrijving, bestandsnaam, altTekst, titel, slug } }
};

let productImageState = {
  files: [],
  previewUrls: [],
  mainIndex: 0,
  productType: 'meat',
  results: [],
  generating: false,
  requestId: null,
  completedRequestId: null,
  pollTimer: null,
  pollFailures: 0,
};

const PRODUCT_IMAGE_REQUEST_KEY = 'pitboard-product-image-request';

// Nederlandse stopwoorden voor bestandsnamen en titels
const NL_STOPWOORDEN = [
  'van', 'op', 'een', 'de', 'het', 'in', 'met', 'voor', 'aan', 'bij',
  'door', 'naar', 'om', 'over', 'uit', 'tot', 'te', 'en', 'of', 'maar',
  'als', 'dan', 'die', 'dat', 'deze', 'dit', 'zijn', 'wordt', 'worden',
  'werd', 'is', 'was', 'heeft', 'hebben', 'kan', 'zal', 'zou', 'mag',
  'moet', 'er', 'hier', 'daar', 'zo', 'nog', 'wel', 'niet', 'ook', 'al'
];

function renderConverter() {
  const container = document.getElementById('view-converter');

  productImageState.previewUrls.forEach(url => URL.revokeObjectURL(url));
  if (productImageState.pollTimer) clearTimeout(productImageState.pollTimer);
  const pendingRequestId = sessionStorage.getItem(PRODUCT_IMAGE_REQUEST_KEY);
  productImageState = {
    files: [],
    previewUrls: [],
    mainIndex: 0,
    productType: 'meat',
    results: [],
    generating: Boolean(pendingRequestId),
    requestId: pendingRequestId,
    completedRequestId: null,
    pollTimer: null,
    pollFailures: 0,
  };

  container.innerHTML = `
    <div class="page-header">
      <h2><i class="fas fa-image" style="color:var(--primary)"></i> Afbeeldingen</h2>
      <p style="color:var(--text-light);font-size:14px;">Maak productfoto's en converteer afbeeldingen naar WEBP</p>
    </div>

    <section class="product-image-card" aria-labelledby="product-image-title">
      <div class="product-image-header">
        <div>
          <span class="product-image-eyebrow">AI productfotografie</span>
          <h3 id="product-image-title">Productfoto Generator</h3>
          <p>Maak betrouwbare productfoto's vanuit maximaal vijf echte referentiefoto's.</p>
          <p><i class="fas fa-circle-check" style="color:var(--success);"></i> Goedgekeurde BBQuality-stijlbibliotheek actief: bereide varianten krijgen verschillende sferen.</p>
        </div>
        <button class="btn btn-outline" type="button" onclick="openProductPromptModal()">
          <i class="fas fa-pen"></i> Prompt instellen
        </button>
      </div>

      <div class="product-image-form">
        <div class="product-type-picker" role="radiogroup" aria-label="Soort opdracht">
          <button type="button" class="active" data-type="meat" onclick="setProductImageType('meat')"><i class="fas fa-drumstick-bite"></i><strong>Vlees</strong><span>2 rauw + 2 bereid</span></button>
          <button type="button" data-type="sauce" onclick="setProductImageType('sauce')"><i class="fas fa-bottle-droplet"></i><strong>Saus of rub</strong><span>2 productfoto's</span></button>
          <button type="button" data-type="bundle" onclick="setProductImageType('bundle')"><i class="fas fa-box-open"></i><strong>Totaalpakket</strong><span>2 totaalbeelden</span></button>
        </div>
        <div class="product-image-fields">
          <div class="form-group"><label for="product-image-name">Productnaam *</label><input id="product-image-name" type="text" maxlength="160" placeholder="Bijvoorbeeld Black Angus picanha" oninput="updateProductImageForm()"></div>
          <div class="form-group"><label for="product-image-quantity">Exact aantal *</label><input id="product-image-quantity" type="number" min="1" max="100" value="1" oninput="updateProductImageForm()"><small>Dit aantal wordt in iedere foto aangehouden.</small></div>
        </div>
        <div class="form-group"><label for="product-image-notes">Belangrijke productdetails <span>(optioneel)</span></label><textarea id="product-image-notes" rows="2" maxlength="2000" placeholder="Bijvoorbeeld herkomst, marmering of gewenste bereiding"></textarea></div>
        <div class="form-group hidden" id="product-image-components-group"><label for="product-image-components">Onderdelen zonder eigen foto <span>(indien van toepassing)</span></label><textarea id="product-image-components" rows="3" maxlength="3000" placeholder="Beschrijf ieder ontbrekend onderdeel en het exacte aantal"></textarea></div>
      </div>

      <div class="product-image-workspace">
        <div class="product-image-dropzone" id="product-image-dropzone"
          role="button" tabindex="0"
          onclick="document.getElementById('product-image-file-input').click()"
          onkeydown="handleProductImageDropzoneKeydown(event)">
          <i class="fas fa-camera"></i>
          <h4>Upload 1 tot 5 referentiefoto's</h4>
          <p>Sleep foto's hierheen of klik om te kiezen</p>
          <span>JPG, PNG of WEBP · maximaal 10 MB per foto</span>
        </div>
        <input type="file" id="product-image-file-input" multiple accept="image/jpeg,image/png,image/webp"
          style="display:none" onchange="handleProductImageFiles(this.files)">
        <div class="product-image-selection" id="product-image-selection">
          <div class="product-image-empty-preview">
            <i class="fas fa-drumstick-bite"></i>
            <span>Je referentiefoto's verschijnen hier</span>
          </div>
        </div>
      </div>

      <div class="product-image-actions">
        <button class="btn btn-primary btn-lg" id="product-image-generate-btn" type="button"
          onclick="startProductImageGeneration()" disabled>
          <i class="fas fa-wand-magic-sparkles"></i> Maak productfoto
        </button>
        <span class="product-image-action-hint" id="product-image-action-hint">De afbeeldingen worden op de achtergrond gemaakt. Je mag ondertussen verder werken.</span>
      </div>

      <div class="product-image-status hidden" id="product-image-status" role="status" aria-live="polite"></div>
      <div class="product-image-results hidden" id="product-image-results" aria-live="polite"></div>
    </section>

    <section class="webp-converter-section" aria-labelledby="webp-converter-title">
      <div class="section-heading">
        <span class="product-image-eyebrow">Optimaliseren</span>
        <h3 id="webp-converter-title">Converter naar WEBP</h3>
        <p>Converteer afbeeldingen naar WEBP en genereer SEO-optimale metadata.</p>
      </div>

      <div class="converter-layout">
      <!-- Upload zone -->
      <div class="converter-upload-card">
        <div class="converter-dropzone" id="converter-dropzone"
          onclick="document.getElementById('converter-file-input').click()">
          <i class="fas fa-cloud-upload-alt"></i>
          <h3>Sleep bestanden hierheen</h3>
          <p>of klik om bestanden te selecteren</p>
          <span class="converter-formats">JPG, JPEG, PNG, GIF, BMP, TIFF, PDF</span>
        </div>
        <input type="file" id="converter-file-input" multiple
          accept=".jpg,.jpeg,.png,.gif,.bmp,.tiff,.tif,.pdf"
          style="display:none"
          onchange="handleConverterFiles(this.files)">

        <div class="converter-settings">
          <div class="converter-quality">
            <label>Kwaliteit: <strong id="quality-value">80</strong>%</label>
            <input type="range" id="converter-quality" min="1" max="100" value="80"
              oninput="document.getElementById('quality-value').textContent = this.value">
          </div>
          <div class="converter-quality-hints">
            <span>Klein bestand</span>
            <span>Hoge kwaliteit</span>
          </div>
        </div>
      </div>

      <!-- File list -->
      <div id="converter-file-list" class="converter-file-list"></div>

      <!-- Convert button -->
      <div id="converter-actions" class="converter-actions" style="display:none;">
        <button class="btn btn-primary btn-lg" id="convert-btn" onclick="startConversion()">
          <i class="fas fa-sync-alt"></i> Converteer naar WEBP
        </button>
        <button class="btn btn-outline" onclick="clearConverter()">
          <i class="fas fa-trash"></i> Wis alles
        </button>
      </div>

      <!-- Results -->
      <div id="converter-results" class="converter-results" style="display:none;">
        <div class="converter-results-header">
          <h3><i class="fas fa-check-circle" style="color:var(--success)"></i> Conversie voltooid</h3>
          <button class="btn btn-primary btn-sm" onclick="downloadAllWebp()">
            <i class="fas fa-download"></i> Download alles
          </button>
        </div>
        <div id="converter-results-list" class="converter-results-list"></div>
        <div id="converter-stats" class="converter-stats"></div>
      </div>
      </div>
    </section>
  `;

  converterState = { files: [], results: [], converting: false, seoData: {} };
  initProductImageDropzone();
  initDropzone();
  if (pendingRequestId) {
    showProductImagePendingState();
    pollProductImageRequest(pendingRequestId);
  }
}

function initProductImageDropzone() {
  const dropzone = document.getElementById('product-image-dropzone');
  if (!dropzone) return;

  dropzone.addEventListener('dragover', (event) => {
    event.preventDefault();
    dropzone.classList.add('dragover');
  });

  dropzone.addEventListener('dragleave', () => dropzone.classList.remove('dragover'));
  dropzone.addEventListener('drop', (event) => {
    event.preventDefault();
    dropzone.classList.remove('dragover');
    handleProductImageFiles(event.dataTransfer.files);
  });
}

function handleProductImageDropzoneKeydown(event) {
  if (!['Enter', ' '].includes(event.key)) return;

  event.preventDefault();
  document.getElementById('product-image-file-input')?.click();
}

function handleProductImageFiles(fileList) {
  // FileList is live in browsers: copy it before resetting the input.
  const selectedFiles = Array.from(fileList || []);
  const input = document.getElementById('product-image-file-input');
  if (input) input.value = '';
  if (selectedFiles.length === 0) return;

  const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
  for (const file of selectedFiles) {
    if (productImageState.files.length >= 5) {
      toast('Je kunt maximaal vijf referentiefoto\'s gebruiken.', 'error');
      break;
    }
    if (!allowedTypes.includes(file.type)) {
      toast(`${file.name} is geen JPG-, PNG- of WEBP-afbeelding.`, 'error');
      continue;
    }
    if (file.size > 10 * 1024 * 1024) {
      toast(`${file.name} is groter dan 10 MB.`, 'error');
      continue;
    }
    if (productImageState.files.some(existing => existing.name === file.name && existing.size === file.size)) continue;
    productImageState.files.push(file);
    productImageState.previewUrls.push(URL.createObjectURL(file));
  }

  productImageState.results = [];

  document.getElementById('product-image-results')?.classList.add('hidden');
  renderProductImageSelection();
}

function renderProductImageSelection() {
  const selection = document.getElementById('product-image-selection');
  const button = document.getElementById('product-image-generate-btn');
  if (!selection || !button) return;

  if (!productImageState.files.length) {
    selection.innerHTML = `
      <div class="product-image-empty-preview">
        <i class="fas fa-drumstick-bite"></i>
        <span>Je referentiefoto's verschijnen hier</span>
      </div>`;
    button.disabled = true;
    return;
  }

  selection.innerHTML = `<div class="product-reference-grid">${productImageState.files.map((file, index) => `
    <article class="product-reference-card ${index === productImageState.mainIndex ? 'is-main' : ''}">
      <img src="${productImageState.previewUrls[index]}" alt="Referentie ${index + 1}">
      <div><strong>${escHtml(file.name)}</strong><small>${formatFileSize(file.size)}</small></div>
      <button type="button" class="reference-main" onclick="setProductImageMain(${index})" ${index === productImageState.mainIndex ? 'disabled' : ''}>${index === productImageState.mainIndex ? '<i class="fas fa-star"></i> Hoofdfoto' : 'Maak hoofdfoto'}</button>
      <button class="btn-icon" type="button" onclick="removeProductImageFile(${index})" title="Foto verwijderen"><i class="fas fa-times"></i></button>
    </article>`).join('')}</div>`;
  updateProductImageForm();
}

function removeProductImageFile(index) {
  if (productImageState.generating) return;
  URL.revokeObjectURL(productImageState.previewUrls[index]);
  productImageState.files.splice(index, 1);
  productImageState.previewUrls.splice(index, 1);
  productImageState.mainIndex = Math.min(productImageState.mainIndex, Math.max(0, productImageState.files.length - 1));
  productImageState.results = [];
  document.getElementById('product-image-results')?.classList.add('hidden');
  renderProductImageSelection();
}

function setProductImageMain(index) {
  if (productImageState.generating) return;
  productImageState.mainIndex = index;
  renderProductImageSelection();
}

function setProductImageType(type) {
  if (productImageState.generating) return;
  productImageState.productType = type;
  document.querySelectorAll('.product-type-picker button').forEach(button => button.classList.toggle('active', button.dataset.type === type));
  document.getElementById('product-image-components-group')?.classList.toggle('hidden', type !== 'bundle');
  const labels = { meat: 'Maak 4 productfoto\'s', sauce: 'Maak 2 productfoto\'s', bundle: 'Maak 2 totaalbeelden' };
  const button = document.getElementById('product-image-generate-btn');
  if (button) button.innerHTML = `<i class="fas fa-wand-magic-sparkles"></i> ${labels[type]}`;
  updateProductImageForm();
}

function updateProductImageForm() {
  const button = document.getElementById('product-image-generate-btn');
  const name = document.getElementById('product-image-name')?.value.trim();
  const quantity = Number(document.getElementById('product-image-quantity')?.value);
  if (button) button.disabled = productImageState.generating || !productImageState.files.length || !name || quantity < 1;
}

async function openProductPromptModal() {
  let data;

  try {
    data = await api('/api/images/prompt');
  } catch (error) {
    toast('De productfotoprompt kon niet worden geladen.', 'error');
    return;
  }

  if (!data) return;

  const modeNotice = data.voorbeeldmodus
    ? `<div class="product-image-mode-notice"><i class="fas fa-flask"></i> Lokale voorbeeldmodus is actief; er worden geen externe API-kosten gemaakt.</div>`
    : '';

  openModal('Prompt instellen', `
    ${modeNotice}
    <div class="form-group">
      <label for="product-image-prompt">Basisinstructie voor de productfoto's</label>
      <textarea id="product-image-prompt" rows="12" maxlength="6000" required>${escHtml(data.prompt)}</textarea>
      <small class="product-image-prompt-help">Regels voor soort product, exact aantal, stijlen en varianten worden automatisch toegevoegd.</small>
    </div>
  `, `
    <button class="btn btn-outline" type="button" onclick="closeModal()">Annuleren</button>
    <button class="btn btn-primary" id="save-product-image-prompt" type="button" onclick="saveProductImagePrompt()">
      <i class="fas fa-save"></i> Prompt opslaan
    </button>
  `);
}

async function saveProductImagePrompt() {
  const textarea = document.getElementById('product-image-prompt');
  const button = document.getElementById('save-product-image-prompt');
  const prompt = textarea?.value.trim() || '';

  if (prompt.length < 20) {
    toast('De prompt moet minimaal 20 tekens bevatten.', 'error');
    textarea?.focus();
    return;
  }

  button.disabled = true;
  let result;

  try {
    result = await api('/api/images/prompt', { method: 'PUT', body: { prompt } });
  } catch (error) {
    toast('De productfotoprompt kon niet worden opgeslagen.', 'error');
    return;
  } finally {
    button.disabled = false;
  }

  if (!result) return;
  closeModal();
  toast('De productfotoprompt is opgeslagen.', 'success');
}

async function startProductImageGeneration() {
  if (!productImageState.files.length || productImageState.generating) return;

  const productName = document.getElementById('product-image-name')?.value.trim() || '';
  const quantity = Number(document.getElementById('product-image-quantity')?.value || 0);
  if (!productName || quantity < 1) {
    toast('Vul de productnaam en het exacte aantal in.', 'error');
    return;
  }

  productImageState.generating = true;
  const button = document.getElementById('product-image-generate-btn');
  const status = document.getElementById('product-image-status');
  const results = document.getElementById('product-image-results');
  button.disabled = true;
  button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Productfoto\'s maken...';
  status.classList.remove('hidden');
  status.innerHTML = '<i class="fas fa-wand-magic-sparkles"></i><div><strong>Beeldgeneratie wordt klaargezet</strong><span>Je referenties en aantallen worden gecontroleerd.</span></div>';
  results.classList.add('hidden');

  const formData = new FormData();
  productImageState.files.forEach(file => formData.append('fotos[]', file));
  formData.append('main_index', String(productImageState.mainIndex));
  formData.append('product_type', productImageState.productType);
  formData.append('product_name', productName);
  formData.append('quantity', String(quantity));
  formData.append('notes', document.getElementById('product-image-notes')?.value.trim() || '');
  formData.append('components', document.getElementById('product-image-components')?.value.trim() || '');
  const headers = {};
  const xsrfToken = typeof getCookie === 'function' ? getCookie('XSRF-TOKEN') : null;
  if (xsrfToken) headers['X-XSRF-TOKEN'] = xsrfToken;

  try {
    if (!getCookie('XSRF-TOKEN')) await refreshCsrfCookie();
    const freshToken = getCookie('XSRF-TOKEN');
    if (freshToken) headers['X-XSRF-TOKEN'] = freshToken;
    const response = await fetch('/api/images/generate', { method: 'POST', headers, body: formData });
    if (response.status === 401) {
      showLogin();
      return;
    }

    const data = await response.json();
    if (!response.ok) {
      const validationError = data.errors ? Object.values(data.errors).flat()[0] : null;
      toast(data.error || validationError || data.message || 'Productfoto maken is mislukt.', 'error');
      return;
    }

    if (!data.request_id || data.status !== 'queued') {
      toast('De achtergrondtaak kon niet worden gestart.', 'error');
      return;
    }

    productImageState.requestId = data.request_id;
    sessionStorage.setItem(PRODUCT_IMAGE_REQUEST_KEY, data.request_id);
    showProductImagePendingState(data);
    pollProductImageRequest(data.request_id);
  } catch (error) {
    toast('De achtergrondtaak kon niet worden gestart.', 'error');
    productImageState.generating = false;
    updateProductImageForm();
    button.innerHTML = '<i class="fas fa-wand-magic-sparkles"></i> Maak productfoto';
    status.classList.add('hidden');
  }
}

function showProductImagePendingState(data = null) {
  const button = document.getElementById('product-image-generate-btn');
  const status = document.getElementById('product-image-status');
  if (!button || !status) return;

  productImageState.generating = true;
  button.disabled = true;
  button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Productfoto\'s worden gemaakt...';
  renderProductImageProgress(data || {
    status: 'queued',
    progress: 5,
    progress_step: 'queued',
    progress_label: 'Opdracht ontvangen',
    elapsed_seconds: 0,
  });
}

function renderProductImageProgress(data) {
  const status = document.getElementById('product-image-status');
  if (!status) return;

  const steps = [
    { key: 'queued', label: 'Foto ontvangen' },
    { key: 'preparing', label: 'Foto voorbereiden' },
    { key: 'generating_product', label: 'Varianten maken' },
    { key: 'saving', label: 'Controleren en opslaan' },
  ];
  const aliases = { starting: 'queued', generating_prepared: 'generating_product', generating_raw: 'generating_product' };
  const activeKey = aliases[data.progress_step] || data.progress_step || 'queued';
  const activeIndex = Math.max(0, steps.findIndex(step => step.key === activeKey));
  const progress = Math.max(0, Math.min(100, Number(data.progress) || 0));
  const elapsed = formatProductImageElapsed(Number(data.elapsed_seconds) || 0);

  status.classList.remove('hidden', 'is-error');
  status.innerHTML = `
    <div class="product-image-progress-content">
      <div class="product-image-progress-heading">
        <div>
          <strong>${escHtml(data.progress_label || 'OpenAI werkt aan je productfoto\'s')}</strong>
          <span>Verstreken tijd: ${elapsed}. Je mag ondertussen verder werken.</span>
        </div>
        <b>${progress}%</b>
      </div>
      <div class="product-image-progress-track" aria-label="${progress}% voltooid">
        <span style="width:${progress}%"></span>
      </div>
      <ol class="product-image-progress-steps">
        ${steps.map((step, index) => {
          const state = index < activeIndex ? 'done' : index === activeIndex ? 'active' : '';
          const icon = index < activeIndex ? 'fa-check' : index === activeIndex ? 'fa-spinner fa-spin' : 'fa-circle';
          return `<li class="${state}"><i class="fas ${icon}"></i><span>${step.label}</span></li>`;
        }).join('')}
      </ol>
    </div>`;
}

function formatProductImageElapsed(seconds) {
  if (seconds < 60) return `${Math.max(0, Math.floor(seconds))} seconden`;

  const minutes = Math.floor(seconds / 60);
  const remainder = Math.floor(seconds % 60);
  return `${minutes} min ${remainder} sec`;
}

function showProductImageError(message) {
  const status = document.getElementById('product-image-status');
  if (!status) return;

  status.classList.remove('hidden');
  status.classList.add('is-error');
  status.innerHTML = `
    <i class="fas fa-triangle-exclamation"></i>
    <div>
      <strong>De opdracht is niet afgerond</strong>
      <span>${escHtml(message)}</span>
    </div>`;
}

async function pollProductImageRequest(requestId) {
  if (!requestId || productImageState.requestId !== requestId) return;

  try {
    const response = await fetch(`/api/images/requests/${encodeURIComponent(requestId)}`, {
      headers: { Accept: 'application/json' },
    });
    if (response.status === 401) {
      showLogin();
      return;
    }
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || 'Status ophalen is mislukt.');
    productImageState.pollFailures = 0;

    if (data.status === 'completed') {
      const expected = data.context?.product_type === 'meat' || !data.context ? 4 : 2;
      if (!Array.isArray(data.results) || data.results.length !== expected) {
        throw new Error(`De beeldservice leverde niet de verwachte ${expected} productfoto's op.`);
      }
      productImageState.results = data.results;
      productImageState.completedRequestId = requestId;
      finishProductImageRequest();
      renderProductImageResults();
      toast(`${expected} productfoto's zijn klaar!`, 'success');
      return;
    }
    if (data.status === 'failed') {
      const message = data.error || 'Productfoto maken is mislukt. Probeer de opdracht opnieuw.';
      finishProductImageRequest(false);
      showProductImageError(message);
      toast(message, 'error');
      return;
    }

    renderProductImageProgress(data);
    productImageState.pollTimer = setTimeout(() => pollProductImageRequest(requestId), 2500);
  } catch (error) {
    productImageState.pollFailures += 1;
    if (productImageState.pollFailures <= 3) {
      productImageState.pollTimer = setTimeout(() => pollProductImageRequest(requestId), 5000);
      return;
    }

    const message = error.message || 'De voortgang kon niet worden geladen. Probeer de opdracht opnieuw.';
    finishProductImageRequest(false);
    showProductImageError(message);
    toast(message, 'error');
  }
}

function finishProductImageRequest(hideStatus = true) {
  if (productImageState.pollTimer) clearTimeout(productImageState.pollTimer);
  productImageState.pollTimer = null;
  productImageState.generating = false;
  productImageState.requestId = null;
  sessionStorage.removeItem(PRODUCT_IMAGE_REQUEST_KEY);

  const button = document.getElementById('product-image-generate-btn');
  const status = document.getElementById('product-image-status');
  if (button) {
    updateProductImageForm();
    const labels = { meat: 'Maak 4 productfoto\'s', sauce: 'Maak 2 productfoto\'s', bundle: 'Maak 2 totaalbeelden' };
    button.innerHTML = `<i class="fas fa-wand-magic-sparkles"></i> ${labels[productImageState.productType]}`;
  }
  if (hideStatus) status?.classList.add('hidden');
}

function renderProductImageResults() {
  const container = document.getElementById('product-image-results');
  if (!container) return;

  container.innerHTML = `
    <div class="product-image-results-header">
      <div>
        <span class="product-image-eyebrow">Resultaat</span>
        <h3>Kies je favoriete productfoto</h3>
      </div>
      <span class="product-image-count"><i class="fas fa-check-circle"></i> ${productImageState.results.length} afbeeldingen</span>
    </div>
    <div class="product-image-grid">
      ${productImageState.results.map(result => `
        <article class="product-image-result-card">
          <div class="product-image-result-visual">
            <img src="${escHtml(result.url)}" alt="${escHtml(result.label)} variant ${Number(result.variant)}">
            <span class="product-image-result-badge ${result.status}">${escHtml(result.label)}</span>
          </div>
          <div class="product-image-result-footer">
            <div>
              <strong>${escHtml(result.label)}</strong>
              <span>Variant ${Number(result.variant)} · versie ${Number(result.version || 1)}</span>
            </div>
            <div class="product-result-buttons">
              <button class="btn btn-outline btn-sm" type="button" onclick="toggleProductImageRefinement(${Number(result.asset_id)})" ${result.refinement_status !== 'idle' ? 'disabled' : ''}><i class="fas ${result.refinement_status !== 'idle' ? 'fa-spinner fa-spin' : 'fa-pen'}"></i> ${result.refinement_status !== 'idle' ? 'Wordt aangepast…' : 'Deze foto aanpassen'}</button>
              <a class="btn btn-primary btn-sm" href="${escHtml(result.download_url)}" ${result.needs_label_review ? `onclick="return confirmProductLabelReview(event, ${Number(result.asset_id)})"` : ''}><i class="fas fa-download"></i> Download</a>
            </div>
          </div>
          ${result.needs_label_review ? `<label class="product-label-warning"><input type="checkbox" id="product-label-approved-${Number(result.asset_id)}"><span><strong>Etiketcontrole verplicht.</strong> Ik heb iedere letter, het logo en de kleuren vergeleken met de echte referentiefoto.</span></label>` : ''}
          ${result.refinement_error ? `<div class="product-refine-error">${escHtml(result.refinement_error)}</div>` : ''}
          <div class="product-refinement hidden" id="product-refinement-${Number(result.asset_id)}">
            <label for="product-refinement-text-${Number(result.asset_id)}">Wat wil je alleen aan deze foto veranderen?</label>
            <textarea id="product-refinement-text-${Number(result.asset_id)}" rows="3" maxlength="1200" placeholder="Bijvoorbeeld: maak de steak medium en de korst krokanter"></textarea>
            <div><button class="btn btn-primary btn-sm" type="button" onclick="refineProductImage(${Number(result.asset_id)})"><i class="fas fa-wand-magic-sparkles"></i> Alleen deze foto aanpassen</button>${Number(result.version || 1) > 1 ? `<button class="btn btn-outline btn-sm" type="button" onclick="openProductImageHistory(${Number(result.asset_id)})"><i class="fas fa-clock-rotate-left"></i> Eerdere versies</button>` : ''}</div>
          </div>
        </article>
      `).join('')}
    </div>`;
  container.classList.remove('hidden');
}

function toggleProductImageRefinement(assetId) {
  document.getElementById(`product-refinement-${assetId}`)?.classList.toggle('hidden');
}

function confirmProductLabelReview(event, assetId) {
  if (document.getElementById(`product-label-approved-${assetId}`)?.checked) return true;
  event.preventDefault();
  toast('Controleer eerst het volledige etiket en vink de controle aan.', 'error');
  return false;
}

async function refineProductImage(assetId) {
  const requestId = productImageState.completedRequestId;
  const textarea = document.getElementById(`product-refinement-text-${assetId}`);
  const instruction = textarea?.value.trim() || '';
  if (!requestId || instruction.length < 5) {
    toast('Beschrijf eerst duidelijk wat je aan deze foto wilt veranderen.', 'error');
    textarea?.focus();
    return;
  }

  try {
    const started = await api(`/api/images/requests/${encodeURIComponent(requestId)}/assets/${assetId}/refine`, {
      method: 'POST', body: { instruction },
    });
    if (!started) return;
    const result = productImageState.results.find(item => Number(item.asset_id) === Number(assetId));
    if (result) result.refinement_status = 'queued';
    renderProductImageResults();
    toast('Alleen de gekozen foto wordt nu aangepast.', 'success');
    pollProductImageRefinement(requestId, assetId, Number(result?.version || 1));
  } catch (error) {
    toast(error.message || 'De aanpassing kon niet worden gestart.', 'error');
  }
}

async function pollProductImageRefinement(requestId, assetId, oldVersion) {
  try {
    const data = await api(`/api/images/requests/${encodeURIComponent(requestId)}`);
    if (!data) return;
    productImageState.results = data.results || [];
    const selected = productImageState.results.find(item => Number(item.asset_id) === Number(assetId));
    renderProductImageResults();
    if (selected?.refinement_error) {
      toast(selected.refinement_error, 'error');
      return;
    }
    if (selected?.refinement_status !== 'idle' || Number(selected?.version || 1) === oldVersion) {
      setTimeout(() => pollProductImageRefinement(requestId, assetId, oldVersion), 2500);
      return;
    }
    toast('De gekozen foto is aangepast. De andere foto’s zijn ongewijzigd.', 'success');
  } catch (error) {
    setTimeout(() => pollProductImageRefinement(requestId, assetId, oldVersion), 5000);
  }
}

async function openProductImageHistory(assetId) {
  const requestId = productImageState.completedRequestId;
  if (!requestId) return;
  try {
    const data = await api(`/api/images/requests/${encodeURIComponent(requestId)}/assets/${assetId}/revisions`);
    if (!data) return;
    const revisions = Array.isArray(data?.revisions) ? data.revisions : [];
    openModal('Eerdere versies', revisions.length ? `
      <div class="product-version-list">${revisions.map(revision => `
        <div><span><strong>Versie ${Number(revision.version)}</strong><small>${escHtml(revision.instruction || 'Oorspronkelijke foto')}</small></span><button class="btn btn-outline btn-sm" type="button" onclick="restoreProductImageVersion(${assetId}, ${Number(revision.id)})">Herstel deze versie</button></div>`).join('')}</div>
    ` : '<p>Er zijn nog geen eerdere versies.</p>', '<button class="btn btn-outline" type="button" onclick="closeModal()">Sluiten</button>');
  } catch (error) {
    toast('De eerdere versies konden niet worden geladen.', 'error');
  }
}

async function restoreProductImageVersion(assetId, revisionId) {
  const requestId = productImageState.completedRequestId;
  if (!requestId) return;
  try {
    const restored = await api(`/api/images/requests/${encodeURIComponent(requestId)}/assets/${assetId}/revisions/${revisionId}/restore`, { method: 'POST', body: {} });
    if (!restored) return;
    const data = await api(`/api/images/requests/${encodeURIComponent(requestId)}`);
    productImageState.results = data?.results || [];
    closeModal();
    renderProductImageResults();
    toast('De gekozen versie is hersteld.', 'success');
  } catch (error) {
    toast('Deze versie kon niet worden hersteld.', 'error');
  }
}

function initDropzone() {
  const dropzone = document.getElementById('converter-dropzone');
  if (!dropzone) return;

  dropzone.addEventListener('dragover', (e) => {
    e.preventDefault();
    dropzone.classList.add('dragover');
  });

  dropzone.addEventListener('dragleave', () => {
    dropzone.classList.remove('dragover');
  });

  dropzone.addEventListener('drop', (e) => {
    e.preventDefault();
    dropzone.classList.remove('dragover');
    handleConverterFiles(e.dataTransfer.files);
  });
}

function handleConverterFiles(fileList) {
  const allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/tiff', 'image/webp', 'application/pdf'];

  for (const file of fileList) {
    if (!allowed.includes(file.type)) {
      toast(`${file.name}: niet ondersteund formaat`, 'error');
      continue;
    }
    if (file.size > 25 * 1024 * 1024) {
      toast(`${file.name}: bestand te groot (max 25MB)`, 'error');
      continue;
    }
    // Avoid duplicates
    if (converterState.files.find(f => f.name === file.name && f.size === file.size)) continue;
    converterState.files.push(file);
  }

  renderFileList();

  // Reset file input
  const input = document.getElementById('converter-file-input');
  if (input) input.value = '';
}

function renderFileList() {
  const list = document.getElementById('converter-file-list');
  const actions = document.getElementById('converter-actions');

  if (converterState.files.length === 0) {
    list.innerHTML = '';
    actions.style.display = 'none';
    return;
  }

  actions.style.display = 'flex';

  list.innerHTML = converterState.files.map((f, i) => {
    const ext = f.name.split('.').pop().toUpperCase();
    const size = formatFileSize(f.size);
    const icon = f.type === 'application/pdf' ? 'fa-file-pdf' : 'fa-file-image';
    const preview = f.type.startsWith('image/') ? URL.createObjectURL(f) : null;

    return `
      <div class="converter-file-item">
        ${preview ? `<img src="${preview}" class="converter-file-preview" alt="">` :
          `<div class="converter-file-icon"><i class="fas ${icon}"></i></div>`}
        <div class="converter-file-info">
          <span class="converter-file-name">${escHtml(f.name)}</span>
          <span class="converter-file-meta">
            <span class="converter-file-badge">${ext}</span>
            ${size}
          </span>
        </div>
        <span class="converter-file-arrow"><i class="fas fa-arrow-right"></i></span>
        <span class="converter-file-target">
          <span class="converter-file-badge webp">WEBP</span>
        </span>
        <button class="btn-icon" onclick="removeConverterFile(${i})" title="Verwijderen">
          <i class="fas fa-times"></i>
        </button>
      </div>
    `;
  }).join('');
}

function removeConverterFile(index) {
  converterState.files.splice(index, 1);
  renderFileList();
}

function clearConverter() {
  converterState.files = [];
  converterState.results = [];
  converterState.seoData = {};
  renderFileList();
  document.getElementById('converter-results').style.display = 'none';
}

async function startConversion() {
  if (converterState.files.length === 0) return;
  if (converterState.converting) return;

  converterState.converting = true;
  const btn = document.getElementById('convert-btn');
  btn.disabled = true;
  btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Bezig met converteren...';

  const quality = document.getElementById('converter-quality').value;

  const formData = new FormData();
  for (const file of converterState.files) {
    formData.append('bestanden[]', file);
  }
  formData.append('quality', quality);

  try {
    const headers = {};
    const xsrfToken = typeof getCookie === 'function' ? getCookie('XSRF-TOKEN') : null;
    if (xsrfToken) headers['X-XSRF-TOKEN'] = xsrfToken;

    const res = await fetch('/api/convert/webp', {
      method: 'POST',
      headers,
      body: formData,
    });

    if (res.status === 401) {
      showLogin();
      return;
    }

    const data = await res.json();

    if (!res.ok) {
      toast(data.error || 'Conversie mislukt', 'error');
      return;
    }

    converterState.results = data.results;
    converterState.seoData = {};
    renderResults();
    toast('Conversie voltooid!', 'success');
  } catch (err) {
    toast('Er ging iets mis bij de conversie', 'error');
  } finally {
    converterState.converting = false;
    btn.disabled = false;
    btn.innerHTML = '<i class="fas fa-sync-alt"></i> Converteer naar WEBP';
  }
}

// ========== SEO Metadata Generator ==========

function generateSlug(text) {
  return text
    .toLowerCase()
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '') // Remove accents
    .replace(/[^a-z0-9\s-]/g, '') // Remove special chars
    .trim()
    .replace(/\s+/g, '-') // Spaces to hyphens
    .replace(/-+/g, '-') // Multiple hyphens to single
    .replace(/^-|-$/g, ''); // Trim hyphens
}

function generateTitel(text) {
  // Remove stopwoorden but keep first letter capitalized
  const woorden = text.split(/\s+/);
  const filtered = woorden.filter(w => !NL_STOPWOORDEN.includes(w.toLowerCase()));
  if (filtered.length === 0) return text; // Fallback if all words are stop words
  // Capitalize first letter of first word
  const result = filtered.join(' ');
  return result.charAt(0).toUpperCase() + result.slice(1);
}

function generateBestandsnaam(text) {
  // Remove stopwoorden, then slugify
  const woorden = text.toLowerCase().split(/\s+/);
  const filtered = woorden.filter(w => !NL_STOPWOORDEN.includes(w));
  if (filtered.length === 0) return generateSlug(text); // Fallback
  return generateSlug(filtered.join(' '));
}

// Productcategorieën en bijbehorende SEO-zinnen voor BBQuality
const PRODUCT_CATEGORIEEN = {
  rub: {
    keywords: ['rub', 'kruidenmix', 'kruiden', 'seasoning', 'spice'],
    templates: [
      '{product} is een premium kruidenmix van BBQuality, zorgvuldig samengesteld voor de echte BBQ-liefhebber.',
      'Deze kruidenmix is zorgvuldig samengesteld met hoogwaardige ingrediënten voor een optimale smaakbeleving.',
      'Ideaal voor zowel de beginnende als de ervaren pitmaster.'
    ],
    vleesTypes: {
      'pork': 'varkensvlees. Perfect voor ribs, pulled pork en andere low & slow bereidingen.',
      'beef': 'rundvlees. Uitstekend geschikt voor steaks, burgers en brisket.',
      'chicken': 'kip. Geeft een heerlijke smaak aan chicken wings, drumsticks en hele kippen.',
      'kip': 'kip. Geeft een heerlijke smaak aan chicken wings, drumsticks en hele kippen.',
      'varken': 'varkensvlees. Perfect voor ribs, pulled pork en andere low & slow bereidingen.',
      'rund': 'rundvlees. Uitstekend geschikt voor steaks, burgers en brisket.',
      'all': 'diverse soorten vlees en groenten van de BBQ.'
    }
  },
  saus: {
    keywords: ['saus', 'sauce', 'glaze', 'marinade', 'bbq saus', 'hot sauce'],
    templates: [
      '{product} is een smaakvolle saus van BBQuality, perfect als dip, marinade of finishing sauce.',
      'Deze saus is gemaakt met zorgvuldig geselecteerde ingrediënten voor een authentieke BBQ-smaak.',
      'Verkrijgbaar bij BBQuality voor de ultieme BBQ-ervaring.'
    ]
  },
  vlees: {
    keywords: ['burger', 'patty', 'steak', 'ribs', 'pulled', 'worst', 'sausage', 'filet', 'vlees', 'biefstuk', 'entrecote', 'ribeye', 'spareribs', 'chicken', 'wings', 'drumsticks', 'kip', 'gehakt', 'lam', 'lamb'],
    templates: [
      '{product} van BBQuality, geselecteerd voor de beste kwaliteit en smaak.',
      'Dit premium vleesproduct is ideaal voor bereiding op de BBQ of in de oven.',
      'Verkrijgbaar bij BBQuality, uw specialist in kwaliteitsvlees en BBQ-producten.'
    ],
    bereidingTips: {
      'burger': 'Ideaal om te bereiden op een hete grill voor een krokant korstje en sappig resultaat.',
      'patty': 'Ideaal om te bereiden op een hete grill voor een krokant korstje en sappig resultaat.',
      'steak': 'Het beste resultaat bereikt u door het vlees op kamertemperatuur te brengen voor het grillen.',
      'biefstuk': 'Het beste resultaat bereikt u door het vlees op kamertemperatuur te brengen voor het grillen.',
      'entrecote': 'Het beste resultaat bereikt u door het vlees op kamertemperatuur te brengen voor het grillen.',
      'ribeye': 'Het beste resultaat bereikt u door het vlees op kamertemperatuur te brengen voor het grillen.',
      'ribs': 'Perfect voor de low & slow bereiding op de BBQ of smoker.',
      'spareribs': 'Perfect voor de low & slow bereiding op de BBQ of smoker.',
      'pulled': 'Uitermate geschikt voor de low & slow bereiding, ideaal als pulled meat.',
      'worst': 'Heerlijk vers van de grill, perfect als onderdeel van een BBQ-maaltijd.',
      'sausage': 'Heerlijk vers van de grill, perfect als onderdeel van een BBQ-maaltijd.',
      'chicken': 'Heerlijk van de grill of uit de smoker, met een krokant velletje en mals vlees.',
      'wings': 'Heerlijk van de grill of uit de smoker, met een krokant velletje en mals vlees.',
      'drumsticks': 'Heerlijk van de grill of uit de smoker, met een krokant velletje en mals vlees.',
      'kip': 'Heerlijk van de grill of uit de smoker, met een krokant velletje en mals vlees.',
      'filet': 'Een premium stuk vlees dat uitstekend tot zijn recht komt op de BBQ.',
    }
  },
  accessoire: {
    keywords: ['grill', 'smoker', 'thermometer', 'tang', 'rooster', 'houtskool', 'briketten', 'aansteker', 'handschoen', 'plank', 'snijplank', 'mes', 'schort', 'handschoenen'],
    templates: [
      '{product} is een hoogwaardig BBQ-accessoire, verkrijgbaar bij BBQuality.',
      'Dit product is onmisbaar voor iedere BBQ-liefhebber die waarde hecht aan kwaliteit.',
      'Ontdek het volledige assortiment BBQ-accessoires bij BBQuality.'
    ]
  }
};

function detectProductCategorie(tekst) {
  const lower = tekst.toLowerCase();
  for (const [cat, info] of Object.entries(PRODUCT_CATEGORIEEN)) {
    for (const kw of info.keywords) {
      if (lower.includes(kw)) return cat;
    }
  }
  return null; // Geen specifieke categorie herkend
}

function detectVleesType(tekst) {
  const lower = tekst.toLowerCase();
  const types = PRODUCT_CATEGORIEEN.rub.vleesTypes;
  for (const type of Object.keys(types)) {
    if (type !== 'all' && lower.includes(type)) return type;
  }
  return 'all';
}

function extractProductNaam(tekst) {
  // Maak een nette productnaam van de alt-tekst
  let naam = tekst.trim();

  // Verwijder gewicht-patronen inclusief voorafgaand "van" zoals "van 150 gram", "150g", "200gr"
  naam = naam.replace(/\b(van\s+)?\d+\s*(g|gr|gram|kg|kilogram|ml|liter|l|cl|stuks?|st)\b/gi, '').trim();

  // Verwijder locatie-indicaties achteraan zoals "op een houten plank", "op de tafel"
  naam = naam.replace(/\s+(op\s+(een|de|het)\s+.+)$/i, '').trim();

  // Verwijder dubbele spaties en losse lidwoorden aan het einde
  naam = naam.replace(/\s+/g, ' ').trim();
  naam = naam.replace(/\s+(van|op|in|met|voor|aan)$/i, '').trim();

  // Capitalize
  naam = naam.charAt(0).toUpperCase() + naam.slice(1);

  return naam;
}

function extractGewicht(tekst) {
  const match = tekst.match(/\b(\d+)\s*(g|gr|gram|kg|kilogram|ml|liter|l|cl|stuks?|st)\b/i);
  if (match) {
    const waarde = match[1];
    let eenheid = match[2].toLowerCase();
    // Normaliseer eenheid
    if (eenheid === 'g' || eenheid === 'gr') eenheid = 'gram';
    if (eenheid === 'kg') eenheid = 'kilogram';
    if (eenheid === 'st') eenheid = 'stuks';
    return `${waarde} ${eenheid}`;
  }
  return null;
}

function detectBereidingTip(tekst, catInfo) {
  if (!catInfo.bereidingTips) return null;
  const lower = tekst.toLowerCase();
  for (const [keyword, tip] of Object.entries(catInfo.bereidingTips)) {
    if (lower.includes(keyword)) return tip;
  }
  return null;
}

function generateBeschrijving(altTekst) {
  const categorie = detectProductCategorie(altTekst);
  const productNaam = extractProductNaam(altTekst);
  const gewicht = extractGewicht(altTekst);

  let beschrijving = '';

  if (categorie && PRODUCT_CATEGORIEEN[categorie]) {
    const catInfo = PRODUCT_CATEGORIEEN[categorie];

    // Eerste zin: productintroductie
    beschrijving = catInfo.templates[0].replace('{product}', productNaam);

    // Bij rubs: voeg vleestype-specifieke info toe
    if (categorie === 'rub') {
      const vleesType = detectVleesType(altTekst);
      const vleesInfo = catInfo.vleesTypes[vleesType] || catInfo.vleesTypes['all'];
      beschrijving = beschrijving.replace(/\.$/, '') + ', speciaal ontwikkeld voor ' + vleesInfo;
    }

    // Bij vlees: voeg bereidingstip toe
    if (categorie === 'vlees') {
      const tip = detectBereidingTip(altTekst, catInfo);
      if (tip) {
        beschrijving += ' ' + tip;
      }
    }

    // Gewicht toevoegen indien gevonden
    if (gewicht) {
      beschrijving += ` Inhoud: ${gewicht}.`;
    }

    // Tweede zin toevoegen
    if (catInfo.templates[1]) {
      beschrijving += ' ' + catInfo.templates[1];
    }
  } else {
    // Generieke beschrijving wanneer categorie niet herkend wordt
    beschrijving = `${productNaam} van BBQuality. `;
    if (gewicht) {
      beschrijving += `Inhoud: ${gewicht}. `;
    }
    beschrijving += 'Dit product is verkrijgbaar bij BBQuality, uw specialist in BBQ-producten en accessoires.';
  }

  // Zorg dat de beschrijving netjes eindigt
  beschrijving = beschrijving.replace(/\s+/g, ' ').trim();
  if (!beschrijving.endsWith('.')) beschrijving += '.';

  return beschrijving;
}

function generateSeoFromDescription(beschrijving, index) {
  const trimmed = beschrijving.trim();
  if (!trimmed) {
    converterState.seoData[index] = null;
    return null;
  }

  // Behoud handmatig aangepaste beschrijving als de gebruiker die heeft bewerkt
  const existing = converterState.seoData[index];
  const titel = generateTitel(trimmed);
  const autoBeschrijving = generateBeschrijving(trimmed);

  const data = {
    altTekst: trimmed, // Natuurlijke taal, ongewijzigd
    titel: titel,
    bestandsnaam: generateBestandsnaam(trimmed) + '.webp',
    beschrijving: existing && existing._handmatigBewerkt ? existing.beschrijving : autoBeschrijving,
    _handmatigBewerkt: existing ? existing._handmatigBewerkt : false,
  };

  converterState.seoData[index] = data;
  return data;
}

function onSeoDescriptionInput(index) {
  const input = document.getElementById(`seo-input-${index}`);
  if (!input) return;

  const beschrijving = input.value;
  const data = generateSeoFromDescription(beschrijving, index);
  const container = document.getElementById(`seo-fields-${index}`);

  if (!data) {
    container.innerHTML = '<p style="color:var(--text-light);font-size:13px;padding:8px 0;">Voer een beschrijving in om SEO-velden te genereren</p>';
    return;
  }

  // Count characters for alt text indicator
  const altLen = data.altTekst.length;
  const altColor = altLen <= 125 ? 'var(--success)' : 'var(--danger)';

  container.innerHTML = `
    <div class="seo-field-row">
      <div class="seo-field">
        <div class="seo-field-label">
          <i class="fas fa-file"></i> Bestandsnaam
        </div>
        <div class="seo-field-value-wrap">
          <input type="text" class="seo-field-input" id="seo-filename-${index}" value="${escHtml(data.bestandsnaam)}" onchange="updateSeoField(${index}, 'bestandsnaam', this.value)">
          <button class="seo-copy-btn" onclick="copySeoField(this, 'seo-filename-${index}')" title="Kopieer">
            <i class="fas fa-copy"></i>
          </button>
        </div>
      </div>
      <div class="seo-field">
        <div class="seo-field-label">
          <i class="fas fa-universal-access"></i> Alternatieve tekst
          <span class="seo-char-count" style="color:${altColor}">${altLen}/125</span>
        </div>
        <div class="seo-field-value-wrap">
          <input type="text" class="seo-field-input" id="seo-alt-${index}" value="${escHtml(data.altTekst)}" onchange="updateSeoField(${index}, 'altTekst', this.value)">
          <button class="seo-copy-btn" onclick="copySeoField(this, 'seo-alt-${index}')" title="Kopieer">
            <i class="fas fa-copy"></i>
          </button>
        </div>
      </div>
      <div class="seo-field">
        <div class="seo-field-label">
          <i class="fas fa-heading"></i> Titel
        </div>
        <div class="seo-field-value-wrap">
          <input type="text" class="seo-field-input" id="seo-title-${index}" value="${escHtml(data.titel)}" onchange="updateSeoField(${index}, 'titel', this.value)">
          <button class="seo-copy-btn" onclick="copySeoField(this, 'seo-title-${index}')" title="Kopieer">
            <i class="fas fa-copy"></i>
          </button>
        </div>
      </div>
      <div class="seo-field">
        <div class="seo-field-label">
          <i class="fas fa-align-left"></i> Beschrijving
        </div>
        <div class="seo-field-value-wrap">
          <textarea class="seo-field-textarea" id="seo-desc-${index}" rows="3"
            placeholder="Schrijf een uitgebreide beschrijving van het product of de afbeelding..."
            onchange="updateSeoBeschrijving(${index}, this.value)"
            oninput="updateSeoBeschrijving(${index}, this.value)">${escHtml(data.beschrijving)}</textarea>
          <button class="seo-copy-btn" onclick="copySeoFieldTextarea(this, 'seo-desc-${index}')" title="Kopieer">
            <i class="fas fa-copy"></i>
          </button>
        </div>
      </div>
    </div>
  `;
}

function updateSeoField(index, field, value) {
  if (!converterState.seoData[index]) return;
  converterState.seoData[index][field] = value;
}

function updateSeoBeschrijving(index, value) {
  if (!converterState.seoData[index]) return;
  converterState.seoData[index].beschrijving = value;
  converterState.seoData[index]._handmatigBewerkt = true;
}

function copySeoField(btn, inputId) {
  const input = document.getElementById(inputId);
  if (!input) return;

  navigator.clipboard.writeText(input.value).then(() => {
    const origIcon = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check"></i>';
    btn.classList.add('copied');
    setTimeout(() => {
      btn.innerHTML = origIcon;
      btn.classList.remove('copied');
    }, 1500);
  }).catch(() => {
    // Fallback: select and copy
    input.select();
    document.execCommand('copy');
    const origIcon = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check"></i>';
    btn.classList.add('copied');
    setTimeout(() => {
      btn.innerHTML = origIcon;
      btn.classList.remove('copied');
    }, 1500);
  });
}

function copySeoFieldTextarea(btn, textareaId) {
  const textarea = document.getElementById(textareaId);
  if (!textarea) return;

  navigator.clipboard.writeText(textarea.value).then(() => {
    const origIcon = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check"></i>';
    btn.classList.add('copied');
    setTimeout(() => {
      btn.innerHTML = origIcon;
      btn.classList.remove('copied');
    }, 1500);
  }).catch(() => {
    textarea.select();
    document.execCommand('copy');
    const origIcon = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-check"></i>';
    btn.classList.add('copied');
    setTimeout(() => {
      btn.innerHTML = origIcon;
      btn.classList.remove('copied');
    }, 1500);
  });
}

function copyAllSeoFields(index) {
  const data = converterState.seoData[index];
  if (!data) {
    toast('Voer eerst een beschrijving in', 'error');
    return;
  }

  const text = `Bestandsnaam: ${data.bestandsnaam}\nAlternatieve tekst: ${data.altTekst}\nTitel: ${data.titel}\nBeschrijving: ${data.beschrijving || '(nog niet ingevuld)'}`;

  navigator.clipboard.writeText(text).then(() => {
    toast('Alle SEO-velden gekopieerd!', 'success');
  }).catch(() => {
    toast('Kopiëren mislukt', 'error');
  });
}

// ========== Results Rendering ==========

function renderResults() {
  const resultsDiv = document.getElementById('converter-results');
  const resultsList = document.getElementById('converter-results-list');
  const statsDiv = document.getElementById('converter-stats');

  resultsDiv.style.display = 'block';

  const successResults = converterState.results.filter(r => !r.error);
  const errorResults = converterState.results.filter(r => r.error);

  resultsList.innerHTML = converterState.results.map((r, i) => {
    if (r.error) {
      return `
        <div class="converter-result-item error">
          <div class="converter-result-icon error">
            <i class="fas fa-exclamation-circle"></i>
          </div>
          <div class="converter-result-info">
            <span class="converter-result-name">${escHtml(r.origineel)}</span>
            <span class="converter-result-error">${escHtml(r.error)}</span>
          </div>
        </div>
      `;
    }

    return `
      <div class="converter-result-card">
        <div class="converter-result-item">
          <div class="converter-result-icon success">
            <i class="fas fa-check-circle"></i>
          </div>
          <div class="converter-result-info">
            <span class="converter-result-name">${escHtml(r.origineel)}</span>
            <span class="converter-result-meta">
              ${formatFileSize(r.origineel_grootte)} <i class="fas fa-arrow-right" style="font-size:10px;margin:0 6px;color:var(--text-light)"></i> ${formatFileSize(r.geconverteerd_grootte)}
              <span class="converter-result-saving ${r.besparing > 0 ? 'positive' : 'negative'}">
                ${r.besparing > 0 ? '-' : '+'}${Math.abs(r.besparing)}%
              </span>
              <span style="color:var(--text-light)">${r.breedte}x${r.hoogte}px</span>
            </span>
          </div>
          <div class="converter-download-btns">
            <button class="btn btn-sm btn-primary" onclick="downloadWebpFile(${i})" title="Download afbeelding + kopieer SEO-data naar klembord">
              <i class="fas fa-download"></i> Download
            </button>
            <button class="btn btn-sm btn-outline" onclick="downloadSeoMetadataFile(${i})" title="Download SEO-metadata als tekstbestand">
              <i class="fas fa-file-alt"></i>
            </button>
          </div>
        </div>

        <!-- SEO Metadata Section -->
        <div class="seo-section">
          <div class="seo-section-header">
            <span class="seo-section-title"><i class="fas fa-search"></i> WordPress SEO Metadata</span>
            <button class="btn btn-sm btn-outline" onclick="copyAllSeoFields(${i})">
              <i class="fas fa-clipboard-list"></i> Kopieer alles
            </button>
          </div>
          <div class="seo-description-input-wrap">
            <label class="seo-input-label">Beschrijf deze afbeelding in een natuurlijke zin:</label>
            <input type="text" class="seo-description-input" id="seo-input-${i}"
              placeholder="bijv. Gegaarde beef burger patty van 150 gram op een houten plank"
              oninput="onSeoDescriptionInput(${i})"
              maxlength="200">
            <span class="seo-input-hint">Tip: Gebruik een duidelijke, beschrijvende zin. De SEO-velden worden automatisch gegenereerd.</span>
          </div>
          <div id="seo-fields-${i}" class="seo-generated-fields">
            <p style="color:var(--text-light);font-size:13px;padding:8px 0;">Voer een beschrijving in om SEO-velden te genereren</p>
          </div>
        </div>
      </div>
    `;
  }).join('');

  // Stats
  if (successResults.length > 0) {
    const totalOriginal = successResults.reduce((s, r) => s + r.origineel_grootte, 0);
    const totalConverted = successResults.reduce((s, r) => s + r.geconverteerd_grootte, 0);
    const totalSaving = Math.round((1 - totalConverted / totalOriginal) * 100);

    statsDiv.innerHTML = `
      <div class="converter-stat">
        <span class="converter-stat-value">${successResults.length}</span>
        <span class="converter-stat-label">Bestanden geconverteerd</span>
      </div>
      <div class="converter-stat">
        <span class="converter-stat-value">${formatFileSize(totalOriginal)}</span>
        <span class="converter-stat-label">Originele grootte</span>
      </div>
      <div class="converter-stat">
        <span class="converter-stat-value">${formatFileSize(totalConverted)}</span>
        <span class="converter-stat-label">Na conversie</span>
      </div>
      <div class="converter-stat">
        <span class="converter-stat-value ${totalSaving > 0 ? 'positive' : ''}">${totalSaving > 0 ? '-' : '+'}${Math.abs(totalSaving)}%</span>
        <span class="converter-stat-label">Besparing</span>
      </div>
      ${errorResults.length > 0 ? `
        <div class="converter-stat">
          <span class="converter-stat-value" style="color:var(--danger)">${errorResults.length}</span>
          <span class="converter-stat-label">Mislukt</span>
        </div>
      ` : ''}
    `;
  }

  // Scroll to results
  resultsDiv.scrollIntoView({ behavior: 'smooth' });
}

function downloadWebpFile(index) {
  const r = converterState.results[index];
  if (!r || r.error) return;

  const seo = converterState.seoData[index];
  const downloadName = seo && seo.bestandsnaam ? seo.bestandsnaam : r.geconverteerd;

  // Download de afbeelding
  const link = document.createElement('a');
  link.href = r.download_url + '?naam=' + encodeURIComponent(downloadName);
  link.download = downloadName;
  link.click();

  // Kopieer automatisch SEO-metadata naar klembord als die beschikbaar is
  if (seo) {
    const metadataText = formatSeoMetadataForClipboard(seo);
    navigator.clipboard.writeText(metadataText).then(() => {
      toast('Afbeelding gedownload + SEO-metadata gekopieerd naar klembord!', 'success');
    }).catch(() => {
      toast('Afbeelding gedownload! Metadata kon niet naar klembord worden gekopieerd.', 'success');
    });
  }
}

function formatSeoMetadataForClipboard(seo) {
  let text = '';
  text += `Bestandsnaam: ${seo.bestandsnaam}\n`;
  text += `Alternatieve tekst: ${seo.altTekst}\n`;
  text += `Titel: ${seo.titel}\n`;
  text += `Beschrijving: ${seo.beschrijving || ''}`;
  return text;
}

function downloadSeoMetadataFile(index) {
  const seo = converterState.seoData[index];
  if (!seo) {
    toast('Voer eerst een beschrijving in om metadata te genereren', 'error');
    return;
  }

  // Maak een nette tekst met alle WordPress metadata
  let content = '=== WordPress SEO Metadata ===\n\n';
  content += `Bestandsnaam: ${seo.bestandsnaam}\n\n`;
  content += `Alternatieve tekst:\n${seo.altTekst}\n\n`;
  content += `Titel:\n${seo.titel}\n\n`;
  content += `Beschrijving:\n${seo.beschrijving || '(niet ingevuld)'}\n`;

  // Download als .txt bestand
  const baseName = seo.bestandsnaam.replace('.webp', '');
  const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = `${baseName}-seo-metadata.txt`;
  link.click();
  URL.revokeObjectURL(url);

  toast('SEO-metadata bestand gedownload!', 'success');
}

async function downloadAllWebp() {
  let allMetadata = '';
  let metadataCount = 0;

  for (let i = 0; i < converterState.results.length; i++) {
    const r = converterState.results[i];
    if (r.error) continue;

    const seo = converterState.seoData[i];
    const downloadName = seo && seo.bestandsnaam ? seo.bestandsnaam : r.geconverteerd;

    // Download de afbeelding
    const link = document.createElement('a');
    link.href = r.download_url + '?naam=' + encodeURIComponent(downloadName);
    link.download = downloadName;
    link.click();

    // Verzamel metadata
    if (seo) {
      metadataCount++;
      allMetadata += `--- ${seo.bestandsnaam} ---\n`;
      allMetadata += formatSeoMetadataForClipboard(seo);
      allMetadata += '\n\n';
    }

    // Small delay between downloads
    await new Promise(resolve => setTimeout(resolve, 300));
  }

  // Kopieer alle metadata naar klembord
  if (allMetadata) {
    navigator.clipboard.writeText(allMetadata.trim()).then(() => {
      toast(`${metadataCount} afbeelding(en) gedownload + alle SEO-metadata gekopieerd naar klembord!`, 'success');
    }).catch(() => {
      toast('Alle afbeeldingen gedownload!', 'success');
    });
  }
}

function formatFileSize(bytes) {
  if (!bytes) return '0 B';
  if (bytes < 1024) return bytes + ' B';
  if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
  return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
}
