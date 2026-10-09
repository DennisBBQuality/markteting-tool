const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

function harness() {
  const nodes = new Map();
  const calls = [];
  const state = {enabled:true, approved:true, ready:true, fingerprint:'current', version:1, destination:'local'};
  const context = {App:{currentUser:{id:'owner', rol:'admin'}}, productImageState:{results:[{asset_id:1, version:1}, {asset_id:2, version:1}]},
    document:{getElementById: id => {if(!nodes.has(id)) nodes.set(id, {isConnected:true, innerHTML:'', disabled:false}); return nodes.get(id);}},
    toast(){}, escHtml: x => String(x).replaceAll('<','&lt;'), confirm:()=>true,
    api: async (url, opts={}) => {calls.push([url,opts]); return {...state};}};
  vm.createContext(context);
  vm.runInContext(fs.readFileSync('public/js/wordpress-media.js','utf8')+'\nthis.bridge = WordPressMedia;',context);
  return {context, bridge:context.bridge, nodes, calls, state};
}

test('approval sends exact fingerprint; absent approval cannot upload', async()=>{
  const h=harness(); await h.bridge.refresh();
  await h.bridge.approve(1,true);
  assert.equal(h.calls.find(([url])=>url.endsWith('/approval'))[1].body.fingerprint,'current');
  h.bridge.states.get(1).approved=false;
  const before=h.calls.length; await h.bridge.upload(1); assert.equal(h.calls.length,before);
});

test('batch is sequential and stops at first unconfirmed upload',async()=>{
  const h=harness(); await h.bridge.refresh();
  let active=0,max=0,posts=0;
  h.context.api=async(url,opts={})=>{
    if(opts.method==='POST') {posts++; active++;max=Math.max(max,active);await new Promise(r=>setImmediate(r));active--;return null;}
    return {...h.state};
  };
  await h.bridge.uploadApproved({disabled:false});
  assert.equal(posts,1); assert.equal(max,1); assert.equal(h.bridge.batch,false);
});

test('double click does not issue a second upload',async()=>{
  const h=harness(); await h.bridge.refresh();
  let release,posts=0;
  h.context.api=(url,opts={})=>{if(opts.method==='POST'){posts++; return new Promise(r=>release=r);}return Promise.resolve({...h.state});};
  const first=h.bridge.upload(1); await h.bridge.upload(1); assert.equal(posts,1);
  release({...h.state, uploaded:true}); await first;
});

test('late status response cannot replace a different photo version or detached view',async()=>{
  const h=harness(); h.context.api=async()=>({...h.state,version:2}); await h.bridge.refresh(); assert.equal(h.bridge.states.size,0);
  h.context.api=async()=>{h.nodes.get('wordpress-media-1').isConnected=false;return h.state;};
  await h.bridge.refresh(); assert.equal(h.bridge.states.has(1),false);
});

test('settings have password input with no stored value and identify public live media',async()=>{
  const h=harness(); h.context.api=async()=>({available:true,configured:true,username:'pitboard',destination:'live',url:'https://www.bbquality.nl'});
  await h.bridge.settings(); const html=h.nodes.get('wordpress-settings').innerHTML;
  assert.match(html,/type="password"/); assert.match(html,/openbaar/); assert.doesNotMatch(html,/id="wordpress-password"[^>]*value=/);
});
