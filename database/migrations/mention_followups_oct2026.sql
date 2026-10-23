-- =============================================================================
-- Tag alerts + "still not opened" reminders (Oct-2026) — ROUND 1 (web)
-- Plan: TAG-ALERTS-PLAN-OCT19-2026.md (workspace root)
--
-- Run in MySQL Workbench against production BEFORE uploading the round-1 code.
-- Idempotent — safe to re-run. Nothing here changes existing behaviour on its
-- own: the code that reads these only switches on for the users listed in
-- MENTION_FOLLOWUP_USER_IDS (step 3).
-- =============================================================================

-- 0. VERIFY FIRST — the follow-up list below is keyed by user id. On the
--    replica Taimur is id 68 and owns the "@Taimur" tag. Expect ONE row: 68 / Taimur.
SELECT u.id, u.fullname, l.id AS label_id, l.name AS label_name
FROM t_sys_user u
JOIN t_wa_labels l ON l.user_id = u.id
WHERE l.name = '@Taimur';

-- 1. One row per tag "cycle": someone (waiting_user) is waiting on someone
--    (on_user) to open a chat. Kept forever — it is the Daily Issues record.
--    Deliberately NOT columns on t_wa_conversation_labels: that pivot row is
--    reset on every re-tag and deleted on un-tag.
--    DATETIME (not TIMESTAMP) — the dev replica renders TIMESTAMP shifted.
CREATE TABLE IF NOT EXISTS `t_wa_mention_followup` (
  `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `subject_type`          VARCHAR(20)  NOT NULL DEFAULT 'wa_mention' COMMENT 'wa_mention (v1). Room for team tasks later.',
  `conversation_label_id` BIGINT UNSIGNED NULL     COMMENT 't_wa_conversation_labels.id (the tag)',
  `conversation_id`       BIGINT UNSIGNED NOT NULL,
  `label_id`              BIGINT UNSIGNED NULL,
  `on_user_id`            INT UNSIGNED NOT NULL    COMMENT 'who must respond (tagged user)',
  `waiting_user_id`       INT UNSIGNED NOT NULL    COMMENT 'who tagged and is waiting',
  `tagged_at`             DATETIME     NOT NULL,
  `state`                 VARCHAR(20)  NOT NULL DEFAULT 'watching' COMMENT 'watching|opened|acknowledged|muted|gave_up|untagged',
  `next_at`               DATETIME     NULL        COMMENT 'next reminder due; NULL once nothing more will be sent',
  `remind_count`          TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `last_reminded_at`      DATETIME     NULL,
  `opened_at`             DATETIME     NULL,
  `acknowledged_at`       DATETIME     NULL        COMMENT 'tapped On it',
  `closed_at`             DATETIME     NULL,
  `last_action`           VARCHAR(20)  NULL        COMMENT 'wait10|called|stop|on_it',
  `last_action_at`        DATETIME     NULL,
  `created_at`            DATETIME     NULL,
  `updated_at`            DATETIME     NULL,
  PRIMARY KEY (`id`),
  KEY `idx_mfu_due`  (`state`, `next_at`),
  KEY `idx_mfu_conv` (`conversation_id`, `on_user_id`),
  KEY `idx_mfu_day`  (`on_user_id`, `tagged_at`),
  KEY `idx_mfu_wait` (`waiting_user_id`, `conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Which pushes a phone can draw itself. Sent by the NEW APK on every
--    register-device; an old APK sends nothing and the server overwrites this
--    to NULL — NULL = exactly today's push. (Same safety shape as app_flavor.)
ALTER TABLE `t_wa_device_tokens`
  ADD COLUMN IF NOT EXISTS `capabilities` VARCHAR(100) NULL DEFAULT NULL
  COMMENT 'Comma list from the APK, e.g. team_alerts. NULL = old APK = plain pushes only.'
  AFTER `app_flavor`;

-- 3. Who gets the louder alert + tagger reminders. Comma list of user ids.
--    Add a person later = edit this value (no deploy). Taimur only for now.
INSERT INTO `t_fin_config` (`config_key`, `config_value`, `description`, `is_active`, `created_at`)
SELECT 'MENTION_FOLLOWUP_USER_IDS', '68',
       'Tag alerts: users whose tags get the loud alert + reminders to the tagger (comma list of t_sys_user ids). Taimur only (Oct-2026).',
       1, NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `t_fin_config` WHERE `config_key` = 'MENTION_FOLLOWUP_USER_IDS');

-- 4. Verify
-- DESC t_wa_mention_followup;
-- SHOW COLUMNS FROM t_wa_device_tokens LIKE 'capabilities';
-- SELECT config_key, config_value FROM t_fin_config WHERE config_key = 'MENTION_FOLLOWUP_USER_IDS';
