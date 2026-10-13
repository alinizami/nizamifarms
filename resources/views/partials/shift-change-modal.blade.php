{{-- Reusable "Change shift" popup. Include once per page, then call:
       openShiftChange({ userId, userName, onSaved })
     Self-contained (own CSS + IIFE JS, no page dependencies); loads its own shift
     types from /shifts/list and posts to /shifts/assign (mode-aware engine). --}}
<div id="shiftChangeModal" class="scm-bg" style="display:none;">
  <div class="scm-card">
    <div class="scm-head">
      <h2 id="scmTitle">Change shift</h2>
      <button type="button" id="scmX" class="scm-x">&times;</button>
    </div>
    <div class="scm-body">
      <div id="scmSummary" class="scm-summary"></div>

      {{-- 🔒 Shown instead of the form when the shift rules say this person is not yours to
           change. The summary above stays — seeing the shift is not the same as setting it. --}}
      <div id="scmLock" class="scm-lock" style="display:none;"></div>

      <div id="scmForm">
      <div class="scm-sep"><span>Set a shift</span></div>

      <label class="scm-label">Shift</label>
      <select id="scmTemplate" class="scm-input"></select>
      <div id="scmTplNote" style="display:none;font-size:11.5px;color:#64748b;margin-top:5px;"></div>

      <label class="scm-label">How long?</label>
      {{-- ⭐ Most-used first (owner ruling 6-Sep): this popup opens on "One day only". --}}
      <div class="scm-mode" data-mode="one_day">
        <div class="mt">One day only <span class="scm-dur scm-temp">TEMPORARY</span></div>
        <div class="md">Just the one date you pick — back to their normal shift <b>the next day</b>.</div>
      </div>
      <div class="scm-mode" data-mode="date_range">
        <div class="mt">A date range <span class="scm-dur scm-temp">TEMPORARY</span></div>
        <div class="md">Only between the two dates — back to their normal shift <b>after</b>.</div>
      </div>
      <div class="scm-mode" data-mode="until_changed">
        <div class="mt">New regular shift <span class="scm-dur scm-ongoing">REGULAR</span></div>
        <div class="md">Every day from the start date, <b>until you change it</b>. This is their new normal.</div>
      </div>

      <div class="scm-dates">
        <div><label id="scmFromLabel" class="scm-label">From</label><input type="date" id="scmFrom" class="scm-input"></div>
        <div id="scmToWrap"><label class="scm-label">To</label><input type="date" id="scmTo" class="scm-input"></div>
      </div>

      <div id="scmLocSection">
        <label class="scm-label">Location <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#94a3b8;">— where they check in</span></label>
        <div id="scmLocBubbles" class="scm-locs"></div>
        <label id="scmSetDefWrap" class="scm-setdef"><input type="checkbox" id="scmSetDef"> <span id="scmSetDefLbl">Make this their default location</span></label>
      </div>

      <p id="scmEffect" class="scm-effect"></p>
      </div>{{-- /#scmForm --}}
      <div class="scm-actions">
        <button type="button" id="scmCancel" class="scm-cancel">Cancel</button>
        <button type="button" id="scmSave" class="scm-save">Save</button>
      </div>
    </div>
  </div>
</div>

<style>
  #shiftChangeModal.scm-bg{ position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:100000; display:none; align-items:center; justify-content:center; padding:16px; }
  #shiftChangeModal .scm-card{ background:#fff; border-radius:14px; box-shadow:0 25px 60px rgba(15,23,42,.35); width:100%; max-width:440px; margin:auto; max-height:calc(100vh - 32px); overflow-y:auto; }
  #shiftChangeModal .scm-head{ display:flex; justify-content:space-between; align-items:center; padding:16px 18px; border-bottom:1px solid #e3e8f0; }
  #shiftChangeModal .scm-head h2{ font-size:17px; font-weight:600; margin:0; color:#0f172a; }
  #shiftChangeModal .scm-x{ background:none; border:0; font-size:24px; line-height:1; color:#94a3b8; cursor:pointer; }
  #shiftChangeModal .scm-body{ padding:18px; }
  #shiftChangeModal .scm-label{ display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin:14px 0 6px; }
  #shiftChangeModal .scm-body > .scm-label:first-child{ margin-top:0; }
  #shiftChangeModal .scm-input{ width:100%; padding:9px 11px; font-size:13px; border:1px solid #e3e8f0; border-radius:8px; background:#fff; color:#0f172a; }
  #shiftChangeModal .scm-mode{ border:1px solid #e3e8f0; border-radius:9px; padding:9px 11px; margin-bottom:7px; cursor:pointer; }
  #shiftChangeModal .scm-mode.on{ border-color:#B91C1C; background:#FBECEC; }
  #shiftChangeModal .scm-mode .mt{ font-weight:600; font-size:13px; color:#0f172a; }
  #shiftChangeModal .scm-mode .md{ font-size:11px; color:#64748b; margin-top:1px; }
  #shiftChangeModal .scm-dur{ display:inline-block; font-size:9.5px; font-weight:800; letter-spacing:.04em; border-radius:20px; padding:1px 7px; margin-left:6px; vertical-align:middle; }
  #shiftChangeModal .scm-ongoing{ background:#E6F3EB; color:#15803d; }
  #shiftChangeModal .scm-temp{ background:#FEF3C7; color:#92400e; }
  #shiftChangeModal .scm-dates{ display:flex; gap:12px; }
  #shiftChangeModal .scm-dates > div{ flex:1; }
  #shiftChangeModal .scm-effect{ font-size:12px; color:#37485f; background:#F1F5FB; border:1px solid #E1E8F2; border-radius:8px; padding:9px 11px; margin:14px 0 0; line-height:1.5; }
  #shiftChangeModal .scm-effect b{ color:#0f172a; }
  #shiftChangeModal .scm-actions{ display:flex; gap:10px; margin-top:16px; }
  #shiftChangeModal .scm-save{ flex:1; background:#B91C1C; color:#fff; border:0; border-radius:9px; padding:11px; font-size:14px; font-weight:600; cursor:pointer; }
  #shiftChangeModal .scm-save:hover{ background:#a11818; }
  #shiftChangeModal .scm-cancel{ padding:11px 16px; background:#fff; color:#475569; border:1px solid #e3e8f0; border-radius:9px; font-size:14px; font-weight:600; cursor:pointer; }
  /* current-shift + scheduled-changes summary */
  #shiftChangeModal .scm-summary{ background:#F8FAFC; border:1px solid #e8edf3; border-radius:10px; padding:11px 12px; }
  #shiftChangeModal .scm-now{ font-size:13px; color:#0f172a; }
  #shiftChangeModal .scm-now .scm-now-lbl{ display:inline-block; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#15803D; background:#E6F3EB; border-radius:20px; padding:1px 8px; margin-right:6px; }
  #shiftChangeModal .scm-now b{ color:#0f172a; }
  #shiftChangeModal .scm-changes{ display:flex; flex-wrap:wrap; gap:6px; margin-top:9px; }
  #shiftChangeModal .scm-chg{ display:inline-flex; align-items:center; gap:5px; font-size:11.5px; font-weight:600; background:#FEF9C3; color:#854d0e; border-radius:20px; padding:2px 5px 2px 9px; }
  #shiftChangeModal .scm-chg-x{ background:none; border:0; color:#a1691a; font-weight:700; font-size:14px; line-height:1; cursor:pointer; padding:0 2px; }
  #shiftChangeModal .scm-chg.acked{ background:#E6F3EB; color:#15803d; }
  #shiftChangeModal .scm-chg .scm-ack{ font-weight:700; margin-left:2px; }
  #shiftChangeModal .scm-chg.acked .scm-chg-x{ color:#15803d; }
  #shiftChangeModal .scm-nochg{ font-size:11.5px; color:#94a3b8; margin-top:7px; }
  #shiftChangeModal .scm-loading{ font-size:12px; color:#94a3b8; }
  /* 🔒 The refusal, in the place the form would have been. */
  #shiftChangeModal .scm-lock{ font-size:12.5px; color:#92400E; background:#FFFBEB; border:1px solid #FDE68A; border-radius:9px; padding:11px 13px; margin-top:14px; line-height:1.5; font-weight:600; }
  #shiftChangeModal .scm-sep{ display:flex; align-items:center; gap:10px; margin:16px 0 2px; color:#94a3b8; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; }
  #shiftChangeModal .scm-sep::before, #shiftChangeModal .scm-sep::after{ content:""; flex:1; height:1px; background:#e8edf3; }
  #shiftChangeModal .scm-locs{ display:flex; flex-wrap:wrap; gap:7px; }
  #shiftChangeModal .scm-loc{ font-size:12px; font-weight:600; border:1px solid #e3e8f0; background:#fff; color:#334155; border-radius:20px; padding:5px 11px; cursor:pointer; }
  #shiftChangeModal .scm-loc.on{ border-color:#B91C1C; background:#FBECEC; color:#B91C1C; }
  #shiftChangeModal .scm-loc .scm-loc-def{ font-size:9px; font-weight:800; text-transform:uppercase; color:#15803d; margin-left:5px; }
  #shiftChangeModal .scm-setdef{ display:flex; align-items:center; gap:8px; margin-top:9px; font-size:12px; color:#475569; cursor:pointer; }
</style>

<script>
(function(){
  if (window.openShiftChange) return; // include-once guard
  const $ = (id) => document.getElementById(id);
  const CSRF = document.querySelector('meta[name="csrf-token"]').content;
  let templates = [], target = null, mode = 'until_changed', onSaved = null;
  let locations = [], selLoc = null, defaultLocId = null; // location picker state
  /**
   * 🔒 SHIFT AUTHORITY (Sep-2026) — filled from /shifts/user-summary, which answers the
   * same gate the write endpoint uses:
   *   canChange     false ⇒ the whole form is replaced by the lock and the reason;
   *   needsApproval true  ⇒ Save becomes "Send for approval" and nothing lands yet;
   *   allowedIds    non-null ⇒ the picker shows only those shift types.
   * ⚠ Advisory only. The server refuses regardless of what this file believes.
   */
  let canChange = true, needsApproval = false, allowedIds = null, lockReason = null;
  /**
   * 🔁 His WEEKLY DAYS (Oct-2026), as one line from /shifts/user-summary. This popup cannot
   * edit them — the Shift Planner and the phone can — so it only SAYS what happens to them:
   * a new regular shift KEEPS them (the server carries them over when this form does not
   * send any), a date range PAUSES them for those dates. '' = he has none.
   */
  let weeklySummary = '';

  const fmt = (d) => { try { return new Date(d+'T00:00:00').toLocaleDateString(undefined,{day:'numeric',month:'short'}); } catch(e){ return d; } };
  const todayStr = () => { const d=new Date(); const p=(n)=>String(n).padStart(2,'0'); return d.getFullYear()+'-'+p(d.getMonth()+1)+'-'+p(d.getDate()); };

  /* ⚠ Re-fetched for EVERY rider, not cached across them: which types are on offer is a
     property of the PERSON (his allowed-shift list), not of the page. `pending` types are
     dropped — they cannot be assigned to anybody. */
  async function loadTemplates(userId){
    try {
      const url = '/shifts/list' + (userId ? ('?for_user_id=' + encodeURIComponent(userId)) : '');
      const j = await fetch(url).then(r=>r.json());
      if (j.success) templates = j.data.filter(t=>t.active && !t.pending);
    } catch(e){ console.error('shift templates load failed', e); }
  }

  function isNotReq(){ return $('scmTemplate').value === 'not_required'; }

  function setMode(m){
    mode = m;
    document.querySelectorAll('#shiftChangeModal .scm-mode').forEach(el => el.classList.toggle('on', el.dataset.mode===m));
    $('scmToWrap').style.display = (m==='date_range') ? 'block' : 'none';
    $('scmFromLabel').textContent = (m==='one_day') ? 'Day' : 'From';
    renderEffect();
  }

  // "Not required" is a per-day marker, not a recurring shift → hide "until changed" + location.
  function onTemplateChange(){
    const nr = isNotReq();
    const untilOpt = document.querySelector('#shiftChangeModal .scm-mode[data-mode="until_changed"]');
    if (untilOpt) untilOpt.style.display = nr ? 'none' : '';
    if ($('scmLocSection')) $('scmLocSection').style.display = (nr || !locations.length) ? 'none' : '';
    if (nr && mode==='until_changed') setMode('one_day');
    renderEffect();
  }

  function renderLocBubbles(){
    const wrap = $('scmLocBubbles');
    if (!wrap) return;
    if (!locations.length){ $('scmLocSection').style.display = 'none'; return; }
    if (!isNotReq()) $('scmLocSection').style.display = '';
    wrap.innerHTML = locations.map(l =>
      `<span class="scm-loc ${l.id===selLoc?'on':''}" data-loc="${l.id}">${l.name}${l.id===defaultLocId?'<span class="scm-loc-def">default</span>':''}</span>`
    ).join('');
    wrap.querySelectorAll('[data-loc]').forEach(b => b.addEventListener('click', () => { selLoc = parseInt(b.dataset.loc); renderLocBubbles(); }));
    const who = target && target.userName ? (target.userName.split(' ')[0] + "'s") : 'their';
    $('scmSetDefLbl').textContent = 'Make this ' + who + ' default location';
  }

  function renderEffect(){
    const from = $('scmFrom').value, to = $('scmTo').value;
    const who = target ? target.userName : 'this rider';
    if (isNotReq()){
      const range = (mode==='date_range') ? `${fmt(from)}–${fmt(to)}` : fmt(from);
      $('scmEffect').innerHTML = `→ ${who} marked <b>🚫 not needed</b> on <b>${range}</b> — paid, not counted absent. Reversible from the day cell or here.`;
      return;
    }
    const t = templates.find(x => String(x.id)===String($('scmTemplate').value));
    const nm = t ? t.shift_name : 'the shift';
    let msg;
    if (mode==='until_changed') msg = `→ ${who} will be on <b>${nm}</b> from <b>${fmt(from)}</b> until you change it. Past days are untouched.`;
    else if (mode==='one_day') msg = `→ ${who} on <b>${nm}</b> for <b>${fmt(from)}</b> only, then back to their normal shift automatically.`;
    else msg = `→ ${who} on <b>${nm}</b> for <b>${fmt(from)}–${fmt(to)}</b>, then back to their normal shift automatically.`;
    // 🔁 What happens to his weekly days — this form cannot change them.
    if (weeklySummary && mode==='until_changed') msg += `<br><span style="color:#1d4ed8;">🔁 His weekly days stay as they are (${escapeHtmlScm(weeklySummary)}). Change them in the Shift Planner.</span>`;
    else if (weeklySummary && mode==='date_range') msg += `<br><span style="color:#1d4ed8;">🔁 His weekly days are paused for these dates — every day is <b>${nm}</b>. To keep them, use the Shift Planner.</span>`;
    // ⏳ The sentence that stops a manager assuming it has landed.
    if (needsApproval) msg += `<br><span style="color:#9A3412;font-weight:600;">⏳ This waits for approval. ${who} is not told until it is approved.</span>`;
    $('scmEffect').innerHTML = msg;
  }

  function close(){ $('shiftChangeModal').style.display='none'; }

  /**
   * ⭐ Everything the rules change about this form, in one place.
   *  • Locked: the "Set a shift" half is hidden entirely and the reason is shown. The
   *    summary above it stays visible — a planner may still need to SEE the shift he is
   *    not allowed to change (owner ruling: show with a lock, never hide the person).
   *  • Needs approval: the button says what it does, and a line says the person is not told.
   *  • Restricted: only the allowed types are in the dropdown, with a note saying why.
   */
  function applyAuthority(){
    const form = $('scmForm'), lock = $('scmLock'), save = $('scmSave');
    if (form) form.style.display = canChange ? '' : 'none';
    if (lock){
      lock.style.display = canChange ? 'none' : 'block';
      lock.textContent = lockReason || "You can't change this person's shift.";
    }
    if (save){
      save.style.display = canChange ? '' : 'none';
      save.textContent = needsApproval ? 'Send for approval' : 'Save';
      save.style.background = needsApproval ? '#B45309' : '';
    }
    if (allowedIds) {
      const before = $('scmTemplate').value;
      fillTemplateOptions();
      if (before && [...$('scmTemplate').options].some(o=>o.value===before)) $('scmTemplate').value = before;
    }
    const note = $('scmTplNote');
    if (note){
      note.style.display = allowedIds ? 'block' : 'none';
      note.textContent = allowedIds
        ? ('Only these shifts are allowed for ' + ((target && target.userName) || 'this person') + '.')
        : '';
    }
    renderEffect();
  }

  function fillTemplateOptions(){
    const list = allowedIds ? templates.filter(t => allowedIds.indexOf(t.id) >= 0) : templates;
    $('scmTemplate').innerHTML = list.map(t => `<option value="${t.id}">${t.shift_name} · ${t.shift_start}${t.shift_end?'–'+t.shift_end:'+'} · off ${t.off_days}</option>`).join('')
      + `<option value="not_required">🚫 Not required (day off, paid — not a shift)</option>`;
  }

  function escapeHtmlScm(s){ return String(s==null?'':s).replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }

  async function loadSummary(userId){
    weeklySummary = '';   // never show the previous person's weekly days while this loads
    $('scmSummary').innerHTML = '<div class="scm-loading">Loading current shift…</div>';
    try {
      const d = await fetch('/shifts/user-summary?user_id=' + encodeURIComponent(userId)).then(r=>r.json());
      if (d.success) renderSummary(d); else $('scmSummary').innerHTML = '';
    } catch(e){ $('scmSummary').innerHTML = ''; }
  }
  function renderSummary(d){
    // 🔒 The gate's answer, applied to this form.
    canChange = d.can_change !== false;
    needsApproval = !!d.needs_approval;
    allowedIds = d.allowed_template_ids || null;
    lockReason = d.lock_reason || null;
    applyAuthority();

    // Locations come with the summary (one fetch) → render the picker.
    if (Array.isArray(d.locations)) {
      locations = d.locations;
      defaultLocId = d.default_location_id || null;
      const primaryLoc = locations.find(l => l.is_primary);
      if (selLoc === null) selLoc = d.default_location_id || d.usual_location_id || (primaryLoc ? primaryLoc.id : (locations[0] ? locations[0].id : null));
      renderLocBubbles();
      onTemplateChange();
    }
    const p = d.primary || {};
    weeklySummary = d.weekly_summary || '';
    let html = `<div class="scm-now"><span class="scm-now-lbl">Now</span> <b>${p.shift_name||'—'}</b> · ${p.start||''}${p.end?'–'+p.end:' onwards'}</div>`;
    if (weeklySummary) html += `<div class="scm-nochg" style="color:#1d4ed8;">🔁 Weekly days: ${escapeHtmlScm(weeklySummary)}</div>`;
    renderEffect();
    if (d.changes && d.changes.length){
      html += '<div class="scm-changes">' + d.changes.map(c=>{
        const lbl = c.kind==='temporary'
          ? `${c.shift_name||'—'} · ${fmt(c.from)}${c.to&&c.to!==c.from?'–'+fmt(c.to):''}`
          : `${c.shift_name||'—'} · from ${fmt(c.from)}`;
        const ack = c.acknowledged ? '<span class="scm-ack">✓ confirmed</span>' : '<span class="scm-ack">⏳ awaiting</span>';
        return `<span class="scm-chg ${c.acknowledged?'acked':''}">${c.kind==='temporary'?'⏱':'⏭'} ${lbl} ${ack} <button type="button" class="scm-chg-x" data-cancel="${c.assignment_id}" title="Cancel this change">×</button></span>`;
      }).join('') + '</div>';
    } else {
      html += '<div class="scm-nochg">No upcoming changes — just their normal shift.</div>';
    }
    $('scmSummary').innerHTML = html;
    $('scmSummary').querySelectorAll('[data-cancel]').forEach(b => b.addEventListener('click', () => cancelChange(parseInt(b.dataset.cancel))));
  }
  async function cancelChange(id){
    if (!confirm('Cancel this temporary change? Their normal shift resumes.')) return;
    let j;
    try { j = await fetch('/shifts/cancel-change', { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF}, body:JSON.stringify({ assignment_id:id }) }).then(r=>r.json()); }
    catch(e){ j = { success:false, message:'Network error' }; }
    if (j.success){ loadSummary(target.userId); if (typeof onSaved==='function') onSaved(); }
    else alert(j.message || 'Failed to cancel');
  }

  async function save(){
    const from = $('scmFrom').value, to = $('scmTo').value;
    if (!from){ alert('Pick a date.'); return; }
    if (mode==='date_range' && (!to || to<from)){ alert('Pick a valid end date.'); return; }
    const btn = $('scmSave'); btn.disabled=true; btn.textContent='Saving…';

    // 🚫 Not required — writes not-needed day tags over the date(s); no shift assignment.
    if (isNotReq()){
      const payload = { user_ids: [target.userId], from, action: 'add' };
      if (mode==='date_range') payload.to = to;
      let j;
      try { j = await fetch('/attendance/day-tag-range', { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF}, body:JSON.stringify(payload) }).then(r=>r.json()); }
      catch(e){ j = { success:false, message:'Network error' }; }
      btn.disabled=false;
      if (j.success){ if (typeof onSaved==='function') onSaved(); btn.textContent='Saved ✓'; setTimeout(()=>{btn.textContent='Save';},1300); }
      else { btn.textContent='Save'; alert(j.message || 'Failed to save'); }
      return;
    }

    const templateId = parseInt($('scmTemplate').value);
    if (!templateId){ alert('Pick a shift and a date.'); btn.disabled=false; btn.textContent='Save'; return; }
    /* ⚠ The "one day only, dated today" confirm was removed on 6-Sep when one-day became
       the DEFAULT. As the default it would interrupt nearly every save; the effect line
       states it permanently instead. */
    const payload = { user_id: target.userId, shift_template_id: templateId, mode, effective_from: from };
    if (mode==='date_range') payload.effective_to = to;
    if (mode==='one_day') payload.effective_to = from;
    if (selLoc){ payload.location_id = selLoc; payload.set_default_location = $('scmSetDef') ? $('scmSetDef').checked : false; }
    const send = async (body) => {
      try { return await fetch('/shifts/assign', { method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':CSRF}, body:JSON.stringify(body) }).then(r=>r.json()); }
      catch(e){ return { success:false, message:'Network error' }; }
    };
    let json = await send(payload);
    /**
     * ⏳ NOT AN ERROR — A QUESTION (owner ruling 7-Sep). This would overwrite a shift change
     * the rider has already been told about, or confirmed. Ask, then send it again with the
     * confirmation. ⚠ Asked ONCE — the retry carries the flag, so it cannot loop.
     */
    if (json && json.needs_confirmation) {
      if (!confirm(json.message + '\n\nChange it?')) {
        btn.disabled = false; btn.textContent = needsApproval ? 'Send for approval' : 'Save';
        return;
      }
      json = await send(Object.assign({}, payload, { confirm_replace: 1 }));
    }
    btn.disabled=false;
    const restore = () => { btn.textContent = needsApproval ? 'Send for approval' : 'Save'; };
    if (json.success){
      // Keep the popup open and refresh the summary so the manager SEES the change land
      // (new primary updates "Now"; a temporary change appears in the list). Table refreshes too.
      loadSummary(target.userId);
      if (typeof onSaved==='function') onSaved();
      /* ⚠ A queued change did NOT land — never show "Saved ✓" for one. */
      if (json.pending){
        btn.textContent = 'Sent ⏳';
        alert(json.message || 'Sent for approval.');
        if (window.refreshShiftApprovals) window.refreshShiftApprovals();
      } else {
        btn.textContent = 'Saved ✓';
      }
      setTimeout(restore, 1500);
    } else {
      restore();
      alert(json.message || 'Failed to save');
    }
  }

  window.openShiftChange = async function(opts){
    target = opts || {}; onSaved = (opts && opts.onSaved) || null;
    // ⚠ Reset the gate's answer to the SAFE side before the fetch lands. Carrying the last
    //   rider's "you may" into this one would flash an editable form for a locked person.
    canChange = true; needsApproval = false; allowedIds = null; lockReason = null;
    await loadTemplates(target.userId);
    $('scmTitle').textContent = 'Change shift · ' + (target.userName || '');
    // Reset location state for the new rider; the summary fetch re-populates it.
    locations = []; selLoc = null; defaultLocId = null;
    if ($('scmSetDef')) $('scmSetDef').checked = false;
    if ($('scmLocSection')) $('scmLocSection').style.display = 'none';
    fillTemplateOptions();
    const today = todayStr();
    $('scmFrom').value = today; $('scmTo').value = today;
    /**
     * ⭐ DEFAULT IS "ONE DAY ONLY" (owner ruling, 6-Sep-2026) — a one-day cover is the most
     *   frequent change, so it is what opens. A lasting change is now the deliberate choice.
     * ⚠ The "did you really mean today only?" confirm went with it; as the default it would
     *   have fired on nearly every save, and the effect line already says the same thing.
     */
    setMode('one_day');
    onTemplateChange();
    $('shiftChangeModal').style.display='flex';
    loadSummary(target.userId);
  };

  // wiring
  $('scmX').addEventListener('click', close);
  $('scmCancel').addEventListener('click', close);
  $('scmSave').addEventListener('click', save);
  $('scmTemplate').addEventListener('change', onTemplateChange);
  $('scmFrom').addEventListener('change', renderEffect);
  $('scmTo').addEventListener('change', renderEffect);
  $('shiftChangeModal').addEventListener('click', function(e){ if (e.target === this) close(); });
  document.querySelectorAll('#shiftChangeModal .scm-mode').forEach(el => el.addEventListener('click', () => setMode(el.dataset.mode)));
})();
</script>
