// Content workflow. Platform publication stays separate from editorial approval.
const Content = {
  tab: 'overview', posts: [], media: [], page: 1, total: 0, lastPage: 1, editor: null,
  dirty: false, saving: false, generation: 0, timer: null, calendar: null,
  platforms: {instagram: 'Instagram', facebook: 'Facebook', youtube: 'YouTube', tiktok: 'TikTok'},
  statuses: {draft: 'Concept', review: 'Ter beoordeling', approved: 'Goedgekeurd', changes: 'Aanpassen', submitting: 'Wordt aangeboden', scheduled: 'Ingepland', processing: 'Wordt verwerkt', published: 'Gepubliceerd', failed: 'Mislukt', unknown: 'Uitkomst controleren', cancelled: 'Geannuleerd'},
  canReview() { return ['admin', 'manager'].includes(App.currentUser?.rol); },
  draftKey() { return `pitboard-content-draft:${App.currentUser?.id}`; },
  editable(post) { return !(post.deliveries || []).some(d => !['draft', 'cancelled'].includes(d.status)); },
  label(value) { return this.statuses[value] || value; },
  badge(value) { return `<span class="content-badge content-status-${escHtml(value)}">${escHtml(this.label(value))}</span>`; },
  amsterdam(iso) {
    if (!iso) return '';
    const p = new Intl.DateTimeFormat('sv-SE', {timeZone:'Europe/Amsterdam',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).format(new Date(iso));
    return p.replace(' ', 'T');
  },
  utc(local) {
    if (!local) return null;
    const matches = ['+01:00', '+02:00'].map(offset => `${local}:00${offset}`).filter(iso => this.amsterdam(iso) === local);
    if (matches.length !== 1) throw new Error(matches.length ? 'Deze tijd komt tweemaal voor door de wintertijd. Kies een ander tijdstip.' : 'Deze tijd bestaat niet. Controleer de datum en de zomertijd.');
    return matches[0];
  },
  remember() {
    if (!this.editor || !this.dirty) return;
    try { localStorage.setItem(this.draftKey(), JSON.stringify(this.collect())); } catch (_) { /* Invalid dates remain in the live form. */ }
  },
  leave() {
    if (this.saving) { toast('Wacht tot het opslaan klaar is.', 'error'); return false; }
    this.remember();
    if (this.dirty && !confirm('Er zijn nog niet opgeslagen wijzigingen. Deze pagina verlaten?')) return false;
    clearTimeout(this.timer); this.calendar?.destroy(); this.calendar = null; return true;
  },
  async render(tab = this.tab) {
    if (this.editor && !this.leave()) return;
    this.editor = null; this.dirty = false; this.tab = tab;
    const ticket = ++this.generation;
    const container = document.getElementById('view-content');
    container.innerHTML = `<div class="page-header"><div><h2>Content</h2><p class="content-muted">Van eerste idee tot bevestigde publicatie.</p></div><button class="btn btn-primary" id="content-new"><i class="fas fa-plus"></i> Nieuwe content</button></div>
      <nav class="content-tabs" aria-label="Contentonderdelen">${[['overview','Overzicht'],['calendar','Kalender'],['review','Goedkeuringen'],['media','Media'],['connection','Kanalen']].map(([key,label])=>`<button class="btn ${key===tab?'btn-primary':'btn-secondary'}" data-content-tab="${key}">${label}</button>`).join('')}</nav>
      <div id="content-body" aria-live="polite">Bezig met laden…</div>`;
    document.getElementById('content-new').onclick=()=>this.open();
    container.querySelectorAll('[data-content-tab]').forEach(b=>b.onclick=()=>{this.page=1;this.render(b.dataset.contentTab);});
    if (tab==='connection') { await this.connection(); return; }
    if (tab==='media') { await this.library(); return; }
    const response = await api(`/api/content/posts?page=${this.page}${tab==='review'?'&status=review':''}`);
    if (ticket!==this.generation || App.currentView!=='content') return;
    if (!response) { document.getElementById('content-body').innerHTML='<p>Content kon niet worden geladen. Vernieuw dit overzicht om opnieuw te proberen.</p>'; return; }
    this.posts=response.data; this.total=response.total;this.lastPage=response.last_page;
    if (tab==='calendar') {
      // Load every page for the calendar instead of silently showing only the first 100 items.
      for (let page=2;page<=response.last_page;page++) {
        const more=await api(`/api/content/posts?page=${page}`);
        if(ticket!==this.generation)return;
        if(!more){document.getElementById('content-body').textContent='De kalender is onvolledig. Vernieuw het overzicht.';return;}
        this.posts.push(...more.data);
      }
    }
    this.list();
  },
  list() {
    const body=document.getElementById('content-body');
    const recovery=localStorage.getItem(this.draftKey());
    body.innerHTML=`${recovery?'<div class="content-notice">Er staat nog een lokaal bewaarde invoer klaar. <button class="btn btn-secondary" id="content-recover">Invoer herstellen</button><button class="btn btn-secondary" id="content-discard">Wissen</button></div>':''}
      <div class="content-filters"><label>Kanaal<select id="content-platform"><option value="">Alle kanalen</option>${Object.entries(this.platforms).map(([key,label])=>`<option value="${key}">${label}</option>`).join('')}</select></label><label>Zoeken op deze pagina<input id="content-search" type="search" placeholder="Titel of briefing"></label><span class="content-muted">${this.total} contentitems · Europe/Amsterdam</span></div>
      <div id="content-results"></div><div class="content-pagination"><button class="btn btn-secondary" id="content-prev" ${this.page<=1?'disabled':''}>Vorige</button><span>Pagina ${this.page} van ${this.lastPage}</span><button class="btn btn-secondary" id="content-next" ${this.page>=this.lastPage?'disabled':''}>Volgende</button></div>`;
    if(recovery) {
      document.getElementById('content-recover').onclick=async()=>{try{const saved=JSON.parse(recovery);await this.open(saved.id||null,saved);}catch(_){toast('De bewaarde invoer kon niet worden hersteld.','error');}};
      document.getElementById('content-discard').onclick=()=>{localStorage.removeItem(this.draftKey());this.list();};
    }
    document.getElementById('content-prev').onclick=()=>{this.page--;this.render();};
    document.getElementById('content-next').onclick=()=>{this.page++;this.render();};
    document.getElementById('content-platform').onchange=()=>this.results();
    document.getElementById('content-search').oninput=()=>this.results();this.results();
  },
  results() {
    const platform=document.getElementById('content-platform').value;
    const search=document.getElementById('content-search').value.toLowerCase();
    const posts=this.posts.filter(p=>(!platform||p.deliveries.some(d=>d.platform===platform))&&`${p.title} ${p.brief||''}`.toLowerCase().includes(search));
    const results=document.getElementById('content-results');
    this.calendar?.destroy();this.calendar=null;
    if(this.tab==='calendar') {
      results.innerHTML='<div id="content-calendar"></div><div class="content-unplanned"><h3>Nog zonder publicatiedatum</h3><div id="content-undated"></div></div>';
      this.calendar=new FullCalendar.Calendar(document.getElementById('content-calendar'),{
        locale:'nl',buttonText:{today:'Vandaag',month:'Maand',week:'Week',list:'Lijst'},eventTimeFormat:{hour:'2-digit',minute:'2-digit',hour12:false},initialView:'dayGridMonth',firstDay:1,headerToolbar:{left:'prev,next today',center:'title',right:'dayGridMonth,timeGridWeek,listWeek'},
        events:posts.flatMap(p=>p.deliveries.filter(d=>d.scheduled_at&&(!platform||d.platform===platform)).map(d=>({id:d.id,title:`${this.platforms[d.platform]} · ${p.title} · ${this.label(d.status==='draft'?p.review_status:d.status)}`,start:this.amsterdam(d.scheduled_at),color:d.status==='published'?'#26734d':d.status==='failed'?'#b42318':'#ea484b',extendedProps:{postId:p.id}}))),
        eventClick:info=>this.open(info.event.extendedProps.postId),eventDidMount:info=>{info.el.tabIndex=0;info.el.setAttribute('role','button');info.el.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();this.open(info.event.extendedProps.postId);}});},dateClick:()=>this.open(),height:'auto'
      });this.calendar.render();
      document.getElementById('content-undated').innerHTML=posts.filter(p=>!p.deliveries.length||p.deliveries.some(d=>!d.scheduled_at)).map(p=>this.card(p)).join('')||'<p class="content-muted">Alle items hebben een publicatiedatum.</p>';
    } else results.innerHTML=`<div class="content-grid">${posts.map(p=>this.card(p)).join('')||'<div class="content-empty"><i class="fas fa-feather"></i><h3>Ruimte voor je volgende verhaal</h3><p>Maak een concept en werk het samen uit.</p></div>'}</div>`;
    results.querySelectorAll('[data-open-post]').forEach(b=>b.onclick=()=>this.open(b.dataset.openPost));
  },
  card(p) {
    return `<button class="content-card" data-open-post="${p.id}">${this.badge(p.review_status)}<h3>${escHtml(p.title)}</h3><p>${escHtml((p.brief||'Nog geen briefing').slice(0,130))}</p><div>${p.deliveries.map(d=>`<div class="content-channel-line"><span>${this.platforms[d.platform]}</span>${this.badge(d.status)}<small>${d.scheduled_at?escHtml(this.amsterdam(d.scheduled_at).replace('T',' ')):'Nog geen datum'}</small></div>`).join('')}</div><small class="content-muted">${escHtml(App.users.find(u=>u.id===p.owner_id)?.naam||'Team')} · Versie ${p.revision}</small></button>`;
  },
  async open(id=null,recovery=null) {
    if(this.editor&&!this.leave())return;
    const ticket=++this.generation;
    const post=id?await api(`/api/content/posts/${id}`):{title:'',brief:'',source_url:'',owner_id:App.currentUser.id,project_id:null,task_id:null,revision:1,review_status:'draft',deliveries:[],activities:[]};
    if(!post||ticket!==this.generation)return;
    const [media,tasks]=await Promise.all([api('/api/content/media'),api('/api/tasks')]);
    if(tasks)App.tasks=tasks;
    if(ticket!==this.generation)return;
    this.media=media?.data||[];
    const missing=(recovery?.deliveries||post.deliveries).flatMap(d=>d.media_ids||[]).filter(id=>!this.media.some(m=>m.id===id));
    if(missing.length){const extra=await api('/api/content/media?'+[...new Set(missing)].map(id=>'ids[]='+encodeURIComponent(id)).join('&'));if(ticket!==this.generation)return;if(!extra){toast('Geselecteerde media konden niet worden geladen. Open het bericht opnieuw.','error');return;}this.media.push(...extra.data);}
    if(recovery) {
      // Retain the stale revision so the server refuses overwriting newer work.
      Object.assign(post,recovery);toast('Bewaarde invoer hersteld. Controleer en sla op.','info');
    }
    this.editor=post;this.dirty=!!recovery;this.calendar?.destroy();this.calendar=null;
    const editable=this.editable(post);
    const root=document.getElementById('content-body');
    root.innerHTML=`<div class="content-editor-head"><button class="btn btn-secondary" id="content-back">← Overzicht</button><span><span id="content-editor-status">${this.badge(post.review_status)}</span> <span id="content-save-state" role="status">${recovery?'Herstelde invoer, nog niet opgeslagen':post.id?'Opgeslagen':'Nieuw concept'}</span></span></div>
      ${!editable?'<div class="content-notice">Deze versie is aangeboden voor publicatie en staat vast. Gebruik Dupliceren voor een nieuwe versie.</div>':''}
      <div class="content-editor"><form id="content-form"><fieldset ${!editable?'disabled':''}>
      <section class="content-panel"><h3>1. Verhaal en verantwoordelijkheid</h3>
      <label>Interne titel<input id="content-title" maxlength="200" required value="${escHtml(post.title)}" placeholder="Bijvoorbeeld: winterrecept op de BBQ"></label>
      <div class="content-two"><label>Eigenaar<select id="content-owner">${App.users.map(u=>`<option value="${u.id}" ${post.owner_id===u.id?'selected':''}>${escHtml(u.naam)}</option>`).join('')}</select></label>
      <label>Project / campagne<select id="content-project"><option value="">Geen project</option>${App.projects.map(p=>`<option value="${p.id}" ${post.project_id===p.id?'selected':''}>${escHtml(p.naam)}</option>`).join('')}</select></label></div>
      <label>Briefing<textarea id="content-brief" rows="3" placeholder="Doel, doelgroep en wat er gemaakt moet worden">${escHtml(post.brief||'')}</textarea></label>
      <label>Bron of receptlink<input id="content-source" type="url" value="${escHtml(post.source_url||'')}" placeholder="https://…"></label>
      <label>Gekoppelde productietaak<select id="content-task"><option value="">Geen taak</option>${(App.tasks||[]).map(t=>`<option value="${t.id}" ${post.task_id===t.id?'selected':''}>${escHtml(t.titel)}</option>`).join('')}</select></label></section>
      <section class="content-panel"><h3>2. Kanalen en content</h3><p class="content-muted">Ieder kanaal heeft een eigen tekst, bestand en publicatiemoment.</p>
      <div class="content-choices">${Object.entries(this.platforms).map(([key,label])=>`<label><input type="checkbox" data-channel="${key}" ${post.deliveries.some(d=>d.platform===key)?'checked':''}> ${label}</label>`).join('')}</div>
      <div id="content-versions">${post.deliveries.map(d=>this.version(d)).join('')}</div></section></fieldset>
      <div class="content-actions"><button type="submit" class="btn btn-primary" ${!editable?'disabled':''}>Concept opslaan</button><button type="button" id="content-review" class="btn btn-secondary" ${!editable?'disabled':''}>Ter beoordeling</button>
      ${this.canReview()?`<button type="button" id="content-approve" class="btn btn-secondary" ${post.review_status!=='review'||!editable?'disabled':''}>Goedkeuren</button><button type="button" id="content-changes" class="btn btn-secondary" ${post.review_status!=='review'||!editable?'disabled':''}>Aanpassing vragen</button><button type="button" id="content-schedule" class="btn btn-primary" ${post.review_status!=='approved'||!post.deliveries.some(d=>d.platform!=='tiktok'&&['draft','cancelled'].includes(d.status))?'disabled':''}>Automatisch inplannen</button>`:''}
      ${post.id?'<button type="button" class="btn btn-secondary" id="content-duplicate">Dupliceren</button>':''}</div>
      <p id="content-error" class="error-msg" role="alert"></p></form>
      <aside><section class="content-panel content-preview"><h3>Voorbeeld</h3><label>Kanaal<select id="content-preview-channel"></select></label><p class="content-muted">Indicatief; het platform bepaalt de uiteindelijke weergave.</p><div id="content-preview"></div></section>
      <section class="content-panel"><h3>Publicatiecontrole</h3><ul id="content-checks"></ul><p class="content-muted">Een concept of goedkeuring is nog geen publicatie.</p></section>
      <section class="content-panel"><h3>Feedback en historie</h3><div class="content-history">${(post.activities||[]).slice(-15).reverse().map(a=>`<p><strong>${escHtml(App.users.find(u=>u.id===a.user_id)?.naam||'Systeem')}</strong> · v${a.revision}<br>${escHtml(a.message)}</p>`).join('')||'<p class="content-muted">Nog geen activiteiten.</p>'}</div>
      <label>Opmerking<textarea id="content-comment" rows="3"></textarea></label><button class="btn btn-secondary" id="content-comment-send" ${!post.id?'disabled':''}>Reactie toevoegen</button></section>
      ${post.id?`<section class="content-panel"><h3>Publicatieresultaat</h3><div id="content-delivery-results"></div>${this.canReview()?'<button class="btn btn-secondary" id="content-refresh">Status controleren</button>':''}${this.canReview()&&post.deliveries.some(d=>d.status==='scheduled')?'<button class="btn btn-secondary" id="content-cancel">Wachtende planning annuleren</button>':''}</section>`:''}</aside></div>`;
    document.getElementById('content-back').onclick=()=>this.render('overview');
    const form=document.getElementById('content-form');form.onsubmit=e=>{e.preventDefault();this.save();};
    form.oninput=()=>this.changed();
    root.querySelectorAll('[data-channel]').forEach(input=>input.onchange=()=>{
      const versions=document.getElementById('content-versions');
      if(input.checked)versions.insertAdjacentHTML('beforeend',this.version({platform:input.dataset.channel,text:'',media_ids:[],options:{format:['youtube','tiktok'].includes(input.dataset.channel)?'video':'photo'}}));
      else document.getElementById(`content-version-${input.dataset.channel}`)?.remove();
      this.wireVersions();this.changed();
    });this.wireVersions();this.preview();
    document.getElementById('content-preview-channel').onchange=()=>this.preview();
    document.getElementById('content-cancel')?.addEventListener('click',()=>this.cancel());
    document.getElementById('content-changes')?.addEventListener('click',()=>this.review('changes'));
    document.getElementById('content-review').onclick=()=>this.review('request');
    document.getElementById('content-approve')?.addEventListener('click',()=>this.review('approve'));
    document.getElementById('content-schedule')?.addEventListener('click',()=>this.schedule());
    document.getElementById('content-duplicate')?.addEventListener('click',async()=>{const copy=await api(`/api/content/posts/${post.id}/duplicate`,{method:'POST'});if(copy){this.dirty=false;await this.open(copy.id);}});
    document.getElementById('content-comment-send').onclick=async()=>{const value=document.getElementById('content-comment').value;if(!value.trim())return;const r=await api(`/api/content/posts/${this.editor.id}/comments`,{method:'POST',body:{message:value}});if(r){await this.saveIfDirty();if(!this.dirty)await this.open(this.editor.id);}};
    document.getElementById('content-refresh')?.addEventListener('click',()=>this.refreshStatus());
    this.deliveryResults();
  },
  version(d) {
    const p=d.platform,o=d.options||{};
    return `<section class="content-version" id="content-version-${p}" data-platform="${p}"><h4>${this.platforms[p]}</h4><p class="content-muted">${({instagram:'Automatisch: één JPG-foto of één MP4 Reel.',facebook:'Automatisch: tekst of één foto. Facebook-video volgt later.',youtube:'Automatisch: één MP4-video. De gekozen zichtbaarheid blijft van toepassing.',tiktok:'Alleen voorbereiden en plannen; automatisch plaatsen volgt later.'})[p]}</p>
      <div class="content-two"><label>Formaat<select data-field="format">${['photo','video','text'].map(f=>`<option value="${f}" ${(o.format||'photo')===f?'selected':''}>${({photo:'Foto',video:'Video',text:'Tekst'})[f]}</option>`).join('')}</select></label><label>Publicatie · Amsterdam<input data-field="scheduled_at" type="datetime-local" value="${this.amsterdam(d.scheduled_at)}"></label></div>
      <label>Tekst<textarea data-field="text" rows="4">${escHtml(d.text||'')}</textarea></label>
      ${p==='youtube'?`<label>Videotitel<input data-field="title" maxlength="100" value="${escHtml(o.title||'')}"></label>`:''}
      ${['youtube','tiktok'].includes(p)?`<label>Zichtbaarheid<select data-field="privacy"><option value="">Kies zichtbaarheid</option>${(p==='youtube'?['public','unlisted','private']:['public','private']).map(v=>`<option value="${v}" ${o.privacy===v?'selected':''}>${({public:'Openbaar',unlisted:'Verborgen',private:'Privé'})[v]}</option>`).join('')}</select></label>`:''}
      ${p==='youtube'?`<label>Gemaakt voor kinderen?<select data-field="made_for_kids"><option value="">Maak een keuze</option><option value="false" ${o.made_for_kids===false?'selected':''}>Nee</option><option value="true" ${o.made_for_kids===true?'selected':''}>Ja</option></select></label>`:''}
      <label><input type="checkbox" data-field="synthetic" ${o.synthetic?'checked':''}> Bevat realistische AI-gegenereerde of gewijzigde beelden</label>
      ${p==='tiktok'?`<label><input type="checkbox" data-field="commercial" ${o.commercial?'checked':''}> Promoot ons eigen merk of product</label><label><input type="checkbox" data-field="allow_comments" ${o.allow_comments?'checked':''}> Reacties toestaan</label>`:''}
      <label>Media kiezen<select data-field="media_ids" multiple size="3">${this.media.map(m=>`<option value="${m.id}" ${(d.media_ids||[]).includes(m.id)?'selected':''}>${escHtml(m.name)}</option>`).join('')}</select></label>
      <label class="content-upload">Bestand toevoegen (JPG, PNG, WEBP, MP4 · maximaal 25 MB)<input type="file" data-upload="${p}" accept="image/jpeg,image/png,image/webp,video/mp4"></label>
      <p class="content-muted">Video-uploadcapaciteit hangt ook af van de server. Een origineel wordt niet automatisch bijgesneden.</p></section>`;
  },
  wireVersions() {
    document.querySelectorAll('[data-upload]').forEach(input=>input.onchange=async()=>{
      const file=input.files[0];if(!file)return;if(file.size>25*1024*1024){toast('Dit bestand is groter dan 25 MB.','error');return;}
      const ticket=this.generation;clearTimeout(this.timer);const form=new FormData;form.append('file',file);
      const media=await apiUpload('/api/content/media',form);if(!media||ticket!==this.generation)return;
      this.media.unshift(media);
      document.querySelectorAll('[data-field="media_ids"]').forEach(select=>{const option=new Option(media.name,media.id,false,select.closest('[data-platform]').dataset.platform===input.dataset.upload);select.add(option,0);});this.changed();
    });
  },
  collect() {
    const get=id=>document.getElementById(id)?.value||'';
    return {id:this.editor.id,revision:this.editor.revision,title:get('content-title'),brief:get('content-brief'),source_url:get('content-source')||null,owner_id:get('content-owner'),project_id:get('content-project')||null,task_id:get('content-task')||null,
      deliveries:[...document.querySelectorAll('#content-versions [data-platform]')].map(section=>{
        const el=key=>section.querySelector(`[data-field="${key}"]`);const value=key=>el(key)?.value||'';
        const options={format:value('format'),synthetic:!!el('synthetic')?.checked};
        for(const key of ['title','privacy'])if(el(key))options[key]=value(key)||null;
        if(el('made_for_kids'))options.made_for_kids=value('made_for_kids')===''?null:value('made_for_kids')==='true';
        for(const key of ['commercial','allow_comments'])if(el(key))options[key]=el(key).checked;
        return {platform:section.dataset.platform,text:value('text'),scheduled_at:this.utc(value('scheduled_at')),media_ids:[...el('media_ids').selectedOptions].map(o=>o.value),options};
      })};
  },
  changed() {
    this.dirty=true;this.remember();document.getElementById('content-save-state').textContent='Nog niet opgeslagen';
    document.getElementById('content-approve')?.setAttribute('disabled','');document.getElementById('content-schedule')?.setAttribute('disabled','');
    this.preview();clearTimeout(this.timer);this.timer=setTimeout(()=>{if(document.getElementById('content-title')?.value.trim())this.save();},1200);
  },
  async save() {
    if(this.saving||!this.editor||!this.editable(this.editor))return false;
    clearTimeout(this.timer);let data;try{data=this.collect();}catch(e){document.getElementById('content-error').textContent=e.message;return false;}
    if(!data.title.trim()){document.getElementById('content-error').textContent='Vul een interne titel in.';return false;}
    const snapshot=JSON.stringify(data);this.saving=true;document.getElementById('content-save-state').textContent='Opslaan…';
    const response=await api(`/api/content/posts${this.editor.id?'/'+this.editor.id:''}`,{method:this.editor.id?'PUT':'POST',body:data,onError:message=>{document.getElementById('content-error').textContent=message;}});
    this.saving=false;
    if(!response){document.getElementById('content-save-state').textContent='Opslaan niet gelukt';return false;}
    let changed=true;try{changed=JSON.stringify(this.collect())!==snapshot;}catch(_){}
    this.editor=response;this.dirty=changed;document.getElementById('content-comment-send').disabled=false;
    document.getElementById('content-save-state').textContent=changed?'Nieuwe wijzigingen worden opgeslagen…':`Opgeslagen · versie ${response.revision}`;
    document.getElementById('content-error').textContent='';
    document.getElementById('content-editor-status').innerHTML=this.badge(response.review_status);this.preview();
    if(changed){this.remember();clearTimeout(this.timer);this.timer=setTimeout(()=>this.save(),500);}else localStorage.removeItem(this.draftKey());
    return !changed;
  },
  async saveIfDirty() {return !this.dirty&&!!this.editor.id?true:await this.save();},
  async review(action) {
    if(!await this.saveIfDirty())return;
    const response=await api(`/api/content/posts/${this.editor.id}/review`,{method:'POST',body:{revision:this.editor.revision,action}});
    if(response){this.dirty=false;await this.open(response.id);}
  },
  preview() {
    const selector=document.getElementById('content-preview-channel');
    const sections=[...document.querySelectorAll('#content-versions [data-platform]')];
    const selected=selector.value;selector.innerHTML=sections.map(el=>`<option value="${el.dataset.platform}" ${selected===el.dataset.platform?'selected':''}>${this.platforms[el.dataset.platform]}</option>`).join('');
    const first=sections.find(el=>el.dataset.platform===selector.value);const preview=document.getElementById('content-preview');
    if(!first){preview.innerHTML='<p class="content-muted">Kies een kanaal voor een voorbeeld.</p>';document.getElementById('content-checks').innerHTML='<li>Kies ten minste één kanaal.</li>';return;}
    const id=first.querySelector('[data-field="media_ids"]')?.selectedOptions[0]?.value;
    const media=this.media.find(m=>m.id===id);const text=first.querySelector('[data-field="text"]').value;
    preview.innerHTML=`<strong>BBQuality · ${this.platforms[first.dataset.platform]}</strong>${media?(media.mime.startsWith('video/')?`<video controls preload="metadata" src="/api/content/media/${media.id}"></video>`:`<img alt="Geselecteerd publicatiebeeld" src="/api/content/media/${media.id}">`):''}<p class="content-preview-text">${escHtml(text||'Je tekst verschijnt hier.')}</p>`;
    const count=document.querySelectorAll('#content-versions [data-platform]').length;
    document.getElementById('content-checks').innerHTML=`<li>${count} kanaalversie${count!==1?'s':''}</li><li>${this.dirty?'Wijzigingen nog opslaan':this.label(this.editor.review_status)}</li><li>Tekst, media en planning worden vóór overdracht gecontroleerd.</li>`;
  },
  deliveryResults() {
    const node=document.getElementById('content-delivery-results');if(!node)return;
    node.innerHTML=this.editor.deliveries.map(d=>`<p><strong>${this.platforms[d.platform]}</strong> ${this.badge(d.status)}${d.account_snapshot?`<br>Account: ${escHtml(d.account_snapshot.name)} (${escHtml(d.account_snapshot.id)})`:''}${d.platform==='youtube'&&d.options?.privacy?`<br>Zichtbaarheid: ${escHtml(({public:'Openbaar',unlisted:'Verborgen',private:'Privé'})[d.options.privacy]||d.options.privacy)}`:''}${d.error?`<br>${escHtml(d.error)}`:''}${d.published_url&&/^https:\/\//.test(d.published_url)?`<br><a target="_blank" rel="noopener noreferrer" href="${escHtml(d.published_url)}">Bekijk publicatie</a>`:''}</p>`).join('');
  },
  async library() {
    const ticket=this.generation;const response=await api('/api/content/media');if(!response||ticket!==this.generation||App.currentView!=='content')return;this.media=response.data;
    document.getElementById('content-body').innerHTML=`<section class="content-panel"><h3>Gedeelde media</h3><p>Upload bestanden vanuit een kanaalversie. Originele bestanden blijven behouden. ${response.total} bestanden.</p><div class="content-grid">${this.media.map(m=>`<a class="content-card" href="/api/content/media/${m.id}" target="_blank" rel="noopener">${m.mime.startsWith('image/')?`<img loading="lazy" alt="${escHtml(m.name)}" src="/api/content/media/${m.id}">`:'<i class="fas fa-film"></i>'}<strong>${escHtml(m.name)}</strong><small>${(m.size/1024/1024).toFixed(1)} MB</small></a>`).join('')||'<p>Nog geen media geüpload.</p>'}</div>${response.last_page>1?'<p>De nieuwste 100 bestanden worden getoond.</p>':''}</section>`;
  },
  async connection() {
    const node=document.getElementById('content-body'),ticket=this.generation;
    if(App.currentUser.rol!=='admin'){node.innerHTML='<section class="content-panel"><h3>Publicatiekanalen</h3><p>Een beheerder kan Instagram, Facebook en YouTube koppelen. TikTok blijft voorlopig alleen beschikbaar voor planning.</p></section>';return;}
    const data=await api('/api/content/connections');if(ticket!==this.generation||App.currentView!=='content')return;
    if(!data){node.textContent='Kanaalinstellingen konden niet worden geladen.';return;}
    if(data.storage_ready===false){
      node.innerHTML='<section class="content-panel"><h3>Contentopslag voorbereiden</h3><p>De aparte contenttabellen ontbreken of zijn onvolledig. Deze controle voegt alleen ontbrekende contentopslag toe als dat veilig kan; bestaande taken, projecten, kalender en notities blijven behouden.</p><button class="btn btn-primary" id="content-initialize">Contentopslag voorbereiden</button><p id="content-setup-error" class="error-msg" role="alert"></p></section>';
      document.getElementById('content-initialize').onclick=async e=>{if(!confirm('De aparte contentopslag veilig voorbereiden? Bestaande gegevens worden niet vervangen.'))return;e.target.disabled=true;const r=await api('/api/content/connections/initialize',{method:'POST',body:{confirmed:true},onError:m=>{document.getElementById('content-setup-error').textContent=m;}});if(r)this.connection();else e.target.disabled=false;};return;
    }
    node.innerHTML=`<div class="content-notice">${data.live_allowed?'Koppel de juiste bedrijfsaccounts en controleer de automatische uitvoerder voordat je publicatie activeert.':'Lokale omgeving: echte publicatie staat uit. Je kunt wel content voorbereiden.'}</div>
      <p class="content-muted">Databasecontrole: alle vijf contenttabellen en vereiste velden zijn beschikbaar. Deze controle publiceert niets.</p><div class="content-editor">${['meta','youtube'].map(provider=>{
        const c=data.connections.find(c=>c.provider===provider)||{};
        const redirect=c.redirect_uri||location.origin+'/api/content/connections/'+provider+'/callback';
        const seen=c.scheduler_seen_at&&Date.now()-new Date(c.scheduler_seen_at).getTime()<180000;
        return `<section class="content-panel" data-connection="${provider}"><h3>${provider==='meta'?'Instagram & Facebook':'YouTube'}</h3>
          <p>${c.enabled?'Publicatie actief':'Publicatie uit'} · ${c.verified_at?'Account eerder gecontroleerd':'Nog niet gekoppeld'}</p>
          <p class="content-muted">${provider==='meta'?'Maak een Meta-app met Facebook Login voor de Facebook-pagina en het gekoppelde professionele Instagram-account.':'Maak een Google OAuth-webapp en schakel YouTube Data API v3 in.'}</p>
          <label>Terugkeeradres voor de app<input readonly value="${escHtml(redirect)}"></label>
          <form data-credentials><label>${provider==='meta'?'App-ID':'Client-ID'}<input name="client_id" required autocomplete="off" value="${escHtml(c.client_id||'')}"></label>
          <label>Appgeheim<input name="client_secret" type="password" autocomplete="new-password" placeholder="${c.has_secret?'Bewaard; leeg laten om te behouden':'Vul hier veilig het appgeheim in'}"></label>
          ${provider==='meta'?`<label>API-versie uit de Meta-app<input name="api_version" required placeholder="Versie zoals vermeld in Meta" value="${escHtml(c.api_version||'')}"></label>`:''}
          <button class="btn btn-secondary">Appgegevens bewaren</button></form>
          <div class="content-actions"><button class="btn btn-secondary" data-login ${!c.client_id?'disabled':''}>Aanmelden bij ${provider==='meta'?'Meta':'Google'}</button><button class="btn btn-secondary" data-verify ${!c.verified_at?'disabled':''}>Account opnieuw controleren</button></div>
          <form data-activate><label>Publicatieaccount<select name="account_id" required><option value="">Kies het bedrijfsaccount</option>${(c.accounts||[]).map(a=>`<option value="${escHtml(a.id)}" ${a.id===c.selected_account?'selected':''}>${escHtml(a.name)}${a.instagram?' · @'+escHtml(a.instagram):''}</option>`).join('')}</select></label>
          <p class="content-muted">Automatische uitvoerder: ${seen?'recent actief':'niet recent aangetoond'}${c.scheduler_seen_at?' · laatste start '+escHtml(this.amsterdam(c.scheduler_seen_at).replace('T',' '))+' (Amsterdam)':''}. De uitvoerder moet op de server blijven draaien.</p>
          <label><input type="checkbox" name="enabled" ${c.enabled?'checked':''} ${!data.live_allowed?'disabled':''}> Automatisch publiceren activeren</label>
          <label><input type="checkbox" name="confirmed" required> Ik heb het gekozen bedrijfsaccount gecontroleerd.</label>
          <button class="btn btn-primary" ${!c.accounts?.length?'disabled':''}>Accountkeuze bewaren</button></form><p class="error-msg" data-error role="alert"></p></section>`;
      }).join('')}</div><section class="content-panel"><h3>TikTok — later</h3><p>TikTok-content kan al worden voorbereid, goedgekeurd en in de kalender gezet. Publiceren gebeurt voorlopig handmatig.</p></section>`;
    node.querySelectorAll('[data-connection]').forEach(section=>{
      const provider=section.dataset.connection;
      const send=async(path,method,body)=>api('/api/content/connections/'+provider+path,{method,body,onError:m=>{section.querySelector('[data-error]').textContent=m;}});
      section.querySelector('[data-credentials]').onsubmit=async e=>{e.preventDefault();const values=Object.fromEntries(new FormData(e.target));if(await send('','PUT',values))this.connection();};
      section.querySelector('[data-login]').onclick=async()=>{const result=await send('/start','POST');if(result)location.assign(result.url);};
      section.querySelector('[data-verify]').onclick=async()=>{if(await send('/verify','POST'))this.connection();};
      section.querySelector('[data-activate]').onsubmit=async e=>{e.preventDefault();const form=e.target;if(await send('/activate','PUT',{account_id:form.account_id.value,enabled:form.enabled.checked,confirmed:form.confirmed.checked}))this.connection();};
    });
  },
  async schedule() {
    if(!await this.saveIfDirty())return;
    const selected=this.editor.deliveries.filter(d=>d.platform!=='tiktok'&&['draft','cancelled'].includes(d.status));
    if(!selected.length){toast('Geen kanaal klaar voor automatische publicatie.','error');return;}
    const ticket=this.generation;
    const result=await api('/api/content/targets');if(!result||ticket!==this.generation)return;
    if(selected.some(d=>!result.targets[d.platform]?.enabled)){toast('Laat eerst de bedrijfsaccounts koppelen en activeren via Kanalen.','error');return;}
    const accounts=Object.fromEntries(selected.map(d=>[d.platform,result.targets[d.platform].id]));
    const description=selected.map(d=>`${this.platforms[d.platform]} → ${result.targets[d.platform].name} (${result.targets[d.platform].id}): ${d.scheduled_at?this.amsterdam(d.scheduled_at).replace('T',' '):'GEEN DATUM'}${d.platform==='youtube'?' · '+({public:'Openbaar',unlisted:'Verborgen',private:'Privé'})[d.options?.privacy]:''}`).join('\n');
    if(!confirm(`Versie ${this.editor.revision} van “${this.editor.title}” automatisch laten plaatsen?\n\n${description}\n\nTijden: Amsterdam. TikTok wordt niet automatisch geplaatst.`))return;
    const response=await api(`/api/content/posts/${this.editor.id}/schedule`,{method:'POST',body:{revision:this.editor.revision,delivery_ids:selected.map(d=>d.id),account_ids:accounts,confirmed:true},onError:m=>{document.getElementById('content-error').textContent=m;}});
    if(response){this.dirty=false;await this.open(response.id);}
  },
  async cancel() {
    if(!confirm('Alle nog niet gestarte publicaties van dit bericht annuleren? Overdrachten die al gestart zijn, blijven staan.'))return;
    const response=await api(`/api/content/posts/${this.editor.id}/cancel`,{method:'POST',body:{revision:this.editor.revision}});
    if(response)await this.open(response.id);
  },
  async refreshStatus() {
    if(!await this.saveIfDirty())return;
    const response=await api(`/api/content/posts/${this.editor.id}/refresh`,{method:'POST'});
    if(response){this.editor=response;this.deliveryResults();toast('Publicatiestatus gecontroleerd.','info');}
  }
};
function renderContent() { return Content.render(); }
window.addEventListener('beforeunload',e=>{if(Content.dirty||Content.saving){Content.remember();e.preventDefault();e.returnValue='';}});
