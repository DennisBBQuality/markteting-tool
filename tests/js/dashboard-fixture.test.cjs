const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const fs=require('node:fs');
const os=require('node:os');
const path=require('node:path');
const net=require('node:net');
const {spawn}=require('node:child_process');
const {once}=require('node:events');

// Exercise the real browser API helper and layout module against the isolated
// PHP fixture. Mocking api() alone misses missing security-cookie regressions.
test('fixture supports secure layout save, reload, expiry and stale-tab protection', {timeout:15000}, async t=>{
  const listener=net.createServer();
  listener.listen(0,'127.0.0.1'); await once(listener,'listening');
  const port=listener.address().port;
  await new Promise(resolve=>listener.close(resolve));
  const sessions=fs.mkdtempSync(path.join(os.tmpdir(),'pitboard-fixture-test-'));
  const server=spawn('php',['-d',`session.save_path=${sessions}`,'-S',`127.0.0.1:${port}`,'tests/browser/dashboard-router.php'],{stdio:'ignore'});
  t.after(async()=>{
    if(server.exitCode===null) { const exited=once(server,'exit'); server.kill(); await exited; }
    fs.rmSync(sessions,{recursive:true,force:true});
  });
  const base=`http://127.0.0.1:${port}`;
  let ready=false;
  for(let i=0;i<50;i++) {
    try { ready=(await fetch(base)).ok; } catch {}
    if(ready) break;
    await new Promise(resolve=>setTimeout(resolve,50));
  }
  assert.ok(ready,'isolated fixture started');
  const jar=new Map();
  const requests=[];
  async function browserFetch(url,options={}) {
    requests.push([options.method||'GET',url]);
    const response=await fetch(base+url,{...options,headers:{...options.headers,Cookie:[...jar].map(([k,v])=>`${k}=${v}`).join('; ')}});
    for(const cookie of response.headers.getSetCookie()) {
      const pair=cookie.split(';')[0], index=pair.indexOf('=');
      jar.set(pair.slice(0,index),pair.slice(index+1));
    }
    return response;
  }
  function page() {
    const status={textContent:''};
    const context={structuredClone,fetch:browserFetch,toast:()=>{},showLogin:()=>{},
      document:{get cookie(){return [...jar].filter(([k])=>k==='XSRF-TOKEN').map(([k,v])=>`${k}=${v}`).join('; ');},getElementById:()=>status,querySelectorAll:()=>[]},
      Dashboard:{version:1,isCurrent:()=>true}};
    vm.createContext(context);
    const app=fs.readFileSync('public/js/app.js','utf8').split('async function apiUpload(')[0];
    vm.runInContext(app+'\nApp.currentUser={id:"fixture-user"};\n'+fs.readFileSync('public/js/dashboard-layout.js','utf8')+'\nthis.layout=DashboardLayout;',context);
    context.layout.apply=()=>{};
    return {context,layout:context.layout,status};
  }
  const first=page();
  first.layout.tiles=first.layout.defaults(); first.layout.editing=true;
  first.layout.change('calendar','width',6);
  first.layout.change('calendar','height',560);
  first.layout.move('tasks',-1);
  first.layout.change('trunkrs','visible',false);
  const expected=JSON.stringify(first.layout.tiles);
  await first.layout.save();
  assert.equal(first.layout.editing,false,first.status.textContent);
  assert.equal(first.layout.revision,1);
  assert.match(first.status.textContent,/opgeslagen/);
  assert.ok(requests.some(([method,url])=>method==='GET'&&url==='/api/auth/csrf'));
  const reloaded=page();
  await reloaded.layout.load(1,'fixture-user');
  assert.equal(JSON.stringify(reloaded.layout.tiles),expected);
  assert.equal(reloaded.layout.revision,1);
  // The fixture must reject a missing token, not merely make the button green.
  const rejected=await browserFetch('/api/dashboard/preferences',{method:'PUT',headers:{'Content-Type':'application/json'},body:JSON.stringify({tiles:[],revision:1})});
  assert.equal(rejected.status,419);
  jar.set('XSRF-TOKEN','expired-test-token');
  reloaded.layout.editing=true;
  reloaded.layout.change('calendar','width',8);
  await reloaded.layout.save();
  assert.equal(reloaded.layout.revision,2);
  assert.equal(reloaded.layout.editing,false);
  first.layout.editing=true;
  await first.layout.save();
  assert.equal(first.layout.editing,true);
  assert.match(first.status.textContent,/Niet opgeslagen/);
  const latest=page(); await latest.layout.load(1,'fixture-user');
  assert.equal(latest.layout.revision,2);
  assert.equal(latest.layout.tiles.find(tile=>tile.id==='calendar').width,8);
});
