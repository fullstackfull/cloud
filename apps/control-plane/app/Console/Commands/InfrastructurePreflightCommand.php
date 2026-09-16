<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Lynomia\Modules\Infrastructure\Application\Preflight\InfrastructurePreflightService;
use Lynomia\Modules\Infrastructure\Domain\Preflight\CheckStatus;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightFinding;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightMode;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightReport;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightRequest;
use Lynomia\Modules\Infrastructure\Domain\Preflight\PreflightScope;

/**
 * What exactly prevents this from being used?
 *
 * ---------------------------------------------------------------------------
 * Why a command as well as a screen
 * ---------------------------------------------------------------------------
 *
 * Because the two callers have different jobs. An operator pressing a button
 * wants the answer rendered; a deployment pipeline wants an exit code it can
 * branch on without parsing anything. Both ask the same service, so neither
 * can be right while the other is wrong — the Admin endpoint and this command
 * are two presentations of one answer, which is the whole point of Gap 3.
 *
 * ---------------------------------------------------------------------------
 * The mode is required
 * ---------------------------------------------------------------------------
 *
 * There is no default and there is no auto. A run that guessed which world it
 * was asking about would sometimes dial a customer's cluster when somebody
 * meant to rehearse, and would sometimes rehearse when somebody meant to find
 * out whether the estate is real. Both mistakes end in a wrong sentence in a
 * status meeting.
 *
 * ---------------------------------------------------------------------------
 * Exit codes
 * ---------------------------------------------------------------------------
 *
 *   0  no blocking finding
 *   1  at least one blocking finding
 *   2  the invocation itself was wrong
 *
 * Laravel's own constants, so the numbers are the framework's rather than
 * this file's opinion. A warning does not fail: an expiring licence is worth
 * saying and is not worth stopping a pipeline over, and if it were, it would
 * be a blocker.
 */
final class InfrastructurePreflightCommand extends Command
{
    protected $signature = 'infra:preflight
        {--mode= : simulation or read-only-real. Required; there is no default.}
        {--provider= : One provider, by name or id}
        {--product= : One product: vps, dedicated, shared_hosting, wordpress, domains}
        {--site= : One datacenter, by slug}
        {--machine= : One managed machine, by name}
        {--json : Emit the report as JSON and nothing else}';

    protected $description = 'Report exactly what prevents a provider, product, site or machine from being used';

    public function handle(InfrastructurePreflightService $preflight): int
    {
        $mode = $this->mode();

        if ($mode === null) {
            return self::INVALID;
        }

        try {
            $request = $this->request($mode);
        } catch (InvalidArgumentException $wrong) {
            $this->components->error($wrong->getMessage());

            return self::INVALID;
        }

        $report = $preflight->run($request);

        if ((bool) $this->option('json')) {
            /*
             * The only thing on standard output, so a pipeline can read it
             * without stripping anything. No ANSI, no banner, and the same
             * typed structure the Admin API returns — one shape for both
             * callers, so automation written against one works against the
             * other.
             */
            $this->output->writeln((string) json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return $report->passed() ? self::SUCCESS : self::FAILURE;
        }

        $this->render($report);

        return $report->passed() ? self::SUCCESS : self::FAILURE;
    }

    private function mode(): ?PreflightMode
    {
        $given = (string) ($this->option('mode') ?? '');

        if ($given === '') {
            $this->components->error(
                'Say which mode: --mode=simulation rehearses against controlled providers, '
                .'--mode=read-only-real reads real ones. There is no default, because the difference between '
                .'"the software works" and "the estate works" is the difference this exists to keep.'
            );

            return null;
        }

        // Hyphens on the command line, underscores in the enum. The CLI is
        // where a person types, and nobody types underscores.
        $mode = PreflightMode::tryFrom(str_replace('-', '_', $given));

        if ($mode === null) {
            $this->components->error(sprintf('Unknown mode "%s". Expected simulation or read-only-real.', $given));
        }

        return $mode;
    }

    private function request(PreflightMode $mode): PreflightRequest
    {
        $scopes = [
            'provider' => PreflightScope::Provider,
            'product' => PreflightScope::Product,
            'site' => PreflightScope::Site,
            'machine' => PreflightScope::Machine,
        ];

        $given = [];

        foreach ($scopes as $option => $scope) {
            $value = $this->option($option);

            if (is_string($value) && trim($value) !== '') {
                $given[] = [$scope, trim($value)];
            }
        }

        if (count($given) > 1) {
            throw new InvalidArgumentException(
                'Name one scope at a time. A run that mixed a provider and a product would produce one report '
                .'answering two questions, and the reader could not tell which finding belonged to which.'
            );
        }

        if ($given === []) {
            return PreflightRequest::estate($mode);
        }

        [$scope, $target] = $given[0];

        return new PreflightRequest($mode, $scope, $target);
    }

    private function render(PreflightReport $report): void
    {
        /*
         * The mode first, always, and before anything that could be mistaken
         * for a result. A simulation report whose header scrolled off is a
         * simulation report somebody quotes as proof.
         */
        $this->newLine();
        $this->line(sprintf('  <options=bold>%s</> — %s%s',
            $report->mode->label(),
            $report->scope->label(),
            $report->target === null ? '' : ': '.$report->target,
        ));
        $this->newLine();

        foreach ($report->findings as $finding) {
            $this->line(sprintf('  %s %s', $this->tag($finding->status), $this->describe($finding)));
        }

        $this->newLine();
        $this->line(sprintf('  Mode:     %s', $report->mode->label()));
        $this->line(sprintf('  Checks:   %d', count($report->findings)));
        $this->line(sprintf('  Passed:   %d', $report->countOf(CheckStatus::Pass)));
        $this->line(sprintf('  Failed:   %d', $report->countOf(CheckStatus::Fail)));
        $this->line(sprintf('  Blocked:  %d', $report->countOf(CheckStatus::Blocked)));
        $this->line(sprintf('  Warnings: %d', $report->countOf(CheckStatus::Warning)));

        if ($report->countOf(CheckStatus::NotTested) > 0) {
            $this->line(sprintf('  Not established: %d', $report->countOf(CheckStatus::NotTested)));
        }

        $this->line(sprintf('  Verification: %s', implode(', ', $report->verificationLevels())));

        $claims = $report->realVerificationClaims();

        $this->line($claims === []
            ? '  Real infrastructure verified: NONE'
            : sprintf('  Real infrastructure verified, for these reads only: %s', implode(', ', array_unique($claims))));

        $actions = $report->nextActions();

        if ($actions !== []) {
            $this->newLine();
            $this->line('  <options=bold>Next:</>');

            foreach ($actions as $action) {
                $this->line('   - '.$action);
            }
        }

        $this->newLine();
    }

    private function describe(PreflightFinding $finding): string
    {
        return sprintf('%s — %s: %s', $finding->id, $finding->target, $finding->summary);
    }

    private function tag(CheckStatus $status): string
    {
        return match ($status) {
            CheckStatus::Pass => '<fg=green>[PASS]   </>',
            CheckStatus::Fail => '<fg=red>[FAIL]   </>',
            CheckStatus::Blocked => '<fg=red>[BLOCKED]</>',
            CheckStatus::Warning => '<fg=yellow>[WARN]   </>',
            CheckStatus::NotApplicable => '<fg=gray>[N/A]    </>',
            CheckStatus::NotTested => '<fg=gray>[UNKNOWN]</>',
        };
    }
}
