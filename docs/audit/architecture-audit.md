# Architecture audit: uBixCore standards vs Kelpie

Date: 2026-10-15. Scope: `php/Kelpie/` and `tests/` on `dev` at `1dfb2f8` (includes !25), and the
uBixCore framework at `origin/main` (`6874f5a`, tag v0.46.1 is what Kelpie pins). Read-only: no
code was changed. Written for the question "work is being done in the wrong places; why are
uBixCore standards and architecture not followed, and why did review not catch it?"

## Summary

1. The rules exist and are clear (uBixCore `docs/architecture/complete-php-guide.md`, repeated in
   Kelpie's `CLAUDE.md` Layout). Business logic goes in `Service/<Domain>/`, data access in
   `Repository/<Entity>/`, and so on, one folder per kind of class.
2. **Nothing machine-checks class placement.** uBixCore's test base decides which rules to apply
   *from the folder a class is in*. A class in a folder it does not know (`Autofix/`, `Triage/`,
   `Sandbox/`) is not rejected; it is simply checked against fewer rules. A service in the wrong
   folder passes every tool.
3. **The AI review was told not to look.** `ci/ai-review/prompt.md` says naming and layering are
   checked by a machine gate and must not be reported. That claim is false for placement.
4. **Result on Kelpie dev:** 19 classes in 5 folders outside the family layout (Autofix, Triage,
   GitLab, OpenRouter, Sandbox), 3 console commands that carry business logic and build their own
   object graph, 1 service that takes a concrete SQL repository, and one `GitLab`/`Gitlab` spelling
   split. Everything that sits behind the repository pattern (the autofix job store) is correct.
5. Four fixes, each one MR: (a) an architecture check in uBixCore, (b) fix the review prompt and
   get the standards into its context, (c) rehome Kelpie (two MRs: moves, then thin commands),
   (d) change how briefs and lane scopes name folders.

What I could not verify from the repository: the Shepherd lane scopes and agent briefs for the
autofix lanes. Section 5 says what the repo does show and what has to be asked of Shepherd.

## 1. The standards

### 1.1 uBixCore

Sources: `docs/architecture/complete-php-guide.md` (sections "Major Object Types", "File
Organization", "Class Naming", "Business Logic Locations", "External APIs"),
`docs/standards/unit-testing.md`, `docs/standards/code-review.md`, `CLAUDE.md`, and the rules
actually executed in `php/Ubix/Tests/AbstractUbixConcreteClassOrEnumTestCase.php`.

| Kind of class | Folder (under the product root) | Rules stated or executed |
|---|---|---|
| Controller | `Controller/<Api>/` | Extends `AbstractController`, name ends `Controller`, thin; depends on services, not repositories |
| Service (business logic, orchestration) | `Service/<Domain>/` | Name ends `Service`, interface ends `ServiceInterface`, `final`, stateless, **first constructor parameter is `Logger $logger`** |
| External API / provider integration | `Service/<Domain>/` (guide: "External Services", example `XvtService` taking an HTTP client) | Same as a service; uBixCore itself does this: `Service/Payment/StripePaymentProviderService`, `Service/Blob/S3BlobService`, `Service/Identity/*ProviderService` |
| SQL access | `Repository/<Entity>/` | `XReaderInterface` + `XWriterInterface` + `XSqlRepository`; reads through one private `query(XOptions)`; only a service may depend on a repository; only a SqlRepository may depend on a SqlService |
| Query criteria | `DataTransferObject/SqlRepository/<Entity>Options` | Readonly, `DtoInterface` |
| Other DTO (data carrier between layers) | `DataTransferObject/` | Readonly, no behaviour |
| Model (persistent entity) | `Model/` | Extends `AbstractModel`; the word `Model` is reserved in short names |
| DataType (value object) | `DataType/{Int,String,...}/` | Wraps a domain scalar |
| Enum | `Enum/<Domain>/` | Native enum, no `Enum` suffix |
| Payload | `Payload/{Request,Response}/` | Validates HTTP in and out |
| PSR-18 HTTP client / decorator | `HttpClient/` | Name ends `HttpClient`, implements `Psr\Http\Client\ClientInterface` |
| Middleware | `Middleware/` | Ends `Middleware` |
| Console command | `Console/Command/` | Ends `Command`, thin |
| Exception | `Exception/` | |

The guide also says what a service must not be (HTTP request/response handling, SQL, templates,
state) and that "Primary: Services" is where business logic lives, models second.

### 1.2 kitg (the reference app)

`php/Kitg/` is flat under one product root and has exactly the family folders above and nothing
else: `Console, Controller, DataTransferObject, DataType, Enum, Exception, Middleware, Model,
Payload, Repository, Service`. There is no domain folder at the top level. Domains appear *inside*
a family (`Enum/Tier/`, `Repository/Creator/`, `DataTransferObject/Post/`). Its `CLAUDE.md` states
the same rules as Kelpie's and adds `Enum/<Domain>/<Name>.php` and `Payload/{Request,Response}`.

### 1.3 Kelpie's own statement

`CLAUDE.md` "Layout": `php/Kelpie/` holds `Controller, Model, Repository, Service, DataType, Enum,
Console/Command, Payload, DataTransferObject, Middleware (create as needed)`. "Conventions":
repository pattern with split interfaces, service layer holds business logic, controllers thin.
It says nothing about `Autofix/`, `Triage/`, `GitLab/`, `OpenRouter/` or `Sandbox/`: those
folders are not sanctioned anywhere.

## 2. What the machine actually checks

| Tool | Checks placement? | What it does |
|---|---|---|
| phpcs (`Ubix` standard) | No | Formatting, naming of variables, docblocks, `ConcreteClassTestCaseSniff` (a `@see` test case exists) |
| phpstan (level max, bleeding edge) | No | Types. No architecture rules, no `phpat`-style rules in `phpstan.neon` or the framework baseline |
| rector | No | Level 0 everywhere |
| `PhpunitTestCasesTest` | No | Walks `php/Kelpie`; a class at `X/Y.php` needs a test at `tests/X/YTest.php`. It follows the class wherever it is, so it cannot object to the location |
| `testFollowingUbixStandards()` (per class) | **Only for classes inside a known family folder** | See below |

How `testClassFollowingUbixStandards` works (`AbstractUbixConcreteClassOrEnumTestCase`, lines
~60-170): it finds the first path segment after the vendor/product root that is in a fixed
`FAMILIES` list and runs that family's rules (`Service` -> suffix, interface suffix, logger;
`Repository` -> reader/writer/`query`; `HttpClient` -> suffix + PSR-18; and so on). Consequences
verified in the code:

- **A folder that is not a family is silently accepted.** `Kelpie\Autofix\ClaimService` gets only
  the universal rules (namespace, logger first, properties, constants). Its name ends in `Service`
  but the `Service` rules are never applied to it. The same holds for `Kelpie\Triage\*`,
  `Kelpie\Sandbox\*`, `Kelpie\GitLab\*`, `Kelpie\OpenRouter\*`.
- **The rules point one way.** "Repositories may only be dependencies of classes in the `Service`
  family" forces a class that takes a repository *into* `Service/`. Nothing forces a class that
  does services' work but takes no repository (an HTTP client, a process runner, a filter) *into*
  `Service/`. That is exactly the shape of what ended up outside.
- **A family name nested deeper still counts.** `Kelpie\Autofix\DataTransferObject\AutofixJob`
  resolves to the `DataTransferObject` family because the first family segment wins, so the nested
  DTO passes while sitting beside, not under, the real `DataTransferObject/`.
- **The evidence the universal rules do run:** `TriagePrompt` carries
  `@phpstan-ignore ... (Every uBix class takes a logger first; the prompt has nothing to log)`.
  The author met the logger rule and complied; no equivalent rule met the folder.

uBixCore's own `docs/standards/code-review.md` says the gate catches "everything
machine-checkable" and that a finding class the review keeps catching "means the gate is
under-built". Placement is in neither the gate nor, in Kelpie, the review (section 4.2).

## 3. Kelpie on dev: what breaks the rules

Target names follow the family rules in 1.1: a class in `Service/` ends `Service`, its interface
`ServiceInterface`, and it takes `Logger` first. Names in the last column are what the mechanical
rule would require; a different name that keeps the rule is fine.

### 3.1 Classes in folders that are not families

| Where it is | Where it belongs | Why |
|---|---|---|
| `Autofix/ClaimService` | `Service/Autofix/ClaimService` | A service by name and role; sits beside `Service/Autofix/*` |
| `Autofix/ReplyService` | `Service/Autofix/ReplyService` | Same |
| `Autofix/EnqueueService` | `Service/Autofix/EnqueueService` | Same; called from `GitlabWebhookController`, so the controller already depends on a service that is outside `Service/` |
| `Autofix/ThreadResponder` | `Service/Autofix/ThreadResponderService` | Orchestrates claim, triage, reply for each thread: a service, wrong name |
| `Autofix/EventFilter` | `Service/Autofix/EventFilterService` | Business rule (loop guards, allow-list), wired in `app/KelpieApi/src/Dependencies.php` |
| `Autofix/GateRunner` | `Service/Autofix/GateRunnerService` | Runs a process and interprets the result: a service |
| `Autofix/ClaudeCliFixerAgent` | `Service/Autofix/ClaudeCliFixerAgentService` | External-tool integration; the guide puts these in `Service/` |
| `Autofix/FixerAgentInterface` | `Service/Autofix/FixerAgentServiceInterface` | Service interfaces must end `ServiceInterface` |
| `Autofix/DataTransferObject/AutofixJob` | `DataTransferObject/AutofixJob` | A DTO nested under a domain folder; the DTO family is flat (kitg: `DataTransferObject/Post/` is a subfolder *of* the family, not the reverse) |
| `Triage/TriageService` | `Service/Triage/TriageService` | A service |
| `Triage/SafetyPolicy` | `Service/Triage/SafetyPolicyService` | Business rule enforced in code |
| `Triage/TriagePrompt` | `Service/Triage/TriagePromptService` | Builds and parses; logic, not data. (It takes a logger it never uses: a sign it is not a good DTO either) |
| `Triage/AuthenticatedHttpClient` | `HttpClient/AuthenticatedHttpClient` | A PSR-18 decorator; `HttpClient/` exists and holds its siblings `BotHttpClient`, `ApprovalRetryHttpClient`. Overlap with `BotHttpClient` should be looked at in the same MR (not verified) |
| `GitLab/DiscussionReader` | `Service/Gitlab/DiscussionReaderService` | An API integration. `Reader`/`Writer` names also read as repository interfaces, which they are not |
| `GitLab/DiscussionWriter` | `Service/Gitlab/DiscussionWriterService` | Same; also unhelpful that it reads (`headSha`, `mergeRequest`) as well as writes |
| `OpenRouter/OpenRouterClient` | `Service/OpenRouter/OpenRouterService` | Provider integration, the guide's `XvtService` shape. Its docblock notes uBixCore has no LLM client: generic plumbing, so an upstream candidate under the boundary rule in `CLAUDE.md` |
| `Sandbox/Checkout` | `Service/Sandbox/CheckoutService` | Runs git through a process runner |
| `Sandbox/ProcOpenProcessRunner` | `Service/Sandbox/ProcOpenProcessRunnerService` | Process execution; uBixCore already has `Service/ProcessServiceInterface` + `NativeProcessService` (shell string, no env or timeout) so the right home may be upstream |
| `Sandbox/ProcessRunnerInterface` | `Service/Sandbox/ProcessRunnerServiceInterface` | Interface suffix |

Also in the wrong place: three folders spelled two ways. `GitLab/` (and `tests/GitLab/`) against
`Service/Gitlab/` and `Controller/Gitlab/`. On a case-insensitive filesystem (macOS) a move
between the spellings is a hazard; the rehoming MR should settle on `Gitlab`.

### 3.2 Logic or wiring in the wrong layer

| Where | Problem |
|---|---|
| `Console/Command/FixCommand` (184 lines) | `execute()` reads nine env vars, applies the project allow-list, looks up the head sha, selects the thread, calls the job service, then builds ten collaborators with `new` (`BotHttpClient`, `DiscussionWriter`, `ClaimService`, `GateRunner`, `Checkout`, ...). That is orchestration and policy, and it bypasses the container. Belongs: a service method (`FixerRunnerService` or a new `Service/Autofix/` entry point), config in a config DTO, wiring in the CLI `Dependencies` |
| `Console/Command/RespondCommand` (130) | Same pattern: allow-list check, `new` for writer, reader, claim, reply, responder |
| `Console/Command/TriageCommand` (110) | Same pattern, smaller; wraps the client in `AuthenticatedHttpClient` itself |
| `Service/Autofix/AutofixJobService` | Constructor takes the concrete `AutofixJobSqlRepository` and builds `AutofixJobCoalescerService` with `new`. The docblock gives the reason: "so that the CLI container can build it without extra bindings". That is a workaround for missing CLI wiring, and it defeats the reader/writer interfaces. Passes the machine because the class is in `Service/` |

Checked and fine: the controllers are thin and call services only (`GitlabWebhookController` is
190 lines but is routing, secret check and dispatch); no controller or command touches a
repository; `Repository/AutofixJob/*`, `Model/AutofixJobRecord` and
`DataTransferObject/SqlRepository/AutofixJobOptions` follow the pattern exactly (reader, writer,
SqlRepository, private `query`). `Service/Autofix/{AutofixJobCoalescerService, FixerRunnerService}`
and `Service/Gitlab/*` are correctly placed. The top-level `DataTransferObject/`, `Enum/`
folders are in family. Minor: `Enum/` is flat where kitg uses `Enum/<Domain>/`.

### 3.3 Tests

Tests mirror the code, so every row in 3.1 has a test in the wrong place too, and moves with it:
`tests/Autofix/*` (8 test classes plus helpers `InMemoryJobStore`, `ScriptedFixerAgent`),
`tests/Autofix/DataTransferObject/AutofixJobTest`, `tests/Triage/*` (4), `tests/GitLab/*`
(`DiscussionReaderTest`, `DiscussionWriterTest`, `FakeGitlab`, `fixtures/*.json`),
`tests/OpenRouter/*` (+ `fixtures/`), `tests/Sandbox/*` (2 + `ScriptedProcessRunner`). Counted:
19 test classes, 4 test helpers, 2 fixture folders. No test-side rule is broken beyond following
its class.

## 4. Why it happened

Ordered by how much the evidence supports each cause.

### 4.1 No mechanical check exists (confirmed)

Section 2. The one rule that does push a class into `Service/` (repositories) was met by the
classes that take repositories and by no others. All 19 misplaced classes pass phpcs, phpstan,
rector and `PhpunitTestCasesTest`; CI was green on every autofix MR. The first seven autofix MRs
(!19 to !25) were merged within one day.

### 4.2 The AI review was instructed not to report it (confirmed)

`ci/ai-review/prompt.md`, "What you are, and are not":

> A separate machine gate already checks the repository's mechanical standards: formatting, lint,
> static types, the test suite, naming and layering conventions.

and "Do NOT report ... anything the repository's own convention rules would flag". `docs/ai-review.md`
repeats it ("lint, static types, tests, naming, layering, conventions"). The text came from
uBixCore's two-layer model, where it is aspirational. The same prompt does list "wrong layer" as a
design smell to report, so the instruction contradicts itself. A reviewer that believes a gate
covers layering will skip the one place that nothing else looks. Merge requests !19 to !25 carry no
review thread about placement; none carries any non-system note at all.

Second cause in the same place: **the standards do not reach the reviewer.** `review.sh` builds
context from the *reviewed repo's* `CLAUDE.md`, `AGENTS.md`, `CONTRIBUTING.md`, `.ai-review.md`
and `docs/standards/*.md`, at most 20 KB per file and 60 KB total, and it skips `vendor/`.
Kelpie has no `docs/standards/` and no `.ai-review.md`. uBixCore's guide (2,783 lines) is not in
context, and would be cut at 20 KB before its "File Organization" section (line ~1951). What the
reviewer gets is Kelpie's `CLAUDE.md` Layout, one list of folder names. The default model is
`openrouter/anthropic/claude-haiku-5.5`.

### 4.3 The documentation does not forbid the pattern (confirmed)

The guide says where each kind of class lives but never says "a product has no other top-level
folders". Its "File Organization" tree omits `External`, `HttpClient`, `Renderer`,
`SessionHandler`, `SlimApp`, `SlimHandler`, `Bootstrap` and `Filters`, which the test base treats
as families, so the tree and the executable list disagree. It also names a stale path
(`tests/AbstractUbixConcreteClassOrEnumTestCase.php`; the file is
`php/Ubix/Tests/AbstractUbixConcreteClassOrEnumTestCase.php`). Kelpie's `CLAUDE.md` lists the
families with "create as needed" and no prohibition. The design doc
(`docs/design/mr-thread-autofix.md`) describes nine steps by behaviour ("GitLab client call",
"OpenRouter client call", "Sandbox image") and names no folder or class for any of them, so the
plan an agent worked from invited a domain name as a folder name. The step 1 MR text calls
`DiscussionReader` a "service" and puts it in `GitLab\`.

### 4.4 The test base "forced" files into `Service\Autofix` (confirmed, and partial)

True in one direction: `FixerRunnerService`, `AutofixJobService` and `AutofixJobCoalescerService`
depend on repository interfaces, and the rule "repositories only in services" requires them to
be in the `Service` family. The commit for !24 shows the split: classes that take a repository went
to `Service\Autofix\`, their neighbours that do not went to `Autofix\`, `Sandbox\`, `Triage\`.
So the rule produced a split directory, because it constrains only one side. It also pushed the
author to inject the concrete `AutofixJobSqlRepository` rather than make the CLI wiring (see 3.2).

### 4.5 Lane scopes and agent briefs (not verifiable from the repository)

The history shows the shape of the outcome, not the cause:

- Step 3 (`a73443c`, !22) put `Repository/AutofixJob/*` and `Model/AutofixJobRecord` in the right
  place. If files were moved into `Repository/` to fit a lane scope, the scope helped there.
- Steps 1, 2, 4, 5 and 6 (`46c757f`, `ed0863b`, `cc003da`, `1152cd2`, `f70cc80`) each added a
  domain folder (`GitLab/`, `Autofix/`, `Triage/` + `OpenRouter/`, `Sandbox/`). A lane scope of
  the form `php/Kelpie/<Feature>/**` would produce this; so would an agent that was not told the
  family rule. The repository cannot tell the two apart: lane definitions and briefs are in
  Shepherd, not here, and the commit authors are all the person's identity.

This needs a question to Shepherd (section 5d), not a guess. Do not treat the "scope" cause as
established.

### 4.6 Contributing factors

- Mirror-the-class test rule: moving a class costs a test move too, so the first placement
  sticks. The cost of fixing it later is the reason it grows (53 files import the moved
  namespaces today).
- Seven feature MRs ran back to back on one day, each adding to the previous folder instead of
  rehoming it.
- Kelpie's boundary rule ("uBixCore gets primitives ... never product logic") pushes generic
  plumbing (OpenRouter client, process runner) away from the family folders under a "this is
  Kelpie's own" label, without saying they still follow the family layout.

## 5. Recommendations

Each is one MR's worth. Order: (a) and (b) first, because they stop new drift while (c) cleans
up.

### (a) Mechanical architecture check, in uBixCore

- **Tool:** a PHPUnit architecture test, in uBixCore, as a second assertion on the base class that
  hosts already extend: `Ubix\Tests\AbstractPhpunitTestCasesTestCase`
  (`php/Ubix/Tests/`). Hosts get it by upgrading and calling one new method from the test they
  already have (`assertEveryClassIsInItsFamily()`); no new tool, no new dependency.
- **Why not phpstan or phpcs:** a phpstan architecture rule (phpat or a custom rule) needs a new
  dependency and a baseline per host; phpcs sniffs see tokens, not "implements ClientInterface".
  The reflection code, the `FAMILIES` list and the exemption hooks are already in the base class.
- **Rules (all derived from sections 1.1 and 2):**
  1. The first segment under the product root must be in `FAMILIES` (hosts may add to the list
     through an overridable method, as they do for exemptions today).
  2. A family name must not appear again below another family (kills `Autofix\DataTransferObject`).
  3. Kind by shape must match folder: short name ends `Service` or `ServiceInterface` -> `Service`
     family; implements `Psr\Http\Client\ClientInterface` -> `HttpClient`; extends `AbstractModel`
     -> `Model`; implements `DtoInterface` -> `DataTransferObject`; enum -> `Enum`; Symfony
     `Command` -> `Console\Command`; extends `AbstractController` -> `Controller`; ends
     `Reader/Writer Interface` or `SqlRepository` -> `Repository`.
  4. A class in `Console\Command` or `Controller` must not instantiate other host classes
     with `new` in `execute()` / actions (cheap form: a class there may not have constructor
     dependencies on `HttpClient` or `Repository` types, and phpstan-free; deeper form can wait).
- **Rollout:** ship in uBixCore as a minor; Kelpie and kitg bump the pin. Kelpie starts with an
  exempt list equal to section 3.1, which (c) empties. kitg should be run first: it is the
  reference and should be clean.
- **Process:** uBixCore's `code-review.md` requires Christopher Olsen's sign-off for gate
  changes. This is one.
- Also in that MR: fix the guide's file tree and the stale test-base path (4.3), and add the one
  sentence it lacks: "a product root contains the family folders and nothing else".

### (b) Fix the AI review prompt, and get the standards to it

1. In `ci/ai-review/prompt.md` and `docs/ai-review.md`, remove "naming and layering conventions"
   from the machine-gate list until (a) ships, and add to the review list: "class placement and
   layering: a class outside the family folder for what it is, business logic in a controller or
   command, a command or service building its collaborators with `new`, a concrete repository
   injected where the interface exists". Give it the 1.1 table inline (about 1.5 KB) so it does
   not depend on a file being found. Re-add the exclusion when (a) lands, scoped to what (a)
   actually checks.
2. Context: add `docs/standards/architecture.md` to Kelpie (distilled layout and naming, under
   8 KB) so the existing glob picks it up, or extend `review.sh` to also fetch the same file from
   uBixCore. Raising the per-file cap from 20 KB does not help; the guide is too long to be useful
   as context. A short file in uBixCore, referenced by every host, is better than a copy per host.
3. Test: add a dry-run case under `ci/ai-review/test` with a diff that adds
   `php/Kelpie/Foo/BarService.php`, expecting a finding.

### (c) Rehome Kelpie

Two MRs, after (a) lands (or before it, with the same list as the exempt list): MR 1 is moves,
MR 2 is thin commands. Neither is blocked by an in-flight lane: step 7 (`feat/autofix-resolution`)
merged as !25. Step 8 has not started; tell that lane first, because every file below is one it
will touch.

**MR c1: moves and renames** (no behaviour change; git `mv` plus namespace, `use`, `@see`,
`@coversDefaultClass` lines)

| From | To |
|---|---|
| `php/Kelpie/Autofix/ClaimService.php` | `php/Kelpie/Service/Autofix/ClaimService.php` |
| `.../Autofix/ReplyService.php` | `.../Service/Autofix/ReplyService.php` |
| `.../Autofix/EnqueueService.php` | `.../Service/Autofix/EnqueueService.php` |
| `.../Autofix/ThreadResponder.php` | `.../Service/Autofix/ThreadResponderService.php` |
| `.../Autofix/EventFilter.php` | `.../Service/Autofix/EventFilterService.php` |
| `.../Autofix/GateRunner.php` | `.../Service/Autofix/GateRunnerService.php` |
| `.../Autofix/ClaudeCliFixerAgent.php` | `.../Service/Autofix/ClaudeCliFixerAgentService.php` |
| `.../Autofix/FixerAgentInterface.php` | `.../Service/Autofix/FixerAgentServiceInterface.php` |
| `.../Autofix/DataTransferObject/AutofixJob.php` | `.../DataTransferObject/AutofixJob.php` |
| `.../Triage/TriageService.php` | `.../Service/Triage/TriageService.php` |
| `.../Triage/SafetyPolicy.php` | `.../Service/Triage/SafetyPolicyService.php` |
| `.../Triage/TriagePrompt.php` | `.../Service/Triage/TriagePromptService.php` |
| `.../Triage/AuthenticatedHttpClient.php` | `.../HttpClient/AuthenticatedHttpClient.php` |
| `.../GitLab/DiscussionReader.php` | `.../Service/Gitlab/DiscussionReaderService.php` |
| `.../GitLab/DiscussionWriter.php` | `.../Service/Gitlab/DiscussionWriterService.php` |
| `.../OpenRouter/OpenRouterClient.php` | `.../Service/OpenRouter/OpenRouterService.php` |
| `.../Sandbox/Checkout.php` | `.../Service/Sandbox/CheckoutService.php` |
| `.../Sandbox/ProcOpenProcessRunner.php` | `.../Service/Sandbox/ProcOpenProcessRunnerService.php` |
| `.../Sandbox/ProcessRunnerInterface.php` | `.../Service/Sandbox/ProcessRunnerServiceInterface.php` |

Tests move to the mirrored path with `...Test` names: `tests/Autofix/*Test.php` ->
`tests/Service/Autofix/`, `tests/Autofix/DataTransferObject/AutofixJobTest.php` ->
`tests/DataTransferObject/`, `tests/Triage/*` -> `tests/Service/Triage/` (the client test to
`tests/HttpClient/`), `tests/GitLab/*` -> `tests/Service/Gitlab/` (with `FakeGitlab.php` and
`fixtures/`), `tests/OpenRouter/*` -> `tests/Service/OpenRouter/`, `tests/Sandbox/*` ->
`tests/Service/Sandbox/`. Helpers `InMemoryJobStore`, `ScriptedFixerAgent`, `ScriptedProcessRunner`
move to the directory of the class they fake.

DI and wiring touched:
- `app/KelpieApi/src/Dependencies.php`: `use Kelpie\Autofix\EventFilter` and its binding
  (`EventFilterService`). This is the only reference under `app/`, `bin/`, `public/`, `config/`.
- `use` and `@see` / `@coversDefaultClass` lines in about 53 files under `php/` and `tests/`.
- `GitlabWebhookController` (`EnqueueService`), `Console/Command/{Fix,Respond,Triage}Command`
  (all the moved collaborators), `Service/Autofix/FixerRunnerService` (its `FixerAgent` alias),
  `AutofixJobService`/`AutofixJobCoalescerService` (`AutofixJob` import).
- `docs/design/mr-thread-autofix.md` and `CHANGELOG`/`docs/DECISIONS.md` references to old
  names (grep before editing).
- Decision left to the MR: `OpenRouterService` and the process runner are upstream candidates
  (uBixCore has no LLM client and its `ProcessService` has no env/timeout). Rehome first, upstream
  later; do not block the move on it.

**MR c2: thin commands and fix the repository bypass**
- Move the allow-list check, head-sha lookup, thread selection and enabled-flag logic out of
  `FixCommand`, `RespondCommand` and `TriageCommand` into services; give them one config DTO read
  from the environment in one place.
- Bind the collaborators in the CLI container so commands receive a built service. Remove the
  `new` chains.
- Change `AutofixJobService` to take `AutofixJobReader` and `AutofixJobWriter`, bound in the CLI
  container; drop the concrete-repository workaround noted in its docblock.
- Optional, same MR or later: `Enum/<Domain>/` subfolders as in kitg.

### (d) Briefs and lane scopes

I could not see these, so this is a recommendation plus a question:

1. Ask Shepherd for the lane scope and brief of the lanes `feat/autofix-thread-reader`,
   `-event-filter`, `-triage`, `-job-state`, `-reply-label`, `-fixer-runner`, `-resolution`. If a
   scope was `php/Kelpie/<Feature>/**` or the brief named a folder, that is the cause and the
   rest of this item applies. If neither, the cause was the missing rule alone (4.1 to 4.3).
2. Briefs for PHP lanes should carry the family table (1.1) and the sentence "put each class in
   its family folder; if the scope does not allow that folder, `ask_shepherd` rather than place
   it elsewhere". Without that, a scope that excludes `Service/` yields a domain folder.
3. Scope lanes by family, not by feature: `php/Kelpie/Service/<Domain>/**`,
   `php/Kelpie/Repository/<Entity>/**`, `php/Kelpie/DataTransferObject/**`, `php/Kelpie/Enum/**`
   and the mirrored `tests/**` paths. A feature that needs a new family folder is then a question
   to Shepherd, not a silent addition.
4. Design docs list target FQCNs per step. The step table in `mr-thread-autofix.md` names none;
   the plan-first rule already asks for a file list, so approve the FQCNs there. An unfamiliar
   top-level folder in a plan is a reject.
5. Agent self-check: the brief should name the exact commands, including the new architecture
   test once (a) exists. Until then, the person-approved file list is the only gate.

## 6. Observations outside the question

- uBixCore also ships an AI review (`Service/Ci/AiReviewService`, Gemini, with
  `ai-review-instructions.md`) and Kelpie has its own (`ci/ai-review`, OpenRouter). Two
  implementations of the same job; whichever wins should be the one that carries the placement
  rule from (b).
- `php bin/ubix code:review` fails in its phpstan step on ubixcore 0.45.0 (noted in `CLAUDE.md`).
  If the architecture check is to ride in that gate, that bug has to be fixed upstream first.
- uBixCore `External/GoogleAuthenticator` sits in the `External` family while every other
  provider integration in the framework is `Service/<Domain>/*Service`. Not verified as wrong, but
  it is the one upstream example that supports the "integrations go in their own folder" reading
  that Kelpie followed.

## 7. Method and limits

Read: uBixCore `origin/main` (docs and test base, via `git show`; the local checkout at
`~/git/ubixcore` is behind it), kitg `CLAUDE.md` and tree, Kelpie `CLAUDE.md`, all files under
`php/Kelpie/` and `tests/`, `git log` for the autofix paths, `glab mr view` and notes for !19 to
!25, `docs/design/mr-thread-autofix.md`, `ci/ai-review/{prompt.md,review.sh}`, `docs/ai-review.md`.
Not run: the quality gate (nothing changed), and no class was loaded to confirm family resolution;
the family findings come from reading the test base source. Not available: Shepherd lane scopes
and briefs (5d).
