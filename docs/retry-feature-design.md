# Retry Feature — Design

This document specifies the design of paratest's `--retry` feature: re-execute failing tests up to N times and surface only the last attempt's outcome while preserving all artifacts (coverage, JUnit, TestDox). Branch: `7.x`. No production code is proposed here; every design claim is backed by a concrete file:line reference.

## 1. User decisions (constraints — fixed)

- **R1** — JUnit retry metadata is opt-in via `--junit-retry-metadata`, default off. When off, JUnit XML contains only the last attempt (bit-identical to current behavior). When on, emit Surefire-convention `<flakyFailure>` / `<flakyError>` / `<rerunFailure>` / `<rerunError>` children under `<testcase>` (one per prior attempt) plus a redundant `retries="N"` attribute for quick parsing. See §3.3 for selection rules.
- **R2** — Final exit code is 0 if last attempt passed. Exit > 0 only if final attempt still fails.
- **R3** — Worker crash is NOT retriable in MVP. `WorkerCrashedException` (thrown at `WrapperRunner.php:156, 222`) propagates. `--retry-on=crash` is a follow-up.
- **R4** — When `--retry>0`, always print `Flaky tests (N): …` section after the regular summary. When `--retry=0`, no section is printed.
- **R5** — `--retry` accepted range `[0, 10]`; throw `InvalidArgumentException` in `Options::fromConsoleInput` for values outside.
- **R6** — Coverage = union across all attempts. Existing `Merger` in `generateCodeCoverageReports()` (`WrapperRunner.php:411`) handles multi-file merge; coverage files from prior attempts must NOT be deleted between attempts.

---

## 2. T1: RetryOrchestrator

### 2.1 Class: `RetryOrchestrator`

**Location:** `src/WrapperRunner/RetryOrchestrator.php` (new).
**Rationale:** it is a collaborator of `WrapperRunner` and uses runner-private concepts (worker pool, pending queue, tmpDir layout), so it stays in the `ParaTest\WrapperRunner` namespace.

```php
final class RetryOrchestrator
{
    public function __construct(
        private readonly Options $options,
        private readonly OutputInterface $output,
        private readonly SuiteLoader $suiteLoader,            // for @depends graph, functional-mode formatting
        private readonly FailedTestExtractor $extractor,      // see §3.1
    ) {}

    /** @param callable(int $attempt, list<non-empty-string>): AttemptOutcome $attemptRunner */
    public function orchestrate(array $initialPending, callable $attemptRunner): RetryResult;
}
```

**State (all transient, reset per `orchestrate()` call):**

- `array<int, AttemptOutcome> $attempts` — keyed by 1-based attempt number.
- `array<int, list<non-empty-string>> $archivedJunitsPerAttempt` — path of each attempt's archived JUnit files, consumed by `LogMerger::mergeAcrossAttempts()` (§3.3).
- `list<string> $flakyTests` — tests that failed at least once but passed in the final attempt; drives R4 summary.

**Public API:**

- `orchestrate(array $initialPending, callable $attemptRunner): RetryResult` — runs `options.retry + 1` attempts maximum; after every non-final attempt calls `$this->extractor->extractFailures()` on the just-finished attempt's JUnit files; stops early if pending list becomes empty or `stopOnFailure()` triggered.
- `getFlakyTestList(): list<string>` — returns deduped names (for R4 summary).

**Why a separate class, not more methods on `WrapperRunner`:** `WrapperRunner::run()` currently contains 8 sequential phases (`WrapperRunner.php:120-140`) with significant private state (`$pending`, `$workers`, `$junitFiles`, etc., `WrapperRunner.php:58-86`). Conflating retry orchestration with worker-pool lifecycle would double the class size and obscure the invariant "retry=0 behaves exactly as today". An orchestrator that *calls back* into `WrapperRunner::runAttempt()` (§2.3) keeps the retry concern isolated and makes R6 (coverage union) trivial: the orchestrator simply never truncates the coverage file list between attempts.

**Dependencies injected into ctor:**

1. `Options` — for `retry`, `junitRetryMetadata`, `tmpDir`, `functional`, `configuration->stopOnFailure()`.
2. `OutputInterface` — diagnostics only.
3. `SuiteLoader` — needed because `@depends` propagation (§2.4) uses the same PHPUnit `TestSuite` object the loader already built at `SuiteLoader.php:98`. Re-building would violate R6 (random seed) and the one-time `TestResultFacade::init()` at `SuiteLoader.php:95`.
4. `FailedTestExtractor` — §3.1.

### 2.2 Value object: `AttemptOutcome`

**Location:** `src/WrapperRunner/AttemptOutcome.php` (new).

```php
final readonly class AttemptOutcome
{
    public function __construct(
        public int $attemptNumber,                     // 1-based
        public list<non-empty-string> $executedWorkItems,
        /** @var list<SplFileInfo> */ public array $junitFiles,
        /** @var list<SplFileInfo> */ public array $coverageFiles,
        /** @var list<SplFileInfo> */ public array $testResultFiles,
        /** @var list<SplFileInfo> */ public array $testdoxFiles,
        /** @var list<SplFileInfo> */ public array $teamcityFiles,
        /** @var list<SplFileInfo> */ public array $resultCacheFiles,
        /** @var list<SplFileInfo> */ public array $progressFiles,
        /** @var list<SplFileInfo> */ public array $unexpectedOutputFiles,
        /** @var list<SplFileInfo> */ public array $statusFiles,
        public int $exitcode,
        public TestResult $testResultAggregate,         // result of complete()-like aggregation for this attempt
    ) {}
}
```

**Justification of fields:** mirrors the ten private accumulator arrays in `WrapperRunner.php:69-86`. `exitcode` mirrors `WrapperRunner::$exitcode` (`WrapperRunner.php:59, 198`). `testResultAggregate` is the per-attempt partial merge used by R4 (flaky counting): we need to know, for the *final* attempt, what is still failing and therefore contributes to R2's exit code.

### 2.3 `WrapperRunner::runAttempt()` refactor

**Current shape** (`WrapperRunner.php:120-140`):

```
run():
  ExcludeList::addDirectory(...)                         // once (§2.8 proof)
  new SuiteLoader(...) → $suiteLoader->tests, testCount  // once
  $this->pending = $suiteLoader->tests                   // assign pending
  printer->start()                                        // once
  startWorkers() / assignAllPendingTests() / waitForAllToFinish()
  return complete(...)                                   // merges + exitcode
```

**Refactored shape:**

```
run():
  ExcludeList::addDirectory(...)                         // once — outside loop
  $suiteLoader = new SuiteLoader(...)                    // once — outside loop
  $result      = TestResultFacade::result()              // once (see SuiteLoader:95 init)
  $printer->setTestCount($suiteLoader->testCount)
  $printer->start()                                       // once — attempt 1's progress only

  if ($this->options->retry === 0) {
      $outcome = $this->runAttempt(1, $suiteLoader->tests)
      return $this->complete($result, [$outcome])          // single-attempt path (§2.8)
  }

  $orchestrator = new RetryOrchestrator(...)
  $result = $orchestrator->orchestrate(
      $suiteLoader->tests,
      fn(int $n, array $pending) => $this->runAttempt($n, $pending),
  )
  return $this->complete(...$result->allAttempts)
```

**`runAttempt(int $attempt, list<non-empty-string> $pending): AttemptOutcome`:**

1. Reset per-attempt fields: `$this->pending = $pending; $this->workers = []; $this->batches = [];` — fields copied out of the existing `run()` so the reset list is exhaustive (`WrapperRunner.php:58-86`).
2. **Do not reset** `$requiredTestResultFiles`/`$requiredCoverageFiles` because they are aggregation keys across attempts; R6 (coverage union) requires keeping every attempt's files. Instead, for attempt N, snapshot-then-clear the per-attempt arrays (`$junitFiles`, `$coverageFiles`, `$testResultFiles`, `$testdoxFiles`, `$teamcityFiles`, `$statusFiles`, `$progressFiles`, `$unexpectedOutputFiles`, `$resultCacheFiles`) into the returned `AttemptOutcome` and clear them.
3. Run `$this->startWorkers()` (`WrapperRunner.php:142-147`) + `$this->assignAllPendingTests()` (`WrapperRunner.php:149-183`) + `$this->waitForAllToFinish()` (`WrapperRunner.php:207-231`) unchanged — worker pool is always fresh per attempt (trade-off: loses warm PHP opcache between attempts; gains clean process state and no cross-attempt test pollution, a problem PHPUnit itself doesn't guard against).
4. Build `AttemptOutcome` and return.

**Why fresh worker pool per attempt:** `WrapperWorker::$currentlyExecuting`, `$inExecution`, and status-file positions (`WrapperWorker.php:44-48, 208-222`) are per-process. Carrying workers across attempts would require flushing the `InputStream` protocol state (`WrapperWorker.php:185-206`) and resetting `$inExecution` at `WrapperWorker.php:46`, which is not worth the complexity. Kill-and-restart is idempotent — `startWorkers()` and `destroyWorker()` already prove this (`WrapperRunner.php:273-282`).

### 2.4 `@depends` chain resolver

**What paratest already knows.** `SuiteLoader::$options->configuration->resolveDependencies()` is honored at `SuiteLoader.php:111`, causing PHPUnit's `TestSuiteSorter` to topologically reorder tests so that dependents follow dependencies. However, paratest does **not** retain a dependency graph after this; it only emits a flat `list<non-empty-string> $tests` at `SuiteLoader.php:159-161`.

**Problem.** When test `B` depends on `A` and both fail in attempt 1, retrying only `B` is unsafe: PHPUnit marks `B` skipped with message `"This test depends on 'ClassA::A' to pass"`. If `A` is not re-included, the retry of `B` will always skip.

**Design: two-pass DFS for transitive closure, built in `SuiteLoader`.**

A single `TestCase::requires()` call returns only *direct* dependencies. The retry path needs the **transitive closure**: if `C @depends B` and `B @depends A`, retrying `C` must also re-run `A` (otherwise PHPUnit skips `B`, and hence `C`).

**Pass 1 — collect direct edges** (during `SuiteLoader::loadFiles()`, `SuiteLoader.php:133-157`).

Walk the PHPUnit `TestSuite` (`SuiteLoader.php:98`). Descend into nested suites including `DataProviderTestSuite` (children are plain `TestCase` — valid). Exclude `PhptTestCase` (PHPTs do not participate in `@depends`, see §2.5). For each `TestCase`, call `$case->requires(): list<ExecutionOrderDependency>`. Collect edges into `array<string, list<string>> $directDeps` keyed by dependent's `"Class::method"`:

- `ExecutionOrderDependency` with `$className` + `$methodName`: single edge `"ClassA::methodA"`.
- `ExecutionOrderDependency` with only `$className` (i.e., `#[DependsOnClass]`): expand to **all methods of that class** present in `$directDeps`' key space — iterate keys with prefix `"$className::"` and add each as an edge.

`#[DependsUsingDeepClone]` and `#[DependsUsingShallowClone]` are flag variants of `#[Depends]`; PHPUnit surfaces them via the same `ExecutionOrderDependency` (clone strategy is handled internally when the test runs). No special handling needed.

**Pass 2 — iterative DFS for transitive closure** (after Pass 1 completes).

For each key in `$directDeps` compute the transitive ancestor set via iterative DFS (explicit stack, not recursion — avoids stack overflow on deep chains). Memoize per node across overlapping subgraphs. Guard against cycles (PHPUnit rejects them, but defensive) by marking nodes `in-progress` and ignoring back-edges.

Result: `public array $dependsMap` — `array<string, list<string>>` flat transitive ancestor list per key. Extractor dedupes on emit.

**Consumer:** `FailedTestExtractor` (§3.1) looks up `$dependsMap["Class::method"] ?? []` for each failure and emits work items for each ancestor via the reverse mapping of §2.5.

**Rejected alternative:** parsing `@depends` / attributes from source via reflection. Reason: PHPUnit 13 supports the annotation + all four attribute variants. Duplicating the logic would diverge on edge cases. Reuse `TestCase::requires()`.

**PHPT fallback:** not added to `$dependsMap` — handled as bare file-path work items.

**Cross-class `@depends`:** graph is keyed by qualified name, not file. In functional mode a cross-class ancestor is emitted as its own `$file\0$pcre` work item via §2.5.

### 2.5 Functional vs. non-functional mode mapping

**Work-item format (current):** `SuiteLoader.php:131-161` produces one of three shapes:

| Mode | Has `providedData()` | Work item |
|---|---|---|
| non-functional | — | `$file` (bare path, grouped by `array_keys($files)`, line 161) |
| functional, no dataset | — | `"$file\0/$name\$/"` (line 152, 155) |
| functional, dataset | yes | `"$file\0/preg_quote(name) preg_quote(dataSetAsString)\$/"` (line 143, 155) |
| PHPT (both modes) | N/A | `$file` (line 137) |

**Reverse mapping in `FailedTestExtractor`:**

A JUnit `<testcase>` element has attributes `name`, `class`, `file`, `line` set in `TestCase.php:113-120`. `name` is already what PHPUnit uses in `FilterFactory::addIncludeNameFilter()` (`ApplicationForWrapperWorker.php:85`) — including data-set suffix like `"testFoo with data set #3"`.

- **Non-functional mode:** emit `$file` (raw path from `<testcase file="…">`). De-duplicate by file. Even if only one data row of one test failed, the entire file is re-run. Matches R6 (coverage union, so nothing is lost).
- **Functional mode, no dataset:** emit `$file\0/preg_quote(name)\$/`.
- **Functional mode, with dataset:** the JUnit `name` attribute already embeds the dataset-as-string (PHPUnit's `JunitXmlLogger` writes `"testMethod with data set #N"` or `"testMethod with data set \"key\""`). Build the PCRE: `/preg_quote(name)\$/` — note that `dataSetAsString()` is already *inside* `name`, so `preg_quote($name, '/')` + trailing `$` produces an equivalent regex to the one `SuiteLoader.php:143` originally emitted.
- **PHPT:** JUnit writes PHPT entries with `class="PHPUnit\Runner\Phpt\TestCase"` and `name="$file"`; emit the `file` attribute.

**Edge case:** `PhptTestCase::filename` is reached via reflection in `SuiteLoader.php:192-197`. The extractor recognizes PHPT by `class` attribute, not by file extension, because `.phpt` tests can be registered via custom bootstraps.

### 2.6 Artifact lifecycle

**Per-attempt tmp layout:**

```
{tmpDir}/                                 # attempt N in flight
  worker_01_stdout_{uniq}_junit           # ← WrapperWorker.php:57-63 pattern
  worker_01_stdout_{uniq}_coverage
  ...
{tmpDir}/attempt-1/                       # archived after attempt 1 finishes
  worker_01_stdout_{uniq}_junit
  worker_01_stdout_{uniq}_coverage
  ...
{tmpDir}/attempt-2/                       # archived after attempt 2 finishes
  ...
```

**Lifecycle rules:**

1. **During attempt N:** files live at `{tmpDir}/worker_{token}_stdout_{uniq}_*` exactly as today (`WrapperWorker.php:57-89`).
2. **At end of attempt N, if N < final:** `RetryOrchestrator` moves *all* of that attempt's collected `SplFileInfo`s into `{tmpDir}/attempt-{N}/` using `rename()`. `rename()` is picked over `copy+unlink` because it is atomic on the same filesystem and preserves inodes so coverage merging downstream is I/O-cheap.
3. **At end of final attempt:** do NOT move files; they stay at the top-level `{tmpDir}` path where the existing `complete()` flow reads them (`WrapperRunner.php:300-305, 411-420`).
4. **Aggregation reads:** `complete()` receives all `AttemptOutcome`s and their `SplFileInfo` paths (post-rename). For coverage (`generateCodeCoverageReports()`, `WrapperRunner.php:405-411`) — every attempt's coverage file path is included in the union (R6). For JUnit — only the *final* attempt's files go into `LogMerger::merge()` when `--junit-retry-metadata` is OFF (R1). When ON, all attempts go to `LogMerger::mergeAcrossAttempts()` (§3.3).
5. **Cleanup:** extend `clearFiles()` (`WrapperRunner.php:463-472`) to also iterate over archived per-attempt directories and `rmdir()` after emptying. On failed aggregation (exception in `complete()`), archived files are left in place — useful for post-mortem.

**Why archival is needed:** `WrapperWorker` filenames contain `uniqid()` (`WrapperWorker.php:62`). If attempt 2 uses a new worker, it writes to a *different* path (not overwriting attempt 1). But both paths live in the same `{tmpDir}` and would be collected by directory scans. Archival puts attempt 1 into its own namespace and lets future maintenance code distinguish them.

### 2.7 Interaction matrix

| Flag / feature | Behavior with `--retry>0` | Source refs |
|---|---|---|
| `--stop-on-failure` | Takes priority: `RetryOrchestrator` does not start attempt N+1 if any prior attempt triggered the early-terminate branch at `WrapperRunner.php:170-174`. Document as "stop-on-failure disables retry implicitly." | `WrapperRunner.php:170-174`, `Options.php:96` |
| `--teamcity` / `--log-teamcity` | Last-attempt-only. `ResultPrinter::printResults()` writes TeamCity from `teamcityFiles` passed in (`ResultPrinter.php:186-197`). Pass only the final attempt's `teamcityFiles`. TeamCity streaming has no schema for retry metadata and pulling in prior attempts would double-count events consumed by CI. | `ResultPrinter.php:147-159, 186-197` |
| `--processes` | Independent of retry. Each attempt starts a fresh pool of `$options->processes` workers. | `WrapperRunner.php:142-147` |
| `--functional` | Drives work-item reverse mapping (§2.5). No behavioral difference otherwise. | `SuiteLoader.php:142-157` |
| `--shard` | Applied once at `SuiteLoader.php:100-102` (before retry). Retry only re-runs failures from the current shard — never pulls tests from other shards. | `SuiteLoader.php:232-264` |
| `random-order-seed` | Applied once at `SuiteLoader.php:104-106`. On retry attempts, order within the *pending* list is preserved (no re-shuffle), guaranteeing reproducibility. | `SuiteLoader.php:104-106` |
| PHPT tests | Emitted as bare filename work items. Retryable: the extractor sees `<testcase class="PHPUnit\Runner\Phpt\TestCase">` and emits the file path. | `SuiteLoader.php:136-137`, `ApplicationForWrapperWorker.php:92-94` |
| `--coverage-*` | R6 — union across all attempts. Existing `Merger` (`WrapperRunner.php:411`) handles multi-file merge. | `WrapperRunner.php:379-427` |
| `stop-on-defect/error/warning/risky/…` | Same as `stop-on-failure`: if any worker exit code triggers it, retry is suppressed. Keep the `max($this->exitcode, $worker->getExitCode())` check in place (`WrapperRunner.php:198`). | `WrapperRunner.php:170-174, 198` |
| `--order-by=defects` | Uses PHPUnit's result cache. Each attempt uses the cache as-updated by the previous attempt — flaky tests may get re-sorted. Acceptable: stable behavior across attempts is not a goal. | `SuiteLoader.php:108-125` |
| Worker crash | NOT retriable (R3). `WrapperRunner::assignAllPendingTests()` throws at `WrapperRunner.php:156` before `RetryOrchestrator` gets an `AttemptOutcome`. The exception propagates and the run fails. | `WrapperRunner.php:156, 222` |

### 2.8 Backward compatibility proofs (`--retry=0`)

Concrete assertions, each grounded in existing code:

1. **No new tmp dirs.** `{tmpDir}/attempt-N/` is only created when `RetryOrchestrator::orchestrate()` runs archival between attempts (§2.6). With `retry=0` the orchestrator is never instantiated; `run()` falls through the `if ($options->retry === 0)` short-circuit in §2.3.
2. **`$pending` resets identically.** `WrapperRunner::run()` currently does `$this->pending = $suiteLoader->tests` (`WrapperRunner.php:132`). The retry=0 branch keeps the exact same assignment; no extra mutation.
3. **`complete()` sees one `AttemptOutcome`.** All snapshot arrays (`$junitFiles`, etc.) contain exactly the same `SplFileInfo`s as they do today. Merging one `AttemptOutcome` is identity.
4. **`printer->start()` called exactly once.** Preserves the PHPUnit header output at `ResultPrinter.php:92-140`.
5. **Exit code unchanged.** `ShellExitCodeCalculator::calculate()` at `WrapperRunner.php:361-364` runs on the one aggregated `TestResult` — same as today.
6. **No new CLI flag effect.** `--junit-retry-metadata` and `--retry-on` are parsed in `Options::fromConsoleInput()` but do not influence any code path when `retry=0` — they are read only inside `RetryOrchestrator` and `FailedTestExtractor`.
7. **Flaky summary not printed.** R4 gates the section on `retry>0`. With `retry=0`, `ResultPrinter::printResults()` output is byte-identical.
8. **No extra worker creation.** `startWorkers()` is called once (`WrapperRunner.php:135`) — same count as today.

Tests to add (listed in PR, not implemented here): `WrapperRunnerTest::testRetryZeroProducesIdenticalArtifactsAsNoRetryFlag()` — bit-diff JUnit/TestDox/Teamcity/coverage files between a `retry=0` run and a pre-refactor baseline.

---

## 3. T2: `FailedTestExtractor` + JUnit cross-attempt merge

### 3.1 Class: `FailedTestExtractor`

**Location:** `src/JUnit/FailedTestExtractor.php` (new).

```php
final readonly class FailedTestExtractor
{
    /**
     * @param non-empty-list<MessageType> $retryOn  // default [MessageType::failure, MessageType::error]
     * @param array<string, list<string>> $dependsMap  // §2.4 — transitive-closure ancestor map (values are flat ancestor lists, not just direct deps)
     */
    public function __construct(
        private array $retryOn,
        private array $dependsMap,
        private bool $functional,
    ) {}

    /**
     * @param list<SplFileInfo> $junitFiles
     * @return list<non-empty-string>  work items in SuiteLoader::$tests format
     */
    public function extractFailures(array $junitFiles): array;
}
```

**Input:** paths of one attempt's per-worker JUnit files (the ones collected at `WrapperRunner.php:254-256`).

**Output:** work items in the exact format `SuiteLoader::$tests` produces (`SuiteLoader.php:131-161`, already documented in §2.5). This is the contract: `RetryOrchestrator` shoves the output directly into `runAttempt(N+1, $pending)` without further transformation.

**Algorithm:**

1. For each file: `TestSuite::fromFile($file)` — reuses the existing parser at `TestSuite.php:41-52`. Walk all cases (`$suite->cases`) recursively through `$suite->suites`.
2. For each case matching filter (§3.2), produce a work item:
   - **Non-functional:** `$file` attribute value (dedup into a set).
   - **Functional:** `$file . "\0" . "/" . preg_quote($name, '/') . "\$/"`. The `$name` is PHPUnit's own name format (already dataset-aware) — exactly what `Factory::addIncludeNameFilter()` expects (`ApplicationForWrapperWorker.php:82-88`).
   - **PHPT:** case has `class="PHPUnit\Runner\Phpt\TestCase"`. Emit `$file` attribute.
3. Close the failure set under `$dependsMap` ancestors (§2.4 / §3.3).
4. Return deduped list.

### 3.2 Filter rules (`--retry-on`)

**New option:** `--retry-on` — comma-separated subset of `{failure, error, skipped}`. Default `failure,error`. `skipped` opt-in because most skipped tests are intentional (e.g., `@requires` guards) and retrying them is noise.

**Validation (performed in `Options::fromConsoleInput()` — patch-point `Options.php:146-312`):**

```php
$raw = $input->getOption('retry-on') ?? 'failure,error';
$tokens = array_unique(array_map('trim', explode(',', $raw)));
$valid = ['failure', 'error', 'skipped'];
foreach ($tokens as $t) {
    if (!in_array($t, $valid, true)) {
        throw new InvalidArgumentException(
            "Invalid --retry-on value: '$t'. Allowed: " . implode(', ', $valid)
        );
    }
}
if ($retry > 0 && $tokens === []) {
    throw new InvalidArgumentException("--retry-on cannot be empty when --retry > 0");
}
```

Validated tokens are then mapped to `MessageType` enum cases (`MessageType.php:8-22`) and stored as `public list<MessageType> $retryOn` on `Options`. The `FailedTestExtractor` consumes the enum list, never the raw strings — guaranteeing string/enum drift is caught at boot.

**`crash` is NOT an accepted value in MVP** — per R3, worker crashes are not retriable. The token is reserved for a future `--retry-on=crash` follow-up and passing it today fails the validation above.

**Mapping:** The `MessageType` enum at `MessageType.php:8-22` already has exactly these three cases and the `TestCase` parser at `TestCase.php:50-110` emits `TestCaseWithMessage` with the matching `$xmlTagName`. Filter logic:

```php
foreach ($suite->cases as $case) {
    if (!$case instanceof TestCaseWithMessage) continue;           // passed, skip
    if (!in_array($case->xmlTagName, $this->retryOn, true)) continue;
    // emit work item
}
```

**Crash is NOT retriable (R3).** Reconfirming: crashes never produce JUnit `<testcase>` entries because `WrapperRunner::assignAllPendingTests()` throws `WorkerCrashedException` at `WrapperRunner.php:156` *before* the worker finishes writing its JUnit file. The extractor therefore *cannot* see crashed tests and needs no special handling.

**Risky/warning/deprecation not retriable by default.** The JUnit format lowered by PHPUnit writes these as `<warning>` / etc. under the testcase but marks the case as passing. `TestCase.php:50-110` only recognizes `error`/`failure`/`skipped`. To expose risky/warning retry in the future, extend `MessageType` enum and `TestCase::caseFromNode()` in the same PR as a `--retry-on=risky` follow-up.

### 3.3 `LogMerger::mergeAcrossAttempts()`

**Current behavior** (`LogMerger.php:16-67`): merges multiple JUnit files (one per worker) in the *same* attempt into a single `TestSuite`. Uses `$mainSuite->mergeWith($otherSuite)` (`TestSuite.php:117-145`) to combine suites by name and concatenate `$cases`. Last-writer-wins is NOT the model; cases are concatenated.

**New method:**

```php
/**
 * @param array<int, list<SplFileInfo>> $perAttemptJunitFiles
 *     attemptNumber (1-based) => junit file list for that attempt
 */
public function mergeAcrossAttempts(array $perAttemptJunitFiles): ?TestSuite;
```

**Algorithm (streaming, memory-bounded — resolves PB4):**

1. **Final attempt first.** Build canonical `TestSuite $final` via existing `LogMerger::merge()`. Walk leaves and index every `TestCase` into a hash map keyed by `(class, name)` (plus `file` for PHPT) → reference to the case inside `$final`. Memory: `O(final_test_count)`.
2. **For each prior attempt N (N = 1..final-1), in order:** parse via `LogMerger::merge()` to get `TestSuite $prior`. Walk `$prior->cases` recursively; for each `$priorCase instanceof TestCaseWithMessage`, look up `(class, name)`. On match:
   - On first attach, replace the final case in-place (in its parent suite's `$cases` list and in the hash map) with a new `TestCaseWithRetries` copying the final case's attributes.
   - Append `$priorCase` to the `priorAttemptFailures` list.
3. **Drop `$prior`** before loading attempt N+1 — only small message payloads are retained on the final cases. Peak memory = `final_test_count + max(attempt_test_count)` instead of `sum(attempt_test_count)`. For 50k tests × 10 retries: ~1 GB → ~200 MB.
4. **Emit** via extended `Writer::createCaseNode()` (`Writer.php:84-110`). For each entry in `priorAttemptFailures` emit one Surefire-convention child; element name selected by:

   | Prior attempt message | Final attempt outcome | Element |
   |---|---|---|
   | `failure` | pass | `<flakyFailure>` |
   | `error` | pass | `<flakyError>` |
   | `failure` | fail (top-level `<failure>` stays) | `<rerunFailure>` |
   | `error` | fail (top-level `<error>` stays) | `<rerunError>` |

   Same shape as existing `<failure>` / `<error>` (`TestCase.php:50-110`): `type`, `message` attributes; stack trace as text. The final attempt's outcome is NOT duplicated — it remains as the top-level `<failure>`/`<error>` or is implicit on pass.
5. Additionally emit `retries="N"` on `<testcase>` — redundant, harmless, useful for quick parsing. The `<flakyFailure>` / `<rerunFailure>` elements are the primary signal.
6. Return aggregate suite.

**New value object `TestCaseWithRetries`** — subclass of `TestCase` with `public int $retries` and `public list<TestCaseWithMessage> $priorAttemptFailures`. `Writer::createCaseNode()` dispatches on class, selects child tag per table above, serializes like the existing `<failure>`/`<error>` branch (`Writer.php:92-108`) — same `htmlspecialchars(..., ENT_XML1)` escaping.

**Why extend `LogMerger` and not fork it:** the per-worker merge logic (`LogMerger.php:31-61` — unnaming root-level suites when names collide) is non-trivial and unchanged. Adding a second method preserves backward compatibility: `generateJunitLog()` at `WrapperRunner.php:429-444` keeps calling `merge()` when metadata is OFF.

### 3.4 Sample XML: before / after

**Scenario:** 2 attempts. `ExampleTest::testFlaky` fails in attempt 1, passes in attempt 2. `ExampleTest::testStable` passes both times.

**`--junit-retry-metadata=off` (bit-identical to today, R1):**

```xml
<testsuites>
  <testsuite name="ExampleTest" tests="2" failures="0" errors="0" ...>
    <testcase name="testStable" class="ExampleTest" file="..." line="10" time="0.001" assertions="1"/>
    <testcase name="testFlaky"  class="ExampleTest" file="..." line="20" time="0.002" assertions="1"/>
  </testsuite>
</testsuites>
```

**`--junit-retry-metadata=on` (Surefire convention):**

```xml
<testsuites>
  <testsuite name="ExampleTest" tests="2" failures="0" errors="0" ...>
    <testcase name="testStable" class="ExampleTest" file="..." line="10" time="0.001" assertions="1"/>
    <testcase name="testFlaky"  class="ExampleTest" file="..." line="20" time="0.002" assertions="1" retries="1">
      <flakyFailure type="PHPUnit\Framework\AssertionFailedError" message="Failed asserting that false is true.">ExampleTest::testFlaky
Failed asserting that false is true.

/path/ExampleTest.php:21</flakyFailure>
    </testcase>
  </testsuite>
</testsuites>
```

**Second scenario:** `testStillBroken` fails in attempt 1 *and* attempt 2 (final). One prior attempt → one `<rerunFailure>`; the final failure stays as the top-level `<failure>`:

```xml
<testcase name="testStillBroken" class="ExampleTest" file="..." line="30" time="0.003" assertions="1" retries="1">
  <failure type="PHPUnit\Framework\AssertionFailedError" message="still broken">...final attempt stack trace...</failure>
  <rerunFailure type="PHPUnit\Framework\AssertionFailedError" message="still broken">...attempt 1 stack trace...</rerunFailure>
</testcase>
```

### 3.5 Schema compatibility

We follow the **Maven Surefire rerun convention** (see `docs/retry-feature-research.md` §7.1), which is the de-facto industry standard for JUnit-XML retry reporting.

- **Jenkins JUnit plugin:** natively parses `<flakyFailure>` / `<flakyError>` / `<rerunFailure>` / `<rerunError>` and renders the "flaky" badge on the test report.
- **GitHub Actions (`dorny/test-reporter` and similar consumers of Surefire XML):** parse the same convention and render flaky tests distinctly from hard failures.
- **Azure DevOps Publish Test Results task:** with format `JUnit` / `VSTest` + Surefire adapter, reads the rerun elements and marks the test outcome "Passed on rerun" / "Failed".
- **GitLab CI, CircleCI:** treat the extra elements as unknown but harmless children — the `<testcase>` outcome itself is preserved (pass = no top-level `<failure>`/`<error>`; rerun failure = top-level `<failure>` kept verbatim).
- **`retries="N"` attribute:** redundant but additive; unknown attributes are ignored by all of the above parsers.
- **Strict XSD validators (e.g., `xmllint --schema`):** strict "JUnit 4" XSDs (not Surefire's) will reject the extra elements. Acceptable because R1 makes this opt-in.
- **Document in `--help`:** "Emits Surefire-convention `<flakyFailure>` / `<rerunFailure>` children; parsed natively by Jenkins, GitHub Actions, and Azure DevOps."

### 3.6 `TestDoxResultsMerger` cross-attempt extension

**Current:** `TestDoxResultsMerger::getResultsFromTestdoxFiles()` at `src/TestDox/TestDoxResultsMerger.php` (called from `WrapperRunner.php:350`) merges TestDox collections by class name, concatenating per-method `TestResultCollection` via `fromArray([...a, ...b])`. TestDox has no schema and no metadata fields.

**Extension:** "last attempt wins" semantics.

- When called with only the final attempt's `$testdoxFiles` — current behavior, unchanged.
- When retry is enabled, `WrapperRunner::complete()` passes only the **final attempt's** `testdoxFiles` to `TestDoxResultsMerger`. Prior attempts are discarded. Justification: TestDox output is a human-readable pretty-print of test outcomes — showing flaky historical results would clutter the report without structured metadata to tag them.
- No new method required. The merger itself is not modified. The only change is in `WrapperRunner::complete()`: when consuming `AttemptOutcome[]`, pass `end($attempts)->testdoxFiles` instead of the aggregate.

Same rule applies to `--teamcity` / `--log-teamcity` output per §2.7.

---

## 4. Open design questions (handoff to devils-advocate / performance-reviewer)

1. **Memory footprint of cross-attempt JUnit merge.** **Resolved (PB4):** `mergeAcrossAttempts()` uses the streaming approach documented in §3.3 — the final attempt's `TestSuite` is held canonically, prior attempts are loaded one at a time and dropped after their message payloads have been copied onto the matching final cases. Peak memory is `final_test_count + max(attempt_test_count)` instead of `sum(attempt_test_count)`. For 50k tests × 10 retries, peak drops from ~1 GB to ~200 MB. No explicit ceiling or SAX-based parse is required for MVP — the existing DOM-backed parser (`TestSuite.php:49`) is retained because each individual attempt fits comfortably in memory under the streaming model.
2. **Worker pool warmup cost.** `runAttempt()` discards workers between attempts (§2.3). For `--retry=10` on a suite with bootstrap-heavy tests (containers, DB fixtures), this may dominate runtime. Should workers be reusable across attempts behind a `--retry-reuse-workers` opt-in?
3. **Result-cache interaction.** PHPUnit's result cache (`DefaultResultCache`, `WrapperRunner.php:339-348`) is updated per attempt. Does writing "passed" for attempt 2 when attempt 1 failed break `--order-by=defects` semantics? We believe yes — flaky tests will *not* be prioritized on the next full run because their last state is "passed."
4. **Coverage double-counting of flaky lines.** R6 = union means lines covered by a failing-then-passing test count once (OR-union). This is correct for line coverage but may inflate mutation scores for mutation-testing tools reading paratest's coverage output.
5. **`@depends` with shared fixtures across files.** If test `A` in file1 seeds a fixture and test `B` in file2 `@depends` on it, retrying `B` alone (functional mode) without re-running `A` will fail again. The `$dependsMap` closes under ancestors — but does it correctly handle `#[DependsOnClass]` across file boundaries? Confirm by inspecting PHPUnit's own graph emission in attempt-1's JUnit.
6. **Teamcity stream replay.** CI consumers watching TeamCity output live will see attempt-1 failures, then a second round of the same tests passing. No `retry=N` metadata in the stream means they cannot distinguish "flaky" from "bug ignored." Explicit caveat in `--help`.
7. **Interaction with `--stop-on-defect` partial pool.** At `WrapperRunner.php:170-174`, pending is cleared. But workers already mid-test complete normally. With retry, do these post-stop completions count toward attempt 1's failure set, or are they excluded? Proposal: include them (conservative retry).

---

## 5. Patch point summary

| File | Line(s) | Change type |
|---|---|---|
| `src/Options.php` | 124-143 | Add `public int $retry`, `public bool $junitRetryMetadata`, `public list<MessageType> $retryOn` to ctor |
| `src/Options.php` | 146-312 | Parse new flags in `fromConsoleInput()`; range check `[0,10]` (R5); tokenize `--retry-on` (default `failure,error`), validate each token against allowlist `{failure, error, skipped}` (reject `crash` — reserved), reject empty set when `retry > 0`, map to `MessageType` enum (§3.2) |
| `src/Options.php` | 314-884 | Add 3 `InputOption` definitions: `--retry`, `--junit-retry-metadata`, `--retry-on` |
| `src/WrapperRunner/WrapperRunner.php` | 120-140 | Refactor `run()` into `run()` + `runAttempt()`; retry-0 short-circuit |
| `src/WrapperRunner/WrapperRunner.php` | 58-86 | Document per-attempt vs. across-attempt field lifecycle |
| `src/WrapperRunner/WrapperRunner.php` | 284-377 | `complete()` accepts `AttemptOutcome[]`; coverage reads all attempts, JUnit/TestDox/TeamCity read only last unless metadata ON |
| `src/WrapperRunner/WrapperRunner.php` | 429-444 | `generateJunitLog()` branches on `--junit-retry-metadata` |
| `src/WrapperRunner/WrapperRunner.php` | 350 | TestDox merger input = last attempt only |
| `src/WrapperRunner/ResultPrinter.php` | 186-259 | Add R4 "Flaky tests (N): …" section after `SummaryPrinter::print()` |
| `src/WrapperRunner/RetryOrchestrator.php` | — | **New class** (§2.1) |
| `src/WrapperRunner/AttemptOutcome.php` | — | **New readonly value object** (§2.2) |
| `src/WrapperRunner/SuiteLoader.php` | 131-161 | Pass 1: walk suite (skipping `PhptTestCase`; `DataProviderTestSuite` children traversed), call `TestCase::requires()`, collect `$directDeps`. Expand `#[DependsOnClass]` via prefix-match on `"$className::"` keys |
| `src/WrapperRunner/SuiteLoader.php` | after 159 | Pass 2: iterative DFS from each `$directDeps` key with memoization + cycle guard → build `$dependsMap` (transitive closure) |
| `src/WrapperRunner/SuiteLoader.php` | 55-60 | Expose `public array $dependsMap` (transitive-closure ancestor map) |
| `src/JUnit/FailedTestExtractor.php` | — | **New class** (§3.1) |
| `src/JUnit/LogMerger.php` | 16-67 | Add `mergeAcrossAttempts()` method; `merge()` untouched |
| `src/JUnit/TestCaseWithRetries.php` | — | **New subclass of `TestCase`** carrying `retries` + prior-attempt payloads (§3.3) |
| `src/JUnit/Writer.php` | 84-110 | `createCaseNode()` detects `TestCaseWithRetries`; for each prior-attempt payload emits one Surefire-convention child (`<flakyFailure>` / `<flakyError>` / `<rerunFailure>` / `<rerunError>`) with `type` / `message` attributes and stack-trace text content; additionally emits redundant `retries="N"` attribute |
| `src/ParaTestCommand.php` | 75-97 | No change — flags flow through `Options::fromConsoleInput()` |
