<?php

declare(strict_types=1);

/*
 * Account-level sentences: what a country or currency change would touch and
 * what stops it. Composed when a request is answered, from codes the analyser
 * stored, so the same request reads in English or Arabic as asked. Counted
 * lines are pluralised here rather than in code.
 */

return [

    'country_currency_change' => [

        'blockers' => [
            'target_currency_not_priced' => 'Nothing is priced in :currency. The catalogue must carry prices in the new currency before an account can be billed in it.',
            'open_invoices' => '{1} 1 open invoice in :currency must be paid or voided first. An invoice is never converted.|[2,*] :count open invoices in :currency must be paid or voided first. An invoice is never converted.',
            'orders_in_flight' => '{1} 1 order is between placement and provisioning, priced in :currency. It must complete or be cancelled first.|[2,*] :count orders are between placement and provisioning, priced in :currency. They must complete or be cancelled first.',
            'domain_operations_in_flight' => '{1} 1 domain operation is with the registrar, quoted in :currency. It must finish first.|[2,*] :count domain operations are with the registrar, quoted in :currency. They must finish first.',
            'subscriptions_renew' => '{1} 1 subscription renews in :currency. A renewal is never silently repriced: end it, then order again from the :to_currency price list.|[2,*] :count subscriptions renew in :currency. A renewal is never silently repriced: end them, then order again from the :to_currency price list.',
            'wallet_holds_balance' => 'The wallet holds a balance in :currency. Credit is never exchanged; spend it or ask for a refund first.',
        ],

        'warnings' => [
            'history_keeps_currency' => 'Every invoice, payment and order already recorded stays in :currency. Only what is issued after the change is in :to_currency.',
            'tax_changes' => 'Invoices issued after the change carry the tax for the new country (:tax_after instead of :tax_before). Invoices already issued keep the tax they were issued with.',
            'open_invoices_keep_tax' => '{1} 1 open invoice keeps the tax it was issued with.|[2,*] :count open invoices keep the tax they were issued with.',
        ],

        'tax' => [
            'none' => 'no tax',
            'rate' => ':rate% :name',
            'unnamed' => 'tax',
        ],

    ],

];
