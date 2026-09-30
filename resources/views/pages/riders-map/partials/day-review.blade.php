{{--
    Day Review (Jul-2026) — replaces the old History + Dispatch Tracker + Issues tabs.

    Self-contained on purpose: its own markup, styles (.dr-* prefix), state (dr*)
    and Leaflet instance. It shares NOTHING with the older tabs' JS, so the two
    can coexist during rollout and the old blocks can be deleted later without
    touching this file.

    Everything shown here comes from /orders/riders-map/day-review[/rider].
    Forensic rows (route, stops, verdicts) arrive only when the signed-in user
    has `view_rider_reports` — the same gate the old Issues tab used.
--}}

<div id="dayReviewView" style="display: none;">

    <!-- Day bar: date + headline tiles -->
    <div class="dr-bar">
        <div class="dr-datewrap">
            <button class="dr-nav" onclick="drShiftDay(-1)" title="Previous day">‹</button>
            <input type="date" id="drDateInput" onchange="drLoadDay(this.value)">
            <button class="dr-nav" onclick="drShiftDay(1)" title="Next day">›</button>
            <button class="dr-today" onclick="drSetToday()">Today</button>
        </div>
        <div id="drTiles" class="dr-tiles"></div>
    </div>

    <div id="drTrailNote" class="dr-note" style="display:none;"></div>
    {{-- 🚚 The van day. Rendered ABOVE the rider rail because it is the story
         those rows cannot tell: the rider lines show each person's deliveries,
         never the choreography between them. Hidden entirely on days no van
         ran, so an ordinary day looks exactly as it always has. --}}
    <div id="drVanTrips" class="dr-van" style="display:none;"></div>

    <!-- Level 1: rider cards -->
    <div id="drRiderRail" class="dr-rail">
        <div class="dr-empty">Loading…</div>
    </div>

    <!-- Level 2: one rider's day -->
    <div id="drDetail" style="display:none;">
        <div class="dr-detailhead">
            <button class="btn-back" onclick="drBackToRiders()">← All riders</button>
            <h3 id="drRiderName"></h3>
            <div id="drRiderSummary" class="dr-detailsummary"></div>
        </div>
        <div class="dr-split">
            <div class="dr-orders">
                <div class="dr-colhead">
                    <span>Deliveries, in order</span>
                    <label class="dr-onlyflag"><input type="checkbox" id="drOnlyFlagged" onchange="drRenderOrders()"> Only flagged</label>
                </div>
                <div id="drOrderList"></div>
            </div>
            <div class="dr-mapcol">
                <div class="dr-colhead">
                    <span id="drMapTitle">Route</span>
                    <button id="drWholeDayBtn" class="dr-mini" style="display:none;" onclick="drShowWholeDay()">Show whole day</button>
                </div>
                <div id="drMap" class="dr-map"></div>
                <div class="dr-legend">
                    <i><span class="dr-lg-line"></span> route</i>
                    <i>⏸ stayed 10+ min</i>
                    <i><span class="dr-lg-ring"></span> customer pin</i>
                    <i>✕ marked-delivered spot</i>
                    <i><span class="dr-lg-gap"></span> GPS gap</i>
                </div>
            </div>
        </div>
        <div id="drDrawer" class="dr-drawer" style="display:none;"></div>
    </div>
</div>

<style>
/* ---------- Day Review (scoped .dr-*) ---------- */
.dr-bar{display:flex;align-items:center;gap:16px;flex-wrap:wrap;padding:12px 16px;background:#fff;border-bottom:1px solid #e5e7eb;}
.dr-datewrap{display:flex;align-items:center;gap:6px;}
.dr-datewrap input[type=date]{padding:6px 10px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;}
.dr-nav{border:1px solid #d1d5db;background:#fff;border-radius:6px;width:28px;height:30px;cursor:pointer;font-size:16px;line-height:1;color:#374151;}
.dr-nav:hover{background:#f3f4f6;}
.dr-today{border:1px solid #d1d5db;background:#fff;border-radius:6px;padding:6px 12px;cursor:pointer;font-size:12px;color:#374151;}
.dr-today:hover{background:#f3f4f6;}
.dr-tiles{display:flex;gap:10px;flex-wrap:wrap;}
.dr-tile{background:#f8fafc;border:1px solid #e5e7eb;border-radius:8px;padding:6px 14px;text-align:center;min-width:88px;}
.dr-tile b{display:block;font-size:17px;font-weight:700;color:#111827;line-height:1.2;}
.dr-tile span{font-size:10.5px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;}
.dr-tile.alert{background:#fffbeb;border-color:#f59e0b;}
.dr-tile.alert b{color:#b45309;}
.dr-tile.flight{background:#eff6ff;border-color:#3b82f6;}
.dr-tile.flight b{color:#1d4ed8;}

.dr-note{padding:8px 16px;background:#f1f5f9;color:#475569;font-size:12.5px;border-bottom:1px solid #e2e8f0;}
/* 🚚 van trips — teal to match the van surfaces everywhere else */
.dr-van{padding:10px 16px;border-bottom:1px solid #e2e8f0;background:#f8fafc;}
.dr-vancard{background:#fff;border:1px solid #ccfbf1;border-left:3px solid #0f766e;border-radius:9px;padding:10px 12px;margin-bottom:8px;}
.dr-vanhead{font-size:13.5px;font-weight:800;color:#0f766e;}
.dr-vanhead .dur{font-weight:600;color:#64748b;font-size:11.5px;}
.dr-vancounts{font-size:12px;color:#475569;margin-top:3px;}
.dr-vanline{font-size:11.5px;color:#64748b;margin-top:5px;line-height:1.7;}
.dr-vanrider{display:flex;gap:8px;align-items:baseline;margin-top:5px;padding-top:5px;border-top:1px dashed #f1f5f9;}
.dr-vanrider .nm{font-size:12.5px;font-weight:700;color:#0f172a;min-width:130px;}
.dr-vanrider .mt{font-size:11.5px;color:#64748b;}
.dr-vanflags{margin-top:7px;font-size:11.5px;color:#b45309;line-height:1.6;}

.dr-rail{display:flex;gap:10px;padding:14px 16px;overflow-x:auto;flex-wrap:wrap;}
.dr-card{border:1px solid #e5e7eb;border-radius:8px;padding:10px 14px;min-width:190px;background:#fff;cursor:pointer;transition:.15s;}
.dr-card:hover{border-color:#f59e0b;box-shadow:0 2px 6px rgba(0,0,0,.07);}
.dr-card .nm{font-weight:600;color:#111827;}
.dr-card .st{font-size:12px;color:#6b7280;margin-top:2px;}
.dr-flag{display:inline-block;font-size:11px;background:#fef3c7;color:#b45309;border-radius:999px;padding:1px 8px;font-weight:600;margin-top:4px;}
.dr-out{display:inline-block;font-size:11px;background:#dbeafe;color:#1d4ed8;border-radius:999px;padding:1px 8px;font-weight:600;margin-top:4px;margin-left:4px;}
.dr-empty{padding:24px;text-align:center;color:#9ca3af;font-size:13px;width:100%;}

.dr-detailhead{display:flex;align-items:center;gap:12px;padding:10px 16px;border-bottom:1px solid #e5e7eb;background:#fff;flex-wrap:wrap;}
.dr-detailhead h3{margin:0;font-size:15px;color:#111827;}
.dr-detailsummary{margin-left:auto;font-size:12.5px;color:#6b7280;}

.dr-split{display:grid;grid-template-columns:minmax(300px,400px) 1fr;}
@media (max-width:900px){.dr-split{grid-template-columns:1fr;}}
.dr-orders{border-right:1px solid #e5e7eb;display:flex;flex-direction:column;max-height:calc(100vh - 330px);overflow-y:auto;}
@media (max-width:900px){.dr-orders{border-right:0;border-bottom:1px solid #e5e7eb;max-height:none;}}
.dr-colhead{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:9px 14px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;font-weight:600;background:#f9fafb;border-bottom:1px solid #e5e7eb;position:sticky;top:0;z-index:2;}
.dr-onlyflag{display:flex;align-items:center;gap:5px;text-transform:none;letter-spacing:0;font-weight:500;cursor:pointer;font-size:11.5px;}
.dr-mini{border:1px solid #d1d5db;background:#fff;border-radius:6px;padding:3px 9px;cursor:pointer;font-size:11px;text-transform:none;letter-spacing:0;color:#374151;}
.dr-mini:hover{background:#f3f4f6;}

.dr-midrun{margin:10px 14px;padding:9px 12px;background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;font-size:12.5px;color:#78350f;line-height:1.45;}
.dr-midrun-why{font-size:11.5px;color:#92400e;margin-top:3px;}
.dr-wave{padding:7px 14px;background:#f1f5f9;border-top:1px solid #e2e8f0;display:flex;flex-direction:column;gap:1px;}
.dr-wave b{font-size:12.5px;color:#334155;}
.dr-wave span{font-size:11.5px;color:#64748b;}
.dr-orow{padding:10px 14px;border-bottom:1px solid #f1f5f9;cursor:pointer;}
.dr-orow:hover{background:#f9fafb;}
.dr-orow.sel{background:#fffbeb;border-left:3px solid #f59e0b;padding-left:11px;}
.dr-orow.inflight{background:#f8fbff;}
.dr-cust{font-weight:600;color:#111827;display:flex;align-items:center;gap:8px;font-size:13.5px;}
.dr-seq{display:inline-flex;width:20px;height:20px;border-radius:50%;background:#374151;color:#fff;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;}
.dr-seq.flight{background:#2563eb;}
.dr-times{font-size:12px;color:#6b7280;margin-top:3px;font-variant-numeric:tabular-nums;}
.dr-chips{margin-top:5px;display:flex;gap:5px;flex-wrap:wrap;}
.dr-chip{font-size:11px;border-radius:999px;padding:1.5px 8px;font-weight:600;white-space:nowrap;}
.dr-c-good{background:#dcfce7;color:#15803d;}
.dr-c-warn{background:#fef3c7;color:#b45309;}
.dr-c-bad{background:#fee2e2;color:#b91c1c;}
.dr-c-mut{background:#f3f4f6;color:#6b7280;}
.dr-c-info{background:#dbeafe;color:#1d4ed8;}

.dr-mapcol{display:flex;flex-direction:column;}
.dr-map{flex:1;min-height:380px;background:#eef1ec;}
.dr-legend{display:flex;gap:14px;flex-wrap:wrap;padding:8px 14px;font-size:11.5px;color:#6b7280;background:#f9fafb;border-top:1px solid #e5e7eb;}
.dr-legend i{font-style:normal;display:flex;align-items:center;gap:4px;}
.dr-lg-line{display:inline-block;width:16px;height:3px;background:#0e6b5b;border-radius:2px;}
.dr-lg-ring{display:inline-block;width:10px;height:10px;border:2px dashed #6b7280;border-radius:50%;}
.dr-lg-gap{display:inline-block;width:16px;height:0;border-top:2px dashed #dc2626;}

.dr-drawer{border-top:2px solid #f59e0b;background:#fff;padding:14px 16px;display:grid;grid-template-columns:1.5fr 1fr;gap:20px;}
@media (max-width:900px){.dr-drawer{grid-template-columns:1fr;}}
.dr-drawer h4{margin:0 0 2px;font-size:14.5px;color:#111827;}
.dr-drawer .dr-sub{color:#6b7280;font-size:12px;margin-bottom:10px;}
.dr-verdict{display:flex;gap:10px;padding:8px 0;border-top:1px solid #f1f5f9;align-items:flex-start;}
.dr-verdict:first-of-type{border-top:0;}
.dr-vico{flex-shrink:0;width:20px;text-align:center;font-size:15px;line-height:1.4;}
.dr-verdict p{margin:0;font-size:13px;color:#111827;line-height:1.45;}
.dr-verdict .dr-why{font-size:11.5px;color:#6b7280;margin-top:2px;}
.dr-stopwrap h5{margin:0 0 7px;font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#6b7280;}
.dr-stop{display:flex;gap:9px;padding:7px 10px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:7px;margin-bottom:6px;font-size:12.5px;align-items:center;}
.dr-pause{background:#fef3c7;color:#b45309;border-radius:5px;padding:2px 7px;font-weight:700;font-size:11.5px;flex-shrink:0;}
.dr-drawclose{float:right;border:none;background:none;font-size:18px;color:#9ca3af;cursor:pointer;line-height:1;}
</style>

<script>
// =============================================
// DAY REVIEW — state
// =============================================
let drDate = null;
let drDayData = null;
let drRider = null;          // loaded rider payload
let drSelectedIdx = null;    // index into drRider.orders
let drMap = null;
let drLayer = null;          // everything we draw lives here
let drCanForensics = false;
let drInitDone = false;

const DR_BASE = '/orders/riders-map/day-review';

function drInit() {
    if (!drInitDone) {
        const today = new Date().toISOString().split('T')[0];
        const inp = document.getElementById('drDateInput');
        inp.value = today;
        inp.max = today;
        drDate = today;
        drInitDone = true;
    }
    drLoadDay(drDate);
}

function drSetToday() {
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('drDateInput').value = today;
    drLoadDay(today);
}

function drShiftDay(delta) {
    const cur = document.getElementById('drDateInput').value || new Date().toISOString().split('T')[0];
    const d = new Date(cur + 'T12:00:00');
    d.setDate(d.getDate() + delta);
    const today = new Date().toISOString().split('T')[0];
    let next = d.toISOString().split('T')[0];
    if (next > today) next = today;
    document.getElementById('drDateInput').value = next;
    drLoadDay(next);
}

// =============================================
// LEVEL 1 — the day
// =============================================
function drLoadDay(date) {
    drDate = date;
    drBackToRiders();
    document.getElementById('drRiderRail').innerHTML = '<div class="dr-empty">Loading…</div>';
    document.getElementById('drTiles').innerHTML = '';
    // Cleared up front, not just re-rendered on success — a too-old date or a
    // failed fetch must not leave the PREVIOUS date's van card standing.
    drRenderVanTrips([]);

    fetch(DR_BASE + '?date=' + encodeURIComponent(date))
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.message || 'Failed');
            drDayData = res;
            drCanForensics = !!res.can_forensics;

            if (res.too_old) {
                document.getElementById('drRiderRail').innerHTML =
                    '<div class="dr-empty">That date is further back than Day Review keeps (' + res.max_back_days + ' days).</div>';
                return;
            }
            drRenderTiles(res);
            drRenderTrailNote(res);
            drRenderVanTrips(res.van_trips || []);
            drRenderRail(res.riders || []);
        })
        .catch(err => {
            console.error('Day Review:', err);
            document.getElementById('drRiderRail').innerHTML =
                '<div class="dr-empty">Could not load this day. Please try again.</div>';
        });
}

function drRenderTiles(res) {
    const t = res.totals || {};
    let html = '';
    html += drTile(t.delivered ?? 0, 'Delivered', '');
    html += drTile(t.on_time_pct === null || t.on_time_pct === undefined ? '—' : t.on_time_pct + '%', 'On time', '');
    html += drTile(t.avg_late_min === null || t.avg_late_min === undefined ? '—' : (t.avg_late_min > 0 ? '+' + t.avg_late_min + 'm' : 'on time'), 'Avg delay', '');
    if (res.is_today && (t.in_flight || 0) > 0) html += drTile(t.in_flight, 'Out now', 'flight');
    html += drTile(t.needs_look ?? 0, 'Needs a look', (t.needs_look || 0) > 0 ? 'alert' : '');
    document.getElementById('drTiles').innerHTML = html;
}

function drTile(value, label, cls) {
    return '<div class="dr-tile ' + (cls || '') + '"><b>' + value + '</b><span>' + label + '</span></div>';
}

/* 🚚 THE VAN DAY — one card per trip.
   Day Review's rider rows already show each person's deliveries; what they can
   never show is the choreography BETWEEN them: when the van loaded and left,
   how long it stood at the meet-up point, who kept it waiting, and how long
   each rider then took to actually set off. Read-only — every figure here is
   already recorded by the scans and the dispatch engine. */
function drRenderVanTrips(trips) {
    const el = document.getElementById('drVanTrips');
    if (!el) return;
    if (!trips.length) { el.style.display = 'none'; el.innerHTML = ''; return; }

    el.style.display = 'block';
    el.innerHTML = trips.map(t => {
        const tot = t.totals || {};

        // The trip's spine: loaded → left → each stop → closed.
        const line = [];
        if (t.loaded_at)   line.push('loaded ' + drHm(t.loaded_at));
        if (t.departed_at) line.push('left ' + drHm(t.departed_at));
        (t.stops || []).forEach(s => {
            let bit = drEsc(s.label);
            if (s.reached_at) {
                bit += ' ' + drHm(s.reached_at);
                if (s.waiting_minutes !== null && s.waiting_minutes !== undefined) {
                    bit += ' (stood ' + s.waiting_minutes + 'm)';
                }
            } else {
                bit += ' — never reached';
            }
            line.push('📍 ' + bit);
        });
        line.push(t.ended_at ? 'closed ' + drHm(t.ended_at) : 'still open');

        const counts = [];
        if (tot.own)     counts.push(tot.own + ' own');
        if (tot.carried) counts.push(tot.carried + ' carried for ' + tot.riders
                                   + ' rider' + (tot.riders === 1 ? '' : 's'));
        if (tot.uncollected) counts.push(tot.uncollected + ' never collected');

        // Per rider: how long the van waited, and the gap before he set off.
        const riders = (t.riders || []).map(r => {
            const bits = [r.collected + ' of ' + r.orders + ' collected'];
            if (r.wait_minutes !== null && r.wait_minutes !== undefined) {
                bits.push('van waited ' + r.wait_minutes + 'm');
            }
            if (r.dispatch_lag_minutes !== null && r.dispatch_lag_minutes !== undefined) {
                bits.push('set off ' + r.dispatch_lag_minutes + 'm later');
            }
            return '<div class="dr-vanrider">'
                 + '<span class="nm">' + (r.complete ? '✅' : '⏳') + ' ' + drEsc(r.name) + '</span>'
                 + '<span class="mt">' + drEsc(bits.join(' · ')) + '</span></div>';
        }).join('');

        const flags = (t.flags || []).length
            ? '<div class="dr-vanflags">' + t.flags.map(f => '⚠️ ' + drEsc(f)).join('<br>') + '</div>'
            : '';

        return '<div class="dr-vancard">'
             + '<div class="dr-vanhead">🚚 ' + drEsc(t.driver_name) + '’s van'
             + (t.duration_minutes ? ' <span class="dur">' + t.duration_minutes + ' min out</span>' : '')
             + '</div>'
             + (counts.length ? '<div class="dr-vancounts">' + drEsc(counts.join(' · ')) + '</div>' : '')
             + '<div class="dr-vanline">' + line.join(' → ') + '</div>'
             + riders + flags
             + '</div>';
    }).join('');
}

function drRenderTrailNote(res) {
    const el = document.getElementById('drTrailNote');
    if (!drCanForensics) {
        el.style.display = 'block';
        el.textContent = 'Delivery times and ETAs only — the route and stop details need the rider-reports permission.';
        return;
    }
    if (res.trail_expected === false) {
        el.style.display = 'block';
        el.textContent = 'This day is ' + res.days_ago + ' days back, so the GPS trail (kept ' +
            res.retention_days + ' days) has expired. Delivery times and ETAs still work; the route and stops do not.';
        return;
    }
    el.style.display = 'none';
}

function drRenderRail(riders) {
    const rail = document.getElementById('drRiderRail');
    if (!riders.length) {
        rail.innerHTML = '<div class="dr-empty">No deliveries recorded on this day.</div>';
        return;
    }
    rail.innerHTML = riders.map(r => {
        let st = r.delivered + ' delivered';
        if (r.on_time || r.late) st += ' · ' + r.on_time + ' on time';
        if (r.km !== null && r.km !== undefined) st += ' · ' + r.km + ' km';
        let badges = '';
        if (r.needs_look > 0) badges += '<span class="dr-flag">⚠ ' + r.needs_look + ' need' + (r.needs_look === 1 ? 's' : '') + ' a look</span>';
        if (r.in_flight > 0) badges += '<span class="dr-out">' + r.in_flight + ' out now' + (r.overdue > 0 ? ' · ' + r.overdue + ' overdue' : '') + '</span>';
        return '<div class="dr-card" onclick="drSelectRider(' + r.user_id + ')">' +
               '<div class="nm">' + drEsc(r.name) + '</div>' +
               '<div class="st">' + st + '</div>' + badges + '</div>';
    }).join('');
}

// =============================================
// LEVEL 2 — one rider
// =============================================
function drSelectRider(uid) {
    document.getElementById('drRiderRail').style.display = 'none';
    document.getElementById('drDetail').style.display = 'block';
    document.getElementById('drRiderName').textContent = 'Loading…';
    document.getElementById('drOrderList').innerHTML = '<div class="dr-empty">Loading…</div>';
    drCloseDrawer();

    fetch(DR_BASE + '/rider?date=' + encodeURIComponent(drDate) + '&rider_id=' + uid)
        .then(r => r.json())
        .then(res => {
            if (!res.success) throw new Error(res.message || 'Failed');
            if (!res.rider) {
                document.getElementById('drRiderName').textContent = 'Nothing recorded';
                document.getElementById('drOrderList').innerHTML =
                    '<div class="dr-empty">' + (res.message || 'No deliveries for this rider on this day.') + '</div>';
                return;
            }
            drRider = res.rider;
            drSelectedIdx = null;
            document.getElementById('drRiderName').textContent = drRider.name;
            drRenderRiderSummary();
            drRenderOrders();
            drEnsureMap();
            drShowWholeDay();
        })
        .catch(err => {
            console.error('Day Review rider:', err);
            document.getElementById('drOrderList').innerHTML =
                '<div class="dr-empty">Could not load this rider. Please try again.</div>';
        });
}

function drBackToRiders() {
    document.getElementById('drRiderRail').style.display = 'flex';
    document.getElementById('drDetail').style.display = 'none';
    drRider = null;
    drSelectedIdx = null;
    drCloseDrawer();
}

function drRenderRiderSummary() {
    const o = drRider.orders || [];
    const flagged = o.filter(x => x.needs_look).length;
    let s = o.length + ' delivered';
    if ((drRider.in_flight || []).length) s += ' · ' + drRider.in_flight.length + ' still out';
    if (flagged) s += ' · ⚠ ' + flagged + ' need' + (flagged === 1 ? 's' : '') + ' a look';
    if (drCanForensics && drRider.has_trail === false) s += ' · no GPS trail';
    document.getElementById('drRiderSummary').textContent = s;
}

/**
 * Re-dispatches the rider made HIMSELF after he had already started dropping —
 * the ones that move his own ETAs. Empty for every day before the dispatch log
 * started recording, so the block simply doesn't render rather than showing a
 * misleading "none".
 */
function drRenderMidRun() {
    const list = drRider?.mid_run_changes || [];
    if (!drCanForensics || !list.length) return '';
    return list.map(m => {
        const bits = [];
        if (m.order_count) bits.push(m.order_count + ' order' + (m.order_count === 1 ? '' : 's') + ' re-timed');
        if (m.delivered_before) bits.push('after ' + m.delivered_before + ' already delivered');
        if (m.avg_shift_min !== null && m.avg_shift_min !== undefined) {
            bits.push('promises moved ' + (m.avg_shift_min >= 0 ? '+' : '') + m.avg_shift_min + ' min on average');
        }
        return '<div class="dr-midrun">⚠ <b>Re-dispatched himself at ' + drHm(m.at) + '</b> — ' +
            drEsc(bits.join(' · ')) +
            '<div class="dr-midrun-why">His remaining ETAs were rebuilt mid-run, so later stops are judged against the new promise.</div></div>';
    }).join('');
}

function drRenderOrders() {
    const list = document.getElementById('drOrderList');
    const onlyFlagged = document.getElementById('drOnlyFlagged').checked;
    let html = drRenderMidRun();

    (drRider.in_flight || []).forEach(f => {
        if (onlyFlagged && !f.overdue) return;
        html += '<div class="dr-orow inflight">' +
            '<div class="dr-cust"><span class="dr-seq flight">→</span>' + drEsc(f.customer_name || 'Customer') + '</div>' +
            '<div class="dr-times">' + drOutLine(f) + '</div>' +
            '<div class="dr-chips">' + drFlightChip(f) + '</div></div>';
    });

    // Grouped by DISPATCH WAVE — one press of dispatch per group. This is what the
    // old Dispatch Tracker grouped by; without it the per-wave sequence numbering
    // (#1,#2 then #1,#2,#3…) looks like a bug rather than the restart it is.
    const waves = drRider.waves || [];
    const renderOrder = (o, i) => {
        let extra = '';
        // planned_rank = the plan as it stood AT DISPATCH (ETA order, never
        // renumbered). delivery_priority is renumbered mid-route and would miss
        // exactly the re-ordering this chip exists to surface.
        if (o.planned_rank !== null && o.planned_rank !== undefined
            && o.actual_seq !== null && o.actual_seq !== undefined
            && Number(o.planned_rank) !== Number(o.actual_seq)) {
            extra += '<span class="dr-chip dr-c-warn" title="The store dispatched this as stop #' + o.planned_rank + ', but he delivered it ' + o.actual_seq + drOrdinal(o.actual_seq) + '">↕ planned #' + o.planned_rank + '</span>';
        }
        if (!o.was_dispatched) extra += '<span class="dr-chip dr-c-bad">not dispatched</span>';
        return '<div class="dr-orow' + (drSelectedIdx === i ? ' sel' : '') + '" onclick="drSelectOrder(' + i + ')">' +
            '<div class="dr-cust"><span class="dr-seq">' + (o.actual_seq || (i + 1)) + '</span>' +
            drEsc(o.customer_name || 'Customer') + '</div>' +
            '<div class="dr-times">' + drTimeLine(o) + '</div>' +
            '<div class="dr-chips">' + drTimeChip(o) + drPinChip(o) + extra + '</div></div>';
    };

    const all = drRider.orders || [];
    if (waves.length) {
        waves.forEach(w => {
            const idxs = [];
            all.forEach((o, i) => {
                if ((o.wave ?? null) !== (w.dispatched_at ?? null)) return;
                if (onlyFlagged && !o.needs_look) return;
                idxs.push(i);
            });
            if (!idxs.length) return;
            html += '<div class="dr-wave"><b>' +
                (w.dispatched_at ? '🚀 Dispatched ' + drHm(w.dispatched_at) + drWaveWho(w) : '📋 Never dispatched') +
                '</b><span>' + drWaveStory(w) + w.orders + ' order' + (w.orders === 1 ? '' : 's') +
                (w.late ? ' · ' + w.late + ' late' : '') +
                (w.seq_checked
                    ? ' · sequence followed ' + w.seq_followed + '/' + w.seq_checked +
                      (w.out_of_order ? ' <b style="color:#b45309;">(' + w.out_of_order + ' out of order)</b>' : '')
                    : '') +
                '</span></div>';
            idxs.forEach(i => { html += renderOrder(all[i], i); });
        });
    } else {
        all.forEach((o, i) => { if (!onlyFlagged || o.needs_look) html += renderOrder(o, i); });
    }

    // `html` may already hold the mid-run banner, so test the ORDER rows for
    // emptiness — otherwise a filter that hides everything still looks populated.
    const hasRows = html.indexOf('dr-orow') !== -1;
    list.innerHTML = hasRows ? html : (html + '<div class="dr-empty">Nothing matches that filter.</div>');
}

// Who pressed this wave (Sep-2026): nothing when the rider did, " by Taimur" when
// the store did.
function drWaveWho(w) {
    if (w.by_self !== false || !w.by_name) return '';
    return ' by ' + drEsc(w.by_name);
}
// What a store press WAS — a re-time/re-route of a route the rider had already
// dispatched, or a first dispatch he never pressed. Ends with ' · ' when non-empty.
function drWaveStory(w) {
    if (w.by_self !== false) return '';
    let s = '';
    if (w.kind === 'retime') {
        s = 're-time of ' + (w.prior_by_self ? "the rider's " : 'the ') + drHm(w.prior_at) + ' dispatch (same route' +
            (w.shift_min ? ', ' + (w.shift_min > 0 ? '+' : '') + w.shift_min + ' min' : '') + ')';
    } else if (w.kind === 'reroute') {
        s = 'changed the ' + drHm(w.prior_at) + ' route';
    } else if (w.kind === 'next') {
        s = 'next wave, sent by the store';
    } else if (w.kind === 'first') {
        s = "rider didn't press";
    }
    if (s && w.after_cancel) s += ', cleared & re-dispatched';
    return s ? drEsc(s) + ' · ' : '';
}

// The first time is the OUT-FOR-DELIVERY status stamp, not the dispatch press
// (that is the wave header) — labelled as such so the two never look contradictory.
function drTimeLine(o) {
    const d = o.dispatched_at ? drHm(o.dispatched_at) : null;
    const e = o.eta_at ? drHm(o.eta_at) : null;
    let s = '';
    // No out-for-delivery stamp is not the same as "not dispatched" — trust was_dispatched.
    s += d ? 'out for delivery ' + d : (o.was_dispatched ? 'dispatched' : 'dispatch not pressed');
    s += ' → ' + (e ? 'promised ' + e : 'no promise');
    if (o.eta_pre_store && o.store_kind === 'retime') {
        s += " (rider's own " + drHm(o.eta_pre_store) + ')';
    }
    s += ' → delivered ' + drHm(o.delivered_at);
    return s;
}

function drOutLine(f) {
    // An order can sit in out-for-delivery for days. Showing only a clock time
    // would read as "dispatched this morning" — say the date when it isn't today.
    const d = f.dispatched_at ? drWhen(f.dispatched_at) : null;
    const e = f.eta_at ? drWhen(f.eta_at) : null;
    let s = d ? 'out for delivery ' + d : (f.was_dispatched ? 'dispatched' : 'dispatch not pressed');
    s += ' → ' + (e ? 'promised ' + e : 'no promise') + ' → still out';
    return s;
}

function drFlightChip(f) {
    if (f.eta_at === null || f.eta_at === undefined) {
        return f.was_dispatched
            ? '<span class="dr-chip dr-c-mut">⚫ no ETA given</span>'
            : '<span class="dr-chip dr-c-mut">⚫ no ETA — dispatch not pressed</span>';
    }
    if (f.overdue) return '<span class="dr-chip dr-c-bad">🔴 ' + Math.abs(f.mins_left) + ' min overdue</span>';
    return '<span class="dr-chip dr-c-info">🔵 ' + f.mins_left + ' min left</span>';
}

/** Time alone when it happened today, otherwise "23 Jul, 8:07 PM". */
function drWhen(dt) {
    if (!dt) return '--:--';
    const day = String(dt).substring(0, 10);
    const today = new Date().toISOString().split('T')[0];
    if (day === today || day.length !== 10) return drHm(dt);
    const d = new Date(day + 'T12:00:00');
    return d.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }) + ', ' + drHm(dt);
}

function drTimeChip(o) {
    if (o.late_minutes === null || o.late_minutes === undefined) {
        return '<span class="dr-chip dr-c-mut">⚫ no ETA' + (o.was_dispatched ? '' : ' — dispatch not pressed') + '</span>';
    }
    const m = o.late_minutes;
    if (m <= 0) return '<span class="dr-chip dr-c-good">🟢 ' + (m === 0 ? 'on time' : Math.abs(m) + ' min early') + '</span>';
    if (m <= 10) return '<span class="dr-chip dr-c-good">🟢 on time</span>';
    if (m <= 15) return '<span class="dr-chip dr-c-warn">🟠 ' + m + ' min late</span>';
    return '<span class="dr-chip dr-c-bad">🔴 ' + m + ' min late</span>';
}

function drPinChip(o) {
    if (!o.has_verified) return '<span class="dr-chip dr-c-mut">no saved pin</span>';
    if (o.at_verified === 1) return '<span class="dr-chip dr-c-good">📍 at pin</span>';
    if (o.at_verified === 0) return '<span class="dr-chip dr-c-bad">⚠ ' + drM(o.pin_distance_m) + ' from pin</span>';
    return '<span class="dr-chip dr-c-mut">❓ can\'t verify</span>';
}

// =============================================
// LEVEL 3 — the drawer (why did this happen?)
// =============================================
function drSelectOrder(i) {
    drSelectedIdx = i;
    drRenderOrders();
    drRenderDrawer();
    drZoomToOrder(i);
}

function drCloseDrawer() {
    drSelectedIdx = null;
    const d = document.getElementById('drDrawer');
    if (d) d.style.display = 'none';
}

function drRenderDrawer() {
    const o = drRider.orders[drSelectedIdx];
    if (!o) return;
    const el = document.getElementById('drDrawer');

    let v = '';

    // 1 — timing
    const promised = o.eta_at ? drHm(o.eta_at) : null;
    const disp = o.dispatched_at ? drHm(o.dispatched_at) : null;
    let timing;
    if (promised === null) {
        timing = 'Delivered <b>' + drHm(o.delivered_at) + '</b> — no ETA was ever given' +
                 (o.was_dispatched ? '' : ', because dispatch was never pressed') + '.';
    } else if (o.late_minutes > 0) {
        timing = 'Delivered <b>' + drHm(o.delivered_at) + '</b> — ' + o.late_minutes +
                 ' min after the ' + promised + ' promise' + (disp ? ' (out for delivery ' + disp + ')' : '') + '.';
    } else {
        timing = 'Delivered <b>' + drHm(o.delivered_at) + '</b> — ' +
                 (o.late_minutes === 0 ? 'exactly on the' : Math.abs(o.late_minutes) + ' min before the') +
                 ' ' + promised + ' promise' + (disp ? ' (out for delivery ' + disp + ')' : '') + '.';
    }
    let timingWhy = o.eta_retimed ? 'The promise was re-timed during the run' +
        (o.eta_retimed_by_rider ? ' by the rider himself — judged against the original.' : ' by the store, so the yardstick moved.') : '';
    // The store re-dispatched a route the rider had dispatched himself: say who,
    // and how it went against the rider's OWN time (Sep-2026).
    const storeWho = o.dispatched_by_name ? drEsc(o.dispatched_by_name) : 'The store';
    if (o.store_kind === 'retime' && o.eta_pre_store && o.own_late_minutes !== null) {
        const om = o.own_late_minutes;
        timingWhy = storeWho + ' re-timed this route (same stops, same order) after the rider had dispatched it. ' +
            "Against the rider's own " + drHm(o.eta_pre_store) + ' time it was ' +
            (om > 0 ? om + ' min late' : (om === 0 ? 'on time' : Math.abs(om) + ' min early')) + '.';
    } else if (o.store_kind === 'reroute') {
        timingWhy = storeWho + ' changed this route after the rider had dispatched it, so it is judged against the new plan.';
    }
    v += drVerdict('🕒', timing, timingWhy);

    if (drCanForensics) {
        // 2 — did he reach the customer's pin?
        const c = o.pin_cross || {};
        if (c.state === 'crossed') {
            // Timing is the story: at the pin when he pressed, or only later?
            const dm = c.delta_min;
            let when = '', why = '';
            if (dm === null || dm === undefined || Math.abs(dm) <= 10) {
                when = ' at ' + drHm(c.at) + ', around the time he pressed Delivered';
                why = o.at_verified === 0
                    ? 'So he was at the right place — it is the delivered marker that is off.' : '';
            } else if (dm > 10) {
                when = ' at ' + drHm(c.at) + ' — but that was <b>' + dm +
                       ' min AFTER</b> he pressed Delivered';
                why = 'He marked it delivered ' + drM(o.pin_distance_m) +
                      ' away and only reached the address afterwards.';
            } else {
                when = ' at ' + drHm(c.at) + ' — <b>' + Math.abs(dm) +
                       ' min BEFORE</b> he pressed Delivered';
                why = 'He was at the address earlier, then pressed Delivered ' +
                      drM(o.pin_distance_m) + ' away.';
            }
            v += drVerdict('📍', drEsc(drRider.name) + ' <b>did reach the customer\'s saved location</b> (within ' +
                drM(c.closest_m) + ')' + when + '.', why);
        } else if (c.state === 'not_crossed') {
            v += drVerdict('📍', '<b>Never came near the customer\'s saved location</b> — closest was ' +
                drM(c.closest_m) + ' at ' + drHm(c.at) + '.', 'Worth asking about.');
        } else if (c.state === 'no_gps') {
            v += drVerdict('📍', 'No GPS trail for this day, so we <b>cannot tell</b> whether he reached the pin.', '');
        } else {
            v += drVerdict('📍', 'This customer has <b>no saved location</b>, so there is nothing to compare against.',
                'Setting a verified pin for this address makes future deliveries checkable.');
        }

        // 3 — glitch check (only interesting when the marker is far from the pin)
        if (o.away_verdict === 'likely_glitch') {
            v += drVerdict('⚡', 'The marked-delivered spot is <b>' + drM(o.press_check.trail_m) +
                ' from where he actually was</b> at that minute — most likely a GPS glitch when he pressed Delivered.',
                'The trail says he was at the customer; only the marker is wrong.');
        } else if (o.away_verdict === 'really_away') {
            v += drVerdict('⚡', 'The trail <b>agrees</b> with the delivered marker — this delivery genuinely happened ' +
                drM(o.pin_distance_m) + ' from the customer\'s saved location.', 'Not a GPS glitch.');
        } else if (o.away_verdict === 'never_reached') {
            v += drVerdict('⚡', 'The delivered marker is ' + drM(o.pin_distance_m) +
                ' from the pin and the trail never got close either.', '');
        } else if (o.away_verdict === 'unverifiable') {
            v += drVerdict('⚡', 'Delivered marker is ' + drM(o.pin_distance_m) +
                ' from the pin, but there is <b>no usable GPS</b> around that moment to check it.', '');
        }

        if (o.door_wait_min) {
            v += drVerdict('🚪', 'Waited about <b>' + o.door_wait_min + ' min</b> at the address.', '');
        }
    }

    // stops around the delivery
    let stops = '';
    if (drCanForensics) {
        const near = drStopsAround(o, 20 * 60);
        stops = '<h5>Where he spent time (around this delivery)</h5>';
        if (near.length) {
            stops += near.map(s =>
                '<div class="dr-stop"><span class="dr-pause">⏸ ' + s.min + ' min</span><div>' +
                drEsc(s.label || 'unknown spot') + ' · <b>' + drHm(s.from) + ' – ' + drHm(s.to) + '</b></div></div>'
            ).join('');
        } else {
            stops += '<div class="dr-stop"><div>No stop of 10 minutes or more near this delivery.</div></div>';
        }
    }

    el.innerHTML =
        '<div><button class="dr-drawclose" onclick="drCloseDrawer()" title="Close">×</button>' +
        '<h4>' + drEsc(o.customer_name || 'Customer') + '</h4>' +
        '<div class="dr-sub">' + drEsc(o.order_number || '') +
        (o.amount ? ' · ' + (o.payment_type === 'cash' ? 'COD ' : 'Online ') + 'Rs ' + drNum(o.amount) : '') + '</div>' +
        v + '</div>' +
        '<div class="dr-stopwrap">' + stops + '</div>';

    el.style.display = 'grid';
}

function drVerdict(icon, text, why) {
    return '<div class="dr-verdict"><span class="dr-vico">' + icon + '</span><div><p>' + text + '</p>' +
        (why ? '<p class="dr-why">' + why + '</p>' : '') + '</div></div>';
}

function drStopsAround(o, windowSecs) {
    const stops = drRider.stops || [];
    const delMs = new Date(o.delivered_at.replace(' ', 'T')).getTime();
    const day = o.delivered_at.substring(0, 10);
    return stops.filter(s => {
        if (!s.from) return false;
        const t = new Date(day + 'T' + (s.from.length === 5 ? s.from + ':00' : s.from)).getTime();
        return Math.abs(t - delMs) <= windowSecs * 1000;
    });
}

// =============================================
// MAP — deliberately sparse (no raw GPS dots)
// =============================================
function drEnsureMap() {
    if (drMap) { setTimeout(() => drMap.invalidateSize(), 60); return; }
    drMap = L.map('drMap').setView([33.6844, 73.0479], 12);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors'
    }).addTo(drMap);
    drLayer = L.layerGroup().addTo(drMap);
    setTimeout(() => drMap.invalidateSize(), 60);
}

function drClearMap() {
    if (drLayer) drLayer.clearLayers();
}

function drShowWholeDay() {
    if (!drRider) return;
    drEnsureMap();
    drClearMap();
    document.getElementById('drMapTitle').textContent = 'Route — whole day';
    document.getElementById('drWholeDayBtn').style.display = 'none';

    const bounds = [];

    if (drCanForensics && (drRider.route || []).length) {
        const pts = drRider.route.map(p => [p.lat, p.lng]);
        L.polyline(pts, { color: '#0e6b5b', weight: 3.5, opacity: .85 }).addTo(drLayer);
        pts.forEach(p => bounds.push(p));

        (drRider.stops || []).forEach(s => {
            if (s.lat === null || s.lat === undefined) return;
            L.marker([s.lat, s.lng], { icon: drStopIcon(s.min) }).addTo(drLayer)
                .bindPopup('<b>Stopped ' + s.min + ' min</b><br>' + drEsc(s.label || 'unknown spot') +
                           '<br>' + drHm(s.from) + ' – ' + drHm(s.to));
            bounds.push([s.lat, s.lng]);
        });
    }

    (drRider.orders || []).forEach((o, i) => {
        drDrawDelivery(o, i, bounds);
    });

    if (bounds.length) drMap.fitBounds(bounds, { padding: [30, 30] });
}

function drDrawDelivery(o, i, bounds) {
    if (o.verified_lat !== null && o.verified_lat !== undefined) {
        L.circleMarker([o.verified_lat, o.verified_lng], {
            radius: 9, color: o.at_verified === 0 ? '#dc2626' : '#6b7280',
            weight: 2, dashArray: '3 3', fill: false
        }).addTo(drLayer).bindPopup('Customer pin — ' + drEsc(o.customer_name || ''));
        bounds.push([o.verified_lat, o.verified_lng]);
    }
    if (o.pin_lat !== null && o.pin_lat !== undefined) {
        L.marker([o.pin_lat, o.pin_lng], { icon: drSeqIcon(o) }).addTo(drLayer)
            .bindPopup('<b>#' + (o.actual_seq || (i + 1)) + ' ' + drEsc(o.customer_name || '') + '</b><br>' +
                       'Delivered ' + drHm(o.delivered_at) +
                       (o.late_minutes > 0 ? '<br>' + o.late_minutes + ' min late' : ''))
            .on('click', () => drSelectOrder(i));
        bounds.push([o.pin_lat, o.pin_lng]);
    }
}

function drZoomToOrder(i) {
    const o = drRider.orders[i];
    if (!o) return;
    drEnsureMap();
    drClearMap();
    document.getElementById('drMapTitle').textContent = 'Around delivery #' + (o.actual_seq || (i + 1));
    document.getElementById('drWholeDayBtn').style.display = 'inline-block';

    const bounds = [];

    if (drCanForensics && (o.slice || []).length) {
        const pts = o.slice.map(p => [p.lat, p.lng]);
        L.polyline(pts, { color: '#0e6b5b', weight: 4, opacity: .9 }).addTo(drLayer);
        pts.forEach(p => bounds.push(p));
    }
    drDrawDelivery(o, i, bounds);

    if (drCanForensics) {
        drStopsAround(o, 20 * 60).forEach(s => {
            if (s.lat === null || s.lat === undefined) return;
            L.marker([s.lat, s.lng], { icon: drStopIcon(s.min) }).addTo(drLayer)
                .bindPopup('<b>Stopped ' + s.min + ' min</b><br>' + drEsc(s.label || 'unknown spot'));
            bounds.push([s.lat, s.lng]);
        });
    }

    if (bounds.length) drMap.fitBounds(bounds, { padding: [40, 40], maxZoom: 17 });
}

function drSeqIcon(o) {
    let bg = '#16a34a';
    if (o.late_minutes !== null && o.late_minutes !== undefined) {
        if (o.late_minutes > 15) bg = '#dc2626';
        else if (o.late_minutes > 10) bg = '#d97706';
    } else { bg = '#6b7280'; }
    const away = o.at_verified === 0;
    const label = away ? '✕' : (o.actual_seq || '•');
    return L.divIcon({
        className: '',
        html: '<div style="background:' + bg + ';color:#fff;width:24px;height:24px;border-radius:50%;' +
              'display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;' +
              'border:2px solid #fff;box-shadow:0 1px 4px rgba(0,0,0,.4);">' + label + '</div>',
        iconSize: [24, 24], iconAnchor: [12, 12]
    });
}

function drStopIcon(min) {
    return L.divIcon({
        className: '',
        html: '<div style="background:#fef3c7;color:#b45309;border:1px solid #f59e0b;border-radius:9px;' +
              'padding:1px 6px;font-size:11px;font-weight:700;white-space:nowrap;">⏸ ' + min + 'm</div>',
        iconSize: [46, 18], iconAnchor: [23, 9]
    });
}

// =============================================
// small helpers
// =============================================
function drHm(dt) {
    if (!dt) return '--:--';
    const s = String(dt);
    const t = s.length > 11 ? s.substring(11, 16) : s.substring(0, 5);
    const [h, m] = t.split(':').map(Number);
    if (isNaN(h)) return t;
    const ap = h >= 12 ? 'PM' : 'AM';
    const hh = h % 12 === 0 ? 12 : h % 12;
    return hh + ':' + String(m).padStart(2, '0') + ' ' + ap;
}
/** 1 → "st", 2 → "nd", 3 → "rd", else "th" — for "delivered it 2nd". */
function drOrdinal(n) {
    const v = Number(n) % 100;
    if (v >= 11 && v <= 13) return 'th';
    return {1: 'st', 2: 'nd', 3: 'rd'}[v % 10] || 'th';
}
function drM(m) {
    if (m === null || m === undefined) return '?';
    return m >= 1000 ? (m / 1000).toFixed(1) + ' km' : Math.round(m) + ' m';
}
function drNum(n) {
    return Number(n || 0).toLocaleString('en-PK', { maximumFractionDigits: 0 });
}
function drEsc(s) {
    return String(s === null || s === undefined ? '' : s)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}
</script>
