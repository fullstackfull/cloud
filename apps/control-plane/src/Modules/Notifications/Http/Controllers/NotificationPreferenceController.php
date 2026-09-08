<?php

declare(strict_types=1);

namespace Lynomia\Modules\Notifications\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Lynomia\Modules\Audit\Application\Actions\RecordActAtomically;
use Lynomia\Modules\Audit\Application\DTOs\AuditedAct;
use Lynomia\Modules\Audit\Domain\Enums\AuditAction;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationCategory;
use Lynomia\Modules\Notifications\Domain\Enums\NotificationChannel;
use Lynomia\Modules\Notifications\Infrastructure\Models\NotificationPreference;

/**
 * Which optional messages a person wants.
 *
 * Per user, not per customer account: two people on one account read different
 * mail, and a finance contact who silences service email must not silence it
 * for the engineer who actually operates the servers.
 *
 * The response lists every category with whether it can be changed at all, so
 * the portal renders the immovable ones as stated policy rather than as
 * missing controls. A screen that simply omitted security and billing would
 * leave a customer wondering whether they had been switched off silently.
 */
final class NotificationPreferenceController
{
    public function index(Request $request): JsonResponse
    {
        $userId = (string) $request->user()?->getAuthIdentifier();

        $set = NotificationPreference::query()
            ->where('user_id', $userId)
            ->get()
            ->keyBy(static fn (NotificationPreference $p): string => $p->category->value.'|'.$p->channel->value);

        $data = [];

        foreach (NotificationCategory::cases() as $category) {
            foreach (NotificationChannel::implemented() as $channel) {
                $changeable = in_array($channel, $category->disableableChannels(), strict: true);

                $data[] = [
                    'category' => $category->value,
                    'channel' => $channel->value,
                    // Absence means enabled, which is what keeps a new category
                    // working for every existing account without a backfill.
                    // A missing row means enabled, which is what keeps a new
                    // category working for every existing account with no
                    // backfill.
                    'enabled' => ! $changeable
                        || ($set->get($category->value.'|'.$channel->value)->enabled ?? true),
                    'changeable' => $changeable,
                ];
            }
        }

        return response()->json(['data' => $data]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'category' => ['required', 'string'],
            'channel' => ['required', 'string'],
            'enabled' => ['required', 'boolean'],
        ]);

        $category = NotificationCategory::tryFrom($validated['category']);
        $channel = NotificationChannel::tryFrom($validated['channel']);

        if ($category === null || $channel === null) {
            throw ValidationException::withMessages([
                'category' => 'Unknown notification category or channel.',
            ]);
        }

        /*
         * Refused here as well as ignored at send time. The send-time check is
         * what actually protects the customer; this one exists so the API
         * tells the truth rather than accepting a change it will not honour —
         * a setting that appears to save and does nothing is worse than one
         * that refuses.
         */
        if (! in_array($channel, $category->disableableChannels(), strict: true)) {
            throw ValidationException::withMessages([
                'category' => sprintf(
                    'The %s category cannot be turned off on %s: these messages carry obligations or security warnings.',
                    $category->value,
                    $channel->value,
                ),
            ]);
        }

        $userId = (string) $request->user()?->getAuthIdentifier();

        /*
         * The change and its record together. The next dispute this settles is
         * "nobody told me my server was suspended": the answer is either that
         * the platform did, or that this person asked it not to on a date
         * somebody can read.
         */
        app(RecordActAtomically::class)->execute(
            act: static fn (): NotificationPreference => NotificationPreference::query()->updateOrCreate(
                [
                    'user_id' => $userId,
                    'category' => $category,
                    'channel' => $channel,
                ],
                ['enabled' => $validated['enabled']],
            ),
            describe: static fn (NotificationPreference $preference): AuditedAct => new AuditedAct(
                action: AuditAction::NotificationPreferenceChanged,
                subject: $preference,
                context: [
                    'category' => $category->value,
                    'channel' => $channel->value,
                    'enabled' => $validated['enabled'],
                ],
            ),
        );

        return $this->index($request);
    }
}
