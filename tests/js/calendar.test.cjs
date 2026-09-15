const {test}=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const vm=require('node:vm');
function harness() {
  const fields=new Map(); const messages=[]; let modal;
  const c={Date,Intl,URLSearchParams,clearInterval,App:{currentView:'dashboard'},
    document:{getElementById:id=>fields.get(id),querySelectorAll:()=>[]},
    escHtml:s=>String(s).replaceAll('&','&amp;').replaceAll('"','&quot;').replaceAll('<','&lt;'),
    createEmojiPicker:()=>'',projectSelectOptions:()=>'',colorPickerHtml:()=>'',attachmentsHtml:()=>'',loadAttachments:()=>{},
    openModal:(title,body,footer)=>{modal={title,body,footer};},closeModal:()=>{},
    getSelectedColor:()=>'#3B82F6',toast:message=>messages.push(message),api:async()=>({id:'new'})};
  vm.createContext(c);
  vm.runInContext(fs.readFileSync('public/js/dashboard.js','utf8')+'\nthis.Dashboard=Dashboard;\n'+fs.readFileSync('public/js/calendar.js','utf8'),c);
  return {c,fields,messages,modal:()=>modal};
}
test('date-only and timed clicks prefill local dates without duplicated T or UTC shifts',()=>{
  const {c}=harness();
  assert.equal(c.calendarInputDate('2026-09-15'),'2026-09-15T09:00');
  assert.equal(c.calendarInputDate('2026-09-15T13:30:00+02:00'),'2026-09-15T13:30:00');
  assert.equal(c.calendarInputDate('2026-09-15 13:30:00'),'2026-09-15T13:30:00');
  assert.equal(c.calendarNewDates('2026-09-15T23:30:00+02:00',false).end,'2026-09-16T00:30');
  assert.equal(c.calendarNewDates('2026-09-15',true).start,'2026-09-15T00:00');
  assert.equal(c.calendarNewDates('2026-09-15',true).end,'2026-09-16T00:00');
});
test('vacation checkbox reflects explicit choice, legacy names and the absence strip',()=>{
  const {c,modal}=harness();
  c.openCalendarModal(null,'2026-09-15T13:30:00+02:00');
  assert.match(modal().body,/value="2026-09-15T13:30:00"/);
  assert.doesNotMatch(modal().body,/id="cal-vacation" checked/);
  c.openCalendarModal(null,'2026-09-15',true);
  assert.match(modal().body,/id="cal-vacation" checked/);
  for(const [item,checked] of [[{id:'a',titel:'Sam vakantie'},true],[{id:'a',titel:'Sam vakantie',is_vacation:false},false],[{id:'a',titel:'Sam',is_vacation:true},true]]) {
    c.openCalendarModal(item);
    assert.equal(/id="cal-vacation" checked/.test(modal().body),checked);
  }
});
function fill(fields) {
  for(const [id,value] of Object.entries({'cal-titel':'Testmedewerker','cal-beschrijving':'Test','cal-type':'content','cal-project':'','cal-start':'2026-09-15T09:00','cal-eind':'2026-09-16T18:00','cal-link':''})) fields.set(id,{value});
  fields.set('cal-vacation',{checked:true});fields.set('cal-save',{disabled:false});
}
test('create and edit send explicit flag and refresh dashboard without switching page',async()=>{
  const {c,fields}=harness();fill(fields);let sent,refreshes=0;
  c.Dashboard.calendar={refetchEvents:()=>refreshes++};
  c.api=async(url,options)=>{sent={url,...options};return {id:'new'};};
  await c.saveCalendarItem('');assert.equal(sent.method,'POST');assert.equal(sent.body.is_vacation,true);
  assert.equal(refreshes,1); assert.equal(c.App.currentView,'dashboard');
  fields.get('cal-vacation').checked=false;
  await c.saveCalendarItem('existing');assert.equal(sent.method,'PUT');assert.equal(sent.body.is_vacation,false);
  assert.equal(refreshes,2);
});
test('failed save keeps modal input, pending save blocks double clicks and dates are validated',async()=>{
  const {c,fields,messages}=harness();fill(fields);let sends=0,resolve;
  c.api=()=>{sends++;return new Promise(r=>resolve=r);};
  const pending=c.saveCalendarItem('');await c.saveCalendarItem('');assert.equal(sends,1);
  resolve(null);await pending;assert.equal(fields.get('cal-save').disabled,false);
  assert.equal(fields.get('cal-vacation').checked,true);
  fields.get('cal-eind').value='2026-09-14T09:00';
  await c.saveCalendarItem('');assert.equal(sends,1);assert.match(messages.at(-1),/einddatum/);
});
