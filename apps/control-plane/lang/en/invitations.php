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

    /*
     * The mail an operator invitation sends (OperatorInvitation): the reset
     * link, in words that fit. The same for a new login and a promoted one,
     * so it says nothing about whether the address had a login.
     */
    'operator' => [
        'title' => 'You have been made an operator of Lynomia Cloud',
        'body' => 'An operator of Lynomia Cloud has given this address access to the platform\'s operator console. Set a password with the link below to sign in — it stops working in :minutes minutes. Nobody can sign in with this address until a password is set with it: if you already had a login here, its password, signed-in sessions, API tokens and two-factor setup have been removed, and setting a new password is the only way back in.',
    ],

];
