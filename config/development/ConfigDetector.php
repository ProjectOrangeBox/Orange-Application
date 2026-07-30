<?php

declare(strict_types=1);

namespace config\development;

// This file is namespaced, so framework classes need explicit imports rather
// than relying on global-namespace resolution.
use Brick\VarExporter\VarExporter;
use orange\framework\exceptions\filesystem\DirectoryNotFound;
use orange\framework\exceptions\filesystem\DirectoryNotWritable;
use Closure;
use ReflectionFunction;

/**
 * Builds the production configuration snapshot.
 *
 * The third of the detectors, after RouterDetector and ViewDetector, and the
 * same bargain: a cascade that has to be discovered and merged on every request
 * is discovered once and written out as a plain PHP array, so production
 * includes one opcache-resident file instead of globbing directories and
 * including a file per section.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Why this one cannot live in a config file
 *
 * RouterDetector runs from config/development/routes.php and ViewDetector from
 * config/development/views.php - each produces one section, so the config
 * cascade can load them like any other. This one produces the *whole* cascade,
 * so loading it from inside the cascade would be circular. It is driven from
 * bin/configExport instead.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Why bin/configExport defines ENVIRONMENT and DEBUG itself
 *
 * A snapshot is only valid for the environment it was built in. Application
 * define()s ENVIRONMENT and DEBUG from .env and guards both with defined(), so
 * a process that sets them first decides which cascade gets merged and what a
 * value like 'debug' => DEBUG freezes to. Running this from an ordinary
 * development request would bake ENVIRONMENT=development, DEBUG=true and the
 * config/development overrides into a file named production - which is why the
 * only supported entry point is a CLI process that sets them to production
 * before booting anything.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Values that must not be frozen
 *
 * Baking values means whatever a config file computed at load time is what
 * production gets forever. Three kinds of value in this cascade cannot survive
 * that, so they are written back out as the expression that produced them
 * rather than as the literal it evaluated to:
 *
 *     __ROOT__ . '/var/logs/' . date('Y-m-d') . '.log'   framework log.php
 *     sys_get_temp_dir()                                  framework view.php
 *     __ROOT__ . '/...'                                   any absolute path
 *
 * Frozen, the log filename would stop changing on the day the snapshot was
 * built, the temp directory would be the build machine's, and every absolute
 * path would break the moment the project is mounted somewhere else.
 *
 * This is done by substring match against the value each expression produces
 * right now - the exported array holds strings, not the expressions that built
 * them, so there is nothing to trace back. That is exact for paths, which are
 * long and specific. It is a guess for date('Y-m-d'), which is eight characters
 * that a config value could legitimately equal on the day of the build; see
 * EXPRESSIONS if that ever bites.
 */
class ConfigDetector
{
    /** token wrapped around an expression placeholder while exporting */
    private const string TOKEN = '@@ORANGE_EXPRESSION_';

    /**
     * Values that have to be re-emitted as the expression that produced them,
     * longest first so a longer match is never shadowed by a shorter one it
     * contains.
     *
     * Add to this when a config file starts computing something at load time -
     * or better, stop computing it there.
     *
     * @return array<string, string> current value => PHP expression
     */
    protected static function expressions(): array
    {
        $expressions = [
            __ROOT__ => '__ROOT__',
            sys_get_temp_dir() => 'sys_get_temp_dir()',
            date('Y-m-d') => "date('Y-m-d')",
        ];

        // longest value first
        uksort($expressions, static fn($a, $b): int => strlen((string)$b) <=> strlen((string)$a));

        return $expressions;
    }

    /**
     * Merge the whole cascade and write it to $productionPathWrite/config.php.
     *
     * @param array<string, mixed> $config every bakeable section, already merged - see bin/configExport
     * @param array<string, list<string>> $deferred section => files, for sections
     *        that must be evaluated per request rather than baked
     * @param string $productionPathWrite directory to write config.php into
     * @throws DirectoryNotFound|DirectoryNotWritable
     */
    public static function write(array $config, array $deferred, string $productionPathWrite): string
    {
        if (ENVIRONMENT != 'production') {
            echo 'The ' . self::class . ' builds a production snapshot and must run with ENVIRONMENT=production.' . PHP_EOL;
            echo 'ENVIRONMENT is currently "' . ENVIRONMENT . '", which would bake the wrong cascade.' . PHP_EOL;
            echo 'Use bin/configExport (composer config:export), which sets it before booting.' . PHP_EOL;

            exit(1);
        }

        if (!is_dir($productionPathWrite)) {
            throw new DirectoryNotFound($productionPathWrite);
        }

        if (!is_writable($productionPathWrite)) {
            throw new DirectoryNotWritable($productionPathWrite);
        }

        $file = $productionPathWrite . '/config.php';

        file_put_contents($file, static::export($config, $deferred));

        return $file;
    }

    /**
     * Render the merged cascade as a complete PHP config file.
     *
     * @param array<string, mixed> $config
     * @param array<string, list<string>> $deferred
     */
    public static function export(array $config, array $deferred = []): string
    {
        ksort($config);
        ksort($deferred);

        [$payload, $expressions] = static::extractExpressions(['config' => $config, 'deferred' => $deferred]);

        // ADD_RETURN writes the leading "return"; closures are exported from
        // their source, which is the whole reason this uses VarExporter rather
        // than var_export() - config/services.php is closures
        $exported = VarExporter::export($payload, VarExporter::ADD_RETURN | VarExporter::TRAILING_COMMA_IN_ARRAY);

        // put the expressions back, replacing the quoted placeholder with raw
        // PHP. The placeholder is unique and always exported as a plain single
        // quoted string, so this cannot touch anything else - including the
        // bodies of the exported closures
        foreach ($expressions as $placeholder => $expression) {
            $exported = str_replace("'" . $placeholder . "'", $expression, $exported);
        }

        $php = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            '// Generated by ' . self::class . ' - do not edit by hand.',
            '// Regenerate with: composer config:export',
            '//',
            '// config   - every bakeable section, already merged, for ENVIRONMENT=production',
            '// deferred - sections that are request state rather than configuration, so',
            '//            only their file paths are recorded and they are included per',
            '//            request. Baking config/input.php would write the build machine',
            '//            environment into this file and then serve every request the',
            '//            build-time URI, method and headers.',
            '',
            $exported,
        ];

        return implode(PHP_EOL, $php) . PHP_EOL;
    }

    /**
     * Is the snapshot older than any file that feeds it?
     *
     * Used by the development refresh so an edit to a config file does not go
     * unnoticed until deploy.
     *
     * @param list<string> $configDirectories
     */
    public static function isStale(array $configDirectories, string $snapshot): bool
    {
        if (!is_file($snapshot)) {
            return true;
        }

        $snapshotTime = (int)filemtime($snapshot);

        foreach ($configDirectories as $directory) {
            foreach (glob(rtrim($directory, '/') . '/*.php', GLOB_NOSORT) ?: [] as $file) {
                // the snapshot lives in one of these directories, so skip it -
                // it is never its own input
                if ($file !== $snapshot && filemtime($file) > $snapshotTime) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Replace every value that must stay dynamic with a unique placeholder, and
     * return the expression each placeholder stands for.
     *
     * Only strings are touched. A value like DEBUG is a bool by the time it gets
     * here and carries nothing to match on, which is exactly why the snapshot
     * has to be built by a process already configured as production rather than
     * patched up afterwards.
     *
     * @param array<string, mixed> $config
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    protected static function extractExpressions(array $config): array
    {
        $expressions = static::expressions();
        $placeholders = [];
        $next = 0;

        $walk = function (array $input) use (&$walk, $expressions, &$placeholders, &$next): array {
            $output = [];

            foreach ($input as $key => $value) {
                if (is_array($value)) {
                    $output[$key] = $walk($value);

                    continue;
                }

                if (is_string($value) && ($expression = static::toExpression($value, $expressions)) !== null) {
                    $placeholder = self::TOKEN . $next++ . '@@';
                    $placeholders[$placeholder] = $expression;
                    $output[$key] = $placeholder;

                    continue;
                }

                if ($value instanceof Closure && ($expression = static::toCallableExpression($value)) !== null) {
                    $placeholder = self::TOKEN . $next++ . '@@';
                    $placeholders[$placeholder] = $expression;
                    $output[$key] = $placeholder;

                    continue;
                }

                $output[$key] = $value;
            }

            return $output;
        };

        return [$walk($config), $placeholders];
    }

    /**
     * Render a first-class callable as the syntax that produced it.
     *
     * VarExporter exports a closure by reading its source, which a first-class
     * callable does not have - Container::getInstance(...) points at a method
     * body, not a closure literal, and the export fails with "Expected exactly
     * 1 closure ... found 0". Reflection knows the class and method, so the
     * original syntax can simply be written back out.
     *
     * Ordinary closures and arrow functions return null here and go to
     * VarExporter as normal, which is what it is good at.
     */
    protected static function toCallableExpression(Closure $closure): ?string
    {
        $reflection = new ReflectionFunction($closure);
        $name = $reflection->getName();

        // an anonymous closure is named '{closure:file:line}'
        if (str_starts_with($name, '{closure')) {
            return null;
        }

        $class = $reflection->getClosureCalledClass();

        // a plain function reference - strlen(...) and friends
        return $class === null ? '\\' . $name . '(...)' : '\\' . $class->getName() . '::' . $name . '(...)';
    }

    /**
     * Rewrite one string as a PHP concatenation of expressions and literals, or
     * null when it holds nothing dynamic.
     *
     * @param array<string, string> $expressions
     */
    protected static function toExpression(string $value, array $expressions): ?string
    {
        $parts = [$value];

        foreach ($expressions as $needle => $expression) {
            if ($needle === '') {
                continue;
            }

            $split = [];

            foreach ($parts as $part) {
                // an already-substituted expression is not a string to re-split
                if (!is_string($part)) {
                    $split[] = $part;

                    continue;
                }

                $pieces = explode((string)$needle, $part);

                foreach ($pieces as $index => $piece) {
                    if ($index > 0) {
                        // marked as an object so a later needle cannot match
                        // inside an expression already substituted
                        $split[] = (object)['php' => $expression];
                    }

                    if ($piece !== '') {
                        $split[] = $piece;
                    }
                }
            }

            $parts = $split;
        }

        // nothing matched
        if (count($parts) === 1 && is_string($parts[0])) {
            return null;
        }

        $rendered = [];

        foreach ($parts as $part) {
            $rendered[] = is_string($part) ? var_export($part, true) : $part->php;
        }

        return $rendered === [] ? "''" : implode(' . ', $rendered);
    }
}
