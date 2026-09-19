<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Lynomia\Modules\Infrastructure\Application\Naming\AuditInfrastructureNaming;
use Lynomia\Modules\Infrastructure\Domain\Naming\NamingFinding;
use Lynomia\Modules\Infrastructure\Domain\Preflight\CheckStatus;

/**
 * Which names in this estate disagree with the standard.
 *
 * ---------------------------------------------------------------------------
 * Exit codes, the same three the preflight uses
 * ---------------------------------------------------------------------------
 *
 *   0  nothing wrong now — warnings included, because a legacy value that
 *      predates the standard is not a reason to fail a pipeline
 *   1  at least one failure: an identity collision, a reference name on a
 *      production row, or a production zone that cannot resolve
 *   2  the invocation itself was wrong
 *
 * A warning not failing is the decision this command is built around. The
 * alternative — every pre-standard rack code turning a deployment red — ends
 * with somebody passing a flag to silence the whole check, and then the
 * failures are silenced too.
 */
final class InfrastructureNamingAuditCommand extends Command
{
    protected $signature = 'infra:naming:audit
        {--json : Emit the findings as JSON and nothing else}';

    protected $description = 'Report infrastructure names that disagree with the canonical naming standard';

    public function handle(AuditInfrastructureNaming $audit): int
    {
        $findings = $audit->execute(production: $this->laravel->environment('production'));

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'findings' => array_map(static fn (NamingFinding $f): array => $f->toArray(), $findings),
                'failures' => count(array_filter($findings, static fn (NamingFinding $f): bool => $f->blocks())),
                'warnings' => count(array_filter($findings, static fn (NamingFinding $f): bool => $f->status === CheckStatus::Warning)),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $this->exitCode($findings);
        }

        $this->newLine();
        $this->line('Infrastructure naming audit');
        $this->newLine();

        foreach ($findings as $finding) {
            $this->line(sprintf('%s %s', $this->tag($finding->status), $finding->target));
            $this->line(sprintf('       %s', $finding->summary));

            if ($finding->nextAction !== null) {
                $this->line(sprintf('       → %s', $finding->nextAction));
            }
        }

        $this->newLine();

        return $this->exitCode($findings);
    }

    /**
     * @param  list<NamingFinding>  $findings
     */
    private function exitCode(array $findings): int
    {
        foreach ($findings as $finding) {
            if ($finding->blocks()) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    private function tag(CheckStatus $status): string
    {
        return match ($status) {
            CheckStatus::Pass => '[PASS]',
            CheckStatus::Warning => '[WARN]',
            CheckStatus::Fail, CheckStatus::Blocked => '[FAIL]',
            CheckStatus::NotApplicable => '[N/A ]',
            CheckStatus::NotTested => '[----]',
        };
    }
}
