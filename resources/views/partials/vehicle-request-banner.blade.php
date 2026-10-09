{{--
  🔁 VEHICLE HANDOVER REQUESTS — the manager's banner (Sep-2026).

  ONE partial, included by every page a manager might be standing on when a rider
  asks for a machine: the Orders board AND the Bikes screen. It was originally
  inlined in orders/index.blade.php only, and the first live test found the hole —
  the owner raised a request from the rider app, went to the Bikes page to approve
  it, and saw nothing at all. A vehicle request belongs on the vehicle screen.

  ⚠ INCLUDE IT, NEVER COPY IT. Two hand-maintained copies of an approval control
  is exactly how one surface starts approving something the other cannot see.
  The IIFE guards against a double include, so a page may safely include it twice.

  Renders nothing for anyone without `assign_vehicles`: the server answers that
  poll with an empty list, so there is no client-side permission logic to drift.
--}}
<div id="nfVehicleReqBanners" class="px-4 lg:px-6 pt-2 min-w-0" style="display:none;"></div>

<script>
// ─────────────────────────────────────────────────────────────────────────────
// 🔁 VEHICLE HANDOVER REQUESTS (Sep-2026) — the manager's side of Rajab's morning.
//
// He asks for the van from his phone; nothing moves. This strip is where Shabib or
// Taimur says yes, and approving here runs the SAME VehicleService::assign() the
// fleet screen runs — there is no second handover engine.
//
// ⚠ SAME CONTRACT AS THE PIN STRIP ABOVE, deliberately: the banner is never state
//   we hold. Every 30s the open set is re-derived by the server and repainted, so a
//   request approved in store mode, or one that simply timed out, disappears from
//   here without this page being told anything.
//
// ⚠ The RETURN card carries a picker, because the owner asked that the management
//   team be able to change what the rider gets back. Its default is what the server
//   resolved FRESH (his own bike), not the snapshot taken when he asked.
// ─────────────────────────────────────────────────────────────────────────────
(function(){
  var box = document.getElementById('nfVehicleReqBanners');
  if(!box) return;
  if(box.dataset.nfWired) return;   // included twice on one page — wire once
  box.dataset.nfWired = '1';
  var timer = null;
  var busy = false;     // a decision is in flight — a poll must not repaint under it

  function csrfToken(){
    var m = document.querySelector('meta[name="csrf-token"]');
    return m ? (m.getAttribute('content') || '') : '';
  }
  function esc(s){
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
  }
  function shortTime(s){
    if(!s) return '';
    var m = String(s).replace('T',' ').match(/^(\d{4})-(\d{2})-(\d{2})[ ](\d{2}):(\d{2})/);
    if(!m) return '';
    var d = new Date(+m[1], +m[2]-1, +m[3], +m[4], +m[5]);
    if(isNaN(d.getTime())) return '';
    return d.toLocaleTimeString([], {hour:'numeric', minute:'2-digit'});
  }
  function num(n){ return (n == null) ? '' : String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ','); }

  function card(r){
    var at = shortTime(r.requested_at);
    var isReturn = (r.direction === 'return');
    var line;

    if(isReturn){
      line = '<strong>' + esc(r.rider_name) + '</strong> wants to hand back <strong>'
           + esc(r.vehicle_name) + '</strong>';
    } else {
      line = '<strong>' + esc(r.rider_name) + '</strong> is asking for <strong>'
           + esc(r.vehicle_name) + '</strong>'
           + (r.current_keeper_name ? ' — currently with ' + esc(r.current_keeper_name) : '');
      // What approving does to the man losing it — said BEFORE the tap, because
      // this banner (unlike the fleet modal) never asks the displaced question.
      if(r.current_keeper_name && !r.already_satisfied){
        line += '<br><span style="opacity:.85;">' + esc(r.current_keeper_name) + ' gets back: '
             + (r.keeper_gets_back ? '<strong>' + esc(r.keeper_gets_back) + '</strong>'
                                   : '<strong>nothing</strong> (no own bike free)') + '</span>';
      }
    }
    if(r.meter_claimed != null){ line += ' · meter <strong>' + esc(num(r.meter_claimed)) + '</strong>'; }
    if(at){ line += ' · ' + esc(at); }
    // ⭐ 7-Oct — a company → company SWAP: approving also takes his current company machine
    //   back, at the reading he typed. Said on the card so it is read before the tap.
    if(!isReturn && r.swap_from_name){
      line += '<br><span style="opacity:.85;">He hands back: <strong>' + esc(r.swap_from_name) + '</strong>'
           + (r.swap_from_meter != null ? ' · meter <strong>' + esc(num(r.swap_from_meter)) + '</strong>' : '')
           + '</span>';
      if(r.swap_meter_hint){
        line += '<br><span style="color:#b45309;">⚠ ' + esc(r.swap_meter_hint) + '</span>';
      }
    }
    if(r.note){ line += '<br><span style="opacity:.85;">“' + esc(r.note) + '”</span>'; }
    if(r.meter_hint){
      line += '<br><span style="color:#b45309;">⚠ ' + esc(r.meter_hint) + '</span>';
    }
    if(r.already_satisfied){
      line += '<br><span style="opacity:.85;">He already holds it — approving just records the request.</span>';
    }

    // What he gets back, and the manager's chance to change it.
    var picker = '';
    if(isReturn){
      var sug = r.give_back_suggested || null;
      // ⚠ The FRESH suggestion is the default, never the raise-time snapshot — it is
      //   what decide() executes when no override is posted, so the closed select and
      //   the outcome can never disagree (Sep-01 review finding).
      var chosen = sug ? sug.id : 'none';
      picker = '<div style="margin-top:6px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">'
             + '<span style="opacity:.85;">He gets back:</span>'
             + '<select data-giveback="' + r.id + '" '
             + 'style="border:1px solid #cbd5e1;border-radius:6px;padding:2px 6px;font-size:11.5px;max-width:220px;">';
      if(sug){
        picker += '<option value="' + sug.id + '"' + (String(chosen) === String(sug.id) ? ' selected' : '') + '>'
               +  esc(sug.name) + ' (his own)</option>';
      }
      var spares = r.give_back_spares || [];
      for(var s=0; s<spares.length; s++){
        if(sug && String(spares[s].id) === String(sug.id)) continue;
        picker += '<option value="' + spares[s].id + '"' + (String(chosen) === String(spares[s].id) ? ' selected' : '')
               +  '>' + esc(spares[s].name) + '</option>';
      }
      picker += '<option value="none"' + (chosen === 'none' ? ' selected' : '') + '>Nothing for now</option>'
             +  '</select></div>';
    }

    var photo = r.photo_url
      ? '<a href="' + esc(r.photo_url) + '" target="_blank" rel="noopener" '
        + 'style="color:#1d4ed8;text-decoration:underline;font-size:11.5px;">meter photo</a>'
      : '';

    return '<div style="display:flex;align-items:flex-start;gap:10px;padding:8px 12px;margin-bottom:6px;'
         + 'background:#eef2ff;border:1px solid #c7d2fe;border-left:3px solid #6366f1;border-radius:8px;'
         + 'font-size:12.5px;color:#312e81;">'
         + '<span style="font-size:14px;line-height:1.3;">🔁</span>'
         + '<span style="min-width:0;">' + line + picker + '</span>'
         + '<span style="margin-left:auto;display:flex;align-items:center;gap:6px;white-space:nowrap;">'
         + photo
         + '<button type="button" data-approve="' + r.id + '" '
         + 'style="background:#4f46e5;border:1px solid #4338ca;color:#fff;padding:3px 12px;border-radius:6px;'
         + 'font-size:11.5px;font-weight:700;cursor:pointer;">Approve</button>'
         + '<button type="button" data-reject="' + r.id + '" title="Reject this request" '
         + 'style="background:none;border:none;color:#4338ca;font-size:14px;line-height:1;cursor:pointer;padding:0 2px;">✕</button>'
         + '</span></div>';
  }

  function render(list){
    if(!list || !list.length){ box.style.display = 'none'; box.innerHTML = ''; return; }
    // ⚠ The 30s repaint must not eat a choice the manager already made in a
    //   give-back select (Sep-01 review finding: change it, get interrupted 30s,
    //   click Approve — and the machine posted is the one he rejected). Capture
    //   every select's value before the innerHTML swap and restore it after,
    //   when the same option still exists.
    var kept = {};
    box.querySelectorAll('select[data-giveback]').forEach(function(s){
      kept[s.getAttribute('data-giveback')] = s.value;
    });
    var html = '';
    for(var i=0; i<list.length; i++){ html += card(list[i]); }
    box.innerHTML = html;
    box.querySelectorAll('select[data-giveback]').forEach(function(s){
      var v = kept[s.getAttribute('data-giveback')];
      if(v !== undefined && s.querySelector('option[value="' + v + '"]')){ s.value = v; }
    });
    box.style.display = 'block';
  }

  function poll(){
    if(document.hidden || busy) return;
    // Mid-interaction is not the moment to repaint under the manager's cursor —
    // an open select would snap shut. The next tick catches up.
    if(box.contains(document.activeElement)) return;
    fetch('{{ route('orders.vehicle-requests') }}', {
      headers: {'X-Requested-With':'XMLHttpRequest','Accept':'application/json'},
      credentials: 'same-origin'
    })
      .then(function(r){ return r.json(); })
      // can_approve false ⇒ the server sends an empty list, so this renders nothing
      // for anyone without the right. No client-side permission logic to drift.
      .then(function(d){ if(d && d.success) render(d.requests || []); })
      .catch(function(){});
  }

  /**
   * ⭐ A DECISION CHANGES THE PAGE UNDER THE BANNER, so the page has to catch up.
   *
   * The machine has just moved: its keeper line, the ⏳ chip on its card, the
   * roster, the rider's "no machine" panel — all stale the instant Approve
   * returns. Leaving the manager to press F5 is how someone approves the same
   * request twice, or walks away believing it did not work.
   *
   * ⚠ REFRESH IN PLACE, NEVER `location.reload()`. This strip also lives on the
   *   Orders board, and a full reload there would throw away the tab, filters and
   *   scroll position of a screen that has nothing to do with vehicles. So: if the
   *   Bikes screen is on this page, re-read exactly what it is showing — the grid,
   *   and the open vehicle profile if one is open — and otherwise do nothing.
   *
   * ⚠ `flvOpen()` resets the detail view to 'money' on every open, so a manager
   *   who was reading the Days tab would be silently bounced back. Capture the
   *   mode first and put it back.
   */
  function afterDecision(){
    try {
      if(typeof flvLoad !== 'function') return;   // not the Bikes screen — nothing to do
      var openId = (typeof flvOpenId !== 'undefined') ? flvOpenId : null;
      var mode   = (typeof flvDetailMode !== 'undefined') ? flvDetailMode : null;

      // The day cache is keyed by vehicle+month and is now wrong for this machine.
      // ⚠ It is a `const` object — clear IN PLACE; reassigning it throws.
      if(typeof flvDaysCache === 'object' && flvDaysCache){
        Object.keys(flvDaysCache).forEach(function(k){ delete flvDaysCache[k]; });
      }

      flvLoad();
      if(openId && typeof flvOpen === 'function'){
        flvOpen(openId);
        if(mode && mode !== 'money' && typeof flvSetDetailMode === 'function'){
          setTimeout(function(){ try { flvSetDetailMode(mode); } catch(e){} }, 400);
        }
      }
    } catch(e){ /* a refresh must never break the decision that already succeeded */ }
  }

  function act(url, body, btn, failMsg){
    busy = true;
    if(btn){ btn.disabled = true; btn.style.opacity = '0.6'; }
    return fetch(url, {
      method: 'POST',
      headers: {'Content-Type':'application/json','X-CSRF-TOKEN':csrfToken(),'Accept':'application/json'},
      credentials: 'same-origin',
      body: JSON.stringify(body || {})
    })
      .then(function(r){ return r.json(); })
      .then(function(d){
        busy = false;
        /**
         * ⭐ 7-Oct — A MISSING READING IS A QUESTION, NOT A DEAD END. The server names the one
         *   box it needs (`meter` = the machine he is asking about, `close_meter` = the company
         *   machine he steps off). Ask for that number and send the SAME decision again — the
         *   request was put back to pending, so nothing has moved yet.
         */
        if(d && !d.success && (d.meter_missing === 'meter' || d.meter_missing === 'close_meter')){
          var typed = prompt((d.message || 'A meter reading is needed.') + '\n\nType the reading to approve:');
          if(typed !== null){
            var n = parseInt(String(typed).replace(/[^0-9]/g, ''), 10);
            if(n > 0){
              var again = {};
              for(var k in (body || {})){ if(Object.prototype.hasOwnProperty.call(body, k)) again[k] = body[k]; }
              again[d.meter_missing] = n;
              return act(url, again, btn, failMsg);
            }
          }
          if(btn){ btn.disabled = false; btn.style.opacity = '1'; }
          poll();
          return;
        }
        if(!d || !d.success){ alert(failMsg + (d && d.message ? ': ' + d.message : '')); }
        else if(d.message){ /* the outcome sentence is worth seeing — it says what moved */
          if(/could not|somebody else|no machine/i.test(d.message)) alert(d.message);
        }
        poll();     // repaint from the server, never from an assumption
        afterDecision();
      })
      .catch(function(){
        busy = false;
        if(btn){ btn.disabled = false; btn.style.opacity = '1'; }
        alert(failMsg + ' — please try again.');
      });
  }

  box.addEventListener('click', function(ev){
    var el = ev.target.closest('button[data-approve], button[data-reject]');
    if(!el) return;

    var okId = el.getAttribute('data-approve');
    if(okId){
      var body = {};
      var sel = box.querySelector('select[data-giveback="' + okId + '"]');
      if(sel){
        if(sel.value === 'none'){ body.give_back_none = true; }
        else if(sel.value){ body.give_back_vehicle_id = parseInt(sel.value, 10); }
      }
      act('/orders/vehicle-requests/' + okId + '/approve', body, el, 'Could not approve');
      return;
    }
    var noId = el.getAttribute('data-reject');
    if(noId){
      var why = prompt('Reject this handover request?\nA short reason (optional) — the rider is told.');
      if(why === null) return;        // cancelled the prompt = changed his mind
      act('/orders/vehicle-requests/' + noId + '/reject', {note: why || null}, el, 'Could not reject');
    }
  });

  function start(){ poll(); if(timer) clearInterval(timer); timer = setInterval(poll, 30000); }
  if(document.readyState === 'loading'){ document.addEventListener('DOMContentLoaded', start); }
  else { start(); }
  document.addEventListener('visibilitychange', function(){ if(!document.hidden) poll(); });
})();
</script>
