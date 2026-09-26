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
     * How long an account waits before it may mail the same address again.
     *
     * `security.rate_limits.team_invitations` bounds how much one account
     * sends in an hour, and says nothing about where it goes: the whole
     * budget could go to one address, back to back, by pressing Resend or by
     * withdrawing an offer and inviting the address again. This bounds how
     * often one address is mailed. Both roads compare against the same clock
     * — the last time this account mailed this address — so at the default
     * one address gets at most one invitation mail every ten minutes from one
     * account. Per address, not per inbox: two addresses that deliver to one
     * mailbox each have a wait of their own, and which ones do is not
     * something this platform can see. At least a minute, whatever this says,
     * so a zero cannot switch the wait off.
     */
    'invitation_cooldown_minutes' => (int) env('TEAM_INVITATION_COOLDOWN_MINUTES', 10),

    /*
     * Where the portal puts its invitation screen. Used to build the link in
     * the mail, which a queue worker has to construct without a request.
     */
    'invitation_path' => env('TEAM_INVITATION_PATH', '/invitations/:token'),

];
