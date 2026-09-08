<?php

declare(strict_types=1);

/*
 * The invitation mail, in whole sentences.
 *
 * The same rule as notifications.php: one string per message per language,
 * never assembled from fragments, placeholders named rather than positional.
 */

return [

    'invited' => [
        'title' => 'You have been invited to join :account on Lynomia Cloud',
        'body' => ':inviter has invited you to work on the :account account. Use the link below to accept — it stops working in :days days, and only the address this message was sent to can use it.',
    ],

];
