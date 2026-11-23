<?php

declare(strict_types=1);

namespace RageKit\Tests\Cli\Toolchain;

use PHPUnit\Framework\TestCase;
use RageKit\Cli\Toolchain\Process;

final class ProcessTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rk-process-' . uniqid();
        mkdir("$this->dir/a", 0777, true);
        mkdir("$this->dir/b");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function find(string $program): ?string
    {
        return Process::findOnWindowsPath($program, "$this->dir/a;;$this->dir/b", '.COM;.EXE;.BAT;.CMD', '/');
    }

    public function testFindsABatchFileNotTheShellScriptNextToIt(): void
    {
        // Composer's Windows installer puts composer.bat next to a `composer` script for Git Bash.
        touch("$this->dir/b/composer");
        touch("$this->dir/b/composer.bat");

        self::assertSame("$this->dir/b/composer.bat", $this->find('composer'));
    }

    public function testEarlierPathEntriesWinAndPathextOrderDecidesWithinOne(): void
    {
        touch("$this->dir/a/tool.cmd");
        touch("$this->dir/a/tool.exe");
        touch("$this->dir/b/tool.exe");

        self::assertSame("$this->dir/a/tool.exe", $this->find('tool'));
    }

    public function testANameWithAnExtensionIsLookedUpAsIs(): void
    {
        touch("$this->dir/b/cl.exe");

        self::assertSame("$this->dir/b/cl.exe", $this->find('cl.exe'));
    }

    public function testUnknownProgramIsNull(): void
    {
        self::assertNull($this->find('nothing'));
    }

    /** Three half-second commands, three at a time: well under the 1.5 s they take one by one. */
    public function testCaptureAllRunsCommandsAtOnceAndKeepsTheirOrder(): void
    {
        $process = new Process(['PATH' => (string) getenv('PATH')]);
        $start = hrtime(true);
        $results = $process->captureAll([
            ['sh', '-c', 'sleep 0.5; echo one'],
            ['sh', '-c', 'sleep 0.5; echo two >&2'],
            ['sh', '-c', 'sleep 0.5; echo three'],
        ], $this->dir, 3);
        self::assertLessThan(1.2, (hrtime(true) - $start) / 1e9);
        self::assertSame([[0, "one\n"], [0, "two\n"], [0, "three\n"]], $results);
    }

    public function testCaptureAllStartsNothingAfterAFailure(): void
    {
        $process = new Process(['PATH' => (string) getenv('PATH')]);
        $results = $process->captureAll([
            ['sh', '-c', 'echo broken; exit 3'],
            ['sh', '-c', "touch ran"],
        ], $this->dir, 1);
        self::assertSame([[3, "broken\n"]], $results);
        self::assertFileDoesNotExist("$this->dir/ran");
    }
}
