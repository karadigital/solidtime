# KARA-47: Sync Plane Work Item Status from the Timer — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Starting a timer on a task named `[KARA-47] …` moves that Plane work item to "In Progress"; stopping the timer asks the user whether to move it to review ("In Review", else "Ready for Test").

**Architecture:** solidtime tasks are already named after Plane work items (`[IDENT-N] Title`, 1909 of 1916 tasks), so the identifier is parsed from the task name — no new columns. A small `PlaneClient` (Laravel `Http`, `X-Api-Key`) talks to the Plane public API v1; `PlaneStatusService` applies the state rules. Start: a `TimeEntry` observer dispatches a job **after the response** (never blocks/fails the timer). Stop: the frontend shows a prompt after a successful stop; "Move to review" calls a new endpoint that moves the work item synchronously and returns the outcome for a notification.

**Tech Stack:** Laravel (PHP 8.3), PHPUnit with `Http::fake`/`Bus::fake`; Vue 3 + Pinia + Zodios client, Vitest.

**Spec:** Plane ticket KARA-47 "[Time][Plane] Auto Change Status": *"Jika kita start time di suatu task, buat otomatis pindahin tiket status ke inprogress. ketika stop update lagi ke in review / ready to test"*. Decisions by the requester on 2026-11-10:
1. On **stop: ask first** (prompt Yes/No). Never on auto-stop (KARA-46 command) — only the user's own Stop click prompts.
2. Review target: **"In Review" first, else "Ready for Test"**, else leave unchanged.
3. On **start**: move to "In Progress" from any state **except** groups `completed` / `cancelled`.
4. Credentials in **server env** (one bot API key for everything).

Plane API (verified in `/Users/karadigital/Documents/projects/plane/apps/api/plane/api/urls`):
- `GET  {base}/api/v1/workspaces/{slug}/work-items/{IDENT}-{N}/` → `{id, project, state, …}` (404 if unknown; identifier must be the project's *current* identifier).
- `GET  {base}/api/v1/workspaces/{slug}/projects/{project_id}/states/` → `{results: [{id, name, group, …}]}`.
- `PATCH {base}/api/v1/workspaces/{slug}/projects/{project_id}/work-items/{id}/` body `{"state": "<state id>"}`.
- Header `X-Api-Key: <key>`; rate limit 60/min per key.

## Global Constraints

- Env: `PLANE_API_KEY`, `PLANE_BASE_URL` (e.g. `https://plane.shrwt.id`, no trailing slash needed — trim it), `PLANE_WORKSPACE_SLUG` (e.g. `kara-digital`) → `config('services.plane.api_key|base_url|workspace_slug')`. Integration is **enabled only if all three are non-empty**; add the three keys (empty) to `.env.example`.
- Identifier pattern (PHP and TS identical): `^\[([A-Z][A-Z0-9]*-[0-9]+)\]` on the task name; no match → integration does nothing.
- State names matched **case-insensitively after trim**: start target `In Progress`; review targets in order `In Review`, `Ready for Test`.
- Never change a work item whose current state's group is `completed` or `cancelled`; never PATCH when already in the target state.
- HTTP timeout 5 s for every Plane call.
- Starting a timer must never wait for or fail because of Plane.
- `Model::preventLazyLoading` is on outside production → load the task explicitly (`Task::query()->find(...)` / `loadMissing`).
- Branch: `git checkout main && git checkout -b feat/kara-47-plane-status-sync`. PR #2 (KARA-46) also edits `stopTimer` in `resources/js/utils/useCurrentTimeEntry.ts`; whichever merges second resolves by keeping both changes.
- Local PHP test runs: `PHP_INI_SCAN_DIR=":<scratchpad>/phpini" DB_TEST_HOST=127.0.0.1 DB_TEST_DATABASE=solidtime_testing /opt/homebrew/opt/php@8.3/bin/php artisan test …`. Known local env failures: 6 PDF export tests in `TimeEntryEndpointTest` (also fail on `main`).

## Review Focus

1. **Plane is down/slow/403 when a timer starts** → timer starts normally, error only logged. → Task 2 `test_job_logs_and_swallows_plane_errors` + `test_starting_a_timer_does_not_call_plane_during_the_request`.
2. **Task carries an old identifier** (`[SOPI-293]` in Plane project ECOM, now `ECOM`) → Plane 404 → nothing changes; review prompt answers "not found" instead of erroring. → Task 1 `test_returns_work_item_not_found_on_404`, Task 3 `test_review_returns_not_found_outcome_for_unknown_work_item`.
3. **Project has no "In Review"** (KARA) → "Ready for Test" is used; **neither exists** → no PATCH. → Task 1 `test_review_falls_back_to_ready_for_test` + `test_review_does_nothing_when_no_review_state_exists`.
4. **Work item already Done/Cancelled** → start doesn't reopen it, review doesn't move it. → Task 1 `test_does_not_touch_completed_or_cancelled_work_items`.
5. **Timer started without a task, task picked later** → moves to In Progress when the task is set; editing only the description doesn't call Plane again. → Task 2 `test_dispatches_when_task_is_added_to_a_running_entry` + `test_does_not_dispatch_when_other_fields_of_a_running_entry_change`.

---

### Task 1: Plane client + status rules

**Files:**
- Modify: `config/services.php` (`'plane' => ['api_key' => env('PLANE_API_KEY'), 'base_url' => env('PLANE_BASE_URL'), 'workspace_slug' => env('PLANE_WORKSPACE_SLUG')]`), `.env.example`
- Create: `app/Service/Plane/PlaneClient.php`, `app/Service/Plane/PlaneApiException.php`, `app/Service/Plane/PlaneStatusService.php`, `app/Service/Plane/PlaneTransitionOutcome.php` (string-backed enum)
- Test: `tests/Unit/Service/Plane/PlaneStatusServiceTest.php` (extends `Tests\TestCase`, uses `Http::fake`)

**Interfaces:**
- Produces:
  - `PlaneClient::isConfigured(): bool`
  - `PlaneClient::findWorkItem(string $identifier): ?array` → `['id' => string, 'project' => string, 'state' => string]`, `null` on 404; throws `PlaneApiException` on any other non-2xx / connection error.
  - `PlaneClient::listStates(string $projectId): array` → `list<array{id: string, name: string, group: string}>` (from `results`).
  - `PlaneClient::updateWorkItemState(string $projectId, string $workItemId, string $stateId): void`
  - `PlaneStatusService::workItemIdentifierFromTaskName(string $taskName): ?string` (static)
  - `PlaneStatusService::moveToInProgress(string $identifier): PlaneStatusResult`
  - `PlaneStatusService::moveToReview(string $identifier): PlaneStatusResult`
  - `PlaneStatusResult` — readonly class in `app/Service/Plane/PlaneStatusResult.php`: `PlaneTransitionOutcome $outcome`, `?string $stateName` (target state's name when known).
  - `PlaneTransitionOutcome` cases (value): `Moved` (`moved`), `AlreadyInState` (`already_in_state`), `WorkItemClosed` (`work_item_closed`), `StateNotFound` (`state_not_found`), `WorkItemNotFound` (`work_item_not_found`).

- [ ] **Step 1: Write the failing tests** (config set in `setUp` to `api_key=key`, `base_url=https://plane.test/`, `workspace_slug=kara`; fake URLs `https://plane.test/api/v1/workspaces/kara/...`; states fixture for project `p1`: Todo/unstarted, In Progress/started, In Review/started, Ready for Test/started, Done/completed, Cancelled/cancelled)
  - `test_parses_identifier_from_task_name`: `'[KARA-47] Auto change'` → `'KARA-47'`; `'[A1B-2] x'` → `'A1B-2'`; `'KARA-47 no brackets'`, `'[-35] x'`, `'Finish migration'` → `null`.
  - `test_is_not_configured_when_a_value_is_missing`: blank `workspace_slug` → `isConfigured()` false.
  - `test_moves_to_in_progress`: work item state = Todo id → PATCH sent to `.../projects/p1/work-items/w1/` with `['state' => <In Progress id>]` and header `X-Api-Key: key`; result `Moved`, `stateName 'In Progress'`.
  - `test_matches_state_names_case_insensitively`: states named `' in progress '` → still `Moved`.
  - `test_does_not_touch_completed_or_cancelled_work_items`: current Done → `moveToInProgress` and `moveToReview` both `WorkItemClosed`; same for Cancelled; `Http::assertNotSent` for PATCH.
  - `test_does_not_patch_when_already_in_target_state`: current In Progress → `AlreadyInState`, no PATCH.
  - `test_review_prefers_in_review`: → PATCH In Review id, `Moved`, `'In Review'`.
  - `test_review_falls_back_to_ready_for_test`: states without In Review → PATCH Ready for Test.
  - `test_review_does_nothing_when_no_review_state_exists`: → `StateNotFound`, no PATCH.
  - `test_returns_work_item_not_found_on_404`: GET work item 404 → `WorkItemNotFound`, no further requests.
  - `test_throws_plane_api_exception_on_server_error`: GET work item 500 → `PlaneApiException`.
- [ ] **Step 2: Run** `… artisan test tests/Unit/Service/Plane` → Expected: all FAIL (classes missing).
- [ ] **Step 3: Implement** the classes above (`Http::withHeaders(['X-Api-Key' => …])->acceptJson()->timeout(5)`; base URL `rtrim(…, '/')`).
- [ ] **Step 4: Run** same → all PASS. phpstan + pint on changed files → clean.
- [ ] **Step 5: Commit** `feat(plane): add Plane client and status rules (KARA-47)`.

---

### Task 2: Move to "In Progress" when a timer starts on a task

**Files:**
- Create: `app/Jobs/MovePlaneWorkItemToInProgress.php` (`Dispatchable`, **not** `ShouldQueue`; public `string $identifier`; `handle(PlaneStatusService $service): void` calls `moveToInProgress`, catches `\Throwable` and `Log::warning('Plane: could not move work item to In Progress', ['identifier' => …, 'error' => $e->getMessage()])`; logs outcome at `info`)
- Create: `app/Observers/TimeEntryPlaneObserver.php` (`saved(TimeEntry $timeEntry): void`)
- Modify: `app/Providers/AppServiceProvider.php` `boot()` (`TimeEntry::observe(TimeEntryPlaneObserver::class);`)
- Test: `tests/Unit/Observers/TimeEntryPlaneObserverTest.php` (extends `TestCaseWithDatabase`), `tests/Unit/Jobs/MovePlaneWorkItemToInProgressTest.php`

**Interfaces:**
- Consumes: Task 1 `PlaneClient::isConfigured()`, `PlaneStatusService::workItemIdentifierFromTaskName()`, `moveToInProgress()`.
- Produces: observer rule — dispatch `MovePlaneWorkItemToInProgress::dispatchAfterResponse($identifier)` iff Plane configured **and** `end === null` **and** `task_id !== null` **and** (`wasRecentlyCreated` **or** `wasChanged('task_id')`) **and** the task name yields an identifier.

- [ ] **Step 1: Write the failing tests** (observer tests: `Bus::fake()`, Plane config set; task `Task::factory()->forOrganization($org)->create(['name' => '[KARA-47] Auto change'])`)
  - `test_dispatches_when_a_running_entry_is_created_on_a_plane_task` → `Bus::assertDispatchedAfterResponse(MovePlaneWorkItemToInProgress::class, fn ($job) => $job->identifier === 'KARA-47')`.
  - `test_dispatches_when_task_is_added_to_a_running_entry`: running entry without task, then `task_id` set + save → dispatched once.
  - `test_does_not_dispatch_when_other_fields_of_a_running_entry_change`: running entry on task, then only `description` changed + save → dispatched exactly once in total (from creation).
  - `test_does_not_dispatch_for_stopped_entries`: create entry with `end` set (manual/import) → not dispatched.
  - `test_does_not_dispatch_for_tasks_without_identifier`: task `'Finish migration'` → not dispatched.
  - `test_does_not_dispatch_when_plane_is_not_configured`: blank api_key → not dispatched.
  - `test_starting_a_timer_does_not_call_plane_during_the_request`: `Http::fake()`; `POST route('api.v1.time-entries.store', …)` running entry on Plane task as an authorized member (pattern from `TimeEntryEndpointTest` store tests) → 201 and `Http::assertNothingSent()` before the response is returned (with `Bus::fake()` the after-response job is only recorded).
  - Job test `test_job_logs_and_swallows_plane_errors`: `Http::fake` 500 for the work item GET, `Log::spy()` → `handle()` does not throw; `Log::shouldHaveReceived('warning')`.
- [ ] **Step 2: Run** `… artisan test tests/Unit/Observers tests/Unit/Jobs/MovePlaneWorkItemToInProgressTest.php` → Expected: FAIL.
- [ ] **Step 3: Implement** job, observer (load task via `Task::query()->find($timeEntry->task_id)`), provider registration.
- [ ] **Step 4: Run** those tests + `tests/Unit/Endpoint/Api/V1/TimeEntryEndpointTest.php` → new PASS; TimeEntryEndpointTest only the 6 known PDF export failures.
- [ ] **Step 5:** phpstan + pint clean. **Commit** `feat(plane): move work item to In Progress when a timer starts (KARA-47)`.

---

### Task 3: "Move to review" endpoint + feature flag for the frontend

**Files:**
- Create: `app/Http/Controllers/Api/V1/TimeEntryPlaneController.php` with `moveToReview(Organization $organization, TimeEntry $timeEntry, PlaneClient $client, PlaneStatusService $service): JsonResponse` (docblock `@operationId moveTimeEntryTaskToPlaneReview`)
- Modify: `routes/api.php` time-entries group: `Route::post('/time-entries/{timeEntry}/plane-review', [TimeEntryPlaneController::class, 'moveToReview'])->name('plane-review')->middleware('check-organization-blocked');`
- Modify: `app/Http/Middleware/HandleInertiaRequests.php` share `'plane_integration_enabled' => app(PlaneClient::class)->isConfigured()`
- Test: `tests/Unit/Endpoint/Api/V1/TimeEntryPlaneEndpointTest.php`, extend `tests/Unit/Middleware/HandleInertiaRequestsMiddlewareTest.php`

**Interfaces:**
- Consumes: Task 1 service/client.
- Produces: `POST /api/v1/organizations/{organization}/time-entries/{timeEntry}/plane-review` →
  - 200 `{"data": {"identifier": "KARA-47", "outcome": "<PlaneTransitionOutcome value>", "state": "<state name>|null"}}`
  - 422 `{"message": "This time entry has no task linked to a Plane work item."}` when no task / no identifier
  - 503 `{"message": "The Plane integration is not configured."}`
  - 502 `{"message": "Plane could not be reached. Please try again later."}` on `PlaneApiException`
  - Permission: own entry (`$timeEntry->member_id === $this->member($organization)->getKey()`) → `time-entries:update:own`, else `time-entries:update:all` (same `checkPermission($organization, …, $timeEntry)` pattern as `TimeEntryController::update`).
  - Inertia prop `plane_integration_enabled: bool`.

- [ ] **Step 1: Write the failing tests** (Plane config set + `Http::fake` per test)
  - `test_review_moves_work_item_and_returns_outcome` → 200, `data.outcome = 'moved'`, `data.state = 'In Review'`, `data.identifier = 'KARA-47'`.
  - `test_review_returns_not_found_outcome_for_unknown_work_item` (`[SOPI-293]`, GET 404) → 200 `outcome = 'work_item_not_found'`.
  - `test_review_fails_for_entry_without_plane_task` → 422.
  - `test_review_fails_when_plane_is_not_configured` → 503.
  - `test_review_returns_502_when_plane_errors` (GET 500) → 502.
  - `test_review_fails_for_other_members_entry_without_update_all_permission` → 403.
  - Inertia: `test_shares_plane_integration_enabled` true when configured, false when not.
- [ ] **Step 2: Run** the two test files → FAIL.
- [ ] **Step 3: Implement** controller, route, shared prop.
- [ ] **Step 4: Run** → PASS; phpstan + pint clean.
- [ ] **Step 5: Commit** `feat(plane): endpoint to move a time entry's work item to review (KARA-47)`.

---

### Task 4: Frontend — ask after stop

**Files:**
- Create: `resources/js/utils/plane.ts` — `getPlaneIdentifier(taskName: string): string | null` (Global Constraints regex), `isPlaneIntegrationEnabled(): boolean` (`usePage().props.plane_integration_enabled === true`)
- Modify: `resources/js/packages/api/src/openapi.json.client.ts` — endpoint `{ method: 'post', path: '/v1/organizations/:organization/time-entries/:timeEntry/plane-review', alias: 'moveTimeEntryTaskToPlaneReview', requestFormat: 'json', parameters: [organization, timeEntry path strings], response: z.object({ data: z.object({ identifier: z.string(), outcome: z.enum(['moved','already_in_state','work_item_closed','state_not_found','work_item_not_found']), state: z.union([z.string(), z.null()]) }).passthrough() }).passthrough() }` (follow the `resetUserPendingEmail` entry's shape).
- Modify: `resources/js/utils/useCurrentTimeEntry.ts` — `stopTimer(): Promise<boolean>` (true iff the update returned data); new state `planeReviewCandidate = ref<{ timeEntryId: string; taskId: string } | null>(null)` (exported from the store); in `setActiveState(false)` capture `id`/`task_id` before stopping and, if stop returned true, `task_id` is set and `isPlaneIntegrationEnabled()`, set the candidate.
- Create: `resources/js/Components/Common/Plane/PlaneReviewPrompt.vue` — mounted once in `resources/js/Layouts/AppLayout.vue` next to `<NotificationContainer>`; reads the candidate, finds the task in `useTasksQuery()` data, computes the identifier (none → clear candidate silently), shows an `AlertDialog` (`@/Components/ui/alert-dialog`).
- Test: `resources/js/utils/plane.test.ts`, `resources/js/Components/Common/Plane/PlaneReviewPrompt.test.ts`, extend/create `resources/js/utils/useCurrentTimeEntry.test.ts`

Copy (exact):
- Title: `Move {identifier} to review?`
- Description: `Your timer for "{task name}" was stopped. Move the Plane work item to review, or keep its current status if you are not done yet.`
- Buttons: `Not yet` (clears candidate, no request) / `Move to review` (calls API, then clears)
- Notifications after the call: `moved` → success `Moved {identifier} to {state}`; `already_in_state` → success `{identifier} is already in {state}`; `work_item_closed` → error `{identifier} is already done or cancelled in Plane`; `state_not_found` → error `{identifier}'s project has no "In Review" or "Ready for Test" state`; `work_item_not_found` → error `{identifier} was not found in Plane`; request failure → handled by `handleApiRequestNotifications` (shows the API message).

- [ ] **Step 1: Write the failing tests**
  - `plane.test.ts`: same cases as Task 1 `test_parses_identifier_from_task_name`.
  - Store: `it('offers a Plane review after stopping a timer on a task')` (mocks as in the KARA-46 store test pattern: `@/packages/api/src`, `@/utils/useUser`, `@/utils/notification`, `@tanstack/vue-query`, plus `@/utils/plane` → `isPlaneIntegrationEnabled: () => true`) → after `setActiveState(false)` on a running entry with `task_id: 't1'`, `planeReviewCandidate` equals `{ timeEntryId: 'te-1', taskId: 't1' }`; `it('does not offer a review when the stop failed')` (update resolves `undefined`) → `null`; `it('does not offer a review when the integration is disabled')` → `null`.
  - Prompt: mount with pinia + mocked `useTasksQuery` (`tasks` ref with `{ id: 't1', name: '[KARA-47] Auto change' }`) and mocked `api.moveTimeEntryTaskToPlaneReview` → candidate set ⇒ text `Move KARA-47 to review?` rendered; click `Move to review` ⇒ `api.moveTimeEntryTaskToPlaneReview` called with `(undefined, { params: { organization: 'org-1', timeEntry: 'te-1' } })` (same call shape as `api.resetUserPendingEmail` in `resources/js/utils/useUserQuery.ts:104`), success notification `Moved KARA-47 to In Review`, candidate cleared; click `Not yet` ⇒ API not called, candidate cleared; task `Finish migration` ⇒ nothing rendered, candidate cleared.
- [ ] **Step 2: Run** `npx vitest run resources/js/utils/plane.test.ts resources/js/utils/useCurrentTimeEntry.test.ts resources/js/Components/Common/Plane` → FAIL.
- [ ] **Step 3: Implement** as above.
- [ ] **Step 4: Run** those → PASS; `npx vitest run` all pass; `npm run type-check` 0; eslint + prettier on changed files clean.
- [ ] **Step 5: Manual check** (if a browser is available): with Plane env set locally to a sandbox work item, start/stop a timer on `[SAND-…]` task and watch Plane. Otherwise ledger it as not done.
- [ ] **Step 6: Commit** `feat(plane): ask to move the work item to review after stopping a timer (KARA-47)`.
