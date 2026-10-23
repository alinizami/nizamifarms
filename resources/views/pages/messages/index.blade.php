@extends(request()->boolean('embed') ? 'layouts.embed' : 'layouts.app')

@section('title', 'Messages')

@push('demo1_css')
<style>
/* ══════════════════════════════════════════════════════════════
   WhatsApp-style Messages Page
   ══════════════════════════════════════════════════════════════ */

/* Override the layout so content fills height */
#content { display: flex; flex-direction: column; overflow: hidden; }

/* ── Main Container ── */
.wa-page {
    display: flex;
    flex: 1;
    height: calc(100vh - 130px);
    background: #f0f2f5;
    border: 1px solid #dfe5e7;
    border-radius: 4px;
    overflow: hidden;
    margin: 0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.08);
}

/* ── LEFT: Sidebar ── */
.wa-sidebar {
    width: 380px;
    min-width: 340px;
    max-width: 420px;
    background: #fff;
    border-right: 1px solid #e2e8f0;
    display: flex;
    flex-direction: column;
    flex-shrink: 0;
}

/* Sidebar Header */
.wa-side-hdr {
    padding: 12px 16px;
    background: #f0f2f5;
    border-bottom: 1px solid #e2e8f0;
    flex-shrink: 0;
}
.wa-side-hdr-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 10px;
}
.wa-side-hdr-top h2 {
    font-size: 20px;
    font-weight: 800;
    color: #111827;
    margin: 0;
}
.wa-side-hdr-actions {
    display: flex;
    gap: 6px;
}
.wa-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 7px 14px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    transition: all 0.15s;
}
.wa-btn-green { background: #16a34a; color: #fff; }
.wa-btn-green:hover { background: #15803d; }
.wa-btn-gray { background: #e5e7eb; color: #6b7280; }
.wa-btn-gray:hover { background: #d1d5db; }

/* Search */
.wa-search-wrap { position: relative; }
.wa-search-wrap svg {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #9ca3af;
}
.wa-search {
    width: 100%;
    padding: 9px 14px 9px 36px;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-size: 14px;
    outline: none;
    background: #fff;
    box-sizing: border-box;
    transition: all 0.2s;
}
.wa-search:focus {
    border-color: #16a34a;
    box-shadow: 0 0 0 3px rgba(22,163,74,0.08);
}

/* Filters */
/* Apr-2026: filter strip wraps to a second line at narrow widths instead
   of clipping. We deliberately do NOT use horizontal scrolling here —
   the strip hosts the Label-filter dropdown which is positioned
   absolutely beneath its trigger button, and any overflow:auto/hidden
   on the parent would clip that popover. The management buttons (⚙
   Qurbani settings, 🤖 Auto-reply) were moved up into the side header
   so the filter row only needs to hold actual filter pills. */
.wa-filters { display: flex; gap: 6px; margin-top: 10px; flex-wrap: wrap; }
.wa-filter-btn {
    padding: 5px 14px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
    border: 1.5px solid transparent;
    cursor: pointer;
    background: #fff;
    color: #6b7280;
    transition: all 0.15s;
    white-space: nowrap;
}
.wa-filter-btn:hover { background: #f3f4f6; }
.wa-filter-btn.active {
    background: #dcfce7;
    color: #16a34a;
    border-color: #86efac;
}

/* Apr-2026: Mark-All-Read row. Lives directly under the filter pills,
   only visible when the Unread tab is active AND the user is a
   super-reader. Styled as an emphatic-but-safe action: green ink, light
   tint, full-width pill so it's hard to miss but not alarming. */
.wa-mark-all-read-row { display: flex; flex-direction: column; gap: 3px; }
.wa-mark-all-read-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    width: 100%;
    padding: 7px 12px;
    border-radius: 8px;
    border: 1.5px solid #86efac;
    background: #dcfce7;
    color: #166534;
    font-size: 12.5px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s;
}
.wa-mark-all-read-btn:hover { background: #bbf7d0; border-color: #4ade80; }
.wa-mark-all-read-btn:disabled { opacity: 0.55; cursor: progress; }
.wa-mark-all-read-hint {
    font-size: 10.5px;
    color: #6b7280;
    text-align: center;
    line-height: 1.3;
}

/* Search-mode toggle (Names / Chats). Minimal, pill-shaped, picks up
   the same green accent as filters when active so the UI stays cohesive. */
.wa-searchmode-btn {
    flex: 1;
    padding: 5px 10px;
    border-radius: 6px;
    font-size: 11px;
    font-weight: 600;
    border: 1px solid #e5e7eb;
    background: #fff;
    color: #6b7280;
    cursor: pointer;
    transition: all 0.15s;
}
.wa-searchmode-btn:hover { background: #f9fafb; }
.wa-searchmode-btn.active {
    background: #eef2ff;
    color: #4338ca;
    border-color: #c7d2fe;
}

/* Chat-content match snippet shown under a conversation when the
   sidebar is in "Chats" search mode. Slight indigo tint so it reads as
   "matched content" rather than "latest message". */
.wa-conv-match {
    font-size: 11.5px;
    color: #4b5563;
    background: #eef2ff;
    border-left: 2px solid #6366f1;
    padding: 3px 6px;
    border-radius: 4px;
    margin-top: 3px;
    line-height: 1.35;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.wa-conv-match mark {
    background: #fde68a;
    color: #78350f;
    padding: 0 2px;
    border-radius: 2px;
}

/* New Message Panel */
.wa-new-panel {
    display: none;
    padding: 12px 16px;
    border-bottom: 1px solid #d1fae5;
    background: #f0fdf4;
    flex-shrink: 0;
}
.wa-new-panel.open { display: block; }
.wa-new-panel-hdr {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
}
.wa-new-panel-hdr span { font-size: 13px; font-weight: 600; color: #166534; }
.wa-new-panel-close {
    background: none;
    border: none;
    font-size: 20px;
    cursor: pointer;
    color: #6b7280;
    line-height: 1;
    padding: 2px 6px;
    border-radius: 4px;
}
.wa-new-panel-close:hover { background: #dcfce7; }
.wa-new-input {
    width: 100%;
    padding: 9px 14px;
    border: 1.5px solid #bbf7d0;
    border-radius: 8px;
    font-size: 14px;
    outline: none;
    box-sizing: border-box;
    background: #fff;
}
.wa-new-input:focus { border-color: #16a34a; box-shadow: 0 0 0 3px rgba(22,163,74,0.08); }
.wa-cust-results {
    max-height: 240px;
    overflow-y: auto;
    margin-top: 6px;
}
.wa-cust-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    cursor: pointer;
    border-radius: 8px;
    transition: background 0.1s;
}
.wa-cust-item:hover { background: #dcfce7; }
.wa-cust-avatar {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, #16a34a, #15803d);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-weight: 700;
    font-size: 14px;
    flex-shrink: 0;
}
.wa-cust-info { flex: 1; min-width: 0; }
.wa-cust-name { font-size: 14px; font-weight: 500; color: #111827; }
.wa-cust-phone { font-size: 12px; color: #6b7280; }

/* Conversation List */
.wa-conv-list {
    flex: 1;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: #d1d5db transparent;
}
.wa-conv-list::-webkit-scrollbar { width: 5px; }
.wa-conv-list::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 10px; }
.wa-conv-list::-webkit-scrollbar-track { background: transparent; }
.wa-conv-item {
    display: flex;
    align-items: flex-start;
    padding: 13px 16px;
    cursor: pointer;
    border-bottom: 1px solid #f3f4f6;
    transition: background 0.12s;
}
.wa-conv-item:hover { background: #f3f4f6; }
.wa-conv-item.active { background: #dcfce7; }
.wa-conv-item.unread { background: #f0fdf4; }
/* Tagged-for-you pin. Amber rather than the unread green so "a teammate needs
   you" is distinguishable at a glance from "the customer replied". */
.wa-conv-item.pinned-mention { background: #fffbeb; border-left: 3px solid #f59e0b; }
.wa-conv-item.pinned-mention.active { background: #fef3c7; }
.wa-conv-pinned { font-size: 11px; font-weight: 700; color: #b45309; margin-top: 3px;
                  white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.wa-avatar {
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: linear-gradient(135deg, #16a34a, #15803d);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-weight: 700;
    font-size: 17px;
    flex-shrink: 0;
    margin-right: 12px;
}
.wa-conv-info { flex: 1; min-width: 0; }
.wa-conv-top { display: flex; justify-content: space-between; align-items: center; }
.wa-conv-name {
    font-size: 14px;
    font-weight: 500;
    color: #111827;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.wa-conv-item.unread .wa-conv-name { font-weight: 700; }
/* Apr-2026: failed-send pin shown next to the customer name. Stays on
   the inbox row until the next successful outbound clears the flag
   server-side (see WhatsAppWebController::getConversations). */
.wa-conv-failed-pin {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-right: 4px;
    color: #dc2626;
    font-size: 14px;
    font-weight: 700;
    cursor: help;
}
.wa-conv-time { font-size: 11px; color: #9ca3af; flex-shrink: 0; margin-left: 8px; }
.wa-conv-item.unread .wa-conv-time { color: #16a34a; font-weight: 600; }
.wa-conv-bottom { display: flex; justify-content: space-between; align-items: center; margin-top: 3px; }
.wa-conv-preview {
    font-size: 13px;
    color: #6b7280;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
}
.wa-conv-item.unread .wa-conv-preview { font-weight: 600; color: #374151; }
.wa-conv-city { font-size: 11px; color: #9ca3af; margin-top: 2px; }

/* ── Labels: shared chip styles + inbox-row strip + header strip ───────
   We render labels as pills both on inbox rows (small, inline, max two
   visible plus "+N more") and in the chat header (slightly larger so
   the operator can scan the current status at a glance). Colour comes
   from the label record itself; we pick a readable text colour based
   on a contrast heuristic at render time.
*/
.wa-label-chip {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 10.5px;
    font-weight: 600;
    letter-spacing: 0.1px;
    line-height: 1.4;
    white-space: nowrap;
    background: #e5e7eb;
    color: #111827;
}
.wa-label-chip .wa-label-remove {
    background: none;
    border: none;
    color: inherit;
    padding: 0 0 0 2px;
    font-size: 12px;
    cursor: pointer;
    opacity: 0.75;
    line-height: 1;
}
.wa-label-chip .wa-label-remove:hover { opacity: 1; }
.wa-conv-labels { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px; }
.wa-chat-hdr-labels {
    display: flex; gap: 6px; flex-wrap: wrap;
    padding: 6px 12px 0 12px;
    background: rgba(0,0,0,0.04);
}
.wa-chat-hdr-labels:empty { display: none; }

/* Pinned marketing-template indicator (Apr 2026 — see add_marketing_dedup_apr2026.sql).
   Sits just below the chat header / labels strip. Hidden when empty. */
.wa-chat-hdr-marketing {
    padding: 8px 12px;
    background: #fef3c7;          /* amber-100 */
    border-top: 1px solid #fde68a;
    border-bottom: 1px solid #fde68a;
    color: #78350f;               /* amber-900 */
    font-size: 12px;
    line-height: 1.45;
    display: flex; align-items: flex-start; gap: 6px;
}
.wa-chat-hdr-marketing:empty { display: none; }
.wa-chat-hdr-marketing .wa-mkt-pin { font-size: 14px; line-height: 1; margin-top: 1px; }
.wa-chat-hdr-marketing .wa-mkt-list {
    display: flex; flex-wrap: wrap; gap: 4px 10px;
}
.wa-chat-hdr-marketing .wa-mkt-item { white-space: nowrap; }
.wa-chat-hdr-marketing .wa-mkt-item b { font-weight: 600; }
.wa-chat-hdr-marketing .wa-mkt-item .wa-mkt-when { color: #92400e; }

/* Confirm dialog used for the marketing-template re-send guard. Re-uses the
   same backdrop behaviour as other inline modals in this view. */
.wa-mkt-confirm-back {
    position: fixed; inset: 0;
    background: rgba(15, 23, 42, 0.55);
    z-index: 9999;
    display: flex; align-items: center; justify-content: center;
}
.wa-mkt-confirm {
    background: #fff;
    border-radius: 10px;
    width: min(440px, 92vw);
    box-shadow: 0 20px 40px rgba(0,0,0,0.25);
    overflow: hidden;
    font-size: 14px;
}
.wa-mkt-confirm h4 {
    margin: 0; padding: 14px 18px;
    background: #fef3c7;
    color: #78350f;
    font-size: 15px;
    border-bottom: 1px solid #fde68a;
}
.wa-mkt-confirm .wa-mkt-body { padding: 16px 18px; color: #334155; line-height: 1.5; }
.wa-mkt-confirm .wa-mkt-actions {
    padding: 12px 18px; display: flex; gap: 8px; justify-content: flex-end;
    background: #f8fafc; border-top: 1px solid #e2e8f0;
}
.wa-mkt-confirm button {
    border: none; border-radius: 6px; padding: 8px 14px;
    cursor: pointer; font-weight: 500; font-size: 13px;
}
.wa-mkt-confirm .wa-mkt-cancel { background: #e2e8f0; color: #334155; }
.wa-mkt-confirm .wa-mkt-cancel:hover { background: #cbd5e1; }
.wa-mkt-confirm .wa-mkt-send-anyway { background: #f59e0b; color: #fff; }
.wa-mkt-confirm .wa-mkt-send-anyway:hover { background: #d97706; }
.wa-mkt-confirm .wa-mkt-blocked { background: #94a3b8; color: #fff; cursor: not-allowed; }

/* 3-dot chat-header menu trigger + dropdown. Absolute-positioned
   dropdown so it floats over the chat body instead of getting clipped
   by overflow:hidden on .wa-chat. */
.wa-chat-menu-wrap { position: relative; margin-left: 6px; }
.wa-chat-menu-btn {
    background: rgba(255,255,255,0.2);
    border: none;
    border-radius: 6px;
    padding: 6px 10px;
    cursor: pointer;
    color: #fff;
    font-size: 16px;
    font-weight: 500;
    line-height: 1;
}
.wa-chat-menu-btn:hover { background: rgba(255,255,255,0.32); }
.wa-chat-menu {
    display: none;
    position: absolute;
    right: 0;
    top: calc(100% + 4px);
    min-width: 200px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.12);
    z-index: 20;
    overflow: hidden;
}
.wa-chat-menu.open { display: block; }
.wa-chat-menu button {
    display: flex; align-items: center; gap: 8px;
    width: 100%;
    padding: 10px 14px;
    background: #fff;
    border: none;
    text-align: left;
    font-size: 13px;
    color: #111827;
    cursor: pointer;
}
.wa-chat-menu button:hover { background: #f3f4f6; }
.wa-chat-menu hr { margin: 4px 0; border: none; border-top: 1px solid #f3f4f6; }

/* Labels modal */
.wa-labels-modal {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.45);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}
.wa-labels-modal.open { display: flex; }
.wa-labels-box {
    width: 480px; max-width: 95vw; max-height: 88vh;
    background: #fff; border-radius: 12px;
    display: flex; flex-direction: column;
    overflow: hidden;
}
.wa-labels-hdr {
    display: flex; justify-content: space-between; align-items: center;
    padding: 14px 18px;
    border-bottom: 1px solid #f3f4f6;
}
.wa-labels-hdr h3 { margin: 0; font-size: 15px; font-weight: 700; color: #111827; }
.wa-labels-body { padding: 14px 18px; overflow-y: auto; flex: 1; }
.wa-labels-section-title {
    font-size: 12px; text-transform: uppercase; letter-spacing: 0.6px;
    color: #6b7280; font-weight: 700;
    margin: 4px 0 8px 0;
}
.wa-label-toggle {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 10px;
    border-radius: 12px;
    font-size: 12px; font-weight: 600;
    cursor: pointer;
    border: 1.5px solid #e5e7eb;
    background: #fff;
    color: #374151;
    margin: 3px 4px 3px 0;
    transition: all 0.12s;
}
.wa-label-toggle.applied {
    border-color: currentColor;
}
.wa-label-toggle:hover { background: #f9fafb; }
.wa-label-manage-row {
    display: flex; align-items: center; gap: 8px;
    padding: 6px 0;
    border-bottom: 1px dashed #f3f4f6;
}
.wa-label-manage-row:last-child { border-bottom: none; }
.wa-label-color-dot {
    width: 14px; height: 14px; border-radius: 50%;
    flex-shrink: 0;
    border: 1px solid rgba(0,0,0,0.1);
}
.wa-label-manage-actions { margin-left: auto; display: flex; gap: 4px; }
.wa-label-manage-actions button {
    background: none; border: none; cursor: pointer;
    color: #6b7280; padding: 4px 6px; border-radius: 6px; font-size: 12px;
}
.wa-label-manage-actions button:hover { color: #111827; background: #f3f4f6; }
.wa-label-create-row {
    display: flex; gap: 6px; align-items: center;
    padding-top: 10px; border-top: 1px solid #f3f4f6; margin-top: 10px;
}
.wa-label-create-row input[type=text] {
    flex: 1; padding: 7px 10px;
    border: 1.5px solid #e5e7eb; border-radius: 8px;
    font-size: 13px; outline: none;
}
.wa-label-create-row input[type=color] {
    width: 36px; height: 34px; padding: 2px;
    border: 1.5px solid #e5e7eb; border-radius: 8px; cursor: pointer;
    background: #fff;
}
.wa-label-create-row button {
    padding: 7px 14px;
    background: #16a34a; color: #fff; font-weight: 600; font-size: 13px;
    border: none; border-radius: 8px; cursor: pointer;
}
.wa-label-create-row button:hover { background: #15803d; }

/* Labels filter dropdown on the inbox */
/* Apr-2026: dropped `margin-left: auto`. It originally pushed the Label
   button (and the now-relocated ⚙/🤖 buttons) to the right edge of the
   filter row. With those settings buttons moved to the side header, the
   filter pills can simply sit left-to-right with natural spacing — and
   when the row gets crowded it now wraps to a second line cleanly
   instead of having one item awkwardly anchored to the right. */
.wa-label-filter-wrap { position: relative; }
.wa-label-filter-btn {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    padding: 5px 10px;
    font-size: 12px;
    cursor: pointer;
    color: #374151;
    font-weight: 500;
}
.wa-label-filter-btn:hover { background: #f3f4f6; }
.wa-label-filter-btn.active { background: #dcfce7; border-color: #86efac; color: #166534; }
.wa-label-filter-menu {
    display: none;
    position: absolute; right: 0; top: calc(100% + 4px);
    min-width: 180px; max-height: 260px; overflow-y: auto;
    background: #fff; border: 1px solid #e5e7eb; border-radius: 8px;
    box-shadow: 0 8px 22px rgba(0,0,0,0.1);
    z-index: 30;
}
.wa-label-filter-menu.open { display: block; }
.wa-label-filter-menu button {
    display: flex; align-items: center; gap: 6px;
    width: 100%; padding: 8px 12px;
    background: #fff; border: none; text-align: left;
    font-size: 13px; cursor: pointer; color: #111827;
}
.wa-label-filter-menu button:hover { background: #f3f4f6; }
.wa-label-filter-menu button.active { background: #f0fdf4; font-weight: 600; }
.wa-unread-badge {
    background: #16a34a;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    border-radius: 10px;
    min-width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0 6px;
    margin-left: 8px;
}

/* Phase 2 — @mention dot on inbox rows. Blue to differentiate from the
   green "unread" badge; shown only when the CURRENT user has an unread
   mention on this conversation. */
.wa-mention-dot {
    background: #3B82F6;
    color: #fff;
    font-size: 10px;
    font-weight: 700;
    border-radius: 10px;
    min-width: 20px;
    height: 20px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0 6px;
    margin-left: 6px;
    line-height: 1;
}
.wa-filter-me { display: inline-flex; align-items: center; gap: 4px; }
.wa-filter-me .wa-mention-pip {
    width: 8px; height: 8px; border-radius: 50%; background: #DC2626;
    display: inline-block;
}
/* User-mention label: subtle @ tag on the toggle chip. */
.wa-label-mention-tag {
    color: #3B82F6; font-weight: 700; margin-right: 2px;
}
.wa-label-mention-badge {
    font-size: 10px; color: #3B82F6; font-weight: 600;
    background: #EFF6FF; border: 1px solid #BFDBFE;
    padding: 1px 5px; border-radius: 6px; margin-left: 4px;
}
/* User-picker dropdown in the create-label row. */
.wa-label-user-select {
    flex: 1; min-width: 140px;
    padding: 6px 8px; border: 1px solid #e5e7eb; border-radius: 6px;
    font-size: 12px; background: #fff;
}

/* ── RIGHT: Chat Panel ── */
.wa-chat {
    flex: 1;
    display: none;
    flex-direction: column;
    min-width: 0;
    background: #e5ddd5;
    background-image: url("data:image/svg+xml,%3Csvg width='400' height='400' xmlns='http://www.w3.org/2000/svg'%3E%3Cdefs%3E%3Cpattern id='p' width='40' height='40' patternUnits='userSpaceOnUse'%3E%3Cpath d='M20 0v10M0 20h10M30 20h10M20 30v10' stroke='%23c9c2b6' stroke-width='0.5' fill='none' opacity='0.4'/%3E%3Ccircle cx='20' cy='20' r='1' fill='%23c9c2b6' opacity='0.25'/%3E%3C/pattern%3E%3C/defs%3E%3Crect width='400' height='400' fill='%23e5ddd5'/%3E%3Crect width='400' height='400' fill='url(%23p)'/%3E%3C/svg%3E");
}
.wa-chat.visible { display: flex; }

/* Chat Header */
.wa-chat-hdr {
    padding: 10px 20px;
    background: linear-gradient(135deg, #16a34a, #15803d);
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
    box-shadow: 0 1px 3px rgba(0,0,0,0.12);
    z-index: 3;
}
.wa-chat-back {
    cursor: pointer;
    font-size: 18px;
    color: rgba(255,255,255,0.9);
    background: rgba(255,255,255,0.12);
    border: none;
    border-radius: 50%;
    width: 34px;
    height: 34px;
    display: none;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.wa-chat-back:hover { background: rgba(255,255,255,0.22); }
.wa-chat-avatar {
    width: 38px;
    height: 38px;
    border-radius: 50%;
    background: rgba(255,255,255,0.2);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #fff;
    font-weight: 700;
    font-size: 16px;
    flex-shrink: 0;
}
.wa-chat-hdr-info { flex: 1; min-width: 0; }
.wa-chat-hdr-info h3 {
    color: #fff;
    font-size: 15px;
    font-weight: 700;
    margin: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.wa-chat-hdr-info p {
    color: rgba(255,255,255,0.8);
    font-size: 12px;
    margin: 1px 0 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.wa-session-badge {
    background: rgba(255,255,255,0.18);
    padding: 4px 10px;
    border-radius: 8px;
    font-size: 11px;
    color: #fff;
    font-weight: 600;
    flex-shrink: 0;
}

/* Chat Messages */
.wa-chat-msgs {
    flex: 1;
    overflow-y: auto;
    padding: 16px 60px;
    display: flex;
    flex-direction: column;
    gap: 4px;
    scrollbar-width: thin;
    scrollbar-color: #bbb transparent;
}
.wa-chat-msgs::-webkit-scrollbar { width: 6px; }
.wa-chat-msgs::-webkit-scrollbar-thumb { background: rgba(0,0,0,0.15); border-radius: 10px; }

/* Message Bubbles */
.wa-msg {
    max-width: 55%;
    padding: 7px 10px 6px;
    border-radius: 10px;
    position: relative;
    box-shadow: 0 1px 1px rgba(0,0,0,0.06);
    word-break: break-word;
}
.wa-msg.out {
    align-self: flex-end;
    background: #d9fdd3;
    border-bottom-right-radius: 3px;
}
.wa-msg.in {
    align-self: flex-start;
    background: #fff;
    border-bottom-left-radius: 3px;
}
.wa-msg-text { font-size: 13.5px; line-height: 1.4; color: #111827; white-space: pre-wrap; }
.wa-msg-meta { display: flex; align-items: center; justify-content: flex-end; gap: 4px; margin-top: 2px; }
.wa-msg-time { font-size: 10px; color: #6b7280; }
.wa-msg-sender { font-size: 10px; color: #6b7280; }
.wa-msg-status { font-size: 10px; color: #6b7280; }
.wa-msg-status.read { color: #3b82f6; }
.wa-msg-status.failed { color: #ef4444; }
.wa-msg-error { font-size: 11px; color: #ef4444; margin-top: 4px; font-style: italic; }
.wa-msg-tpl-badge {
    background: rgba(0,0,0,0.05);
    padding: 2px 8px;
    border-radius: 6px;
    font-size: 10px;
    color: #6b7280;
    margin-bottom: 4px;
    display: inline-block;
}
.wa-msg-media {
    background: rgba(0,0,0,0.04);
    padding: 8px 10px;
    border-radius: 6px;
    margin-bottom: 4px;
    font-size: 13px;
    color: #374151;
}

/* Chat Input */
.wa-chat-foot {
    padding: 10px 60px;
    background: #f0f0f0;
    flex-shrink: 0;
    z-index: 3;
}
.wa-date-sep {
    text-align: center;
    margin: 16px 0 10px;
}
.wa-date-sep span {
    background: #e2e8f0;
    color: #64748b;
    font-size: 11px;
    font-weight: 600;
    padding: 4px 14px;
    border-radius: 8px;
    letter-spacing: 0.3px;
}
.wa-session-timer {
    font-size: 11px;
    color: #92400e;
    background: #fef3c7;
    padding: 5px 12px;
    border-radius: 6px;
    text-align: center;
    margin-bottom: 6px;
}
.wa-session-timer.expiring { color: #dc2626; background: #fee2e2; font-weight: 600; }
.wa-expired-bar {
    display: none;
    background: linear-gradient(135deg, #fef3c7, #fde68a);
    border: 1px solid #fcd34d;
    padding: 14px 16px;
    text-align: center;
    border-radius: 12px;
    margin-bottom: 10px;
}
.wa-expired-bar p { margin: 0 0 10px; font-size: 13px; color: #92400e; font-weight: 500; }
.wa-expired-bar button {
    background: #16a34a;
    color: #fff;
    border: none;
    padding: 9px 22px;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    box-shadow: 0 1px 4px rgba(22,163,74,0.3);
}
.wa-expired-bar button:hover { background: #15803d; }
.wa-input-row { display: flex; align-items: flex-end; gap: 8px; }
.wa-text-input {
    flex: 1;
    padding: 10px 16px;
    border: none;
    border-radius: 22px;
    font-size: 14px;
    outline: none;
    resize: none;
    max-height: 100px;
    font-family: inherit;
    background: #fff;
    box-shadow: 0 1px 2px rgba(0,0,0,0.06);
}
.wa-text-input:focus { box-shadow: 0 0 0 2px rgba(22,163,74,0.15); }
.wa-send-btn {
    width: 42px;
    height: 42px;
    border-radius: 50%;
    background: #16a34a;
    border: none;
    color: #fff;
    font-size: 18px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.wa-send-btn:hover { background: #15803d; }
.wa-send-btn:disabled { background: #9ca3af; cursor: default; }
.wa-tpl-link { padding: 6px 0 0; }
.wa-tpl-link a {
    font-size: 12px;
    color: #16a34a;
    font-weight: 500;
    cursor: pointer;
    text-decoration: none;
}
.wa-tpl-link a:hover { text-decoration: underline; }

/* ── RIGHT: Orders Panel ── */
.wa-orders-panel {
    width: 0;
    min-width: 0;
    overflow: hidden;
    background: #fff;
    border-left: 1px solid #e5e7eb;
    display: flex;
    flex-direction: column;
    transition: width 0.25s ease, min-width 0.25s ease;
    flex-shrink: 0;
}
.wa-orders-panel.open {
    width: 300px;
    min-width: 300px;
}
.wa-op-hdr {
    padding: 10px 14px;
    border-bottom: 1px solid #e5e7eb;
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-shrink: 0;
    background: #fafafa;
}
.wa-op-hdr h4 { font-size: 13px; font-weight: 700; color: #111827; margin: 0; }
.wa-op-close { background: none; border: none; font-size: 18px; cursor: pointer; color: #6b7280; padding: 2px 6px; border-radius: 4px; }
.wa-op-close:hover { background: #f3f4f6; }
.wa-op-list {
    flex: 1;
    overflow-y: auto;
    padding: 0;
    scrollbar-width: thin;
}
.wa-op-item {
    padding: 10px 14px;
    border-bottom: 1px solid #f3f4f6;
    cursor: default;
    transition: background 0.15s;
}
.wa-op-item:hover { background: #f9fafb; }
.wa-op-num { font-weight: 700; font-size: 13px; color: #111827; }
.wa-op-date { font-size: 11px; color: #9ca3af; margin-left: 6px; }
.wa-op-total { font-weight: 600; font-size: 13px; color: #111827; }
.wa-op-status {
    display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 10px; font-weight: 600; text-transform: capitalize;
}
.wa-op-status.processing { background: #FEF3C7; color: #92400E; }
.wa-op-status.out_for_delivery, .wa-op-status.shipped { background: #DBEAFE; color: #1E40AF; }
.wa-op-status.delivered { background: #D1FAE5; color: #065F46; }
.wa-op-status.cancelled, .wa-op-status.refunded { background: #FEE2E2; color: #991B1B; }
.wa-op-status.pending { background: #F3F4F6; color: #6B7280; }
.wa-op-rider { font-size: 11px; color: #6b7280; margin-top: 2px; }
.wa-op-items { font-size: 11px; color: #9ca3af; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.wa-op-inv-btn {
    margin-top: 6px; padding: 4px 10px; background: #FFF7ED; border: 1px solid #FED7AA; border-radius: 6px;
    cursor: pointer; font-size: 11px; font-weight: 500; color: #9a3412; transition: all 0.15s;
}
.wa-op-inv-btn:hover { background: #FFEDD5; border-color: #F59E0B; }
.wa-op-toggle {
    position: absolute; right: 0; top: 50%; transform: translateY(-50%);
    background: #fff; border: 1px solid #e5e7eb; border-right: none; border-radius: 6px 0 0 6px;
    padding: 8px 4px; cursor: pointer; font-size: 12px; z-index: 5; box-shadow: -2px 0 4px rgba(0,0,0,0.06);
    display: none;
}
.wa-op-toggle:hover { background: #f0fdf4; }
@keyframes spin { to { transform: rotate(360deg); } }

/* ── Empty State ── */
.wa-empty {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: #f0f2f5;
    padding: 40px;
}
.wa-empty-icon {
    width: 200px;
    height: 200px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
}
.wa-empty-phone {
    width: 100px;
    height: 150px;
    background: #fff;
    border-radius: 16px;
    border: 2.5px solid #d1d5db;
    position: relative;
    box-shadow: 0 6px 24px rgba(0,0,0,0.08);
    overflow: hidden;
    z-index: 2;
}
.wa-empty-phone-top {
    height: 26px;
    background: linear-gradient(135deg, #16a34a, #15803d);
    display: flex;
    align-items: center;
    padding: 0 8px;
    gap: 4px;
}
.wa-empty-phone-top .dot { width: 14px; height: 14px; border-radius: 50%; background: rgba(255,255,255,0.35); }
.wa-empty-phone-top .bar { flex: 1; height: 5px; background: rgba(255,255,255,0.4); border-radius: 3px; }
.wa-empty-phone-body { padding: 6px; display: flex; flex-direction: column; gap: 5px; }
.wa-empty-bbl {
    border-radius: 6px;
    height: 8px;
}
.wa-empty-bbl.in { background: #fff; border: 1px solid #e5e7eb; align-self: flex-start; width: 55px; border-bottom-left-radius: 2px; }
.wa-empty-bbl.out { background: #d9fdd3; align-self: flex-end; width: 45px; border-bottom-right-radius: 2px; }
.wa-empty-bbl.in.lg { width: 65px; }
.wa-empty-ring {
    position: absolute;
    border-radius: 50%;
    border: 2px solid rgba(22,163,74,0.15);
    animation: ring-pulse 3s ease-in-out infinite;
}
.wa-empty-ring:nth-child(1) { width: 150px; height: 150px; }
.wa-empty-ring:nth-child(2) { width: 190px; height: 190px; animation-delay: 0.5s; }
@keyframes ring-pulse {
    0%, 100% { opacity: 0.3; transform: scale(0.95); }
    50% { opacity: 0.7; transform: scale(1.05); }
}
.wa-empty h3 { font-size: 22px; color: #374151; margin: 0 0 6px; font-weight: 700; }
.wa-empty p { font-size: 14px; margin: 0; color: #9ca3af; }

/* ── Load More ── */
.wa-load-more { text-align: center; padding: 10px; }
.wa-load-more a { font-size: 13px; color: #16a34a; font-weight: 500; cursor: pointer; text-decoration: none; }
.wa-load-more a:hover { text-decoration: underline; }

/* Conversation list "Load older chats" footer (Apr 2026 — see
   loadMoreConversations() in JS). Sits at the bottom of the inbox.
   Hidden during chat-search; the static end-state ditto. */
.wa-conv-loadmore {
    padding: 14px 16px 18px;
    text-align: center;
    border-top: 1px dashed #e5e7eb;
    background: #fafafa;
}
.wa-conv-loadmore-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 18px;
    background: #fff;
    border: 1px solid #16a34a;
    color: #15803d;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: background .15s, color .15s;
}
.wa-conv-loadmore-btn:hover:not(:disabled) {
    background: #16a34a;
    color: #fff;
}
.wa-conv-loadmore-btn:disabled {
    opacity: .7;
    cursor: progress;
}
.wa-conv-loadmore-spin {
    width: 12px; height: 12px;
    border: 2px solid #d1fae5;
    border-top-color: #15803d;
    border-radius: 50%;
    animation: waConvSpin .8s linear infinite;
}
@keyframes waConvSpin { to { transform: rotate(360deg); } }
.wa-conv-loadmore-hint {
    margin-top: 6px;
    font-size: 11px;
    color: #9ca3af;
}
.wa-conv-loadmore-end {
    padding: 10px 16px 18px;
    text-align: center;
    font-size: 11px;
    color: #9ca3af;
    border-top: 1px dashed #e5e7eb;
    background: #fafafa;
}

/* ── Loading ── */
.wa-loading {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px;
    color: #9ca3af;
    font-size: 14px;
    gap: 8px;
}

/* ── Modal Overlay ── */
.wa-modal-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.45);
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(3px);
    animation: mFadeIn 0.15s ease-out;
}
@keyframes mFadeIn { from { opacity: 0; } to { opacity: 1; } }
.wa-modal {
    background: #fff;
    border-radius: 16px;
    width: 520px;
    max-width: 92vw;
    max-height: 82vh;
    display: flex;
    flex-direction: column;
    box-shadow: 0 25px 60px -12px rgba(0,0,0,0.3);
    animation: mSlideIn 0.2s ease-out;
}
@keyframes mSlideIn {
    from { transform: translateY(20px) scale(0.97); opacity: 0; }
    to { transform: translateY(0) scale(1); opacity: 1; }
}
.wa-modal-hdr {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid #e5e7eb;
    flex-shrink: 0;
}
.wa-modal-hdr h3 { font-size: 17px; font-weight: 700; margin: 0; color: #111827; }
.wa-modal-x {
    font-size: 18px;
    color: #9ca3af;
    cursor: pointer;
    background: #f3f4f6;
    border: none;
    border-radius: 50%;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
}
.wa-modal-x:hover { background: #e5e7eb; color: #374151; }
.wa-modal-body {
    flex: 1;
    overflow-y: auto;
    padding: 16px 20px;
    scrollbar-width: thin;
    scrollbar-color: #d1d5db transparent;
}
.wa-modal-body::-webkit-scrollbar { width: 5px; }
.wa-modal-body::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 10px; }

/* ── Template Cards ── */
.wa-tpl-card {
    background: #fff;
    border: 1.5px solid #e5e7eb;
    border-radius: 12px;
    padding: 16px;
    margin-bottom: 12px;
    transition: all 0.15s;
}
.wa-tpl-card:hover { border-color: #86efac; box-shadow: 0 2px 12px rgba(22,163,74,0.08); }
.wa-tpl-name {
    font-size: 14px;
    font-weight: 700;
    color: #111827;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 6px;
}
.wa-tpl-name::before {
    content: '';
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #16a34a;
    flex-shrink: 0;
}
.wa-tpl-body {
    font-size: 13px;
    color: #374151;
    line-height: 1.5;
    white-space: pre-wrap;
    background: #f8fafc;
    padding: 10px 14px;
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    margin-bottom: 10px;
}
.wa-tpl-btns { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 10px; }
.wa-tpl-btn-tag {
    background: #f0fdf4;
    padding: 5px 14px;
    border-radius: 8px;
    font-size: 12px;
    color: #16a34a;
    font-weight: 600;
    border: 1px solid #bbf7d0;
}
.wa-tpl-params { display: flex; flex-direction: column; gap: 8px; margin-top: 8px; }
.wa-tpl-param-in {
    width: 100%;
    padding: 9px 14px;
    border: 1.5px solid #d1d5db;
    border-radius: 10px;
    font-size: 13px;
    outline: none;
    box-sizing: border-box;
}
.wa-tpl-param-in:focus { border-color: #16a34a; box-shadow: 0 0 0 3px rgba(22,163,74,0.08); }
.wa-tpl-send {
    width: 100%;
    padding: 10px;
    background: #16a34a;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    margin-top: 10px;
}
.wa-tpl-send:hover { background: #15803d; }

/* ── Template Manager ── */
.wa-mgr-info {
    margin-bottom: 16px;
    padding: 12px 14px;
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 10px;
    font-size: 13px;
    color: #166534;
    line-height: 1.4;
}
.wa-mgr-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 14px;
    background: #f9fafb;
    border: 1px solid #e5e7eb;
    border-radius: 10px;
    margin-bottom: 8px;
}
.wa-mgr-item:hover { border-color: #d1d5db; }
.wa-mgr-item-name { font-weight: 600; font-size: 14px; color: #111827; }
.wa-mgr-item-meta { font-size: 12px; color: #6b7280; margin-top: 2px; }
.wa-mgr-del {
    padding: 4px 10px;
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 6px;
    color: #dc2626;
    font-size: 12px;
    cursor: pointer;
    flex-shrink: 0;
}
.wa-mgr-del:hover { background: #fee2e2; }
.wa-mgr-divider {
    border-top: 1px solid #e5e7eb;
    padding-top: 16px;
    margin-top: 16px;
}
.wa-mgr-divider h4 { font-size: 15px; font-weight: 700; margin: 0 0 12px; color: #111827; }
.wa-mgr-grid { display: grid; gap: 10px; margin-bottom: 10px; }
.wa-mgr-grid.cols-2 { grid-template-columns: 1fr 1fr; }
.wa-mgr-grid.cols-3 { grid-template-columns: 1fr 1fr 1fr; }
.wa-mgr-label { font-size: 12px; font-weight: 600; color: #374151; display: block; margin-bottom: 4px; }
.wa-mgr-input {
    width: 100%;
    padding: 8px 12px;
    border: 1.5px solid #d1d5db;
    border-radius: 8px;
    font-size: 13px;
    outline: none;
    box-sizing: border-box;
    font-family: inherit;
}
.wa-mgr-input:focus { border-color: #16a34a; box-shadow: 0 0 0 3px rgba(22,163,74,0.08); }
textarea.wa-mgr-input { resize: vertical; }
select.wa-mgr-input { background: #fff; cursor: pointer; }
.wa-mgr-save {
    width: 100%;
    padding: 11px;
    background: #16a34a;
    color: #fff;
    border: none;
    border-radius: 10px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    margin-top: 4px;
}
.wa-mgr-save:hover { background: #15803d; }

/* Template-scope radio: highlight the selected segment */
.tpl-scope-opt { transition: background .12s, border-color .12s, box-shadow .12s; }
.tpl-scope-opt:hover { border-color: #cbd5e1; }
.tpl-scope-opt.tpl-scope-selected[data-scope="common"]  { border-color: #94a3b8; background: #f1f5f9; box-shadow: 0 0 0 1px #94a3b8 inset; }
.tpl-scope-opt.tpl-scope-selected[data-scope="regular"] { border-color: #2563eb; background: #eff6ff; box-shadow: 0 0 0 1px #2563eb inset; }
.tpl-scope-opt.tpl-scope-selected[data-scope="qurbani"] { border-color: #d97706; background: #fffbeb; box-shadow: 0 0 0 1px #d97706 inset; }

/* ── Responsive ── */
@media (max-width: 768px) {
    .wa-sidebar { width: 100%; min-width: auto; max-width: none; }
    .wa-chat {
        position: fixed;
        top: 0; left: 0; right: 0; bottom: 0;
        z-index: 50;
    }
    .wa-chat-back { display: flex; }
    .wa-empty { display: none !important; }
    .wa-chat-msgs { padding: 16px; }
    .wa-chat-foot { padding: 10px 12px; }
    .wa-msg { max-width: 80%; }
    .wa-orders-panel, #waOrdersToggle { display: none !important; }
}
@media (min-width: 769px) {
    .wa-chat-back { display: none; }
}
</style>
@endpush

@section('content')
<div class="wa-page" id="waApp">

    <!-- ═══ LEFT: Sidebar ═══ -->
    <div class="wa-sidebar" id="waSidebar">
        <div class="wa-side-hdr">
            <div class="wa-side-hdr-top">
                <h2>Messages</h2>
                <div class="wa-side-hdr-actions">
                    <button class="wa-btn wa-btn-green" id="waNewMsgBtn" onclick="toggleNewMessage()">+ New</button>
                    {{-- Apr-2026: management buttons moved up here (out of the
                         filter row below) so they're always reachable even
                         when the filter strip is full. Templates already
                         lived here; Qurbani-tab settings and Auto-reply now
                         join it. Permissions match what the filter row used
                         to enforce. --}}
                    <button class="wa-btn wa-btn-gray" onclick="openTemplateManager()" title="Manage Templates">⚙</button>
                    @if(!(($waIsLimited ?? false)))
                    <button class="wa-btn wa-btn-gray" onclick="openQurbaniSettings()" title="Qurbani tab settings">🐐</button>
                    @endif
                    @if(($waCanManageAutoReply ?? false))
                    <button class="wa-btn wa-btn-gray" onclick="openAutoReplySettings()" title="Auto-reply rules (out-of-hours, day-off, custom)">🤖</button>
                    @endif
                </div>
            </div>
            <div class="wa-search-wrap">
                <svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor"><path d="M11.742 10.344a6.5 6.5 0 1 0-1.397 1.398l3.85 3.85a1 1 0 0 0 1.415-1.414l-3.868-3.834zm-5.44.856a4.8 4.8 0 1 1 0-9.6 4.8 4.8 0 0 1 0 9.6z"/></svg>
                <input type="text" class="wa-search" id="waSearch" placeholder="Search name, phone or city..." />
            </div>
            {{-- Search-mode toggle. Defaults to "Names" for backward-compat
                 with how the search has always worked; "Chats" searches the
                 actual message content (see WhatsAppWebController@getConversations
                 with search_mode=chats). Kept tiny so it doesn't steal space
                 from the filter row below. --}}
            <div class="wa-search-mode" role="tablist" aria-label="Search mode" style="display:flex;gap:4px;margin-top:6px;">
                <button type="button" class="wa-searchmode-btn active" data-mode="customers" role="tab" aria-selected="true">👤 Names</button>
                <button type="button" class="wa-searchmode-btn" data-mode="chats" role="tab" aria-selected="false" title="Search inside message contents">💬 Chats</button>
            </div>
            <div class="wa-filters">
                <button class="wa-filter-btn active" data-filter="all">All</button>
                <button class="wa-filter-btn" data-filter="unread">Unread</button>
                <button class="wa-filter-btn" data-filter="qurbani" id="waFilterQurbani" style="display:none;" title="Conversations auto-flagged as Qurbani">🐐 Qurbani</button>
                <!-- Label filter: picks one label id to narrow the inbox. Uses a
                     dropdown instead of one pill per label so the strip doesn't
                     explode if the workspace has many labels. -->
                <div class="wa-label-filter-wrap">
                    <button class="wa-label-filter-btn" id="waLabelFilterBtn" onclick="toggleLabelFilter()" title="Filter by label">🏷️ Label</button>
                    <div class="wa-label-filter-menu" id="waLabelFilterMenu"></div>
                </div>
                {{-- Phase 2 — @me: only conversations where the current user
                     has an unread mention. Tiny red dot appears when there
                     are any unread mentions regardless of toggle state. --}}
                <button class="wa-filter-btn wa-filter-me" id="waFilterMe" onclick="toggleAssignedToMe()" title="Conversations you were tagged in">
                    <span>@ me</span>
                    <span class="wa-mention-pip" id="waMentionPip" style="display:none;"></span>
                </button>
                {{-- Apr-2026: ⚙ Qurbani-settings and 🤖 Auto-reply buttons used
                     to live here; they were clipped at narrow widths because
                     the filter row had no overflow handling and adding any
                     would have clipped the Label-filter popover. They were
                     moved to .wa-side-hdr-actions above (next to +New /
                     Templates) where they belong as management actions
                     rather than filters. --}}
            </div>
            @if(($waIsLimited ?? false))
            <div style="margin-top:8px;padding:7px 10px;background:#fef3c7;border:1px solid #fde68a;border-radius:6px;font-size:11px;color:#92400e;display:flex;align-items:center;gap:6px;line-height:1.35;" title="Your account has Limited Messages access. Older conversations are hidden.">
                <span>⚠</span>
                <span><strong>Limited view</strong> — showing messages from today and yesterday only.</span>
            </div>
            @endif
            {{-- Apr-2026: Mark-All-Read action row.
                 Visibility rules:
                   - Server gate: only rendered for users where
                     WhatsAppService::isSuperReader() returns true (i.e.
                     the Taimur role with the `whatsapp_super_reader`
                     permission). Regular users will never see this even
                     by inspecting the DOM, since the wrapper div isn't
                     emitted at all for them.
                   - Client gate: hidden until the operator switches to
                     the Unread tab — there's nothing to "mark all read"
                     on the All / @me / Qurbani / label tabs.
                 Action: posts to /messages/mark-all-read which stamps
                 global_read_at on every currently-unread conversation, so
                 the inbox is cleared for the whole team in one shot. --}}
            @if(($waIsSuperReader ?? false))
            <div class="wa-mark-all-read-row" id="waMarkAllReadRow" style="display:none;margin-top:8px;">
                <button type="button" class="wa-mark-all-read-btn" id="waMarkAllReadBtn" onclick="confirmMarkAllRead()">
                    <span>✓</span>
                    <span>Mark all <strong id="waMarkAllReadCount"></strong> as read</span>
                </button>
                <div class="wa-mark-all-read-hint">Clears the inbox for everyone.</div>
            </div>
            @endif
        </div>

        <!-- New message panel -->
        <div id="waNewMsgPanel" class="wa-new-panel">
            <div class="wa-new-panel-hdr">
                <span>New Message — Search Customer</span>
                <button class="wa-new-panel-close" onclick="toggleNewMessage()">&times;</button>
            </div>
            <input type="text" id="waCustomerSearch" class="wa-new-input" placeholder="Search customer name or phone..." />
            <div id="waCustomerResults" class="wa-cust-results"></div>
        </div>

        <div class="wa-conv-list" id="waConvList">
            <div class="wa-loading">Loading conversations...</div>
        </div>
    </div>

    <!-- ═══ RIGHT: Chat Panel ═══ -->
    <div class="wa-chat" id="waChat">
        <div class="wa-chat-hdr" id="waChatHeader">
            <button class="wa-chat-back" id="waChatBack" title="Back">←</button>
            <div class="wa-chat-avatar" id="waChatAvatar"></div>
            <div class="wa-chat-hdr-info">
                <h3 id="waChatName"></h3>
                <p id="waChatSub"></p>
            </div>
            <div class="wa-session-badge" id="waSessionBadge" style="display:none;">24h expired</div>
            <button id="waOrdersToggle" onclick="toggleOrdersPanel()" style="background:rgba(255,255,255,0.2);border:none;border-radius:6px;padding:6px 10px;cursor:pointer;color:#fff;font-size:12px;font-weight:500;display:none;margin-left:8px;white-space:nowrap;" title="Customer Orders">📋 Orders</button>
            <div class="wa-chat-menu-wrap">
                <button class="wa-chat-menu-btn" id="waChatMenuBtn" title="More actions" onclick="toggleChatMenu()">⋮</button>
                <div class="wa-chat-menu" id="waChatMenu">
                    <button onclick="doMarkUnread()" title="Mark this conversation as unread">
                        <span>📩</span> Mark as Unread
                    </button>
                    <button onclick="openLabelsModal()" title="Apply or manage labels">
                        <span>🏷️</span> Labels…
                    </button>
                </div>
            </div>
        </div>
        <!-- Labels row under the header — shows labels applied to the active
             conversation. Hidden when empty via CSS. -->
        <div class="wa-chat-hdr-labels" id="waChatHdrLabels"></div>
        <!-- Marketing-template pinned indicator — shows marketing-category
             templates sent to this customer in the last N days (see
             add_marketing_dedup_apr2026.sql). Hidden when empty via CSS. -->
        <div class="wa-chat-hdr-marketing" id="waChatHdrMarketing"></div>
        {{-- Phase 4 (May-2026) — Active Delivery Banner.
             Surfaces a one-line summary when this customer has any
             order currently OUT FOR DELIVERY (regular OR qurbani),
             with the live ETA and how many stops are ahead in the
             rider's route. Refreshed on every loadOrdersPanel() call
             (i.e. piggybacks on the existing customer-orders poll —
             no extra HTTP traffic). Hidden when empty by default via
             inline display:none. --}}
        <div id="waActiveDeliveryBanner" style="display:none;background:linear-gradient(135deg,#dbeafe,#eff6ff);border-bottom:1px solid #bfdbfe;padding:8px 14px;font-size:12.5px;color:#1e3a8a;cursor:pointer;" title="Click for details"></div>
        {{-- Aug-2026 — "a pin from this customer is waiting". Sits above the
             message list because the hint under the bubble scrolls out of view,
             which is exactly how the Aug-2 pin went unnoticed until after the
             order had shipped. Filled by renderPinPendingBar(). --}}
        <div id="waPinPendingBar" style="display:none;"></div>
        {{-- Oct-2026 — tag follow-up: shown ONLY to the person who tagged someone on the
             tag-alert list (Taimur) on this chat — "⏳ Taimur hasn't opened this · 25 min"
             with I'll call him / Wait 10 min / Stop reminders. Filled by renderTagWaitBar(). --}}
        <div id="waTagWaitBar" style="display:none;"></div>
        <div class="wa-chat-msgs" id="waChatMessages">
            <div class="wa-loading">Loading messages...</div>
        </div>
        <div class="wa-chat-foot" id="waChatInput">
            <div id="waSessionExpiredBar" class="wa-expired-bar" style="display:none;">
                <p>The 24-hour messaging window has expired.</p>
                <div style="display:flex;gap:8px;">
                    <button onclick="openTemplatePicker()">Send Template Message</button>
                    <button onclick="openInvoicePicker()" style="background:#f59e0b;color:#fff;">📄 Send Invoice</button>
                </div>
            </div>
            <div id="waActiveSessionInput">
                <div id="waSessionTimer" class="wa-session-timer" style="display:none;"></div>
                <div class="wa-input-row">
                    <textarea class="wa-text-input" id="waMessageInput" rows="1" placeholder="Type a message..." maxlength="4096"></textarea>
                    <button class="wa-send-btn" id="waSendBtn" title="Send">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z" fill="currentColor"/></svg>
                    </button>
                </div>
                <div class="wa-tpl-link" style="display:flex;gap:12px;">
                    <a onclick="openTemplatePicker()">📋 Templates</a>
                    <a onclick="openInvoicePicker()" style="color:#d97706;cursor:pointer;">📄 Send Invoice</a>
                    {{-- Aug-2026: manual image attachment (session messages only —
                         the 24h-window bar above replaces this whole block when the
                         window has closed, so no expiry guard is needed here). --}}
                    <a onclick="document.getElementById('waAttachInput').click()" style="color:#2563eb;cursor:pointer;">📎 Attach Image</a>
                </div>
                <input type="file" id="waAttachInput" accept="image/jpeg,image/png" style="display:none;" onchange="waAttachPicked(this)">
            </div>
        </div>
    </div>

    <!-- ═══ ORDERS PANEL (collapsible right sidebar) ═══ -->
    <div class="wa-orders-panel" id="waOrdersPanel">
        <div class="wa-op-hdr">
            <h4>📋 Customer Orders</h4>
            <button class="wa-op-close" onclick="toggleOrdersPanel()" title="Close">&times;</button>
        </div>
        <div class="wa-op-list" id="waOrdersList">
            <div style="padding:20px;text-align:center;color:#9ca3af;font-size:13px;">Select a conversation to see orders</div>
        </div>
    </div>

    <!-- ═══ RIGHT: Empty State ═══ -->
    <div class="wa-empty" id="waEmptyState">
        <div class="wa-empty-icon">
            <div class="wa-empty-ring"></div>
            <div class="wa-empty-ring"></div>
            <div class="wa-empty-phone">
                <div class="wa-empty-phone-top">
                    <span class="dot"></span>
                    <span class="bar"></span>
                </div>
                <div class="wa-empty-phone-body">
                    <div class="wa-empty-bbl in lg"></div>
                    <div class="wa-empty-bbl out"></div>
                    <div class="wa-empty-bbl in"></div>
                    <div class="wa-empty-bbl out"></div>
                </div>
            </div>
        </div>
        <h3>Start a conversation</h3>
        <p>Select a customer from the left or tap <strong>+ New</strong> to begin</p>
    </div>
</div>

<!-- ═══ MODAL: Qurbani Tab Settings ═══ -->
<div class="wa-modal-overlay" id="waQurbaniSettingsModal" style="display:none;" onclick="if(event.target===this)closeQurbaniSettings()">
    <div class="wa-modal" style="width:560px;max-width:94vw;">
        <div class="wa-modal-hdr">
            <h3>🐐 Qurbani Tab Settings</h3>
            <button class="wa-modal-x" onclick="closeQurbaniSettings()">✕</button>
        </div>
        <div class="wa-modal-body" style="padding:18px;">
            <p style="font-size:13px;color:#6b7280;margin-top:0;">
                Conversations are auto-tagged as Qurbani when the customer has a Qurbani
                order for the active year, a Qurbani-only template was sent to them, or
                any of the last few inbound messages contains a keyword below.
            </p>

            <label style="display:flex;align-items:center;gap:8px;padding:10px 12px;background:#f0fdf4;border:1px solid #86efac;border-radius:8px;margin-bottom:14px;cursor:pointer;">
                <input type="checkbox" id="qcfgEnabled" style="transform:scale(1.2);" />
                <span style="font-weight:600;color:#166534;">Enable Qurbani tab &amp; goat badge</span>
            </label>

            <div id="qcfgFields">
                <div style="margin-bottom:12px;">
                    <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Keywords (comma-separated)</label>
                    <textarea id="qcfgKeywords" rows="3" style="width:100%;padding:8px 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;font-family:inherit;box-sizing:border-box;" placeholder="qurbani, eid, bakra, goat, hissa, ..."></textarea>
                    <div style="font-size:11px;color:#9ca3af;margin-top:3px;">Case-insensitive, matched anywhere in the message body. "beef" is deliberately excluded because it overlaps with regular meat orders.</div>
                </div>

                <div style="display:flex;gap:12px;margin-bottom:12px;">
                    <div style="flex:1;">
                        <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Messages to scan</label>
                        <input type="number" id="qcfgLookback" min="1" max="20" style="width:100%;padding:8px 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;box-sizing:border-box;" />
                        <div style="font-size:11px;color:#9ca3af;margin-top:3px;">How many recent inbound messages to check per customer (1–20).</div>
                    </div>
                    <div style="flex:1;">
                        <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Active Qurbani year</label>
                        <input type="number" id="qcfgYear" min="2000" max="2100" placeholder="{{ date('Y') }}" style="width:100%;padding:8px 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;box-sizing:border-box;" />
                        <div style="font-size:11px;color:#9ca3af;margin-top:3px;">Leave blank to use the current year.</div>
                    </div>
                </div>
            </div>

            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:18px;padding-top:14px;border-top:1px solid #e5e7eb;">
                <button onclick="rescanQurbani()" id="qcfgRescanBtn" class="wa-btn wa-btn-gray" style="padding:8px 14px;" title="Re-classify all existing conversations using the current settings">🔄 Rescan all conversations</button>
                <div style="display:flex;gap:8px;">
                    <button onclick="closeQurbaniSettings()" class="wa-btn wa-btn-gray" style="padding:8px 16px;">Cancel</button>
                    <button onclick="saveQurbaniSettings()" id="qcfgSaveBtn" class="wa-btn wa-btn-green" style="padding:8px 16px;">Save</button>
                </div>
            </div>

            <div id="qcfgStatus" style="margin-top:10px;font-size:12px;color:#6b7280;min-height:16px;"></div>
        </div>
    </div>
</div>

{{-- ═══ MODAL: WhatsApp Auto-Reply settings (Apr-2026) ═══
     Two panes: rules list on the left, editor form on the right. The
     "+ New Rule" button on the list switches the form into create mode.
     Saving / deleting in the form refreshes the list without a full
     re-fetch. Master toggle lives at the top so an operator can silence
     the entire feature in one click during an event. --}}
@if(($waCanManageAutoReply ?? false))
<div class="wa-modal-overlay" id="waAutoReplyModal" style="display:none;" onclick="if(event.target===this)closeAutoReplySettings()">
    <div class="wa-modal" style="width:880px;max-width:96vw;max-height:90vh;display:flex;flex-direction:column;">
        <div class="wa-modal-hdr">
            <h3>🤖 WhatsApp Auto-Reply</h3>
            <button class="wa-modal-x" onclick="closeAutoReplySettings()">✕</button>
        </div>
        <div class="wa-modal-body" style="padding:0;display:flex;flex-direction:column;flex:1;min-height:0;">
            {{-- ═══ Tab bar (Jun-2026): Out-of-office | Order actions | Invoice ═══ --}}
            <div style="display:flex;gap:2px;padding:8px 12px 0;border-bottom:1px solid #e5e7eb;background:#fff;">
                <button type="button" id="waTabBtn-ooo" onclick="switchAutoTab('ooo')" style="background:none;border:none;border-bottom:2px solid #16a34a;color:#166534;font-weight:600;font-size:13px;padding:8px 12px;cursor:pointer;">Out-of-office</button>
                <button type="button" id="waTabBtn-order" onclick="switchAutoTab('order')" style="background:none;border:none;border-bottom:2px solid transparent;color:#6b7280;font-weight:500;font-size:13px;padding:8px 12px;cursor:pointer;">Order actions</button>
                <button type="button" id="waTabBtn-invoice" onclick="switchAutoTab('invoice')" style="background:none;border:none;border-bottom:2px solid transparent;color:#6b7280;font-weight:500;font-size:13px;padding:8px 12px;cursor:pointer;">Invoice &amp; dispatch</button>
                <button type="button" id="waTabBtn-shift" onclick="switchAutoTab('shift')" style="background:none;border:none;border-bottom:2px solid transparent;color:#6b7280;font-weight:500;font-size:13px;padding:8px 12px;cursor:pointer;">Shifts</button>
                <button type="button" id="waTabBtn-templates" onclick="waOpenTemplatesTab()" style="background:none;border:none;border-bottom:2px solid transparent;color:#6b7280;font-weight:500;font-size:13px;padding:8px 12px;cursor:pointer;margin-left:auto;">📄 Templates</button>
            </div>

            {{-- ═══ PANEL: Out-of-office (the existing auto-reply UI) ═══ --}}
            <div id="waTab-ooo" style="display:flex;flex-direction:column;flex:1;min-height:0;">
            {{-- Help strip + master toggle. Help text is intentionally short
                 (one line) so it doesn't push the rule list off the screen. --}}
            <div style="padding:12px 16px;border-bottom:1px solid #e5e7eb;background:#fafafa;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
                <div style="font-size:12px;color:#6b7280;flex:1;min-width:200px;line-height:1.4;">
                    Replies fire on inbound messages, inside the WhatsApp 24h customer-care window.
                    No template approval needed — text is sent as a free-form session message.
                </div>
                <label style="display:flex;align-items:center;gap:8px;padding:6px 12px;background:#ecfdf5;border:1px solid #86efac;border-radius:8px;cursor:pointer;font-weight:600;color:#166534;">
                    <input type="checkbox" id="arMasterToggle" style="transform:scale(1.2);" onchange="toggleAutoReplyMaster(this.checked)" />
                    <span id="arMasterLabel">Auto-reply: Off</span>
                </label>
            </div>

            <div style="display:flex;flex:1;min-height:0;">
                {{-- LEFT: rule list --}}
                <div style="width:260px;border-right:1px solid #e5e7eb;display:flex;flex-direction:column;background:#f9fafb;">
                    <div style="padding:10px;border-bottom:1px solid #e5e7eb;display:flex;gap:6px;">
                        <button class="wa-btn wa-btn-green" style="flex:1;padding:6px 10px;font-size:12px;" onclick="newAutoReplyRule()">+ New Rule</button>
                    </div>
                    <div id="arRuleList" style="flex:1;overflow-y:auto;"></div>
                </div>

                {{-- RIGHT: editor --}}
                <div id="arEditorPane" style="flex:1;overflow-y:auto;padding:16px;">
                    <div id="arEmptyHint" style="text-align:center;color:#9ca3af;padding:60px 20px;font-size:13px;">
                        Select a rule on the left to edit, or click <strong>+ New Rule</strong> to add one.
                    </div>
                    <div id="arForm" style="display:none;">
                        <input type="hidden" id="arRuleId" value="" />

                        <div style="margin-bottom:12px;">
                            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Rule name</label>
                            <input type="text" id="arName" maxlength="150" placeholder="e.g. Out of working hours" style="width:100%;padding:8px 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;box-sizing:border-box;" />
                        </div>

                        <div style="margin-bottom:12px;">
                            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Reply message</label>
                            <textarea id="arMessage" rows="4" maxlength="4000" placeholder="Thanks for your message! We're currently away from our desks. We'll get back to you as soon as we're back online." style="width:100%;padding:8px 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;font-family:inherit;box-sizing:border-box;"></textarea>
                            <div style="font-size:11px;color:#9ca3af;margin-top:3px;">Sent verbatim. WhatsApp formatting works (*bold*, _italic_, ~strike~).</div>
                        </div>

                        <div style="display:flex;gap:12px;margin-bottom:12px;">
                            <div style="flex:1;">
                                <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Trigger</label>
                                <select id="arMatchMode" onchange="onArMatchModeChange()" style="width:100%;padding:8px 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;background:white;">
                                    <option value="time_window">Time window (hours/days)</option>
                                    <option value="specific_dates">Specific dates (e.g. holidays)</option>
                                    <option value="always">Always (catch-all)</option>
                                </select>
                            </div>
                            <div style="flex:1;">
                                <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Priority</label>
                                <input type="number" id="arPriority" min="0" max="9999" value="100" style="width:100%;padding:8px 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;box-sizing:border-box;" />
                                <div style="font-size:11px;color:#9ca3af;margin-top:3px;">Lower runs first.</div>
                            </div>
                            <div style="flex:1;">
                                <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Send how often</label>
                                <select id="arCooldownMode" onchange="onArCooldownModeChange()" style="width:100%;padding:8px 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;background:white;">
                                    <option value="daily">Once per customer / day</option>
                                    <option value="once">Once per customer (whole period)</option>
                                    <option value="rolling">Every N hours</option>
                                </select>
                                <div id="arCooldownHoursWrap" style="display:none;margin-top:6px;">
                                    <input type="number" id="arCooldown" min="1" max="168" value="6" placeholder="hours" style="width:100%;padding:8px 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;box-sizing:border-box;" />
                                    <div style="font-size:11px;color:#9ca3af;margin-top:3px;">Hours between replies.</div>
                                </div>
                            </div>
                        </div>

                        {{-- Active date range (optional). Lets a holiday rule auto-expire
                             instead of firing forever — the fix for the "always" Eid trap. --}}
                        <div style="display:flex;gap:12px;margin-bottom:12px;">
                            <div style="flex:1;">
                                <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Active from</label>
                                <input type="date" id="arActiveFrom" style="width:100%;padding:8px 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;box-sizing:border-box;" />
                            </div>
                            <div style="flex:1;">
                                <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Active until</label>
                                <input type="date" id="arActiveTo" style="width:100%;padding:8px 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;box-sizing:border-box;" />
                            </div>
                            <div style="flex:1;display:flex;align-items:flex-end;">
                                <div style="font-size:11px;color:#9ca3af;line-height:1.4;">Optional. Blank = runs indefinitely. Set an end date for holidays so the rule stops itself.</div>
                            </div>
                        </div>

                        {{-- Time-window-specific fields --}}
                        <div id="arTimeWindowFields">
                            <div style="margin-bottom:12px;">
                                <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;">Days of week</label>
                                <div id="arDaysRow" style="display:flex;gap:6px;flex-wrap:wrap;">
                                    {{-- 0=Sun … 6=Sat to match JS Date.getDay() --}}
                                    @foreach(['Sun'=>0,'Mon'=>1,'Tue'=>2,'Wed'=>3,'Thu'=>4,'Fri'=>5,'Sat'=>6] as $lab=>$idx)
                                        <label style="display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:12px;cursor:pointer;background:white;">
                                            <input type="checkbox" class="ar-day" data-day="{{ $idx }}" />
                                            <span>{{ $lab }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <div style="font-size:11px;color:#9ca3af;margin-top:3px;">Leave all unchecked = every day.</div>
                            </div>

                            <div style="display:flex;gap:12px;margin-bottom:12px;">
                                <div style="flex:1;">
                                    <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Start time</label>
                                    <input type="time" id="arStart" style="width:100%;padding:8px 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;box-sizing:border-box;" />
                                </div>
                                <div style="flex:1;">
                                    <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">End time</label>
                                    <input type="time" id="arEnd" style="width:100%;padding:8px 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;box-sizing:border-box;" />
                                </div>
                            </div>
                            <div style="font-size:11px;color:#6b7280;background:#fffbeb;border:1px solid #fde68a;border-radius:6px;padding:6px 10px;line-height:1.4;">
                                <strong>Tip — out-of-hours setup:</strong> set Start to your closing time (e.g. 18:00) and End to opening (e.g. 09:00). Crossing midnight is supported automatically. Leave both blank to fire all-day on the selected weekdays.
                            </div>
                        </div>

                        {{-- Specific-dates-specific fields --}}
                        <div id="arSpecificDatesFields" style="display:none;">
                            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:4px;">Dates (one per line, YYYY-MM-DD)</label>
                            <textarea id="arSpecificDates" rows="4" placeholder="2026-12-25&#10;2026-12-26" style="width:100%;padding:8px 10px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;font-family:monospace;box-sizing:border-box;"></textarea>
                            <div style="font-size:11px;color:#9ca3af;margin-top:3px;">Fires on these dates only, regardless of time of day. Useful for fixed holidays.</div>
                        </div>

                        <label style="display:flex;align-items:center;gap:8px;margin-top:12px;padding:8px 12px;background:#f0fdf4;border:1px solid #86efac;border-radius:6px;cursor:pointer;">
                            <input type="checkbox" id="arEnabled" />
                            <span style="font-weight:600;color:#166534;font-size:13px;">Rule active</span>
                        </label>

                        <div id="arStatus" style="margin-top:10px;font-size:12px;color:#6b7280;min-height:16px;"></div>

                        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;padding-top:14px;border-top:1px solid #e5e7eb;">
                            <button id="arDeleteBtn" onclick="deleteAutoReplyRule()" class="wa-btn" style="padding:7px 14px;background:#fee2e2;border:1px solid #fecaca;color:#b91c1c;font-size:12px;display:none;">🗑 Delete</button>
                            <div style="display:flex;gap:8px;margin-left:auto;">
                                <button onclick="cancelAutoReplyEdit()" class="wa-btn wa-btn-gray" style="padding:7px 16px;">Cancel</button>
                                <button onclick="saveAutoReplyRule()" id="arSaveBtn" class="wa-btn wa-btn-green" style="padding:7px 16px;">Save</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            </div>{{-- /#waTab-ooo --}}

            {{-- ═══ PANEL: Order actions ═══ --}}
            <div id="waTab-order" style="display:none;flex:1;overflow-y:auto;padding:16px;">
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px;padding:10px 12px;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;">
                    <label style="display:flex;align-items:center;gap:8px;padding:5px 10px;background:#ecfdf5;border:1px solid #86efac;border-radius:8px;cursor:pointer;font-weight:600;color:#166534;font-size:13px;">
                        <input type="checkbox" id="waAutoMaster" onchange="toggleAutoMaster(this.checked)" /> <span id="waAutoMasterLabel">Automations: Off</span>
                    </label>
                    <div style="display:flex;align-items:center;gap:6px;font-size:12px;color:#6b7280;">
                        <span>🧪 Test to my number:</span>
                        <input type="text" id="waAutoTestPhone" placeholder="blank = real customers" style="padding:6px 8px;border:1px solid #e5e7eb;border-radius:6px;font-size:12px;width:150px;" />
                        <button class="wa-btn wa-btn-gray" style="padding:5px 10px;font-size:12px;" onclick="saveAutoTestPhone()">Save</button>
                    </div>
                    <button class="wa-btn wa-btn-gray" style="margin-left:auto;padding:5px 10px;font-size:12px;" onclick="toggleAutoLog()">📋 Activity</button>
                </div>
                <div id="waAutoLog" style="display:none;margin-bottom:14px;"></div>
                <div style="font-size:12px;color:#6b7280;background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:8px 12px;margin-bottom:12px;line-height:1.4;">
                    <strong>Note:</strong> the order-received rules currently fire for <strong>Shopify-received orders only</strong> — not orders you create in your own open-orders system. (We can extend them to those later.)
                </div>
                <div id="waAutoOrderCards"><div style="color:#9ca3af;font-size:13px;padding:30px;text-align:center;">Loading…</div></div>
            </div>

            {{-- ═══ PANEL: Invoice & dispatch ═══ --}}
            <div id="waTab-invoice" style="display:none;flex:1;overflow-y:auto;padding:16px;">
                <div id="waAutoInvoiceCards"><div style="color:#9ca3af;font-size:13px;padding:30px;text-align:center;">Loading…</div></div>
            </div>

            {{-- ═══ PANEL: Shifts ═══ --}}
            <div id="waTab-shift" style="display:none;flex:1;overflow-y:auto;padding:16px;">
                <div style="font-size:12px;color:#6b7280;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:8px 12px;margin-bottom:12px;line-height:1.4;">
                    <strong>Note:</strong> when you turn this on, riders get a WhatsApp with a <strong>confirm</strong> button whenever their shift changes. Tapping confirm marks it accepted — the same as confirming in the app. Uses the <code>shift_reminder</code> template; the master switch and 🧪 test number above apply here too.
                </div>
                <div id="waAutoShiftCards"><div style="color:#9ca3af;font-size:13px;padding:30px;text-align:center;">Loading…</div></div>
            </div>
        </div>
    </div>
</div>
@endif

<!-- ═══ MODAL: Template Picker ═══ -->
<div class="wa-modal-overlay" id="waTemplateModal" style="display:none;" onclick="if(event.target===this)closeTemplatePicker()">
    <div class="wa-modal">
        <div class="wa-modal-hdr">
            <h3>Send Template</h3>
            <button class="wa-modal-x" onclick="closeTemplatePicker()">✕</button>
        </div>
        <div class="wa-modal-body" id="waTemplateList">
            <div class="wa-loading">Loading templates...</div>
        </div>
    </div>
</div>

<!-- ═══ MODAL: Template Manager ═══ -->
<div class="wa-modal-overlay" id="waTemplateManager" style="display:none;" onclick="if(event.target===this)closeTemplateManager()">
    <div class="wa-modal" style="width:640px;">
        <div class="wa-modal-hdr">
            <h3>Manage Templates</h3>
            <button class="wa-modal-x" onclick="closeTemplateManager()">✕</button>
        </div>
        <div class="wa-modal-body" id="waTemplateManagerBody">
            <div class="wa-mgr-info">
                Add your WhatsApp Business approved templates here. These will appear as options when sending messages.
            </div>
            <div id="waExistingTemplates"></div>
            <div class="wa-mgr-divider">
                <div id="tplFormBanner" style="display:none;margin-bottom:10px;padding:8px 12px;background:#fef3c7;border:1px solid #fde68a;border-radius:8px;font-size:12px;color:#92400e;">
                    You are editing an existing template. Click <b>Cancel</b> to discard changes.
                </div>
                <h4 id="tplFormHeading">Add New Template</h4>
                <div class="wa-mgr-grid cols-2">
                    <div>
                        <label class="wa-mgr-label">Template Name (exact match from WhatsApp)</label>
                        <input id="tplName" class="wa-mgr-input" placeholder="e.g. capacity_full" />
                    </div>
                    <div>
                        <label class="wa-mgr-label">Display Name</label>
                        <input id="tplDisplayName" class="wa-mgr-input" placeholder="e.g. Capacity Full - Tomorrow" />
                    </div>
                </div>
                <div style="margin-bottom:10px;">
                    <label class="wa-mgr-label">Body Text (use {{1}}, {{2}} for variables)</label>
                    <textarea id="tplBody" rows="3" class="wa-mgr-input" placeholder="Dear {{1}}, your order {{2}} is ready..."></textarea>
                </div>
                <div class="wa-mgr-grid cols-3">
                    <div>
                        <label class="wa-mgr-label">Category</label>
                        <select id="tplCategory" class="wa-mgr-input">
                            <option value="utility">Utility</option>
                            <option value="marketing">Marketing</option>
                        </select>
                    </div>
                    <div>
                        <label class="wa-mgr-label">Variable Count</label>
                        <input id="tplVarCount" type="number" min="0" max="10" value="0" class="wa-mgr-input" />
                    </div>
                    <div>
                        <label class="wa-mgr-label">Has Buttons?</label>
                        <select id="tplHasButtons" class="wa-mgr-input">
                            <option value="0">No</option>
                            <option value="1">Yes</option>
                        </select>
                    </div>
                </div>
                <div id="tplButtonLabelsDiv" style="display:none;margin-bottom:10px;">
                    <label class="wa-mgr-label">Button Labels (comma separated)</label>
                    <input id="tplButtonLabels" class="wa-mgr-input" placeholder="Yes, deliver tomorrow, Cancel order" />
                </div>
                <div class="wa-mgr-grid cols-2" style="margin-bottom:12px;">
                    <div>
                        <label class="wa-mgr-label">Header Text (optional)</label>
                        <input id="tplHeader" class="wa-mgr-input" placeholder="e.g. Order Update" />
                    </div>
                    <div>
                        <label class="wa-mgr-label">Footer Text (optional)</label>
                        <input id="tplFooter" class="wa-mgr-input" placeholder="e.g. Nizami Farms" />
                    </div>
                </div>
                <div style="margin-bottom:12px;">
                    <label class="wa-mgr-label">Show In (where this template appears)</label>
                    <div style="display:flex;gap:16px;margin-top:4px;flex-wrap:wrap;">
                        <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;"><input type="checkbox" id="tplShowMessages" checked /> Messages</label>
                        <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;"><input type="checkbox" id="tplShowOrders" checked /> Open Orders</label>
                        <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;"><input type="checkbox" id="tplShowCustomers" checked /> Customers</label>
                        <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;"><input type="checkbox" id="tplShowShopify" checked /> Shopify</label>
                        <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;background:#fffbeb;padding:2px 6px;border-radius:4px;border:1px solid #fde68a;margin-top:4px;"><input type="checkbox" id="tplShowInvoice" /> 📄 Regular Invoice</label>
                        <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;background:#fef3c7;padding:2px 6px;border-radius:4px;border:1px solid #f59e0b;margin-top:4px;"><input type="checkbox" id="tplShowQurbaniInvoice" /> 🐄 Qurbani Invoice</label>
                        <label style="display:flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;background:#ede9fe;padding:2px 6px;border-radius:4px;border:1px solid #c4b5fd;margin-top:4px;" title="Used by automations only. Check this alone (uncheck the others) to hide it from the manual Send/Manage pickers."><input type="checkbox" id="tplShowAutomation" /> 🤖 Automation only</label>
                    </div>
                </div>
                <div style="margin-bottom:12px;">
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;padding:8px 10px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;">
                        <input type="checkbox" id="tplIsActive" checked />
                        <div>
                            <div style="font-weight:600;color:#15803d;">✓ Active</div>
                            <div style="font-size:11px;color:#64748b;">Uncheck to hide this template everywhere without deleting it</div>
                        </div>
                    </label>
                </div>
                <div style="margin-bottom:12px;padding:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                    <div style="font-weight:600;color:#334155;font-size:13px;margin-bottom:6px;">👥 Who can see this template?</div>
                    <div style="font-size:11px;color:#64748b;margin-bottom:8px;">Pick where this template shows up. Marketing / broadcast messages should stay "Common".</div>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;" id="tplScopeGroup">
                        <label class="tpl-scope-opt" data-scope="common" style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;padding:8px;background:#fff;border:2px solid #e5e7eb;border-radius:6px;">
                            <input type="radio" name="tplScope" value="common" id="tplScopeCommon" checked style="margin:0;" />
                            <div>
                                <div style="font-weight:600;color:#334155;">🌐 Common</div>
                                <div style="font-size:10px;color:#64748b;">Both Regular &amp; Qurbani</div>
                            </div>
                        </label>
                        <label class="tpl-scope-opt" data-scope="regular" style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;padding:8px;background:#fff;border:2px solid #e5e7eb;border-radius:6px;">
                            <input type="radio" name="tplScope" value="regular" id="tplScopeRegular" style="margin:0;" />
                            <div>
                                <div style="font-weight:600;color:#1e40af;">🛒 Regular only</div>
                                <div style="font-size:10px;color:#64748b;">Hidden on Qurbani pages</div>
                            </div>
                        </label>
                        <label class="tpl-scope-opt" data-scope="qurbani" style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;padding:8px;background:#fff;border:2px solid #e5e7eb;border-radius:6px;">
                            <input type="radio" name="tplScope" value="qurbani" id="tplScopeQurbani" style="margin:0;" />
                            <div>
                                <div style="font-weight:600;color:#b45309;">🐄 Qurbani only</div>
                                <div style="font-size:10px;color:#64748b;">Hidden on Regular pages</div>
                            </div>
                        </label>
                    </div>
                </div>
                <div style="margin-bottom:12px;">
                    <label style="display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer;"><input type="checkbox" id="tplIsDefault" /> ⭐ Set as default invoice template</label>
                    <div style="font-size:11px;color:#9ca3af;margin-top:2px;">Used for <b>Qurbani</b> invoices. For <b>regular</b> invoices the template is set in 🤖 Automations → Invoice &amp; dispatch (online/cash + ETA) — that's the single source of truth, so this star doesn't affect regular invoice sends.</div>
                </div>
                <div style="display:flex;gap:8px;">
                    <button id="tplSaveBtn" onclick="saveTemplateForm()" class="wa-mgr-save" style="flex:1;">Save Template</button>
                    <button id="tplCancelBtn" onclick="cancelTemplateEdit()" style="display:none;padding:11px 20px;background:#e5e7eb;color:#374151;border:none;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;">Cancel</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══ LABELS MODAL ═══
     Two sections:
       • Apply/remove labels for the currently-open conversation (toggles).
       • Manage label library (create/edit/delete) — only visible to users
         with manage_whatsapp_labels. System labels can be edited but the
         delete button shows a confirmation warning.
-->
<div class="wa-labels-modal" id="waLabelsModal" onclick="if(event.target===this)closeLabelsModal()">
    <div class="wa-labels-box" onclick="event.stopPropagation()">
        <div class="wa-labels-hdr">
            <h3>Conversation Labels</h3>
            <button class="wa-new-panel-close" onclick="closeLabelsModal()">&times;</button>
        </div>
        <div class="wa-labels-body">
            <div class="wa-labels-section-title">Apply labels</div>
            <div id="waLabelToggles" style="margin-bottom:10px;">
                <div style="font-size:12px;color:#9ca3af;">Loading…</div>
            </div>

            <div id="waLabelManageBlock" style="display:none;">
                <div class="wa-labels-section-title" style="margin-top:14px;">Manage library</div>
                <div id="waLabelManageList"></div>
                <div class="wa-label-create-row">
                    <input type="text" id="waLabelNewName" placeholder="New label name (e.g. Complaint)" maxlength="60">
                    <input type="color" id="waLabelNewColor" value="#16A34A" title="Label colour">
                    {{-- Phase 2: optional user-mention binding. When a user
                         is picked, applying the label pushes them an FCM
                         notification and tracks the mention as unread for
                         them until they open the conversation. --}}
                    <select id="waLabelNewUser" class="wa-label-user-select" title="Mention a user (optional)">
                        <option value="">No user (generic)</option>
                    </select>
                    <button onclick="createLabel()">Add</button>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('demo1_js')
@verbatim
<script>
(function() {
    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const POLL_INTERVAL = 10000;
    let activeConvId = null;
    let activeConv = null;
    let currentFilter = 'all';
    // Phase 2 — when true, ?assigned_to_me=1 is added to the inbox query
    // and only conversations with unread @mentions for the current user
    // are returned. Toggled by the @me filter button.
    let assignedToMe = false;
    // 'customers' (legacy behaviour, searches name/phone/city) or 'chats'
    // (searches inside message content via the backend's search_mode param).
    // Toggled by the small pill row underneath the search input.
    let currentSearchMode = 'customers';
    let searchTimeout = null;
    let convPollTimer = null;
    let msgPollTimer = null;
    // ─────────────────────────────────────────────────────────────────────
    // May-2026 — Message-pane pagination state.
    //
    // The chat panel always fetches the LATEST N messages from the server
    // (controller now orders DESC, slices, then reverses to chronological).
    // For longer threads (>50 msgs) we also need to let the user scroll
    // BACK in time — that's what the "Load older messages" link at the
    // top of the message list does. The link existed in the HTML for
    // months but `loadOlderMessages()` was never defined, so clicking it
    // threw a silent ReferenceError. These state vars + the function
    // below close that gap.
    //
    //   waMsgs           — flat cumulative array of currently rendered
    //                      messages (oldest first). Polling + send
    //                      reset it to the latest N; "Load older"
    //                      PREPENDS to it.
    //   waMsgsHasMore    — true if the server hinted there are more
    //                      OLDER messages available beyond what's in
    //                      waMsgs. Drives whether the "Load older" link
    //                      renders at the top.
    //   waMsgsHistoryMode — set to true once the user has clicked
    //                      "Load older" at least once for this open
    //                      conversation. While true, the background
    //                      polling loop SKIPS the wholesale replace
    //                      (which would wipe the older history the
    //                      user is reading) and instead just keeps
    //                      mark-read in sync. Reset to false when a
    //                      different conversation is opened.
    //   waMsgsLoadingOlder — re-entrancy guard so a slow network or
    //                        double-click can't fire two overlapping
    //                        pagination requests.
    // ─────────────────────────────────────────────────────────────────────
    let waMsgs = [];
    let waMsgsHasMore = false;
    let waMsgsHistoryMode = false;
    let waMsgsLoadingOlder = false;
    let templates = [];
    var _cachedApiTemplates = null;

    // ─────────────────────────────────────────────────────────────────────
    // Apr-2026: Conversation list pagination state.
    //
    // Server returns up to 200 most-recent conversations on a "fresh" call
    // (default page). To go deeper, frontend asks for older rows by passing
    // ?before_last_message_at=<oldest visible's last_message_at> and gets
    // up to 100 more per page.
    //
    // We keep two arrays:
    //   convListLive  — refreshed on every loadConversations() call (the
    //                   poll, filter changes, search). Always the top 200.
    //   convListExtra — appended to by loadMoreConversations(). Static
    //                   between polls; the next poll preserves it (we
    //                   only refetch the live head). On render we dedupe
    //                   so a tail conv that bubbled up into the live head
    //                   doesn't appear twice.
    //
    // convListMoreCursor / convListMoreExhausted track whether the Load
    // More button should be shown and what cursor to send next.
    // ─────────────────────────────────────────────────────────────────────
    let convListLive = [];
    let convListExtra = [];
    let convListMoreCursor = null;
    let convListMoreExhausted = false;
    let convListLoadingMore = false;
    let convListLastSearch = '';
    let convListLastFilter = 'all';

    // Qurbani tab / badge master switch. Refreshed from the backend on init
    // and after the settings drawer is saved.
    let waQurbaniEnabled = true;
    let waQurbaniSettings = null;

    function loadQurbaniSettings() {
        return apiFetch('/messages/qurbani-settings').then(d => {
            if (d.success && d.settings) {
                waQurbaniSettings = d.settings;
                waQurbaniEnabled = !!d.settings.enabled;
                const tabBtn = document.getElementById('waFilterQurbani');
                if (tabBtn) tabBtn.style.display = waQurbaniEnabled ? '' : 'none';
                if (!waQurbaniEnabled && currentFilter === 'qurbani') {
                    currentFilter = 'all';
                    document.querySelectorAll('.wa-filter-btn').forEach(b => b.classList.toggle('active', b.dataset.filter === 'all'));
                    loadConversations();
                }
            }
        }).catch(() => {});
    }

    window.openQurbaniSettings = function() {
        document.getElementById('waQurbaniSettingsModal').style.display = 'flex';
        document.getElementById('qcfgStatus').textContent = '';
        // Hydrate from whatever is already cached; refresh in the background.
        if (waQurbaniSettings) hydrateQurbaniForm(waQurbaniSettings);
        loadQurbaniSettings().then(() => { if (waQurbaniSettings) hydrateQurbaniForm(waQurbaniSettings); });
    };
    window.closeQurbaniSettings = function() {
        document.getElementById('waQurbaniSettingsModal').style.display = 'none';
    };

    function hydrateQurbaniForm(s) {
        document.getElementById('qcfgEnabled').checked = !!s.enabled;
        document.getElementById('qcfgKeywords').value = Array.isArray(s.keywords) ? s.keywords.join(', ') : (s.keywords || '');
        document.getElementById('qcfgLookback').value = s.lookback || 5;
        document.getElementById('qcfgYear').value = s.year || '';
    }

    window.saveQurbaniSettings = function() {
        const btn = document.getElementById('qcfgSaveBtn');
        const status = document.getElementById('qcfgStatus');
        btn.disabled = true; status.textContent = 'Saving...';
        const payload = {
            enabled: document.getElementById('qcfgEnabled').checked,
            keywords: document.getElementById('qcfgKeywords').value,
            lookback: parseInt(document.getElementById('qcfgLookback').value, 10) || 5,
            year: document.getElementById('qcfgYear').value || null,
        };
        apiFetch('/messages/qurbani-settings', { method: 'POST', body: JSON.stringify(payload) })
            .then(d => {
                btn.disabled = false;
                if (d.success) {
                    waQurbaniSettings = d.settings;
                    waQurbaniEnabled = !!d.settings.enabled;
                    const tabBtn = document.getElementById('waFilterQurbani');
                    if (tabBtn) tabBtn.style.display = waQurbaniEnabled ? '' : 'none';
                    status.style.color = '#16a34a';
                    status.textContent = 'Saved. Settings apply to new messages automatically. Use "Rescan" below to re-evaluate existing conversations.';
                    loadConversations();
                } else {
                    status.style.color = '#ef4444';
                    status.textContent = d.message || 'Failed to save settings.';
                }
            })
            .catch(e => { btn.disabled = false; status.style.color = '#ef4444'; status.textContent = 'Failed to save settings.'; });
    };

    window.rescanQurbani = function() {
        if (!confirm('Re-evaluate every conversation with the current keyword / lookback / year settings? This may take a few seconds.')) return;
        const btn = document.getElementById('qcfgRescanBtn');
        const status = document.getElementById('qcfgStatus');
        btn.disabled = true; status.style.color = '#6b7280'; status.textContent = 'Rescanning...';
        apiFetch('/messages/qurbani-rescan', { method: 'POST' })
            .then(d => {
                btn.disabled = false;
                if (d.success) {
                    status.style.color = '#16a34a';
                    status.textContent = `Done. Scanned ${d.total} · newly flagged ${d.flagged} · cleared ${d.cleared}.`;
                    loadConversations();
                } else {
                    status.style.color = '#ef4444';
                    status.textContent = d.message || 'Rescan failed.';
                }
            })
            .catch(() => { btn.disabled = false; status.style.color = '#ef4444'; status.textContent = 'Rescan failed.'; });
    };

    // ═════════════════════════════════════════════════════════════════════
    // Auto-Reply settings (Apr-2026)
    //
    // Rules are loaded once when the modal opens; saving/deleting refreshes
    // the local cache without refetching. The form is editor-pane-style:
    // null arRuleId = "create new", otherwise update existing.
    // ═════════════════════════════════════════════════════════════════════

    let _arRules = [];
    let _arSelectedId = null;

    window.openAutoReplySettings = function() {
        const modal = document.getElementById('waAutoReplyModal');
        if (!modal) return;
        modal.style.display = 'flex';
        document.getElementById('arStatus').textContent = '';
        if (window.switchAutoTab) switchAutoTab('ooo');
        loadAutoReplyRules();
    };
    window.closeAutoReplySettings = function() {
        const modal = document.getElementById('waAutoReplyModal');
        if (modal) modal.style.display = 'none';
    };

    function loadAutoReplyRules() {
        apiFetch('/messages/auto-reply').then(d => {
            if (!d.success) {
                document.getElementById('arRuleList').innerHTML =
                    '<div style="padding:14px;color:#ef4444;font-size:12px;line-height:1.5;">' +
                    (d.message || 'Failed to load rules.') + '</div>';
                return;
            }
            _arRules = d.rules || [];
            const toggle = document.getElementById('arMasterToggle');
            const label = document.getElementById('arMasterLabel');
            toggle.checked = !!d.enabled;
            label.textContent = 'Auto-reply: ' + (d.enabled ? 'On' : 'Off');
            renderAutoReplyRuleList();
            // Keep selection if the rule still exists, otherwise clear.
            if (_arSelectedId && _arRules.find(r => r.id === _arSelectedId)) {
                selectAutoReplyRule(_arSelectedId);
            } else {
                _arSelectedId = null;
                document.getElementById('arForm').style.display = 'none';
                document.getElementById('arEmptyHint').style.display = '';
            }
        }).catch(() => {
            document.getElementById('arRuleList').innerHTML =
                '<div style="padding:14px;color:#ef4444;font-size:12px;">Failed to load rules.</div>';
        });
    }

    function renderAutoReplyRuleList() {
        const host = document.getElementById('arRuleList');
        if (!_arRules.length) {
            host.innerHTML = '<div style="padding:18px;color:#9ca3af;font-size:12px;text-align:center;">No rules yet. Click "+ New Rule" above.</div>';
            return;
        }
        const esc = s => String(s == null ? '' : s).replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
        const summary = r => {
            if (r.match_mode === 'always') return 'Always';
            if (r.match_mode === 'specific_dates') return (r.specific_dates && r.specific_dates.length) + ' date(s)';
            const days = r.days_of_week ? r.days_of_week.split(',').map(d => ['Su','Mo','Tu','We','Th','Fr','Sa'][parseInt(d,10)]).join(' ') : 'every day';
            const t = (r.start_time && r.end_time) ? (r.start_time.slice(0,5) + '–' + r.end_time.slice(0,5)) : 'all day';
            return days + ' · ' + t;
        };
        host.innerHTML = _arRules.map(r => `
            <div class="ar-row" data-id="${r.id}" onclick="selectAutoReplyRule(${r.id})"
                 style="padding:10px 12px;border-bottom:1px solid #e5e7eb;cursor:pointer;${_arSelectedId === r.id ? 'background:#dbeafe;' : ''}">
                <div style="display:flex;align-items:center;gap:6px;">
                    <span style="width:6px;height:6px;border-radius:50%;background:${r.enabled ? '#22c55e' : '#9ca3af'};display:inline-block;"></span>
                    <div style="font-weight:600;font-size:13px;color:#111827;flex:1;${r.enabled ? '' : 'color:#9ca3af;'}" title="${esc(r.name)}">${esc(r.name)}</div>
                </div>
                <div style="font-size:11px;color:#6b7280;margin-top:3px;margin-left:12px;">${esc(summary(r))}</div>
            </div>
        `).join('');
    }

    window.selectAutoReplyRule = function(id) {
        _arSelectedId = id;
        const r = _arRules.find(x => x.id === id);
        if (!r) return;
        document.getElementById('arEmptyHint').style.display = 'none';
        document.getElementById('arForm').style.display = '';
        document.getElementById('arRuleId').value = r.id;
        document.getElementById('arName').value = r.name || '';
        document.getElementById('arMessage').value = r.message || '';
        document.getElementById('arMatchMode').value = r.match_mode || 'time_window';
        document.getElementById('arPriority').value = r.priority != null ? r.priority : 100;
        document.getElementById('arCooldown').value = r.cooldown_hours != null ? r.cooldown_hours : 6;
        document.getElementById('arCooldownMode').value = r.cooldown_mode || 'daily';
        document.getElementById('arActiveFrom').value = r.active_from ? String(r.active_from).slice(0,10) : '';
        document.getElementById('arActiveTo').value = r.active_to ? String(r.active_to).slice(0,10) : '';
        document.getElementById('arEnabled').checked = !!r.enabled;
        const days = r.days_of_week ? r.days_of_week.split(',').map(s => parseInt(s,10)) : [];
        document.querySelectorAll('.ar-day').forEach(cb => {
            cb.checked = days.includes(parseInt(cb.dataset.day, 10));
        });
        document.getElementById('arStart').value = r.start_time ? r.start_time.slice(0,5) : '';
        document.getElementById('arEnd').value   = r.end_time   ? r.end_time.slice(0,5)   : '';
        document.getElementById('arSpecificDates').value = (r.specific_dates || []).join('\n');
        document.getElementById('arDeleteBtn').style.display = '';
        onArMatchModeChange();
        onArCooldownModeChange();
        document.getElementById('arStatus').textContent = '';
        renderAutoReplyRuleList();
    };

    window.newAutoReplyRule = function() {
        _arSelectedId = null;
        document.getElementById('arEmptyHint').style.display = 'none';
        document.getElementById('arForm').style.display = '';
        document.getElementById('arRuleId').value = '';
        document.getElementById('arName').value = '';
        document.getElementById('arMessage').value = '';
        document.getElementById('arMatchMode').value = 'time_window';
        document.getElementById('arPriority').value = 100;
        document.getElementById('arCooldown').value = 6;
        document.getElementById('arCooldownMode').value = 'daily';
        document.getElementById('arActiveFrom').value = '';
        document.getElementById('arActiveTo').value = '';
        document.getElementById('arEnabled').checked = true;
        document.querySelectorAll('.ar-day').forEach(cb => { cb.checked = false; });
        document.getElementById('arStart').value = '';
        document.getElementById('arEnd').value = '';
        document.getElementById('arSpecificDates').value = '';
        document.getElementById('arDeleteBtn').style.display = 'none';
        onArMatchModeChange();
        onArCooldownModeChange();
        document.getElementById('arStatus').textContent = '';
        renderAutoReplyRuleList();
    };

    window.cancelAutoReplyEdit = function() {
        _arSelectedId = null;
        document.getElementById('arForm').style.display = 'none';
        document.getElementById('arEmptyHint').style.display = '';
        renderAutoReplyRuleList();
    };

    window.onArMatchModeChange = function() {
        const mode = document.getElementById('arMatchMode').value;
        document.getElementById('arTimeWindowFields').style.display = (mode === 'time_window') ? '' : 'none';
        document.getElementById('arSpecificDatesFields').style.display = (mode === 'specific_dates') ? '' : 'none';
    };

    window.onArCooldownModeChange = function() {
        const mode = document.getElementById('arCooldownMode').value;
        // The "hours between replies" input only matters for the rolling mode.
        document.getElementById('arCooldownHoursWrap').style.display = (mode === 'rolling') ? '' : 'none';
    };

    window.toggleAutoReplyMaster = function(checked) {
        const label = document.getElementById('arMasterLabel');
        label.textContent = 'Auto-reply: ' + (checked ? 'On' : 'Off');
        apiFetch('/messages/auto-reply/toggle', {
            method: 'POST',
            body: JSON.stringify({ enabled: checked }),
        }).then(d => {
            if (!d.success) {
                // Revert on failure.
                document.getElementById('arMasterToggle').checked = !checked;
                label.textContent = 'Auto-reply: ' + (!checked ? 'On' : 'Off');
                alert(d.message || 'Failed to update master switch.');
            }
        }).catch(() => {
            document.getElementById('arMasterToggle').checked = !checked;
            label.textContent = 'Auto-reply: ' + (!checked ? 'On' : 'Off');
            alert('Failed to update master switch.');
        });
    };

    window.saveAutoReplyRule = function() {
        const id = document.getElementById('arRuleId').value || null;
        const status = document.getElementById('arStatus');
        const btn = document.getElementById('arSaveBtn');
        const name = document.getElementById('arName').value.trim();
        const message = document.getElementById('arMessage').value.trim();
        const mode = document.getElementById('arMatchMode').value;
        if (!name) { status.style.color = '#ef4444'; status.textContent = 'Rule name is required.'; return; }
        if (!message) { status.style.color = '#ef4444'; status.textContent = 'Reply message is required.'; return; }

        const days = Array.from(document.querySelectorAll('.ar-day'))
            .filter(cb => cb.checked).map(cb => cb.dataset.day).join(',');
        const specificDates = document.getElementById('arSpecificDates').value
            .split(/\r?\n/).map(s => s.trim()).filter(s => /^\d{4}-\d{2}-\d{2}$/.test(s));

        if (mode === 'specific_dates' && specificDates.length === 0) {
            status.style.color = '#ef4444';
            status.textContent = 'At least one valid YYYY-MM-DD date is required for "Specific dates" mode.';
            return;
        }

        const payload = {
            name,
            message,
            match_mode: mode,
            enabled: document.getElementById('arEnabled').checked,
            priority: parseInt(document.getElementById('arPriority').value, 10) || 100,
            cooldown_hours: parseInt(document.getElementById('arCooldown').value, 10) || 0,
            days_of_week: mode === 'time_window' ? days : '',
            start_time: (mode === 'time_window' ? document.getElementById('arStart').value : '') || null,
            end_time:   (mode === 'time_window' ? document.getElementById('arEnd').value   : '') || null,
            specific_dates: mode === 'specific_dates' ? specificDates : [],
            cooldown_mode: document.getElementById('arCooldownMode').value,
            active_from: document.getElementById('arActiveFrom').value || null,
            active_to: document.getElementById('arActiveTo').value || null,
        };

        btn.disabled = true; status.style.color = '#6b7280'; status.textContent = 'Saving...';
        const url = id ? ('/messages/auto-reply/rules/' + id) : '/messages/auto-reply/rules';
        apiFetch(url, { method: 'POST', body: JSON.stringify(payload) })
            .then(d => {
                btn.disabled = false;
                if (d.success) {
                    status.style.color = '#16a34a';
                    status.textContent = 'Saved.';
                    _arSelectedId = d.id || _arSelectedId;
                    loadAutoReplyRules();
                } else {
                    status.style.color = '#ef4444';
                    status.textContent = d.message || 'Failed to save.';
                }
            })
            .catch(e => { btn.disabled = false; status.style.color = '#ef4444'; status.textContent = 'Failed to save.'; });
    };

    window.deleteAutoReplyRule = function() {
        const id = document.getElementById('arRuleId').value;
        if (!id) return;
        if (!confirm('Delete this auto-reply rule? This cannot be undone.')) return;
        apiFetch('/messages/auto-reply/rules/' + id, { method: 'DELETE' })
            .then(d => {
                if (d.success) {
                    _arSelectedId = null;
                    document.getElementById('arForm').style.display = 'none';
                    document.getElementById('arEmptyHint').style.display = '';
                    loadAutoReplyRules();
                } else {
                    alert(d.message || 'Failed to delete.');
                }
            })
            .catch(() => alert('Failed to delete.'));
    };

    // ═════════════════════════════════════════════════════════════════════
    // Order / Invoice automations (Jun-2026, Phase 2). Shares apiFetch + CSRF
    // with the auto-reply code above. Rules + their on/off come from
    // /messages/automations (registry-driven, off by default).
    // ═════════════════════════════════════════════════════════════════════
    let _waAutoLoaded = false;
    let _waAutoRules = [];
    let _waAutoTemplates = null;

    function waEsc(s){ return String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

    window.switchAutoTab = function(tab){
        // Restore each panel's REAL display when active (ooo is a flex column;
        // the others are simple scroll panes) — never '' which would drop the
        // out-of-office flex layout back to block.
        const disp = { ooo:'flex', order:'block', invoice:'block', shift:'block' };
        ['ooo','order','invoice','shift'].forEach(t => {
            const panel = document.getElementById('waTab-'+t);
            if (panel) panel.style.display = (t===tab) ? disp[t] : 'none';
            const btn = document.getElementById('waTabBtn-'+t);
            if (btn){
                btn.style.borderBottom = (t===tab) ? '2px solid #16a34a' : '2px solid transparent';
                btn.style.color = (t===tab) ? '#166534' : '#6b7280';
                btn.style.fontWeight = (t===tab) ? '600' : '500';
            }
        });
        if ((tab==='order' || tab==='invoice' || tab==='shift') && !_waAutoLoaded) loadAutomations();
    };

    // "Templates" tab: opens the existing Template Manager (list + create/edit +
    // show-in + delete). We preload the template list first so the 👁 message
    // previews in the manager resolve.
    window.waOpenTemplatesTab = function(){
        ensureAutoTemplates().then(function(){ if (window.openTemplateManager) openTemplateManager(); });
    };

    function ensureAutoTemplates(){
        if (_waAutoTemplates) return Promise.resolve(_waAutoTemplates);
        // include_automation=1: this IS the automation rule picker, so it must
        // see the 🤖 automation-only templates the manual pickers now hide.
        return apiFetch('/messages/templates?include_automation=1').then(d => { _waAutoTemplates = d.templates || []; return _waAutoTemplates; })
            .catch(() => { _waAutoTemplates = []; return []; });
    }

    function autoTemplateOptions(selected){
        selected = selected || '';
        let opts = '<option value="">— choose template —</option>';
        const list = _waAutoTemplates || [];
        if (!list.length) opts += '<option value="" disabled>No templates yet — create in Meta + add to the system</option>';
        let found = false;
        list.forEach(t => {
            if (t.name === selected) found = true;
            opts += `<option value="${waEsc(t.name)}" ${t.name===selected?'selected':''}>${waEsc(t.display_name||t.name)} (${t.variable_count||0} vars)</option>`;
        });
        if (selected && !found) opts += `<option value="${waEsc(selected)}" selected>${waEsc(selected)} (not in list)</option>`;
        return opts;
    }

    // ── "View message" preview ────────────────────────────────────────────────
    // Shows the actual WhatsApp copy a rule will send, with example values filled
    // in. Reads body_text off the registered template (run the template seed so
    // the automation templates carry their text). Buttons render as chips.
    function waPreviewExample(body){
        return String(body || '')
            .replace(/\{\{\s*1\s*\}\}/g, 'Ali')
            .replace(/\{\{\s*2\s*\}\}/g, 'NF-12345')
            .replace(/\{\{\s*3\s*\}\}/g, 'Today, 4:10-4:40 PM');
    }
    window.waShowTemplatePreview = function(name){
        if (!name){ alert('Pick a template first.'); return; }
        const t = (_waAutoTemplates || []).find(x => x.name === name);
        const old = document.getElementById('waTplPreviewModal'); if (old) old.remove();
        let inner;
        if (!t){
            inner = `<div style="color:#b91c1c;font-size:13px;line-height:1.5;">"${waEsc(name)}" isn't registered in the app yet, so there's no saved copy to preview.<br><br>Run <code>seed_wa_templates_register_jun2026.sql</code> and clear cache to register the automation templates, then reopen this.</div>`;
        } else {
            const body = waPreviewExample(t.body_text || '(no message body saved for this template)');
            let btns = [];
            if (t.has_buttons && t.button_labels){
                btns = Array.isArray(t.button_labels) ? t.button_labels : (function(){ try { return JSON.parse(t.button_labels); } catch(e){ return []; } })();
            }
            const btnHtml = btns.length
                ? `<div style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap;">${btns.map(b=>`<span style="border:1px solid #25D366;color:#128C7E;border-radius:14px;padding:3px 10px;font-size:12px;">${waEsc(b)}</span>`).join('')}</div>`
                : '';
            inner = `<div style="font-size:11px;color:#6b7280;margin-bottom:6px;">${waEsc(t.display_name||t.name)} · ${t.variable_count||0} vars</div>
                <div style="white-space:pre-wrap;font-size:13px;color:#111827;background:#f0f7f4;border:1px solid #d6e9df;border-radius:8px;padding:12px;line-height:1.5;">${waEsc(body)}</div>
                ${btnHtml}
                <div style="font-size:11px;color:#9ca3af;margin-top:8px;">Preview with example values — name, order # and ETA fill in automatically when sent.</div>`;
        }
        const modal = document.createElement('div');
        modal.id = 'waTplPreviewModal';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:10000;display:flex;align-items:center;justify-content:center;padding:20px;';
        modal.innerHTML = `<div style="background:#fff;border-radius:14px;max-width:460px;width:100%;max-height:80vh;overflow:auto;padding:18px;box-shadow:0 12px 40px rgba(0,0,0,0.2);">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                <div style="font-weight:600;font-size:15px;color:#111827;">📄 Message preview</div>
                <button type="button" onclick="document.getElementById('waTplPreviewModal').remove()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#6b7280;">&times;</button>
            </div>
            ${inner}
        </div>`;
        modal.addEventListener('click', e => { if (e.target === modal) modal.remove(); });
        document.body.appendChild(modal);
    }
    window.waPreviewSelectById = function(id){ const el = document.getElementById(id); window.waShowTemplatePreview(el ? el.value : ''); };
    window.waPreviewRowTpl = function(btn){ const row = btn.closest('.wa-sched-row'); const sel = row ? row.querySelector('.wsr-tpl') : null; window.waShowTemplatePreview(sel ? sel.value : ''); };
    // Reorder a schedule row for priority (first match wins, top→bottom). Moves
    // the row's DOM node among its siblings; gatherSchedule() reads rows in DOM
    // order on Save, so the new order is what's persisted. No-ops at top/bottom.
    window.waMoveSchedRow = function(btn, dir){
        const row = btn.closest('.wa-sched-row');
        if (!row) return;
        if (dir < 0){
            const prev = row.previousElementSibling;
            if (prev && prev.classList && prev.classList.contains('wa-sched-row')) row.parentNode.insertBefore(row, prev);
        } else {
            const next = row.nextElementSibling;
            if (next && next.classList && next.classList.contains('wa-sched-row')) row.parentNode.insertBefore(next, row);
        }
    };

    // ── Plain-English schedule summary (read-only; the editable rows live below) ──
    function waFmtTime(t){
        if (!t) return null;
        const m = /^(\d{1,2}):(\d{2})/.exec(t); if (!m) return t;
        let h = parseInt(m[1], 10); const mm = m[2];
        const ap = h >= 12 ? 'PM' : 'AM'; let h12 = h % 12; if (h12 === 0) h12 = 12;
        return h12 + ':' + mm + ' ' + ap;
    }
    function waSummaryDays(days){
        const names = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
        if (!Array.isArray(days) || !days.length) return 'any day';
        return days.map(d => names[d] || '?').join(', ');
    }
    function waSummaryComp(c){
        return ({chicken_only:'chicken only', mixed:'chicken + red meat', red_meat_only:'red meat only'})[c] || '';
    }
    function waTplDisplayName(name){
        const t = (_waAutoTemplates || []).find(x => x.name === name);
        return t ? (t.display_name || t.name) : (name || '(none)');
    }
    function waScheduleSummary(cfg){
        cfg = cfg || {};
        const rows = Array.isArray(cfg.rows) ? cfg.rows : [];
        const order = [], groups = {};
        rows.forEach(r => {
            const n = r.template || '(none chosen)';
            if (!groups[n]){ groups[n] = []; order.push(n); }
            const start = waFmtTime(r.start), end = waFmtTime(r.end);
            const when = (start && end) ? (start + '–' + end) : 'all day';
            const comp = waSummaryComp(r.composition);
            groups[n].push(waSummaryDays(r.days) + ' ' + when + (comp ? (' (' + comp + ')') : ''));
        });
        // Inline 👁 preview per template so the operator can see exactly what each
        // line sends (the Default included — handy to confirm it's a real template).
        const eye = (name) => (name && name !== '(none chosen)')
            ? ` <span onclick="window.waShowTemplatePreview('${waEsc(name)}')" title="View message" style="cursor:pointer;color:#2563eb;">👁</span>`
            : '';
        const items = order.map(n =>
            `<li style="margin-bottom:4px;"><b>${waEsc(waTplDisplayName(n))}</b>${eye(n)} — ${groups[n].map(waEsc).join('; ')}</li>`);
        if (cfg.default_template){
            items.push(`<li style="margin-bottom:4px;"><b>Default → ${waEsc(waTplDisplayName(cfg.default_template))}</b>${eye(cfg.default_template)} — any other time</li>`);
        }
        if (!items.length) return '<div style="font-size:12px;color:#9ca3af;padding:6px 0;">No schedule rows yet — open Edit schedule below to add one.</div>';
        return `<div style="background:#f8fafc;border:1px solid #eef2f7;border-radius:8px;padding:10px 12px;margin-bottom:8px;font-size:12px;color:#374151;">
            <div style="font-weight:600;margin-bottom:5px;">📨 What this sends <span style="font-weight:400;color:#9ca3af;">(first match wins, top to bottom)</span></div>
            <ul style="margin:0;padding-left:18px;line-height:1.5;">${items.join('')}</ul></div>`;
    }
    window.waToggleSchedEditor = function(key){
        const el = document.getElementById('waSchedEditor-' + key);
        const btn = document.getElementById('waSchedEditBtn-' + key);
        if (!el) return;
        const open = el.style.display === 'none';
        el.style.display = open ? '' : 'none';
        if (btn) btn.textContent = open ? '▲ Hide schedule editor' : '✏️ Edit schedule';
    };

    function loadAutomations(){
        apiFetch('/messages/automations').then(d => {
            if (!d.success){
                document.getElementById('waAutoOrderCards').innerHTML =
                    '<div style="color:#ef4444;padding:16px;font-size:13px;">'+(d.message||'Failed to load.')+'</div>';
                return;
            }
            _waAutoLoaded = true;
            _waAutoRules = d.rules || [];
            const m = document.getElementById('waAutoMaster');
            if (m){ m.checked = !!d.enabled; document.getElementById('waAutoMasterLabel').textContent = 'Automations: '+(d.enabled?'On':'Off'); }
            const tp = document.getElementById('waAutoTestPhone'); if (tp) tp.value = d.test_phone || '';
            ensureAutoTemplates().then(renderAutoCards);
        }).catch(() => {
            document.getElementById('waAutoOrderCards').innerHTML = '<div style="color:#ef4444;padding:16px;font-size:13px;">Failed to load.</div>';
        });
    }

    function renderAutoCards(){
        const order = _waAutoRules.filter(r => r.tab==='order_actions');
        const invoice = _waAutoRules.filter(r => r.tab==='invoice');
        const shift = _waAutoRules.filter(r => r.tab==='shift');
        document.getElementById('waAutoOrderCards').innerHTML = order.map(renderAutoCard).join('') || '<div style="color:#9ca3af;padding:16px;">No order rules.</div>';
        const inv = document.getElementById('waAutoInvoiceCards');
        if (inv) inv.innerHTML = invoice.map(renderAutoCard).join('') || '<div style="color:#9ca3af;padding:16px;">No invoice rules.</div>';
        const shf = document.getElementById('waAutoShiftCards');
        if (shf) shf.innerHTML = shift.map(renderAutoCard).join('') || '<div style="color:#9ca3af;padding:16px;">No shift rules.</div>';
    }

    function schedRowHtml(key, row){
        row = row || {};
        const days = Array.isArray(row.days) ? row.days.map(Number) : [];
        const dayBoxes = [['Su',0],['Mo',1],['Tu',2],['We',3],['Th',4],['Fr',5],['Sa',6]].map(d =>
            `<label style="display:inline-flex;align-items:center;gap:2px;font-size:11px;"><input type="checkbox" class="wsd" data-day="${d[1]}" ${days.includes(d[1])?'checked':''}/> ${d[0]}</label>`).join('');
        const comp = row.composition || 'any';
        const compOpts = [['any','Any items'],['chicken_only','Chicken only'],['mixed','Chicken + red meat'],['red_meat_only','Red meat only']]
            .map(o => `<option value="${o[0]}" ${comp===o[0]?'selected':''}>${o[1]}</option>`).join('');
        return `<div class="wa-sched-row" style="border:1px solid #eef2f7;border-radius:8px;padding:8px;margin-bottom:6px;">
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:6px;">${dayBoxes}
                <span style="margin-left:auto;display:inline-flex;gap:1px;align-items:center;">
                    <button type="button" onclick="waMoveSchedRow(this,-1)" title="Move up (higher priority — first match wins)" style="background:none;border:none;color:#6b7280;cursor:pointer;font-size:13px;padding:0 4px;line-height:1;">▲</button>
                    <button type="button" onclick="waMoveSchedRow(this,1)" title="Move down (lower priority)" style="background:none;border:none;color:#6b7280;cursor:pointer;font-size:13px;padding:0 4px;line-height:1;">▼</button>
                    <button type="button" onclick="this.closest('.wa-sched-row').remove()" title="Remove this rule" style="background:none;border:none;color:#b91c1c;cursor:pointer;font-size:13px;padding:0 4px;line-height:1;">✕</button>
                </span>
            </div>
            <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">
                <input type="time" class="wsr-start" value="${waEsc(row.start||'')}" style="padding:5px;border:1px solid #e5e7eb;border-radius:6px;font-size:12px;"/>
                <span style="color:#9ca3af;font-size:12px;">to</span>
                <input type="time" class="wsr-end" value="${waEsc(row.end||'')}" style="padding:5px;border:1px solid #e5e7eb;border-radius:6px;font-size:12px;"/>
                <select class="wsr-comp" style="padding:5px;border:1px solid #e5e7eb;border-radius:6px;font-size:12px;">${compOpts}</select>
                <select class="wsr-tpl" style="flex:1;min-width:150px;padding:5px;border:1px solid #e5e7eb;border-radius:6px;font-size:12px;">${autoTemplateOptions(row.template||'')}</select>
                <button type="button" onclick="waPreviewRowTpl(this)" title="View message" style="background:none;border:none;cursor:pointer;font-size:15px;padding:2px 4px;">👁</button>
            </div>
        </div>`;
    }

    function renderScheduleEditor(r){
        const cfg = r.config || {};
        const rows = Array.isArray(cfg.rows) ? cfg.rows : [];
        const rowsHtml = rows.map(row => schedRowHtml(r.key, row)).join('');
        const key = waEsc(r.key);
        // Read-only summary up top (grouped by template); the precise editable
        // rows are collapsed below so the card reads cleanly at a glance.
        return `<div style="margin-top:10px;border-top:1px dashed #e5e7eb;padding-top:10px;">
            ${waScheduleSummary(cfg)}
            <button type="button" id="waSchedEditBtn-${key}" class="wa-btn wa-btn-gray" style="padding:4px 10px;font-size:12px;" onclick="waToggleSchedEditor('${key}')">✏️ Edit schedule</button>
            <div id="waSchedEditor-${key}" style="display:none;margin-top:10px;">
                <div style="font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;">Pick a template by when the order arrives (first match wins)</div>
                <div id="waSchedRows-${key}">${rowsHtml}</div>
                <button type="button" class="wa-btn wa-btn-gray" style="padding:4px 10px;font-size:12px;margin-top:4px;" onclick="addSchedRow('${key}')">+ Add rule</button>
                <div style="margin-top:10px;">
                    <label style="font-size:12px;font-weight:600;color:#374151;">Default template (if nothing above matches)
                        <span onclick="waPreviewSelectById('waSchedDefault-${key}')" title="View message" style="cursor:pointer;font-weight:400;color:#2563eb;font-size:11px;margin-left:4px;">👁 view</span></label>
                    <select id="waSchedDefault-${key}" style="width:100%;padding:7px 9px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;margin-top:4px;">${autoTemplateOptions(cfg.default_template||'')}</select>
                </div>
                <div style="font-size:11px;color:#9ca3af;margin-top:6px;">🔒 Customer name and order number fill in automatically. Composition (chicken vs red meat) is read from the order. The summary above refreshes after you Save.</div>
            </div>
        </div>`;
    }

    function renderAutoCard(r){
        const avail = !!r.available;
        const badge = avail
            ? (r.enabled ? '<span style="background:#dcfce7;color:#166534;font-size:11px;padding:2px 8px;border-radius:10px;">On</span>'
                         : '<span style="background:#f3f4f6;color:#6b7280;font-size:11px;padding:2px 8px;border-radius:10px;">Off</span>')
            : '<span style="background:#fef3c7;color:#92400e;font-size:11px;padding:2px 8px;border-radius:10px;">Coming soon</span>';
        let body = '';
        if (!avail){
            body = '<div style="font-size:12px;color:#9ca3af;padding:8px 0;">This automation ships in a later update.</div>';
        } else if ((r.editable||[]).includes('schedule')){
            body = renderScheduleEditor(r);
        } else if ((r.editable||[]).includes('templates_online_cash')){
            const cfg = r.config || {};
            const k = waEsc(r.key);
            const sel = (id, label, val) => `<div style="flex:1;min-width:170px;">
                <label style="font-size:11px;font-weight:600;color:#6b7280;">${label}
                    <span onclick="waPreviewSelectById('${id}')" title="View message" style="cursor:pointer;font-weight:400;color:#2563eb;margin-left:4px;">👁</span></label>
                <select id="${id}" style="width:100%;padding:7px 9px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;margin-top:3px;">${autoTemplateOptions(val)}</select>
            </div>`;
            body = `<div style="margin-top:10px;border-top:1px dashed #e5e7eb;padding-top:10px;">
                <div style="font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">Online / bank-transfer invoice</div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
                    ${sel('waInvOnlineEta-'+k, 'with delivery ETA (3 vars)', cfg.online_eta_template)}
                    ${sel('waInvOnlineNoeta-'+k, 'without ETA (2 vars)', cfg.online_noeta_template)}
                </div>
                <div style="font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;">Cash invoice</div>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    ${sel('waInvCashEta-'+k, 'with delivery ETA (3 vars)', cfg.cash_eta_template)}
                    ${sel('waInvCashNoeta-'+k, 'without ETA (2 vars)', cfg.cash_noeta_template)}
                </div>
                <div style="font-size:11px;color:#9ca3af;margin-top:6px;">The Send Invoice button auto-picks online vs cash by payment method, and the with-ETA version once the delivery time is known (you can still change it before sending).</div>
            </div>`;
        } else if ((r.editable||[]).includes('templates_delivery_online_cash')){
            // Delivered → payment confirmation: TWO templates, picked by the
            // order's payment method read fresh at send time. Both must be set;
            // an unset one makes that half of the rule skip (logged in the
            // activity log) rather than fall back to the other variant, which
            // would send bank details to a cash customer.
            const cfg = r.config || {};
            const dk = waEsc(r.key);
            const dsel = (id, label, val, hint) => `<div style="flex:1;min-width:210px;">
                <label style="font-size:11px;font-weight:600;color:#6b7280;">${label}
                    <span onclick="waPreviewSelectById('${id}')" title="View message" style="cursor:pointer;font-weight:400;color:#2563eb;margin-left:4px;">👁</span></label>
                <select id="${id}" style="width:100%;padding:7px 9px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;margin-top:3px;">${autoTemplateOptions(val)}</select>
                <div style="font-size:11px;color:#9ca3af;margin-top:3px;">${hint}</div>
            </div>`;
            body = `<div style="margin-top:10px;border-top:1px dashed #e5e7eb;padding-top:10px;">
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    ${dsel('waDelivOnline-'+dk, 'Online / bank transfer', cfg.online_template,
                           'Sent only when nothing has been paid yet. Should carry the &quot;Get bank details&quot; button.')}
                    ${dsel('waDelivCash-'+dk, 'Cash', cfg.cash_template,
                           'Plain confirmation — no bank details, no button.')}
                </div>
                <div style="font-size:11px;color:#9ca3af;margin-top:6px;">Both use 4 variables: {1} customer name, {2} order number, {3} delivery date &amp; time, {4} rider name.</div>
            </div>`;
        } else if ((r.editable||[]).includes('template')){
            const tk = waEsc(r.key);
            let tplBody = `<div style="margin-top:10px;border-top:1px dashed #e5e7eb;padding-top:10px;">
                <label style="font-size:12px;font-weight:600;color:#374151;">Template
                    <span onclick="waPreviewSelectById('waTpl-${tk}')" title="View message" style="cursor:pointer;font-weight:400;color:#2563eb;font-size:11px;margin-left:4px;">👁 view</span></label>
                <select id="waTpl-${tk}" style="width:100%;padding:7px 9px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;margin-top:4px;">${autoTemplateOptions(r.template_name)}</select>
            </div>`;
            // Daily send window (location rule) — configured here so the whole
            // automation lives on one screen.
            if ((r.editable||[]).includes('location_window')){
                const cfg = r.config || {};
                const ws = waEsc(cfg.window_start || '09:00');
                const we = waEsc(cfg.window_end || '18:00');
                // Days off: CSV of ISO day numbers (1=Mon..7=Sun); default none.
                const offSet = new Set(String(cfg.window_offdays || '')
                    .split(',').map(s => parseInt(s.trim(), 10)).filter(n => n >= 1 && n <= 7));
                const dayBoxes = [['1','Mon'],['2','Tue'],['3','Wed'],['4','Thu'],['5','Fri'],['6','Sat'],['7','Sun']]
                    .map(([d, lbl]) => `<label style="display:inline-flex;align-items:center;gap:4px;font-size:12px;cursor:pointer;">
                        <input type="checkbox" class="waLocOff-${tk}" data-day="${d}" ${offSet.has(parseInt(d,10))?'checked':''}/> ${lbl}
                    </label>`).join('');
                tplBody += `<div style="margin-top:10px;">
                    <label style="font-size:12px;font-weight:600;color:#374151;">Send only during these hours (Asia/Karachi)</label>
                    <div style="display:flex;gap:8px;align-items:center;margin-top:4px;">
                        <input type="time" id="waLocWinStart-${tk}" value="${ws}" style="padding:6px 8px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;">
                        <span style="color:#9ca3af;font-size:12px;">to</span>
                        <input type="time" id="waLocWinEnd-${tk}" value="${we}" style="padding:6px 8px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;">
                    </div>
                    <div style="font-size:11px;color:#9ca3af;margin-top:4px;">Orders accepted outside these hours are queued and sent automatically when the window next opens.</div>
                </div>
                <div style="margin-top:10px;">
                    <label style="font-size:12px;font-weight:600;color:#374151;">Day off — don't send on these days</label>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:5px;">${dayBoxes}</div>
                    <div style="font-size:11px;color:#9ca3af;margin-top:4px;">On these days nothing is sent. Orders queue and go out automatically on the next working day.</div>
                </div>`;
            }
            // Per-customer cooldown (delivered → storage guidelines). Stops a
            // weekly customer getting the identical message on every delivery.
            if ((r.editable||[]).includes('cooldown')){
                const cfg = r.config || {};
                const cd = (cfg.cooldown_days === undefined || cfg.cooldown_days === null || cfg.cooldown_days === '')
                    ? 30 : parseInt(cfg.cooldown_days, 10);
                tplBody += `<div style="margin-top:10px;">
                    <label style="font-size:12px;font-weight:600;color:#374151;">Don't message the same customer again for</label>
                    <div style="display:flex;gap:8px;align-items:center;margin-top:4px;">
                        <input type="number" min="0" max="365" step="1" id="waCooldown-${tk}" value="${isNaN(cd)?30:cd}"
                               style="width:90px;padding:6px 8px;border:1px solid #e5e7eb;border-radius:6px;font-size:13px;">
                        <span style="font-size:12px;color:#6b7280;">days</span>
                    </div>
                    <div style="font-size:11px;color:#9ca3af;margin-top:4px;">A customer who orders weekly would otherwise get the same message on every delivery. <b>0</b> = send after every single delivery. Each order is still messaged at most once either way.</div>
                </div>`;
            }
            body = tplBody;
        }
        // The on/off toggle only shows for rules that expose 'enabled' (order +
        // location). The invoice card has no auto toggle yet (sending is manual).
        const toggle = (avail && (r.editable||[]).includes('enabled'))
            ? `<label style="display:flex;align-items:center;gap:6px;font-size:12px;cursor:pointer;"><input type="checkbox" id="waEn-${waEsc(r.key)}" ${r.enabled?'checked':''}/> Enabled</label>`
            : '';
        const saveBtn = avail ? `<button type="button" class="wa-btn wa-btn-green" style="padding:6px 14px;font-size:12px;" onclick="saveAutoRule('${waEsc(r.key)}')">Save</button>` : '';
        return `<div style="border:1px solid #e5e7eb;border-radius:12px;padding:14px;margin-bottom:12px;background:#fff;">
            <div style="font-size:14px;font-weight:600;color:#111827;display:flex;align-items:center;gap:8px;">${waEsc(r.label)} ${badge}</div>
            <div style="font-size:12px;color:#6b7280;margin-top:4px;line-height:1.45;">${waEsc(r.description)}</div>
            <div style="font-size:11px;color:#9ca3af;margin-top:4px;">⏱ ${waEsc(r.trigger_summary)}</div>
            ${body}
            <div style="display:flex;align-items:center;gap:12px;margin-top:12px;">${toggle}<div style="margin-left:auto;">${saveBtn}</div></div>
        </div>`;
    }

    window.addSchedRow = function(key){
        const host = document.getElementById('waSchedRows-'+key);
        if (host) host.insertAdjacentHTML('beforeend', schedRowHtml(key, {}));
    };

    function gatherSchedule(key){
        const rows = [];
        const host = document.getElementById('waSchedRows-'+key);
        if (host){
            host.querySelectorAll('.wa-sched-row').forEach(div => {
                const days = Array.from(div.querySelectorAll('.wsd')).filter(c => c.checked).map(c => parseInt(c.dataset.day,10));
                rows.push({
                    days: days,
                    start: div.querySelector('.wsr-start').value || null,
                    end: div.querySelector('.wsr-end').value || null,
                    composition: div.querySelector('.wsr-comp').value,
                    template: div.querySelector('.wsr-tpl').value,
                });
            });
        }
        const def = document.getElementById('waSchedDefault-'+key);
        return { rows: rows, default_template: def ? def.value : '' };
    }

    window.saveAutoRule = function(key){
        const r = _waAutoRules.find(x => x.key===key);
        if (!r) return;
        const enabled = !!(document.getElementById('waEn-'+key) && document.getElementById('waEn-'+key).checked);
        const payload = { enabled: enabled };
        if ((r.editable||[]).includes('schedule')) payload.config = gatherSchedule(key);
        else if ((r.editable||[]).includes('templates_online_cash')) payload.config = {
            online_eta_template:   (document.getElementById('waInvOnlineEta-'+key) || {}).value || '',
            online_noeta_template: (document.getElementById('waInvOnlineNoeta-'+key) || {}).value || '',
            cash_eta_template:     (document.getElementById('waInvCashEta-'+key) || {}).value || '',
            cash_noeta_template:   (document.getElementById('waInvCashNoeta-'+key) || {}).value || '',
        };
        else if ((r.editable||[]).includes('templates_delivery_online_cash')) payload.config = {
            online_template: (document.getElementById('waDelivOnline-'+key) || {}).value || '',
            cash_template:   (document.getElementById('waDelivCash-'+key) || {}).value || '',
        };
        else if ((r.editable||[]).includes('template')) {
            payload.template_name = (document.getElementById('waTpl-'+key) || {}).value || null;
            if ((r.editable||[]).includes('location_window')) {
                payload.config = {
                    window_start: (document.getElementById('waLocWinStart-'+key) || {}).value || '',
                    window_end:   (document.getElementById('waLocWinEnd-'+key) || {}).value || '',
                    window_offdays: Array.from(document.querySelectorAll('.waLocOff-'+key))
                        .filter(c => c.checked).map(c => parseInt(c.dataset.day, 10)),
                };
            }
            if ((r.editable||[]).includes('cooldown')) {
                const raw = (document.getElementById('waCooldown-'+key) || {}).value;
                const n = parseInt(raw, 10);
                // Blank / junk falls back to the 30-day default rather than 0 —
                // 0 means "message on every delivery", which must be a DELIBERATE
                // choice, never the result of an empty box.
                payload.config = Object.assign(payload.config || {}, {
                    cooldown_days: isNaN(n) ? 30 : Math.max(0, Math.min(365, n)),
                });
            }
        }
        apiFetch('/messages/automations/rules/'+encodeURIComponent(key), { method:'POST', body: JSON.stringify(payload) })
            .then(d => {
                if (d.success){ if (d.blocked) alert('This automation is not available yet.'); loadAutomations(); }
                else alert(d.message || 'Failed to save.');
            }).catch(() => alert('Failed to save.'));
    };

    window.toggleAutoMaster = function(checked){
        document.getElementById('waAutoMasterLabel').textContent = 'Automations: '+(checked?'On':'Off');
        apiFetch('/messages/automations/toggle', { method:'POST', body: JSON.stringify({ enabled: checked }) })
            .then(d => { if (!d.success){ document.getElementById('waAutoMaster').checked = !checked; document.getElementById('waAutoMasterLabel').textContent = 'Automations: '+(!checked?'On':'Off'); alert(d.message||'Failed.'); } })
            .catch(() => { document.getElementById('waAutoMaster').checked = !checked; alert('Failed.'); });
    };

    window.saveAutoTestPhone = function(){
        const phone = document.getElementById('waAutoTestPhone').value.trim();
        apiFetch('/messages/automations/test-phone', { method:'POST', body: JSON.stringify({ phone: phone }) })
            .then(d => { if (d.success) alert(phone ? ('Test mode ON — automation sends go to '+(d.test_phone||phone)) : 'Test mode off — sends go to real customers.'); else alert(d.message||'Failed.'); })
            .catch(() => alert('Failed.'));
    };

    window.toggleAutoLog = function(){
        const box = document.getElementById('waAutoLog');
        if (box.style.display !== 'none'){ box.style.display = 'none'; return; }
        box.style.display = '';
        box.innerHTML = '<div style="color:#9ca3af;font-size:12px;padding:10px;">Loading…</div>';
        apiFetch('/messages/automations/log').then(d => {
            if (!d.success){ box.innerHTML = '<div style="color:#ef4444;font-size:12px;padding:10px;">Failed to load log.</div>'; return; }
            const c = d.counts || {};
            const head = `<div style="display:flex;gap:8px;margin-bottom:8px;font-size:12px;flex-wrap:wrap;">
                <span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:10px;">Sent ${c.sent||0}</span>
                <span style="background:#fee2e2;color:#b91c1c;padding:2px 8px;border-radius:10px;">Failed ${c.failed||0}</span>
                <span style="background:#f3f4f6;color:#6b7280;padding:2px 8px;border-radius:10px;">Skipped ${c.skipped||0}</span>
                <span style="color:#9ca3af;align-self:center;">last 7 days</span></div>`;
            const rows = (d.log||[]).slice(0,50).map(l => {
                const color = l.status==='sent' ? '#166534' : (l.status==='failed' ? '#b91c1c' : '#6b7280');
                const detail = l.skip_reason || l.error_message || l.template_name || '';
                return `<div style="display:flex;gap:8px;font-size:12px;padding:4px 0;border-bottom:1px solid #f3f4f6;">
                    <span style="color:${color};font-weight:600;width:62px;">${waEsc(l.status)}</span>
                    <span style="color:#374151;flex:1;">${waEsc(l.rule_key)}${l.order_id?(' · #'+waEsc(l.order_id)):''}${detail?(' · '+waEsc(detail)):''}</span>
                    <span style="color:#9ca3af;white-space:nowrap;">${l.created_at?waEsc(String(l.created_at).slice(0,16).replace('T',' ')):''}</span></div>`;
            }).join('') || '<div style="color:#9ca3af;font-size:12px;padding:10px;">No activity yet.</div>';
            box.innerHTML = `<div style="border:1px solid #e5e7eb;border-radius:8px;padding:10px;background:#fafafa;">${head}${rows}</div>`;
        }).catch(() => { box.innerHTML = '<div style="color:#ef4444;font-size:12px;padding:10px;">Failed to load log.</div>'; });
    };

    function apiFetch(url, opts = {}) {
        opts.headers = { ...(opts.headers || {}), 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json', 'Content-Type': 'application/json' };
        return fetch(url, opts).then(r => r.json());
    }

    function fmtTime(iso) {
        if (!iso) return '';
        const d = new Date(iso), now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const msgDay = new Date(d.getFullYear(), d.getMonth(), d.getDate());
        const diffDays = Math.round((today - msgDay) / 86400000);
        if (diffDays === 0) return d.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
        if (diffDays === 1) return 'Yesterday';
        if (diffDays < 7) return d.toLocaleDateString([], {weekday:'short'});
        return d.toLocaleDateString([], {day:'numeric',month:'short'});
    }
    function fmtMsgTime(iso) {
        if (!iso) return '';
        const d = new Date(iso), now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const msgDay = new Date(d.getFullYear(), d.getMonth(), d.getDate());
        const diffDays = Math.round((today - msgDay) / 86400000);
        const time = d.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'});
        if (diffDays === 0) return time;
        if (diffDays === 1) return 'Yesterday ' + time;
        return d.toLocaleDateString([], {day:'numeric',month:'short'}) + ' ' + time;
    }
    function fmtDateLabel(iso) {
        if (!iso) return '';
        const d = new Date(iso), now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const msgDay = new Date(d.getFullYear(), d.getMonth(), d.getDate());
        const diffDays = Math.round((today - msgDay) / 86400000);
        if (diffDays === 0) return 'Today';
        if (diffDays === 1) return 'Yesterday';
        if (diffDays < 7) return d.toLocaleDateString([], {weekday:'long'});
        return d.toLocaleDateString([], {weekday:'short', day:'numeric', month:'short', year:'numeric'});
    }
    function statusIcon(s) {
        return {sent:'✓',delivered:'✓✓',read:'✓✓',failed:'✕'}[s] || '';
    }
    function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    let sessionTimerInterval = null;
    function updateSessionUI(conv) {
        const sessionActive = conv.session_active;
        document.getElementById('waSessionBadge').style.display = sessionActive ? 'none' : 'block';
        document.getElementById('waSessionExpiredBar').style.display = sessionActive ? 'none' : 'block';
        document.getElementById('waActiveSessionInput').style.display = sessionActive ? 'block' : 'none';

        if (sessionTimerInterval) { clearInterval(sessionTimerInterval); sessionTimerInterval = null; }
        const timerEl = document.getElementById('waSessionTimer');
        if (!conv.session_expires_at || !sessionActive) {
            timerEl.style.display = 'none';
            return;
        }
        function tick() {
            const remaining = new Date(conv.session_expires_at) - new Date();
            if (remaining <= 0) {
                timerEl.style.display = 'none';
                document.getElementById('waSessionBadge').style.display = 'block';
                document.getElementById('waSessionExpiredBar').style.display = 'block';
                document.getElementById('waActiveSessionInput').style.display = 'none';
                if (sessionTimerInterval) { clearInterval(sessionTimerInterval); sessionTimerInterval = null; }
                return;
            }
            const h = Math.floor(remaining / 3600000);
            const m = Math.floor((remaining % 3600000) / 60000);
            timerEl.style.display = 'block';
            timerEl.className = 'wa-session-timer' + (h === 0 && m < 30 ? ' expiring' : '');
            timerEl.textContent = h > 0
                ? `Free messaging window closes in ${h}h ${m}m`
                : `Free messaging window closes in ${m}m`;
        }
        tick();
        sessionTimerInterval = setInterval(tick, 30000);
    }

    // ── Conversations ──
    //
    // loadConversations() refreshes the LIVE head — i.e. the top 200 most-
    // recent conversations server-side. Called by the 10s poll, the search
    // box, the filter pills, and post-action callbacks. Whenever the user
    // changes search/filter we also drop the extras tail because the cursor
    // becomes meaningless under the new criteria.
    //
    // loadMoreConversations() pulls the NEXT page (100 older rows) using
    // the cursor we got back from the previous call. It only ever appends
    // to convListExtra; the live head keeps updating independently.
    function loadConversations() {
        const search = document.getElementById('waSearch').value.trim();
        // Treat search/filter changes as a reset for the "Load more" tail.
        // The cursor from the previous query no longer applies once the
        // server-side WHERE clause has changed.
        const sigChanged = (search !== convListLastSearch) ||
                           (currentFilter !== convListLastFilter);
        if (sigChanged) {
            convListExtra = [];
            convListMoreCursor = null;
            convListMoreExhausted = false;
            convListLoadingMore = false;
            convListLastSearch = search;
            convListLastFilter = currentFilter;
        }

        let url = '/messages/conversations?filter=' + currentFilter;
        if (search) {
            url += '&search=' + encodeURIComponent(search);
            // Only meaningful when there's an actual search term; saves us
            // sending the mode on every no-search refresh.
            url += '&search_mode=' + encodeURIComponent(currentSearchMode);
        }
        apiFetch(url).then(d => {
            if (!d.success) return;
            convListLive = d.conversations || [];
            // Only adopt the head's cursor when the user hasn't already
            // paged into the tail — otherwise convListMoreCursor must keep
            // pointing at the bottom of the LATEST loaded page, not the
            // bottom of the live head.
            if (!convListExtra.length) {
                convListMoreCursor   = d.next_cursor || null;
                convListMoreExhausted = !d.has_more;
            }
            // Apr-2026: refresh the Mark-All-Read row. The server returns
            // total_unread only when filter=unread; on any other filter we
            // hide the row entirely. Count is the DB-wide unread total
            // (not just what's currently in the loaded list).
            updateMarkAllReadVisibility(typeof d.total_unread === 'number' ? d.total_unread : 0);
            repaintConvList({ searchMode: currentSearchMode, searchTerm: search });
        });
    }

    // Apr-2026: Mark-All-Read row visibility helper. Three gates:
    //   1. Server-side: button only rendered for super-readers (Blade
    //      gate) — so for regular users this function is harmless,
    //      it just no-ops on a missing row element.
    //   2. Filter: only show on the Unread tab (currentFilter==='unread').
    //   3. Count > 0: nothing to clear → don't show the action.
    function updateMarkAllReadVisibility(totalUnread) {
        const row = document.getElementById('waMarkAllReadRow');
        if (!row) return; // not a super-reader
        const show = (currentFilter === 'unread') && (totalUnread > 0);
        row.style.display = show ? 'flex' : 'none';
        if (show) {
            const cntEl = document.getElementById('waMarkAllReadCount');
            if (cntEl) cntEl.textContent = totalUnread;
        }
    }
    window.updateMarkAllReadVisibility = updateMarkAllReadVisibility;

    // Confirm + post handler. Disables the button while in flight so a
    // double-click doesn't fire two requests, both of which would succeed
    // — the second would just clear 0 and report no harm — but it's tidier
    // to gate it client-side too.
    window.confirmMarkAllRead = function() {
        const cntEl = document.getElementById('waMarkAllReadCount');
        const total = cntEl ? (cntEl.textContent || '?') : '?';
        const ok = window.confirm(
            'Mark all ' + total + ' unread conversations as read for everyone?\n\n' +
            'This will clear the inbox badge for the entire team. ' +
            'You can still mark individual chats unread again afterwards.'
        );
        if (!ok) return;
        const btn = document.getElementById('waMarkAllReadBtn');
        if (btn) { btn.disabled = true; btn.querySelector('span:last-child').textContent = 'Clearing…'; }
        apiFetch('/messages/mark-all-read', { method: 'POST' })
            .then(d => {
                if (!d || !d.success) {
                    alert(d && d.message ? d.message : 'Failed to mark all as read');
                    if (btn) { btn.disabled = false; }
                    return;
                }
                // Reload the conversation list (will be empty for Unread)
                // and refresh the sidebar badge so the team sees zero too
                // on their next poll.
                loadConversations();
                if (typeof window.refreshUnreadBadge === 'function') {
                    try { window.refreshUnreadBadge(); } catch (e) {}
                }
            })
            .catch(() => {
                alert('Network error while marking all as read');
                if (btn) { btn.disabled = false; }
            });
    };

    // Fetch the NEXT page using the stored cursor and append to extras.
    // Idempotent re-entrancy guard via convListLoadingMore so a rapid
    // double-click doesn't fetch the same page twice.
    function loadMoreConversations() {
        if (convListLoadingMore || convListMoreExhausted || !convListMoreCursor) return;
        convListLoadingMore = true;
        // Reflect "Loading..." on the button immediately so the user knows
        // the click was registered even on a slow connection.
        repaintConvList({
            searchMode: currentSearchMode,
            searchTerm: (document.getElementById('waSearch').value || '').trim(),
        });

        const search = (document.getElementById('waSearch').value || '').trim();
        let url = '/messages/conversations?filter=' + currentFilter
                + '&before_last_message_at=' + encodeURIComponent(convListMoreCursor);
        if (search) {
            url += '&search=' + encodeURIComponent(search);
            url += '&search_mode=' + encodeURIComponent(currentSearchMode);
        }
        apiFetch(url).then(d => {
            convListLoadingMore = false;
            if (!d || !d.success) {
                repaintConvList({ searchMode: currentSearchMode, searchTerm: search });
                return;
            }
            const incoming = Array.isArray(d.conversations) ? d.conversations : [];
            // Append to extras. Dedup happens at render time, so we don't
            // need to splice anything out here even if the server returned
            // a row that's now also in the live head.
            convListExtra = convListExtra.concat(incoming);
            convListMoreCursor    = d.next_cursor || null;
            convListMoreExhausted = !d.has_more || !convListMoreCursor;
            repaintConvList({ searchMode: currentSearchMode, searchTerm: search });
        }).catch(() => {
            convListLoadingMore = false;
            repaintConvList({ searchMode: currentSearchMode, searchTerm: search });
        });
    }
    window.loadMoreConversations = loadMoreConversations;

    // Merge live + extras (deduping by id, live wins because it's fresher),
    // hand to renderConversations, and append the Load More footer.
    function repaintConvList(opts) {
        const seen = new Set();
        const merged = [];
        (convListLive || []).forEach(c => { if (!seen.has(c.id)) { seen.add(c.id); merged.push(c); } });
        (convListExtra || []).forEach(c => { if (!seen.has(c.id)) { seen.add(c.id); merged.push(c); } });
        renderConversations(merged, Object.assign({
            showLoadMore: !convListMoreExhausted && !!convListMoreCursor,
            loadingMore: convListLoadingMore,
            extraCount: convListExtra.length,
        }, opts || {}));
    }

    // Phase 2 — poll the mentions count every 30s and keep the red pip
    // on the @me filter button in sync even when the user isn't actively
    // filtering. Only runs when the user has any chance of seeing a
    // mention (i.e. they have WA view perm — we can approximate by just
    // trying and ignoring 403s).
    function refreshMentionsPip() {
        apiFetch('/messages/mentions-count').then(d => {
            if (!d || !d.success) return;
            const pip = document.getElementById('waMentionPip');
            if (!pip) return;
            if ((d.mentions_count || 0) > 0) {
                pip.style.display = 'inline-block';
            } else {
                pip.style.display = 'none';
            }
        }).catch(() => {});
    }
    window.toggleAssignedToMe = function() {
        assignedToMe = !assignedToMe;
        const btn = document.getElementById('waFilterMe');
        if (btn) btn.classList.toggle('active', assignedToMe);
        loadConversations();
    };

    function renderConversations(convs, opts = {}) {
        const el = document.getElementById('waConvList');
        const inChatSearch = opts.searchMode === 'chats' && (opts.searchTerm || '').length > 0;
        if (!convs.length) {
            el.innerHTML = inChatSearch
                ? '<div class="wa-loading">No messages match “' + esc(opts.searchTerm) + '”.<br><span style="font-size:12px;color:#9ca3af;">Try a shorter word or switch back to Names.</span></div>'
                : '<div class="wa-loading">No conversations found</div>';
            return;
        }
        const rowsHtml = convs.map(c => {
            const isActive = c.id === activeConvId;
            const isUnread = c.unread_count > 0;
            let cls = 'wa-conv-item';
            if (isActive) cls += ' active';
            if (isUnread) cls += ' unread';
            // Aug-2026: a teammate tagged ME here recently and I haven't opened
            // it yet. The server has already floated these to the top; the tint
            // + caption make it read as a section rather than a list that
            // reshuffled itself. Clears on open (mention_seen_at is stamped).
            if (c.pinned_mention) cls += ' pinned-mention';
            // Only show the goat badge when the Qurbani feature is enabled
            // (master switch in settings drawer); we hide the badge otherwise.
            const qBadge = (waQurbaniEnabled && c.is_qurbani) ? '<span title="Qurbani conversation" style="margin-right:4px;font-size:14px;">🐐</span>' : '';
            // Apr-2026: failed-send indicator. Surfaced when the latest
            // outbound message in this conversation (within last 7 days)
            // came back with status='failed'. Tooltip carries the error
            // reason so the operator knows what to retry without first
            // opening the chat. Cleared automatically on next successful
            // send (server-side computation in getConversations).
            const failBadge = c.last_send_failed
                ? `<span class="wa-conv-failed-pin" title="${esc('Last send failed' + (c.last_send_error ? ': ' + c.last_send_error : '') + (c.last_send_template ? ' (template: ' + c.last_send_template + ')' : ''))}">⚠</span>`
                : '';
            // Chat-search mode: append a snippet of the matched message
            // under the last-message preview, with the query highlighted.
            // We still show the last-message line above so operators keep
            // their usual context of "where is this conversation at now".
            const matchBlock = (inChatSearch && c.match_snippet)
                ? `<div class="wa-conv-match" title="Matched message content">
                        ${c.match_direction === 'outbound' ? '✓ ' : ''}${highlightMatch(c.match_snippet, opts.searchTerm)}${c.match_count > 1 ? ` <span style="color:#6366f1;font-weight:600;">+${c.match_count - 1} more</span>` : ''}
                   </div>`
                : '';
            // Jun-2026: payment-proof badge (customer sent bank screenshot / email confirmed).
            const pp = c.payment_proof;
            const proofBadge = (pp && pp.status && pp.status !== 'none')
                ? `<span title="${esc(pp.label)}" style="margin-left:4px; display:inline-flex; align-items:center; padding:0 5px; border-radius:7px; font-size:10px; font-weight:700; background:${pp.color}1A; color:${pp.color}; border:1px solid ${pp.color}55;">${pp.has_whatsapp?'📷':''}${pp.has_sms?'📱':''}${pp.has_email?'✉️':''}</span>`
                : '';
            // Aug-2026: this customer sent a location pin we did not save and
            // nobody has dealt with it yet. Sits with the other attention badges
            // so the inbox surfaces it without anyone opening the chat.
            const pinBadge = c.pin_reply_pending
                ? `<span title="${esc('Location pin waiting' + (c.pin_reply_pending.away_text ? ' — ' + c.pin_reply_pending.away_text : '') + (c.pin_reply_pending.at_human ? ' (' + c.pin_reply_pending.at_human + ')' : ''))}" style="margin-left:4px;display:inline-flex;align-items:center;padding:0 5px;border-radius:7px;font-size:10px;font-weight:700;background:#FEF3C7;color:#92400E;border:1px solid #FCD34D;">📍</span>`
                : '';
            return `<div class="${cls}" onclick="openConv(${c.id})" data-id="${c.id}">
                <div class="wa-avatar">${(c.customer_name||'?')[0].toUpperCase()}</div>
                <div class="wa-conv-info">
                    <div class="wa-conv-top">
                        <div class="wa-conv-name">${qBadge}${failBadge}${esc(c.customer_name || c.wa_phone)}${proofBadge}${pinBadge}</div>
                        <div class="wa-conv-time">${fmtTime(c.last_message_at)}</div>
                    </div>
                    <div class="wa-conv-bottom">
                        <div class="wa-conv-preview">${c.last_message_direction==='outbound'?'✓ ':''}${esc(c.last_message_preview||'No messages yet')}</div>
                        ${isUnread ? `<div class="wa-unread-badge">${c.unread_count}</div>` : ''}
                    </div>
                    ${matchBlock}
                    ${c.pinned_mention ? `<div class="wa-conv-pinned" title="Tagged for you — opens and clears">📌 ${esc(c.pinned_mention.label)} · by ${esc(c.pinned_mention.by)} · ${esc(c.pinned_mention.ago)}</div>` : ''}
                    ${c.customer_city ? `<div class="wa-conv-city">${esc(c.customer_city)}</div>` : ''}
                </div>
            </div>`;
        }).join('');

        // Load-more footer. Hidden during chat-search (results are already
        // capped + ranked server-side and an "older messages" pagination
        // there would surface noise). For the regular inbox it sits at the
        // bottom of the list and triggers loadMoreConversations() — which
        // appends the next 100 older conversations to convListExtra.
        let footerHtml = '';
        if (!inChatSearch) {
            if (opts.showLoadMore) {
                footerHtml = `
                    <div class="wa-conv-loadmore">
                        <button type="button"
                                class="wa-conv-loadmore-btn"
                                onclick="loadMoreConversations()"
                                ${opts.loadingMore ? 'disabled' : ''}>
                            ${opts.loadingMore
                                ? '<span class="wa-conv-loadmore-spin"></span>Loading…'
                                : 'Load older chats'}
                        </button>
                        ${opts.extraCount > 0
                            ? `<div class="wa-conv-loadmore-hint">${opts.extraCount} older chat${opts.extraCount === 1 ? '' : 's'} loaded · click for 100 more</div>`
                            : '<div class="wa-conv-loadmore-hint">Showing the most recent 200 — older chats are still searchable</div>'}
                    </div>`;
            } else if (opts.extraCount > 0) {
                footerHtml = `<div class="wa-conv-loadmore-end">No more chats to load · ${opts.extraCount} older chat${opts.extraCount === 1 ? '' : 's'} loaded.</div>`;
            }
        }

        el.innerHTML = rowsHtml + footerHtml;
    }

    // Escape-and-highlight helper for chat-search snippets. Escapes the
    // whole snippet first, then wraps case-insensitive occurrences of the
    // search term in <mark>. Operates on text (not HTML) so XSS is safe.
    function highlightMatch(snippet, term) {
        const safe = esc(snippet);
        if (!term) return safe;
        const pattern = term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        return safe.replace(new RegExp('(' + pattern + ')', 'ig'), '<mark>$1</mark>');
    }

    // ── Open Conversation ──
    window.openConv = function(id) {
        activeConvId = id;
        document.getElementById('waChat').classList.add('visible');
        document.getElementById('waEmptyState').style.display = 'none';
        document.getElementById('waChatMessages').innerHTML = '<div class="wa-loading">Loading...</div>';
        // May-2026 — opening a different conversation always exits
        // history-scrollback mode. Polling for the new conversation
        // should resume its normal "snap to latest N" behaviour.
        waMsgs = [];
        waMsgsHasMore = false;
        waMsgsHistoryMode = false;
        waMsgsLoadingOlder = false;
        // Phase 4 (May-2026): clear any stale active-delivery banner
        // from the previously-open conversation. loadOrdersPanel()
        // below will repopulate it for the new customer.
        if (typeof renderActiveDeliveryBanner === 'function') {
            renderActiveDeliveryBanner(null);
        }

        document.querySelectorAll('.wa-conv-item').forEach(el => {
            el.classList.toggle('active', parseInt(el.dataset.id) === id);
        });

        apiFetch('/messages/conversations/' + id).then(d => {
            if (!d.success) return;
            activeConv = d.conversation;
            const name = d.conversation.customer_name || d.conversation.wa_phone;
            const qPrefix = (waQurbaniEnabled && d.conversation.is_qurbani) ? '🐐 ' : '';
            document.getElementById('waChatName').textContent = qPrefix + name;
            document.getElementById('waChatAvatar').textContent = (name || '?')[0].toUpperCase();
            let sub = d.conversation.wa_phone;
            if (d.conversation.customer_city) sub += ' · ' + d.conversation.customer_city;
            if (d.conversation.customer_orders) sub += ' · ' + d.conversation.customer_orders + ' orders';
            document.getElementById('waChatSub').textContent = sub;

            updateSessionUI(d.conversation);

            renderMessages(d.messages, d.has_more);

            // Show/hide orders toggle button and load orders if customer is linked
            const toggleBtn = document.getElementById('waOrdersToggle');
            if (d.conversation.customer_id) {
                toggleBtn.style.display = 'inline-block';
                loadOrdersPanel(d.conversation.customer_id);
            } else {
                toggleBtn.style.display = 'none';
                document.getElementById('waOrdersList').innerHTML = '<div style="padding:20px;text-align:center;color:#9ca3af;font-size:13px;">No linked customer</div>';
            }
        });

        // Strip the unread badge off this row in the DOM right away so
        // a previously "Mark as Unread"-ed conversation doesn't keep the
        // dot until the next inbox poll. The server-side implicit
        // markRead inside getMessages has already cleared the forced
        // flag; this is just a UI sync.
        try {
            const row = document.querySelector('.wa-conv-item[data-id="' + id + '"]');
            if (row) {
                row.classList.remove('unread');
                row.querySelectorAll('.wa-unread-badge').forEach(b => b.remove());
            }
        } catch (_) {}

        apiFetch('/messages/conversations/' + id + '/mark-read', {method:'POST'})
            .then(() => {
                // Once the server confirms, re-pull the inbox so any other
                // staleness (last-message preview, label badges, etc.) is
                // refreshed too. Cheap because loadConversations is the
                // same call the regular poll makes.
                if (typeof loadConversations === 'function') {
                    try { loadConversations(); } catch (_) {}
                }
            })
            .catch(() => {});

        if (msgPollTimer) clearInterval(msgPollTimer);
        msgPollTimer = setInterval(() => {
            if (activeConvId !== id) return;
            // May-2026 — while the user is scrolled into older history
            // (after clicking "Load older messages"), the poll must NOT
            // wholesale-replace the message list. Doing so would yank
            // the chat back to the latest 50 and wipe whatever older
            // batch they were reading. We still keep mark-read in sync
            // so unread counts don't get stuck. The user gets back to
            // the live tail by clicking the "Jump to latest" pill that
            // loadOlderMessages() renders.
            if (waMsgsHistoryMode) {
                apiFetch('/messages/conversations/' + id + '/mark-read', {method:'POST'});
                return;
            }
            apiFetch('/messages/conversations/' + id).then(d => {
                if (!d.success || activeConvId !== id) return;
                activeConv = d.conversation;
                updateSessionUI(d.conversation);
                renderMessages(d.messages, d.has_more);
            });
            apiFetch('/messages/conversations/' + id + '/mark-read', {method:'POST'});
        }, POLL_INTERVAL);
    };

    // Aug-2026 — find the server's verdict for one inbound location message.
    // Nearest verdict within two minutes wins (the audit row is written by the
    // same webhook request that saved the message, so they are near-simultaneous);
    // each verdict is consumed once so repeated pins do not share an outcome.
    // Returns null when nothing matches — e.g. messages that predate this
    // feature, which must stay silent rather than claim an outcome.
    function pinVerdictFor(msgIso, used) {
        const list = (activeConv && activeConv.location_verdicts) || [];
        if (!list.length || !msgIso) return null;
        const t = new Date(msgIso).getTime();
        if (isNaN(t)) return null;
        let best = null, bestIdx = -1, bestGap = 120000; // 2 minutes
        for (let i = 0; i < list.length; i++) {
            if (used.has(i) || !list[i].at) continue;
            const vt = new Date(list[i].at).getTime();
            if (isNaN(vt)) continue;
            const gap = Math.abs(vt - t);
            if (gap <= bestGap) { best = list[i]; bestIdx = i; bestGap = gap; }
        }
        if (bestIdx >= 0) used.add(bestIdx);
        return best;
    }

    // Amber bar across the top of the chat when a pin this customer sent is
    // still sitting unused. The bubble hint alone is not enough — it scrolls
    // out of view, which is precisely how the Aug-2 pin went unnoticed.
    function renderPinPendingBar() {
        const host = document.getElementById('waPinPendingBar');
        if (!host) return;
        const p = activeConv && activeConv.pin_reply_pending;
        if (!p) { host.style.display = 'none'; host.innerHTML = ''; return; }
        let h = '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:7px 12px;background:#FFFBEB;border-bottom:1px solid #FCD34D;">';
        h += '<span style="font-size:12px;font-weight:700;color:#92400E;">⚠️ ' + esc(p.headline || 'A pin from this customer is waiting') + '</span>';
        if (p.away_text) h += '<span style="font-size:11.5px;color:#78350F;">' + esc(p.away_text) + '</span>';
        if (p.at_human) h += '<span style="font-size:11.5px;color:#B45309;">· ' + esc(p.at_human) + '</span>';
        if (p.offered && p.offered.maps_url) {
            h += '<a href="' + esc(p.offered.maps_url) + '" target="_blank" style="margin-left:auto;font-size:11.5px;font-weight:700;color:#92400E;text-decoration:underline;">See their pin ↗</a>';
        }
        h += '</div>';
        host.innerHTML = h;
        host.style.display = 'block';
    }

    // Oct-2026 — "⏳ Taimur hasn't opened this" strip for the person who TAGGED on this chat.
    // The server sends conversation.followup only to that person (null for everyone else) and
    // refreshes it on every chat poll, so the minutes tick and the strip turns green the
    // moment Taimur opens the chat. Buttons POST to the same engine the phone uses.
    let waTagWaitNote = '', waTagWaitNoteConv = null; // the confirmation belongs to ONE chat
    function renderTagWaitBar() {
        const host = document.getElementById('waTagWaitBar');
        if (!host) return;
        const f = activeConv && activeConv.followup;
        if (!f) { host.style.display = 'none'; host.innerHTML = ''; return; }
        const who = esc(f.on_user || 'They');
        let bg = '#FFFBEB', border = '#FCD34D', color = '#92400E', text = '', buttons = '';
        if (f.state === 'watching') {
            text = '⏳ ' + who + " hasn't opened this · " + f.minutes + ' min'
                 + ' <span style="font-weight:500;color:#B45309;">· reminder ' + f.remind_count + ' of ' + f.max_reminders
                 + (f.next_at ? ' · next ' + esc(f.next_at) : '') + '</span>';
            const b = (act, label) => '<button type="button" onclick="waTagFollowupAct(' + f.id + ',\'' + act + '\')" '
                + 'style="font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:6px;border:1px solid #F59E0B;background:#fff;color:#92400E;cursor:pointer;">' + label + '</button>';
            buttons = b('called', "📞 I'll call him") + b('wait10', '⏰ Wait 10 min') + b('stop', '🔕 Stop reminders');
        } else if (f.state === 'muted') {
            bg = '#F3F4F6'; border = '#D1D5DB'; color = '#374151';
            text = '🔕 Reminders stopped · ' + who + " hasn't opened this yet (" + f.minutes + ' min)';
        } else if (f.state === 'gave_up') {
            bg = '#FEF2F2'; border = '#FCA5A5'; color = '#991B1B';
            text = who + " didn't open this in 2 hours — no more reminders; it's in Daily Issues.";
        } else if (f.state === 'opened') {
            bg = '#ECFDF5'; border = '#6EE7B7'; color = '#065F46';
            text = '✓ ' + who + ' opened it' + (f.opened_at ? ' at ' + esc(f.opened_at) : '') + ' (after ' + f.minutes + ' min)';
        } else if (f.state === 'acknowledged') {
            bg = '#ECFDF5'; border = '#6EE7B7'; color = '#065F46';
            text = '👍 ' + who + ' is on it' + (f.acknowledged_at ? ' (' + esc(f.acknowledged_at) + ')' : '');
        } else {
            host.style.display = 'none'; host.innerHTML = ''; return;
        }
        host.innerHTML = '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:7px 12px;background:' + bg + ';border-bottom:1px solid ' + border + ';">'
            + '<span style="font-size:12px;font-weight:700;color:' + color + ';">' + text + '</span>'
            + (buttons ? '<span style="margin-left:auto;display:inline-flex;gap:6px;flex-wrap:wrap;">' + buttons + '</span>' : '')
            + (waTagWaitNote && waTagWaitNoteConv === activeConvId ? '<span style="flex-basis:100%;font-size:11.5px;color:' + color + ';">' + esc(waTagWaitNote) + '</span>' : '')
            + '</div>';
        host.style.display = 'block';
    }
    window.waTagFollowupAct = function(id, action) {
        const convId = activeConvId;
        apiFetch('/messages/mention-followups/' + id + '/action', {method: 'POST', body: JSON.stringify({action: action})})
            .then(r => {
                if (activeConvId !== convId) return;
                waTagWaitNoteConv = convId;
                waTagWaitNote = (r && r.message) ? r.message : 'Could not save that — try again.';
                if (r && 'followup' in r && activeConv) activeConv.followup = r.followup;
                renderTagWaitBar();
                setTimeout(() => { waTagWaitNote = ''; if (activeConvId === convId) renderTagWaitBar(); }, 5000);
            })
            .catch(() => { waTagWaitNoteConv = convId; waTagWaitNote = 'Could not save that — try again.'; renderTagWaitBar(); });
    };

    function renderMessages(msgs, hasMore, preserveHistoryMode) {
        const el = document.getElementById('waChatMessages');
        // May-2026 — Cache the just-rendered list so loadOlderMessages()
        // knows the current oldest visible id (waMsgs[0].id) to walk
        // back from. Polling + send + initial-open all flow through
        // here. The third arg (preserveHistoryMode) is true ONLY when
        // loadOlderMessages prepends an older batch — every other path
        // (initial open, poll, send, template send, invoice send,
        // jumpToLatest) leaves it falsy so we snap back to the live
        // tail and stop showing the "Jump to latest" pill.
        waMsgs = Array.isArray(msgs) ? msgs : [];
        waMsgsHasMore = !!hasMore;
        if (!preserveHistoryMode) waMsgsHistoryMode = false;
        let html = '';
        if (waMsgsHistoryMode) {
            // While viewing older history, give the user a one-tap
            // way back to the live tail (also exits history-mode so
            // the regular poll resumes wholesale refreshes).
            html += '<div class="wa-load-more"><a onclick="jumpToLatestMessages()" style="color:#16a34a;font-weight:600;">↓ Jump to latest messages</a></div>';
        }
        if (hasMore) {
            html += '<div class="wa-load-more"><a onclick="loadOlderMessages()">Load older messages</a></div>';
        }
        if (!msgs.length) {
            html += '<div class="wa-loading">No messages in this conversation</div>';
        }
        let lastDateKey = null;
        // Anchor the "Seen by" indicator under the LAST INBOUND message
        // (i.e. the customer's most recent note). That way the team can see
        // at a glance which colleagues have already opened the chat since
        // that message arrived — useful to avoid double-replies when the
        // customer is waiting on us.
        let lastInIdx = -1;
        for (let i = msgs.length - 1; i >= 0; i--) {
            if (msgs[i].direction === 'inbound') { lastInIdx = i; break; }
        }
        const lastInTs = lastInIdx >= 0 ? new Date(msgs[lastInIdx].created_at).getTime() : 0;
        // Only count a teammate as "seen" if they opened the thread AFTER
        // the customer's latest message — an old read timestamp from last
        // week doesn't mean they've seen the new incoming note.
        const seenSince = (activeConv && Array.isArray(activeConv.seen_by))
            ? activeConv.seen_by.filter(s => s.last_read_at && new Date(s.last_read_at).getTime() >= lastInTs)
            : [];

        // Aug-2026 — outcomes of this customer's location replies, decided by the
        // server. Matched to bubbles by time (the verdict is written inside the
        // same webhook request that stored the message) and each verdict is
        // consumed once, so two pins minutes apart keep their own outcome.
        const usedPinVerdicts = new Set();
        renderPinPendingBar();
        renderTagWaitBar();

        msgs.forEach((m, idx) => {
            if (m.created_at) {
                const md = new Date(m.created_at);
                const dateKey = md.getFullYear() + '-' + md.getMonth() + '-' + md.getDate();
                if (dateKey !== lastDateKey) {
                    html += `<div class="wa-date-sep"><span>${fmtDateLabel(m.created_at)}</span></div>`;
                    lastDateKey = dateKey;
                }
            }
            const isOut = m.direction === 'outbound';
            const meta = (typeof m.metadata === 'string') ? JSON.parse(m.metadata || '{}') : (m.metadata || {});
            html += `<div class="wa-msg ${isOut?'out':'in'}">`;
            if (m.type === 'template') html += `<div class="wa-msg-tpl-badge">Template: ${esc(m.template_name||'')}</div>`;
            if (m.type === 'image' && m.media_url) {
                html += `<div class="wa-msg-image"><a href="${esc(m.media_url)}" target="_blank"><img src="${esc(m.media_url)}" alt="Image" style="max-width:260px;max-height:260px;border-radius:8px;display:block;cursor:pointer;" /></a></div>`;
            }
            if (m.type === 'audio') {
                if (m.media_url) {
                    html += `<div class="wa-msg-media" style="min-width:220px;"><div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;"><span style="font-size:16px;">🎤</span><span style="font-weight:600;font-size:12px;color:#374151;">Voice Note</span></div><audio controls preload="metadata" style="width:100%;max-width:280px;height:36px;" src="${esc(m.media_url)}"></audio><div style="margin-top:4px;"><a href="${esc(m.media_url)}" target="_blank" style="font-size:11px;color:#2563eb;text-decoration:none;">Download ↗</a></div></div>`;
                } else {
                    html += '<div class="wa-msg-media">🎤 Voice Note (unavailable)</div>';
                }
            }
            if (m.type === 'video') {
                if (m.media_url) html += `<div class="wa-msg-media"><a href="${esc(m.media_url)}" target="_blank" style="color:inherit;text-decoration:none;">🎬 Video (click to open)</a></div>`;
                else html += '<div class="wa-msg-media">🎬 Video</div>';
            }
            if (m.type === 'document') {
                if (m.media_url) html += `<div class="wa-msg-media"><a href="${esc(m.media_url)}" target="_blank" style="color:inherit;text-decoration:none;">📄 Document (click to open)</a></div>`;
                else html += '<div class="wa-msg-media">📄 Document</div>';
            }
            // Sep-2026 — a REACTION is not something the customer typed: it is an
            // emoji stuck onto an existing message. Rendered as a bare bubble it
            // read exactly like a fresh "👍" reply, with no way to tell WHAT was
            // being agreed to. Quote the original (resolved server-side from
            // metadata.reacted_message_id) and show the emoji under it.
            // An EMPTY emoji means the customer REMOVED their reaction (Meta
            // sends emoji:"") — that used to draw a completely blank bubble.
            if (m.type === 'reaction') {
                const rt = m.reacted_to;
                const emoji = (m.content || '').trim();
                if (rt) {
                    html += '<div style="border-left:3px solid #86EFAC;background:rgba(0,0,0,0.05);border-radius:6px;padding:4px 8px;margin-bottom:4px;max-width:280px;">'
                          + `<div style="font-size:10px;font-weight:700;color:#059669;letter-spacing:.3px;text-transform:uppercase;">${rt.direction === 'outbound' ? 'Reacted to our message' : 'Reacted to their message'}</div>`
                          + `<div style="font-size:12px;color:#4B5563;">${esc(rt.snippet || '')}</div>`
                          + '</div>';
                }
                html += emoji
                    ? `<div style="font-size:26px;line-height:1.15;">${esc(emoji)}</div>`
                    : '<div style="font-size:12px;color:#9CA3AF;font-style:italic;">Removed their reaction</div>';
            }
            // Sep-2026 — stickers are downloaded now (they used to be dropped and
            // stored as "[Unsupported message type: sticker]"). Older rows have no
            // media_url, so they fall back to the label instead of that raw string.
            if (m.type === 'sticker') {
                if (m.media_url) {
                    html += `<div class="wa-msg-image"><a href="${esc(m.media_url)}" target="_blank"><img src="${esc(m.media_url)}" alt="Sticker" style="max-width:140px;max-height:140px;display:block;" /></a></div>`;
                } else {
                    html += '<div class="wa-msg-media">💬 Sticker</div>';
                }
            }
            if (m.type === 'location') {
                const lat = meta.latitude, lng = meta.longitude;
                const locName = meta.name || '', locAddr = meta.address || '';
                let mapsUrl = (lat && lng) ? `https://www.google.com/maps?q=${lat},${lng}` : '';
                if (!mapsUrl && m.content) { const urlMatch = m.content.match(/(https?:\/\/[^\s]+)/); if (urlMatch) mapsUrl = urlMatch[0]; }
                html += `<div class="wa-msg-location" style="display:flex;align-items:center;gap:8px;padding:8px;background:rgba(0,0,0,0.04);border-radius:8px;margin-bottom:4px;cursor:pointer;" onclick="window.open('${mapsUrl}','_blank')">`;
                html += '<span style="font-size:24px;">📍</span><div>';
                if (locName) html += `<div style="font-weight:600;font-size:13px;">${esc(locName)}</div>`;
                if (locAddr) html += `<div style="font-size:12px;color:#6B7280;">${esc(locAddr)}</div>`;
                html += `<div style="font-size:12px;color:#2563EB;margin-top:2px;">Click to open in Maps</div>`;
                html += '</div></div>';

                // Inbound location pins from a customer can be saved as
                // their verified location with one click — same as the
                // mobile Store screen. Only shown for inbound messages
                // on conversations linked to a known customer (no
                // customer = no place to save). Outbound pins we
                // ourselves sent obviously don't need this.
                if (!isOut && lat && lng && activeConv && activeConv.customer_id) {
                    // ⭐ Aug-2026 — say what ACTUALLY happened to this pin.
                    // ⚠ Never decide this from "does the customer have a pin?":
                    // since the re-request rule landed, a reply to a manual
                    // re-request DOES overwrite, so that test would print "not
                    // saved" directly under a pin it had just saved. The server
                    // ships the real verdict; with no match we say nothing.
                    const pv = pinVerdictFor(m.created_at, usedPinVerdicts);
                    if (pv) {
                        const tone = pv.saved
                            ? {bg:'#F0FDF4', bd:'#86EFAC', fg:'#047857'}
                            : (pv.needs_action ? {bg:'#FFFBEB', bd:'#FCD34D', fg:'#92400E'}
                                               : {bg:'#F9FAFB', bd:'#E5E7EB', fg:'#4B5563'});
                        html += `<div style="margin-top:4px;padding:6px 8px;background:${tone.bg};border:1px solid ${tone.bd};border-radius:6px;">`;
                        html += `<div style="font-size:11px;font-weight:700;color:${tone.fg};">${pv.saved ? '✅' : '⚠️'} ${esc(pv.headline)}</div>`;
                        if (pv.detail) {
                            html += `<div style="font-size:11px;color:${tone.fg};opacity:.85;margin-top:1px;">${esc(pv.detail)}</div>`;
                        }
                        html += `</div>`;
                    }
                    // The button stays regardless: even an auto-saved pin can be
                    // re-applied harmlessly, and a refused one needs exactly this.
                    html += `<button type="button" class="wa-set-verified-btn" data-cust="${activeConv.customer_id}" data-lat="${esc(String(lat))}" data-lng="${esc(String(lng))}" data-context="chat_pin" style="margin-top:4px;background:#2563EB;color:#fff;border:none;border-radius:5px;padding:5px 10px;font-size:11px;font-weight:600;cursor:pointer;">📌 Set as Verified Location</button>`;
                }
            }
            // 'reaction' and 'sticker' draw their own body above — without these
            // two exclusions a reaction would print the emoji twice, and every
            // legacy sticker row would print "[Unsupported message type: sticker]".
            if (m.content && m.type !== 'location' && m.type !== 'audio' && m.type !== 'reaction' && m.type !== 'sticker') {
                const linked = esc(m.content).replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" style="color:#2563EB;text-decoration:underline;">$1</a>');
                html += `<div class="wa-msg-text">${linked}</div>`;

                // Inbound text containing a Google Maps share-link
                // (the maps.app.goo.gl shortlink that opens in maps)
                // can also be saved as verified location. The web
                // CustomerController::setVerifiedLocation endpoint now
                // follows the shortlink and extracts lat/lng server
                // side, so a URL-only save still verifies the customer.
                if (!isOut && activeConv && activeConv.customer_id) {
                    const mapsRe = /https?:\/\/(maps\.google\.com|www\.google\.com\/maps|goo\.gl\/maps|maps\.app\.goo\.gl|g\.co\/maps)[^\s)\]"<]*/i;
                    const um = m.content.match(mapsRe);
                    if (um && um[0]) {
                        html += `<button type="button" class="wa-set-verified-btn" data-cust="${activeConv.customer_id}" data-url="${esc(um[0])}" data-context="chat_link" style="margin-top:4px;background:#2563EB;color:#fff;border:none;border-radius:5px;padding:5px 10px;font-size:11px;font-weight:600;cursor:pointer;">📌 Set as Verified Location</button>`;
                    }
                }
            }
            html += `<div class="wa-msg-meta">`;
            if (m.sender_name && isOut) html += `<span class="wa-msg-sender">${esc(m.sender_name)} · </span>`;
            html += `<span class="wa-msg-time">${fmtMsgTime(m.created_at)}</span>`;
            if (isOut) html += ` <span class="wa-msg-status ${m.status==='read'?'read':''} ${m.status==='failed'?'failed':''}">${statusIcon(m.status)}</span>`;
            html += '</div>';
            if (m.status === 'failed' && m.error_message) html += `<div class="wa-msg-error">${esc(m.error_message)}</div>`;
            html += '</div>';

            // "Seen by" row under the last inbound message, left-aligned so
            // it sits below the customer's bubble. Only renders teammates
            // whose read timestamp is newer than that incoming message.
            if (idx === lastInIdx && seenSince.length > 0) {
                const names = seenSince.map(s => esc(s.name)).slice(0, 3);
                let suffix = '';
                if (seenSince.length > 3) suffix = ' +' + (seenSince.length - 3);
                html += `<div style="display:flex;justify-content:flex-start;margin:2px 0 6px 4px;font-size:10.5px;color:#6b7280;font-style:italic;">👀 Seen by ${names.join(', ')}${suffix}</div>`;
            }
        });
        el.innerHTML = html;
        el.scrollTop = el.scrollHeight;

        // Wire any "Set as Verified Location" buttons we just rendered.
        // Done via event delegation on each button rather than a single
        // container listener so we get explicit per-button disabled
        // state during the network call. The handler hits the existing
        // /customers/{id}/set-verified-location endpoint which now
        // (server-side) follows short URLs and extracts coords too,
        // so a maps.app.goo.gl link saves both the URL AND the pin.
        el.querySelectorAll('.wa-set-verified-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const cust = btn.getAttribute('data-cust');
                if (!cust) return;
                const lat = btn.getAttribute('data-lat');
                const lng = btn.getAttribute('data-lng');
                const url = btn.getAttribute('data-url');
                const payload = {};
                if (lat && lng) {
                    payload.latitude = parseFloat(lat);
                    payload.longitude = parseFloat(lng);
                } else if (url) {
                    payload.url = url;
                } else {
                    return;
                }
                // Tell the audit trail WHERE this save came from, so the pin
                // history can say "saved from the customer's chat pin" rather
                // than a flat "web" that hides the best provenance we have.
                const ctx = btn.getAttribute('data-context');
                if (ctx) payload.context = ctx;
                const original = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = 'Saving...';
                btn.style.opacity = '0.7';
                apiFetch('/customers/' + cust + '/set-verified-location', {
                    method: 'POST',
                    body: JSON.stringify(payload),
                }).then(function (r) {
                    if (r && r.success) {
                        btn.innerHTML = '✅ Saved as Verified';
                        btn.style.background = '#16a34a';
                    } else {
                        btn.disabled = false;
                        btn.innerHTML = original;
                        btn.style.opacity = '1';
                        alert((r && r.message) || 'Failed to save verified location');
                    }
                }).catch(function () {
                    btn.disabled = false;
                    btn.innerHTML = original;
                    btn.style.opacity = '1';
                    alert('Failed to save verified location');
                });
            });
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // May-2026 — Older-history pagination.
    //
    // Backend contract (mirrors mobile WhatsAppController::getMessages):
    //   GET /messages/conversations/{id}?before=<id>&limit=50
    //     → returns up to 50 messages with id < before, oldest first.
    //     → response.has_more = true if there are still more BEFORE
    //       that batch.
    //
    // The "Load older messages" link in renderMessages() used to call
    // a function with this name, but the function had never been
    // defined — clicking threw a silent ReferenceError, so the UI
    // appeared to do nothing for any conversation over 50 messages.
    // Defining it here fixes that, AND combined with the controller's
    // new DESC ordering it means: by default the chat shows the
    // LATEST 50, click to walk backwards through history 50 at a
    // time, click "Jump to latest" to come back.
    // ─────────────────────────────────────────────────────────────────────
    window.loadOlderMessages = function () {
        if (waMsgsLoadingOlder) return;
        if (!waMsgs.length) return;
        if (!activeConvId) return;
        // Need the OLDEST currently-rendered id as the upper bound.
        // waMsgs is chronological (oldest first), so waMsgs[0] is it.
        const beforeId = waMsgs[0].id;
        if (!beforeId) return;
        waMsgsLoadingOlder = true;

        // Capture scroll position relative to bottom so the user stays
        // visually anchored to the message they were reading after the
        // older batch is prepended (the new content pushes everything
        // down, so we just compensate by adjusting scrollTop).
        const el = document.getElementById('waChatMessages');
        const distanceFromBottom = el.scrollHeight - el.scrollTop;

        // Show a tiny inline spinner where the "Load older" link was so
        // the user knows the click registered.
        const loadMoreLink = el.querySelector('.wa-load-more a');
        const originalLinkText = loadMoreLink ? loadMoreLink.textContent : null;
        if (loadMoreLink) {
            loadMoreLink.textContent = 'Loading older messages…';
            loadMoreLink.style.pointerEvents = 'none';
            loadMoreLink.style.opacity = '0.6';
        }

        apiFetch('/messages/conversations/' + activeConvId + '?before=' + encodeURIComponent(beforeId))
            .then(function (d) {
                if (!d || !d.success || activeConvId == null) {
                    if (loadMoreLink) {
                        loadMoreLink.textContent = originalLinkText || 'Load older messages';
                        loadMoreLink.style.pointerEvents = '';
                        loadMoreLink.style.opacity = '';
                    }
                    return;
                }
                const older = Array.isArray(d.messages) ? d.messages : [];
                if (!older.length) {
                    // No more history — disable further pagination but
                    // STAY in history mode so the regular poll keeps
                    // not snapping the user back to the live tail.
                    waMsgsHistoryMode = true;
                    renderMessages(waMsgs, false, true);
                    return;
                }
                // Prepend older batch to the cumulative array.
                const combined = older.concat(waMsgs);
                waMsgsHistoryMode = true;
                renderMessages(combined, !!d.has_more, true);

                // Restore visual scroll position so the user stays on
                // the same message they were reading (renderMessages
                // ends with scrollTop = scrollHeight which would jump
                // them to the latest bubble — undo that).
                requestAnimationFrame(function () {
                    const el2 = document.getElementById('waChatMessages');
                    if (el2) el2.scrollTop = el2.scrollHeight - distanceFromBottom;
                });
            })
            .catch(function () {
                if (loadMoreLink) {
                    loadMoreLink.textContent = originalLinkText || 'Load older messages';
                    loadMoreLink.style.pointerEvents = '';
                    loadMoreLink.style.opacity = '';
                }
            })
            .finally(function () {
                waMsgsLoadingOlder = false;
            });
    };

    window.jumpToLatestMessages = function () {
        if (!activeConvId) return;
        // Exit history mode first so the next renderMessages() doesn't
        // re-show the "Jump to latest" pill and the poll resumes
        // normal wholesale refreshes.
        waMsgsHistoryMode = false;
        waMsgsLoadingOlder = false;
        const el = document.getElementById('waChatMessages');
        if (el) el.innerHTML = '<div class="wa-loading">Loading...</div>';
        apiFetch('/messages/conversations/' + activeConvId).then(function (d) {
            if (!d || !d.success || activeConvId == null) return;
            activeConv = d.conversation;
            if (typeof updateSessionUI === 'function') updateSessionUI(d.conversation);
            renderMessages(d.messages, d.has_more);
        });
    };

    // ── Send Message ──
    document.getElementById('waSendBtn').addEventListener('click', sendMessage);
    document.getElementById('waMessageInput').addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendMessage(); }
    });

    function sendMessage() {
        const input = document.getElementById('waMessageInput');
        const text = input.value.trim();
        if (!text || !activeConvId) return;

        if (activeConv && activeConv.session_expires_at && new Date(activeConv.session_expires_at) <= new Date()) {
            updateSessionUI({session_active: false, session_expires_at: null});
            if (confirm('The 24-hour window has expired. Would you like to send a template message instead?')) {
                openTemplatePicker();
            }
            return;
        }

        const btn = document.getElementById('waSendBtn');
        btn.disabled = true;

        apiFetch('/messages/conversations/' + activeConvId + '/send', {
            method: 'POST',
            body: JSON.stringify({message: text})
        }).then(d => {
            btn.disabled = false;
            if (d.success) {
                input.value = '';
                apiFetch('/messages/conversations/' + activeConvId).then(r => {
                    if (r.success) {
                        renderMessages(r.messages, r.has_more);
                        if (r.conversation) updateSessionUI(r.conversation);
                    }
                });
            } else if (d.session_expired) {
                updateSessionUI({session_active: false, session_expires_at: null});
                if (confirm('The 24-hour window has expired. Would you like to send a template message instead?')) {
                    openTemplatePicker();
                }
            } else {
                alert(d.message || 'Failed to send message');
            }
        }).catch(() => { btn.disabled = false; alert('Failed to send message'); });
    }

    // ── Attach image (Aug-2026) ────────────────────────────────────────
    // Manual picture send for an OPEN session. Used to push a readable copy
    // of an invoice when a customer says the template image is hard to read:
    // a plain image message skips the template header path. Client-side caps
    // mirror the server (5 MB, JPEG/PNG) so the operator gets an instant
    // answer instead of a round-trip rejection.
    window.waAttachPicked = function(input) {
        const file = input.files && input.files[0];
        input.value = ''; // allow re-picking the same file after a cancel
        if (!file || !activeConvId) return;
        if (!['image/jpeg', 'image/png'].includes(file.type)) {
            alert('Only JPEG or PNG images can be sent.');
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
            alert('Image is ' + (file.size / 1048576).toFixed(1) + ' MB. WhatsApp\u2019s limit is 5 MB \u2014 please pick a smaller file.');
            return;
        }
        const url = URL.createObjectURL(file);
        const old = document.getElementById('waAttachDialog');
        if (old) old.remove();
        const dlg = document.createElement('div');
        dlg.id = 'waAttachDialog';
        dlg.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:100000;display:flex;align-items:center;justify-content:center;';
        dlg.innerHTML =
            '<div style="background:#fff;border-radius:10px;padding:18px;max-width:420px;width:92%;max-height:90vh;overflow:auto;">' +
              '<h3 style="margin:0 0 12px;font-size:16px;">Send image</h3>' +
              '<img src="' + url + '" style="max-width:100%;max-height:45vh;display:block;margin:0 auto 12px;border:1px solid #e5e7eb;border-radius:6px;">' +
              '<div style="font-size:12px;color:#6b7280;margin-bottom:10px;">' + (file.name || 'image') + ' \u00b7 ' + (file.size / 1024).toFixed(0) + ' KB</div>' +
              '<input id="waAttachCaption" maxlength="1024" placeholder="Caption (optional)" style="width:100%;padding:8px;border:1px solid #d1d5db;border-radius:6px;margin-bottom:12px;font-size:14px;">' +
              '<div id="waAttachStatus" style="display:none;font-size:13px;margin-bottom:8px;"></div>' +
              '<div style="display:flex;gap:8px;justify-content:flex-end;">' +
                '<button id="waAttachCancel" style="padding:8px 14px;border:1px solid #d1d5db;background:#fff;border-radius:6px;cursor:pointer;">Cancel</button>' +
                '<button id="waAttachSend" style="padding:8px 16px;border:none;background:#16a34a;color:#fff;border-radius:6px;cursor:pointer;font-weight:600;">Send</button>' +
              '</div>' +
            '</div>';
        document.body.appendChild(dlg);
        const close = () => { URL.revokeObjectURL(url); dlg.remove(); };
        document.getElementById('waAttachCancel').onclick = close;
        dlg.addEventListener('click', e => { if (e.target === dlg) close(); });

        document.getElementById('waAttachSend').onclick = function() {
            const btn = this;
            const status = document.getElementById('waAttachStatus');
            btn.disabled = true; btn.textContent = 'Sending...';
            const fd = new FormData();
            fd.append('image', file);
            fd.append('caption', (document.getElementById('waAttachCaption').value || '').trim());
            // Raw fetch, not apiFetch: that helper forces a JSON content-type,
            // which would break the multipart boundary.
            fetch('/messages/conversations/' + activeConvId + '/send-image', {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
                body: fd
            }).then(r => r.json()).then(d => {
                if (d.success) {
                    close();
                    apiFetch('/messages/conversations/' + activeConvId).then(r => {
                        if (r.success) {
                            renderMessages(r.messages, r.has_more);
                            if (r.conversation) updateSessionUI(r.conversation);
                        }
                    });
                } else {
                    btn.disabled = false; btn.textContent = 'Send';
                    status.style.display = 'block'; status.style.color = '#dc2626';
                    status.textContent = d.message || 'Failed to send image';
                }
            }).catch(() => {
                btn.disabled = false; btn.textContent = 'Send';
                status.style.display = 'block'; status.style.color = '#dc2626';
                status.textContent = 'Network error \u2014 please try again.';
            });
        };
    };

    // ── Back button ──
    document.getElementById('waChatBack').addEventListener('click', function() {
        activeConvId = null;
        activeConv = null;
        document.getElementById('waChat').classList.remove('visible');
        document.getElementById('waEmptyState').style.display = 'flex';
        if (msgPollTimer) clearInterval(msgPollTimer);
        loadConversations();
    });

    // ── Search ──
    document.getElementById('waSearch').addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(loadConversations, 400);
    });

    // Search-mode toggle (Names ↔ Chats). Refresh on switch only when
    // there's actually a query to re-run, otherwise flipping the mode on
    // an empty search would hit the conversations endpoint for nothing.
    document.querySelectorAll('.wa-searchmode-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            if (this.dataset.mode === currentSearchMode) return;
            document.querySelectorAll('.wa-searchmode-btn').forEach(b => {
                b.classList.remove('active');
                b.setAttribute('aria-selected', 'false');
            });
            this.classList.add('active');
            this.setAttribute('aria-selected', 'true');
            currentSearchMode = this.dataset.mode;
            // Update placeholder so the user can immediately see what the
            // input now does. Keeping the typed text is intentional — they
            // often toggle to re-check the same word in the other mode.
            const input = document.getElementById('waSearch');
            input.placeholder = currentSearchMode === 'chats'
                ? 'Search inside message contents…'
                : 'Search name, phone or city…';
            if ((input.value || '').trim().length > 0) {
                clearTimeout(searchTimeout);
                loadConversations();
            }
        });
    });

    // ── Filters ──
    document.querySelectorAll('.wa-filter-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.wa-filter-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            currentFilter = this.dataset.filter;
            // Hide Mark-All-Read pre-emptively when leaving Unread so the
            // operator never sees it linger on the wrong tab while the
            // conversation fetch is still in flight. It'll re-show with a
            // fresh count once the Unread response comes back.
            if (typeof window.updateMarkAllReadVisibility === 'function') {
                window.updateMarkAllReadVisibility(0);
            }
            loadConversations();
        });
    });

    // ── Template Picker ──
    let tplCustomerOrders = [];

    window.openTemplatePicker = function() {
        document.getElementById('waTemplateModal').style.display = 'flex';
        document.getElementById('waTemplateList').innerHTML = '<div class="wa-loading">Loading...</div>';
        tplCustomerOrders = [];

        const fetchTpls = apiFetch('/messages/templates');
        const fetchOrders = (activeConv && activeConv.customer_id)
            ? apiFetch('/messages/customer-orders/' + activeConv.customer_id).catch(() => ({success:false}))
            : Promise.resolve({success:false});

        Promise.all([fetchTpls, fetchOrders]).then(([tplData, ordData]) => {
            if (!tplData.success) return;
            templates = tplData.templates || [];
            tplCustomerOrders = (ordData.success && ordData.orders) ? ordData.orders : [];
            renderTemplates();
        });
    };
    window.closeTemplatePicker = function() {
        document.getElementById('waTemplateModal').style.display = 'none';
    };

    function buildOrderDropdown(idx, varNum, preselectLatest) {
        let html = `<select class="wa-tpl-param-in" data-tpl="${idx}" data-var="${varNum}" style="padding:8px 10px;">`;
        // preselectLatest (Start Conversation): default to the customer's most
        // recent order (tplCustomerOrders is newest-first, any status) instead of
        // leaving "-- Select Order --" selected. The operator can still change it.
        html += `<option value=""${preselectLatest ? '' : ' selected'}>-- Select Order --</option>`;
        tplCustomerOrders.forEach((o, oi) => {
            const dt = o.order_date ? new Date(o.order_date).toLocaleDateString('en-GB', {day:'2-digit',month:'short',year:'numeric'}) : '';
            const label = `${o.order_number} — ${dt} — Rs ${Number(o.total||0).toLocaleString()} (${o.status||''})`;
            const sel = (preselectLatest && oi === 0) ? ' selected' : '';
            html += `<option value="${esc(o.order_number)}"${sel}>${esc(label)}</option>`;
        });
        html += `</select>`;
        return html;
    }

    function renderTemplates() {
        const el = document.getElementById('waTemplateList');
        if (!templates.length) { el.innerHTML = '<div class="wa-loading">No approved templates found</div>'; return; }
        el.innerHTML = templates.map((t, idx) => {
            let bodyText = (t.body_text||'').replace(/\\n/g, '\n');
            const custName = activeConv ? (activeConv.customer_name || '') : '';
            if (custName) bodyText = bodyText.replace('{{1}}', custName);
            let html = `<div class="wa-tpl-card">`;
            html += `<div class="wa-tpl-name">${esc(t.display_name || t.name)}</div>`;
            html += `<div class="wa-tpl-body">${esc(bodyText)}</div>`;
            if (t.has_buttons) {
                html += '<div class="wa-tpl-btns">';
                try { JSON.parse(t.button_labels||'[]').forEach(b => { html += `<span class="wa-tpl-btn-tag">${esc(b)}</span>`; }); } catch(e){}
                html += '</div>';
            }
            if (t.variable_count > 0) {
                html += '<div class="wa-tpl-params">';
                const isStartConvo = (t.name === 'start_conversation');
                for (let i = 1; i <= t.variable_count; i++) {
                    if (i >= 2 && tplCustomerOrders.length > 0) {
                        // Order-number variable. Start Conversation defaults to the
                        // customer's latest order (any status); others stay unpicked.
                        html += `<label style="font-size:11px;color:#6b7280;margin-bottom:2px;display:block;">Variable {{${i}}} — Order Number</label>`;
                        html += buildOrderDropdown(idx, i, isStartConvo);
                    } else if (i >= 2 && isStartConvo) {
                        // Start Conversation with a NEW customer (no order on file):
                        // prefill a plausible-looking dummy order so the send isn't
                        // blocked (Meta rejects an empty variable) and it still reads
                        // naturally to the customer. The operator can edit it.
                        html += `<label style="font-size:11px;color:#6b7280;margin-bottom:2px;display:block;">Variable {{${i}}} — Order Number (no order on file — dummy)</label>`;
                        html += `<input class="wa-tpl-param-in" data-tpl="${idx}" data-var="${i}" placeholder="Variable {{${i}}}" value="NF-999" />`;
                    } else {
                        const defaultVal = (i === 1 && activeConv) ? esc(activeConv.customer_name || '') : '';
                        html += `<input class="wa-tpl-param-in" data-tpl="${idx}" data-var="${i}" placeholder="Variable {{${i}}}" value="${defaultVal}" />`;
                    }
                }
                html += '</div>';
            }
            html += `<button class="wa-tpl-send" onclick="sendTemplate(${idx})">Send Template</button>`;
            html += '</div>';
            return html;
        }).join('');
    }

    window.sendTemplate = function(idx) {
        const t = templates[idx];
        if (!t || !activeConv) return;
        const params = [];
        for (let i = 1; i <= t.variable_count; i++) {
            const el = document.querySelector(`[data-tpl="${idx}"][data-var="${i}"]`);
            const val = el?.value?.trim() || '';
            if (!val) { alert('Please fill in all template variables.'); return; }
            params.push(val);
        }

        // Marketing-template dedup guard. We always run the pre-flight
        // check first so a recently-sent marketing template prompts the
        // operator BEFORE the network round-trip to Meta. The server
        // double-checks on the actual /send-template call, so this UI
        // step is purely for UX (it cannot be the only line of defence).
        runMarketingDedupCheck(t.name).then(check => {
            if (check && check.recently_sent) {
                if (check.can_override) {
                    showMarketingDedupConfirm(check, () => {
                        // Operator chose Send Anyway → re-send with force=true.
                        doSendTemplate(t, params, true);
                    });
                } else {
                    // Hard block — show the message and stop.
                    showMarketingDedupBlocked(check);
                }
                return;
            }
            doSendTemplate(t, params, false);
        });
    };

    // Internal helper that performs the actual /send-template POST and
    // handles the standard success / failure / 409 fallback. Called from
    // sendTemplate() above and from the "Send Anyway" path.
    function doSendTemplate(t, params, force) {
        apiFetch('/messages/send-template', {
            method: 'POST',
            body: JSON.stringify({
                phone: activeConv.wa_phone,
                template_name: t.name,
                body_params: params,
                conversation_id: activeConvId || null,
                customer_id: activeConv.customer_id,
                force: !!force
            })
        }).then(d => {
            if (d.success) {
                closeTemplatePicker();
                loadConversations();
                if (activeConvId) {
                    apiFetch('/messages/conversations/' + activeConvId).then(r => {
                        if (r.success) renderMessages(r.messages, r.has_more);
                    });
                    // Refresh the pinned-marketing strip — the just-sent
                    // template will become a new entry on it.
                    if (typeof loadRecentMarketingStrip === 'function') {
                        loadRecentMarketingStrip(activeConvId);
                    }
                } else {
                    setTimeout(() => {
                        apiFetch('/messages/conversations?search=' + encodeURIComponent(activeConv.wa_phone)).then(r => {
                            if (r.success && r.conversations.length > 0) {
                                openConv(r.conversations[0].id);
                                loadConversations();
                            }
                        });
                    }, 500);
                }
                return;
            }
            // Server-side dedup guard rejected — surface the same confirm
            // we'd have shown via the pre-flight (covers the case where
            // the pre-flight raced or the server rule fired).
            if (d.reason === 'recent_marketing_send') {
                if (d.can_override) {
                    showMarketingDedupConfirm(d, () => doSendTemplate(t, params, true));
                } else {
                    showMarketingDedupBlocked(d);
                }
                return;
            }
            alert(d.message || 'Failed to send template');
        });
    }

    // ── Auto-resize textarea ──
    document.getElementById('waMessageInput').addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 100) + 'px';
    });

    // ── New Message Panel ──
    let newMsgOpen = false;
    let custSearchTimeout = null;
    window.toggleNewMessage = function() {
        newMsgOpen = !newMsgOpen;
        document.getElementById('waNewMsgPanel').classList.toggle('open', newMsgOpen);
        if (newMsgOpen) {
            document.getElementById('waCustomerSearch').value = '';
            document.getElementById('waCustomerResults').innerHTML = '<div style="font-size:13px;color:#9ca3af;padding:8px;">Type at least 2 characters to search</div>';
            document.getElementById('waCustomerSearch').focus();
        }
    };

    document.getElementById('waCustomerSearch')?.addEventListener('input', function() {
        clearTimeout(custSearchTimeout);
        const q = this.value.trim();
        const resultsEl = document.getElementById('waCustomerResults');
        if (q.length < 2) {
            resultsEl.innerHTML = '<div style="font-size:13px;color:#9ca3af;padding:8px;">Type at least 2 characters to search</div>';
            return;
        }
        resultsEl.innerHTML = '<div style="font-size:13px;color:#9ca3af;padding:8px;">Searching...</div>';
        custSearchTimeout = setTimeout(() => {
            apiFetch('/api/customers/search?q=' + encodeURIComponent(q) + '&limit=15').then(d => {
                const custs = d.customers || d || [];
                if (!custs.length) {
                    resultsEl.innerHTML = '<div style="font-size:13px;color:#9ca3af;padding:8px;">No customers found</div>';
                    return;
                }
                resultsEl.innerHTML = custs.map(c => {
                    const name = esc(c.name || '').trim() || 'Unknown';
                    const phone = esc(c.phone || '');
                    const city = c.address?.city ? ' · ' + esc(c.address.city) : '';
                    return `<div class="wa-cust-item" onclick="startConversationWith(${c.id}, '${esc(phone)}', '${esc(name)}')">
                        <div class="wa-cust-avatar">${name[0].toUpperCase()}</div>
                        <div class="wa-cust-info">
                            <div class="wa-cust-name">${name}</div>
                            <div class="wa-cust-phone">${phone}${city}</div>
                        </div>
                    </div>`;
                }).join('');
            }).catch(() => {
                resultsEl.innerHTML = '<div style="font-size:13px;color:#ef4444;padding:8px;">Search failed</div>';
            });
        }, 400);
    });

    window.startConversationWith = function(customerId, phone, customerName) {
        if (!phone) { alert('Customer has no phone number'); return; }

        // Close new message panel
        newMsgOpen = false;
        document.getElementById('waNewMsgPanel').classList.remove('open');

        apiFetch('/messages/conversations?search=' + encodeURIComponent(phone)).then(d => {
            const convs = d.conversations || [];
            const existing = convs.find(c => c.wa_phone && phone.replace(/\D/g,'').includes(c.wa_phone.replace(/\D/g,'').slice(-10)));
            if (existing) {
                openConv(existing.id);
            } else {
                activeConvId = null;
                activeConv = { wa_phone: phone, customer_id: customerId, customer_name: customerName || '' };
                document.getElementById('waChat').classList.add('visible');
                document.getElementById('waEmptyState').style.display = 'none';
                document.getElementById('waChatName').textContent = customerName || phone;
                document.getElementById('waChatAvatar').textContent = (customerName || phone || '?')[0].toUpperCase();
                document.getElementById('waChatSub').textContent = phone + ' · New conversation';
                document.getElementById('waChatMessages').innerHTML = '<div class="wa-loading"><div style="font-size:40px;">👋</div><div style="font-size:15px;color:#374151;font-weight:500;">Start a conversation</div><div style="font-size:13px;color:#6b7280;">Send a template message to begin</div></div>';
                updateSessionUI({session_active: false, session_expires_at: null});

                // Apr-2026 bugfix: clear chat-header strips that belong to
                // the previously-open conversation. The chat header DOM
                // (labels strip, marketing-template strip) is reused
                // across chats, so without an explicit clear here the
                // user sees the previous customer's pinned data on top
                // of the new "Start a conversation" placeholder until
                // they refresh. The openConv() path goes through
                // wrappers that already do this; this manual-setup path
                // bypasses them, hence the duplicated reset. We also
                // reset the marketing-strip cache so the next switch
                // back to a real conversation forces a fresh fetch.
                try {
                    if (typeof renderRecentMarketingStrip === 'function') {
                        renderRecentMarketingStrip([]);
                    }
                    if (typeof _mktStripFor !== 'undefined') {
                        _mktStripFor = null;
                        _mktStripRaw = null;
                    }
                    const labelsStrip = document.getElementById('waChatHdrLabels');
                    if (labelsStrip) labelsStrip.innerHTML = '';
                } catch (_) {}

                // May-2026 bugfix: wire up the right-rail Customer Orders
                // panel for new-chat conversations too. Previously only
                // openConv() (existing conversation path) revealed the
                // orders toggle and loaded the linked orders, which meant
                // when a manager opened a brand-new chat with a customer
                // who hadn't messaged yet (e.g. to send Send Invoice or
                // the Qurbani 🕒 Timeline button), the orders rail stayed
                // empty even though customer_id was already known. Mirror
                // the openConv() wiring here. Hide the toggle when there
                // is no linked customer (search-by-phone with no match).
                try {
                    const toggleBtn = document.getElementById('waOrdersToggle');
                    if (toggleBtn) {
                        if (customerId) {
                            toggleBtn.style.display = 'inline-block';
                            loadOrdersPanel(customerId);
                        } else {
                            toggleBtn.style.display = 'none';
                            const list = document.getElementById('waOrdersList');
                            if (list) list.innerHTML = '<div style="padding:20px;text-align:center;color:#9ca3af;font-size:13px;">No linked customer</div>';
                        }
                    }
                } catch (_) {}
            }
        });
    };

    // ── Template Manager ──
    window.openTemplateManager = function() {
        document.getElementById('waTemplateManager').style.display = 'flex';
        // Warm the template cache so the per-row 👁 message previews resolve
        // regardless of how the manager was opened (tab or the ⚙ gear).
        if (typeof ensureAutoTemplates === 'function') ensureAutoTemplates();
        resetTemplateForm();
        loadExistingTemplates();
    };
    window.closeTemplateManager = function() {
        document.getElementById('waTemplateManager').style.display = 'none';
        resetTemplateForm();
    };

    document.getElementById('tplHasButtons')?.addEventListener('change', function() {
        document.getElementById('tplButtonLabelsDiv').style.display = this.value === '1' ? 'block' : 'none';
    });

    let _existingTemplatesById = {};
    let editingTemplateId = null;

    function loadExistingTemplates() {
        // Pass include_inactive=1 so the manager can see + re-enable templates
        // that are currently hidden from regular pickers.
        // Manage Templates lists EVERYTHING (it's the editor) — automation-only
        // ones included, shown in their own collapsed 🤖 section below.
        apiFetch('/messages/templates?include_inactive=1&include_automation=1').then(d => {
            const el = document.getElementById('waExistingTemplates');
            const tpls = d.templates || [];
            _existingTemplatesById = {};
            tpls.forEach(t => { _existingTemplatesById[t.id] = t; });
            if (!tpls.length) { el.innerHTML = '<p style="color:#9ca3af;font-size:13px;">No templates added yet.</p>'; return; }
            const renderRow = (t) => {
                const si = (t.show_in || 'messages,orders,customers').split(',');
                const tagStyle = 'display:inline-block;padding:1px 6px;border-radius:4px;font-size:10px;font-weight:500;margin-right:3px;';
                const tags = [
                    {key:'messages',label:'Messages',bg:'#dbeafe',fg:'#1e40af'},
                    {key:'orders',label:'Orders',bg:'#fef3c7',fg:'#92400e'},
                    {key:'customers',label:'Customers',bg:'#d1fae5',fg:'#065f46'},
                    {key:'shopify',label:'Shopify',bg:'#ede9fe',fg:'#5b21b6'},
                    {key:'invoice',label:'📄 Invoice',bg:'#fff7ed',fg:'#9a3412'},
                    {key:'qurbani_invoice',label:'🐄 Qurbani Invoice',bg:'#fef3c7',fg:'#b45309'},
                    {key:'automation',label:'🤖 Automation',bg:'#ede9fe',fg:'#5b21b6'}
                ].filter(x => si.includes(x.key)).map(x => `<span style="${tagStyle}background:${x.bg};color:${x.fg};">${x.label}</span>`).join('');
                const defaultBadge = t.is_default ? `<span style="${tagStyle}background:#dcfce7;color:#166534;border:1px solid #86efac;">⭐ Default</span>` : '';
                const qurbaniOnlyBadge = t.is_qurbani_only ? `<span style="${tagStyle}background:#fef3c7;color:#b45309;border:1px solid #fde68a;">🐄 Qurbani only</span>` : '';
                const regularOnlyBadge = t.is_regular_only ? `<span style="${tagStyle}background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;">🛒 Regular only</span>` : '';
                const commonBadge = (!t.is_qurbani_only && !t.is_regular_only) ? `<span style="${tagStyle}background:#f1f5f9;color:#475569;border:1px solid #cbd5e1;">🌐 Common</span>` : '';
                // Inactive templates are visually dimmed and get a prominent badge.
                const isActive = (typeof t.is_active === 'undefined') ? true : !!t.is_active;
                const inactiveBadge = !isActive ? `<span style="${tagStyle}background:#fee2e2;color:#991b1b;border:1px solid #fecaca;">⏸ Inactive</span>` : '';
                const inactiveRowStyle = !isActive ? 'opacity:0.55;background:#fafafa;' : '';
                const isEditingThis = editingTemplateId === t.id;
                const editBtnStyle = isEditingThis
                    ? 'padding:4px 10px;background:#fef3c7;border:1px solid #f59e0b;color:#92400e;border-radius:6px;font-size:11px;cursor:pointer;font-weight:600;'
                    : 'padding:4px 10px;background:#f3f4f6;border:1px solid #d1d5db;border-radius:6px;font-size:11px;cursor:pointer;';
                const toggleBtnStyle = isActive
                    ? 'padding:4px 10px;background:#fff7ed;border:1px solid #fdba74;color:#9a3412;border-radius:6px;font-size:11px;cursor:pointer;'
                    : 'padding:4px 10px;background:#dcfce7;border:1px solid #86efac;color:#166534;border-radius:6px;font-size:11px;cursor:pointer;font-weight:600;';
                const toggleBtnLabel = isActive ? 'Disable' : 'Enable';
                return `<div class="wa-mgr-item" style="flex-wrap:wrap;${inactiveRowStyle}${isEditingThis?'border-color:#f59e0b;background:#fffbeb;':''}">
                    <div style="flex:1;">
                        <div class="wa-mgr-item-name">${esc(t.display_name || t.name)}</div>
                        <div class="wa-mgr-item-meta">${esc(t.name)} · ${t.variable_count} vars · ${t.status}</div>
                        <div style="margin-top:4px;">${inactiveBadge}${qurbaniOnlyBadge}${regularOnlyBadge}${commonBadge}${defaultBadge}${tags}</div>
                    </div>
                    <div style="display:flex;gap:6px;align-items:center;">
                        <button onclick="window.waShowTemplatePreview('${esc(t.name)}')" style="padding:4px 10px;background:#f0f7f4;border:1px solid #d6e9df;color:#128C7E;border-radius:6px;font-size:11px;cursor:pointer;" title="View message">👁</button>
                        <button onclick="toggleTemplateActive(${t.id})" style="${toggleBtnStyle}" title="${isActive ? 'Hide this template from pickers' : 'Show this template in pickers again'}">${toggleBtnLabel}</button>
                        <button onclick="editFullTemplate(${t.id})" style="${editBtnStyle}">${isEditingThis ? 'Editing…' : 'Edit'}</button>
                        <button onclick="deleteTemplate(${t.id})" class="wa-mgr-del">Delete</button>
                    </div>
                </div>`;
            };
            // Segregate automation-only templates (show_in='automation') into a
            // collapsed section so they don't clutter the main list.
            const isAuto = (t) => String(t.show_in || '').split(',').map(s => s.trim()).includes('automation');
            const pageTpls = tpls.filter(t => !isAuto(t));
            const autoTpls = tpls.filter(isAuto);
            let html = pageTpls.length ? pageTpls.map(renderRow).join('') : '<p style="color:#9ca3af;font-size:13px;">No page templates yet.</p>';
            if (autoTpls.length){
                html += `<div style="margin-top:14px;border-top:1px dashed #e5e7eb;padding-top:10px;">
                    <div onclick="waToggleAutoTplSection()" style="cursor:pointer;font-size:12px;font-weight:600;color:#6b7280;display:flex;align-items:center;gap:6px;"><span id="waAutoTplCaret">▶</span> 🤖 Automation templates (${autoTpls.length}) — hidden from manual pickers</div>
                    <div id="waAutoTplSection" style="display:none;margin-top:8px;">${autoTpls.map(renderRow).join('')}</div>
                </div>`;
            }
            el.innerHTML = html;
        });
    }
    window.waToggleAutoTplSection = function(){
        const s = document.getElementById('waAutoTplSection');
        const c = document.getElementById('waAutoTplCaret');
        if (!s) return;
        const open = s.style.display === 'none';
        s.style.display = open ? '' : 'none';
        if (c) c.textContent = open ? '▼' : '▶';
    };

    // Quick toggle for is_active right from the list (no need to open edit).
    window.toggleTemplateActive = function(id) {
        const t = _existingTemplatesById[id];
        if (!t) return;
        const next = !((typeof t.is_active === 'undefined') ? true : !!t.is_active);
        apiFetch('/messages/templates/' + id, {
            method: 'PUT',
            body: JSON.stringify({ is_active: next ? 1 : 0 })
        }).then(d => {
            if (d.success) {
                _cachedApiTemplates = null;
                loadExistingTemplates();
            } else {
                alert(d.message || 'Failed to update template status');
            }
        });
    };

    window.editFullTemplate = function(id) {
        const t = _existingTemplatesById[id];
        if (!t) return;
        editingTemplateId = id;

        document.getElementById('tplName').value = t.name || '';
        document.getElementById('tplDisplayName').value = t.display_name || '';
        document.getElementById('tplBody').value = (t.body_text || '').replace(/\\n/g, '\n');
        document.getElementById('tplCategory').value = t.category || 'utility';
        document.getElementById('tplVarCount').value = t.variable_count ?? 0;
        const hasBtns = t.has_buttons ? '1' : '0';
        document.getElementById('tplHasButtons').value = hasBtns;
        document.getElementById('tplButtonLabelsDiv').style.display = hasBtns === '1' ? 'block' : 'none';
        try {
            const labels = JSON.parse(t.button_labels || '[]');
            document.getElementById('tplButtonLabels').value = Array.isArray(labels) ? labels.join(', ') : '';
        } catch (e) { document.getElementById('tplButtonLabels').value = ''; }
        document.getElementById('tplHeader').value = t.header_text || '';
        document.getElementById('tplFooter').value = t.footer_text || '';

        const si = (t.show_in || '').split(',').map(s => s.trim());
        document.getElementById('tplShowMessages').checked = si.includes('messages');
        document.getElementById('tplShowOrders').checked = si.includes('orders');
        document.getElementById('tplShowCustomers').checked = si.includes('customers');
        document.getElementById('tplShowShopify').checked = si.includes('shopify');
        document.getElementById('tplShowInvoice').checked = si.includes('invoice');
        document.getElementById('tplShowQurbaniInvoice').checked = si.includes('qurbani_invoice');
        document.getElementById('tplShowAutomation').checked = si.includes('automation');
        document.getElementById('tplIsDefault').checked = !!t.is_default;
        document.getElementById('tplIsActive').checked = (typeof t.is_active === 'undefined') ? true : !!t.is_active;
        setTemplateScope(
            t.is_qurbani_only ? 'qurbani'
            : (t.is_regular_only ? 'regular' : 'common')
        );

        document.getElementById('tplFormHeading').textContent = 'Edit Template: ' + (t.display_name || t.name);
        document.getElementById('tplSaveBtn').textContent = 'Update Template';
        document.getElementById('tplCancelBtn').style.display = 'inline-block';
        document.getElementById('tplFormBanner').style.display = 'block';

        loadExistingTemplates();

        setTimeout(() => {
            document.getElementById('tplFormBanner').scrollIntoView({ behavior: 'smooth', block: 'start' });
            document.getElementById('tplName').focus();
        }, 50);
    };

    window.cancelTemplateEdit = function() {
        resetTemplateForm();
        loadExistingTemplates();
    };

    function resetTemplateForm() {
        editingTemplateId = null;
        ['tplName','tplDisplayName','tplBody','tplHeader','tplFooter','tplButtonLabels'].forEach(id => { var el = document.getElementById(id); if(el) el.value = ''; });
        document.getElementById('tplVarCount').value = '0';
        document.getElementById('tplHasButtons').value = '0';
        document.getElementById('tplButtonLabelsDiv').style.display = 'none';
        document.getElementById('tplCategory').value = 'utility';
        document.getElementById('tplShowMessages').checked = true;
        document.getElementById('tplShowOrders').checked = true;
        document.getElementById('tplShowCustomers').checked = true;
        document.getElementById('tplShowShopify').checked = true;
        document.getElementById('tplShowInvoice').checked = false;
        document.getElementById('tplShowQurbaniInvoice').checked = false;
        document.getElementById('tplShowAutomation').checked = false;
        document.getElementById('tplIsDefault').checked = false;
        document.getElementById('tplIsActive').checked = true;
        setTemplateScope('common');
        document.getElementById('tplFormHeading').textContent = 'Add New Template';
        document.getElementById('tplSaveBtn').textContent = 'Save Template';
        document.getElementById('tplCancelBtn').style.display = 'none';
        document.getElementById('tplFormBanner').style.display = 'none';
    }

    // --- Template scope (Common / Regular-only / Qurbani-only) ---------
    // The three radio buttons map to two backend booleans:
    //   common  → is_qurbani_only=0, is_regular_only=0   (shows everywhere)
    //   regular → is_qurbani_only=0, is_regular_only=1   (hidden on Qurbani)
    //   qurbani → is_qurbani_only=1, is_regular_only=0   (hidden on Regular)
    function getTemplateScope() {
        const r = document.querySelector('input[name="tplScope"]:checked');
        return r ? r.value : 'common';
    }
    function setTemplateScope(scope) {
        const valid = ['common','regular','qurbani'].includes(scope) ? scope : 'common';
        const radio = document.querySelector('input[name="tplScope"][value="'+valid+'"]');
        if (radio) radio.checked = true;
        refreshTemplateScopeUI();
    }
    function refreshTemplateScopeUI() {
        const selected = getTemplateScope();
        document.querySelectorAll('.tpl-scope-opt').forEach(el => {
            el.classList.toggle('tpl-scope-selected', el.getAttribute('data-scope') === selected);
        });
    }
    // Attach listeners once the manager modal is rendered (the form exists at page load).
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('input[name="tplScope"]').forEach(r => {
            r.addEventListener('change', refreshTemplateScopeUI);
        });
        refreshTemplateScopeUI();
    });

    function getShowInValue() {
        const parts = [];
        if (document.getElementById('tplShowMessages').checked) parts.push('messages');
        if (document.getElementById('tplShowOrders').checked) parts.push('orders');
        if (document.getElementById('tplShowCustomers').checked) parts.push('customers');
        if (document.getElementById('tplShowShopify').checked) parts.push('shopify');
        if (document.getElementById('tplShowInvoice').checked) parts.push('invoice');
        if (document.getElementById('tplShowQurbaniInvoice').checked) parts.push('qurbani_invoice');
        if (document.getElementById('tplShowAutomation').checked) parts.push('automation');
        return parts.length ? parts.join(',') : 'messages';
    }

    window.saveTemplateForm = function() {
        const name = document.getElementById('tplName').value.trim();
        const displayName = document.getElementById('tplDisplayName').value.trim();
        const body = document.getElementById('tplBody').value.trim();
        if (!name || !displayName || !body) { alert('Please fill in Template Name, Display Name, and Body Text.'); return; }

        const hasButtons = document.getElementById('tplHasButtons').value === '1';
        const buttonLabels = hasButtons
            ? document.getElementById('tplButtonLabels').value.split(',').map(s => s.trim()).filter(Boolean)
            : [];

        const payload = {
            name: name,
            display_name: displayName,
            body_text: body,
            category: document.getElementById('tplCategory').value,
            variable_count: parseInt(document.getElementById('tplVarCount').value) || 0,
            has_buttons: hasButtons ? 1 : 0,
            button_labels: buttonLabels,
            header_text: document.getElementById('tplHeader').value.trim(),
            footer_text: document.getElementById('tplFooter').value.trim(),
            show_in: getShowInValue(),
            is_default: document.getElementById('tplIsDefault').checked ? 1 : 0,
            is_active: document.getElementById('tplIsActive').checked ? 1 : 0,
            is_qurbani_only: getTemplateScope() === 'qurbani' ? 1 : 0,
            is_regular_only: getTemplateScope() === 'regular' ? 1 : 0
        };

        const isEdit = editingTemplateId !== null;
        const url = isEdit ? '/messages/templates/' + editingTemplateId : '/messages/templates';
        const method = isEdit ? 'PUT' : 'POST';

        const btn = document.getElementById('tplSaveBtn');
        const origText = btn.textContent;
        btn.disabled = true;
        btn.textContent = isEdit ? 'Updating…' : 'Saving…';

        apiFetch(url, { method: method, body: JSON.stringify(payload) }).then(d => {
            btn.disabled = false;
            btn.textContent = origText;
            if (d.success) {
                _cachedApiTemplates = null;
                resetTemplateForm();
                loadExistingTemplates();
            } else {
                alert(d.message || 'Failed to save template');
            }
        }).catch(() => {
            btn.disabled = false;
            btn.textContent = origText;
            alert('Failed to save template');
        });
    };

    window.deleteTemplate = function(id) {
        if (!confirm('Delete this template?')) return;
        apiFetch('/messages/templates/' + id, {method: 'DELETE'}).then(d => {
            if (d.success) { _cachedApiTemplates = null; loadExistingTemplates(); }
            else alert(d.message || 'Failed to delete');
        }).catch(() => alert('Failed to delete'));
    };

    // ── Orders Panel (right sidebar) ──
    let ordersPanelOpen = localStorage.getItem('waOrdersPanel') !== 'closed';

    window.toggleOrdersPanel = function() {
        const panel = document.getElementById('waOrdersPanel');
        ordersPanelOpen = !panel.classList.contains('open');
        panel.classList.toggle('open', ordersPanelOpen);
        localStorage.setItem('waOrdersPanel', ordersPanelOpen ? 'open' : 'closed');
    };

    function loadOrdersPanel(customerId) {
        const list = document.getElementById('waOrdersList');
        list.innerHTML = '<div style="padding:20px;text-align:center;"><div style="width:20px;height:20px;border:2px solid #e5e7eb;border-top-color:#16a34a;border-radius:50%;animation:spin 0.6s linear infinite;margin:0 auto;"></div></div>';

        apiFetch('/messages/customer-orders/' + customerId).then(d => {
            // Phase 4 (May-2026) — refresh the chat-header active
            // delivery banner from this same response so we don't add
            // an extra HTTP poll. The backend pre-computes
            // active_delivery (soonest-ETA OFD entity) so the front
            // end just renders.
            renderActiveDeliveryBanner(d && d.active_delivery ? d.active_delivery : null);

            if (!d.success || !d.orders || !d.orders.length) {
                list.innerHTML = '<div style="padding:20px;text-align:center;color:#9ca3af;font-size:13px;">No orders found</div>';
                return;
            }
            let html = '';
            d.orders.forEach(o => {
                const dt = o.order_date ? new Date(o.order_date).toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'}) : '';
                const st = (o.status || 'pending').toLowerCase().replace(/\s+/g, '_');
                const statusLabel = (o.status || 'Pending').replace(/_/g, ' ');
                // Cash/online chip + persistent proof/verified badge. The proof
                // badge stays regardless of approval — it's a record here, not
                // an action prompt (the inbox list is where it clears on approval).
                const pm = (o.payment_method || '').toLowerCase();
                let pmChip = '';
                if (pm === 'online') pmChip = '<span style="font-size:10px;font-weight:700;color:#1d4ed8;background:#dbeafe;padding:1px 6px;border-radius:8px;">💳 Online</span>';
                else if (pm === 'cash') pmChip = '<span style="font-size:10px;font-weight:700;color:#166534;background:#dcfce7;padding:1px 6px;border-radius:8px;">💵 Cash</span>';
                let proofChip = '';
                const pp = o.payment_proof;
                if (pp && pp.status && pp.status !== 'none') {
                    const ic = (pp.has_whatsapp ? '📷' : '') + (pp.has_sms ? '📱' : '') + (pp.has_email ? '✉️' : '');
                    proofChip = '<span style="font-size:10px;font-weight:700;color:#fff;background:' + (pp.color || '#9ca3af') + ';padding:1px 6px;border-radius:8px;">' + (ic ? ic + ' ' : '') + esc(pp.label || '') + '</span>';
                }
                html += `<div class="wa-op-item">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <div><span class="wa-op-num">#${esc(o.order_number||'')}</span><span class="wa-op-date">${dt}</span></div>
                        <span class="wa-op-total">Rs. ${parseFloat(o.total||0).toLocaleString()}</span>
                    </div>
                    <div style="margin-top:4px;display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                        <span class="wa-op-status ${st}">${statusLabel}</span>
                        ${o.items_count ? '<span style="font-size:10px;color:#9ca3af;">' + o.items_count + ' items</span>' : ''}
                        ${pmChip}
                        ${proofChip}
                    </div>
                    ${o.rider_name ? '<div class="wa-op-rider">🏍️ ' + esc(o.rider_name) + (o.eta ? ' · ETA ' + o.eta : '') + '</div>' : (o.eta ? '<div class="wa-op-rider">⏱️ ETA ' + o.eta + '</div>' : '')}
                    ${o.items_summary ? '<div class="wa-op-items">' + esc(o.items_summary) + '</div>' : ''}
                    <div style="display:flex; gap:6px; flex-wrap:wrap; margin-top:8px;">
                        <button class="wa-op-inv-btn" style="flex:1; min-width:120px; margin-top:0;" onclick="openInvoiceFromPanel(${o.id}, '${esc(o.order_number||'')}', ${parseFloat(o.total||0)})">📄 Send Invoice</button>
                        ${o.is_qurbani && (o.qurbani_items || []).length ? `<button class="wa-op-inv-btn" style="flex:1; min-width:120px; margin-top:0; background:#dbeafe; border-color:#93c5fd; color:#1e3a8a;" onclick='openMessagesTimeline(${JSON.stringify({order_id:o.id, order_number:o.order_number||'', items:o.qurbani_items||[]}).replace(/'/g, "&#39;")})'>🕒 Timeline</button>` : ''}
                    </div>
                </div>`;
            });
            list.innerHTML = html;
        }).catch(() => {
            list.innerHTML = '<div style="padding:20px;text-align:center;color:#dc2626;font-size:13px;">Failed to load</div>';
        });

        if (ordersPanelOpen) {
            document.getElementById('waOrdersPanel').classList.add('open');
        }
    }

    // ─── Phase 4 (May-2026) — Active Delivery Banner ─────────────
    // The banner sits between the chat header and the message list
    // and surfaces a one-line summary when the active customer has
    // ANY order (regular or qurbani) that's currently out for
    // delivery — ETA, rider, and how many stops are ahead in the
    // rider's route. Self-updating via the existing poll cadence
    // (loadOrdersPanel + convPollTimer below).
    //
    // For qurbani entries, clicking the banner opens the timeline
    // modal for that line item. For regular entries it just shows
    // a tooltip — there's no per-order timeline yet.
    function renderActiveDeliveryBanner(ad) {
        const el = document.getElementById('waActiveDeliveryBanner');
        if (!el) return;
        if (!ad) {
            el.style.display = 'none';
            el.innerHTML = '';
            el.onclick = null;
            return;
        }

        // Friendly bits ---------------------------------------------
        const etaTxt = ad.eta_human ? '⏱ ETA ' + esc(ad.eta_human) : '⏱ ETA pending';
        let aheadTxt;
        if (ad.ahead_count === 0) {
            aheadTxt = '🛵 Next stop on the route';
        } else if (ad.ahead_count === 1) {
            aheadTxt = '🛵 1 stop ahead';
        } else {
            aheadTxt = '🛵 ' + ad.ahead_count + ' stops ahead';
        }
        const riderTxt = ad.rider_name ? esc(ad.rider_name) : '—';
        const orderTag = ad.type === 'qurbani' ? 'Qurbani' : 'Order';
        const moreHint = (ad.more_count && ad.more_count > 0)
            ? ' &nbsp;<span style="background:#1e40af;color:#fff;border-radius:4px;padding:1px 6px;font-size:10px;font-weight:700;">+' + ad.more_count + ' more</span>'
            : '';
        const clickable = (ad.type === 'qurbani' && ad.line_item_id);

        // May-2026 — ETA freshness + drift chips. The CS manager
        // looks at this banner BEFORE quoting an ETA to a customer,
        // so we surface (a) how stale the displayed ETA is and (b)
        // whether the customer was previously told a different
        // value via WhatsApp. Both signals come pre-baked from the
        // controller; we just render them.
        function fmtAge(m) {
            if (m == null) return null;
            if (m < 1) return 'just now';
            if (m < 60) return m + 'm ago';
            const h = Math.floor(m / 60); const r = m % 60;
            return h + 'h' + (r ? ' ' + r + 'm' : '') + ' ago';
        }
        let freshChip = '';
        if (ad.eta_age_minutes != null) {
            const ageLabel = fmtAge(ad.eta_age_minutes);
            const color = ad.eta_age_minutes <= 5  ? '#16a34a'
                         : ad.eta_age_minutes >= 30 ? '#b45309' : '#6b7280';
            freshChip = '<span style="font-size:10px;color:' + color
                + ';font-weight:600;">calc\'d ' + esc(ageLabel) + '</span>';
        }
        let driftChip = '';
        if (ad.eta_drift_state && ad.eta_drift_state !== 'none' && ad.eta_drift_minutes != null) {
            const palette = {
                in_sync:  { bg: '#d1fae5', fg: '#065f46', bd: '#10b981', text: '✓ Customer has correct ETA' },
                drifting: { bg: '#fef3c7', fg: '#92400e', bd: '#f59e0b', text: 'ETA shifted ' + (ad.eta_drift_minutes>=0?'+':'') + ad.eta_drift_minutes + 'm since last WhatsApp' },
                stale:    { bg: '#fee2e2', fg: '#991b1b', bd: '#ef4444', text: '⚠ Customer has STALE ETA · ' + (ad.eta_drift_minutes>=0?'+':'') + ad.eta_drift_minutes + 'm drift · send update' },
            };
            const p = palette[ad.eta_drift_state] || palette.drifting;
            let msgTip = '';
            if (ad.messaged_eta_at) {
                try {
                    const m = new Date(String(ad.messaged_eta_at).replace(' ', 'T'));
                    msgTip = ' (told ' + m.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'}) + ')';
                } catch (_) {}
            }
            driftChip = '<div style="margin-top:6px;padding:5px 10px;background:' + p.bg
                + ';color:' + p.fg + ';border:1px solid ' + p.bd
                + ';border-radius:6px;font-size:11px;font-weight:700;display:flex;align-items:center;gap:6px;">'
                + esc(p.text + msgTip)
                + '</div>';
        }

        el.style.display = 'block';
        el.style.cursor = clickable ? 'pointer' : 'default';
        el.title = clickable ? 'Click to open the order timeline' : '';

        el.innerHTML =
            '<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">' +
                '<span style="background:#1e40af;color:#fff;border-radius:6px;padding:2px 7px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;">Out for Delivery</span>' +
                '<span style="font-weight:700;">#' + esc(ad.order_number || '') + '</span>' +
                '<span style="font-size:11px;color:#1d4ed8;">' + esc(orderTag) + ' · Rider: ' + riderTxt + '</span>' +
                '<span style="margin-left:auto;display:flex;gap:8px;align-items:center;">' +
                    '<span style="background:#fff;border:1px solid #bfdbfe;border-radius:6px;padding:2px 8px;font-weight:700;color:#1e3a8a;">' + aheadTxt + '</span>' +
                    '<span style="background:#fff;border:1px solid #bfdbfe;border-radius:6px;padding:2px 8px;font-weight:700;color:#1e3a8a;display:flex;flex-direction:column;align-items:center;line-height:1.15;">' +
                        '<span>' + etaTxt + '</span>' +
                        (freshChip ? freshChip : '') +
                    '</span>' +
                    moreHint +
                '</span>' +
            '</div>' +
            driftChip;

        if (clickable) {
            el.onclick = () => {
                // Reuse the same timeline modal that the right-rail
                // Timeline button opens. We synthesize the minimal
                // payload it expects.
                openMessagesTimeline({
                    order_id: ad.order_id,
                    order_number: ad.order_number,
                    items: [{
                        line_item_id: ad.line_item_id,
                        name: ad.line_item_name || '',
                        qurbani_day: ad.qurbani_day || '',
                        qurbani_slot: ad.qurbani_slot || '',
                        qurbani_delivery_type: ad.qurbani_delivery_type || '',
                    }],
                });
            };
        } else {
            el.onclick = null;
        }
    }

    // Refresh banner on the existing convPollTimer cadence — keeps
    // ETA / ahead-count current as the rider delivers earlier stops
    // without adding a new HTTP loop.
    function refreshActiveDeliveryBanner() {
        if (!activeConv || !activeConv.customer_id) {
            renderActiveDeliveryBanner(null);
            return;
        }
        apiFetch('/messages/customer-orders/' + activeConv.customer_id)
            .then(d => {
                if (!activeConv) return; // user changed convs while in flight
                renderActiveDeliveryBanner(d && d.active_delivery ? d.active_delivery : null);
            })
            .catch(() => {});
    }
    setInterval(() => {
        // Only fetch when there's an open chat AND the banner is
        // currently visible OR could become visible — but since we
        // need the response to know that, we just always poll when
        // a chat is open. One small request per POLL_INTERVAL. The
        // backend response is cheap (eager-loaded fields, batched
        // ahead-count queries).
        if (activeConvId && activeConv && activeConv.customer_id) {
            refreshActiveDeliveryBanner();
        }
    }, POLL_INTERVAL);

    // ─── Phase 3 (May-2026) — Qurbani Timeline modal ──────────────
    // Opens a slide-in panel showing the timeline for one Qurbani
    // line item (status events, dispatch, ETA, delay alert, today's
    // WhatsApp activity for this customer). When the order has more
    // than one Qurbani bundle, a tab strip at the top lets the user
    // switch between bundles WITHOUT closing the modal.
    //
    // Reuses the same /api/line-items/{id}/timeline endpoint built
    // for the qurbani orders page.
    window.openMessagesTimeline = function(payload) {
        let data;
        try { data = (typeof payload === 'string') ? JSON.parse(payload) : payload; }
        catch (e) { console.error('Bad timeline payload', e); return; }
        if (!data || !data.items || !data.items.length) return;

        let modal = document.getElementById('waTimelineModal');
        if (modal) modal.remove();

        modal = document.createElement('div');
        modal.id = 'waTimelineModal';
        modal.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:10001;display:flex;justify-content:flex-end;';
        modal.onclick = (e) => { if (e.target === modal) modal.remove(); };

        // Tab list — single bundle gets no tab strip, just a header.
        const items = data.items;
        const tabsHtml = items.length > 1
            ? `<div id="waTlTabs" style="display:flex; gap:4px; padding:8px 12px; background:#f9fafb; border-bottom:1px solid #e5e7eb; overflow-x:auto; flex-shrink:0;">
                  ${items.map((it, i) => {
                      const lbl = (it.qurbani_day ? it.qurbani_day + ' · ' : '') + (it.name || ('Item ' + (i+1)));
                      return `<button class="wa-tl-tab" data-idx="${i}" data-li="${it.line_item_id}" style="padding:6px 12px; border-radius:6px; border:1px solid #d1d5db; background:#fff; font-size:12px; font-weight:600; cursor:pointer; white-space:nowrap;">${esc(lbl)}</button>`;
                  }).join('')}
              </div>`
            : '';

        modal.innerHTML = `
            <div style="background:#fff; width:540px; max-width:96vw; height:100vh; display:flex; flex-direction:column; box-shadow:-10px 0 30px rgba(0,0,0,0.2);">
                <div style="padding:14px 16px; background:linear-gradient(135deg,#1e40af,#1e3a8a); color:#fff; flex-shrink:0;">
                    <div style="display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <div style="font-size:11px; opacity:0.85; text-transform:uppercase; letter-spacing:0.5px;">Timeline</div>
                            <div style="font-size:16px; font-weight:700;">#${esc(data.order_number || '')}</div>
                        </div>
                        <button onclick="document.getElementById('waTimelineModal').remove()" style="background:rgba(255,255,255,0.2); border:none; color:#fff; width:32px; height:32px; border-radius:6px; font-size:18px; cursor:pointer;">&times;</button>
                    </div>
                </div>
                ${tabsHtml}
                <div id="waTlBody" style="flex:1; overflow-y:auto; padding:14px 16px; background:#f9fafb;">
                    <div style="padding:30px; text-align:center; color:#6b7280;">Loading…</div>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        // Tab clicks load that line item's timeline into the body.
        modal.querySelectorAll('.wa-tl-tab').forEach(btn => {
            btn.addEventListener('click', () => {
                modal.querySelectorAll('.wa-tl-tab').forEach(b => {
                    b.style.background = '#fff';
                    b.style.color = '#374151';
                    b.style.borderColor = '#d1d5db';
                });
                btn.style.background = '#1e40af';
                btn.style.color = '#fff';
                btn.style.borderColor = '#1e40af';
                fetchAndRenderMessagesTimeline(parseInt(btn.dataset.li, 10));
            });
        });

        // Auto-select first tab.
        const firstTab = modal.querySelector('.wa-tl-tab') || null;
        if (firstTab) firstTab.click();
        else fetchAndRenderMessagesTimeline(items[0].line_item_id);
    };

    function fetchAndRenderMessagesTimeline(lineItemId) {
        const body = document.getElementById('waTlBody');
        if (!body) return;
        body.innerHTML = '<div style="padding:30px; text-align:center; color:#6b7280;">Loading…</div>';
        fetch('/qurbani/api/line-items/' + lineItemId + '/timeline', { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(d => {
                if (!d || !d.success) {
                    body.innerHTML = '<div style="padding:20px; color:#dc2626;">Failed to load timeline.</div>';
                    return;
                }
                body.innerHTML = renderMessagesTimeline(d);
            })
            .catch(() => {
                body.innerHTML = '<div style="padding:20px; color:#dc2626;">Network error.</div>';
            });
    }

    // Field shape mirrors the qurbani/orders timeline modal renderer
    // so updates to that endpoint flow through here transparently.
    function renderMessagesTimeline(d) {
        const fmtTime = (ts) => {
            if (!ts) return '';
            try {
                const dt = new Date(String(ts).replace(' ', 'T'));
                return dt.toLocaleString([], { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
            } catch (e) { return String(ts); }
        };

        const li = d.line_item || {};
        const events = d.events || [];
        const rider = d.rider || null;
        const dispatch = d.dispatch || null;
        const currentEta = d.current_eta || null;
        const delay = d.delay_alert || null;
        const wa = d.whatsapp_today || {};

        let html = '';

        // Item summary chips.
        const itemBits = [];
        if (li.qurbani_day) itemBits.push(esc(li.qurbani_day));
        if (li.qurbani_slot) itemBits.push(esc(li.qurbani_slot));
        if (li.qurbani_delivery_type) itemBits.push(esc(li.qurbani_delivery_type));
        if (li.qurbani_sub_region) itemBits.push(esc(li.qurbani_sub_region));
        if (itemBits.length) {
            html += '<div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:14px;">';
            itemBits.forEach(t => {
                html += '<span style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:3px 8px;font-size:11px;font-weight:600;color:#374151;">' + t + '</span>';
            });
            html += '</div>';
        }

        if (delay && delay.active) {
            html += '<div style="background:#fef3c7;border:1px solid #f59e0b;border-radius:8px;padding:10px 12px;margin-bottom:14px;display:flex;gap:10px;align-items:flex-start;">'
                + '<span style="font-size:18px;line-height:1;">⚠️</span>'
                + '<div style="flex:1;font-size:13px;color:#92400e;line-height:1.45;"><strong>Running late.</strong> ' + esc(delay.reason || '') + '</div>'
                + '</div>';
        }

        if (rider || dispatch) {
            html += '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;margin-bottom:14px;">';
            html += '<div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">Rider &amp; Dispatch</div>';
            html += rider
                ? '<div style="font-size:13px;color:#1f2937;margin-bottom:4px;"><strong>🛵 Rider:</strong> ' + esc(rider.name || '') + '</div>'
                : '<div style="font-size:13px;color:#9ca3af;margin-bottom:4px;font-style:italic;">No rider assigned yet.</div>';
            if (dispatch) {
                let line = '<strong>🚀 Dispatched:</strong> ' + esc(fmtTime(dispatch.at));
                if (dispatch.by_name) line += ' · by ' + esc(dispatch.by_name);
                html += '<div style="font-size:13px;color:#1f2937;margin-bottom:4px;">' + line + '</div>';
                if (dispatch.started_at) {
                    html += '<div style="font-size:13px;color:#0e7490;"><strong>🏁 Rider started:</strong> ' + esc(fmtTime(dispatch.started_at)) + '</div>';
                }
            } else {
                html += '<div style="font-size:13px;color:#9ca3af;font-style:italic;">Not yet dispatched.</div>';
            }
            html += '</div>';
        }

        if (currentEta) {
            html += '<div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:10px 12px;margin-bottom:14px;">';
            html += '<div style="font-size:11px;font-weight:700;color:#1e40af;text-transform:uppercase;letter-spacing:.5px;margin-bottom:6px;">Current ETA</div>';
            html += '<div style="font-size:18px;font-weight:700;color:#1e3a8a;">⏱ ' + esc(fmtTime(currentEta.at)) + '</div>';
            if (currentEta.note) {
                html += '<div style="font-size:11px;color:#1d4ed8;margin-top:4px;">' + esc(currentEta.note) + '</div>';
            } else if (currentEta.is_initial && currentEta.calculated_at) {
                html += '<div style="font-size:11px;color:#1d4ed8;margin-top:4px;">Initial estimate from dispatch.</div>';
            }
            // Phase 4 (May-2026): mirror the route-position block
            // from the qurbani/orders timeline modal so customer
            // service can answer "how many stops away is my order?"
            // straight from the chat.
            const rp = d.route_position || null;
            if (rp && rp.is_in_dispatch) {
                const totalLine = (rp.total_remaining > 0)
                    ? rp.total_remaining + ' total stop' + (rp.total_remaining === 1 ? '' : 's') + ' still pending in this dispatch'
                    : '';
                html += '<div style="margin-top:10px;padding-top:10px;border-top:1px dashed #bfdbfe;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">'
                    + '<span style="background:#1e40af;color:#fff;border-radius:6px;padding:3px 8px;font-size:12px;font-weight:700;">🚚 ' + esc(rp.label) + '</span>'
                    + (totalLine ? '<span style="font-size:11px;color:#1d4ed8;">' + esc(totalLine) + '</span>' : '')
                    + '</div>';
            }
            // Phase 4 (May-2026) — slot vs ETA / delivered chip.
            // Mirrors the qurbani/orders timeline modal so the
            // customer-service rep replying from messages sees the
            // same "🟢 Within slot" / "🟡 ETA past slot" verdict.
            if (d.slot_compare && d.slot_compare.label) {
                const sc = d.slot_compare;
                const isWithin = sc.state === 'within';
                const isDeliveredCmp = !!(d.line_item && d.line_item.qurbani_item_status === 'delivered');
                const bg = isWithin ? '#d1fae5' : (isDeliveredCmp ? '#fee2e2' : '#fef3c7');
                const fg = isWithin ? '#065f46' : (isDeliveredCmp ? '#991b1b' : '#92400e');
                const bd = isWithin ? '#10b981' : (isDeliveredCmp ? '#ef4444' : '#f59e0b');
                html += '<div style="margin-top:8px;padding:6px 8px;background:' + bg + ';color:' + fg + ';border:1px solid ' + bd + ';border-radius:6px;font-size:12px;font-weight:700;display:inline-block;">'
                    + esc(sc.label) + '</div>';
            }
            html += '</div>';
        }

        html += '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:10px 12px;margin-bottom:14px;">';
        html += '<div style="font-size:11px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Status Events</div>';
        if (!events.length) {
            html += '<div style="font-size:13px;color:#9ca3af;font-style:italic;">No status events yet.</div>';
        } else {
            events.forEach((ev, idx) => {
                const isLast = idx === events.length - 1;
                html += '<div style="display:flex;gap:10px;align-items:flex-start;position:relative;padding-bottom:' + (isLast ? '0' : '14px') + ';">';
                if (!isLast) {
                    html += '<div style="position:absolute;left:11px;top:22px;bottom:0;width:2px;background:' + esc(ev.color || '#e5e7eb') + ';opacity:0.35;"></div>';
                }
                html += '<div style="width:24px;height:24px;border-radius:50%;background:' + esc(ev.color || '#6b7280') + ';color:#fff;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;line-height:1;z-index:1;">' + esc(ev.icon || '•') + '</div>';
                html += '<div style="flex:1;min-width:0;">';
                html += '<div style="font-size:13px;font-weight:600;color:#1f2937;">' + esc(ev.label || '') + '</div>';
                const metaParts = [];
                if (ev.at) metaParts.push(fmtTime(ev.at));
                if (ev.by) metaParts.push('by ' + ev.by);
                if (metaParts.length) {
                    html += '<div style="font-size:11px;color:#6b7280;margin-top:2px;">' + esc(metaParts.join(' · ')) + '</div>';
                }
                html += '</div></div>';
            });
        }
        html += '</div>';

        html += '<div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:10px 12px;">';
        html += '<div style="font-size:11px;font-weight:700;color:#15803d;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">📱 WhatsApp · Today</div>';
        if (!wa.last_inbound && !wa.last_outbound) {
            html += '<div style="font-size:13px;color:#6b7280;font-style:italic;">No messages exchanged today.</div>';
        } else {
            if (wa.last_inbound) {
                html += '<div style="margin-bottom:8px;">'
                    + '<div style="font-size:11px;color:#6b7280;">Last received · ' + esc(fmtTime(wa.last_inbound.at)) + '</div>'
                    + '<div style="font-size:12px;color:#374151;padding:6px 8px;background:#fff;border-radius:6px;margin-top:3px;">' + esc((wa.last_inbound.preview || '').slice(0, 200)) + '</div>'
                    + '</div>';
            }
            if (wa.last_outbound) {
                html += '<div>'
                    + '<div style="font-size:11px;color:#6b7280;">Last sent · ' + esc(fmtTime(wa.last_outbound.at))
                    + (wa.last_outbound.template_name ? ' · ' + esc(wa.last_outbound.template_name) : '')
                    + (wa.last_outbound.by ? ' · by ' + esc(wa.last_outbound.by) : '')
                    + '</div>'
                    + '<div style="font-size:12px;color:#374151;padding:6px 8px;background:#dcfce7;border-radius:6px;margin-top:3px;">' + esc((wa.last_outbound.preview || '').slice(0, 200)) + '</div>'
                    + '</div>';
            }
        }
        html += '</div>';

        return html;
    }

    window.openInvoiceFromPanel = function(orderId, orderNum, total) {
        if (!activeConv) return;
        // Open the invoice picker modal pre-filled
        let modal = document.getElementById('waInvoicePickerModal');
        if (modal) modal.remove();

        const custName = activeConv.customer_name || '';
        modal = document.createElement('div');
        modal.id = 'waInvoicePickerModal';
        modal.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;';
        modal.innerHTML = `
            <div style="background:#fff;border-radius:12px;width:520px;max-width:95vw;max-height:85vh;overflow:auto;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
                <div style="padding:14px 20px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;">
                    <span style="font-weight:700;font-size:15px;">📄 Send Invoice #${orderNum}</span>
                    <button onclick="document.getElementById('waInvoicePickerModal').remove()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#6b7280;">&times;</button>
                </div>
                <div style="padding:16px 20px;" id="waInvPickerBody"></div>
            </div>`;
        document.body.appendChild(modal);
        modal.addEventListener('click', function(e) { if (e.target === modal) modal.remove(); });
        selectInvoiceOrder(orderId, orderNum, total);
    };

    // ── Invoice Picker ──
    window.openInvoicePicker = function() {
        if (!activeConv || !activeConv.customer_id) {
            alert('This conversation has no linked customer. Cannot look up invoices.');
            return;
        }
        let modal = document.getElementById('waInvoicePickerModal');
        if (modal) modal.remove();

        modal = document.createElement('div');
        modal.id = 'waInvoicePickerModal';
        modal.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.5);z-index:10000;display:flex;align-items:center;justify-content:center;';
        modal.innerHTML = `
            <div style="background:#fff;border-radius:12px;width:520px;max-width:95vw;max-height:85vh;overflow:auto;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
                <div style="padding:14px 20px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;">
                    <span style="font-weight:700;font-size:15px;">📄 Send Invoice — ${esc(activeConv.customer_name || '')}</span>
                    <button onclick="document.getElementById('waInvoicePickerModal').remove()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#6b7280;">&times;</button>
                </div>
                <div style="padding:16px 20px;" id="waInvPickerBody">
                    <div style="text-align:center;padding:20px;color:#6b7280;">Loading orders...</div>
                </div>
            </div>`;
        document.body.appendChild(modal);
        modal.addEventListener('click', function(e) { if (e.target === modal) modal.remove(); });

        apiFetch('/messages/customer-orders/' + activeConv.customer_id).then(d => {
            const body = document.getElementById('waInvPickerBody');
            if (!d.success || !d.orders.length) {
                body.innerHTML = '<div style="text-align:center;padding:20px;color:#6b7280;">No orders found for this customer.</div>';
                return;
            }
            let html = '<div style="margin-bottom:12px;font-size:13px;color:#6b7280;">Select an order to send its invoice:</div>';
            d.orders.forEach(o => {
                const dt = o.order_date ? new Date(o.order_date).toLocaleDateString() : '';
                html += `<div onclick="selectInvoiceOrder(${o.id}, '${esc(o.order_number)}', ${parseFloat(o.total||0)})" style="padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:8px;cursor:pointer;transition:background 0.15s;" onmouseover="this.style.background='#f0fdf4'" onmouseout="this.style.background='#fff'">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <div>
                            <span style="font-weight:600;font-size:14px;color:#111827;">#${esc(o.order_number)}</span>
                            <span style="font-size:12px;color:#6b7280;margin-left:8px;">${dt}</span>
                        </div>
                        <div style="text-align:right;">
                            <div style="font-weight:600;font-size:14px;color:#111827;">Rs. ${parseFloat(o.total||0).toLocaleString()}</div>
                            <div style="font-size:11px;color:#6b7280;">${o.items_count} item(s)</div>
                        </div>
                    </div>
                    ${o.items_summary ? '<div style="font-size:11px;color:#9ca3af;margin-top:4px;">' + esc(o.items_summary) + '</div>' : ''}
                </div>`;
            });
            body.innerHTML = html;
        }).catch(e => {
            document.getElementById('waInvPickerBody').innerHTML = '<div style="text-align:center;padding:20px;color:#dc2626;">Failed to load orders.</div>';
        });
    };

    window.selectInvoiceOrder = function(orderId, orderNum, total) {
        const body = document.getElementById('waInvPickerBody');
        const custName = activeConv ? activeConv.customer_name : '';
        body.innerHTML = `
            <div style="margin-bottom:12px;">
                <div style="font-size:12px;color:#6b7280;">Order</div>
                <div style="font-weight:600;font-size:15px;">#${orderNum} — Rs. ${total.toLocaleString()}</div>
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px;">Invoice Template Name</label>
                <input id="waInvTplName" type="text" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;" placeholder="e.g. send_invoice" />
                <div style="font-size:11px;color:#9ca3af;margin-top:2px;">Must match the approved template in Meta Business Suite</div>
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px;">Body Variables (comma-separated)</label>
                <input id="waInvTplParams" type="text" value="${custName}, ${orderNum}" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;" />
            </div>
            <div id="waInvPickerPreview" style="margin-bottom:12px;display:none;">
                <label style="font-size:12px;color:#6b7280;display:block;margin-bottom:4px;">Invoice Preview</label>
                <div style="border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;text-align:center;background:#f9fafb;padding:6px;">
                    <img id="waInvPickerPreviewImg" style="max-width:100%;max-height:250px;border-radius:4px;cursor:pointer;" onclick="openFullscreenImg(this.src)" title="Click to view full size" />
                </div>
            </div>
            <div style="display:flex;gap:8px;">
                <button id="waInvPickerPrevBtn" onclick="previewInvPicker(${orderId})" style="flex:1;padding:9px;border:1px solid #d97706;color:#d97706;background:#fff;border-radius:8px;font-weight:600;cursor:pointer;font-size:13px;">Preview</button>
                <button id="waInvPickerSendBtn" onclick="sendInvPicker(${orderId})" style="flex:1;padding:9px;background:#25D366;color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer;font-size:13px;">Send Invoice</button>
            </div>
            <div id="waInvPickerStatus" style="margin-top:8px;font-size:13px;text-align:center;display:none;"></div>`;

        // Resolve the right invoice template + body variables (online vs cash,
        // with-ETA vs no-ETA by whether the order is out for delivery) from the
        // server send-plan. Chat orders are always live (prod) orders, so no
        // source param is needed. The ETA window contains a comma, so we stash a
        // structured copy for comma-safe sending. Falls back to the first invoice
        // template if the plan can't be fetched.
        const fallbackInvTpl = () => apiFetch('/messages/templates?context=invoice').then(d => {
            if (d.success && d.templates && d.templates.length) {
                const el = document.getElementById('waInvTplName');
                if (el && !el.value) el.value = d.templates[0].name;
            }
        }).catch(() => {});
        apiFetch('/messages/automations/invoice-send-plan/' + orderId).then(d => {
            if (!d || !d.success || !d.template) { return fallbackInvTpl(); }
            const nameEl = document.getElementById('waInvTplName');
            if (nameEl) nameEl.value = d.template;
            const parsEl = document.getElementById('waInvTplParams');
            if (parsEl) {
                // Keep the conversation's customer name (prior behaviour); add the
                // ETA window as the 3rd var when the plan resolved one. The ETA
                // contains a comma, so stash a structured copy for comma-safe send.
                const nm = custName || (Array.isArray(d.body_params) ? (d.body_params[0] || '') : '') || '';
                const parts = d.eta ? [nm, String(orderNum), d.eta] : [nm, String(orderNum)];
                parsEl.dataset.structured = JSON.stringify(parts);
                parsEl.value = parts.join(', ');
            }
        }).catch(() => { fallbackInvTpl(); });

        // Auto-generate the preview image on order select so the user sees
        // the invoice immediately and the Send button activates. Without
        // this, Send stays disabled (because the template has an image
        // header that must be generated first) and it's not obvious why.
        setTimeout(() => { try { previewInvPicker(orderId); } catch (e) {} }, 50);
    };

    function captureInvoiceImage(invoiceUrl, orderId) {
        return new Promise((resolve, reject) => {
            const iframe = document.createElement('iframe');
            iframe.style.cssText = 'position:fixed;left:-9999px;top:0;width:900px;height:1400px;border:none;opacity:0;';
            document.body.appendChild(iframe);
            iframe.src = invoiceUrl;
            iframe.onload = async function() {
                try {
                    const addScript = (doc, src) => new Promise(r => { const s = doc.createElement('script'); s.src = src; s.onload = r; doc.head.appendChild(s); });
                    const iDoc = iframe.contentDocument || iframe.contentWindow.document;
                    await addScript(iDoc, 'https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js');
                    const node = iDoc.querySelector('.invoice-container');
                    if (!node) { iframe.remove(); reject(new Error('Invoice container not found')); return; }
                    // Aug-2026: compact ?wa=1 layout + dynamic scale. The long
                    // side targets <=1600px (WhatsApp re-encodes larger images
                    // on delivery, so extra pixels only slow the capture),
                    // width floor 800px, cap 2.2x. JPEG keeps the explicit
                    // white background (no alpha).
                    const rect = node.getBoundingClientRect();
                    const capScale = Math.max(800 / Math.max(rect.width, 1), Math.min(2.2, 1600 / Math.max(rect.height, 1)));
                    const canvas = await iframe.contentWindow.html2canvas(node, {scale: capScale, useCORS: true, allowTaint: true, backgroundColor: '#ffffff'});
                    const dataUrl = canvas.toDataURL('image/jpeg', 0.85);
                    iframe.remove();

                    const uploadRes = await apiFetch('/messages/upload-invoice-image', {
                        method: 'POST',
                        body: JSON.stringify({ order_id: orderId, image_data: dataUrl })
                    });
                    if (uploadRes.success) {
                        resolve(uploadRes);
                    } else {
                        reject(new Error(uploadRes.message || 'Upload failed'));
                    }
                } catch (err) { iframe.remove(); reject(err); }
            };
            iframe.onerror = function() { iframe.remove(); reject(new Error('Failed to load invoice')); };
        });
    }

    window.previewInvPicker = function(orderId) {
        const btn = document.getElementById('waInvPickerPrevBtn');
        btn.textContent = 'Loading...'; btn.disabled = true;
        apiFetch('/messages/invoice-image/' + orderId).then(d => {
            if (!d.success) { alert(d.message || 'Failed'); btn.textContent = 'Preview'; btn.disabled = false; return; }

            if (d.needs_capture) {
                captureInvoiceImage(d.invoice_url, orderId).then(uploadRes => {
                    document.getElementById('waInvPickerPreviewImg').src = uploadRes.image_url;
                    document.getElementById('waInvPickerPreview').style.display = 'block';
                    document.getElementById('waInvPickerSendBtn').disabled = false;
                    btn.textContent = 'Refresh'; btn.disabled = false;
                }).catch(err => { alert('Failed to capture invoice: ' + err.message); btn.textContent = 'Preview'; btn.disabled = false; });
            } else {
                document.getElementById('waInvPickerPreviewImg').src = d.image_url;
                document.getElementById('waInvPickerPreview').style.display = 'block';
                document.getElementById('waInvPickerSendBtn').disabled = false;
                btn.textContent = 'Refresh'; btn.disabled = false;
            }
        }).catch(() => { btn.textContent = 'Preview'; btn.disabled = false; });
    };

    window.sendInvPicker = function(orderId) {
        const tplName = document.getElementById('waInvTplName').value.trim();
        if (!tplName) { alert('Please enter the template name'); return; }
        const parsEl = document.getElementById('waInvTplParams');
        const paramsStr = parsEl.value.trim();
        // Prefer the structured params (set from the send-plan): the ETA window
        // contains a comma, so a naive split would shatter it. Only fall back to
        // splitting when the operator has hand-edited the field.
        let bodyParams;
        try {
            const structured = parsEl.dataset.structured ? JSON.parse(parsEl.dataset.structured) : null;
            if (Array.isArray(structured) && structured.join(', ') === paramsStr) {
                bodyParams = structured;
            } else {
                bodyParams = paramsStr ? paramsStr.split(',').map(s => s.trim()) : [];
            }
        } catch (e) {
            bodyParams = paramsStr ? paramsStr.split(',').map(s => s.trim()) : [];
        }
        const phone = activeConv ? activeConv.wa_phone : '';
        if (!phone) { alert('No phone number'); return; }

        const btn = document.getElementById('waInvPickerSendBtn');
        const status = document.getElementById('waInvPickerStatus');
        btn.textContent = 'Sending...'; btn.disabled = true; status.style.display = 'none';

        // Safety net: if the preview image hasn't been generated yet (the
        // auto-preview may still be running, or it failed), run the capture
        // flow synchronously before sending so we don't hit a silent
        // "needs_capture" 422 from the backend.
        const previewImg = document.getElementById('waInvPickerPreviewImg');
        const previewReady = previewImg && previewImg.src && !previewImg.src.endsWith('#');
        const ensurePreview = previewReady
            ? Promise.resolve(true)
            : apiFetch('/messages/invoice-image/' + orderId).then(d => {
                if (!d.success) throw new Error(d.message || 'Failed to generate invoice image');
                if (d.needs_capture) {
                    return captureInvoiceImage(d.invoice_url, orderId).then(uploadRes => {
                        if (previewImg) previewImg.src = uploadRes.image_url;
                        const prevBox = document.getElementById('waInvPickerPreview');
                        if (prevBox) prevBox.style.display = 'block';
                        return true;
                    });
                }
                if (previewImg) previewImg.src = d.image_url;
                const prevBox = document.getElementById('waInvPickerPreview');
                if (prevBox) prevBox.style.display = 'block';
                return true;
            });

        ensurePreview.then(() => apiFetch('/messages/send-invoice', {
            method: 'POST',
            body: JSON.stringify({ order_id: orderId, phone: phone, template_name: tplName, body_params: bodyParams, conversation_id: activeConvId })
        })).then(d => {
            if (d.success) {
                status.style.display = 'block'; status.style.color = '#16a34a'; status.textContent = 'Invoice sent!';
                btn.textContent = 'Sent!';
                setTimeout(() => {
                    document.getElementById('waInvoicePickerModal')?.remove();
                    if (activeConvId) apiFetch('/messages/conversations/' + activeConvId).then(r => { if (r.success) renderMessages(r.messages, r.has_more); });
                    loadConversations();
                }, 1500);
            } else { status.style.display = 'block'; status.style.color = '#dc2626'; status.textContent = d.message || 'Failed'; btn.textContent = 'Send Invoice'; btn.disabled = false; }
        }).catch(e => { status.style.display = 'block'; status.style.color = '#dc2626'; status.textContent = e.message || 'Failed'; btn.textContent = 'Send Invoice'; btn.disabled = false; });
    };

    window.openFullscreenImg = function(src) {
        if (!src) return;
        let overlay = document.getElementById('waImgOverlay');
        if (overlay) overlay.remove();
        overlay = document.createElement('div');
        overlay.id = 'waImgOverlay';
        overlay.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,0.85);z-index:99999;display:flex;align-items:center;justify-content:center;cursor:zoom-out;';
        overlay.innerHTML = '<img src="' + src + '" style="max-width:92vw;max-height:92vh;border-radius:8px;box-shadow:0 8px 32px rgba(0,0,0,0.5);" /><button style="position:absolute;top:16px;right:24px;background:rgba(255,255,255,0.15);border:none;color:#fff;font-size:28px;cursor:pointer;border-radius:50%;width:44px;height:44px;display:flex;align-items:center;justify-content:center;">&times;</button>';
        overlay.addEventListener('click', function() { overlay.remove(); });
        document.body.appendChild(overlay);
    };

    // ═══════════════════════════════════════════════════════════════════
    // LABELS + 3-DOT MENU (Phase 1)
    //
    // Responsibilities:
    //   • Fetch the workspace label library lazily (first time the labels
    //     modal or filter dropdown opens). Cached in `allLabels` for the
    //     rest of the session until we bust it after create/update/delete.
    //   • Render label chips on inbox rows and in the chat header.
    //   • Apply / remove labels on the active conversation.
    //   • Library CRUD (gated on `can_manage` from the API response).
    //   • "Mark Unread" — hits the new markUnread endpoint and reloads.
    // ═══════════════════════════════════════════════════════════════════
    let allLabels = null;     // { labels:[…], can_manage:bool }
    let labelFilterId = null; // active filter id or null

    function labelTextColor(hex) {
        // Pick white or dark text for a given hex colour by relative
        // luminance. Handles both 3- and 6-digit hexes. Fallback to dark.
        if (!hex || typeof hex !== 'string') return '#111827';
        let h = hex.trim().replace('#','');
        if (h.length === 3) h = h.split('').map(c => c+c).join('');
        if (h.length !== 6) return '#111827';
        const r = parseInt(h.slice(0,2), 16);
        const g = parseInt(h.slice(2,4), 16);
        const b = parseInt(h.slice(4,6), 16);
        const yiq = (r*299 + g*587 + b*114) / 1000;
        return yiq >= 160 ? '#111827' : '#fff';
    }
    function chipBackground(hex) {
        // Light pastel fill for inbox rows (10% hex) falling back to
        // grey, plus a matching coloured border for definition.
        if (!hex) return { bg: '#f3f4f6', color: '#374151', border: '#e5e7eb' };
        return { bg: hex + '26', color: '#111827', border: hex + '80' };
    }
    function renderConvLabels(labels) {
        if (!labels || !labels.length) return '';
        const shown = labels.slice(0, 2);
        const extra = labels.length - shown.length;
        const chips = shown.map(l => {
            const c = chipBackground(l.color);
            return `<span class="wa-label-chip" title="${esc(l.name)}" style="background:${c.bg};color:${c.color};border:1px solid ${c.border};">${esc(l.name)}</span>`;
        }).join('');
        const more = extra > 0 ? `<span class="wa-label-chip" style="background:#e5e7eb;color:#374151;">+${extra}</span>` : '';
        return `<div class="wa-conv-labels">${chips}${more}</div>`;
    }
    function renderChatHeaderLabels(labels) {
        const wrap = document.getElementById('waChatHdrLabels');
        if (!wrap) return;
        if (!labels || !labels.length) { wrap.innerHTML = ''; return; }
        wrap.innerHTML = labels.map(l => {
            const c = chipBackground(l.color);
            return `<span class="wa-label-chip" style="background:${c.bg};color:${c.color};border:1px solid ${c.border};">
                ${esc(l.name)}
                <button class="wa-label-remove" title="Remove label" onclick="removeLabelFromActive(${l.id})">&times;</button>
            </span>`;
        }).join('');
    }

    // Hook into renderConversations — we *extend* the existing markup by
    // injecting the labels row after city. Easier than rewriting the whole
    // function, and keeps the existing chat-search snippet logic intact.
    const _origRenderConversations = renderConversations;
    renderConversations = function(convs, opts) {
        _origRenderConversations(convs, opts);
        // After the base render, walk the list and inject a labels strip
        // for each row (if the conversation has any labels).
        document.querySelectorAll('#waConvList .wa-conv-item').forEach(el => {
            const id = parseInt(el.dataset.id || '0', 10);
            const conv = convs.find(c => c.id === id);
            if (!conv) return;
            // Labels strip.
            if (conv.labels && conv.labels.length && !el.querySelector('.wa-conv-labels')) {
                const info = el.querySelector('.wa-conv-info');
                if (info) info.insertAdjacentHTML('beforeend', renderConvLabels(conv.labels));
            }
            // Phase 2 — mention dot. Injected at the end of the bottom row
            // right after the unread badge so both "needs attention" chips
            // line up. Only added when the CURRENT user has unread mentions
            // on this convo.
            if ((conv.mentions_count || 0) > 0 && !el.querySelector('.wa-mention-dot')) {
                const bottom = el.querySelector('.wa-conv-bottom') || el.querySelector('.wa-conv-info');
                if (bottom) bottom.insertAdjacentHTML('beforeend',
                    '<span class="wa-mention-dot" title="You were tagged on this conversation">@</span>');
            }
        });
    };

    // Hook into openConv — after the detail call resolves, push the
    // conversation's labels into the header strip. The original openConv
    // already sets activeConv when the promise resolves, so we rely on
    // a tiny poll to detect that happened and paint the strip. We keep
    // this simple by overriding window.openConv; preserving all the
    // existing behaviour by calling through.
    const _origOpenConv = window.openConv;
    window.openConv = function(id) {
        renderChatHeaderLabels([]); // clear while loading
        _origOpenConv(id);
        // activeConv is set asynchronously inside _origOpenConv. Poll once
        // shortly after to paint header labels. 400ms is enough for a
        // typical detail fetch; worst case we'll reconcile on next poll.
        setTimeout(() => {
            if (activeConv && activeConv.id === id) {
                renderChatHeaderLabels(activeConv.labels || []);
            }
        }, 400);
    };

    // Also refresh the header-label strip every poll tick so changes
    // applied by other users eventually show up here (we're already
    // refetching the detail in the existing msgPollTimer → the
    // updateSessionUI call) — hook that by reading activeConv.
    setInterval(() => {
        if (activeConv && activeConv.id === activeConvId) {
            renderChatHeaderLabels(activeConv.labels || []);
        }
    }, POLL_INTERVAL);

    // ── 3-dot menu ──
    window.toggleChatMenu = function() {
        document.getElementById('waChatMenu').classList.toggle('open');
    };
    // Close menu on outside click.
    document.addEventListener('click', function(e) {
        const menu = document.getElementById('waChatMenu');
        const btn = document.getElementById('waChatMenuBtn');
        if (menu && menu.classList.contains('open') && !menu.contains(e.target) && e.target !== btn) {
            menu.classList.remove('open');
        }
        const lf = document.getElementById('waLabelFilterMenu');
        const lfBtn = document.getElementById('waLabelFilterBtn');
        if (lf && lf.classList.contains('open') && !lf.contains(e.target) && e.target !== lfBtn) {
            lf.classList.remove('open');
        }
    });

    window.doMarkUnread = function() {
        document.getElementById('waChatMenu').classList.remove('open');
        if (!activeConvId) return;
        apiFetch('/messages/conversations/' + activeConvId + '/mark-unread', { method: 'POST' }).then(d => {
            if (d.success) {
                // Drop out of this conversation so the unread badge re-appears
                // on the left, and reload the list so the styling updates.
                const prevId = activeConvId;
                activeConvId = null;
                activeConv = null;
                const panel = document.getElementById('waChat');
                if (panel) panel.classList.remove('visible');
                const empty = document.getElementById('waEmptyState');
                if (empty) empty.style.display = 'flex';
                loadConversations();
                if (window.toastr && toastr.success) toastr.success('Marked as unread');
            } else if (window.toastr && toastr.error) {
                toastr.error(d.message || 'Failed to mark unread');
            }
        });
    };

    // ── Labels modal ──
    window.openLabelsModal = function() {
        document.getElementById('waChatMenu').classList.remove('open');
        if (!activeConvId) return;
        document.getElementById('waLabelsModal').classList.add('open');
        renderLabelsModal();
    };
    window.closeLabelsModal = function() {
        document.getElementById('waLabelsModal').classList.remove('open');
    };

    function fetchLabels(force) {
        if (allLabels && !force) return Promise.resolve(allLabels);
        return apiFetch('/messages/labels').then(d => {
            if (!d.success) return { labels: [], can_manage: false };
            allLabels = d;
            return d;
        });
    }

    function renderLabelsModal() {
        const togglesEl = document.getElementById('waLabelToggles');
        const manageWrap = document.getElementById('waLabelManageBlock');
        const manageList = document.getElementById('waLabelManageList');
        togglesEl.innerHTML = '<div style="font-size:12px;color:#9ca3af;">Loading…</div>';
        manageWrap.style.display = 'none';

        Promise.all([
            fetchLabels(true),
            apiFetch('/messages/conversations/' + activeConvId), // to get current labels
        ]).then(([lib, det]) => {
            const applied = (det && det.conversation && det.conversation.labels) || [];
            const appliedIds = new Set(applied.map(l => l.id));
            // Keep activeConv in sync so the header strip is correct.
            if (det && det.conversation) {
                activeConv = det.conversation;
                renderChatHeaderLabels(activeConv.labels || []);
            }

            if (!lib.labels.length) {
                togglesEl.innerHTML = '<div style="font-size:12px;color:#9ca3af;">No labels yet. ' +
                    (lib.can_manage ? 'Create one below.' : 'Ask an admin to create labels.') + '</div>';
            } else {
                togglesEl.innerHTML = lib.labels.map(l => {
                    const on = appliedIds.has(l.id);
                    const style = on
                        ? `background:${l.color}26;color:${l.color === '#FFFFFF' || l.color === '#fff' ? '#111827' : l.color};border-color:${l.color};`
                        : '';
                    const mentionPrefix = l.user_id ? '<span class="wa-label-mention-tag">@</span>' : '';
                    return `<span class="wa-label-toggle ${on ? 'applied' : ''}" style="${style}" onclick="toggleLabelOnActive(${l.id}, ${on ? 1 : 0})">
                        <span class="wa-label-color-dot" style="background:${l.color}"></span>
                        ${mentionPrefix}${esc(l.name)}
                    </span>`;
                }).join('');
            }

            if (lib.can_manage) {
                manageWrap.style.display = 'block';
                // Populate the user-mention picker ONCE per modal open.
                // Server gates this endpoint by manage_whatsapp_labels, so
                // if we got can_manage we know it will succeed.
                populateLabelUserPicker();
                if (!lib.labels.length) {
                    manageList.innerHTML = '<div style="font-size:12px;color:#9ca3af;padding:6px 0;">No labels yet.</div>';
                } else {
                    manageList.innerHTML = lib.labels.map(l => `
                        <div class="wa-label-manage-row" data-lid="${l.id}">
                            <span class="wa-label-color-dot" style="background:${l.color}"></span>
                            <span style="font-size:13px;color:#111827;font-weight:500;">${esc(l.name)}</span>
                            ${l.user_id ? '<span class="wa-label-mention-badge" title="Applying this label pings the tagged user">@mention</span>' : ''}
                            ${l.is_system ? '<span style="font-size:10px;color:#9ca3af;margin-left:4px;">system</span>' : ''}
                            <div class="wa-label-manage-actions">
                                <button title="Rename" onclick="renameLabel(${l.id})">✏️</button>
                                <button title="Delete" onclick="deleteLabelClick(${l.id}, ${l.is_system ? 1 : 0})">🗑️</button>
                            </div>
                        </div>
                    `).join('');
                }
            }
        });
    }

    // Phase 2: fill the user-mention picker dropdown with eligible staff.
    // Cached between renders; re-fetched only if cache is empty.
    let _labelUsersCache = null;
    function populateLabelUserPicker() {
        const sel = document.getElementById('waLabelNewUser');
        if (!sel) return;
        if (_labelUsersCache) {
            _renderLabelUserOptions(sel, _labelUsersCache);
            return;
        }
        apiFetch('/messages/label-users').then(d => {
            if (!d || !d.success) return;
            _labelUsersCache = d.users || [];
            _renderLabelUserOptions(sel, _labelUsersCache);
        });
    }
    function _renderLabelUserOptions(sel, users) {
        // Preserve any already-selected value across re-renders.
        const prev = sel.value;
        sel.innerHTML = '<option value="">No user (generic)</option>' +
            users.map(u => `<option value="${u.id}">@ ${esc(u.fullname || ('User #' + u.id))}</option>`).join('');
        if (prev) sel.value = prev;
    }

    window.toggleLabelOnActive = function(labelId, isApplied) {
        if (!activeConvId) return;
        if (isApplied) {
            apiFetch('/messages/conversations/' + activeConvId + '/labels/' + labelId, { method: 'DELETE' }).then(d => {
                if (!d.success) return;
                if (activeConv) activeConv.labels = d.labels;
                renderChatHeaderLabels(d.labels || []);
                renderLabelsModal(); // re-render toggles
                loadConversations(); // refresh inbox row chips
            });
        } else {
            apiFetch('/messages/conversations/' + activeConvId + '/labels', {
                method: 'POST', body: JSON.stringify({ label_id: labelId }),
            }).then(d => {
                if (!d.success) return;
                if (activeConv) activeConv.labels = d.labels;
                renderChatHeaderLabels(d.labels || []);
                renderLabelsModal();
                loadConversations();
            });
        }
    };

    window.removeLabelFromActive = function(labelId) {
        if (!activeConvId) return;
        apiFetch('/messages/conversations/' + activeConvId + '/labels/' + labelId, { method: 'DELETE' }).then(d => {
            if (!d.success) return;
            if (activeConv) activeConv.labels = d.labels;
            renderChatHeaderLabels(d.labels || []);
            loadConversations();
        });
    };

    window.createLabel = function() {
        const name = (document.getElementById('waLabelNewName').value || '').trim();
        const color = document.getElementById('waLabelNewColor').value || '#16A34A';
        const userEl = document.getElementById('waLabelNewUser');
        const userId = userEl && userEl.value ? parseInt(userEl.value, 10) : null;
        if (!name) { alert('Please enter a label name.'); return; }
        const body = { name, color };
        if (userId) body.user_id = userId;
        apiFetch('/messages/labels', {
            method: 'POST', body: JSON.stringify(body),
        }).then(d => {
            if (!d.success) { alert(d.message || 'Failed to create label'); return; }
            document.getElementById('waLabelNewName').value = '';
            if (userEl) userEl.value = '';
            allLabels = null;
            renderLabelsModal();
            loadConversations();
            loadLabelFilterMenu(true);
        });
    };

    window.renameLabel = function(id) {
        const newName = prompt('New label name:');
        if (!newName) return;
        apiFetch('/messages/labels/' + id, {
            method: 'PUT', body: JSON.stringify({ name: newName.trim() }),
        }).then(d => {
            if (!d.success) { alert(d.message || 'Failed to rename'); return; }
            allLabels = null;
            renderLabelsModal();
            loadConversations();
            loadLabelFilterMenu(true);
            // If the active conv had this label, refresh its header strip.
            if (activeConv && (activeConv.labels || []).some(l => l.id === id)) {
                apiFetch('/messages/conversations/' + activeConvId).then(d2 => {
                    if (d2.success) { activeConv = d2.conversation; renderChatHeaderLabels(activeConv.labels || []); }
                });
            }
        });
    };

    window.deleteLabelClick = function(id, isSystem) {
        const warn = isSystem
            ? 'This is a system label — are you SURE you want to delete it from every conversation?'
            : 'Delete this label? It will be removed from every conversation that has it.';
        if (!confirm(warn)) return;
        apiFetch('/messages/labels/' + id, { method: 'DELETE' }).then(d => {
            if (!d.success) { alert(d.message || 'Failed to delete'); return; }
            allLabels = null;
            renderLabelsModal();
            loadConversations();
            loadLabelFilterMenu(true);
            if (labelFilterId === id) { labelFilterId = null; }
            if (activeConvId) {
                apiFetch('/messages/conversations/' + activeConvId).then(d2 => {
                    if (d2.success) { activeConv = d2.conversation; renderChatHeaderLabels(activeConv.labels || []); }
                });
            }
        });
    };

    // ── Label filter on inbox ──
    window.toggleLabelFilter = function() {
        const menu = document.getElementById('waLabelFilterMenu');
        const opening = !menu.classList.contains('open');
        menu.classList.toggle('open');
        if (opening) loadLabelFilterMenu(false);
    };
    function loadLabelFilterMenu(force) {
        fetchLabels(force).then(lib => {
            const menu = document.getElementById('waLabelFilterMenu');
            if (!menu) return;
            const items = [
                `<button class="${labelFilterId===null?'active':''}" onclick="setLabelFilter(null)">All conversations</button>`,
                ...lib.labels.map(l => `
                    <button class="${labelFilterId===l.id?'active':''}" onclick="setLabelFilter(${l.id})">
                        <span class="wa-label-color-dot" style="background:${l.color}"></span>
                        ${esc(l.name)}
                    </button>
                `),
            ];
            menu.innerHTML = items.join('');
        });
    }
    window.setLabelFilter = function(id) {
        labelFilterId = id;
        const btn = document.getElementById('waLabelFilterBtn');
        const menu = document.getElementById('waLabelFilterMenu');
        if (menu) menu.classList.remove('open');
        if (btn) {
            if (id) {
                btn.classList.add('active');
                const lib = allLabels || { labels: [] };
                const l = lib.labels.find(x => x.id === id);
                btn.textContent = '🏷️ ' + (l ? l.name : 'Label');
            } else {
                btn.classList.remove('active');
                btn.textContent = '🏷️ Label';
            }
        }
        loadConversations();
    };

    // Hook loadConversations once more — append label_id / assigned_to_me to
    // the URL if either filter is active. When neither is set we just defer
    // to the original implementation so we don't run two requests in a row.
    //
    // Apr-2026: this branch also has to keep the conversation pagination
    // state (live head + extras + cursor) in sync, otherwise switching to
    // a label/@me filter loses the Load More button entirely. We mirror
    // _origLoadConversations' state-update logic; the only thing different
    // is the URL we hit and the filter signature we compare against.
    const _origLoadConversations = loadConversations;
    loadConversations = function() {
        if (!labelFilterId && !assignedToMe) {
            _origLoadConversations();
            return;
        }
        const searchInput = document.getElementById('waSearch');
        const search = (searchInput && searchInput.value || '').trim();

        // Filter "signature" — any change resets the extras tail.
        const sig = currentFilter + '|' + (labelFilterId || '') + '|' + (assignedToMe ? 'me' : '');
        if (search !== convListLastSearch || sig !== convListLastFilter) {
            convListExtra = [];
            convListMoreCursor = null;
            convListMoreExhausted = false;
            convListLoadingMore = false;
            convListLastSearch = search;
            convListLastFilter = sig;
        }

        let url = '/messages/conversations?filter=' + currentFilter;
        if (search) url += '&search=' + encodeURIComponent(search) + '&search_mode=' + (currentSearchMode || 'customers');
        if (labelFilterId) url += '&label_id=' + labelFilterId;
        if (assignedToMe)  url += '&assigned_to_me=1';
        apiFetch(url).then(d => {
            if (!d.success) return;
            convListLive = d.conversations || [];
            if (!convListExtra.length) {
                convListMoreCursor    = d.next_cursor || null;
                convListMoreExhausted = !d.has_more;
            }
            // Keep Mark-All-Read in sync when the label/@me-aware wrapper
            // is the active loader (label or @me toggled on). Same logic
            // as the unwrapped path: hide unless filter=unread + count>0.
            if (typeof window.updateMarkAllReadVisibility === 'function') {
                window.updateMarkAllReadVisibility(typeof d.total_unread === 'number' ? d.total_unread : 0);
            }
            repaintConvList({ searchMode: currentSearchMode, searchTerm: search });
        });
    };

    // The standalone loadMoreConversations() also needs to pick up the
    // active label/@me filters, since the operator might click Load More
    // while filtered. Wrap the original to splice those params in.
    const _origLoadMoreConversations = loadMoreConversations;
    loadMoreConversations = function() {
        if (!labelFilterId && !assignedToMe) {
            _origLoadMoreConversations();
            return;
        }
        if (convListLoadingMore || convListMoreExhausted || !convListMoreCursor) return;
        convListLoadingMore = true;
        const searchInput = document.getElementById('waSearch');
        const search = (searchInput && searchInput.value || '').trim();
        repaintConvList({ searchMode: currentSearchMode, searchTerm: search });

        let url = '/messages/conversations?filter=' + currentFilter
                + '&before_last_message_at=' + encodeURIComponent(convListMoreCursor);
        if (search) url += '&search=' + encodeURIComponent(search) + '&search_mode=' + (currentSearchMode || 'customers');
        if (labelFilterId) url += '&label_id=' + labelFilterId;
        if (assignedToMe)  url += '&assigned_to_me=1';
        apiFetch(url).then(d => {
            convListLoadingMore = false;
            if (!d || !d.success) {
                repaintConvList({ searchMode: currentSearchMode, searchTerm: search });
                return;
            }
            const incoming = Array.isArray(d.conversations) ? d.conversations : [];
            convListExtra = convListExtra.concat(incoming);
            convListMoreCursor    = d.next_cursor || null;
            convListMoreExhausted = !d.has_more || !convListMoreCursor;
            repaintConvList({ searchMode: currentSearchMode, searchTerm: search });
        }).catch(() => {
            convListLoadingMore = false;
            repaintConvList({ searchMode: currentSearchMode, searchTerm: search });
        });
    };
    window.loadMoreConversations = loadMoreConversations;

    // =========================================================================
    // Marketing-template dedup module (Apr 2026 — see add_marketing_dedup_apr2026.sql).
    //
    // Three responsibilities, all confined to this block to keep the rest of
    // the file untouched:
    //   1. runMarketingDedupCheck(templateName) — pre-flight check called from
    //      sendTemplate() before the actual send. Returns the same payload
    //      shape as the server-side 409 so callers can reuse one renderer.
    //   2. showMarketingDedupConfirm() / showMarketingDedupBlocked() — render
    //      the inline confirm dialog (override allowed) or the hard-block
    //      message (override denied by role).
    //   3. loadRecentMarketingStrip(convId) + renderRecentMarketingStrip() —
    //      fetch and paint the pinned amber strip in the chat header.
    // =========================================================================

    function runMarketingDedupCheck(templateName) {
        if (!activeConv || !templateName) return Promise.resolve(null);
        return apiFetch('/messages/template-recent-send-check', {
            method: 'POST',
            body: JSON.stringify({
                conversation_id: activeConvId || null,
                phone:           activeConv.wa_phone || null,
                template_name:   templateName,
            })
        }).then(d => d || null).catch(() => null);
    }

    function showMarketingDedupConfirm(payload, onConfirm) {
        // Tear down any previous instance so repeated clicks don't stack.
        const existing = document.getElementById('waMktConfirmBack');
        if (existing) existing.remove();

        const wrap = document.createElement('div');
        wrap.id = 'waMktConfirmBack';
        wrap.className = 'wa-mkt-confirm-back';
        wrap.innerHTML = `
            <div class="wa-mkt-confirm" role="dialog" aria-modal="true">
                <h4>📌 Marketing template recently sent</h4>
                <div class="wa-mkt-body">
                    This customer was sent the marketing template
                    <b>${esc(payload.template_display_name || payload.template_name || '')}</b>
                    <span class="wa-mkt-when">${esc(payload.sent_at_human || '')}</span>.
                    <br><br>
                    Sending the same marketing template again may annoy the customer
                    and risk WhatsApp quality penalties. Do you want to send it anyway?
                </div>
                <div class="wa-mkt-actions">
                    <button class="wa-mkt-cancel"      type="button">Cancel</button>
                    <button class="wa-mkt-send-anyway" type="button">Send Anyway</button>
                </div>
            </div>`;
        document.body.appendChild(wrap);

        const close = () => wrap.remove();
        wrap.querySelector('.wa-mkt-cancel').addEventListener('click', close);
        wrap.querySelector('.wa-mkt-send-anyway').addEventListener('click', () => {
            close();
            try { onConfirm && onConfirm(); } catch (e) {}
        });
        wrap.addEventListener('click', (ev) => { if (ev.target === wrap) close(); });
    }

    function showMarketingDedupBlocked(payload) {
        const existing = document.getElementById('waMktConfirmBack');
        if (existing) existing.remove();

        const wrap = document.createElement('div');
        wrap.id = 'waMktConfirmBack';
        wrap.className = 'wa-mkt-confirm-back';
        wrap.innerHTML = `
            <div class="wa-mkt-confirm" role="dialog" aria-modal="true">
                <h4>📌 Marketing template recently sent</h4>
                <div class="wa-mkt-body">
                    ${esc(payload.message || 'This marketing template was already sent recently.')}
                    <br><br>
                    Re-sending requires the <b>WhatsApp: Override Marketing Dedup</b>
                    permission. Please ask Taimur or Management to send it.
                </div>
                <div class="wa-mkt-actions">
                    <button class="wa-mkt-cancel" type="button">OK</button>
                </div>
            </div>`;
        document.body.appendChild(wrap);
        const close = () => wrap.remove();
        wrap.querySelector('.wa-mkt-cancel').addEventListener('click', close);
        wrap.addEventListener('click', (ev) => { if (ev.target === wrap) close(); });
    }

    function renderRecentMarketingStrip(templates) {
        const wrap = document.getElementById('waChatHdrMarketing');
        if (!wrap) return;
        if (!Array.isArray(templates) || templates.length === 0) {
            wrap.innerHTML = '';
            return;
        }
        const items = templates.map(t => `
            <span class="wa-mkt-item">
                <b>${esc(t.template_display_name || t.template_name)}</b>
                <span class="wa-mkt-when">${esc(t.sent_at_human || '')}</span>
            </span>
        `).join('');
        wrap.innerHTML = `
            <span class="wa-mkt-pin">📌</span>
            <div class="wa-mkt-list">
                <span style="font-weight:600;">Marketing template${templates.length > 1 ? 's' : ''} sent recently:</span>
                ${items}
            </div>`;
    }

    // Cache the latest fetch per conversation so polling doesn't churn
    // the DOM when nothing changed.
    let _mktStripFor = null;
    let _mktStripRaw = null;
    function loadRecentMarketingStrip(convId) {
        if (!convId) {
            _mktStripFor = null;
            _mktStripRaw = null;
            renderRecentMarketingStrip([]);
            return;
        }
        apiFetch('/messages/conversations/' + convId + '/recent-marketing-templates')
            .then(d => {
                if (!d || !d.success) return;
                if (!d.feature_enabled) {
                    _mktStripFor = convId;
                    _mktStripRaw = '[]';
                    renderRecentMarketingStrip([]);
                    return;
                }
                const list = Array.isArray(d.templates) ? d.templates : [];
                const json = JSON.stringify(list);
                if (_mktStripFor === convId && _mktStripRaw === json) return; // no change
                _mktStripFor = convId;
                _mktStripRaw = json;
                renderRecentMarketingStrip(list);
            })
            .catch(() => {});
    }

    // Wrap openConv (chains over the labels-strip wrap added above) so
    // opening any conversation refreshes the marketing strip too.
    const _origOpenConvForMkt = window.openConv;
    window.openConv = function(id) {
        renderRecentMarketingStrip([]); // clear while loading
        _origOpenConvForMkt(id);
        loadRecentMarketingStrip(id);
    };

    // Refresh on the same poll cadence as labels — handles the case where
    // a marketing template gets sent from another tab / device.
    setInterval(() => {
        if (activeConvId) loadRecentMarketingStrip(activeConvId);
    }, POLL_INTERVAL);

    // ── Deep-link bootstrap (May-2026) ────────────────────────────
    // Other pages (Qurbani Performance, Qurbani Orders, etc.) can
    // deep-link straight to a customer's conversation by opening
    //   /messages?focus_phone=<phone>
    // We honour the param by:
    //   1. Pre-filling the existing #waSearch input so the user can
    //      see what filter is active (and can clear it manually).
    //   2. Hitting the conversations endpoint with that search; if
    //      EXACTLY one match comes back we auto-open it. Multiple
    //      matches stay as a filtered list for the user to pick.
    // We strip non-digits/+ from the param so any phone format works
    // (e.g. "+92 321 4567890", "03214567890", "923214567890" all hit).
    function _qpFocusPhoneFromUrl() {
        try {
            const sp = new URLSearchParams(window.location.search);
            const raw = sp.get('focus_phone');
            if (!raw) return null;
            const cleaned = String(raw).replace(/[^\d+]/g, '');
            return cleaned || null;
        } catch (e) { return null; }
    }
    const _qpFocusPhone = _qpFocusPhoneFromUrl();
    if (_qpFocusPhone) {
        const searchEl = document.getElementById('waSearch');
        if (searchEl) {
            // Use the last 9 digits — most resilient to PK number
            // format variants (+92/0/0092 prefixes drop off).
            const tail = _qpFocusPhone.replace(/\D/g, '').slice(-9) || _qpFocusPhone;
            searchEl.value = tail;
        }
    }

    // ── Init ──
    loadQurbaniSettings();
    loadConversations();
    convPollTimer = setInterval(() => {
        loadConversations();
    }, POLL_INTERVAL);

    // Phase 2 (May-2026) — auto-open the matching conversation when
    // the user landed here via ?focus_phone=. We do this AFTER the
    // first loadConversations() so the sidebar list is already in
    // sync; the openConv call below will highlight the right row.
    if (_qpFocusPhone) {
        const tail = _qpFocusPhone.replace(/\D/g, '').slice(-9) || _qpFocusPhone;
        apiFetch('/messages/conversations?search=' + encodeURIComponent(tail)).then(r => {
            if (r && r.success && Array.isArray(r.conversations) && r.conversations.length === 1) {
                openConv(r.conversations[0].id);
            }
            // 0 or >1 matches: leave the user looking at the filtered list.
        }).catch(() => { /* deep-link is best-effort, never block normal load */ });
    }
    // Oct-2026 — /messages?open=<conversation id> opens that chat directly (Daily Issues
    // links its "tags not answered" lines here). The chat endpoint does its own access
    // check, so a link to a chat the viewer cannot see simply opens nothing.
    try {
        const openId = new URLSearchParams(window.location.search).get('open');
        if (openId && /^\d+$/.test(openId)) openConv(parseInt(openId, 10));
    } catch (e) { /* best-effort, never block normal load */ }
    // Phase 2 — keep the at-me pip in sync with the server's mention count.
    refreshMentionsPip();
    setInterval(refreshMentionsPip, 30000);
})();
</script>
@endverbatim
@endpush
