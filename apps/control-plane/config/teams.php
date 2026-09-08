<?php

declare(strict_types=1);

return [

    /*
     * How long an offer of membership stays open.
     *
     * Long enough to survive a weekend and a holiday; short enough that an
     * invitation forgotten in a mailbox for a year is not a standing key to
     * somebody's servers. A person who misses it asks for another — which is
     * a message, not a lockout.
     */
    'invitation_ttl_days' => (int) env('TEAM_INVITATION_TTL_DAYS', 14),

    /*
     * How many people one account may have, invitations included.
     *
     * Not a commercial limit — it is a blast radius. An account whose owner's
     * session is stolen can be filled with members faster than anybody reads
     * the notifications, and every one of them is a login that survives the
     * password change that closes the original hole.
     */
    'max_members' => (int) env('TEAM_MAX_MEMBERS', 25),

    /*
     * Where the portal puts its invitation screen. Used to build the link in
     * the mail, which a queue worker has to construct without a request.
     */
    'invitation_path' => env('TEAM_INVITATION_PATH', '/invitations/:token'),

];
