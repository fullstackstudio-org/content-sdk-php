<?php

declare(strict_types=1);

namespace FullStackStudio\Content\Tests\Laravel;

use FullStackStudio\Content\Laravel\AgentsBlock;

final class AgentsCommandTest extends LaravelTestCase
{
    private string $agents;
    private string $claude;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agents = base_path('AGENTS.md');
        $this->claude = base_path('CLAUDE.md');
        @unlink($this->agents);
        @unlink($this->claude);
    }

    protected function tearDown(): void
    {
        @unlink($this->agents);
        @unlink($this->claude);
        parent::tearDown();
    }

    public function testCreatesAgentsFileWithTheBlock(): void
    {
        $this->artisan('fsc:agents')->expectsOutput('AGENTS.md: created.')->assertExitCode(0);

        self::assertSame(AgentsBlock::newFile(), file_get_contents($this->agents));
        self::assertStringContainsString('vendor/fullstackstudio/content/AGENTS.md', (string) file_get_contents($this->agents));
        self::assertFileDoesNotExist($this->claude);
    }

    public function testRerunIsIdempotent(): void
    {
        $this->artisan('fsc:agents')->assertExitCode(0);
        $first = file_get_contents($this->agents);

        $this->artisan('fsc:agents')->expectsOutput('AGENTS.md: unchanged.')->assertExitCode(0);

        self::assertSame($first, file_get_contents($this->agents));
        self::assertSame(1, substr_count((string) $first, AgentsBlock::START));
    }

    public function testUpdatesAnOutdatedBlockAndLeavesOtherContentAlone(): void
    {
        $before = "# My site\n\nUse tabs. Cost \$1 and \\1 stay as typed.\n\n";
        $after = "\n\n## Deploy\n\nSomething else.\n";
        file_put_contents($this->agents, $before . AgentsBlock::START . "\nold text\n" . AgentsBlock::END . $after);

        $this->artisan('fsc:agents')->expectsOutput('AGENTS.md: updated.')->assertExitCode(0);

        self::assertSame($before . AgentsBlock::block() . $after, file_get_contents($this->agents));
    }

    public function testAppendsTheBlockToAnExistingFileWithoutMarkers(): void
    {
        file_put_contents($this->agents, "# Mine\n\nHello.\n");

        $this->artisan('fsc:agents')->assertExitCode(0);

        $text = (string) file_get_contents($this->agents);
        self::assertStringStartsWith("# Mine\n\nHello.\n\n" . AgentsBlock::START, $text);
        self::assertStringEndsWith(AgentsBlock::END . "\n", $text);
    }

    public function testAnIncompleteBlockIsNotTouched(): void
    {
        file_put_contents($this->agents, "text\n" . AgentsBlock::START . "\nno end\n");

        $this->artisan('fsc:agents')->assertExitCode(1);

        self::assertSame("text\n" . AgentsBlock::START . "\nno end\n", file_get_contents($this->agents));
    }

    public function testClaudeMdGetsTheImportOnce(): void
    {
        file_put_contents($this->claude, "# Claude notes\n");

        $this->artisan('fsc:agents')->expectsOutput('CLAUDE.md: added @AGENTS.md.')->assertExitCode(0);
        $this->artisan('fsc:agents')->expectsOutput('CLAUDE.md: already imports @AGENTS.md.')->assertExitCode(0);

        self::assertSame("# Claude notes\n\n@AGENTS.md\n", file_get_contents($this->claude));
    }

    public function testClaudeMdWithAnImportIsLeftAlone(): void
    {
        file_put_contents($this->claude, "@AGENTS.md\n\nMore.\n");

        $this->artisan('fsc:agents')->assertExitCode(0);

        self::assertSame("@AGENTS.md\n\nMore.\n", file_get_contents($this->claude));
    }
}
