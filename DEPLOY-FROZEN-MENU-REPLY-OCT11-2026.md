# Deploy — Frozen campaign: "Menu" tap sends the menu image (Oct-11-2026)

Customer taps **Menu** on the frozen campaign template → the system replies at once with the
frozen-range menu picture. Free-form image (the tap opens the 24-h window), so no second template
or approval is needed.

## Before you submit the template in WhatsApp Manager
- **Language = "English"** (code `en`). NOT "English (US)/(UK)". Campaigns always send `en`, and a
  template's language can't be changed after it's created.
- **Category = Marketing.**
- Quick-reply label exactly **`Menu`** (that's what the code matches; "View menu" would NOT fire).
- Optional but recommended: put UTM tags on the Visit website URL so Shopify shows visits/sales from
  this campaign, e.g. `https://nizamifarms.com/...?utm_source=whatsapp&utm_medium=campaign&utm_campaign=frozen_launch`.
  Do it now: changing an approved template sends it back for review.

## After Meta approves — 1 web file + 2 images + 1 SQL (no /xclean needed)
1. Upload **`app/Services/WhatsAppService.php`** → `public_html/app/app/Services/WhatsAppService.php`.
   It's the only file in the uncommitted diff, so it carries nothing else.
2. Upload the **menu picture**:
   local `storage/app/public/whatsapp-media/quick-reply/frozen_menu.jpg`
   → `public_html/app/storage/app/public/whatsapp-media/quick-reply/frozen_menu.jpg` (make the folders).
   This is not in git (`storage/app` is gitignored), so it has to be uploaded by hand.
3. Upload the **template header photo** (the boxes photo you gave Meta) as JPG →
   `public_html/app/storage/app/wa-template-headers/frozen_1.jpg`.
4. Open `database/migrations/add_frozen_range_template_oct2026.sql` (already set to `frozen_1`) and run it on prod.
   You can run it again safely.

## Test before the campaign
- From a chat, send the template to your own number (or run a 1-person campaign), then tap **Menu**.
  The menu image should arrive within a few seconds and show in the chat under the tap.
- Prod log: `quick-reply image sent` = it worked · `quick-reply image send failed` /
  `quick-reply image missing on disk` = it didn't (step 2).

## Behaviour
- An answered tap raises **no unread badge, no push, no out-of-office reply** (same as the
  "Get bank details" answer). It stays visible in the chat, and the campaign still counts it as **Replied**.
- If the image can't be sent, the tap behaves like any normal customer message (badge + push), so
  someone sees that a customer asked for the menu.
- Only button taps trigger it. A customer **typing** "menu" gets no automatic reply.
- To change the picture later, replace the file (step 2). The next tap sends the new one.

Proof: harness `test_quick_reply_menu.php` (Meta stubbed, DB rolled back) 43/43 · SQL dry-run
twice in a rolled-back transaction (1 row, re-runnable). Not tested against live Meta.
