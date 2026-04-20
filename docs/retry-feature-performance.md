# Retry Feature — Performance Review

## Blocking concerns (must fix)

### 1. `mergeAcrossAttempts()` SimpleXML full-load across all attempts — DOM OOM risk
**Impact**: High | **Confidence**: 88 | **Location**: design §3.3 / `src/JUnit/TestSuite.php:49`

The design's `mergeAcrossAttempts()` calls the existing `merge()` per attempt, which calls `TestSuite::fromFile()` for every JUnit file (`TestSuite.php:41–52`). That method loads the full file into a `SimpleXMLElement` (`TestSuite.php:49`) and materialises every `<testcase>` into a `TestCase` PHP object (`TestCase.php:99–101`). All parsed `TestSuite` objects for all attempts are held in memory simultaneously while the cross-attempt lookup (design §3.3 step 2) executes.

Concrete numbers: 5000 tests × 8 workers = 8 JUnit files per attempt, each ~600 bytes/testcase → ~375 KB per worker file, ~3 MB total per attempt. Across 10 attempts with 5000 tests all-failing, that is 80 files fully parsed and held in memory at once. Each `TestCase` object carries 6 scalar fields; with PHP object overhead (~200 bytes each) × 50000 objects → ~10 MB of PHP objects. This is acceptable for 5000 tests, but scales linearly: at 50000 tests all-retried 10 times the peak is ~1 GB of live objects.

The design itself flags this (§4 question 1) but defers the decision. The fix is: do not hold all parsed attempts in memory simultaneously. Process attempts sequentially — merge attempt 1 into a running hash map `array<"class::name", TestCaseWithRetries>`, free the attempt-1 objects, then process attempt 2 into the same map. This reduces memory from O(tests × attempts) to O(tests × 2) regardless of retry count.

### 2. Fresh worker pool per attempt: no opcache warm-up for large bootstraps
**Impact**: High | **Confidence**: 85 | **Location**: design §2.3 / `src/WrapperRunner/WrapperRunner.php:142–147`

Each `runAttempt()` call unconditionally invokes `startWorkers()` (`WrapperRunner.php:142–147`), which spawns `$options->processes` fresh PHP processes. A new PHP process must cold-start the autoloader and re-JIT/re-interpret all bootstrap-loaded files. Symfony app bootstraps routinely load 200–500 classes. For 8 workers × 3 retries = 24 PHP process starts; for 8 workers × 10 retries = 80 process starts.

Practical overhead per cold process start in a medium project (no opcache): 300–800 ms. With opcache but no preloading: 50–150 ms (file stat hits are cached). Estimated penalty for retry=3 on 8 workers: 24 × 100 ms = 2.4 seconds extra wall time per CI job assuming warm opcache. For retry=10: 80 × 100 ms = 8 seconds. This is non-fatal for most CI budgets but grows super-linearly with bootstrap size.

The design §2.3 explicitly discusses the trade-off and rejects worker reuse. The concern is real but the design's rationale (clean process state, no cross-test pollution) is sound for the MVP. Document the overhead in `--help` so users with expensive bootstraps understand the cost model and can set retry conservatively.

---

## High-confidence concerns

### 3. `FailedTestExtractor` scans all 5000 `<testcase>` elements to find 10 failures
**Impact**: Medium | **Confidence**: 90 | **Location**: design §3.1 / `src/JUnit/TestSuite.php:98–101`

`extractFailures()` iterates every `TestCase` in every JUnit file for every inter-attempt extraction. With 5000 tests and 10 failing, the extractor scans 5000 objects to find 10. The scan is O(n) in test count, not O(failures). For 5000 tests this is cheap (microseconds in-process). For 50000 tests with 8 workers and 10 retries it runs 9 times × 50000 iterations = 450000 object comparisons — still under 50 ms and non-blocking.

However, `extractFailures()` must complete before `runAttempt(N+1)` starts — it sits on the critical path between attempts. At 50000 tests this is acceptable. No change needed, but add a note that this is a sequential bottleneck if tests ever exceed 100000.

### 4. Per-attempt disk I/O: `rename()` for all artifact files between attempts
**Impact**: Medium | **Confidence**: 82 | **Location**: design §2.6

The design archives each attempt's files via `rename()` into `{tmpDir}/attempt-{N}/`. With 8 workers and 9 artifact types per worker (junit, coverage, testResult, testdox, teamcity, status, progress, unexpectedOutput, resultCache), that is 8 × 9 = 72 `rename()` syscalls per inter-attempt transition. For 10 attempts: 9 transitions × 72 = 648 `rename()` calls total. On local ext4 or tmpfs this is negligible (< 1 ms total). On NFS-mounted `/tmp` (some CI runners) `rename()` is not atomic and can be 5–50 ms per call. The design is correct to choose `rename()` over `copy+unlink` for local filesystems. For NFS, document the caveat.

More concretely: 5000 tests × 10 retries = 50000 test executions, but the file count does not grow with test count. File count is fixed at `workers × artifact_types × (attempts - 1)`. At retry=10 with 8 workers: 648 files total in tmpDir. This is well within any tmpfs quota.

### 5. `AttemptOutcome` arrays accumulate all file handles across all attempts
**Impact**: Medium | **Confidence**: 83 | **Location**: design §2.2 / `src/WrapperRunner/WrapperRunner.php:69–86`

The `AttemptOutcome` value objects are collected in `RetryOrchestrator::$attempts` and held until `complete()` finishes. Each holds `list<SplFileInfo>` for every artifact type (10 types). With 8 workers and 10 attempts, the orchestrator holds 80 `AttemptOutcome` objects each with 10 arrays totalling 80 `SplFileInfo` references. `SplFileInfo` objects in PHP carry ~500 bytes each. Total: 80 × 10 × 8 = 6400 `SplFileInfo` objects × 500 bytes = ~3 MB. This is small and not an OOM risk at any realistic worker/retry count. Not a problem.

### 6. `complete()` `unserialize()` loop on test result files — scales with attempt count
**Impact**: Medium | **Confidence**: 80 | **Location**: `src/WrapperRunner/WrapperRunner.php:300–336`

`complete()` calls `unserialize()` on every `$testResultFile` (`WrapperRunner.php:305–307`) and immediately `array_merge_recursive()` to build a cumulative `TestResult`. With retry=10 and 8 workers, this loop runs 80 times instead of 8. Each `unserialize()` of a PHPUnit `TestResult` for 5000 tests is ~2–10 ms depending on event count; 80 calls = 160–800 ms extra wall time in `complete()`. This is not blocking but is wasted work: for the final exit-code calculation only the last-attempt `TestResult` is needed. Consider whether `complete()` should accept `AttemptOutcome[]` and skip `unserialize()` for non-final attempts' `testResultFiles` when only the last attempt's aggregate is needed for `ShellExitCodeCalculator`.

---

## Benchmark recommendations

1. **Worker bootstrap cost benchmark**: measure `time paratest --retry=3` vs `time paratest --retry=0` on the paratest own test suite (currently ~600 tests) to get real numbers before assuming the 100 ms/process estimate above. The real number drives whether `--retry-reuse-workers` is worth implementing.

2. **Cross-attempt JUnit merge peak memory**: run `--retry=10` on a synthetic 5000-test suite with all tests failing, measure `memory_peak_usage()` inside `mergeAcrossAttempts()`. If it stays under 128 MB the current approach is fine; if it exceeds 256 MB the sequential-merge fix in concern #1 is required.

3. **`complete()` unserialize time**: add a stopwatch around the `testResultFiles` loop in `complete()` for a 5000-test / 10-retry run and compare to a 1-retry baseline. If the delta is > 500 ms, apply the last-attempt-only optimization.

---

## Verified non-issues

**`--retry=0` hot-path overhead (concern #8)**: The design's short-circuit at `run()` (`if ($options->retry === 0)`) means the `RetryOrchestrator` is never instantiated. No guard `if ($this->orchestrator !== null)` sits inside `assignAllPendingTests()` or `waitForAllToFinish()` — those methods remain structurally identical to today. The backward-compat proofs in design §2.8 are exhaustive and grounded in specific line references. Zero overhead for `--retry=0` confirmed.

**`@depends` resolver build cost (concern #6)**: The design builds `$dependsMap` during the *same* `loadFiles()` iteration at `SuiteLoader.php:133–161` that already walks the full `TestSuite`. Adding a hash-map insertion per `TestCase` during an existing traversal is O(n) with negligible constant. No extra traversal pass. Not a concern.

**Options propagation to workers (concern #7)**: The new `$retry`, `$junitRetryMetadata`, and `$retryOn` fields are consumed exclusively in `RetryOrchestrator` and `FailedTestExtractor` on the controller side. Looking at `WrapperWorker.php:116–152`, workers receive options only via `$options->phpunitOptions` (passed as `--phpunit-argv` serialised payload). The three new fields are not PHPUnit passthrough options, so they are not added to `phpunitOptions` and do not grow the worker's argv. Serialization cost to workers is unchanged.

**Coverage union merge complexity (concern #3)**: `Merger::merge()` at `WrapperRunner.php:411` is `sebastianbergmann/code-coverage`'s `Serialization\Merger`. It reads coverage files sequentially and merges via OR-union of line bitmaps — O(n) in total file count, not O(n²). With retry=10 all-fail and 8 workers, the merger receives 88 files (10 attempts × 8 workers). Memory peak is bounded by the largest single coverage file size plus the running aggregate; no quadratic blowup. Not a concern.

**Filesystem pressure (concern #10)**: File count in tmpDir is fixed at `workers × artifact_types × attempts`, not `tests × attempts`. At retry=10 with 8 workers and 9 artifact types: 720 files total. Standard CI runner `/tmp` (typically 512 MB–2 GB tmpfs) is not threatened by 720 small files.
