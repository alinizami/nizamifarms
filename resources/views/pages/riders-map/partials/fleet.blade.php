{{--
    Bikes (Jul-2026) — what each rider's bike costs per month.

    Self-contained like Day Review: own markup, .fl-* styles, fl* state.
    Reads /orders/riders-map/fleet[/rider]. Gated server-side; the tab button
    is only rendered for users who pass the same gate.

    The screen's job is a decision, not a report: company bikes vs own bikes,
    cost per PRODUCTIVE kilometre, with and without maintenance.
--}}

<div id="fleetView" style="display: none;">

    <div class="fl-bar">
        {{-- Riders = what each rider's bike COSTS (the original screen, untouched).
             Vehicles = the machines themselves and who has them (Aug-2026).
             Two questions about the same fleet, so they live in one tab rather
             than a new sidebar entry. --}}
        <div class="fl-modes">
            <button type="button" id="flModeRiders" class="fl-mode on" onclick="flSetMode('riders')">👤 Riders</button>
            <button type="button" id="flModeVehicles" class="fl-mode" onclick="flSetMode('vehicles')">🏍️ Vehicles</button>
            {{-- 🛠 Sep-15: the THIRD question about the same fleet — "is anything stuck, and
                 whose turn is it". Riders answers what a man's riding costs, Vehicles what a
                 machine has done; neither could answer whether a complaint was being handled. --}}
            <button type="button" id="flModeIssues" class="fl-mode" onclick="flSetMode('issues')">🛠 Issues<span id="flIssBadge" style="display:none;background:#dc2626;color:#fff;border-radius:999px;font-size:10px;font-weight:800;padding:0 5px;margin-left:5px;">0</span></button>
        </div>
        <div class="fl-monthwrap" id="flMonthWrap">
            <button class="fl-nav" onclick="flShiftMonth(-1)" title="Previous month">‹</button>
            <input type="month" id="flMonthInput" onchange="flLoad(this.value)">
            <button class="fl-nav" onclick="flShiftMonth(1)" title="Next month">›</button>
        </div>
        <div id="flHeadline" class="fl-headline"></div>
        {{-- ⛽/🔧 File a bike expense WITHOUT leaving Bikes. Opens a small modal
             here rather than navigating to the full request form — that form has
             a company-wide employee dropdown (long, and the names render cut off)
             and it takes the manager away from the numbers he is looking at.
             The rider list here is the Bikes roster, already loaded.
             Posts to the SAME endpoint, so the same FuelClaimRules apply. --}}
        <div class="fl-newreq">
            <button type="button" class="fl-newreqbtn" onclick="flOpenNew('Petrol')" title="File a petrol claim for a rider">⛽ New petrol</button>
            <button type="button" class="fl-newreqbtn" onclick="flOpenNew('Maintenance')" title="File a maintenance claim for a rider">🔧 New maintenance</button>
            {{-- Shown only to whoever may change service schedules (manage_bike_service).
                 Hidden entirely for everyone else rather than shown-and-refused. --}}
            <button type="button" class="fl-newreqbtn" id="flTypesBtn" style="display:none;"
                    onclick="flOpenTypes()" title="Add or edit the maintenance categories">⚙️ Types</button>
        </div>
    </div>

    <div id="flVerdict" class="fl-verdict" style="display:none;"></div>
    <div id="flNotes" class="fl-notes" style="display:none;"></div>

    <div class="fl-tablewrap">
        <table class="fl-table" id="flTable">
            <thead>
                <tr>
                    <th>Rider</th>
                    <th>Bike</th>
                    <th class="num">Work km</th>
                    <th class="num" title="Meter-out at home → next morning's meter-in, on a night he had the bike at both ends. Real commuting only.">Off-duty</th>
                    {{-- ⭐ Aug-2026: kilometres that belong to NOBODY. A handover day's
                         run is real but unsplittable, and the bike travelling between
                         two riders is nobody's personal usage. Before this column they
                         were dumped on whoever happened to hold the bike. --}}
                    <th class="num" title="Kilometres on a day the bike changed hands (real, but impossible to split between the two riders) and the bike's own travel between them. Counted against neither man.">Shared / transit</th>
                    {{-- "Costed km" used to show WORK km, while the firm was demonstrably
                         paying for the commute too. Fuelled km = every kilometre whose
                         petrol this company bought. --}}
                    <th class="num" title="Every km we bought the fuel for: work + commute on a company bike, shift km on an own bike">Fuelled km</th>
                    <th class="num">Fuel</th>
                    {{-- ONE rate per row (owner: avoid clutter): fuel ÷ every km we
                         fuelled — what a kilometre on this machine costs to run. The
                         company-vs-own COMPARISON lives in the banner above and is
                         computed on productive km, which is stated there. --}}
                    <th class="num" title="Fuel ÷ every km we fuelled (work + commute on a company bike). What a kilometre on this machine costs to run. The company-vs-own comparison in the banner uses productive km instead — see the note there.">Rs/km <span style="font-weight:400;font-size:9.5px;">ridden</span></th>
                    <th class="num">Maint.</th>
                    <th class="num" title="(Fuel + maintenance) ÷ the same kilometres as the Rs/km beside it">Rs/km all-in</th>
                    <th>Service</th>
                    <th></th>
                </tr>
            </thead>
            <tbody id="flBody">
                <tr><td colspan="12" class="fl-empty">Loading…</td></tr>
            </tbody>
        </table>
    </div>

    <div id="flDetail" class="fl-detail" style="display:none;"></div>

    {{-- ═══ VEHICLES (Aug-2026) ═══
         The machines as things in their own right: what they are, who has them,
         what condition they were handed over in. Hidden until the toggle is used,
         so the costs screen opens exactly as it always did. --}}
    <div id="flVehWrap" style="display:none;">
        <div id="flVehIntro" class="fl-vintro"></div>

        {{-- 🔁 Riders waiting for a machine. THE SAME partial the Orders board
             includes — a vehicle request has to be answerable on the vehicle
             screen, which is where a manager goes when a rider asks for a bike.
             (First live test: the request was raised on the phone, the owner came
             here to approve it, and there was nothing to approve.) --}}
        @include("partials.vehicle-request-banner")
        <div id="flVehGrid" class="fl-vgrid"><div class="fl-empty">Loading…</div></div>
        <div id="flVehDetail" class="fl-vdetail" style="display:none;"></div>
    </div>

    {{-- ═══ 🛠 ISSUES (Sep-15 2026) ═══
         The third question about the same fleet: is anything stuck, and whose turn is it.
         ⚠ The SAME partial the standalone planners' page includes — one board, two doors.
         ⚠ The flag below is what tells it that flOpenTicket() exists here, so a ticket row
           expands its thread in place instead of linking away. The standalone page sets
           nothing and gets links. --}}
    <script>window.FL_ISS_INLINE_THREADS = true;</script>
    @include('pages.riders-map.partials.fleet-issues')
</div>

<style>
/* ---------- Bikes (scoped .fl-*) ---------- */
.fl-bar{display:flex;align-items:center;gap:18px;flex-wrap:wrap;padding:12px 16px;background:#fff;border-bottom:1px solid #e5e7eb;}
.fl-monthwrap{display:flex;align-items:center;gap:6px;}
.fl-monthwrap input[type=month]{padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;}
.fl-nav{border:1px solid #d1d5db;background:#fff;border-radius:6px;width:28px;height:30px;cursor:pointer;font-size:16px;line-height:1;color:#374151;}
.fl-nav:hover{background:#f3f4f6;}
.fl-headline{font-size:12.5px;color:#6b7280;}
.fl-headline b{color:#111827;}

.fl-newreq{display:flex;gap:8px;margin-left:auto;}
.fl-newreqbtn{display:inline-block;padding:6px 12px;border:1px solid #fcd34d;background:#fff;color:#92400e;border-radius:7px;font-size:12.5px;font-weight:700;text-decoration:none;white-space:nowrap;}
.fl-newreqbtn:hover{background:#fffbeb;}
.fl-verdict{margin:12px 16px 0;padding:11px 14px;border-radius:8px;background:#f0fdf4;border:1px solid #86efac;font-size:13px;color:#14532d;line-height:1.5;}
.fl-verdict.tie{background:#fffbeb;border-color:#fcd34d;color:#78350f;}
.fl-verdict b{font-weight:700;}
/* Compact comparison instead of a paragraph: two rows, three numbers each, read
   left to right. A manager wants the figures, not the essay. */
.fl-cmp{width:auto;border-collapse:collapse;font-size:13px;}
.fl-cmp th{font-size:9.5px;font-weight:600;text-transform:uppercase;letter-spacing:.04em;opacity:.6;padding:0 14px 4px 0;text-align:right;}
.fl-cmp th:first-child{text-align:left;}
.fl-cmp td{padding:3px 14px 3px 0;text-align:right;font-variant-numeric:tabular-nums;}
.fl-cmp td:first-child{text-align:left;font-weight:600;padding-right:22px;}
.fl-cmp .big{font-size:16px;font-weight:700;}
.fl-cmp .dim{opacity:.55;}
.fl-cmpwin{font-size:11.5px;font-weight:700;padding-top:6px;}
.fl-cmpfoot{font-size:11px;opacity:.65;padding-top:7px;line-height:1.45;}
.fl-notes{margin:10px 16px 0;padding:9px 13px;border-radius:8px;background:#fef2f2;border:1px solid #fecaca;font-size:12.5px;color:#7f1d1d;}

.fl-tablewrap{padding:12px 16px;overflow-x:auto;}
.fl-table{border-collapse:collapse;width:100%;font-size:13px;min-width:940px;background:#fff;}
.fl-table th{font-size:10.5px;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;text-align:left;padding:8px 10px;border-bottom:2px solid #e5e7eb;font-weight:600;background:#f9fafb;white-space:nowrap;}
.fl-table td{padding:9px 10px;border-bottom:1px solid #f1f5f9;white-space:nowrap;font-variant-numeric:tabular-nums;}
.fl-table th.num,.fl-table td.num{text-align:right;}
.fl-table tbody tr{cursor:pointer;}
.fl-table tbody tr:hover{background:#f9fafb;}
.fl-table tbody tr.sel{background:#fffbeb;}
.fl-name{font-weight:600;color:#111827;}
.fl-empty{text-align:center;color:#9ca3af;padding:22px;font-style:normal;}
.fl-muted{color:#9ca3af;}
.fl-strong{font-weight:700;color:#111827;}

.fl-pill{display:inline-block;font-size:11px;border-radius:999px;padding:1.5px 9px;font-weight:600;}
/* 🏠 Home location on the riders row — green when one is on file, and the amber
   "set home" uses the shared .fl-warn colour. Both are buttons, hence the cursor. */
.fl-home{background:#dcfce7;color:#166534;}
.fl-pill[onclick]{cursor:pointer;}
.fl-pill[onclick]:hover{filter:brightness(0.95);text-decoration:underline;}
.fl-company{background:#e0e7ff;color:#3730a3;}
.fl-own{background:#f3f4f6;color:#4b5563;}
.fl-unknown{background:#fee2e2;color:#b91c1c;}
/* Shared / transit — kilometres charged to nobody. Deliberately its own colour:
   it must not read as either a warning (the rider did nothing wrong) or as an
   ordinary figure (it is not his). */
.fl-shared{background:#e6f7f3;color:#0f766e;}
.fl-mrow{display:flex;gap:10px;align-items:baseline;flex-wrap:wrap;padding:6px 8px;
         border:1px solid #e5e7eb;border-radius:8px;background:#fafafa;margin-bottom:6px;}
.fl-mrow .fl-mkm{font-weight:700;}
.fl-mlink{color:#1d4ed8;cursor:pointer;font-size:11.5px;font-weight:600;}
.fl-mlink:hover{text-decoration:underline;}
.fl-handover{background:#e6f7f3;border-left:3px solid #0f766e;border-radius:6px;
             padding:4px 8px;margin:3px 0;}
/* Day cards — one card per date, the day read top to bottom. */
.fl-dc{border:1px solid #e5e7eb;border-radius:9px;margin-bottom:9px;overflow:hidden;}
.fl-dc-h{display:flex;justify-content:space-between;align-items:baseline;gap:10px;
         background:#f8f9fb;border-bottom:1px solid #e5e7eb;padding:6px 11px;
         font-weight:650;font-size:13px;color:#111827;}
.fl-dc-b{padding:6px 11px 8px;}
.fl-dc-l{display:flex;gap:9px;padding:2.5px 0;font-size:12.5px;align-items:baseline;flex-wrap:wrap;}
.fl-dc-k{min-width:92px;color:#6b7280;font-size:11.5px;flex-shrink:0;}
.fl-dc-l b{font-size:13px;color:#111827;}
.fl-ok{background:#dcfce7;color:#15803d;}
.fl-due{background:#fef3c7;color:#b45309;}
.fl-over{background:#fee2e2;color:#b91c1c;}
.fl-na{background:#f3f4f6;color:#9ca3af;}
.fl-warn{background:#fef3c7;color:#b45309;}

.fl-detail{margin:0 16px 18px;border:1px solid #e5e7eb;border-radius:9px;background:#fff;overflow:hidden;}
.fl-dhead{display:flex;align-items:center;gap:12px;padding:11px 14px;background:#f9fafb;border-bottom:1px solid #e5e7eb;flex-wrap:wrap;}
.fl-dhead h4{margin:0;font-size:14.5px;color:#111827;}
.fl-dclose{margin-left:auto;border:none;background:none;font-size:19px;color:#9ca3af;cursor:pointer;line-height:1;}
.fl-dbody{display:grid;grid-template-columns:1fr 300px;gap:0;}
@media (max-width:960px){.fl-dbody{grid-template-columns:1fr;}}
.fl-days{max-height:460px;overflow-y:auto;}
.fl-side{border-left:1px solid #e5e7eb;padding:12px 14px;}
@media (max-width:960px){.fl-side{border-left:0;border-top:1px solid #e5e7eb;}}
.fl-side h5{margin:0 0 8px;font-size:10.5px;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;}
.fl-day{padding:9px 14px;border-bottom:1px solid #f1f5f9;}
.fl-dayhead{display:flex;align-items:center;gap:10px;font-size:12.5px;}
.fl-daydate{font-weight:600;color:#111827;min-width:96px;}
.fl-daykm{color:#6b7280;font-variant-numeric:tabular-nums;}
.fl-daymissing{color:#b45309;font-weight:600;}
.fl-appnote{font-size:11.5px;color:#3730a3;margin-top:4px;}
.fl-maintsplit{font-size:10.5px;color:#6b7280;font-weight:500;margin-top:1px;}
.fl-appnote span{color:#9ca3af;}
.fl-appwho{font-size:11px;color:#4b5563;margin-top:3px;}
.fl-appwho b{color:#111827;font-weight:600;}
.fl-appwho .fl-muted{color:#9ca3af;}
.fl-kmcell.tap{cursor:pointer;}
.fl-kmcell.tap:hover{background:#eef2ff;border-color:#c7d2fe;}
.fl-offlist{margin-top:8px;border-top:1px solid #e5e7eb;padding-top:7px;}
.fl-offrow{display:flex;justify-content:space-between;font-size:12px;color:#374151;padding:3px 0;font-variant-numeric:tabular-nums;}
.fl-offrow span:last-child{font-weight:600;color:#b45309;}
.fl-kmbox{padding:10px 14px;background:#f9fafb;border-bottom:1px solid #e5e7eb;}
.fl-kmtitle{font-size:10.5px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;}
.fl-kmrow{display:flex;gap:8px;margin-top:7px;flex-wrap:wrap;}
.fl-kmcell{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:6px 14px;text-align:center;min-width:78px;}
.fl-kmcell b{display:block;font-size:14px;color:#111827;font-variant-numeric:tabular-nums;}
.fl-kmcell span{font-size:9.5px;color:#6b7280;text-transform:uppercase;letter-spacing:.03em;}
.fl-kmcell.off b{color:#d97706;}
.fl-kmnote{font-size:11.5px;color:#b45309;margin-top:7px;font-weight:600;}
.fl-kmnote.dim{color:#9ca3af;font-weight:500;}
.fl-claim{display:flex;align-items:center;gap:9px;margin-top:6px;padding:6px 9px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:7px;font-size:12.5px;flex-wrap:wrap;}
.fl-claim.flagged{background:#fffbeb;border-color:#fcd34d;}
.fl-claim.rejected{opacity:.55;}
.fl-thumb{width:38px;height:38px;object-fit:cover;border-radius:5px;border:1px solid #d1d5db;cursor:pointer;flex-shrink:0;background:#f3f4f6;}
.fl-nophoto{width:38px;height:38px;border-radius:5px;border:1px dashed #d1d5db;display:flex;align-items:center;justify-content:center;color:#d1d5db;font-size:15px;flex-shrink:0;}
.fl-amt{font-weight:700;color:#111827;font-variant-numeric:tabular-nums;}
.fl-actions{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-left:auto;}
.fl-src{border:1px solid #d1d5db;border-radius:6px;padding:3px 6px;font-size:11.5px;background:#fff;color:#374151;max-width:150px;}
.fl-approve,.fl-reject{border:none;border-radius:6px;padding:4px 10px;font-size:11.5px;font-weight:700;color:#fff;cursor:pointer;white-space:nowrap;}
.fl-approve{background:#16a34a;}
.fl-approve:hover{background:#15803d;}
.fl-reject{background:#dc2626;}
.fl-reject:hover{background:#b91c1c;}
.fl-svc{display:flex;justify-content:space-between;font-size:12.5px;padding:5px 0;border-bottom:1px solid #f1f5f9;}
.fl-btn{border:1px solid #d1d5db;background:#fff;border-radius:6px;padding:5px 11px;cursor:pointer;font-size:12px;color:#374151;}
.fl-btn:hover{background:#f3f4f6;}

/* ---------- Vehicles view (Aug-2026, scoped .fl-v*) ---------- */
.fl-modes{display:flex;gap:0;border:1px solid #d1d5db;border-radius:8px;overflow:hidden;}
.fl-mode{border:none;background:#fff;padding:6px 13px;font-size:12.5px;font-weight:700;color:#6b7280;cursor:pointer;white-space:nowrap;}
.fl-mode + .fl-mode{border-left:1px solid #d1d5db;}
.fl-mode.on{background:#f59e0b;color:#fff;}
.fl-mode:not(.on):hover{background:#f9fafb;}

.fl-vintro{margin:12px 16px 0;font-size:12px;color:#6b7280;line-height:1.5;}
.fl-vintro b{color:#111827;}
.fl-vwarn{margin:12px 16px 0;padding:10px 13px;border-radius:8px;background:#fffbeb;border:1px solid #fcd34d;font-size:12.5px;color:#78350f;}
.fl-vgrid{padding:12px 16px;}
/* ⭐ Two sides: working pairs on the left, everything idle on the right. Collapses
   to one column on a narrow screen — the left side stays first, which is the side
   a manager reads every day. */
.fl-vsplit{display:grid;grid-template-columns:1.15fr .85fr;gap:16px;align-items:start;}
@media (max-width:1100px){.fl-vsplit{grid-template-columns:1fr;}}
.fl-vcol{min-width:0;display:flex;flex-direction:column;gap:10px;}
.fl-vcolh{margin:0 0 2px;font-size:12px;font-weight:800;color:#6b7280;text-transform:uppercase;
  letter-spacing:.05em;display:flex;align-items:center;gap:7px;}
.fl-vcolh span{background:#f3f4f6;color:#4b5563;border-radius:20px;padding:1px 8px;font-size:11px;letter-spacing:0;}
.fl-vsubh{margin-top:6px;font-size:11.5px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:.04em;}
.fl-vretired{margin-top:6px;}
.fl-vretired summary{font-size:11.5px;color:#9ca3af;cursor:pointer;padding:4px 0;}
.fl-vretired[open] summary{margin-bottom:8px;}
.fl-vcard{border:1px solid #e5e7eb;border-radius:10px;background:#fff;padding:12px;cursor:pointer;transition:box-shadow .12s,border-color .12s;}
.fl-vcard:hover{border-color:#fcd34d;box-shadow:0 3px 12px rgba(0,0,0,.07);}
.fl-vcard.retired{opacity:.55;}
/* Per-type service schedule rows on the machine profile. */
.fl-vsched{margin-top:9px;display:flex;flex-direction:column;}
.fl-vschedrow{display:flex;align-items:center;gap:10px;padding:6px 0;font-size:12.5px;
  color:#374151;border-top:1px solid #f3f4f6;}
.fl-vschedrow:first-child{border-top:none;}
/* A rider holding nothing — a state to act on, not an error. */
.fl-vcard.fl-vrider{cursor:default;border-style:dashed;background:#fcfcfd;}
.fl-vcard.fl-vrider:hover{border-color:#e5e7eb;box-shadow:none;}
.fl-vnote{margin-top:8px;font-size:11.5px;line-height:1.45;color:#6b7280;}
.fl-vtop{display:flex;align-items:flex-start;gap:10px;}
.fl-vphoto{width:52px;height:52px;border-radius:8px;object-fit:cover;border:1px solid #e5e7eb;background:#f3f4f6;flex-shrink:0;}
.fl-vnophoto{width:52px;height:52px;border-radius:8px;border:1px dashed #d1d5db;display:flex;align-items:center;justify-content:center;font-size:22px;color:#d1d5db;flex-shrink:0;}
.fl-vname{font-size:15px;font-weight:700;color:#111827;line-height:1.2;}
.fl-vsub{font-size:11.5px;color:#6b7280;margin-top:2px;}
.fl-vtag{display:inline-block;padding:1px 7px;border-radius:20px;font-size:10px;font-weight:700;vertical-align:middle;margin-left:6px;}
.fl-vtag.co{background:#dbeafe;color:#1e40af;}
.fl-vtag.own{background:#f3f4f6;color:#4b5563;}
.fl-vtag.none{background:#fef3c7;color:#92400e;}
.fl-vkeeper{margin-top:9px;font-size:12.5px;color:#374151;}
.fl-vkeeper b{color:#111827;}
/* "Nobody has this one" is informational, not a fault — amber, not red. */
.fl-vkeeper.none{color:#9ca3af;font-weight:600;}
/* 🔁 Someone is waiting on this machine. Indigo like the banner it points at —
   deliberately NOT red or amber: nothing is wrong, a decision is simply due. */
.fl-vpending{margin-top:7px;font-size:12px;color:#3730a3;background:#eef2ff;
  border:1px solid #c7d2fe;border-left:3px solid #6366f1;border-radius:7px;padding:5px 8px;}
.fl-vpending-cta{font-weight:700;text-decoration:underline;}
/* ⚠ The attention strip — which bikes are asking for an action. Red = somebody is stuck
   NOW, amber = something is due, indigo = informational. Only rendered when the machine
   actually wants something, so a healthy fleet still reads as a calm grid. */
.fl-vattn{margin-top:7px;display:flex;flex-wrap:wrap;gap:5px;}
.fl-vattn-chip{font-size:10.5px;font-weight:800;padding:2px 7px;border-radius:999px;
  background:#eef2ff;color:#3730a3;}
.fl-vattn-chip.bad{background:#fee2e2;color:#991b1b;}
.fl-vattn-chip.warn{background:#fef3c7;color:#92400e;}
.fl-vattn-head{font-size:12px;font-weight:800;color:#b45309;margin:0 0 10px;}
.fl-vattn-head.calm{color:#15803d;}
/* An own bike whose owner is out on a company machine: exactly where it should be. */
.fl-vkeeper.parked{color:#6b7280;}
/* Standing "needs a home pin" nag — amber, informative, self-clearing. */
.fl-vwarnpin{margin-top:6px;font-size:11.5px;line-height:1.45;color:#92400e;
  background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:6px 8px;}
/* 🏠 The nag's action, and the calm "there is one on file" line it becomes. */
.fl-homebtn{margin-left:2px;padding:3px 9px;border:1px solid #d97706;background:#fff;
  color:#92400e;border-radius:6px;font-size:11px;font-weight:700;cursor:pointer;}
.fl-homebtn:hover{background:#fef3c7;}
.fl-vhome{margin-top:6px;font-size:11.5px;color:#047857;}
.fl-vhome a{color:#047857;font-weight:700;text-decoration:underline;}
.fl-homelink{border:0;background:none;padding:0;color:#047857;font-size:11.5px;
  font-weight:700;text-decoration:underline;cursor:pointer;}
/* The home-pin editor — same modal furniture as the Types editor above it. */
.fl-hpwrap{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:10001;display:none;}
.fl-hpbox{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;
  border-radius:12px;width:92%;max-width:520px;max-height:90vh;overflow-y:auto;
  box-shadow:0 25px 50px -12px rgba(0,0,0,.3);}
.fl-hphead{padding:14px 16px;border-bottom:1px solid #e5e7eb;display:flex;
  justify-content:space-between;align-items:center;}
.fl-hpbody{padding:16px;}
.fl-hpinput{width:100%;padding:9px 12px;border:1px solid #6EE7B7;border-radius:8px;font-size:13px;}
.fl-hpcoord{width:120px;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;font-size:13px;}
.fl-hpsave{padding:8px 16px;background:#059669;color:#fff;border:0;border-radius:8px;
  font-size:13px;font-weight:700;cursor:pointer;}
.fl-hpsave:disabled{opacity:.5;cursor:default;}
.fl-hpdel{padding:8px 14px;background:#fff;color:#b91c1c;border:1px solid #fecaca;
  border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;}
.fl-hpmsg{margin-top:10px;font-size:12px;line-height:1.5;}
.fl-hpmsg.err{color:#b91c1c;}
.fl-hpmsg.ok{color:#047857;}
.fl-hphist{margin-top:14px;border-top:1px solid #e5e7eb;padding-top:10px;font-size:11.5px;color:#6b7280;}
.fl-hphist b{color:#374151;}
.fl-vstats{display:flex;gap:14px;flex-wrap:wrap;margin-top:8px;font-size:12px;color:#4b5563;font-variant-numeric:tabular-nums;}
.fl-vchip{display:inline-block;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;}
.fl-vchip.ok{background:#dcfce7;color:#166534;}
.fl-vchip.due{background:#fef3c7;color:#92400e;}
.fl-vchip.over{background:#fee2e2;color:#991b1b;}
.fl-vchip.unk{background:#f3f4f6;color:#6b7280;}
.fl-vfoot{display:flex;align-items:center;gap:7px;margin-top:10px;padding-top:9px;border-top:1px solid #f1f5f9;}
.fl-vbtn{border:1px solid #d1d5db;background:#fff;border-radius:7px;padding:5px 11px;font-size:12px;font-weight:600;color:#374151;cursor:pointer;}
.fl-vbtn:hover{background:#f3f4f6;}
.fl-vbtn.primary{background:#f59e0b;border-color:#f59e0b;color:#fff;}
.fl-vbtn.primary:hover{background:#d97706;}

/* ⚠ NOT overflow:hidden — that turns the panel into a scroll box, and a sticky header
     inside one never sticks. Rounded corners are kept by rounding the header itself. */
.fl-vdetail{margin:0 16px 16px;border:1px solid #e5e7eb;border-radius:10px;background:#fff;}
/* ⭐ The header carries the machine's identity AND its two actions, so it stays in view
     while the manager reads down the page. */
.fl-vdhead{position:sticky;top:0;z-index:6;border-radius:10px 10px 0 0;}
.fl-vtbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:9px 0 11px;margin-bottom:4px;
          border-bottom:1px solid #f3f4f6;}
.fl-vstep{display:inline-flex;align-items:center;border:1px solid #e5e7eb;border-radius:8px;background:#fff;}
.fl-vstep button{border:0;background:none;cursor:pointer;font-size:14px;line-height:1;padding:5px 9px;color:#374151;}
.fl-vstep button:disabled{color:#d1d5db;cursor:default;}
.fl-vstep b{font-size:12px;font-weight:700;color:#111827;padding:0 5px;min-width:78px;text-align:center;}
.fl-vchipbtn{padding:4px 10px;font-size:11.5px;border:1px solid #e5e7eb;border-radius:14px;background:#fff;
             cursor:pointer;color:#374151;font-weight:600;}
.fl-vchipbtn.on{background:#fff7ed;border-color:#fdba74;color:#9a3412;}
.fl-vduechip{font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:12px;}
.fl-vdhead{display:flex;align-items:center;gap:12px;padding:14px 16px;border-bottom:1px solid #e5e7eb;background:#fafafa;flex-wrap:wrap;}
.fl-vdbody{padding:14px 16px;}
.fl-vsec{margin-bottom:16px;}
.fl-vsec h4{font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#6b7280;margin:0 0 8px;}
.fl-vstrip{display:flex;gap:9px;overflow-x:auto;padding-bottom:4px;}
.fl-vpic{flex-shrink:0;width:104px;}
.fl-vpic img{width:104px;height:82px;object-fit:cover;border-radius:7px;border:1px solid #e5e7eb;cursor:pointer;background:#f3f4f6;}
.fl-vpiclab{font-size:10.5px;color:#6b7280;margin-top:3px;line-height:1.3;}
.fl-vhrow{display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid #f1f5f9;font-size:12.5px;}
.fl-vhrow:last-child{border-bottom:none;}
.fl-vhnow{background:#dcfce7;color:#166534;padding:1px 7px;border-radius:20px;font-size:10px;font-weight:700;}

.fl-vdaychip{display:inline-block;margin-left:7px;padding:1px 7px;border-radius:20px;font-size:10.5px;font-weight:700;background:#f3f4f6;color:#4b5563;vertical-align:middle;}
.fl-vdaychip.fix{background:#e0e7ff;color:#3730a3;}
.fl-vdaychip.xfer{background:#fef3c7;color:#92400e;}
.fl-vdaylink{margin-left:7px;font-size:10.5px;font-weight:700;color:#9ca3af;text-decoration:none;vertical-align:middle;}
.fl-vdaylink:hover{color:#b45309;text-decoration:underline;}

/* photo lightbox (inline-styled shell — the purged utility classes cannot be trusted here) */
#flLightbox{display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:4000;background:rgba(0,0,0,.75);align-items:center;justify-content:center;}
#flLightbox img{max-width:92vw;max-height:88vh;border-radius:8px;box-shadow:0 12px 44px rgba(0,0,0,.5);background:#fff;}
#flLightbox .fl-lbclose{position:absolute;top:14px;right:20px;color:#fff;font-size:30px;cursor:pointer;line-height:1;background:none;border:none;}
</style>

<div id="flLightbox" onclick="flClosePhoto()">
    <button class="fl-lbclose" onclick="flClosePhoto()" title="Close">&times;</button>
    <img id="flLightboxImg" src="" alt="Receipt photo">
</div>

{{-- New bike expense. Inline-styled shell on purpose — this page's CSS is purged
     of the legacy utility classes, so a class-based modal renders top-left and
     will not scroll (see the metronic-v9 note). --}}
{{-- ⚙️ MAINTENANCE TYPES — the manager's own category list.
     Inline styles for the same reason as the modal below: this page's shell drops
     the legacy utility classes, so a class-based modal renders top-left and will
     not scroll (see the metronic-v9 note). --}}
<div id="flTypesModal" onclick="if(event.target===this)flCloseTypes()"
     style="display:none;position:fixed;inset:0;z-index:4200;background:rgba(0,0,0,.5);
            align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:12px;width:100%;max-width:560px;max-height:90vh;
              overflow-y:auto;box-shadow:0 18px 60px rgba(0,0,0,.35);">
    <div style="display:flex;align-items:center;gap:9px;padding:14px 18px;border-bottom:1px solid #e5e7eb;">
      <b style="font-size:15px;color:#111827;">⚙️ Maintenance types</b>
      <button type="button" onclick="flCloseTypes()" title="Close"
              style="margin-left:auto;border:none;background:none;font-size:24px;color:#9ca3af;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div style="padding:14px 18px;">
      <div style="font-size:11.5px;color:#6b7280;margin-bottom:10px;">
        The list riders and managers pick from when filing maintenance. A <b>Regular</b> type can
        carry a schedule (“every 1,200&nbsp;km”); a <b>Repair</b> happens as needed.
        Only a regular type that <b>resets the service clock</b> counts as “the vehicle was serviced” —
        tick it for oil services only, or a brake-shoe job will make the bike look serviced when it is not.
        <div id="flTypesClassNote" style="display:none;margin-top:6px;">
          Each job carries its own figure for <b>🏍️ bikes</b> and for <b>🚚 vans</b> — this is the
          company standard. A single machine that differs is set on its own page under
          <b>Vehicles → ⚙️ This vehicle’s schedule</b>.
        </div>
      </div>
      <div id="flTypesBody" style="font-size:13px;">Loading…</div>

      <div id="flTypesForm" style="margin-top:12px;padding-top:12px;border-top:1px solid #e5e7eb;display:none;">
        <input type="hidden" id="flTypeId">
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
          <div style="flex:2;min-width:150px;">
            <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Name</label>
            <input id="flTypeName" type="text" maxlength="80" placeholder="e.g. Brake Shoe"
                   style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
          </div>
          <div style="flex:1;min-width:110px;">
            <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Kind</label>
            <select id="flTypeBucket" onchange="flTypeBucketChanged()"
                    style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
              <option value="regular">🛢️ Regular</option>
              <option value="repair">🔧 Repair</option>
            </select>
          </div>
        </div>

        {{-- ⭐⭐ VEHICLE CLASS + BASIS (Sep-2026).
             A job is ONE row with a number per class — the van's Oil Change is the same
             job as the bike's, on its own schedule. Hidden entirely until the migration
             has run (`class_aware`), so uploading these files before the SQL shows the
             old single-figure form rather than fields that would be silently dropped. --}}
        <div id="flTypeClassWrap" style="display:none;margin-top:9px;">
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;">
            <div style="flex:1;min-width:140px;">
              <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Applies to</label>
              <select id="flTypeApplies" onchange="flTypeClassChanged()"
                      style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
                <option value="bike">🏍️ Bikes only</option>
                <option value="van">🚚 Vans only</option>
                <option value="both">🏍️🚚 Both</option>
              </select>
            </div>
            <div style="flex:1;min-width:140px;">
              <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Measured in</label>
              <select id="flTypeBasis" onchange="flTypeClassChanged()"
                      style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
                <option value="km">Kilometres</option>
                <option value="time">Time</option>
              </select>
            </div>
            <div id="flTypeUnitWrap" style="flex:1;min-width:120px;display:none;">
              <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Unit</label>
              <select id="flTypeTimeUnit" onchange="flTypeClassChanged()"
                      style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
                <option value="months">Months</option>
                <option value="days">Days</option>
              </select>
            </div>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-top:8px;">
            <div id="flTypeBikeWrap" style="flex:1;min-width:150px;">
              <label id="flTypeBikeLbl" style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">
                🏍️ Bikes — due every</label>
              <input id="flTypeIntervalBike" type="number" min="0" max="200000" step="100" placeholder="blank = as conditions"
                     style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
            </div>
            <div id="flTypeVanWrap" style="flex:1;min-width:150px;">
              <label id="flTypeVanLbl" style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">
                🚚 Vans — due every</label>
              <input id="flTypeIntervalVan" type="number" min="0" max="200000" step="100" placeholder="blank = as conditions"
                     style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
            </div>
          </div>
        </div>

        {{-- The pre-migration single field. Replaced by the pair above the moment the
             SQL has run, so there is never one number pretending to serve both. --}}
        <div id="flTypeLegacyWrap" style="margin-top:9px;max-width:200px;">
          <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Due every (km)</label>
          <input id="flTypeInterval" type="number" min="0" max="200000" step="100" placeholder="blank = as conditions"
                 style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
        </div>
        <label id="flTypeResetWrap" style="display:block;margin-top:8px;font-size:12.5px;color:#374151;">
          <input type="checkbox" id="flTypeResets"> <b>Oil service</b> — a bigger oil service counts for this one too
          <span style="display:block;font-size:11.5px;color:#6b7280;margin:2px 0 0 20px;">
            e.g. recording Oil + Tuning also counts as an Oil Change when both are ticked. Every job
            still resets its own countdown; the bike's chip shows whichever job is due soonest.
          </span>
        </label>
        <div id="flTypesError" style="display:none;font-size:12px;color:#b91c1c;background:#fef2f2;
             border:1px solid #fecaca;border-radius:8px;padding:7px 9px;margin-top:8px;"></div>
        <div style="display:flex;gap:8px;margin-top:10px;">
          <button type="button" onclick="flSaveType()" id="flTypeSaveBtn"
                  style="padding:8px 14px;border:none;border-radius:8px;background:#f59e0b;color:#fff;
                         font-size:13px;font-weight:700;cursor:pointer;">Save</button>
          <button type="button" onclick="flResetTypeForm()"
                  style="padding:8px 14px;border:1px solid #d1d5db;background:#fff;border-radius:8px;
                         font-size:13px;font-weight:600;color:#374151;cursor:pointer;">Clear</button>
        </div>
      </div>
    </div>
  </div>
</div>

{{-- 🏍️ ASSIGN / REASSIGN a vehicle. Inline-styled shell for the same reason as the
     others (purged legacy classes render a class-based modal top-left, unscrollable).

     ⭐ The consequence preview is the point of this modal. Handing a bike over changes
     who is measured for its meter, whether the company buys the fuel, and whether the
     overnight checks can run at all — so the server is asked what WILL happen and the
     answer is shown in words before Save is ever pressed. --}}
{{-- ⭐ The machine's meter editor (owner ask, Aug-14). Opened from a vehicle card or
     its profile. It EDITS the date rather than blindly inserting: whatever is already
     recorded is preloaded and each field is routed back to its own source on save. --}}
<div id="flvMeterModal" onclick="if(event.target===this)flvCloseMeter()"
     style="display:none;position:fixed;inset:0;background:rgba(17,24,39,.45);z-index:1200;
            align-items:flex-start;justify-content:center;padding:40px 14px;overflow:auto;">
  <div style="background:#fff;border-radius:12px;max-width:470px;width:100%;padding:18px;
              box-shadow:0 10px 30px rgba(20,20,40,.18);">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
      <b id="flvMeterTitle" style="font-size:15px;color:#111827;">🧾 Meter reading</b>
      <button type="button" onclick="flvCloseMeter()"
              style="margin-left:auto;border:0;background:none;font-size:22px;color:#9ca3af;cursor:pointer;">&times;</button>
    </div>

    <div>
      <label class="fl-dc-k">date</label>
      <input type="date" id="flvMeterDate" onchange="flvLoadMeterDay()"
             style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
    </div>

    <div id="flvMeterBody" style="margin-top:11px;"></div>

    <div id="flvMeterError" style="display:none;font-size:12px;color:#b91c1c;background:#fef2f2;
                                   border-radius:7px;padding:8px 10px;margin-top:9px;"></div>

    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px;">
      <button type="button" class="fl-vbtn" onclick="flvCloseMeter()">Cancel</button>
      <button type="button" class="fl-vbtn primary" id="flvMeterSave" onclick="flvSaveMeter()">Save</button>
    </div>
  </div>
</div>

<div id="flvAssignModal" onclick="if(event.target===this)flvCloseAssign()"
     style="display:none;position:fixed;inset:0;z-index:4200;background:rgba(0,0,0,.5);
            align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:12px;width:100%;max-width:520px;max-height:90vh;
              overflow-y:auto;box-shadow:0 18px 60px rgba(0,0,0,.35);">
    <div style="display:flex;align-items:center;gap:9px;padding:14px 18px;border-bottom:1px solid #e5e7eb;">
      <b id="flvAssignTitle" style="font-size:15px;color:#111827;">Assign vehicle</b>
      <button type="button" onclick="flvCloseAssign()" title="Close"
              style="margin-left:auto;border:none;background:none;font-size:24px;color:#9ca3af;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div style="padding:14px 18px;">
      <input type="hidden" id="flvAssignVehicleId">

      {{-- Only shown when the manager came in from a RIDER card ("give him a bike"),
           where the machine is the thing still to be chosen. Hidden otherwise. --}}
      <div style="display:none;margin-bottom:10px;">
        <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Which machine</label>
        <select id="flvAssignVehicleSel" onchange="flvAssignVehicleChanged()"
                style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 9px;font-size:13px;">
        </select>
      </div>

      <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Give it to</label>
      <select id="flvAssignRider" onchange="flvPreviewAssign()"
              style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 9px;font-size:13px;">
      </select>

      {{-- ⭐⭐ "AND WHAT ABOUT HIM?" (owner ask, Aug-6).
           Handing a machine over used to be half an act: the outgoing rider's
           assignment closed and nothing else happened, so he silently held nothing
           while the system still judged him as if he had a bike. Now the manager
           answers for him here, and the three options are the three real ones. --}}
      <div id="flvDisplacedBox" style="display:none;margin-top:12px;border:1px solid #fde68a;
           background:#fffbeb;border-radius:9px;padding:10px 11px;">
        <div id="flvDisplacedQ" style="font-size:12.5px;font-weight:700;color:#92400e;margin-bottom:7px;"></div>
        <div id="flvDisplacedOpts" style="display:flex;flex-direction:column;gap:5px;"></div>
        <select id="flvDisplacedVehicle" onchange="flvDisplacedPick('vehicle')"
                style="display:none;width:100%;margin-top:7px;border:1px solid #d1d5db;
                       border-radius:8px;padding:7px 9px;font-size:12.5px;"></select>
      </div>

      <div style="display:flex;gap:9px;margin-top:10px;">
        <div style="flex:1;">
          <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">From</label>
          <input type="date" id="flvAssignDate" onchange="flvPreviewAssign()"
                 style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
        </div>
        <div style="flex:2;">
          <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Note <span style="font-weight:400;color:#9ca3af;">(optional)</span></label>
          <input type="text" id="flvAssignNote" maxlength="255" placeholder="e.g. Arslan on leave"
                 style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
        </div>
      </div>

      {{-- ⭐ THE ODOMETER AT HANDOVER (owner ruling Aug-13).
           Without it, the day a bike changes hands shows as "shared" and is charged
           to neither rider — honest, but blunt. With it, the day splits exactly.
           ⚠ Optional and never blocking: the warning below is advice, not a gate. --}}
      <div style="margin-top:10px;">
        <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">
          Odometer at handover <span style="font-weight:400;color:#9ca3af;">(optional)</span>
        </label>
        <input type="number" id="flvAssignMeter" min="0" step="1" oninput="flvMeterHint()"
               placeholder="e.g. 25780"
               style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
        <div id="flvMeterHint" style="font-size:11.5px;color:#6b7280;margin-top:4px;">
          If you enter it, this day's kilometres split exactly between the two riders
          instead of showing as shared. Leave blank if you don't know it.
        </div>
      </div>

      {{-- ⭐⭐ R3 — THE MACHINE THIS RIDER IS STEPPING OFF (13-Sep-2026).
           ⚠⚠ THIS BOX DID NOT EXIST. Handing a rider a machine silently closes whatever he was
              already holding, and nothing ever asked for THAT machine's closing reading — so on
              13-Sep the van left Rajab's hands with no number and Shabib typed it from memory.
           ⚠ Shown only when the server's `meters_required` says the vacated machine is a company
             one; the label and prompt are the server's words, never composed here. --}}
      <div id="flvVacatedWrap" style="margin-top:10px;display:none;">
        <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">
          <span id="flvVacatedLabel"></span> <span style="font-weight:500;color:#b45309;">(required)</span>
        </label>
        <input type="number" id="flvVacatedMeter" min="0" step="1" placeholder="Odometer now"
               style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
        <div id="flvVacatedHint" style="font-size:11.5px;color:#6b7280;margin-top:4px;"></div>
      </div>

      {{-- Condition photos. Optional by design — a handover with no photo is still a
           handover, and blocking on a camera would just mean the assignment never
           gets recorded at all. --}}
      <div style="margin-top:11px;">
        <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">
          📷 Condition photos <span style="font-weight:400;color:#9ca3af;">(optional — how it looks at handover)</span>
        </label>
        <input type="file" id="flvAssignPhotos" accept="image/*" multiple
               style="width:100%;font-size:12px;">
      </div>

      <div id="flvPreviewBox" style="margin-top:12px;font-size:12.5px;"></div>

      <div id="flvAssignError" style="display:none;font-size:12px;color:#b91c1c;background:#fef2f2;
           border:1px solid #fecaca;border-radius:8px;padding:7px 9px;margin-top:9px;"></div>

      <div style="display:flex;gap:8px;margin-top:12px;">
        <button type="button" id="flvAssignSave" onclick="flvSaveAssign()"
                style="padding:9px 16px;border:none;border-radius:8px;background:#f59e0b;color:#fff;
                       font-size:13px;font-weight:700;cursor:pointer;">Assign</button>
        <button type="button" onclick="flvCloseAssign()"
                style="padding:9px 16px;border:1px solid #d1d5db;background:#fff;border-radius:8px;
                       font-size:13px;font-weight:600;color:#374151;cursor:pointer;">Cancel</button>
      </div>
    </div>
  </div>
</div>

{{-- ➕ ADD / EDIT a machine, and set the fixed base a van needs. --}}
{{-- 🧾 THE BILL for a service being recorded (owner ask, 3-Sep).
     ⚠⚠ This is a MODAL and not another window.prompt() for one reason: a prompt cannot carry a
        FILE, and the owner's ask was that the bill itself rides along with the expense. The rest
        of the Record-service flow stays the prompt chain it has always been — a manager who is
        only entering a reading never sees this box at all.
     ⭐ Everything money-shaped lives in here together: the amount, where it is paid from, the
       bill photo, and the warning that says what will actually happen. --}}
{{-- 🪟 THE ONE IN-PAGE FORM for the quick actions (schedule / accept / cancel / mark done a
     workshop visit, report a problem). Built by flForm() from a field list — see the JS. --}}
<div id="flFormModal" onclick="if(event.target===this)flFormClose()"
     style="display:none;position:fixed;inset:0;z-index:4310;background:rgba(0,0,0,.5);
            align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:12px;width:100%;max-width:440px;max-height:90vh;
              overflow-y:auto;box-shadow:0 18px 60px rgba(0,0,0,.35);">
    <div style="display:flex;align-items:center;gap:9px;padding:14px 18px;border-bottom:1px solid #e5e7eb;">
      <b id="flFormTitle" style="font-size:15px;color:#111827;"></b>
      <button type="button" onclick="flFormClose()" title="Close"
              style="margin-left:auto;border:0;background:none;font-size:20px;color:#9ca3af;cursor:pointer;">&times;</button>
    </div>
    <div style="padding:6px 18px 16px;">
      <div id="flFormIntro" style="font-size:12.5px;color:#6b7280;margin-top:8px;"></div>
      <div id="flFormBody"></div>
      <div id="flFormResult" style="display:none;font-size:12.5px;border:1px solid;border-radius:8px;padding:9px 10px;margin-top:12px;"></div>
      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">
        <button type="button" class="fl-vbtn" onclick="flFormClose()">Cancel</button>
        <button type="button" id="flFormOk" class="fl-vbtn" style="background:#111827;color:#fff;border-color:#111827;">Save</button>
      </div>
    </div>
  </div>
</div>
{{-- 🛠 RECORD A SERVICE — one form for everything a visit to the workshop produces
     (29-Sep-2026, Qasim: "I should be able to select multiple jobs"). It replaces a chain of
     window.prompt boxes that could only ever pick ONE job. It mirrors the phone's "Record a
     service" sheet field for field — jobs, when, odometer, one bill — because both post to the
     SAME `/mark-serviced` and the same engine (`ServiceRecordService::recordVisit`).
     ⭐ Every ticked job is written as its own record (its own countdown resets); the bill, when
       given, is ONE claim linked to all of them — one receipt, never split, never duplicated. --}}
<div id="flSvcBillModal" onclick="if(event.target===this)flSvcBillClose()"
     style="display:none;position:fixed;inset:0;z-index:4300;background:rgba(0,0,0,.5);
            align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:12px;width:100%;max-width:480px;max-height:90vh;
              overflow-y:auto;box-shadow:0 18px 60px rgba(0,0,0,.35);">
    <div style="display:flex;align-items:center;gap:9px;padding:14px 18px;border-bottom:1px solid #e5e7eb;">
      <b style="font-size:15px;color:#111827;">🛠 Record a service</b>
      <button type="button" onclick="flSvcBillClose()" title="Close"
              style="margin-left:auto;border:0;background:none;font-size:20px;color:#9ca3af;cursor:pointer;">&times;</button>
    </div>
    <div style="padding:16px 18px;">
      <div id="flSvcBillWhat" style="font-size:12.5px;color:#6b7280;margin-bottom:12px;"></div>

      <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:6px;">
        What was done <span style="font-weight:400;color:#9ca3af;">— tick every job done on this visit</span>
      </label>
      <div id="flSvcJobs" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
      {{-- One line per ticked job, from the server's own wording — what resets, and how often. --}}
      <div id="flSvcJobsSaid" style="font-size:11.5px;color:#6b7280;margin-top:6px;line-height:1.55;"></div>

      <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin:12px 0 4px;">When</label>
      {{-- 📅 Work handed in on Monday and recorded on Wednesday belongs to MONDAY — every
           countdown runs from the service date. --}}
      <input type="date" id="flSvcDate"
             style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">

      <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin:11px 0 4px;">Odometer at the service (km)</label>
      <input type="number" id="flSvcMeter" min="1" step="1" placeholder="e.g. 54125"
             style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">

      <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin:11px 0 4px;">
        Bill amount (Rs) <span style="font-weight:400;color:#9ca3af;">— optional; one bill for every ticked job</span>
      </label>
      <input type="number" id="flSvcBillAmount" min="1" step="1" placeholder="leave blank if only recording the reading"
             oninput="flSvcBillSync()"
             style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">

      <div id="flSvcBillSourceWrap" style="display:none;">
        <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin:11px 0 4px;">Paid from</label>
        {{-- ⚠ No pre-selected option: this spends real money, so the account is always an
             explicit choice rather than whatever happened to be first in the list. --}}
        <select id="flSvcBillSource" onchange="flSvcBillSync()"
                style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
          <option value="">— choose an account —</option>
        </select>
      </div>

      <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin:11px 0 4px;">
        📷 Photo <span style="font-weight:400;color:#9ca3af;">(the receipt or the work — kept even with no amount)</span>
      </label>
      <input type="file" id="flSvcBillPhoto" accept="image/*" onchange="flSvcBillSync()"
             style="width:100%;font-size:12px;">

      {{-- ⚠⚠ States the outcome for THIS user — an approver's expense posts to the ledger the
           same second, everyone else's queues. A manager who believes it is queued when it in
           fact posted is the failure this line exists to prevent. --}}
      <div id="flSvcBillWarn" style="display:none;font-size:12px;color:#92400E;background:#FFFBEB;
           border:1px solid #FCD34D;border-radius:8px;padding:9px 10px;margin-top:12px;"></div>

      <div id="flSvcBillError" style="display:none;font-size:12px;color:#b91c1c;background:#fef2f2;
           border:1px solid #fecaca;border-radius:8px;padding:7px 9px;margin-top:9px;"></div>

      <div style="display:flex;gap:8px;margin-top:14px;">
        <button type="button" onclick="flSvcBillClose()"
                style="flex:1;border:1px solid #d1d5db;background:#fff;border-radius:8px;
                       padding:9px;font-size:13px;font-weight:600;cursor:pointer;">
          Cancel
        </button>
        <button type="button" id="flSvcBillSubmit" onclick="flSvcBillSave()"
                style="flex:1;border:0;background:#111827;color:#fff;border-radius:8px;
                       padding:9px;font-size:13px;font-weight:700;cursor:pointer;">
          Save
        </button>
      </div>
    </div>
  </div>
</div>

{{-- 🛠📷 CLOSING A WORKSHOP VISIT — the same form the Bikes screen uses to record a service,
     because it writes the SAME row. See flWorkshopDone() for what the old four-field version
     could not express (no job, no money, no photo) and why each of those cost somebody. --}}
<div id="flWsDone" onclick="if(event.target===this)flWsDoneClose()"
     style="display:none;position:fixed;inset:0;z-index:4300;background:rgba(0,0,0,.5);
            align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:12px;width:100%;max-width:460px;max-height:90vh;
              overflow-y:auto;box-shadow:0 18px 60px rgba(0,0,0,.35);">
    <div style="display:flex;align-items:center;gap:9px;padding:14px 18px;border-bottom:1px solid #e5e7eb;">
      <b style="font-size:15px;color:#111827;">🛠 Mark the visit done</b>
      <button type="button" onclick="flWsDoneClose()" title="Close"
              style="margin-left:auto;border:0;background:none;font-size:20px;color:#9ca3af;cursor:pointer;">&times;</button>
    </div>
    <div style="padding:16px 18px;">

      <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">
        Odometer at the service (km)
      </label>
      <input type="number" id="flWsDoneMeter" min="0" placeholder="e.g. 28100"
             style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
      <div style="font-size:11.5px;color:#6b7280;margin-top:4px;">
        Leave blank if nothing was serviced (an inspection).
      </div>

      <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin:11px 0 4px;">
        What was done <span style="font-weight:400;color:#9ca3af;">— tick every job done on this trip</span>
      </label>
      {{-- ⭐ 29-Sep-2026: several jobs per trip, the same as "Record a service". The job the visit
           was booked for arrives pre-ticked; each ticked job becomes its own record. --}}
      <div id="flWsDoneType" style="display:flex;flex-wrap:wrap;gap:6px;">
        <span style="font-size:12px;color:#9ca3af;">Loading…</span>
      </div>
      {{-- ⚠ "no countdown" is the honest label for work that is real but not on a schedule
           (an overhaul, a general repair, or any job with no figures for a van). It is
           recorded in full and resets nothing — the owner's "other repair". --}}
      <div style="font-size:11.5px;color:#6b7280;margin-top:4px;">
        Jobs marked <b>no countdown</b> are recorded in full but reset no service clock.
      </div>

      <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin:11px 0 4px;">
        Amount (Rs) <span style="font-weight:400;color:#9ca3af;">— optional, if a bill was paid</span>
      </label>
      <input type="number" id="flWsDoneAmount" min="1" step="1" placeholder="leave blank if not paid yet"
             style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">

      <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin:11px 0 4px;">Paid from</label>
      <select id="flWsDoneSource"
              style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
        <option value="">—</option>
      </select>

      <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin:11px 0 4px;">
        📷 Photo <span style="font-weight:400;color:#9ca3af;">(the receipt or the work — no amount needed)</span>
      </label>
      {{-- ⭐ THE POINT OF THIS FIELD: the rider is handed a receipt at the counter and pays
           nothing. The photo is kept against the service itself, and whoever enters the amount
           days later can see what he is paying for. --}}
      <input type="file" id="flWsDonePhoto" accept="image/*" style="width:100%;font-size:12px;">

      <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin:11px 0 4px;">
        What was the outcome? (optional)
      </label>
      <textarea id="flWsDoneNote" rows="3" placeholder="e.g. Chain and sprocket replaced"
                style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;resize:vertical;"></textarea>

      {{-- ⭐⭐ RULING 6 (15-Sep-2026): "and is the complaint fixed?" — asked, never assumed.
           A visit being done does NOT close a ticket by itself: only a manager closes one, and
           the RIDER can mark a visit done. So the question is put to the manager at the moment
           he is already deciding what happened, and it costs one tap.
           ⚠ Filled in by flWorkshopDone(); EMPTY for anyone who cannot close tickets, and empty
             when the machine has nothing open. --}}
      <div id="flWsDoneIssues" style="display:none;"></div>

      <div id="flWsDoneErr" style="display:none;font-size:12px;color:#b91c1c;background:#fef2f2;
           border:1px solid #fecaca;border-radius:8px;padding:7px 9px;margin-top:9px;"></div>

      <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px;">
        <button type="button" class="fl-vbtn" onclick="flWsDoneClose()">Cancel</button>
        <button type="button" id="flWsDoneSubmit" onclick="flWsDoneSave()" class="fl-vbtn"
                style="background:#111827;color:#fff;border-color:#111827;">Mark done</button>
      </div>
    </div>
  </div>
</div>

<div id="flvEditModal" onclick="if(event.target===this)flvCloseEdit()"
     style="display:none;position:fixed;inset:0;z-index:4200;background:rgba(0,0,0,.5);
            align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:12px;width:100%;max-width:500px;max-height:90vh;
              overflow-y:auto;box-shadow:0 18px 60px rgba(0,0,0,.35);">
    <div style="display:flex;align-items:center;gap:9px;padding:14px 18px;border-bottom:1px solid #e5e7eb;">
      <b id="flvEditTitle" style="font-size:15px;color:#111827;">Add a vehicle</b>
      <button type="button" onclick="flvCloseEdit()" title="Close"
              style="margin-left:auto;border:none;background:none;font-size:24px;color:#9ca3af;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div style="padding:14px 18px;">
      <input type="hidden" id="flvEditId">
      <div style="display:flex;gap:9px;flex-wrap:wrap;">
        <div style="flex:1;min-width:120px;">
          <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Kind</label>
          <select id="flvEditType" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
            <option value="bike">🏍️ Bike</option>
            <option value="van">🚚 Van</option>
          </select>
        </div>
        <div style="flex:1;min-width:130px;">
          <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Plate</label>
          <input id="flvEditReg" type="text" maxlength="32" placeholder="e.g. AY-4771"
                 style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
        </div>
        <div style="flex:1;min-width:130px;">
          <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Name <span style="font-weight:400;color:#9ca3af;">(if no plate)</span></label>
          <input id="flvEditNick" type="text" maxlength="64" placeholder="e.g. Van"
                 style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
        </div>
      </div>
      <div style="display:flex;gap:9px;flex-wrap:wrap;margin-top:9px;">
        <div style="flex:2;min-width:150px;">
          <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Make / model</label>
          <input id="flvEditModelName" type="text" maxlength="64" placeholder="e.g. Honda CG125"
                 style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
        </div>
      </div>
      {{-- ⚠⚠ "Service every (km)" WAS HERE and is gone (Sep-2026). It was the third
           writer of the single-number scalar that named no job, so a manager could set
           it on this form and watch nothing change on any screen. The schedule is per
           job now, on its own editor — reachable from this machine's panel. --}}
      <div style="margin-top:9px;font-size:11.5px;color:#6b7280;background:#f9fafb;
                  border:1px solid #e5e7eb;border-radius:8px;padding:8px 10px;line-height:1.5;">
        🔧 Service schedules are set per job under <b>⚙️ This vehicle&rsquo;s schedule</b> on the
        machine&rsquo;s own panel. The company standard for every job lives under <b>⚙️ Types</b>.
      </div>
      <label style="display:block;margin-top:9px;font-size:12.5px;color:#374151;">
        <input type="checkbox" id="flvEditCompany" checked> The company buys the fuel for this one
      </label>
      <label style="display:block;margin-top:5px;font-size:12.5px;color:#374151;">
        <input type="checkbox" id="flvEditActive" checked> In service
      </label>

      {{-- The base. A bike left blank behaves exactly as today: it sleeps wherever its
           rider does, measured against that rider's own home pin. Only a machine with a
           home of its own (the van's parking) needs a base here. --}}
      <div style="margin-top:12px;padding-top:11px;border-top:1px solid #e5e7eb;">
        <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">
          📍 Where it is parked overnight <span style="font-weight:400;color:#9ca3af;">(leave blank for a bike)</span>
        </label>
        <div style="font-size:11.5px;color:#6b7280;margin-bottom:6px;line-height:1.45;">
          Blank means it sleeps wherever its rider does, and the morning and overnight meter
          checks use <b>that rider's own home</b> — which is what every bike should do.
          Set a point only for a machine with a fixed home of its own, like the van's parking.
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <input id="flvEditLat" type="text" placeholder="latitude"
                 style="flex:1;min-width:110px;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
          <input id="flvEditLng" type="text" placeholder="longitude"
                 style="flex:1;min-width:110px;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
          <input id="flvEditRadius" type="number" min="50" max="5000" step="50" placeholder="radius m"
                 style="width:110px;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">
        </div>
      </div>

      <div id="flvEditError" style="display:none;font-size:12px;color:#b91c1c;background:#fef2f2;
           border:1px solid #fecaca;border-radius:8px;padding:7px 9px;margin-top:9px;"></div>

      <div style="display:flex;gap:8px;margin-top:12px;">
        <button type="button" onclick="flvSaveVehicle()" id="flvEditSave"
                style="padding:9px 16px;border:none;border-radius:8px;background:#f59e0b;color:#fff;
                       font-size:13px;font-weight:700;cursor:pointer;">Save</button>
        <button type="button" onclick="flvCloseEdit()"
                style="padding:9px 16px;border:1px solid #d1d5db;background:#fff;border-radius:8px;
                       font-size:13px;font-weight:600;color:#374151;cursor:pointer;">Cancel</button>
      </div>
    </div>
  </div>
</div>

{{-- ⚙️⚙️ THIS VEHICLE'S SCHEDULE — the per-(vehicle, job) editor (Sep-2026).

     ⚠⚠ WHAT THIS REPLACES. A `window.prompt` asking "Service this bike every how many
        km?" — ONE number, for a machine, naming no job. It could not work: the engine
        had to guess which countdown the number meant, it guessed "the shortest
        clock-resetting type", and that guess MOVED when an unrelated checkbox changed.
        Since the Aug-27 resolver it changed nothing on any screen at all.

     ⭐ The two levels the owner asked for: the COMPANY STANDARD per job per class
       (⚙️ Types), and this — the exceptions, one row per job, blank = follow the
       standard. Opened from the machine, because that is what it is about. --}}
<div id="flvSchedModal" onclick="if(event.target===this)flvCloseSchedule()"
     style="display:none;position:fixed;inset:0;z-index:4300;background:rgba(0,0,0,.5);
            align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:12px;width:100%;max-width:620px;max-height:90vh;
              display:flex;flex-direction:column;box-shadow:0 18px 60px rgba(0,0,0,.35);">
    <div style="display:flex;align-items:center;gap:9px;padding:14px 18px;border-bottom:1px solid #e5e7eb;flex:0 0 auto;">
      <b id="flvSchedTitle" style="font-size:15px;color:#111827;">⚙️ This vehicle&rsquo;s schedule</b>
      <button type="button" onclick="flvCloseSchedule()" title="Close"
              style="margin-left:auto;border:none;background:none;font-size:24px;color:#9ca3af;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div id="flvSchedBody" style="padding:14px 18px;overflow-y:auto;flex:1 1 auto;min-height:0;font-size:13px;">
      Loading&hellip;
    </div>
    <div id="flvSchedError" style="display:none;font-size:12px;color:#b91c1c;background:#fef2f2;
         border:1px solid #fecaca;border-radius:8px;padding:8px 10px;margin:0 18px 10px;"></div>
    <div id="flvSchedFoot" style="padding:12px 18px;border-top:1px solid #e5e7eb;background:#f9fafb;display:flex;
                gap:8px;justify-content:flex-end;flex:0 0 auto;">
      <button type="button" onclick="flvCloseSchedule()"
              style="padding:9px 16px;border:1px solid #d1d5db;background:#fff;border-radius:8px;
                     font-size:13px;font-weight:600;color:#374151;cursor:pointer;">Cancel</button>
      <button type="button" id="flvSchedSave" onclick="flvSaveSchedule()"
              style="padding:9px 18px;border:none;background:#4f46e5;color:#fff;border-radius:8px;
                     font-size:13px;font-weight:700;cursor:pointer;">Save schedule</button>
    </div>
  </div>
</div>

<div id="flNewModal" onclick="if(event.target===this)flCloseNew()"
     style="display:none;position:fixed;inset:0;z-index:4200;background:rgba(0,0,0,.5);
            align-items:center;justify-content:center;padding:16px;">
  <div style="background:#fff;border-radius:12px;width:100%;max-width:440px;max-height:90vh;
              overflow-y:auto;box-shadow:0 18px 60px rgba(0,0,0,.35);">
    <div style="display:flex;align-items:center;gap:9px;padding:14px 18px;border-bottom:1px solid #e5e7eb;">
      <b id="flNewTitle" style="font-size:15px;color:#111827;">⛽ New petrol request</b>
      <button type="button" onclick="flCloseNew()" title="Close"
              style="margin-left:auto;border:none;background:none;font-size:24px;color:#9ca3af;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div style="padding:16px 18px;display:flex;flex-direction:column;gap:12px;">

      <div>
        <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Rider</label>
        <select id="flNewRider" onchange="flNewRiderChanged()"
                style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;"></select>
        <div id="flNewBikeHint" style="font-size:11px;color:#6b7280;margin-top:4px;"></div>
        {{-- ⭐ Which machine this claim lands on, from the registry, for the claim's
             own date — so nobody files against the wrong bike after a handover. --}}
        <div id="flNewWhichBike" style="display:none;font-size:11.5px;color:#0f766e;margin-top:4px;"></div>
      </div>

      {{-- ⭐⭐ WHICH VEHICLE — the picker (Aug-27 2026).
           A rider can put kilometres on TWO machines in one day: he arrives on his own
           bike and takes the van out mid-shift. The form named one machine and offered
           no choice, so his own bike's kilometres — the ones he is actually owed for —
           could not be filed from here at all. Each chip is a machine he really rode
           that date, with its distance and whether it has already been claimed, served
           from the SAME engine the rider's phone reads. --}}
      <div id="flNewVehWrap" style="display:none;">
        <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">
          Which vehicle <span style="font-weight:500;color:#6b7280;">— what he rode that day</span>
        </label>
        <div id="flNewVehChips" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
        <div id="flNewVehNote" style="font-size:11.5px;margin-top:6px;line-height:1.5;"></div>
      </div>

      {{-- ⭐ WHAT IS ALREADY ON RECORD FOR THIS BIKE (owner ask, Aug-16).
           "So they know what they are entering and what is already there, when, and
           by whom." Maintenance only — a petrol claim has its own since-last-fill
           line and this would just be noise on it. Filled by flNewWhichBike() from
           the same response that names the machine, so it can never disagree with
           the label directly above it. --}}
      <div id="flNewLastMaint" style="display:none;border:1px solid #e5e7eb;border-radius:8px;
           background:#f9fafb;padding:9px 11px;font-size:11.5px;line-height:1.55;"></div>

      {{-- 🔧 What was done. Populated from the manager's own maintenance types
           (grouped Regular / Repair). Falls back to the original two-option list
           when the types table is not there yet, so the form still works if the
           web files are uploaded before SQL batch 12 runs. --}}
      <div id="flNewSvcWrap" style="display:none;">
        <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">What was done</label>
        <select id="flNewServiceType" onchange="flNewSvcChanged()"
                style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;">
          <option value="">Not a bike / other maintenance</option>
          <option value="oil_change">🛢️ Regular service (oil change / tuning)</option>
          <option value="repair">🔧 Repair (anything broken)</option>
        </select>
        <div id="flNewSvcDue" style="font-size:11px;color:#6b7280;margin-top:4px;"></div>
      </div>

      <div style="display:flex;gap:10px;">
        <div style="flex:1;">
          <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Amount (Rs)</label>
          <input id="flNewAmount" type="number" min="1" step="0.01" placeholder="e.g. 1000"
                 style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;">
        </div>
        <div style="flex:1;">
          <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Date</label>
          {{-- Changing the date can change the bike: a backdated claim belongs to
               whoever's machine it was THAT day. --}}
          <input id="flNewDate" type="date" onchange="flNewWhichBike()"
                 style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;">
        </div>
      </div>

      {{-- 💳 Where the money actually comes from. Until Aug-2026 this form sent
           nothing, so every claim silently defaulted to the Expense Fund at
           posting time even when a manager had paid cash out of his own till.
           Options come from PaymentSourceService — the same rules the submit
           endpoint enforces, so nothing offered here can be rejected. --}}
      <div id="flNewPayWrap" style="display:none;">
        <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">💳 Paid from</label>
        <select id="flNewPaySource" onchange="flNewPayChanged()"
                style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;"></select>
        <div id="flNewPayHint" style="font-size:11px;color:#6b7280;margin-top:4px;"></div>
      </div>

      {{-- Only for a bank source: which of OUR banks the money left, so the
           per-bank balances stay right. Mandatory server-side. --}}
      <div id="flNewBankWrap" style="display:none;">
        <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">
          🏦 From which bank <span style="font-weight:500;color:#b45309;">(required)</span>
        </label>
        <div id="flNewBankChips" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
      </div>

      <div id="flNewMeterWrap">
        <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">
          Bike meter reading <span id="flNewMeterReq" style="font-weight:500;color:#b45309;"></span>
        </label>
        <input id="flNewMeter" type="number" min="0" max="9999999" step="1" placeholder="Odometer (km)"
               oninput="flNewMeterTyped()"
               style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;">
        {{-- Live "that's N km since his last fill" — the same number the approver
             sees on the claim later, shown while the amount is still being typed. --}}
        <div id="flNewSince" style="font-size:11.5px;font-weight:700;color:#3730a3;margin-top:5px;display:none;"></div>
        <div id="flNewMeterHint" style="font-size:11px;color:#6b7280;margin-top:4px;"></div>
      </div>

      <div>
        <label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin-bottom:4px;">Note (optional)</label>
        <input id="flNewNote" type="text" maxlength="200" placeholder="e.g. filled at Shell"
               style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:8px 10px;font-size:13px;">
      </div>

      <div id="flNewError" style="display:none;font-size:12px;color:#b91c1c;background:#fef2f2;
           border:1px solid #fecaca;border-radius:8px;padding:8px 10px;"></div>
      {{-- ✏️ Doubles as the EDIT form: same fields, different verb. A separate
           modal would have meant two copies of the meter/type/date rules. --}}
      <div id="flNewEditNote" style="display:none;font-size:11.5px;color:#3730a3;background:#eef2ff;
           border:1px solid #c7d2fe;border-radius:8px;padding:8px 10px;"></div>
      {{-- 🧾 "This bill is for the service already recorded on …" (owner ruling, 3-Sep).
           ⭐ Shown only when the manager arrived from an un-billed row. The odometer, the job
             and the date are INHERITED from that reading by the server, so he types the amount
             and attaches the receipt and nothing else — the whole point of the ruling. --}}
      <div id="flNewSvcNote" style="display:none;font-size:11.5px;color:#065f46;background:#ecfdf5;
           border:1px solid #a7f3d0;border-radius:8px;padding:8px 10px;"></div>
    </div>
    <div style="display:flex;gap:8px;padding:12px 18px;border-top:1px solid #e5e7eb;">
      <button type="button" onclick="flCloseNew()"
              style="flex:1;padding:9px;border:1px solid #d1d5db;background:#fff;border-radius:8px;
                     font-size:13px;font-weight:600;color:#374151;cursor:pointer;">Cancel</button>
      <button type="button" id="flNewSubmit" onclick="flSubmitNew()"
              style="flex:2;padding:9px;border:none;background:#f59e0b;color:#fff;border-radius:8px;
                     font-size:13px;font-weight:700;cursor:pointer;">Create request</button>
    </div>
  </div>
</div>

{{-- 🏠 HOME LOCATION EDITOR (Sep-2026).
     Opened from a vehicle card — from the amber nag when there is no pin, and from the
     green "change" link when there is. It posts to the SAME endpoint the phone posts to
     (VehicleController::homePinSave → RiderHomePinService), so a location saved here and
     one saved on the phone are the same write, resolved by the same parser.
     Everything it displays comes back from the server after the write, so the manager
     always sees what was actually STORED rather than what he typed. --}}
<div id="flHomePinModal" class="fl-hpwrap" onclick="if(event.target===this)flCloseHomePin()">
  <div class="fl-hpbox">
    <div class="fl-hphead">
      <div style="font-size:15px;font-weight:700;color:#111827;">🏠 Home location — <span id="flHpName">rider</span></div>
      <button type="button" onclick="flCloseHomePin()"
              style="border:0;background:none;font-size:22px;line-height:1;color:#6b7280;cursor:pointer;">&times;</button>
    </div>
    <div class="fl-hpbody">
      <div id="flHpCurrent" style="font-size:12.5px;color:#047857;margin-bottom:12px;"></div>

      <label style="display:block;font-size:12px;font-weight:600;color:#065F46;margin-bottom:5px;">
        Paste the Google Maps link the rider sent
      </label>
      <input type="text" id="flHpText" class="fl-hpinput"
             placeholder="https://maps.app.goo.gl/… — or a Plus Code, or 33.6402495, 73.1109461">
      <p style="font-size:11px;color:#6b7280;margin:6px 0 10px;">
        Short links, place links and Plus Codes (like <code>P35Q+5FF</code>) are all read here.
        Or type the coordinates instead:
      </p>
      <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <input type="number" step="any" id="flHpLat" class="fl-hpcoord" placeholder="Latitude">
        <input type="number" step="any" id="flHpLng" class="fl-hpcoord" placeholder="Longitude">
        <input type="number" id="flHpRadius" class="fl-hpcoord" min="30" step="10" placeholder="radius 300 m">
      </div>

      <div id="flHpMsg" class="fl-hpmsg"></div>

      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:16px;">
        <button type="button" id="flHpDelete" class="fl-hpdel" onclick="flClearHomePin()"
                style="display:none;">🗑 Remove</button>
        <div style="flex:1;"></div>
        <button type="button" onclick="flCloseHomePin()"
                style="padding:8px 14px;border:1px solid #d1d5db;background:#fff;border-radius:8px;
                       font-size:13px;font-weight:600;color:#374151;cursor:pointer;">Cancel</button>
        <button type="button" id="flHpSave" class="fl-hpsave" onclick="flSaveHomePin()">Save location</button>
      </div>

      <div id="flHpHistory" class="fl-hphist"></div>
    </div>
  </div>
</div>

<script>
// =============================================
// FLEET & FUEL — state
// =============================================
let flMonth = null;
let flData = null;
// The "expense" request category, needed to file a claim from the inline modal.
// Null if that category is ever missing — the buttons then explain rather than
// posting something the server would reject.
const flExpenseCategoryId = {{ $expenseCategoryId ?? 'null' }};
let flSelected = null;
let flInitDone = false;
let flApproval = null;    // what THIS user may approve + the payment sources
let flRider = null;       // the rider currently open in the drawer (for editing a claim)
let flCanManageService = false;   // may this user CHANGE service schedules?
let flDefaultInterval = 0;        // company-wide km between regular services

const FL_BASE = '/orders/riders-map/fleet';

function flInit() {
    if (!flInitDone) {
        const now = new Date();
        const m = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
        const inp = document.getElementById('flMonthInput');
        inp.value = m;
        inp.max = m;
        flMonth = m;
        flInitDone = true;
        /**
         * 🛠 The Issues tab's red count, asked for ONCE when Bikes first renders (15-Sep, later).
         *    Without this the badge stayed 0 until the tab had been opened — the one moment it
         *    could not help. Counts only, so nothing about the costs view waits on it.
         */
        if (typeof flIssLoadStripOnly === 'function') flIssLoadStripOnly();
    }
    flLoad(flMonth);
}

function flShiftMonth(delta) {
    const cur = document.getElementById('flMonthInput').value;
    const [y, m] = cur.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    const now = new Date();
    let next = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
    const max = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
    if (next > max) next = max;
    document.getElementById('flMonthInput').value = next;
    flLoad(next);
}

// `fresh` skips the 2-minute server cache — used right after creating a claim
// here, so the money and the "N to approve" counts move immediately.
function flLoad(month, fresh) {
    flMonth = month;
    flCloseDetail();
    document.getElementById('flBody').innerHTML = '<tr><td colspan="12" class="fl-empty">Loading…</td></tr>';

    fetch(FL_BASE + '?month=' + encodeURIComponent(month) + (fresh ? '&fresh=1' : ''))
        .then(r => r.status === 403 ? Promise.reject(new Error('403')) : r.json())
        .then(res => {
            if (!res.success) throw new Error(res.message || 'Failed');
            flData = res;
            // The ⚙️ Types button belongs to whoever may change service schedules.
            const tb = document.getElementById('flTypesBtn');
            if (tb) tb.style.display = res.can_manage_types ? '' : 'none';
            flRenderTable(res);
            flRenderVerdict(res.totals);
            flRenderNotes(res);
            /**
             * ⚠ Seen in the browser pane (15-Sep): the month payload can land AFTER the user has
             *   already switched to Vehicles or Issues, and the two renders above then paint the
             *   costs verdict and notes OVER the other view. Re-apply the mode's own hiding once
             *   the late render is done. Cheap, and a no-op on the Riders view.
             */
            if (typeof flvMode !== 'undefined' && flvMode !== 'riders') {
                ['flMonthWrap', 'flHeadline', 'flVerdict', 'flNotes', 'flDetail'].forEach(id => {
                    const el = document.getElementById(id);
                    if (el) { el.dataset.flPrevDisplay = el.style.display || ''; el.style.display = 'none'; }
                });
            }
        })
        .catch(err => {
            const msg = err.message === '403'
                ? 'You do not have permission to see fleet costs.'
                : 'Could not load this month. Please try again.';
            document.getElementById('flBody').innerHTML =
                '<tr><td colspan="12" class="fl-empty">' + msg + '</td></tr>';
            document.getElementById('flVerdict').style.display = 'none';
            document.getElementById('flNotes').style.display = 'none';
        });
}

function flRenderTable(res) {
    const rows = res.riders || [];
    if (!rows.length) {
        document.getElementById('flBody').innerHTML =
            '<tr><td colspan="12" class="fl-empty">No fuel or distance recorded this month.</td></tr>';
        document.getElementById('flHeadline').innerHTML = '';
        return;
    }

    document.getElementById('flBody').innerHTML = rows.map(r => {
        const flags = [];
        // New requests waiting on someone — the reason to open this rider today.
        if (r.pending_count) flags.push('<span class="fl-pill fl-due" title="Requests waiting for approval">⏳ ' + r.pending_count + ' to approve</span>');
        if (r.dupe_flags) flags.push('<span class="fl-pill fl-warn" title="Possible duplicate claims">⚠ ' + r.dupe_flags + '</span>');
        if (r.early_service_count) flags.push('<span class="fl-pill fl-warn" title="Regular service done before the schedule was up">⏱ ' + r.early_service_count + ' early</span>');
        if (r.no_meter_days) flags.push('<span class="fl-pill fl-na" title="Days worked with no usable meter reading">' + r.no_meter_days + ' no-meter</span>');
        // Checked in on a past day and never checked out. Kept as "in progress" on
        // purpose — the team has to go and close it — but it must be VISIBLE, or a
        // day with deliveries and no end meter just disappears from every count.
        if (r.open_days) flags.push('<span class="fl-pill fl-warn" title="Checked in on a past day and never checked out — still open. Someone needs to close the day so its kilometres can be counted.">🔓 ' + r.open_days + ' still open</span>');
        if (r.bike === 'unknown') flags.push('<span class="fl-pill fl-unknown" title="No rider profile — cannot classify the bike">unclassified</span>');

        /**
         * 🏠 HOME LOCATION — on the RIDERS row, where the owner asked for it ("in the
         * fleet we have riders and vehicles… it should be with riders in the fleet").
         *
         * Shown for EVERY rider, not just company-bike ones: the pin is kept whatever
         * he happens to be riding this week, and hiding it for an 'own' row is exactly
         * the mistake that used to lose pins. Green = on file (hover for the coordinates
         * and who set it), amber = missing.
         *
         * ⚠ stopPropagation — the whole <tr> has an onclick that opens the rider's month.
         * ⚠ Only the numeric user_id crosses the onclick boundary. A quoted string inside
         *   an onclick="" attribute is the trap that silently kills the button.
         */
        if (r.home_pin) {
            flags.push('<span class="fl-pill fl-home" title="Home location: '
                + r.home_pin.lat.toFixed(5) + ', ' + r.home_pin.lng.toFixed(5)
                + (r.home_pin.set_by_name ? ' · set by ' + flEsc(r.home_pin.set_by_name) : '')
                + (r.home_pin.set_at ? ' on ' + flEsc(String(r.home_pin.set_at).slice(0, 10)) : '')
                + '" onclick="event.stopPropagation();flOpenHomePin(' + Number(r.user_id) + ')">🏠 home</span>');
        } else {
            flags.push('<span class="fl-pill fl-warn" title="No home location — the overnight and morning meter checks have nowhere to measure from"'
                + ' onclick="event.stopPropagation();flOpenHomePin(' + Number(r.user_id) + ')">🏠 set home</span>');
        }

        return '<tr onclick="flSelectRider(' + r.user_id + ')" id="flRow' + r.user_id + '">' +
            '<td class="fl-name">' + flEsc(r.name) + '</td>' +
            '<td>' + flBikePill(r) + '</td>' +
            '<td class="num">' + flNum(r.work_km) + '</td>' +
            '<td class="num">' + (r.offduty_km === null ? '<span class="fl-muted">—</span>' : flNum(r.offduty_km)) +
              // Km inside a stretch that contains a worked-but-unmetered day. Shown
              // right here so the commute figure is never read as including them.
              // ⚠ `unaccounted_km` is the PURE figure; `unattributed_km` also carries
              // shared + transit for older mobile builds — never show that one here
              // or the same kilometres appear in two columns.
              (r.unaccounted_km > 0
                ? '<div class="fl-maintsplit" style="color:#b45309;" title="Kilometres across a stretch that contains a day he worked with no usable meter — part work, part commute, and impossible to split. Not counted as either.">+' + flNum(r.unaccounted_km) + ' unaccounted</div>'
                : '') + '</td>' +
            // ⭐ Shared / transit — named, and charged to no one.
            '<td class="num">' + flSharedCell(r) + '</td>' +
            '<td class="num fl-strong">' + (r.fuelled_km > 0 ? flNum(r.fuelled_km) : '<span class="fl-muted">—</span>') + '</td>' +
            '<td class="num">' + flRs(r.fuel_rs) + (r.fuel_pending_rs > 0 ? ' <span class="fl-muted" title="Pending approval">+' + flRs(r.fuel_pending_rs) + '</span>' : '') + '</td>' +
            '<td class="num fl-strong">' + (r.rs_per_fuelled_km === null || r.rs_per_fuelled_km === undefined ? '<span class="fl-muted">—</span>' : r.rs_per_fuelled_km.toFixed(2)) + '</td>' +
            // Maintenance split by what was done — a repair bill and a scheduled
            // service are different stories and shouldn't sit in one number.
            '<td class="num">' + (r.maint_rs > 0
                ? flRs(r.maint_rs) + '<div class="fl-maintsplit">' +
                  (r.maint_regular_rs > 0 ? '🛢️ ' + flNum(r.maint_regular_rs) : '') +
                  (r.maint_repair_rs > 0 ? (r.maint_regular_rs > 0 ? ' · ' : '') + '🔧 ' + flNum(r.maint_repair_rs) : '') +
                  (r.maint_other_rs > 0 ? ' · 🔩 ' + flNum(r.maint_other_rs) : '') + '</div>'
                : '<span class="fl-muted">—</span>') + '</td>' +
            // Same denominator as the Rs/km beside it — see rs_per_fuelled_km_all.
            '<td class="num fl-strong">' + (r.rs_per_fuelled_km_all === null || r.rs_per_fuelled_km_all === undefined ? '<span class="fl-muted">—</span>' : r.rs_per_fuelled_km_all.toFixed(2)) + '</td>' +
            '<td>' + flServicePill(r.service) + '</td>' +
            '<td>' + flags.join(' ') + '</td></tr>';
    }).join('');

    const t = res.totals;
    const pending = rows.reduce((s, r) => s + (r.pending_count || 0), 0);
    document.getElementById('flHeadline').innerHTML =
        'Fuel <b>Rs ' + flNum(t.fuel_rs) + '</b> · Maintenance <b>Rs ' + flNum(t.maint_rs) + '</b>' +
        (pending ? ' · <b>⏳ ' + pending + '</b> waiting for approval' : '') +
        (t.dupe_flags ? ' · <b>⚠ ' + t.dupe_flags + '</b> possible duplicate claims' : '');
}

/**
 * ⭐⭐ THE MACHINE, NOT JUST THE KIND OF MACHINE (Aug-2026).
 *
 * This used to render a bare 🏢/👤 from a checkbox on the rider's profile, so a
 * manager could not tell WHICH bike anyone was on — the single biggest gap on this
 * screen. Now it names the plate he holds right now, keeps the company/own colour
 * for the at-a-glance read, and says "+N" when the month touched more machines.
 *
 * ⚠ Riders the registry has never tracked have no plate to show and fall back to
 *   the original pill exactly, so nothing regresses for them.
 */
function flBikePill(r) {
    const b = (typeof r === 'string') ? r : r.bike;
    const label = (typeof r === 'string') ? null : r.vehicle_label;
    const cls = b === 'company' ? 'fl-company' : (b === 'own' ? 'fl-own' : 'fl-unknown');

    if (!label) {
        if (b === 'company') return '<span class="fl-pill fl-company">🏢 company</span>';
        if (b === 'own') return '<span class="fl-pill fl-own">👤 own</span>';
        return '<span class="fl-pill fl-unknown">❓ unknown</span>';
    }

    // holds_now === false: he rode it this month but has since handed it back, so
    // the plate is history. Saying so beats implying he is still on it.
    const returned = r.holds_now === false;
    const icon = b === 'company' ? '🏍' : '👤';
    let out = '<span class="fl-pill ' + cls + '"' +
        (returned ? ' title="He rode this bike this month but does not hold it now"' : '') +
        '>' + icon + ' ' + flEsc(label) + (returned ? ' ·&nbsp;handed back' : '') + '</span>';

    const extra = (r.machine_count || 0) - 1;
    if (extra > 0) {
        const others = (r.machines || []).filter(m => m.label !== label)
            .map(m => m.label + ' (' + flNum(m.km_with_him) + ' km)').join(', ');
        out += ' <span class="fl-pill fl-na" title="Also this month: ' + flEsc(others) + '">+' + extra + '</span>';
    }
    return out;
}

/**
 * Kilometres that belong to nobody. Two different stories, so two lines:
 * the handover DAY (shared, and we name who with) and the bike's own travel
 * between two riders (transit).
 */
function flSharedCell(r) {
    const bits = [];
    if (r.shared_km) {
        bits.push('<span class="fl-pill fl-shared" title="Days this bike changed hands: one rider took the morning reading, the other the evening one. The distance is real, the split is not knowable — so it is charged to neither of them.">🔁 ' +
            flNum(r.shared_km) + ' shared</span>');
    }
    if (r.transfer_km) {
        bits.push('<div class="fl-maintsplit" style="color:#0f766e;" title="The bike travelling between two riders. Nobody\'s personal usage.">+' +
            flNum(r.transfer_km) + ' in transit</div>');
    }
    if (!bits.length) return '<span class="fl-muted">—</span>';
    return bits.join('');
}

function flServicePill(s) {
    /* ⭐ Sep-2026: a personal machine is not "not set" — nothing is missing, the company
         simply does not keep its schedule. Saying "not set" invited someone to go and
         set it, which is the opposite of the ruling. */
    if (s && s.state === 'not_tracked') {
        return '<span class="fl-pill fl-na" title="Own bikes are the rider’s own affair — '
             + 'the company keeps no service schedule for them">👤 personal</span>';
    }
    if (!s || s.state === 'unknown') {
        return '<span class="fl-pill fl-na" title="No last-service reading recorded yet">not set</span>';
    }
    /* The sentence is the server's, so km and time jobs read in their own unit. */
    const txt = s.due_text || null;
    if (s.state === 'overdue') {
        return '<span class="fl-pill fl-over">🔴 ' + flEsc(txt || ('overdue ' + flNum(Math.abs(s.due_in_km)) + ' km')) + '</span>';
    }
    if (s.state === 'due_soon') {
        return '<span class="fl-pill fl-due">🟡 ' + flEsc(txt || ('due in ' + flNum(s.due_in_km) + ' km')) + '</span>';
    }
    return '<span class="fl-pill fl-ok">🟢 ' + flEsc(txt || (flNum(s.due_in_km) + ' km left')) + '</span>';
}

/**
 * The comparison, as a compact grid rather than a paragraph (owner, Jul-28).
 * Two rows, three numbers each: kilometres ridden, fuel per km, and everything-in
 * per km. The all-in column is the one to decide on — it carries the maintenance.
 */
function flRenderVerdict(t) {
    const el = document.getElementById('flVerdict');
    const c = t.company, o = t.own;
    if (!c || !o || !c.rs_per_fuelled_km || !o.rs_per_fuelled_km) {
        el.style.display = 'none';
        return;
    }

    // Decided on ALL-IN cost per km ridden — fuel alone ignores that a company
    // bike also brings its own repair bill.
    const ca = c.rs_per_fuelled_km_all, oa = o.rs_per_fuelled_km_all;
    const diff = ca - oa;
    const pct = oa > 0 ? Math.abs(diff) / oa * 100 : 0;
    const close = pct < 8;

    const row = (icon, label, riders, km, fuelRate, allRate, winner) =>
        '<tr>' +
        '<td>' + icon + ' ' + label + ' <span class="dim">(' + riders + ')</span></td>' +
        '<td>' + flNum(km) + '</td>' +
        '<td>' + (fuelRate === null ? '—' : fuelRate.toFixed(2)) + '</td>' +
        '<td class="big"' + (winner ? '' : ' style="opacity:.6;"') + '>' + (allRate === null ? '—' : allRate.toFixed(2)) + '</td>' +
        '</tr>';

    let s = '<table class="fl-cmp">' +
        '<tr><th>Bike</th><th>Km ridden</th><th>Fuel Rs/km</th><th>All-in Rs/km</th></tr>' +
        row('🏢', 'Company', c.riders, c.fuelled_km, c.rs_per_fuelled_km, ca, close || diff < 0) +
        row('👤', 'Own', o.riders, o.fuelled_km, o.rs_per_fuelled_km, oa, close || diff > 0) +
        '</table>';

    s += '<div class="fl-cmpwin">' + (close
        ? 'Within ' + pct.toFixed(0) + '% — too close to call this month.'
        : (diff > 0
            ? 'Own bikes cost Rs ' + Math.abs(diff).toFixed(2) + '/km less, all in (' + pct.toFixed(0) + '%).'
            : 'Company bikes cost Rs ' + Math.abs(diff).toFixed(2) + '/km less, all in (' + pct.toFixed(0) + '%).')) +
        '</div>';

    // ONE short line, not a paragraph. It still has to be said: a company bike's
    // km include the commute we fuel, and its all-in still leaves out the machine.
    s += '<div class="fl-cmpfoot">Company km include the commute you fuel; own-bike riders fund their own. ' +
         'All-in adds maintenance, still not the bike itself.</div>';

    el.className = 'fl-verdict' + (close ? ' tie' : '');
    el.innerHTML = s;
    el.style.display = 'block';
}

function flRenderNotes(res) {
    const el = document.getElementById('flNotes');
    const t = res.totals;
    const notes = [];
    if (t.unattributed_rs > 0) {
        notes.push('<b>Rs ' + flNum(t.unattributed_rs) + '</b> of fuel and maintenance could not be tied to any ' +
            'kilometres (no usable meter readings)' +
            (t.unattributed_who && t.unattributed_who.length ? ': ' + t.unattributed_who.map(flEsc).join(', ') : '') +
            '. It is excluded from every Rs/km figure above.');
    }
    // Moved out of the comparison box to keep that box to figures only. Still has
    // to be stated: these km are neither work nor commute, so no rate claims them.
    // ⭐ Aug-2026: this used to be one lump. Most of it turned out to be handover
    //   days and bikes in transit — km with a perfectly good explanation — so the
    //   two are now counted and worded separately instead of reading as suspicion.
    // ⚠ From the TOTALS, not by summing rider rows — a shared leg is named on both
    //   riders on purpose, so adding the rows up counts every such kilometre twice.
    const shared = (t.shared_km || 0) + (t.transfer_km || 0);
    const unattKm = (t.company && t.company.unattributed_km) || 0;
    const pureUnacc = Math.max(0, unattKm - shared * 2);
    if (shared > 0) {
        notes.push('<b>' + flNum(shared) + ' km</b> ran on days a bike changed hands, or while it was ' +
            'travelling between riders. Real distance with a known reason — charged to nobody.');
    }
    if (pureUnacc > 0) {
        notes.push('<b>' + flNum(pureUnacc) + ' km</b> ran across stretches containing a day worked with no meter — ' +
            'part work, part commute, counted as neither.');
    }
    if (!notes.length) { el.style.display = 'none'; return; }
    el.innerHTML = notes.join('<br>');
    el.style.display = 'block';
}

// =============================================
// RIDER DETAIL — datewise
// =============================================
function flSelectRider(uid) {
    flSelected = uid;
    document.querySelectorAll('.fl-table tbody tr').forEach(tr => tr.classList.remove('sel'));
    const row = document.getElementById('flRow' + uid);
    if (row) row.classList.add('sel');

    const el = document.getElementById('flDetail');
    el.style.display = 'block';
    el.innerHTML = '<div class="fl-dhead"><h4>Loading…</h4></div>';

    // Which machine he was on each day rides along with the costs. Fetched in
    // parallel and deliberately FAILURE-TOLERANT: an older server (or one where
    // batch 13 has not been run) simply returns nothing and the day rows render
    // exactly as they always did.
    Promise.all([
        fetch(FL_BASE + '/rider?month=' + encodeURIComponent(flMonth) + '&rider_id=' + uid)
            .then(r => r.json()),
        fetch(FLV_BASE + '/rider-days?rider_id=' + uid + '&month=' + encodeURIComponent(flMonth))
            .then(r => r.ok ? r.json() : {days: []}).catch(() => ({days: []})),
    ])
        .then(([res, vd]) => {
            if (!res.success) throw new Error(res.message || 'Failed');
            flApproval = res.approval || null;
            flCanManageService = !!res.can_manage_service;
            flDefaultInterval = res.default_interval_km || 0;
            // Kept so the edit form can find a claim by id without another round
            // trip — the drawer already has every field it needs.
            flRider = res.rider || null;

            flvDayMap = {};
            flvDaysCanManage = !!(vd && vd.can_manage);
            ((vd && vd.days) || []).forEach(d => { flvDayMap[d.date] = d; });

            flRenderDetail(res.rider);
        })
        .catch(() => {
            el.innerHTML = '<div class="fl-dhead"><h4>Could not load this rider</h4>' +
                '<button class="fl-dclose" onclick="flCloseDetail()">&times;</button></div>';
        });
}

function flCloseDetail() {
    flSelected = null;
    const el = document.getElementById('flDetail');
    if (el) el.style.display = 'none';
    document.querySelectorAll('.fl-table tbody tr').forEach(tr => tr.classList.remove('sel'));
}

function flRenderDetail(r) {
    const days = (r.days || []).map(d => {
        // More than one machine this month = the plate is information, not noise.
        const showMachine = ((r.machines || []).length > 1);
        const claims = (d.claims || []).map(c => flClaimRow(c, showMachine)).join('');
        const today = d.machines_today || [];
        let km = '';
        if (d.work_km !== null && d.work_km !== undefined) {
            // ⚠⚠ On a TWO-MACHINE day these scalars are the LAST machine's, so printing
            //   them beside a plate that may be the other one is how DCR-799's 27,751
            //   came to sit under a CEN-455 chip. With more than one machine the
            //   readings move into the per-machine lines below and only the total
            //   distance — which is genuinely his for the day — stays up here.
            km = (today.length > 1)
                ? '<b>' + d.work_km + ' km</b>'
                : '<b>' + flNum(d.meter_start) + ' → ' + flNum(d.meter_end) + '</b> · <b>' + d.work_km + ' km</b>';
            if (d.offduty_km !== null && d.offduty_km > 0) {
                km += d.offduty_since
                    ? ' · <span title="Measured from the last usable reading, not yesterday">+' +
                      d.offduty_km + ' km off-duty since ' + flDate(d.offduty_since) + '</span>'
                    : ' · <span title="Ridden since the previous day\'s close">+' + d.offduty_km + ' km off-duty</span>';
            }
            if (d.incl_ride_home) {
                km += ' <span class="fl-muted" title="On duty runs to the meter-out at home">🏠 to home</span>';
            }
        } else if (d.handover) {
            // ⭐ A handover day is NOT a missing reading — he recorded his end of it.
            // ⚠ LABELS ARE ENGLISH (owner ruling): "meter start" / "meter end". Only
            //   the explanations are Roman Urdu, and the same two words are used on
            //   the machine's day cards so both views speak one language.
            km = (today.length > 1) ? '<span class="fl-muted">two vehicles — see below</span>'
               : '<span title="His half of a handover day — the other rider recorded the other end. Not a missed reading.">'
                + (d.meter_start !== null && d.meter_start !== undefined
                    ? 'meter start <b>' + flNum(d.meter_start) + '</b>'
                    : (d.meter_end !== null && d.meter_end !== undefined
                        ? 'meter end <b>' + flNum(d.meter_end) + '</b>'
                        : '<span class="fl-muted">bike change day</span>'))
                + '</span>';
        } else {
            // Say WHY. Leave/absent are states, not failures — only a day he
            // actually worked can be "missing" a reading.
            const cls = d.status === 'missing' ? 'fl-daymissing' : 'fl-muted';
            km = '<span class="' + cls + '">' + (FL_DAY_TEXT[d.detail] || FL_DAY_TEXT[d.status] || 'no meter reading') + '</span>';
        }

        // Kilometres nobody is charged for — short on screen, full in the tooltip.
        let extra = '';
        if (d.shared_km) {
            extra += '<div class="fl-handover" title="Bike changed hands this day: one rider took the morning reading, the other the evening one. The distance is real but cannot be split, so it is charged to neither of them.">' +
                '🔁 <b>' + flNum(d.shared_km) + ' km</b> shared' +
                (d.shared_with ? ' · ' + flEsc(d.shared_with) + ' ke saath' : '') +
                ' · <span class="fl-muted">kisi ke naam nahi</span></div>';
        }
        if (d.transfer_km) {
            extra += '<div class="fl-handover" title="The bike travelling between two riders — nobody\'s personal usage.">' +
                '🔁 <b>' + flNum(d.transfer_km) + ' km</b> transit' +
                (d.transfer_with ? ' · ' + flEsc(d.transfer_with) + ' ke saath' : '') +
                ' · <span class="fl-muted">kisi ke naam nahi</span></div>';
        }
        if (d.unattributed_km) {
            extra += '<div class="fl-handover" style="background:#fdf3e0;border-left-color:#b45309;" ' +
                'title="This stretch spans a day worked with no usable reading, so it cannot be split into work and commute. Counted as neither.">' +
                '⚠ <b>' + flNum(d.unattributed_km) + ' km</b> unaccounted · ' +
                '<span class="fl-muted">beech mein aik din meter ke baghair</span></div>';
        }

        // Whose hand wrote the reading down. ⚠ The MACHINE is deliberately NOT
        // repeated here — flvDayChip above already owns the per-day vehicle label
        // (and the manager-override affordance with it), so adding a second chip
        // printed the plate twice on every row.
        const marks = [];
        if (d.start_source === 'manager') {
            marks.push('<span class="fl-pill fl-warn" title="The morning reading was entered by a manager, not by the rider">✎ manager</span>');
        }

        // ⭐⭐ EVERY BIKE HE HELD THAT DAY, in the order he rode them.
        //
        // ⚠⚠ The chip beside the date comes from `flvDayChip`, which resolves ONE
        //    vehicle per day (open assignment wins). On a day he swapped machines that
        //    named only the last one — so a day where he rode DCR-799 all morning read
        //    simply "CEN-455", and the reading printed next to it belonged to the bike
        //    that was no longer on screen. The engine knew both all along.
        //
        // The last one is the machine he ended the day on; the earlier ones are marked
        // "handed back", the same words the riders table already uses for a bike a man
        // no longer holds. Single-machine days are untouched — they keep the original
        // chip, including its manager-correction link.
        let dayChips = flvDayChip(r.user_id, d.date);
        let perMachine = '';
        if (today.length > 1) {
            dayChips = ' ' + today.map(function (m, i) {
                const last = (i === today.length - 1);
                const tip = last
                    ? 'The machine he ended the day on'
                    : 'He was on this earlier in the day and handed it back before the day ended';
                return '<span class="fl-vdaychip" style="' + (last ? '' : 'opacity:.7;') + '" title="' + tip + '">'
                     + flOwnerIcon(m) + ' ' + flEsc(m.label)
                     + (last ? '' : ' <span style="opacity:.8;">· handed back</span>') + '</span>';
            }).join(' <span class="fl-muted">→</span> ');

            // …and each machine's OWN readings, so no number is ever shown under
            // another bike's name. A machine listed only because a claim names it
            // says so rather than showing a blank pair.
            perMachine = '<div class="fl-handover" style="background:#f8f9fb;border-left-color:#94a3b8;">'
                + today.map(function (m) {
                    let rd;
                    if (m.meter_start !== null && m.meter_end !== null) {
                        rd = flNum(m.meter_start) + ' → ' + flNum(m.meter_end)
                           + (m.work_km !== null ? ' · <b>' + flNum(m.work_km) + ' km</b>' : '');
                    } else if (m.meter_start !== null) {
                        rd = 'meter start <b>' + flNum(m.meter_start) + '</b>';
                    } else if (m.meter_end !== null) {
                        rd = 'meter end <b>' + flNum(m.meter_end) + '</b>';
                    } else if (m.from_claim) {
                        rd = '<span class="fl-muted">no reading of his — listed because a claim names it</span>';
                    } else {
                        rd = '<span class="fl-muted">no reading</span>';
                    }
                    const at = m.start_at || m.end_at;
                    return '<div>' + flOwnerIcon(m) + ' <b>' + flEsc(m.label) + '</b> · ' + rd
                         + (at ? ' <span class="fl-muted">· ' + flEsc(at) + '</span>' : '') + '</div>';
                }).join('')
                + '</div>';
        }

        return '<div class="fl-day"><div class="fl-dayhead">' +
            '<span class="fl-daydate">' + flDate(d.date) + dayChips +
              (marks.length ? ' ' + marks.join(' ') : '') + '</span>' +
            '<span class="fl-daykm">' + km + '</span></div>' + perMachine + extra + claims + '</div>';
    }).join('');

    // ⭐ HIS MACHINES THIS MONTH — the strip that answers "what was he driving?"
    //   without leaving the row. Each one opens that bike's own profile, so the
    //   machine's full story stays in one place and is never duplicated here.
    let machinesHtml = '';
    if ((r.machines || []).length) {
        machinesHtml = '<h5 style="margin-top:2px;">His machines this month</h5>' +
            r.machines.map(m => {
                const rate = (m.rs_per_km !== null && m.rs_per_km !== undefined)
                    ? 'Rs ' + m.rs_per_km.toFixed(2) + '/km' : '<span class="fl-muted">no rate</span>';
                return '<div class="fl-mrow">' +
                    '<span class="fl-pill ' + (m.is_company ? 'fl-company' : 'fl-own') + '">' +
                      flOwnerIcon(m) + ' ' + flEsc(m.label) + '</span>' +
                    '<span class="fl-mkm">' + flNum(m.km_with_him) + ' km</span>' +
                    '<span class="fl-muted">' + m.days + ' day' + (m.days === 1 ? '' : 's') +
                      (m.shared_km ? ' · 🔁 ' + flNum(m.shared_km) + ' shared' : '') + '</span>' +
                    '<span>' + (m.fuel_rs > 0 ? 'Rs ' + flNum(m.fuel_rs) + ' fuel' : '<span class="fl-muted">no fuel</span>') + '</span>' +
                    '<span>' + rate + '</span>' +
                    (m.reconciles === false
                      ? '<span class="fl-pill fl-warn" title="This bike\'s odometer chain has a hole this month — readings exist that nobody recorded a handover for. His duty kilometres still stand; the stretches between days do not.">⚠ chain incomplete</span>'
                      : '') +
                    '<span class="fl-mlink" onclick="flOpenVehicle(' + m.vehicle_id + ')">open ' + flEsc(m.label) + ' ▸</span>' +
                    '</div>';
            }).join('');
    }

    // 🛠 What this rider has REPORTED about the bike, above the service figures —
    //    "he says the brakes are going" is the context a manager needs BEFORE he reads
    //    a countdown. Loaded separately (see flLoadTickets) because tickets have their
    //    own audience rule; folding them into the Bikes payload would put two different
    //    answers to "who may know this exists" in the codebase.
    let ticketsHtml = '<div id="flWorkshop" data-uid="' + r.user_id + '"></div>'
                    + '<div id="flTickets" data-uid="' + r.user_id + '"></div>';

    const svc = r.service;
    // ⚠ These figures are ONE job's story — the most urgent scheduled one, which the
    //   server names in due_type_name — so the heading names it too. An unnamed
    //   "last done" reads as the bike's most recent visit, which can be a different,
    //   larger job on a slower clock.
    let svcHtml = '<h5>Service' + (svc && svc.due_type_name ? ' — ' + flEsc(svc.due_type_name) : '') + '</h5>';
    if (svc && svc.state !== 'unknown') {
        svcHtml += '<div class="fl-svc"><span>Status</span><span>' + flServicePill(svc) + '</span></div>' +
            '<div class="fl-svc"><span>Since last service</span><span>' + flNum(svc.since_km) + ' km</span></div>' +
            /* ⚠ A time-based job can be the summary now (29-Sep-2026) — it reports 0 km by design,
                 so its own phrase ("every 6 months") is shown instead of "0 km". */
            '<div class="fl-svc"><span>Interval</span><span>' + (svc.basis === 'time' && svc.interval_label
                ? flEsc(svc.interval_label) : flNum(svc.interval_km) + ' km') + '</span></div>' +
            '<div class="fl-svc"><span>Last done</span><span>' + (svc.last_service_at ? flDate(svc.last_service_at) : '—') + '</span></div>';
    } else {
        svcHtml += '<div class="fl-svc"><span>Status</span><span>' + flServicePill(svc) + '</span></div>' +
            '<div style="font-size:12px;color:#6b7280;margin:6px 0 8px;">Record the odometer at the last oil change to start tracking.</div>';
    }
    // Schedule controls only for someone who may CHANGE it — reading the running
    // costs never implies moving when a bike falls due.
    if (flCanManageService) {
        svcHtml += '<div style="margin-top:8px; display:flex; gap:6px; flex-wrap:wrap;">' +
            // Recording a service and changing the schedule are different things and
            // must not share a button — one resets the due clock, the other only says
            // how often one falls due.
            /* ⭐ The MACHINE goes with it (10-Sep-2026). The rider tab knows which bike this
                 panel is about, and a service record now carries it rather than being
                 re-derived from "what was he on that day" every time it is read. */
            '<button class="fl-btn" onclick="flMarkServiced(' + r.user_id + ',' +
            (svc && svc.current_meter ? svc.current_meter : 0) + ',' +
            (svc && svc.vehicle_id ? svc.vehicle_id : 0) + ')">🛢️ Record service</button>' +
            /* ⭐⭐ THE SCHEDULE LIVES ON THE MACHINE (owner ruling, 9-Sep). It was a
                 prompt here, keyed by RIDER, which is the wrong subject twice over: a
                 schedule is a fact about the vehicle, and a parked spare or a van
                 between keepers had no way to be given one at all. This opens the
                 machine's own editor rather than repeating it in a second place. */
            ((svc && svc.vehicle_id)
              ? '<button class="fl-btn" onclick="flvOpenSchedule(' + svc.vehicle_id + ','
                + flJsArg(r.bike || r.name || '') + ')">⚙️ This vehicle\'s schedule</button>'
              : '') +
            // The company standard is the TYPES list now — per job, per class — so the
            // old single fleet-wide number has no button any more.
            '<button class="fl-btn" onclick="flOpenTypes()">🏢 Company standards</button>' +
            '</div>' +
            // The rider's own maintenance request is the normal input — it carries
            // the bill and the photo, and approving it resets the clock by itself.
            // Saying so stops a manager double-recording work already filed.
            '<div style="font-size:11px;color:#9ca3af;margin-top:7px;line-height:1.45;">' +
            'Riders normally record a service by filing a Maintenance request with the meter reading — ' +
            'approving it resets this automatically. Use “Record service” only for work filed no other way.</div>';
    }

    // 🔧 Per-type schedule — each job on its own clock. This is what the manager
    // asked for: "Oil Change every 1,200" and "Brake Shoe every 10,000" are not
    // the same countdown, and one combined number hid that.
    const sched = r.service_schedule || [];
    /* ⭐ An empty schedule on a rider holding a PERSONAL machine is not a gap to fill —
         the company keeps no schedule for it (owner ruling, 10-Sep). Say so once, here,
         rather than leaving the section silently absent. */
    if (!sched.length && svc && svc.state === 'not_tracked') {
        svcHtml += '<h5 style="margin-top:14px;">Service schedule</h5>'
                 + '<div style="font-size:11.5px;color:#6b7280;line-height:1.5;">'
                 + 'This rider is on his own bike. The company does not keep its service '
                 + 'schedule, so nothing is counted down and no alerts are raised.</div>';
    }
    if (sched.length) {
        svcHtml += '<h5 style="margin-top:14px;">Service schedule</h5>' + sched.map(s => {
            const tone = s.state === 'overdue' ? '#b91c1c'
                       : (s.state === 'due_soon' ? '#b45309'
                       : (s.state === 'ok' ? '#15803d' : '#9ca3af'));
            /* ⭐ The server composes the sentence, so a time-based job reads in days.
                 The km form stays as the fallback for an older payload. */
            const left = (s.basis === 'time') ? s.due_in_days : s.due_in_km;
            const right = (left === null || left === undefined)
                ? '<span style="color:#9ca3af;">' + flEsc(s.due_text || 'never recorded') + '</span>'
                : '<b style="color:' + tone + '">' + flEsc(s.due_text
                    || (left < 0 ? flNum(Math.abs(left)) + ' km overdue'
                                 : flNum(left) + ' km left')) + '</b>';
            // ⭐ Say WHERE the interval came from when it is NOT the job's own schedule.
            //   The server has always sent this and nothing rendered it — which is how a
            //   number could differ from the one in the prompt with nothing explaining it.
            const src = (s.interval_overridden && s.interval_source_label)
                ? ' <span style="color:#b45309;" title="This number is not the job\'s own schedule">('
                  + flEsc(s.interval_source_label) + ')</span>' : '';
            return '<div class="fl-svc"><span>' + flEsc(s.name) +
                   ' <span style="color:#9ca3af;">· ' + flEsc(s.interval_label
                        || ('every ' + flNum(s.interval_km) + ' km')) + '</span>' + src + '</span>' +
                   '<span>' + right +
                   (s.last_meter !== null ? ' <span style="color:#9ca3af;">· last ' + flNum(s.last_meter) + ' km</span>' : '') +
                   '</span></div>';
        }).join('');
    }

    // Where this month's maintenance money actually went.
    const byType = r.maint_by_type || [];
    if (byType.length) {
        svcHtml += '<h5 style="margin-top:14px;">This month by type</h5>' + byType.map(x =>
            '<div class="fl-svc"><span>' + flEsc(x.label) +
            ' <span style="color:#9ca3af;">· ' + x.n + ' claim' + (x.n === 1 ? '' : 's') +
            (x.pending_n ? ', ' + x.pending_n + ' pending' : '') + '</span></span>' +
            '<span>Rs ' + flNum(x.total) + '</span></div>').join('');
    }

    const hist = r.service_history || [];   // approved + pending, filtered server-side
    if (hist.length) {
        svcHtml += '<h5 style="margin-top:14px;">Past services</h5>' + hist.map(h =>
            '<div class="fl-svc"><span>' + flDate(h.date) + (h.type ? ' · ' + flServiceLabel(h.type) : '') +
            (h.status === 'pending' ? ' <span class="fl-pill fl-due">⏳</span>' : '') + '</span>' +
            '<span>Rs ' + flNum(h.amount) + (h.photo ? ' <a href="#" onclick="flPhoto(\'' + h.photo + '\');return false;">📷</a>' : '') + '</span></div>'
        ).join('');
    }

    // The header pill uses the TABLE row, so it names the same machine the row does.
    const headRow = ((flData && flData.riders) || []).find(x => x.user_id === r.user_id) || r;

    /**
     * 🏠 HOME LOCATION, in the rider's own panel — beside his tickets and his service
     * state, which is where a manager already comes to answer "what about this man?".
     * Read from the SAME table row the pill reads, so the two can never disagree.
     * The button opens the shared editor; everything it shows comes back from the server.
     */
    const hpRow = headRow.home_pin;
    const homeHtml =
        '<h5>🏠 Home location</h5>' +
        (hpRow
            ? '<div style="font-size:12px;color:#374151;line-height:1.55;margin-bottom:8px;">' +
                '<b>' + hpRow.lat.toFixed(5) + ', ' + hpRow.lng.toFixed(5) + '</b><br>' +
                '<a href="' + flEsc(hpRow.maps_url) + '" target="_blank" rel="noopener"' +
                ' style="color:#047857;font-weight:700;">view on map ↗</a>' +
                '<div style="color:#6b7280;font-size:11px;margin-top:2px;">Set' +
                  (hpRow.set_by_name ? ' by ' + flEsc(hpRow.set_by_name) : '') +
                  (hpRow.set_at ? ' on ' + flEsc(String(hpRow.set_at).slice(0, 10)) : '') +
                  ' · geofence ' + (hpRow.radius_m || 300) + ' m</div>' +
              '</div>'
            : '<div style="font-size:12px;color:#92400e;line-height:1.5;margin-bottom:8px;">' +
                'Not set — the overnight and morning meter checks have nowhere to measure from.' +
              '</div>') +
        '<button type="button" class="fl-homebtn" onclick="flOpenHomePin(' + Number(r.user_id) + ')">' +
          (hpRow ? 'Change home location' : 'Set home location') + '</button>';

    document.getElementById('flDetail').innerHTML =
        '<div class="fl-dhead"><h4>' + flEsc(r.name) + '</h4>' + flBikePill(headRow) +
        '<span style="font-size:12px;color:#6b7280;">day by day · ' + flMonthLabel(r.month) + '</span>' +
        '<button class="fl-dclose" onclick="flCloseDetail()" title="Close">&times;</button></div>' +
        '<div class="fl-dbody"><div class="fl-days">' + flKmSummary(r) + machinesHtml +
        (days || '<div class="fl-empty">Nothing recorded this month.</div>') +
        '</div><div class="fl-side">' + homeHtml + ticketsHtml + svcHtml + '</div></div>';

    // Fetched after the drawer is painted: the costs a manager opened this for must
    // never wait on a ticket list, and an older server (no tickets table) simply
    // leaves the panel empty.
    flLoadTickets(r.user_id);
    flLoadWorkshop(r.user_id);
}

/* ═══════════════════════════════════════════════════════════════════════════
   🔧 WORKSHOP VISITS — "take this bike in on Friday".

   ⭐ SAME endpoints the phone calls (WorkshopVisitController), so the desk and the
     phone cannot enforce "who may schedule" differently.
   ⚠ A visit does NOT change the rider's day: it is an errand on a normal paid
     working day (owner ruling). The panel says so, because a manager who thinks
     he has just booked a day off will plan the week wrong.
   ⚠ The rider ACCEPTS it. A manager may stand in when the app is not working, and
     that is shown as "accepted by X for him" — never as the rider confirming.
   ═══════════════════════════════════════════════════════════════════════════ */
let flWorkshopSeq = 0;
/* 📍 Phase 4 — registered workshops. Picking one makes it the rider's shift location for
   that day, so his check-in there is on time by itself. Empty until a manager ticks a
   location as a workshop, and then the prompt just asks for a free-text name as before. */
let flWorkshopLocations = [];

function flLoadWorkshop(uid) {
    const box = document.getElementById('flWorkshop');
    if (!box) return;
    const seq = ++flWorkshopSeq;
    fetch('/orders/riders-map/fleet/workshop?user_id=' + encodeURIComponent(uid),
          { headers: { 'Accept': 'application/json' } })
        .then(r => r.ok ? r.json() : null)
        .then(j => {
            if (seq !== flWorkshopSeq || !j || !j.success) return;
            flWorkshopLocations = j.workshops || [];
            flRenderWorkshop(uid, j.visits || [], !!j.can_schedule);
        })
        .catch(() => {});
}

function flRenderWorkshop(uid, visits, canSchedule) {
    const box = document.getElementById('flWorkshop');
    if (!box) return;

    const btn = canSchedule
        ? '<button class="fl-btn" onclick="flScheduleWorkshop(' + uid + ')">🔧 Schedule workshop</button>'
        : '';

    if (!visits.length) {
        box.innerHTML = '<h5>🔧 Workshop</h5>'
            + '<div style="font-size:11.5px;color:#9ca3af;line-height:1.5;margin-bottom:6px;">'
            + 'Nothing booked.</div>' + btn;
        return;
    }

    box.innerHTML = '<h5>🔧 Workshop</h5>' + visits.map(v => {
        const state = v.accepted
            ? (v.accepted_on_behalf
                ? '<span style="color:#166534;font-weight:700;">✓ accepted for him by '
                  + flEsc(v.accepted_by_name || 'a manager') + '</span>'
                : '<span style="color:#166534;font-weight:700;">✓ confirmed by the rider</span>')
            : '<span style="color:#b45309;font-weight:700;">⏳ awaiting his confirmation</span>';
        const missed = v.is_missed
            ? '<div style="color:#b91c1c;font-weight:800;font-size:11.5px;margin-top:3px;">⚠ This date has passed — mark it done or move it.</div>'
            : '';
        return '<div style="border:1px solid #e5e7eb;border-radius:8px;padding:8px 10px;margin-bottom:6px;">'
            + '<b style="font-size:12.5px;">' + flEsc(v.visit_date)
            + (v.visit_time ? ' · ' + flEsc(v.visit_time) : '') + '</b>'
            + '<div style="font-size:11px;color:#6b7280;margin-top:2px;">'
            + flEsc(v.vehicle_name || 'bike')
            + (v.workshop ? ' · ' + flEsc(v.workshop) : '')
            + ' · ' + flEsc(v.purpose) + '</div>'
            + (v.note ? '<div style="font-size:11px;color:#6b7280;margin-top:2px;">' + flEsc(v.note) + '</div>' : '')
            + '<div style="font-size:11px;margin-top:4px;">' + state + '</div>'
            + missed
            + (canSchedule
                ? '<div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap;">'
                  + '<button class="fl-btn" onclick="flWorkshopDone(' + v.id + ')">Mark done</button>'
                  + '<button class="fl-btn" onclick="flScheduleWorkshop(' + uid + ')">Move</button>'
                  + '<button class="fl-btn" onclick="flWorkshopCancel(' + v.id + ')">Cancel</button>'
                  + (v.accepted ? '' :
                     '<button class="fl-btn" onclick="flWorkshopAccept(' + v.id + ')" '
                     + 'title="Only when his app is not working — it is recorded as accepted on his behalf">'
                     + 'Accept for him</button>')
                  /* 🚦⭐ THE TWO BUTTONS THE DEPLOY DOC ALREADY CLAIMED EXISTED (built 11-Sep).
                       "He has gone" was promised on 10-Sep and never drawn — the note said an old
                       APK was covered by "the manager's 'he has gone'", and there was no such
                       control anywhere. "He is there" is the answer to a FREE-TEXT workshop: it
                       has no pin, so the geofence can never stamp arrival and the rider shows as
                       travelling all day.
                     ⚠ "He is there" appears ONLY for a visit with no pin. A geofenced workshop
                       must never be arrivable by hand, or the stamp stops meaning he was there. */
                  + (v.departed_at ? '' :
                     '<button class="fl-btn" onclick="flWorkshopDepart(' + v.id + ')" '
                     + 'title="He set off but did not press the button on his phone">'
                     + 'He has gone</button>')
                  + ((v.departed_at && !v.arrived_at && !v.location_id)
                     ? '<button class="fl-btn" onclick="flWorkshopArrivedHere(' + v.id + ')" '
                       + 'title="This workshop has no pin, so nothing can detect his arrival">'
                       + 'He is there</button>'
                     : '')
                  + '</div>'
                : '')
            + '</div>';
    }).join('')
    + '<div style="font-size:10.5px;color:#9ca3af;line-height:1.45;margin-bottom:6px;">'
    + 'He still works and is still paid that day — set his shift or location around the appointment.</div>'
    + btn;
}

/* ═══════════════════════════════════════════════════════════════════════════════
   🪟 ONE IN-PAGE FORM FOR THE QUICK ACTIONS (owner, 5-Sep: "these should be proper pop ups,
   not these system-type notifications"). The workshop scheduler alone was FOUR browser
   prompt() boxes in a row; Report a problem, Cancel, Accept and Mark done each had their
   own. They all now open this one modal, described by a field list, and show their result
   inside it instead of in alert().

   flForm({ title, intro, fields: [{key, label, type, options, value, placeholder, hint,
            required}], okLabel, onSubmit(values, done) })
     type: date | time | text | number | textarea | select | radio | checkbox
     done(ok, message, warnings)  — renders the outcome in the modal; on ok the button
                                    becomes "Close" so the manager can read the warnings.
   ═══════════════════════════════════════════════════════════════════════════════ */
let flFormSpec = null;

function flForm(spec) {
    flFormSpec = spec;
    const esc = flEsc;
    document.getElementById('flFormTitle').textContent = spec.title || '';
    document.getElementById('flFormIntro').innerHTML = spec.intro ? esc(spec.intro) : '';
    document.getElementById('flFormIntro').style.display = spec.intro ? '' : 'none';
    const lab = 'display:block;font-size:11.5px;font-weight:700;color:#374151;margin:11px 0 4px;';
    const inp = 'width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;box-sizing:border-box;';
    document.getElementById('flFormBody').innerHTML = (spec.fields || []).map(f => {
        const id = 'flF_' + f.key;
        const hint = f.hint ? '<div style="font-size:11px;color:#9ca3af;margin-top:3px;">' + esc(f.hint) + '</div>' : '';
        /**
         * ⭐ 6-Sep: `showIf: {key, value}` — a field that appears only when another field
         *   holds a given value. Added for "➕ Add a new workshop", which the team asked to
         *   be fillable WITHOUT LEAVING THE FORM: a second modal would throw away everything
         *   already typed, and three always-visible extra boxes would clutter the common case.
         *   Purely presentational — `flFormValues()` still reads every field, and the submit
         *   handler decides what to do with a hidden one.
         */
        let inner;
        if (f.type === 'checkbox') {
            inner = '<label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#111827;margin-top:12px;cursor:pointer;">'
                 + '<input type="checkbox" id="' + id + '"' + (f.value ? ' checked' : '') + '> ' + esc(f.label) + '</label>' + hint;
        } else if (f.type === 'radio') {
            inner = '<div style="' + lab + '">' + esc(f.label) + '</div>'
                 + '<div style="display:flex;flex-direction:column;gap:6px;">'
                 + (f.options || []).map((o, i) =>
                     '<label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#111827;cursor:pointer;">'
                   + '<input type="radio" name="' + id + '" value="' + esc(String(o.value)) + '"'
                   + ((f.value !== undefined ? String(f.value) === String(o.value) : i === 0) ? ' checked' : '') + '> '
                   + esc(o.label) + '</label>').join('')
                 + '</div>' + hint;
        } else if (f.type === 'select') {
            inner = '<label style="' + lab + '">' + esc(f.label) + '</label>'
                 + '<select id="' + id + '" style="' + inp + '">'
                 + (f.options || []).map(o => '<option value="' + esc(String(o.value)) + '"'
                     + (String(f.value) === String(o.value) ? ' selected' : '') + '>' + esc(o.label) + '</option>').join('')
                 + '</select>' + hint;
        } else if (f.type === 'textarea') {
            inner = '<label style="' + lab + '">' + esc(f.label) + '</label>'
                 + '<textarea id="' + id + '" rows="3" placeholder="' + esc(f.placeholder || '') + '" style="' + inp + 'resize:vertical;">'
                 + esc(f.value || '') + '</textarea>' + hint;
        } else if (f.type === 'checklist') {
            /**
             * ⭐ 15-Sep (Part B): a MULTI-select list of tick-boxes, returning an array of the
             *   ticked values. Added for "which of this bike's reported issues is this trip
             *   for?", where every box is ticked by default — the common case is that the bike
             *   is going in for everything that has been reported on it.
             * ⚠ Its own `data-flck` group rather than a name, because `flFieldValue` already
             *   treats `input[name=...]` as a RADIO group and would return only the first.
             */
            inner = '<div style="' + lab + '">' + esc(f.label) + '</div>'
                 + ((f.options || []).length
                     ? '<div style="display:flex;flex-direction:column;gap:5px;border:1px solid #e5e7eb;'
                       + 'border-radius:8px;padding:8px 10px;max-height:190px;overflow:auto;">'
                       + f.options.map(o =>
                           '<label style="display:flex;align-items:flex-start;gap:8px;font-size:12.5px;color:#111827;cursor:pointer;">'
                         + '<input type="checkbox" data-flck="' + esc(f.key) + '" value="' + esc(String(o.value)) + '"'
                         + (o.checked === false ? '' : ' checked') + ' style="margin-top:2px;"> '
                         + '<span>' + esc(o.label)
                         + (o.note ? '<br><span style="color:#9ca3af;font-size:11px;">' + esc(o.note) + '</span>' : '')
                         + '</span></label>').join('')
                       + '</div>'
                     : '<div style="font-size:12px;color:#9ca3af;">' + esc(f.empty || 'Nothing to choose.') + '</div>')
                 + hint;
        } else if (f.type === 'note') {
            // Not an input at all — a line of guidance that belongs inside the flow of the
            // form (e.g. "these are the only workshops that can pin his check-in").
            inner = '<div style="margin-top:10px;padding:8px 10px;background:#f8fafc;border:1px solid #e5e7eb;'
                 + 'border-radius:8px;font-size:12px;color:#475569;line-height:1.5;">' + esc(f.label) + '</div>';
        } else {
            inner = '<label style="' + lab + '">' + esc(f.label) + (f.required ? ' <span style="color:#b91c1c;">*</span>' : '') + '</label>'
                 + '<input type="' + (f.type || 'text') + '" id="' + id + '" value="' + esc(f.value || '') + '"'
                 + ' placeholder="' + esc(f.placeholder || '') + '"' + (f.min !== undefined ? ' min="' + esc(String(f.min)) + '"' : '')
                 + ' style="' + inp + '">' + hint;
        }
        return '<div id="flFW_' + f.key + '"' + (f.showIf ? ' style="display:none;"' : '') + '>' + inner + '</div>';
    }).join('');
    /**
     * ⭐ 6-Sep: `showIf: {key, value}` — a field shown only while another field holds a
     *   given value. Added for "➕ Add a new workshop", which the team asked to be fillable
     *   WITHOUT LEAVING THE FORM: a second modal would throw away everything already typed,
     *   and three permanently visible extra boxes would clutter the ordinary case.
     * ⚠ Presentation only. `flFormValues()` still reads every field and `flFormSubmit`
     *   still validates only what `required` says — so a hidden field is never demanded
     *   (see the `required` guard below, which skips anything currently hidden).
     */
    (spec.fields || []).filter(f => f.showIf).forEach(f => {
        const box = document.getElementById('flFW_' + f.key);
        const src = f.showIf.key;
        const sync = () => {
            const v = flFieldValue(src);
            box.style.display = (String(v) === String(f.showIf.value)) ? '' : 'none';
        };
        document.querySelectorAll('[name="flF_' + src + '"], #flF_' + src)
            .forEach(el => el.addEventListener('change', sync));
        sync();
    });
    const r = document.getElementById('flFormResult');
    r.style.display = 'none'; r.innerHTML = '';
    const ok = document.getElementById('flFormOk');
    ok.textContent = spec.okLabel || 'Save';
    ok.disabled = false;
    ok.onclick = flFormSubmit;
    document.getElementById('flFormModal').style.display = 'flex';
    const first = document.querySelector('#flFormBody input, #flFormBody select, #flFormBody textarea');
    if (first && first.focus) setTimeout(() => first.focus(), 30);
}

function flFormClose() {
    document.getElementById('flFormModal').style.display = 'none';
    flFormSpec = null;
}

/** One field's current value, by key — used by flFormValues and by showIf. */
function flFieldValue(key) {
    const id = 'flF_' + key;
    /**
     * ⭐ 15-Sep: a `checklist` returns an ARRAY of the ticked values. Checked first, because a
     *   group with none ticked must come back as `[]` rather than falling through to the radio
     *   branch and answering `null`.
     */
    const ticks = document.querySelectorAll('input[data-flck="' + key + '"]');
    if (ticks.length) {
        return Array.from(ticks).filter(c => c.checked).map(c => c.value);
    }
    const radio = document.querySelector('input[name="' + id + '"]:checked');
    if (radio) return radio.value;
    if (document.querySelector('input[name="' + id + '"]')) return null;  // radio group, none picked
    const el = document.getElementById(id);
    if (!el) return '';
    if (el.type === 'checkbox') return !!el.checked;
    return String(el.value || '').trim();
}

function flFormValues() {
    const out = {};
    (flFormSpec.fields || []).forEach(f => {
        if (f.type === 'note') return;
        out[f.key] = flFieldValue(f.key);
    });
    return out;
}

function flFormSubmit() {
    if (!flFormSpec) return;
    const v = flFormValues();
    for (const f of (flFormSpec.fields || [])) {
        if (f.type === 'note') continue;
        // ⚠ A hidden showIf field is not required — it is not part of what is being asked.
        const box = document.getElementById('flFW_' + f.key);
        if (box && box.style.display === 'none') continue;
        if (f.required && !v[f.key]) { flFormShow(false, (f.label || 'This') + ' is required.'); return; }
    }
    const ok = document.getElementById('flFormOk');
    ok.disabled = true;
    flFormSpec.onSubmit(v, function (good, message, warnings) {
        flFormShow(good, message, warnings);
        if (good) {
            // Leave the outcome (and any warning) on screen; the button now just closes.
            ok.textContent = 'Close';
            ok.disabled = false;
            ok.onclick = flFormClose;
        } else {
            ok.disabled = false;
        }
    });
}

function flFormShow(good, message, warnings) {
    const r = document.getElementById('flFormResult');
    r.style.display = '';
    r.style.background = good ? '#f0fdf4' : '#fef2f2';
    r.style.borderColor = good ? '#bbf7d0' : '#fecaca';
    r.style.color = good ? '#166534' : '#b91c1c';
    r.innerHTML = flEsc(message || (good ? 'Saved.' : 'Could not save.'))
        + ((warnings && warnings.length)
            ? '<div style="margin-top:8px;padding:8px 10px;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;color:#92400e;">'
              + warnings.map(w => '⚠ ' + flEsc(w)).join('<br>') + '</div>'
            : '');
}

/**
 * Small helper: the ticked workshops AND who this person is, fetched once per page and
 * reused by the scheduler.
 * ⭐ 6-Sep: also carries `can_approve` (is he a shift planner?) and the shift templates —
 *   the two things the booking form needs to decide whether to ASK him "assign now, or
 *   send for approval?".
 * ⚠ `flWorkshopsRefresh()` drops the cache after a workshop is added inline, so the picker
 *   redraws with the new one.
 */
let flWsMeta = null;
function flWorkshopsRefresh() { flWsMeta = null; }
function flWorkshopsThen(cb) {
    if (flWsMeta !== null) { cb(flWsMeta); return; }
    fetch('/orders/riders-map/fleet/workshop?limit=1', { headers: { 'Accept': 'application/json' } })
        .then(r => r.ok ? r.json() : null)
        .then(j => {
            flWsMeta = { list: (j && j.workshops) || [], canApprove: !!(j && j.can_approve),
                         approvalOn: !!(j && j.approval_on), shifts: (j && j.shifts) || [] };
            cb(flWsMeta);
        })
        .catch(() => { flWsMeta = { list: [], canApprove: false, approvalOn: false, shifts: [] }; cb(flWsMeta); });
}

/**
 * @param uid        the rider who takes it in — OR null when `vehicleId` is given
 * @param vehicleId  ⭐ VEHICLE-FIRST (5-Sep): from the machine's page, or from a ticket row,
 *                   name the BIKE and let the registry say who holds it. The old ticket-row call
 *                   passed the RAISER, who since tickets follow the machine may no longer hold it.
 */
function flScheduleWorkshop(uid, ticketId, vehicleId) {
    const tomorrow = (() => { const d = new Date(); d.setDate(d.getDate() + 1);
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); })();
    /**
     * 🔗 PART B (15-Sep-2026) — ask which reported issues this trip is for, BEFORE building the
     *    form, so the tick-boxes are part of it rather than appearing a beat later.
     * ⚠ One source: the warnings endpoint, which resolves the machine the same way `schedule()`
     *   will (so a rider-first booking is answered too) and is already manager-gated.
     * ⚠ Never fatal — a booking form must still open if this fails; it just offers no boxes.
     */
    const q = (vehicleId ? 'vehicle_id=' + encodeURIComponent(vehicleId) : '')
            + (uid ? (vehicleId ? '&' : '') + 'user_id=' + encodeURIComponent(uid) : '');
    fetch('/orders/riders-map/fleet/workshop/warnings?' + q + '&visit_date=' + encodeURIComponent(tomorrow),
          { headers: { 'Accept': 'application/json' } })
        .then(r => r.ok ? r.json() : null)
        .then(j => flScheduleWorkshopForm(uid, ticketId, vehicleId, tomorrow,
                                          (j && j.open_tickets) || []))
        .catch(() => flScheduleWorkshopForm(uid, ticketId, vehicleId, tomorrow, []));
}

function flScheduleWorkshopForm(uid, ticketId, vehicleId, tomorrow, openTickets) {
    flWorkshopsThen(function (meta) {
        const list = meta.list || [];
        const fields = [
            { key: 'date', label: 'Which day', type: 'date', value: tomorrow, required: true,
              hint: 'He still works that day as normal — this is an errand, not a day off. Booking again on the same bike MOVES the existing date.' },
            { key: 'time', label: 'Time (optional)', type: 'time', hint: 'Leave blank for "sometime that day".' },
            { key: 'purpose', label: 'What for', type: 'select', value: 'service',
              options: [{ value: 'service', label: 'Service' }, { value: 'repair', label: 'Repair' },
                        { value: 'inspection', label: 'Inspection' }, { value: 'other', label: 'Other' }] },
        ];

        /**
         * 🛠 WHICH JOB (10-Sep-2026). Neither form asked, so a visit booked as "Service"
         *   named no job — and the man closing it had to pick one from scratch, on a phone,
         *   at a workshop. Naming it here makes the close-out one tap and puts the intent on
         *   the record from the moment it is booked.
         *
         * ⚠ OPTIONAL, and only for a SERVICE: a repair or an inspection resets no countdown,
         *   so asking would be asking for a wrong answer.
         * ⚠ Not binding. Whoever closes the visit may pick a different job (the work turned
         *   out to be something else), and the server re-judges it against THAT machine's own
         *   schedule at that moment — so a stale or inapplicable choice here can never record
         *   a service the countdown cannot use.
         */
        /* ⚠ Every active job, not just the scheduled ones (11-Sep-2026). A bike goes in for an
             overhaul or a general repair at least as often as for an oil change, and a booking
             form that cannot name the reason sends it in as "service" — which is then the wrong
             job pre-filled when somebody closes the visit. */
        const wsJobs = (flData && flData.maint_types || []);
        if (wsJobs.length) {
            fields.push({ key: 'maintenance_type_id', label: 'Which job (optional)', type: 'select', value: '',
                showIf: { key: 'purpose', value: 'service' },
                hint: 'Pre-fills the service record when the visit is closed. Whoever closes it can still change it.',
                options: [{ value: '', label: '— not decided yet —' }]
                    .concat(wsJobs.map(t => ({ value: t.id, label: t.name + (t.due_label ? ' · ' + t.due_label : '') }))) });
        }
        /**
         * 📍 THE WORKSHOP PICKER, WITH A WAY OUT OF AN EMPTY LIST (owner + team, 6-Sep).
         *   Nothing was ticked as a workshop on prod, so this list was empty and every
         *   booking pinned nothing — silently. "➕ Add a new workshop" is therefore always
         *   offered, and it is filled in RIGHT HERE rather than sending the manager off to
         *   the Locations page and losing the date he already typed.
         */
        fields.push({ key: 'location_id', label: 'Which workshop', type: 'radio',
            options: list.map(w => ({ value: w.id, label: w.name }))
                .concat([{ value: '__new', label: '➕ Add a new workshop' },
                         { value: '', label: 'Somewhere else (type the name below — pins nothing)' }]),
            value: list.length ? list[0].id : '__new',
            hint: 'A registered workshop becomes his shift location that day — he checks in THERE, so no lateness lands on him.' });

        fields.push({ key: 'new_name', label: 'New workshop — name', type: 'text', required: true,
            placeholder: 'e.g. Bilal Auto, Ghauri Town', showIf: { key: 'location_id', value: '__new' } });
        fields.push({ key: 'new_coords', label: 'Where is it? (required)', type: 'text', required: true,
            placeholder: '33.6867, 73.0331  — or a Google Maps pin link',
            showIf: { key: 'location_id', value: '__new' },
            hint: '⚠ A Maps link that just names the business carries NO coordinates and cannot be used. '
                + 'In Maps, press and hold the exact spot to drop a pin, then share THAT link — or type the numbers.' });
        fields.push({ key: 'new_radius', label: 'How close must he be? (metres)', type: 'number', min: 100,
            value: '300', showIf: { key: 'location_id', value: '__new' } });

        fields.push({ key: 'workshop', label: 'Workshop name (not on the list)', type: 'text',
            placeholder: 'e.g. Bilal Auto', showIf: { key: 'location_id', value: '' },
            hint: '⚠ A typed name is only a label — his check-in place will NOT move, so he can be marked remote that morning.' });
        /**
         * 🔗 PART B: WHICH REPORTED ISSUES IS THIS TRIP FOR? (owner, 15-Sep)
         *
         *   Before this, a visit linked at most the ONE ticket a manager had pressed Schedule
         *   from — and since managers book from the machine's card, not a thread, that meant
         *   nothing was ever linked. Every open issue on the bike is now offered, TICKED BY
         *   DEFAULT, because the bike going in for everything reported on it is the normal case.
         *
         * ⚠ Untick one and it simply stays in the queue; the visit covers what is ticked.
         * ⚠ An issue already covered by another live day is shown and ticked too, flagged —
         *   moving a bike to a new day must not silently narrow what that day is for.
         */
        if ((openTickets || []).length) {
            fields.push({ key: 'ticket_ids', type: 'checklist',
                label: 'Which reported issues is this trip for?',
                options: openTickets.map(t => ({
                    value: t.id,
                    label: (t.urgent ? '🔴 ' : '') + t.title,
                    note: (t.opened_for_name ? 'reported by ' + t.opened_for_name : '')
                        + (t.already_linked ? (t.opened_for_name ? ' · ' : '')
                              + 'already on another workshop day' : ''),
                })),
                hint: 'Each one ticked is told about this date in its own thread, and shows as '
                    + '"Workshop set" until the visit is done. Untick anything this trip is not for.' });
        }
        fields.push({ key: 'note', label: 'Note (optional)', type: 'textarea', placeholder: 'Anything the rider or the mechanic should know' });

        /**
         * ⏳ THE 6-SEP RULING, in one question. A booking now goes to the shift planners for
         *   approval before the rider hears anything. A planner booking it HIMSELF is asked
         *   whether to just assign it — everyone else is not asked, because they have no
         *   choice to make, and the server decides this again from his rights either way.
         */
        if (meta.approvalOn && meta.canApprove) {
            fields.push({ key: 'route', label: 'And then?', type: 'radio', value: 'now',
                options: [{ value: 'now',      label: '✓ Assign it now — he is told straight away' },
                          { value: 'approval', label: '⏳ Send it to the shift planners for approval' }],
                hint: 'You plan the shifts, so you may assign it yourself.' });
        } else if (meta.approvalOn) {
            fields.push({ key: 'note_approval', type: 'note',
                label: '⏳ This goes to the shift planners (Shabib, Farooq, Taimur) for approval. '
                     + 'The rider is told nothing until one of them approves it.' });
        }

        flForm({
            title: '🔧 Schedule a workshop visit',
            fields: fields,
            okLabel: 'Book it',
            onSubmit: function (v, done) {
                if (!/^\d{4}-\d{2}-\d{2}$/.test(v.date)) { done(false, 'Give the date as YYYY-MM-DD.'); return; }
                if (v.time && !/^\d{2}:\d{2}$/.test(v.time)) { done(false, 'Give the time as HH:MM, e.g. 11:00.'); return; }

                const book = function (locationId) {
                    const payload = vehicleId ? { vehicle_id: vehicleId, visit_date: v.date } : { user_id: uid, visit_date: v.date };
                    if (v.time) payload.visit_time = v.time;
                    if (v.purpose) payload.purpose = v.purpose;
                    // 🛠 Only meaningful on a service — the server ignores it otherwise.
                    if (v.purpose === 'service' && v.maintenance_type_id) {
                        payload.maintenance_type_id = parseInt(v.maintenance_type_id, 10);
                    }
                    if (locationId) payload.location_id = locationId;
                    else if (v.workshop) payload.workshop = v.workshop;
                    if (v.note) payload.note = v.note;
                    if (ticketId) payload.ticket_id = ticketId;
                    /**
                     * 🔗 PART B: the issues this trip covers. ⚠ Sent as ids only — the server
                     *   re-checks every one against the machine actually going in and against
                     *   being open (`resolveTicketIds`), so this list is a convenience and
                     *   never the rule.
                     *
                     * ⚠⚠ SENT WHENEVER THE QUESTION WAS ASKED, even when he ticked NOTHING.
                     *    The key's presence is how the server tells "he unticked them all" from
                     *    "this client is too old to have been asked" — and those two must not
                     *    mean the same thing, or a re-booking from a stale APK would unhook
                     *    every complaint on the machine.
                     */
                    if ((openTickets || []).length) {
                        payload.ticket_ids = (Array.isArray(v.ticket_ids) ? v.ticket_ids : [])
                            .map(n => parseInt(n, 10)).filter(Boolean);
                    }
                    if (v.route === 'approval') payload.send_for_approval = 1;
                    flPostWorkshop('', payload, function (res) {
                        done(true, res.message || 'Booked.', res.warnings || []);
                    }, function (msg) { done(false, msg); });
                };

                if (v.location_id === '__new') {
                    // ⭐ Create the workshop, THEN book against it — one press, no lost typing.
                    const body = { location_name: v.new_name, radius_meters: parseInt(v.new_radius || '300', 10) };
                    const m = String(v.new_coords || '').match(/^\s*(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)\s*$/);
                    if (m) { body.latitude = parseFloat(m[1]); body.longitude = parseFloat(m[2]); }
                    else { body.maps_url = v.new_coords; }
                    fetch('/orders/riders-map/fleet/workshop/locations', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                                   'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '' },
                        body: JSON.stringify(body)
                    })
                    .then(r => r.json())
                    .then(res => {
                        if (!res.success) { done(false, res.message || 'Could not add that workshop.'); return; }
                        flWorkshopsRefresh();
                        book(parseInt(res.location.id, 10));
                    })
                    .catch(() => done(false, 'Could not add that workshop. Please try again.'));
                    return;
                }
                book(v.location_id ? parseInt(v.location_id, 10) : null);
            }
        });
    });
}

function flWorkshopAccept(id) {
    flForm({
        title: '✅ Accept on the rider’s behalf',
        intro: 'He told you in person, or he cannot reach his phone. It is recorded as accepted BY YOU for him — not as his own confirmation.',
        fields: [],
        okLabel: 'Accept for him',
        onSubmit: function (v, done) {
            flPostWorkshop('/' + id + '/accept', {}, function (res) { done(true, res.message || 'Accepted.'); },
                           function (msg) { done(false, msg); });
        }
    });
}

/**
 * ⏳ APPROVE A PROPOSED WORKSHOP DAY (owner + team ruling, 6-Sep) — from the machine's page.
 *
 * ⭐ The planner may change three things on the way through, and the third is the one nothing
 *   else in the flow could fix: the rider's START TIME that day. Danish's visit was booked for
 *   09:00 while his shift still began 09:30 at LaCarne, so even a correct pin would have
 *   measured him against the wrong hour.
 * ⚠ Approving is what tells the rider. Until then he has heard nothing at all.
 */
function flWorkshopApprove(id) {
    flWorkshopsThen(function (meta) {
        const list = meta.list || [];
        flForm({
            title: '✓ Approve this workshop day',
            intro: 'Approving pins the workshop to his day and tells him — in Roman Urdu — that the '
                 + 'PLACE has changed, not the time. Change anything below first if it needs fixing.',
            fields: [
                { key: 'location_id', label: 'Which workshop', type: 'select',
                  options: [{ value: '', label: '— none (his check-in place will NOT move) —' }]
                      .concat(list.map(w => ({ value: w.id, label: w.name }))),
                  hint: list.length ? '' : '⚠ No location is ticked as a workshop yet, so nothing can be pinned. '
                      + 'Add one from the booking form (🔧 Schedule workshop → ➕ Add a new workshop) or on the Locations page.' },
                { key: 'time', label: 'Appointment time (optional)', type: 'time' },
                { key: 'shift_template_id', label: 'His shift that day', type: 'select',
                  options: [{ value: '', label: '— keep his usual shift —' }]
                      .concat((meta.shifts || []).map(s => ({ value: s.id, label: s.name + (s.start ? ' · ' + s.start : '') }))),
                  hint: 'Only change this if the appointment does not fit the hours he already works that day.' },
            ],
            okLabel: 'Approve and tell him',
            onSubmit: function (v, done) {
                const body = { location_id: v.location_id ? parseInt(v.location_id, 10) : null,
                               visit_time: v.time || null };
                if (v.shift_template_id) body.shift_template_id = parseInt(v.shift_template_id, 10);
                flPostWorkshop('/' + id + '/approve', body,
                               function (res) { done(true, res.message || 'Approved.'); },
                               function (msg) { done(false, msg); });
            }
        });
    });
}

function flWorkshopDecline(id) {
    flForm({
        title: '✖ Decline this workshop day',
        intro: 'The person who asked for it is told, with your reason. The rider was never told '
             + 'anything, so there is nothing to undo.',
        fields: [{ key: 'reason', label: 'Why not?', type: 'text',
                   placeholder: 'e.g. He is on the Faizabad run that morning' }],
        okLabel: 'Decline it',
        onSubmit: function (v, done) {
            flPostWorkshop('/' + id + '/decline', { reason: v.reason || null },
                           function (res) { done(true, res.message || 'Declined.'); },
                           function (msg) { done(false, msg); });
        }
    });
}

/**
 * ⏰ PUT THIS PROPOSAL ASIDE FOR SIX HOURS — for THIS user only (owner ask, 11-Sep-2026).
 *
 * ⚠⚠ WHY NOT JUST SHOW IT TO FEWER PEOPLE. Everyone holding `manage_shifts` sees every
 *    proposal, because who decides changes day to day: Taimur is usually only being informed,
 *    but on the days Farooq is away he is the one who must act. Narrowing the audience would
 *    break precisely the case the audience exists for. This lets him say "not me, not now"
 *    without hiding it from anybody else.
 * ⭐ No confirm dialog: it decides nothing and undoes itself by waiting.
 */
function flWorkshopSnooze(id) {
    flPostWorkshop('/' + id + '/snooze', {}, function (res) {
        if (res.message) alert(res.message);
        if (typeof flvOpenId !== 'undefined' && flvOpenId && typeof flvLoadVisits === 'function') flvLoadVisits(flvOpenId);
        if (typeof flSelected !== 'undefined' && flSelected && typeof flLoadWorkshop === 'function') flLoadWorkshop(flSelected);
    });
}

/**
 * 🚦 "HE HAS GONE" — a manager starting the trip for a rider who set off without pressing the
 *    button on his phone (promised 10-Sep, built 11-Sep; the deploy note had been describing it
 *    as though it existed).
 *
 * ⚠ The server applies the SAME gate it applies to the rider: dispatched orders still out will
 *   refuse this, and `force` is what a manager sends when he has just moved them by phone.
 */
function flWorkshopDepart(id) {
    if (!confirm('Mark that he has set off for the workshop?\n\nUse this only when he really has gone and his phone did not record it.')) return;
    flPostWorkshop('/' + id + '/depart', {},
        function (res) { if (res.message) alert(res.message); },
        function (msg) {
            /* ⚠ The orders refusal is not a failure — it is the remedy, and it is actionable:
                 he can send it again once the orders are moved. */
            if (confirm(msg + '\n\nSend it anyway?')) {
                flPostWorkshop('/' + id + '/depart', {force: 1},
                    function (r2) { if (r2.message) alert(r2.message); });
            }
        });
}

/**
 * 📍 "HE IS THERE" — for a workshop entered as free text, which has no pin and therefore no
 *    geofence. Without this the rider reads "going to the workshop" until the day ends.
 *
 * ⭐ The rider's own phone offers him the same answer and ALSO pins the place from his GPS;
 *   this desk version cannot (the manager is not standing there), so it stamps the arrival
 *   only. That asymmetry is deliberate — a pin must come from somebody who was actually there.
 */
function flWorkshopArrivedHere(id) {
    if (!confirm('Mark that he has reached the workshop?\n\nThis workshop has no pin, so nothing can detect it automatically.')) return;
    fetch(FL_BASE + '/workshop/' + id + '/arrived-here', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                   'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '' },
        body: JSON.stringify({ manager: 1 })
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) { alert(res.message || 'Could not save.'); return; }
        if (res.message) alert(res.message);
        if (typeof flSelected !== 'undefined' && flSelected && typeof flLoadWorkshop === 'function') flLoadWorkshop(flSelected);
        if (typeof flvOpenId !== 'undefined' && flvOpenId && typeof flvLoadVisits === 'function') flvLoadVisits(flvOpenId);
    })
    .catch(() => alert('Could not save. Please try again.'));
}

function flWorkshopCancel(id) {
    flForm({
        title: '✖ Cancel this workshop visit',
        intro: 'The rider is told, with your reason if you give one.',
        fields: [{ key: 'reason', label: 'Reason (optional)', type: 'textarea', placeholder: 'e.g. Part has not arrived yet' }],
        okLabel: 'Cancel the visit',
        onSubmit: function (v, done) {
            flPostWorkshop('/' + id + '/cancel', { reason: v.reason || null },
                           function (res) { done(true, res.message || 'Cancelled.'); }, function (msg) { done(false, msg); });
        }
    });
}

/**
 * ⭐ PHASE 3 — completing the visit RECORDS THE SERVICE. Giving a meter writes a typed
 *   service record against the job the visit was booked for, through the same engine as
 *   "Record service"; leaving it blank just closes the errand (an inspection has nothing
 *   to record). If the type cannot be recorded (an "as conditions" job) the server refuses
 *   and the visit stays open — that is shown here, not lost in an alert.
 */
/**
 * 🛠📷⭐⭐ CLOSING A VISIT IS THE SAME FORM AS RECORDING A SERVICE (owner ruling, 11-Sep-2026).
 *
 * ⚠⚠ WHAT THIS REPLACES, AND WHY IT WAS WRONG. The old dialog asked for an odometer and a note
 *    and nothing else — no job picker, no amount, no photo. Three consequences, all reported
 *    from the floor:
 *      • the server fell back to the job the visit was BOOKED for, so an inspection-purpose
 *        visit plus a typed meter was a dead end no manager could get out of;
 *      • the work that actually happened could not be named, and on prod only two of the four
 *        maintenance types were even offerable — Shabib's "I can only see 2 categories";
 *      • the receipt in his hand had nowhere to go, because a photo could only ride on an
 *        expense claim and the money often arrives days later.
 *    The record this writes has ALWAYS been the same row the Bikes screen writes
 *    (`ServiceRecordService::record`), so the form had no business being poorer.
 *
 * ⭐ The job list comes from the SERVER, per visit, and every active job is in it. A job with
 *   no countdown on this machine is offered and labelled — it records the work and resets
 *   nothing, which is exactly "other repair".
 */
function flWorkshopDone(id) {
    const box = document.getElementById('flWsDone');
    if (!box) return;
    box.dataset.visit = String(id);
    document.getElementById('flWsDoneMeter').value = '';
    document.getElementById('flWsDoneAmount').value = '';
    document.getElementById('flWsDoneNote').value = '';
    document.getElementById('flWsDonePhoto').value = '';
    document.getElementById('flWsDoneErr').style.display = 'none';
    // ⚠ Cleared on every open: a stale list from the LAST visit would otherwise sit there
    //   pre-ticked, and one Save would close another machine's complaints.
    const issBox = document.getElementById('flWsDoneIssues');
    if (issBox) { issBox.innerHTML = ''; issBox.style.display = 'none'; }
    const typeSel = document.getElementById('flWsDoneType');
    typeSel.innerHTML = '<span style="font-size:12px;color:#9ca3af;">Loading…</span>';
    const srcSel = document.getElementById('flWsDoneSource');
    srcSel.innerHTML = '<option value="">—</option>';
    box.style.display = 'flex';

    fetch(FL_BASE + '/workshop/' + id + '/types', { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(d => {
            if (!d || !d.success) { typeSel.innerHTML = '<span style="font-size:12px;color:#b91c1c;">(could not load the jobs)</span>'; return; }
            /* ⭐ Scheduled jobs first, then the rest marked so nobody expects a countdown to
                 move. The server decides the order and the labels; this only draws them.
               ⭐ 29-Sep-2026: checkboxes — every job done on the trip is ticked, and the one the
                 visit was booked for arrives pre-ticked. */
            typeSel.innerHTML = (d.types || []).map(t => {
                const on = d.booked_type_id && Number(d.booked_type_id) === Number(t.id);
                return '<label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;'
                    + 'border:1px solid #e5e7eb;background:#f9fafb;border-radius:999px;padding:5px 11px;'
                    + 'font-size:12.5px;color:#111827;">'
                    + '<input type="checkbox" data-wsjob="1" value="' + Number(t.id) + '"' + (on ? ' checked' : '')
                    + ' style="margin:0;">'
                    + flEsc(t.name || t.type_name || ('Type ' + t.id))
                    + (t.counts_down ? '' : ' <span style="color:#9ca3af;font-size:11px;">· no countdown</span>')
                    + '</label>';
            }).join('') || '<span style="font-size:12px;color:#9ca3af;">No job list is set up.</span>';
            (d.pay_sources || []).forEach(s => {
                const o = document.createElement('option');
                o.value = s.id; o.textContent = s.label || s.name || ('Account ' + s.id);
                srcSel.appendChild(o);
            });

            /**
             * ⭐⭐ RULING 6 — "and is the complaint fixed?"
             *
             * ⭐ PRE-TICKED only for what this visit actually went in for (Part B made the visit
             *   know that). Anything else open on the machine is offered UNTICKED: the trip may
             *   have happened to fix it, but a manager tapping through must not close a
             *   complaint the bike never went in for.
             * ⚠ The whole block is absent for anyone the server says cannot close tickets —
             *   which is how the rider marking his own visit done is never asked at all.
             */
            const iss = document.getElementById('flWsDoneIssues');
            const list = d.closeable_tickets || [];
            if (!d.can_close_tickets || !list.length) {
                iss.style.display = 'none'; iss.innerHTML = '';
            } else {
                iss.style.display = '';
                iss.innerHTML =
                    '<label style="display:block;font-size:11.5px;font-weight:700;color:#374151;margin:13px 0 4px;">'
                  + 'Which of these are now fixed?</label>'
                  + '<div style="display:flex;flex-direction:column;gap:5px;border:1px solid #e5e7eb;'
                  + 'border-radius:8px;padding:8px 10px;max-height:170px;overflow:auto;">'
                  + list.map(t =>
                      '<label style="display:flex;align-items:flex-start;gap:8px;font-size:12.5px;color:#111827;cursor:pointer;">'
                    + '<input type="checkbox" data-wsclose="1" value="' + t.id + '"'
                    + (t.covered_by_this_visit ? ' checked' : '') + ' style="margin-top:2px;"> '
                    + '<span>' + (t.urgent ? '🔴 ' : '') + flEsc(t.title)
                    + '<br><span style="color:#9ca3af;font-size:11px;">'
                    + (t.covered_by_this_visit ? 'this trip was for it'
                         : 'not part of this trip — tick only if it is genuinely fixed')
                    + '</span></span></label>').join('')
                  + '</div>'
                  + '<div style="font-size:11px;color:#9ca3af;margin-top:3px;">'
                  + 'Closing tells the rider. Your outcome note above becomes the close note. '
                  + 'Leave one unticked and it simply stays open.</div>';
            }
        })
        .catch(() => { typeSel.innerHTML = '<span style="font-size:12px;color:#b91c1c;">(could not load the jobs)</span>'; });
}

function flWsDoneClose() {
    const box = document.getElementById('flWsDone');
    if (box) box.style.display = 'none';
}

function flWsDoneSave() {
    const box = document.getElementById('flWsDone');
    const id  = box ? box.dataset.visit : null;
    if (!id) return;
    const err = document.getElementById('flWsDoneErr');
    const meter = document.getElementById('flWsDoneMeter').value.trim();
    const jobs  = Array.from(document.querySelectorAll('input[data-wsjob="1"]:checked')).map(c => c.value);
    const amt   = document.getElementById('flWsDoneAmount').value.trim();
    const src   = document.getElementById('flWsDoneSource').value;
    const note  = document.getElementById('flWsDoneNote').value.trim();
    const file  = document.getElementById('flWsDonePhoto').files[0] || null;

    /* ⚠ The meter is what makes this a SERVICE RECORD rather than just a closed visit; the
         server refuses a reading with no job named, so ask here where the sentence is short. */
    if (meter && !jobs.length) {
        err.textContent = 'Tick which job(s) were done — the odometer alone does not say which countdown to reset.';
        err.style.display = ''; return;
    }
    /* ⚠ An amount with no account cannot be filed as a claim, and failing at the server would
         leave the visit closed with the money lost. Asked here instead. */
    if (amt && parseFloat(amt) > 0 && !src) {
        err.textContent = 'Choose which account the bill was paid from.';
        err.style.display = ''; return;
    }
    err.style.display = 'none';

    /* multipart, because of the photo — which no longer needs an amount beside it: the rider
       is handed a receipt at the counter and a manager types the figure days later. */
    const fd = new FormData();
    if (meter) fd.append('meter', parseInt(meter, 10));
    if (meter) jobs.forEach(j => fd.append('maintenance_type_ids[]', j));
    if (amt && parseFloat(amt) > 0) {
        fd.append('amount', amt);
        fd.append('payment_source_account_id', src);
    }
    if (note) fd.append('outcome_note', note);
    if (file) fd.append('photo', file);
    /**
     * ⭐ RULING 6: the issues he says are fixed, closed in the same request.
     * ⚠ Ids only — the server re-checks that this caller may close and that each id is really
     *   open on this machine, so an old or crafted client can close nothing it should not.
     */
    document.querySelectorAll('input[data-wsclose="1"]:checked')
        .forEach(c => fd.append('close_ticket_ids[]', c.value));

    const btn = document.getElementById('flWsDoneSubmit');
    btn.disabled = true; btn.textContent = 'Saving…';

    fetch(FL_BASE + '/workshop/' + id + '/done', {
        method: 'POST',
        headers: { 'Accept': 'application/json',
                   'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '' },
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        btn.disabled = false; btn.textContent = 'Mark done';
        if (!res.success) { err.textContent = res.message || 'Could not save.'; err.style.display = ''; return; }
        flWsDoneClose();
        if (res.message) alert(res.message);
        /* ⚠ The SAME two refreshes every other workshop action does (see flPostWorkshop) —
             the rider's own block and, when it is open, the vehicle panel. Missing the second
             is how a closed visit keeps showing as live on the machine's page. */
        if (typeof flSelected !== 'undefined' && flSelected && typeof flLoadWorkshop === 'function') flLoadWorkshop(flSelected);
        if (typeof flvOpenId !== 'undefined' && flvOpenId && typeof flvLoadVisits === 'function') flvLoadVisits(flvOpenId);
        /* A reading recorded new job records — the open vehicle's countdowns and Past services
           are derived from them, so redraw the machine too. */
        if (meter && typeof flvOpenId !== 'undefined' && flvOpenId && typeof flvOpen === 'function') flvOpen(flvOpenId, true);
    })
    .catch(() => {
        btn.disabled = false; btn.textContent = 'Mark done';
        err.textContent = 'Could not save. Please try again.'; err.style.display = '';
    });
}

/**
 * 🎫 REPORT A PROBLEM FROM THE MACHINE'S PAGE (owner, 5-Sep). Managers had no way to raise a
 *    ticket on the web at all — riders raise from the phone. Same endpoint the phone posts to;
 *    the registry fills in who holds the bike (`VehicleTicketService::open`).
 */
/**
 * 🎫 Open an issue on a machine.
 *
 * ⭐ 15-Sep-2026: the form NAMES the machine (owner ruling — "the flow clearly handles what bike
 *   they are on and tells the user as well"). The caller passes the label it is already showing,
 *   so the desk cannot open a thread against a bike it never mentioned.
 * ⚠ Company machines only (same ruling). The button is hidden on an own bike, and the SERVER
 *   refuses it regardless — this argument only decides what the page offers.
 */
function flvReportProblem(vehicleId, vehicleName) {
    flForm({
        title: '🎫 Open an issue on ' + (vehicleName || 'this bike'),
        intro: vehicleName
            ? 'This issue will be recorded against ' + vehicleName
              + ', and whoever holds that machine sees it.'
            : 'Whoever holds this machine sees it.',
        fields: [
            { key: 'title', label: 'What is wrong (one line)', type: 'text', required: true, placeholder: 'e.g. Front brake is loose' },
            { key: 'urgent', label: 'Not rideable right now (urgent)', type: 'checkbox' },
            { key: 'body', label: 'Details (optional)', type: 'textarea', placeholder: 'What you noticed, since when…' },
        ],
        okLabel: 'Open the issue',
        onSubmit: function (v, done) {
            fetch('/orders/riders-map/fleet/tickets', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                           'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '' },
                body: JSON.stringify({ vehicle_id: vehicleId, title: v.title, urgent: v.urgent ? 1 : 0, category: 'problem', body: v.body || null })
            })
            .then(r => r.json())
            .then(res => {
                if (!res.success) { done(false, res.message || 'Could not raise it.'); return; }
                done(true, res.message || 'Raised. The holder of the bike and the managers are told.');
                if (flvOpenId === vehicleId) flvLoadTickets(vehicleId);
            })
            .catch(() => done(false, 'Could not raise it. Please try again.'));
        }
    });
}

/* @param onErr  optional — receives the server's message instead of an alert(), so a form
                 can show it inline (the in-page modals above pass one). */
function flPostWorkshop(suffix, payload, onOk, onErr) {
    fetch('/orders/riders-map/fleet/workshop' + suffix, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(res => {
        /**
         * ⏳ NOT AN ERROR — A QUESTION (owner ruling 7-Sep). The booking would change a
         * workshop day the rider has ALREADY been told about, so the server refused the first
         * attempt and told us exactly what would change. Ask, and only then send it again
         * carrying the confirmation.
         * ⚠ Asked ONCE: the retry already carries `confirm_replace`, so it cannot loop.
         */
        if (!res.success && res.needs_confirmation && !payload.confirm_replace) {
            if (!confirm(res.message + '\n\nChange it?')) {
                if (onErr) onErr('Nothing was changed.');
                return;
            }
            flPostWorkshop(suffix, Object.assign({}, payload, { confirm_replace: 1 }), onOk, onErr);
            return;
        }
        if (!res.success) { if (onErr) onErr(res.message || 'Could not save.'); else alert(res.message || 'Could not save.'); return; }
        if (onOk) onOk(res); else if (res.message) alert(res.message);
        if (flSelected) flLoadWorkshop(flSelected);
        // 🔧 The same actions now live on the VEHICLE panel too — refresh its block as well.
        if (typeof flvOpenId !== 'undefined' && flvOpenId && typeof flvLoadVisits === 'function') flvLoadVisits(flvOpenId);
        // 🛠 …and the Issues board, whose Schedule button may be where this came from (15-Sep, later).
        if (typeof flIssRefresh === 'function') flIssRefresh();
    })
    .catch(() => { if (onErr) onErr('Could not save. Please try again.'); else alert('Could not save. Please try again.'); });
}

/* ═══════════════════════════════════════════════════════════════════════════
   🛠 BIKE TICKETS — the manager's side of what a rider reported.

   ⭐ SAME ENDPOINTS THE PHONE CALLS (VehicleTicketController). Everything the desk
     can do here — read, reply, close — the phone can do too, and both go through
     VehicleTicketService, so a rule cannot be enforced on one surface and not the
     other. That is the failure this whole round began with.
   ⚠ `can_reply` / `can_close` come from the SERVER. The composer is hidden, not
     merely disabled, when it says no: offering a box that will 422 is how someone
     concludes the page is broken.
   ═══════════════════════════════════════════════════════════════════════════ */
let flTicketSeq = 0;

function flLoadTickets(uid) {
    const box = document.getElementById('flTickets');
    if (!box) return;
    const seq = ++flTicketSeq;                       // last answer wins
    // Filtered SERVER-side by rider — a fleet-wide list capped at 30 could silently drop
    // this man's tickets on a busy day.
    fetch('/orders/riders-map/fleet/tickets?status=all&limit=30&user_id=' + encodeURIComponent(uid),
          { headers: { 'Accept': 'application/json' } })
        .then(r => r.ok ? r.json() : null)
        .then(j => {
            if (seq !== flTicketSeq || !j || !j.success) return;
            flRenderTicketList(j.tickets || [], j.can_manage);
        })
        .catch(() => {});                            // a panel never breaks the drawer
}

function flTicketPill(t) {
    const map = {
        open:        ['#FEF3C7', '#92400E', 'Open'],
        acknowledged:['#DBEAFE', '#1E40AF', 'Being looked at'],
        scheduled:   ['#EDE9FE', '#5B21B6', 'Workshop set'],
        closed:      ['#E5E7EB', '#4B5563', 'Closed'],
    };
    const m = map[t.status] || map.open;
    return '<span style="background:' + m[0] + ';color:' + m[1] + ';font-size:10px;font-weight:800;'
         + 'padding:2px 7px;border-radius:999px;">' + m[2] + '</span>';
}

/**
 * ⭐ ONE ROW RENDERER, TWO CALLERS (5-Sep-2026) — the rider drawer and the VEHICLE panel.
 *   The owner asked for this box on the vehicle as well; copying the markup would have meant
 *   two places to fix the next time a badge or a status colour changes.
 *
 * @param opts.showWho  name the rider on each row. ON for the vehicle panel — the whole point
 *                      there is the machine's history ACROSS holders, so "raised by Waseem"
 *                      is the useful half; OFF in the rider drawer, where every row is his.
 */
function flTicketRowsHtml(list, opts) {
    opts = opts || {};
    return list.map(t =>
        '<div style="border:1px solid #e5e7eb;border-radius:8px;padding:8px 10px;margin-bottom:6px;cursor:pointer;"'
        + ' onclick="flOpenTicket(' + t.id + ')">'
        + '<div style="display:flex;justify-content:space-between;gap:8px;align-items:center;">'
        + '<b style="font-size:12.5px;">' + (t.urgent ? '🔴 ' : '') + flEsc(t.title) + '</b>'
        + (t.unread ? '<span style="background:#dc2626;color:#fff;font-size:10px;font-weight:800;'
                    + 'border-radius:999px;padding:1px 6px;">' + t.unread + '</span>' : '')
        + '</div>'
        + '<div style="font-size:11px;color:#6b7280;margin-top:3px;">'
        + (opts.showWho
             ? (t.opened_for_name || t.opened_by_name
                  ? 'raised by ' + flEsc(t.opened_by_name || t.opened_for_name) : 'raised')
               + (t.opened_at ? ' · ' + flvDate(t.opened_at) : '')
             : flEsc(t.vehicle_name || 'bike')
               + (t.opened_for_name ? ' · ' + flEsc(t.opened_for_name) : ''))
        + '</div>'
        + '<div style="margin-top:5px;">' + flTicketPill(t) + '</div>'
        + '</div>').join('');
}

function flRenderTicketList(list, canManage) {
    const box = document.getElementById('flTickets');
    if (!box) return;
    if (!list.length) {
        box.innerHTML = '<h5>🛠 Bike tickets</h5>'
            + '<div style="font-size:11.5px;color:#9ca3af;line-height:1.5;">Nothing reported. '
            + 'Faults a rider raises from his phone appear here.</div>';
        return;
    }
    box.innerHTML = '<h5>🛠 Bike tickets</h5>' + flTicketRowsHtml(list)
        + '<div id="flTicketThread"></div>';
}

/**
 * Draw one ticket's thread.
 *
 * ⚠ 15-Sep-2026: `wrapEl` was added for the ISSUES BOARD, which shows many machines at once and
 *   therefore needs a per-card target — there is only ever one `#flTicketThread` on the page, so
 *   a board that used it would open every thread into the same box. Both existing callers pass
 *   nothing and keep the old behaviour exactly.
 */
function flOpenTicket(id, wrapEl) {
    const wrap = wrapEl || document.getElementById('flTicketThread');
    if (!wrap) return;
    wrap.innerHTML = '<div style="font-size:11.5px;color:#9ca3af;padding:8px 0;">Loading…</div>';
    fetch('/orders/riders-map/fleet/tickets/' + id, { headers: { 'Accept': 'application/json' } })
        .then(r => r.ok ? r.json() : null)
        .then(j => {
            if (!j || !j.success) { wrap.innerHTML = ''; return; }
            const t = j.ticket || {};
            const msgs = (j.messages || []).map(m => {
                if (m.kind === 'system') {
                    return '<div style="font-size:11px;color:#9ca3af;font-style:italic;text-align:center;margin:8px 0;">'
                         + flEsc(m.body) + '</div>';
                }
                let inner = '';
                if (m.kind === 'photo' && m.media_url) {
                    inner += '<a href="' + flEsc(m.media_url) + '" target="_blank" rel="noopener">'
                           + '<img src="' + flEsc(m.media_url) + '" style="max-width:100%;border-radius:6px;margin-top:4px;"></a>';
                }
                if (m.kind === 'voice' && m.media_url) {
                    // A plain <audio> element — no library, plays in every browser the
                    // office uses, and the file is served by the same /public-storage door.
                    inner += '<audio controls preload="none" src="' + flEsc(m.media_url)
                           + '" style="width:100%;margin-top:4px;"></audio>';
                }
                if (m.body) inner += '<div style="font-size:12.5px;color:#111827;margin-top:2px;">' + flEsc(m.body) + '</div>';
                return '<div style="margin-bottom:9px;">'
                     + '<div style="font-size:11px;font-weight:700;color:#374151;">' + flEsc(m.author_name || 'Someone')
                     + ' <span style="font-weight:400;color:#9ca3af;">· ' + flEsc((m.created_at || '').substring(0, 16)) + '</span></div>'
                     + inner + '</div>';
            }).join('');

            /* 📷 The desk can answer with a PHOTO too — "this is the part we ordered" is
               often the whole reply. Voice is deliberately NOT offered here: a manager at a
               keyboard types, and recording from a browser would add a second media path for
               no gain. The rider's phone is where voice belongs. */
            const composer = (t.can_reply === false) ? ''
                : '<div style="display:flex;gap:6px;margin-top:8px;align-items:center;">'
                  + '<input id="flTicketReply" placeholder="Reply…" maxlength="2000"'
                  + ' style="flex:1;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:12.5px;">'
                  + '<input type="file" id="flTicketPhotos" accept="image/*" multiple style="display:none;"'
                  + ' onchange="flTicketPhotoPicked()">'
                  + '<button class="fl-btn" title="Attach photos" '
                  + 'onclick="document.getElementById(\'flTicketPhotos\').click()">📷</button>'
                  + '<button class="fl-btn" onclick="flReplyTicket(' + id + ')">Send</button></div>'
                  + '<div id="flTicketPhotoNote" style="font-size:11px;color:#6b7280;margin-top:4px;"></div>';

            wrap.innerHTML = '<div style="border-top:1px solid #e5e7eb;margin-top:10px;padding-top:10px;">'
                + '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">'
                + '<b style="font-size:12.5px;">' + flEsc(t.title || '') + '</b>'
                + '<span style="display:flex;gap:6px;">'
                // 🔧 The commonest ANSWER to a reported fault is a workshop date, so it
                //    is offered here rather than making a manager leave the thread. The
                //    ticket id rides along: the visit writes itself into this thread and
                //    moves the ticket to "workshop set".
                + (j.can_manage
                    ? '<button class="fl-btn" onclick="flScheduleWorkshop(null, ' + id + ', ' + (parseInt(t.vehicle_id, 10) || 0) + ')">🔧 Schedule workshop</button>'
                    : '')
                + (j.can_close ? '<button class="fl-btn" onclick="flCloseTicket(' + id + ')">Close issue</button>' : '')
                + '</span>'
                + '</div>'
                /* 💬 15-Sep (later): the ONE context line the server composes — what this
                     conversation was for, when, and whether it ended — so anyone reading a
                     history never has to leave the messages to find out. */
                + (t.context_line
                    ? '<div style="font-size:11.5px;color:#6b7280;margin-top:3px;">' + flEsc(t.context_line) + '</div>'
                    : '')
                + '<div style="max-height:280px;overflow:auto;margin-top:8px;">' + msgs + '</div>'
                + composer
                + (t.can_reply === false
                    ? '<div style="font-size:11px;color:#9ca3af;margin-top:8px;line-height:1.5;">Closed too long ago to reply to. '
                      + 'The rider can report it again if it is still happening.</div>'
                    : '')
                + '</div>';
        })
        .catch(() => { wrap.innerHTML = ''; });
}

/** Say how many photos are staged, so the manager knows they will go with the reply. */
function flTicketPhotoPicked() {
    const input = document.getElementById('flTicketPhotos');
    const note  = document.getElementById('flTicketPhotoNote');
    if (!input || !note) return;
    const n = input.files ? input.files.length : 0;
    // ⚠ 8 is the server's own cap (`photos|array|max:8`) — say so now rather than 422 later.
    if (n > 8) { note.textContent = 'Only 8 photos at a time — the first 8 will be sent.'; return; }
    note.textContent = n ? (n === 1 ? '1 photo attached' : n + ' photos attached') : '';
}

function flReplyTicket(id) {
    const el = document.getElementById('flTicketReply');
    const body = el ? el.value.trim() : '';
    const input = document.getElementById('flTicketPhotos');
    const files = input && input.files ? Array.from(input.files).slice(0, 8) : [];
    if (!body && !files.length) return;

    const fd = new FormData();
    if (body) fd.append('body', body);
    files.forEach(f => fd.append('photos[]', f));

    fetch('/orders/riders-map/fleet/tickets/' + id + '/reply', {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                   'Accept': 'application/json' },
        body: fd
    }).then(r => r.json()).then(res => {
        if (!res.success) { alert(res.message || 'Could not send.'); return; }
        if (res.reopened) alert('That ticket was closed — your reply has re-opened it.');
        // ⚠ The reply can succeed while a photo did not — say so rather than swallowing it.
        if (res.attachments_failed > 0) {
            alert('Sent, but ' + res.attachments_failed + ' photo(s) could not be uploaded.');
        }
        flOpenTicket(id);
        if (flSelected) flLoadTickets(flSelected);
        /**
         * 🛠 The Issues board (15-Sep, later). Two things were wrong here when the reply came from
         *    the board's INLINE thread: the turn marker on the card stayed "waiting on us" after
         *    you had just answered (and the poll skips while a thread is open), and the
         *    `flOpenTicket(id)` above redraws into the rider DRAWER's box, not the board's — so the
         *    board's thread never showed the reply. flIssRefresh() redraws the cards and re-opens
         *    the same thread in its own box.
         */
        if (typeof flIssRefresh === 'function') flIssRefresh();
    }).catch(() => alert('Could not send. Please try again.'));
}

/**
 * ⭐⭐ THE ONE CLOSE ENGINE (owner condition, 15-Sep-2026: "close from the board only if it
 *    follows the same single engine approach, so whether done from here or from inside the
 *    vehicles tab it should work seamlessly").
 *
 *    Three places press this — the thread, the vehicle panel and the Issues board — and all
 *    three land on this one dialog, posting to the one endpoint, and its tail refreshes ALL
 *    THREE surfaces. A second close path would be a second set of refreshes to forget.
 *
 * @param unread  how many messages the presser has NOT read on this ticket. Only the BOARD
 *                passes it: there the button sits away from the conversation, so a manager can
 *                close an issue whose newest messages he has never seen. It WARNS, never blocks
 *                — that was the whole objection to closing from a list.
 */
function flCloseTicket(id, unread) {
    flForm({
        // ⭐ "issue", to match the phone and the Open-an-issue button (owner, 6-Sep).
        title: '✅ Close this issue',
        intro: (unread > 0
                  ? '⚠ There ' + (unread === 1 ? 'is 1 message' : 'are ' + unread + ' messages')
                    + ' on this issue you have not read. '
                  : '')
               + 'The rider holding the bike sees your note.',
        fields: [{ key: 'note', label: 'Note (optional)', type: 'textarea', placeholder: 'e.g. Chain replaced, tension checked' }],
        okLabel: 'Close issue',
        onSubmit: function (v, done) {
            fetch('/orders/riders-map/fleet/tickets/' + id + '/close', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json',
                           'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '' },
                body: JSON.stringify({ note: v.note || null })
            }).then(r => r.json()).then(res => {
                if (!res.success) { done(false, res.message || 'Could not close.'); return; }
                done(true, res.message || 'Closed.');
                flOpenTicket(id);
                if (flSelected) flLoadTickets(flSelected);
                if (typeof flvOpenId !== 'undefined' && flvOpenId) flvLoadTickets(flvOpenId);
                // 🛠 …and the board, if it is the surface he pressed it on.
                if (typeof flIssData !== 'undefined' && flIssData) { flIssOpenThreadId = null; flIssLoad(); }
            }).catch(() => done(false, 'Could not close. Please try again.'));
        }
    });
}

/**
 * ⭐ RIDER → MACHINE. The rider view deliberately shows only his SLICE of a bike;
 *    the bike's whole story (odometer, service, everyone who rode it) lives in the
 *    Vehicles view. Rather than duplicate it here, we take the manager there.
 */
function flOpenVehicle(vehicleId) {
    if (!vehicleId) return;
    flSetMode('vehicles');
    // The grid may still be loading on first switch; wait for it, then open.
    let tries = 0;
    (function open() {
        if (typeof flvOpen === 'function' && flvData && (flvData.vehicles || []).length) {
            flvOpen(vehicleId);
        } else if (tries++ < 40) {
            setTimeout(open, 100);
        }
    })();
}

/**
 * The month's distance, sitting directly above the days that produced it, so a
 * total can always be traced to the readings behind it. Read from the same month
 * row as the table, so the two can never disagree.
 */
function flKmSummary(r) {
    const row = ((flData && flData.riders) || []).find(x => x.user_id === r.user_id);
    if (!row) return '';
    const counted = (r.days || []).filter(d => d.work_km !== null && d.work_km !== undefined).length;

    let cells = '<div class="fl-kmcell"><b>' + flNum(row.work_km) + '</b><span>on duty</span></div>';
    if (row.offduty_km !== null && row.offduty_km !== undefined) {
        // Tap to see it night by night — this is the only distance outside the
        // shift, so it is the number a manager actually wants to interrogate.
        cells += '<div class="fl-kmcell off tap" onclick="flToggleOffNights()" title="Show each night">' +
                 '<b>' + flNum(row.offduty_km) + ' ›</b><span>off duty</span></div>';
    }
    // Kilometres that belong to nobody get their own cells — putting them inside
    // "off duty" is exactly the mistake this project exists to undo.
    if (row.shared_km) {
        cells += '<div class="fl-kmcell" style="background:#e6f7f3;" title="Days the bike changed hands. Real distance, unsplittable — charged to neither rider.">' +
                 '<b style="color:#0f766e;">' + flNum(row.shared_km) + '</b><span>🔁 shared</span></div>';
    }
    if (row.transfer_km) {
        cells += '<div class="fl-kmcell" style="background:#e6f7f3;" title="The bike travelling between riders.">' +
                 '<b style="color:#0f766e;">' + flNum(row.transfer_km) + '</b><span>🔁 in transit</span></div>';
    }
    if (row.total_km !== null && row.total_km !== undefined) {
        cells += '<div class="fl-kmcell"><b>' + flNum(row.total_km) + '</b><span>total</span></div>';
    }
    cells += '<div class="fl-kmcell"><b>' + counted + '</b><span>days counted</span></div>';

    let notes = '';
    if (row.chain_ok === false) {
        notes += '<div class="fl-kmnote">⚠ One of these machines has a gap in its odometer history this month — ' +
                 'readings exist that no handover was recorded for. His duty kilometres still stand; ' +
                 'the stretches between days cannot be trusted until the days are corrected.</div>';
    }
    if (row.no_meter_days) {
        notes += '<div class="fl-kmnote">⚠ ' + row.no_meter_days + ' day' + (row.no_meter_days === 1 ? '' : 's') +
                 ' he worked without a usable meter reading — those kilometres are not in the totals above.</div>';
    }
    if (row.incl_ride_home_days) {
        // By design: the ride home counts as shift. Stated plainly, not as a caveat.
        notes += '<div class="fl-kmnote dim">On duty runs to the meter-out at home on ' +
                 row.incl_ride_home_days + ' day' + (row.incl_ride_home_days === 1 ? '' : 's') +
                 '. Off duty is the stretch from there to the next morning.</div>';
    }

    // Each off-duty stretch: meter-out at home → next morning's meter-in.
    let offList = '';
    (r.off_nights || []).forEach(n => {
        offList += '<div class="fl-offrow"><span>' + flDate(n.date) +
            (n.since ? ' <span style="color:#9ca3af;">(since ' + flDate(n.since) + ')</span>' : '') +
            ' &nbsp;' + (n.from !== null ? flNum(n.from) + ' → ' + flNum(n.to) : '') +
            (n.vehicle_label ? ' <span style="color:#9ca3af;">on ' + flEsc(n.vehicle_label) + '</span>' : '') +
            '</span><span>' + flNum(n.km) + ' km</span></div>';
    });
    if (offList) {
        offList = '<div class="fl-offlist" id="flOffNights" style="display:none;">' +
            '<div style="font-size:10.5px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;margin-bottom:4px;">' +
            'Off duty — meter-out at home → next morning</div>' + offList + '</div>';
    }

    return '<div class="fl-kmbox"><div class="fl-kmtitle">Distance this month</div>' +
           '<div class="fl-kmrow">' + cells + '</div>' + notes + offList + '</div>';
}

function flToggleOffNights() {
    const el = document.getElementById('flOffNights');
    if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

/**
 * @param {boolean} showMachine  name the bike this claim was filed against.
 *   Passed only when the rider had MORE THAN ONE machine that month — on a
 *   one-bike rider it would repeat the same plate down every row, which is the
 *   noise the day chip already avoids.
 */
function flClaimRow(c, showMachine) {
    const flagText = {
        double_tap: 'same amount filed minutes apart — likely a double tap',
        flat_on_metered_day: 'cash claim on a day the meter already paid for',
        second_same_day: 'second cash claim of the day'
    };
    const photo = c.photo
        ? '<img class="fl-thumb" src="' + c.photo + '" alt="Receipt" onclick="flPhoto(\'' + c.photo + '\')">'
        : '<div class="fl-nophoto" title="No photo attached">✕</div>';

    let mid = '<span class="fl-amt">Rs ' + flNum(c.amount) + '</span>';
    // Show the manager's own category name ("Brake Shoe") when the claim carries
    // one; older claims fall back to the generic Regular/Repair wording.
    mid += ' <span class="fl-pill ' + (c.kind === 'fuel' ? 'fl-own' : 'fl-company') + '">' +
           (c.kind === 'fuel' ? '⛽ fuel'
                              : '🔧 ' + flEsc(c.maintenance_type || flServiceLabel(c.service_type))) + '</span>';
    if (c.source === 'meter') {
        mid += ' <span class="fl-muted">' + c.meter_distance + ' km × ' + c.petrol_rate + '</span>';
    } else {
        mid += ' <span class="fl-muted">cash claim</span>';
    }
    // ⭐⭐ WHICH BIKE THIS MONEY WAS FOR. On a day he rode two machines the claims
    //   were rendered identically — two fuel rows, two different tanks, nothing to
    //   tell them apart. The vehicle lens never had this problem because it is
    //   machine-scoped by construction; this is the rider lens catching up.
    // ⚠ Only ever the claim's OWN stamp. An unstamped claim says so instead of
    //   borrowing the day's machine — on the very day this matters the day has two,
    //   so a guess would print a confident wrong answer. Same words the vehicle
    //   card uses, so one vocabulary across both lenses.
    if (showMachine) {
        if (c.vehicle_label) {
            mid += ' <span class="fl-pill" style="background:#eef2ff;color:#3730a3;" title="This claim was filed against ' + flEsc(c.vehicle_label) + '">🏍 ' + flEsc(c.vehicle_label) + '</span>';
        } else {
            mid += ' <span class="fl-pill fl-warn" title="This claim does not name a machine, and he had more than one — so which bike it was for cannot be told from the record.">❓ machine not recorded</span>';
        }
    }
    if (c.meter_at_fill) mid += ' <span class="fl-muted">· meter ' + flNum(c.meter_at_fill) + '</span>';
    // The approver's number: how far the BIKE went on the PREVIOUS tank — whoever
    // filled it. On a handover the previous tank is usually the other man's, and an
    // unattributed number reads as "since HIS last fill", which is what made a
    // 143 km tank look like 962. So the chip names him when it was not the filer.
    if (c.km_since_fill) {
        let sinceTip = 'Kilometres since this bike\'s previous fuel fill';
        if (c.km_since_fill_from) sinceTip += ', at meter ' + flNum(c.km_since_fill_from);
        if (c.km_since_fill_by)   sinceTip += ' — filled by ' + c.km_since_fill_by;
        if (c.km_since_fill_on)   sinceTip += ' on ' + flDate(c.km_since_fill_on);
        mid += ' <span class="fl-pill fl-company" title="' + flEsc(sinceTip) + '">▲ ' + flNum(c.km_since_fill) + ' km since last fill'
             + (c.km_since_fill_by ? ' <span style="opacity:.75;">· ' + flEsc(c.km_since_fill_by) + '</span>' : '')
             + '</span>';
    } else if (c.km_since_fill_odd) {
        mid += ' <span class="fl-pill fl-warn" title="This meter reading and the previous fill\'s reading don\'t add up — typo or a different bike">⚠ meter vs last fill doesn\'t add up</span>';
    }
    if (c.litres) mid += ' <span class="fl-muted">· ' + c.litres + ' L</span>';
    // Every claim states its money status plainly. Only approved and pending
    // exist here — rejected/cancelled are filtered out server-side.
    mid += c.status === 'approved'
        ? ' <span class="fl-pill fl-ok">✓ approved</span>'
        : ' <span class="fl-pill fl-due">⏳ pending</span>';
    if (c.flag) mid += ' <span class="fl-pill fl-warn" title="' + (flagText[c.flag] || '') + '">⚠ ' + (flagText[c.flag] || c.flag) + '</span>';
    // Serviced before the schedule was up — money spent sooner than needed, or a
    // bike with a problem. Either way the approver should see it.
    //
    // ⚠ SILENT ONCE THE ROW CARRIES A FROZEN FIGURE. `service_due_km_at_approval`
    //   below says the same thing in past tense ("done 43 km overdue") and is the
    //   permanent record; printing both is the same number twice. They can no longer
    //   DISAGREE — since Aug-2026 the server derives early/late from that very figure
    //   — but before it did, and this row read "serviced 297 km early" beside
    //   "done 43 km overdue". Belt and braces: only one of them ever speaks.
    const svcFrozen = c.service_due_km_at_approval !== null && c.service_due_km_at_approval !== undefined;
    if (c.service_early_by && !svcFrozen) {
        mid += ' <span class="fl-pill fl-warn" title="' + flNum(c.km_since_service) + ' km since the last service; schedule is ' +
               flNum(c.service_interval) + ' km">⏱ serviced ' + flNum(c.service_early_by) + ' km early</span>';
    } else if (c.service_late_by && !svcFrozen) {
        mid += ' <span class="fl-pill fl-warn" title="' + flNum(c.km_since_service) + ' km since the last service; schedule is ' +
               flNum(c.service_interval) + ' km">⏱ serviced ' + flNum(c.service_late_by) + ' km overdue</span>';
    } else if (c.km_since_service) {
        mid += ' <span class="fl-pill fl-company">▲ ' + flNum(c.km_since_service) + ' km since last service</span>';
    }
    // Still waiting for approval, so the clock hasn't reset — the bike is running
    // past due right now. This is the same number the chip at the top shows, put
    // where the decision is actually made.
    if (c.overdue_now_km) {
        mid += ' <span class="fl-pill fl-warn" title="The bike has run ' + flNum(c.overdue_now_km) +
               ' km past its service schedule and this request has not been approved yet">🔴 bike is ' +
               flNum(c.overdue_now_km) + ' km overdue</span>';
    }
    // Frozen at approval, so it survives the clock reset that follows.
    if (c.service_due_km_at_approval !== null && c.service_due_km_at_approval !== undefined) {
        const d = c.service_due_km_at_approval;
        if (d < 0) mid += ' <span class="fl-pill fl-warn" title="Recorded when this was approved">🔴 done ' + flNum(-d) + ' km overdue</span>';
        else if (d > 25) mid += ' <span class="fl-pill fl-muted" title="Recorded when this was approved">⏱ done ' + flNum(d) + ' km before due</span>';
        else mid += ' <span class="fl-pill fl-ok" title="Recorded when this was approved">⏱ done on schedule</span>';
    }

    // Approve / reject right here — the whole point of this screen is that the
    // approver sees the month's context (duplicate flags, km, other claims that
    // day) at the moment of deciding. Posts to the SAME endpoint and payload as
    // the Daily Closing screen, including the payment source, so money is booked
    // identically no matter where it was approved from.
    let actions = '';
    if (c.status === 'pending' && flApproval && flApproval.can_approve
        && c.next_level && flApproval.levels.indexOf(c.next_level) !== -1) {
        // ⭐ Pre-select what the claim was FILED against, falling back to this
        // approver's own default. Until Aug-2026 this list always started on its
        // first option (NF Cash) and flApprove ALWAYS sent it — so a manager who
        // filed "paid from Online Bank" had it silently rebooked to NF Cash unless
        // the approver happened to touch the dropdown.
        const list = flApproval.accounts || [];
        const filed = list.some(a => a.id === c.filed_source_id) ? c.filed_source_id : null;
        const preferred = filed || (list.find(a => a.is_default) || {}).id;
        const accs = list.map(a =>
            '<option value="' + a.id + '"' + (a.id === preferred ? ' selected' : '') +
            ' data-online="' + (a.is_online ? '1' : '0') + '">' +
            flEsc(a.display_name || a.name) + '</option>').join('');
        actions =
            '<div class="fl-actions" id="flAct' + c.id + '">' +
            (accs ? '<select class="fl-src" id="flSrc' + c.id + '" title="Pay from"' +
                    ' data-filed="' + (filed || '') + '" data-bank="' + (c.filed_bank_id || '') + '">' + accs + '</select>' : '') +
            '<button class="fl-approve" onclick="flApprove(' + c.id + ',' + c.next_level + ')">✅ Approve</button>' +
            '<button class="fl-reject" onclick="flReject(' + c.id + ',' + c.next_level + ')">❌ Reject</button>' +
            // ✏️ Correct what the rider sent BEFORE approving it — wrong category,
            // wrong amount, wrong meter. Only while pending: once approved the
            // money is in the ledger and the service may have moved the clock.
            (flCanManageTypes()
                ? '<button class="fl-reject" style="background:#e0e7ff;color:#3730a3;border-color:#c7d2fe;" ' +
                  'onclick="flOpenEdit(' + c.id + ')">✏️ Edit</button>'
                : '') +
            '</div>';
    }

    // What the approver wrote — often the only record of WHAT the money bought
    // ("Tyre Puncture"). Lives on the approval row and reached no screen before.
    let notes = '';
    (c.approval_notes || []).forEach(n => {
        notes += '<div class="fl-appnote">💬 ' + flEsc(n.text) +
                 '<span> — ' + flEsc(n.by || 'approver') + '</span></div>';
    });
    // Who signed it off, and from which screen. Same money, different desk —
    // this is how you tell Shabib approving from Daily Closing apart from Qasim
    // approving here.
    (c.approval_actions || []).forEach(a => {
        notes += '<div class="fl-appwho">' + (a.status === 'rejected' ? '❌ Rejected' : '✅ Approved') +
                 (a.level ? ' (L' + a.level + ')' : '') +
                 ' by <b>' + flEsc(a.by || 'unknown') + '</b>' +
                 (a.source ? ' from ' + flEsc(a.source) : '') +
                 (a.at ? ' <span class="fl-muted">· ' + flEsc(String(a.at).slice(0, 16).replace('T', ' ')) + '</span>' : '') +
                 '</div>';
    });

    return '<div class="fl-claim' + (c.flag ? ' flagged' : '') + '" id="flClaim' + c.id + '">' +
           photo + '<div style="flex:1;">' + mid + notes + '</div>' + actions + '</div>';
}

// ---- approve / reject a pending claim ----
// Same endpoint, level and payload the Daily Closing screen uses. On success the
// row is replaced in place and the month totals are reloaded, because approving
// moves money from "pending" into the Rs/km figures above.
function flClaimAction(id, level, action, extra) {
    const box = document.getElementById('flAct' + id);
    if (box) box.innerHTML = '<span class="fl-muted">' + (action === 'approve' ? 'Approving…' : 'Rejecting…') + '</span>';

    const payload = Object.assign({level: level}, extra || {});
    fetch('/requests/' + id + '/' + action, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) throw new Error(res.message || 'Failed');
        const row = document.getElementById('flClaim' + id);
        if (row) {
            row.style.background = action === 'approve' ? '#f0fdf4' : '#fef2f2';
            row.style.borderColor = action === 'approve' ? '#86efac' : '#fecaca';
            row.innerHTML = '<div style="padding:2px 4px; font-size:12.5px; font-weight:600; color:' +
                (action === 'approve' ? '#15803d' : '#b91c1c') + ';">' +
                (action === 'approve' ? '✅ Approved' : '❌ Rejected') + '</div>';
        }
        // totals and the rider row above are now stale
        flLoad(flMonth);
    })
    .catch(err => {
        alert(err.message || 'Could not complete that. Please try again.');
        if (flSelected) flSelectRider(flSelected);
    });
}

function flApprove(id, level) {
    if (!confirm('Approve this claim?')) return;
    const sel = document.getElementById('flSrc' + id);
    const extra = {comments: 'Approved from Fleet'};
    if (sel && sel.value) {
        extra.payment_source_account_id = parseInt(sel.value, 10);
        // An ONLINE source must say which bank, or the per-bank balances drift.
        // The bank the claim was filed with is reused when the source is unchanged;
        // if the approver switched TO an online source that had no bank, ask.
        const opt = sel.options[sel.selectedIndex];
        if (opt && opt.dataset.online === '1') {
            let bank = sel.dataset.bank ? parseInt(sel.dataset.bank, 10) : null;
            const unchanged = sel.dataset.filed && parseInt(sel.dataset.filed, 10) === parseInt(sel.value, 10);
            if (!bank || !unchanged) {
                bank = flAskBank(bank);
                if (bank === null) return;          // cancelled — do not approve blind
            }
            extra.receiving_account_id = bank;
        }
    }
    flClaimAction(id, level, 'approve', extra);
}

/**
 * Which of our banks did this leave from? A numbered prompt rather than a modal:
 * this strip is a dense table row and the list is short. Returns null if cancelled.
 */
function flAskBank(current) {
    const banks = (flData && flData.pay_banks) ? flData.pay_banks : [];
    if (!banks.length) return current || null;
    const lines = banks.map((b, i) => (i + 1) + '. ' + (b.short_code || b.name)).join('\n');
    const cur = banks.findIndex(b => b.id === current);
    const ans = prompt('This is an online payment — which bank did it leave from?\n\n' + lines,
                       cur >= 0 ? String(cur + 1) : '1');
    if (ans === null) return null;
    const idx = parseInt(ans, 10) - 1;
    if (isNaN(idx) || idx < 0 || idx >= banks.length) { alert('That is not one of the listed banks.'); return null; }
    return banks[idx].id;
}

function flReject(id, level) {
    const reason = window.prompt('Why is this being rejected? (the rider sees this)');
    if (reason === null) return;
    if (!String(reason).trim()) { alert('Please give a short reason.'); return; }
    // The reject endpoint requires `comments` — that string is what the rider is shown.
    flClaimAction(id, level, 'reject', {comments: reason.trim()});
}

/* ═══════════════════════════════════════════════════════════════════════════
   ⚙️⚙️ THIS VEHICLE'S SCHEDULE (Sep-2026) — replaces BOTH retired controls:
   the per-bike `window.prompt` and the fleet-wide "Company default (N km)" modal.

   ⚠⚠ WHY BOTH HAD TO GO. Each was ONE number that named no job, so neither could
      answer the question a manager actually has ("oil more often on THIS van").
      Since the Aug-27 resolver the job's own standard always wins, which made both
      controls inert — typing into them changed nothing on any screen.

   ⭐ The replacement is two levels, exactly as the owner put it:
        • the COMPANY STANDARD per job, per class → ⚙️ Types
        • the EXCEPTIONS per (vehicle, job)       → this editor
      …and changing a standard ASKS about the vehicles holding their own value.
   ═══════════════════════════════════════════════════════════════════════════ */
let flvSchedFor = null;    // { id, rows: [...] } for the machine on screen

function flvOpenSchedule(vehicleId, vehicleName) {
    flvSchedFor = null;
    flvSchedError('');
    document.getElementById('flvSchedTitle').textContent =
        '⚙️ Service schedule — ' + (vehicleName || 'this vehicle');
    document.getElementById('flvSchedBody').innerHTML =
        '<div style="color:#9ca3af;">Loading…</div>';
    document.getElementById('flvSchedModal').style.display = 'flex';

    fetch(FL_BASE + '/vehicles/' + vehicleId + '/schedule', {headers: {'Accept': 'application/json'}})
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.message || 'Could not load');
            flvSchedFor = {id: vehicleId, rows: res.rows || [], tracked: !!res.tracked};
            flvRenderSchedule(res);
        })
        .catch(err => {
            document.getElementById('flvSchedBody').innerHTML =
                '<div style="color:#b91c1c;">' + flEsc(err.message || 'Could not load this schedule.') + '</div>';
        });
}

function flvCloseSchedule() {
    document.getElementById('flvSchedModal').style.display = 'none';
    flvSchedFor = null;
}

function flvSchedError(msg) {
    const el = document.getElementById('flvSchedError');
    el.textContent = msg || '';
    el.style.display = msg ? 'block' : 'none';
}

function flvRenderSchedule(res) {
    const body = document.getElementById('flvSchedBody');
    const foot = document.getElementById('flvSchedFoot');

    /* ⭐ A personal machine is not on the company schedule at all (owner ruling,
         10-Sep) — so it gets a sentence, not a form full of settings nothing reads. */
    if (!res.tracked) {
        body.innerHTML = '<div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;'
            + 'padding:12px 14px;color:#374151;line-height:1.5;">'
            + flEsc(res.note || 'The company does not keep this vehicle’s service schedule.')
            + '</div>';
        document.getElementById('flvSchedSave').style.display = 'none';
        return;
    }
    document.getElementById('flvSchedSave').style.display = '';

    const isVan = (res.vehicle_class === 'van');
    const rows = res.rows || [];
    if (!rows.length) {
        /* The van's day-one state, said plainly rather than shown as an empty table. */
        body.innerHTML = '<div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;'
            + 'padding:12px 14px;color:#92400e;line-height:1.5;">'
            + 'No ' + (isVan ? 'van' : 'bike') + ' jobs are set up yet. Add them under '
            + '<b>⚙️ Types</b> — each job carries its own figure for bikes and for vans.</div>';
        document.getElementById('flvSchedSave').style.display = 'none';
        return;
    }

    body.innerHTML =
        '<div style="color:#6b7280;margin-bottom:10px;line-height:1.5;">'
        + 'Leave a box blank to follow the company standard for '
        + (isVan ? '🚚 vans' : '🏍️ bikes') + '. A number here applies to '
        + '<b>this vehicle only</b>, and to <b>that job only</b>.'
        + '</div>'
        + '<div style="display:flex;font-size:11px;font-weight:700;color:#6b7280;'
        + 'padding:0 2px 5px;border-bottom:1px solid #e5e7eb;">'
        +   '<div style="flex:2;min-width:0;">Job</div>'
        +   '<div style="flex:1;">Standard</div>'
        +   '<div style="width:120px;text-align:right;">On this vehicle</div>'
        + '</div>'
        + rows.map(t => {
            const timeBased = t.basis === 'time';
            const own = timeBased ? t.own_days : t.own_km;
            const unit = timeBased ? 'days' : 'km';
            return '<div style="display:flex;align-items:center;gap:8px;padding:8px 2px;'
                + 'border-bottom:1px solid #f3f4f6;">'
                + '<div style="flex:2;min-width:0;">'
                +   '<b style="font-size:13px;">' + flEsc(t.name) + '</b>'
                /* ⭐ Which jobs speak for "the vehicle's service" overall — the same flag
                     the headline uses, shown so a manager knows why one job drives it. */
                +   (t.resets_service_clock
                      ? ' <span title="Oil service — a bigger oil service counts for this one too"'
                        + ' style="color:#059669;">⟲</span>' : '')
                + '</div>'
                + '<div style="flex:1;color:#6b7280;font-size:12px;">'
                +   flEsc(t.standard_label || 'as conditions') + '</div>'
                + '<div style="width:120px;display:flex;align-items:center;gap:4px;justify-content:flex-end;">'
                +   '<input type="number" min="0" max="' + (timeBased ? 36500 : 200000) + '"'
                +     ' step="' + (timeBased ? 1 : 100) + '" data-type-id="' + t.id + '"'
                +     ' data-basis="' + t.basis + '" class="flv-sched-in"'
                +     ' value="' + (own || '') + '" placeholder="standard"'
                +     ' style="width:78px;border:1px solid #d1d5db;border-radius:6px;padding:5px 7px;'
                +     'font-size:12.5px;text-align:right;">'
                +   '<span style="font-size:11px;color:#9ca3af;width:26px;">' + unit + '</span>'
                + '</div>'
                + '</div>';
        }).join('')
        + '<div style="margin-top:10px;font-size:11.5px;color:#9ca3af;line-height:1.5;">'
        + 'The company standard for every job is set under <b>⚙️ Types</b> on the Bikes screen. '
        + 'Changing a standard there asks what to do with the vehicles listed here.</div>';
}

function flvSaveSchedule() {
    if (!flvSchedFor) return;
    const btn = document.getElementById('flvSchedSave');
    btn.disabled = true; btn.textContent = 'Saving…';
    flvSchedError('');

    /* ⚠ EVERY row it displayed is posted, including the blank ones — a blank must
         CLEAR that vehicle's exception, and a partial save is how a screen and its
         table drift apart. See VehicleScheduleService::saveFor. */
    const rows = Array.from(document.querySelectorAll('.flv-sched-in')).map(el => {
        const n = parseInt(String(el.value).replace(/[^0-9]/g, ''), 10) || 0;
        const isTime = el.dataset.basis === 'time';
        return {
            id: parseInt(el.dataset.typeId, 10),
            interval_km:   isTime ? 0 : n,
            interval_days: isTime ? n : 0,
        };
    });

    fetch(FL_BASE + '/vehicles/' + flvSchedFor.id + '/schedule', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
                  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''},
        body: JSON.stringify({rows: rows})
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) throw new Error(res.message || 'Failed');
        const vid = flvSchedFor.id;
        flvCloseSchedule();
        alert(res.message);
        /* The countdowns are DERIVED from this, so redraw whatever is on screen. */
        if (typeof flvLoad === 'function' && document.getElementById('flvWrap')) flvLoad();
        if (typeof flvOpen === 'function' && typeof flvOpenId !== 'undefined' && flvOpenId) flvOpen(vid);
        flLoad(flMonth);
        if (flSelected) flSelectRider(flSelected);
    })
    .catch(err => flvSchedError(err.message || 'Could not save this schedule.'))
    .finally(() => { btn.disabled = false; btn.textContent = 'Save schedule'; });
}

/* ═══════════════════════════════════════════════════════════════════════════
   🛠 RECORD A SERVICE — one visit, one or more jobs, at most ONE bill (29-Sep-2026).

   ⭐⭐ Qasim's ask: "I should be able to select multiple categories here." A trip to the
      workshop is often an oil service AND brake shoes AND a chain set, paid with one receipt.
      Every ticked job is written as its own record — so each resets its own countdown on every
      screen — and the bill, when given, is ONE claim linked to all of them.
   ⚠⚠ This replaced a chain of window.prompt boxes that could only pick one job (and could not
      carry a file). The phone's "Record a service" sheet asks the same questions in the same
      order and posts to the same `/mark-serviced`, so the two cannot drift.
   ⚠ An amount SPENDS REAL MONEY: nothing is pre-filled, the account is an explicit choice, and
     the warning says what will happen to THIS user before he commits.
   ═══════════════════════════════════════════════════════════════════════════ */
let flSvcCtx = null;   // { uid, vehicleId, types, picked: [typeId…] }

/**
 * ⭐⭐ WHICH JOBS TO OFFER — the MACHINE's own schedule rows whenever the screen has them.
 *    From a vehicle card that is the open vehicle's `service_schedule`; from the rider drawer,
 *    the rider's. Both come from `serviceScheduleFor`, so the labels are the effective
 *    interval for THIS machine (the Aug-27 "every 2,000 vs 1,200 on one screen" lesson) and a
 *    van is never offered a bike-only job. The raw company list is the last resort.
 * ⚠ `&& .length` matters: an EMPTY array is truthy, and an empty picker is a dead end now that
 *   the server refuses an untyped meter.
 * ⚠ NOT filtered on a kilometre figure (11-Sep-2026): a time-based job reports 0 km and an
 *   "other repair" has no figure at all — both are real work that must be recordable.
 */
function flSvcTypesFor(uid, vehicleId) {
    if (vehicleId && typeof flvLastRes !== 'undefined' && flvLastRes && flvLastRes.vehicle
        && Number(flvLastRes.vehicle.id) === Number(vehicleId)
        && Array.isArray(flvLastRes.service_schedule) && flvLastRes.service_schedule.length) {
        return flvLastRes.service_schedule;
    }
    if (flRider && flRider.user_id === uid && Array.isArray(flRider.service_schedule)
        && flRider.service_schedule.length) {
        return flRider.service_schedule;
    }
    return (flData && flData.maint_types) || [];
}

/** Does this job count down? The server decides; this only labels the chip honestly. */
function flSvcCounts(t) {
    if (t.has_schedule !== undefined) return !!t.has_schedule;
    return Number(t.interval_km || 0) > 0 || Number(t.interval_days || 0) > 0;
}

/** "every 2,000 km" — the SERVER's phrasing when it sent one, so no screen composes its own. */
function flSvcInterval(t) {
    if (t.interval_label) return t.interval_label;
    if (t.due_label) return t.due_label;
    return Number(t.interval_km || 0) > 0 ? 'every ' + flNum(t.interval_km) + ' km' : '';
}

/** A service HAPPENED — opens the form. Never touches the schedule. */
function flMarkServiced(uid, suggested, vehicleId) {
    const types = flSvcTypesFor(uid, vehicleId);
    flSvcCtx = { uid: uid, vehicleId: vehicleId || null, types: types,
                 picked: types.length === 1 ? [types[0].id] : [] };

    /* Say WHOSE and WHICH machine, so a manager on the wrong row notices before saving. */
    const who = (flRider && flRider.user_id === uid && flRider.name) ? flRider.name
        : ((flData && Array.isArray(flData.riders))
            ? ((flData.riders.find(r => Number(r.user_id) === Number(uid)) || {}).name || '') : '');
    const veh = (vehicleId && typeof flvLastRes !== 'undefined' && flvLastRes && flvLastRes.vehicle
                 && Number(flvLastRes.vehicle.id) === Number(vehicleId)) ? (flvLastRes.vehicle.name || '') : '';
    document.getElementById('flSvcBillWhat').textContent =
        [veh, who].filter(Boolean).join(' · ') + (veh || who ? ' — ' : '')
        + 'every ticked job resets its own countdown.';

    const today = flvTodayYmd();
    const d = document.getElementById('flSvcDate');
    d.value = today; d.max = today;
    document.getElementById('flSvcMeter').value = suggested ? String(parseInt(suggested, 10) || '') : '';
    document.getElementById('flSvcBillAmount').value = '';
    document.getElementById('flSvcBillPhoto').value = '';
    document.getElementById('flSvcBillError').style.display = 'none';

    const sel = document.getElementById('flSvcBillSource');
    const sources = (flData && flData.pay_sources) ? flData.pay_sources : [];
    sel.innerHTML = '<option value="">— choose an account —</option>'
        + sources.map(a => '<option value="' + a.id + '">' + flEsc(a.name || a.account_name) + '</option>').join('');
    sel.disabled = !sources.length;

    flSvcRenderJobs();
    flSvcBillSync();
    document.getElementById('flSvcBillModal').style.display = 'flex';
}

function flSvcRenderJobs() {
    const c = flSvcCtx;
    const box = document.getElementById('flSvcJobs');
    const said = document.getElementById('flSvcJobsSaid');
    if (!c) { box.innerHTML = ''; said.innerHTML = ''; return; }
    if (!c.types.length) {
        box.innerHTML = '<span style="font-size:12px;color:#9ca3af;">No job list is set up — the reading is recorded on its own.</span>';
        said.innerHTML = '';
        return;
    }
    box.innerHTML = c.types.map(t => {
        const on = c.picked.indexOf(t.id) !== -1;
        return '<label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;'
            + 'border:1px solid ' + (on ? '#F59E0B' : '#e5e7eb') + ';background:' + (on ? '#FEF3C7' : '#f9fafb') + ';'
            + 'border-radius:999px;padding:5px 11px;font-size:12.5px;color:#111827;">'
            + '<input type="checkbox" style="margin:0;" ' + (on ? 'checked ' : '')
            + 'onchange="flSvcToggle(' + Number(t.id) + ', this.checked)">'
            + flEsc(t.name || t.type_name || 'Job')
            + (flSvcCounts(t) ? '' : ' <span style="color:#9ca3af;font-size:11px;">· no countdown</span>')
            + '</label>';
    }).join('');

    /* One line per ticked job — what it resets, in the server's words. */
    const picked = c.types.filter(t => c.picked.indexOf(t.id) !== -1);
    said.innerHTML = picked.length
        ? picked.map(t => '• <b>' + flEsc(t.name || t.type_name) + '</b> — '
            + (flSvcCounts(t)
                ? 'resets its own countdown' + (flSvcInterval(t) ? ' (' + flEsc(flSvcInterval(t)) + ')' : '')
                : 'logged as work done, no countdown')).join('<br>')
        : '<span style="color:#b45309;">Tick at least one job.</span>';
}

function flSvcToggle(id, on) {
    if (!flSvcCtx) return;
    const i = flSvcCtx.picked.indexOf(id);
    if (on && i === -1) flSvcCtx.picked.push(id);
    if (!on && i !== -1) flSvcCtx.picked.splice(i, 1);
    flSvcRenderJobs();
}

function flSvcBillClose() {
    document.getElementById('flSvcBillModal').style.display = 'none';
    flSvcCtx = null;
}

/** Live: the warning appears the moment an amount is typed, and says what will happen. */
function flSvcBillSync() {
    const amt  = parseInt(document.getElementById('flSvcBillAmount').value, 10);
    const warn = document.getElementById('flSvcBillWarn');
    const btn  = document.getElementById('flSvcBillSubmit');
    const n    = flSvcCtx ? flSvcCtx.picked.length : 0;
    document.getElementById('flSvcBillSourceWrap').style.display = amt > 0 ? '' : 'none';
    if (!(amt > 0)) {
        warn.style.display = 'none';
        btn.textContent = 'Save';
        return;
    }
    /* flApproval.levels is what the SERVER itself uses to decide, so this copy can never
       contradict what actually happens. */
    const auto = !!(flApproval && flApproval.levels && flApproval.levels.indexOf(1) !== -1);
    const hasPhoto = !!document.getElementById('flSvcBillPhoto').files.length;
    warn.innerHTML = 'This will add ONE expense of <b>Rs ' + flNum(amt) + '</b>'
        + (n > 1 ? ' covering all <b>' + n + ' jobs</b>' : '') + '.<br>'
        + (auto
            ? 'It will be <b>approved immediately</b> and posted to the ledger, because you are an approver.'
            : 'It will go for <b>approval</b> before it reaches the ledger.')
        + (hasPhoto ? '' : '<br><span style="color:#b45309;">No bill photo chosen yet.</span>');
    warn.style.display = '';
    btn.textContent = 'Save + add Rs ' + flNum(amt);
}

function flSvcBillSave() {
    const c = flSvcCtx;
    if (!c) return;
    const err   = document.getElementById('flSvcBillError');
    const fail  = (m) => { err.textContent = m; err.style.display = ''; };
    const today = flvTodayYmd();
    const day   = String(document.getElementById('flSvcDate').value || '').trim() || today;
    const meter = parseInt(String(document.getElementById('flSvcMeter').value).replace(/[^0-9]/g, ''), 10);
    const amt   = parseInt(document.getElementById('flSvcBillAmount').value, 10);
    const src   = document.getElementById('flSvcBillSource').value;
    const file  = document.getElementById('flSvcBillPhoto').files[0] || null;

    if (c.types.length && !c.picked.length) return fail('Tick which job(s) were done.');
    if (!meter || meter < 1)                return fail('Enter the odometer at the service, in kilometres.');
    if (!/^\d{4}-\d{2}-\d{2}$/.test(day))   return fail('Choose the date of the service.');
    // A service cannot have happened in the future; the server refuses one too, but saying so
    // here keeps everything the manager just typed.
    if (day > today)                        return fail('That date is in the future.');
    if (amt > 0 && !src)                    return fail('Choose which account this was paid from.');
    err.style.display = 'none';

    const fields = { rider_id: c.uid, meter: meter };
    if (day !== today) fields.date = day;
    /* ⭐ WHICH MACHINE — sent whenever the screen knows it (every vehicle card). The server
         falls back to the registry when absent. */
    if (c.vehicleId) fields.vehicle_id = c.vehicleId;

    let body, headers = {'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
                         'Accept': 'application/json'};
    if (amt > 0 || file) {
        /* multipart, because of the photo. ⚠ Every field goes in HERE — a form that builds its
           own FormData silently drops anything added to the plain path (the Sep-10 lesson). */
        const fd = new FormData();
        Object.keys(fields).forEach(k => fd.append(k, fields[k]));
        c.picked.forEach(id => fd.append('maintenance_type_ids[]', id));
        if (amt > 0) {
            fd.append('amount', amt);
            fd.append('payment_source_account_id', src);
        }
        /* 📷 With money it is the bill (rides onto the expense); without, the proof photo kept
             on the service record for whoever enters the amount later. */
        if (file) fd.append(amt > 0 ? 'bill_image' : 'photo', file);
        body = fd;
    } else {
        headers['Content-Type'] = 'application/json';
        body = JSON.stringify(Object.assign({}, fields, c.picked.length ? { maintenance_type_ids: c.picked } : {}));
    }

    const btn = document.getElementById('flSvcBillSubmit');
    btn.disabled = true; btn.textContent = 'Saving…';
    const uid = c.uid, vid = c.vehicleId;
    fetch(FL_BASE + '/mark-serviced', { method: 'POST', headers: headers, body: body })
        .then(r => r.json().then(j => j, () => ({ success: false, message: 'Could not save (' + r.status + ').' })))
        .then(res => {
            btn.disabled = false; flSvcBillSync();
            /* ⚠ On a refusal EVERYTHING typed stays in the form — the odometer guard names the
                 record that set its bound, and the manager fixes one field, not five. */
            if (!res.success) return fail(res.message || 'Could not save.');
            flSvcBillClose();
            if (res.message) alert(res.message);
            flLoad(flMonth);
            flSelectRider(uid);
            /* The open vehicle's countdowns and Past services are derived from what was just
               written — redraw it, or the card goes on showing the old numbers. */
            if (vid && typeof flvOpenId !== 'undefined' && Number(flvOpenId) === Number(vid)) flvOpen(vid, true);
        })
        .catch(() => { btn.disabled = false; flSvcBillSync(); fail('Could not save. Please try again.'); });
}

/**
 * ⚠⚠ `flSetBikeInterval` IS GONE (Sep-2026), and this note is deliberately left where
 *    it stood so the next person looking for it finds out why.
 *
 * It was a `window.prompt` asking "Service this bike every how many km?" and posting
 * ONE number for a machine — naming no job. The engine therefore had to GUESS which
 * countdown it meant, guessed "the shortest clock-resetting type", and that guess moved
 * whenever an unrelated checkbox changed. After the Aug-27 resolver made a job's own
 * standard authoritative, the prompt changed nothing on any screen whatsoever.
 *
 * ⭐ Its replacement is `flvOpenSchedule(vehicleId)` — a real editor, keyed by MACHINE,
 *   with a row per job and the company standard printed beside each. The server refuses
 *   the old `interval_km` payload with a sentence, so an APK that predates this tells
 *   its user where the setting went instead of silently doing nothing.
 */

// =============================================
// NEW BIKE EXPENSE — inline modal
// Posts to the SAME endpoint the full request form uses, so FuelClaimRules
// applies identically. The rider list is the Bikes roster already on screen,
// which is both shorter and more relevant than the company-wide employee list.
// =============================================
let flNewCat = 'Petrol';

/* ═══════════════════════════════════════════════════════════════════════════
   🏍️ THE SAME TWO ACTIONS, STARTED FROM THE MACHINE (owner ask, 3-Sep):
   "new maintenance should also be an option here, currently it's only in the riders section".

   ⭐⭐ Both REUSE the existing forms rather than adding a second way to do the same thing.
      A claim filed from here goes through `/requests` exactly as it does from the Riders tab,
      so the L1/L2 auto-approval rule, the ledger posting and the type gate are the ones
      already in place — not a copy of them that would drift.
   ⚠ The rider is the machine's CURRENT KEEPER: on this screen the bike is the subject, and a
     claim still has to belong to a person. No keeper → the buttons are not shown at all.
   ═══════════════════════════════════════════════════════════════════════════ */
/**
 * ⭐ Open the shared claim modal FROM a machine — pre-set to its keeper, and pre-pointed at
 *   the machine itself.
 *
 * ⚠ The keeper is passed IN from the render, where the open machine is in scope.
 *   `flvData` holds the vehicle LIST payload, never the open machine — reading
 *   `flvData.vehicle` here would always be undefined and the button would do nothing
 *   but apologise. Caught before it shipped; a JS parse check cannot see this.
 *
 * ⚠⚠ `vehicleId` matters even though the picker would eventually offer it: the picker
 *    PRESELECTS "his own machine with unclaimed kilometres", which on a two-machine day is
 *    the wrong one when the manager opened a specific card. Naming it here is what makes the
 *    claim land on the bike he is looking at — the same reason Record service carries it.
 */
function flvOpenClaimFor(cat, keeperUserId, vehicleId, meter) {
    if (!keeperUserId) {
        alert('This machine has no keeper right now, so a claim has nobody to belong to.');
        return;
    }
    flOpenNew(cat);
    // ⚠ AFTER flOpenNew — it clears the preference, so setting it earlier would be lost.
    flNewPreferVehId = vehicleId ? parseInt(vehicleId, 10) : null;
    if (meter && Number(meter) > 0) {
        const mf = document.getElementById('flNewMeter');
        if (mf) mf.value = String(parseInt(meter, 10));
    }
    const sel = document.getElementById('flNewRider');
    if (sel) {
        sel.value = String(keeperUserId);
        // Fire the same change the manager's own click would, so the vehicle picker and the
        // per-km context load for this rider exactly as they normally do.
        sel.dispatchEvent(new Event('change'));
    }
}

function flvNewMaintenance(keeperUserId, vehicleId) {
    flvOpenClaimFor('Maintenance', keeperUserId, vehicleId, null);
}

/**
 * ⛽ Fuel for a COMPANY machine, filed from its own card. The odometer is seeded with the
 *   reading the manager is looking at — the owner's flow is "assign a meter value, then add
 *   a claim to it" — and stays editable, because the fill may have been at a lower reading.
 */
function flvNewPetrol(keeperUserId, vehicleId, currentMeter) {
    flvOpenClaimFor('Petrol', keeperUserId, vehicleId, currentMeter);
}

function flvRecordService(keeperUserId, currentMeter, vehicleId) {
    if (!keeperUserId) { alert('This machine has no keeper right now, so there is nobody to record the service against.'); return; }
    /* The same prompt chain the Riders tab uses, seeded with THIS machine's odometer.
       ⭐ …and with THIS machine's id (10-Sep-2026), which is the whole point of opening it
         from a vehicle card: the record is stamped with the bike the manager is looking at,
         not with whatever the registry hands its keeper today. The case that used to break:
         the bike is IN the workshop and he has been given a spare. */
    flMarkServiced(keeperUserId, currentMeter || '', vehicleId || 0);
}

/* 🧾 Which already-recorded service this bill is for. Null = a new service, which is every
     claim filed the ordinary way. Cleared on every open of the modal — a stale id would
     attach the next claim to the wrong reading. */
let flNewSvcLogId = null;
/* ⭐ 29-Sep-2026: the OTHER un-billed jobs of the same visit, offered with it. One receipt usually
     pays for the whole trip, so they arrive ticked; the manager unticks any billed separately. */
let flNewSvcVisitIds = [];

/**
 * 🧾 ADD THE BILL to a service that was recorded without one (owner ask, 3-Sep):
 * "in case they added meter readings for the service but later on want to add the bill
 *  that was handed over to them."
 *
 * ⚠⚠ THIS IS THE MANAGER'S PATH, and the data says so: every service log on the system was
 *    recorded by Shabib or Qasim, all of them un-billed. A rider's own form has always forced
 *    an amount, so he never leaves a reading behind — only a manager does.
 *
 * ⭐ The meter, the job and the date come from the READING; he supplies the money. Nothing is
 *   retyped and nothing can drift, because the server inherits them from the log itself.
 */
function flvAddBill(logId, riderId, label) {
    // The label is derived HERE from the loaded history, never passed through an inline
    // onclick (a quoted string inside a double-quoted attribute truncates it).
    // ⚠ The OPEN machine's history (`flvLastRes`) — `flvData` is the vehicle LIST payload.
    const hist = ((typeof flvLastRes !== 'undefined' && flvLastRes && flvLastRes.service_history)
                  || (typeof flvData !== 'undefined' && flvData && flvData.service_history) || []);
    const s = hist.find(r => Number(r.log_id) === Number(logId));
    /* ⭐ THE REST OF THE VISIT (29-Sep-2026): every other job of the same trip that no live bill
         covers yet, from the SERVER's own visit key. */
    const mates = s && s.visit_key
        ? hist.filter(r => r.log_id && Number(r.log_id) !== Number(logId) && !flvBillLive(r)
                           && r.visit_key === s.visit_key && Number(r.rider_id || 0) === Number(s.rider_id || 0))
        : [];
    if (!label) {
        label = s
            ? flvDate(s.date) + ' · ' + (s.kind || 'Service') + (s.meter ? ' · ' + flNum(s.meter) + ' km' : '')
            : 'the recorded service';
    }
    flOpenNew('Maintenance');
    flNewSvcLogId = logId;
    flNewSvcVisitIds = mates.map(r => Number(r.log_id));
    const sel = document.getElementById('flNewRider');
    if (sel && riderId) { sel.value = String(riderId); sel.dispatchEvent(new Event('change')); }
    // The reading is fixed by the service — offering the boxes would invite a contradiction.
    const mw = document.getElementById('flNewMeterWrap');
    if (mw) mw.style.display = 'none';
    const sw = document.getElementById('flNewSvcWrap');
    if (sw) sw.style.display = 'none';
    const note = document.getElementById('flNewSvcNote');
    if (note) {
        note.innerHTML = '🧾 This bill is for the service already recorded — <b>' + flEsc(label) + '</b>.<br>'
                       + 'Its odometer, job and date are taken from that record. Just enter the amount.'
                       + (mates.length
                          ? '<div style="margin-top:7px;">Same visit — does this ONE receipt also pay for:</div>'
                            + mates.map(r => '<label style="display:block;margin-top:3px;cursor:pointer;">'
                                + '<input type="checkbox" data-billmate="1" value="' + Number(r.log_id) + '" checked> '
                                + flEsc(r.kind || 'Service') + '</label>').join('')
                            + '<div style="font-size:11px;color:#6b7280;margin-top:3px;">Untick a job that has its own separate bill.</div>'
                          : '');
        note.style.display = '';
    }
    const t = document.getElementById('flNewTitle');
    if (t) t.textContent = '🧾 Add the bill';
}

function flOpenNew(cat) {
    // ⚠ Cleared FIRST: every path into this modal must start with no service attached.
    flNewSvcLogId = null;
    flNewSvcVisitIds = [];
    const svcNote = document.getElementById('flNewSvcNote');
    if (svcNote) svcNote.style.display = 'none';
    if (!flExpenseCategoryId) {
        alert('The "Expense" request category is not configured, so a claim cannot be filed from here.');
        return;
    }
    // Leaving edit mode: the modal is shared, so every trace of the last edit has
    // to go or a new claim inherits its disabled rider and hidden pay pickers.
    flEditId = null;
    document.getElementById('flNewEditNote').style.display = 'none';
    document.getElementById('flNewRider').disabled = false;
    document.getElementById('flNewSubmit').textContent = 'Create request';
    // ⚠ The vehicle picker is per rider+date, so it must not survive a reopen — a
    //   stale selection would file the previous rider's machine.
    flNewCtx = null; flNewVehId = null; flNewMetered = false;
    // ⚠ A vehicle-card entry sets this immediately AFTER calling us — see flvOpenClaimFor.
    flNewPreferVehId = null;
    document.getElementById('flNewVehWrap').style.display = 'none';
    document.getElementById('flNewAmount').readOnly = false;
    document.getElementById('flNewAmount').style.background = '#fff';
    document.getElementById('flNewMeterWrap').style.display = '';

    flNewCat = cat;
    const isPetrol = cat === 'Petrol';
    document.getElementById('flNewTitle').textContent = isPetrol ? '⛽ New petrol request' : '🔧 New maintenance request';
    document.getElementById('flNewSvcWrap').style.display = isPetrol ? 'none' : 'block';
    flFillMaintTypes();
    document.getElementById('flNewServiceType').value = '';

    // Riders come from the month payload already loaded for this screen.
    const sel = document.getElementById('flNewRider');
    const riders = (flData && flData.riders) ? flData.riders : [];
    // ⭐ NAME THE MACHINE, NOT ITS KIND (owner, Aug-17). `r.bike` is only
    //   company|own — it answers "who pays for the fuel", never "which vehicle" —
    //   so a VAN keeper read "Taimur — company bike", which is simply wrong.
    //   `vehicle_label` is the plate/name and `holds_now` says he still has it, both
    //   already on the row; fall back to the old wording for riders the registry has
    //   never tracked, whose label stays exactly as before.
    sel.innerHTML = '<option value="">— choose a rider —</option>' + riders.map(r => {
        const known = r.vehicle_label && r.holds_now !== false;
        const suffix = known
            ? ' — ' + flEsc(r.vehicle_label)
            : (r.bike === 'company' ? ' — company vehicle' : ' — own vehicle');
        return '<option value="' + r.user_id + '" data-company="' + (r.bike === 'company' ? '1' : '0') + '">'
            + flEsc(r.name) + suffix + '</option>';
    }).join('');

    document.getElementById('flNewAmount').value = '';
    document.getElementById('flNewMeter').value = '';
    document.getElementById('flNewNote').value = '';
    // LOCAL date, not toISOString() — that is UTC, which on PKT reads as
    // YESTERDAY between midnight and 5am, and as `max` it would even block
    // choosing today. (Same trap as the date-cast Carbon issue elsewhere.)
    const d = new Date();
    const todayYmd = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0')
                   + '-' + String(d.getDate()).padStart(2, '0');
    document.getElementById('flNewDate').value = todayYmd;
    document.getElementById('flNewDate').max = todayYmd;
    flNewError('');
    flNewFillPaySources();
    flNewRiderChanged();
    document.getElementById('flNewModal').style.display = 'flex';
}

/**
 * Build the "Paid from" list. A user without `expense_all_payment_sources`
 * legitimately gets a single option (the Expense Fund) — the row is still
 * SHOWN, greyed, because "it came out of the fund" is information the person
 * filing wants confirmed, not hidden. The whole block only disappears when the
 * server sent no accounts at all (misconfiguration), and then we submit
 * nothing and the old default applies.
 */
let flNewBankId = null;

function flNewFillPaySources() {
    const wrap = document.getElementById('flNewPayWrap');
    const sel  = document.getElementById('flNewPaySource');
    const list = (flData && flData.pay_sources) ? flData.pay_sources : [];

    flNewBankId = null;
    if (!list.length) { wrap.style.display = 'none'; sel.innerHTML = ''; flNewPayChanged(); return; }

    sel.innerHTML = list.map(a =>
        '<option value="' + a.id + '"' + (a.is_default ? ' selected' : '') +
        ' data-online="' + (a.is_online ? '1' : '0') + '">' +
        flEsc(a.display_name || a.name) + '</option>').join('');
    sel.disabled = (list.length === 1);
    sel.style.background = (list.length === 1) ? '#f9fafb' : '#fff';
    wrap.style.display = 'block';
    flNewPayChanged();
}

/** Bank sources need the specific bank; cash sources must never carry one. */
function flNewPayChanged() {
    const sel  = document.getElementById('flNewPaySource');
    const opt  = sel.options[sel.selectedIndex];
    const isOnline = !!(opt && opt.dataset && opt.dataset.online === '1');
    const bankWrap = document.getElementById('flNewBankWrap');

    document.getElementById('flNewPayHint').textContent = !opt ? ''
        : (sel.disabled ? 'This is the only account you can spend from.'
                        : 'The account this money actually left.');

    if (!isOnline) {
        bankWrap.style.display = 'none';
        flNewBankId = null;
        return;
    }

    const banks = (flData && flData.pay_banks) ? flData.pay_banks : [];
    if (!banks.length) { bankWrap.style.display = 'none'; flNewBankId = null; return; }
    if (!banks.some(b => b.id === flNewBankId)) flNewBankId = null;

    document.getElementById('flNewBankChips').innerHTML = banks.map(b =>
        '<button type="button" onclick="flNewPickBank(' + b.id + ')" ' +
        'style="border:1px solid ' + (b.id === flNewBankId ? '#f59e0b' : '#d1d5db') + ';' +
        'background:' + (b.id === flNewBankId ? '#fffbeb' : '#fff') + ';border-radius:999px;' +
        'padding:5px 11px;font-size:12px;font-weight:700;cursor:pointer;' +
        'color:' + (b.id === flNewBankId ? '#92400e' : '#374151') + ';">' +
        flEsc(b.short_code || b.name) + '</button>').join('');
    bankWrap.style.display = 'block';
}

function flNewPickBank(id) {
    flNewBankId = id;
    flNewPayChanged();
}

function flCloseNew() {
    document.getElementById('flNewModal').style.display = 'none';
    // Drop the bike's record with the modal — reopening for a DIFFERENT rider must
    // never flash the previous machine's history while the new answer is in flight.
    flRenderLastMaint(null);
}

/** May this user manage types / correct a filed claim? Same server-side gate. */
function flCanManageTypes() { return !!(flData && flData.can_manage_types); }

// =============================================
// ✏️ EDIT A FILED CLAIM (pending only)
// Reuses the new-request modal — identical fields, so a second form would have
// meant a second copy of the meter/type/date rules that then drifts.
// =============================================
let flEditId = null;

function flOpenEdit(claimId) {
    // The claim as the server last sent it — searched across the loaded rider's days.
    let claim = null;
    ((flRider && flRider.days) || []).forEach(d => (d.claims || []).forEach(c => { if (c.id === claimId) claim = c; }));
    if (!claim) { alert('Could not find that claim — refresh and try again.'); return; }

    flEditId = claimId;
    // ⚠ Reset the picker before anything reads it — the modal is shared, and a
    //   selection left over from a previous claim would re-stamp this one.
    flNewCtx = null; flNewVehId = null; flNewMetered = false;
    document.getElementById('flNewAmount').readOnly = false;
    document.getElementById('flNewAmount').style.background = '#fff';
    document.getElementById('flNewMeterWrap').style.display = '';
    flNewCat = claim.kind === 'fuel' ? 'Petrol' : 'Maintenance';
    const isPetrol = flNewCat === 'Petrol';

    document.getElementById('flNewTitle').textContent = isPetrol ? '✏️ Edit petrol claim' : '✏️ Edit maintenance claim';
    document.getElementById('flNewSvcWrap').style.display = isPetrol ? 'none' : 'block';
    flFillMaintTypes();

    // The rider cannot be changed here — moving a claim between riders would move
    // it between two different bikes and odometer histories. Re-file instead.
    // ⚠ The option MUST carry the user id as its value: flNewWhichBike() reads
    //   `.value` to name the machine and fetch its record. A value-less option made
    //   `.value` the rider's NAME, the server cast it to user 0, and the edit modal
    //   silently lost both the which-bike line and the last-maintenance panel.
    const rsel = document.getElementById('flNewRider');
    rsel.innerHTML = '<option value="' + ((flRider && flRider.user_id) || '') + '">'
        + flEsc((flRider && flRider.name) || 'this rider') + '</option>';
    rsel.disabled = true;
    document.getElementById('flNewBikeHint').textContent = 'Filed for this rider — to move it to someone else, reject it and file again.';

    const svcSel = document.getElementById('flNewServiceType');
    svcSel.value = claim.maintenance_type_id ? ('type:' + claim.maintenance_type_id)
                 : (claim.service_type || '');

    document.getElementById('flNewAmount').value = claim.amount ?? '';
    document.getElementById('flNewMeter').value  = claim.meter_at_fill ?? '';
    document.getElementById('flNewNote').value   = claim.note || '';
    const d = (claim.filed_at || '').slice(0, 10);
    if (d) document.getElementById('flNewDate').value = d;

    // Paying is decided at approval, not here — hide the money pickers so an edit
    // cannot quietly re-book the source.
    document.getElementById('flNewPayWrap').style.display = 'none';
    document.getElementById('flNewBankWrap').style.display = 'none';

    const note = document.getElementById('flNewEditNote');
    note.textContent = 'Correcting claim #' + claimId + ' before approval. The rider keeps the claim; '
        + 'only these details change. Pick a vehicle below only if this claim is on the wrong one.';
    note.style.display = 'block';

    document.getElementById('flNewSubmit').textContent = 'Save changes';
    flNewError('');
    flNewWhichBike();     // an edit still says which machine it belongs to
    flNewSvcChanged();
    flNewMeterTyped();
    document.getElementById('flNewModal').style.display = 'flex';
}

/** Send just the corrected fields; the server re-runs every filing rule on them. */
function flSubmitEdit() {
    const svc    = document.getElementById('flNewServiceType').value;
    const amount = parseFloat(document.getElementById('flNewAmount').value);
    const meter  = document.getElementById('flNewMeter').value;
    const date   = document.getElementById('flNewDate').value;
    const note   = document.getElementById('flNewNote').value;

    if (!amount || amount <= 0) { flNewError('Enter the amount.'); return; }

    const body = {amount: amount, expense_date: date, description: note};
    if (meter !== '') body.meter_at_fill = parseInt(meter, 10);
    // ⭐ D4 — only when the editor actually picked a machine. Sending nothing leaves the
    //   existing stamp alone, which is what an ordinary amount/date correction wants.
    if (flNewVehId) body.vehicle_id = flNewVehId;
    if (flNewCat === 'Maintenance' && svc.indexOf('type:') === 0) {
        body.maintenance_type_id = parseInt(svc.slice(5), 10);
    }

    const btn = document.getElementById('flNewSubmit');
    btn.disabled = true; btn.textContent = 'Saving…';
    flNewError('');

    fetch('/orders/riders-map/fleet/claim/' + flEditId + '/edit', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
                  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''},
        body: JSON.stringify(body)
    })
    .then(r => r.json().then(j => ({ok: r.ok, j})))
    .then(({ok, j}) => {
        // The server's own message explains WHY (meter needed, reading out of range).
        if (!ok || !j.success) { flNewError(j.message || 'Could not save the change.'); return; }
        flCloseNew();
        flLoad(flMonth, true);
        if (flSelected) flSelectRider(flSelected);
    })
    .catch(() => flNewError('Could not save the change. Please try again.'))
    .finally(() => { btn.disabled = false; btn.textContent = 'Save changes'; });
}

function flNewError(msg) {
    const el = document.getElementById('flNewError');
    el.textContent = msg || '';
    el.style.display = msg ? 'block' : 'none';
}

/** Tell the manager, before he submits, whether the meter is required. */
function flNewRiderChanged() {
    const sel = document.getElementById('flNewRider');
    const opt = sel.options[sel.selectedIndex];
    const isCompany = opt && opt.dataset && opt.dataset.company === '1';
    // "vehicle", not "bike" — the van goes through this same form.
    document.getElementById('flNewBikeHint').textContent = !opt || !sel.value
        ? '' : (isCompany
            ? 'Company vehicle — the firm buys the fuel, so the meter is required.'
            : 'Own vehicle — paid per shift kilometre. The meter is optional here.');
    flNewSvcChanged();
    flNewMeterTyped();          // the since-last-fill line is per rider
    flNewWhichBike();           // ⭐ and WHICH machine this will land on
}

/**
 * ⭐ NAME THE MACHINE BEFORE THE CLAIM IS FILED (owner ask, Aug-5).
 *
 * "So the team knows which bike they are raising for." Asked of the registry, so
 * it follows a reassignment on its own, and keyed to the CLAIM'S DATE rather than
 * today — a backdated claim lands on the bike he had THAT day, which is exactly how
 * the server stamps it. Changing either the rider or the date re-asks.
 *
 * Silent when there is nothing to say (no rider picked, no machine registered):
 * a label is never worth breaking a form over.
 */
let flNewBikeSeq = 0;

/* ⭐ THE EFFECTIVE SCHEDULE of the machine this claim will land on, so the
   "what was done" list can print the interval THIS bike runs instead of the
   type's standard one. Arrives with the same /for-user response that names the
   bike; null whenever no machine is in scope, in which case the type's own
   `data-due` label stands. Display only — the form still posts the type id. */
let flNewSched = null;

/* ⭐⭐ THE DAY'S MACHINES — state for the picker.
   flNewCtx     = the petrol-context response (machines, km, existing claims)
   flNewVehId   = the machine the manager picked, null = let the server decide
   flNewMetered = this will be filed as a PER-KM claim (own bike, real distance,
                  a rate to price it) rather than a flat cash one. */
let flNewCtx = null, flNewVehId = null, flNewMetered = false, flNewCtxSeq = 0;

/**
 * ⭐ The machine a VEHICLE-CARD entry asked for, honoured once by the picker below.
 *
 * ⚠ Consumed on use and cleared by `flOpenNew`, so it can never leak into a claim opened
 *   from the fleet header — where the manager picks the rider and the picker's own rule
 *   ("his own machine with unclaimed kilometres") is the right preselect.
 */
let flNewPreferVehId = null;

/**
 * ⭐⭐ WHAT HE ACTUALLY RODE THAT DAY (Aug-27 2026).
 *
 * Petrol only, and only ever ADDITIVE to the line above: on an ordinary single-machine
 * day the picker shows one chip and nothing about the form changes. It earns its place
 * on a mixed day, where it is the difference between "there is no claim for this man"
 * and paying him what he is owed.
 *
 * ⚠ The kilometres and the already-claimed status come from the server, never from
 *   arithmetic here — the modal must not be able to offer a figure the claim guard
 *   would then refuse.
 */
function flNewLoadVehicles() {
    const wrap = document.getElementById('flNewVehWrap');
    if (!wrap) return;
    const uid  = document.getElementById('flNewRider').value;
    const date = document.getElementById('flNewDate').value;

    flNewCtx = null; flNewVehId = null; flNewMetered = false;
    if (!uid || flNewCat !== 'Petrol') { wrap.style.display = 'none'; flNewApplyVehicle(); return; }

    const seq = ++flNewCtxSeq;
    fetch(FLV_BASE + '/petrol-context?user_id=' + encodeURIComponent(uid)
          + (date ? '&date=' + encodeURIComponent(date) : ''),
          { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(res => {
            if (seq !== flNewCtxSeq) return;          // last answer wins
            flNewCtx = res || null;
            const list = (res && res.vehicles) ? res.vehicles : [];
            if (!list.length) { wrap.style.display = 'none'; flNewApplyVehicle(); return; }

            wrap.style.display = 'block';
            document.getElementById('flNewVehChips').innerHTML = list.map((v, i) => {
                const km = (v.km === null || v.km === undefined) ? null : v.km;
                const claimed = v.claim ? true : false;
                return '<button type="button" data-vid="' + v.vehicle_id + '" onclick="flNewPickVehicle('
                    + v.vehicle_id + ')" class="flNewVehChip" style="'
                    + 'border:1px solid #d1d5db;background:#fff;border-radius:999px;padding:6px 11px;'
                    + 'font-size:12px;cursor:pointer;display:flex;align-items:center;gap:6px;'
                    + (claimed ? 'opacity:.65;' : '') + '">'
                    + flMachineIcon(v) + ' <b>' + flEsc(v.label) + '</b>'
                    + (km !== null ? ' <span style="color:#6b7280;">' + flNum(km) + ' km</span>' : '')
                    + (claimed ? ' <span style="color:#b45309;">' + (v.claim.status === 'approved' ? '✅' : '⏳') + '</span>' : '')
                    + '</button>';
            }).join('');

            // ⚠ EDITING an existing claim: the chips are here to RE-POINT it at the
            //   right machine (D4), never to preselect one — a claim already has an
            //   amount and a kind, and quietly re-picking would rewrite both.
            if (flEditId) { flNewPickVehicle(null); return; }

            /**
             * ⭐ A VEHICLE CARD asked for a specific machine — that beats every guess below,
             *   because the manager is looking straight at it. Consumed here so a later
             *   rider change in the same modal falls back to the ordinary rule.
             * ⚠ Only if it is actually in the day's list; a machine with no leg that day
             *   cannot be picked, and silently selecting nothing is the honest answer.
             */
            const asked = flNewPreferVehId
                ? list.find(v => v.vehicle_id === flNewPreferVehId) : null;
            flNewPreferVehId = null;

            // Otherwise preselect the one a manager almost always wants: his own machine with
            // unclaimed kilometres. Otherwise leave it to him — never guess on money.
            const best = asked
                || list.find(v => v.can_meter_claim)
                || (list.length === 1 ? list[0] : null);
            flNewPickVehicle(best ? best.vehicle_id : null);
        })
        .catch(() => { if (seq === flNewCtxSeq) { wrap.style.display = 'none'; flNewApplyVehicle(); } });
}

function flNewPickVehicle(vid) {
    flNewVehId = vid ? parseInt(vid, 10) : null;
    document.querySelectorAll('#flNewVehChips .flNewVehChip').forEach(b => {
        const on = flNewVehId && parseInt(b.dataset.vid, 10) === flNewVehId;
        b.style.borderColor = on ? '#f59e0b' : '#d1d5db';
        b.style.background  = on ? '#fffbeb' : '#fff';
        b.style.boxShadow   = on ? '0 0 0 2px rgba(245,158,11,.25)' : 'none';
    });
    flNewApplyVehicle();
}

/**
 * Put the form into the mode the chosen machine implies.
 *
 * ⭐ OWN BIKE with real kilometres → the PER-KM claim: the amount is km × his rate, and
 *   it is filed as the same self-auditing row his own phone would file (attendance row,
 *   distance and rate all recorded). A hand-typed amount here would be a different kind
 *   of money for the same kilometres.
 * ⭐ COMPANY vehicle → the flat cash claim, exactly as before, meter and all.
 */
function flNewApplyVehicle() {
    const note   = document.getElementById('flNewVehNote');
    const amount = document.getElementById('flNewAmount');
    const veh    = (flNewCtx && flNewCtx.vehicles || []).find(v => v.vehicle_id === flNewVehId) || null;

    // ⚠ An EDIT never becomes a per-km claim. The row already exists with its own kind,
    //   amount and evidence; switching it here would rewrite settled facts rather than
    //   correct them. Editing changes WHICH MACHINE, and nothing else.
    flNewMetered = !!(veh && veh.can_meter_claim && flNewCat === 'Petrol' && !flEditId);

    if (amount) {
        amount.readOnly = flNewMetered;
        amount.style.background = flNewMetered ? '#f9fafb' : '#fff';
        if (flNewMetered && veh.suggested_amount) amount.value = veh.suggested_amount;
    }
    // The meter box belongs to the flat/company flow — a per-km claim measures the day,
    // not the moment of filling.
    const mw = document.getElementById('flNewMeterWrap');
    if (mw) mw.style.display = flNewMetered ? 'none' : '';

    if (note) {
        if (!veh) { note.textContent = ''; note.style.color = '#6b7280'; }
        else if (veh.claim) {
            note.style.color = '#b45309';
            note.innerHTML = '⚠ Already claimed for this vehicle on this date — '
                + flEsc(veh.claim.number || ('#' + veh.claim.id)) + ' · Rs ' + flNum(veh.claim.amount)
                + ' · ' + flEsc(veh.claim.status) + '.';
        } else if (flNewMetered) {
            note.style.color = '#0f766e';
            note.innerHTML = '👤 His own vehicle — paid per kilometre. '
                + (veh.meter_start !== null && veh.meter_end !== null
                    ? ('Meter ' + flNum(veh.meter_start) + ' → ' + flNum(veh.meter_end) + ' = ') : '')
                + '<b>' + flNum(veh.km) + ' km</b> × Rs ' + (flNewCtx.petrol_rate || 0)
                + ' = <b>Rs ' + flNum(veh.suggested_amount) + '</b>'
                + (veh.entered_by_name ? ' <span style="color:#6b7280;">(readings entered by '
                    + flEsc(veh.entered_by_name) + ')</span>' : '');
        } else if (veh.is_company) {
            note.style.color = '#6b7280';
            note.innerHTML = '🏢 Company vehicle — the firm buys the fuel, so this is a cash claim '
                + 'and the meter reading is required.';
        } else if (flNewCtx && flNewCtx.in_window === false && !veh.is_company && (veh.km || 0) > 0) {
            // ⭐ Say why a real, unclaimed distance is not on offer — the alternative
            //   is a manager filling the form and meeting a 422 he cannot act on.
            note.style.color = '#b45309';
            note.innerHTML = '⚠ ' + flNum(veh.km) + ' km recorded, but per-kilometre claims can only be '
                + 'raised for the last ' + flNum(flNewCtx.window_days) + ' days. File it as a cash claim, '
                + 'or ask for the window to be widened in Attendance settings.';
        } else {
            note.style.color = '#6b7280';
            note.innerHTML = (flNewCtx && !flNewCtx.petrol_rate)
                ? 'No per-kilometre rate is set for him, so this will be a cash claim.'
                : 'No claimable distance recorded on this vehicle for that date — this will be a cash claim.';
        }

        // ⚠ Claims that day which belong to no chip above (a cash claim on a machine
        //   with no readings, or one nobody stamped) are SHOWN, never quietly dropped —
        //   "has this day already been claimed?" is the question this panel exists to
        //   answer, and a silent omission answers it wrongly.
        const others = (flNewCtx && flNewCtx.other_claims) ? flNewCtx.other_claims : [];
        if (others.length) {
            note.innerHTML += '<div style="margin-top:5px;color:#b45309;">↪ Also that day: '
                + others.map(o => flEsc(o.number || ('#' + o.id)) + ' Rs ' + flNum(o.amount)
                    + ' (' + flEsc(o.status) + ')').join(' · ') + '</div>';
        }
    }
    flNewSvcChanged();          // the meter-required label follows the CHOSEN machine
}

function flNewWhichBike() {
    const el  = document.getElementById('flNewWhichBike');
    if (!el) return;
    const uid  = document.getElementById('flNewRider').value;
    const date = document.getElementById('flNewDate').value;
    flNewLoadVehicles();        // ⭐ the day's machines, in parallel with the label
    if (!uid) {
        el.style.display = 'none'; el.textContent = '';
        flRenderLastMaint(null);
        flNewSched = null; flNewSvcChanged();
        return;
    }

    const seq = ++flNewBikeSeq;        // last answer wins, never a stale one
    fetch(FLV_BASE + '/for-user?user_id=' + encodeURIComponent(uid)
          + (date ? '&date=' + encodeURIComponent(date) : ''),
          { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(res => {
            if (seq !== flNewBikeSeq) return;
            const v = res && res.vehicle;
            if (!v) {
                el.style.display = 'none'; el.textContent = '';
                flRenderLastMaint(null);
                flNewSched = null; flNewSvcChanged();
                return;
            }
            el.style.display = '';
            el.innerHTML = (v.vtype === 'van' ? '🚚' : '🏍️')
                + ' This will be recorded against <b>' + flEsc(v.name) + '</b>'
                + (v.is_company ? '' : ' <span style="color:#9ca3af;">(his own bike)</span>');
            // ⭐ …and what that machine already has on record.
            flRenderLastMaint(res.last_maintenance || null);
            // ⭐ …and how often each job is due ON THIS BIKE — re-render the due line,
            //    because a type may already be selected when the machine resolves.
            flNewSched = Array.isArray(res.service_schedule) ? res.service_schedule : null;
            // ⚠ The list itself depends on the machine now, not just the label, so it
            //   has to be rebuilt once the schedule arrives.
            flFillMaintTypes();
            flNewSvcChanged();
        })
        .catch(() => {
            if (seq === flNewBikeSeq) {
                el.style.display = 'none';
                flRenderLastMaint(null);
                flNewSched = null; flNewSvcChanged();
            }
        });
}

/**
 * ⭐ "WHAT IS ALREADY THERE" — the machine's maintenance record, shown while the
 *    claim is still being typed (owner ask, Aug-16).
 *
 * Three things, in the order a person actually asks them:
 *   1. what is due next, and how soon — so a service is not filed twice, and a due
 *      one is not missed while the form is open;
 *   2. each scheduled job's last record — WHEN, at what odometer and BY WHOM;
 *   3. the last few entries as filed, pending ones included — because "somebody
 *      already put this in yesterday" is the duplicate this panel exists to stop.
 *
 * Maintenance only. On a petrol claim it would be noise beside the since-last-fill
 * line, so it stays hidden.
 */
function flRenderLastMaint(lm) {
    const box = document.getElementById('flNewLastMaint');
    if (!box) return;
    if (!lm || flNewCat !== 'Maintenance') { box.style.display = 'none'; box.innerHTML = ''; return; }

    const tone = s => s === 'overdue' ? '#b91c1c' : (s === 'due_soon' ? '#b45309' : '#15803d');
    const dueText = t => t.due_in_km === null || t.due_in_km === undefined
        ? 'never recorded'
        : (t.due_in_km < 0 ? flNum(-t.due_in_km) + ' km overdue' : 'due in ' + flNum(t.due_in_km) + ' km');

    let html = '';

    const o = lm.overall;
    if (o && o.due_in_km !== null && o.due_in_km !== undefined) {
        html += '<div style="font-weight:700;color:' + tone(o.state) + ';margin-bottom:5px;">'
            + '🛢 ' + (o.due_type_name ? flEsc(o.due_type_name) : 'Service') + ' — '
            + (o.due_in_km < 0 ? flNum(-o.due_in_km) + ' km overdue' : 'due in ' + flNum(o.due_in_km) + ' km')
            + '</div>';
    } else if (o && o.basis === 'time' && o.due_in_days !== null && o.due_in_days !== undefined && o.due_text) {
        /* ⚠ The summary can be a TIME-based job now (29-Sep-2026): no km figure, so the headline
             used to vanish. The server's own phrase ("5 days overdue") says it instead. */
        html += '<div style="font-weight:700;color:' + tone(o.state) + ';margin-bottom:5px;">'
            + '🛢 ' + (o.due_type_name ? flEsc(o.due_type_name) : 'Service') + ' — ' + flEsc(o.due_text)
            + '</div>';
    }

    const rows = (lm.per_type || []).filter(t => t.last_meter !== null && t.last_meter !== undefined);
    if (rows.length) {
        html += '<div style="color:#6b7280;font-weight:700;font-size:10.5px;letter-spacing:.03em;'
            + 'text-transform:uppercase;margin-bottom:3px;">Last done on this bike</div>';
        rows.forEach(t => {
            html += '<div style="display:flex;gap:6px;align-items:baseline;">'
                + '<span style="color:#111827;font-weight:600;">' + flEsc(t.name) + '</span>'
                + '<span style="color:#6b7280;">' + flNum(t.last_meter) + ' km'
                + (t.last_at ? ' · ' + flDate(t.last_at) : '')
                + (t.last_by ? ' · ' + flEsc(t.last_by) : '')
                + (t.covered_by ? ' · <i>via ' + flEsc(t.covered_by) + '</i>' : '')
                + '</span>'
                + '<span style="margin-left:auto;white-space:nowrap;color:' + tone(t.state) + ';font-weight:600;">'
                + dueText(t) + '</span></div>';
        });
    }

    const recent = (lm.recent || []).slice(0, 3);
    if (recent.length) {
        html += '<div style="color:#6b7280;font-weight:700;font-size:10.5px;letter-spacing:.03em;'
            + 'text-transform:uppercase;margin:7px 0 3px;">Recent entries</div>';
        recent.forEach(r => {
            html += '<div style="color:#6b7280;">'
                + flDate(r.date) + ' · ' + flEsc(r.kind)
                + ' · Rs ' + flNum(r.amount)
                + (r.meter ? ' · ' + flNum(r.meter) + ' km' : '')
                + ' · ' + flEsc(r.by_name || '')
                + (r.is_pending ? ' <b style="color:#b45309;">(pending)</b>' : '')
                + '</div>';
        });
    }

    if (!html) {
        html = '<span style="color:#9ca3af;">No maintenance recorded on this bike yet.</span>';
    }
    box.innerHTML = html;
    box.style.display = '';
}

/**
 * "That's N km since his last fill" while the manager types the odometer — the
 * same figure that will sit on the claim for whoever approves it. Petrol only,
 * and silent unless we actually have a previous fill reading to measure from.
 */
function flNewMeterTyped() {
    const el = document.getElementById('flNewSince');
    const sel = document.getElementById('flNewRider');
    const r = ((flData && flData.riders) || []).find(x => String(x.user_id) === String(sel.value));
    const prev = r ? r.last_fill_meter : null;
    const now = parseInt(document.getElementById('flNewMeter').value, 10);

    if (flNewCat !== 'Petrol' || !prev || !now || isNaN(now)) { el.style.display = 'none'; return; }
    // ⭐ The reading belongs to the BIKE he holds, so when the previous tank was
    //   somebody else's the hint says whose. "his last fill" on a bike that changed
    //   hands last night is the error this whole change exists to remove.
    // (written with textContent below, so no escaping — and none must be added)
    const who = r && r.last_fill_by ? (r.last_fill_by + '’s') : 'his';
    const gap = now - prev;
    if (gap <= 0) {
        el.style.color = '#b45309';
        el.textContent = '⚠ That is not above ' + who + ' last fill reading (' + flNum(prev) + ' km).';
    } else if (gap > 2000) {
        el.style.color = '#b45309';
        el.textContent = '⚠ ' + flNum(gap) + ' km since ' + who + ' last fill (' + flNum(prev) + ') — check the reading.';
    } else {
        el.style.color = '#3730a3';
        el.textContent = '▲ ' + flNum(gap) + ' km since ' + who + ' last fill (' + flNum(prev) + ' km).';
    }
    el.style.display = 'block';
}

// =============================================
// ⚙️ MAINTENANCE TYPES — the manager's own category list
// Server is the authority: every write returns the full list and we re-render
// from that, so the screen can never drift from what was actually stored.
// =============================================
const FL_TYPES_URL = '/orders/riders-map/fleet/maintenance-types';

function flOpenTypes() {
    document.getElementById('flTypesModal').style.display = 'flex';
    flResetTypeForm();
    flLoadTypes();
}
function flCloseTypes() { document.getElementById('flTypesModal').style.display = 'none'; }

function flLoadTypes() {
    fetch(FL_TYPES_URL, {headers: {'Accept': 'application/json'}})
        .then(r => r.json())
        .then(flRenderTypes)
        .catch(() => { document.getElementById('flTypesBody').textContent = 'Could not load the types.'; });
}

function flRenderTypes(d) {
    const body = document.getElementById('flTypesBody');
    if (!d || !d.success) { body.textContent = 'Could not load the types.'; return; }
    if (!d.available) {
        body.innerHTML = '<i>Maintenance types are not set up on this database yet (SQL batch 12).</i>';
        document.getElementById('flTypesForm').style.display = 'none';
        return;
    }
    document.getElementById('flTypesForm').style.display = d.can_manage ? 'block' : 'none';

    const rows = d.types || [];
    if (!rows.length) { body.innerHTML = '<i>No types yet — add the first one below.</i>'; return; }

    const group = (bucket, label) => {
        const list = rows.filter(t => t.bucket === bucket);
        if (!list.length) return '';
        return '<div style="margin-bottom:10px;">'
            + '<div style="font-size:11.5px;font-weight:700;color:#6b7280;margin-bottom:4px;">' + label + '</div>'
            + list.map(t =>
                '<div style="display:flex;align-items:center;gap:8px;padding:6px 2px;border-bottom:1px solid #f3f4f6;'
                + (t.is_active ? '' : 'opacity:.55;') + '">'
                + '<div style="flex:1;min-width:0;">'
                +   '<b style="font-size:13px;">' + flEsc(t.name) + '</b>'
                +   (t.is_active ? '' : ' <span style="font-size:11px;color:#b45309;">(retired)</span>')
                /* ⭐ ONE LINE PER CLASS. Before this a single "Due every 1,000 km" stood for
                     the whole fleet, which is exactly how the van came to be judged on bike
                     numbers. Each class now states its own figure, or says it is not offered. */
                +   '<div style="font-size:11.5px;color:#6b7280;">' + flTypeDueLine(t)
                +   (t.resets_service_clock ? ' · oil service' : '') + '</div>'
                + '</div>'
                + (d.can_manage
                    ? '<button type="button" onclick="flEditType(' + t.id + ')" style="padding:3px 9px;font-size:12px;'
                      + 'border:1px solid #d1d5db;background:#fff;border-radius:6px;cursor:pointer;">Edit</button>'
                      + '<button type="button" onclick="flDeleteType(' + t.id + ',\'' + flEsc(t.name).replace(/'/g, "\\'") + '\')" '
                      + 'style="padding:3px 9px;font-size:12px;border:1px solid #fecaca;background:#fff;color:#b91c1c;'
                      + 'border-radius:6px;cursor:pointer;">' + (t.is_active ? 'Retire' : 'Delete') + '</button>'
                    : '')
                + '</div>').join('')
            + '</div>';
    };
    body.innerHTML = group('regular', '🛢️ Regular service') + group('repair', '🔧 Repair');

    // Keep the picker on the create form in step with what was just edited.
    if (flData) { flData.maint_types = rows.filter(t => t.is_active); }
    window._flTypes = rows;
    window._flTypesClassAware = !!d.class_aware;

    // The class fields exist only where the database understands them.
    const cw = document.getElementById('flTypeClassWrap');
    const lw = document.getElementById('flTypeLegacyWrap');
    const nt = document.getElementById('flTypesClassNote');
    if (cw) cw.style.display = d.class_aware ? 'block' : 'none';
    if (lw) lw.style.display = d.class_aware ? 'none' : 'block';
    if (nt) nt.style.display = d.class_aware ? 'block' : 'none';

    // A save that cleared exceptions says so, rather than leaving the manager to wonder
    // whether his "put every vehicle on it" actually reached anybody.
    if (d.message) alert(d.message);
}

/** "🏍️ every 1,000 km · 🚚 every 5,000 km" — or the old single line pre-migration. */
function flTypeDueLine(t) {
    if (!window._flTypesClassAware) return 'Due ' + flEsc(t.due_label || 'as conditions');
    const bits = [];
    if (t.bike_label) bits.push('🏍️ ' + flEsc(t.bike_label));
    if (t.van_label)  bits.push('🚚 ' + flEsc(t.van_label));
    if (!bits.length) return 'Due ' + flEsc(t.due_label || 'as conditions');
    return bits.join(' · ');
}

/**
 * Keep the two figure boxes honest about what they are asking for: which classes are
 * offered the job, and whether the number is kilometres, months or days.
 */
function flTypeClassChanged() {
    if (!window._flTypesClassAware) return;
    const applies = document.getElementById('flTypeApplies').value;
    const basis   = document.getElementById('flTypeBasis').value;
    const unit    = document.getElementById('flTypeTimeUnit').value;

    document.getElementById('flTypeUnitWrap').style.display = basis === 'time' ? 'block' : 'none';
    document.getElementById('flTypeBikeWrap').style.display = (applies === 'van')  ? 'none' : 'block';
    document.getElementById('flTypeVanWrap').style.display  = (applies === 'bike') ? 'none' : 'block';

    const word = basis === 'time' ? (unit === 'days' ? 'days' : 'months') : 'km';
    document.getElementById('flTypeBikeLbl').textContent = '🏍️ Bikes — due every (' + word + ')';
    document.getElementById('flTypeVanLbl').textContent  = '🚚 Vans — due every (' + word + ')';
    // Kilometres step in hundreds; months do not.
    ['flTypeIntervalBike', 'flTypeIntervalVan'].forEach(id => {
        const el = document.getElementById(id);
        el.step = basis === 'time' ? '1' : '100';
        el.max  = basis === 'time' ? '1200' : '200000';
    });
}

function flResetTypeForm() {
    document.getElementById('flTypeId').value = '';
    document.getElementById('flTypeName').value = '';
    document.getElementById('flTypeBucket').value = 'regular';
    document.getElementById('flTypeInterval').value = '';
    document.getElementById('flTypeResets').checked = false;
    document.getElementById('flTypeSaveBtn').textContent = 'Save';
    if (window._flTypesClassAware) {
        document.getElementById('flTypeApplies').value = 'bike';
        // ⭐ Kilometres is the default basis (owner ruling, 10-Sep): time is the
        //   exception, and a new job should not have to be un-configured.
        document.getElementById('flTypeBasis').value = 'km';
        document.getElementById('flTypeTimeUnit').value = 'months';
        document.getElementById('flTypeIntervalBike').value = '';
        document.getElementById('flTypeIntervalVan').value = '';
        flTypeClassChanged();
    }
    flTypesError('');
    flTypeBucketChanged();
}

function flTypesError(msg) {
    const el = document.getElementById('flTypesError');
    el.textContent = msg || '';
    el.style.display = msg ? 'block' : 'none';
}

/** A repair never resets the service clock — the server enforces it too. */
function flTypeBucketChanged() {
    const isRegular = document.getElementById('flTypeBucket').value === 'regular';
    document.getElementById('flTypeResetWrap').style.display = isRegular ? 'block' : 'none';
    if (!isRegular) document.getElementById('flTypeResets').checked = false;
}

function flEditType(id) {
    const t = (window._flTypes || []).find(x => x.id === id);
    if (!t) return;
    document.getElementById('flTypeId').value = t.id;
    document.getElementById('flTypeName').value = t.name;
    document.getElementById('flTypeBucket').value = t.bucket;
    document.getElementById('flTypeInterval').value = t.interval_km || '';
    document.getElementById('flTypeResets').checked = !!t.resets_service_clock;
    document.getElementById('flTypeSaveBtn').textContent = 'Save changes';

    if (window._flTypesClassAware) {
        document.getElementById('flTypeApplies').value = t.applies_to || 'bike';
        document.getElementById('flTypeBasis').value   = t.basis || 'km';
        if ((t.basis || 'km') === 'time') {
            /* ⚠ Days are stored; months are shown when the figure divides evenly, which
                 is how "every 6 months" round-trips instead of becoming "every 180 days"
                 the moment somebody opens the form. */
            const d = t.interval_days || t.interval_days_van || 0;
            const asMonths = d > 0 && d % 30 === 0;
            document.getElementById('flTypeTimeUnit').value = asMonths ? 'months' : 'days';
            const conv = v => (v ? (asMonths ? (v / 30) : v) : '');
            document.getElementById('flTypeIntervalBike').value = conv(t.interval_days);
            document.getElementById('flTypeIntervalVan').value  = conv(t.interval_days_van);
        } else {
            document.getElementById('flTypeTimeUnit').value = 'months';
            document.getElementById('flTypeIntervalBike').value = t.interval_km || '';
            document.getElementById('flTypeIntervalVan').value  = t.interval_km_van || '';
        }
        flTypeClassChanged();
    }
    flTypesError('');
}

function flSaveType() {
    const id = document.getElementById('flTypeId').value;
    const name = document.getElementById('flTypeName').value.trim();
    if (!name) { flTypesError('Give the type a name.'); return; }

    const body = {
        type_name: name,
        bucket: document.getElementById('flTypeBucket').value,
        interval_km: parseInt(document.getElementById('flTypeInterval').value, 10) || 0,
        resets_service_clock: document.getElementById('flTypeResets').checked,
        is_active: true,
    };

    if (window._flTypesClassAware) {
        const applies = document.getElementById('flTypeApplies').value;
        const basis   = document.getElementById('flTypeBasis').value;
        const unit    = document.getElementById('flTypeTimeUnit').value;
        const n = id => parseInt(document.getElementById(id).value, 10) || 0;
        const bike = n('flTypeIntervalBike'), van = n('flTypeIntervalVan');

        body.applies_to = applies;
        body.basis      = basis;
        if (basis === 'time') {
            const mult = unit === 'days' ? 1 : 30;
            body.interval_days     = bike * mult;
            body.interval_days_van = van * mult;
            body.interval_km = 0; body.interval_km_van = 0;
        } else {
            body.interval_km     = bike;
            body.interval_km_van = van;
            body.interval_days = 0; body.interval_days_van = 0;
        }
    }

    /**
     * ⭐⭐ ASK BEFORE CHANGING A STANDARD (owner, 10-Sep): the vehicles carrying their own
     *    value for this job would otherwise ignore the change in silence. This is the same
     *    promise the old fleet-wide modal made, now per job and per class — and the list
     *    comes from the server, so the phone and the desk name the same machines.
     */
    const go = (clear) => {
        if (clear) { body.clear_overrides = true; body.clear_overrides_class = 'both'; }
        fetch(FL_TYPES_URL + (id ? '/' + id : ''), {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
                      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''},
            body: JSON.stringify(body)
        }).then(r => r.json()).then(d => {
            if (!d.success) { flTypesError(d.message || 'Could not save.'); return; }
            flRenderTypes(d);
            flResetTypeForm();
        }).catch(() => flTypesError('Could not save. Please try again.'));
    };

    // A NEW type can have no exceptions yet, so it never asks.
    if (!id || !window._flTypesClassAware) { go(false); return; }

    fetch(FL_TYPES_URL + '/' + id + '/exceptions', {headers: {'Accept': 'application/json'}})
        .then(r => r.json())
        .then(d => {
            const ex = (d && d.exceptions) || [];
            if (!ex.length) { go(false); return; }
            const lines = ex.map(e => '• ' + (e.vehicle_class === 'van' ? '🚚 ' : '🏍️ ')
                                    + e.name + ' — ' + (e.own_label || '')).join('\n');
            go(confirm(ex.length + (ex.length === 1 ? ' vehicle has' : ' vehicles have')
                + ' their own schedule for "' + name + '" and will IGNORE this change:\n\n'
                + lines + '\n\nOK  = put them on the new standard too (clears their own value)\n'
                + 'Cancel = leave them on their own schedule'));
        })
        // Advisory only: a settings screen must never be blocked by a lookup.
        .catch(() => go(false));
}

function flDeleteType(id, name) {
    if (!confirm('Remove "' + name + '"? If it has already been used it is kept on those claims and just hidden from the list.')) return;
    fetch(FL_TYPES_URL + '/' + id, {
        method: 'DELETE',
        headers: {'Accept': 'application/json',
                  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''}
    }).then(r => r.json()).then(d => {
        if (!d.success) { flTypesError(d.message || 'Could not remove.'); return; }
        flRenderTypes(d);
        flResetTypeForm();
    }).catch(() => flTypesError('Could not remove. Please try again.'));
}

/**
 * Fill the "what was done" list from the manager's own types, grouped
 * Regular / Repair. Leaves the built-in two-option list alone when types are not
 * configured yet, so the form still works before SQL batch 12 runs.
 */
function flFillMaintTypes() {
    const sel = document.getElementById('flNewServiceType');
    let types = (flData && flData.maint_types) ? flData.maint_types : [];
    if (!sel || !types.length) return;

    /**
     * ⭐⭐ THE MACHINE'S OWN LIST WHEN WE KNOW THE MACHINE (Sep-2026).
     *
     * `flData.maint_types` is the whole company list with BIKE numbers — it is the
     * Bikes screen's payload and has no single vehicle in scope. Once a rider is
     * chosen the server has already told us this machine's resolved schedule
     * (`flNewSched`, from vehicle-for-user), so the picker offers exactly the jobs
     * that apply to it, labelled with ITS figures.
     *
     * ⚠ A job absent from the resolved schedule is kept, not dropped — an "as
     *   conditions" repair has no countdown yet must still be billable. Only the
     *   LABEL comes from the schedule; the list itself only loses jobs the class is
     *   not offered at all.
     */
    if (Array.isArray(flNewSched) && flNewSched.length) {
        const onThis = new Set(flNewSched.map(s => String(s.id)));
        const scheduled = types.filter(t => onThis.has(String(t.id)));
        // Repairs and "as conditions" jobs carry no schedule row, so they are added
        // back rather than being filtered out with the jobs that truly do not apply.
        const unscheduled = types.filter(t => !onThis.has(String(t.id))
                                              && !(Number(t.interval_km) > 0));
        types = scheduled.concat(unscheduled);
    }

    const dueFor = t => {
        if (Array.isArray(flNewSched)) {
            const row = flNewSched.find(s => String(s.id) === String(t.id));
            if (row) return row.interval_label || ('every ' + flNum(row.interval_km) + ' km');
        }
        return t.due_label || '';
    };

    const group = (bucket, label) => {
        const rows = types.filter(t => t.bucket === bucket);
        if (!rows.length) return '';
        return '<optgroup label="' + label + '">' + rows.map(t =>
            '<option value="type:' + t.id + '" data-bucket="' + t.bucket + '"' +
            ' data-due="' + flEsc(dueFor(t)) + '">' + flEsc(t.name) + '</option>').join('') + '</optgroup>';
    };
    sel.innerHTML = '<option value="">Not a bike / other maintenance</option>'
        + group('regular', '🛢️ Regular service')
        + group('repair', '🔧 Repair');
}

/** The chosen option's bucket — drives the meter requirement. */
function flSvcBucket() {
    const sel = document.getElementById('flNewServiceType');
    const opt = sel ? sel.options[sel.selectedIndex] : null;
    if (!opt || !opt.value) return '';
    // Typed option carries its bucket; the legacy fallback list carries the raw
    // machine value.
    if (opt.dataset && opt.dataset.bucket) return opt.dataset.bucket;
    return opt.value === 'oil_change' ? 'regular' : (opt.value === 'repair' ? 'repair' : '');
}

function flNewSvcChanged() {
    const sel = document.getElementById('flNewRider');
    const opt = sel.options[sel.selectedIndex];
    // ⭐ THE CHOSEN MACHINE DECIDES, not the rider's month-level label. `data-company`
    //   is `r.bike`, i.e. "who pays for his fuel in general" — on a mixed day that is
    //   the wrong question, and it was demanding a meter for an own-bike claim (or
    //   waiving it for a van one) purely because of what he usually rides.
    const picked = (flNewCtx && flNewCtx.vehicles || []).find(v => v.vehicle_id === flNewVehId) || null;
    const isCompany = picked ? !!picked.is_company
                             : (opt && opt.dataset && opt.dataset.company === '1');
    const bucket = flSvcBucket();
    // Required on a company bike for petrol, and for a SCHEDULED service (a
    // service with no odometer can never reset that job's countdown).
    // A repair never needs it.
    const need = isCompany && (flNewCat === 'Petrol' || bucket === 'regular');
    document.getElementById('flNewMeterReq').textContent = need ? '(required)' : '(optional)';
    document.getElementById('flNewMeterHint').textContent = flNewCat === 'Petrol'
        ? 'The odometer at the moment of filling — this is what links the fill to the km ridden.'
        : (bucket === 'regular' ? 'The odometer at the service — this resets that job\'s own countdown on approval.' : '');

    // "every 1,200 km" / "as conditions" — how often this job is due.
    // ⭐ THIS BIKE's schedule when the registry could name the machine, the type's own
    //   standard one otherwise. `data-due` carries only the latter (the option list is
    //   built from the raw type rows, with no vehicle in scope), which is how a picker
    //   could print a different interval from the service panel beside it.
    const due = document.getElementById('flNewSvcDue');
    const svcOpt = document.getElementById('flNewServiceType').selectedOptions[0];
    let dueTxt = (svcOpt && svcOpt.dataset && svcOpt.dataset.due) ? svcOpt.dataset.due : '';
    const tid = svcOpt ? /^type:(\d+)$/.exec(svcOpt.value || '') : null;
    if (tid && Array.isArray(flNewSched)) {
        const row = flNewSched.find(s => String(s.id) === tid[1]);
        // ⭐ The phrase is the server's, so a van reads van numbers and a time-based
        //   job reads months rather than a figure in the wrong unit.
        if (row && (row.has_schedule || row.interval_km > 0)) {
            dueTxt = (row.interval_label || ('every ' + flNum(row.interval_km) + ' km'))
                   + (row.interval_overridden && row.interval_source_label
                      ? ' — ' + row.interval_source_label : '');
        }
    }
    if (due) due.textContent = dueTxt ? ('Due ' + dueTxt) : '';
}

function flSubmitNew() {
    // The same button serves both jobs — the modal is shared, so the verb is too.
    if (flEditId) { flSubmitEdit(); return; }

    const uid    = document.getElementById('flNewRider').value;
    const amount = parseFloat(document.getElementById('flNewAmount').value);
    const date   = document.getElementById('flNewDate').value;
    const meter  = document.getElementById('flNewMeter').value;
    const svc    = document.getElementById('flNewServiceType').value;
    const note   = document.getElementById('flNewNote').value;

    const paySel  = document.getElementById('flNewPaySource');
    const payId   = (paySel && paySel.value) ? parseInt(paySel.value, 10) : null;
    const payOpt  = paySel ? paySel.options[paySel.selectedIndex] : null;
    const payIsOnline = !!(payOpt && payOpt.dataset && payOpt.dataset.online === '1');

    if (!uid) { flNewError('Choose which rider this is for.'); return; }
    if (!amount || amount <= 0) { flNewError('Enter the amount.'); return; }
    if (!date) { flNewError('Choose the date.'); return; }
    // Caught here as well as server-side so the manager fixes it in place
    // instead of losing the form to a 422.
    if (payIsOnline && !flNewBankId) { flNewError('Choose which bank this online payment came from.'); return; }

    const btn = document.getElementById('flNewSubmit');
    btn.disabled = true; btn.textContent = 'Creating…';
    flNewError('');

    const body = {
        category_id: flExpenseCategoryId,
        requester_user_id: parseInt(uid, 10),
        title: flNewCat,
        description: note || (flNewCat + ' recorded from the Bikes screen'),
        amount: amount,
        expense_category: flNewCat,
        expense_date: date,
    };
    if (meter !== '' && !flNewMetered) body.meter_at_fill = parseInt(meter, 10);

    // ⭐⭐ WHICH MACHINE — and, for an own bike with real kilometres, the PER-KM claim.
    //
    // Filing the metered trio (attendance row + distance + rate) makes this the SAME
    // self-auditing row the rider's own app files, instead of an untethered cash claim
    // for the same kilometres. That is what lets the one-per-day rule see it, what makes
    // it show as claimed on his phone, and what keeps the money on the right machine.
    if (flNewVehId) body.vehicle_id = flNewVehId;
    if (flNewMetered && flNewCtx && flNewCtx.attendance_id) {
        const pickedVeh = (flNewCtx.vehicles || []).find(v => v.vehicle_id === flNewVehId);
        body.attendance_id  = flNewCtx.attendance_id;
        body.meter_distance = pickedVeh ? pickedVeh.km : null;
        body.petrol_rate    = flNewCtx.petrol_rate;
    }

    // A typed option sends the type id and lets the SERVER derive service_type
    // from its bucket; the legacy fallback list still sends the raw value.
    if (flNewCat === 'Maintenance' && svc) {
        if (svc.indexOf('type:') === 0) body.maintenance_type_id = parseInt(svc.slice(5), 10);
        else body.service_type = svc;
    }
    if (payId) body.payment_source_account_id = payId;
    /* 🧾 The service this bill belongs to. The server validates it, inherits its reading, and
       refuses if that service already carries a live bill — the double-money guard. */
    if (flNewSvcLogId) {
        /* ⭐ One receipt for several jobs of the visit (29-Sep-2026): the ticked mates ride along
             as `service_log_ids`, and the server checks they are one visit with no live bill. */
        const mates = Array.from(document.querySelectorAll('input[data-billmate="1"]:checked')).map(c => parseInt(c.value, 10));
        if (mates.length) body.service_log_ids = [flNewSvcLogId].concat(mates);
        else body.service_log_id = flNewSvcLogId;
    }
    // Only ever sent with a bank source — the server drops it otherwise, but a
    // cash claim should not carry a bank id in the first place.
    if (payId && payIsOnline && flNewBankId) body.receiving_account_id = flNewBankId;

    fetch('/requests', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        },
        body: JSON.stringify(body)
    })
    .then(r => r.json().then(j => ({ok: r.ok, j})))
    .then(({ok, j}) => {
        // The server's own message is shown verbatim — it is the one that
        // explains WHY (meter required, reading out of range, double tap).
        if (!ok || !j.success) { flNewError(j.message || 'Could not create the request.'); return; }
        flCloseNew();
        flLoad(flMonth, true);              // money + counts move immediately
        if (flSelected) flSelectRider(flSelected);
    })
    .catch(() => flNewError('Could not create the request. Please try again.'))
    .finally(() => { btn.disabled = false; btn.textContent = 'Create request'; });
}

// ---- photo lightbox ----
function flPhoto(url) {
    document.getElementById('flLightboxImg').src = url;
    document.getElementById('flLightbox').style.display = 'flex';
}
function flClosePhoto() {
    document.getElementById('flLightbox').style.display = 'none';
    document.getElementById('flLightboxImg').src = '';
}

// ---- helpers ----
// Why a day has no distance. Leave/absent are states, not failures — only
// "worked but no usable reading" is worth an alert. Mirrors the mobile screen
// and the attendance screen's own classification of the same date.
const FL_DAY_TEXT = {
    leave: '🌴 on leave',
    absent: '— absent',
    no_attendance: '— no attendance recorded',
    in_progress: '— still on shift',
    no_reading: '⚠ worked, no meter reading',
    no_start: '⚠ worked, start meter missing',
    no_end: '⚠ worked, end meter missing',
    unusable: '⚠ meter reading unusable',
};

/** service_type → words a manager reads. Regular service is what resets the due-clock. */
function flServiceLabel(t) {
    return {oil_change: 'regular service', general: 'general service', repair: 'repair', other: 'other'}[t] || 'maintenance';
}
function flNum(n) {
    if (n === null || n === undefined) return '—';
    return Number(n).toLocaleString('en-PK', { maximumFractionDigits: 0 });
}
function flRs(n) { return 'Rs ' + flNum(n); }
function flDate(d) {
    if (!d) return '—';
    const x = new Date(String(d).substring(0, 10) + 'T12:00:00');
    if (isNaN(x)) return d;
    return x.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
}
function flMonthLabel(m) {
    if (!m) return '';
    const x = new Date(m + '-01T12:00:00');
    return isNaN(x) ? m : x.toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
}
// ⭐ WHAT THE MACHINE IS — the registry's `vtype` ('bike' | 'van') and nothing else.
//   `is_company` answers a different question (whose fuel we buy) and was standing in
//   for this: it drew a company BIKE as a van, and every van in a company/own pairing
//   as a bike. Unknown vtype ⇒ bike, which is what those payloads always showed.
function flMachineIcon(v) {
    return (v && String(v.vtype || '') === 'van') ? '🚚' : '🏍️';
}
// The company/own pill: WHOSE it is decides between the machine and the person, and
// only then does the machine's own shape pick the glyph.
function flOwnerIcon(m) {
    if (!m || !m.is_company) return '👤';
    return String(m.vtype || '') === 'van' ? '🚚' : '🏍';
}
function flEsc(s) {
    return String(s === null || s === undefined ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

/**
 * ⚠⚠ A STRING ARGUMENT INSIDE A DOUBLE-QUOTED `onclick` ATTRIBUTE.
 *
 * `JSON.stringify(name)` is the obvious way to quote a JS string and it is WRONG here:
 * it emits real double quotes, and the first one ENDS the attribute. A plate like
 * AY-4771 produced `onclick="flvOpenSchedule(1,"AY-4771")"`, which the parser reads as
 * `onclick="flvOpenSchedule(1,"` followed by rubbish — the button silently does nothing.
 *
 * Escaping the quotes as entities is what makes it survive the HTML layer: the parser
 * decodes `&quot;` back to `"` before the JS is compiled, so the handler receives the
 * string intact. `flEsc` handles the rest (a nickname is user-typed and can hold `<`).
 */
function flJsArg(s) {
    // TWO layers, in this order. The HTML parser decodes the attribute FIRST, then the
    // JS engine parses what it gets — so the backslashes must be applied before the
    // entity encoding, or the quote they protect arrives already decoded and ends the
    // string early.
    const js = String(s === null || s === undefined ? '' : s)
        .replace(/\\/g, '\\\\')
        .replace(/"/g, '\\"');
    return flEsc('"' + js + '"');
}

/* ═══════════════════════════════════════════════════════════════════════════
   VEHICLES (Aug-2026) — the machines, and who has them.

   Kept in its own block with an `flv` prefix so it cannot collide with the costs
   screen above. The two views share only the tab and the toggle; switching
   between them never reloads the other's data.
   ═══════════════════════════════════════════════════════════════════════════ */

const FLV_BASE = FL_BASE + '/vehicles';
let flvMode      = 'riders';   // which view the tab is showing
let flvData      = null;       // last /vehicles payload
let flvOpenId    = null;       // vehicle whose profile is expanded
let flvLoaded    = false;      // fetched at least once
let flvPreviewSeq = 0;         // guards against a slow preview landing after a newer one

/** Switch between the costs table and the machines. */
/**
 * ⚠ THREE modes since 15-Sep, not two. `isVeh` used to double as "hide the costs view", which
 *   was true while Vehicles was the only other mode. It no longer is — the Issues board must
 *   hide exactly the same group — so the costs chrome now keys off `isCosts`, and the two
 *   non-costs views each own their own wrapper.
 */
function flSetMode(mode) {
    flvMode = mode;
    const isVeh    = mode === 'vehicles';
    const isIssues = mode === 'issues';
    const isCosts  = !isVeh && !isIssues;

    document.getElementById('flModeRiders').classList.toggle('on', isCosts);
    document.getElementById('flModeVehicles').classList.toggle('on', isVeh);
    const mi = document.getElementById('flModeIssues');
    if (mi) mi.classList.toggle('on', isIssues);

    // Everything the COSTS view owns — including the month picker, which means
    // nothing here — is hidden as one group.
    ['flMonthWrap', 'flHeadline', 'flVerdict', 'flNotes', 'flDetail'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        if (!isCosts) { el.dataset.flPrevDisplay = el.style.display || ''; el.style.display = 'none'; }
        else if (el.dataset.flPrevDisplay !== undefined) { el.style.display = el.dataset.flPrevDisplay; }
    });
    const tw = document.querySelector('#fleetView .fl-tablewrap');
    if (tw) tw.style.display = isCosts ? '' : 'none';
    const nr = document.querySelector('#fleetView .fl-newreq');
    if (nr) nr.style.display = isCosts ? '' : 'none';

    document.getElementById('flVehWrap').style.display = isVeh ? '' : 'none';
    const iw = document.getElementById('flIssWrap');
    if (iw) iw.style.display = isIssues ? '' : 'none';

    if (isVeh && !flvLoaded) flvLoad();
    if (isIssues && typeof flIssLoad === 'function') {
        // Always re-read on entry: this is a NOW view, and a stale board is worse than a slow one.
        flIssLoad();
        if (typeof flIssPoll === 'function') flIssPoll();
    }
}

/**
 * 🛠 The red count on the Issues tab — what is waiting on US, so the tab itself says whether it
 *    is worth opening. Asked once when Bikes first renders and refreshed whenever the board
 *    reloads; it is the same endpoint, so no extra rule can drift.
 */
function flIssBadgeFrom(totals) {
    const el = document.getElementById('flIssBadge');
    if (!el || !totals) return;
    const n = (totals.unanswered || 0) + (totals.waiting_on_us || 0)
            + (totals.workshop_missed || 0) + (totals.urgent || 0);
    el.textContent = n;
    el.style.display = n ? '' : 'none';
}

function flvLoad() {
    const grid = document.getElementById('flVehGrid');
    grid.innerHTML = '<div class="fl-empty">Loading…</div>';

    // ⭐⭐ ASK FOR RETIRED MACHINES TOO (Aug-22 2026).
    //
    // ⚠⚠ The renderer below has ALWAYS had a "N retired" <details> block, but this fetch never
    //    sent `include_retired`, and `VehicleService::all()` filters `is_active = 1` without it.
    //    So `retired` was permanently empty and that block was unreachable code: a machine saved
    //    with "In service" unticked vanished from the page COMPLETELY, with no way back to it from
    //    the UI at all — while the duplicate check still insisted the plate existed (CEN-455).
    //    The retire button was therefore a one-way door, which is not what "retire" means.
    fetch(FLV_BASE + '?include_retired=1', { headers: { 'Accept': 'application/json' } })
        .then(r => r.status === 403 ? Promise.reject(new Error('403')) : r.json())
        .then(res => {
            if (!res.success) throw new Error(res.message || 'Failed');
            flvLoaded = true;
            flvData = res;

            // The SQL has not been run yet — say so plainly instead of showing an
            // empty grid that looks like "there are no vehicles".
            if (res.available === false) {
                document.getElementById('flVehIntro').innerHTML =
                    '<div class="fl-vwarn">The vehicle list is not set up on this server yet '
                    + '(database batch 13 has not been run). Nothing else on this page is affected.</div>';
                grid.innerHTML = '';
                return;
            }
            flvRenderIntro(res);
            flvRenderGrid(res.vehicles || []);

            /* ⭐ DEEP LINK (Qasim/Shabib, 5-Sep): a toast's "Open Bikes →" now names the
               machine (`#bikes?vehicle=ID`, parked by index.blade.php as window.flDeepLink).
               Honour it ONCE, here, where the list it belongs to has just arrived — and only
               if the machine is actually in that list, so a stale link cannot open a panel
               for a bike this manager may not see. Consumed immediately: a refresh or a
               tab switch afterwards behaves exactly as it always did. */
            const dl = window.flDeepLink;
            if (dl && dl.vehicle) {
                const wanted = parseInt(dl.vehicle, 10);
                const listed = (res.vehicles || []).some(v => parseInt(v.id, 10) === wanted);
                window.flDeepLink = dl.ticket ? { vehicle: null, ticket: dl.ticket } : null;
                if (listed) flvOpen(wanted);
            }
        })
        .catch(err => {
            grid.innerHTML = '<div class="fl-empty">'
                + (err.message === '403'
                    ? 'You do not have permission to see the fleet.'
                    : 'Could not load the vehicles. Please try again.')
                + '</div>';
        });
}

function flvRenderIntro(res) {
    const el = document.getElementById('flVehIntro');
    let h = 'Every machine the company runs, and who has it now. '
          + 'Meter readings and service history follow the <b>vehicle</b>, so a bike keeps its own '
          + 'record when it changes hands.';

    // Honest about the fact that Phase A records but does not yet enforce.
    if (res.rules_enabled === false) {
        h += ' <span style="color:#92400e;">Assignments are being recorded now; the meter rules '
           + 'still follow the rider until this is switched on.</span>';
    }
    if (res.can_manage) {
        h += ' <button type="button" class="fl-vbtn primary" style="margin-left:8px;" '
           + 'onclick="flvOpenEdit(null)">➕ Add a vehicle</button>';
    }
    // 📍 The PERMANENT door to the meet-up points list.
    //
    // ⚠ Without this the list was only reachable from the ⚙ on the store's van
    //   panel — which only exists WHILE A VAN IS OUT. So the stops a van run
    //   depends on could not be set up before the first van run: chicken and egg.
    //   Vehicles is the right home for it (it is van infrastructure, next to the
    //   van itself), and it is always reachable. `vpOpenStops` lives in the
    //   van-panel partial, which is in the DOM on this page whether or not the
    //   live view is showing — so the same modal serves both doors.
    if (typeof vpOpenStops === 'function') {
        h += ' <button type="button" class="fl-vbtn" style="margin-left:6px;" '
           + 'onclick="vpOpenStops()" title="Where riders collect their orders from the van">'
           + '📍 Meet-up points</button>';
    }
    el.innerHTML = h;
}

/**
 * ⭐⭐ THE FLEET AS WORKING PAIRS, NOT AS A LIST OF OBJECTS (owner ruling R4, Aug-6).
 *
 * The old grid was one card per machine, which meant a rider on a company bike
 * appeared TWICE — once on the bike he rides and once as the ⚠ "Not assigned to
 * anyone" own bike he has parked. And a rider with NO machine appeared nowhere at
 * all, which is exactly the state that most needs a manager's attention.
 *
 * So the screen is now two sides:
 *   LEFT  "On the road"    — machine + rider, one card per working pair
 *   RIGHT "Parked & spare" — idle machines, then RIDERS WITH NO MACHINE
 *
 * A parked own bike says "Danish is on DCR-799" instead of raising an alarm about
 * a bike that is exactly where it should be. And "no bike" becomes a visible,
 * assignable state rather than an absence you have to notice.
 */
function flvRenderGrid(list) {
    const grid = document.getElementById('flVehGrid');
    if (!list.length) {
        grid.innerHTML = '<div class="fl-empty">No vehicles yet.</div>';
        return;
    }

    const onRoad = list.filter(v => v.keeper_user_id && v.is_active);
    const idle   = list.filter(v => !v.keeper_user_id && v.is_active);
    const retired = list.filter(v => !v.is_active);

    // Who is on a machine right now — so a parked own bike can name where its
    // owner actually is instead of reading as a fault.
    const keeperOf = {};
    onRoad.forEach(v => { keeperOf[v.keeper_user_id] = v; });

    // ⭐ RIDERS WITH NO MACHINE. Derived from the roster's has_vehicle, which the
    //   server computes from the OPEN ASSIGNMENT (never the stale profile mirror).
    const freeRiders = ((flvData && flvData.riders) || []).filter(r => !r.has_vehicle);

    /* ⚠ NEEDS-ATTENTION FIRST, WITHIN each column (owner review, Sep-2026). The columns
       themselves stay — "on the road" and "parked" answer a different question and merging
       them would lose that — but inside each one the machine that wants something now sorts
       above the ones that do not. Rank comes from the SAME server map the phone sorts by
       (FleetAttentionService); an older payload leaves every rank undefined and the order is
       exactly what it was. */
    const attnRank = v => (flvData && flvData.attention
        && flvData.attention[String(v.id)] ? flvData.attention[String(v.id)].rank : 99);
    const company = a => (a.is_company ? 0 : 1);
    onRoad.sort((a, b) => attnRank(a) - attnRank(b) || company(a) - company(b) || a.name.localeCompare(b.name));
    idle.sort((a, b) => attnRank(a) - attnRank(b) || company(a) - company(b) || a.name.localeCompare(b.name));

    /* A count at the top, so a calm list is trustworthy rather than merely quiet. */
    const attnCount = list.filter(v => v.is_active && attnRank(v) < 99).length;
    const attnBanner = attnCount
        ? '<div class="fl-vattn-head">⚠ ' + attnCount + (attnCount === 1 ? ' bike needs' : ' bikes need')
          + ' attention — shown first in each column</div>'
        : '<div class="fl-vattn-head calm">✓ Nothing needs attention right now</div>';

    const left = '<div class="fl-vcol">'
        + '<h4 class="fl-vcolh">🛣️ On the road <span>' + onRoad.length + '</span></h4>'
        // ⚠ NOT `.map(flvCard)` — map passes the INDEX as the second argument, which
        //   would arrive as the keeper lookup. Harmless for these cards today, but
        //   only by accident; pass it explicitly so it stays true.
        + (onRoad.length ? onRoad.map(v => flvCard(v, keeperOf)).join('')
            : '<div class="fl-empty" style="padding:14px;">Nobody is on a machine right now.</div>')
        + '</div>';

    const right = '<div class="fl-vcol">'
        + '<h4 class="fl-vcolh">🅿️ Parked &amp; spare <span>'
            + (idle.length + freeRiders.length) + '</span></h4>'
        + (idle.length ? idle.map(v => flvCard(v, keeperOf)).join('') : '')
        + (freeRiders.length
            ? '<div class="fl-vsubh">Riders with no machine</div>'
              + freeRiders.map(flvFreeRiderCard).join('')
            : '')
        + (!idle.length && !freeRiders.length
            ? '<div class="fl-empty" style="padding:14px;">Every machine is out and every rider has one.</div>'
            : '')
        + (retired.length
            ? '<details class="fl-vretired"><summary>' + retired.length + ' retired</summary>'
              + retired.map(v => flvCard(v, keeperOf)).join('') + '</details>'
            : '')
        + '</div>';

    grid.innerHTML = attnBanner + '<div class="fl-vsplit">' + left + right + '</div>';
}

/**
 * A rider holding nothing. The point of showing him at all: under the registry
 * rules he is no longer asked for meter readings and cannot file his own fuel —
 * correct while he really has no bike, and quietly wrong the moment he is riding
 * something nobody recorded. Putting him on screen with an Assign button is what
 * keeps that from going unnoticed.
 */
function flvFreeRiderCard(r) {
    const canManage = flvData && flvData.can_manage;
    const first = (r.name || '').split(' ')[0];
    return ''
      + '<div class="fl-vcard fl-vrider">'
      +   '<div class="fl-vtop">'
      +     '<div class="fl-vnophoto">👤</div>'
      +     '<div style="min-width:0;flex:1;">'
      +       '<div class="fl-vname">' + flEsc(r.name)
      +         '<span class="fl-vtag none">no bike</span></div>'
      +       '<div class="fl-vsub">'
      +         (r.free_since
                    ? 'Has had no machine since ' + flvDate(r.free_since)
                    : 'No machine on record')
      +       '</div>'
      +     '</div>'
      +   '</div>'
      +   '<div class="fl-vnote">'
      +     (flvData && flvData.rules_enabled
                ? 'Not asked for meter readings, and cannot file his own fuel, while this is the case.'
                : 'Once vehicle rules are switched on he will not be asked for meter readings.')
      +   '</div>'
      +   (canManage
            ? '<div class="fl-vfoot">'
              + '<button type="button" class="fl-vbtn primary" onclick="flvAssignToRider(' + r.user_id + ')">'
              +   '➕ Give him a bike</button>'
              // ⚠⚠ ID ONLY — never a name in an inline onclick. JSON.stringify puts
              //   DOUBLE QUOTES inside the double-quoted attribute, which truncates
              //   it ("flvNewOwnBike(95," + junk attributes) and makes the click a
              //   silent SyntaxError. That is why "New own bike" did nothing for
              //   Rajab on prod AND dev (14 Aug) — broken for every rider since the
              //   button shipped, invisible in every log because no request ever
              //   fired. The name is looked up from flvData inside the function.
              + '<button type="button" class="fl-vbtn" onclick="flvNewOwnBike(' + r.user_id + ')"'
              +   ' title="Register a bike he owns himself and assign it to him">'
              +   '🏍️ New own bike</button>'
              + '</div>'
            : '')
      + '</div>';
}

/**
 * ➕ Give a machine to a rider — the reverse of the usual flow (pick the man, then
 * the machine). Opens the ordinary assign modal on the first spare, with the rider
 * preselected; the vehicle dropdown lets the manager change it.
 */
function flvAssignToRider(userId) {
    // ⚠⚠ THE 12:05 LESSON (7 Aug, prod): this used to preselect the first spare of
    //    ANY kind — which that morning was "Danish - own bike", and one Save put
    //    Waseem on Danish's personal machine. Another man's own bike is NEVER a
    //    quick pick: only company machines and the rider's OWN bike qualify here.
    //    (Genuinely lending X's personal bike to Y stays possible from the bike's
    //    own card, where the manager can see whose it is.)
    const spare = ((flvData && flvData.vehicles) || [])
        .filter(v => !v.keeper_user_id && v.is_active
                     && (v.is_company || v.last_keeper_user_id === userId));
    if (!spare.length) {
        alert('There is no spare company machine (and he has no own bike on record). '
            + 'Add a vehicle, take one back from another rider, or use "New own bike".');
        return;
    }
    // His own bike first — giving a man his own machine back is the common case.
    spare.sort((a, b) => (a.last_keeper_user_id === userId ? 0 : 1) - (b.last_keeper_user_id === userId ? 0 : 1));
    flvOpenAssign(spare[0].id, userId);
}

/**
 * 🏍️ Register a rider's OWN bike and hand it to him in one step — the onboarding
 * path for a new rider who arrives with his own machine (owner asked, Aug-6).
 * Without this, "where do I enter him?" is a two-screen answer nobody remembers.
 */
function flvNewOwnBike(userId) {
    // The name comes from the SAME payload that rendered the card — passing it
    // through an HTML attribute is what broke this button (see the call site).
    const rider = ((flvData && flvData.riders) || []).find(r => r.user_id === userId);
    const name = (rider && rider.name) || 'Rider';
    flvOpenEdit(null);
    document.getElementById('flvEditNick').value = name + ' - own bike';
    document.getElementById('flvEditCompany').checked = false;   // his bike, his fuel
    document.getElementById('flvEditTitle').textContent = 'Add ' + name + '’s own bike';
    flvPendingAssignUser = userId;      // handed to him the moment it is created
}

function flvCard(v, keeperOf) {
    const canManage = flvData && flvData.can_manage;
    const icon      = v.vtype === 'van' ? '🚚' : '🏍️';
    const photo     = v.photo_count > 0 && v.first_photo_url
        ? '<img class="fl-vphoto" src="' + flEsc(v.first_photo_url) + '" alt="">'
        : '<div class="fl-vnophoto">' + icon + '</div>';

    // ⭐ An idle OWN bike whose owner is out on a company machine is not a problem
    //   — it is a bike parked exactly where it should be. Saying "⚠ Not assigned to
    //   anyone" about it sent managers looking for a fault that did not exist.
    //   The owner is found from the vehicle's own name-holder: whoever last held it.
    let parkedBecause = null;
    if (!v.keeper_user_id && !v.is_company && keeperOf && v.last_keeper_user_id) {
        const on = keeperOf[v.last_keeper_user_id];
        if (on) parkedBecause = (v.last_keeper_name || 'its owner').split(' ')[0] + ' is on ' + on.name;
    }

    /* 🔁 A RIDER IS WAITING ON THIS MACHINE (Sep-2026). The grid used to look
       entirely normal while a request sat unanswered — nothing on the card, so
       unless the manager already knew to read the banner above, the ask was
       invisible. Comes from the SAME live() the banner reads, so a card can never
       advertise a request the banner has already dropped. */
    const pend = (flvData && flvData.pending_requests)
        ? flvData.pending_requests[String(v.id)] : null;
    const pendChip = pend
        ? '<div class="fl-vpending">⏳ <b>' + flEsc(pend.rider_name) + '</b> '
          + (pend.direction === 'return' ? 'wants to hand this back' : 'is asking for this')
          + ' — <span class="fl-vpending-cta">approve it above</span></div>'
        : '';

    /* ⚠ THE ATTENTION STRIP (owner review, Sep-2026): "will it be clear which bikes are
       asking for an action?" Every card looked identical, so an urgent ticket or a missed
       workshop visit was invisible until you opened the machine. Comes from the SAME
       server map the mobile list reads (FleetAttentionService) and the columns sort by the
       same rank — the desk and the phone cannot disagree about which bike is worst.
       ⚠ Rendered ONLY when something is actually wanted, so a healthy fleet stays calm.
       ⚠ `pending` is already shown by pendChip above; it is dropped here to avoid saying
         the same thing twice on one card. */
    const attn = (flvData && flvData.attention) ? flvData.attention[String(v.id)] : null;
    const attnChips = (attn && attn.flags || []).filter(f => f.k !== 'waiting');
    const attnStrip = attnChips.length
        ? '<div class="fl-vattn">' + attnChips.map(f => {
            const bad  = (f.k === 'stranded' || f.k === 'missed' || f.k === 'overdue');
            const warn = (f.k === 'today' || f.k === 'unconfirmed');
            return '<span class="fl-vattn-chip' + (bad ? ' bad' : (warn ? ' warn' : '')) + '">'
                 + flEsc(f.text) + '</span>';
          }).join('') + '</div>'
        : '';

    const keeper = v.keeper_name
        ? '<div class="fl-vkeeper">👤 <b>' + flEsc(v.keeper_name) + '</b>'
            + (v.assigned_on ? ' <span style="color:#9ca3af;">since ' + flvDate(v.assigned_on) + '</span>' : '')
          + '</div>'
        : (parkedBecause
            ? '<div class="fl-vkeeper parked">🅿️ Parked — ' + flEsc(parkedBecause) + '</div>'
            : '<div class="fl-vkeeper none">Nobody has this one — '
              + (canManage ? 'give it to a rider below.' : 'not assigned.') + '</div>');

    const s = v.service || {};
    let svc = '<span class="fl-vchip unk">service unknown</span>';
    if (s.due_in_km !== null && s.due_in_km !== undefined) {
        if (s.due_in_km < 0)        svc = '<span class="fl-vchip over">🛢 ' + flNum(-s.due_in_km) + ' km overdue</span>';
        else if (s.state === 'due_soon') svc = '<span class="fl-vchip due">🛢 due in ' + flNum(s.due_in_km) + ' km</span>';
        else                        svc = '<span class="fl-vchip ok">🛢 due in ' + flNum(s.due_in_km) + ' km</span>';
    } else if (s.basis === 'time' && s.due_in_days !== null && s.due_in_days !== undefined && s.due_text) {
        /* ⚠ A TIME-based job can be the summary now (29-Sep-2026) — it read "service unknown"
             even when overdue. Same three tones, the server's own phrase. */
        const cls = s.state === 'overdue' ? 'over' : (s.state === 'due_soon' ? 'due' : 'ok');
        svc = '<span class="fl-vchip ' + cls + '">🛢 ' + flEsc(s.due_text) + '</span>';
    }

    return ''
      + '<div class="fl-vcard' + (v.is_active ? '' : ' retired') + '" onclick="flvOpen(' + v.id + ')">'
      +   '<div class="fl-vtop">'
      +     photo
      +     '<div style="min-width:0;flex:1;">'
      +       '<div class="fl-vname">' + flEsc(v.name)
      +         '<span class="fl-vtag ' + (v.is_company ? 'co' : 'own') + '">'
      +           (v.is_company ? 'company' : 'own') + '</span>'
      +       '</div>'
      +       '<div class="fl-vsub">' + icon + ' ' + flEsc(v.make_model || (v.vtype === 'van' ? 'Van' : 'Bike'))
      +         (v.base && v.base.has_base ? ' · 📍 parked at a fixed spot' : '')
      +         (v.is_active ? '' : ' · retired')
      +       '</div>'
      +     '</div>'
      +   '</div>'
      +   keeper
      +   pendChip
      +   attnStrip
      /* ⭐ STANDING ALERT (owner ruling Aug-4): a company machine whose overnight
         and morning meter checks have nowhere to measure from. It stays on the
         card until the pin is saved, then disappears by itself — nothing to
         dismiss. Quiet for personal or unassigned machines. */
      /* 🏠 Sep-2026 — the nag is now ACTIONABLE, and the saved pin is visible.
         It used to end with "Set it on the Riders page", which is a dead end: the
         manager is standing in front of the machine looking at this card, and the
         page it points at is three clicks away behind a company-bike tick that hid
         the box. Both states open the SAME editor, which posts to the SAME endpoint
         the phone posts to.
         ⚠ Only the numeric keeper id is passed through the onclick — never a name.
         A quoted string argument inside an onclick="" attribute is the trap that
         silently kills the button (the real " ends the attribute). The editor
         fetches everything else from the server anyway. */
      +   (v.needs_home_pin
            ? '<div class="fl-vwarnpin">⚠ No home location for '
              + flEsc((v.keeper_name || 'the rider').split(' ')[0])
              + ' — overnight and morning meter checks cannot run. '
              + (canManage && v.keeper_user_id
                  ? '<button type="button" class="fl-homebtn" onclick="event.stopPropagation();flOpenHomePin('
                    + Number(v.keeper_user_id) + ')">🏠 Set home location</button>'
                  : '<b>Ask a manager to set it.</b>')
              + '</div>'
            : (v.keeper_home_pin
                ? '<div class="fl-vhome">🏠 Home: '
                  + '<a href="' + flEsc(v.keeper_home_pin.maps_url) + '" target="_blank" rel="noopener"'
                  + ' onclick="event.stopPropagation();">view on map ↗</a>'
                  + (canManage && v.keeper_user_id
                      ? ' · <button type="button" class="fl-homelink" onclick="event.stopPropagation();flOpenHomePin('
                        + Number(v.keeper_user_id) + ')">change</button>'
                      : '')
                  + '</div>'
                : ''))
      +   '<div class="fl-vstats">'
      +     '<span>' + (v.current_meter !== null ? flNum(v.current_meter) + ' km' : 'no reading yet') + '</span>'
      +     svc
      +     (v.photo_count ? '<span>📷 ' + v.photo_count + '</span>' : '')
      +   '</div>'
      +   '<div class="fl-vfoot" onclick="event.stopPropagation()">'
      +     (canManage
        ? '<button type="button" class="fl-vbtn primary" onclick="flvOpenAssign(' + v.id + ')">'
            + (v.keeper_user_id ? '🔄 Reassign' : '➕ Assign') + '</button>'
          + (v.keeper_user_id ? '<button type="button" class="fl-vbtn" onclick="flvRelease(' + v.id + ')">Take back</button>' : '')
          + '<button type="button" class="fl-vbtn" onclick="flvOpenEdit(' + v.id + ')">✏️ Edit</button>'
        : '')
      // ⭐ Gated on can_log_meters (manage_bike_service): recording what a machine
      //   did belongs to the service-recording family of right, not the assignment one.
      +     (flvData && flvData.can_log_meters
              ? '<button type="button" class="fl-vbtn" onclick="flvOpenMeter(' + v.id + ')" title="Record or correct this machine&#39;s meter for a day">🧾 Meter</button>'
              : '')
      +     '<button type="button" class="fl-vbtn" style="margin-left:auto;" onclick="flvOpen(' + v.id + ')">Profile ▸</button>'
      +   '</div>'
      + '</div>';
}

/**
 * ⭐ Which half of "this month" the profile is showing (owner ruling Aug-5):
 *    'money' = what was spent (the default, unchanged), 'days' = where the
 *    kilometres went. The day list is fetched only when asked for — see the
 *    note on VehicleController::days.
 *
 * ⚠ Named `flvDetailMode`, NOT `flvMode` — that one already means riders-vs-
 *   vehicles for the whole tab (see flSetMode above). Re-declaring it here was a
 *   SyntaxError that killed this entire script block, so the Bikes tab never
 *   loaded at all.
 */
let flvDetailMode = 'money';

/* ⭐ Which slice of the service history is on screen: all | regular | repairs | unclassified.
     Lives out here so a re-render (adding a bill, correcting a reading) does not throw the
     manager back to "All" mid-task. Reset on each open, like flvDetailMode. */
let flvHistFilter = 'all';

/* ⭐⭐ ONE CLOCK FOR THE WHOLE PANEL (owner, 3-Sep: "I still can't see day by day or the
     maintenance history for previous months… how do I even use this?").

   The page used to have TWO time controls: these period chips, and the month picker in the
   SCREEN header — which scrolls out of sight the moment a vehicle is opened. Everything
   money-shaped (the fuel list, day-by-day, the month cost tile) obeyed that hidden picker, so
   a manager looking at August saw September's emptiness and had no visible way to move.

   Now: `flvMonth` is the panel's own month and `flvRange` the width of the window around it.
   Both live in ONE bar at the top of the panel, and everything below reads them.
 ⚠ `flvMonth` drives the SERVER fetch (the fuel list and day-by-day are built per month), so
   changing it reloads; the range is a client-side filter over the all-time record and does not. */
let flvMonth = null;               // 'YYYY-MM' — null until a vehicle is opened
let flvRange = 'q';                // month | q | year | all
let flvHistShowAll = false;
/* Which view of the ACTIVITY section: the service record, the month's fuel, or the
   kilometre audit. The last two only exist per month — see flvSetView. */
let flvView = 'list';              // list | fuel | days
/* ⚠ Guards the one-time ‘jump to the last month with activity’ so it cannot loop. */
let flvMonthAuto = false;

/**
 * 🎫 THE MACHINE'S OPEN PROBLEMS, ON THE MACHINE'S PAGE (owner ask, 5-Sep-2026):
 *    "this issue box should come in vehicles too… currently it's coming in the riders part only."
 *
 * ⚠ Same endpoint, same renderer, same thread opener as the rider drawer — only the FILTER
 *   differs (`vehicle_id` instead of `user_id`). The server decides who may see what
 *   (`VehicleTicketService::visibilityScope`); this never re-implements that.
 * ⚠ Its own fetch, not part of the vehicle payload: a ticket list has its own permission
 *   question, and folding it in would make two places decide who may know one exists.
 */
let flvTickets = null;      // { vehicleId, list } for the panel that is open
let flvTicketSeq = 0;

function flvLoadTickets(vehicleId) {
    flvTickets = null;
    const seq = ++flvTicketSeq;                        // last answer wins
    fetch('/orders/riders-map/fleet/tickets?status=' + (flvTicketsAll ? 'all' : 'open')
          + '&limit=' + (flvTicketsAll ? 40 : 10) + '&vehicle_id=' + encodeURIComponent(vehicleId),
          { headers: { 'Accept': 'application/json' } })
        .then(r => r.ok ? r.json() : null)
        .then(j => {
            if (seq !== flvTicketSeq || !j || !j.success) return;
            if (flvOpenId !== vehicleId) return;        // he moved on to another machine
            flvTickets = { vehicleId: vehicleId, list: j.tickets || [], canManage: !!j.can_manage };
            if (flvLastRes) flvRenderDetail(flvLastRes.vehicle, flvLastRes.can_manage, flvLastRes);

            /* 🎫 The second half of a ticket deep link: the vehicle is open and its tickets
               have just been drawn (so #flTicketThread exists) — open the one the toast named,
               and only if it is genuinely on this machine's list. Consumed once. */
            const dl = window.flDeepLink;
            if (dl && dl.ticket) {
                const wanted = parseInt(dl.ticket, 10);
                window.flDeepLink = null;
                if ((j.tickets || []).some(t => parseInt(t.id, 10) === wanted)) flOpenTicket(wanted);
            }
        })
        .catch(() => {});                              // a panel never breaks the page
}

/* 🎫 History on the vehicle: open only by default; the toggle refetches with closed rows. */
let flvTicketsAll = false;
function flvToggleTicketHistory() {
    flvTicketsAll = !flvTicketsAll;
    if (flvOpenId) flvLoadTickets(flvOpenId);
}

/**
 * 🔧 THE MACHINE'S WORKSHOP DAY, ON THE MACHINE'S PAGE (owner, 5-Sep: "to add a workshop day it
 *    shouldn't solely depend on a ticket being raised — there should be a button on the vehicle").
 *    Same endpoint and same rows as the rider drawer, filtered by `vehicle_id`; the buttons
 *    (Accept · Mark done · Move · Cancel) are the drawer's own functions.
 */
let flvVisits = null;       // { vehicleId, list, canSchedule } for the panel that is open
let flvVisitSeq = 0;
function flvLoadVisits(vehicleId) {
    const seq = ++flvVisitSeq;
    fetch('/orders/riders-map/fleet/workshop?vehicle_id=' + encodeURIComponent(vehicleId),
          { headers: { 'Accept': 'application/json' } })
        .then(r => r.ok ? r.json() : null)
        .then(j => {
            if (seq !== flvVisitSeq || !j || !j.success) return;
            if (flvOpenId !== vehicleId) return;
            flvVisits = { vehicleId: vehicleId, list: j.visits || [], canSchedule: !!j.can_schedule,
                          // ⏳ 6-Sep: a planner can decide a proposal right here on the machine's page.
                          canApprove: !!j.can_approve };
            if (flvLastRes) flvRenderDetail(flvLastRes.vehicle, flvLastRes.can_manage, flvLastRes);
        })
        .catch(() => {});
}

function flvHistRerender() {
    if (flvLastRes) flvRenderDetail(flvLastRes.vehicle, flvLastRes.can_manage, flvLastRes);
}
function flvSetHistFilter(k) { flvHistFilter = k; flvHistShowAll = false; flvHistRerender(); }
function flvHistMore()       { flvHistShowAll = !flvHistShowAll; flvHistRerender(); }

/* Widen or narrow the window. ⚠ The month-only views cannot survive a wider window — there
   is no such thing as ‘day by day over a year’ here — so they fall back to the record. */
function flvSetRange(k) {
    flvRange = k;
    flvHistShowAll = false;
    if (k !== 'month' && flvView !== 'list') flvView = 'list';
    flvHistRerender();
}

/* Switch what the ACTIVITY section shows. Fuel and the kilometre audit are built per month,
   so choosing one narrows the window to a month rather than showing a misleading partial. */
function flvSetView(v) {
    flvView = v;
    flvHistShowAll = false;
    if (v !== 'list' && flvRange !== 'month') { flvRange = 'month'; }
    if (v === 'days') { flvSetDetailMode('days'); return; }   // that loader also redraws
    flvDetailMode = 'money';
    flvHistRerender();
}

/* Step the panel's own month. ⚠ Refetches, because the fuel list and the kilometre audit are
   built server-side for one month; the record and the cost tiles come back with it. */
function flvShiftMonth(delta) {
    if (!flvLastRes) return;
    const base = (flvMonth || flvLastRes.month || flMonth || '').slice(0, 7);
    const m = base.match(/^(\d{4})-(\d{2})$/);
    if (!m) return;
    const d = new Date(Number(m[1]), Number(m[2]) - 1 + delta, 1);
    const next = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0');
    // Never step into the future — there is nothing there to read.
    const now = new Date();
    const cap = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
    if (next > cap) return;
    flvMonth = next;
    flvHistShowAll = false;
    flvOpen(flvLastRes.vehicle.id, true);   // keep the panel open, reload for the new month
}
let flvLastRes = null;
const flvDaysCache = {};          // 'vehicleId|month' → the day payload

function flvDaysKey(res) { return res.vehicle.id + '|' + (flvMonth || res.month || flMonth); }

function flvSetDetailMode(mode) {
    flvDetailMode = mode;
    if (!flvLastRes) return;
    const key = flvDaysKey(flvLastRes);

    if (mode === 'days' && !flvDaysCache[key]) {
        flvDaysCache[key] = { loading: true };
        flvRenderDetail(flvLastRes.vehicle, flvLastRes.can_manage, flvLastRes);
        fetch(FLV_BASE + '/' + flvLastRes.vehicle.id + '/days?month='
              + encodeURIComponent(flvMonth || flvLastRes.month || flMonth),
              { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(res => { flvDaysCache[key] = res && res.success ? res : { error: true }; })
            .catch(() => { flvDaysCache[key] = { error: true }; })
            .finally(() => {
                // Only redraw if the manager is still looking at this bike and mode.
                if (flvLastRes && flvDaysKey(flvLastRes) === key && flvDetailMode === 'days') {
                    flvRenderDetail(flvLastRes.vehicle, flvLastRes.can_manage, flvLastRes);
                }
            });
        return;
    }
    flvRenderDetail(flvLastRes.vehicle, flvLastRes.can_manage, flvLastRes);
}

/** Expand one vehicle: condition photos, who has had it, and its service state. */
function flvOpen(id, keepMonth) {
    /* ⚠ `keepMonth` is the month stepper reloading the SAME machine. Everything else is a
       fresh open and starts from the defaults, so one bike's filters never leak to the next. */
    if (!keepMonth || flvOpenId !== id) {
        flvDetailMode = 'money';   // every open starts on the cheap view
        flvHistFilter = 'all';     // …showing every kind, not the last filter used
        flvRange      = 'q';       // …over the last 3 months
        flvView       = 'list';    // …on the record, not a month-only view
        flvMonth      = null;      // …and on the month the screen is showing
        flvMonthAuto  = false;
    }
    flvOpenId = id;
    flvHistShowAll = false;
    const box = document.getElementById('flVehDetail');
    box.style.display = '';
    box.innerHTML = '<div class="fl-vdbody">Loading…</div>';
    if (!keepMonth || flvOpenId !== id) { flvTicketsAll = false; flvVisits = null; }
    flvLoadTickets(id);          // 🎫 in parallel with the detail — see flvLoadTickets
    flvLoadVisits(id);           // 🔧 likewise — see flvLoadVisits
    box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

    // ⚠ Pass the month the manager is actually looking at. Without it the popover
    //   always reported the CURRENT month while the table behind it showed another.
    /* ⚠⚠ `all_time=1` — otherwise the history is a 24-row window with NO way to reach an older
         month, which is exactly the complaint: "there's no way to change the month or see
         previous months' history". The whole record comes down once (capped at 200 rows for one
         machine) and the range chips below filter it in the browser, so changing period costs
         no round trip. The MONTH still goes up because "This month" and the day-by-day audit
         are month-scoped and must follow the picker at the top of the screen. */
    fetch(FLV_BASE + '/' + id + '?all_time=1&month=' + encodeURIComponent(flvMonth || flMonth),
          { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.message || 'Failed');
            flvLastRes = res;
            flvMonth = (res.month || flvMonth || flMonth || '').slice(0, 7);

            /* ⭐ OPEN ON A MONTH THAT HAS SOMETHING IN IT. On the 3rd of a month a machine
               has usually done nothing yet, and greeting a manager with three zeros and an
               empty list is what made this page look broken. If the month we landed on is
               empty and the record knows a later-used month, go there once.
             ⚠ Once only (flvMonthAuto), and never over an explicit step. */
            if (!flvMonthAuto && !keepMonth) {
                flvMonthAuto = true;
                const w = (res.cost_summary && res.cost_summary.windows && res.cost_summary.windows.month) || {};
                const spent = (w.regular_rs || 0) + (w.repairs_rs || 0) + (w.fuel_rs || 0)
                            + (w.unclassified_rs || 0) + (w.waiting_rs || 0);
                const claims = (res.claims || []).length;
                if (!spent && !claims) {
                    const newest = (res.service_history || [])
                        .map(r => String(r.date || '').slice(0, 7))
                        .filter(m => /^\d{4}-\d{2}$/.test(m) && m < flvMonth)
                        .sort().pop();
                    if (newest) { flvMonth = newest; flvOpen(id, true); return; }
                }
            }
            flvRenderDetail(res.vehicle, res.can_manage, res);
        })
        .catch(() => { box.innerHTML = '<div class="fl-vdbody">Could not load that vehicle.</div>'; });
}

/**
 * The month's kilometres, day by day — the receipt behind the figure above it.
 * Every stretch the machine moved is on one of these lines, so the four totals
 * add back up to the headline number (the server says whether they did).
 */
function flvDaysHtml(res) {
    const d = flvDaysCache[flvDaysKey(res)];
    if (!d)          return '';
    if (d.loading)   return '<div style="font-size:12px;color:#9ca3af;">Loading the day list…</div>';
    if (d.error)     return '<div style="font-size:12px;color:#b91c1c;">Could not load the day list.</div>';

    const t = d.totals || {};
    const cell = (n, label, tone) =>
        '<div style="flex:1;min-width:74px;text-align:center;padding:7px 4px;background:#f9fafb;'
      + 'border:1px solid #e5e7eb;border-radius:8px;">'
      + '<div style="font-size:15px;font-weight:700;color:' + (tone || '#111827') + ';">' + flNum(n) + '</div>'
      + '<div style="font-size:10.5px;color:#6b7280;text-transform:uppercase;letter-spacing:.03em;">' + label + '</div>'
      + '</div>';

    const strip = '<div style="display:flex;gap:6px;margin-bottom:8px;flex-wrap:wrap;">'
        + cell(t.on_duty || 0, 'on duty')
        + cell(t.off_duty || 0, 'off duty', (t.off_duty ? '#b45309' : null))
        + (t.shared ? cell(t.shared, '🔁 shared', '#0f766e') : '')
        + (t.transfer ? cell(t.transfer, '🔁 in transit', '#0f766e') : '')
        + (t.unaccounted ? cell(t.unaccounted, 'unaccounted', '#b91c1c') : '')
        + cell(t.total || 0, 'total')
        + '</div>';

    // ⭐ Say plainly whether the rows behind the total actually make it up. A list
    //    that quietly disagrees with the number above it is worse than no list.
    const note = d.reconciles
        ? '<div style="font-size:11.5px;color:#6b7280;margin-bottom:9px;">'
          + 'Every kilometre below is a stretch between two readings of this machine — '
          + 'they add up to the ' + flNum(d.month_km === null ? (t.total || 0) : d.month_km)
          + ' km shown above.</div>'
        : '<div style="font-size:11.5px;color:#b45309;margin-bottom:9px;">'
          + '⚠ These rows come to ' + flNum(t.total || 0) + ' km but the month figure is '
          + (d.month_km === null ? '—' : flNum(d.month_km))
          + ' km. A meter reading in this month looks wrong.</div>';

    // ⭐⭐ ONE CARD PER DATE (owner review, Aug-13). The old list printed a block
    //    per RIDER, so a handover date arrived as two fragments and the manager had
    //    to assemble the story. A date is one thing that happened to one machine:
    //    it opens on a meter, things occur, it closes on a meter.
    //
    //    The server decides the ORDER (meters and handovers by clock, claims by
    //    where their odometer places them), so this is a dumb renderer and mobile
    //    can show the identical sequence from the identical payload.
    // ⚠ Falls back to the old flat day list when day_cards is absent.
    let hidden = 0;
    const cards = d.day_cards || null;
    const rows = cards ? cards.map(c => {
        const s = c.summary || {};
        if (!(c.lines || []).length) { hidden++; return ''; }

        const head = flvCardVerdict(s);
        const body = c.lines.map(l => flvCardLine(l, s)).join('');

        return '<div class="fl-dc">'
            + '<div class="fl-dc-h"><span>' + flvDate(c.date) + '</span>' + head + '</div>'
            + '<div class="fl-dc-b">' + body + '</div></div>';
    }).join('') : (d.days || []).map(day => flvLegacyDayRow(day, () => hidden++)).join('');

    return strip + note + flvWhoRodeIt(d)
        + (rows || '<div style="font-size:12px;color:#9ca3af;">No readings for this machine this month.</div>')
        + (hidden ? '<div style="font-size:11.5px;color:#9ca3af;margin-top:7px;">· ' + hidden
            + ' other day' + (hidden === 1 ? '' : 's') + ' with nothing recorded for this machine</div>' : '');
}

/**
 * The day's verdict, right-aligned in the card header. This is the whole question a
 * manager opens the list to answer: how far did it go, and does that distance belong
 * to one man or to neither?
 */
function flvCardVerdict(s) {
    const km = s.km === null || s.km === undefined ? null : flNum(s.km);
    const who = (s.riders || []).filter(Boolean).join(' + ');

    if (s.kind === 'shared') {
        return '<span style="color:#0f766e;" title="The meter was started by one rider and closed by '
            + 'another, so this day cannot be split between them — it is charged to neither. '
            + 'These kilometres are counted ONCE on the machine, never twice.">'
            + km + ' km · 🔁 shared' + (who ? ' <span style="font-weight:400;color:#6b7280;">' + flEsc(who) + '</span>' : '')
            + '</span>';
    }
    if (s.kind === 'split') {
        const parts = (s.parts || []).map(p => flNum(p.km) + ' km ' + flEsc(p.who || '—')).join(' + ');
        return '<span style="color:#15803d;" title="A handover odometer was recorded, so this day '
            + 'splits exactly between the two riders.">✂ ' + (parts || km + ' km') + '</span>';
    }
    if (km !== null) {
        return '<span>' + km + ' km on duty'
            + (who ? ' <span style="font-weight:400;color:#6b7280;">' + flEsc(who) + '</span>' : '') + '</span>';
    }
    const TEXT = {
        no_meter: '⚠ meter nahi mila', leave: 'on leave', claim_only: 'sirf claims',
        half: 'aik hi meter', in_progress: 'din band nahi hua',
    };
    return '<span style="font-weight:400;color:#9ca3af;">' + (TEXT[s.kind] || '—') + '</span>';
}

/**
 * ONE LINE OF THE DAY. Labels are English on purpose ("meter start" / "meter end" —
 * owner ruling); only the EXPLANATIONS are Roman Urdu, and the long-form English
 * lives in the tooltip so nothing is lost for whoever wants it.
 */
function flvCardLine(l, s) {
    const K = v => '<span class="fl-dc-k">' + v + '</span>';

    if (l.type === 'meter_start' || l.type === 'meter_end') {
        const isStart = l.type === 'meter_start';
        return '<div class="fl-dc-l">' + K(isStart ? 'meter start' : 'meter end')
            + '<b>' + flNum(l.value) + '</b>'
            + '<span class="fl-muted">· ' + flEsc(l.who || '—') + (l.at ? ' · ' + l.at : '') + '</span>'
            + (l.source === 'log'
                ? ' <span class="fl-vchip unk" title="Recorded from the Vehicles page — the machine own record, not the rider day">🧾 manager entry</span>'
                : '')
            + (isStart && l.source === 'manager'
                ? ' <span class="fl-vchip unk" title="Entered by a manager, not by the rider">✎ manager ne likha</span>'
                : '')
            + '</div>';
    }

    if (l.type === 'claim') {
        // ⭐ TIME + PROVENANCE (owner request, Aug-22). The card always ORDERED claims by their
        //   filing time but never printed it, so on a machine that changed hands mid-day a
        //   manager could see the sequence and not the clock — and the clock is the whole story:
        //   it says which side of the handover a claim was filed on.
        // ⭐ "machine not recorded" marks a claim whose vehicle was INFERRED from who held what
        //   that day rather than recorded on the claim itself. That inference is exactly how an
        //   own-bike per-km claim can surface on a company machine's card, so it is labelled
        //   instead of being presented as a fact.
        return '<div class="fl-dc-l">' + K(flEsc(l.kind))
            + '<span>Rs <b>' + flNum(l.amount) + '</b>'
            + (l.meter ? ' <span class="fl-muted">· meter ' + flNum(l.meter) + '</span>' : '')
            + ' <span class="fl-muted">· ' + flEsc(l.who || '—')
            + (l.at ? ' · ' + flEsc(l.at) : '') + '</span></span>'
            + (l.stamped === false
                ? ' <span class="fl-vchip unk" title="This claim does not name a machine — it is shown here because the rider held this one that day. An own-bike claim filed on a day he also held a company machine can land here wrongly.">❓ machine not recorded</span>'
                : '')
            + (l.pending ? ' <span class="fl-vchip due">waiting</span>' : '')
            + '</div>';
    }

    if (l.type === 'handover') {
        return '<div class="fl-dc-l" style="color:#0f766e;">' + K('🔁 handover')
            + '<span>→ <b>' + flEsc(l.to || '—') + '</b>'
            + (l.by_name ? ' <span class="fl-muted">· ' + flEsc(l.by_name) + ' ne record ki</span>' : '')
            + (l.time ? ' <span class="fl-muted">· ' + l.time + '</span>'
                      : (l.recorded_on ? ' <span class="fl-muted">· ' + flvDate(l.recorded_on) + ' ko likha</span>' : ''))
            + (l.meter ? ' <span class="fl-muted">· handover meter ' + flNum(l.meter) + '</span>' : '')
            + '</span></div>';
    }

    // The stretch that ARRIVED at this day — never the day's own run (the server
    // keeps shared/split out of here so no kilometre is shown twice).
    if (l.type === 'gap') {
        const since = l.since ? ' · ' + flvDate(l.since) + ' se' : '';
        if (l.kind === 'transfer') {
            return '<div class="fl-dc-l" style="color:#0f766e;">' + K('🔁 transit')
                + '<span><b>+' + flNum(l.km) + ' km</b>'
                + (l.from && l.to ? ' <span class="fl-muted">· ' + flEsc(l.from) + ' → ' + flEsc(l.to) + '</span>' : '')
                + ' <span class="fl-muted">· kisi ke naam nahi</span></span></div>';
        }
        if (l.kind === 'unaccounted') {
            return '<div class="fl-dc-l" style="color:#b91c1c;">' + K('⚠ unaccounted')
                + '<span title="This stretch spans a day worked with no usable reading, so it cannot be '
                + 'split into work and commute."><b>+' + flNum(l.km) + ' km</b>'
                + '<span class="fl-muted">' + since + ' · beech mein aik din meter ke baghair</span></span></div>';
        }
        return '<div class="fl-dc-l" style="color:#b45309;">' + K('↳ off duty')
            + '<span><b>+' + flNum(l.km) + ' km</b>'
            + '<span class="fl-muted">' + since + (l.who ? ' (' + flEsc(l.who) + ')' : '') + '</span></span></div>';
    }

    return '';
}

/** The pre-day-card row, kept so an older server still renders the list it sends. */
function flvLegacyDayRow(day, countHidden) {
    const interesting = day.work_km !== null || day.gap_km || day.home_km
        || (day.claims && day.claims.length) || day.anomaly || day.partial
        || day.status === 'no_meter';
    if (!interesting) { countHidden(); return ''; }

    let km = '';
    if (day.work_km !== null) {
        km += '<div style="font-size:12.5px;color:#374151;"><b>' + flNum(day.meter_start)
            + ' → ' + flNum(day.meter_end) + '</b> · <b>' + flNum(day.work_km) + ' km</b> on duty</div>';
    }
    if (day.gap_km) {
        km += '<div style="font-size:12px;color:#b45309;">↳ <b>' + flNum(day.gap_km) + ' km</b> '
            + flEsc(day.gap_kind || 'off duty') + '</div>';
    }
    const claims = (day.claims || []).map(c =>
          '<div style="font-size:11.5px;color:#6b7280;margin-left:10px;">'
        + flEsc(c.kind) + ' · <b style="color:#374151;">Rs ' + flNum(c.amount) + '</b>'
        + (c.meter ? ' · meter ' + flNum(c.meter) : '')
        + ' · by ' + flEsc(c.by_name || '—') + '</div>').join('');

    return '<div style="padding:7px 0;border-bottom:1px solid #f3f4f6;">'
        + '<div style="display:flex;align-items:baseline;gap:8px;margin-bottom:2px;">'
        + '<b style="font-size:12.5px;color:#111827;min-width:74px;">' + flvDate(day.date) + '</b>'
        + '<span style="font-size:11.5px;color:#6b7280;">' + flEsc(day.keeper || '—') + '</span>'
        + '</div>' + km + claims + '</div>';
}

/**
 * ⭐⭐ WHO RODE IT THIS MONTH — the machine's mirror of the rider view's "his
 *     machines" strip, from the SAME engine, so the two lenses cannot disagree.
 *
 * Shared and transit kilometres get their own line at the bottom rather than being
 * divided between the riders: the whole point is that they belong to neither.
 */
function flvWhoRodeIt(d) {
    const r = d.riders;
    if (!r || !(r.riders || []).length) return '';

    const rows = r.riders.map(p => {
        const spend = (p.fuel_rs || 0) + (p.maint_rs || 0);
        return '<div class="fl-mrow" style="margin-bottom:4px;">'
            + '<span class="fl-mlink" style="min-width:110px;" onclick="flOpenRider(' + p.user_id + ')" '
            +   'title="Open this rider\'s own month">' + flEsc(p.name || '—') + ' ▸</span>'
            + '<span class="fl-mkm">' + flNum(p.work_km) + ' km</span>'
            + '<span style="color:#6b7280;font-size:11.5px;">on duty</span>'
            + (p.offduty_km ? '<span style="color:#b45309;font-size:11.5px;">+' + flNum(p.offduty_km) + ' off duty</span>' : '')
            + (p.shared_days ? '<span style="color:#0f766e;font-size:11.5px;">🔁 ' + p.shared_days
                + ' handover day' + (p.shared_days === 1 ? '' : 's') + '</span>' : '')
            + (spend > 0 ? '<span style="font-size:11.5px;">Rs ' + flNum(spend) + ' filed</span>' : '')
            + '</div>';
    }).join('');

    const nobody = (r.shared_km || r.transfer_km)
        ? '<div class="fl-mrow" style="background:#e6f7f3;border-color:#c8e9e1;">'
          + '<span class="fl-pill fl-shared">🔁 nobody\'s</span>'
          + (r.shared_km ? '<span class="fl-mkm">' + flNum(r.shared_km) + ' km</span>'
              + '<span style="color:#6b7280;font-size:11.5px;">shared on handover days</span>' : '')
          + (r.transfer_km ? '<span class="fl-mkm">' + flNum(r.transfer_km) + ' km</span>'
              + '<span style="color:#6b7280;font-size:11.5px;">in transit between riders</span>' : '')
          + '</div>'
        : '';

    return '<h4 style="margin:12px 0 7px;">Who rode it this month</h4>' + rows + nobody;
}

/** MACHINE → RIDER, the reverse of flOpenVehicle. */
function flOpenRider(userId) {
    if (!userId) return;
    flSetMode('riders');
    let tries = 0;
    (function open() {
        if (document.getElementById('flRow' + userId)) {
            flSelectRider(userId);
            const row = document.getElementById('flRow' + userId);
            if (row && row.scrollIntoView) row.scrollIntoView({block: 'center'});
        } else if (tries++ < 40) {
            setTimeout(open, 100);
        }
    })();
}

function flvCloseDetail() {
    flvOpenId = null;
    document.getElementById('flVehDetail').style.display = 'none';
}

function flvRenderDetail(v, canManage, res) {
    // Recording condition is a WIDER right than assigning (see canAddPhotos on the
    // server): a bike-service manager may photograph a machine he cannot reassign.
    // Falls back to canManage so an older payload still behaves as before.
    const canAddPhotos = (res && res.can_add_photos !== undefined)
        ? !!res.can_add_photos
        : !!canManage;
    const s = v.service || {};
    const icon = v.vtype === 'van' ? '🚚' : '🏍️';

    /* ⭐ THIS MACHINE'S fuel & maintenance for the month — the same
       `claimsForVehicle` the rider's own screen reads, so a manager and a rider
       can never see two different histories for one bike. Each row names WHO
       filed it, which is what stops a new keeper mistaking a predecessor's
       spend for his own. Rows attributed by assignment window (rather than a
       stamped vehicle_id, which only exists from Aug-2026) are marked, so the
       reconstruction never pretends to be a record. */
    const cl  = (res && res.claims) || [];
    const tot = (res && res.claims_total) || {};
    /* The bike's Rs/km — this month vs the previous 3 pooled (same server
       method the rider's screen reads, so the two can never disagree). */
    const av  = (res && res.averages) || {};
    const ks  = (res && res.keeper_stint) || null;
    /* ⭐ The rider-vs-machine diagnostic (owner ruling Aug-4): the current
       keeper's stint against the bike's own last-3-months baseline. */
    let avLine = '';
    if (ks && ks.rs_per_km) {
        avLine += '<div style="font-size:12px;color:#0f766e;margin-bottom:3px;">'
                + '👤 <b>' + flEsc(ks.name || 'Keeper') + ': Rs ' + ks.rs_per_km + '/km</b>'
                + ' over ' + flNum(ks.km) + ' km since ' + flvDate(ks.since)
                + ((av.last3 && av.last3.rs_per_km)
                    ? ' · this bike\'s usual is Rs ' + av.last3.rs_per_km + '/km' : '')
                + '</div>';
    } else if (av.last3 && av.last3.rs_per_km) {
        avLine += '<div style="font-size:12px;color:#0f766e;margin-bottom:3px;">'
                + 'This bike\'s usual: <b>Rs ' + av.last3.rs_per_km + '/km</b> (last 3 months)</div>';
    }
    if (av.this_month && av.this_month.rs_per_km) {
        avLine += '<div style="font-size:12px;color:#6b7280;margin-bottom:6px;">'
                + '⛽ Rs ' + av.this_month.rs_per_km + '/km this month ('
                + flNum(av.this_month.km) + ' km)</div>';
    }
    const money = cl.length
        ? '<div style="font-size:12.5px;color:#374151;margin-bottom:7px;">'
          + '⛽ <b>Rs ' + flNum(tot.fuel_rs || 0) + '</b> fuel · 🔧 <b>Rs '
          + flNum(tot.maint_rs || 0) + '</b> maintenance</div>'
          + cl.map(c =>
              '<div class="fl-vhrow">'
            +   '<span style="min-width:118px;">' + flEsc(c.kind) + '</span>'
            +   '<span style="min-width:88px;color:#6b7280;">' + flvDate(c.date) + '</span>'
            +   '<span style="min-width:92px;"><b>Rs ' + flNum(c.amount) + '</b></span>'
            +   '<span style="color:#6b7280;">by ' + flEsc(c.by_name || '—')
            +     (c.meter ? ' · meter ' + flNum(c.meter) : '') + '</span>'
            +   (c.is_pending ? '<span class="fl-vchip due">waiting</span>' : '')
            +   (c.stamped ? '' : '<span class="fl-vchip unk" title="Attributed from who held the '
                + 'bike on that date — filed before claims recorded a vehicle">from history</span>')
            + '</div>').join('')
        : '<div style="font-size:12px;color:#9ca3af;">Nothing filed for this machine this month.</div>';

    /* ⭐⭐ ONE TIME BAR for the whole panel. The month stepper and the width of the window
         sit together, at the top, INSIDE the panel — the screen-header picker scrolls away
         the moment a vehicle is opened, which is why previous months were unreachable.
       ⚠ The stepper is only meaningful on a single month; on wider windows it is disabled
         rather than hidden, so the control does not jump around as the manager switches. */
    /* ⚠ Settle the panel's month HERE, once, before anything reads it. The bar showed
       `res.month` while the record's month filter fell back to the CURRENT month, so a panel
       opened on August could label itself August and then filter to September — two answers
       to “which month am I looking at”, which is the bug this whole round exists to kill. */
    if (!flvMonth) flvMonth = (res.month || flMonth || '').slice(0, 7);
    const shownMonth = flvMonth;
    const nowM = (() => { const n = new Date();
        return n.getFullYear() + '-' + String(n.getMonth() + 1).padStart(2, '0'); })();
    const rangeChip = (k, label) =>
        '<button type="button" class="fl-vchipbtn' + (flvRange === k ? ' on' : '') + '"'
      + ' onclick="flvSetRange(\'' + k + '\')">' + label + '</button>';
    const timeBar =
        '<div class="fl-vtbar">'
      +   '<span class="fl-vstep">'
      +     '<button type="button" onclick="flvShiftMonth(-1)" title="Previous month">‹</button>'
      +     '<b>' + flEsc(flMonthLabel(shownMonth)) + '</b>'
      +     '<button type="button" onclick="flvShiftMonth(1)" title="Next month"'
      +       (shownMonth >= nowM ? ' disabled' : '') + '>›</button>'
      +   '</span>'
      +   rangeChip('month', 'That month') + rangeChip('q', 'Last 3 months')
      +   rangeChip('year', 'This year') + rangeChip('all', 'All time')
      + '</div>';

    /* ⭐ ACTIVITY — what happened to this machine, in one place. The record (services and
       maintenance, all-time capable) is the default; fuel and the kilometre audit are
       month-shaped and say so on the chip, so nobody reads a month's fuel as a year's. */
    const viewChip = (k, label) =>
        '<button type="button" class="fl-vchipbtn' + (flvView === k ? ' on' : '') + '"'
      + ' onclick="flvSetView(\'' + k + '\')">' + label + '</button>';
    const activity =
        '<div class="fl-vsec">'
      +   '<h4>Activity</h4>'
      +   '<div style="display:flex;gap:5px;flex-wrap:wrap;margin-bottom:8px;">'
      +     viewChip('list', '🛠 Service &amp; repairs')
      +     viewChip('fuel', '⛽ Fuel — ' + flEsc(flMonthLabel(shownMonth)))
      +     viewChip('days', '📅 Day by day — ' + flEsc(flMonthLabel(shownMonth)))
      +   '</div>'
      +   (flvView === 'list' ? flvServiceHistoryHtml(res)
         : flvView === 'days' ? avLine + flvDaysHtml(res)
         :                      avLine + money)
      + '</div>';

    /* ⭐⭐ WHAT HAS THIS MACHINE COST (owner ask, 3-Sep). The page showed one month with every
         non-fuel claim under a single "maintenance" figure, so "is my money going on scheduled
         upkeep or on things breaking, this month and over its life" could not be read at all.
       ⚠ Fuel is kept in its OWN column, never folded in — the machine's Rs/km averages are
         computed from it (owner ruling).
       ⚠ "Unclassified" is shown, never absorbed: 109 legacy rows genuinely say nothing about
         which kind of work they paid for, and a total that hides that is a lie. */
    /* The headline of the service schedule, for the sticky header. ⚠ Reads the SAME
       `v.service` the schedule below reads — one source, so they can never disagree. */
    const sv = v.service || {};
    /* ⭐ Sep-2026: the chip reads the state and the server's own sentence, so a
         time-based job says "12 days overdue" rather than a number in the wrong unit,
         and a personal machine says why it has no countdown instead of showing none. */
    const svLeft = (sv.basis === 'time') ? sv.due_in_days : sv.due_in_km;
    const dueChip = (sv.state === 'not_tracked')
        ? '<span class="fl-vduechip" style="background:#f3f4f6;color:#6b7280;"'
          + ' title="Own bikes are the rider’s own affair — the company keeps no schedule for them">'
          + '👤 personal — no company schedule</span>'
        : (svLeft === null || svLeft === undefined)
        ? (sv.interval_km || sv.interval_days
            ? '<span class="fl-vduechip" style="background:#f3f4f6;color:#6b7280;">'
              + '🛢 no service recorded</span>' : '')
        : '<span class="fl-vduechip" style="'
          + (sv.state === 'overdue'  ? 'background:#fef2f2;color:#b91c1c;'
             : sv.state === 'due_soon' ? 'background:#fffbeb;color:#b45309;'
             : 'background:#f0fdf4;color:#15803d;') + '">'
          + '🛢 ' + flEsc(sv.due_type_name || 'Service') + ' '
          + flEsc(sv.due_text || (svLeft < 0 ? flNum(-svLeft) + ' km overdue'
                                             : 'due in ' + flNum(svLeft) + ' km'))
          + '</span>';

    /* 🎫 The machine's open problems — loaded beside the detail, see flvLoadTickets. */
    const tks = (flvTickets && flvTickets.vehicleId === v.id) ? flvTickets.list : null;
    const tkChip = (tks && tks.length)
        ? '<span class="fl-vduechip" style="background:'
          + (tks.some(t => t.urgent) ? '#fef2f2;color:#b91c1c;' : '#fffbeb;color:#b45309;') + '">'
          + '🎫 ' + tks.length + (tks.length === 1 ? ' open ticket' : ' open tickets') + '</span>'
        : '';
    /* ⭐ ALWAYS a place for tickets & chat on the machine (owner, 5-Sep: "do I always see a
         tickets/issues/chat button that opens these chats or history?"). The block is there the
         moment the answer arrives, empty or not; history is one click; raising one is a button. */
    const tkCanManage = !!(flvTickets && flvTickets.vehicleId === v.id && flvTickets.canManage);
    /**
     * ⭐⭐ COMPANY MACHINES ONLY (owner ruling, 15-Sep-2026). The server refuses a ticket on an
     *    own bike whoever asks, so offering the button here would be a control that always 422s.
     *    ⚠ The BLOCK still renders on an own bike — history is history, and any ticket raised
     *      before this rule must stay readable. Only RAISING is withdrawn.
     */
    const tkCompany = v.is_company !== false;
    const tkBlock = tks === null
        ? ''
        : '<div class="fl-vsec">'
          + '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;">'
          +   '<h4 style="margin:0;">Tickets &amp; chat</h4>'
          +   '<button type="button" class="fl-vchipbtn' + (flvTicketsAll ? ' on' : '') + '" onclick="flvToggleTicketHistory()">'
          +     (flvTicketsAll ? 'Hide closed' : 'Show history') + '</button>'
          +   (tkCanManage && tkCompany
                 /* ⚠⚠ flJsArg, NEVER JSON.stringify — its real `"` ends the attribute and the
                       button silently does nothing (see the note on flJsArg above). */
                 ? '<button type="button" class="fl-vchipbtn" style="margin-left:auto;" onclick="flvReportProblem('
                   + v.id + ', ' + flJsArg(v.name || '') + ')">🎫 Open an issue</button>'
                 : '')
          + '</div>'
          + (tks.length
                ? flTicketRowsHtml(tks, { showWho: true }) + '<div id="flTicketThread"></div>'
                : '<div style="font-size:12px;color:#9ca3af;">'
                  + (flvTicketsAll ? 'No tickets have ever been raised on this machine.'
                       : !tkCompany
                           // ⚠ Say WHY it is permanently empty, rather than implying someone
                           //   might report something here one day.
                           ? 'This is not a company machine, so bike issues are not raised on it. '
                             + 'Bike issues cover company bikes and the van.'
                           : 'Nothing open on this machine. Faults a rider reports from his phone appear here.')
                  + '</div><div id="flTicketThread"></div>')
          + '</div>';

    /* 🔧 THE WORKSHOP DAY — booked against the machine, so it lives here too. Buttons are the
         rider drawer's own; nothing is decided twice. */
    const vs = (flvVisits && flvVisits.vehicleId === v.id) ? flvVisits.list : null;
    const wsCan = !!(flvVisits && flvVisits.vehicleId === v.id && flvVisits.canSchedule);
    const wsApprove = !!(flvVisits && flvVisits.vehicleId === v.id && flvVisits.canApprove);
    const wsBtn = wsCan
        ? '<button type="button" class="fl-vchipbtn" onclick="flScheduleWorkshop(null, null, ' + v.id + ')">🔧 '
          + (vs && vs.length ? 'Move' : 'Schedule workshop') + '</button>'
        : '';
    const wsBlock = vs === null
        ? ''
        : '<div class="fl-vsec">'
          + '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px;">'
          +   '<h4 style="margin:0;">Workshop</h4>'
          +   (wsBtn ? '<span style="margin-left:auto;">' + wsBtn + '</span>' : '')
          + '</div>'
          + (vs.length
                ? vs.map(w =>
                    '<div class="fl-vhrow" style="align-items:center;">'
                  +   '<b style="min-width:110px;">' + flEsc(w.visit_date) + (w.visit_time ? ' ' + flEsc(w.visit_time) : '') + '</b>'
                  +   '<span style="color:#374151;">' + flEsc(w.rider_name || v.keeper_name || 'rider') + ' takes it'
                  +     (w.workshop ? ' · ' + flEsc(w.workshop) : '') + (w.purpose ? ' · ' + flEsc(w.purpose) : '') + '</span>'
                  /**
                   * ⏳ 6-Sep: say plainly WHERE in the flow this day is, because "booked" and
                   *   "the rider knows about it" stopped being the same thing.
                   */
                  +   '<span class="fl-vchip ' + (w.is_declined ? 'over' : w.is_proposed ? 'due' : (w.accepted ? 'ok' : 'unk')) + '">'
                  /* ⚠ A DECLINED request is in this list too (a manager should see that Qasim
                       asked and a planner said no). Without its own branch it drew as
                       "scheduled, rider told" — the exact opposite of what happened. */
                  +     (w.is_declined
                          ? '✖ declined' + (w.declined_by_name ? ' by ' + flEsc(w.declined_by_name) : ' — nobody approved it in time')
                            + (w.decline_reason ? ' · ' + flEsc(w.decline_reason) : '')
                          : w.is_proposed ? '⏳ awaiting a shift planner — rider NOT told'
                                          : (w.accepted ? '✓✓ accepted' : '✓ scheduled, rider told')) + '</span>'
                  +   (w.is_declined ? '' : (wsCan || wsApprove
                        ? '<span style="margin-left:auto;display:flex;gap:4px;">'
                          + (w.is_proposed
                              // A proposal has one question on it, and only a planner may answer.
                              ? (wsApprove
                                  ? '<button type="button" class="fl-vbtn" onclick="flWorkshopApprove(' + w.id + ')">✓ Approve</button>'
                                    + '<button type="button" class="fl-vbtn" onclick="flWorkshopDecline(' + w.id + ')">✖ Decline</button>'
                                    /* ⏰ "Later (6h)" — the desk twin of the phone's button, so a
                                         planner who works on the web gets the same third answer.
                                       ⭐ Per USER and never a decision: the request stays open for
                                         every other planner and returns to this one in six hours. */
                                    + '<button type="button" class="fl-vbtn" onclick="flWorkshopSnooze(' + w.id + ')" '
                                      + 'title="Hide this from YOUR list for 6 hours. It stays open for everyone else.">'
                                      + '⏰ Later</button>'
                                  : '')
                                + (wsCan ? '<button type="button" class="fl-vbtn" onclick="flWorkshopCancel(' + w.id + ')">Withdraw</button>' : '')
                              : (!w.accepted && wsCan ? '<button type="button" class="fl-vbtn" onclick="flWorkshopAccept(' + w.id + ')">Accept for him</button>' : '')
                                + (wsCan ? '<button type="button" class="fl-vbtn" onclick="flWorkshopDone(' + w.id + ')">Mark done</button>'
                                         + '<button type="button" class="fl-vbtn" onclick="flWorkshopCancel(' + w.id + ')">Cancel</button>' : ''))
                          + '</span>'
                        : ''))
                  + '</div>').join('')
                : '<div style="font-size:12px;color:#9ca3af;">Nothing booked for this machine.</div>')
          + '</div>';

    const cs = (res && res.cost_summary && res.cost_summary.windows) || null;
    const costStrip = cs
        ? '<div class="fl-vsec"><h4>What it has cost</h4>'
          + '<div style="display:flex;gap:8px;flex-wrap:wrap;">'
          + [['month', flMonthLabel((flvMonth || res.month || flMonth || '').slice(0, 7))],
             ['quarter', 'Last 3 months'], ['year', 'This year'], ['lifetime', 'Lifetime']]
              .map(([k, label]) => {
                  const w = cs[k] || {};
                  const line = (lbl, rs, colour) => (rs > 0
                      ? '<div style="display:flex;justify-content:space-between;gap:10px;font-size:12px;color:' + colour + ';">'
                        + '<span>' + lbl + '</span><b>' + flNum(rs) + '</b></div>' : '');
                  /* ⭐ The tile matching the chosen window is lit, so the number the manager
                       is reading is visibly the one the clock above is set to. */
                  const liveKey = flvRange === 'q' ? 'quarter' : flvRange === 'all' ? 'lifetime' : flvRange;
                  const on = (k === liveKey);
                  return '<div style="flex:1 1 150px;min-width:150px;border:1px solid ' + (on ? '#fdba74' : '#e5e7eb') + ';border-radius:9px;background:' + (on ? '#fffbf5' : '#fff') + ';padding:9px 11px;">'
                       + '<div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.03em;margin-bottom:5px;">' + label + '</div>'
                       + (line('🛢 Regular', w.regular_rs, '#374151')
                          || line('🛢 Regular', 0, '#374151'))
                       + line('🔧 Repairs', w.repairs_rs, '#374151')
                       + line('⛽ Fuel', w.fuel_rs, '#374151')
                       + line('❓ Unclassified', w.unclassified_rs, '#b45309')
                       + line('⏳ Waiting', w.waiting_rs, '#9a3412')
                       + ((w.regular_rs + w.repairs_rs + w.fuel_rs + w.unclassified_rs + w.waiting_rs) === 0
                            ? '<div style="font-size:12px;color:#9ca3af;">Nothing filed</div>' : '')
                       + '</div>';
              }).join('')
          + '</div>'
          + '<div style="font-size:11px;color:#9ca3af;margin-top:7px;">'
          + 'Approved money only. “Waiting” is filed but not yet approved. “Unclassified” is older '
          + 'work filed before the named service list existed — it is counted, not hidden.</div>'
          + '</div>'
        : '';

    const photos = (v.photos || []).length
        ? '<div class="fl-vstrip">' + v.photos.map(p =>
              '<div class="fl-vpic">'
            +   '<img src="' + flEsc(p.url) + '" onclick="flPhoto(\'' + flEsc(p.url) + '\')" alt="">'
            +   '<div class="fl-vpiclab">' + flEsc(p.label) + '<br>' + flvDate(p.taken_on)
            +     (p.note ? '<br><span style="color:#9ca3af;">' + flEsc(p.note) + '</span>' : '')
            +   '</div>'
            + '</div>').join('') + '</div>'
        : '<div style="font-size:12px;color:#9ca3af;">No photos yet. Add them when the vehicle changes hands, '
          + 'so its condition on the day is on record.</div>';

    const history = (v.history || []).length
        ? v.history.map(h =>
              '<div class="fl-vhrow">'
            +   '<span style="min-width:150px;"><b>' + flEsc(h.rider_name || ('user ' + h.user_id)) + '</b></span>'
            +   '<span style="color:#6b7280;">' + flvDate(h.assigned_on) + ' → '
            +     (h.released_on ? flvDate(h.released_on) : 'now') + '</span>'
            +   (h.is_current ? '<span class="fl-vhnow">current</span>' : '')
            +   (h.note ? '<span style="color:#9ca3af;font-size:11.5px;">' + flEsc(h.note) + '</span>' : '')
            + '</div>').join('')
        : '<div style="font-size:12px;color:#9ca3af;">Never assigned.</div>';

    document.getElementById('flVehDetail').innerHTML = ''
      + '<div class="fl-vdhead">'
      +   '<div style="font-size:16px;font-weight:700;color:#111827;">' + icon + ' ' + flEsc(v.name) + '</div>'
      +   '<span class="fl-vtag ' + (v.is_company ? 'co' : 'own') + '">' + (v.is_company ? 'company' : 'own') + '</span>'
      +   '<div style="font-size:12.5px;color:#6b7280;">'
      +     (v.keeper_name ? 'with <b style="color:#111827;">' + flEsc(v.keeper_name) + '</b>' : 'unassigned')
      +     (v.current_meter !== null ? ' · ' + flNum(v.current_meter) + ' km' : '')
      +   '</div>'
      /* ⭐ WHEN IS IT DUE — in the header, because it is one of the two questions this page
           exists to answer and it used to need a scroll. Named job, not a bare number, so
           “due in 920 km” cannot be read as the wrong service. The full per-type schedule is
           still below; this is the headline of it. */
      +   (dueChip || '')
      +   tkChip
      /* ⭐ VEHICLE-CENTRIC ACTIONS (owner ask, 3-Sep): "new maintenance should also be an
           option here, currently it's only in the riders section". Both open the SAME forms
           the Riders tab uses, pre-set to this machine and its keeper — so the approval rule,
           the ledger posting and the type gate are the ones already in place, not a second
           copy. Shown only when there is a keeper to file against, and only to someone who
           may do it. */
      +   ((v.keeper_user_id && res && res.can_log_meters)
            /* ⛽ FUEL FOR A COMPANY MACHINE, FROM THE MACHINE (owner ask, 10-Sep-2026):
                 "they usually assign a meter value, and then they add a claim to it."
               ⚠ COMPANY ONLY, deliberately. A personal bike's petrol is the rider's PER-KM
                 claim — money owed to him for his own fuel — and that belongs on the Riders
                 tab beside his day's kilometres, which is where it already is. Offering it
                 here would invite a cash claim against a machine the firm does not fuel.
               ⭐ Same modal, same picker, same server door as the Riders tab — this only
                 pre-points it at the machine on screen and seeds its odometer. */
            ? ((v.is_company)
                ? '<button type="button" class="fl-vbtn" style="margin-left:auto;" '
                  + 'onclick="flvNewPetrol(' + v.keeper_user_id + ',' + v.id + ',' + (v.current_meter || 0) + ')">⛽ New petrol</button>'
                  + '<button type="button" class="fl-vbtn" '
                  + 'onclick="flvNewMaintenance(' + v.keeper_user_id + ',' + v.id + ')">➕ New maintenance</button>'
                : '<button type="button" class="fl-vbtn" style="margin-left:auto;" '
                  + 'onclick="flvNewMaintenance(' + v.keeper_user_id + ',' + v.id + ')">➕ New maintenance</button>')
              + '<button type="button" class="fl-vbtn" onclick="flvRecordService(' + v.keeper_user_id + ',' + (v.current_meter || 0) + ',' + v.id + ')">🛠 Record service</button>'
            : '')
      /* ⭐⭐ THE SCHEDULE, ON THE MACHINE ITSELF (owner ask, 9-Sep: "this should be in
           vehicles now since maintenance is vehicle specific"). ⚠ Deliberately NOT gated
           on there being a keeper — a parked spare and a van between drivers both need a
           schedule, and the old rider-keyed prompt could not give them one. Personal
           machines open it too and are told, in the editor, that the company keeps no
           schedule for them. */
      +   ((res && res.can_log_meters)
            ? '<button type="button" class="fl-vbtn"'
              + ((v.keeper_user_id) ? '' : ' style="margin-left:auto;"')
              + ' onclick="flvOpenSchedule(' + v.id + ',' + flJsArg(v.name || '') + ')">'
              + '⚙️ This vehicle\'s schedule</button>'
            : '')
      +   '<button type="button" class="fl-vbtn" style="' + ((v.keeper_user_id || (res && res.can_log_meters)) ? '' : 'margin-left:auto;') + '" onclick="flvCloseDetail()">Close</button>'
      + '</div>'
      + '<div class="fl-vdbody">'
      /* ⭐⭐ COST FIRST. It was sitting BELOW the whole service record, so a manager scrolled
           past forty rows to reach the numbers he opened the page for. It is the summary of
           everything under it and belongs at the top. */
      /* ⭐⭐ THE ORDER A MANAGER READS IN: when am I looking at → what did it cost → what is
           due → what happened. Condition and the keeper log are reference, and fold away. */
      +   timeBar
      +   costStrip
      /* ⚠ Condition photos were the FIRST thing on this screen and are rarely why it was
           opened. Collapsed, not removed — the upload form and the dated record are all still
           in here, one click away. */
      +   '<details class="fl-vsec"><summary style="cursor:pointer;font-weight:700;font-size:13px;color:#374151;">'
      +     'Condition photos' + ((v.photos || []).length ? ' (' + v.photos.length + ')' : '') + '</summary>'
      +     '<div style="margin-top:8px;">' + photos
      /* ⭐ The condition record is DATED (owner ask, Aug-2026): these photos exist to
         prove what a machine looked like on the day it changed hands, and a handover
         is often photographed later than it happened. Until now the upload sent no
         date at all, so every photo was silently stamped TODAY — which quietly makes
         the record useless for the one question it exists to answer.
         Defaults to today, cannot be set in the future (the server clamps too). */
      /* ⚠ canAddPhotos, NOT canManage — recording condition is its own right, so a
         bike-service manager can photograph a machine he may not reassign. */
      +     (canAddPhotos
          ? '<div style="margin-top:9px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">'
            + '<input type="file" id="flvMorePhotos" accept="image/*" multiple style="font-size:12px;">'
            + '<label style="font-size:11.5px;color:#6b7280;">Taken on '
            +   '<input type="date" id="flvMoreDate" max="' + flvTodayYmd() + '" value="' + flvTodayYmd() + '" '
            +     'style="border:1px solid #d1d5db;border-radius:6px;padding:4px 6px;font-size:12px;margin-left:4px;">'
            + '</label>'
            + '<input type="text" id="flvMoreNote" maxlength="255" placeholder="note (optional)" '
            +   'style="border:1px solid #d1d5db;border-radius:6px;padding:4px 8px;font-size:12px;min-width:190px;">'
            + '<button type="button" class="fl-vbtn" onclick="flvUploadMore(' + v.id + ')">Add photos</button>'
            + '</div>'
          : '')
      +   '</div></details>'
      +   '<div class="fl-vsec">'
      +     '<h4>Service</h4>'
      +     '<div style="font-size:12.5px;color:#374151;line-height:1.7;">'
      /* ⚠ One job's story, named — the most urgent scheduled job's own last-done and
         interval, not necessarily the bike's most recent visit. The full per-type
         truth sits right below in flvScheduleHtml. */
      +       (s.due_type_name ? '<b>' + flEsc(s.due_type_name) + '</b> — due' : 'Due')
      /* ⚠ The summary can be a TIME-based job now (29-Sep-2026): its interval and due point are
           in days, so the server's phrases are used for it ("every 6 months", "5 days overdue")
           rather than "every 0 km" and no due clause at all. km jobs read exactly as before. */
      +       (s.basis === 'time' && s.interval_label
              ? ' <b>' + flEsc(s.interval_label) + '</b>'
              : ' every <b>' + flNum(s.interval_km) + ' km</b>')
      +       (s.last_service_meter !== null && s.last_service_meter !== undefined
              ? ' · last done at <b>' + flNum(s.last_service_meter) + ' km</b>'
                + (s.last_service_at ? ' on ' + flvDate(s.last_service_at) : '')
              : ' · <span style="color:#b45309;">no service recorded yet</span>')
      +       (s.due_in_km !== null && s.due_in_km !== undefined
              ? (s.due_in_km < 0
                  ? ' · <b style="color:#991b1b;">' + flNum(-s.due_in_km) + ' km overdue</b>'
                  : ' · due in <b>' + flNum(s.due_in_km) + ' km</b>')
              : (s.basis === 'time' && s.due_in_days !== null && s.due_in_days !== undefined && s.due_text
                  ? ' · <b style="color:' + (s.due_in_days < 0 ? '#991b1b' : '#374151') + ';">' + flEsc(s.due_text) + '</b>'
                  : ''))
      +     '</div>'
      +     flvScheduleHtml(res)
      +   '</div>'
      /* ⭐ The record itself gets its OWN section rather than trailing off the end of the
           schedule — it is the longest thing here and needs its own controls. */
      /* 🎫 Above Activity: an open fault is what a manager acts on TODAY; the cost and
           service record are what he reads about. */
      +   tkBlock
      +   wsBlock
      +   activity
      /* ⭐ Rarely-needed sections collapse (owner: "it seems messy"). Nothing is removed —
           photos and keeper history are one click away instead of in the way. */
      +   '<details class="fl-vsec"><summary style="cursor:pointer;font-weight:700;font-size:13px;color:#374151;">'
      +     'Who has had it</summary><div style="margin-top:8px;">' + history + '</div></details>'
      + '</div>';
}

/**
 * ⭐ THE PER-TYPE SERVICE SCHEDULE ON THE MACHINE (owner ask, Aug-6): oil every
 * 1,200, oil+tuning every 2,500, brake shoe every 10,000 — each with its own
 * "last done / due in", exactly what the rider drill-down has shown for weeks,
 * but keyed to the BIKE. The work travels with the machine now, so "brake shoe
 * never recorded" must not reset just because the keeper changed.
 *
 * Sorted most-urgent first: overdue → due soon → ok → never recorded. The jobs
 * that need money now are the reason a manager opens this at all.
 */
/**
 * ⭐ THE MACHINE'S SERVICE RECORD (owner ask, Aug-13).
 *
 * "When was this bike last serviced, and what was done?" used to be answerable only
 * from the RIDER's drill-down, which meant a handover split one bike's history
 * across two people. This is the same list, attributed to the machine — so the work
 * travels with the bike, which is the whole premise of the registry.
 */
function flvServiceHistoryHtml(res) {
    const h = (res && res.service_history) || [];
    if (!h.length) return '';

    /* ⭐ MANUAL records (no bill) now appear here alongside the claims, and are the only
       rows that can be CORRECTED from this screen — `log_id` is what says so. A claim
       carries money and is amended through the claims flow instead.
       ⚠ Until this list existed, a "Record service" row fed every countdown while appearing
         on no screen at all: that is how log #8 sat misfiled and needed SQL to find. */
    const canFix = flvData && flvData.can_log_meters;

    /* ⭐ FILTER THE HISTORY (owner: "it seems messy with all services day by day"). The bucket
         is already on every row — regular / repairs / unclassified — so this only decides what
         is drawn. ⚠ Counts are shown so an empty filter reads as "none of these", never as a
         broken list. `flvHistFilter` lives outside this function so it survives a re-render. */
    /* ⭐⭐ A PERIOD, AND A LIMIT (owner, 3-Sep: "no way to change the month or see previous
         months' history … how do I even use this?"). The whole record is fetched once
         (`all_time=1`), so both controls are pure filters — changing period costs no round trip.
       ⚠ Ranges, not a month stepper: a manager asks "this month / this year / ever", and a
         stepper would make him click back through empty months to find the one with the work in
         it. The month PICKER at the top of the screen still drives "This month" below. */
    const inRange = r => {
        if (flvRange === 'all') return true;
        const d = String(r.date || '');
        const now = new Date();
        const ym = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
        if (flvRange === 'month') return d.slice(0, 7) === (flvMonth || ym);
        if (flvRange === 'year')  return d.slice(0, 4) === String(now.getFullYear());
        if (flvRange === 'q') {
            const from = new Date(now.getFullYear(), now.getMonth() - 2, 1);
            return d >= (from.getFullYear() + '-' + String(from.getMonth() + 1).padStart(2, '0') + '-01');
        }
        return true;
    };
    const inWindow = h.filter(inRange);
    const counts = inWindow.reduce((m, r) => { const b = r.bucket || 'unclassified'; m[b] = (m[b] || 0) + 1; return m; }, {});
    const btn = (on, onclick, label) =>
        '<button type="button" class="fl-vbtn" style="padding:2px 8px;font-size:11px;'
      + (on ? 'background:#fff7ed;border-color:#fdba74;color:#9a3412;font-weight:700;' : '')
      + '" onclick="' + onclick + '">' + label + '</button>';

    const chip = (k, label) => {
        const n = k === 'all' ? inWindow.length : (counts[k] || 0);
        if (k !== 'all' && !n) return '';          // never offer a filter that shows nothing
        return btn(flvHistFilter === k, 'flvSetHistFilter(\'' + k + '\')', label + ' ' + n);
    };

    const filtered = flvHistFilter === 'all'
        ? inWindow : inWindow.filter(r => (r.bucket || 'unclassified') === flvHistFilter);

    /* ⚠ A LIMIT, with a way past it. The list ran to every row it had — forty of them on an
         older bike — which is why the cost summary underneath was never reached. Ten is enough
         to answer "what happened lately"; the count on the button says what is being held back,
         so nothing is hidden, only folded. */
    const CAP = 10;
    const shown = flvHistShowAll ? filtered : filtered.slice(0, CAP);
    const more  = filtered.length - shown.length;

    /* 🧾⭐ WORK DONE, COST NOT ENTERED (owner ask, 11-Sep-2026 — the interim nag until the
         unpaid-visits screen exists). Counted over the WHOLE window, not the visible slice: the
         list is capped at ten, and a reminder that only counts what happens to be on screen is
         worse than none. The SERVER decides which rows qualify (`needs_amount`), including the
         1-September cut-off, so this line can never disagree with the rows beneath it. */
    const owed = inWindow.filter(r => r.needs_amount);

    /* ⭐⭐ ONE VISIT, SEVERAL JOBS (29-Sep-2026). The SERVER groups them (`visit_key` = same
         machine, day and odometer) and sorts them together; this only draws a header over a
         visit with more than one row, so Oil + Tuning, Brake Shoe and Chain Set read as the one
         trip they were — with its bill shown ONCE. Each job keeps its own row, because each has
         its own countdown and its own Edit / Remove. */
    const visitRows = {};
    filtered.forEach(r => { if (r.visit_key) (visitRows[r.visit_key] = visitRows[r.visit_key] || []).push(r); });
    const headed = {};
    const visitHead = (s) => {
        const g = visitRows[s.visit_key] || [];
        if (g.length < 2 || headed[s.visit_key]) return '';
        headed[s.visit_key] = true;
        const logs = g.filter(r => r.log_id);
        const first = logs[0] || null;
        /* ⚠ C1 (29-Sep-2026): a rejected or cancelled bill is not money spent on this visit — it
             is left out of the total and the bill count, and its jobs are offered "Add the bill". */
        const total = g.reduce((t, r) => t + (Number(r.amount) > 0 && flvMoneyLive(r) ? Number(r.amount) : 0), 0);
        const bills = new Set(g.map(r => (r.bill_id ? (flvBillLive(r) ? r.bill_id : null)
                                          : (r.req_id && flvMoneyLive(r) ? 'c' + r.req_id : null))).filter(Boolean)).size;
        const unbilled = logs.filter(r => !flvBillLive(r));
        return '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:6px;padding:5px 8px;'
            + 'background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px 8px 0 0;font-size:12px;color:#374151;">'
            + '<b>🛠 ' + flvDate(s.date) + (s.meter ? ' · ' + flNum(s.meter) + ' km' : '') + ' · ' + g.length + ' jobs</b>'
            + '<span style="color:#6b7280;">' + (total > 0
                ? 'Rs ' + flNum(total) + (bills > 1 ? ' · ' + bills + ' bills' : ' · one bill')
                : 'no bill yet') + '</span>'
            + (first && canFix
                ? '<a href="#" onclick="flFixServiceRecord(' + first.log_id + ');return false;" '
                  + 'title="Correct the odometer or date — every job of this visit moves with it" '
                  + 'style="font-size:11px;font-weight:700;">Edit visit</a>'
                  + ' <a href="#" onclick="flvAddJobToVisit(' + first.log_id + ');return false;" '
                  + 'title="Record a job that was done on this visit but not entered" '
                  + 'style="font-size:11px;font-weight:700;">+ Add a job</a>'
                : '')
            + (unbilled.length && canFix
                ? ' <a href="#" onclick="flvAddBill(' + unbilled[0].log_id + ',' + (unbilled[0].rider_id || 0) + ');return false;" '
                  + 'title="One receipt for the jobs of this visit that have no bill yet" '
                  + 'style="font-size:11px;font-weight:700;color:#047857;">Add the bill</a>'
                : '')
            + '</div>';
    };
    const inVisit = (s) => (visitRows[s.visit_key] || []).length > 1;

    return ''
        + (owed.length
            ? '<div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;padding:8px 10px;'
              + 'background:#FFFBEB;border:1px solid #FCD34D;border-radius:8px;font-size:12px;color:#92400E;">'
              + '<b>🧾 ' + owed.length + ' service' + (owed.length === 1 ? '' : 's') + ' with no amount</b>'
              + '<span style="opacity:.9;">— the work is recorded; somebody still has to enter what it cost.</span>'
              + '</div>'
            : '')
        + '<div style="display:flex;gap:5px;flex-wrap:wrap;margin-bottom:7px;">'
        +   chip('all', 'All') + chip('regular', '🛢 Regular') + chip('repairs', '🔧 Repairs')
        +   chip('unclassified', '❓ Unclassified')
        + '</div>'
        + (filtered.length ? '' : '<div style="font-size:12px;color:#9ca3af;">Nothing of that kind in this period.</div>')
        + shown.map(s =>
            visitHead(s)
          + '<div class="fl-vhrow"' + (inVisit(s) ? ' style="margin-left:10px;border-left:2px solid #e5e7eb;padding-left:8px;"' : '') + '>'
          +   '<span style="min-width:120px;">' + (inVisit(s) ? '<span style="color:#9ca3af;">↳</span>' : flvDate(s.date)) + '</span>'
          +   '<span style="flex:1;min-width:0;">' + flEsc(s.kind || 'Maintenance')
          +     (s.meter ? ' <span style="color:#9ca3af;">at ' + flNum(s.meter) + ' km</span>' : '')
          +     (s.by_name ? ' <span style="color:#9ca3af;">· by ' + flEsc(s.by_name) + '</span>' : '')
          +     (s.status === 'pending' ? ' <span class="fl-vchip due">waiting</span>' : '')
          /* ⚠ A hand-recorded row now carries its bill's status too (review, 3-Sep). A bill that
                was REJECTED must not sit there reading as paid money — the work still happened
                and the reading still counts, but the Rs figure did not clear. */
          +     (s.status === 'rejected' ? ' <span class="fl-vchip unk" title="The bill filed with this service was rejected — the reading still counts, the money did not clear">bill rejected</span>' : '')
          +     (s.manual
                  ? ' <span class="fl-vchip unk" title="'
                    + (s.bill_id
                        ? 'Recorded by hand on the Bikes screen, with its bill filed at the same time'
                        : 'Recorded by hand on the Bikes screen — no bill filed')
                    + '">recorded' + (s.bill_id ? ' + bill' : '') + '</span>'
                  : '')
          +     (s.stamped === false
                  ? ' <span class="fl-vchip unk" title="Attributed by who held this machine on that date, '
                    + 'rather than recorded against it at the time">from history</span>' : '')
          +     (s.log_id && canFix
                  ? ' <a href="#" onclick="flFixServiceRecord(' + s.log_id + ');return false;" '
                    + 'style="font-size:11px;font-weight:700;">Edit</a>'
                    + ' <a href="#" onclick="flRemoveServiceRecord(' + s.log_id + ');return false;" '
                    + 'style="font-size:11px;font-weight:700;color:#b91c1c;">Remove</a>'
                  : '')
          /* 🧾 ADD THE BILL — a service recorded without one, and the receipt turned up later
                (owner ask, 3-Sep). Only on rows that have no LIVE bill. ⚠ `bill_id` SURVIVES a
                rejected or cancelled claim, so the server's `bill_live` decides (C1, 29-Sep-2026):
                a rejected bill's service offers this again rather than sitting there looking paid. */
          +     (s.log_id && !flvBillLive(s) && canFix && !inVisit(s)
                  /* ⚠⚠ ID ONLY in the inline onclick — the label is rebuilt inside flvAddBill from
                        the row. JSON.stringify here put DOUBLE QUOTES inside this double-quoted
                        attribute and truncated it, so the link never fired (same trap as
                        "New own bike", 14 Aug). */
                  ? ' <a href="#" onclick="flvAddBill(' + s.log_id + ',' + (s.rider_id || 0) + ');return false;" '
                    + 'title="Attach the workshop bill to this service. Its odometer, job and date are kept." '
                    + 'style="font-size:11px;font-weight:700;color:#047857;">Add the bill</a>'
                  : '')
          /* ✏️ A CLAIM's READING is correctable too (Sep-3) — approved ones included.
             ⚠⚠ Only the odometer and which job it was. The amount, the date and the vehicle
                stay locked behind editClaim, which still refuses an approved claim outright:
                money in the ledger is reversed and re-filed, never quietly edited.
             ⚠ Until this existed, the panel could read "Oil Change 767 km overdue" off a typo
                on an approved claim that NOBODY could reach — the only workaround being to
                record a manual service and leave the wrong figure in the history for ever.
             ⭐ No Remove: deleting a claim is deleting a payment. */
          +     (!s.log_id && s.req_id && canFix
                  ? ' <a href="#" onclick="flFixClaimReading(' + s.req_id + ');return false;" '
                    + 'title="Correct the odometer or which service this was. The amount is not touched." '
                    + 'style="font-size:11px;font-weight:700;">Edit reading</a>'
                  : '')
          +   '</span>'
          /* ⭐ A hand-recorded row can now carry its BILL (Sep-3): the manager records the
                work and its cost in one action, and the claim half is dropped from this list so
                the job appears once. So "no bill" is only true when there genuinely is none. */
          +   '<span>' + ((s.manual && flvBillLive(s) && !(s.amount > 0))
                            /* ⭐ One receipt for several jobs: the money is on the first job's row
                                 and in the visit header, never repeated (29-Sep-2026). */
                            ? '<span style="color:#9ca3af;" title="Paid by the same bill as the other jobs of this visit">same bill</span>'
                            : (s.manual && !(s.amount > 0))
                            /* 🧾 Two different silences, and they must not look alike. A row
                                 from BEFORE the 1-Sept cut-off is history nobody will chase —
                                 it stays the quiet grey "no bill". A row after it is an open
                                 job for the team, so it is amber and says what to do. The
                                 SERVER draws that line (`needs_amount`). */
                            ? (s.needs_amount
                                ? '<span style="color:#92400E;font-weight:700;" '
                                  + 'title="The work is recorded but nobody has entered what it cost.">'
                                  + '🧾 amount needed</span>'
                                /* ⭐ Added to a visit that already has a live bill (29-Sep-2026):
                                     not nagged — it names that bill instead. */
                                : s.visit_bill_id
                                ? '<span style="color:#9ca3af;" title="This visit already has bill #' + flEsc(s.visit_bill_id)
                                  + '. If this job had its own receipt, use Add the bill on the visit.">'
                                  + 'visit bill #' + flEsc(s.visit_bill_id) + '</span>'
                                : '<span style="color:#9ca3af;">no bill</span>')
                            : 'Rs ' + flNum(s.amount))
              /* 📷 THE PHOTO, ON EITHER BRANCH (11-Sep-2026). It is no longer tied to money:
                   the rider is handed a receipt at the workshop and often pays nothing, so the
                   commonest row carrying evidence is the one that reads "no bill". Putting the
                   link inside just one branch would hide it exactly where a manager goes
                   looking before he types the amount. */
              + (s.photo_url
                  ? ' <a href="' + flEsc(s.photo_url) + '" target="_blank" rel="noopener" '
                    + 'title="The photo taken at the workshop" style="font-size:11px;font-weight:700;">📷</a>'
                  : '')
              + '</span>'
          + '</div>').join('')
        /* The rest of the record is one click away, and the button says how much. */
        + (more > 0
            ? '<div style="margin-top:6px;">' + btn(false, 'flvHistMore()',
                'Show ' + more + ' more') + '</div>'
            : (flvHistShowAll && filtered.length > CAP
                ? '<div style="margin-top:6px;">' + btn(false, 'flvHistMore()', 'Show fewer') + '</div>'
                : ''));
}

/* ═══════════════════════════════════════════════════════════════════════════
   ✏️ CORRECT A SERVICE RECORD (owner ask, 3-Sep): "Qasim or Shabib or Taimur can
   modify these service dates later on as well if needed."

   ⚠⚠ These rows used to be INSERT-ONLY. A record filed against the wrong job, day or
      odometer could only be fixed with hand-written SQL — the situation log #8 left us
      in. Same permission as recording one: if you can write it, you can fix it.
   ⚠ Only MANUAL records. A claim carries money and is amended through the claims flow.
   ⭐ The countdown is DERIVED from these rows, so a correction self-corrects every
     surface — there is no frozen figure to chase afterwards.
   ═══════════════════════════════════════════════════════════════════════════ */
/* ══════════════════════════════════════════════════════════════════════
   ✏️ CORRECT THE READING ON A MAINTENANCE CLAIM (owner ask, 3-Sep)

   ⚠⚠ THE TWO FIELDS HERE ARE NOT MONEY. The odometer and which job was done are
      OBSERVATIONS about a machine; the amount, the date and the vehicle decide what was
      spent, which period it lands in and which bike carries it. Only the observations are
      editable — the server enforces that, this dialog just never offers the rest.

   ⚠ The countdown is DERIVED from this row, so the correction self-corrects the schedule
     panel, the alerts, the rider's chip and this card at once.
   ══════════════════════════════════════════════════════════════════════ */
function flFixClaimReading(reqId) {
    /* ⚠⚠ NO LONGER FILTERED ON A KILOMETRE FIGURE (11-Sep-2026). `interval_km > 0` hides a
         TIME-based job, which reports 0 km by design, and on prod hides the two of four types
         that carry no figure at all — which is why a manager reported "I can only see 2
         categories". This is a "which job was this?" picker: every active job belongs in it,
         and whether a countdown moves is the SERVER's decision (see resolveType/counts_down). */
    const types = (flData && flData.maint_types || []);
    const payload = {};

    if (types.length) {
        const lines = types.map((t, i) => (i + 1) + '. ' + t.name).join('\n');
        const pick = window.prompt(
            'Which service was actually done?\n\n' + lines
            + '\n\nLeave blank to keep the job it is recorded against.', '');
        if (pick === null) return;
        if (String(pick).trim() !== '') {
            const idx = parseInt(pick, 10) - 1;
            if (isNaN(idx) || idx < 0 || idx >= types.length) { alert('That is not one of the listed services.'); return; }
            payload.maintenance_type_id = types[idx].id;
        }
    }

    const km = window.prompt(
        'Odometer at the service (km).\n\nLeave blank to keep it.\n\n'
        + 'The bill amount and its date are NOT changed by this.', '');
    if (km === null) return;
    const kmN = String(km).replace(/[^0-9]/g, '');
    if (kmN) payload.meter = parseInt(kmN, 10);

    if (!Object.keys(payload).length) { alert('Nothing was changed.'); return; }

    fetch(FL_BASE + '/claim-readings/' + reqId, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
                  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''},
        body: JSON.stringify(payload)
    }).then(r => r.json()).then(res => {
        alert(res.message || (res.success ? 'Saved.' : 'Could not save.'));
        if (res.success && typeof flvLoad === 'function') { flvLoad(); if (flvOpenId) flvOpen(flvOpenId); }
    }).catch(() => alert('Could not save. Please try again.'));
}

/* ⭐ C1 (29-Sep-2026): does this job row's bill still STAND? The server's `bill_live` when it sent
     one — `bill_id` survives a rejected or cancelled claim — and `bill_id` alone from an older server. */
function flvBillLive(r) { return !!r && (r.bill_live !== undefined ? !!r.bill_live : !!r.bill_id); }
/* …and does this row's MONEY count (a claim row is itself the bill)? Unknown = counts, as before. */
function flvMoneyLive(r) { return !!r && (r.bill_live !== undefined ? !!r.bill_live : true); }

/** The open machine's history row for a record, and the other rows of its visit. */
function flvVisitOf(logId) {
    const hist = (typeof flvLastRes !== 'undefined' && flvLastRes && flvLastRes.service_history) || [];
    const row = hist.find(r => Number(r.log_id) === Number(logId)) || null;
    const mates = row && row.visit_key
        ? hist.filter(r => r.log_id && Number(r.log_id) !== Number(logId) && r.visit_key === row.visit_key) : [];
    return { row: row, mates: mates };
}

function flFixServiceRecord(logId) {
    /* ⚠⚠ NO LONGER FILTERED ON A KILOMETRE FIGURE (11-Sep-2026). `interval_km > 0` hides a
         TIME-based job, which reports 0 km by design, and on prod hides the two of four types
         that carry no figure at all — which is why a manager reported "I can only see 2
         categories". This is a "which job was this?" picker: every active job belongs in it,
         and whether a countdown moves is the SERVER's decision (see resolveType/counts_down).
       ⭐ 29-Sep-2026: the MACHINE's own job list (same source as Record a service), so a bike is
         never offered a van-only job with the same name. */
    const types = flSvcTypesFor(null, (typeof flvOpenId !== 'undefined') ? flvOpenId : null);
    const payload = {};

    if (types.length) {
        const lines = types.map((t, i) => (i + 1) + '. ' + t.name).join('\n');
        const pick = window.prompt(
            'Which service was actually done?\n\n' + lines
            + '\n\nLeave blank to keep the job it is recorded against.', '');
        if (pick === null) return;
        if (String(pick).trim() !== '') {
            const idx = parseInt(pick, 10) - 1;
            if (isNaN(idx) || idx < 0 || idx >= types.length) { alert('That is not one of the listed services.'); return; }
            payload.maintenance_type_id = types[idx].id;
        }
    }

    const km = window.prompt('Odometer at the service (km).\n\nLeave blank to keep it.', '');
    if (km === null) return;
    const kmN = String(km).replace(/[^0-9]/g, '');
    if (kmN) payload.meter = parseInt(kmN, 10);

    const day = window.prompt('Date of the service (YYYY-MM-DD).\n\nLeave blank to keep it.', '');
    if (day === null) return;
    const d = String(day).trim();
    if (d) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(d)) { alert('Give the date as YYYY-MM-DD.'); return; }
        if (d > flvTodayYmd()) { alert('That date is in the future.'); return; }
        payload.date = d;
    }

    if (!Object.keys(payload).length) { alert('Nothing was changed.'); return; }

    /* ⭐⭐ A VISIT IS ONE READING (owner, 29-Sep-2026). A typo in the odometer or the date was
         typed once for every job of the trip, so by default the fix moves them all — the server
         does exactly that. Asked only when there is something else to move. */
    const v = flvVisitOf(logId);
    if ((payload.meter || payload.date) && v.mates.length) {
        payload.apply_to_visit = window.confirm(
            'This visit has ' + (v.mates.length + 1) + ' jobs recorded at the same reading.\n\n'
            + 'OK = correct all ' + (v.mates.length + 1) + ' of them (usual).\n'
            + 'Cancel = correct only this one job.\n\n'
            + '(Jobs paid by the same bill always move together.)') ? 1 : 0;
    }

    fetch(FL_BASE + '/service-records/' + logId, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
                  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''},
        body: JSON.stringify(payload)
    }).then(r => r.json()).then(res => {
        alert(res.message || (res.success ? 'Saved.' : 'Could not save.'));
        if (res.success && typeof flvLoad === 'function') { flvLoad(); if (flvOpenId) flvOpen(flvOpenId); }
    }).catch(() => alert('Could not save. Please try again.'));
}

/**
 * ➕ A JOB DONE ON THIS VISIT BUT NOT ENTERED (29-Sep-2026) — recorded into the SAME visit
 *    (machine, day, odometer) through the one writer, never typed again by hand.
 */
function flvAddJobToVisit(logId) {
    const v = flvVisitOf(logId);
    const have = new Set([v.row].concat(v.mates).filter(Boolean).map(r => Number(r.type)));
    const types = flSvcTypesFor(null, (typeof flvOpenId !== 'undefined') ? flvOpenId : null)
        .filter(t => !have.has(Number(t.id)));
    if (!types.length) { alert('Every job is already recorded on this visit.'); return; }
    const pick = window.prompt('Which job(s) were also done on this visit?\n\n'
        + types.map((t, i) => (i + 1) + '. ' + (t.name || t.type_name)).join('\n')
        + '\n\nType the numbers, e.g. 1 or 1,3', '');
    if (pick === null || !String(pick).trim()) return;
    const ids = String(pick).split(/[^0-9]+/).filter(Boolean).map(x => parseInt(x, 10) - 1);
    if (!ids.length || ids.some(i => isNaN(i) || i < 0 || i >= types.length)) {
        alert('That is not one of the listed jobs.'); return;
    }
    fetch(FL_BASE + '/service-records/' + logId, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json',
                  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''},
        body: JSON.stringify({ add_maintenance_type_ids: ids.map(i => types[i].id) })
    }).then(r => r.json()).then(res => {
        alert(res.message || (res.success ? 'Saved.' : 'Could not save.'));
        if (res.success && typeof flvLoad === 'function') { flvLoad(); if (flvOpenId) flvOpen(flvOpenId); }
    }).catch(() => alert('Could not save. Please try again.'));
}

function flRemoveServiceRecord(logId) {
    /* ⭐⭐ THE LAST JOB ON A LIVE BILL (owner ruling D7, 29-Sep-2026) is asked in its own words:
         removing it also makes the bill stop counting as that service, so the clock really goes
         back. Only when the server says the bill stands (`bill_live`) — an older server never does. */
    const v = flvVisitOf(logId), row = v.row;
    const hist = (typeof flvLastRes !== 'undefined' && flvLastRes && flvLastRes.service_history) || [];
    const lastOnBill = !!(row && row.bill_id && row.bill_live === true
        && hist.filter(r => r.log_id && Number(r.bill_id) === Number(row.bill_id)).length === 1);
    const job = (row && row.kind) || 'this job';
    const amt = row ? (Number(row.bill_amount != null ? row.bill_amount : row.amount) || 0) : 0;
    const ask = lastOnBill
        ? 'This is the last job on bill #' + row.bill_id + (amt > 0 ? ' (Rs ' + flNum(amt) + ')' : '') + '.\n\n'
          + 'Removing it puts the ' + job + ' clock back to the previous service. '
          + 'The money stays on record.\n\nRemove?'
        : 'Remove this ONE job record?\n\n'
          + 'The countdowns are worked out from these records, so removing it will move this job\'s '
          + 'next-due figure back. Other jobs of the same visit stay. Any bill stays on record.\n\n'
          + 'Do this only if this job was not actually done.';
    if (!window.confirm(ask)) return;
    fetch(FL_BASE + '/service-records/' + logId, {
        method: 'DELETE',
        headers: {'Accept': 'application/json',
                  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''}
    }).then(r => r.json()).then(res => {
        alert(res.message || (res.success ? 'Removed.' : 'Could not remove.'));
        if (res.success && typeof flvLoad === 'function') { flvLoad(); if (flvOpenId) flvOpen(flvOpenId); }
    }).catch(() => alert('Could not remove. Please try again.'));
}

function flvScheduleHtml(res) {
    const sched = (res && res.service_schedule) || [];

    /* ⭐ AN EMPTY SCHEDULE IS AN ANSWER, not a blank (Sep-2026). Two very different
         reasons produce one, and a manager must be able to tell them apart:
         a personal machine the company does not maintain, and a class whose jobs
         nobody has set up yet (the van, on day one). */
    if (!sched.length) {
        const v = (res && res.vehicle) || {};
        const untracked = v.is_company === false;
        return '<div class="fl-vsec"><h4>Service schedule</h4>'
            + '<div style="font-size:12.5px;color:#6b7280;background:#f9fafb;border:1px solid #e5e7eb;'
            + 'border-radius:8px;padding:10px 12px;line-height:1.5;">'
            + (untracked
                ? 'This is a personal vehicle. The company does not keep its service schedule, '
                  + 'so nothing is counted down here and no service alerts are raised for it.'
                : 'No jobs are scheduled for ' + (v.vtype === 'van' ? 'vans' : 'bikes')
                  + ' yet. Set them up under <b>⚙️ Types</b> on the Bikes screen — each job '
                  + 'carries its own figure for bikes and for vans.')
            + '</div></div>';
    }

    const rank = { overdue: 0, due_soon: 1, ok: 2, unknown: 3 };
    const rows = sched.slice().sort((a, b) =>
        (rank[a.state] ?? 9) - (rank[b.state] ?? 9)
        /* ⚠ Unit-free: with km and time jobs side by side, "40 left" means nothing
             until it is read against that job's own cycle. */
        || (flvSchedFrac(a) - flvSchedFrac(b)));

    /* ⭐ The sentence comes from the SERVER (`due_text`), so a 90-DAY job can never be
         announced as "90 km". The old km phrasing stays as the fallback for a payload
         built by an older code path. */
    const chip = t => {
        const txt = t.due_text || (t.due_in_km === null || t.due_in_km === undefined
            ? 'never recorded'
            : (t.due_in_km < 0 ? flNum(-t.due_in_km) + ' km overdue'
                               : 'due in ' + flNum(t.due_in_km) + ' km'));
        if (t.state === 'overdue')  return '<span class="fl-vchip over">' + flEsc(txt) + '</span>';
        if (t.state === 'due_soon') return '<span class="fl-vchip due">' + flEsc(txt) + '</span>';
        if (t.state === 'ok')       return '<span class="fl-vchip ok">' + flEsc(txt) + '</span>';
        return '<span class="fl-vchip unk">' + flEsc(txt) + '</span>';
    };

    return '<div class="fl-vsched">'
        + rows.map(t => ''
            + '<div class="fl-vschedrow">'
            +   '<div style="min-width:0;">'
            +     '<b>' + flEsc(t.name) + '</b>'
            +     ' <span style="color:#9ca3af;">' + flEsc(t.interval_label || ('every ' + flNum(t.interval_km) + ' km')) + '</span>'
            /* ⭐ …and where that number came from, when it is not the job's own
               schedule. Emitted by the server since Aug-2026 and never rendered. */
            +     ((t.interval_overridden && t.interval_source_label)
                    ? ' <span style="color:#b45309;font-size:11px;" title="Not this job\'s own schedule">('
                      + flEsc(t.interval_source_label) + ')</span>' : '')
            +     (t.last_meter !== null
                    ? '<div style="font-size:11px;color:#6b7280;">last at ' + flNum(t.last_meter) + ' km'
                      + (t.last_at ? ' · ' + flvDate(t.last_at) : '')
                      + (t.last_by ? ' · by ' + flEsc(t.last_by) : '')
                      /* ⭐ Say WHY a job nobody filed reads as freshly done — a bigger
                         service contained it (the covers rule). Without this line an
                         Oil Change with no record of its own looks like a bug. */
                      + (t.covered_by ? ' · <i title="A larger scheduled service '
                          + 'includes this job, so it reset this countdown too">done with '
                          + flEsc(t.covered_by) + '</i>' : '')
                      + (t.assumed ? ' <span class="fl-vchip unk" title="Recorded before the registry '
                          + 'knew who had this machine — credited to its first known keeper">assumed</span>' : '')
                      + '</div>'
                    : '')
            +   '</div>'
            +   '<div style="margin-left:auto;flex-shrink:0;">' + chip(t) + '</div>'
            + '</div>').join('')
        + '</div>';
}

/** How far through its own cycle a job is — comparable across km and time. */
function flvSchedFrac(t) {
    const isTime = t.basis === 'time';
    const left = isTime ? t.due_in_days : t.due_in_km;
    if (left === null || left === undefined) return 1e9;      // never recorded, sorts last
    const span = isTime ? (t.interval_days || 0) : (t.interval_km || 0);
    return span > 0 ? (left / span) : left;
}

// ── assign ────────────────────────────────────────────────────────────────
/**
 * ⭐⭐ THE MACHINE'S METER EDITOR (owner ask, Aug-14).
 *
 * A rider-day carries ONE set of meters and the engine maps it to ONE machine, so a
 * mid-day second machine — the van handed over at noon while the driver already
 * opened his day on his own bike — had nowhere to record its kilometres.
 *
 * ⭐ THIS IS AN EDITOR, NOT AN INSERT FORM. It PRELOADS whatever the date already
 *   has and routes each field back to ITS OWN home on save: a reading the rider
 *   entered updates his attendance row (through the same writer the Attendance page
 *   uses), a manager's entry updates the machine's log, and only an empty slot
 *   creates a new one. That is what makes "one reading, one home" enforceable — two
 *   competing records for one reading would put a contradiction into the chain.
 */
let flvMeterVehicle = null;
let flvMeterState = null;

function flvOpenMeter(vehicleId, date) {
    flvMeterVehicle = vehicleId;
    const v = ((flvData && flvData.vehicles) || []).find(x => x.id === vehicleId);
    document.getElementById('flvMeterTitle').textContent =
        '🧾 Meter reading — ' + (v ? v.name : 'vehicle');
    document.getElementById('flvMeterDate').value = date || flvTodayYmd();
    document.getElementById('flvMeterError').style.display = 'none';
    document.getElementById('flvMeterModal').style.display = 'flex';
    flvLoadMeterDay();
}

function flvCloseMeter() {
    document.getElementById('flvMeterModal').style.display = 'none';
    flvMeterVehicle = null;
    flvMeterState = null;
}

/** Preload the date: what is already recorded, and where each reading came from. */
function flvLoadMeterDay() {
    const date = document.getElementById('flvMeterDate').value;
    const body = document.getElementById('flvMeterBody');
    if (!flvMeterVehicle || !date) return;
    body.innerHTML = '<div class="fl-muted" style="font-size:12.5px;">Loading…</div>';

    fetch(FLV_BASE + '/' + flvMeterVehicle + '/meter-day?date=' + encodeURIComponent(date),
          {headers: {'Accept': 'application/json'}})
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.message || 'Could not load that day');
            flvMeterState = res;
            body.innerHTML = flvMeterForm(res);
        })
        .catch(e => { body.innerHTML = '<div style="color:#b91c1c;font-size:12.5px;">'
            + flEsc(e.message || 'Could not load that day') + '</div>'; });
}

function flvMeterForm(res) {
    const a = res.attendance;
    const l = res.log;

    // ── Section 1: the RIDER's own reading (attendance) ──────────────────────
    // Shown as his, editable only by someone the Attendance page would already
    // let correct it — the same act must not have two different locks depending
    // on which screen you are standing on.
    let attBlock = '';
    if (a) {
        const locked = !res.can_edit_attendance;
        attBlock =
          '<div class="fl-mrow" style="background:#f8f9fb;">'
        +   '<span class="fl-dc-k">rider reading</span>'
        +   '<b>' + flEsc(a.name || 'rider') + '</b>'
        +   '<span class="fl-muted">his own day on this machine</span>'
        +   (locked
              ? '<span class="fl-vchip unk" title="Correct it on the Attendance page">read-only here</span>'
              : '<span class="fl-vchip ok">you can correct it</span>')
        + '</div>'
        + '<div style="display:flex;gap:9px;margin-top:6px;">'
        +   '<div style="flex:1;"><label class="fl-dc-k">meter start</label>'
        +     '<input type="number" id="flvMeterAStart" value="' + (a.meter_start ?? '') + '"' + (locked ? ' disabled' : '')
        +     ' oninput="flvMeterHintCheck()" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;"></div>'
        +   '<div style="flex:1;"><label class="fl-dc-k">meter end</label>'
        +     '<input type="number" id="flvMeterAEnd" value="' + (a.meter_end ?? '') + '"' + (locked ? ' disabled' : '')
        +     ' oninput="flvMeterHintCheck()" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;"></div>'
        + '</div>';
    }

    // ── Section 2: the MACHINE's own log (manager entry) ─────────────────────
    // ⭐ ALWAYS rendered — before Aug-18 this block was hidden whenever an
    //   attendance row existed, which made a SECOND stint on the same date
    //   (holder rode, then a manager stint) impossible to record, and left an
    //   already-existing log row invisible ("shadowed"). Both records now show,
    //   each routed to its own home on Save.
    // ⭐⭐ WHO WAS DRIVING — prefilled (Aug-27 2026).
    //
    // The box used to open on "— no driver —" and every log row on prod carries NULL,
    // which is the same fact stated twice: nobody fills in a field that starts empty.
    // Yet the answer was already knowable — the server now sends `suggested_driver`,
    // resolved for THAT DATE (so a historical day names that day's holder, and a day the
    // machine changed hands names the last man to take it).
    //
    // ⚠ ORDER OF AUTHORITY: an existing row's own driver always wins — it is a recorded
    //   fact, and a suggestion must never quietly overwrite one. The suggestion only
    //   fills the gap, and the manager can always change it.
    const sug = res.suggested_driver || null;
    const preselect = (l && l.driver_user_id) ? l.driver_user_id : (sug ? sug.user_id : null);
    const drivers = (res.drivers || []).map(d =>
        '<option value="' + d.user_id + '"'
        + ((preselect && preselect === d.user_id) ? ' selected' : '')
        + '>' + flEsc(d.name) + '</option>').join('');
    // Say WHY that name is there, so a prefill never reads as a claim of fact.
    const sugLine = (sug && !(l && l.driver_user_id))
        ? '<div class="fl-muted" style="font-size:11.5px;margin-top:3px;">👤 Assigned that day: <b>'
          + flEsc(sug.name || '') + '</b> — change it if someone else drove.</div>'
        : '';

    const logHead = a
        ? (l ? 'entry on the same date' : 'add a second stint')
        : (l ? 'manager entry' : 'nothing recorded yet');

    const logBlock =
      '<div class="fl-mrow" style="background:#f8f9fb;margin-top:' + (a ? '12px' : '0') + ';">'
    +   '<span class="fl-dc-k">machine log</span><b>🧾 ' + logHead + '</b>'
    +   (l ? '<button type="button" class="fl-vbtn" style="margin-left:auto;" onclick="flvMeterClearLog()"'
           + ' title="Empty both readings removes this entry on Save">clear</button>' : '')
    + '</div>'
    + '<div style="display:flex;gap:9px;margin-top:6px;">'
    +   '<div style="flex:1;"><label class="fl-dc-k">meter start</label>'
    +     '<input type="number" id="flvMeterStart" value="' + (l ? (l.meter_start ?? '') : '') + '"'
    +     ' oninput="flvMeterHintCheck()" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;"></div>'
    +   '<div style="flex:1;"><label class="fl-dc-k">meter end</label>'
    +     '<input type="number" id="flvMeterEnd" value="' + (l ? (l.meter_end ?? '') : '') + '"'
    +     ' oninput="flvMeterHintCheck()" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;"></div>'
    + '</div>'
    // The driver only exists on a manager entry: a rider's own row already knows
    // whose day it was, and re-asking would invite a contradiction.
    + '<div style="margin-top:9px;"><label class="fl-dc-k">driver</label>'
    + '<select id="flvMeterDriver" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;">'
    +   '<option value="">— no driver (machine only) —</option>' + drivers
    + '</select>'
    + sugLine
    + '<div class="fl-muted" style="font-size:11.5px;margin-top:3px;">'
    + 'The named driver gets these kilometres in his own month too. '
    + '⚠ This does NOT hand him the machine — no handover, no meter demands change.</div></div>'
    + '<div style="margin-top:9px;"><label class="fl-dc-k">note</label>'
    + '<input type="text" id="flvMeterNote" maxlength="255" value="' + flEsc((l && l.note) || '') + '"'
    + ' placeholder="e.g. lunchtime delivery run" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:7px 9px;font-size:13px;"></div>';

    return attBlock + logBlock
      + '<div id="flvMeterHint" class="fl-muted" style="font-size:11.5px;margin-top:7px;"></div>';
}

function flvMeterClearLog() {
    const sEl = document.getElementById('flvMeterStart');
    const eEl = document.getElementById('flvMeterEnd');
    if (sEl) sEl.value = '';
    if (eEl) eEl.value = '';
    flvMeterHintCheck();
}

/**
 * ⚠ ADVICE, NEVER A BLOCK — the same rule as the handover odometer. A reading that
 *   looks wrong is still recorded; refusing it would leave the machine's history
 *   less complete than the manager's memory, which helps nobody.
 */
function flvMeterHintCheck() {
    const hint = document.getElementById('flvMeterHint');
    let sEl = document.getElementById('flvMeterStart');
    let eEl = document.getElementById('flvMeterEnd');
    if (!hint) return;
    // When the log inputs are empty but a rider block is present, advise on that
    // block instead — the manager is likely correcting the rider's reading.
    if (sEl && eEl && sEl.value === '' && eEl.value === '') {
        const aS = document.getElementById('flvMeterAStart');
        const aE = document.getElementById('flvMeterAEnd');
        if (aS && aE && (aS.value !== '' || aE.value !== '')) { sEl = aS; eEl = aE; }
    }
    if (!sEl || !eEl) return;
    const s = parseInt(sEl.value, 10), e = parseInt(eEl.value, 10);
    const w = (flvMeterState && flvMeterState.window) || {};

    if (!isNaN(s) && !isNaN(e) && e < s) {
        hint.style.color = '#b45309';
        hint.innerHTML = '⚠ The end reading is below the start. It will still be saved — check for a typo.';
        return;
    }
    if (!isNaN(s) && !isNaN(e)) {
        hint.style.color = '#15803d';
        hint.innerHTML = '✓ ' + flNum(e - s) + ' km on this machine for that day.';
        return;
    }
    if (w.floor && !isNaN(s) && s < w.floor) {
        hint.style.color = '#b45309';
        hint.innerHTML = '⚠ Below this machine\'s last known reading (' + flNum(w.floor) + ' km). Saved anyway.';
        return;
    }
    hint.style.color = '#6b7280';
    hint.innerHTML = 'Either reading may stand alone — add the close later on the same date.';
}

function flvSaveMeter() {
    const btn = document.getElementById('flvMeterSave');
    const err = document.getElementById('flvMeterError');
    const res = flvMeterState;
    if (!res) return;

    const date = document.getElementById('flvMeterDate').value;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    const val = id => { const el = document.getElementById(id); return el ? el.value : ''; };

    // ⭐ ROUTE EACH READING TO ITS OWN HOME — the whole point. The rider block
    //   goes back to his attendance row (via the shared corrector), the machine
    //   block to the log. Both can be saved in one go now that both are visible.
    const jobs = [];

    if (res.attendance && res.can_edit_attendance) {
        const aS = val('flvMeterAStart'), aE = val('flvMeterAEnd');
        const had = v => (v === null || v === undefined) ? '' : String(v);
        const fd = new FormData();
        fd.append('date', date);
        fd.append('target', 'attendance');
        fd.append('attendance_id', res.attendance.attendance_id);
        // Only fields actually CHANGED are sent — an untouched (or emptied)
        // field stays untouched. Clearing a rider's reading is an Attendance-page
        // act, not something this editor should do by accident.
        if (aS !== '' && aS !== had(res.attendance.meter_start)) fd.append('meter_start', parseInt(aS, 10));
        if (aE !== '' && aE !== had(res.attendance.meter_end))   fd.append('meter_end', parseInt(aE, 10));
        if (fd.has('meter_start') || fd.has('meter_end')) jobs.push(fd);
    }

    const lS = val('flvMeterStart'), lE = val('flvMeterEnd');
    const lD = val('flvMeterDriver'), lN = val('flvMeterNote').trim();
    const hasLogValue = lS !== '' || lE !== '';

    // A driver or note with no reading would be silently dropped server-side
    // (empty readings mean "remove the row") — say so instead of losing it.
    if (!hasLogValue && (lD || lN) && !res.log) {
        err.textContent = 'A driver or note needs at least one meter reading to attach to.';
        err.style.display = '';
        return;
    }

    if (hasLogValue || res.log) {
        // Empty readings on an EXISTING row = deliberate removal (server deletes).
        const fd = new FormData();
        fd.append('date', date);
        fd.append('target', 'log');
        if (lS !== '') fd.append('meter_start', parseInt(lS, 10));
        if (lE !== '') fd.append('meter_end', parseInt(lE, 10));
        if (lD) fd.append('driver_user_id', lD);
        if (lN) fd.append('note', lN);
        jobs.push(fd);
    }

    if (!jobs.length) {
        err.textContent = 'Nothing changed.';
        err.style.display = '';
        return;
    }

    err.style.display = 'none';
    btn.disabled = true; btn.textContent = 'Saving…';

    // Sequential on purpose — both writers flush the same derived-month cache.
    jobs.reduce((chain, fd) => chain.then(() =>
        fetch(FLV_BASE + '/' + flvMeterVehicle + '/meter-save', {
            method: 'POST',
            headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': csrf},
            body: fd
        })
        .then(r => r.json())
        .then(j => { if (!j.success) throw new Error(j.message || 'Could not save'); })
    ), Promise.resolve())
    .then(() => {
        flvCloseMeter();
        // ⭐ TRUE refresh (owner, Aug-18): every figure this save can move is
        //   redrawn, and every cache that could serve the OLD numbers is dropped.
        //   1. the client-side day-list cache — it is keyed per vehicle+month and
        //      would happily keep showing yesterday's rows after a save;
        //   2. the vehicles grid;  3. the RIDERS table (the named driver's month
        //   changed too);  4. the open profile, PRESERVING the tab the manager was
        //   on — flvOpen() resets to 'money', which would hide the very day list
        //   he was looking at.
        // ⚠ flvDaysCache is a const — clear it IN PLACE, reassignment throws.
        Object.keys(flvDaysCache).forEach(k => delete flvDaysCache[k]);
        const wasMode = flvDetailMode;
        flvLoad();
        if (typeof flLoad === 'function') flLoad();
        if (flvOpenId) {
            const vid = flvOpenId;
            flvOpen(vid);
            if (wasMode === 'days') {
                // flvOpen fetches the profile async; switch back once it lands.
                let tries = 0;
                (function back() {
                    if (flvLastRes && flvLastRes.vehicle && flvLastRes.vehicle.id === vid) {
                        flvSetDetailMode('days');
                    } else if (tries++ < 40) { setTimeout(back, 100); }
                })();
            }
        }
    })
    .catch(e => { err.textContent = e.message || 'Could not save.'; err.style.display = ''; })
    .finally(() => { btn.disabled = false; btn.textContent = 'Save'; });
}

function flvOpenAssign(vehicleId, preselectUserId) {
    const v = (flvData.vehicles || []).find(x => x.id === vehicleId);
    if (!v) return;

    document.getElementById('flvAssignVehicleId').value = vehicleId;
    document.getElementById('flvAssignTitle').textContent =
        (v.keeper_user_id ? 'Reassign ' : 'Assign ') + v.name;
    document.getElementById('flvAssignNote').value = '';
    document.getElementById('flvAssignPhotos').value = '';
    const mEl0 = document.getElementById('flvAssignMeter');
    if (mEl0) { mEl0.value = ''; flvMeterHint(); }
    document.getElementById('flvAssignError').style.display = 'none';
    document.getElementById('flvPreviewBox').innerHTML = '';
    flvClearDisplaced();

    // Local date, never toISOString() — that reads as yesterday before 5am PKT.
    const d = new Date();
    document.getElementById('flvAssignDate').value =
        d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');

    // When the flow started from a RIDER ("give him a bike") he is preselected and
    // the machine is the thing being chosen — the reverse of the usual direction.
    const want = preselectUserId || v.keeper_user_id;
    const sel = document.getElementById('flvAssignRider');
    sel.innerHTML = '<option value="">— choose a rider —</option>'
        + (flvData.riders || []).map(r =>
            '<option value="' + r.user_id + '"' + (r.user_id === want ? ' selected' : '') + '>'
          + flEsc(r.name) + (r.company_bike ? ' · 🏢' : ' · 👤') + (r.has_vehicle ? '' : ' · free')
          + '</option>').join('');

    // Which machine, when the manager came in from a rider card. Same filter as
    // flvAssignToRider: company machines + HIS own bike, never someone else's.
    const vsel = document.getElementById('flvAssignVehicleSel');
    if (vsel) {
        const spare = (flvData.vehicles || []).filter(x => !x.keeper_user_id && x.is_active
            && (x.is_company || x.last_keeper_user_id === preselectUserId));
        if (preselectUserId && spare.length > 1) {
            vsel.parentElement.style.display = '';
            vsel.innerHTML = spare.map(x =>
                '<option value="' + x.id + '"' + (x.id === vehicleId ? ' selected' : '') + '>'
              + flEsc(x.name) + (x.is_company ? ' · company' : ' · own') + '</option>').join('');
        } else {
            vsel.parentElement.style.display = 'none';
        }
    }

    document.getElementById('flvAssignModal').style.display = 'flex';
    if (sel.value) flvPreviewAssign();
}

/** The machine picker only appears in the rider-first flow; keep it in step. */
function flvAssignVehicleChanged() {
    const vsel = document.getElementById('flvAssignVehicleSel');
    if (!vsel || !vsel.value) return;
    document.getElementById('flvAssignVehicleId').value = vsel.value;
    flvPreviewAssign();
}

function flvCloseAssign() { document.getElementById('flvAssignModal').style.display = 'none'; }

/**
 * Ask the SERVER what this handover would do. Deliberately not computed here —
 * the answer depends on who currently holds what and whose home pin is missing,
 * and a stale guess in the browser would be worse than no preview at all.
 */
function flvPreviewAssign() {
    const vid  = document.getElementById('flvAssignVehicleId').value;
    const uid  = document.getElementById('flvAssignRider').value;
    const date = document.getElementById('flvAssignDate').value;
    const box  = document.getElementById('flvPreviewBox');
    if (!vid || !uid) { box.innerHTML = ''; return; }

    const seq = ++flvPreviewSeq;
    box.innerHTML = '<span style="color:#9ca3af;">Checking…</span>';

    fetch(FLV_BASE + '/' + vid + '/preview-assign?user_id=' + encodeURIComponent(uid)
          + '&date=' + encodeURIComponent(date), { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(res => {
            if (seq !== flvPreviewSeq) return;   // a newer preview already answered
            const lines = (res.lines || []).map(l =>
                '<div style="margin-bottom:3px;">• ' + flEsc(l) + '</div>').join('');
            const warns = (res.warnings || []).map(w =>
                '<div style="margin-top:6px;padding:7px 9px;border-radius:7px;background:#fffbeb;'
              + 'border:1px solid #fcd34d;color:#78350f;">⚠ ' + flEsc(w) + '</div>').join('');
            box.innerHTML = (lines || warns)
                ? '<div style="padding:9px 11px;border-radius:8px;background:#f9fafb;border:1px solid #e5e7eb;color:#374151;">'
                  + lines + warns + '</div>'
                : '';
            flvRenderDisplaced(res.displaced);
            flvRenderMetersRequired(res.meters_required || []);
        })
        .catch(() => { if (seq === flvPreviewSeq) box.innerHTML = ''; });
}

/* ═══════════════════════════════════════════════════════════════════════════
   ⭐⭐ "AND WHAT ABOUT HIM?" — the displaced rider.
   `flvDisplaced` holds the manager's answer, sent with the handover.
   ⭐ OWNER RULING (Aug-8): his own registered bike, when free, is the DEFAULT —
   preselected here AND applied server-side even when nothing is sent (older
   clients). The dangerous silent option ("no bike") still requires an explicit
   pick; when he has no own bike the manager must still choose.
   ═══════════════════════════════════════════════════════════════════════════ */
let flvDisplaced = { user_id: null, action: null, vehicle_id: null };
let flvDisplacedData = null;      // the server's description of who is losing it

/* ═══════════════════════════════════════════════════════════════════════════
   ⭐⭐ R3 — WHICH METER BOXES THIS MOVE REQUIRES (13-Sep-2026).

   The SERVER decides (`previewAssign` / `releasePreview` → `meters_required`), so this screen,
   the store-mode sheet and the rider's own sheet cannot disagree about whether a reading is
   optional. Each entry is {field, vehicle_id, label, which, prompt}.
   ⚠ `handover_meter`'s own box is always visible (it stays optional for an own bike); this only
     re-labels it "(required)" and shows the extra `vacated_meter` box when the server asks.
   ═══════════════════════════════════════════════════════════════════════════ */
let flvMetersRequired = [];
function flvNeedsMeter(field) {
    return flvMetersRequired.find(m => m && m.field === field) || null;
}
function flvRenderMetersRequired(list) {
    flvMetersRequired = Array.isArray(list) ? list : [];

    // the opening reading — same box as before, just labelled honestly
    const openReq = flvNeedsMeter('handover_meter');
    const openLbl = document.querySelector('label[for="flvAssignMeter"]')
                 || document.getElementById('flvAssignMeter')?.previousElementSibling;
    if (openLbl) {
        openLbl.innerHTML = 'Odometer at handover ' + (openReq
            ? '<span style="font-weight:500;color:#b45309;">(required)</span>'
            : '<span style="font-weight:400;color:#9ca3af;">(optional)</span>');
    }

    // the CLOSING reading of whatever he is stepping off
    const vac  = flvNeedsMeter('vacated_meter');
    const wrap = document.getElementById('flvVacatedWrap');
    if (wrap) {
        wrap.style.display = vac ? '' : 'none';
        if (vac) {
            document.getElementById('flvVacatedLabel').textContent =
                (vac.label || 'His current machine') + ' — closing meter';
            document.getElementById('flvVacatedHint').textContent = vac.prompt || '';
        } else {
            const el = document.getElementById('flvVacatedMeter');
            if (el) el.value = '';
        }
    }
}

function flvClearDisplaced() {
    flvDisplaced = { user_id: null, action: null, vehicle_id: null };
    flvDisplacedData = null;
    const box = document.getElementById('flvDisplacedBox');
    if (box) box.style.display = 'none';
}

function flvRenderDisplaced(d) {
    const box = document.getElementById('flvDisplacedBox');
    if (!box) return;
    if (!d) { flvClearDisplaced(); return; }

    // A new person to ask about → reset. ⭐ OWNER RULING (Aug-8): when his own
    // registered bike is free, "back on his own bike" is the DEFAULT — the server
    // applies it even if nothing is sent. "No bike" stays an explicit choice.
    if (flvDisplaced.user_id !== d.user_id) {
        flvDisplaced = { user_id: d.user_id, action: d.own ? 'own' : null, vehicle_id: null };
    }
    flvDisplacedData = d;
    const first = (d.name || '').split(' ')[0];

    document.getElementById('flvDisplacedQ').textContent =
        'And ' + d.name + '? He is giving this machine up — what is he on now?';

    const opt = (action, label, sub) =>
        '<label style="display:flex;gap:7px;align-items:flex-start;cursor:pointer;font-size:12.5px;color:#374151;">'
      + '<input type="radio" name="flvDisp" style="margin-top:2px;"'
      + (flvDisplaced.action === action ? ' checked' : '')
      + ' onchange="flvDisplacedPick(\'' + action + '\')">'
      + '<span><b>' + label + '</b>'
      + (sub ? '<br><span style="color:#92400e;font-size:11.5px;">' + sub + '</span>' : '')
      + '</span></label>';

    let html = '';
    if (d.own) {
        html += opt('own', '👤 Back on his own bike — ' + flEsc(d.own.name),
                    'His own machine is free — this is what happens unless you pick otherwise.');
    }
    if ((d.spare || []).length) {
        html += opt('vehicle', '🏍️ Onto another machine', 'Pick which one below.');
    }
    html += opt('none', '🚫 No bike for now',
                d.goes_quiet
                  ? first + ' will not be asked for meter readings, and cannot file his own fuel, until he has one again.'
                  : 'He will show under “Riders with no machine” on the fleet screen.');

    document.getElementById('flvDisplacedOpts').innerHTML = html;

    const sel = document.getElementById('flvDisplacedVehicle');
    sel.innerHTML = '<option value="">— which machine? —</option>'
        + (d.spare || []).map(s => '<option value="' + s.id + '">' + flEsc(s.name)
            + (s.is_company ? ' · company' : ' · own') + '</option>').join('');
    sel.style.display = flvDisplaced.action === 'vehicle' ? '' : 'none';
    box.style.display = '';
}

function flvDisplacedPick(action) {
    flvDisplaced.action = action;
    const sel = document.getElementById('flvDisplacedVehicle');
    if (action === 'vehicle') {
        sel.style.display = '';
        flvDisplaced.vehicle_id = sel.value || null;
    } else {
        sel.style.display = 'none';
        flvDisplaced.vehicle_id = null;
    }
}

/**
 * ⚠⚠ ADVICE, NOT A GATE. The reading is checked against what we know of this
 *    machine's odometer and the manager is told when it looks wrong — but the
 *    handover is never blocked. A bike that has physically changed hands must be
 *    recordable even when a digit is questionable, or the register starts lying
 *    about who has the machine, which is far worse than one odd number.
 */
function flvMeterHint() {
    const el = document.getElementById('flvAssignMeter');
    const hint = document.getElementById('flvMeterHint');
    if (!el || !hint) return;

    const v = parseInt(el.value, 10);
    if (el.value === '' || isNaN(v)) {
        hint.style.color = '#6b7280';
        hint.innerHTML = 'If you enter it, this day\'s kilometres split exactly between the two riders '
                       + 'instead of showing as shared. Leave blank if you don\'t know it.';
        return;
    }

    const vid = parseInt(document.getElementById('flvAssignVehicleId').value, 10);
    const veh = ((flvData && flvData.vehicles) || []).find(x => x.id === vid);
    const now = veh && veh.current_meter ? parseInt(veh.current_meter, 10) : null;

    if (now !== null && v < now) {
        hint.style.color = '#b45309';
        hint.innerHTML = '⚠ That is <b>below</b> this machine\'s last known reading ('
                       + flNum(now) + ' km). It will still be saved — check for a typo.';
    } else if (now !== null && v - now > 2000) {
        hint.style.color = '#b45309';
        hint.innerHTML = '⚠ That is ' + flNum(v - now) + ' km above the last known reading ('
                       + flNum(now) + ' km). It will still be saved — check for a typo.';
    } else {
        hint.style.color = '#15803d';
        hint.innerHTML = '✓ This day\'s kilometres will be split exactly at ' + flNum(v) + ' km.';
    }
}

function flvSaveAssign() {
    const vid  = document.getElementById('flvAssignVehicleId').value;
    const uid  = document.getElementById('flvAssignRider').value;
    const err  = document.getElementById('flvAssignError');
    const btn  = document.getElementById('flvAssignSave');
    if (!uid) { err.textContent = 'Choose a rider first.'; err.style.display = ''; return; }

    // ⚠ Somebody is losing this machine and the manager has not said what he is on
    //   now. Refusing here is the whole point — a silent default is what left riders
    //   stranded with no machine and the old rules still judging them.
    if (flvDisplacedData && flvDisplacedData.user_id && !flvDisplaced.action) {
        err.textContent = 'Say what ' + flvDisplacedData.name + ' is on now — that is the question above.';
        err.style.display = ''; return;
    }
    if (flvDisplaced.action === 'vehicle' && !flvDisplaced.vehicle_id) {
        err.textContent = 'Choose which machine he moves onto.';
        err.style.display = ''; return;
    }

    // ⭐ R3 — refuse locally with the SERVER's own prompt before spending a round trip. The
    //   engine refuses too (this is a courtesy, not the gate) and its 422 names the field.
    const mEl  = document.getElementById('flvAssignMeter');
    const vEl  = document.getElementById('flvVacatedMeter');
    const need = flvNeedsMeter('handover_meter');
    const needVac = flvNeedsMeter('vacated_meter');
    if (need && (!mEl || mEl.value === '' || isNaN(parseInt(mEl.value, 10)))) {
        err.textContent = need.prompt || 'This is a company machine — record its meter reading.';
        err.style.display = ''; return;
    }
    if (needVac && (!vEl || vEl.value === '' || isNaN(parseInt(vEl.value, 10)))) {
        err.textContent = needVac.prompt || 'Record the closing reading of the machine he is handing back.';
        err.style.display = ''; return;
    }

    const fd = new FormData();
    fd.append('user_id', uid);
    fd.append('date', document.getElementById('flvAssignDate').value || '');
    fd.append('note', document.getElementById('flvAssignNote').value || '');
    // Optional for an own bike. Sent only when typed — an empty box must not be read as "0 km".
    if (mEl && mEl.value !== '' && !isNaN(parseInt(mEl.value, 10))) {
        fd.append('handover_meter', parseInt(mEl.value, 10));
    }
    // R3 — the closing reading of the machine this rider is stepping OFF.
    if (vEl && vEl.value !== '' && !isNaN(parseInt(vEl.value, 10))) {
        fd.append('vacated_meter', parseInt(vEl.value, 10));
    }
    if (flvDisplaced.action) {
        fd.append('displaced_action', flvDisplaced.action);
        if (flvDisplaced.vehicle_id) fd.append('displaced_vehicle_id', flvDisplaced.vehicle_id);
    }
    const files = document.getElementById('flvAssignPhotos').files;
    for (let i = 0; i < files.length && i < 8; i++) fd.append('photos[]', files[i]);

    err.style.display = 'none';
    btn.disabled = true; btn.textContent = 'Saving…';

    fetch(FLV_BASE + '/' + vid + '/assign', {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        },
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) throw new Error(res.message || 'Failed');
        flvCloseAssign();
        flvLoad();
        if (flvOpenId === Number(vid)) flvOpen(Number(vid));
    })
    .catch(e => { err.textContent = e.message || 'Could not assign that vehicle.'; err.style.display = ''; })
    .finally(() => { btn.disabled = false; btn.textContent = 'Assign'; });
}

/**
 * Taking a machine back displaces its rider exactly as reassigning does, so it
 * asks the same question — otherwise "Take back" would remain the one door that
 * still leaves a man with nothing and nobody told.
 */
function flvRelease(vehicleId) {
    const v = (flvData.vehicles || []).find(x => x.id === vehicleId);
    if (!v) return;

    fetch(FLV_BASE + '/' + vehicleId + '/preview-release', { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(res => flvDoRelease(v, res.displaced || null, res.meters_required || []))
        .catch(() => flvDoRelease(v, null, []));   // the prompt is a nicety, never a blocker
}

function flvDoRelease(v, d, metersRequired) {
    let action = null, targetVehicle = null;

    /* ⭐⭐ R3 — THE MACHINE'S OWN CLOSING READING (13-Sep-2026).
       ⚠⚠ `VehicleService::release()` took no meter at all until today, so "Take back" has NEVER
          recorded a closing odometer from this screen: the number had nowhere to go. On 13-Sep
          that left the van with no reading at either end of Rajab's day.
       ⚠ Asked FIRST, before the displaced-rider question, because it is about the machine in
         front of the manager rather than about somebody's next bike. */
    const needClose = (metersRequired || []).find(m => m && m.field === 'meter') || null;
    let closeMeter = null;
    if (needClose) {
        const ans = prompt(needClose.prompt
            || (v.name + ' — meter reading as it comes back:'), '');
        if (ans === null) return;                                  // cancelled
        const n = parseInt(String(ans).replace(/[^0-9]/g, ''), 10);
        if (isNaN(n)) {
            alert('A meter reading is required for a company machine — nothing changed.');
            return;
        }
        closeMeter = n;
    }

    if (d) {
        const first = (d.name || '').split(' ')[0];
        // ⭐ OWNER RULING (Aug-8): his own bike, when free, is option 1 AND the
        //   default — "no bike" must be chosen deliberately, never inherited.
        const choices = []; const keys = [];
        if (d.own) { choices.push('Back on his own bike — ' + d.own.name + ' (the default)'); keys.push('own'); }
        if ((d.spare || []).length) { choices.push('Onto another machine'); keys.push('vehicle'); }
        choices.push('Nothing for now' + (d.goes_quiet
            ? ' (he stops being asked for meter readings)' : ''));
        keys.push('none');

        const menu = choices.map((c, i) => '  ' + (i + 1) + '. ' + c).join('\n');
        const ans = prompt('Take ' + v.name + ' back from ' + d.name + '.\n\n'
            + 'What is ' + first + ' on after this?\n' + menu + '\n\nType a number:', '1');
        if (ans === null) return;                       // cancelled

        const idx = parseInt(ans, 10) - 1;
        if (isNaN(idx) || idx < 0 || idx >= keys.length) { alert('Not one of the options — nothing changed.'); return; }
        action = keys[idx];

        if (action === 'vehicle') {
            const list = d.spare.map((s, i) => '  ' + (i + 1) + '. ' + s.name
                + (s.is_company ? ' (company)' : ' (own)')).join('\n');
            const pick = prompt('Which machine does ' + first + ' move onto?\n' + list + '\n\nType a number:', '1');
            if (pick === null) return;
            const pi = parseInt(pick, 10) - 1;
            if (isNaN(pi) || pi < 0 || pi >= d.spare.length) { alert('Not one of the options — nothing changed.'); return; }
            targetVehicle = d.spare[pi].id;
        }
    } else if (!confirm('Take ' + v.name + ' back from ' + (v.keeper_name || 'its rider') + '?\n\n'
               + 'Its history stays on record; it simply has no rider until you assign it again.')) {
        return;
    }

    const fd = new FormData();
    if (action) {
        fd.append('displaced_action', action);
        if (targetVehicle) fd.append('displaced_vehicle_id', targetVehicle);
    }
    if (closeMeter !== null) fd.append('meter', closeMeter);   // R3 — its closing reading

    fetch(FLV_BASE + '/' + v.id + '/release', {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        },
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) throw new Error(res.message || 'Failed');
        flvLoad();
        if (flvOpenId === v.id) flvOpen(v.id);
    })
    .catch(e => alert(e.message || 'Could not release that vehicle.'));
}

// ── add / edit ────────────────────────────────────────────────────────────
/** Set by "New own bike for X": the rider it is handed to as soon as it exists. */
let flvPendingAssignUser = null;

function flvOpenEdit(id) {
    // ⚠ Cleared on EVERY open. flvNewOwnBike() sets it right after calling this, so
    //   an abandoned "new own bike" can never hand the NEXT vehicle to that rider.
    flvPendingAssignUser = null;
    const v = id ? (flvData.vehicles || []).find(x => x.id === id) : null;
    document.getElementById('flvEditId').value        = id || '';
    document.getElementById('flvEditTitle').textContent = v ? ('Edit ' + v.name) : 'Add a vehicle';
    document.getElementById('flvEditType').value      = v ? v.vtype : 'bike';
    document.getElementById('flvEditReg').value       = v ? (v.reg_no || '') : '';
    document.getElementById('flvEditNick').value      = v ? (v.nickname || '') : '';
    document.getElementById('flvEditModelName').value = v ? (v.make_model || '') : '';
    // ⚠ The STORED override, never the derived interval. `service.interval_km` is the
    //   due job's own schedule (1,200 from Oil Change even when this bike has no
    //   override at all) — pre-filling from it would turn "follow the company
    //   default" into a hard-coded 1,200 the moment anyone opened and saved this form.
    //   Blank here means blank in the database, which is what "default" is.
    document.getElementById('flvEditCompany').checked = v ? !!v.is_company : true;
    document.getElementById('flvEditActive').checked  = v ? !!v.is_active : true;
    document.getElementById('flvEditLat').value    = v && v.base && v.base.latitude  !== null ? v.base.latitude  : '';
    document.getElementById('flvEditLng').value    = v && v.base && v.base.longitude !== null ? v.base.longitude : '';
    document.getElementById('flvEditRadius').value = v && v.base && v.base.radius_m  !== null ? v.base.radius_m  : '';
    document.getElementById('flvEditError').style.display = 'none';
    document.getElementById('flvEditModal').style.display = 'flex';
}

function flvCloseEdit() { document.getElementById('flvEditModal').style.display = 'none'; }

function flvSaveVehicle() {
    const id  = document.getElementById('flvEditId').value;
    const err = document.getElementById('flvEditError');
    const btn = document.getElementById('flvEditSave');
    const csrf = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const body = {
        vtype:      document.getElementById('flvEditType').value,
        reg_no:     document.getElementById('flvEditReg').value.trim(),
        nickname:   document.getElementById('flvEditNick').value.trim(),
        make_model: document.getElementById('flvEditModelName').value.trim(),
        is_company: document.getElementById('flvEditCompany').checked,
        is_active:  document.getElementById('flvEditActive').checked,
        /* ⚠ `service_interval_km` is deliberately NOT sent. The server keeps accepting
             it (an old client may still post it) but this form no longer writes it —
             see the note where the field used to be. Omitting the key leaves any
             existing value alone rather than clearing it. */
    };

    err.style.display = 'none';
    btn.disabled = true; btn.textContent = 'Saving…';

    fetch(FLV_BASE + (id ? '/' + id : ''), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify(body)
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) throw new Error(res.message || 'Failed');
        // The base is a second, separate write — it has its own endpoint because
        // clearing it is meaningful (the machine goes back to following its rider).
        const vid = id || (res.vehicle && res.vehicle.id);
        const lat = document.getElementById('flvEditLat').value.trim();
        const lng = document.getElementById('flvEditLng').value.trim();
        const rad = document.getElementById('flvEditRadius').value.trim();
        const hadBase = res.vehicle && res.vehicle.base && res.vehicle.base.has_base;

        // ⭐ "New own bike for [rider]" — hand it over the moment it exists, so the
        //   onboarding path is genuinely ONE step. Chained, not fired alongside:
        //   the vehicle must exist before it can be assigned.
        // ⚠ AWAITED and CHECKED (14 Aug): this used to be fire-and-forget with an
        //   empty catch, so a failed assign showed pure success and the reload
        //   raced it — the manager saw "no bike" and had no idea why. A failure
        //   now says the bike EXISTS and where to finish the job.
        const pending = flvPendingAssignUser;
        flvPendingAssignUser = null;
        let assignP = Promise.resolve();
        if (pending && vid) {
            const afd = new FormData();
            afd.append('user_id', pending);
            assignP = fetch(FLV_BASE + '/' + vid + '/assign', {
                method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf }, body: afd
            })
            .then(r => r.json())
            .then(a => {
                if (!a.success) {
                    throw new Error('The bike was created, but could not be handed over: '
                        + (a.message || 'assignment failed') + ' Assign it from its card.');
                }
            })
            .catch(e => {
                throw (e instanceof Error ? e : new Error(
                    'The bike was created, but the handover did not go through. Assign it from its card.'));
            });
        }

        if (!vid || (!lat && !lng && !hadBase)) return assignP;
        return assignP.then(() => fetch(FLV_BASE + '/' + vid + '/base', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(
                (!lat || !lng)
                    ? { clear: true }
                    : { latitude: lat, longitude: lng, radius_m: parseInt(rad, 10) || null })
        }).then(r => r.json()).then(b => { if (!b.success) throw new Error(b.message || 'Base not saved'); }));
    })
    .then(() => { flvCloseEdit(); flvLoad(); if (flvOpenId) flvOpen(flvOpenId); })
    .catch(e => { err.textContent = e.message || 'Could not save.'; err.style.display = ''; })
    .finally(() => { btn.disabled = false; btn.textContent = 'Save'; });
}

/** Today as YYYY-MM-DD, LOCAL — never toISOString(), which reads as yesterday
 *  before 5am PKT and would then also block picking today as `max`. */
function flvTodayYmd() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0')
         + '-' + String(d.getDate()).padStart(2, '0');
}

function flvUploadMore(vehicleId) {
    const input = document.getElementById('flvMorePhotos');
    if (!input || !input.files.length) { alert('Choose one or more photos first.'); return; }

    const fd = new FormData();
    for (let i = 0; i < input.files.length && i < 8; i++) fd.append('photos[]', input.files[i]);
    fd.append('context', 'condition');
    // ⭐ The day the condition was as photographed — not the day it was uploaded.
    const dEl = document.getElementById('flvMoreDate');
    const nEl = document.getElementById('flvMoreNote');
    if (dEl && dEl.value) fd.append('date', dEl.value);
    if (nEl && nEl.value.trim()) fd.append('note', nEl.value.trim());

    fetch(FLV_BASE + '/' + vehicleId + '/photos', {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        },
        body: fd
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) throw new Error(res.message || 'Failed');
        flvOpen(vehicleId);
        flvLoad();
    })
    .catch(e => alert(e.message || 'Could not upload those photos.'));
}

/* ── which machine each day was on, inside the rider drill-down ───────────── */
let flvDayMap = {};          // 'YYYY-MM-DD' -> {vehicle_id, label, overridden, transfer}
let flvDaysCanManage = false;

/**
 * The chip beside a day's date. Silent when there is nothing to say — a fleet
 * with one bike per rider would otherwise repeat the same plate 30 times down
 * the column, which is noise, not information. So it renders only when the day
 * is interesting (a manager correction, or a handover) or when the manager can
 * act on it.
 */
function flvDayChip(uid, date) {
    const d = flvDayMap[date];
    if (!d || !d.label) {
        return flvDaysCanManage
            ? ' <a href="#" class="fl-vdaylink" onclick="flvEditDay(' + uid + ',\'' + date + '\');return false;"'
              + ' title="Record which vehicle he was on">🏍️ set</a>'
            : '';
    }
    let chip = '';
    if (d.overridden) {
        chip = '<span class="fl-vdaychip fix" title="Recorded by a manager for this day">✎ '
             + flEsc(d.label) + '</span>';
    } else if (d.transfer) {
        chip = '<span class="fl-vdaychip xfer" title="This vehicle changed hands on this day — '
             + 'the handover ride is allowed for, not counted as personal use">🔁 ' + flEsc(d.label) + '</span>';
    } else {
        return flvDaysCanManage
            ? ' <a href="#" class="fl-vdaylink" onclick="flvEditDay(' + uid + ',\'' + date + '\');return false;"'
              + ' title="' + flEsc(d.label) + ' — click to correct">' + flEsc(d.label) + '</a>'
            : ' <span class="fl-vdaychip">' + flEsc(d.label) + '</span>';
    }
    return ' ' + (flvDaysCanManage
        ? '<a href="#" style="text-decoration:none;" onclick="flvEditDay(' + uid + ',\'' + date + '\');return false;">'
          + chip + '</a>'
        : chip);
}

/**
 * Correct (or clear) the machine recorded against one day.
 *
 * The vehicle list is fetched on demand rather than assumed: this is reachable
 * from the costs drawer without the Vehicles view ever having been opened, and
 * "open that tab first, then come back" is not an instruction worth giving.
 */
async function flvEditDay(uid, date) {
    let list = ((flvData && flvData.vehicles) || []);
    if (!list.length) {
        try {
            const res = await fetch(FLV_BASE, {headers: {'Accept': 'application/json'}}).then(r => r.json());
            if (res && res.success) { flvData = res; list = res.vehicles || []; }
        } catch (e) { /* falls through to the message below */ }
    }
    if (!list.length) {
        alert('No vehicles are registered yet, so there is nothing to record against this day.');
        return;
    }
    // ⭐ Retired machines belong in THIS list — a bike retired today may well be the one he rode
    //   last week, and this picker is about history. Label them so the choice is informed.
    const menu = list.map((v, i) => (i + 1) + ' = ' + v.name + (v.is_active ? '' : ' (retired)')).join('\n');
    const ans = window.prompt(
        'Which vehicle was he on for ' + date + '?\n\n' + menu +
        '\n\n0 = follow the normal assignment (clear any correction)',
        '');
    if (ans === null) return;

    const n = parseInt(String(ans).trim(), 10);
    if (isNaN(n) || n < 0 || n > list.length) { alert('Enter a number from the list.'); return; }
    const vehicleId = n === 0 ? null : list[n - 1].id;

    fetch(FLV_BASE + '/day-override', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json', 'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
        },
        body: JSON.stringify({user_id: uid, date: date, vehicle_id: vehicleId})
    })
    .then(r => r.json())
    .then(res => {
        if (!res.success) throw new Error(res.message || 'Failed');
        flSelectRider(uid);      // re-render the drawer with the corrected day
    })
    .catch(e => alert(e.message || 'Could not save that.'));
}

/** "12 Jan 26" — short, unambiguous, and never a US month/day flip. */
function flvDate(d) {
    if (!d) return '';
    const x = new Date(d + 'T12:00:00');
    return isNaN(x) ? d : x.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: '2-digit' });
}

// =============================================================================
// 🏠 HOME LOCATION — the Bikes tab's half of the shared engine (Sep-2026).
//
// Owner ruling: a rider's home is what decides where he takes the COMPANY vehicle
// overnight, and riders swap between a company machine and their own constantly.
// So the pin lives independently of the "company bike" tick, and it is editable
// from wherever the manager already is — this tab, the Riders page, or his phone.
//
// ⭐ ONE ENGINE. Nothing here parses a Google link. The raw text goes to the server
//    exactly as pasted; RiderHomePinService resolves it (short link → place URL →
//    coordinates, with a Plus Code fallback) and hands back what it STORED. That is
//    why a place-ID link the browser could never decode still works here, and why
//    this tab and the phone can never disagree about one rider's home.
//
// ⚠ A refusal leaves the stored pin ALONE. On `success:false` we show the reason and
//   keep the sheet open with the manager's text still in it — losing a good pin to a
//   bad paste is the exact failure this whole round exists to close.
// =============================================================================
let flHpUserId = null;

function flHpUrl(userId) { return FL_BASE + '/home-pin/' + Number(userId); }

function flHpHeaders() {
    return {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
    };
}

function flOpenHomePin(userId) {
    flHpUserId = Number(userId);
    document.getElementById('flHpName').textContent = 'rider';
    document.getElementById('flHpText').value = '';
    document.getElementById('flHpLat').value = '';
    document.getElementById('flHpLng').value = '';
    document.getElementById('flHpRadius').value = '';
    document.getElementById('flHpMsg').textContent = '';
    document.getElementById('flHpMsg').className = 'fl-hpmsg';
    document.getElementById('flHpCurrent').textContent = 'Loading…';
    document.getElementById('flHpHistory').innerHTML = '';
    document.getElementById('flHomePinModal').style.display = 'block';

    fetch(flHpUrl(flHpUserId), { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(j => flRenderHomePin(j))
        .catch(() => {
            document.getElementById('flHpCurrent').textContent = 'Could not load this rider’s home location.';
        });
}

function flCloseHomePin() {
    document.getElementById('flHomePinModal').style.display = 'none';
    flHpUserId = null;
}

/** Paint whatever the SERVER says is stored — never what was typed. */
function flRenderHomePin(j) {
    if (!j || (j.success === false && !j.home_pin)) {
        document.getElementById('flHpCurrent').textContent = (j && j.message) ? j.message : 'Not available.';
        return;
    }
    const hp = j.home_pin || {};
    document.getElementById('flHpName').textContent = j.rider_name || 'rider';

    const cur = document.getElementById('flHpCurrent');
    if (hp.has_pin) {
        cur.innerHTML =
            '✓ Saved: <b>' + Number(hp.lat).toFixed(7) + ', ' + Number(hp.lng).toFixed(7) + '</b>'
            + ' · <a href="' + flEsc(hp.maps_url) + '" target="_blank" rel="noopener"'
            + ' style="color:#047857;font-weight:700;">view on map ↗</a><br>'
            + '<span style="color:#6b7280;">Set'
            + (hp.set_by_name ? ' by ' + flEsc(hp.set_by_name) : '')
            + (hp.set_at ? ' on ' + flEsc(String(hp.set_at).slice(0, 10)) : '')
            + ' · geofence ' + (hp.effective_radius_m || 300) + ' m'
            + (hp.radius_m == null ? ' (global default)' : ' (set for this rider)')
            + '</span>';
        // ⭐ Prefill the coordinate boxes so a manager can nudge an existing pin rather
        //   than having to re-source a link for a small correction.
        document.getElementById('flHpLat').value = hp.lat;
        document.getElementById('flHpLng').value = hp.lng;
        document.getElementById('flHpRadius').value = (hp.radius_m == null ? '' : hp.radius_m);
    } else {
        cur.innerHTML = '<span style="color:#92400e;">⚠ No home location on file — the overnight and '
            + 'morning meter checks have nowhere to measure from.</span>';
    }

    const canEdit = !!j.can_manage;
    document.getElementById('flHpSave').style.display = canEdit ? '' : 'none';
    document.getElementById('flHpDelete').style.display = (canEdit && hp.has_pin) ? '' : 'none';
    document.getElementById('flHpText').disabled = !canEdit;
    document.getElementById('flHpLat').disabled = !canEdit;
    document.getElementById('flHpLng').disabled = !canEdit;
    document.getElementById('flHpRadius').disabled = !canEdit;

    // The trail — who moved this rider's home, and when. The owner asked for exactly
    // this: "have a tracking to know who saved it."
    const hist = j.history || [];
    document.getElementById('flHpHistory').innerHTML = hist.length
        ? '<div style="font-weight:700;color:#374151;margin-bottom:4px;">Change history</div>'
          + hist.map(function (h) {
              return '<div>' + flEsc(String(h.at || '').slice(0, 16)) + ' — <b>' + flEsc(h.by) + '</b> '
                  + (h.action === 'clear' ? 'removed it' : 'set it')
                  + (h.new ? ' to ' + Number(h.new.lat).toFixed(5) + ', ' + Number(h.new.lng).toFixed(5) : '')
                  + (h.source ? ' <span style="color:#9ca3af;">(' + flEsc(h.source) + ')</span>' : '')
                  + '</div>';
            }).join('')
        : '';
}

function flSaveHomePin() {
    if (!flHpUserId) return;
    const btn = document.getElementById('flHpSave');
    const msg = document.getElementById('flHpMsg');
    const radius = document.getElementById('flHpRadius').value;

    btn.disabled = true;
    msg.className = 'fl-hpmsg';
    msg.textContent = 'Saving…';

    fetch(flHpUrl(flHpUserId), {
        method: 'POST',
        headers: flHpHeaders(),
        body: JSON.stringify({
            text: document.getElementById('flHpText').value,
            latitude: document.getElementById('flHpLat').value,
            longitude: document.getElementById('flHpLng').value,
            home_radius_m: radius === '' ? null : radius
        })
    })
    .then(r => r.json())
    .then(j => {
        btn.disabled = false;
        if (!j.success) {
            // ⚠ Nothing was written. Keep what he typed on screen so he can fix it.
            msg.className = 'fl-hpmsg err';
            msg.textContent = j.message || 'That could not be read, so the home location was not changed.';
            flRenderHomePin(j);
            return;
        }
        msg.className = 'fl-hpmsg ok';
        msg.textContent = j.message || 'Saved.';
        document.getElementById('flHpText').value = '';
        flRenderHomePin(j);
        flvRefreshAfterHomePin();
    })
    .catch(() => {
        btn.disabled = false;
        msg.className = 'fl-hpmsg err';
        msg.textContent = 'Could not reach the server — nothing was changed.';
    });
}

function flClearHomePin() {
    if (!flHpUserId) return;
    if (!confirm('Remove this rider’s home location?\n\nThe overnight and morning meter checks will have nowhere to measure from until a new one is set.')) {
        return;
    }
    const msg = document.getElementById('flHpMsg');
    msg.className = 'fl-hpmsg';
    msg.textContent = 'Removing…';

    fetch(flHpUrl(flHpUserId), { method: 'DELETE', headers: flHpHeaders() })
        .then(r => r.json())
        .then(j => {
            msg.className = j.success ? 'fl-hpmsg ok' : 'fl-hpmsg err';
            msg.textContent = j.message || (j.success ? 'Removed.' : 'Could not remove it.');
            document.getElementById('flHpLat').value = '';
            document.getElementById('flHpLng').value = '';
            document.getElementById('flHpRadius').value = '';
            flRenderHomePin(j);
            flvRefreshAfterHomePin();
        })
        .catch(() => {
            msg.className = 'fl-hpmsg err';
            msg.textContent = 'Could not reach the server — nothing was changed.';
        });
}

/** Whatever is behind the modal is now stale — the 🏠 pill and the vehicle-card nag
 *  should both settle immediately.
 *
 *  ⚠ Refreshes the view the manager is ACTUALLY looking at. The editor opens from two
 *    places — the riders table and a vehicle card — and reloading only the vehicles grid
 *    left the riders row still showing "set home" after a successful save, which reads
 *    exactly like the save failed.
 *  Non-fatal throughout: a failed refresh must never look like a failed save. */
function flvRefreshAfterHomePin() {
    try {
        if (flvMode === 'vehicles') {
            if (typeof flvLoad === 'function') flvLoad();
        } else if (typeof flLoad === 'function') {
            flLoad(flMonth, true);
        }
    } catch (e) { /* cosmetic */ }
}

</script>
