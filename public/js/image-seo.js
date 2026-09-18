// SEO belongs to one asset and one image version. Never replace a dirty editor on polling.
const IMAGE_SEO_FIELDS = [['filename', 'Bestandsnaam'], ['alt', 'Alt-tekst'], ['title', 'Afbeeldingstitel'], ['caption', 'Bijschrift'], ['description', 'Beschrijving']];
let imageSeoEditor = null;

function imageSeoActive(editor) {
  return imageSeoEditor === editor && !!document.getElementById(`image-seo-form-${editor.assetId}`);
}

function imageSeoMessage(editor, message) {
  if (imageSeoActive(editor)) document.getElementById('image-seo-status').textContent = message;
}

function imageSeoValues() {
  return Object.fromEntries(IMAGE_SEO_FIELDS.map(([key]) => [key, document.getElementById(`image-seo-${key}`).value]));
}

async function openImageSeoEditor(assetId) {
  const result = productImageState.results.find(item => Number(item.asset_id) === Number(assetId));
  const requestId = productImageState.completedRequestId;
  if (!result || !requestId) return;
  const editor = { assetId, requestId, version: Number(result.version), revision: Number(result.seo?.revision || 0), dirty: false, busy: false, source: result.seo?.source };
  imageSeoEditor = editor;
  editor.url = `/api/images/requests/${encodeURIComponent(requestId)}/assets/${assetId}/seo`;
  openModal('SEO voor deze foto', `
    <div id="image-seo-form-${assetId}">
      <img class="image-seo-preview" src="${escHtml(result.url)}" alt="Te controleren productfoto">
      <p>Vijf velden voor deze fotoversie. Controleer wat werkelijk zichtbaar is; bijgerechten zijn serveersuggesties.</p>
      <p>Bij BBQ, Pan, Oven en Airfryer wordt de gekozen bereidingswijze in alle vijf velden opgenomen. Bij opslaan wordt een ontbrekende vermelding aangevuld. Rauwe varianten krijgen geen bereidingswijze.</p>
      <p id="image-seo-status" role="status" aria-live="polite">SEO ophalen…</p>
      ${IMAGE_SEO_FIELDS.map(([key, label]) => `<div class="form-group"><label for="image-seo-${key}">${label}</label>
        <textarea id="image-seo-${key}" rows="${key === 'description' ? 3 : 2}" maxlength="${key === 'description' ? 1600 : 400}" oninput="imageSeoEditor.dirty = true">${escHtml(result.metadata?.[key] || '')}</textarea>
        <button type="button" class="btn btn-outline btn-sm" onclick="copyImageSeo('${key}')">Kopiëren</button></div>`).join('')}
      <p>De opgeslagen bestandsnaam wordt ook bij de WEBP-download gebruikt. Versies blijven intern bewaard.</p>
      <p>Gebruik een unieke beschrijvende bestandsnaam zonder cijfers. Bij een dubbele naam kies je een ander zichtbaar detail. De WEBP-download is beschikbaar zodra alle vijf SEO-velden voor deze fotoversie volledig zijn opgeslagen.</p>
    </div>`, `<button class="btn btn-primary" id="image-seo-save" onclick="saveImageSeo()">SEO opslaan</button>
      <button class="btn btn-outline" id="image-seo-generate" onclick="generateImageSeo()">SEO opnieuw maken</button>
      <button class="btn btn-outline" onclick="copyImageSeo('all')">Alles kopiëren</button>
      <button class="btn btn-outline" onclick="closeImageSeo()">Sluiten</button>`);
  await refreshImageSeo(editor);
}

function closeImageSeo() {
  if (imageSeoEditor?.dirty && !confirm('Je hebt niet-opgeslagen SEO-wijzigingen. Toch sluiten?')) return;
  imageSeoEditor = null;
  closeModal();
}

function applyImageSeo(editor, data) {
  if (!imageSeoActive(editor)) return;
  if (Number(data.version) !== editor.version) {
    imageSeoMessage(editor, 'Deze foto is intussen gewijzigd. Sluit dit venster en open de actuele fotoversie. Je invoer is niet overschreven.');
    document.getElementById('image-seo-save').disabled = true;
    document.getElementById('image-seo-generate').disabled = true;
    return;
  }
  if (Number(data.seo.revision) < editor.revision) return;
  const result = productImageState.results.find(item => Number(item.asset_id) === Number(editor.assetId));
  if (result) { result.metadata = data.metadata; result.seo = data.seo; }
  if (typeof renderProductImageResults === 'function') renderProductImageResults();
  if (typeof productImagesSeoReady === 'function' && productImagesSeoReady()) {
    if (typeof finishProductImageRequest === 'function') finishProductImageRequest();
  }
  const pending = ['queued', 'processing'].includes(data.seo.status);
  document.getElementById('image-seo-generate').disabled = pending || editor.busy;
  if (!editor.dirty) {
    IMAGE_SEO_FIELDS.forEach(([key]) => { document.getElementById(`image-seo-${key}`).value = data.metadata[key] || ''; });
    editor.revision = Number(data.seo.revision);
    editor.source = data.seo.source;
  }
  imageSeoMessage(editor, data.seo.error || (pending
    ? 'AI analyseert deze foto. Je kunt de velden ook handmatig invullen en opslaan.'
    : editor.dirty && Number(data.seo.revision) !== editor.revision
      ? 'Er is nieuwe SEO beschikbaar. Je invoer blijft staan; kopieer eventuele correcties en open dit venster opnieuw.'
      : data.seo.ready && data.seo.source === 'ai' ? 'Alle vijf SEO-velden voor deze foto zijn ingevuld en opgeslagen. Controleer de teksten vóór publicatie.'
        : data.seo.ready && data.seo.source === 'manual' ? 'Handmatig opgeslagen SEO.' : 'De SEO is nog niet afgerond. Maak SEO of vul alle vijf velden in.'));
  if (pending) setTimeout(() => refreshImageSeo(editor), 2500);
}

async function refreshImageSeo(editor) {
  if (!imageSeoActive(editor)) return;
  const data = await api(editor.url, { silentError: true, onError: message => imageSeoMessage(editor, message) });
  if (data) applyImageSeo(editor, data);
}

async function saveImageSeo() {
  const editor = imageSeoEditor;
  if (!editor || !imageSeoActive(editor) || editor.busy) return;
  editor.busy = true;
  document.getElementById('image-seo-save').disabled = true;
  document.getElementById('image-seo-generate').disabled = true;
  const data = await api(editor.url, { method: 'PUT', body: { image_version: editor.version, revision: editor.revision, fields: imageSeoValues() },
    silentError: true, onError: message => imageSeoMessage(editor, message) });
  editor.busy = false;
  if (!imageSeoActive(editor)) return;
  document.getElementById('image-seo-save').disabled = false;
  document.getElementById('image-seo-generate').disabled = false;
  if (data) { editor.dirty = false; applyImageSeo(editor, data); imageSeoMessage(editor, 'SEO opgeslagen. De WEBP-download gebruikt deze bestandsnaam.'); }
}

async function generateImageSeo() {
  const editor = imageSeoEditor;
  if (!editor || !imageSeoActive(editor) || editor.busy) return;
  if ((editor.dirty || editor.source === 'manual') && !confirm('AI gaat de vijf SEO-velden opnieuw maken. Handmatige of niet-opgeslagen teksten kunnen worden vervangen. Doorgaan?')) return;
  editor.busy = true;
  document.getElementById('image-seo-generate').disabled = true;
  const data = await api(`${editor.url}/generate`, { method: 'POST', body: { image_version: editor.version, revision: editor.revision, replace_manual: editor.source === 'manual' },
    silentError: true, onError: message => imageSeoMessage(editor, message) });
  editor.busy = false;
  if (!imageSeoActive(editor)) return;
  if (data) { editor.dirty = false; applyImageSeo(editor, data); }
  else document.getElementById('image-seo-generate').disabled = false;
}

async function copyImageSeo(key) {
  const editor = imageSeoEditor;
  if (!editor || !imageSeoActive(editor)) return;
  const fields = imageSeoValues();
  try {
    await navigator.clipboard.writeText(key === 'all' ? IMAGE_SEO_FIELDS.map(([field, label]) => `${label}: ${fields[field]}`).join('\n\n') : fields[key]);
    imageSeoMessage(editor, 'Gekopieerd. Niet-opgeslagen wijzigingen moet je nog opslaan voor de download.');
  } catch { imageSeoMessage(editor, 'Kopiëren is niet toegestaan door de browser. Selecteer de tekst en gebruik Cmd+C of Ctrl+C.'); }
}
