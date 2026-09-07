<?php

declare(strict_types=1);

/*
 * Notification copy, one whole sentence per message per language.
 *
 * Never assembled from fragments. "Your " . $kind . " is ready" is a sentence
 * no translator can repair: Arabic orders the words differently and inflects
 * around them, so the pieces have no correct translation in isolation.
 *
 * Placeholders are named rather than positional for the same reason - the
 * amount and the invoice number appear in a different order in the two
 * languages, and :amount is the only form that survives being moved.
 *
 * Nested by group so that Laravel's dot lookup reaches them: a top-level key
 * containing a literal dot is unreachable, because the translator splits the
 * key it is given and never finds a match.
 */

return [

    'security' => [
        'password_changed' => [
            'title' => 'Your password was changed',
            'body' => 'The password for your Lynomia Cloud account was changed. If this was not you, reset it immediately and review your active sessions.',
        ],
        'two_factor_enabled' => [
            'title' => 'Two-factor authentication enabled',
            'body' => 'Two-factor authentication is now on for your account. Keep your recovery codes somewhere safe.',
        ],
        'two_factor_disabled' => [
            'title' => 'Two-factor authentication disabled',
            'body' => 'Two-factor authentication has been turned off for your account. If this was not you, turn it back on and change your password.',
        ],
        'new_sign_in' => [
            'title' => 'New sign-in to your account',
            'body' => 'Your account was signed in to from :location. If this was not you, change your password and end that session.',
        ],
    ],
    'billing' => [
        'order_placed' => [
            'title' => 'Order :number received',
            'body' => 'We have your order and will start work as soon as it is paid.',
        ],
        'invoice_issued' => [
            'title' => 'Invoice :number for :amount',
            'body' => 'Invoice :number has been issued for :amount and is due on :due_date.',
        ],
        'payment_succeeded' => [
            'title' => 'Payment of :amount received',
            'body' => 'Thank you. Your payment of :amount has been received and applied to invoice :number.',
        ],
        'payment_failed' => [
            'title' => 'Payment of :amount could not be taken',
            'body' => 'We could not take your payment of :amount for invoice :number. Please check your card details; we will try again automatically.',
        ],
        'refund_issued' => [
            'title' => 'Refund of :amount issued',
            'body' => 'A refund of :amount has been issued to your original payment method. It can take several working days to appear.',
        ],
        'renewal_upcoming' => [
            'title' => 'Your subscription renews on :date',
            'body' => ':service renews on :date for :amount. No action is needed if your payment details are current.',
        ],
        'renewal_succeeded' => [
            'title' => 'Subscription renewed',
            'body' => ':service has been renewed for another term. Your next payment is due on :date.',
        ],
        'renewal_failed' => [
            'title' => 'Could not renew your subscription',
            'body' => 'We could not take payment to renew :service. Please update your payment details before :grace_ends to avoid interruption.',
        ],
        'grace_period_started' => [
            'title' => 'Payment overdue for :service',
            'body' => 'Payment for :service is overdue. Your service continues until :grace_ends, after which it will be suspended.',
        ],
        'suspension_warning' => [
            'title' => ':service will be suspended on :date',
            'body' => 'Payment for :service is still outstanding. It will be suspended on :date unless payment is received.',
        ],
        'cancellation_scheduled' => [
            'title' => ':service is scheduled to end',
            'body' => ':service will end on :date. You will keep full use of it until then.',
        ],
    ],
    'service' => [
        'provisioning' => [
            'title' => 'Setting up :service',
            'body' => 'We have started building :service. This usually takes a few minutes and we will tell you when it is ready.',
        ],
        'ready' => [
            'title' => ':service is ready',
            'body' => ':service is now running and ready to use. You can manage it from your portal.',
        ],
        'provisioning_failed' => [
            'title' => 'Could not set up :service',
            'body' => 'Something went wrong while building :service. Our team has been alerted and will look at it; you have not been charged for a service that does not exist.',
        ],
        'needs_review' => [
            'title' => ':service needs our attention',
            'body' => 'Setting up :service did not finish cleanly and needs a person to look at it. We will be in touch.',
        ],
        'suspended' => [
            'title' => ':service has been suspended',
            'body' => ':service has been suspended and is no longer running. Settling the outstanding balance will restore it.',
        ],
        'restored' => [
            'title' => ':service is running again',
            'body' => 'Thank you. :service has been restored and is available now.',
        ],
        'reactivation_failed' => [
            'title' => 'Could not restore :service yet',
            'body' => 'Your payment went through, but we could not bring :service back online automatically. Our team is on it and will restore it shortly.',
        ],
        'terminated' => [
            'title' => ':service has been terminated',
            'body' => ':service has been terminated and its data removed. This cannot be undone.',
        ],
        'plan_change_completed' => [
            'title' => ':service is now on the :plan plan',
            'body' => 'The plan change for :service is complete. Your next invoice will reflect the new price.',
        ],
        'plan_change_failed' => [
            'title' => 'Could not change the plan for :service',
            'body' => 'The plan change for :service did not complete and has been left as it was. Nothing has been charged for the change.',
        ],
        'reinstall_started' => [
            'title' => 'Reinstalling :service',
            'body' => 'The reinstall of :service has started. It will be unavailable while this runs.',
        ],
        'reinstall_completed' => [
            'title' => ':service has been reinstalled',
            'body' => ':service has been reinstalled with :image and is running again.',
        ],
        'reinstall_failed' => [
            'title' => 'Reinstall of :service did not complete',
            'body' => 'The reinstall of :service did not finish. Our team has been alerted; do not assume the disk is in either state until we confirm.',
        ],
        'backup_completed' => [
            'title' => 'Backup of :service completed',
            'body' => 'A backup of :service finished successfully.',
        ],
        'backup_failed' => [
            'title' => 'Backup of :service failed',
            'body' => 'A backup of :service did not complete. Your existing backups are unaffected.',
        ],
        'restore_completed' => [
            'title' => ':service has been restored from backup',
            'body' => 'The restore of :service finished. Anything written after the backup was taken is no longer present.',
        ],
        'restore_failed' => [
            'title' => 'Restore of :service did not complete',
            'body' => 'The restore of :service did not finish. Our team has been alerted.',
        ],
    ],
    'operational' => [
        'incident' => [
            'title' => 'An incident is affecting :service',
            'body' => 'We are aware of a problem affecting :service and are working on it. We will let you know when it is resolved.',
        ],
        'maintenance_scheduled' => [
            'title' => 'Planned maintenance on :date',
            'body' => 'Maintenance affecting :service is planned for :date. You may see a short interruption.',
        ],
    ],

    'action' => [
        'open' => 'Open in your portal',
    ],

    'signature' => 'Lynomia Cloud',
];
