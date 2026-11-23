<?php

declare(strict_types=1);

namespace RageKit\Cli\Toolchain;

use RageKit\Cli\RageKitException;

/** Runs build tools with the toolchain's environment. */
final class Process
{
    /** @param array<string, string> $env */
    public function __construct(private readonly array $env)
    {
    }

    public function withEnv(string $name, string $value): self
    {
        return new self([...$this->env, $name => $value]);
    }

    public function env(string $name): ?string
    {
        return $this->env[$name] ?? null;
    }

    /** Streams the tool's output; returns its exit code. */
    public function run(array $command, string $cwd): int
    {
        $process = proc_open($this->resolve($command), [STDIN, STDOUT, STDERR], $pipes, $cwd, $this->env);
        if (!is_resource($process)) {
            throw new RageKitException('could not start ' . $command[0]);
        }
        return proc_close($process);
    }

    /**
     * Collects stdout + stderr. $tick, if given, is called with the whole seconds elapsed each
     * time another second passes (not on Windows, where proc_open pipes can't be polled).
     *
     * @param (callable(int): void)|null $tick
     * @return array{int, string} exit code, output
     */
    public function capture(array $command, string $cwd, ?callable $tick = null): array
    {
        $process = proc_open($this->resolve($command), [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd, $this->env);
        if (!is_resource($process)) {
            throw new RageKitException('could not start ' . $command[0]);
        }
        $output = $tick === null || Platform::isWindows()
            ? (string) stream_get_contents($pipes[1])
            : self::readTicking($pipes[1], $tick);
        fclose($pipes[1]);
        return [proc_close($process), $output];
    }

    /**
     * Runs the commands, up to $jobs at a time, each with stdout + stderr collected. Stops starting
     * new ones after one fails. Not on Windows (its pipes can't be polled): there they run one by one.
     *
     * @param list<list<string>>         $commands
     * @param (callable(int): void)|null $tick
     * @return list<array{int, string}> exit code and output of each command that ran, in order
     */
    public function captureAll(array $commands, string $cwd, int $jobs, ?callable $tick = null): array
    {
        if (Platform::isWindows() || $jobs <= 1) {
            $results = [];
            foreach ($commands as $command) {
                $results[] = $result = $this->capture($command, $cwd, $tick);
                if ($result[0] !== 0) {
                    break;
                }
            }
            return $results;
        }
        $results = [];
        /** @var array<int, array{resource, resource}> $running index => process, its output pipe */
        $running = [];
        $output = [];
        $next = 0;
        $failed = false;
        $start = hrtime(true);
        $reported = 0;
        while ((!$failed && $next < count($commands)) || $running !== []) {
            while (!$failed && $next < count($commands) && count($running) < $jobs) {
                $process = proc_open($this->resolve($commands[$next]), [0 => STDIN, 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd, $this->env);
                if (!is_resource($process)) {
                    throw new RageKitException('could not start ' . $commands[$next][0]);
                }
                stream_set_blocking($pipes[1], false);
                $running[$next] = [$process, $pipes[1]];
                $output[$next] = '';
                $next++;
            }
            $read = array_map(fn(array $entry) => $entry[1], array_values($running));
            $none = null;
            @stream_select($read, $none, $none, 0, 200_000);
            foreach ($running as $index => [$process, $pipe]) {
                $output[$index] .= (string) fread($pipe, 65536);
                if (feof($pipe)) {
                    fclose($pipe);
                    $results[$index] = [proc_close($process), $output[$index]];
                    $failed = $failed || $results[$index][0] !== 0;
                    unset($running[$index]);
                }
            }
            $seconds = intdiv(hrtime(true) - $start, 1_000_000_000);
            if ($tick !== null && $seconds > $reported) {
                $reported = $seconds;
                $tick($seconds);
            }
        }
        ksort($results);
        return array_values($results);
    }

    /**
     * @param resource            $pipe
     * @param callable(int): void $tick
     */
    private static function readTicking($pipe, callable $tick): string
    {
        stream_set_blocking($pipe, false);
        $start = hrtime(true);
        $output = '';
        $reported = 0;
        while (!feof($pipe)) {
            $read = [$pipe];
            $none = null;
            if ((int) @stream_select($read, $none, $none, 0, 200_000) > 0) {
                $output .= (string) fread($pipe, 65536);
            }
            $seconds = intdiv(hrtime(true) - $start, 1_000_000_000);
            if ($seconds > $reported) {
                $reported = $seconds;
                $tick($seconds);
            }
        }
        return $output;
    }

    /**
     * Windows looks a bare program name (cl.exe) up on ragekit's own PATH, not on the PATH of the
     * environment passed to proc_open(), so find it on the toolchain's PATH first.
     */
    private function resolve(array $command): array
    {
        if (!Platform::isWindows() || strpbrk($command[0], '/\\') !== false) {
            return $command;
        }
        $path = $this->env['Path'] ?? $this->env['PATH'] ?? (string) getenv('PATH');
        $pathExt = $this->env['PATHEXT'] ?? (getenv('PATHEXT') ?: '.COM;.EXE;.BAT;.CMD');
        $command[0] = self::findOnWindowsPath($command[0], $path, $pathExt) ?? $command[0];
        return $command;
    }

    /**
     * Like cmd.exe: a name without an extension gets each PATHEXT extension in turn, so `composer`
     * finds composer.bat (CreateProcess itself only tries .exe), not the extensionless script for
     * Git Bash that Composer's installer puts next to it.
     *
     * @internal public for tests
     */
    public static function findOnWindowsPath(string $program, string $path, string $pathExt, string $separator = '\\'): ?string
    {
        $extensions = array_filter(explode(';', strtolower($pathExt)));
        $names = in_array(strtolower((string) strrchr($program, '.')), $extensions, true)
            ? [$program]
            : array_map(fn (string $extension): string => $program . $extension, $extensions);
        foreach (explode(';', $path) as $dir) {
            foreach ($dir === '' ? [] : $names as $name) {
                if (is_file($dir . $separator . $name)) {
                    return $dir . $separator . $name;
                }
            }
        }
        return null;
    }
}
