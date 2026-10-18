# Deploy — 📣 Campaign tracking: categories, clickable results, timeline (18-Oct-2026)

**Why.** The frozen campaign could only count "any order" (a mutton order counted as a frozen win), or
specific products picked one by one from a hard-to-use list. None of the counts could be clicked, so you
couldn't see who replied, who ordered, or whether the team answered them. "Replied" also counted "Menu"
button taps.

**What changes (owner rulings 18-Oct).**
- **A. Results by category on every campaign**, including campaigns already running. The Results window
  has a "By category" table: of everyone who ordered after the message, how many bought Frozen,
  Mutton, Chicken and so on, with the number who bought frozen for the first time. Frozen = Khaas +
  Table Talk, the same grouping as HQ, which now uses the same single map.
- **New tracking type "Only orders with a category (e.g. Frozen)"**, available when creating a campaign
  AND through **"Change tracking"** on an existing one (so `frozen food 2026` can be switched to Frozen).
  Changing it only affects counting: nobody is messaged, added or removed.
- **B. Clickable tiles**: Delivered · Read · Replied · Tapped a button · Conversation · Waiting on us ·
  Ordered (+ orders & PKR) · Undelivered. Each tile opens the list of people behind its number.
- **C. Timeline per customer** (click a name or "Timeline"): sent/delivered/read times, first reply,
  button tap, when the team answered and how long that took, every order in the window (categories,
  whether it counts), every WhatsApp message with the staff name, and an "Open chat" link.
- **D. Replies split**: a button tap is shown on its own and is **not** a reply. A reaction **is** a reply.
  Conversation = the customer wrote and someone from the team replied after that. Waiting on us = the
  customer wrote and no team message followed.
- **E. Fixes**: the Results window no longer says "any *delivered* order counts" (the rule is any
  *placed* order). The product picker shows active products only, with their category; it searches by
  type too (type "Khaas"), has a "Select all shown" button, and its rows are no longer squashed into
  uppercase blocks.

**1 SQL → 8 web files → /xclean. No APK** (mobile reads the same service; its "Replied" figure gets the
tap fix automatically).

## Step 1 — SQL (phpMyAdmin), BEFORE the upload

`database/migrations/campaign_category_tracking_oct2026.sql`, safe to re-run:
1. `ALTER TABLE t_crm_campaigns ADD COLUMN IF NOT EXISTS tracked_categories TEXT NULL`.
2. A preview SELECT, then an UPDATE that corrects `replied_at` **only for rows that had a button tap**
   in their window: it is moved to the first real reply, or cleared if the tap was all they did.
3. A verify SELECT, which must return `tap_only_replies_left = 0`.

Local replica: 12 rows had a tap; 1 became "no reply". Each statement took under 100 ms.

If the web files are uploaded first, nothing breaks: category tracking is refused with "needs the
Oct-2026 database update".

## Step 2 — Web files (WinSCP)

| # | File | What |
|---|------|------|
| W1 | `app/Services/CRM/ProductCategoryRollup.php` | **NEW** — the one product_type → category map (moved out of HQ) |
| W2 | `app/Services/HQ/ExecutiveClosingService.php` | uses W1 instead of its private copy (HQ numbers unchanged; checked Sep-2026 products tab) |
| W3 | `app/Services/Campaigns/CampaignStatsService.php` | one `conversionFilter()` for every conversion query (+category); `categoryBreakdown()`, `engagementMap()`, `convertedCustomerIds()`, `customerTimeline()`; tile filters on the list |
| W4 | `app/Http/Controllers/Web/CampaignWebController.php` | `engagement`, `timeline`, `updateTracking`, `getCategories`; create accepts category; product picker active-only + category |
| W5 | `app/Services/WhatsAppService.php` | a button tap no longer stamps `replied_at` (8 lines, campaign reply stamp only) |
| W6 | `resources/views/pages/campaigns/index.blade.php` | tiles, list chip, row badges, timeline + change-tracking dialogs, by-category table, picker |
| W7 | `routes/web.php` | 4 routes: `/campaigns/categories`, `/{id}/engagement`, `/{id}/customers/{cid}/timeline`, `POST /{id}/tracking` |
| W8 | `database/migrations/campaign_category_tracking_oct2026.sql` | (for the record; it's run in step 1) |

## Step 3 — `/api/public/xclean` (routes + view changed)

## Step 4 — After upload (2 minutes)
1. Open **frozen food 2026**. Tiles show numbers within a second or two (Ordered etc. load just after the list).
2. Click **Change tracking** → "Only orders with a category" → tick **Khaas · Frozen** → Save.
   "Ordered" now counts frozen orders only. Results → By category still shows everything else.
3. Click **Waiting on us**: these are customers who wrote and haven't had a team reply yet.
4. Open any name → Timeline.

## Things to know
- Replies, conversations and orders count anything in the window (30 days by default), including a
  message about an older invoice. The timeline shows the actual messages so you can judge.
- "Team answered" = any message a staff member sent (chat or a template like an invoice), except this
  campaign's own template. Automatic messages (out-of-office, order-received) don't count.
- "First time buying frozen" is exact. It isn't shown for meat: almost all 2025 order lines point at the
  old catalog, so "first time buying mutton" would be wrong.
- Frozen includes every product typed Khaas, including e.g. "KS - Desi Ghee" and "KW - Potato".
- The Ordered/Conversation tile numbers are cached for 60 seconds.

## Verified (local replica, `app launch wave 1`, 507 sent)
- Tiles: Delivered 448 · Read 330 · Replied 228 · Tapped 11 · Conversation 156 · Waiting 67 · Ordered 240.
  Each tile's list total = the tile number, and every row in it qualifies (checked all 7).
- Change tracking → Khaas · Frozen: Ordered 240 → 32 = the 32 frozen buyers in By category (46 orders);
  7 bought frozen for the first time. Restored afterwards.
- Create with category saves `["Khaas · Frozen"]`; an empty category or product list is refused (422) on create and on
  change. Product search "Khaas" → Select all shown → 11 picked.
- Timeline renders receipts, a counted order with categories, staff names and templates. No JS errors.
- HQ products tab still groups exactly as before.
