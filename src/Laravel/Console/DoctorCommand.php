<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Console;

use FullStackStudio\Content\Laravel\Doctor;
use Illuminate\Console\Command;

/**
 * `php artisan fsc:doctor`: checks the always-required items of a site on this package (AGENTS.md, "Altijd verplicht") and prints one Dutch
 * line per check. Exit code 1 when anything fails; warnings do not fail unless `--strict`. Read-only and offline unless `--online`.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'fsc:doctor
        {--skip-env : Skip the checks of FSC_SERVER_TOKEN, FSC_WEBHOOK_SECRET and APP_KEY (for CI or a checkout without .env)}
        {--online : Also call FullStack Hub once with the token (project and SEO settings)}
        {--strict : Exit with 1 on warnings too}';

    protected $description = 'Check that this site has everything FullStack Hub needs (layout, routes, env, middleware, https, sessions)';

    private const LABELS = [
        Doctor::OK => '<info>  OK     </info>',
        Doctor::WARN => '<comment>  LET OP </comment>',
        Doctor::FAIL => '<error>  FOUT   </error>',
        Doctor::SKIP => '  OVERGESLAGEN ',
        Doctor::INFO => '  INFO   ',
    ];

    public function handle(): int
    {
        $results = (new Doctor($this->laravel))->run(! $this->option('skip-env'), (bool) $this->option('online'));

        $this->line('FullStack Hub: controle van deze site (zie vendor/fullstackstudio/content/AGENTS.md)');
        $this->newLine();

        $count = [Doctor::OK => 0, Doctor::WARN => 0, Doctor::FAIL => 0, Doctor::SKIP => 0, Doctor::INFO => 0];
        foreach ($results as $result) {
            $count[$result['status']]++;
            $this->line(self::LABELS[$result['status']] . ' ' . $result['label']);
            if ($result['hint'] !== null && $result['status'] !== Doctor::OK) {
                $this->line('           ' . $result['hint']);
            }
        }

        $this->newLine();
        $this->line(sprintf('%d in orde, %d om op te letten, %d fout.', $count[Doctor::OK], $count[Doctor::WARN], $count[Doctor::FAIL]));

        if ($count[Doctor::FAIL] > 0 || ($this->option('strict') && $count[Doctor::WARN] > 0)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
