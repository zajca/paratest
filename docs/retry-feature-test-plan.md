# Retry Feature — Test Plan

## 1. Test taxonomy

### 1.1 Unit tests

#### RetryOrchestratorTest
**File:** `test/Unit/WrapperRunner/RetryOrchestratorTest.php`
**Covers class:** `src/WrapperRunner/RetryOrchestrator.php`
**Mocks/stubs needed:** `WrapperRunner` stub (to control `runAttempt()` return value), `FailedTestExtractor` stub, `Options` value object constructed via `createOptionsFromArgv()`.

Test methods:

- `testSingleAttemptPassReturnsSuccessExitImmediately` — when `runAttempt()` returns zero failures on attempt 1, orchestrator returns exit 0 without a second attempt.
- `testRetryZeroIsIdenticalToNoRetry` — with `--retry=0`, orchestrator calls `runAttempt()` exactly once and returns the same exit code as that attempt.
- `testRetryExhaustedReturnsExitCodeOne` — with `--retry=2` and every attempt returning at least one failure, final exit code is 1 (FAILURE_EXIT).
- `testFlakyTestPassesOnSecondAttemptReturnsExitZero` — attempt 1 fails, attempt 2 passes; final exit code is 0 (R2).
- `testOrchestratorCallsRunAttemptExactlyRetryPlusOneTimesOnPersistentFailure` — asserts `runAttempt()` is called `N+1` times when `--retry=N` and all attempts fail.
- `testOrchestratorStopsEarlyOnCleanPass` — when attempt 2 of 3 allowed retries passes, `runAttempt()` is not called a third time.
- `testArtifactsArchivedBetweenAttempts` — after attempt 1 completes with failures, tmp dir contains `attempt-1/` subdirectory with renamed worker files before attempt 2 begins.
- `testCrashBubblesUpWithoutRetry` — when `runAttempt()` throws `WorkerCrashedException`, orchestrator re-throws immediately regardless of `--retry` value (R3).
- `testStopOnFailureSuppressesRetry` — when `Options::$stopOnFailure` is true, orchestrator calls `runAttempt()` exactly once even if `--retry=3`.
- `testMaxRetryCapAtTen` — `Options` validation rejects `--retry=11`; orchestrator is never constructed with that value.
- `testFlakySummaryLineCountMatchesDistinctFlakyTests` — after a mixed run (2 flaky, 1 exhausted), flaky summary collected by orchestrator contains exactly 2 entries and 1 failed entry.
- `testRetryOnFilterExcludesSkippedFromRetry` — when `--retry-on=failure` (error excluded), orchestrator does not pass error test names back to the next `runAttempt()` call.

**Mocks:** `WrapperRunner` is replaced with a configurable stub that accepts a sequence of `AttemptOutcome` objects to return per call. `FailedTestExtractor` is stubbed to return a preset list of `TestIdentifier` objects. No filesystem I/O needed in these unit tests — archive behavior is tested via a spy on the rename calls or by injecting a `FilesystemAdapter` seam.

---

#### FailedTestExtractorTest
**File:** `test/Unit/JUnit/FailedTestExtractorTest.php`
**Covers class:** `src/JUnit/FailedTestExtractor.php`
**Mocks/stubs needed:** none — class takes raw XML strings; all tests are pure data-in/list-out.

Test methods:

- `testExtractsFailedTestcaseInNonFunctionalMode` — XML with one `<testcase>` containing `<failure>` returns a single non-functional work-item with correct class and method name.
- `testIgnoresPassingTestcasesInNonFunctionalMode` — XML with mixed pass/fail returns only failed entries.
- `testExtractsFunctionalModeTestcaseWithDataset` — XML `name` attribute in the form `testFoo with data set "foo bar"` is parsed into a PCRE work-item matching the functional-mode format from `SuiteLoader.php:143`.
- `testExtractsFunctionalModeTestcaseWithoutDataset` — `name` without a `with data set` suffix still parses correctly.
- `testExtractsErrorTestcase` — `<error>` child is treated as a failure by default (`--retry-on` defaults to `failure,error`).
- `testIgnoresErrorWhenRetryOnIsFailureOnly` — when extractor is constructed with `retryOn=['failure']`, `<error>` testcases are omitted.
- `testIgnoresSkippedTestcase` — `<skipped>` is never returned regardless of `--retry-on`.
- `testExtractsPhptTestcase` — a `.phpt` path in the `file` attribute is returned as a PHPT work-item.
- `testEmptyXmlReturnsEmptyList` — `<testsuites/>` with no children returns `[]`.
- `testExtractsDependsChainAncestors` — when `SuiteLoader::$dependsMap` maps `testB -> testA`, a failure on `testB` also adds `testA` to the retry list so the chain re-runs.
- `testNoDuplicateWorkItemsWhenMultipleFailuresInSameClass` — two failed methods in the same class produce two distinct work items, not one collapsed class entry.

---

#### LogMergerCrossAttemptTest
**File:** `test/Unit/JUnit/LogMergerCrossAttemptTest.php`
**Covers method:** `LogMerger::mergeAcrossAttempts()` (new method on `src/JUnit/LogMerger.php`)
**Mocks/stubs needed:** none — purely functional XML merging.

Test methods:

- `testMergeAcrossAttemptsLastPassWinsForFlakyTest` — attempt-1 XML has `<failure>`, attempt-2 XML has clean `<testcase>`; merged result has no `<failure>` child and carries `retries="1"` attribute (opt-in metadata path).
- `testMergeAcrossAttemptsExhaustedRetainsFinalFailure` — three attempt XMLs all fail; merged testcase keeps the last failure message.
- `testMergeAcrossAttemptsWithoutMetadataFlagIsIdenticalToSingleAttempt` — when `junitRetryMetadata=false`, `mergeAcrossAttempts()` on a single-attempt result is bit-identical output to the existing `merge()` call (SC-5 backward compat for JUnit).
- `testMergeAcrossAttemptsWithMetadataEmitsRetriesAttribute` — with `junitRetryMetadata=true` and 2 retries, outer `<testcase>` has `retries="2"` attribute.
- `testMergeAcrossAttemptsWithMetadataEmitsFlakyAndRerunElements` — prior attempts emitted as Surefire-convention `<flakyFailure>`/`<flakyError>` (final passed) or `<rerunFailure>`/`<rerunError>` (final failed) children inside the merged `<testcase>`; additionally `retries="N"` attribute present.
- `testMergePreservesTimeSumAcrossAttempts` — merged `time` attribute equals the sum of all attempt times.

---

#### OptionsTest additions
**File:** `test/Unit/OptionsTest.php` (existing file, new methods added)

Test methods:

- `testRetryDefaultsToZero` — `createOptionsFromArgv([])` has `$options->retry === 0`.
- `testRetryAcceptsValuesZeroToTen` — data provider over `[0, 1, 5, 10]` asserts each is accepted without exception.
- `testRetryRejectsValueAboveTen` — `--retry=11` throws `InvalidArgumentException` (R5 cap).
- `testRetryRejectsNegativeValue` — `--retry=-1` throws `InvalidArgumentException`.
- `testJunitRetryMetadataDefaultsToFalse` — `$options->junitRetryMetadata === false` without flag (R1 opt-in).
- `testJunitRetryMetadataCanBeEnabled` — `--junit-retry-metadata` sets `$options->junitRetryMetadata === true`.
- `testRetryOnDefaultsToFailureAndError` — `$options->retryOn` equals `['failure', 'error']` by default.
- `testRetryOnAcceptsFailureOnly` — `--retry-on=failure` parses to `['failure']`.
- `testRetryOnRejectsUnknownValues` — `--retry-on=unknown` throws `InvalidArgumentException` (SC-4 guard).

---

### 1.2 Integration tests

#### WrapperRunnerRetryTest
**File:** `test/Unit/WrapperRunner/WrapperRunnerRetryTest.php`
**Extends:** `TestBase` (same pattern as `WrapperRunnerTest`)
**Harness:** uses `$this->runRunner()` which invokes a real `WrapperRunner` subprocess end-to-end.

Test methods:

- `testRetryZeroProducesBitIdenticalOutputToNoRetryFlag` — run `common_results/FailureTest.php` without any retry flag; run again with `--retry=0`; assert exit code, stdout, and JUnit XML byte-for-byte identical (SC-5).
- `testFlakyTestPassesOnSecondAttemptWithExitZero` — uses `retry/FlakyOnFirstAttemptTest.php` fixture; with `--retry=1` exit code is 0; output contains flaky summary line (SC-1, SC-3).
- `testExhaustedRetryReturnsFailureExitCode` — uses `retry/AlwaysFailingTest.php` with `--retry=2`; exit code is 1; output does NOT contain flaky summary (SC-2).
- `testFlakySummaryPrintedByDefault` — any run with `--retry>=1` that encounters a flaky test prints "Flaky tests (N):" section; asserting with `assertStringContainsString` (SC-3, R4).
- `testRetryOnFailureOnlyDoesNotRetryErrors` — uses `retry/AlwaysErrorTest.php` with `--retry=2 --retry-on=failure`; error is NOT retried; run count equals 1; exit code is 2 (SC-4).
- `testRetryOnErrorRetries` — same fixture with `--retry-on=failure,error` (default); error IS retried up to 2 times; run count equals 3 (SC-4).
- `testStopOnFailureWithRetrySkipsRetry` — uses `retry/AlwaysFailingTest.php` with `--stop-on-failure --retry=3`; runner stops after first failure with no retry attempts (SC-6).
- `testJunitOutputWithoutMetadataFlagHasNoRetriesAttribute` — `--log-junit` with `--retry=1` on flaky fixture; XML has no `retries` attribute on any `<testcase>` when `--junit-retry-metadata` is absent (SC-7, R1).
- `testJunitOutputWithMetadataFlagHasRetriesAttribute` — same run with `--junit-retry-metadata`; flaky `<testcase>` carries `retries="1"` attribute (SC-7).
- `testCoverageIsUnionAcrossAttempts` — uses `retry/FlakyOnFirstAttemptTest.php` with `--coverage-php` and `--retry=1`; resulting coverage file contains lines from both the failing and passing attempt (SC-8).
- `testFunctionalModeDataRowRetry` — uses `retry/FlakyDataRowTest.php` with `--functional --retry=1`; only the failing data-set row is retried, not the entire test method; passes with exit 0 (SC-9).
- `testEndToEndFullSuiteWithMixedResults` — uses mixed fixture directory `retry/mixed_suite/` containing one always-passing, one flaky, one always-failing test; with `--retry=2`: exit code 1, flaky summary contains exactly 1 entry, failed section contains exactly 1 entry (SC-10).
- `testRetryCreatesAttemptSubdirInTmpDir` — after a retry run, `$this->tmpDir/attempt-1/` exists and contains worker result files.
- `testPhptTestRetry` — uses `retry/FlakyPhptTest.phpt` fixture; with `--retry=1` and flaky PHPT test, exits 0 with flaky summary.

---

## 2. Fixtures required

All fixtures live under `test/fixtures/retry/`.

**`FlakyOnFirstAttemptTest.php`**
Uses a tmp counter file in `sys_get_temp_dir()` keyed by test class name. On first call, writes `1` to the file and asserts `false` (fails). On subsequent calls, reads the file, sees value `>= 1`, and asserts `true` (passes). Counter file is cleaned up in `tearDownAfterClass()`. This simulates a genuinely transient failure without external state.

**`AlwaysFailingTest.php`**
Single test method that asserts `false` unconditionally. Used to verify retry exhaustion and that exit code 1 is returned after all attempts.

**`AlwaysErrorTest.php`**
Single test method that throws an unhandled `\RuntimeException`. Produces `<error>` in JUnit XML (as opposed to `<failure>`), used to test `--retry-on` filter.

**`FlakyDataRowTest.php`**
Data provider provides three rows: `['a', true]`, `['b', false]`, `['c', true]`. The `'b'` row fails on attempt 1 by checking a counter file (same mechanism as `FlakyOnFirstAttemptTest`). Used to test SC-9 (functional mode retries only the failing row).

**`DependsChainTest.php`**
Three methods: `testA` (always passes), `testB` depends on `testA` (always passes), `testC` depends on `testB` and is flaky on first attempt. Used to verify that `FailedTestExtractor` correctly reconstructs the `@depends` ancestor chain so `testA` and `testB` are included in retry work items alongside `testC`.

**`FlakyPhptTest.phpt`**
A minimal PHPT test that writes/reads a counter file in the same pattern as `FlakyOnFirstAttemptTest.php`. Fails first execution, passes second. Used for SC-9 PHPT coverage.

**`mixed_suite/` directory**
Contains three files: `AlwaysPassTest.php` (trivially passes), `FlakyOnFirstAttemptTest.php` (symlink or copy of the above flaky fixture), `AlwaysFailingTest.php`. Used for SC-10 end-to-end test.

---

## 3. SC → test coverage matrix

| SC# | Description | Test class::method | Fixture | Key assertions |
|---|---|---|---|---|
| SC-1 | Basic retry passes | `WrapperRunnerRetryTest::testFlakyTestPassesOnSecondAttemptWithExitZero` | `FlakyOnFirstAttemptTest.php` | exit 0, "Flaky tests (1)" in output |
| SC-2 | Exhausted retries fails | `WrapperRunnerRetryTest::testExhaustedRetryReturnsFailureExitCode` | `AlwaysFailingTest.php` | exit 1, no "Flaky tests" line |
| SC-3 | Flaky marker in summary | `WrapperRunnerRetryTest::testFlakySummaryPrintedByDefault` | `FlakyOnFirstAttemptTest.php` | "Flaky tests (1):" present in stdout |
| SC-3 | Flaky marker unit | `RetryOrchestratorTest::testFlakySummaryLineCountMatchesDistinctFlakyTests` | none (stubbed) | summary list has 2 entries |
| SC-4 | --retry-on filter | `WrapperRunnerRetryTest::testRetryOnFailureOnlyDoesNotRetryErrors` | `AlwaysErrorTest.php` | exit 2, single attempt |
| SC-4 | --retry-on filter | `WrapperRunnerRetryTest::testRetryOnErrorRetries` | `AlwaysErrorTest.php` | 3 attempts, exit 2 |
| SC-4 | --retry-on unit | `FailedTestExtractorTest::testIgnoresErrorWhenRetryOnIsFailureOnly` | inline XML | empty result list |
| SC-5 | Backward compat --retry=0 | `WrapperRunnerRetryTest::testRetryZeroProducesBitIdenticalOutputToNoRetryFlag` | `common_results/FailureTest.php` | identical exit, stdout, JUnit XML |
| SC-5 | Backward compat JUnit | `LogMergerCrossAttemptTest::testMergeAcrossAttemptsWithoutMetadataFlagIsIdenticalToSingleAttempt` | inline XML | bit-identical merge output |
| SC-6 | --stop-on-failure skips retry | `WrapperRunnerRetryTest::testStopOnFailureWithRetrySkipsRetry` | `AlwaysFailingTest.php` | single attempt, exit 1 |
| SC-6 | --stop-on-failure unit | `RetryOrchestratorTest::testStopOnFailureSuppressesRetry` | none (stubbed) | `runAttempt` called once |
| SC-7 | JUnit opt-in metadata off | `WrapperRunnerRetryTest::testJunitOutputWithoutMetadataFlagHasNoRetriesAttribute` | `FlakyOnFirstAttemptTest.php` | no `retries` attr in XML |
| SC-7 | JUnit opt-in metadata on | `WrapperRunnerRetryTest::testJunitOutputWithMetadataFlagHasRetriesAttribute` | `FlakyOnFirstAttemptTest.php` | `retries="1"` attr present |
| SC-7 | JUnit merge unit | `LogMergerCrossAttemptTest::testMergeAcrossAttemptsWithMetadataEmitsRetriesAttribute` | inline XML | `retries="2"` in merged XML |
| SC-8 | Coverage union | `WrapperRunnerRetryTest::testCoverageIsUnionAcrossAttempts` | `FlakyOnFirstAttemptTest.php` | coverage PHP file contains lines from both attempts |
| SC-9 | Functional data-row retry | `WrapperRunnerRetryTest::testFunctionalModeDataRowRetry` | `FlakyDataRowTest.php` | exit 0, only failing row retried |
| SC-9 | PHPT retry | `WrapperRunnerRetryTest::testPhptTestRetry` | `FlakyPhptTest.phpt` | exit 0, flaky summary present |
| SC-10 | End-to-end | `WrapperRunnerRetryTest::testEndToEndFullSuiteWithMixedResults` | `mixed_suite/` | exit 1, 1 flaky, 1 failed |

---

## 4. Edge-case tests

These address open questions from design doc §4 and known gotchas from the research doc.

- **`RetryOrchestratorTest::testCrashBubblesUpWithoutRetry`** — `WorkerCrashedException` from any attempt is not caught by the orchestrator and propagates to the caller unchanged. Rationale: design R3 explicitly excludes crashes from retry because the worker process died, not the test logic.

- **`FailedTestExtractorTest::testExtractsFunctionalModeTestcaseWithDataset`** — Verifies that the PCRE work-item format reconstructed from the JUnit `name` attribute (`testFoo with data set "x"`) matches exactly what `SuiteLoader.php:143` would produce with `preg_quote`. A mismatch here would cause the retry to rerun the entire test file instead of just the failing row.

- **`FailedTestExtractorTest::testNoDuplicateWorkItemsWhenMultipleFailuresInSameClass`** — Guards against a regression where the extractor collapses multiple failed methods into a single class-level work item, causing the retry to rerun methods that already passed.

- **`RetryOrchestratorTest::testArtifactsArchivedBetweenAttempts`** — Verifies that attempt-1 worker tmp files are moved into `attempt-1/` before attempt-2 starts, preventing the result collector from finding stale files.

- **`LogMergerCrossAttemptTest::testMergePreservesTimeSumAcrossAttempts`** — Time inflation check: merged `time` must equal the sum of all attempt times, not just the last attempt's time. Flaky tests always cost more wall time than a clean run.

- **`OptionsTest::testRetryRejectsValueAboveTen`** — Enforces R5 hard cap. Without this guard, a user passing `--retry=100` could run paratest for hours.

- **`WrapperRunnerRetryTest::testRetryCreatesAttemptSubdirInTmpDir`** — Filesystem contract test. If archival fails silently, later attempts would pick up stale result files from attempt-1, corrupting the failure list fed to `FailedTestExtractor`.

- **`FailedTestExtractorTest::testExtractsDependsChainAncestors`** — `@depends` resolution: if `testB` depends on `testA` and `testB` fails, both must be in the retry list. Without this, `testB` would be skipped on retry because its dependency (`testA`) did not run in that attempt.

- **`RetryOrchestratorTest::testRetryOnFilterExcludesSkippedFromRetry`** — Skipped tests must never be retried regardless of `--retry-on`. Research noted that most skips are intentional `@requires` guards; retrying them would be wasteful and incorrect.

---

## 5. Subprocess harness pattern

**Invocation:** All integration tests in `WrapperRunnerRetryTest` extend `TestBase` and use the inherited `runRunner()` method. Options are set via `$this->bareOptions` array before calling `runRunner()`. The runner executes synchronously inside the test process; worker subprocesses are spawned by `WrapperRunner` itself via `Symfony\Component\Process`.

```
$this->bareOptions['path']    = $this->fixture('retry/FlakyOnFirstAttemptTest.php');
$this->bareOptions['--retry'] = '1';

$result = $this->runRunner(); // returns RunnerResult($exitCode, $output)
```

**Exit code assertion:**
```
self::assertSame(RunnerInterface::SUCCESS_EXIT, $result->exitCode);   // 0
self::assertSame(RunnerInterface::FAILURE_EXIT, $result->exitCode);   // 1
self::assertSame(RunnerInterface::EXCEPTION_EXIT, $result->exitCode); // 2
```

**Stdout assertions:** Use `assertStringContainsString` for presence checks and `assertStringMatchesFormat` (with `%s`/`%d` wildcards) for structured output sections. The flaky summary section format to match:
```
assertStringContainsString('Flaky tests (1):', $result->output);
assertStringContainsString('FlakyOnFirstAttemptTest::testFlakyMethod', $result->output);
```

**JUnit XML assertions:** Write to `$this->tmpDir . '/test-output.xml'` via `--log-junit`. Parse with `simplexml_load_string(file_get_contents($outputFile))`. Example pattern already established in `testShardsWithExtendedAssertions`:
```
$xml       = simplexml_load_string(file_get_contents($outputFile));
$testcase  = $xml->testsuite[0]->testsuite[0]->testcase[0];
self::assertSame('1', (string) $testcase['retries']);
self::assertCount(0, $testcase->failure);
```

**Stderr:** `RunnerResult` only exposes `$output` (BufferedOutput wraps stdout+stderr merged). If stderr isolation is needed for a specific test, spawn paratest via `Symfony\Component\Process` directly with separate pipes — see `testRaiseExceptionWhenATestCallsExitLoudlyWithCoverage` as a precedent for exception-path testing.

**Flaky fixture counter isolation:** Each flaky fixture test method must use a counter file path that incorporates the full test class+method name and the current `$this->tmpDir` to avoid cross-test interference when running in parallel.

---

## 6. What NOT to test

- **`runAttempt()` internal split mechanics** — the refactoring of `WrapperRunner::run()` into `run()` + `runAttempt()` is an implementation detail. The existing `WrapperRunnerTest` tests continue to exercise all current behavior unchanged; if they pass after the refactor, the split is correct.
- **PHPUnit internals** — how PHPUnit generates `<failure>` vs `<error>` XML nodes is PHPUnit's responsibility. Tests assume correct JUnit output from the worker.
- **`Merger` coverage union logic** — `src/Coverage/Merger.php` already handles multi-file merging and has its own tests. SC-8 tests only verify that the orchestrator calls the merger with files from all attempts, not the merger's internal union algorithm.
- **`ResultPrinter` line-by-line formatting** — exact whitespace/color of the flaky summary line is not pinned. Only the presence of "Flaky tests (N):" and the test name are asserted, avoiding fragile format coupling.
- **Upstream library bugs** — `symfony/process` behavior, PHPUnit XML schema changes.

---

## 7. Execution plan

Write tests in this order to maximise early feedback and minimize blocked work:

**Phase 1 — Options validation (no new production code needed beyond Options.php)**
1. `OptionsTest` additions (9 methods) — validates new CLI flags parse correctly before any orchestration logic exists.

**Phase 2 — Pure data transformation units (no subprocess)**
2. `FailedTestExtractorTest` (11 methods) — pure XML-in/list-out, can be written and run against the new class in isolation.
3. `LogMergerCrossAttemptTest` (6 methods) — pure XML merging, no subprocess.

**Phase 3 — Orchestrator unit (stubs only)**
4. `RetryOrchestratorTest` (12 methods) — stub-based, fast, exercises all orchestration paths including crash propagation, stop-on-failure, and artifact archival.

**Phase 4 — Integration / subprocess**
5. `WrapperRunnerRetryTest` (14 methods) — write after phases 1-3 pass; these tests are slow (real subprocess) and should be the last to be written and the last to break during development.

**Total estimated test method count:**
- `OptionsTest` additions: 9
- `FailedTestExtractorTest`: 11
- `LogMergerCrossAttemptTest`: 6
- `RetryOrchestratorTest`: 12
- `WrapperRunnerRetryTest`: 14
- **Total: 52 test methods**
