// Layout preferences contain only presentation choices, never business data.
const DashboardLayout = {
  names: {calendar:'Weekkalender', tasks:'Openstaande taken', projects:'Actieve projecten', notes:'Mijn notities', trunkrs:'Niet bezorgd Trunkrs'},
  defaults() {
    return [
      {id:'calendar', width:8, height:420, visible:true},
      {id:'tasks', width:4, height:420, visible:true},
      {id:'projects', width:6, height:280, visible:true},
      {id:'notes', width:6, height:280, visible:true},
      {id:'trunkrs', width:12, height:160, visible:true},
    ];
  },
  normalize(tiles) {
    const defaults = this.defaults();
    if (!Array.isArray(tiles)) return defaults;
    const seen = new Set();
    const result = tiles.filter(t => t && defaults.some(d => d.id === t.id) && !seen.has(t.id) && seen.add(t.id))
      .map(t => ({id:t.id, width:[4,6,8,12].includes(t.width)?t.width:6, height:Math.max(['calendar','tasks'].includes(t.id)?280:160,[160,280,420,560].includes(t.height)?t.height:280), visible:t.visible !== false}));
    return result.concat(defaults.filter(d => !seen.has(d.id)));
  },
  tiles: [], revision:0, editing:false, busy:false, ready:false, sortable:null, observer:null,
  dispose() {
    this.sortable?.destroy(); this.observer?.disconnect();
    this.sortable = null; this.observer = null; this.editing = false; this.ready = false;
    this.busy = false; this.tiles = []; this.saved = null;
  },
  async load(version, userId) {
    const data = await api('/api/dashboard/preferences', {silentError:true});
    if (!Dashboard.isCurrent(version, userId)) return false;
    this.tiles = this.normalize(data?.tiles);
    this.revision = Number.isInteger(data?.revision) ? data.revision : 0;
    this.ready = !!data && Number.isInteger(data.revision);
    return true;
  },
  mount() {
    const grid = document.getElementById('dashboard-widget-grid');
    grid.querySelectorAll('[data-widget]').forEach(section => {
      const wrapper = document.createElement('article');
      wrapper.className = 'dashboard-widget'; wrapper.dataset.widgetId = section.dataset.widget;
      section.before(wrapper); wrapper.append(section);
      const tools = document.createElement('div'); tools.className = 'widget-tools';
      const id = section.dataset.widget;
      tools.innerHTML = `<span class="widget-drag" title="Versleep tegel"><i class="fas fa-grip-vertical" aria-hidden="true"></i> ${this.names[id]}</span>
        <button type="button" aria-label="${this.names[id]} naar voren" onclick="DashboardLayout.move('${id}',-1)"><i class="fas fa-arrow-up" aria-hidden="true"></i></button>
        <button type="button" aria-label="${this.names[id]} naar achteren" onclick="DashboardLayout.move('${id}',1)"><i class="fas fa-arrow-down" aria-hidden="true"></i></button>
        <label>Breedte <select aria-label="Breedte ${this.names[id]}" onchange="DashboardLayout.change('${id}','width',Number(this.value))">${[[4,'Smal'],[6,'Half'],[8,'Breed'],[12,'Volledig']].map(([v,l])=>`<option value="${v}">${l}</option>`).join('')}</select></label>
        <label>Hoogte <select aria-label="Hoogte ${this.names[id]}" onchange="DashboardLayout.change('${id}','height',Number(this.value))">${[[160,'Mini'],[280,'Compact'],[420,'Normaal'],[560,'Hoog']].filter(([v])=>v>=280 || !['calendar','tasks'].includes(id)).map(([v,l])=>`<option value="${v}">${l}</option>`).join('')}</select></label>`;
      wrapper.prepend(tools);
    });
    const editor = document.getElementById('dashboard-layout-editor');
    editor.innerHTML = `<p>Jouw dashboard. Versleep de tegels met de handgreep of gebruik de pijlen. Kies per tegel de breedte en hoogte.</p>
      <div class="widget-visibility">${Object.entries(this.names).map(([id,name])=>`<label><input type="checkbox" data-visible="${id}" onchange="DashboardLayout.change('${id}','visible',this.checked)"> ${name}</label>`).join('')}</div>
      <div class="layout-actions"><button class="btn btn-primary" onclick="DashboardLayout.save()">Indeling opslaan</button>
      <button class="btn btn-outline" onclick="DashboardLayout.cancel()">Annuleren</button>
      <button class="btn btn-outline" onclick="DashboardLayout.reset()">Standaardindeling</button></div>`;
    const button = document.getElementById('dashboard-customize');
    button.disabled = !this.ready;
    if (!this.ready) document.getElementById('dashboard-layout-status').textContent = 'Je indeling kon niet worden geladen. De standaard wordt getoond. Herlaad om opnieuw te proberen.';
    this.apply();
    if (typeof ResizeObserver !== 'undefined') {
      this.observer = new ResizeObserver(() => this.resizeCalendar());
      this.observer.observe(document.querySelector('[data-widget="calendar"]'));
    }
  },
  resizeCalendar() {
    const section = document.querySelector('[data-widget="calendar"]');
    if (!Dashboard.calendar || !section || !section.getBoundingClientRect().height) return;
    const header = section.querySelector('header').getBoundingClientRect().height;
    const height = Math.max(150, section.clientHeight - header - 55);
    if (Dashboard.calendar.getOption('height') !== height) Dashboard.calendar.setOption('height',height);
    Dashboard.calendar.updateSize();
  },
  apply() {
    const grid = document.getElementById('dashboard-widget-grid');
    if (!grid) return;
    const scroller=grid.querySelector('#dashboard-calendar .fc-scroller-liquid-absolute');
    const scrollTop=scroller?.scrollTop;
    grid.classList.toggle('is-editing',this.editing);
    this.tiles.forEach(t => {
      const wrapper = grid.querySelector(`[data-widget-id="${t.id}"]`);
      if (!wrapper) return;
      grid.append(wrapper);
      wrapper.hidden = !t.visible;
      wrapper.style.setProperty('--widget-width',t.width);
      wrapper.style.setProperty('--widget-height',t.height+'px');
      wrapper.querySelectorAll('.widget-tools select').forEach(s => { s.value = s.getAttribute('aria-label').startsWith('Breedte')?t.width:t.height; });
      document.querySelector(`[data-visible="${t.id}"]`).checked = t.visible;
    });
    document.getElementById('dashboard-layout-editor').hidden = !this.editing;
    document.getElementById('dashboard-customize').hidden = this.editing;
    this.resizeCalendar();
    if (scrollTop !== undefined) {
      const updated=grid.querySelector('#dashboard-calendar .fc-scroller-liquid-absolute');
      if (updated) updated.scrollTop=scrollTop;
    }
  },
  edit() {
    if (!this.ready || this.busy) return;
    this.saved = structuredClone(this.tiles); this.editing = true; this.apply();
    let calendarScroll;
    if (typeof Sortable !== 'undefined') this.sortable = new Sortable(document.getElementById('dashboard-widget-grid'), {
      animation:150, handle:'.widget-drag', draggable:'.dashboard-widget',
      onStart:() => { calendarScroll=document.querySelector('#dashboard-calendar .fc-scroller-liquid-absolute')?.scrollTop; },
      onEnd:() => {
        const ids = [...document.querySelectorAll('#dashboard-widget-grid > [data-widget-id]')].map(el=>el.dataset.widgetId);
        this.tiles.sort((a,b)=>ids.indexOf(a.id)-ids.indexOf(b.id));
        this.resizeCalendar();
        const scroller=document.querySelector('#dashboard-calendar .fc-scroller-liquid-absolute');
        if (scroller && calendarScroll !== undefined) scroller.scrollTop=calendarScroll;
      },
    });
  },
  change(id, key, value) {
    if (!this.editing || this.busy) return;
    const tile = this.tiles.find(t=>t.id===id);
    if (!tile || !['width','height','visible'].includes(key)) return;
    tile[key] = value; this.apply();
  },
  move(id, direction) {
    if (!this.editing || this.busy) return;
    const index = this.tiles.findIndex(t=>t.id===id), next = index+direction;
    if (index<0 || next<0 || next>=this.tiles.length) return;
    [this.tiles[index],this.tiles[next]]=[this.tiles[next],this.tiles[index]]; this.apply();
  },
  reset() { if (this.editing && !this.busy) { this.tiles=this.defaults(); this.apply(); } },
  cancel() {
    if (this.busy) return;
    this.tiles=this.saved || this.defaults(); this.editing=false;
    this.sortable?.destroy(); this.sortable=null; this.apply();
  },
  async save() {
    if (!this.editing || this.busy) return;
    this.busy=true;
    const version=Dashboard.version, userId=App.currentUser.id;
    const status=document.getElementById('dashboard-layout-status');
    status.textContent='Indeling opslaan…';
    const controls=[...document.querySelectorAll('#dashboard-layout-editor button, #dashboard-layout-editor input, .widget-tools select, .widget-tools button')];
    controls.forEach(c=>c.disabled=true); this.sortable?.option('disabled',true);
    let errorMessage='';
    const data=await api('/api/dashboard/preferences',{method:'PUT',body:{tiles:this.tiles,revision:this.revision},silentError:true,onError:message=>{errorMessage=message;}});
    if (!Dashboard.isCurrent(version,userId)) return;
    this.busy=false; controls.forEach(c=>c.disabled=false); this.sortable?.option('disabled',false);
    if (!data || !Number.isInteger(data.revision)) {
      status.textContent='Niet opgeslagen. '+(errorMessage || 'Je aanpassingen staan nog in beeld; probeer opnieuw.'); return;
    }
    this.tiles=this.normalize(data.tiles); this.revision=data.revision; this.editing=false;
    this.sortable?.destroy(); this.sortable=null; this.apply();
    status.textContent='Jouw dashboardindeling is opgeslagen.';
  },
};
