<?php

declare(strict_types=1);

use config\development\ViewDetector;

/**
 * ViewDetector produces two things that must agree: the array it returns for
 * development to consume directly, and the file it writes for production to
 * include. They are built from the same scan but formatted differently - the
 * production file stores paths relative to __ROOT__ and re-prepends it as an
 * expression so the snapshot survives being deployed somewhere else.
 *
 * That split is exactly where they drifted once: the returned array kept the
 * relative strings, so development resolved every view to a path that did not
 * exist while production was fine. These tests pin both halves.
 */
final class ViewDetectorTest extends unitTestHelper
{
    /**
     * detect() refuses to run outside development and the test bootstrap fixes
     * ENVIRONMENT to 'testing', so go at the scan underneath it instead.
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    protected function scan(): array
    {
        require_once __ROOT__ . '/config/development/ViewDetector.php';

        $method = new ReflectionMethod(ViewDetector::class, 'findViewsInPsr4Roots');

        [$views, $fallbacks] = $method->invoke(null, []);

        return [$views, $fallbacks];
    }

    /**
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    protected function snapshot(): array
    {
        $production = include __ROOT__ . '/config/production/views.php';

        return [$production['views'], $production['view fallbacks']];
    }

    public function testScanFindsViews(): void
    {
        [$views, $fallbacks] = $this->scan();

        // a guard for the assertions below - every one of them passes trivially
        // against empty arrays
        $this->assertNotEmpty($views);
        $this->assertNotEmpty($fallbacks);
    }

    /**
     * The regression: a development request consumes this array as-is, so a
     * relative path here is a view that can never be rendered.
     */
    public function testEveryScannedPathIsAbsoluteAndExists(): void
    {
        [$views, $fallbacks] = $this->scan();

        foreach ($views + $fallbacks as $name => $path) {
            $this->assertStringStartsWith(__ROOT__, $path, $name . ' is not an absolute path');
            $this->assertFileExists($path, $name . ' does not resolve to a real file');
        }
    }

    /**
     * The production snapshot rebuilds absolute paths from __ROOT__, so it has
     * to land on real files too - on this machine at least, which is the only
     * one that can be checked from here.
     */
    public function testEverySnapshotPathIsAbsoluteAndExists(): void
    {
        [$views, $fallbacks] = $this->snapshot();

        foreach ($views + $fallbacks as $name => $path) {
            $this->assertStringStartsWith(__ROOT__, $path, $name . ' is not an absolute path');
            $this->assertFileExists($path, $name . ' does not resolve to a real file');
        }
    }

    /**
     * Development and production must resolve every name to the same file. This
     * is also the check that catches a snapshot nobody regenerated after adding
     * or renaming a view - if it fails, run the app once in development and
     * commit config/production/views.php.
     */
    public function testProductionSnapshotMatchesAFreshScan(): void
    {
        [$scannedViews, $scannedFallbacks] = $this->scan();
        [$storedViews, $storedFallbacks] = $this->snapshot();

        $this->assertEquals($scannedViews, $storedViews, 'config/production/views.php is out of date - regenerate it');
        $this->assertEquals($scannedFallbacks, $storedFallbacks, 'config/production/views.php is out of date - regenerate it');
    }

    /**
     * Application modules are scanned before vendor packages and the first
     * writer of a fallback key keeps it, which is the whole override mechanism.
     */
    /**
     * Matching is case insensitive and the folding happens here, once, rather
     * than on every lookup - so every generated key has to arrive lower cased.
     */
    public function testEveryGeneratedKeyIsLowerCased(): void
    {
        [$views, $fallbacks] = $this->scan();

        foreach (array_keys($views + $fallbacks) as $name) {
            $this->assertEquals(mb_convert_case((string)$name, MB_CASE_LOWER, 'UTF-8'), $name);
        }
    }

    /**
     * Aliases are name -> name, so both halves are folded: the key because a
     * lookup is matched against it, the target because it is then looked up in
     * the maps.
     */
    public function testHandWrittenAliasesAreNormalizedOnBothSides(): void
    {
        require_once __ROOT__ . '/config/development/ViewDetector.php';

        $method = new ReflectionMethod(ViewDetector::class, 'findViewsInPsr4Roots');

        [, , $aliases] = $method->invoke(null, [
            'view aliases' => ['/DashBoard/' => 'Main/Index'],
        ]);

        $this->assertEquals(['dashboard' => 'main/index'], $aliases);
    }

    public function testHandWrittenExtrasHoldTheirKeysAgainstTheScan(): void
    {
        require_once __ROOT__ . '/config/development/ViewDetector.php';

        $method = new ReflectionMethod(ViewDetector::class, 'findViewsInPsr4Roots');

        // extras go in before anything is scanned, and first writer wins
        [, $fallbacks] = $method->invoke(null, [
            'view fallbacks' => ['Main/Index' => '/somewhere/else.php'],
        ]);

        $this->assertEquals('/somewhere/else.php', $fallbacks['main/index']);
    }

    /**
     * Two files whose names differ only in case fold to one key and cannot both
     * be addressed, so the second is dropped and recorded rather than silently
     * overwriting the first.
     *
     * Exercised through claim() directly: this repository is developed on a
     * case-insensitive filesystem, where the pair of files that triggers it
     * cannot be created in the first place.
     */
    public function testACaseCollisionIsRecordedRatherThanOverwriting(): void
    {
        require_once __ROOT__ . '/config/development/ViewDetector.php';

        $method = new ReflectionMethod(ViewDetector::class, 'claim');

        $map = [];
        $shadowed = [];

        $args = [&$map, &$shadowed, 'main/index', '/app/views/main/index.php'];
        $method->invokeArgs(null, $args);

        $args = [&$map, &$shadowed, 'main/index', '/app/views/Main/Index.php'];
        $method->invokeArgs(null, $args);

        $this->assertEquals(['main/index' => '/app/views/main/index.php'], $map);
        $this->assertEquals(['main/index' => ['/app/views/Main/Index.php']], $shadowed);
    }

    public function testApplicationViewsAreNamespacedAndVendorViewsAreNot(): void
    {
        [$views, $fallbacks] = $this->scan();

        // an application view is reachable both ways
        $this->assertArrayHasKey('application/welcome/main/index', $views);
        $this->assertArrayHasKey('main/index', $fallbacks);
        $this->assertEquals($views['application/welcome/main/index'], $fallbacks['main/index']);

        // a vendor view is reachable only un-namespaced, so an application
        // module can claim its key
        $this->assertArrayHasKey('errors/html/404', $fallbacks);
        $this->assertStringContainsString('/vendor/', $fallbacks['errors/html/404']);

        foreach (array_keys($views) as $name) {
            $this->assertStringNotContainsString('/vendor/', $views[$name], $name . ' should not be namespaced');
        }
    }
}
