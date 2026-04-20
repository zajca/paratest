# Retry Feature — Research

Research on how popular test runners implement retry-failed-tests functionality, conducted to inform the design of the same feature in ParaTest (parallel PHPUnit runner).

## 1. pytest-rerunfailures (Python/pytest)

### CLI Surface
- **Flag**: `--reruns N` (set number of reruns, 0–10+)
- **Overrides**: `--force-reruns` (overrides all markers and config)
- **Filtering**:
  - `--only-rerun REGEX` (retry only failures matching exception pattern; multiple times)
  - `--rerun-except REGEX` (exclude failures matching pattern; multiple times)
- **Config file**: `pytest.ini` / `pyproject.toml` (option: `reruns = 3`)
- **Marker-based** (highest priority): `@pytest.mark.flaky(reruns=5)`

### Failure Type Coverage
- **Default**: All failures (including setup/teardown failures and fixture errors)
- **Can be filtered** by exception type with `--only-rerun` and `--rerun-except`

### Test Granularity
- Per-test (using marker) or per-run (global CLI flag)
- **Known footgun**: With `@pytest.mark.parametrize`, dynamic parameter generators (e.g., `generate_timestamp()`) are **not re-evaluated** during retries; parametrization happens at collection time. Retried tests use the original parameter values.

### JUnit XML Output
- **Current limitation**: JUnit XML output does **not** include retry information by default
- Issue #97 raised this gap; no built-in XML extension implemented
- Workaround: Use `record_property()` fixture to annotate retry count in test properties, not XML elements

### Parallel Execution (pytest-xdist)
- **Compatible** with `pytest-xdist -n` flag for parallelization
- **Crash recovery** (since pytest-rerunfailures 13.0+): If pytest-xdist 2.3.0+ installed and `-n` flag used, can recover from hard crashes (segfaults) **assuming workers and controller are on same LAN**
- **Incompatible** with `--looponfail` flag (xdist-specific re-run mode)
- Cannot coexist with the separate `flaky` plugin
- Incompatible with core pytest `--pdb` flag

### Known Issues & Gotchas
- Parametrized tests don't regenerate dynamic data on retry (Issue #149)
- JUnit XML integration incomplete
- State from first failure attempt carries into retries (no automatic cleanup between attempts)

**References**:
- [pytest-rerunfailures GitHub](https://github.com/pytest-dev/pytest-rerunfailures)
- [pytest-rerunfailures Documentation](https://pytest-rerunfailures.readthedocs.io/latest/configuration.html)
- [Issue #97: JUnit XML Integration](https://github.com/pytest-dev/pytest-rerunfailures/issues/97)
- [Issue #149: Parametrize Re-evaluation](https://github.com/pytest-dev/pytest-rerunfailures/issues/149)

---

## 2. Jest `--testRetries` / `jest.retryTimes()`

### API Surface
- **Programmatic API**: `jest.retryTimes(numRetries, options?)`
  - `numRetries` (required): number of retries
  - `options` (optional):
    - `logErrorsBeforeRetry` (boolean): log errors before retry
    - `waitBeforeRetry` (number): milliseconds delay before retry
    - `retryImmediately` (boolean): retry immediately after fail (default: queue after suite finishes)

- **CLI**: No direct `--testRetries` flag in Jest core; retries are configured programmatically in test files only

### Test Granularity
- Per-test or per-describe-block (declared at file/block scope, affects all tests within that scope)
- Cannot be declared in a setup phase; must be at top level of test file or describe block
- Applies to all tests following the call in execution order

### Failure Type Coverage
- Only test failures (assertions); not setup/teardown errors
- Only available with the **jest-circus** runner (default for Jest 27+)

### Output Format
- Console output shows retry counts in test names/output
- **No explicit flaky marking** in default reporters
- Third-party libraries (jest-retry, jest-retries) provide enhanced reporting

### Coverage Handling
- **Not documented** in Jest's retry feature; coverage likely not cumulative across retries
- Appears to be best-effort coverage from last attempt

### Known Gotchas
- `retryImmediately: true` runs retries during test execution (not queued), affecting overall test timing
- No CLI flag means retries must be hardcoded in test files
- Not compatible with other runners (vitest, etc.)

**References**:
- [Jest Object Documentation](https://jestjs.io/docs/jest-object)
- [Jest CLI Options](https://jestjs.io/docs/cli)
- [Issue #14696: Feature Request for Immediate Retry Option](https://github.com/jestjs/jest/issues/14696)

---

## 3. Playwright `retries`

### Configuration API
- **Config file**: `playwright.config.ts`
  ```typescript
  export default defineConfig({
    retries: 3,  // Global default
  });
  ```

- **Per-test override**: `test.describe.configure({ retries: 2 })`

- **CLI**: `npx playwright test --retries=3`

- **Runtime detection**: `testInfo.retry` property to detect current retry attempt and execute cleanup logic

### Failure Type Coverage
- Only test failures (assertions)
- Setup/teardown failures are retried if they fail before or during the test

### Test Granularity
- Per-test (entire test function) or per-describe block
- Cannot be per-data-row; a parameterized test set uses the same retry count

### Output Categorization
Playwright explicitly categorizes test outcomes:
- **Passed**: Passed on first run
- **Flaky**: Failed first run, passed on retry
- **Failed**: Failed on all attempts

### JUnit XML Output (Current Limitation)
- **By design**: Retries are **omitted** from JUnit XML to maintain standard compliance
- Issue #35592 (in-review): Proposes opt-in flaky annotation (`flaky="true"` attribute or `<flaky>` element)
- Issue #29446: Proposes including all retries as separate `<testcase>` elements
- **Status**: Feature not yet implemented; proposal in community feedback phase

### Console Output
- Shows [FLAKY] or [FAILED] prefix for retried tests
- Retry count visible in report text

### Coverage Handling
- **Not documented**; likely cumulative or last-attempt

### Known Issues
- No JUnit XML flaky annotation yet (despite being the most-requested feature)
- Flaky detection disabled when retries enabled (per Trunk.io guidance for accurate flaky detection)

**References**:
- [Playwright Retries Documentation](https://playwright.dev/docs/test-retries)
- [Issue #35592: JUnit XML Flaky Annotation](https://github.com/microsoft/playwright/issues/35592)
- [Issue #29446: All Retries as Test Runs](https://github.com/microsoft/playwright/issues/29446)
- [Playwright Flaky Tests Guide](https://docs.trunk.io/flaky-tests/get-started/frameworks/playwright)

---

## 4. PHPUnit Extension Ecosystem

### Existing Packages

**Most Popular: `bshaffer/phpunit-retry-annotations`**
- Trait-based system for retry control
- Annotations:
  - `@retryAttempts N`: retry up to N times on any failure
  - `@retryForSeconds N`: retry for N seconds
  - `@retryIfException ExceptionClass`: retry only if specific exception thrown
  - `@retryIfMethod methodName`: use custom method to determine retry
  - `@retryDelaySeconds N`: fixed delay between retries
  - `@retryDelayMethod methodName`: custom delay (supports "exponentialBackoff" built-in)

- Integration: Install via Composer; include trait in test class
- Default: Retry on any exception except `IncompleteTestError` and `SkippedTestError`
- License: Apache-2.0

**Alternatives**:
- `keboola/phpunit-retry-annotations` (3 default retries; Keboola's fork)
- `profenom/phpunit-retry-annotations` (similar)
- `alorel/phpunit-auto-rerun` (class inheritance approach)

### PHPUnit Core Support
- **PHPUnit 11/12/13**: No built-in retry hooks; would require plugin or extension system
- PHPUnit 14+ may have hooks, but not documented in current versions
- PHPUnit relies on custom test listeners and lifecycle hooks for retry behavior

### Limitations of Existing Extensions
- Trait-based only; no CLI-level global retry setting
- Per-test configuration (annotation); no `--retry 3` CLI flag
- No built-in JUnit XML extension element support
- State from first attempt carries into retry (no automatic cleanup)

**References**:
- [bshaffer/phpunit-retry-annotations GitHub](https://github.com/bshaffer/phpunit-retry-annotations)
- [Packagist Results](https://packagist.org/search/?q=phpunit%20retry)

---

## 5. Surefire / Failsafe (Java/Maven) — JUnit XML Standard Bearer

### Configuration
- **Property**: `rerunFailingTestsCount` (set to `N` > 0)
- **Variants**: `skipAfterFailureCount` (skip remaining after N failures; since 2.19.1)
- **Variants**: `failOnFlakeCount` (fail build if flaky count exceeds threshold; since 3.0.0-M6)

### XML Element Convention (Most Widely Adopted)

When `rerunFailingTestsCount` > 0:

**Scenario 1: Test passes after retries (flaky test)**
```xml
<testcase name="testMethod" classname="TestClass" time="0.050">
  <flakyFailure message="AssertionError" type="java.lang.AssertionError">
    <stackTrace>...</stackTrace>
    <system-out>first failure output</system-out>
  </flakyFailure>
  <system-out>final success output</system-out>
</testcase>
```

**Scenario 2: Test fails all retries**
```xml
<testcase name="testMethod" classname="TestClass" time="0.050">
  <failure message="AssertionError" type="java.lang.AssertionError">
    <stackTrace>first failure stack trace</stackTrace>
    <system-out>first failure output</system-out>
  </failure>
  <rerunFailure message="AssertionError" type="java.lang.AssertionError">
    <stackTrace>second attempt stack trace</stackTrace>
    <system-out>second attempt output</system-out>
  </rerunFailure>
</testcase>
```

### Element Names (JUnit Standard Extension)
- `<flakyFailure>` / `<flakyError>`: for flaky tests
- `<rerunFailure>` / `<rerunError>`: for subsequent failure attempts

### Framework Support
- JUnit 4.x and 5.x (since 3.0.0-M4)
- Cucumber JVM (since 2.21.0)
- **Backward compatible**: Existing JUnit parsers ignore unknown elements

### Failure Type Coverage
- All test failures (assertions, thrown exceptions)
- Not setup/teardown by default

### pom.xml Example
```xml
<plugin>
  <groupId>org.apache.maven.plugins</groupId>
  <artifactId>maven-surefire-plugin</artifactId>
  <version>3.2.3</version>
  <configuration>
    <rerunFailingTestsCount>3</rerunFailingTestsCount>
    <skipAfterFailureCount>5</skipAfterFailureCount>
    <failOnFlakeCount>10</failOnFlakeCount>
  </configuration>
</plugin>
```

### Known Gotchas
- Flaky tests still contribute to failure count if configured
- `failOnFlakeCount` allows failing the build based on flakiness (useful for detecting regression)

**References**:
- [Maven Surefire Rerun Failing Tests](https://maven.apache.org/surefire/maven-surefire-plugin/examples/rerun-failing-tests.html)
- [SUREFIRE-1087 JIRA](https://issues.apache.org/jira/browse/SUREFIRE-1087)

---

## 6. Comparison Table

| Tool | CLI Flag | Default | Failure Types | Granularity | JUnit Extension | Coverage | Parallel/Crash |
|------|----------|---------|---------------|-------------|-----------------|----------|-----------------|
| **pytest-rerunfailures** | `--reruns N` | 0 | All (setup/teardown/fixture) | Per-test or global | No (issue #97) | Not cumulative | xdist: segfault recovery w/ 2.3.0+ |
| **Jest retryTimes** | Programmatic only | 0 | Test assertions only | Per-test or per-block | No | Not documented | N/A |
| **Playwright retries** | `--retries=N` or config | 0 | Test assertions only | Per-test or per-block | Proposed (issue #35592) | Not documented | Built-in; no separate workers |
| **PHPUnit extensions** | @Annotation only | 0 per-test | Any exception (filtered) | Per-test annotation | No | Not documented | N/A (single-process) |
| **Maven Surefire** | `<rerunFailingTestsCount>` | 0 | Test failures | Per-run global | Yes (`<flakyFailure>`, `<rerunFailure>`) | Unknown | N/A (Maven serial) |

---

## 7. Recommendations for ParaTest

### 7.1 JUnit Output Format (Element Names and Structure)

**Recommendation**: Adopt Maven Surefire's convention using `<flakyFailure>` and `<rerunFailure>` elements.

**Rationale**:
- Surefire is the originator and most widely-adopted convention across the Java ecosystem
- Backward compatible (existing JUnit parsers ignore unknown elements)
- Clear distinction between flaky (passed-on-retry) and consistently-failed (never passed)
- Already supported by major CI platforms (Jenkins, GitHub Actions, Azure DevOps)
- Playwright team is considering similar elements; alignment strengthens cross-language convention

**Exact structure** (opt-in via `--junit-retry-metadata`):

```xml
<!-- Flaky test (failed initially, passed on retry) -->
<testcase name="test_example" classname="Tests\Example" time="0.050">
  <flakyFailure message="AssertionError" type="PHPUnit\Framework\AssertionFailedError">
    <stackTrace>...</stackTrace>
  </flakyFailure>
</testcase>

<!-- Consistent failure (failed all attempts) -->
<testcase name="test_example" classname="Tests\Example" time="0.050">
  <failure message="AssertionError" type="PHPUnit\Framework\AssertionFailedError">
    <stackTrace>first attempt stack</stackTrace>
  </failure>
  <rerunFailure message="AssertionError" type="PHPUnit\Framework\AssertionFailedError">
    <stackTrace>second attempt stack</stackTrace>
  </rerunFailure>
</testcase>
```

**Attributes**:
- Include `message` (exception message) and `type` (exception class)
- Include `<stackTrace>` element for debugging
- Omit from standard JUnit XML when `--junit-retry-metadata` not set (maintain backward compatibility)

---

### 7.2 Default `--retry-on` Set

**Recommendation**: Default to **test failures and errors** only; do NOT retry setup/teardown/fixture failures by default.

**Rationale**:
- pytest-rerunfailures retries setup/teardown, but it's a known pain point (state carryover issues)
- Jest/Playwright only retry test-level failures
- ParaTest's worker isolation already handles test-level state; setup/teardown failures suggest infrastructure issues (database, file I/O) that retry won't fix
- Allows explicit opt-in for setup retry via `--retry-setup` if needed

**Failure types to include**:
- Test assertion failures (e.g., `AssertionFailedError`)
- Test-thrown exceptions
- Test-level errors

**Failure types to exclude** (unless explicitly enabled):
- Setup/teardown errors
- Data provider errors (parametrization failures)
- Fixture errors

**CLI surface**:
```bash
paratest --retry=3                           # Retry test failures only
paratest --retry=3 --retry-on=setup          # Also retry setup
paratest --retry=3 --retry-on=setup,error    # Multiple types (future expansion)
```

---

### 7.3 CLI Naming Convention

**Recommendation**: Use `--retry` (not `--retries`, `--rerun-failures`, `--reruns`).

**Rationale**:
- Playwright uses `--retries=N` (plural), but `--retry` is shorter and matches Maven property naming pattern
- Consistent with ParaTest's existing short flags (`--workers`, `--functional`)
- Avoids confusion with other tools (pytest uses `--reruns`, Maven uses property name)
- `--retry=0` is intuitive (disabled)

**Proposed flags**:
```bash
--retry=N                    # Number of retries (0–10 cap)
--retry-on=TYPE             # Comma-separated: failure,error,setup (default: failure)
--junit-retry-metadata       # Opt-in for JUnit XML extensions (as already decided)
```

---

### 7.4 Known Footguns to Handle/Document

**1. Parametrized Test Retry Issue (pytest footgun)**
- **Problem**: If using data providers that return new data on each call, ParaTest should re-invoke the provider on each retry, not reuse the original parameters.
- **Action**: Document this behavior clearly; ensure data provider is invoked fresh per retry, not cached from first attempt.
- **Example**: A test using `@dataProvider` with a method that returns `[new DateTime(), ...]` should call the provider again on retry.

**2. Test State Carryover**
- **Problem**: Global state from first failure attempt carries into retry.
- **Action**: Document that tests must clean up their own state; `setUp()` and `tearDown()` are called per-attempt; static variables persist across retries within same process.
- **Recommendation**: Provide `TEST_RETRY_COUNT` environment variable so tests can detect which attempt they're on and reset accordingly.

**3. Worker Crashes Are Not Retriable (already decided)**
- **Problem**: If a worker segfaults, test assignment is rebalanced; entire test does not re-run.
- **Action**: Document this clearly; pytest-rerunfailures + xdist 2.3.0+ have crash recovery, but ParaTest's MVP does not.
- **Future consideration**: Issue #650 (pytest-xdist) shows crash recovery is complex; defer to post-MVP.

**4. Coverage Union Across Attempts**
- **Decided**: Coverage = union across all attempts (not last-only, not cumulative).
- **Action**: Ensure coverage aggregator sums edge coverage across retries; document this behavior.

**5. Flaky Summary Timing**
- **Problem**: Flaky summary should not mislead on test count (e.g., "5 flaky" could mean 5 tests or 5 failure attempts).
- **Action**: Print explicit "X test(s) flaky" and "Y failure attempt(s) retried" in summary.

---

### 7.5 Console Output Conventions

**Recommendation**: Mirror Playwright's explicit flaky marking.

**Proposed console output**:
```
FAIL  tests/ExampleTest.php
  ...
  Tests: 10 failed, 5 passed
  Retried: 3 tests (7 failure attempts total)
  Flaky: 2 tests (passed on retry)
```

**Per-test output in verbose mode**:
```
⚠  FLAKY  tests/ExampleTest.php::testExample (failed 1/3 attempts, passed on retry 2)
✓  RETRY-PASS  tests/OtherTest.php::testOther (failed attempts 1, 2; passed on attempt 3)
✗  FAILED  tests/BadTest.php::testBad (failed all 3 attempts)
```

**Symbols/conventions**:
- `⚠  FLAKY` — test failed initially but passed on retry (show retry count)
- `✓  RETRY-PASS` — explicit "passed after N retries" (alternative phrasing)
- `✗  FAILED` — failed on all attempts (not flaky)
- **Color**: Use yellow/amber for FLAKY (warning level), red for FAILED

**Inspiration**:
- Playwright: Shows `[FLAKY]` and `[FAILED]` prefixes
- Jest: Shows retry counts in brackets
- Maven/Surefire: Lists flaky tests in summary section

---

## 8. Open Questions / Future Consideration

1. **Retry on errors vs. failures**: Should `Exception` and `Error` both be retried by default? Recommend conservative default (assertions only).

2. **Retry strategy for parameterized tests**: Should a single parameterized test variant retry independently? Recommend yes, and document that data provider is re-invoked.

3. **Coverage mode interaction**: Does `--coverage` + `--retry` + `--functional` work in all combinations? Flag any edge cases in testing phase.

4. **Worker crash recovery**: Defer to post-MVP; document as not-supported for MVP release.

5. **Timeout between retries**: Should ParaTest add configurable delays? Recommend no (sensible default: immediate), but allow future extension via `--retry-delay-ms`.

---

## References

- [pytest-rerunfailures GitHub](https://github.com/pytest-dev/pytest-rerunfailures)
- [pytest-rerunfailures Docs](https://pytest-rerunfailures.readthedocs.io/latest/configuration.html)
- [Jest retryTimes API](https://jestjs.io/docs/jest-object)
- [Playwright Test Retries](https://playwright.dev/docs/test-retries)
- [Playwright JUnit Flaky Issue #35592](https://github.com/microsoft/playwright/issues/35592)
- [bshaffer/phpunit-retry-annotations](https://github.com/bshaffer/phpunit-retry-annotations)
- [Maven Surefire Rerun Failing Tests](https://maven.apache.org/surefire/maven-surefire-plugin/examples/rerun-failing-tests.html)
- [SUREFIRE-1087 JIRA](https://issues.apache.org/jira/browse/SUREFIRE-1087)
