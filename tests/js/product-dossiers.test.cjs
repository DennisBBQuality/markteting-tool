const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');

function harness() {
  const fields = new Map();
  const field = id => {
    if (!fields.has(id)) fields.set(id, {id, value:'', checked:false, disabled:false, dataset:{}, innerHTML:'', textContent:'', classList:{add(){},remove(){},toggle(){}}, focus(){}, scrollIntoView(){}});
    return fields.get(id);
  };
  const storage = new Map();
  const context = vm.createContext({
    window:{addEventListener(){}}, App:{currentUser:{id:'user-a'},currentView:'product-dossiers'},
    document:{getElementById:field, querySelectorAll:() => [], querySelector:() => null},
    sessionStorage:{setItem:(k,v)=>storage.set(k,v), getItem:k=>storage.get(k)||null, removeItem:k=>storage.delete(k)},
    URL:{revokeObjectURL(){}}, escHtml:String, formatDateTime:String, toast(){}, confirm:()=>true,
    structuredClone, setTimeout:()=>1, clearTimeout(){}, api:async()=>null,
  });
  vm.runInContext(fs.readFileSync(path.join(__dirname,'../../public/js/product-dossiers.js'),'utf8'), context);
  return {field,context,storage,run:code=>vm.runInContext(code,context)};
}

test('generation lock survives the end of the save request', () => {
  const h=harness(); h.run('productDossierState.aiActive=true; productDossierState.generating=true; setDossierButtonsBusy(false)');
  assert.equal(h.field('dossier-generate-btn').disabled,true);
  assert.equal(h.field('dossier-save-only-btn').disabled,false);
});

test('analysis lock cannot be undone by refreshing button state', () => {
  const h=harness(); h.run("productDossierState.aiActive=true; productDossierState.analyzing=true; productDossierState.labelImages=[{}]; updateDossierAnalyzeButton()");
  assert.equal(h.field('dossier-analyze-btn').disabled,true);
});

test('FAQ question and answer are individually copyable', () => {
  const h=harness(); h.run("productDossierState.generatedContent={faqs:[{vraag:'Wat krijg ik?',antwoord:'Een complete brisket.'}]}");
  assert.equal(h.run("generatedPartText('question',0)"),'Wat krijg ik?');
  assert.equal(h.run("generatedPartText('answer',0)"),'Een complete brisket.');
});

test('automatic choices keep following typing but respect manual choice', () => {
  const h=harness(); h.run("productDossierState.options.selections=[{label:'Angus'},{label:'El Rancho'}]");
  h.field('dossier-product-name').value='Black Angus'; h.run('autoSelectDossierChoices()');
  assert.equal(h.field('dossier-selection').value,'Angus');
  h.field('dossier-product-name').value='Black Angus brisket El Rancho'; h.run('autoSelectDossierChoices()');
  assert.equal(h.field('dossier-selection').value,'El Rancho');
  h.field('dossier-selection').value='Tierno'; h.run('autoSelectDossierChoices()');
  assert.equal(h.field('dossier-selection').value,'Tierno');
});

test('browser recovery uses separate user and dossier keys', () => {
  const h=harness();
  assert.equal(h.run("dossierStorageKey('abc')"),'bbquality-studio:user-a:abc');
  h.run("App.currentUser.id='user-b'");
  assert.equal(h.run("dossierStorageKey('abc')"),'bbquality-studio:user-b:abc');
});

test('expert approval is not automatically set by filling a name', () => {
  const h=harness(); h.field('dossier-expert-name').value='Testexpert';
  assert.equal(h.run('collectProductDossierData().expert.approved'),false);
});

test('empty PHP source arrays become objects before a manual field source is added', () => {
  const h=harness();
  h.run('productDossierState.nutritionMeta.field_sources=[]');
  const nutrition=h.run('collectProductDossierData().nutrition');
  nutrition.field_sources.zout='handmatig';
  assert.equal(JSON.parse(JSON.stringify(nutrition)).field_sources.zout,'handmatig');
});

test('composition source badges survive collecting the form for a text-only edit', () => {
  const h=harness();
  h.field('dossier-ingredients').value='Rundvlees';
  h.field('dossier-allergens').value='Geen verwacht';
  h.run("setFieldSource('ingredients','etiket'); setFieldSource('allergens','ai_schatting')");
  assert.equal(h.run('collectProductDossierData().ingredients_source_status'),'etiket');
  assert.equal(h.run('collectProductDossierData().allergens_source_status'),'ai_schatting');
});
