-- =============================================================================
-- Register the FROZEN RANGE campaign template in NF — Oct 2026
--
-- Run AFTER Meta approves the template. Run on PROD (and local if you test
-- there). Idempotent: ON DUPLICATE KEY on the unique (name, language). If you
-- already added the template from WhatsApp → Manage Templates, this only fills
-- in what that form cannot set (the header image path).
--
-- ⚠ STEP 0 — set the name on the next line to the template's EXACT name as
--   shown in WhatsApp Manager (lowercase, underscores). A wrong name = every
--   campaign send fails with 132001 "template does not exist".
SET @tpl_name := 'frozen_1';
--
-- WHY THIS IS NEEDED (not just nice to have):
--   The template has an IMAGE HEADER. The photo uploaded in WhatsApp Manager is
--   only Meta's review SAMPLE — every send must attach the image itself, or Meta
--   rejects it with 132012 and nothing is delivered (proven live Jul-2026 on
--   app_launch_template). header_media_path tells the campaign sender which file
--   to attach; WhatsAppService::headerParamsForTemplate() uploads it to Meta once
--   and reuses it for the whole campaign. The Manage Templates form has no field
--   for it, hence this SQL.
--
-- ⚠ The file must exist on the server BEFORE the first send:
--     public_html/app/storage/app/wa-template-headers/<name>.jpg
--   (same <name> as @tpl_name, JPG). Use the same photo you uploaded to Meta.
--   If it is missing, the campaign stops BEFORE sending anyone ("media_missing")
--   and everyone stays Pending — safe, but nothing goes out.
--
-- Language: campaigns always send language code `en`, so the template must be
-- created in Meta as plain "English" — NOT "English (US)" / "English (UK)".
-- =============================================================================

INSERT INTO `t_wa_templates`
    (`name`, `display_name`, `category`, `language`,
     `header_text`, `header_media_path`, `body_text`, `footer_text`,
     `variable_count`, `has_buttons`, `button_labels`,
     `show_in`, `is_default`, `is_active`, `is_qurbani_only`, `is_regular_only`,
     `status`, `created_at`, `updated_at`)
VALUES
    (@tpl_name,
     'Frozen range launch',
     'marketing',
     'en',
     NULL,                                        -- header is an IMAGE, not text
     CONCAT('wa-template-headers/', @tpl_name, '.jpg'),
     -- Preview only (Meta holds the real text). Emojis left out on purpose.
     'For years, you''ve trusted us to serve you the BEST quality meat.\nNow, we bring you our range of quality *frozen food!*\nThe tastiest samosas, spring rolls, and kababs, made the Nizami Farms way',
     NULL,
     -- The body has NO {{1}} placeholders. MUST match Meta: the sender sizes the
     -- body parameters from this number and a mismatch is rejected (132012).
     0,
     1,
     -- Labels are display only — no send code reads them. "Menu" is a quick
     -- reply (no send parameter needed: its payload defaults to the label, which
     -- is what WhatsAppService::QUICK_REPLY_IMAGES matches on). "Visit website"
     -- is a static URL button (no send parameter either).
     '["Menu","Visit website"]',
     -- Campaigns picker shows every approved template regardless of show_in;
     -- this also lets staff send it by hand from a chat.
     'messages,customers',
     0,                                           -- never a default
     1,                                           -- active
     0,
     0,
     'approved',
     NOW(), NOW())
ON DUPLICATE KEY UPDATE
    display_name      = VALUES(display_name),
    category          = VALUES(category),
    header_media_path = VALUES(header_media_path),
    body_text         = VALUES(body_text),
    variable_count    = VALUES(variable_count),
    has_buttons       = VALUES(has_buttons),
    button_labels     = VALUES(button_labels),
    status            = VALUES(status),
    updated_at        = NOW();


-- =========================================================================
-- VERIFICATION
-- =========================================================================
-- SELECT name, language, category, variable_count, header_media_path, status
-- FROM t_wa_templates WHERE name = @tpl_name;
--
-- Expect ONE row: language=en, category=marketing, variable_count=0,
-- header_media_path=wa-template-headers/<name>.jpg, status=approved.
