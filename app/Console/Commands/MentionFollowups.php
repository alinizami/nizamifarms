<?php

namespace App\Console\Commands;

use App\Services\WhatsApp\MentionFollowupService;
use Illuminate\Console\Command;

/**
 * ⏰ `whatsapp:mention-followups` — the clock for tag alerts (Oct-2026).
 *
 * Sends the "⏳ Taimur hasn't opened your tag" reminders to whoever tagged him (10 min, then
 * every 15), re-buzzes Taimur at the same moments, and gives up at 2 h. All the rules live
 * in MentionFollowupService; this only ticks it.
 *
 * Prod runs no `schedule:run`, so this gets ITS OWN StackCP Scheduled Task, every 1 minute:
 *
 *   /usr/bin/php82 /home/sites/29a/8/8556230fc3/public_html/app/artisan whatsapp:mention-followups
 *
 * An idle tick is one indexed query. The unread-count polls also run the same tick after
 * their response (MentionFollowupService::fireFromRequest), so reminders still flow on a day
 * the cron is off — and every push is claimed per cycle, so the two can never double-send.
 */
class MentionFollowups extends Command
{
    protected $signature   = 'whatsapp:mention-followups';
    protected $description = 'Tag alerts: remind the tagger while the tagged person has not opened the chat (10 min, every 15, stop at 2 h)';

    public function handle(MentionFollowupService $svc): int
    {
        if (!$svc->ready()) {
            $this->info('whatsapp:mention-followups — table not installed yet (run mention_followups_oct2026.sql). Nothing to do.');
            return self::SUCCESS;
        }
        $r = $svc->tickLocked();
        if ($r === null) {
            $this->info('Another tick is still running — skipping.');
            return self::SUCCESS;
        }
        $this->info(sprintf(
            'whatsapp:mention-followups — reminders %d · gave up %d · closed as opened %d · untagged %d · skipped %d',
            $r['reminders'], $r['gave_up'], $r['opened'], $r['untagged'], $r['skipped']
        ));
        foreach ($r['errors'] as $e) $this->error('  ⚠ ' . $e);
        return $r['errors'] ? self::FAILURE : self::SUCCESS;
    }
}
