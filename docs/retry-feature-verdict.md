# Retry Feature — Planning Phase Verdict (v3 — FINAL)

## Verdict

**READY** — all four blockers are concretely resolved in the revised design. One test-plan method carries a stale `<system-out>` assertion that contradicts the new Surefire schema; it must be corrected before the test-writer begins Phase 2 (no additional architect revision required — the fix is a one-line change to the test spec, not to the design).

---

## Blocker resolution verification

### PB1. JUnit schema diverges from Surefire convention

**Status: RESOLVED**

Evidence in revised design:

- `design.md:7` (R1) — explicitly names `<flakyFailure>` / `<flakyError>` / `<rerunFailure>` / `<rerunError>` as the emitted children, not `<system-out>`.
- `design.md:352-355` (§3.3 step 4) — element selection table: `failure + pass → <flakyFailure>`, `error + pass → <flakyError>`, `failure + fail → <rerunFailure>`, `error + fail → <rerunError>`. Concrete, unambiguous.
- `design.md:387-401` (§3.4 sample XML) — actual XML shows `<flakyFailure type="..." message="...">stack</flakyFailure>` and `<rerunFailure ...>` in correct positions.
- `design.md:409-415` (§3.5) — explicitly cites Maven Surefire convention; lists Jenkins, GitHub Actions, Azure DevOps as native consumers.
- `design.md:464` (§5 patch table) — `Writer.php:84-110` entry describes Surefire-convention children with `type`/`message` attributes and stack-trace text.

**One test-plan residue to fix (non-blocking for READY, blocking for implementation start):**

`test-plan.md:63` — `testMergeAcrossAttemptsWithMetadataEmitsPriorAttemptSystemOut` asserts "prior attempt failure messages appear as `<system-out>` children". This is a stale description from the pre-revision design. The revised design emits `<flakyFailure>`/`<rerunFailure>` instead. The test method must be renamed to `testMergeAcrossAttemptsWithMetadataEmitsFlakyFailureElement` and its assertion updated to check for the Surefire element, not `<system-out>`. **This correction must happen before Phase 2 of the test-writer's execution plan.** No additional design change needed.

---

### PB2. `DependencyGraph` via `TestCase::requires()` — transitive closure missing

**Status: RESOLVED**

Evidence in revised design:

- `design.md` §2.4 (full section, lines ~155-210) — describes two-pass DFS algorithm explicitly:
  - Pass 1: walks `TestSuite` tree, skips `PhptTestCase`, descends into `DataProviderTestSuite`, calls `TestCase::requires()` per instance, collects `class::method → direct_deps[]` into `$directDeps`.
  - `#[DependsOnClass]` handling: expands to all methods of that class via prefix-match on `"$className::"` keys already in `$directDeps`.
  - Pass 2: iterative DFS (explicit stack, not recursion — stack overflow guard), memoized per node, cycle guard marks nodes `in-progress` and ignores back-edges. Result: `public array $dependsMap` (transitive closure).
- `design.md` §5 patch table: `SuiteLoader.php:131-161` for Pass 1, `after 159` for Pass 2, `SuiteLoader.php:55-60` for `public array $dependsMap` exposure.
- `FailedTestExtractor` constructor (`design.md` §3.1): `@param array<string, list<string>> $dependsMap // transitive-closure ancestor map (values are flat ancestor lists, not just direct deps)` — the docblock explicitly flags that the map holds the full closure, not just direct deps.

The two-pass algorithm is fully specified with correct complexity (O(V+E)) and correct handling of the three `ExecutionOrderDependency` variants. No hand-waving.

---

### PB3. `--retry-on` input validation absent

**Status: RESOLVED**

Evidence in revised design:

- `design.md` §3.2 (Filter rules section) — contains explicit PHP pseudocode with `array_unique`, `array_map('trim', explode(',', $raw))`, validation loop throwing `InvalidArgumentException("Invalid --retry-on value: '$t'. Allowed: ...")`, empty-list guard when `$retry > 0`.
- `design.md` §3.2 — explicitly states "`crash` is NOT an accepted value in MVP — per R3, worker crashes are not retriable. The token is reserved for a future `--retry-on=crash` follow-up and passing it today fails the validation above."
- `design.md` §5 patch table: `Options.php:146-312` — "validate each token against allowlist `{failure, error, skipped}` (reject `crash` — reserved), reject empty set when `retry > 0`, map to `MessageType` enum".
- `OptionsTest::testRetryOnRejectsUnknownValues` exists in `test-plan.md:87`.

Validation is specified at the correct layer (parse time, not runtime), covers all four identified failure modes (unknown token, typo, empty, duplicate), and maps directly to `MessageType` enum to prevent string/enum drift at runtime.

---

### PB4. `mergeAcrossAttempts()` SimpleXML full-load — OOM at scale

**Status: RESOLVED**

Evidence in revised design:

- `design.md` §3.3 algorithm header: explicitly labels the algorithm "streaming, memory-bounded — resolves PB4".
- Steps 1-3 in §3.3 specify:
  1. Final attempt parsed into canonical `TestSuite $final`, cases indexed into a hash map `(class, name) → reference`. Memory: O(final_test_count).
  2. Each prior attempt N parsed via `LogMerger::merge()` into `TestSuite $prior`. Matching prior cases are used to attach message payloads to `TestCaseWithRetries` instances in `$final`. Only small message payloads are retained.
  3. "`Drop $prior` before loading attempt N+1" — explicit memory-free instruction.
- `design.md` §4 open-q-1: "Resolved (PB4): peak drops from ~1 GB to ~200 MB" for the 50k × 10 extreme case.
- Peak memory formula stated: `final_test_count + max(attempt_test_count)` — down from `sum(attempt_test_count)`.

v2 re-delegation offered two acceptable resolutions: sequential merge OR documented ceiling plus startup guard. The architect chose the sequential merge (the stronger solution). No startup guard is required under this model because the peak is bounded by a single attempt's parsed objects, not the sum. The design is correct.

---

## Remaining concerns (H1-H6 from devils-advocate)

All six material concerns carry over from v2 and remain non-blocking for READY but must be addressed before the PR is submitted. Status has not changed:

| Concern | Topic | Design acknowledgment | Required action |
|---|---|---|---|
| H1 | `--teamcity` stdout duplicates on retry | §2.7: last-attempt-only for `--log-teamcity` file; stdout streaming gap not explicitly addressed | Must buffer non-final attempts' stdout TeamCity events or prefix with `[attempt N]`; document in `--help` |
| H2 | Result cache poisons `--order-by=defects` for flaky tests | §4 open-q-3: acknowledged, not resolved | After final aggregation, rewrite flaky test cache entries to FAILURE before cache writer at `WrapperRunner.php:339-348` |
| H3 | Null byte in dataset name breaks `\0` separator | §2.5 edge case acknowledged in design V8 note | Low priority; add log-and-skip guard in `FailedTestExtractor` |
| H4 | `--stop-on-failure` + `--retry` silent interaction | §2.7 interaction matrix documents "stop wins" | Add startup warning: "`--retry=N` has no effect with `--stop-on-failure`" |
| H5 | Coverage union counts error-path lines as covered | §4 open-q-4: acknowledged, not resolved | Add warning in `generateCodeCoverageReports()` at `WrapperRunner.php:405-411` when `retry > 0`; document in `--help` |
| H6 | `--random-order` retry subset ordering unspecified | §2.7 states "order within pending list is preserved" | Explicitly document that retry preserves relative ordering of the attempt-1 shuffle subset (no re-shuffle); add assertion test |

H1 is the highest-risk concern: `--teamcity` stdout duplication will cause visible failures in PhpStorm's test runner and CI IDE plugins. The implementing developer must address H1 in the same PR as the core feature.

---

## Test plan adequacy

The test plan covers all 10 SCs with 52 named test methods across 5 test classes. Phase ordering (Options → pure unit → orchestrator unit → integration) is sound.

**Gap requiring correction before Phase 2:**

`test-plan.md:63` — `testMergeAcrossAttemptsWithMetadataEmitsPriorAttemptSystemOut` specifies `<system-out>` children. The revised design emits `<flakyFailure>`/`<rerunFailure>` instead. The implementing test-writer must:

1. Rename this method to `testMergeAcrossAttemptsWithMetadataEmitsFlakyFailureElement`.
2. Change the assertion to verify a `<flakyFailure>` element with correct `type` and `message` attributes for a flaky test, and a `<rerunFailure>` element for an exhausted failure.

No other test plan gaps found. The `DependsChainTest.php` fixture (`test-plan.md` §2, fixture descriptions) exercises the transitive closure (PB2 fix) via `testExtractsDependsChainAncestors`. The `OptionsTest::testRetryOnRejectsUnknownValues` test covers PB3. The SC-7 integration tests cover PB1 end-to-end.

**Minor observation (non-blocking):** Neither the test plan nor the design specifies a test for the `#[DependsOnClass]` cross-class expansion path in the two-pass resolver. The `DependsChainTest.php` fixture uses `@depends` (method-level). A second fixture or parametrized case with `#[DependsOnClass]` would close this gap. Recommend adding it in Phase 3 of the test-writer's execution plan.

---

## Definition of done for implementation phase

The implementing developer must verify each item before opening the PR:

- [ ] `Options::fromConsoleInput()` validates `--retry-on` tokens against `{failure, error, skipped}`, throws `InvalidArgumentException` naming the offending token, rejects empty list when `retry > 0`, deduplicates. `--retry=11` and `--retry=-1` throw.
- [ ] `SuiteLoader` exposes `public array $dependsMap` built via two-pass DFS (Pass 1: direct deps via `TestCase::requires()`; Pass 2: iterative DFS with memoization and cycle guard). `#[DependsOnClass]` entries expand to all `"$className::"` prefixed methods.
- [ ] `FailedTestExtractor::extractFailures()` returns work items in `SuiteLoader::$tests` format; closes the failure set under `$dependsMap` ancestors; deduplicates.
- [ ] `LogMerger::mergeAcrossAttempts()` processes prior attempts sequentially (drop each `$prior` before loading the next); emits `<flakyFailure>`/`<flakyError>`/`<rerunFailure>`/`<rerunError>` children on `<testcase>` per the §3.3 element selection table; emits `retries="N"` as additive attribute.
- [ ] `--junit-retry-metadata` off: JUnit output byte-identical to today (SC-5).
- [ ] `--retry=0`: all backward compatibility proofs in §2.8 hold. Existing `WrapperRunnerTest` passes unchanged.
- [ ] `--stop-on-failure` suppresses retry; emits startup warning when combined with `--retry>0`.
- [ ] `--teamcity` stdout: non-final attempt events are buffered and discarded, not streamed to stdout (H1).
- [ ] Flaky test result-cache entries rewritten to FAILURE after final aggregation (H2).
- [ ] Coverage union warning emitted at `generateCodeCoverageReports()` when `retry > 0` (H5).
- [ ] All 52 test plan methods pass, including the renamed `testMergeAcrossAttemptsWithMetadataEmitsFlakyFailureElement` with corrected `<flakyFailure>` assertions.
- [ ] PHPStan level 8+ passes on all new and modified files.
- [ ] `declare(strict_types=1)` present in every new PHP file.

---

## Final approval

**READY — hand off to implementation swarm.**

The planning package is complete. All four blockers (PB1-PB4) are concretely resolved with file:line-level specificity in the revised design. All 10 success criteria have named design mechanisms and named test methods. All 6 user decisions (R1-R6) are traceable end-to-end. The architect verified 8 design claims (V1-V8) in the devils-advocate document; none was reversed by the revision.

Pre-implementation gate: the test-writer must correct `test-plan.md:63` (rename method, update `<system-out>` assertion to `<flakyFailure>`) before Phase 2 begins. This is a one-line spec correction, not a design problem.

Six material concerns (H1-H6) are carried forward as PR-merge requirements, not implementation blockers. H1 (`--teamcity` stdout) is the highest-risk and must be in scope for the implementation PR.
