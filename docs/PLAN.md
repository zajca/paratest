# Retry Failed Tests — Complete Implementation Plan

**Status:** READY for implementation
**Target branch:** `7.x`
**Planning phase verdict:** [retry-feature-verdict.md](retry-feature-verdict.md) (v3 final, READY)

This is the consolidated master plan. Detailed specialist documents are referenced inline.

---

## 1. Executive summary

Paratest currently has zero retry/rerun support. No GitHub issue has ever requested it. This plan designs and specifies a `--retry=N` feature that re-executes failing tests up to N times, surfaces the last attempt's outcome (plus optional cross-attempt metadata in JUnit XML), and preserves backward-compatible behavior when `--retry=0` (default).

**Scope:** 16 files touched, 5 new files. Estimated 1-2 weeks for a focused implementation swarm.

**Source-of-truth documents:**

- [retry-feature-design.md](retry-feature-design.md) — full architectural design, 38 KB
- [retry-feature-research.md](retry-feature-research.md) — comparison with pytest-rerunfailures, Jest, Playwright, Surefire
- [retry-feature-devils-advocate.md](retry-feature-devils-advocate.md) — adversarial review (3 blockers + 6 concerns, all resolved or documented)
- [retry-feature-performance.md](retry-feature-performance.md) — performance review
- [retry-feature-test-plan.md](retry-feature-test-plan.md) — 52 test methods across 5 test classes
- [retry-feature-verdict.md](retry-feature-verdict.md) — quality gate verdict (v3, READY)

---

## 2. User decisions (locked constraints)

| # | Decision | Mechanism |
|---|---|---|
| **R1** | JUnit retry metadata is opt-in via `--junit-retry-metadata`, default off | When on: emit Surefire `<flakyFailure>`/`<flakyError>`/`<rerunFailure>`/`<rerunError>` children under `<testcase>` + additive `retries="N"` attribute |
| **R2** | Final exit code 0 if last attempt passes | `ShellExitCodeCalculator` operates on final `TestResult` only |
| **R3** | Worker crashes NOT retriable in MVP | `WorkerCrashedException` propagates as today; `--retry-on=crash` reserved for follow-up |
| **R4** | Flaky summary section always printed when `--retry>0` | `ResultPrinter::printResults()` appends "Flaky tests (N): …" after regular summary |
| **R5** | `--retry` accepts `[0, 10]` | `InvalidArgumentException` in `Options::fromConsoleInput()` for out-of-range |
| **R6** | Coverage = union across all attempts | Existing `Merger` in `generateCodeCoverageReports()` handles multi-file union natively |

---

## 3. Success criteria (testable definition of done)

| SC | Description | Test coverage |
|---|---|---|
| SC-1 | `paratest --retry=2` with flaky test (fails then passes) → exit 0 | `WrapperRunnerRetryTest::testFlakyTestPassesOnRetryExitCodeZero` |
| SC-2 | Always-failing test with `--retry=2` → exit > 0 + `<rerunFailure>` entries | `WrapperRunnerRetryTest::testExhaustedRetriesReportsAsFailure` |
| SC-3 | Flaky summary section lists retried-then-passed tests | `WrapperRunnerRetryTest::testFlakySummarySectionPrinted` |
| SC-4 | `--retry-on=error` retries errors but not failures | `FailedTestExtractorTest::testFilterOnlyErrors` + integration |
| SC-5 | `--retry=0` produces bit-identical artifacts to no-flag | `WrapperRunnerRetryTest::testRetryZeroProducesIdenticalArtifactsAsNoFlag` |
| SC-6 | `--stop-on-failure` + `--retry=2` → retry is suppressed | `RetryOrchestratorTest::testStopOnFailureSkipsRetry` |
| SC-7 | `--junit-retry-metadata` on emits Surefire elements | `LogMergerCrossAttemptTest::testMergeAcrossAttemptsWithMetadataEmitsFlakyAndRerunElements` |
| SC-8 | Coverage union across attempts | `WrapperRunnerRetryTest::testCoverageUnionAcrossAttempts` |
| SC-9 | Functional mode retries only failed data row | `WrapperRunnerRetryTest::testFunctionalModeRetriesOnlyFailedDataRow` |
| SC-10 | End-to-end integration with fixture | `WrapperRunnerRetryTest` full suite |

---

## 4. CLI surface

| Flag | Default | Range / values | Description |
|---|---|---|---|
| `--retry=N` | `0` | `[0, 10]` | Max retry attempts for failed tests |
| `--retry-on=LIST` | `failure,error` | subset of `{failure, error, skipped}` | Which failure types trigger retry |
| `--junit-retry-metadata` | off | boolean flag | Emit Surefire `<flakyFailure>`/`<rerunFailure>` metadata in JUnit XML |

Out-of-range/invalid values throw `InvalidArgumentException` in `Options::fromConsoleInput()`.

---

## 5. Architecture

### New classes

1. **`ParaTest\WrapperRunner\RetryOrchestrator`** — orchestrates N+1 attempts, archives per-attempt artifacts, composes final results. Instantiated only when `retry > 0` (retry=0 short-circuit preserves bit-identical behavior).
2. **`ParaTest\WrapperRunner\AttemptOutcome`** — readonly value object snapshotting the ten per-attempt accumulator arrays (`junitFiles`, `coverageFiles`, `testResultFiles`, `testdoxFiles`, `teamcityFiles`, `resultCacheFiles`, `progressFiles`, `unexpectedOutputFiles`, `statusFiles`, `exitcode`, `testResultAggregate`).
3. **`ParaTest\JUnit\FailedTestExtractor`** — parses attempt-N JUnit XML, filters by `--retry-on`, maps back to `SuiteLoader::$tests` work-item format (3 shapes: bare path / `"$file\0/$pcre$/"` / PHPT path), closes under `@depends` transitive ancestors.
4. **`ParaTest\JUnit\TestCaseWithRetries`** — subclass of `TestCase` carrying `retries: int` + `priorAttemptFailures: list<TestCaseWithMessage>`.
5. **PHPT handling** — preserved via `class="PHPUnit\Runner\Phpt\TestCase"` discriminator.

### Refactored classes

- **`WrapperRunner::run()`** split into `run()` + `runAttempt(int $attempt, list<non-empty-string> $pending): AttemptOutcome`. Retry=0 branch calls `runAttempt(1, $tests)` once and returns — no orchestrator instantiation, no tmpDir/attempt-N directories, no new allocations in hot paths.
- **`LogMerger`** gains `mergeAcrossAttempts(array<int, list<SplFileInfo>> $perAttemptJunits): ?TestSuite` — streaming memory-bounded algorithm: final attempt first (hash-map index by class/name), then prior attempts one-by-one with immediate drop. Peak memory `O(final_test_count + max(attempt_test_count))`.
- **`Writer::createCaseNode()`** detects `TestCaseWithRetries`, emits Surefire children (`<flakyFailure>`/`<flakyError>` if final passed, `<rerunFailure>`/`<rerunError>` if final failed), one child per prior attempt. Additive `retries="N"` attribute.
- **`SuiteLoader`** exposes `public array $dependsMap` — two-pass DFS (direct deps + transitive closure via explicit-stack iterative DFS with memoization and cycle guard). Handles `#[Depends]`, `#[DependsOnClass]` (expands to all methods of class), `#[DependsUsingDeepClone]`/`#[DependsUsingShallowClone]` (same edge representation).
- **`Options`** gains `public int $retry`, `public bool $junitRetryMetadata`, `public list<MessageType> $retryOn`. Validation in `fromConsoleInput()`.
- **`ResultPrinter::printResults()`** appends R4 flaky summary section when `$options->retry > 0`.
- **`WrapperRunner::complete()`** signature accepts `AttemptOutcome[]` — coverage merges all attempts (R6); JUnit reads all when metadata on, else final only; TestDox + TeamCity always final-only.

### Patch point summary

| File | Lines | Change |
|---|---|---|
| `src/Options.php` | 124–143 | Add retry fields to ctor |
| `src/Options.php` | 146–312 | Parse + validate `--retry`, `--retry-on`, `--junit-retry-metadata` |
| `src/Options.php` | 314–884 | 3 new `InputOption` definitions |
| `src/WrapperRunner/WrapperRunner.php` | 58–86 | Document per-attempt vs cross-attempt field lifecycle |
| `src/WrapperRunner/WrapperRunner.php` | 120–140 | Split `run()` into `run()` + `runAttempt()` with retry=0 short-circuit |
| `src/WrapperRunner/WrapperRunner.php` | 284–377 | `complete()` accepts `AttemptOutcome[]` |
| `src/WrapperRunner/WrapperRunner.php` | 350 | TestDox input = final attempt only |
| `src/WrapperRunner/WrapperRunner.php` | 429–444 | `generateJunitLog()` branches on metadata flag |
| `src/WrapperRunner/ResultPrinter.php` | 186–259 | R4 flaky summary section |
| `src/WrapperRunner/RetryOrchestrator.php` | — | **New class** |
| `src/WrapperRunner/AttemptOutcome.php` | — | **New readonly VO** |
| `src/WrapperRunner/SuiteLoader.php` | 131–161 | Pass 1 direct deps during iteration |
| `src/WrapperRunner/SuiteLoader.php` | after 159 | Pass 2 transitive closure via iterative DFS |
| `src/WrapperRunner/SuiteLoader.php` | 55–60 | Expose `public array $dependsMap` |
| `src/JUnit/FailedTestExtractor.php` | — | **New class** |
| `src/JUnit/LogMerger.php` | 16–67 | Add `mergeAcrossAttempts()`, `merge()` untouched |
| `src/JUnit/TestCaseWithRetries.php` | — | **New subclass** |
| `src/JUnit/Writer.php` | 84–110 | Emit Surefire elements + `retries` attribute |
| `src/ParaTestCommand.php` | 75–97 | No change (flags flow via `Options::fromConsoleInput()`) |

---

## 6. JUnit XML output format (R1 on)

### Sample: `testFlaky` fails attempt 1, passes attempt 2

```xml
<testcase name="testFlaky" class="ExampleTest" file="..." line="20" time="0.002" retries="1">
  <flakyFailure type="PHPUnit\Framework\AssertionFailedError" message="Failed asserting that false is true.">
ExampleTest::testFlaky
/path/ExampleTest.php:21
  </flakyFailure>
</testcase>
```

### Sample: `testStillBroken` fails all 2 attempts (final still fails)

```xml
<testcase name="testStillBroken" class="ExampleTest" file="..." line="30" time="0.003" retries="1">
  <rerunFailure type="PHPUnit\Framework\AssertionFailedError" message="still broken">...attempt 1 stack trace...</rerunFailure>
  <failure type="PHPUnit\Framework\AssertionFailedError" message="still broken">...final attempt stack trace...</failure>
</testcase>
```

With `--junit-retry-metadata` off, JUnit is bit-identical to current behavior (only final attempt's data, no new attributes).

Native consumers: Jenkins JUnit plugin, GitHub Actions test-reporter, Azure DevOps publish task.

---

## 7. Edge cases (20 identified)

Full list in [retry-feature-design.md](retry-feature-design.md) §4 + [retry-feature-devils-advocate.md](retry-feature-devils-advocate.md). Key handling:

- **Pass-on-retry** → flaky (not failure), exit 0, summary lists it
- **Worker crash** → fatal, retry suppressed (R3)
- **`@depends` chain** → transitive closure resolver pulls all ancestors into retry set
- **Data provider, functional mode** → only failed data row retried
- **Data provider, non-functional mode** → whole test method retried (documented)
- **`--stop-on-failure`** → takes priority, warning emitted
- **Random order** → subset order preserved (no re-shuffle)
- **PHPT tests** → retriable whole-file
- **Sharding** → orthogonal; each shard retries its own
- **Result cache + `--order-by=defects`** → flaky tests rewritten as FAILURE in cache to preserve defect prioritization

---

## 8. Non-blocking concerns (document/warn at PR time)

1. **H1 TeamCity stdout duplication** — when `--teamcity` (stream) + `--retry>0`, buffer non-final attempts to tmp file, discard.
2. **H2 Result-cache poisoning** — rewrite flaky tests as FAILURE in cache after final aggregation.
3. **H3 Null-byte in dataset** — reject work items with embedded `\0`, log + skip.
4. **H4 `--stop-on-failure` + `--retry`** — emit startup warning "retry disabled".
5. **H5 Random order subset** — explicit test: preserve projection order, no re-shuffle.
6. **H6 Coverage union inflation** — warning in `generateCodeCoverageReports()` when `$options->retry > 0`; docs warning in `--help` and README.

---

## 9. Test plan (52 methods across 5 classes)

Full spec in [retry-feature-test-plan.md](retry-feature-test-plan.md). Phased execution order (unit → integration):

1. **`OptionsTest`** (+9 methods) — CLI validation, range check, enum mapping
2. **`FailedTestExtractorTest`** (11 methods) — XML → work-item mapping, `@depends` closure
3. **`LogMergerCrossAttemptTest`** (6 methods) — streaming merge, Surefire elements, backward compat
4. **`RetryOrchestratorTest`** (12 methods) — orchestration, crash propagation, stop-on-failure
5. **`WrapperRunnerRetryTest`** (14 methods) — end-to-end subprocess integration

### Required fixtures under `test/fixtures/retry/`

- Counter-based flaky test (tmp-file counter, fails attempt 1, passes attempt 2)
- Always-failing test
- Test with `@depends` chain (A→B→C)
- Data-provider test with one flaky row
- Test that crashes worker (segfault/fatal)
- PHPT test

---

## 10. Implementation phases

### Phase A — Foundation (parallel-safe)

- [ ] A1: `Options.php` changes — 3 new flags + validation — [SC-4, SC-5, R5]
- [ ] A2: `OptionsTest` test additions
- [ ] A3: `MessageType` enum confirm (no changes needed, but spec-cover)

### Phase B — JUnit machinery (parallel with Phase C)

- [ ] B1: `FailedTestExtractor` new class
- [ ] B2: `TestCaseWithRetries` new class
- [ ] B3: `LogMerger::mergeAcrossAttempts()` streaming impl
- [ ] B4: `Writer::createCaseNode()` extension (Surefire elements)
- [ ] B5: Unit tests for B1–B4

### Phase C — Orchestration (parallel with Phase B)

- [ ] C1: `SuiteLoader::$dependsMap` two-pass DFS
- [ ] C2: `AttemptOutcome` VO
- [ ] C3: `WrapperRunner::run()` → `runAttempt()` refactor with retry=0 short-circuit
- [ ] C4: `RetryOrchestrator` new class
- [ ] C5: `WrapperRunner::complete()` signature change
- [ ] C6: Unit tests for C1–C5

### Phase D — Wire-up & integration

- [ ] D1: `ResultPrinter` flaky summary section
- [ ] D2: Non-blocking concerns H1, H2, H4, H6 (warnings + result-cache rewrite)
- [ ] D3: `WrapperRunnerRetryTest` integration tests + fixtures
- [ ] D4: Bit-identical regression assertion (SC-5)

### Phase E — Documentation & release prep

- [ ] E1: README section on retry feature
- [ ] E2: `--help` text for new flags including coverage-union caveat
- [ ] E3: CHANGELOG entry
- [ ] E4: PHPStan level-max clean
- [ ] E5: Doctrine-coding-standard clean

---

## 11. Definition of done

- All 10 SCs green (52 new test methods + existing regression pass)
- `--retry=0` run produces bit-identical artifacts to pre-refactor baseline (automated assertion)
- PHPStan max + PHP-CS-Fixer + doctrine-coding-standard clean
- Infection mutation score not regressed on changed files
- Manual smoke test: flaky fixture suite passes with `--retry=2`, produces valid Surefire JUnit XML parsed by Jenkins JUnit plugin
- All new PHP files: `declare(strict_types=1)`, readonly properties where applicable, specific exceptions
- No hardcoded constants; retry max (10) and default `--retry-on` tokens live in appropriate config location per CLAUDE.md

---

## 12. Risks & mitigations

| Risk | Mitigation |
|---|---|
| PHPUnit API changes between minor versions (`TestCase::requires()`, `ExecutionOrderDependency` shape) | Two-pass DFS pattern is independent of PHPUnit internals; only consumes public `requires()` return. Smoke-test against PHPUnit 11, 12, 13 matrix. |
| Schema divergence regressions in strict XSD environments | Metadata is opt-in (`--junit-retry-metadata`); default off = zero risk. |
| Result-cache rewrite breaks `--order-by=defects` in unexpected ways | Gated: only rewrite flaky entries to FAILURE, do not touch non-flaky entries. Integration test covers. |
| Memory explosion on huge suites (50k+ tests with retry=10) | Streaming `mergeAcrossAttempts()` bounds peak at `O(final_test_count + max(attempt_test_count))` ≈ 200 MB at 50k×10 (perf review verified). |
| Worker pool warmup cost at retry=10 (80 cold process starts) | Documented trade-off; warning in `--help` for bootstrap-heavy suites. `--retry-reuse-workers` is a possible follow-up. |

---

## 13. Follow-up (out of MVP scope)

- `--retry-on=crash` — opt-in retry for worker crashes (currently R3-fatal)
- `--retry-on=risky`, `--retry-on=warning` — once `MessageType` enum extended
- `--retry-delay=MS` — pause between attempts
- `--retry-reuse-workers` — skip pool restart if warm
- TestDox `(flaky)` marker
- TeamCity stream live retry annotation
