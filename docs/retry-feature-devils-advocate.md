# Retry Feature — Adversarial Review

Only findings with confidence >= 80 are reported. Every claim cites file:line. Inputs reviewed: `retry-feature-design.md` (all sections), `retry-feature-research.md` (Surefire convention), source files listed per finding.

---

## Critical findings (blockers — must resolve before coding)

### B1. Schema divergence from industry convention (confidence 95)

**Claim.** Design §3.3–§3.5 emits `retries="N"` attribute on `<testcase>` plus `<system-out>` children containing escaped prior-attempt payloads. Research doc §7.1 recommends the **Surefire convention**: sibling elements `<flakyFailure>` / `<flakyError>` (test eventually passed) and `<rerunFailure>` / `<rerunError>` (test ultimately failed after N reruns). This convention is recognized natively by Jenkins JUnit plugin, GitHub Actions test-reporter, Azure DevOps publish task, and most CI dashboards.

**Evidence of divergence.** Design §3.5 explicitly acknowledges: _"Surefire's schema only allows `<system-out>` at `<testsuite>` level"_ — then proceeds to put it under `<testcase>` anyway. No justification offered for rejecting the adopted convention.

**Impact.** Opt-in metadata that no existing CI consumer can parse is equivalent to no metadata at all. Users who enable `--junit-retry-metadata` to get flaky-test insights in their dashboards will get nothing. Meanwhile, strict XSD validation against Surefire schema will reject paratest output.

**Recommended fix.** Switch `LogMerger::mergeAcrossAttempts()` and `Writer::createCaseNode()` (`Writer.php:84-110`) to emit `<flakyFailure>`/`<flakyError>`/`<rerunFailure>`/`<rerunError>` children with the same message/type/text payload already used for `<failure>`/`<error>` (`TestCase.php:50-110`). Keep the case's top-level attributes stable. Drop the `retries="N"` attribute or make it additive (harmless) but not primary.

---

### B2. `DependencyGraph` via `TestCase::requires()` — incorrect API reference (confidence 85)

**Claim.** §2.4 step 1 proposes building `$dependsMap` by walking the PHPUnit `TestSuite` and calling `TestCase::requires()` during `SuiteLoader::loadFiles()` (`SuiteLoader.php:133-157`).

**Problem.** PHPUnit's `TestCase::requires(): list<ExecutionOrderDependency>` returns dependency objects for the invoking instance only. Walking `TestSuiteBuilder::build()` output (`SuiteLoader.php:98`) encounters mixed types: `TestCase`, `DataProviderTestSuite`, `PhptTestCase`, nested `TestSuite` — not all expose `requires()`.

More importantly: `requires()` returns **direct dependencies only**, not the transitive closure. If A→B→C, calling `requires()` on C returns `[B]`, not `[A, B]`. `FailedTestExtractor` needs the full closure to safely retry C (otherwise C retries without A, PHPUnit marks C skipped).

**`#[DependsOnClass]`.** Cross-class dependencies resolve via PHPUnit's `TestSuiteSorter` which reorders across classes. The architect's single-pass `requires()` call does not expose cross-class edges explicitly — they are embedded in `ExecutionOrderDependency::$className` which may lack a method name.

**Recommended fix.** Two-pass resolver: (a) first pass collects flat `class::method` → direct-deps; (b) second pass computes transitive closure via DFS (O(V+E), trivial). `ExecutionOrderDependency` with class-only (`#[DependsOnClass]`) expands to "all methods of that class" — emit the whole class as an ancestor. `#[DependsUsingDeepClone]` / `#[DependsUsingShallowClone]` are flag variants of `#[Depends]` and share the same edge — no special handling needed.

---

### B3. `--retry-on` input validation missing (confidence 90)

**Claim.** §3.2 defines `--retry-on` as comma-separated `{failure, error, skipped}`. Filter logic is `in_array($case->xmlTagName, $this->retryOn, true)`.

**Problem.** Design does not specify validation in `Options::fromConsoleInput()` (`Options.php:146-312` patch point). Consequences:

- `--retry-on=crash` (reserved for R3 follow-up) — silently no-ops (not in `MessageType.php:8-22` enum), misleads user.
- `--retry-on=failures` (typo) — silently matches nothing.
- `--retry-on=""` (empty) — retry runs N attempts, retries nothing each time, wastes CI time.
- `--retry-on=failure,failure` — degenerate but accepted.

**Recommended fix.** In `Options::fromConsoleInput()`: explode by comma, trim, validate each token against `MessageType` enum cases (`failure`, `error`, `skipped`). Reject unknown tokens with `InvalidArgumentException` naming the offender. Reject empty list when `--retry>0`. Deduplicate on parse.

---

## High-confidence concerns (should resolve)

### H1. Live TeamCity stream (stdout) is not covered by last-attempt-only (confidence 85)

**Claim.** §2.7: _"TeamCity: Last-attempt-only. `ResultPrinter::printResults()` writes TeamCity from `teamcityFiles`."_

**Problem.** `--log-teamcity=FILE` (file output) is correctly gated — only final attempt's file emitted. But `--teamcity` (stdout streaming) is produced live by workers as tests run. When retry runs attempt 1 then attempt 2, **both attempts' TeamCity events stream to stdout in sequence**. IDEs (PhpStorm test runner) see:

```
##teamcity[testStarted name='testFlaky']
##teamcity[testFailed name='testFlaky' ...]
##teamcity[testFinished name='testFlaky']
... attempt 2 starts ...
##teamcity[testStarted name='testFlaky']     <-- duplicate, IDE panics
```

PhpStorm's TeamCity parser does not tolerate duplicate `testStarted` for a name already finished; display may double-entry, error, or merge incorrectly.

**Recommended fix.** When `--teamcity` (stdout) AND `--retry>0`: buffer non-final attempts' teamcity output into tmp file, discard on final attempt. Alternatively prefix retried-test names with `[attempt N] ` to disambiguate. Document in `--help`.

---

### H2. Result-cache poisons `--order-by=defects` for flaky tests (confidence 85)

**Problem.** Design §4 open-q-3 flags this unresolved. Concretely: PHPUnit's `DefaultResultCache` is updated per attempt (§2.3 runs `waitForAllToFinish()` each attempt, which writes cache). A test that fails attempt 1 then passes attempt 2 ends with cached status "passed." On the *next* paratest run with `--order-by=defects`, this test is NOT prioritized as defect — despite being known-flaky.

**Recommended fix.** After final aggregation, if `RetryOrchestrator::getFlakyTestList()` is non-empty, rewrite each flaky test's entry in the result cache to `FAILURE` before `WrapperRunner::complete()` calls cache writer (`WrapperRunner.php:339-348`). Preserves `--order-by=defects` semantics at cost of a slight lie about most recent outcome — acceptable trade-off. Document.

---

### H3. Functional-mode null-byte in dataset breaks work-item format (confidence 80)

**Claim.** §2.5 / §3.1 step 2b: emit `/preg_quote($junitName, '/')\$/`.

**Verification.** `preg_quote` is concatenative, so `preg_quote(A.B) === preg_quote(A).preg_quote(B)`. Original emission `SuiteLoader.php:143` is `preg_quote($name).preg_quote($dataSetAsString)` → equivalent to `preg_quote($name.$dataSetAsString)`. JUnit `name` attribute stores combined display name. Mapping is **mathematically sound** for typical cases.

**Edge.** XML parsing decodes `&amp;`, `&lt;`, `&gt;`, `&quot;`, `&apos;` before `preg_quote` operates — no issue. But dataset names containing raw null bytes (`\0`) would break the `\0` separator between file and regex in work-item format `"$file\0/$pcre$/"`. PHPUnit does not forbid null bytes in data-provider keys — in practice never appear, but no guard exists.

**Recommended fix.** Reject work items containing embedded `\0` in dataset portion at extraction time; log + skip. Low priority.

---

### H4. `--stop-on-failure` kills retry even for flaky tests (confidence 80)

**Claim.** §2.7 + interaction matrix: `--stop-on-failure` takes priority, retry suppressed.

**Problem.** Product-level tension: user enabling both `--retry=3` (tolerate flakiness) and `--stop-on-failure` (strict CI) gets contradictory semantics. Architect chose stop wins. Silent behavior surprises users.

**Recommended fix.** Emit warning at startup when both flags set: _"`--retry=N` has no effect with `--stop-on-failure`; retry disabled."_ Document in `--help` for both flags.

---

### H5. `--random-order` without explicit seed — retry reproducibility unspecified (confidence 80)

**Claim.** §2.7: _"random-order-seed: Applied once at `SuiteLoader.php:104-106`. Order within pending list is preserved."_

**Problem.** If user runs `--random-order` without `--random-order-seed`, paratest generates a random seed once. On retry attempt 2, if the *retry subset* is re-shuffled with the same seed, Fisher-Yates on smaller array produces a different permutation than the original slice. Design does not clarify whether the subset ordering is a stable projection of attempt-1's shuffle or a re-shuffle.

**Recommended fix.** Explicitly: on retry, preserve the relative order of the pending subset as it emerged from attempt 1's shuffle (do NOT re-shuffle within the subset). Document and add assertion test.

---

### H6. Coverage union lie — error-path lines counted as covered (confidence 85)

**Problem.** Design §4 open-q-4 acknowledges, defers. A test failing in attempt 1 hits error-path lines (exception handlers) that the passing attempt 2 never reaches. With R6=union, those error-path lines appear covered. Teams with coverage-gated merges (e.g., 85% required) may pass gate on covered error paths that are never reached in successful runs.

**Recommended fix.** Document loudly in `--help` and README: _"Coverage with `--retry>0` uses union-across-attempts; lines exercised only in failing attempts appear covered."_ Additionally emit warning at `generateCodeCoverageReports()` (`WrapperRunner.php:405-411`) when `$options->retry > 0`. No deeper code change.

---

## Open questions (design ambiguity, not defect)

- **Q1.** §2.3 step 1 reset list claims exhaustive based on `WrapperRunner.php:58-86`. Verify: `$teamcityLogFileHandle` (`WrapperRunner.php:82`) — does opening it once outside the loop work, or must it rewind between attempts to avoid attempt-1 garbage? Not specified.
- **Q2.** §3.3 step 4: prior attempts embedded as `<system-out>`. With max `--retry=10` and verbose PHPUnit failure (~5KB stack trace), each testcase balloons ~50KB. For 100 flaky tests, JUnit file 5MB. Legacy Bamboo JUnit parsers cap at 10MB. Probably fine at current cap, worth documenting.
- **Q3.** TestDox §3.6: passes only final attempt's files. Flaky test (attempt 1 fail, attempt 2 pass) shows only "passed" with no retry indication. Desired UX, or should TestDox append `(flaky)` marker?

---

## Verified claims (architect got these right)

- **V1.** Fresh worker pool per attempt is isolation-correct (`WrapperWorker.php:44-48, 208-222` — `$currentlyExecuting`/`$inExecution`/status-file positions are per-process; would need explicit reset otherwise).
- **V2.** `rename()` archival is atomic on same filesystem and preserves inodes (POSIX guarantee). `{tmpDir}/attempt-{N}/` namespace correctly disambiguates `uniqid()`-suffixed filenames (`WrapperWorker.php:62`).
- **V3.** `--retry=0` short-circuit in `run()` preserves single `printer->start()` and single `startWorkers()` call — §2.8 items 4, 8 hold under refactor.
- **V4.** `MessageType` enum (`MessageType.php:8-22`) matches `TestCaseWithMessage::$xmlTagName` exactly — filter logic in §3.2 is correct.
- **V5.** PHPT tests correctly emitted as bare `$file` work items; `class="PHPUnit\Runner\Phpt\TestCase"` discriminator is stable PHPUnit API (`SuiteLoader.php:136-137`).
- **V6.** Coverage multi-file merge in existing `Merger` handles N·attempts files without quadratic blowup — R6 is "free."
- **V7.** `--stop-on-defect/error/warning/risky` handled symmetrically with `--stop-on-failure` via `max($this->exitcode, ...)` at `WrapperRunner.php:198`.
- **V8.** `preg_quote` concatenative property makes §2.5 reverse mapping mathematically sound for typical cases (see H3 for the one edge).

---

## Summary for tech-lead

- **3 blockers** (B1 schema divergence, B2 depends-resolver API, B3 CLI validation) — must resolve before coding begins.
- **6 high-confidence concerns** — require documented resolution, warning emission, or behavior clarification.
- **3 open questions** — need architect decision.
- **8 claims verified** as correct.

**Recommendation.** Send architect back with B1, B2, B3 specifically. Bring performance review's blockers (DOM memory in `mergeAcrossAttempts`) into the same revision pass so architect fixes all five blockers simultaneously. Keep other concerns for PR review phase.
