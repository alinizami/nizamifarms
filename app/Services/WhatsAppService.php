<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Models\WhatsApp\ConversationModel;
use App\Models\WhatsApp\MessageModel;
use App\Services\FirebaseService;

class WhatsAppService
{
    protected string $apiBase;
    protected string $phoneNumberId;
    protected string $accessToken;
    protected string $appSecret;
    protected string $apiVersion;

    public function __construct()
    {
        $this->apiVersion = config('whatsapp.api_version') ?? 'v21.0';
        $this->apiBase = (config('whatsapp.api_base_url') ?? 'https://graph.facebook.com') . '/' . $this->apiVersion;
        $this->phoneNumberId = config('whatsapp.phone_number_id') ?? '';
        $this->accessToken = config('whatsapp.access_token') ?? '';
        $this->appSecret = config('whatsapp.app_secret') ?? '';
    }

    /**
     * Verify webhook signature from Meta
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        if (empty($this->appSecret)) {
            Log::warning('WhatsApp: App secret not configured, skipping signature verification');
            return true;
        }

        $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $this->appSecret);
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Send a text message
     */
    public function sendTextMessage(string $to, string $text): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $text],
        ];

        return $this->sendRequest($payload);
    }

    /**
     * Header parameters for a template that has a MEDIA header (image/video/
     * document), ready to pass to sendTemplateMessage().
     *
     * Why this is needed at all: a template whose header format is IMAGE does
     * NOT carry a fixed image. The picture uploaded when the template was
     * created lives under Meta's `example.header_handle` — it is the sample used
     * for approval only. Every send must supply the media itself.
     *
     * VERIFIED by a live send Jul-2026 (app_launch_template to a test number):
     * without the header Meta returns
     *     132012 "Parameter format does not match format in the created template"
     * and delivers nothing; with the header the message goes through. So this is
     * not optional for any media-header template.
     *
     * Attached by MEDIA_ID, never by link: Meta fetching a link from this host
     * 403s (error 131053 — the lesson already baked into the invoice sender).
     *
     * The image is identical for every recipient, so the upload happens ONCE and
     * the media_id is cached and reused for the whole batch — a 2,000-message
     * campaign costs one upload, not 2,000. Meta expires media ids at roughly 30
     * days; the 20-day TTL keeps us comfortably inside that.
     *
     * Returns [] for any template without a media header, so callers can pass
     * the result unconditionally.
     */
    public function headerParamsForTemplate(?string $templateName): array
    {
        $templateName = trim((string) $templateName);
        if ($templateName === '') return [];

        try {
            if (!\Illuminate\Support\Facades\Schema::hasColumn('t_wa_templates', 'header_media_path')) {
                return [];   // migration not applied on this instance yet
            }

            $path = \DB::table('t_wa_templates')->where('name', $templateName)->value('header_media_path');
            if (empty($path)) return [];

            $mediaId = \Illuminate\Support\Facades\Cache::remember(
                $this->headerMediaCacheKey($templateName),
                now()->addDays(20),
                function () use ($templateName, $path) {
                    $full = storage_path('app/' . ltrim($path, '/'));
                    if (!file_exists($full)) {
                        Log::error('WhatsApp: template header media missing on disk', [
                            'template' => $templateName, 'path' => $full,
                        ]);
                        return null;
                    }
                    $mime = @mime_content_type($full) ?: 'image/png';
                    $id = $this->uploadMediaToWhatsApp($full, $mime);
                    Log::info('WhatsApp: uploaded template header media', [
                        'template' => $templateName, 'media_id' => $id, 'mime' => $mime,
                    ]);
                    return $id;
                }
            );

            // Never cache a failure — a null would otherwise stick for 20 days
            // and silently break every send of this template.
            if (!$mediaId) {
                \Illuminate\Support\Facades\Cache::forget($this->headerMediaCacheKey($templateName));
                return [];
            }

            return [['type' => 'image', 'image' => ['id' => $mediaId]]];

        } catch (\Throwable $e) {
            Log::error('WhatsApp: headerParamsForTemplate failed', [
                'template' => $templateName, 'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Drop the cached media id so the next send re-uploads.
     *
     * Called when Meta rejects a send for a media reason — an id can expire or
     * be revoked before our TTL runs out, and without this the campaign would
     * keep replaying the same dead id for the rest of the batch.
     */
    public function forgetTemplateHeaderMedia(?string $templateName): void
    {
        $templateName = trim((string) $templateName);
        if ($templateName === '') return;
        \Illuminate\Support\Facades\Cache::forget($this->headerMediaCacheKey($templateName));
    }

    protected function headerMediaCacheKey(string $templateName): string
    {
        return 'wa_tpl_header_media:' . md5($templateName);
    }

    /**
     * Send a template message.
     * $headerParams can be:
     *   - Array of strings for text header variables
     *   - Array with a single ['type' => 'image', 'image' => ['link' => '...']] for image header
     *   - Array with a single ['type' => 'document', 'document' => ['link' => '...', 'filename' => '...']] for document header
     */
    public function sendTemplateMessage(string $to, string $templateName, string $language = 'en', array $bodyParams = [], array $headerParams = [], array $buttons = []): array
    {
        $components = [];

        if (!empty($headerParams)) {
            $isRichHeader = isset($headerParams[0]['type']) && in_array($headerParams[0]['type'], ['image', 'document', 'video']);
            if ($isRichHeader) {
                $components[] = ['type' => 'header', 'parameters' => $headerParams];
            } else {
                $headerParameters = array_map(fn($p) => ['type' => 'text', 'text' => $p], $headerParams);
                $components[] = ['type' => 'header', 'parameters' => $headerParameters];
            }
        }

        if (!empty($bodyParams)) {
            $bodyParameters = array_map(fn($p) => ['type' => 'text', 'text' => $p], $bodyParams);
            $components[] = ['type' => 'body', 'parameters' => $bodyParameters];
        }

        if (!empty($buttons)) {
            foreach ($buttons as $index => $buttonPayload) {
                $components[] = [
                    'type' => 'button',
                    'sub_type' => 'quick_reply',
                    'index' => (string) $index,
                    'parameters' => [['type' => 'payload', 'payload' => $buttonPayload]],
                ];
            }
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $language],
            ],
        ];

        if (!empty($components)) {
            $payload['template']['components'] = $components;
        }

        return $this->sendRequest($payload);
    }

    /**
     * Send a document (e.g. invoice PDF) with optional caption
     */
    public function sendDocument(string $to, string $documentUrl, string $filename, string $caption = ''): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'document',
            'document' => [
                'link' => $documentUrl,
                'filename' => $filename,
            ],
        ];

        if ($caption) {
            $payload['document']['caption'] = $caption;
        }

        return $this->sendRequest($payload);
    }

    /**
     * Send an image with optional caption
     */
    public function sendImage(string $to, string $imageUrl, string $caption = ''): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'image',
            'image' => ['link' => $imageUrl],
        ];

        if ($caption) {
            $payload['image']['caption'] = $caption;
        }

        return $this->sendRequest($payload);
    }

    /**
     * Upload a local media file to Meta's resumable media endpoint.
     * Returns the Meta media_id string (used afterwards as audio.id /
     * document.id in a /messages call), or null on failure.
     *
     * This is the OUTBOUND counterpart of downloadMedia(). We keep the
     * flow deliberately simple: one multipart POST, no chunking. Voice
     * notes recorded on the mobile app stay well under Meta's 16 MB
     * audio cap (~1 MB per minute at AAC 128 kbps, and the mobile side
     * is capped at 5 minutes). If we ever add larger media we can swap
     * this for Meta's resumable upload API without changing callers.
     *
     * The caller owns the local file — we don't delete it here because
     * the outbound message row needs to keep a pointer for replay in
     * our own UI.
     */
    public function uploadMediaToWhatsApp(string $localPath, string $mimeType): ?string
    {
        try {
            if (!file_exists($localPath)) {
                Log::error('WhatsApp: uploadMediaToWhatsApp - file missing', ['path' => $localPath]);
                return null;
            }

            $url = "{$this->apiBase}/{$this->phoneNumberId}/media";

            // messaging_product + type are required by Meta; the file is
            // attached under the field name "file".
            $response = Http::withToken($this->accessToken)
                ->timeout(60)
                ->attach('file', file_get_contents($localPath), basename($localPath), ['Content-Type' => $mimeType])
                ->asMultipart()
                ->post($url, [
                    ['name' => 'messaging_product', 'contents' => 'whatsapp'],
                    ['name' => 'type',              'contents' => $mimeType],
                ]);

            if (!$response->successful()) {
                Log::error('WhatsApp: Media upload failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'mime' => $mimeType,
                ]);
                return null;
            }

            $mediaId = $response->json('id');
            if (!$mediaId) {
                Log::error('WhatsApp: Media upload returned no id', ['body' => $response->body()]);
                return null;
            }

            return (string) $mediaId;
        } catch (\Exception $e) {
            Log::error('WhatsApp: uploadMediaToWhatsApp exception', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Send an audio message (voice note) using an already-uploaded
     * Meta media_id. The recipient sees a playable audio message
     * inside WhatsApp. With AAC/M4A they get a standard audio player;
     * with OGG/OPUS it renders as the waveform voice-note bubble —
     * same API call either way, just different source MIME.
     */
    public function sendAudio(string $to, string $mediaId): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'audio',
            'audio'             => ['id' => $mediaId],
        ];

        return $this->sendRequest($payload);
    }

    /**
     * Send an image by uploaded media id (the upload→id→send flow the voice
     * notes use). Preferred over the link-based sendImage() for chat sends:
     * it works even when the stored file's public URL isn't reachable from
     * Meta's side (e.g. local/dev), and matches sendAudio symmetrically.
     */
    public function sendImageById(string $to, string $mediaId, string $caption = ''): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type'    => 'individual',
            'to'                => $to,
            'type'              => 'image',
            'image'             => ['id' => $mediaId],
        ];

        if ($caption !== '') {
            $payload['image']['caption'] = $caption;
        }

        return $this->sendRequest($payload);
    }

    /**
     * Mark a message as read (sends blue ticks to customer)
     */
    public function markAsRead(string $messageId): array
    {
        $payload = [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
        ];

        return $this->sendRequest($payload);
    }

    /**
     * Download media from WhatsApp (voice notes, images, documents, etc.)
     * Returns local file path or null on failure.
     */
    public function downloadMedia(string $mediaId): ?string
    {
        try {
            // Step 1: Get the media URL
            $response = Http::withToken($this->accessToken)
                ->get("{$this->apiBase}/{$mediaId}");

            if (!$response->successful()) {
                Log::error('WhatsApp: Failed to get media URL', ['media_id' => $mediaId, 'response' => $response->body()]);
                return null;
            }

            $mediaUrl = $response->json('url');
            $mimeType = $response->json('mime_type', 'application/octet-stream');

            // Step 2: Download the actual file
            $fileResponse = Http::withToken($this->accessToken)
                ->get($mediaUrl);

            if (!$fileResponse->successful()) {
                Log::error('WhatsApp: Failed to download media file', ['media_id' => $mediaId]);
                return null;
            }

            // Determine extension from mime type
            $extension = $this->getExtensionFromMime($mimeType);
            $filename = $mediaId . '.' . $extension;
            $path = config('whatsapp.media_path') . '/' . date('Y/m') . '/' . $filename;

            Storage::disk(config('whatsapp.media_disk'))->put($path, $fileResponse->body());

            return $path;

        } catch (\Exception $e) {
            Log::error('WhatsApp: Media download failed', ['media_id' => $mediaId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Process an incoming webhook payload from Meta
     */
    public function processIncomingWebhook(array $payload): void
    {
        $entries = $payload['entry'] ?? [];

        foreach ($entries as $entry) {
            $changes = $entry['changes'] ?? [];

            foreach ($changes as $change) {
                if (($change['field'] ?? '') !== 'messages') {
                    continue;
                }

                $value = $change['value'] ?? [];
                $contacts = $value['contacts'] ?? [];
                $messages = $value['messages'] ?? [];
                $statuses = $value['statuses'] ?? [];

                // Process incoming messages
                foreach ($messages as $msg) {
                    $this->handleIncomingMessage($msg, $contacts);
                }

                // Process status updates (sent, delivered, read, failed)
                foreach ($statuses as $status) {
                    $this->handleStatusUpdate($status);
                }
            }
        }
    }

    /**
     * Handle a single incoming message
     */
    /**
     * A rider tapping the "confirm" quick-reply on the shift_reminder template →
     * acknowledge their shift change(s), identical to the in-app confirm endpoint
     * (sets acknowledged_at on ALL their pending rows). Resolution:
     *   1. Precise: the tapped template's context.id → the shift_assigned automation
     *      log → its dedup_key "shift:{userId}:{assignmentId}". If context.id is
     *      present but is NOT a shift template, we stop (the tap was on something
     *      else — never phone-guess in that case).
     *   2. Fallback (no context.id at all): match the sender's number to a rider by
     *      last-10 digits of t_ops_rider_profile.phone.
     * Guard: only acts when a NOTIFIED, UNACKNOWLEDGED, not-past assignment exists,
     * so a stray "confirm" tap acknowledges nothing.
     */
    protected function maybeConfirmShiftFromReply(string $from, array $msg, string $content, string $type): void
    {
        // Is this a "confirm"?
        //  • button/interactive: label or payload contains it.
        //  • typed text: the message must essentially BE the word — "confirm",
        //    "Confirmed", "ok/yes confirm" (+ punctuation/emoji). A longer sentence
        //    ("I cannot confirm tomorrow") deliberately does NOT match.
        if ($type === 'text') {
            $c = trim($content);
            if ($c === '' || mb_strlen($c) > 40
                || !preg_match('/^\W*((ok(ay)?|yes|g|ji)\s+)?confirm(ed)?\W*$/i', $c)) {
                return;
            }
        } else {
            $payload = $type === 'button'
                ? (string) ($msg['button']['payload'] ?? '')
                : (string) ($msg['interactive']['button_reply']['id'] ?? '');
            if (stripos($content . ' ' . $payload, 'confirm') === false) {
                return;
            }
        }

        // Resolve WHICH rider. Precise path first: the replied-to template's
        // context.id → our automation log. If that maps to a DIFFERENT rule's
        // template (an order confirm etc.) → stop, it's positively not a shift
        // reply. If it maps to NOTHING (manual send / no context) → fall through
        // to the phone match.
        $userId = null;
        $ctxId = $msg['context']['id'] ?? null;
        if ($ctxId && \Illuminate\Support\Facades\Schema::hasTable('t_wa_automation_log')) {
            $log = \Illuminate\Support\Facades\DB::table('t_wa_automation_log')
                ->where('wa_message_id', $ctxId)
                ->orderByDesc('id')->first();
            if ($log) {
                if (($log->rule_key ?? '') !== 'shift_assigned') {
                    return; // reply to some other automation's template — not ours
                }
                if (preg_match('/^shift:(\d+):/', (string) ($log->dedup_key ?? ''), $m)) {
                    $userId = (int) $m[1];
                }
            }
        }
        if (!$userId) {
            // Best-effort phone match (last 10 digits, driver-agnostic) against the
            // unified rider phone.
            $last10 = substr(preg_replace('/\D/', '', $from), -10);
            if (strlen($last10) !== 10) {
                return;
            }
            $profiles = \Illuminate\Support\Facades\DB::table('t_ops_rider_profile')
                ->whereNotNull('phone')->where('phone', '!=', '')
                ->get(['user_id', 'phone']);
            foreach ($profiles as $p) {
                if (substr(preg_replace('/\D/', '', (string) $p->phone), -10) === $last10) {
                    $userId = (int) $p->user_id;
                    break;
                }
            }
        }
        if (!$userId) {
            return;
        }

        // Only acknowledge when a pending (notified, unacknowledged) row exists —
        // the SAME condition the in-app confirm uses (acknowledgeShift), so both
        // channels agree. Deliberately NOT date-restricted: a single-day change
        // confirmed the next morning is still a legitimate confirm (the earlier
        // "effective_to >= today" guard silently dropped exactly those, so a
        // WhatsApp tap did nothing while the in-app confirm worked). The
        // notified_at + unacknowledged pair already guarantees a real pending
        // change, so a stray "confirm" from a rider with nothing pending no-ops.
        $q = \App\Models\Ops\UserShiftAssignmentModel::where('user_id', $userId)
            ->whereNotNull('notified_at')->whereNull('acknowledged_at');
        $count = (clone $q)->count();
        if ($count === 0) {
            return;
        }
        $q->update(['acknowledged_at' => now()]);
        Log::info('Shift confirmed via WhatsApp reply', [
            'user_id' => $userId, 'rows' => $count, 'from' => $from, 'type' => $type,
        ]);

        // Audit row in the automations Activity log. Safe: dedup only counts
        // sent/failed rows, so this can never block a future send.
        try {
            if (\Illuminate\Support\Facades\Schema::hasTable('t_wa_automation_log')) {
                // NOTE: t_wa_automation_log has created_at only (no updated_at).
                \Illuminate\Support\Facades\DB::table('t_wa_automation_log')->insert([
                    'rule_key' => 'shift_assigned',
                    'trigger_event' => 'shift.confirmed',
                    'dedup_key' => 'shift:' . $userId . ':confirm',
                    'status' => 'confirmed_reply',
                    'wa_message_id' => $msg['id'] ?? null,
                    'created_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            // audit only — never let it affect the ack
        }
    }

    /**
     * Aug-2026 — answer a "Get bank details" quick-reply tap on a delivery
     * confirmation, instantly and automatically.
     *
     * The delivery-confirmation template no longer prints our account numbers in
     * its body; it offers a button instead. Tapping it opens a 24-hour customer
     * service window, so the answer goes out as a FREE-FORM text — no Meta
     * template, no approval needed. (Same reasoning the out-of-office auto-reply
     * relies on: the inbound that triggers us is what opens the window, so there
     * is no isSessionActive() check to make.)
     *
     * Works for automated AND manual sends of the confirmation: the order is
     * resolved from the automation log first, then from the outbound chat row's
     * related_order_number, which the Daily Closing send stamps.
     *
     * RETURNS TRUE ONLY WHEN WE ACTUALLY ANSWERED. The caller uses that to keep
     * the tap out of the unread badge and the push notification. If anything
     * fails we return FALSE on purpose, so the tap behaves like a normal
     * customer message and a human sees that someone asked and got nothing.
     *
     * Every tap is answered — a customer who taps twice wants the details twice.
     * Meta webhook re-deliveries can't double-send: handleIncomingMessage drops
     * a wa_message_id it has already stored before ever reaching us.
     */
    protected function maybeAnswerBankDetailsRequest($conversation, string $from, array $msg, string $type, string $content, $savedMessage): bool
    {
        if (!in_array($type, ['button', 'interactive'], true) || !$conversation) {
            return false;
        }

        // A quick reply on an APPROVED TEMPLATE arrives as type=button with
        // button.text + button.payload (the payload equals the button's own
        // label when the template was sent without button components, which is
        // how ours is sent). An interactive message's button arrives instead as
        // interactive.button_reply.title / .id. Check every shape.
        $label   = (string) ($msg['button']['text'] ?? ($msg['interactive']['button_reply']['title'] ?? $content));
        $payload = (string) ($msg['button']['payload'] ?? ($msg['interactive']['button_reply']['id'] ?? ''));

        if (!self::looksLikeBankDetailsRequest($label) && !self::looksLikeBankDetailsRequest($payload)) {
            return false;
        }

        $orderNumber = $this->resolveOrderNumberForTap($msg);
        $text = \App\Services\WhatsApp\BankDetailsProvider::replyMessage($orderNumber);

        try {
            $resp = $this->sendTextMessage($from, $text);
        } catch (\Throwable $e) {
            Log::warning('WhatsApp: bank-details reply send failed', [
                'conversation_id' => $conversation->id,
                'error'           => $e->getMessage(),
            ]);
            return false;
        }

        $replyWaId = $resp['messages'][0]['id'] ?? null;
        if (empty($replyWaId)) {
            Log::warning('WhatsApp: bank-details reply returned no message id', [
                'conversation_id' => $conversation->id,
                'response'        => $resp,
            ]);
            return false;
        }

        // Persist our answer so it shows in the chat history. Direct create
        // (like the auto-reply path) rather than saveOutboundMessage(), which
        // would drag in campaign adoption and template bookkeeping this send is
        // not part of.
        //
        // ⚠⚠ sent_by MUST stay NULL. The unread queries treat an outbound with a
        // non-null sent_by as "a human replied" and suppress every OLDER inbound
        // in the conversation — a real unanswered customer question would vanish
        // from the unread list. See UnreadQuery.
        try {
            $payloadRow = [
                'conversation_id' => $conversation->id,
                'wa_message_id'   => $replyWaId,
                'direction'       => 'outbound',
                'type'            => 'text',
                'content'         => $text,
                'status'          => 'sent',
                'sent_by'         => null,
                'created_at'      => now(),
            ];
            if ($orderNumber && \Illuminate\Support\Facades\Schema::hasColumn('t_wa_messages', 'related_order_number')) {
                $payloadRow['related_order_number'] = $orderNumber;
            }
            MessageModel::create($payloadRow);
            ConversationModel::where('id', $conversation->id)->update(['last_message_at' => now()]);
        } catch (\Throwable $e) {
            // The customer HAS the details; only our copy of the record failed.
            Log::warning('WhatsApp: bank-details reply not persisted', [
                'conversation_id' => $conversation->id,
                'error'           => $e->getMessage(),
            ]);
        }

        // Flag the TAP so it never raises an unread badge for any user, while
        // staying fully visible in the chat. No-op until the column exists.
        try {
            if ($savedMessage && \App\Services\WhatsApp\UnreadQuery::supported()) {
                \Illuminate\Support\Facades\DB::table('t_wa_messages')
                    ->where('id', $savedMessage->id)
                    ->update([\App\Services\WhatsApp\UnreadQuery::COLUMN => 1]);
            }
        } catch (\Throwable $e) {
            Log::debug('WhatsApp: could not flag tap as unread-exempt', ['error' => $e->getMessage()]);
        }

        // Audit row so the owner can see these in the automations Activity log.
        // ⭐ status is deliberately NEITHER 'sent' NOR 'failed': alreadyHandled()
        // counts only those two, so this row can never block a future automation
        // send for the same order.
        try {
            app(\App\Services\WhatsApp\Automation\WhatsAppAutomationService::class)->recordLog([
                'rule_key'        => 'order_delivered_payment_confirmation',
                'trigger_event'   => 'order.bank_details_requested',
                'dedup_key'       => 'bankdetails_reply:' . ($orderNumber ?: $from),
                'order_id'        => $this->prodOrderIdForNumber($orderNumber),
                'conversation_id' => $conversation->id,
                'status'          => 'details_reply',
                'wa_message_id'   => $replyWaId,
            ]);
        } catch (\Throwable $e) {
            // audit only — never affects the reply
        }

        Log::info('WhatsApp: bank details sent on customer request', [
            'conversation_id' => $conversation->id,
            'order_number'    => $orderNumber,
        ]);

        return true;
    }

    /**
     * Is this tapped button label / payload asking for our bank details?
     *
     * Punctuation-insensitive so "Get bank details!" still matches. Kept tight
     * on the phrase itself: no other approved template in the account carries a
     * button mentioning bank details (the others are Confirm/Cancel/Split and
     * the shift confirm), so a phrase match cannot collide.
     */
    public static function looksLikeBankDetailsRequest(?string $s): bool
    {
        $s = strtolower(trim((string) $s));
        if ($s === '') {
            return false;
        }
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
        $s = trim(preg_replace('/\s+/', ' ', (string) $s));
        return $s !== '' && str_contains($s, 'bank detail');
    }

    /**
     * Oct-2026 — quick-reply buttons the system answers with a PICTURE.
     *
     * Key = the button label after quickReplyImageKey() (lowercase, punctuation
     * dropped). Value = the image, relative to the WhatsApp media folder on the
     * media disk (storage/app/public/whatsapp-media/), so the copy kept in the
     * chat history renders like any other image bubble.
     *
     * "menu" = the Menu button on the frozen-range campaign template. EXACT label
     * match: "Menu" fires it, nothing else can. No other template in the account
     * has a Menu button (checked Oct-2026) — a future template that adds one would
     * get this same picture, so give it a different label or its own entry here.
     *
     * To change the picture, replace the file: the cached Meta media id is keyed
     * on the file's size + modified time, so the next tap sends the new one.
     */
    protected const QUICK_REPLY_IMAGES = [
        'menu' => 'quick-reply/frozen_menu.jpg',
    ];

    /** Normalised QUICK_REPLY_IMAGES key for a button label/payload, or null. */
    public static function quickReplyImageKey(?string $s): ?string
    {
        $s = strtolower(trim((string) $s));
        $s = trim(preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', $s)));
        return ($s !== '' && isset(self::QUICK_REPLY_IMAGES[$s])) ? $s : null;
    }

    /**
     * Oct-2026 — answer a quick-reply tap listed in QUICK_REPLY_IMAGES (the
     * frozen campaign's "Menu") by sending its picture, instantly.
     *
     * Same shape as maybeAnswerBankDetailsRequest: the tap opened the 24-hour
     * window, so this is a free-form image (no template, no approval, no Meta
     * charge for a service message). RETURNS TRUE ONLY WHEN WE ACTUALLY SENT IT —
     * on any failure the tap behaves like a normal customer message (unread badge
     * + push) so a human sees that someone asked for the menu and got nothing.
     * Webhook re-deliveries can't double-send: handleIncomingMessage drops a
     * wa_message_id it has already stored before reaching us.
     */
    protected function maybeAnswerQuickReplyImage($conversation, string $from, array $msg, string $type, string $content, $savedMessage): bool
    {
        if (!in_array($type, ['button', 'interactive'], true) || !$conversation) {
            return false;
        }

        $label   = (string) ($msg['button']['text'] ?? ($msg['interactive']['button_reply']['title'] ?? $content));
        $payload = (string) ($msg['button']['payload'] ?? ($msg['interactive']['button_reply']['id'] ?? ''));

        $key = self::quickReplyImageKey($label) ?? self::quickReplyImageKey($payload);
        if ($key === null) {
            return false;
        }

        $diskName = config('whatsapp.media_disk');
        $relative = config('whatsapp.media_path') . '/' . self::QUICK_REPLY_IMAGES[$key];
        $fullPath = Storage::disk($diskName)->path($relative);
        if (!file_exists($fullPath)) {
            Log::error('WhatsApp: quick-reply image missing on disk', ['button' => $key, 'path' => $fullPath]);
            return false;
        }

        $resp = $this->sendQuickReplyImage($from, $fullPath);
        $replyWaId = $resp['messages'][0]['id'] ?? null;
        if (empty($replyWaId)) {
            Log::warning('WhatsApp: quick-reply image send failed', [
                'conversation_id' => $conversation->id,
                'button'          => $key,
                'error'           => $resp['error'] ?? null,
                'error_code'      => $resp['error_code'] ?? null,
            ]);
            return false;
        }

        // Keep our answer in the chat history. ⚠⚠ sent_by MUST stay NULL — see
        // the same note in maybeAnswerBankDetailsRequest (UnreadQuery).
        try {
            MessageModel::create([
                'conversation_id' => $conversation->id,
                'wa_message_id'   => $replyWaId,
                'direction'       => 'outbound',
                'type'            => 'image',
                'content'         => '[Image]',
                'media_url'       => $relative,
                'media_mime_type' => @mime_content_type($fullPath) ?: 'image/jpeg',
                'status'          => 'sent',
                'sent_by'         => null,
                'created_at'      => now(),
            ]);
            ConversationModel::where('id', $conversation->id)->update(['last_message_at' => now()]);
        } catch (\Throwable $e) {
            // The customer HAS the picture; only our copy of the record failed.
            Log::warning('WhatsApp: quick-reply image not persisted', [
                'conversation_id' => $conversation->id,
                'error'           => $e->getMessage(),
            ]);
        }

        // The tap needs no human: keep it out of every unread badge (still
        // visible in the chat). No-op until the column exists.
        try {
            if ($savedMessage && \App\Services\WhatsApp\UnreadQuery::supported()) {
                \Illuminate\Support\Facades\DB::table('t_wa_messages')
                    ->where('id', $savedMessage->id)
                    ->update([\App\Services\WhatsApp\UnreadQuery::COLUMN => 1]);
            }
        } catch (\Throwable $e) {
            Log::debug('WhatsApp: could not flag tap as unread-exempt', ['error' => $e->getMessage()]);
        }

        // Which template was tapped — log only (forensics), never a gate.
        $tappedTemplate = null;
        try {
            $ctxId = $msg['context']['id'] ?? null;
            if ($ctxId) {
                $tappedTemplate = MessageModel::where('wa_message_id', $ctxId)->value('template_name');
            }
        } catch (\Throwable $e) {
            // log detail only
        }

        Log::info('WhatsApp: quick-reply image sent', [
            'conversation_id' => $conversation->id,
            'button'          => $key,
            'template'        => $tappedTemplate,
        ]);

        return true;
    }

    /**
     * Send a local image by media id, uploading it to Meta at most once per 20
     * days (Meta expires ids at ~30). A media error on a cached id (expired or
     * revoked early) drops it and retries ONCE with a fresh upload. Other errors
     * are not retried — a timeout Meta actually accepted would otherwise send the
     * picture twice.
     */
    protected function sendQuickReplyImage(string $to, string $fullPath): array
    {
        $cacheKey = 'wa_quick_reply_media:' . md5($fullPath . '|' . @filesize($fullPath) . '|' . @filemtime($fullPath));
        $mime = @mime_content_type($fullPath) ?: 'image/jpeg';

        $resp = ['success' => false, 'error' => 'media upload failed'];
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $mediaId = \Illuminate\Support\Facades\Cache::remember(
                $cacheKey,
                now()->addDays(20),
                fn () => $this->uploadMediaToWhatsApp($fullPath, $mime)
            );
            if (!$mediaId) {
                // Never cache a failed upload.
                \Illuminate\Support\Facades\Cache::forget($cacheKey);
                return ['success' => false, 'error' => 'media upload failed'];
            }

            $resp = $this->sendImageById($to, $mediaId);
            if ($resp['success'] ?? false) {
                return $resp;
            }

            $isMediaError = in_array((int) ($resp['error_code'] ?? 0), [131051, 131052, 131053], true)
                || str_contains(strtolower((string) ($resp['error'] ?? '')), 'media');
            if (!$isMediaError) {
                return $resp;
            }
            \Illuminate\Support\Facades\Cache::forget($cacheKey);
        }

        return $resp;
    }

    /**
     * Which order does a button tap answer? WhatsApp sends context.id = the
     * wa_message_id of the template that was tapped.
     *   1. the automation log (an automated send), then
     *   2. the outbound chat row's related_order_number (a MANUAL Daily Closing
     *      send, which has no automation-log row).
     * Null when neither resolves — the reply is still sent, just without naming
     * the order rather than naming a guessed one.
     */
    protected function resolveOrderNumberForTap(array $msg): ?string
    {
        $ctxId = $msg['context']['id'] ?? null;
        if (empty($ctxId)) {
            return null;
        }

        try {
            $viaLog = \App\Services\WhatsApp\OrderReplyService::resolveOrderForReplyContext($ctxId);
            if (!empty($viaLog)) {
                return (string) $viaLog;
            }
        } catch (\Throwable $e) {
            // fall through to the chat-row lookup
        }

        try {
            if (\Illuminate\Support\Facades\Schema::hasColumn('t_wa_messages', 'related_order_number')) {
                $num = \Illuminate\Support\Facades\DB::table('t_wa_messages')
                    ->where('wa_message_id', $ctxId)
                    ->value('related_order_number');
                if (!empty($num)) {
                    return (string) $num;
                }
            }
        } catch (\Throwable $e) {
            // give up quietly
        }

        return null;
    }

    /** Best-effort prod order id for the audit log (display only). */
    protected function prodOrderIdForNumber(?string $orderNumber): ?int
    {
        if (empty($orderNumber)) {
            return null;
        }
        try {
            $id = \Illuminate\Support\Facades\DB::table('t_crm_prod_order')
                ->where('order_number', $orderNumber)
                ->value('id');
            return $id ? (int) $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function handleIncomingMessage(array $msg, array $contacts): void
    {
        try {
            $waMessageId = $msg['id'] ?? null;
            $from = $msg['from'] ?? null;
            $timestamp = $msg['timestamp'] ?? null;
            $type = $msg['type'] ?? 'text';

            if (!$waMessageId || !$from) {
                return;
            }

            // Skip duplicate messages
            if (MessageModel::where('wa_message_id', $waMessageId)->exists()) {
                return;
            }

            // Get contact name from the contacts array
            $contactName = null;
            foreach ($contacts as $contact) {
                if (($contact['wa_id'] ?? '') === $from) {
                    $contactName = $contact['profile']['name'] ?? null;
                    break;
                }
            }

            // Find or create conversation
            $conversation = $this->findOrCreateConversation($from, $contactName);

            // Extract content based on message type
            $content = null;
            $mediaUrl = null;
            $mediaMimeType = null;
            $metadata = null;

            switch ($type) {
                case 'text':
                    $content = $msg['text']['body'] ?? '';
                    break;

                case 'image':
                    $content = $msg['image']['caption'] ?? '';
                    $mediaPath = $this->downloadMedia($msg['image']['id']);
                    $mediaUrl = $mediaPath;
                    $mediaMimeType = $msg['image']['mime_type'] ?? 'image/jpeg';
                    break;

                case 'audio':
                    $mediaPath = $this->downloadMedia($msg['audio']['id']);
                    $mediaUrl = $mediaPath;
                    $mediaMimeType = $msg['audio']['mime_type'] ?? 'audio/ogg';
                    $content = '[Voice Note]';
                    break;

                case 'video':
                    $content = $msg['video']['caption'] ?? '[Video]';
                    $mediaPath = $this->downloadMedia($msg['video']['id']);
                    $mediaUrl = $mediaPath;
                    $mediaMimeType = $msg['video']['mime_type'] ?? 'video/mp4';
                    break;

                case 'document':
                    $content = $msg['document']['caption'] ?? $msg['document']['filename'] ?? '[Document]';
                    $mediaPath = $this->downloadMedia($msg['document']['id']);
                    $mediaUrl = $mediaPath;
                    $mediaMimeType = $msg['document']['mime_type'] ?? 'application/pdf';
                    break;

                case 'sticker':
                    // Sep-2026 — stickers used to fall through to `default`,
                    // which stored the literal string "[Unsupported message
                    // type: sticker]" and threw the image away (26 such rows on
                    // prod). A thumbs-up STICKER is an ordinary customer reply,
                    // so it is now downloaded and shown like any other image.
                    // image/webp is already in the mime→extension map, and
                    // maybeQueuePaymentSignal() only fires on type=image, so a
                    // sticker can never be read as a payment proof (no Gemini
                    // spend). Guarded: a payload with no media id keeps the
                    // label and simply has no picture.
                    $stickerId = $msg['sticker']['id'] ?? null;
                    $mediaPath = $stickerId ? $this->downloadMedia($stickerId) : null;
                    $mediaUrl = $mediaPath;
                    $mediaMimeType = $msg['sticker']['mime_type'] ?? 'image/webp';
                    $content = '[Sticker]';
                    break;

                case 'location':
                    $lat = $msg['location']['latitude'] ?? 0;
                    $lng = $msg['location']['longitude'] ?? 0;
                    $locName = $msg['location']['name'] ?? '';
                    $locAddress = $msg['location']['address'] ?? '';
                    $content = $locName ?: $locAddress ?: 'Shared location';
                    $metadata = json_encode([
                        'latitude' => $lat,
                        'longitude' => $lng,
                        'name' => $locName,
                        'address' => $locAddress,
                    ]);
                    break;

                case 'reaction':
                    $content = $msg['reaction']['emoji'] ?? '';
                    $metadata = json_encode(['reacted_message_id' => $msg['reaction']['message_id'] ?? null]);
                    $type = 'reaction';
                    break;

                case 'button':
                    $content = $msg['button']['text'] ?? '';
                    $metadata = json_encode(['button_payload' => $msg['button']['payload'] ?? null]);
                    break;

                case 'interactive':
                    $interactive = $msg['interactive'] ?? [];
                    if (isset($interactive['button_reply'])) {
                        $content = $interactive['button_reply']['title'] ?? '';
                        $metadata = json_encode(['button_id' => $interactive['button_reply']['id'] ?? null]);
                    } elseif (isset($interactive['list_reply'])) {
                        $content = $interactive['list_reply']['title'] ?? '';
                        $metadata = json_encode(['list_id' => $interactive['list_reply']['id'] ?? null]);
                    }
                    break;

                default:
                    $content = "[Unsupported message type: {$type}]";
                    break;
            }

            // Save the message
            $savedMessage = MessageModel::create([
                'conversation_id' => $conversation->id,
                'wa_message_id' => $waMessageId,
                'direction' => 'inbound',
                'type' => $type,
                'content' => $content,
                'media_url' => $mediaUrl,
                'media_mime_type' => $mediaMimeType,
                'metadata' => $metadata,
                'status' => 'received',
                'created_at' => $timestamp ? date('Y-m-d H:i:s', (int)$timestamp) : now(),
            ]);

            // Jun-2026 — link a quick-reply button/list tap back to the SPECIFIC
            // order it answers (Confirm / Split / Cancel on an order-received
            // template). WhatsApp gives us context.id (the template they tapped);
            // we map it to the order via the automation log and stamp the
            // order_number so the Shopify approval view shows the choice on the
            // exact order. Fully guarded + non-fatal (falls back to per-customer).
            if (in_array($type, ['button', 'interactive'], true) && $savedMessage) {
                try {
                    $ctxId = $msg['context']['id'] ?? null;
                    if ($ctxId && \Illuminate\Support\Facades\Schema::hasColumn('t_wa_messages', 'related_order_number')) {
                        // Aug-2026: resolveOrderNumberForTap() = the automation
                        // log first (unchanged), then the outbound chat row's
                        // related_order_number. The fallback matters because a
                        // template sent BY HAND (Daily Closing, Send Template)
                        // has no automation-log row, so taps on those used to
                        // land unlinked and fall back to per-customer matching.
                        $orderNo = $this->resolveOrderNumberForTap($msg);
                        if ($orderNo) {
                            \Illuminate\Support\Facades\DB::table('t_wa_messages')
                                ->where('id', $savedMessage->id)
                                ->update(['related_order_number' => $orderNo]);
                        }
                    }
                } catch (\Throwable $linkErr) {
                    Log::debug('WhatsApp: reply→order link skipped (non-fatal)', ['error' => $linkErr->getMessage()]);
                }
            }

            // Jul-2026 — shift-change confirm: a quick-reply "confirm" TAP or a short
            // TYPED "confirm" from a rider acknowledges their pending shift change
            // (same acknowledged_at as the in-app confirm). Guarded + non-fatal; only
            // acts when the sender maps to a rider WITH a pending change, so stray
            // messages do nothing. (Typed replies matter: riders often type the word
            // instead of tapping the button.)
            if ($savedMessage && in_array($type, ['button', 'interactive', 'text'], true)) {
                try {
                    $this->maybeConfirmShiftFromReply($from, $msg, (string) $content, $type);
                } catch (\Throwable $shiftErr) {
                    Log::debug('WhatsApp: shift-confirm skipped (non-fatal)', ['error' => $shiftErr->getMessage()]);
                }
            }

            // Aug-2026 — "Get bank details" tap on a delivery confirmation. The
            // system answers it immediately, so it is NOT staff work: when we
            // answered, the tap raises no unread badge, no push notification and
            // no out-of-office auto-reply (the customer already has their
            // answer; a "we're closed" on top would be nonsense). It stays fully
            // visible in the chat history either way.
            //
            // Deliberately FAILS LOUD: if the answer could not be sent this
            // stays false and the tap behaves like any other customer message,
            // so a human notices that someone asked and got nothing.
            $systemAnswered = false;
            if ($savedMessage && in_array($type, ['button', 'interactive'], true)) {
                try {
                    $systemAnswered = $this->maybeAnswerBankDetailsRequest(
                        $conversation, $from, $msg, $type, (string) $content, $savedMessage
                    );
                } catch (\Throwable $bankErr) {
                    Log::warning('WhatsApp: bank-details reply skipped (non-fatal)', ['error' => $bankErr->getMessage()]);
                }
            }

            // Oct-2026 — a tap listed in QUICK_REPLY_IMAGES (the frozen
            // campaign's "Menu") is answered with its picture. Same rules as
            // the bank-details answer above: answered = no badge / push /
            // out-of-office; failed = a normal customer message.
            if (!$systemAnswered && $savedMessage && in_array($type, ['button', 'interactive'], true)) {
                try {
                    $systemAnswered = $this->maybeAnswerQuickReplyImage(
                        $conversation, $from, $msg, $type, (string) $content, $savedMessage
                    );
                } catch (\Throwable $qrErr) {
                    Log::warning('WhatsApp: quick-reply image skipped (non-fatal)', ['error' => $qrErr->getMessage()]);
                }
            }

            // Jun-2026 — Online payment auto-matching. If this inbound is an
            // image (a likely bank-transfer screenshot) from a customer who has
            // a pending online order, drop a cheap "payment signal" row. The
            // expensive Gemini read + matching happens later in the scheduled
            // payments:process-signals command, keeping this webhook fast.
            // Fully gated by the feature switch; non-fatal on any error.
            try {
                $this->maybeQueuePaymentSignal($conversation, $savedMessage, $type, $mediaMimeType, $mediaUrl);
                // Drive the payment workers off this webhook (after the response
                // is flushed) since the shared host can't be trusted to run the
                // scheduler. Self-throttled to ~1 run/min by a Cache lock.
                app(\App\Services\Payments\PaymentSignalAutoRunner::class)->fireFromRequest();
            } catch (\Exception $paySigErr) {
                Log::debug('WhatsApp: payment-signal queue skipped (non-fatal)', ['error' => $paySigErr->getMessage()]);
            }

            // Update conversation.
            //
            // last_customer_message_at is stamped even for a system-answered tap
            // — it genuinely IS a customer message and it genuinely DID open the
            // 24-hour reply window, which the rest of the app reads from here.
            // Only the unread counter is held back (and it is the legacy
            // fallback; the real per-user badge is handled by the unread_exempt
            // flag set in maybeAnswerBankDetailsRequest — see UnreadQuery).
            $conversation->update([
                'last_message_at' => now(),
                'last_customer_message_at' => now(),
                'unread_count' => $systemAnswered
                    ? $conversation->unread_count
                    : $conversation->unread_count + 1,
                'status' => 'active',
            ]);

            // Campaign reply tracking: if this conversation is linked to a known
            // customer, stamp replied_at on any active campaign rows where the
            // customer was already sent a message (within that campaign's
            // tracking window). Non-fatal on any error.
            //
            // Oct-2026 owner ruling: a button TAP (e.g. the frozen campaign's
            // "Menu") is NOT a reply — the system answers it, and the campaign
            // screen shows taps on their own ("Tapped") from the chat log.
            // Reactions DO count as a reply. CampaignStatsService::engagementMap
            // applies the same rule, so the column and the screen agree.
            try {
                if ($conversation->customer_id && !in_array($type, ['button', 'interactive'], true)) {
                    $this->stampCampaignReplies((int) $conversation->customer_id);
                }
            } catch (\Exception $campaignErr) {
                Log::debug('WhatsApp: Campaign reply tracking failed (non-fatal)', ['error' => $campaignErr->getMessage()]);
            }

            // Qurbani auto-classification runs AFTER the inbound message is
            // persisted so the new message is part of the lookback window.
            // Non-fatal on any error.
            try {
                app(\App\Services\QurbaniClassifier::class)->classify($conversation->fresh());
            } catch (\Exception $classifyErr) {
                Log::debug('WhatsApp: Qurbani classify failed (non-fatal)', ['error' => $classifyErr->getMessage()]);
            }

            // Phase 6 (May-2026) — Qurbani location-request reply linking.
            // If this inbound message is a location pin, try to match it
            // to an outstanding t_crm_qurbani_loc_request row so it shows
            // up in the Reviewer drawer on the Orders page. We pass the
            // conversation's customer_id as a SAFETY cross-check — the
            // service refuses to link if it disagrees with the request's
            // stored customer_id (prevents Customer B's pin landing on
            // Customer A). Non-fatal on any error.
            if ($type === 'location') {
                try {
                    app(\App\Services\QurbaniLocationRequestService::class)->recordReply(
                        $msg['context']['id'] ?? null,
                        $from,
                        (float) ($msg['location']['latitude'] ?? 0),
                        (float) ($msg['location']['longitude'] ?? 0),
                        (string) ($msg['location']['name'] ?? ''),
                        (string) ($msg['location']['address'] ?? ''),
                        $conversation->customer_id ? (int) $conversation->customer_id : null,
                        $waMessageId
                    );
                } catch (\Exception $locReqErr) {
                    Log::debug('WhatsApp: QurbaniLocReq link failed (non-fatal)', [
                        'error' => $locReqErr->getMessage(),
                    ]);
                }

                // Jun-2026 — Open Orders "Get Customer Locations": auto-save a
                // native pin onto the customer when their most recent location
                // request was a regular (non-qurbani) one and they have no pin
                // yet. Gated inside the service so the qurbani flow is untouched.
                try {
                    app(\App\Services\Location\OpenOrderLocationService::class)->autoSaveInbound(
                        $conversation,
                        (float) ($msg['location']['latitude'] ?? 0),
                        (float) ($msg['location']['longitude'] ?? 0),
                        null
                    );
                } catch (\Throwable $ooLocErr) {
                    Log::debug('WhatsApp: OpenOrderLoc pin auto-save failed (non-fatal)', [
                        'error' => $ooLocErr->getMessage(),
                    ]);
                }
            }

            // Phase 6 (May-2026) — Maps-URL fallback. Some customers reply
            // with a "https://maps.app.goo.gl/..." link as plain text
            // instead of attaching a real WhatsApp location pin. The
            // service follows the shortlink, extracts lat/lng, and feeds
            // the result through the same reply pipeline so the row lands
            // in the Reviewer drawer next to the genuine pins. If the
            // URL doesn't yield coords we silently no-op — the message
            // itself is already saved above and staff can still use the
            // "Set as Verified Location" button on /messages to handle
            // it manually. Non-fatal on any error.
            if ($type === 'text' && !empty($content)) {
                try {
                    app(\App\Services\QurbaniLocationRequestService::class)->recordUrlReply(
                        $msg['context']['id'] ?? null,
                        $from,
                        (string) $content,
                        $conversation->customer_id ? (int) $conversation->customer_id : null,
                        $waMessageId
                    );
                } catch (\Exception $urlReplyErr) {
                    Log::debug('WhatsApp: QurbaniLocReq URL link failed (non-fatal)', [
                        'error' => $urlReplyErr->getMessage(),
                    ]);
                }

                // Jun-2026 — Open Orders "Get Customer Locations": resolve a
                // Maps URL / plain coordinates sent as text and auto-save it
                // (same gating as the native-pin path above).
                try {
                    app(\App\Services\Location\OpenOrderLocationService::class)->autoSaveInboundText(
                        $conversation,
                        (string) $content
                    );
                } catch (\Throwable $ooLocTextErr) {
                    Log::debug('WhatsApp: OpenOrderLoc text auto-save failed (non-fatal)', [
                        'error' => $ooLocTextErr->getMessage(),
                    ]);
                }
            }

            // NOTE: Read receipts are NO LONGER sent automatically on webhook
            // receipt. They are sent only when a team member actually reads the
            // message in the UI (web or mobile). This prevents customers from
            // seeing the blue double-tick the instant their message hits our
            // server. The send is triggered from markConversationReadForUser().

            // Send push notification to staff — unless the system already
            // answered this tap (nothing for a human to do).
            if (!$systemAnswered) {
                try {
                    $senderName = $contactName ?? $conversation->display_name ?? $from;
                    // Sep-2026 — a reaction push used to be the bare emoji ("👍")
                    // with nothing to say what was reacted to, and an EMPTY body
                    // when the customer removed their reaction. Say what happened.
                    $preview = $type === 'reaction'
                        ? $this->reactionPushPreview($savedMessage, (int) $conversation->id, (string) $content)
                        : mb_substr($content ?? '[Media]', 0, 200);
                    app(FirebaseService::class)->notifyNewWhatsAppMessage($senderName, $preview, $conversation->id);
                } catch (\Exception $pushErr) {
                    Log::debug('WhatsApp: Push notification failed (non-fatal)', ['error' => $pushErr->getMessage()]);
                }
            }

            // Auto-reply (Apr-2026). Fires inside the 24h customer-care window
            // that this very inbound just opened, so it's a free-form text
            // send (no Meta template approval required). All checks —
            // feature toggle, time/day/date matching, per-conversation
            // cooldown — live inside maybeSendAutoReply. Non-fatal on any
            // error because a failed auto-reply must NEVER prevent the
            // inbound from being saved or notifying staff.
            // Skipped when the system already answered the tap: the customer has
            // what they asked for, and following it with "we're currently
            // closed" would read as a non-sequitur.
            if (!$systemAnswered) {
                try {
                    $this->maybeSendAutoReply($conversation, $from, $type);
                } catch (\Exception $autoErr) {
                    Log::debug('WhatsApp: Auto-reply skipped (non-fatal)', ['error' => $autoErr->getMessage()]);
                }
            }

            Log::info('WhatsApp: Incoming message saved', [
                'conversation_id' => $conversation->id,
                'from' => $from,
                'type' => $type,
            ]);

        } catch (\Exception $e) {
            Log::error('WhatsApp: Failed to process incoming message', [
                'error' => $e->getMessage(),
                'message_data' => $msg,
            ]);
        }
    }

    /**
     * Sep-2026 — Resolve what each inbound REACTION was reacting to.
     *
     * A reaction row stores only the emoji (as `content`) plus the reacted-to
     * WhatsApp id in `metadata.reacted_message_id`. On its own that renders as
     * a bare bubble, so a customer thumbs-upping an invoice looked exactly like
     * them sending "👍" as a brand-new message. This turns the stored id into a
     * short quote of the ORIGINAL message so both surfaces can show
     * "Reacted to our message · Invoice #NF-1234".
     *
     * ONE implementation for both surfaces — the web and the mobile controllers
     * both call this — and ONE extra query per page load (a single whereIn,
     * skipped entirely when the page holds no reactions). The lookup is scoped
     * to the SAME conversation, so a stale or foreign id can never pull another
     * customer's message into this thread.
     *
     * @param  iterable $messages        rows already fetched for this page
     * @param  int      $conversationId  the thread they belong to
     * @return array<int, array>         reaction row id => quote payload
     */
    public function reactionContextFor($messages, int $conversationId): array
    {
        $wanted = []; // reacted-to wa_message_id => [ids of the reaction rows quoting it]

        foreach ($messages as $m) {
            if (($m->type ?? null) !== 'reaction') {
                continue;
            }
            $meta = is_string($m->metadata ?? null) ? json_decode($m->metadata, true) : ($m->metadata ?? null);
            $targetWaId = is_array($meta) ? ($meta['reacted_message_id'] ?? null) : null;
            if ($targetWaId) {
                $wanted[$targetWaId][] = $m->id;
            }
        }

        if (!$wanted) {
            return [];
        }

        try {
            $out = [];
            $targets = MessageModel::where('conversation_id', $conversationId)
                ->whereIn('wa_message_id', array_keys($wanted))
                ->get(['id', 'wa_message_id', 'direction', 'type', 'content', 'template_name']);

            foreach ($targets as $t) {
                $payload = [
                    'id'        => $t->id,
                    'direction' => $t->direction,
                    'type'      => $t->type,
                    'snippet'   => $this->messageSnippet($t),
                ];
                foreach ($wanted[$t->wa_message_id] ?? [] as $reactionRowId) {
                    $out[$reactionRowId] = $payload;
                }
            }

            return $out;
        } catch (\Throwable $e) {
            // A decoration must never be able to break the chat payload.
            Log::debug('WhatsApp: reaction context skipped (non-fatal)', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * One-line description of a message, used to quote it under a reaction.
     * Media rows get their own label because their `content` is either a
     * caption or a placeholder like "[Voice Note]".
     */
    public function messageSnippet($msg): string
    {
        $text = $this->clipSnippet((string) ($msg->content ?? ''));

        switch ($msg->type ?? '') {
            case 'image':    return $text !== '' ? '📷 ' . $text : '📷 Photo';
            case 'audio':    return '🎤 Voice note';
            case 'sticker':  return '💬 Sticker';
            case 'video':    return ($text !== '' && $text !== '[Video]') ? '🎬 ' . $text : '🎬 Video';
            case 'document': return ($text !== '' && $text !== '[Document]') ? '📄 ' . $text : '📄 Document';
            case 'location': return $text !== '' ? '📍 ' . $text : '📍 Location';
        }

        if ($text !== '') {
            return $text;
        }

        return $msg->template_name ? ('Template: ' . $msg->template_name) : 'Message';
    }

    /**
     * Collapse whitespace and cut to a quotable length. `\s` is ASCII-only
     * without the /u flag, so it can never land inside a multi-byte sequence;
     * the cut itself uses mb_substr so an emoji is never sliced in half.
     */
    protected function clipSnippet(string $s, int $len = 70): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s));

        return mb_strlen($s) > $len ? (mb_substr($s, 0, $len) . '…') : $s;
    }

    /**
     * Push-notification body for an inbound reaction. Meta sends an EMPTY
     * emoji when the customer REMOVES their reaction — that used to produce a
     * notification with no body at all.
     */
    protected function reactionPushPreview($savedMessage, int $conversationId, string $content): string
    {
        $emoji = trim($content);

        if ($emoji === '') {
            return 'Removed a reaction';
        }

        $ctx = $savedMessage ? $this->reactionContextFor([$savedMessage], $conversationId) : [];
        $snippet = $ctx[$savedMessage->id ?? 0]['snippet'] ?? null;

        return $snippet
            ? mb_substr('Reacted ' . $emoji . ' to: ' . $snippet, 0, 200)
            : ('Reacted ' . $emoji);
    }

    /**
     * Jun-2026 — Create a pending "payment signal" row for an inbound image
     * that is likely a bank-transfer screenshot, so the scheduled
     * payments:process-signals command can read it with Gemini and match it to
     * a pending online order.
     *
     * Cheap by design: this only INSERTs a row (no Gemini call here). It is
     * heavily pre-filtered to keep cost down — we skip unless the conversation
     * is mapped to a customer who has at least one unpaid/partial online order
     * within the match window. The actual image read is deferred.
     */
    protected function maybeQueuePaymentSignal($conversation, $savedMessage, string $type, ?string $mimeType, ?string $mediaUrl): void
    {
        if (!config('payment_signals.enabled')) {
            return;
        }
        if (!$savedMessage) {
            return;
        }

        // Only images (an image-typed message, or a document whose MIME is an
        // image). Non-images are silently ignored (no log — far too noisy).
        $isImage = $type === 'image'
            || ($type === 'document' && $mimeType && str_starts_with($mimeType, 'image/'));
        if (!$isImage) {
            return;
        }

        // From here on we KNOW it's an image, so every skip is worth a one-line
        // debug note — this is what makes "why didn't X get a signal?" instantly
        // answerable from the log.
        $logCtx = ['wa_message_id' => $savedMessage->id, 'conversation_id' => $conversation->id];

        // The media must have downloaded (image_path is required for the Gemini read).
        if (!$mediaUrl) {
            Log::debug('PaymentSignal: image skipped — media download failed (no image_path)', $logCtx);
            return;
        }

        // ── Phase 5c: a TRUSTED STAFF number forwarding a customer's proof ──
        // The forward comes from Taimur, so WhatsApp has stripped the payer's
        // identity — this is NOT a customer proof and must never be matched as
        // one. Hand it to the assistant, which takes the customer from the
        // caption and raises a confirmation CARD. Done AFTER the response so a
        // Gemini read can never slow the webhook (Meta retries on timeout).
        $forwarderUserId = \App\Services\Assistant\ForwardedProofService::forwarderUserId($conversation->wa_phone ?? null);
        if ($forwarderUserId) {
            $caption = $savedMessage->content ?? null;
            $path = $mediaUrl;
            Log::info('ForwardedProof: trusted forwarder image received', $logCtx + ['user_id' => $forwarderUserId]);
            app()->terminating(function () use ($forwarderUserId, $path, $caption) {
                try {
                    app(\App\Services\Assistant\ForwardedProofService::class)->handle($forwarderUserId, $path, $caption);
                } catch (\Throwable $e) {
                    Log::error('ForwardedProof: handling failed', ['error' => $e->getMessage()]);
                }
            });
            return; // never fall through to the customer-proof path
        }

        // Must be a mapped customer (the conversation has to resolve to a customer).
        $customerId = $conversation->customer_id ?? null;
        if (!$customerId) {
            Log::debug('PaymentSignal: image skipped — conversation not mapped to a customer', $logCtx);
            return;
        }

        // Pre-filter: customer must have a still-owed order in the window.
        $windowDays = (int) config('payment_signals.match_window_days', 30);
        $methods = config('payment_signals.online_payment_methods', ['online', 'bank_transfer']);
        $openStatuses = config('payment_signals.open_payment_statuses', ['unpaid', 'partial']);

        $pendingQuery = \DB::table('t_crm_prod_order')
            ->where('customer_id', $customerId)
            ->whereIn('payment_status', $openStatuses)
            ->where('order_status', '!=', 'cancelled')
            ->where('order_date', '>=', now()->subDays($windowDays)->startOfDay());

        // A bank screenshot is itself proof of an online payment, so by default
        // we do NOT require the order to already be tagged online/bank_transfer
        // (delivered orders are often created as 'cash' and paid online later).
        if (config('payment_signals.require_online_payment_method', false)) {
            $pendingQuery->whereIn('payment_method', $methods);
        }

        if (!$pendingQuery->exists()) {
            Log::debug('PaymentSignal: image skipped — no unpaid/partial order in window for customer', $logCtx + [
                'customer_id' => $customerId,
                'window_days' => $windowDays,
                'require_online_method' => (bool) config('payment_signals.require_online_payment_method', false),
            ]);
            return;
        }

        // Idempotency: one signal per WhatsApp message.
        $alreadyQueued = \App\Models\FIN\PaymentSignal::where('wa_message_id', $savedMessage->id)->exists();
        if ($alreadyQueued) {
            return;
        }

        \App\Models\FIN\PaymentSignal::create([
            'source'             => \App\Models\FIN\PaymentSignal::SOURCE_WHATSAPP,
            'wa_message_id'      => $savedMessage->id,
            'wa_conversation_id' => $conversation->id,
            'image_path'         => $mediaUrl,
            'status'             => \App\Models\FIN\PaymentSignal::STATUS_NEW,
        ]);

        Log::info('PaymentSignal: queued WhatsApp image for matching', $logCtx + ['customer_id' => $customerId]);
    }

    /**
     * Handle status update (sent, delivered, read, failed)
     */
    protected function handleStatusUpdate(array $status): void
    {
        try {
            $waMessageId = $status['id'] ?? null;
            $newStatus = $status['status'] ?? null;

            if (!$waMessageId || !$newStatus) {
                return;
            }

            $message = MessageModel::where('wa_message_id', $waMessageId)->first();
            if (!$message) {
                // Jul-2026: log instead of vanishing. A status can arrive for a
                // wamid this database has never seen — most commonly a message
                // sent from the LOCAL dev environment (Meta's webhook points at
                // prod only, so prod receives receipts for local test sends).
                // Before this line those statuses were discarded with zero
                // trace, which made "I sent a test locally and never received
                // it" undiagnosable: the delivery failure reason existed only
                // in this payload, and we threw it away. Now it's greppable:
                //   grep 'status for unknown wamid' storage/logs/laravel.log
                Log::info('WhatsApp: status for unknown wamid (sent from another environment?)', [
                    'wa_message_id' => $waMessageId,
                    'status'        => $newStatus,
                    'recipient'     => $status['recipient_id'] ?? null,
                    'errors'        => $status['errors'] ?? null,
                ]);
                return;
            }

            $statusMap = [
                'sent' => 'sent',
                'delivered' => 'delivered',
                'read' => 'read',
                'failed' => 'failed',
            ];

            $mappedStatus = $statusMap[$newStatus] ?? null;
            if (!$mappedStatus) {
                return;
            }

            $updates = ['status' => $mappedStatus, 'status_updated_at' => now()];

            if ($mappedStatus === 'failed') {
                $errors = $status['errors'] ?? [];
                if (!empty($errors)) {
                    $updates['error_code'] = $errors[0]['code'] ?? null;
                    $updates['error_message'] = $errors[0]['title'] ?? ($errors[0]['message'] ?? null);
                }
                Log::error('WhatsApp: Message delivery failed', [
                    'wa_message_id' => $waMessageId,
                    'conversation_id' => $message->conversation_id,
                    'error_code' => $updates['error_code'] ?? null,
                    'error_message' => $updates['error_message'] ?? null,
                    'errors' => $errors,
                ]);
            }

            $message->update($updates);

            // Jul-2026 — mirror the receipt onto the campaign recipient row.
            // Meta has always reported delivered/read/failed here, but campaigns
            // could not see any of it: t_crm_campaign_customers had no link to
            // the message. Now that the send stores the wamid, one cheap indexed
            // update gives every campaign a real delivery funnel.
            $this->stampCampaignReceipt($waMessageId, $mappedStatus, $updates['error_message'] ?? null);

        } catch (\Exception $e) {
            Log::error('WhatsApp: Failed to process status update', [
                'error' => $e->getMessage(),
                'status_data' => $status,
            ]);
        }
    }

    /**
     * Copy a Meta delivery receipt onto the campaign row that sent this message.
     *
     * Semantics that matter:
     *  - A 'read' receipt also proves delivery, so it back-fills delivered_at
     *    when the delivered webhook was missed or arrived out of order.
     *  - Timestamps are only ever set once (whereNull), so a later duplicate
     *    webhook can't move the story backwards.
     *  - A 'failed' status here is a POST-ACCEPTANCE delivery failure (dead or
     *    blocked number). It does NOT flip the row to status='failed' — that
     *    value means "the send API call was rejected" and drives the retry
     *    button. Retrying a number WhatsApp says doesn't exist is pointless, so
     *    these land in their own Undelivered view instead.
     *
     * Non-fatal by design: a webhook must never 500 because of campaign
     * bookkeeping.
     */
    protected function stampCampaignReceipt(string $waMessageId, string $mappedStatus, ?string $errorMessage = null): void
    {
        if (!in_array($mappedStatus, ['delivered', 'read', 'failed'], true)) {
            return;
        }

        try {
            if (!\Illuminate\Support\Facades\Schema::hasColumn('t_crm_campaign_customers', 'wa_message_id')) {
                return; // migration not applied on this instance yet
            }

            $base = fn() => \DB::table('t_crm_campaign_customers')->where('wa_message_id', $waMessageId);

            if ($mappedStatus === 'delivered') {
                $base()->whereNull('delivered_at')->update(['delivered_at' => now()]);
                return;
            }

            if ($mappedStatus === 'read') {
                $base()->whereNull('read_at')->update(['read_at' => now()]);
                $base()->whereNull('delivered_at')->update(['delivered_at' => now()]);
                return;
            }

            // failed
            $payload = ['undelivered_at' => now()];
            if ($errorMessage) {
                $payload['error_message'] = mb_substr('Undelivered: ' . $errorMessage, 0, 500);
            }
            $base()->whereNull('undelivered_at')->update($payload);

        } catch (\Exception $e) {
            Log::debug('WhatsApp: campaign receipt stamp failed (non-fatal)', [
                'wa_message_id' => $waMessageId,
                'status' => $mappedStatus,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * For a customer who just sent us an inbound message, stamp replied_at
     * on any t_crm_campaign_customers rows where:
     *   - this customer was sent a campaign message (status='sent')
     *   - replied_at is still null
     *   - now() is within the campaign's tracking_window_days after sent_at
     * This lets the campaign stats page compute reply rates.
     */
    protected function stampCampaignReplies(int $customerId): void
    {
        $now = now();

        $rows = \DB::table('t_crm_campaign_customers as cc')
            ->join('t_crm_campaigns as c', 'cc.campaign_id', '=', 'c.id')
            ->where('cc.customer_id', $customerId)
            ->where('cc.status', 'sent')
            ->whereNull('cc.replied_at')
            ->whereNotNull('cc.sent_at')
            ->whereRaw('DATE_ADD(cc.sent_at, INTERVAL c.tracking_window_days DAY) >= ?', [$now])
            ->select('cc.id')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        \DB::table('t_crm_campaign_customers')
            ->whereIn('id', $rows->pluck('id')->all())
            ->update(['replied_at' => $now]);
    }

    /**
     * Find existing conversation or create a new one by phone number
     */
    public function findOrCreateConversation(string $waPhone, ?string $contactName = null): ConversationModel
    {
        // Normalize: ensure phone starts with country code, no +
        $waPhone = ltrim($waPhone, '+');

        $conversation = ConversationModel::where('wa_phone', $waPhone)->first();

        if ($conversation) {
            if ($contactName && !$conversation->wa_contact_name) {
                $conversation->update(['wa_contact_name' => $contactName]);
            }
            return $conversation;
        }

        // Try to match to an existing customer by phone
        $normalizedPhone = substr($waPhone, -10); // Last 10 digits (Pakistan format)
        $customer = \App\Models\CRM\CustomerModel::where('phone_normalized', $normalizedPhone)
            ->whereNull('merged_into_customer_id')
            ->first();

        return ConversationModel::create([
            'customer_id' => $customer?->id,
            'wa_phone' => $waPhone,
            'wa_contact_name' => $contactName,
            'status' => 'active',
            'last_message_at' => now(),
            'last_customer_message_at' => now(),
            'unread_count' => 0,
        ]);
    }

    /**
     * Try to link a customer to a conversation that currently has no linked customer.
     * Looks up the customer by the conversation's phone (last 10 digits, ignoring merged ones).
     * Safe to call repeatedly — it only updates if a match is found and conversation is unlinked.
     *
     * Returns the customer_id that was linked, or null if nothing changed.
     */
    public function relinkConversationCustomer(ConversationModel $conversation): ?int
    {
        if ($conversation->customer_id) {
            return $conversation->customer_id;
        }
        if (!$conversation->wa_phone) {
            return null;
        }

        try {
            $normalizedPhone = substr(ltrim($conversation->wa_phone, '+'), -10);
            // Prefer the most recently updated unmerged customer matching this phone,
            // so newly-created customers (which is the common case for late-link) win.
            $customer = \App\Models\CRM\CustomerModel::where('phone_normalized', $normalizedPhone)
                ->whereNull('merged_into_customer_id')
                ->orderByDesc('updated_at')
                ->first();

            if ($customer) {
                $conversation->update(['customer_id' => $customer->id]);
                Log::info('WhatsApp: Auto-linked conversation to customer', [
                    'conversation_id' => $conversation->id,
                    'wa_phone' => $conversation->wa_phone,
                    'customer_id' => $customer->id,
                ]);
                return $customer->id;
            }
        } catch (\Exception $e) {
            Log::debug('WhatsApp: relinkConversationCustomer failed', [
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Save an outbound message to the database after sending
     */
    /**
     * Work out which order_number an outbound template send should be stamped
     * with. Callers may pass it explicitly (`related_order_number`) or imply it
     * with `order_id`.
     *
     * WHY BOTH: `order_id` on the send endpoints also triggers the invoice-image
     * auto-attach. A template with no media header (e.g.
     * delivery_confirmation_online) is REJECTED by Meta when a header component
     * is attached, so a caller that only wants send history — not an image —
     * must be able to say so without passing order_id. Hence the explicit field.
     *
     * Returns null when neither is usable; never throws.
     */
    public function resolveRelatedOrderNumber(?string $explicit, $orderId = null): ?string
    {
        $explicit = is_string($explicit) ? trim($explicit) : '';
        if ($explicit !== '') {
            return $explicit;
        }

        if (!$orderId) {
            return null;
        }

        try {
            $num = \App\Models\CRM\OrderModel::whereKey((int) $orderId)->value('order_number');
            return $num !== null && $num !== '' ? (string) $num : null;
        } catch (\Throwable $e) {
            // History stamping is decoration — never cost the caller its send.
            return null;
        }
    }

    public function saveOutboundMessage(int $conversationId, array $apiResponse, string $type, string $content, ?int $sentBy = null, ?string $templateName = null, ?array $templateParams = null, bool $forcedDedupOverride = false, ?string $relatedOrderNumber = null, ?array $relatedOrderNumbers = null): ?MessageModel
    {
        $waMessageId = $apiResponse['messages'][0]['id'] ?? null;

        if (!$waMessageId) {
            return null;
        }

        $payload = [
            'conversation_id' => $conversationId,
            'wa_message_id' => $waMessageId,
            'direction' => 'outbound',
            'type' => $type,
            'content' => $content,
            'template_name' => $templateName,
            'template_params' => $templateParams ? json_encode($templateParams) : null,
            'status' => 'sent',
            'sent_by' => $sentBy,
            'created_at' => now(),
        ];

        // Audit column added by add_marketing_dedup_apr2026.sql. Only stamp
        // the flag when (a) the column actually exists in this DB instance
        // (so we degrade gracefully on databases that haven't run the
        // migration yet) and (b) the operator actually overrode the rule —
        // we don't want every outbound row carrying a redundant 0.
        if ($forcedDedupOverride && \Illuminate\Support\Facades\Schema::hasColumn('t_wa_messages', 'forced_dedup_override')) {
            $payload['forced_dedup_override'] = 1;
        }

        // Aug-2026: stamp the order this message is ABOUT so send history can be
        // read back per order (the Daily Closing follow-up panel counts reminders
        // this way). Invoice sends already stamped it by direct assignment after
        // the fact; template sends never did, so the column was NULL on every
        // reminder ever sent. Same hasColumn guard as above for DBs that predate
        // the column. Callers pass order_number (matches how every existing
        // reader joins it), never the id.
        if ($relatedOrderNumber !== null && $relatedOrderNumber !== ''
            && \Illuminate\Support\Facades\Schema::hasColumn('t_wa_messages', 'related_order_number')) {
            $payload['related_order_number'] = $relatedOrderNumber;
        }

        // Sep-2026: ONE message can be about SEVERAL orders — the multi-invoice
        // payment reminder covers every unpaid bill it names. related_order_number
        // is a single varchar and can only hold the primary, so the full list
        // goes to metadata, which is already a JSON column and needs no schema
        // change. OnlineFollowUpService::mergeMultiInvoiceHistory reads it back
        // so a customer's other bills are not counted as "never reminded".
        if (!empty($relatedOrderNumbers)
            && \Illuminate\Support\Facades\Schema::hasColumn('t_wa_messages', 'metadata')) {
            $clean = array_values(array_unique(array_filter(
                array_map(fn ($n) => is_string($n) ? trim($n) : '', $relatedOrderNumbers),
                fn ($n) => $n !== ''
            )));

            // A single-order send gains nothing from the list — related_order_number
            // already says it, and an indexed column beats a JSON scan.
            if (count($clean) > 1) {
                $payload['metadata'] = json_encode(['related_order_numbers' => $clean]);
            }
        }

        $message = MessageModel::create($payload);

        // Update conversation
        ConversationModel::where('id', $conversationId)->update([
            'last_message_at' => now(),
        ]);

        // When a qurbani-flagged template is sent, the conversation immediately
        // qualifies for the Qurbani tab. Re-classify so the UI picks it up on
        // the very next poll.
        if ($type === 'template' && $templateName) {
            try {
                $conv = ConversationModel::find($conversationId);
                if ($conv) {
                    app(\App\Services\QurbaniClassifier::class)->classify($conv);
                }
            } catch (\Exception $e) {
                Log::debug('WhatsApp: Qurbani re-classify on outbound failed (non-fatal)', ['error' => $e->getMessage()]);
            }

            // A template sent BY HAND counts as that campaign's message too.
            $this->adoptManualSendIntoCampaigns($conversationId, $templateName, $waMessageId, $sentBy);
        }

        return $message;
    }

    /**
     * When a campaign's template is sent outside the campaign sender, credit the
     * campaign with it.
     *
     * Placed on saveOutboundMessage() because that is the single choke point
     * every outbound message passes through — chat window (web and mobile),
     * automations, the campaign sender itself. Hooking the chat controller alone
     * would have missed the other paths.
     *
     * The gap this closes: you open a customer's chat and send them the app-launch
     * template by hand. They are also sitting in "app launch wave 1" as Pending.
     * Before this, they stayed Pending — and the next campaign send moved them to
     * Excluded, because the dedup guard could see the manual send. So a person who
     * genuinely received the message counted as neither reached nor converted, and
     * the campaign under-reported itself.
     *
     * Now the row is marked sent with the REAL wamid, so the Meta delivery/read
     * receipts for that manual message flow into the campaign funnel exactly as
     * they would for a campaign send, and any order they place attributes normally.
     *
     * Deliberate boundaries:
     *  - Only PENDING/SENDING rows are touched. A row already 'sent' is left alone
     *    (this is what makes the campaign sender's own call a no-op), and rows the
     *    operator skipped or that dedup excluded are NOT resurrected — changing a
     *    deliberate exclusion behind the operator's back would be worse than the
     *    under-count.
     *  - Only ACTIVE campaigns. An ended campaign's numbers stay frozen.
     *  - Every campaign using that template is credited, since the customer did
     *    receive it.
     *
     * Non-fatal throughout: campaign bookkeeping must never break a send.
     */
    protected function adoptManualSendIntoCampaigns(int $conversationId, string $templateName, ?string $waMessageId, ?int $sentBy): void
    {
        try {
            $customerId = ConversationModel::where('id', $conversationId)->value('customer_id');
            if (!$customerId) return;   // unlinked chat — nothing to credit

            $rows = \DB::table('t_crm_campaign_customers as cc')
                ->join('t_crm_campaigns as c', 'c.id', '=', 'cc.campaign_id')
                ->where('cc.customer_id', $customerId)
                ->whereIn('cc.status', ['pending', 'sending'])
                ->where('c.status', 'active')
                ->where('c.wa_template_name', $templateName)
                ->select('cc.id', 'cc.campaign_id')
                ->get();

            if ($rows->isEmpty()) return;

            $payload = [
                'status'         => 'sent',
                'sent_at'        => now(),
                'sent_by'        => $sentBy,
                'wa_message_id'  => $waMessageId,
                'claimed_at'     => null,
                'error_message'  => null,
                // A fresh send starts a fresh delivery story.
                'delivered_at'   => null,
                'read_at'        => null,
                'undelivered_at' => null,
            ];
            if (\Illuminate\Support\Facades\Schema::hasColumn('t_crm_campaign_customers', 'sent_via')) {
                $payload['sent_via'] = 'manual';
            }

            \DB::table('t_crm_campaign_customers')
                ->whereIn('id', $rows->pluck('id')->all())
                ->update($payload);

            // Keep the denormalised counter honest, the same way the campaign
            // sender does — recomputed, not incremented, so it cannot drift.
            foreach ($rows->pluck('campaign_id')->unique() as $campaignId) {
                \DB::table('t_crm_campaigns')->where('id', $campaignId)->update([
                    'sent_count' => \DB::raw('(SELECT COUNT(*) FROM t_crm_campaign_customers WHERE campaign_id = ' . (int) $campaignId . " AND status = 'sent')"),
                    'updated_at' => now(),
                ]);
            }

            Log::info('Campaign: manual template send adopted', [
                'customer_id' => $customerId,
                'template'    => $templateName,
                'campaigns'   => $rows->pluck('campaign_id')->unique()->values()->all(),
                'rows'        => $rows->count(),
            ]);

        } catch (\Throwable $e) {
            Log::debug('Campaign: manual-send adoption failed (non-fatal)', [
                'template' => $templateName,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Called when a staff member opens/polls a conversation in the UI.
     * Sends read receipts (blue ticks) to the customer for ALL inbound
     * messages we haven't already marked as read.
     *
     * Unlike the old behaviour (firing on webhook receipt), this only
     * happens when a human actually sees the thread. Safe to call repeatedly;
     * we track which WhatsApp message IDs have been reported via the
     * `read_reported_at` column on t_wa_messages.
     */
    public function markInboundAsReadOnWhatsApp(int $conversationId): int
    {
        $reported = 0;

        $hasReportedAt = \Illuminate\Support\Facades\Schema::hasColumn('t_wa_messages', 'read_reported_at');

        $query = MessageModel::where('conversation_id', $conversationId)
            ->where('direction', 'inbound')
            ->whereNotNull('wa_message_id');

        // Only consider columns that exist (new install vs. existing upgrade).
        if ($hasReportedAt) {
            $query->whereNull('read_reported_at');
        } else {
            // Fallback: if column doesn't exist yet, cap lookback to the last
            // 50 inbound messages to avoid hammering the API after a big
            // backlog. Once the migration runs, this branch won't be hit.
            $query->orderByDesc('id')->limit(50);
        }

        $messages = $query->get(['id', 'wa_message_id']);
        if ($messages->isEmpty()) {
            return 0;
        }

        // May-2026 — Cache-based dead-wamid sentinel.
        // EVEN with the read_reported_at column missing (legacy installs
        // that haven't run the May-2026 migration yet) we need to stop
        // hammering Meta's API with the same dead wamids every minute.
        // Strategy: every wamid that gets a 400 "Message ID does not
        // exist" goes into Cache with a 7-day TTL (Meta's wamid
        // retention is ~7 days, so after that the situation can't
        // recover anyway). Subsequent polls short-circuit the API call.
        //
        // This complements (does NOT replace) the DB column — once the
        // migration runs, the whereNull filter culls them server-side
        // and this Cache check becomes a no-op for stamped messages.
        $deadKey = fn(string $wamid) => 'wa_dead_wamid:' . md5($wamid);

        foreach ($messages as $m) {
            // Skip wamids we already know are dead (per process-wide
            // Cache). This is the line that actually fixes the user's
            // observed loop when the column hasn't been migrated yet.
            if (\Illuminate\Support\Facades\Cache::has($deadKey($m->wa_message_id))) {
                continue;
            }

            // May-2026 — ALWAYS stamp read_reported_at after an attempt,
            // regardless of API outcome. The old code only stamped on
            // exception or success — but WhatsAppService::sendRequest()
            // doesn't throw, it returns ['success' => false, ...].
            // So a "Message ID does not exist" 400 (common for test
            // phone wamids from earlier WABA contexts, or messages
            // >7 days old) silently looped forever: every conversation
            // open re-tried the same 9 dead wamids, spamming Meta and
            // filling the error log with (#100) Invalid parameter
            // messages every minute. Stamping on failure = give up
            // after one try, which is the correct policy: Meta won't
            // suddenly accept a wamid it just said doesn't exist.
            $apiOk = false;
            $errMsg = null;
            try {
                $result = $this->markAsRead($m->wa_message_id);
                $apiOk = !empty($result['success']);
                if (!$apiOk) {
                    $errMsg = $result['error'] ?? 'unknown';
                }
            } catch (\Exception $e) {
                $errMsg = $e->getMessage();
            }

            if ($hasReportedAt) {
                MessageModel::where('id', $m->id)->update(['read_reported_at' => now()]);
            }

            if ($apiOk) {
                $reported++;
            } else {
                // Mirror the DB stamp into Cache for 7 days so legacy
                // installs without the column also stop retrying.
                \Illuminate\Support\Facades\Cache::put($deadKey($m->wa_message_id), 1, now()->addDays(7));
                Log::debug('WhatsApp: markAsRead failed (stamped to skip future retries)', [
                    'wa_message_id' => $m->wa_message_id,
                    'error'         => $errMsg,
                    'db_stamped'    => $hasReportedAt,
                    'cache_stamped' => true,
                ]);
            }
        }

        return $reported;
    }

    /**
     * Check if a conversation's 24-hour window is still open
     */
    public function isSessionActive(ConversationModel $conversation): bool
    {
        // Same dev-only override as the model's copy — this service method
        // gates the actual send endpoints, so both must agree or the composer
        // opens and then the send is refused. Never active in production.
        if (ConversationModel::devSessionOverride($conversation->wa_phone)) {
            return true;
        }

        if (!$conversation->last_customer_message_at) {
            return false;
        }

        return $conversation->last_customer_message_at->diffInHours(now()) < 24;
    }

    /**
     * Returns true when the given user can mark a conversation read for
     * EVERYONE (not just themselves). Granted via the
     * `whatsapp_super_reader` mobile permission — assigned to the Taimur
     * role by the labels/super-reader migration. Used by markRead /
     * markUnread to decide whether to stamp the conversation's
     * `global_read_at` column.
     *
     * Accepts either a User instance or null (e.g. unauthenticated web
     * request) and returns false for null.
     */
    public function isSuperReader($user): bool
    {
        if (!$user) {
            return false;
        }
        // hasMobilePermission exists on both web and store users; it
        // walks the role → t_sys_role_mobile_permission link. Safe to
        // call even if the permission row doesn't exist yet (returns false).
        try {
            return method_exists($user, 'hasMobilePermission')
                && $user->hasMobilePermission('whatsapp_super_reader');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Returns true when the given user is allowed to bypass the
     * "marketing template already sent recently" guard via the
     * "Send Anyway" prompt. Granted via the
     * `whatsapp_marketing_dedup_override` mobile permission, seeded for
     * Taimur, Management, and Admin roles by the marketing-dedup migration.
     *
     * Without this permission the chat UI shows a hard block message and
     * the server-side guard rejects the send with HTTP 409 even when
     * `force=true` is passed (so an old or scripted client cannot bypass
     * the rule). Always returns false for null users (unauthenticated
     * web requests).
     */
    public function canOverrideMarketingDedup($user): bool
    {
        if (!$user) {
            return false;
        }
        try {
            return method_exists($user, 'hasMobilePermission')
                && $user->hasMobilePermission('whatsapp_marketing_dedup_override');
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Read the global "marketing template dedup window" (in days) from
     * `t_fin_config`. Cached for a single request via a static so the
     * settings lookup doesn't fire on every send-time check.
     *
     * Returns 0 when the setting is missing/blank/explicitly zero, which
     * the caller treats as "feature disabled" (no pre-flight check, no
     * server-side guard, no pinned indicator).
     */
    public function getMarketingDedupWindowDays(): int
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        try {
            $value = \Illuminate\Support\Facades\DB::table('t_fin_config')
                ->where('config_key', 'wa_marketing_dedup_window_days')
                ->value('config_value');
            $cached = max(0, (int) ($value ?? 0));
        } catch (\Exception $e) {
            $cached = 0;
        }
        return $cached;
    }

    // =========================================================================
    // Auto-Reply (Apr-2026)
    // =========================================================================

    /**
     * Read the global auto-reply master switch. Returns false when the
     * setting row is missing, blank, or "0" — anything else is treated as
     * enabled. Cached per-request so the inbound webhook doesn't hit the
     * config table N times when a customer sends a burst of messages.
     */
    public function isAutoReplyEnabled(): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }
        try {
            $value = \Illuminate\Support\Facades\DB::table('t_fin_config')
                ->where('config_key', 'wa_auto_reply_enabled')
                ->value('config_value');
            $cached = !empty($value) && $value !== '0' && strtolower((string) $value) !== 'false';
        } catch (\Exception $e) {
            $cached = false;
        }
        return $cached;
    }

    /**
     * Decide whether the inbound on this conversation should trigger an
     * auto-reply, and if so, send + record it.
     *
     * The flow is intentionally defensive — every short-circuit is a
     * deliberate "don't reply" branch documented in line comments below.
     * We do NOT throw on any failure path; the caller wraps us in try/catch
     * but we'd rather skip silently and let the inbound message still be
     * persisted normally.
     *
     * @param mixed  $conversation The Eloquent ConversationModel for the inbound.
     * @param string $from         E164-normalized phone number we send back to.
     * @param string $type         Inbound message type ('text', 'image', etc.).
     *                             We don't filter on this today, but it's
     *                             passed through so future rules can opt out
     *                             of auto-replying to e.g. 'reaction' pings.
     */
    protected function maybeSendAutoReply($conversation, string $from, string $type): void
    {
        if (!$this->isAutoReplyEnabled()) {
            return;
        }

        // Reactions are conversational noise (👍, ❤). Auto-replying to them
        // creates an awkward feedback loop and burns Meta session credits
        // for no value, so we skip them outright.
        if ($type === 'reaction') {
            return;
        }

        if (!\Illuminate\Support\Facades\Schema::hasTable('t_wa_auto_replies')) {
            // Migration hasn't been run yet — fail closed.
            return;
        }

        // Match the first enabled rule (lowest priority number wins).
        $rule = $this->findMatchingAutoReplyRule(now());
        if (!$rule) {
            return;
        }

        // Per-conversation throttle so a customer is never pelted with the same
        // auto-reply (Jun-2026 fix). THREE modes — see cooldown_mode on the rule:
        //   - 'daily'   : at most one auto-reply to this conversation per
        //                 calendar day. The safe default for out-of-hours /
        //                 holiday rules and the fix for the "same message every
        //                 few hours across a multi-day holiday" complaint.
        //   - 'rolling' : at most one auto-reply every N hours. Legacy behaviour;
        //                 N is floored at 1 so a mis-set 0 can NEVER disable the
        //                 throttle (the old code skipped the throttle entirely
        //                 when cooldown_hours was 0).
        //   - 'once'    : at most one auto-reply for THIS rule per active period
        //                 (since active_from, else ever) — e.g. a single holiday
        //                 greeting per customer.
        // 'daily'/'rolling' look across ALL rules (stacked rules can't double
        // send); 'once' is scoped to this rule. Defaults to 'daily' when the
        // column is absent, so behaviour is sane even before the SQL is run.
        if (\Illuminate\Support\Facades\Schema::hasColumn('t_wa_messages', 'auto_reply_rule_id')) {
            $mode = $rule->cooldown_mode ?? 'daily';
            $throttleQ = \Illuminate\Support\Facades\DB::table('t_wa_messages')
                ->where('conversation_id', $conversation->id);

            if ($mode === 'once') {
                $throttleQ->where('auto_reply_rule_id', (int) $rule->id);
                if (!empty($rule->active_from)) {
                    $throttleQ->where('created_at', '>=', \Carbon\Carbon::parse($rule->active_from)->startOfDay());
                }
            } elseif ($mode === 'rolling') {
                $hours = max(1, (int) ($rule->cooldown_hours ?? 6));
                $throttleQ->whereNotNull('auto_reply_rule_id')
                          ->where('created_at', '>=', now()->subHours($hours));
            } else { // 'daily' (default)
                $throttleQ->whereNotNull('auto_reply_rule_id')
                          ->where('created_at', '>=', now()->startOfDay());
            }

            if ($throttleQ->exists()) {
                Log::debug('WhatsApp auto-reply: throttled, skipping', [
                    'conversation_id' => $conversation->id,
                    'rule_id'         => $rule->id,
                    'cooldown_mode'   => $mode,
                ]);
                return;
            }
        }

        // Send the WhatsApp text. If the API rejects it (e.g. the 24h window
        // expired between the inbound landing on our webhook and us replying)
        // we log and bail — we never store a failed auto-reply row because
        // the cooldown would then suppress legitimate retries on the next
        // inbound.
        try {
            $resp = $this->sendTextMessage($from, (string) $rule->message);
        } catch (\Exception $e) {
            Log::warning('WhatsApp auto-reply: send failed', [
                'conversation_id' => $conversation->id,
                'rule_id'         => $rule->id,
                'error'           => $e->getMessage(),
            ]);
            return;
        }

        if (empty($resp['messages'][0]['id'])) {
            Log::warning('WhatsApp auto-reply: API returned no message id', [
                'conversation_id' => $conversation->id,
                'rule_id'         => $rule->id,
                'response'        => $resp,
            ]);
            return;
        }

        $waMessageId = $resp['messages'][0]['id'];
        $payload = [
            'conversation_id' => $conversation->id,
            'wa_message_id'   => $waMessageId,
            'direction'       => 'outbound',
            'type'            => 'text',
            'content'         => (string) $rule->message,
            'status'          => 'sent',
            'sent_by'         => null, // System send.
            'created_at'      => now(),
        ];
        if (\Illuminate\Support\Facades\Schema::hasColumn('t_wa_messages', 'auto_reply_rule_id')) {
            $payload['auto_reply_rule_id'] = (int) $rule->id;
        }

        try {
            MessageModel::create($payload);
            // IMPORTANT: only `last_message_at` is touched here. We
            // deliberately do NOT modify any of the read-state fields:
            //   - t_wa_conversations.unread_count          (legacy fallback)
            //   - t_wa_conversation_reads.last_read_at     (per-user read state)
            //   - t_wa_conversation_reads.forced_unread_at (manual "mark unread")
            // ...so the customer's inbound message stays unread for the staff
            // user even after we auto-respond. Otherwise an out-of-hours reply
            // would silently clear the unread badge and the operator would
            // miss real customer requests in the morning. WhatsApp's blue
            // ticks (read receipts) are also untouched — those only fire
            // from markConversationReadForUser() which is called when a
            // human actually opens the chat.
            ConversationModel::where('id', $conversation->id)->update(['last_message_at' => now()]);
        } catch (\Exception $e) {
            Log::warning('WhatsApp auto-reply: failed to persist outbound row', [
                'conversation_id' => $conversation->id,
                'rule_id'         => $rule->id,
                'error'           => $e->getMessage(),
            ]);
        }
    }

    /**
     * Return the single auto-reply rule that should fire at $now, or null.
     * Public so the settings UI can preview "what would fire right now"
     * without sending anything.
     *
     * Evaluation order:
     *   1. enabled = 1
     *   2. ORDER BY priority ASC, id ASC (deterministic on ties)
     *   3. First rule whose match_mode + criteria accept $now wins.
     */
    public function findMatchingAutoReplyRule(\Carbon\Carbon $now)
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('t_wa_auto_replies')) {
            return null;
        }
        $rules = \Illuminate\Support\Facades\DB::table('t_wa_auto_replies')
            ->where('enabled', 1)
            ->orderBy('priority', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        foreach ($rules as $rule) {
            if ($this->autoReplyRuleMatches($rule, $now)) {
                return $rule;
            }
        }
        return null;
    }

    /**
     * Test a single rule row against $now. Pulled out so unit tests / the
     * settings UI can call it directly without standing up the inbox.
     *
     * Important: the rule row is an stdClass from a raw DB::table() query,
     * so we read fields with the array-like ?? null pattern to tolerate
     * older schemas.
     */
    public function autoReplyRuleMatches($rule, \Carbon\Carbon $now): bool
    {
        // Universal active-window gate (Jun-2026) — applies to EVERY match_mode.
        // Lets a holiday rule auto-expire instead of firing forever, which was
        // the root cause of an "always" Eid rule replying every day until it
        // was manually disabled. Dates are inclusive; NULL = unbounded on that
        // side. substr(0,10) tolerates either DATE or DATETIME storage.
        $today = $now->toDateString();
        $from = !empty($rule->active_from) ? substr((string) $rule->active_from, 0, 10) : null;
        $to   = !empty($rule->active_to)   ? substr((string) $rule->active_to,   0, 10) : null;
        if ($from && $today < $from) {
            return false;
        }
        if ($to && $today > $to) {
            return false;
        }

        $mode = $rule->match_mode ?? 'time_window';

        if ($mode === 'always') {
            return true;
        }

        if ($mode === 'specific_dates') {
            $list = $this->parseAutoReplyDateList($rule->specific_dates ?? null);
            return in_array($now->format('Y-m-d'), $list, true);
        }

        // time_window (default).
        $days = $this->parseAutoReplyDaysOfWeek($rule->days_of_week ?? null);
        if (!empty($days) && !in_array((int) $now->dayOfWeek, $days, true)) {
            return false;
        }

        $start = $rule->start_time ?? null;
        $end   = $rule->end_time ?? null;

        // Both null → match every minute of the selected days (acts like a
        // "whole-day rule, restricted to these weekdays").
        if (!$start && !$end) {
            return true;
        }
        if (!$start || !$end) {
            // Half-configured row — treat as non-matching to avoid surprises.
            return false;
        }

        $current = $now->format('H:i:s');
        if ($start <= $end) {
            // Same-day window, e.g. 09:00 → 18:00 (replies fire OUTSIDE
            // working hours, so the rule is typically the inverse: 18:00 →
            // 09:00 next day, which is the cross-midnight branch below).
            return ($current >= $start && $current < $end);
        }
        // Cross-midnight window, e.g. 18:00 → 09:00.
        return ($current >= $start || $current < $end);
    }

    /**
     * Days_of_week is stored as a CSV of 0..6 ints (0=Sun..6=Sat). Parse
     * tolerantly: blank means "every day", garbage entries are dropped.
     */
    protected function parseAutoReplyDaysOfWeek($raw): array
    {
        if ($raw === null || $raw === '') {
            return []; // Empty = no restriction.
        }
        $out = [];
        foreach (explode(',', (string) $raw) as $piece) {
            $piece = trim($piece);
            if ($piece === '' || !ctype_digit($piece)) {
                continue;
            }
            $n = (int) $piece;
            if ($n >= 0 && $n <= 6) {
                $out[] = $n;
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Specific_dates is stored as a JSON array of YYYY-MM-DD strings. Parse
     * tolerantly: invalid JSON → empty list (no match) so a typo in the
     * settings UI never blocks all auto-replies.
     */
    protected function parseAutoReplyDateList($raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $d) {
            if (!is_string($d)) {
                continue;
            }
            $d = trim($d);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
                $out[] = $d;
            }
        }
        return $out;
    }

    /**
     * Server-side guard for the "marketing template already sent recently"
     * rule. Called from every outbound-template entry point (web + mobile)
     * BEFORE the actual send, so an old client or scripted request cannot
     * bypass the dedup by simply not running the pre-flight UI check.
     *
     * Returns:
     *   - null  → OK to send (template is utility/auth, feature disabled,
     *             no recent send found, OR override granted to the user).
     *   - array → block / require explicit confirmation. The caller should
     *             return HTTP 409 with this payload as the JSON body so
     *             the chat UI can show the confirm dialog.
     *
     * Override semantics:
     *   - $force = true  AND user has whatsapp_marketing_dedup_override →
     *              recorded as override on the outbound row, send proceeds.
     *   - $force = true  but user lacks the permission → still blocked
     *              (the UI hides the "Send Anyway" button for these users
     *              but we double-guard server-side).
     *   - $force = false AND there is a recent send → blocked with the
     *              UI confirm payload.
     *
     * @param int|null    $conversationId The conversation we're sending into.
     *                                    May be null when the caller only
     *                                    has a phone number (e.g. one-off
     *                                    sendInvoice flows); we'll resolve
     *                                    or create the conversation first.
     * @param string|null $phone          Used as a fallback to resolve the
     *                                    conversation when $conversationId
     *                                    is null. Already E164-formatted.
     * @param string      $templateName   Cloud API template key, NOT the
     *                                    display name.
     * @param mixed       $user           The auth user. May be null for
     *                                    unauthenticated requests, in
     *                                    which case override is impossible.
     * @param bool        $force          User explicitly chose Send Anyway.
     */
    public function enforceMarketingDedup(?int $conversationId, ?string $phone, string $templateName, $user, bool $force): ?array
    {
        $windowDays = $this->getMarketingDedupWindowDays();
        if ($windowDays <= 0) {
            return null; // Feature disabled globally.
        }

        $filter = app(\App\Services\CampaignFilterService::class);
        if (!$filter->isMarketingTemplate($templateName)) {
            return null; // Utility / auth templates are never deduped.
        }

        // Resolve the conversation if the caller only had a phone.
        if ((!$conversationId || $conversationId <= 0) && $phone) {
            try {
                $conv = $this->findOrCreateConversation($phone);
                if ($conv) {
                    $conversationId = (int) $conv->id;
                }
            } catch (\Exception $e) {
                // If we can't resolve a conversation we can't dedup, so
                // fall through and let the send proceed — better to ship
                // a possibly-duplicate marketing template than to lose
                // the send entirely on a transient lookup failure.
                return null;
            }
        }
        if (!$conversationId || $conversationId <= 0) {
            return null;
        }

        $hit = $filter->recentMarketingTemplateSendForConversation(
            (int) $conversationId,
            $templateName,
            $windowDays
        );
        if (!$hit) {
            return null; // No recent matching send.
        }

        // Recent send found. Decide between block and override.
        $canOverride = $this->canOverrideMarketingDedup($user);

        if ($force && $canOverride) {
            return null; // Authorised override — let the send proceed.
        }

        return [
            'blocked'               => true,
            'reason'                => 'recent_marketing_send',
            'template_name'         => $hit['template_name'],
            'template_display_name' => $hit['template_display_name'],
            'sent_at'               => $hit['sent_at'],
            'sent_at_human'         => $hit['sent_at_human'],
            'window_days'           => $hit['window_days'],
            'can_override'          => $canOverride,
            'message'               => $canOverride
                ? sprintf(
                    'This customer was sent the marketing template "%s" %s. Send it again?',
                    $hit['template_display_name'],
                    $hit['sent_at_human']
                  )
                : sprintf(
                    'This customer was sent the marketing template "%s" %s. Re-sending the same marketing template within %d days is not allowed for your role.',
                    $hit['template_display_name'],
                    $hit['sent_at_human'],
                    $hit['window_days']
                  ),
        ];
    }

    /**
     * Format phone number for WhatsApp API (must be 92XXXXXXXXXX).
     *
     * Pakistan-only by design (last-10-digits rule): collapses every way staff
     * free-type a PK number (+92 345..., 0345..., 345..., 0092 345...) to
     * 92XXXXXXXXXX.
     *
     * Jul-2026: the early-return now requires EXACTLY 12 digits. A valid PK
     * E.164 number is exactly 92 + 10 digits; anything longer that happens to
     * start with "92" is half-normalized junk (e.g. a client formatter turned
     * "00923215793000" into "920923215793000") and previously sailed through
     * to Meta and failed 131026. Now it falls into the same last-10 rule and
     * self-heals to the correct 92XXXXXXXXXX.
     *
     * International numbers are NOT handled here on purpose — dial-time
     * resolution of known international customers lives in resolveDialPhone().
     */
    public function formatPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($digits, '92') && strlen($digits) === 12) {
            return $digits;
        }

        if (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        $digits = substr($digits, -10);

        return '92' . $digits;
    }

    /**
     * Resolve the number to actually DIAL for a customer-facing send.
     *
     * formatPhone() is a PK-only formatter, so it mangles the rare
     * international customer (971..., etc). Rather than teach it country
     * codes, this prefers a KNOWN-GOOD full number the system already holds:
     *
     *   1. An explicitly-passed conversation's wa_phone — that number came
     *      from Meta's own webhook, so it is proven deliverable.
     *   2. A conversation whose wa_phone ends in the same last-10 digits AND
     *      that has at least one INBOUND message. The inbound requirement is
     *      load-bearing: outbound-only conversations can be junk rows created
     *      by a past mangled send (e.g. wa_phone 920923215793000), and
     *      last_customer_message_at can NOT be used instead — it is seeded at
     *      creation time even for outbound-created conversations.
     *   3. The matched customer's phone_original, when it clearly carries a
     *      non-PK country code (11+ digits, no leading 0/92 after stripping a
     *      00 international prefix).
     *   4. Otherwise formatPhone()'s result — byte-for-byte today's behavior.
     *
     * For Pakistani numbers this is a NO-OP by construction: their proven
     * conversation number IS the formatPhone() output. The last-10 identity
     * always comes from the caller's input, so manually typing a different
     * number still redirects the send — this only fixes the country-code
     * interpretation, never the identity.
     *
     * FAIL-OPEN: any lookup error returns the formatted number (old behavior).
     */
    public function resolveDialPhone(string $phone, $conversation = null): string
    {
        $formatted = $this->formatPhone($phone);

        try {
            if ($conversation && !empty($conversation->wa_phone)) {
                return ltrim((string) $conversation->wa_phone, '+');
            }

            $digits = preg_replace('/\D/', '', $phone);
            $last10 = substr($digits, -10);
            if (strlen($last10) < 10) {
                return $formatted;
            }

            $candidates = \App\Models\WhatsApp\ConversationModel::where('wa_phone', 'like', '%' . $last10)
                ->orderBy('id')
                ->limit(10)
                ->get();
            foreach ($candidates as $cand) {
                if (empty($cand->wa_phone)) {
                    continue;
                }
                $hasInbound = \App\Models\WhatsApp\MessageModel::where('conversation_id', $cand->id)
                    ->where('direction', 'inbound')
                    ->exists();
                if ($hasInbound) {
                    return ltrim((string) $cand->wa_phone, '+');
                }
            }

            $customer = \App\Models\CRM\CustomerModel::where('phone_normalized', $last10)
                ->whereNull('merged_into_customer_id')
                ->first();
            if ($customer) {
                $orig = preg_replace('/\D/', '', (string) ($customer->phone_original ?? ''));
                if (str_starts_with($orig, '00')) {
                    $orig = substr($orig, 2);
                }
                if (strlen($orig) >= 11 && !str_starts_with($orig, '0') && !str_starts_with($orig, '92')) {
                    return $orig;
                }
            }
        } catch (\Throwable $e) {
            Log::debug('resolveDialPhone fell back to formatPhone', ['error' => $e->getMessage()]);
        }

        return $formatted;
    }

    /**
     * Positive-evidence check that a WhatsApp number CANNOT receive messages.
     * Used to warn/block a rider from converting a first-time regular customer's
     * order to online when our own sends to that number have bounced.
     *
     * Returns TRUE only with PROOF of undeliverability: at least one outbound
     * message to the number failed with Meta error 131026 ("message
     * undeliverable" — typically not on WhatsApp / can't receive) AND there is NO
     * counter-evidence of reachability (no inbound message ever, and no outbound
     * that reached delivered/read). No conversation / no history → FALSE — we
     * never treat "no data" as invalid. Fail-open (FALSE) on any error, so a
     * broken check can never wrongly block a conversion.
     */
    public function isNumberUndeliverable(?string $phone): bool
    {
        try {
            $digits = preg_replace('/\D/', '', (string) $phone);
            $last10 = substr($digits, -10);
            if (strlen($last10) < 10) {
                return false;
            }

            $conversationIds = ConversationModel::where('wa_phone', 'like', '%' . $last10)->pluck('id');
            if ($conversationIds->isEmpty()) {
                return false; // no conversation → no evidence either way
            }

            // Any proof the number DOES receive? (customer replied, or a send
            // reached delivered/read.) If so, never flag it.
            $reachable = MessageModel::whereIn('conversation_id', $conversationIds)
                ->where(function ($q) {
                    $q->where('direction', 'inbound')
                      ->orWhere(function ($q2) {
                          $q2->where('direction', 'outbound')->whereIn('status', ['delivered', 'read']);
                      });
                })
                ->exists();
            if ($reachable) {
                return false;
            }

            // Positive proof of undeliverability: an outbound send failed 131026.
            return MessageModel::whereIn('conversation_id', $conversationIds)
                ->where('direction', 'outbound')
                ->where('status', 'failed')
                ->where('error_code', '131026')
                ->exists();
        } catch (\Throwable $e) {
            Log::warning('isNumberUndeliverable check failed (fail-open)', ['error' => $e->getMessage()]);
            return false;
        }
    }

    protected function sendRequest(array $payload): array
    {
        try {
            $url = "{$this->apiBase}/{$this->phoneNumberId}/messages";

            $response = Http::withToken($this->accessToken)
                ->timeout(30)
                ->post($url, $payload);

            $data = $response->json();

            if (!$response->successful()) {
                Log::error('WhatsApp API error', [
                    'status' => $response->status(),
                    'response' => $data,
                    'payload_type' => $payload['type'] ?? 'unknown',
                ]);

                return [
                    'success' => false,
                    'error' => $data['error']['message'] ?? 'Unknown API error',
                    'error_code' => $data['error']['code'] ?? null,
                ];
            }

            // Log cost for tracking
            $this->logCost($payload, $data);

            return array_merge(['success' => true], $data);

        } catch (\Exception $e) {
            Log::error('WhatsApp API exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    protected function logCost(array $payload, array $response): void
    {
        try {
            $type = $payload['type'] ?? 'text';
            $templateName = $payload['template']['name'] ?? null;
            $to = $payload['to'] ?? null;

            // Jul-2026 — read the real category off the template instead of
            // calling every template send 'utility'. Marketing templates cost
            // materially more than utility ones, so the old blanket label made
            // campaign spend look far cheaper than it is. Falls back to
            // 'utility' only when the template genuinely isn't on file.
            $category = 'service';
            if ($type === 'template') {
                $category = 'utility';
                if ($templateName) {
                    try {
                        $tplCategory = \DB::table('t_wa_templates')
                            ->where('name', $templateName)
                            ->value('category');
                        if ($tplCategory) {
                            $category = strtolower((string) $tplCategory);
                        }
                    } catch (\Exception $e) {
                        // keep the 'utility' default — cost logging is best-effort
                    }
                }
            }

            \DB::table('t_wa_cost_log')->insert([
                'wa_phone' => $to,
                'message_type' => $type,
                'template_name' => $templateName,
                'category' => $category,
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            // Don't fail the message send just because cost logging failed
        }
    }

    protected function getExtensionFromMime(string $mimeType): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'audio/ogg' => 'ogg',
            'audio/ogg; codecs=opus' => 'ogg',
            'audio/mpeg' => 'mp3',
            'video/mp4' => 'mp4',
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        ];

        return $map[$mimeType] ?? 'bin';
    }
}
