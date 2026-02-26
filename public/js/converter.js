// ========== Image Converter ==========
let converterState = {
  files: [],
  results: [],
  converting: false,
  seoData: {}, // { index: { beschrijving, bestandsnaam, altTekst, titel, slug } }
};

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

  container.innerHTML = `
    <div class="page-header">
      <h2><i class="fas fa-image" style="color:var(--primary)"></i> Image Converter</h2>
      <p style="color:var(--text-light);font-size:14px;">Converteer afbeeldingen naar WEBP en genereer SEO-optimale metadata</p>
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
  `;

  converterState = { files: [], results: [], converting: false, seoData: {} };
  initDropzone();
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
    const res = await fetch('/api/convert/webp', {
      method: 'POST',
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
