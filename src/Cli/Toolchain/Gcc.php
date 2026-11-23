<?php

declare(strict_types=1);

namespace RageKit\Cli\Toolchain;

/**
 * g++ on Linux (and macOS, untested). Each source is compiled to its own object file, then linked:
 * ccache, when g++ goes through it, can only reuse a compile, never a call that also links.
 */
final class Gcc implements CppCompiler
{
    public function sharedLibrary(CppBuild $build): CompileCommand
    {
        $output = "{$build->dir}/lib{$build->name}.so";
        [$compile, $objects] = self::objects($build, ['-O2', '-fPIC', '-fvisibility=hidden']);
        $command = [
            'g++', '-shared', ...$objects, ...self::libraries($build),
            // Run without a shell, so $ORIGIN reaches the linker literally.
            '-Wl,-rpath,$ORIGIN', "-Wl,-soname,lib{$build->name}.so", '-o', $output,
        ];
        return new CompileCommand($command, $output, $output, $compile);
    }

    public function program(CppBuild $build): CompileCommand
    {
        $output = "{$build->dir}/{$build->name}";
        [$compile, $objects] = self::objects($build, ['-O1']);
        $rpaths = array_map(fn(string $path) => "-Wl,-rpath,$path", $build->linkPaths);
        $command = ['g++', ...$objects, ...self::libraries($build), '-lpthread', ...$rpaths, '-o', $output];
        return new CompileCommand($command, $output, $output, $compile);
    }

    /**
     * One compile per source, into <dir>/<name>.objects/ (the path's hash keeps two sources with
     * the same file name apart).
     *
     * @param list<string> $flags
     * @return array{0: list<list<string>>, 1: list<string>} the compile commands, the objects
     */
    private static function objects(CppBuild $build, array $flags): array
    {
        $compile = [];
        $objects = [];
        foreach ($build->sources as $source) {
            $object = "{$build->dir}/{$build->name}.objects/" . pathinfo($source, PATHINFO_FILENAME) . '-' . substr(sha1($source), 0, 8) . '.o';
            $compile[] = [
                'g++', "-std={$build->std}", ...$flags,
                ...array_map(fn(string $define) => "-D$define", $build->defines),
                ...array_map(fn(string $path) => "-I$path", $build->includePaths),
                '-c', $source, '-o', $object,
            ];
            $objects[] = $object;
        }
        return [$compile, $objects];
    }

    /** @return list<string> link paths, libraries */
    private static function libraries(CppBuild $build): array
    {
        return [
            ...array_map(fn(string $path) => "-L$path", $build->linkPaths),
            ...array_map(fn(string $lib) => "-l$lib", $build->linkLibs),
        ];
    }
}
