{{--
    Expense Types — picker renderer + "✎ Manage types" side drawer (Oct-2026).

    Include ONCE per page, AFTER the Expense Type <select>, then register the select:
        @include('fin.expense-types._manager')
        <script>NFExpenseTypes.register('my_select_id', {
            scope: () => ({bu: 1, rcc: 'expense'}),   // business unit + request type code
            label: () => 'Expense Reimbursement',     // shown in the drawer header
        });</script>
    and put a trigger next to the label (render it only when the user may manage):
        <button type="button" class="nfet-trigger" onclick="NFExpenseTypes.open('my_select_id')">✎ Manage types</button>

    The list comes from ExpenseTypeService (the one engine the phone uses too): active types
    only, in Taimur's order, grouped under headings when groups exist. Every change in the
    drawer re-renders every registered select on the page, keeping the current choice.
--}}
@once
@php
    $nfetBoot = [
        'types'     => \App\Services\FIN\ExpenseTypeService::webPayload(),
        'canManage' => \App\Services\FIN\ExpenseTypeService::canManage(),
        'ready'     => \App\Services\FIN\ExpenseTypeService::available(),
        'urls'      => [
            'all'      => route('fin.expense-category.types.all'),
            'list'     => route('fin.expense-category.types'),
            'store'    => route('fin.expense-category.store'),
            'reorder'  => route('fin.expense-category.types.reorder'),
            'mergeOld' => route('fin.expense-category.types.merge-orphan'),
            'undo'     => route('fin.expense-category.types.undo'),
            'preview'  => route('fin.expense-category.types.rename-preview', ['id' => 0]),
            'rename'   => route('fin.expense-category.types.rename', ['id' => 0]),
            'active'   => route('fin.expense-category.types.active', ['id' => 0]),
            'group'    => route('fin.expense-category.types.group', ['id' => 0]),
        ],
    ];
@endphp
<style>
    .nfet-trigger{display:inline-flex;align-items:center;gap:4px;margin-left:auto;padding:3px 10px;border:1px solid #A7F3D0;border-radius:999px;background:#ECFDF5;color:#047857;font-size:12px;font-weight:600;line-height:18px;cursor:pointer;white-space:nowrap}
    .nfet-trigger:hover{background:#D1FAE5;border-color:#6EE7B7}
    .nfet-trigger:focus-visible{outline:2px solid #059669;outline-offset:2px}
    .nfet-root{position:fixed;inset:0;z-index:100050;display:none}
    .nfet-root.open{display:block}
    .nfet-backdrop{position:absolute;inset:0;background:rgba(17,24,39,.35)}
    .nfet-drawer{position:absolute;top:0;right:0;bottom:0;width:min(460px,100vw);background:#fff;box-shadow:-12px 0 32px rgba(0,0,0,.18);display:flex;flex-direction:column;transform:translateX(100%);transition:transform .2s ease;font-size:14px;color:#111827}
    .nfet-root.open .nfet-drawer{transform:translateX(0)}
    .nfet-head{display:flex;align-items:flex-start;gap:12px;padding:16px 18px 12px;border-bottom:1px solid #E5E7EB}
    .nfet-head h2{margin:0;font-size:17px;font-weight:700}
    .nfet-sub{margin:2px 0 0;font-size:12px;color:#6B7280}
    .nfet-x{margin-left:auto;border:0;background:transparent;font-size:20px;line-height:1;color:#6B7280;cursor:pointer;padding:4px 6px;border-radius:6px}
    .nfet-x:hover{background:#F3F4F6;color:#111827}
    .nfet-tools{display:flex;gap:8px;padding:12px 18px 8px}
    .nfet-search{flex:1;min-width:0;padding:8px 10px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px}
    .nfet-search:focus,.nfet-in:focus,.nfet-sel:focus{outline:none;border-color:#10B981;box-shadow:0 0 0 3px rgba(16,185,129,.18)}
    .nfet-btn{border:1px solid #D1D5DB;background:#fff;color:#374151;border-radius:8px;padding:7px 12px;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap}
    .nfet-btn:hover{background:#F9FAFB}
    .nfet-btn:disabled{opacity:.55;cursor:default}
    .nfet-btn-primary{background:#059669;border-color:#059669;color:#fff}
    .nfet-btn-primary:hover{background:#047857}
    .nfet-btn-warn{background:#FFFBEB;border-color:#FCD34D;color:#92400E}
    .nfet-btn-warn:hover{background:#FEF3C7}
    .nfet-btn-sm{padding:4px 9px;font-size:12px;border-radius:7px}
    .nfet-in,.nfet-sel{width:100%;padding:7px 9px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;background:#fff}
    .nfet-addrow{display:flex;gap:6px;padding:0 18px 10px}
    .nfet-notice{margin:0 18px 10px;padding:8px 10px;border-radius:8px;background:#FFFBEB;border:1px solid #FDE68A;color:#92400E;font-size:12px;line-height:1.45}
    .nfet-hint{margin:0 18px 8px;font-size:12px;color:#6B7280}
    .nfet-body{flex:1;overflow-y:auto;padding:0 10px 90px}
    .nfet-ghead{display:flex;align-items:center;gap:8px;padding:12px 8px 6px;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#6B7280}
    .nfet-ghead .nfet-count{font-weight:600;color:#9CA3AF}
    .nfet-gmove{margin-left:auto;display:flex;gap:2px}
    .nfet-gmove button{border:0;background:transparent;color:#9CA3AF;cursor:pointer;font-size:13px;padding:2px 6px;border-radius:5px}
    .nfet-gmove button:hover:not(:disabled){background:#F3F4F6;color:#111827}
    .nfet-gmove button:disabled{opacity:.3;cursor:default}
    .nfet-list{list-style:none;margin:0;padding:0;border:1px solid #F3F4F6;border-radius:10px;overflow:hidden}
    .nfet-row{display:flex;align-items:center;gap:8px;padding:9px 10px;border-bottom:1px solid #F3F4F6;background:#fff}
    .nfet-row:last-child{border-bottom:0}
    .nfet-row:hover{background:#F9FAFB}
    .nfet-row.dragging{opacity:.45;background:#ECFDF5}
    .nfet-grip{cursor:grab;color:#D1D5DB;font-size:14px;letter-spacing:-2px;user-select:none;padding:0 2px}
    .nfet-row:hover .nfet-grip{color:#9CA3AF}
    .nfet-main{flex:1;min-width:0}
    .nfet-name{font-weight:600;display:flex;align-items:center;gap:6px;flex-wrap:wrap;word-break:break-word}
    .nfet-tag{font-size:10px;font-weight:600;padding:1px 6px;border-radius:999px;background:#F3F4F6;color:#4B5563}
    .nfet-tag-lock{background:#EFF6FF;color:#1D4ED8}
    .nfet-tag-group{background:#ECFDF5;color:#047857}
    .nfet-meta{font-size:12px;color:#9CA3AF;margin-top:1px}
    .nfet-acts{display:flex;gap:4px;flex-shrink:0}
    .nfet-act{border:1px solid transparent;background:transparent;color:#4B5563;font-size:12px;font-weight:600;padding:4px 7px;border-radius:7px;cursor:pointer}
    .nfet-act:hover{background:#fff;border-color:#E5E7EB;color:#111827}
    .nfet-act-off:hover{color:#B45309;border-color:#FCD34D;background:#FFFBEB}
    .nfet-edit{flex:1;display:flex;flex-direction:column;gap:6px}
    .nfet-edit-row{display:flex;gap:6px}
    .nfet-confirm{padding:8px 10px;border-radius:8px;background:#F0FDF4;border:1px solid #BBF7D0;font-size:13px;line-height:1.45;color:#14532D}
    .nfet-confirm.merge{background:#FFFBEB;border-color:#FDE68A;color:#78350F}
    .nfet-confirm .nfet-edit-row{margin-top:6px}
    .nfet-err{font-size:12px;color:#B91C1C}
    .nfet-fold{margin:14px 0 0}
    .nfet-fold>summary{cursor:pointer;list-style:none;padding:10px 8px;font-size:13px;font-weight:600;color:#4B5563;display:flex;gap:6px;align-items:center}
    .nfet-fold>summary::-webkit-details-marker{display:none}
    .nfet-fold>summary::before{content:'▸';color:#9CA3AF;transition:transform .15s}
    .nfet-fold[open]>summary::before{transform:rotate(90deg)}
    .nfet-row.off .nfet-name{color:#6B7280;font-weight:500}
    .nfet-empty{padding:18px 8px;text-align:center;color:#9CA3AF;font-size:13px}
    .nfet-toast{position:absolute;left:14px;right:14px;bottom:14px;display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:10px;background:#111827;color:#F9FAFB;font-size:13px;box-shadow:0 8px 24px rgba(0,0,0,.25)}
    .nfet-toast span{flex:1;line-height:1.4}
    .nfet-toast button{border:0;background:transparent;color:#6EE7B7;font-weight:700;cursor:pointer;font-size:13px;padding:2px 4px}
    .nfet-toast.err{background:#7F1D1D}
    .nfet-last{display:flex;align-items:center;gap:10px;margin:6px 0 4px;padding:8px 10px;border:1px solid #E5E7EB;border-radius:9px;background:#F9FAFB;font-size:12px;color:#4B5563;line-height:1.4}
    .nfet-last span{flex:1}
    .nfet-last button{border:0;background:transparent;color:#047857;font-weight:700;font-size:13px;cursor:pointer;padding:2px 4px}
    .nfet-drawer.nfet-busy{pointer-events:none;cursor:progress}
    .nfet-drawer.nfet-busy .nfet-body,.nfet-drawer.nfet-busy .nfet-tools{opacity:.55;transition:opacity .1s}
    @media (max-width:520px){.nfet-acts .nfet-lbl{display:none}}
</style>
<script>
(function () {
    if (window.NFExpenseTypes) return;
    var BOOT = @json($nfetBoot);
    var URLS = BOOT.urls;
    var ORIGINAL = ['expense', 'khaas_expense'];
    var SCOPE_TAG = {expense: 'Expense only', khaas_expense: 'Khaas only', qurbani: 'Qurbani'};
    var S = {
        types: BOOT.types || [], canManage: !!BOOT.canManage, ready: !!BOOT.ready,
        pickers: {}, root: null, ctx: null, data: null, q: '', edit: null, toast: null,
        toastTimer: null, dragId: null, busy: false
    };

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
        });
    }
    function csrf() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.content : '';
    }
    function idUrl(base, id) { return base.replace('/types/0/', '/types/' + encodeURIComponent(id) + '/'); }
    function fmtDate(d) {
        if (!d) return '';
        var p = String(d).slice(0, 10).split('-');
        if (p.length !== 3) return d;
        var m = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][parseInt(p[1], 10) - 1] || '';
        var thisYear = String(new Date().getFullYear()) === p[0];
        return parseInt(p[2], 10) + ' ' + m + (thisYear ? '' : ' ' + p[0]);
    }
    function lc(s) { return String(s || '').trim().toLowerCase(); }

    // Same rule as ExpenseTypeService::inScope().
    function inScope(t, bu, rcc) {
        bu = parseInt(bu || 0, 10);
        if (bu > 0) {
            if (bu === 1) { if (!(t.bu === null || t.bu === 1)) return false; }
            else if (t.bu !== bu) return false;
        }
        if (rcc) {
            if (ORIGINAL.indexOf(rcc) !== -1) { if (!(t.rcc === null || t.rcc === rcc)) return false; }
            else if (t.rcc !== rcc) return false;
        }
        return true;
    }

    // ---------------------------------------------------------------- pickers
    function render(id) {
        var p = S.pickers[id];
        var sel = document.getElementById(id);
        if (!p || !sel) return;
        var sc = (p.scope && p.scope()) || {};
        var keep = sel.value;
        // A rename (or its undo) made in the drawer: the chosen value follows the new name.
        if (S.follow && keep && keep === S.follow.from) keep = S.follow.to;
        var placeholder = sel.querySelector('option[value=""]');
        var addNew = sel.querySelector('option[value="__ADD_NEW__"]');

        var list = [], seen = {};
        S.types.forEach(function (t) {
            if (!inScope(t, sc.bu, sc.rcc)) return;
            var k = lc(t.name);
            if (seen[k]) return;
            seen[k] = 1;
            list.push(t);
        });

        while (sel.firstChild) sel.removeChild(sel.firstChild);
        sel.appendChild(placeholder || new Option(p.placeholder || 'Select Expense Type', ''));
        var hasGroups = list.some(function (t) { return !!t.group; });
        var cur = null, holder = sel;
        list.forEach(function (t) {
            if (hasGroups) {
                var g = t.group || 'Other';
                if (g !== cur) {
                    cur = g;
                    holder = document.createElement('optgroup');
                    holder.label = g;
                    sel.appendChild(holder);
                }
            }
            var o = new Option(t.name, t.name);
            // The attributes older page code still reads.
            o.setAttribute('data-business-unit-id', t.bu == null ? '1' : String(t.bu));
            o.setAttribute('data-request-type', t.rcc || '');
            holder.appendChild(o);
        });
        if (addNew) sel.appendChild(addNew);

        var still = !!keep && keep !== '__ADD_NEW__' && Array.prototype.some.call(sel.options, function (o) { return o.value === keep; });
        sel.value = still ? keep : '';
        if (keep && !still && keep !== '__ADD_NEW__') {
            sel.dispatchEvent(new Event('change', {bubbles: true}));
        }
    }
    function renderAll() { Object.keys(S.pickers).forEach(render); }
    function register(id, opts) {
        S.pickers[id] = opts || {};
        render(id);
    }
    function refresh() {
        return fetch(URLS.all, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j && j.success) {
                    S.types = j.types || [];
                    S.canManage = !!j.can_manage;
                    renderAll();
                }
            })
            .catch(function () { /* the page keeps its current list */ });
    }

    // ---------------------------------------------------------------- api
    function api(method, url, body) {
        return fetch(url, {
            method: method,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json', 'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(), 'X-Requested-With': 'XMLHttpRequest'
            },
            body: body ? JSON.stringify(body) : undefined
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (j) {
                if (j.success === undefined) j.success = r.ok;
                if (!j.message && !r.ok) j.message = r.status === 419 ? 'Your session expired — reload the page.' : 'Something went wrong (' + r.status + ').';
                j._status = r.status;
                return j;
            });
        }).catch(function () {
            return {success: false, message: 'No connection — please try again.'};
        });
    }

    // ---------------------------------------------------------------- drawer
    function ensureRoot() {
        if (S.root) return S.root;
        var root = document.createElement('div');
        root.className = 'nfet-root';
        root.innerHTML =
            '<div class="nfet-backdrop" data-act="close"></div>' +
            '<aside class="nfet-drawer" role="dialog" aria-modal="true" aria-labelledby="nfet-title">' +
            '  <header class="nfet-head"><div><h2 id="nfet-title">Expense types</h2><p class="nfet-sub"></p></div>' +
            '    <button type="button" class="nfet-x" data-act="close" aria-label="Close">✕</button></header>' +
            '  <div class="nfet-tools"><input type="search" class="nfet-search" placeholder="Search types…" aria-label="Search types">' +
            '    <button type="button" class="nfet-btn nfet-btn-primary" data-act="add-open">+ Add type</button></div>' +
            '  <div class="nfet-addrow" hidden><input class="nfet-in nfet-add-in" maxlength="100" placeholder="New expense type name">' +
            '    <button type="button" class="nfet-btn nfet-btn-primary" data-act="add-save">Add</button>' +
            '    <button type="button" class="nfet-btn" data-act="add-cancel">Cancel</button></div>' +
            '  <div class="nfet-notice" hidden></div>' +
            '  <p class="nfet-hint" hidden>Drag ⋮⋮ to change the order in the dropdown. Renaming also renames every past expense.</p>' +
            '  <div class="nfet-body"><div class="nfet-empty">Loading…</div></div>' +
            '  <div class="nfet-toast" hidden role="status"><span></span><button type="button" data-act="undo">Undo</button></div>' +
            '</aside>';
        document.body.appendChild(root);
        root.addEventListener('click', onClick);
        root.addEventListener('keydown', onKey);
        root.addEventListener('change', onChange);
        root.querySelector('.nfet-search').addEventListener('input', function (e) { S.q = e.target.value; draw(); });
        var body = root.querySelector('.nfet-body');
        body.addEventListener('dragstart', onDragStart);
        body.addEventListener('dragover', onDragOver);
        body.addEventListener('dragend', onDragEnd);
        body.addEventListener('drop', function (e) { e.preventDefault(); });
        S.root = root;
        return root;
    }

    // open('select_id') for a registered dropdown, or open({bu, rcc, label}) from a settings page.
    function open(target) {
        var sc, label;
        if (target && typeof target === 'object') {
            sc = target;
            label = target.label || '';
        } else {
            var p = S.pickers[target] || {};
            sc = (p.scope && p.scope()) || {};
            label = (p.label && p.label()) || '';
        }
        S.ctx = {
            pickerId: typeof target === 'string' ? target : null,
            bu: parseInt(sc.bu || 1, 10) || 1,
            rcc: sc.rcc || 'expense',
            label: label
        };
        S.q = ''; S.edit = null; S.data = null; S.changed = false;
        var root = ensureRoot();
        root.querySelector('.nfet-search').value = '';
        root.querySelector('.nfet-addrow').hidden = true;
        root.querySelector('.nfet-sub').textContent = S.ctx.label || 'Expense Reimbursement';
        root.classList.add('open');
        document.addEventListener('keydown', escClose);
        setTimeout(function () { root.querySelector('.nfet-search').focus(); }, 60);
        load();
    }
    function close() {
        if (!S.root || !S.root.classList.contains('open')) return;
        S.root.classList.remove('open');
        document.removeEventListener('keydown', escClose);
        hideToast();
        try { window.dispatchEvent(new CustomEvent('nfet:closed', {detail: {changed: !!S.changed}})); } catch (x) {}
    }
    function escClose(e) {
        if (e.key === 'Escape' && !S.edit) close();
    }

    function load() {
        var u = URLS.list + '?manage=1&business_unit_id=' + encodeURIComponent(S.ctx.bu) +
            '&request_category_code=' + encodeURIComponent(S.ctx.rcc);
        return fetch(u, {headers: {'Accept': 'application/json'}, credentials: 'same-origin'})
            .then(function (r) { return r.json(); })
            .then(function (j) { S.data = j; draw(); })
            .catch(function () {
                S.root.querySelector('.nfet-body').innerHTML = '<div class="nfet-empty">Could not load — check the connection and try again.</div>';
            });
    }
    function afterChange(res) {
        S.changed = true;
        return Promise.all([load(), refresh()]).then(function () {
            showToast(res.message || 'Saved', res.undo_id || null, false);
        });
    }

    function blocks(types) {
        var order = [], map = {};
        types.forEach(function (t) {
            var g = t.group || '';
            if (!(g in map)) { map[g] = []; order.push(g); }
            map[g].push(t);
        });
        var named = order.filter(function (g) { return g !== ''; });
        if (map['']) named.push('');
        return named.map(function (g) { return {group: g, items: map[g]}; });
    }

    function draw() {
        if (!S.root || !S.data) return;
        var d = S.data, manage = !!d.can_manage && !!d.ready;
        var notice = S.root.querySelector('.nfet-notice');
        if (!d.ready) {
            notice.hidden = false;
            notice.textContent = 'Editing is not switched on yet — the database update has not been run. Adding a type still works.';
        } else if (!d.can_manage) {
            notice.hidden = false;
            notice.textContent = 'Only Taimur can rename, reorder or switch off expense types.';
        } else {
            notice.hidden = true;
        }
        S.root.querySelector('.nfet-hint').hidden = !manage || !!S.q;

        var q = lc(S.q), html = '';
        // A standing "last change · Undo" — the 24-hour undo does not depend on catching the toast.
        if (manage && d.last_change && !q) {
            html += '<div class="nfet-last"><span>↶ Last change: ' + esc(d.last_change.note) + '</span>' +
                '<button type="button" data-act="undo-last" data-id="' + d.last_change.undo_id + '">Undo</button></div>';
        }
        var types = (d.types || []).filter(function (t) { return !q || lc(t.name).indexOf(q) !== -1 || lc(t.group).indexOf(q) !== -1; });
        if (!types.length) {
            html += '<div class="nfet-empty">' + (q ? 'No active type matches “' + esc(S.q) + '”.' : 'No active types yet — add one above.') + '</div>';
        } else if (q) {
            html += '<ul class="nfet-list">' + types.map(function (t) { return rowHtml(t, manage, false); }).join('') + '</ul>';
        } else {
            var bl = blocks(types), named = bl.filter(function (b) { return b.group !== ''; }).length;
            bl.forEach(function (b, i) {
                if (bl.length > 1 || b.group !== '') {
                    var canMove = manage && b.group !== '';
                    html += '<div class="nfet-ghead"><span>' + esc(b.group || 'Other') + '</span><span class="nfet-count">' + b.items.length + '</span>' +
                        (canMove ? '<span class="nfet-gmove"><button type="button" data-act="gup" data-group="' + esc(b.group) + '" ' + (i === 0 ? 'disabled' : '') + ' aria-label="Move group up">↑</button>' +
                            '<button type="button" data-act="gdown" data-group="' + esc(b.group) + '" ' + (i >= named - 1 ? 'disabled' : '') + ' aria-label="Move group down">↓</button></span>' : '') +
                        '</div>';
                } else {
                    html += '<div style="height:6px"></div>';
                }
                html += '<ul class="nfet-list" data-group="' + esc(b.group) + '">' + b.items.map(function (t) { return rowHtml(t, manage, true); }).join('') + '</ul>';
            });
        }

        var inactive = (d.inactive || []).filter(function (t) { return !q || lc(t.name).indexOf(q) !== -1; });
        if ((d.inactive || []).length) {
            html += '<details class="nfet-fold"' + (q && inactive.length ? ' open' : '') + '><summary>Inactive types (' + (d.inactive || []).length + ')</summary>' +
                (inactive.length ? '<ul class="nfet-list">' + inactive.map(function (t) { return offRowHtml(t, manage); }).join('') + '</ul>' : '<div class="nfet-empty">None match.</div>') +
                '</details>';
        }

        var old = (d.orphans || []).filter(function (o) { return !q || lc(o.name).indexOf(q) !== -1; });
        if (manage && (d.orphans || []).length) {
            html += '<details class="nfet-fold"' + (S.edit && S.edit.mode === 'old' ? ' open' : '') + '><summary>Old names on past expenses (' + (d.orphans || []).length + ')</summary>' +
                '<p class="nfet-hint" style="margin:0 8px 8px">Typed on old expenses but not in the list. Merge one into a type to relabel those expenses.</p>' +
                (old.length ? '<ul class="nfet-list">' + old.map(function (o) { return oldRowHtml(o, d.types || []); }).join('') + '</ul>' : '<div class="nfet-empty">None match.</div>') +
                '</details>';
        }

        S.root.querySelector('.nfet-body').innerHTML = html;
        var f = S.root.querySelector('.nfet-body [data-autofocus]');
        if (f) { f.focus(); if (f.select) f.select(); }
    }

    function metaLine(t) {
        if (t.locked) return 'Used by bike, fuel and workshop records' + (t.used ? ' · ' + t.used + '×' : '');
        if (!t.used) return 'Not used yet';
        return 'Used ' + t.used + '×' + (t.last_used ? ' · last ' + fmtDate(t.last_used) : '');
    }

    function rowHtml(t, manage, draggable) {
        var e = S.edit && S.edit.id === t.id ? S.edit : null;
        var tags = (t.locked ? '<span class="nfet-tag nfet-tag-lock" title="Petrol and Maintenance cannot be renamed or switched off">🔒 locked</span>' : '') +
            // In the Expense / Khaas list, a type that is NOT shared by both says so.
            (t.scope !== 'shared' && SCOPE_TAG[t.scope] && ORIGINAL.indexOf(S.ctx.rcc) !== -1 ? '<span class="nfet-tag">' + SCOPE_TAG[t.scope] + '</span>' : '') +
            (S.q && t.group ? '<span class="nfet-tag nfet-tag-group">' + esc(t.group) + '</span>' : '');

        if (e && e.mode === 'rename') {
            var c = e.preview;
            return '<li class="nfet-row" data-id="' + t.id + '"><div class="nfet-edit">' +
                '<div class="nfet-edit-row"><input class="nfet-in" data-role="rename-in" maxlength="100" value="' + esc(e.value) + '" ' + (c ? '' : 'data-autofocus') + ' aria-label="New name">' +
                '<button type="button" class="nfet-btn nfet-btn-primary nfet-btn-sm" data-act="rename-check">Save</button>' +
                '<button type="button" class="nfet-btn nfet-btn-sm" data-act="cancel">Cancel</button></div>' +
                (e.error ? '<div class="nfet-err">' + esc(e.error) + '</div>' : '') +
                (c ? '<div class="nfet-confirm' + (c.action === 'merge' ? ' merge' : '') + '">' + esc(c.message) +
                    (c.action === 'merge' ? '<br><small>Their amounts and ledger lines do not change. You can undo this for 24 hours.</small>' : '') +
                    '<div class="nfet-edit-row"><button type="button" class="nfet-btn nfet-btn-primary nfet-btn-sm" data-act="rename-do" data-autofocus>' + (c.action === 'merge' ? 'Merge' : 'Rename') + '</button>' +
                    '<button type="button" class="nfet-btn nfet-btn-sm" data-act="cancel">Cancel</button></div></div>' : '') +
                '</div></li>';
        }
        if (e && e.mode === 'group') {
            var dl = 'nfet-groups-' + t.id;
            return '<li class="nfet-row" data-id="' + t.id + '"><div class="nfet-edit">' +
                '<div style="font-weight:600">' + esc(t.name) + ' — group</div>' +
                '<div class="nfet-edit-row"><input class="nfet-in" data-role="group-in" list="' + dl + '" maxlength="60" placeholder="e.g. Food, Packaging, Shop" value="' + esc(e.value) + '" data-autofocus aria-label="Group">' +
                '<datalist id="' + dl + '">' + ((S.data.groups || []).map(function (g) { return '<option value="' + esc(g) + '">'; }).join('')) + '</datalist>' +
                '<button type="button" class="nfet-btn nfet-btn-primary nfet-btn-sm" data-act="group-save">Save</button></div>' +
                '<div class="nfet-edit-row">' + (t.group ? '<button type="button" class="nfet-btn nfet-btn-sm" data-act="group-clear">Remove from “' + esc(t.group) + '”</button>' : '') +
                '<button type="button" class="nfet-btn nfet-btn-sm" data-act="cancel">Cancel</button></div>' +
                (e.error ? '<div class="nfet-err">' + esc(e.error) + '</div>' : '') +
                '</div></li>';
        }
        if (e && e.mode === 'off') {
            return '<li class="nfet-row" data-id="' + t.id + '"><div class="nfet-edit"><div class="nfet-confirm merge">Turn off “' + esc(t.name) + '”? It stops showing in every Expense Type dropdown. ' +
                (t.used ? 'Its ' + t.used + ' past expense' + (t.used === 1 ? '' : 's') + ' keep it.' : '') +
                '<div class="nfet-edit-row"><button type="button" class="nfet-btn nfet-btn-warn nfet-btn-sm" data-act="off-do" data-autofocus>Turn off</button>' +
                '<button type="button" class="nfet-btn nfet-btn-sm" data-act="cancel">Cancel</button></div></div>' +
                (e.error ? '<div class="nfet-err">' + esc(e.error) + '</div>' : '') + '</div></li>';
        }

        var acts = '';
        if (manage) {
            acts = '<div class="nfet-acts">' +
                (!t.locked ? '<button type="button" class="nfet-act" data-act="rename" title="Rename">✎<span class="nfet-lbl"> Rename</span></button>' : '') +
                '<button type="button" class="nfet-act" data-act="group" title="Put under a heading">🏷<span class="nfet-lbl"> Group</span></button>' +
                (!t.locked ? '<button type="button" class="nfet-act nfet-act-off" data-act="off" title="Stop showing in the dropdown">⏸<span class="nfet-lbl"> Turn off</span></button>' : '') +
                '</div>';
        }
        return '<li class="nfet-row" data-id="' + t.id + '"' + (manage && draggable ? ' draggable="true"' : '') + '>' +
            (manage && draggable ? '<span class="nfet-grip" title="Drag to reorder" aria-hidden="true">⋮⋮</span>' : '') +
            '<div class="nfet-main"><div class="nfet-name">' + esc(t.name) + tags + '</div><div class="nfet-meta">' + esc(metaLine(t)) + '</div></div>' +
            acts + '</li>';
    }

    function offRowHtml(t, manage) {
        var why = t.merged_into ? 'Merged into “' + t.merged_into + '”' : ('Turned off' + (t.hidden_at ? ' ' + fmtDate(t.hidden_at) : ''));
        return '<li class="nfet-row off" data-id="' + t.id + '"><div class="nfet-main"><div class="nfet-name">' + esc(t.name) + '</div>' +
            '<div class="nfet-meta">' + esc(why) + (t.used ? ' · ' + t.used + ' past expense' + (t.used === 1 ? '' : 's') : '') + '</div></div>' +
            (manage ? '<div class="nfet-acts"><button type="button" class="nfet-btn nfet-btn-sm" data-act="on">Turn on</button></div>' : '') + '</li>';
    }

    function oldRowHtml(o, types) {
        var e = S.edit && S.edit.mode === 'old' && S.edit.name === o.name ? S.edit : null;
        // Closest matches first (types sharing a word with the old name), then the rest.
        var words = function (x) { return String(x || '').toLowerCase().split(/[^a-z0-9]+/).filter(function (w) { return w.length > 1; }); };
        var ow = words(o.name);
        var cands = types.filter(function (t) { return !t.locked; }).map(function (t, i) {
            var tw = words(t.name);
            return {t: t, i: i, score: ow.filter(function (w) { return tw.indexOf(w) !== -1; }).length};
        }).sort(function (a, b) { return (b.score - a.score) || (a.i - b.i); });
        var optOf = function (c) { return '<option value="' + c.t.id + '"' + (e && String(e.into) === String(c.t.id) ? ' selected' : '') + '>' + esc(c.t.name) + '</option>'; };
        var close = cands.filter(function (c) { return c.score > 0; }), rest = cands.filter(function (c) { return c.score === 0; });
        var opts = (close.length ? '<optgroup label="Closest match">' + close.map(optOf).join('') + '</optgroup><optgroup label="All other types">' : '') +
            rest.map(optOf).join('') + (close.length ? '</optgroup>' : '');
        var target = e && e.into ? types.filter(function (t) { return String(t.id) === String(e.into); })[0] : null;
        return '<li class="nfet-row" data-old="' + esc(o.name) + '"><div class="nfet-edit">' +
            '<div class="nfet-name">' + esc(o.name) + '<span class="nfet-tag">' + o.used + '×</span></div>' +
            '<div class="nfet-edit-row"><select class="nfet-sel" data-role="old-into" aria-label="Merge into"><option value="">Merge into…</option>' + opts + '</select></div>' +
            (target ? '<div class="nfet-confirm merge">Relabel ' + o.used + ' past expense' + (o.used === 1 ? '' : 's') + ' from “' + esc(o.name) + '” to “' + esc(target.name) + '”? Amounts do not change. You can undo this for 24 hours.' +
                '<div class="nfet-edit-row"><button type="button" class="nfet-btn nfet-btn-warn nfet-btn-sm" data-act="old-do">Merge</button>' +
                '<button type="button" class="nfet-btn nfet-btn-sm" data-act="cancel">Cancel</button></div></div>' : '') +
            (e && e.error ? '<div class="nfet-err">' + esc(e.error) + '</div>' : '') +
            '</div></li>';
    }

    function findType(id) {
        var all = (S.data.types || []).concat(S.data.inactive || []);
        for (var i = 0; i < all.length; i++) if (String(all[i].id) === String(id)) return all[i];
        return null;
    }
    function setBusy(on) {
        S.busy = on;
        if (S.root) S.root.querySelector('.nfet-drawer').classList.toggle('nfet-busy', on);
    }

    // ---------------------------------------------------------------- events
    function onClick(e) {
        var b = e.target.closest('[data-act]');
        if (!b || S.busy) return;
        var act = b.getAttribute('data-act');
        var li = b.closest('.nfet-row');
        var id = li ? li.getAttribute('data-id') : null;
        var t = id ? findType(id) : null;

        if (act === 'close') return close();
        if (act === 'undo') return doUndo();
        if (act === 'undo-last') return doUndo(b.getAttribute('data-id'));
        if (act === 'add-open') {
            var row = S.root.querySelector('.nfet-addrow');
            row.hidden = false;
            var inp = row.querySelector('input');
            inp.value = S.q || '';
            inp.focus();
            return;
        }
        if (act === 'add-cancel') { S.root.querySelector('.nfet-addrow').hidden = true; return; }
        if (act === 'add-save') return doAdd();
        if (act === 'cancel') { S.edit = null; draw(); return; }
        if (act === 'rename' && t) { S.edit = {id: t.id, mode: 'rename', value: t.name}; draw(); return; }
        if (act === 'group' && t) { S.edit = {id: t.id, mode: 'group', value: t.group || ''}; draw(); return; }
        if (act === 'off' && t) { S.edit = {id: t.id, mode: 'off'}; draw(); return; }
        if (act === 'rename-check') return renameCheck(li);
        if (act === 'rename-do') return renameDo();
        if (act === 'group-save') return groupSave(li.querySelector('[data-role="group-in"]').value);
        if (act === 'group-clear') return groupSave('');
        if (act === 'off-do') return setActive(S.edit.id, false);
        if (act === 'on' && id) return setActive(id, true);
        if (act === 'gup' || act === 'gdown') return moveGroup(b.getAttribute('data-group'), act === 'gup' ? -1 : 1);
        if (act === 'old-do') return oldDo();
    }
    function onChange(e) {
        if (e.target.matches('[data-role="old-into"]')) {
            var li = e.target.closest('.nfet-row');
            S.edit = {mode: 'old', name: li.getAttribute('data-old'), into: e.target.value || null};
            draw();
        }
    }
    function onKey(e) {
        var r = e.target.getAttribute && e.target.getAttribute('data-role');
        if (e.key === 'Escape' && S.edit) { e.stopPropagation(); S.edit = null; draw(); return; }
        if (e.key !== 'Enter') return;
        if (e.target.classList.contains('nfet-add-in')) { e.preventDefault(); doAdd(); }
        else if (r === 'rename-in') { e.preventDefault(); if (S.edit && S.edit.preview) renameDo(); else renameCheck(e.target.closest('.nfet-row')); }
        else if (r === 'group-in') { e.preventDefault(); groupSave(e.target.value); }
    }

    // ---------------------------------------------------------------- actions
    function doAdd() {
        var inp = S.root.querySelector('.nfet-add-in');
        var name = inp.value.trim();
        if (!name) { inp.focus(); return; }
        setBusy(true);
        var body = {category_name: name, business_unit_id: S.ctx.bu};
        if (S.ctx.rcc) body.request_category_code = S.ctx.rcc;
        api('POST', URLS.store, body).then(function (r) {
            setBusy(false);
            if (!r.success) {
                if (r.restore_id) {
                    showToast(r.message, null, true);
                    S.root.querySelector('details.nfet-fold') && S.root.querySelector('details.nfet-fold').setAttribute('open', '');
                    return;
                }
                return showToast(r.message, null, true);
            }
            inp.value = '';
            S.root.querySelector('.nfet-addrow').hidden = true;
            afterChange({message: 'Added “' + (r.category || name) + '”'});
        });
    }

    function renameCheck(li) {
        var val = li.querySelector('[data-role="rename-in"]').value.trim();
        S.edit.value = val;
        S.edit.error = null;
        S.edit.preview = null;
        setBusy(true);
        api('GET', idUrl(URLS.preview, S.edit.id) + '?name=' + encodeURIComponent(val)).then(function (r) {
            setBusy(false);
            if (!r.success) { S.edit.error = r.message; draw(); return; }
            S.edit.preview = r;
            draw();
        });
    }
    function renameDo() {
        var e = S.edit;
        if (!e || !e.preview) return;
        setBusy(true);
        var oldName = (findType(e.id) || {}).name;
        var newName = e.preview.action === 'merge' && e.preview.into ? e.preview.into.name : e.value;
        api('POST', idUrl(URLS.rename, e.id), {name: e.value, merge: e.preview.action === 'merge'}).then(function (r) {
            setBusy(false);
            if (!r.success) { e.error = r.message; e.preview = null; draw(); return; }
            S.follow = {from: oldName, to: newName};
            S.edit = null;
            afterChange(r);
        });
    }
    function groupSave(val) {
        var e = S.edit;
        setBusy(true);
        api('POST', idUrl(URLS.group, e.id), {group: val}).then(function (r) {
            setBusy(false);
            if (!r.success) { e.error = r.message; draw(); return; }
            S.edit = null;
            afterChange(r);
        });
    }
    function setActive(id, on) {
        setBusy(true);
        api('POST', idUrl(URLS.active, id), {active: on ? 1 : 0}).then(function (r) {
            setBusy(false);
            if (!r.success) {
                if (S.edit) { S.edit.error = r.message; draw(); } else showToast(r.message, null, true);
                return;
            }
            S.edit = null;
            afterChange(r);
        });
    }
    function oldDo() {
        var e = S.edit;
        if (!e || !e.into) return;
        setBusy(true);
        api('POST', URLS.mergeOld, {name: e.name, into_id: e.into, business_unit_id: S.ctx.bu}).then(function (r) {
            setBusy(false);
            if (!r.success) { e.error = r.message; draw(); return; }
            S.edit = null;
            afterChange(r);
        });
    }
    function doUndo(forId) {
        var id = forId || (S.toast && S.toast.undoId);
        if (!id) return;
        setBusy(true);
        api('POST', URLS.undo, {undo_id: id}).then(function (r) {
            setBusy(false);
            if (!r.success) return showToast(r.message, null, true);
            S.changed = true;
            S.follow = r.renamed ? {from: r.renamed.from, to: r.renamed.to} : null;
            Promise.all([load(), refresh()]).then(function () { showToast('Undone', null, false); });
        });
    }

    // ---------------------------------------------------------------- order
    function currentOrderIds() {
        var ids = [];
        S.root.querySelectorAll('.nfet-body > .nfet-list .nfet-row[data-id]').forEach(function (li) { ids.push(li.getAttribute('data-id')); });
        return ids;
    }
    function saveOrder(ids) {
        setBusy(true);
        api('POST', URLS.reorder, {ids: ids}).then(function (r) {
            setBusy(false);
            if (!r.success) { showToast(r.message, null, true); load(); return; }
            S.changed = true;
            Promise.all([load(), refresh()]).then(function () { showToast('Order saved — the dropdown follows it', null, false); });
        });
    }
    function moveGroup(group, dir) {
        var bl = blocks(S.data.types || []);
        var named = bl.filter(function (b) { return b.group !== ''; });
        var rest = bl.filter(function (b) { return b.group === ''; });
        var i = named.findIndex(function (b) { return b.group === group; });
        var j = i + dir;
        if (i < 0 || j < 0 || j >= named.length) return;
        var tmp = named[i]; named[i] = named[j]; named[j] = tmp;
        var ids = [];
        named.concat(rest).forEach(function (b) { b.items.forEach(function (t) { ids.push(t.id); }); });
        saveOrder(ids);
    }
    function onDragStart(e) {
        var li = e.target.closest && e.target.closest('.nfet-row[draggable="true"]');
        if (!li) return;
        S.dragId = li.getAttribute('data-id');
        S.dragFrom = currentOrderIds().join(',');
        li.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', S.dragId); } catch (x) {}
    }
    function onDragOver(e) {
        if (!S.dragId) return;
        var over = e.target.closest && e.target.closest('.nfet-row[draggable="true"]');
        var dragging = S.root.querySelector('.nfet-row.dragging');
        if (!over || !dragging || over === dragging) return;
        if (over.parentNode !== dragging.parentNode) return; // stay inside the group — use 🏷 to move groups
        e.preventDefault();
        var r = over.getBoundingClientRect();
        var after = (e.clientY - r.top) > r.height / 2;
        over.parentNode.insertBefore(dragging, after ? over.nextSibling : over);
    }
    function onDragEnd() {
        var dragging = S.root && S.root.querySelector('.nfet-row.dragging');
        if (dragging) dragging.classList.remove('dragging');
        if (!S.dragId) return;
        S.dragId = null;
        var ids = currentOrderIds();
        if (ids.join(',') !== S.dragFrom) saveOrder(ids);
    }

    // ---------------------------------------------------------------- toast
    function showToast(msg, undoId, isErr) {
        if (!S.root) return;
        var t = S.root.querySelector('.nfet-toast');
        S.toast = {undoId: undoId};
        t.querySelector('span').textContent = msg;
        t.querySelector('button').hidden = !undoId;
        t.classList.toggle('err', !!isErr);
        t.hidden = false;
        clearTimeout(S.toastTimer);
        S.toastTimer = setTimeout(hideToast, undoId ? 12000 : 5000);
    }
    function hideToast() {
        if (!S.root) return;
        S.root.querySelector('.nfet-toast').hidden = true;
        S.toast = null;
    }

    window.NFExpenseTypes = {register: register, render: render, refresh: refresh, open: open, close: close, canManage: function () { return S.canManage; }};
})();
</script>
@endonce
