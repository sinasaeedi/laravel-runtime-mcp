# Security test inventory

Every security promise of Runtime MCP, and the tests that prove it. A reviewer can check the package from this page
without reading the stories: each row names the test files (and the test ids inside them) that must pass.

- **Spec 11.2** lists the required security tests. Each one has a row in [Required security tests](#required-security-tests).
- **Spec 12** lists the rules for every story. [Rules](#rules) says which test proves each testable rule, and which
  rules are review-only.
- **Spec 14** (the definition of done for v1.0.0) has four "a test proves it" items. [Definition of done](#definition-of-done)
  names those tests.

The tests in `tests/Feature/SecuritySuite/`, `tests/Integration/Pgsql/SecuritySuite/` and
`tests/Integration/Mysql/SecuritySuite/` are the cross-cutting suite: they run the whole stack, over HTTP, for every
registered tool. The other files are the tests of each layer, written with the layer. This folder holds only this page:
CI runs the `Unit`, `Feature` and `Arch` test suites and the `pgsql` and `mysql` groups (`tests/Integration/Pgsql`,
`tests/Integration/Mysql`), so a test file must live in one of those folders, or it runs in no CI job.

## How to read a row

- `File ID` is a test inside that file, by the id at the start of its name (`QueryRunnerTest QP15`). A test without an
  id is named by the start of its description.
- `Pgsql` and `Mysql` files run in CI on PostgreSQL 17, and on MySQL 8.4, MariaDB 11.4 and MariaDB 10.11. They fail,
  never skip, when their database is missing (`RUNTIME_MCP_TEST_REQUIRE_DATABASES=1`, `tests/Support/Databases.php`).
- The tool and identity tests of the cross-cutting suite run in both protocol eras (a legacy request and a 2026-07-28
  request): `EveryToolTest` ET3-ET9, ET11-ET13 and `AlternatingInstancesTest` AI1. The other tests of the suite send
  legacy requests only.
- Some ids appear in two files (`PM1`, `PM4`, `RC1`, `RC3`, `RC7`, `LS1`, `LR3`, `X1`, `X3`): this page always names
  the file with the id.

## The cross-cutting suite

| File | Ids | What it proves |
|---|---|---|
| `tests/Feature/SecuritySuite/EveryToolTest.php` | ET1-ET14 | For every registered tool (every plugin, a custom tool, a wrapped tool), in the strict environment `prod-eu`, over HTTP in both eras: the tool contract; default deny (not listed, refused `no_gate`, audited, its code never runs); the envelope and exactly one audit entry name `prod-eu`; the server name and the instructions name it too; the read-only transaction opens on the default connection; the rate limit; the output cap at its minimum; the result matches the declared `outputSchema`; read-only annotations; the same pipeline for a Sanctum principal and for an inner `execute_tools` call; every built-in prompt hidden and refused while one of its tools is denied, and audited; the label line. ET1 fails when a tool or a prompt is added without a row in `SecuritySuite::TOOLS` or `PROMPTS`. |
| `tests/Feature/SecuritySuite/AlternatingInstancesTest.php` | AI1, AI2 | `tools/call` on two app instances in turn: every call works and writes its own audit entry; no session header. |
| `tests/Feature/SecuritySuite/RedactionEndToEndTest.php` | RD1-RD6 | Every built-in secret sample of `tests/Support/SecretSamples.php`, in a log file (line format, and as a JSON context key) and in a failed job: `logs_search`, `logs_recent_errors` and `queue_failed_job_show` return only markers, never the secret, anywhere in the response; the audit holds no secret, also when the secret is an argument; `contains` matches the redacted text only; a custom and a wrapped tool, whose data only the pipeline redacts (stage 7), show a JWT as its marker. |
| `tests/Feature/SecuritySuite/NeverSilentlyEmptyTest.php` | NE1, NE2 | Every tool of the package that reads an outside source answers `unavailable` with the exact note, never `empty`, when it cannot look (a channel with no file, the `sync` queue, no failed-job store, a failed-job file Laravel cannot read). NE1 fails when a tool of the package has no case here, in the database files below, or in `SecuritySuite::NO_SOURCE`. |
| `tests/Integration/Pgsql/SecuritySuite/NeverSilentlyEmptyTest.php`, `tests/Integration/Mysql/SecuritySuite/NeverSilentlyEmptyTest.php` | NEP1-NEP4, NEM1-NEM4 | `db_schema` and `db_query` with a missing table, `model_show` on a table the role cannot read, and `model_show` with a relation that the database refuses: `unavailable` with the note, never `empty`, and no attribute of the record. |
| `tests/Feature/SecuritySuite/NoOutboundCallsTest.php` | NO1, NO2 | One call of every tool and every prompt (directly and behind the catalog) with 01-01's runtime guards on: nothing reported, every call a success, and the `laravel/mcp` client (`ClientManager`) never resolved. |
| `tests/Feature/SecuritySuite/OctaneConditionsTest.php` | OC1, OC2 | One app serves ten requests: each has its own principal and audit entry, none carries over, and the default connection is writable after every read tool (OC1, `forgetScopedInstances()` as Octane does). Through a real Octane worker (OC2, `laravel/octane`'s `FakeWorker`): each request has its own principal and audit entry, and the worker's default connection, which every request clone shares, is writable after the run. A principal that outlives a request is OC1's proof: in OC2 every request sets its own principal before the audit reads it. |
| `tests/Integration/Pgsql/SecuritySuite/OctaneConditionsTest.php`, `tests/Integration/Mysql/SecuritySuite/OctaneConditionsTest.php` | OCP1, OCM1 | Ten requests in one long-lived app: a session setting, a user variable, an advisory lock or a named lock of one request is gone in the next. |
| `tests/Integration/Pgsql/SecuritySuite/SqlThroughDbQueryTest.php`, `tests/Integration/Mysql/SecuritySuite/SqlThroughDbQueryTest.php` | SQP1-SQP5, SQM1-SQM7 | Every SQL bypass attempt of 01-14 and 01-15 as a real `db_query` call over HTTP, in `prod-eu`, as the read-only role: refused with its note, and no row changes; the row limit inside the database (poison row); a lock gone in the next call. |

## Required security tests

Spec 11.2, one row per item.

| Spec 11.2 item | Proven by |
|---|---|
| **SQL bypass attempts:** stacked statements, data-modifying CTEs, `EXPLAIN ANALYZE` of a write, a function that writes, `pg_sleep()`, advisory locks | `tests/Integration/Pgsql/ReadOnlySessionTest.php` P2-P7; `tests/Integration/Mysql/ReadOnlySessionTest.php` M3-M10; end to end `SqlThroughDbQueryTest` SQP1, SQP2, SQP5 (PostgreSQL) and SQM1-SQM3, SQM7 (MySQL, MariaDB); `tests/Integration/Pgsql/DbQueryTest.php` DQP8, DQP12 and `tests/Integration/Mysql/DbQueryTest.php` DQM9 (plain writes, `SET`, `LOCK`) |
| … masked columns through `row_to_json`, `to_jsonb`, whole-row references, views and subqueries | `tests/Integration/Pgsql/QueryRunnerTest.php` QP17; `tests/Integration/Mysql/QueryRunnerTest.php` QM12, QM13; end to end `SqlThroughDbQueryTest` SQP3, SQM4, SQM5; `tests/Integration/Pgsql/DbQueryTest.php` DQP6, `tests/Integration/Mysql/DbQueryTest.php` DQM6; `db_schema`: `tests/Integration/Pgsql/DbSchemaTest.php` DSP5, `tests/Integration/Mysql/DbSchemaTest.php` DSM3, DSM5; `model_show`: `tests/Integration/Pgsql/ModelShowTest.php` MSP1, MSP16, `tests/Integration/Mysql/ModelShowTest.php` MSM16 |
| **Row limit:** a query that matches more rows than `max_rows` never loads them all into PHP memory | `tests/Integration/Pgsql/QueryRunnerTest.php` QP15 (the poison row: the proof on PostgreSQL) and QP16 (PHP memory); `tests/Integration/Mysql/QueryRunnerTest.php` QM10 (poison row, MariaDB) and QM11 (memory: the proof on MySQL 8.4, which builds a CTE in full before the LIMIT); end to end `SqlThroughDbQueryTest` SQP4, SQM6; failed jobs `tests/Integration/Pgsql/FailedJobsTest.php` FJP3, FJP13 and `tests/Integration/Mysql/FailedJobsTest.php` FJM3, FJM13 |
| **Session reset:** an advisory lock or a session setting does not survive into the next call on the same connection | `tests/Integration/Pgsql/ReadOnlySessionTest.php` P6, P7; `tests/Integration/Mysql/ReadOnlySessionTest.php` M10; `tests/Integration/Pgsql/ModelShowTest.php` MSP20 and `tests/Integration/Mysql/ModelShowTest.php` MSM20 (nothing is read on a discarded session); end to end `SqlThroughDbQueryTest` SQP5, SQM7; ten requests in one app `OctaneConditionsTest` OCP1, OCM1 |
| **Read-only transaction for app code:** a custom tool and an observer that try to write both fail; a cache write with the `database` driver fails with a clear error | `tests/Feature/Security/ReadOnly/ReadOnlyStageTest.php` RF1-RF8 (RF2 an observer, RF4 the `database` cache driver); `tests/Integration/Pgsql/ReadOnlyTransactionTest.php` RP1-RP4 (RP2 an observer, RP3 the cache) and `tests/Integration/Mysql/ReadOnlyTransactionTest.php`; a custom tool `tests/Feature/Tools/CustomToolTest.php` CT4; a wrapped tool `tests/Feature/Tools/WrappedToolTest.php` WT6; `model_show` `tests/Integration/Pgsql/ModelShowTest.php` MSP11; every tool opens it: `EveryToolTest` ET6; the connection is writable again after every call: `OctaneConditionsTest` OC1 |
| **Host and Origin:** a wrong `Host` and a wrong `Origin` are rejected and audited | `tests/Feature/Security/Http/EnsureAllowedHostAndOriginTest.php` ("refuses a host that is not allowed", "refuses an Origin that is not allowed", "checks the host first", "refuses a forwarded host from a trusted proxy"); `tests/Unit/Security/Http/HostRulesTest.php` |
| **Two app instances:** requests that alternate between them work | `tests/Feature/Server/TwoInstancesTest.php` (`tools/list`); `AlternatingInstancesTest` AI1, AI2 (`tools/call` with its audit entries) |
| **Environments:** an unknown environment name gets the strict rules; the server name, the instructions, every envelope and every audit entry show the environment | `tests/Unit/Security/EnvironmentTest.php`; `tests/Feature/Security/Boot/StrictBootTest.php` ("treats an environment that is not in environments.relaxed as strict"); `tests/Feature/Server/ServerIdentityTest.php`; `tests/Feature/Tools/EnvironmentEnvelopeTest.php`; `tests/Feature/Audit/AuditEnvironmentTest.php`; for every tool in `prod-eu`: `EveryToolTest` ET4, ET5, ET11, ET12 |
| **Redaction:** every built-in secret pattern, and false-positive tests | `tests/Unit/Security/Redaction/RedactorTest.php` (every sample of `tests/Support/SecretSamples.php`; the false positives: long order numbers, card numbers, UUIDs, hashes stay visible); end to end `RedactionEndToEndTest` RD1-RD6 (RD6: stage 7 for a custom and a wrapped tool); across a cut `tests/Feature/Plugins/Logs/LogsSearchTest.php` LS14, LS15, LS23, LS25, LS26 (a secret across the first line break), `tests/Feature/Plugins/Queue/FailedJobShowTest.php` FS11, FS14, `tests/Integration/Pgsql/FailedJobShowTest.php` FSP4, FSP5, `tests/Feature/Plugins/Queue/FailedJobsTest.php` FF12; groups `tests/Feature/Plugins/Logs/LogsRecentErrorsTest.php` RE11, `tests/Unit/Plugins/Logs/ErrorGroupsTest.php` EG6; progress messages `tests/Feature/Tools/ProgressTest.php` SP8, `tests/Unit/Tools/FiberProgressTest.php` FP6 |
| **Job payloads** (spec 5.6): no serialized job data reaches the response or the audit | `tests/Unit/Plugins/Queue/PayloadMaskTest.php` PM1-PM4 (PM4 scans every file of `src/` for `unserialize`); `tests/Feature/Plugins/Queue/FailedJobShowTest.php` FS12; `tests/Integration/Pgsql/FailedJobShowTest.php` FSP2; `tests/Integration/Mysql/FailedJobShowTest.php` FSM2 |
| **Auth:** no key, blank key, wrong key, malformed key entry, timing-safe compare; each rejection audited; STDIO does not start without `stdio_principal`; the package's own `WWW-Authenticate`, also with `Mcp::oauthRoutes()` | `tests/Feature/Security/Auth/AuthenticateApiKeyTest.php` ("rejects with the reason in the audit only", "sends our header, not laravel/mcp's", "never writes the key … to the audit"); `tests/Unit/Security/Auth/ApiKeyRingTest.php` ("compares every entry, stored hash first", "uses hash_equals by default", "reports malformed segments"); `tests/Feature/Security/Boot/AuthRulesTest.php`; `tests/Feature/Security/Auth/StdioTest.php` ("does not start the STDIO server without a valid stdio_principal"); `tests/Feature/Server/RouteRegistrationTest.php` (`Mcp::oauthRoutes()`); `tests/Feature/Audit/AuditLoggerTest.php` |
| **Sanctum:** a token with `*` and no `runtime-mcp` ability, an expired token, a token of a deleted user, and a session cookie without a token are rejected and audited | `tests/Feature/Security/Auth/AuthenticateSanctumTokenTest.php` ("rejects with the reason in the audit only"); `tests/Integration/Pgsql/SanctumTokenTest.php`; `tests/Arch/SanctumIsolationTest.php`; the whole pipeline for a token principal `EveryToolTest` ET11 |
| **Authorization:** default deny, denied tools hidden in `tools/list`, the Gate enforced in the pipeline even when `shouldRegister()` is bypassed | `tests/Feature/Security/Authorization/DefaultDenyTest.php`; `tests/Feature/Security/Authorization/EnforcedGateTest.php` ("denies and audits a direct ToolInvoker call, which never asks shouldRegister()"); `tests/Feature/Security/Authorization/ToolGateTest.php`; `tests/Feature/Security/Authorization/ModelPolicyTest.php`; custom tools `tests/Feature/Tools/CustomToolTest.php` CT1, CT3; `tests/Feature/Plugins/Logs/LogsRecentErrorsTest.php` RE17; for every tool `EveryToolTest` ET3 |
| **Envelope:** an unreadable log channel or a missing table returns `unavailable` with a note, never `empty` | `NeverSilentlyEmptyTest` NE1, NE2, NEP1-NEP4, NEM1-NEM4; per tool: `tests/Integration/Pgsql/DbSchemaTest.php` DSP7, `tests/Integration/Mysql/DbSchemaTest.php` DSM6, `tests/Integration/Pgsql/DbQueryTest.php` DQP9, `tests/Integration/Pgsql/ModelShowTest.php` MSP16, MSP18, `tests/Feature/Plugins/Logs/LogsSearchTest.php` LS6, LS7, LS9, LS10, LS11, LS24, `tests/Feature/Plugins/Logs/LogsRecentErrorsTest.php` RE4, RE7, RE8, RE9, RE19, `tests/Feature/Plugins/Queue/FailedJobsTest.php` FF9, FF17, FF18, `tests/Feature/Plugins/Queue/FailedJobShowTest.php` FS3, FS6, FS7, FS15, FS16, `tests/Integration/Pgsql/FailedJobsTest.php` FJP8, FJP16, `tests/Integration/Mysql/FailedJobsTest.php` FJM8, FJM16, `tests/Integration/Pgsql/FailedJobShowTest.php` FSP8, FSP13, `tests/Integration/Mysql/FailedJobShowTest.php` FSM8, FSM13; `tests/Unit/Tools/ToolResultTest.php` (an `empty` result needs a note) |
| **Doctor:** each of the three privilege checks, including drift after a migration that adds a sensitive column | `tests/Integration/Pgsql/DoctorPrivilegesTest.php`; `tests/Integration/Mysql/DoctorPrivilegesTest.php`; `tests/Feature/Doctor/ReadOnlyRolePrivilegesTest.php`; `tests/Unit/Security/Grants/MySqlGrantParserTest.php` |
| **Boot validation:** every rule in section 5.11, in a strict and in a relaxed environment | `tests/Feature/Security/Boot/StrictBootTest.php`, `RelaxedBootTest.php`, `BootRulesTest.php`, `AuthRulesTest.php`, `PluginBootRuleTest.php`, `StdioOnlyBootTest.php`, `BootHasNoQueriesTest.php` (boot reads config only); custom tools `tests/Feature/Security/Boot/ToolRulesTest.php` TR1-TR15 (TR10 strict, TR11 relaxed, one bad entry per `tools.*` rule); wrapped tools `tests/Feature/Security/Boot/WrappedToolRulesTest.php` WR1-WR20 (WR17 strict, WR18 relaxed); prompts `tests/Feature/Security/Boot/PromptRulesTest.php` PR1-PR11; `tests/Feature/Security/Boot/ToolsReservedTest.php` |
| **No outbound calls:** a test fails if the package code makes an HTTP request; the package never resolves the `laravel/mcp` client | Runtime guards in `tests/TestCase.php` (`OutboundGuard`, `Http::preventStrayRequests()`) for every test; `tests/Feature/OutboundGuardTest.php`; static `tests/Arch/NoOutboundCallsTest.php`, `tests/Arch/NoMcpClientTest.php`; for every tool and prompt `NoOutboundCallsTest` NO1, NO2 (nothing reported, `ClientManager` never resolved); DynamoDB never built `tests/Feature/Plugins/Queue/FailedJobsTest.php` FF9, `tests/Feature/Plugins/Queue/FailedJobShowTest.php` FS6; log channels read from config, never built `tests/Unit/Plugins/Logs/LogChannelResolverTest.php` LR3 |
| **Protocol eras:** the identity, auth and tool tests pass with a legacy and with a 2026-07-28 request | `tests/Support/ProtocolEra.php` (the helper); `tests/Feature/Server/ServerIdentityTest.php`; `tests/Feature/Security/Authorization/DefaultDenyTest.php`; `tests/Feature/Tools/CustomToolTest.php` CT1, CT2, CT3; `tests/Feature/Plugins/Logs/LogsSearchTest.php` LS1; `tests/Feature/Plugins/Logs/LogsRecentErrorsTest.php` RE1; `tests/Feature/Catalog/CatalogExecuteTest.php` CE1; the cross-cutting suite: `EveryToolTest` ET3-ET9, ET11-ET13, `AlternatingInstancesTest` AI1 |
| **Cache hints:** `tools/list` and `prompts/list` say `cacheScope: private` and `ttlMs: 0` | `tests/Feature/Server/CacheHintsTest.php` |
| **Wire size:** the decoded result of a capped call is at most `2 × max_bytes + 1,024` bytes in both eras; the bytes on the wire are pinned | `tests/Feature/Tools/WireSizeTest.php` OW1, OW2; one copy within `max_bytes` for every tool: `EveryToolTest` ET8; `tests/Feature/Catalog/CatalogCapTest.php` |
| **Prompts:** hidden and refused when one of its tools is denied; every `prompts/get` audited; prompt classes use no database, logs, queues, cache or storage | `tests/Feature/Prompts/PromptListTest.php` PL2, PL3; `tests/Feature/Prompts/PromptGetTest.php` PG1, PG2, PG4, PG7-PG10, PG18, PG20; `tests/Arch/PromptsHoldNoAppDataTest.php` PN1-PN5; for every built-in prompt `EveryToolTest` ET13 |
| **Progress:** without `progressToken` no SSE; with it, the notifications come before the result, the principal is set inside the stream, and a message with a secret is redacted | `tests/Feature/Tools/ProgressTest.php` SP1, SP2, SP3, SP8, SP10, SP11; `tests/Feature/Plugins/Logs/LogsProgressTest.php` LG6; `tests/Unit/Tools/FiberProgressTest.php` FP6 |
| **Wrapped tools:** a wrapped tool that writes fails; a hidden wrapped tool is denied and audited; image content is left out with a `partial` note | `tests/Feature/Tools/WrappedToolTest.php` WT3, WT6, WT8, WT9; `tests/Unit/Mcp/ResponseConverterTest.php` RC3; the whole pipeline: `EveryToolTest` (`app_order_lookup`) |
| **Catalog:** inner calls run the pipeline and are audited; a call to a hidden tool through `execute_tools` is audited as denied; the outer calls are audited | `tests/Feature/Catalog/CatalogExecuteTest.php` CE1-CE5, CE7, CE12; `tests/Feature/Catalog/CatalogListTest.php` CL4; `tests/Feature/Tools/ExecuteToolsTest.php` (X1-X3: its three tests); for every tool `EveryToolTest` ET12; `NoOutboundCallsTest` NO2 |
| **Test kit:** it throws outside the test environment | `tests/Feature/Testing/KitGuardTest.php` TKG1-TKG3 |
| **Output schema:** every tool declares the envelope as its `outputSchema`, and every result matches it | `tests/Unit/Mcp/EnvelopeSchemaTest.php` ("is exactly the spec 4.4 schema", "accepts every result a tool or the pipeline can build"); per tool: the `JsonSchemaSubset` checks in each tool's feature test; for every tool, also capped: `EveryToolTest` ET2, ET9 |

## Rules

Spec 12. A rule that a test can prove has its tests here; the others are checked in review.

| Rule | Proven by |
|---|---|
| 1. No code execution tool | Review only: there is no such tool to test. `EveryToolTest` ET1 lists every registered tool. |
| 2. No SQL parsing | `tests/Arch/NoSqlParsingTest.php` |
| 3. No security check only in HTTP middleware | `tests/Feature/Security/Authorization/EnforcedGateTest.php` (a direct `ToolInvoker` call); `tests/Feature/Tools/DirectInvokerTest.php`; `tests/Feature/Tools/ExecuteToolsTest.php`; `tests/Feature/Catalog/CatalogExecuteTest.php`; `EveryToolTest` ET12 |
| 4. No outbound calls and no telemetry | `tests/Arch/NoOutboundCallsTest.php`; the runtime guards of `tests/TestCase.php`; `NoOutboundCallsTest` NO1, NO2 |
| 5. One endpoint per server, no hub | Review only (also rule 4: no outbound call at all). |
| 6. No keys in the committed config file | Review only (`config/runtime-mcp.php` reads `env()`). |
| 7. No automatic grants for future tables | `tests/Unit/Security/Grants/RoleSqlGeneratorTest.php` |
| 8. No full fetch before the row limit | `tests/Integration/Pgsql/QueryRunnerTest.php` QP15; `tests/Integration/Mysql/QueryRunnerTest.php` QM10, QM11; `SqlThroughDbQueryTest` SQP4, SQM6; `tests/Integration/Pgsql/FailedJobsTest.php` FJP3 |
| 9. No destructive tools | `Tier` has no write case (`src/Tools/Tier.php`); every tool is listed read-only: `EveryToolTest` ET10 |
| 10. No write without an out-of-band approval | As rule 9: no write tool exists before v1.13.0; `EveryToolTest` ET10 |
| 11. No silent empty results | `NeverSilentlyEmptyTest` NE1, NE2, NEP1-NEP4, NEM1-NEM4; `tests/Unit/Tools/ToolResultTest.php` |
| 12. No relaxing of the core controls | `tests/Unit/Security/Redaction/RedactorTest.php` ("cannot be turned off"); `tests/Unit/Audit/AuditChannelConfigTest.php`; `tests/Feature/Security/Http/EnsureAllowedHostAndOriginTest.php`; `tests/Feature/Security/Boot/RelaxedBootTest.php` (relaxed changes only the boot behaviour); `EveryToolTest` ET3 (default deny in every environment) |
| 13. One column mechanism | `tests/Unit/Security/Redaction/RedactorTest.php` (the false positives: no card number detection) |
| 14. No static request state | `tests/Arch/NoStaticStateTest.php`; `tests/Feature/Security/Auth/ScopedPrincipalTest.php`; `OctaneConditionsTest` OC1, OC2, OCP1, OCM1 |
| 15. No throw at boot in a strict environment | `tests/Feature/Security/Boot/StrictBootTest.php`; `tests/Feature/Security/Boot/BootThrottleTest.php` |
| 16. No result data in the audit | `tests/Unit/Audit/AuditEntryTest.php`; `tests/Feature/Tools/PipelineAuditTest.php`; `tests/Integration/Pgsql/ModelShowTest.php` MSP17; `RedactionEndToEndTest` RD4 |
| 17. No false claims in the docs | Review only (01-31, 01-32). |
| 18. No app data in a prompt | `tests/Arch/PromptsHoldNoAppDataTest.php` PN1-PN5 |
| 19. Only the named MCP methods | `tests/Feature/Server/ServerCapabilitiesTest.php` ("does not know the methods of rule 19"); `tests/Feature/Security/Boot/WrappedToolRulesTest.php` (a tool with `#[RendersApp]` is refused) |
| 20. No use of the `laravel/mcp` client | `tests/Arch/NoMcpClientTest.php`; `NoOutboundCallsTest` NO1, NO2 |
| 21. No wildcard token and no session auth | `tests/Feature/Security/Auth/AuthenticateSanctumTokenTest.php`; `tests/Feature/Console/TokenCommandTest.php` |

## Definition of done

Spec 14, the items that say "a test proves it".

| Spec 14 item | Proven by |
|---|---|
| The guard pipeline runs on every tool call | `EveryToolTest` ET3-ET12 (every stage for every registered tool), ET1 (no tool is missing) |
| Every tool declares the envelope as its `outputSchema`, and every result matches it | `EveryToolTest` ET2, ET9; `tests/Unit/Mcp/EnvelopeSchemaTest.php` |
| The guard pipeline runs on every wrapped tool and on every inner `execute_tools` call | `EveryToolTest` (the wrapped `app_order_lookup` in every row; ET12 for every tool as an inner call); `tests/Feature/Tools/WrappedToolTest.php`; `tests/Feature/Catalog/CatalogExecuteTest.php` |
| No tool returns an empty result when it could not look | `NeverSilentlyEmptyTest` NE1, NE2, NEP1-NEP4, NEM1-NEM4 |
| No outbound network calls from the package code | `NoOutboundCallsTest` NO1, NO2; `tests/Arch/NoOutboundCallsTest.php` |

## What is not proven by CI

Stated as spec principle 1.4.8 asks ("promise only what you can prove"):

- **Octane:** `OctaneConditionsTest` OC2 runs a real Octane worker in the test process (`laravel/octane`'s
  `FakeWorker`, no Swoole, RoadRunner or FrankenPHP server). The database rounds (OCP1, OCM1) simulate Octane with
  `forgetScopedInstances()` in one app.
- **MySQL 8.4 row limit:** the poison row does not work there (MySQL builds a derived table with a CTE in full before the
  LIMIT, and refuses the query): `QueryRunnerTest` QM11 (memory) is the proof on MySQL.
- **What the read-only transaction does not stop** (spec 5.9): a queue push to Redis, a cache write to Redis, a file
  write, an outbound HTTP or SOAP call from app code. No test claims otherwise.

## Found by the suite (01-30)

- A secret in a JSON context **key** that holds JSON text with escaped slashes (`{"smtp":"smtp:\/\/app:pass@host"}`)
  was shown by `logs_search`: the context's JSON escapes the backslashes again (`smtp:\\/\\/`), and the password pattern
  allowed only one backslash before each `/`. The pattern now allows any number (`src/Security/Redaction/Patterns.php`).
  Proven by `RedactionEndToEndTest` RD1 and the sample "password: JSON text encoded twice (01-30)" of
  `tests/Support/SecretSamples.php` (run by `RedactorTest`).
- Found by the code review of 01-30 and its triage. `RedactionEndToEndTest` now also searches for the end of each
  secret: a pattern that stops early leaves the end of the secret in the text.
  - (The review.) A bearer token with a `/` inside JSON text with escaped slashes (`"Bearer ab\/Q9xT…"`) was shown
    whole or in part: the token class had no `\`. The `bearer` pattern now allows backslashes before a `/`
    (`src/Security/Redaction/Patterns.php`). Proven by the sample "bearer: JSON text with escaped slashes (01-30
    triage)" (`RedactorTest`, RD1-RD4).
  - (The triage, by the end-of-secret search.) A PEM block in a line-format log message was shown without its first
    line: the parser splits an entry at its first line break (the message, then the lines under it), and the two parts
    were redacted apart, so the key body and the END line matched no pattern. `RedactedEntry` now redacts the entry's
    whole text (`src/Plugins/Logs/RedactedEntry.php`). Proven by `tests/Feature/Plugins/Logs/LogsSearchTest.php` LS26
    and RD1, RD2.
