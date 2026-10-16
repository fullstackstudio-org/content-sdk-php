<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Laravel\Console;

use FullStackStudio\Content\Laravel\AgentsBlock;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * `php artisan fsc:agents`: writes (or updates) a short marked block in the project's AGENTS.md that points AI coding agents at
 * `vendor/fullstackstudio/content/AGENTS.md`, and adds `@AGENTS.md` to an existing CLAUDE.md. Idempotent; never touches text outside the markers.
 */
final class AgentsCommand extends Command
{
    protected $signature = 'fsc:agents';

    protected $description = 'Add the Fullstack Content block to AGENTS.md (and the @AGENTS.md import to CLAUDE.md) so AI agents find the SDK guide';

    public function handle(): int
    {
        $agents = base_path('AGENTS.md');
        $claude = base_path('CLAUDE.md');

        try {
            if (is_file($agents)) {
                $current = (string) file_get_contents($agents);
                $next = AgentsBlock::apply($current);
                $action = $next === $current ? 'unchanged' : 'updated';
            } else {
                $next = AgentsBlock::newFile();
                $action = 'created';
            }
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($action !== 'unchanged') {
            file_put_contents($agents, $next);
        }

        $this->line("AGENTS.md: {$action}.");

        if (is_file($claude)) {
            $current = (string) file_get_contents($claude);

            if (AgentsBlock::hasImport($current)) {
                $this->line('CLAUDE.md: already imports @AGENTS.md.');
            } else {
                file_put_contents($claude, AgentsBlock::withImport($current));
                $this->line('CLAUDE.md: added @AGENTS.md.');
            }
        }

        return self::SUCCESS;
    }
}
