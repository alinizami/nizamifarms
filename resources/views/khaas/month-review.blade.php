@extends('layouts.app')

@section('title', '📊 Frozen Month Review')

@section('content')
{{--
    Frozen · Month Review (Sep-2026)

    ⭐⭐ Production figures come from FrozenMonthService, the SAME class that
    builds the mobile Inventory Report. If a number here ever disagrees with
    Qasim's phone, the bug is in that service, not in two implementations.

    ⚠ Styling is deliberately self-contained below rather than relying on
    Tailwind utilities — several are purged from the built CSS, and the
    layout only stacks demo1_css.
--}}
<style>
    .mr-wrap { max-width: 1180px; margin: 0 auto; padding: 20px 16px 48px; }
    .mr-head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 18px; }
    .mr-title { font-size: 22px; font-weight: 600; color: #111827; margin: 0; }
    .mr-sub { font-size: 13px; color: #6B7280; margin: 2px 0 0; }
    .mr-controls { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
    .mr-select { padding: 7px 10px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: 13px; background: #fff; min-width: 150px; }
    .mr-toggle { display: inline-flex; border: 1px solid #D1D5DB; border-radius: 8px; overflow: hidden; }
    .mr-toggle a { padding: 7px 12px; font-size: 12px; color: #374151; text-decoration: none; background: #fff; }
    .mr-toggle a.mr-on { background: #B45309; color: #fff; }

    .mr-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin-bottom: 12px; }
    /* ⚠⚠ EVERY class here is mr- prefixed ON PURPOSE — Sep-4 bug, do not undo.
       The global Metronic sheet (assets/css/styles.css line ~303) ships Tailwind
       utilities including .fixed, .sticky, .absolute, .relative and .static.
       The Fixed-cost tile was written with a bare modifier — mr-tile plus the
       word fixed — so it picked up .fixed{position:fixed}. That pinned it to the
       viewport (it scrolled with the page) AND took it out of the grid, so the
       share bar and the paragraph below rendered underneath it. Nothing here
       declared `position`, so the utility had no competitor.
       Never use a bare, generic class name on this page. `position: relative`
       below is the belt-and-braces guard: this <style> comes after the linked
       sheet, so an equal-specificity utility can no longer win. */
    .mr-tile { position: relative; background: #F9FAFB; border-radius: 10px; padding: 14px 16px; }
    .mr-tile .mr-lab { font-size: 12px; color: #6B7280; margin: 0; text-transform: uppercase; letter-spacing: .03em; }
    .mr-tile .mr-big { font-size: 25px; font-weight: 600; margin: 3px 0 0; color: #111827; }
    .mr-tile .mr-tile-sub { font-size: 12px; color: #9CA3AF; margin: 3px 0 0; }
    .mr-tile.mr-t-product { background: #EEF2FF; } .mr-tile.mr-t-product .mr-lab { color: #4338CA; } .mr-tile.mr-t-product .mr-big { color: #312E81; } .mr-tile.mr-t-product .mr-tile-sub { color: #4F46E5; }
    .mr-tile.mr-t-fixed   { background: #ECFDF5; } .mr-tile.mr-t-fixed .mr-lab   { color: #047857; } .mr-tile.mr-t-fixed .mr-big   { color: #064E3B; } .mr-tile.mr-t-fixed .mr-tile-sub   { color: #059669; }
    .mr-tile.mr-t-onetime { background: #F3F4F6; } .mr-tile.mr-t-onetime .mr-lab { color: #4B5563; } .mr-tile.mr-t-onetime .mr-big { color: #1F2937; } .mr-tile.mr-t-onetime .mr-tile-sub { color: #6B7280; }
    .mr-tile.mr-t-unknown { background: #FFFBEB; } .mr-tile.mr-t-unknown .mr-lab { color: #B45309; } .mr-tile.mr-t-unknown .mr-big { color: #78350F; } .mr-tile.mr-t-unknown .mr-tile-sub { color: #D97706; }

    /* ── plan vs direct, and the ingredient panel ──────────────────────────
       ⚠ Every class here is mr- prefixed on purpose. A bare modifier collides
       with a Tailwind utility of the same name in the global sheet — that is
       exactly how the Fixed-cost tile once pinned itself to the viewport. */
    .mr-split { background: #F9FAFB; border: 1px solid #E5E7EB; border-radius: 10px; padding: 12px 14px; margin-bottom: 12px; }
    .mr-split.mr-split-mixed { background: #FFFBEB; border-color: #FDE68A; }
    .mr-split-bar { height: 6px; border-radius: 3px; background: #E5E7EB; overflow: hidden; margin-bottom: 8px; }
    .mr-split-plan { display: block; height: 100%; background: #6366F1; }
    .mr-split-text { font-size: 13px; color: #374151; line-height: 1.5; }
    .mr-split-hint { display: block; color: #6B7280; font-size: 12px; margin-top: 2px; }
    .mr-split-warn { font-size: 12px; color: #92400E; margin-top: 6px; }

    .mr-ing-note { font-size: 12px; color: #92400E; background: #FFFBEB; border: 1px solid #FDE68A;
                   border-radius: 8px; padding: 8px 10px; margin-bottom: 8px; }
    .mr-ing-kind { font-size: 11px; color: #6B7280; text-transform: uppercase; letter-spacing: .03em; }
    .mr-ing-short { color: #B91C1C; font-weight: 600; }
    .mr-ing-untracked { color: #9CA3AF; }
    .mr-ing-legend { font-size: 12px; color: #6B7280; margin-top: 8px; line-height: 1.5; }
    .mr-reapply { float: right; font-size: 12px; background: #fff; border: 1px solid #D1D5DB;
                  border-radius: 6px; padding: 4px 10px; cursor: pointer; color: #374151; }
    .mr-reapply:hover { background: #F3F4F6; }

    .mr-bar { display: flex; height: 10px; border-radius: 6px; overflow: hidden; margin: 14px 0 6px; background: #F3F4F6; }
    .mr-legend { display: flex; flex-wrap: wrap; gap: 14px; font-size: 12px; color: #6B7280; margin-bottom: 18px; }
    .mr-dot { display: inline-block; width: 9px; height: 9px; border-radius: 2px; margin-right: 5px; vertical-align: baseline; }

    .mr-card { border: 1px solid #E5E7EB; border-radius: 12px; background: #fff; margin-bottom: 12px; }
    .mr-card > summary { cursor: pointer; padding: 13px 16px; font-size: 15px; font-weight: 600; color: #111827; list-style: none; display: flex; justify-content: space-between; align-items: center; gap: 10px; }
    .mr-card > summary::-webkit-details-marker { display: none; }
    .mr-card > summary .mr-amt { font-weight: 600; font-variant-numeric: tabular-nums; }
    .mr-body { padding: 0 16px 14px; }
    .mr-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; font-size: 13.5px; padding: 9px 0; border-top: 1px solid #F3F4F6; color: #374151; }
    .mr-row .mr-num { font-variant-numeric: tabular-nums; white-space: nowrap; color: #111827; }
    .mr-row .mr-name { flex: 1; min-width: 0; }
    .mr-note { font-size: 12px; color: #9CA3AF; margin-top: 2px; }
    .mr-chip { display: inline-block; font-size: 11px; padding: 1px 7px; border-radius: 999px; background: #FEF3C7; color: #92400E; margin-left: 6px; }
    .mr-chip.mr-chip-grey { background: #F3F4F6; color: #4B5563; }
    .mr-ct { padding: 4px 7px; border: 1px solid #D1D5DB; border-radius: 6px; font-size: 11.5px; background: #fff; color: #374151; }

    /* Wide tables scroll inside their own box; the page itself never scrolls
       sideways on a laptop or a phone.
       ⚠ `contain: inline-size` is doing the real work and is NOT optional. The
       Metronic shell wraps the content in three nested flex items (main.grow,
       .kt-wrapper, .flex.grow) that all have the default min-width:auto, so a
       wide table's min-content width bubbles all the way up and stretches the
       whole document — measured 714px at a 375px viewport, and it did that
       before this box existed too. Containment stops that contribution at the
       box, so the ancestors shrink and the table scrolls here instead.
       Verified: 375px viewport goes from 714 -> 375 with no change on desktop. */
    .mr-scroll { overflow-x: auto; contain: inline-size; }
    .mr-table { width: 100%; border-collapse: collapse; font-size: 13.5px; min-width: 620px; }
    .mr-table th { text-align: right; font-size: 11px; text-transform: uppercase; letter-spacing: .03em; color: #6B7280; font-weight: 600; padding: 8px 6px; border-bottom: 1px solid #E5E7EB; }
    .mr-table th:first-child, .mr-table td:first-child { text-align: left; }
    .mr-table td { padding: 9px 6px; border-bottom: 1px solid #F3F4F6; text-align: right; font-variant-numeric: tabular-nums; color: #374151; }
    .mr-table tfoot td { font-weight: 600; color: #111827; border-top: 1px solid #E5E7EB; border-bottom: none; }

    .mr-flow { background: #F9FAFB; border-radius: 10px; padding: 12px 16px; font-size: 13px; color: #4B5563; margin-bottom: 12px; }
    .mr-flow b { color: #111827; }
    .mr-info { background: #EFF6FF; border: 1px solid #BFDBFE; color: #1E40AF; border-radius: 10px; padding: 11px 14px; font-size: 12.5px; margin-bottom: 12px; }
    .mr-warn { background: #FFFBEB; border: 1px solid #FDE68A; color: #92400E; border-radius: 10px; padding: 11px 14px; font-size: 12.5px; margin-bottom: 12px; }
    .mr-stale { position: sticky; top: 0; z-index: 30; display: none; background: #B45309; color: #fff; border-radius: 8px; padding: 10px 14px; font-size: 13px; margin-bottom: 12px; align-items: center; justify-content: space-between; gap: 10px; }
    .mr-stale button { background: #fff; color: #B45309; border: none; border-radius: 6px; padding: 5px 12px; font-size: 12.5px; font-weight: 600; cursor: pointer; }
    .mr-meat { background: #FAFAF9; border-radius: 10px; padding: 12px 14px; margin-top: 10px; }
</style>

@php
    $h        = $review['headline'];
    $totals   = $review['production']['totals'];
    $products = collect($review['production']['products'])->sortByDesc('stock_in')->values();
    $meat     = $review['meat'];
    $buckets  = $review['costs']['buckets'] ?? [];
    $made     = (int) $h['made'];

    // [label, colour]. The tiles carry their own mr-t-* class in the markup, so
    // there is deliberately no CSS-class slot here to drift out of sync.
    $bucketMeta = [
        'product'      => ['Product cost', '#6366F1'],
        'fixed'        => ['Fixed cost', '#10B981'],
        'one_time'     => ['One-time cost', '#9CA3AF'],
        'unclassified' => ['Not classified yet', '#F59E0B'],
    ];
    $spend = max((float) ($h['total_spend'] ?? 0), 0.01);
@endphp

<div class="mr-wrap">

    <div class="mr-head">
        <div>
            <h1 class="mr-title">📊 {{ $khaasBU->name }} · Month Review</h1>
            <p class="mr-sub">What the warehouse made in {{ $monthLabel }}, and what the month cost.</p>
        </div>
        <div class="mr-controls">
            <form method="GET" action="{{ route('khaas.month-review') }}" id="mrForm">
                <input type="hidden" name="basis" value="{{ $basis }}">
                <select name="month" class="mr-select" onchange="this.form.submit()">
                    @foreach($availableMonths as $val => $label)
                        <option value="{{ $val }}" {{ $selectedMonth === $val ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </form>
            @if($canSeeCosts)
                <span class="mr-toggle">
                    <a href="{{ route('khaas.month-review', ['month' => $selectedMonth, 'basis' => 'bought']) }}"
                       class="{{ $basis === 'bought' ? 'mr-on' : '' }}">Meat as bought</a>
                    <a href="{{ route('khaas.month-review', ['month' => $selectedMonth, 'basis' => 'used']) }}"
                       class="{{ $basis === 'used' ? 'mr-on' : '' }}">as used</a>
                </span>
            @endif
        </div>
    </div>

    <div class="mr-stale" id="mrStale">
        <span>Classification changed — the numbers below are out of date.</span>
        <button type="button" onclick="window.location.reload()">Refresh</button>
    </div>

    @if($canSeeCosts && empty($review['costs']['map_available']))
        <div class="mr-warn">
            The cost-type table is not installed on this server yet, so every bill shows as
            <b>not classified</b>. Run <b>frozen_month_review_sep2026.sql</b>, then reload.
        </div>
    @endif

    {{-- ── Headline ───────────────────────────────────────────── --}}
    <div class="mr-tiles">
        <div class="mr-tile">
            <p class="mr-lab">Packs made</p>
            <p class="mr-big">{{ number_format($made) }}</p>
            <p class="mr-tile-sub">{{ number_format($h['made_batch']) }} through a batch · {{ number_format($h['made_manual']) }} entered by hand</p>
        </div>
        <div class="mr-tile">
            <p class="mr-lab">Shelf value of what was made</p>
            <p class="mr-big">Rs {{ number_format($h['made_value']) }}</p>
            <p class="mr-tile-sub">Rs {{ number_format($h['avg_price']) }} average per pack</p>
        </div>
        @if($canSeeCosts)
            <div class="mr-tile">
                <p class="mr-lab">Total spend</p>
                <p class="mr-big">Rs {{ number_format($h['total_spend']) }}</p>
                <p class="mr-tile-sub">all cost types together</p>
            </div>
        @endif
    </div>

    @if($canSeeCosts)
        <div class="mr-tiles">
            <div class="mr-tile mr-t-product">
                <p class="mr-lab">Product cost</p>
                <p class="mr-big">Rs {{ number_format($h['product']) }}</p>
                <p class="mr-tile-sub">Rs {{ number_format($h['product_per_pack']) }} per pack made</p>
            </div>
            <div class="mr-tile mr-t-fixed">
                <p class="mr-lab">Fixed cost</p>
                <p class="mr-big">Rs {{ number_format($h['fixed']) }}</p>
                <p class="mr-tile-sub">Rs {{ number_format($h['fixed_per_pack']) }} per pack made</p>
            </div>
            <div class="mr-tile mr-t-onetime">
                <p class="mr-lab">One-time cost</p>
                <p class="mr-big">Rs {{ number_format($h['one_time']) }}</p>
                <p class="mr-tile-sub">kept out of the per-pack figures</p>
            </div>
            @if($h['unclassified'] > 0)
                <div class="mr-tile mr-t-unknown">
                    <p class="mr-lab">Not classified yet</p>
                    <p class="mr-big">Rs {{ number_format($h['unclassified']) }}</p>
                    <p class="mr-tile-sub">set a type below to file it</p>
                </div>
            @endif
        </div>

        <div class="mr-bar">
            @foreach($bucketMeta as $key => $meta)
                @php $val = (float) ($buckets[$key]['total'] ?? 0); @endphp
                @if($val > 0)
                    <div style="width: {{ round($val / $spend * 100, 2) }}%; background: {{ $meta[1] }};"></div>
                @endif
            @endforeach
        </div>
        <div class="mr-legend">
            @foreach($bucketMeta as $key => $meta)
                @php $val = (float) ($buckets[$key]['total'] ?? 0); @endphp
                @if($val > 0)
                    <span><span class="mr-dot" style="background: {{ $meta[1] }};"></span>{{ $meta[0] }} · Rs {{ number_format($val) }} ({{ round($val / $spend * 100) }}%)</span>
                @endif
            @endforeach
        </div>

        @if($made > 0)
            <div class="mr-info">
                Every pack made this month carries <b>Rs {{ number_format($h['product_per_pack']) }}</b> of product cost
                and <b>Rs {{ number_format($h['fixed_per_pack']) }}</b> of fixed cost, against an average shelf price of
                <b>Rs {{ number_format($h['avg_price']) }}</b>.
                @if($h['breakeven_packs'])
                    At this month's prices and product cost, <b>{{ number_format($h['breakeven_packs']) }} packs</b> would
                    cover the fixed base.
                @else
                    Product cost per pack is at or above the average shelf price, so no number of packs covers the fixed
                    base until product cost comes down or prices go up.
                @endif
                One-time cost is excluded from both per-pack figures.
            </div>
        @endif
    @endif

    {{-- ── ⭐⭐ How the packs reached the warehouse ──────────────
         "449 made" is two different stories: packs that went through a production
         plan, and packs typed straight in because the batch was closed at 0 or never
         opened. Both are real production and both are costed the same way — this only
         tells the reader how the figure was assembled, and flags any packs whose
         product has no recipe, because those are missing from the ingredient figures
         below. It is NOT a complaint about direct entry: whatever number Qasim enters
         is what was produced, and that ruling stands. --}}
    @php $split = $review['made_split'] ?? null; @endphp
    @if($split && $split['made'] > 0)
        <div class="mr-split {{ $split['direct'] > 0 ? 'mr-split-mixed' : '' }}">
            <div class="mr-split-bar">
                <span class="mr-split-plan" style="width: {{ $split['plan_share_pct'] }}%"></span>
            </div>
            <div class="mr-split-text">
                <b>How these packs came in:</b> {{ $split['note'] }}
                @if($split['direct'] > 0)
                    <span class="mr-split-hint">Direct entries never had a plan behind them, so the system
                    did not watch the meat come off storage for those packs.</span>
                @endif
            </div>
            @foreach($split['warnings'] as $w)
                <div class="mr-split-warn">⚠ {{ $w }}</div>
            @endforeach
        </div>
    @endif

    {{-- ── What happened to the packs ─────────────────────────── --}}
    <div class="mr-flow">
        Made <b>{{ number_format($made) }}</b>
        · sent to the shop <b>{{ number_format($totals['transferred']) }}</b>
        · sold <b>{{ number_format($totals['sold']) }}</b>
        @if($totals['sold_free'] > 0)(<b>{{ number_format($totals['sold_free']) }}</b> free)@endif
        · counts and corrections <b>{{ $totals['counts'] > 0 ? '+' : '' }}{{ number_format($totals['counts']) }}</b>
        · stock now <b>{{ number_format($totals['warehouse_stock']) }}</b> warehouse
        and <b>{{ number_format($totals['shop_stock']) }}</b> shop.
        <div class="mr-note">
            Made, sent and sold are the month. Stock is today's live count, not the month-end figure — the same
            numbers, from the same code, as the Inventory Report on the phone.
        </div>
    </div>

    {{-- ── 💲 Recipe vs purchases (Sep-27) ─────────────────────────
         "N packs were made — what should they have cost, and what did we buy?" Three
         honest parts: what CAN be compared, purchase money that cannot (bills with no
         lines / lines not linked), and packs with no recipe. History is not restated
         (owner ruling 27-Sep), so this fills in as itemised bills and recipes build up.
         Rupees need view_khaas_costing and are stripped on the server; counts and
         quantities stay for everyone in Frozen mode. --}}
    @php $rvp = $review['recipe_vs_purchases'] ?? null; @endphp
    @if($rvp && $rvp['packs']['made'] > 0)
    <details class="mr-card" open>
        <summary>
            <span>Recipe vs purchases</span>
            <span class="mr-amt">{{ number_format($rvp['packs']['costed']) }} of {{ number_format($rvp['packs']['made']) }} packs costed</span>
        </summary>
        <div class="mr-body">
            <div class="mr-scroll">
            <table class="mr-table">
                <tbody>
                    <tr>
                        <td><b>Packs made</b><div class="mr-ing-kind">stocked into the warehouse, through a plan or entered directly</div></td>
                        <td>{{ number_format($rvp['packs']['made']) }}
                            <div class="mr-ing-kind">{{ number_format($rvp['packs']['plan']) }} plan · {{ number_format($rvp['packs']['direct']) }} direct</div></td>
                    </tr>
                    <tr>
                        <td><b>Packs with a recipe</b><div class="mr-ing-kind">only these can be costed</div></td>
                        <td>{{ number_format($rvp['packs']['costed']) }}
                            @if($rvp['packs']['uncosted'] > 0)<div class="mr-ing-kind">{{ number_format($rvp['packs']['uncosted']) }} had no recipe</div>@endif</td>
                    </tr>
                    @if($canSeeIngredientCost)
                    <tr>
                        <td><b>What the recipes say they cost</b><div class="mr-ing-kind">at this month's average prices</div></td>
                        <td>Rs {{ number_format($rvp['recipe_cost'] ?? 0) }}
                            @if($rvp['packs']['costed'] > 0)<div class="mr-ing-kind">Rs {{ number_format($rvp['recipe_per_pack'] ?? 0, 2) }} a pack</div>@endif</td>
                    </tr>
                    @endif
                    @if($canSeeIngredientCost && $canSeeCosts && $rvp['purchases']['total'] !== null)
                    <tr>
                        <td><b>Product purchases this month</b></td>
                        <td>Rs {{ number_format($rvp['purchases']['total']) }}</td>
                    </tr>
                    <tr>
                        <td style="padding-left:18px;">· meat, from storage orders</td>
                        <td>Rs {{ number_format($rvp['purchases']['meat']) }}</td>
                    </tr>
                    <tr>
                        <td style="padding-left:18px;">· ingredients on itemised bills <div class="mr-ing-kind">these can be compared with the recipes</div></td>
                        <td>Rs {{ number_format($rvp['purchases']['itemised']) }}</td>
                    </tr>
                    @if(($rvp['purchases']['untagged'] ?? 0) > 0)
                    <tr>
                        <td style="padding-left:18px;">· bill lines not linked to an ingredient</td>
                        <td>Rs {{ number_format($rvp['purchases']['untagged']) }}</td>
                    </tr>
                    @endif
                    <tr>
                        <td style="padding-left:18px;">· bills recorded as one total <div class="mr-ing-kind">cannot be matched to a recipe</div></td>
                        <td>Rs {{ number_format($rvp['purchases']['not_itemised']) }}</td>
                    </tr>
                    @endif
                </tbody>
            </table>
            </div>

            @if(!empty($rvp['ingredients']['rows']))
                <div class="mr-ing-note" style="margin-top:10px;"><b>Ingredient by ingredient</b> — bought on itemised bills vs what the recipes say the packs used</div>
                <div class="mr-scroll">
                <table class="mr-table">
                    <thead><tr><th>Ingredient</th><th>Bought</th><th>Recipes used</th><th>Difference</th></tr></thead>
                    <tbody>
                    @foreach($rvp['ingredients']['rows'] as $r)
                        <tr>
                            <td>{{ $r['name'] }}</td>
                            <td>{{ $r['bought_text'] }}</td>
                            <td>{{ $r['used_text'] }}</td>
                            <td class="{{ $r['direction'] === 'used_more' ? 'mr-ing-short' : '' }}">
                                @switch($r['direction'])
                                    @case('not_bought') not bought this month @break
                                    @case('not_used') none used @break
                                    @case('used_more') recipes used more than was bought @break
                                    @default more bought than used
                                @endswitch
                                @if($canSeeIngredientCost && $r['diff_value'] !== null && $r['has_rate'])
                                    <div class="mr-ing-kind">Rs {{ number_format(abs($r['diff_value'])) }}</div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            @endif

            @if(!empty($rvp['meat']))
                <div class="mr-ing-note" style="margin-top:10px;"><b>Meat</b> — what the recipes say vs what storage says left the freezer</div>
                <div class="mr-scroll">
                <table class="mr-table">
                    <thead><tr><th>Meat</th><th>Recipes say</th><th>Storage says</th><th>Difference</th></tr></thead>
                    <tbody>
                    @foreach($rvp['meat'] as $m)
                        <tr>
                            <td>{{ $m['name'] }}</td>
                            <td>{{ $m['direction'] === 'no_recipe' ? '—' : $m['recipe_text'] }}</td>
                            <td>{{ $m['storage_text'] }}</td>
                            <td>
                                @if($m['direction'] === 'no_recipe') no recipe covered these packs
                                @elseif($m['direction'] === 'matches') matches
                                @else {{ $m['diff_text'] }} {{ $m['direction'] === 'more_used' ? 'more used than the recipes say' : 'less used than the recipes say' }}
                                @endif
                                @if($canSeeIngredientCost && $m['diff_value'] !== null && $m['direction'] !== 'matches' && $m['direction'] !== 'no_recipe')
                                    <div class="mr-ing-kind">Rs {{ number_format(abs($m['diff_value'])) }}</div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            @endif

            @if(!empty($rvp['products']))
                <div class="mr-ing-note" style="margin-top:10px;"><b>By product</b> — recipe cost of what was made, against its price</div>
                <div class="mr-scroll">
                <table class="mr-table">
                    <thead><tr><th>Product</th><th>Made (with a recipe)</th>@if($canSeeIngredientCost)<th>Ingredients a pack</th><th>Share of price</th>@endif</tr></thead>
                    <tbody>
                    @foreach($rvp['products'] as $p)
                        <tr>
                            <td>{{ $p['product_name'] }}</td>
                            <td>{{ number_format($p['made']) }}</td>
                            @if($canSeeIngredientCost)
                                <td>Rs {{ number_format($p['cost_per_pack'] ?? 0, 2) }}</td>
                                <td>{{ $p['share_of_price'] !== null ? $p['share_of_price'] . '% of Rs ' . number_format($p['selling_price']) : '—' }}</td>
                            @endif
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
            @endif

            @foreach($rvp['notes'] as $n)
                <div class="mr-ing-note">ℹ {{ $n }}</div>
            @endforeach
        </div>
    </details>
    @endif

    {{-- ── ❄ Ingredients: bought, used, left ──────────────────────
         An ESTIMATE, and it says so on the face. Quantities are open to anyone in
         Frozen mode; the rupees need view_khaas_costing and are stripped on the
         SERVER when it is missing, so a client is never holding money it may not show. --}}
    @php $ing = $review['ingredients'] ?? ['rows' => [], 'totals' => [], 'notes' => []]; @endphp
    @if(!empty($ing['rows']))
    <details class="mr-card">
        <summary>
            <span>Ingredients · bought, used and left</span>
            @if($canSeeIngredientCost)
                <span class="mr-amt">Rs {{ number_format($ing['totals']['used_value'] ?? 0) }} used</span>
            @else
                <span class="mr-amt">{{ count($ing['rows']) }} tracked</span>
            @endif
        </summary>
        <div class="mr-body">
            @if($canManageRecipes)
                <button type="button" class="mr-reapply" id="mrReapplyBtn">Re-apply recipes for this month</button>
            @endif

            @foreach($ing['notes'] as $n)
                <div class="mr-ing-note">⚠ {{ $n }}</div>
            @endforeach

            <div class="mr-scroll">
            <table class="mr-table">
                <thead>
                    <tr>
                        <th>Ingredient</th>
                        <th>Start</th>
                        <th>Bought</th>
                        <th>Used</th>
                        <th>Adjusted</th>
                        <th>Left</th>
                        @if($canSeeIngredientCost)
                            <th>Rate</th>
                            <th>Cost of what was used</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                @foreach($ing['rows'] as $row)
                    <tr>
                        <td>
                            {{ $row['name'] }}
                            <div class="mr-ing-kind">{{ $row['kind_label'] }}@if($row['is_meat']) · from storage @endif</div>
                        </td>
                        {{-- ❄🧂 Sep-30: start + bought − used ± adjusted = left (a full month) --}}
                        <td>
                            @if(($row['start_text'] ?? null) !== null)
                                {{ $row['start_text'] }}
                            @elseif(!empty($row['started_text']))
                                <span class="mr-ing-kind">{{ $row['started_text'] }}</span>
                            @else
                                <span class="mr-ing-untracked">—</span>
                            @endif
                        </td>
                        <td>{{ $row['bought_text'] }}</td>
                        <td class="{{ $row['short'] ? 'mr-ing-short' : '' }}">
                            {{ $row['used_text'] }}
                            @if($row['short'])<br><span class="mr-ing-kind">more than there was</span>@endif
                        </td>
                        <td>
                            @if(!empty($row['adjusted_text']))
                                <span class="{{ ($row['adjusted_qty'] ?? 0) < 0 ? 'mr-ing-short' : '' }}">{{ $row['adjusted_text'] }}</span>
                                @if($canSeeIngredientCost && ($row['adjusted_value'] ?? null) !== null && abs($row['adjusted_value']) >= 1)
                                    <div class="mr-ing-kind">Rs {{ number_format(abs($row['adjusted_value'])) }}</div>
                                @endif
                            @else
                                <span class="mr-ing-untracked">—</span>
                            @endif
                        </td>
                        <td>
                            @if($row['tracked'])
                                {{ $row['remaining_text'] }}
                            @else
                                <span class="mr-ing-untracked">not tracked</span>
                            @endif
                            @if(!empty($row['last_check']))
                                <div class="mr-ing-kind">
                                    weighed {{ $row['last_check']['qty_text'] }} on {{ $row['last_check']['date_text'] }}@if($row['last_check']['gap_text']) · {{ $row['last_check']['gap_text'] }}@endif
                                    @if($canSeeIngredientCost && ($row['last_check_value'] ?? null) !== null && abs($row['last_check_value']) >= 1)
                                        (Rs {{ number_format(abs($row['last_check_value'])) }})
                                    @endif
                                </div>
                            @endif
                        </td>
                        @if($canSeeIngredientCost)
                            <td>
                                {{ $row['rate_text'] ?? '—' }}
                                @if(($row['rate_source'] ?? '') === 'earlier')
                                    <div class="mr-ing-kind">last known price</div>
                                @elseif(($row['rate_source'] ?? '') === 'storage')
                                    <div class="mr-ing-kind">meat order price</div>
                                @endif
                            </td>
                            <td>Rs {{ number_format($row['used_value'] ?? 0) }}</td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>

            <div class="mr-ing-legend">
                <b>This is an estimate, not a stock audit.</b>
                Used is what the recipes say the month's packs should have taken, so it follows the recipe
                rather than the shelf.
                @if($canSeeIngredientCost)
                    Cost is that quantity at the month's average purchase price, or the last price known
                    before it.
                @endif
                Meat is different: it is bought and consumed through the storage ledger, so its figures are
                the real ones. "Not tracked" means no opening stock was ever entered for that ingredient,
                which is honest rather than a number that looks exact and is not.
                <br><b>Start + Bought − Used ± Adjusted = Left</b> for a full month. Bought is by-weight Frozen bills only.
                Adjusted is what weigh-ins changed (a minus is stock that was not there); a "weighed" line under
                Left was recorded but not applied.
            </div>
        </div>
    </details>
    @endif

    {{-- ── ❄ Cost per pack, by product ────────────────────────── --}}
    @php $pc = $review['product_costs'] ?? ['rows' => [], 'totals' => []]; @endphp
    @if(!empty($pc['rows']))
    <details class="mr-card">
        <summary>
            <span>Ingredient cost · by product</span>
            @if($canSeeIngredientCost)
                <span class="mr-amt">Rs {{ number_format($pc['totals']['cost_per_pack'] ?? 0, 2) }} a pack</span>
            @else
                <span class="mr-amt">{{ number_format($pc['totals']['made'] ?? 0) }} packs</span>
            @endif
        </summary>
        <div class="mr-body">
            <div class="mr-scroll">
            <table class="mr-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Made</th>
                        <th>Through a plan</th>
                        <th>Entered directly</th>
                        @if($canSeeIngredientCost)
                            <th>Ingredients</th>
                            <th>Per pack</th>
                            <th>Overhead per pack</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                @foreach($pc['rows'] as $row)
                    <tr>
                        <td>{{ $row['product_name'] }}</td>
                        <td>{{ number_format($row['made']) }}</td>
                        <td>{{ number_format($row['made_plan']) }}</td>
                        <td>{{ number_format($row['made_direct']) }}</td>
                        @if($canSeeIngredientCost)
                            <td>Rs {{ number_format($row['cost'] ?? 0) }}</td>
                            <td><b>Rs {{ number_format($row['cost_per_pack'] ?? 0, 2) }}</b></td>
                            <td>Rs {{ number_format($h['fixed_per_pack'] ?? 0, 2) }}</td>
                        @endif
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
            @if($canSeeIngredientCost)
                <div class="mr-ing-legend">
                    Overhead per pack is this month's fixed cost — gas, electricity, salaries — spread evenly
                    over every pack made. Gas burned per samosa is not measured and this does not pretend to;
                    it is the fixed base carried by the month's production.
                    <br><br>
                    <b>The meat in these figures is the recipe standard, not the storage ledger.</b>
                    Storage records that the meat left the freezer, not which pack it became, so a per-product
                    cost can only use what the recipe says. The Ingredients panel above reports meat from the
                    ledger, which is exact for the month — so the two will differ whenever production ran off
                    recipe, and that difference is worth looking at rather than explaining away.
                </div>
            @endif
        </div>
    </details>
    @endif

    {{-- ── Made by product ────────────────────────────────────── --}}
    <details class="mr-card" open>
        <summary>
            <span>{{ number_format($made) }} packs made · by product</span>
            <span class="mr-amt">Rs {{ number_format($h['made_value']) }}</span>
        </summary>
        <div class="mr-body">
            <div class="mr-scroll">
            <table class="mr-table">
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Price</th>
                        <th>Made</th>
                        <th>Shelf value</th>
                        <th>To shop</th>
                        <th>Sold</th>
                        <th>Stock now</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($products as $p)
                    <tr>
                        <td>
                            {{ $p['product_name'] }}
                            @if($p['stock_in_manual'] > 0 && $p['stock_in_batch'] > 0)
                                <span class="mr-chip mr-chip-grey">{{ $p['stock_in_manual'] }} by hand</span>
                            @elseif($p['stock_in_manual'] > 0 && $p['stock_in'] > 0)
                                <span class="mr-chip mr-chip-grey">all by hand</span>
                            @endif
                            @if($p['adjustments_counts'] != 0)
                                <span class="mr-chip">{{ $p['adjustments_counts'] > 0 ? '+' : '' }}{{ $p['adjustments_counts'] }} counted</span>
                            @endif
                        </td>
                        <td>{{ number_format($p['selling_price']) }}</td>
                        <td>{{ number_format($p['stock_in']) }}</td>
                        <td>{{ number_format($p['made_value']) }}</td>
                        <td>{{ number_format($p['transferred_to_shop']) }}</td>
                        <td>
                            {{ number_format($p['sold']) }}
                            @if($p['sold_free'] > 0)<span class="mr-chip">{{ number_format($p['sold_free']) }} free</span>@endif
                        </td>
                        <td>{{ number_format($p['current_warehouse_qty'] + $p['current_shop_qty']) }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                    <tr>
                        <td>Total</td>
                        <td></td>
                        <td>{{ number_format($made) }}</td>
                        <td>{{ number_format($h['made_value']) }}</td>
                        <td>{{ number_format($totals['transferred']) }}</td>
                        <td>{{ number_format($totals['sold']) }}</td>
                        <td>{{ number_format($totals['warehouse_stock'] + $totals['shop_stock']) }}</td>
                    </tr>
                </tfoot>
            </table>
            </div>
        </div>
    </details>

    {{-- ── Cost sections ──────────────────────────────────────── --}}
    @if($canSeeCosts)
        @foreach($bucketMeta as $key => $meta)
            @php $bucket = $buckets[$key] ?? ['total' => 0, 'rows' => []]; @endphp
            @if(!empty($bucket['rows']))
                <details class="mr-card" open>
                    <summary>
                        <span style="color: {{ $meta[1] }};">{{ $meta[0] }}</span>
                        <span class="mr-amt">Rs {{ number_format($bucket['total']) }}</span>
                    </summary>
                    <div class="mr-body">
                        @if($key === 'unclassified')
                            <div class="mr-warn" style="margin: 8px 0 0;">
                                These bills are counted in the total but belong to no type yet. Give each one a type and
                                it files itself, for this month and every past month.
                            </div>
                        @endif

                        @foreach($bucket['rows'] as $row)
                            <div class="mr-row">
                                <span class="mr-name">
                                    {{ $row['label'] }}
                                    @if(!empty($row['bills']))
                                        <span class="mr-chip mr-chip-grey">{{ $row['bills'] }} {{ $row['bills'] == 1 ? 'entry' : 'entries' }}</span>
                                    @endif
                                    @if(!empty($row['detail']))
                                        <div class="mr-note">{{ implode(' · ', array_slice($row['detail'], 0, 4)) }}</div>
                                    @endif
                                </span>
                                <span class="mr-num">Rs {{ number_format($row['amount']) }}</span>
                                <select class="mr-ct" onchange="mrSetCostType(this)"
                                        data-kind="{{ $row['source_kind'] }}"
                                        data-key="{{ $row['source_key'] }}">
                                    @foreach($costTypes as $t)
                                        <option value="{{ $t }}" {{ $row['cost_type'] === $t ? 'selected' : '' }}>
                                            {{ $t === 'product' ? 'Product' : ($t === 'fixed' ? 'Fixed' : 'One-time') }}
                                        </option>
                                    @endforeach
                                    @if($row['cost_type'] === 'unclassified')
                                        <option value="unclassified" selected disabled>Choose a type</option>
                                    @endif
                                </select>
                            </div>
                        @endforeach

                        {{-- Meat bought vs used sits with the product cost it explains. --}}
                        @if($key === 'product' && !empty($meat['rows']))
                            <div class="mr-meat">
                                <div style="font-weight: 600; font-size: 13.5px; color: #111827; margin-bottom: 6px;">
                                    Raw meat this month
                                </div>
                                <div style="font-size: 13px; color: #4B5563;">
                                    Bought <b>{{ number_format($meat['bought_kg'], 2) }} kg</b>
                                    · used in production <b>{{ number_format($meat['used_kg'], 2) }} kg</b>
                                    (worth Rs {{ number_format($meat['used_value']) }})
                                    · in storage now <b>{{ number_format($meat['on_hand_kg'], 2) }} kg</b>.
                                </div>
                                <div class="mr-note">
                                    @if($basis === 'used')
                                        Showing meat <b>as used</b>: the meat line above is what production consumed,
                                        {{ $h['meat_adjustment'] >= 0 ? 'Rs ' . number_format(abs($h['meat_adjustment'])) . ' more than' : 'Rs ' . number_format(abs($h['meat_adjustment'])) . ' less than' }}
                                        what was bought. Every other row is unchanged.
                                    @else
                                        Showing meat <b>as bought</b>, which matches the vendor figure on the HQ screen.
                                        Buying more than you use is stock, not cost — switch to "as used" to value the
                                        month by what production actually consumed.
                                    @endif
                                </div>
                                <div class="mr-scroll" style="margin-top: 10px;">
                                <table class="mr-table">
                                    <thead>
                                        <tr><th>Raw material</th><th>Bought kg</th><th>Used kg</th><th>Rs / kg</th><th>Used value</th></tr>
                                    </thead>
                                    <tbody>
                                    @foreach($meat['rows'] as $m)
                                        <tr>
                                            <td>{{ $m['name'] }}</td>
                                            <td>{{ number_format($m['received_kg'], 2) }}</td>
                                            <td>{{ number_format($m['used_kg'], 2) }}</td>
                                            <td>{{ number_format($m['rate_per_kg']) }}</td>
                                            <td>{{ number_format($m['used_value']) }}</td>
                                        </tr>
                                    @endforeach
                                    </tbody>
                                </table>
                                </div>
                            </div>
                        @endif
                    </div>
                </details>
            @endif
        @endforeach
    @else
        <div class="mr-info">
            You are seeing the production half of this screen. The cost breakdown includes staff salaries, so it is
            limited to the people who hold the Frozen month-review permission.
        </div>
    @endif

</div>

{{-- Only shipped to someone who may re-classify. The endpoint re-checks the
     permission on its own, so this is presentation, not the actual gate. --}}
@if($canSeeCosts)
<script>
(function () {
    'use strict';

    var CSRF = @json(csrf_token());
    var URL_SET = @json(route('khaas.month-review.cost-type'));

    window.mrSetCostType = function (sel) {
        var kind = sel.getAttribute('data-kind');
        var key = sel.getAttribute('data-key');
        var type = sel.value;
        if (!kind || !key || !type || type === 'unclassified') { return; }

        sel.disabled = true;
        var body = new URLSearchParams();
        body.append('source_kind', kind);
        body.append('source_key', key);
        body.append('cost_type', type);

        fetch(URL_SET, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': CSRF,
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: body.toString()
        })
        .then(function (r) { return r.json().catch(function () { return {success: false, message: 'Unexpected reply'}; }); })
        .then(function (d) {
            sel.disabled = false;
            if (d && d.success) {
                var bar = document.getElementById('mrStale');
                if (bar) { bar.style.display = 'flex'; }
            } else {
                alert((d && d.message) ? d.message : 'Could not save that change.');
            }
        })
        .catch(function () {
            sel.disabled = false;
            alert('Could not save that change. Check your connection and try again.');
        });
    };
})();
</script>
@endif

@if($canManageRecipes)
<script>
// ── Re-apply recipes for this month ────────────────────────────────────────
// The one deliberate way consumption history is rewritten. It exists because a
// product's first recipe is usually typed AFTER some packs were already made. It
// says what it will do before it does it, and what it did afterwards.
(function () {
    var btn = document.getElementById('mrReapplyBtn');
    if (!btn) { return; }

    btn.onclick = function () {
        var month = @json($selectedMonth);
        var ok = confirm(
            'Re-apply recipes for ' + month + '?\n\n' +
            'Every pack that entered the warehouse this month is re-read against the recipe that ' +
            'was in force on its own date. Packs whose product still has no recipe are left alone.\n\n' +
            'Nothing about money changes.'
        );
        if (!ok) { return; }

        btn.disabled = true;
        btn.textContent = 'Working…';

        fetch(@json(route('khaas.consumption.reapply')), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json'
            },
            body: JSON.stringify({month: month})
        })
        .then(function (r) { return r.json().catch(function () { return {success: false, message: 'Unexpected reply'}; }); })
        .then(function (d) {
            btn.disabled = false;
            btn.textContent = 'Re-apply recipes for this month';
            alert((d && d.message) ? d.message : 'Done.');
            if (d && d.success) { window.location.reload(); }
        })
        .catch(function () {
            btn.disabled = false;
            btn.textContent = 'Re-apply recipes for this month';
            alert('Could not reach the server. Nothing was changed.');
        });
    };
})();
</script>
@endif
@endsection
