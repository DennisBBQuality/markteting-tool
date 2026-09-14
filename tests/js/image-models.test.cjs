const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');

function harness() {
  const fields = new Map();
  const field = id => {
    if (!fields.has(id)) fields.set(id, {id, innerHTML: '', isConnected: true, value: ''});
    return fields.get(id);
  };
  const data = {default_model:'gpt-image-2.5-sunburst', models:[
    {id:'gpt-image-2.5-sunburst',label:'Sunburst',available:true,experimental:false},
    {id:'gpt-image-2',label:'GPT Image 2',available:true,experimental:false},
    {id:'gpt-image-3',label:'New model',available:true,experimental:true},
  ]};
  const context = vm.createContext({document:{getElementById:field}, escHtml:s=>String(s).replaceAll('<','&lt;'),
    updateProductImageForm(){}, api:async()=>structuredClone(data), data});
  vm.runInContext(fs.readFileSync(path.join(__dirname, '../../public/js/image-models.js'),'utf8'),context);
  return {context,field,data,run:code=>vm.runInContext(code,context)};
}

test('Sunburst is initially selected and refresh preserves a different deliberate choice and uploads', async()=>{
  const h=harness(); h.field('product-image-name').value='Testproduct';
  h.field('product-image-selection').innerHTML='uploaded-photo';
  await h.run("ImageModelPicker.load('generator')");
  assert.equal(h.run("ImageModelPicker.selection('generator').image_model"),'gpt-image-2.5-sunburst');
  h.run("ImageModelPicker.choose('generator','gpt-image-2')");
  await h.run("ImageModelPicker.load('generator', true)");
  assert.equal(h.run("ImageModelPicker.selection('generator').image_model"),'gpt-image-2');
  assert.equal(h.field('product-image-name').value,'Testproduct');
  assert.equal(h.field('product-image-selection').innerHTML,'uploaded-photo');
});

test('new models require confirmation; changing selection clears that confirmation',async()=>{
  const h=harness(); await h.run("ImageModelPicker.load('generator')");
  h.run("ImageModelPicker.choose('generator','gpt-image-3')");
  assert.equal(h.run("ImageModelPicker.selection('generator')"),null);
  h.run("ImageModelPicker.accept('generator',true)");
  assert.equal(h.run("ImageModelPicker.selection('generator').accept_experimental"),true);
  h.run("ImageModelPicker.choose('generator','gpt-image-2');ImageModelPicker.choose('generator','gpt-image-3')");
  assert.equal(h.run("ImageModelPicker.selection('generator')"),null);
});

test('a disappeared selection never falls back to another model',async()=>{
  const h=harness(); await h.run("ImageModelPicker.load('generator')");
  h.data.models=h.data.models.filter(m=>m.id!=='gpt-image-2.5-sunburst');
  await h.run("ImageModelPicker.load('generator', true)");
  assert.equal(h.run("ImageModelPicker.selection('generator')"),null);
  assert.match(h.field('image-model-picker-generator').innerHTML,/niet meer in de lijst/);
});

test('errors remain visible and an old response cannot replace a newly opened picker',async()=>{
  const h=harness();
  h.context.api=async(url,options)=>{options.onError('Tijdelijk niet bereikbaar');return null;};
  await h.run("ImageModelPicker.load('generator')");
  assert.match(h.field('image-model-picker-generator').innerHTML,/Tijdelijk niet bereikbaar/);
  let resolve;
  h.context.api=()=>new Promise(r=>resolve=r);
  const pending=h.run("ImageModelPicker.load('generator')");
  const old=h.field('image-model-picker-generator');
  h.context.document.getElementById=()=>({id:old.id,innerHTML:'new view',isConnected:true});
  resolve(h.data);await pending;
  assert.doesNotMatch(old.innerHTML,/option value=/);
});

test('saving a default sends only the model and leaves the key field alone',async()=>{
  const h=harness(); await h.run("ImageModelPicker.load('settings')");
  h.field('openai-api-key').value='do-not-touch';
  let captured;
  h.context.api=async(url,options)=>{captured={url,...options};return {model:options.body.image_model,bericht:'Opgeslagen'};};
  await h.run('ImageModelPicker.saveDefault()');
  assert.equal(captured.url,'/api/settings/ai/openai/image-model');
  assert.deepEqual(Object.keys(captured.body),['image_model','accept_experimental']);
  assert.equal(h.field('openai-api-key').value,'do-not-touch');
  assert.match(h.field('image-model-picker-settings').innerHTML,/Opgeslagen/);
});
