<?php

declare(strict_types=1);

use config\development\ConfigDetector;

/**
 * ENVIRONMENT is a constant, defined once per process, and the whole point of
 * the snapshot is that it belongs to one environment. So the parts that only
 * happen under ENVIRONMENT=production - building the snapshot, and booting off
 * it - are exercised in subprocesses rather than in this one, which is fixed at
 * 'testing' by the bootstrap.
 *
 * The rest (the export format itself) is pure and tested directly.
 */
final class ConfigDetectorTest extends unitTestHelper
{
    protected string $snapshot;

    protected function setUp(): void
    {
        require_once __ROOT__ . '/config/development/ConfigDetector.php';

        $this->snapshot = __ROOT__ . '/config/production/config.php';
    }

    /**
     * @return array{0: int, 1: string} exit status, combined output
     */
    protected function php(string $code): array
    {
        $file = tempnam(sys_get_temp_dir(), 'orange-cfg-') . '.php';
        file_put_contents($file, $code);

        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $output, $status);

        unlink($file);

        return [$status, implode(PHP_EOL, $output)];
    }

    /* the generated file */

    public function testTheCommittedSnapshotHasBothSections(): void
    {
        $snapshot = include $this->snapshot;

        $this->assertArrayHasKey('config', $snapshot);
        $this->assertArrayHasKey('deferred', $snapshot);
        $this->assertNotEmpty($snapshot['config']);
    }

    /**
     * config/input.php returns Input::fromGlobals(). Baking it would write the
     * build machine's whole environment into a committed file and then serve
     * every production request the build-time URI, method and headers - so it
     * is recorded as a path and included per request instead.
     */
    public function testInputIsDeferredRatherThanBaked(): void
    {
        $snapshot = include $this->snapshot;

        $this->assertArrayNotHasKey('input', $snapshot['config']);
        $this->assertArrayHasKey('input', $snapshot['deferred']);

        foreach ($snapshot['deferred']['input'] as $file) {
            $this->assertFileExists($file);
        }
    }

    /**
     * The regression this guards: an earlier build wrote $_SERVER into the
     * snapshot, which put PATH, HOME and every editor variable of the build
     * machine into a file destined for git.
     */
    public function testTheSnapshotHoldsNoBuildMachinePaths(): void
    {
        $contents = (string)file_get_contents($this->snapshot);

        $this->assertStringNotContainsString((string)getenv('HOME'), $contents);
        $this->assertStringNotContainsString('$_SERVER', $contents);
    }

    /**
     * Values a config file computed when it loaded have to be written back as
     * the expression that produced them, or production freezes them - the log
     * filename would stop changing on the day the snapshot was built.
     */
    public function testTimeAndMachineDependentValuesStayExpressions(): void
    {
        $contents = (string)file_get_contents($this->snapshot);

        $this->assertStringContainsString("date('Y-m-d')", $contents);
        $this->assertStringContainsString('sys_get_temp_dir()', $contents);
        $this->assertStringContainsString('__ROOT__', $contents);

        // and today's date is not sitting there as a literal
        $this->assertStringNotContainsString("'" . date('Y-m-d') . "'", $contents);
    }

    /* export() */

    public function testExportRewritesDynamicValuesAsExpressions(): void
    {
        $php = ConfigDetector::export([
            'demo' => [
                'path' => __ROOT__ . '/var/logs/' . date('Y-m-d') . '.log',
                'temp' => sys_get_temp_dir(),
                'plain' => 'nothing dynamic here',
            ],
        ]);

        $this->assertStringContainsString("__ROOT__ . '/var/logs/' . date('Y-m-d') . '.log'", $php);
        $this->assertStringContainsString("'temp' => sys_get_temp_dir()", $php);
        $this->assertStringContainsString("'plain' => 'nothing dynamic here'", $php);
    }

    /**
     * VarExporter renders a closure from its source, which a first-class
     * callable has none of - Container::getInstance(...) points at a method
     * body. Reflection knows the class and method, so the syntax is written
     * back out instead.
     */
    public function testExportRewritesFirstClassCallables(): void
    {
        $php = ConfigDetector::export([
            'demo' => [
                'fcc' => \orange\framework\Container::getInstance(...),
                'closure' => fn(int $a): int => $a + 1,
            ],
        ]);

        $this->assertStringContainsString('\orange\framework\Container::getInstance(...)', $php);

        // an ordinary closure still goes through VarExporter, which normalises
        // an arrow function to the long form rather than reproducing the source
        // verbatim - same callable, different spelling
        $this->assertStringContainsString('function (int $a): int {', $php);
        $this->assertStringContainsString('return $a + 1;', $php);
    }

    public function testExportedFileIsValidPhpThatRoundTrips(): void
    {
        $php = ConfigDetector::export(['demo' => ['a' => 1, 'b' => ['c' => 'd']]], ['live' => ['/some/file.php']]);

        $file = tempnam(sys_get_temp_dir(), 'orange-cfg-') . '.php';
        file_put_contents($file, $php);

        try {
            $loaded = include $file;

            $this->assertEquals(['a' => 1, 'b' => ['c' => 'd']], $loaded['config']['demo']);
            $this->assertEquals(['live' => ['/some/file.php']], $loaded['deferred']);
        } finally {
            unlink($file);
        }
    }

    /* isStale() */

    public function testIsStaleWhenTheSnapshotIsMissing(): void
    {
        $this->assertTrue(ConfigDetector::isStale([__ROOT__ . '/config'], '/definitely/not/here.php'));
    }

    public function testIsStaleWhenAConfigFileIsNewer(): void
    {
        $directory = $this->makeTempDir('orange-cfg-');
        $snapshot = $directory . '/config.php';

        file_put_contents($snapshot, '<?php return [];');
        touch($snapshot, time() - 60);

        file_put_contents($directory . '/thing.php', '<?php return [];');

        $this->assertTrue(ConfigDetector::isStale([$directory], $snapshot));

        // and not stale once it is rebuilt
        touch($snapshot);
        $this->assertFalse(ConfigDetector::isStale([$directory], $snapshot));

        $this->removeTempDir($directory);
    }

    /* production, in a subprocess */

    /**
     * The snapshot has to survive being loaded: every section resolves, the
     * expressions evaluate at runtime rather than staying frozen, and the
     * deferred section reflects the running process rather than the build.
     */
    public function testProductionBootsFromTheSnapshot(): void
    {
        [$status, $output] = $this->php($this->productionScript(
            'echo "sections=" . count($config->sections()) . PHP_EOL;'
            . 'echo "temp=" . $config->get("view")["temp directory"] . PHP_EOL;'
            . 'echo "log=" . $config->get("log")["filepath"] . PHP_EOL;'
            . 'echo "pdo=" . get_debug_type($config->get("services")["pdo"]) . PHP_EOL;'
            . 'echo "container=" . get_debug_type($config->get("services")["container"]) . PHP_EOL;'
            . 'echo "inputlive=" . (isset($config->get("input")["server"]["PWD"]) ? "yes" : "no") . PHP_EOL;'
        ));

        $this->assertSame(0, $status, $output);

        $this->assertStringContainsString('sections=16', $output);

        // sys_get_temp_dir() ran now, not at build time
        $this->assertStringContainsString('temp=' . sys_get_temp_dir(), $output);

        // date('Y-m-d') likewise - a frozen value would be the build date
        $this->assertStringContainsString('log=' . __ROOT__ . '/var/logs/' . date('Y-m-d') . '.log', $output);

        // both closure forms survived the export
        $this->assertStringContainsString('pdo=Closure', $output);
        $this->assertStringContainsString('container=Closure', $output);

        // the deferred section was evaluated by this process
        $this->assertStringContainsString('inputlive=yes', $output);
    }

    /**
     * A missing snapshot in production is a deploy that did not finish. Falling
     * back to discovering the cascade would run the site on whatever the
     * directories happen to hold and hide that the build step never ran.
     */
    public function testProductionFailsLoudlyWithoutASnapshot(): void
    {
        $moved = $this->snapshot . '.moved-by-test';

        rename($this->snapshot, $moved);

        try {
            [$status, $output] = $this->php($this->productionScript('echo "reached";'));

            $this->assertNotSame(0, $status);
            $this->assertStringContainsString('ConfigSnapshotNotFound', $output);
            $this->assertStringContainsString('composer config:export', $output);
            $this->assertStringNotContainsString('reached', $output);
        } finally {
            rename($moved, $this->snapshot);
        }
    }

    protected function productionScript(string $body): string
    {
        return '<?php' . PHP_EOL
            . "define('__ROOT__', " . var_export(__ROOT__, true) . ');' . PHP_EOL
            . "define('__WWW__', __ROOT__ . '/htdocs');" . PHP_EOL
            . "define('ENVIRONMENT', 'production');" . PHP_EOL
            . "define('DEBUG', false);" . PHP_EOL
            . "require __ROOT__ . '/vendor/autoload.php';" . PHP_EOL
            . '$container = \orange\framework\Application::make([__ROOT__ . "/.env"])->run();' . PHP_EOL
            . '$config = $container->config;' . PHP_EOL
            . $body . PHP_EOL;
    }
}
