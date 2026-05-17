<?php

declare(strict_types=1);

namespace ParaTest\WrapperRunner;

use Generator;
use ParaTest\Options;
use PHPUnit\Event\Facade as EventFacade;
use PHPUnit\Framework\Test;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\TestSuite;
use PHPUnit\Runner\Extension\ExtensionBootstrapper;
use PHPUnit\Runner\Extension\PharLoader;
use PHPUnit\Runner\Phpt\TestCase as PhptTestCase;
use PHPUnit\Runner\ResultCache\DefaultResultCache;
use PHPUnit\Runner\ResultCache\NullResultCache;
use PHPUnit\Runner\TestSuiteSorter;
use PHPUnit\TestRunner\TestResult\Facade as TestResultFacade;
use PHPUnit\TextUI\Command\Result;
use PHPUnit\TextUI\Command\WarmCodeCoverageCacheCommand;
use PHPUnit\TextUI\Configuration\CodeCoverageFilterRegistry;
use PHPUnit\TextUI\Configuration\PhpHandler;
use PHPUnit\TextUI\Configuration\TestSuiteBuilder;
use PHPUnit\TextUI\TestSuiteFilterProcessor;
use Random\Engine\Mt19937;
use Random\Randomizer;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\Console\Output\OutputInterface;

use function array_filter;
use function array_keys;
use function array_merge;
use function array_pop;
use function array_slice;
use function array_unique;
use function array_values;
use function assert;
use function ceil;
use function class_exists;
use function count;
use function is_int;
use function is_string;
use function mt_srand;
use function ob_get_clean;
use function ob_start;
use function preg_quote;
use function sprintf;
use function str_starts_with;
use function strlen;
use function substr;

use const ARRAY_FILTER_USE_KEY;

/** @internal */
final readonly class SuiteLoader
{
    public int $testCount;
    /** @var list<non-empty-string> */
    public array $tests;

    /**
     * Transitive `@depends` ancestor map for every `TestCase` encountered during
     * suite loading. Keys are `"Class::method"`; values are flat ancestor lists
     * (not just direct deps — closure computed in {@see buildDependsMap()}).
     *
     * Consumed by `FailedTestExtractor` to expand a failed test's retry work-item
     * set with its `@depends` ancestors so PHPUnit does not mark them
     * `"This test depends on X::Y to pass"` skipped on retry.
     *
     * Populated by Pass 1 (`loadFiles()` iteration collects direct edges) + Pass 2
     * (`buildDependsMap()` iterative DFS with memoization + cycle guard).
     *
     * @var array<string, list<string>>
     */
    public array $dependsMap;
    /** @var array<string, non-empty-string> */
    public array $testFileMap;

    public function __construct(
        private Options $options,
        OutputInterface $output,
        CodeCoverageFilterRegistry $codeCoverageFilterRegistry,
    ) {
        (new PhpHandler())->handle($this->options->configuration->php());

        if ($this->options->configuration->hasBootstrap()) {
            $bootstrapFilename = $this->options->configuration->bootstrap();
            include_once $bootstrapFilename;
            EventFacade::emitter()->testRunnerBootstrapFinished($bootstrapFilename);
        }

        if (! $this->options->configuration->noExtensions()) {
            if ($this->options->configuration->hasPharExtensionDirectory()) {
                (new PharLoader())->loadPharExtensionsInDirectory(
                    $this->options->configuration->pharExtensionDirectory(),
                );
            }

            $extensionFacadeClass  = class_exists('PHPUnit\Runner\Extension\ExtensionFacade')
                ? 'PHPUnit\Runner\Extension\ExtensionFacade'
                : 'PHPUnit\Runner\Extension\Facade';
            $extensionFacade       = new $extensionFacadeClass();
            $extensionBootstrapper = new ExtensionBootstrapper(
                $this->options->configuration,
                $extensionFacade,
            );

            foreach ($this->options->configuration->extensionBootstrappers() as $bootstrapper) {
                $extensionBootstrapper->bootstrap(
                    $bootstrapper['className'],
                    $bootstrapper['parameters'],
                );
            }
        }

        TestResultFacade::init();
        EventFacade::instance()->seal();

        $testSuite = (new TestSuiteBuilder())->build($this->options->configuration);

        if ($this->options->hasShard()) {
            $this->shardTests($testSuite);
        }

        if ($this->options->configuration->executionOrder() === TestSuiteSorter::ORDER_RANDOMIZED) {
            mt_srand($this->options->configuration->randomOrderSeed());
        }

        if (
            $this->options->configuration->executionOrder() !== TestSuiteSorter::ORDER_DEFAULT ||
            $this->options->configuration->executionOrderDefects() !== TestSuiteSorter::ORDER_DEFAULT ||
            $this->options->configuration->resolveDependencies()
        ) {
            $resultCache = new NullResultCache();
            if ($this->options->configuration->cacheResult()) {
                $resultCache = new DefaultResultCache($this->options->configuration->testResultCacheFile());
                $resultCache->load();
            }

            (new TestSuiteSorter($resultCache))->reorderTestsInSuite(
                $testSuite,
                $this->options->configuration->executionOrder(),
                $this->options->configuration->resolveDependencies(),
                $this->options->configuration->executionOrderDefects(),
            );
        }

        (new TestSuiteFilterProcessor())->process($this->options->configuration, $testSuite);

        $this->testCount = count($testSuite);

        $files = [];
        $tests = [];
        /** @var array<string, list<string>> $directDeps */
        $directDeps = [];
        /** @var array<string, bool> $knownKeys */
        $knownKeys = [];
        /** @var array<string, non-empty-string> $testFileMap */
        $testFileMap = [];
        foreach ($this->loadFiles($testSuite) as $file => $test) {
            $files[$file] = null;

            if ($test instanceof PhptTestCase) {
                $tests[] = $file;
                continue;
            }

            $name = $test->name();
            if ($test->providedData() !== []) {
                $dataName = $test->dataName();
                if ($this->options->functional) {
                    $name = sprintf('/%s%s$/', preg_quote($name, '/'), preg_quote($test->dataSetAsString(), '/'));
                } else {
                    if (is_int($dataName)) {
                        $name .= '#' . $dataName;
                    } else {
                        $name .= '@' . $dataName;
                    }
                }
            } else {
                $name = sprintf('/%s$/', $name);
            }

            $tests[] = "$file\0$name";

            // Pass 1 — direct `@depends` edges.
            // Key = "Class::method" of the dependent test. PHPT and `DataProviderTestSuite`
            // nodes are skipped above / traversed by loadFiles; they don't participate in @depends.
            $key               = $test::class . '::' . $test->name();
            $knownKeys[$key]   = true;
            $testFileMap[$key] = $file;
            $edges             = [];
            foreach ($test->requires() as $dependency) {
                if (! $dependency->isValid()) {
                    continue;
                }

                // Plain "Class::method" dep (#[Depends], #[DependsUsingDeepClone],
                // #[DependsUsingShallowClone] are all surfaced the same way here —
                // clone strategy is handled by PHPUnit internally at run time).
                if (! $dependency->targetIsClass()) {
                    $edges[] = $dependency->getTarget();
                    continue;
                }

                // #[DependsOnClass] — placeholder; resolved in Pass 2 by expanding
                // to all known "$className::..." keys in $directDeps.
                $edges[] = '::' . $dependency->getTargetClassName();
            }

            if ($edges === []) {
                continue;
            }

            $directDeps[$key] = $edges;
        }

        $this->dependsMap  = $this->buildDependsMap($directDeps, $knownKeys);
        $this->testFileMap = $testFileMap;

        $this->tests = $this->options->functional
            ? $tests
            : array_keys($files);

        if (! $this->options->configuration->hasCoverageReport()) {
            return;
        }

        ob_start();
        $result       = (new WarmCodeCoverageCacheCommand(
            $this->options->configuration,
            $codeCoverageFilterRegistry,
        ))->execute();
        $ob_get_clean = ob_get_clean();
        assert($ob_get_clean !== false);
        $output->write($ob_get_clean);
        $output->write($result->output());
        if ($result->shellExitCode() !== Result::SUCCESS) {
            exit($result->shellExitCode());
        }
    }

    /** @return Generator<non-empty-string, (PhptTestCase|TestCase)> */
    private function loadFiles(TestSuite $testSuite): Generator
    {
        foreach ($testSuite as $test) {
            if ($test instanceof TestSuite) {
                yield from $this->loadFiles($test);

                continue;
            }

            if ($test instanceof PhptTestCase) {
                $refProperty = new ReflectionProperty(PhptTestCase::class, 'filename');
                $filename    = $refProperty->getValue($test);
                assert(is_string($filename) && $filename !== '');
                $filename = $this->stripCwd($filename);

                yield $filename => $test;

                continue;
            }

            if ($test instanceof TestCase) {
                $refClass = new ReflectionClass($test);
                $filename = $refClass->getFileName();
                assert(is_string($filename));
                $filename = $this->stripCwd($filename);

                yield $filename => $test;

                continue;
            }
        }
    }

    /**
     * Pass 2 — compute the transitive closure of `@depends` ancestors for every
     * test key that declared at least one direct dep.
     *
     * Uses iterative DFS with an explicit stack (recursion would risk stack
     * overflow on long `@depends` chains). Memoizes visited nodes across
     * overlapping subgraphs; cycles (PHPUnit already rejects them but we guard
     * defensively) are broken by marking a node `in-progress` and ignoring
     * back-edges.
     *
     * `#[DependsOnClass]` placeholders emitted by Pass 1 in the form
     * `"::ClassName"` are expanded here to every known key with a matching
     * `"ClassName::"` prefix.
     *
     * @param array<string, list<string>> $directDeps
     * @param array<string, bool>         $knownKeys
     *
     * @return array<string, list<string>>
     */
    private function buildDependsMap(array $directDeps, array $knownKeys): array
    {
        // Expand #[DependsOnClass] placeholders into concrete edges.
        $expanded = [];
        foreach ($directDeps as $key => $edges) {
            $resolved = [];
            foreach ($edges as $edge) {
                if (str_starts_with($edge, '::')) {
                    $className = substr($edge, 2);
                    $prefix    = $className . '::';
                    foreach (array_keys($knownKeys) as $knownKey) {
                        if (! str_starts_with($knownKey, $prefix)) {
                            continue;
                        }

                        if ($knownKey === $key) {
                            // Never depend on self.
                            continue;
                        }

                        $resolved[] = $knownKey;
                    }

                    continue;
                }

                $resolved[] = $edge;
            }

            $expanded[$key] = array_values(array_unique($resolved));
        }

        $result = [];
        /** @var array<string, int> $state 0 = unvisited, 1 = in-progress, 2 = done */
        $state = [];

        foreach (array_keys($expanded) as $startKey) {
            if (isset($result[$startKey])) {
                continue;
            }

            // Iterative DFS: stack entries carry (currentKey, iteratorIndex, ancestors-so-far).
            /** @var list<array{0: string, 1: int, 2: list<string>}> $stack */
            $stack            = [[$startKey, 0, []]];
            $state[$startKey] = 1;

            while ($stack !== []) {
                $top      = &$stack[count($stack) - 1];
                $key      = $top[0];
                $idx      = $top[1];
                $children = $expanded[$key] ?? [];

                if ($idx >= count($children)) {
                    // All children explored — compute this node's ancestor set.
                    $acc = [];
                    foreach ($children as $child) {
                        $acc[] = $child;
                        foreach ($result[$child] ?? [] as $grand) {
                            $acc[] = $grand;
                        }
                    }

                    $result[$key] = array_values(array_unique($acc));
                    $state[$key]  = 2;
                    unset($top);
                    array_pop($stack);
                    continue;
                }

                $child  = $children[$idx];
                $top[1] = $idx + 1;
                unset($top);

                if (isset($result[$child])) {
                    continue;
                }

                if (($state[$child] ?? 0) === 1) {
                    // Cycle — defensive guard; PHPUnit itself rejects these.
                    continue;
                }

                if (! isset($expanded[$child])) {
                    // Leaf dep (no further deps) — record empty ancestor set and move on.
                    $result[$child] = [];
                    $state[$child]  = 2;
                    continue;
                }

                $state[$child] = 1;
                $stack[]       = [$child, 0, []];
            }
        }

        return $result;
    }

    /**
     * @param non-empty-string $filename
     *
     * @return non-empty-string
     */
    private function stripCwd(string $filename): string
    {
        if (! str_starts_with($filename, $this->options->cwd)) {
            return $filename;
        }

        $substr = substr($filename, 1 + strlen($this->options->cwd));
        assert($substr !== '');

        return $substr;
    }

    private function shardTests(TestSuite $suite): void
    {
        $shards  = $this->options->totalShards;
        $current = $this->options->currentShard - 1; // 0 indexed. Shard 1 is in reality shard 0

        // With --functional, shard over individual test methods (values).
        // Without --functional, shard over class-level suites (keys) to keep whole files together.
        $items = $this->options->functional
            ? $this->extractTestsInSuite($suite)
            : $this->extractClassSuites($suite);

        $shardedItems = match ($this->options->shardDistribution) {
            ShardDistribution::Sequential => array_slice($items, (int) ceil(count($items) / $shards) * $current, (int) ceil(count($items) / $shards)),
            ShardDistribution::RoundRobin => array_values(array_filter($items, static fn (int $i): bool => $i % $shards === $current, ARRAY_FILTER_USE_KEY)),
            ShardDistribution::Random => $this->randomShardTests($items, $shards, $current),
        };

        $suite->setTests($shardedItems);
    }

    /**
     * @param list<Test> $tests
     *
     * @return list<Test>
     */
    private function randomShardTests(array $tests, int $shards, int $current): array
    {
        $randomizer = new Randomizer(new Mt19937($this->options->shardDistributionSeed));
        /** @var list<Test> $tests */
        $tests = $randomizer->shuffleArray($tests);

        return array_values(array_filter($tests, static fn (int $i): bool => $i % $shards === $current, ARRAY_FILTER_USE_KEY));
    }

    /** @return list<TestSuite> */
    private function extractClassSuites(TestSuite $suite): array
    {
        $classSuites = [];

        foreach ($suite->tests() as $item) {
            if (! ($item instanceof TestSuite)) {
                continue;
            }

            $children = $item->tests();
            if ($children !== [] && $children[0] instanceof TestSuite) {
                $classSuites = array_merge($classSuites, $this->extractClassSuites($item));
            } else {
                $classSuites[] = $item;
            }
        }

        return $classSuites;
    }

    /** @return list<Test> */
    private function extractTestsInSuite(TestSuite $suite): array
    {
        $extractedTests = [];
        $suiteItems     = $suite->tests();

        foreach ($suiteItems as $item) {
            if ($item instanceof TestSuite) {
                $extractedTests = array_merge($extractedTests, $this->extractTestsInSuite($item));
            } else {
                $extractedTests[] = $item;
            }
        }

        return $extractedTests;
    }
}
