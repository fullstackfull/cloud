<?php

declare(strict_types=1);

return [

    /*
     * Where attachments live.
     *
     * The private local disk by default, never the public one: a support
     * attachment is a customer's log file, screenshot or invoice, and the
     * public disk is served straight off the web root by a URL anybody can
     * guess at. Every download here goes through an endpoint that checks who
     * is asking.
     */
    'attachments' => [
        'disk' => env('SUPPORT_ATTACHMENT_DISK', 'local'),

        'max_bytes' => (int) env('SUPPORT_ATTACHMENT_MAX_BYTES', 10 * 1024 * 1024),

        'max_per_message' => (int) env('SUPPORT_ATTACHMENT_MAX_PER_MESSAGE', 5),

        /*
         * What may be attached, decided from the bytes rather than from the
         * name or the browser's claim.
         *
         * An allow list, and a short one. Everything on it is something the
         * platform can serve with `Content-Disposition: attachment` and a
         * `nosniff` header without a browser ever executing it. SVG is
         * deliberately absent — it is a document that can carry script, and a
         * customer's "diagram" that runs JavaScript on an operator's session
         * is stored cross-site scripting with a support queue for a delivery
         * mechanism.
         */
        'allowed_mime_types' => [
            'image/png',
            'image/jpeg',
            'image/gif',
            'image/webp',
            'application/pdf',
            'text/plain',
            'application/json',
            'application/gzip',
            'application/zip',
        ],
    ],

    /*
     * How long a resolved ticket waits before it closes itself.
     *
     * Resolved is not finished: it is the support team's opinion that the
     * problem is solved, and the customer has not agreed yet. A ticket that
     * closed the instant it was resolved would make "that did not fix it" into
     * a new ticket with none of the history.
     */
    /*
     * How many live tickets one account may have at once.
     *
     * Not a commercial limit — a bound on how much of a support queue one
     * account can occupy. Replying on an existing ticket is never limited, so
     * nobody is ever stopped from asking for help.
     */
    'max_open_tickets_per_customer' => (int) env('SUPPORT_MAX_OPEN_TICKETS', 20),

    'auto_close_resolved_after_days' => (int) env('SUPPORT_AUTO_CLOSE_DAYS', 7),

];
