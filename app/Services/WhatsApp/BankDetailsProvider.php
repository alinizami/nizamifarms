<?php

namespace App\Services\WhatsApp;

use App\Models\FIN\ConfigModel;

/**
 * THE single source of truth for our receiving bank accounts in customer-facing
 * WhatsApp text (Aug-2026).
 *
 * Before this class the same accounts were hardcoded in THREE places that had
 * already drifted apart:
 *   - database/migrations/seed_wa_templates_register_jun2026.sql  (Alfalah + Meezan + Askari)
 *   - app/Http/Controllers/API/WhatsAppController::buildTemplateDisplayText  (HBL + Meezan)
 *   - resources/views/fin/employee/outstanding-invoices.blade.php fuBankDetailsMessage (HBL + Meezan)
 * Owner ruling (Aug-31-2026): the `invoice_paychange` set is the correct one —
 * Bank Alfalah / Meezan / Askari, NO HBL.
 * Owner ruling (Oct-11-2026): Askari REPLACED by HBL (PK35HABB0023297901934403,
 * title "Nizami Farms") — set is now Bank Alfalah / Meezan / HBL. The Meta
 * templates invoice_paychange / invoice_online / invoice_online_eta_2 were
 * edited on Meta by the owner to match.
 *
 * The text is overridable at runtime via the t_fin_config key below, so the
 * accounts can be corrected WITHOUT a code deploy (this app deploys by hand).
 * The constant is only the fallback when that key is absent or blank.
 *
 * NOTE ON META TEMPLATES: a template's approved body lives on Meta, not here.
 * This class is for text WE compose — the free-form reply sent when a customer
 * taps "Get bank details", and the wa.me manual fallback. Changing it does NOT
 * change an approved template.
 */
class BankDetailsProvider
{
    /** t_fin_config key holding the accounts block (blank/absent = use DEFAULT_ACCOUNTS). */
    const CONFIG_KEY = 'wa_bank_details_text';

    /**
     * Fallback accounts block. Kept byte-identical to the `invoice_paychange`
     * template body's account list so the two never disagree.
     */
    const DEFAULT_ACCOUNTS = <<<'TXT'
Account Title: "Nizami Meat"
* Bank: Bank Alfalah
* IBAN: PK87ALFH5866005002904343

Account Title: "Nizami Farms"
* Bank: Meezan Bank Limited
* IBAN: PK75MEZN0003050106554237

* Bank: Habib Bank Limited (HBL)
* IBAN: PK35HABB0023297901934403
TXT;

    /**
     * The accounts block alone (no greeting, no closing). Falls back to the
     * constant on ANY problem — this text is customer-facing and must never
     * render empty.
     */
    public static function accountsBlock(): string
    {
        try {
            $v = trim((string) ConfigModel::get(self::CONFIG_KEY, ''));
            if ($v !== '') {
                return $v;
            }
        } catch (\Throwable $e) {
            // Config unreadable — fall through to the constant.
        }
        return self::DEFAULT_ACCOUNTS;
    }

    /**
     * The full free-form message sent when a customer taps the "Get bank
     * details" quick-reply button on a delivery confirmation.
     *
     * $orderNumber is included when we could resolve which order the tap
     * answers, so a customer with two open invoices knows which one this is
     * for. It is omitted (not faked) when unresolvable.
     */
    public static function replyMessage(?string $orderNumber = null): string
    {
        $orderNumber = trim((string) $orderNumber);
        $intro = $orderNumber !== ''
            ? 'Here are our bank account details for order ' . $orderNumber . ':'
            : 'Here are our bank account details:';

        return $intro . "\n\n"
            . self::accountsBlock() . "\n\n"
            . 'Please share a screenshot of the transfer here once it has been made. '
            . 'Thank you for choosing Nizami Farms!';
    }
}
