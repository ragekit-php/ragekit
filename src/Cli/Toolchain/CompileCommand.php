<?php

declare(strict_types=1);

namespace RageKit\Cli\Toolchain;

/** A compiler invocation and what it produces. */
final class CompileCommand
{
    /**
     * @param list<string>       $command the full command line (run without a shell)
     * @param string             $output  the file it produces
     * @param string             $link    what an app links against: the library itself, or its
     *                                    import .lib on Windows
     * @param list<list<string>> $compile commands run first, one per source, each ending with the
     *                                    object file it writes; $command then links those objects
     */
    public function __construct(
        public readonly array $command,
        public readonly string $output,
        public readonly string $link,
        public readonly array $compile = [],
    ) {
    }

    /** @return list<list<string>> every command, in the order they run */
    public function commands(): array
    {
        return [...$this->compile, $this->command];
    }
}
