const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
function harness() {
  const status={textContent:''};
  const context={structuredClone, Set, Number, App:{currentUser:{id:'me'}}, Dashboard:{version:1,isCurrent:(v,u)=>v===1&&u==='me'},
    document:{getElementById:()=>status,querySelectorAll:()=>[]}, api:async()=>({tiles:null,revision:0})};
  vm.createContext(context);
  vm.runInContext(fs.readFileSync('public/js/dashboard-layout.js','utf8')+'\nthis.layout=DashboardLayout;',context);
  context.layout.apply=()=>{};
  return {c:context,l:context.layout,status};
}
test('default is smaller calendar left, tasks right, projects and notes below',()=>{
  const {l}=harness(),tiles=l.defaults();
  assert.deepEqual(Array.from(tiles,t=>t.id),['calendar','tasks','projects','notes','trunkrs']);
  assert.equal(tiles[0].width,8); assert.equal(tiles[1].width,4);
});
test('invalid stored layout is bounded and missing widgets are restored',()=>{
  const {l}=harness(); const tiles=l.normalize([{id:'tasks',width:999,height:160,visible:false},{id:'tasks'},{id:'unknown'}]);
  assert.equal(tiles.length,5); assert.equal(tiles[0].height,280); assert.equal(tiles[0].width,6); assert.equal(tiles[0].visible,false);
});
test('reordering and resizing change only draft; cancel restores saved layout',()=>{
  const {l}=harness(); l.tiles=l.defaults(); l.saved=structuredClone(l.tiles);l.editing=true;
  l.move('tasks',-1); l.change('calendar','width',6); l.change('trunkrs','visible',false);
  assert.equal(l.tiles[0].id,'tasks'); l.cancel();
  assert.equal(l.tiles[0].id,'calendar');assert.equal(l.tiles[0].width,8);assert.equal(l.tiles[4].visible,true);
});
test('save sends an object to shared API, revision and no user-controlled identity',async()=>{
  const {l,c,status}=harness(); l.tiles=l.defaults();l.editing=true; let body;
  c.api=async(url,options)=>{body=options.body;return {tiles:body.tiles,revision:1};};
  await l.save();assert.equal(typeof body,'object');assert.equal(body.revision,0);assert.equal(body.user_id,undefined);
  assert.equal(l.revision,1);assert.equal(l.editing,false);assert.match(status.textContent,/opgeslagen/);
});
test('failed save keeps draft and persistent error; expired session cannot finish save',async()=>{
  const {l,c,status}=harness();l.tiles=l.defaults();l.editing=true;
  c.api=async(url,options)=>{options.onError('Conflicterend tabblad');return null;};
  await l.save();assert.equal(l.editing,true);assert.match(status.textContent,/Conflicterend tabblad/);
  c.api=async()=>{c.Dashboard.isCurrent=()=>false;return {revision:99};};
  await l.save();assert.equal(l.revision,0);
});
test('late preference load for previous user is ignored',async()=>{
  const {l,c}=harness();c.Dashboard.isCurrent=()=>false;
  assert.equal(await l.load(1,'previous'),false);assert.equal(l.ready,false);
});
