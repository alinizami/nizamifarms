-- ============================================================================
-- Campaign tracking round — Oct-18-2026
-- Run ONCE on prod BEFORE uploading the web files. Safe to re-run.
--
-- 1. t_crm_campaigns.tracked_categories — lets a campaign count only orders
--    that contain a product CATEGORY (e.g. "Khaas · Frozen") as "Ordered".
--    Until this column exists the web page hides nothing, but saving a
--    category-tracked campaign is refused with a clear message.
--
-- 2. Owner ruling: a button TAP (e.g. the frozen campaign's "Menu") is NOT a
--    reply. The webhook stamped replied_at on taps until now. This re-points
--    replied_at, for rows that HAD a tap in their window, to the first real
--    reply (message / photo / voice / reaction) — or clears it if the tap was
--    all they did. Rows with no tap are NOT touched.
-- ============================================================================

-- 1 ---------------------------------------------------------------------------
ALTER TABLE t_crm_campaigns
    ADD COLUMN IF NOT EXISTS tracked_categories TEXT NULL AFTER tracked_product_ids;

-- 2 ---------------------------------------------------------------------------
-- Preview first (how many rows will change, and how many become "no reply"):
SELECT COUNT(*) AS rows_with_a_tap,
       SUM(first_real IS NULL) AS will_become_no_reply
FROM (
    SELECT cc.id,
           (SELECT MIN(m.created_at)
              FROM t_wa_conversations cv
              JOIN t_wa_messages m ON m.conversation_id = cv.id
             WHERE cv.customer_id = cc.customer_id
               AND m.direction = 'inbound'
               AND m.type NOT IN ('button', 'interactive')
               AND m.created_at > cc.sent_at
               AND m.created_at <= DATE_ADD(cc.sent_at, INTERVAL COALESCE(c.tracking_window_days, 30) DAY)) AS first_real
      FROM t_crm_campaign_customers cc
      JOIN t_crm_campaigns c ON c.id = cc.campaign_id
     WHERE cc.status = 'sent'
       AND cc.sent_at IS NOT NULL
       AND cc.replied_at IS NOT NULL
       AND EXISTS (
            SELECT 1 FROM t_wa_conversations cv2
              JOIN t_wa_messages t ON t.conversation_id = cv2.id
             WHERE cv2.customer_id = cc.customer_id
               AND t.direction = 'inbound'
               AND t.type IN ('button', 'interactive')
               AND t.created_at > cc.sent_at
               AND t.created_at <= DATE_ADD(cc.sent_at, INTERVAL COALESCE(c.tracking_window_days, 30) DAY))
) x;

UPDATE t_crm_campaign_customers cc
  JOIN t_crm_campaigns c ON c.id = cc.campaign_id
   SET cc.replied_at = (
        SELECT MIN(m.created_at)
          FROM t_wa_conversations cv
          JOIN t_wa_messages m ON m.conversation_id = cv.id
         WHERE cv.customer_id = cc.customer_id
           AND m.direction = 'inbound'
           AND m.type NOT IN ('button', 'interactive')
           AND m.created_at > cc.sent_at
           AND m.created_at <= DATE_ADD(cc.sent_at, INTERVAL COALESCE(c.tracking_window_days, 30) DAY))
 WHERE cc.status = 'sent'
   AND cc.sent_at IS NOT NULL
   AND cc.replied_at IS NOT NULL
   AND EXISTS (
        SELECT 1 FROM t_wa_conversations cv2
          JOIN t_wa_messages t ON t.conversation_id = cv2.id
         WHERE cv2.customer_id = cc.customer_id
           AND t.direction = 'inbound'
           AND t.type IN ('button', 'interactive')
           AND t.created_at > cc.sent_at
           AND t.created_at <= DATE_ADD(cc.sent_at, INTERVAL COALESCE(c.tracking_window_days, 30) DAY));

-- Verify: should return 0 (no replied_at left that came only from a tap).
SELECT COUNT(*) AS tap_only_replies_left
  FROM t_crm_campaign_customers cc
  JOIN t_crm_campaigns c ON c.id = cc.campaign_id
 WHERE cc.status = 'sent' AND cc.replied_at IS NOT NULL
   AND NOT EXISTS (
        SELECT 1 FROM t_wa_conversations cv
          JOIN t_wa_messages m ON m.conversation_id = cv.id
         WHERE cv.customer_id = cc.customer_id
           AND m.direction = 'inbound'
           AND m.type NOT IN ('button', 'interactive')
           AND m.created_at > cc.sent_at
           AND m.created_at <= DATE_ADD(cc.sent_at, INTERVAL COALESCE(c.tracking_window_days, 30) DAY))
   AND EXISTS (
        SELECT 1 FROM t_wa_conversations cv2
          JOIN t_wa_messages t ON t.conversation_id = cv2.id
         WHERE cv2.customer_id = cc.customer_id
           AND t.direction = 'inbound'
           AND t.type IN ('button', 'interactive')
           AND t.created_at > cc.sent_at
           AND t.created_at <= DATE_ADD(cc.sent_at, INTERVAL COALESCE(c.tracking_window_days, 30) DAY));
