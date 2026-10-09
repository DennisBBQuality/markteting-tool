<?php

// Isolated UI fixture: no application boot, database, credentials or provider calls.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$files = ['/js/converter.js' => 'application/javascript', '/css/style.css' => 'text/css'];
if (isset($files[$path])) {
    header('Content-Type: '.$files[$path]);
    readfile(__DIR__.'/../../public'.$path);

    return;
}
if ($path !== '/') {
    http_response_code(404);

    return;
}
?>
<!doctype html><html lang="nl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Lokale test — Stoppen in het Pitboard</title><link rel="stylesheet" href="/css/style.css">
<body style="padding:32px;background:#f5f3ef"><main style="max-width:1050px;margin:auto">
<h1>Productfoto Generator</h1><p>Lokale test met fictieve gegevens — er worden geen AI-aanvragen verstuurd.</p>
<section class="product-image-card" style="padding:24px">
<div class="product-image-actions"><button id="product-image-generate-btn" class="btn btn-primary" disabled>Productfoto’s worden gemaakt…</button>
<button id="product-image-stop-btn" class="btn btn-outline" onclick="stopProductImageGeneration()">Stoppen</button></div>
<div id="product-image-status" class="product-image-status" style="margin-top:20px"></div>
<div id="product-image-results" class="hidden"></div>
<p id="fixture-outcome" role="status"></p>
</section></main>
<script>
const escHtml = value => String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const toast = message => document.getElementById('fixture-outcome').textContent = message;
let fixtureStatus = 'processing';
window.fetch = async () => ({ok:true, json:async () => ({status:fixtureStatus, can_cancel:fixtureStatus === 'processing', results:[], progress:35, progress_label:'Verschillende productfoto’s maken',progress_step:'generating_product'})});
async function api(url, options) {
  if (!url.endsWith('/cancel') || options.method !== 'POST') throw Error('Unexpected fixture request');
  fixtureStatus = 'cancelling';
  setTimeout(() => {fixtureStatus = 'cancelled';}, 3000);
  return {status:'cancelling',can_cancel:false};
}
</script><script src="/js/converter.js"></script><script>
updateProductImageForm = () => {const button = document.getElementById('product-image-generate-btn'); button.disabled = productImageState.generating; button.textContent = productImageState.generating ? 'Productfoto’s worden gemaakt…' : 'Maak productfoto’s';};
productImageState.requestId = 'test-only'; productImageState.generating = true;
pollProductImageRequest('test-only');
</script></body></html>
