<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/src/core/PluginCronSchedule.php';
require_once dirname(__DIR__, 2) . '/src/core/PluginRuntime.php';

/**
 * The runtime is the only place that decides which files from the catalog may
 * be executed. Each test below is a thing that must NOT run: a file outside the
 * plugin, a symlinked directory, code writable by another user, a class that
 * already exists, a namespace another plugin already claimed.
 */
final class PluginRuntimeTest extends TestCase
{
    private string $root;
    private string $outside;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/aipbx-catalog-' . bin2hex(random_bytes(4));
        $this->outside = sys_get_temp_dir() . '/aipbx-outside-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0755, true);
        mkdir($this->outside, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        $this->remove($this->outside);
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @chmod($path, 0644);
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->remove($path . '/' . $entry);
        }
        @rmdir($path);
    }

    /** @param array<string, mixed> $manifest */
    private function plugin(string $slug, array $manifest, array $files = []): string
    {
        $dir = $this->root . '/' . $slug;
        mkdir($dir, 0755, true);
        $manifest += ['slug' => $slug];
        file_put_contents($dir . '/plugin.json', json_encode($manifest));
        foreach ($files as $relative => $contents) {
            $path = $dir . '/' . $relative;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0755, true);
            }
            file_put_contents($path, $contents);
        }
        return $dir;
    }

    /** @param array<int, string> $enabled */
    private function runtime(array $enabled): PluginRuntime
    {
        return new PluginRuntime(
            $this->root,
            static fn(string $slug): bool => in_array($slug, $enabled, true),
            static fn(string $slug): array => ['from' => $slug]
        );
    }

    public function testAPsr4PluginLoadsAClassOnlyWhenItIsAskedFor(): void
    {
        $this->plugin('demo', ['php' => ['namespace' => 'EchoGuard\\Demo\\', 'path' => 'src']], [
            'src/Widget.php' => "<?php\nnamespace EchoGuard\\Demo;\nfinal class Widget { public function name(): string { return 'demo'; } }\n",
        ]);

        $runtime = $this->runtime(['demo']);
        self::assertFalse(class_exists('EchoGuard\\Demo\\Widget'));
        $summary = $runtime->boot();
        self::assertCount(1, $summary['autoloaded']);
        self::assertSame([], $summary['problems']);

        $widget = new EchoGuard\Demo\Widget();
        self::assertSame('demo', $widget->name());
    }

    public function testAFilesPluginDeclaresWhatItProvidesAndIsIncluded(): void
    {
        $this->plugin('legacy', ['php' => ['files' => [
            'src/LegacyThing.php' => ['LegacyThing'],
        ]]], [
            'src/LegacyThing.php' => "<?php\nfinal class LegacyThing { public static function hello(): string { return 'hi'; } }\n",
        ]);

        $runtime = $this->runtime(['legacy']);
        $summary = $runtime->boot();
        self::assertSame([], $summary['problems']);
        self::assertSame(['legacy:src/LegacyThing.php'], $summary['included']);
        self::assertSame('hi', LegacyThing::hello());
    }

    public function testAClassCollisionIsReportedAndTheFileIsNotLoaded(): void
    {
        $this->plugin('clash', ['php' => ['files' => [
            'src/Clash.php' => ['PluginRuntimeTestClash'],
        ]]], [
            'src/Clash.php' => "<?php\nfinal class PluginRuntimeTestClash {}\n",
        ]);

        $runtime = $this->runtime(['clash']);
        $summary = $runtime->boot();
        self::assertSame([], $summary['included']);
        $problems = implode(' | ', $runtime->problems()['clash'] ?? []);
        self::assertStringContainsString('already defined', $problems);
    }

    public function testAFileOutsideThePluginIsRefused(): void
    {
        file_put_contents($this->outside . '/escape.php', "<?php\nfinal class Escaped {}\n");
        $this->plugin('escaper', ['php' => ['files' => [
            '../' . basename($this->outside) . '/escape.php' => ['Escaped'],
        ]]]);

        $runtime = $this->runtime(['escaper']);
        $summary = $runtime->boot();
        self::assertSame([], $summary['included']);
        self::assertFalse(class_exists('Escaped', false));
        self::assertStringContainsString('outside the plugin',
            implode(' | ', $runtime->problems()['escaper'] ?? []));
    }

    public function testASymlinkedDirectoryCannotEscapeThePlugin(): void
    {
        file_put_contents($this->outside . '/Evil.php', "<?php\nnamespace EchoGuard\\Evil;\nfinal class Evil {}\n");
        $dir = $this->plugin('sneaky', ['php' => ['namespace' => 'EchoGuard\\Evil\\', 'path' => 'src']]);
        symlink($this->outside, $dir . '/src');

        $runtime = $this->runtime(['sneaky']);
        $runtime->boot();
        self::assertFalse(class_exists('EchoGuard\\Evil\\Evil'));
        self::assertStringContainsString('not a directory inside the plugin',
            implode(' | ', $runtime->problems()['sneaky'] ?? []));
    }

    public function testAPhpFileWritableByOthersIsRefused(): void
    {
        $dir = $this->plugin('loose', ['php' => ['files' => [
            'src/Loose.php' => ['LooseThing'],
        ]]], [
            'src/Loose.php' => "<?php\nfinal class LooseThing {}\n",
        ]);
        chmod($dir . '/src/Loose.php', 0666);

        $runtime = $this->runtime(['loose']);
        $summary = $runtime->boot();
        self::assertSame([], $summary['included']);
        self::assertFalse(class_exists('LooseThing', false));
        self::assertStringContainsString('writable by others',
            implode(' | ', $runtime->problems()['loose'] ?? []));
    }

    public function testADisabledPluginIsNotBootedAndHasNoEntry(): void
    {
        $this->plugin('off', [
            'php' => ['files' => ['src/Off.php' => ['OffThing']]],
            'cron' => [['job' => 'tick', 'schedule' => '* * * * *', 'file' => 'cron/tick.php']],
        ], [
            'src/Off.php' => "<?php\nfinal class OffThing {}\n",
            'cron/tick.php' => "<?php\nreturn static fn(array \$s, string \$slug): int => 0;\n",
        ]);

        $runtime = $this->runtime([]);
        $summary = $runtime->boot();
        self::assertSame([], $summary['included']);
        self::assertFalse(class_exists('OffThing', false));
        self::assertNull($runtime->entry('off', ['file' => 'cron/tick.php']));
    }

    public function testACronFileOutsideThePluginIsRefused(): void
    {
        file_put_contents($this->outside . '/job.php', "<?php\nreturn static fn(): int => 0;\n");
        $this->plugin('traverse', ['cron' => [[
            'job' => 'bad', 'schedule' => '* * * * *',
            'file' => '../../' . basename($this->outside) . '/job.php',
        ]]]);

        $runtime = $this->runtime(['traverse']);
        $runtime->boot();
        // The path is only resolved when the entry is asked for: the manifest
        // may name any file, the runtime decides whether it may run.
        self::assertNull($runtime->entry('traverse', $runtime->job('traverse', 'bad') ?? []));
        self::assertStringContainsString('outside the plugin',
            implode(' | ', $runtime->problems()['traverse'] ?? []));
    }

    public function testAnInvalidScheduleStopsTheManifestFromRunningAnyJob(): void
    {
        $this->plugin('broken', ['php' => ['files' => ['src/Broken.php' => ['BrokenThing']]], 'cron' => [[
            'job' => 'tick', 'schedule' => 'every minute', 'file' => 'cron/tick.php',
        ]]], [
            'src/Broken.php' => "<?php\nfinal class BrokenThing {}\n",
            'cron/tick.php' => "<?php\nreturn static fn(): int => 0;\n",
        ]);

        $runtime = $this->runtime(['broken']);
        $summary = $runtime->boot();
        self::assertSame([], $summary['included'], 'the PHP is not half-booted');
        self::assertSame([], $runtime->jobs('broken'));
        self::assertStringContainsString('schedule',
            implode(' | ', $runtime->problems()['broken'] ?? []));
    }

    public function testAClassJobResolvesToACallableThatReceivesSettingsAndSlug(): void
    {
        $this->plugin('jobs', [
            'php' => ['namespace' => 'EchoGuard\\Jobs\\', 'path' => 'src'],
            'cron' => [[
                'job' => 'collect', 'schedule' => '*/5 * * * *',
                'class' => 'EchoGuard\\Jobs\\Collector::run', 'timeout' => 120,
            ]],
        ], [
            'src/Collector.php' => "<?php\nnamespace EchoGuard\\Jobs;\nfinal class Collector { public static function run(array \$settings, string \$slug): int { return \$slug === 'jobs' ? 0 : 1; } }\n",
        ]);

        $runtime = $this->runtime(['jobs']);
        $job = $runtime->job('jobs', 'collect');
        self::assertNotNull($job);
        self::assertSame(120, $job['timeout']);
        $entry = $runtime->entry('jobs', $job);
        self::assertNotNull($entry);
        self::assertSame(0, $entry(['a' => 1], 'jobs'));
    }

    public function testAFileJobReturnsACallableThatReceivesSettings(): void
    {
        $this->plugin('filer', ['cron' => [[
            'job' => 'publish', 'schedule' => '* * * * *', 'file' => 'cron/publish.php',
        ]]], [
            'cron/publish.php' => "<?php\nreturn static function (array \$settings, string \$slug): int { return \$settings['from'] === \$slug ? 0 : 1; };\n",
        ]);

        $runtime = $this->runtime(['filer']);
        $entry = $runtime->entry('filer', $runtime->job('filer', 'publish') ?? []);
        self::assertNotNull($entry);
        self::assertSame(0, $entry($runtime->settings('filer'), 'filer'));
    }

    public function testAFileEntryThatReturnsNothingFailsLoudly(): void
    {
        $this->plugin('silent', ['cron' => [[
            'job' => 'publish', 'schedule' => '* * * * *', 'file' => 'cron/publish.php',
        ]]], ['cron/publish.php' => "<?php\n\$x = 1;\n"]);

        $runtime = $this->runtime(['silent']);
        $entry = $runtime->entry('silent', $runtime->job('silent', 'publish') ?? []);
        self::assertNotNull($entry);
        $this->expectException(RuntimeException::class);
        $entry([], 'silent');
    }

    public function testSettingsArePassedThroughAndAFailureYieldsAnEmptyArray(): void
    {
        $this->plugin('settings', []);
        $runtime = new PluginRuntime(
            $this->root,
            static fn(string $slug): bool => true,
            static function (string $slug): array {
                throw new RuntimeException('database is down');
            }
        );
        self::assertSame([], $runtime->settings('settings'));
        self::assertStringContainsString('database is down',
            implode(' | ', $runtime->problems()['settings'] ?? []));
    }

    public function testAPluginCannotClaimBothAPrefixAndAFilesList(): void
    {
        $this->plugin('mixed', ['php' => [
            'namespace' => 'EchoGuard\\Mixed\\', 'path' => 'src',
            'files' => ['src/X.php' => ['XThing']],
        ]], ['src/X.php' => "<?php\nfinal class XThing {}\n"]);

        $runtime = $this->runtime(['mixed']);
        $runtime->boot();
        self::assertStringContainsString('not both',
            implode(' | ', $runtime->problems()['mixed'] ?? []));
    }

    public function testTwoPluginsCannotRegisterTheSameNamespace(): void
    {
        foreach (['first', 'second'] as $slug) {
            $this->plugin($slug, ['php' => ['namespace' => 'EchoGuard\\Shared\\', 'path' => 'src']], [
                'src/Thing.php' => "<?php\nnamespace EchoGuard\\Shared;\nfinal class Thing {}\n",
            ]);
        }
        $runtime = $this->runtime(['first', 'second']);
        $runtime->boot();
        self::assertStringContainsString('already claimed',
            implode(' | ', $runtime->problems()['second'] ?? []));
    }

    public function testACliCommandResolvesAndReceivesItsArguments(): void
    {
        $this->plugin('commands', [
            'php' => ['namespace' => 'EchoGuard\\Cli\\', 'path' => 'src'],
            'cli' => [
                'report' => ['class' => 'EchoGuard\\Cli\\Reporter::run'],
                'apply' => ['file' => 'cli/apply.php'],
            ],
        ], [
            'src/Reporter.php' => "<?php\nnamespace EchoGuard\\Cli;\nfinal class Reporter { public static function run(array \$settings, string \$slug, array \$argv = []): int { return count(\$argv); } }\n",
            'cli/apply.php' => "<?php\nreturn static fn(array \$s, string \$slug, array \$argv = []): int => 0;\n",
        ]);

        $runtime = $this->runtime(['commands']);
        self::assertSame(['report', 'apply'], array_keys($runtime->commands('commands')));

        $entry = $runtime->entry('commands', $runtime->command('commands', 'report') ?? []);
        self::assertNotNull($entry);
        self::assertSame(2, $entry([], 'commands', ['--verbose', '--json']));

        $apply = $runtime->entry('commands', $runtime->command('commands', 'apply') ?? []);
        self::assertNotNull($apply);
        self::assertSame(0, $apply([], 'commands', []));
    }

    public function testACliCommandNeedsExactlyOneSource(): void
    {
        $this->plugin('both', ['cli' => ['report' => [
            'class' => 'SomeClass::run', 'file' => 'cli/report.php',
        ]]]);

        $runtime = $this->runtime(['both']);
        $runtime->boot();
        self::assertSame([], $runtime->commands('both'));
        self::assertStringContainsString('exactly one',
            implode(' | ', $runtime->problems()['both'] ?? []));
    }

    /**
     * The loader has two catalogs, and an uploaded plugin replaces the bundled
     * one of the same slug — including the fact that the bundled copy was
     * broken. A stale problem from the shadowed manifest must not block the
     * replacement, and the replacement's own files are the ones that run.
     */
    public function testAnUploadedPluginReplacesTheBundledOneOfTheSameSlug(): void
    {
        $this->plugin('both', ['name' => 'bundled', 'cron' => [[
            'job' => 'BAD NAME', 'schedule' => '* * * * *', 'class' => 'X::y',
        ]]]);

        $uploaded = $this->outside . '/both';
        mkdir($uploaded, 0755, true);
        file_put_contents($uploaded . '/plugin.json', json_encode([
            'slug' => 'both',
            'name' => 'uploaded',
            'php' => ['namespace' => 'EchoGuard\\Uploaded\\', 'path' => 'src'],
        ]));
        mkdir($uploaded . '/src', 0755, true);
        file_put_contents($uploaded . '/src/Greeter.php',
            "<?php\nnamespace EchoGuard\\Uploaded;\nfinal class Greeter { public function who(): string { return 'uploaded'; } }\n");

        $runtime = new PluginRuntime(
            [$this->root, $this->outside],
            static fn(string $slug): bool => true
        );

        self::assertSame('uploaded', $runtime->manifests()['both']['name'] ?? null);
        self::assertSame([], $runtime->problems()['both'] ?? []);
        self::assertSame([$this->root, $this->outside], $runtime->catalogs());

        $runtime->boot();
        // The class came from the uploaded directory, not the bundled one.
        self::assertSame('uploaded', (new EchoGuard\Uploaded\Greeter())->who());
    }

    public function testAMissingCustomCatalogIsNotAProblem(): void
    {
        $this->plugin('demo', []);
        $runtime = new PluginRuntime(
            [$this->root, $this->root . '/does-not-exist'],
            static fn(string $slug): bool => true
        );

        self::assertArrayHasKey('demo', $runtime->manifests());
        self::assertSame([], $runtime->problems());
    }

    public function testNoCatalogAtAllIsReported(): void
    {
        $runtime = new PluginRuntime(
            $this->root . '/nowhere',
            static fn(string $slug): bool => true
        );

        self::assertSame([], $runtime->manifests());
        self::assertStringContainsString('no plugin catalog exists',
            implode(' | ', $runtime->problems()['*'] ?? []));
    }

    public function testTheSlugMustMatchTheFolderName(): void
    {
        $dir = $this->root . '/folder';
        mkdir($dir, 0755, true);
        file_put_contents($dir . '/plugin.json', json_encode(['slug' => 'other']));

        $runtime = $this->runtime(['other']);
        self::assertSame([], $runtime->manifests());
        self::assertStringContainsString('folder name',
            implode(' | ', $runtime->problems()['folder'] ?? []));
    }
}

/** Pre-declared so the collision test has something real to collide with. */
final class PluginRuntimeTestClash
{
}

