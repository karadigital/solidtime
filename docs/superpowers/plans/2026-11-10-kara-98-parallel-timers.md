# KARA-98 Parallel Running Timers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Users can run several timers at the same time, each with its own project/task, start them from the time tracker, see all of them, and stop them one by one.

**Architecture:**
- Backend: drop the "one running time entry per member" rule unless the organization has `prevent_overlapping_time_entries` on, and add `GET /users/me/time-entries/running`, which lists all running entries.
- Frontend: the Pinia store keeps the timer shown in the tracker as `currentTimeEntry` (the "main" timer) and the other running timers in `backgroundTimeEntries`.
- UI: a "New timer" button parks the main timer in the background and opens an empty tracker. A "Running timers" list under the tracker shows the background timers with Switch/Stop.

**Tech Stack:** Laravel 11, PHPUnit, Vue 3 + Pinia + Zodios, Vitest.

**Spec:** Plane ticket KARA-98 "[Time] Multiple Select for Project Time". The requester multitasks on several projects and writes the other project names in the description; the ticket asks for "running more than 1 timer", with "each timer reserved for 1 project". Decisions in this session:
1. Parallel timers, not one timer with several projects. Each timer counts its full duration for its project, so reports may exceed wall-clock time.
2. No limit on the number of timers.
3. Available to all users.
4. Built on the integration branch `integration/kara-98-base` (main + PRs #1, #2, #4).

## Global Constraints

- Branch `feat/kara-98-parallel-timers` from `integration/kara-98-base`. The PR targets `integration/kara-98-base`.
- Organizations with `prevent_overlapping_time_entries = true` keep the old rule: starting a second running timer → 400 `TimeEntryStillRunningApiException`, and the frontend hides "New timer". The Kara organization has it `false`.
- `GET /users/me/time-entries/active` (`getMyActiveTimeEntry`) stays for API clients and the browser extension. It returns the most recently started running entry and must no longer log a warning for several running entries.
- Quick-start actions keep their old "replace" meaning: they stop the **main** timer only, never background timers. The quick-start actions are:
  - play on a time entry (Time page, Reporting detailed)
  - "recently tracked" on the Dashboard
  - command palette "continue last entry"
- Copy (exact): button `New timer`; list title `Running timers`; row buttons `Switch to` and `Stop`; empty description `No description`; sidebar badge `+{n}` with title `{n} more timer(s) running`.
- Plane (KARA-47): an explicit Stop of any timer, main or background, may offer the review prompt (`planeReviewCandidate`). Quick-start replacements never offer it.
- Auto stop (KARA-46): the command already stops every running entry, one mail per entry. This is unchanged and noted in the PR.
- Team Activity (KARA-32): still one row per member, showing the member's most recently started running timer. This is unchanged and noted in the PR.
- PHP commands use `$PHP` = `PHP_INI_SCAN_DIR=":/private/tmp/claude-501/-Users-karadigital-Documents-projects-solidtime/ada52bc6-c140-4c3e-b761-ce1774e3839b/scratchpad/phpini" DB_TEST_HOST=127.0.0.1 DB_TEST_DATABASE=solidtime_testing /opt/homebrew/opt/php@8.3/bin/php`. Do not modify `.env`.

## Review Focus

1. **Refocusing the window while composing a new timer.**
   - Situation: `AppLayout` calls `fetchCurrentTimeEntry()` on focus while the user is composing a new timer.
   - Expected: the draft must not be replaced by a running timer.
   - Test: Task 2 `keeps the draft while composing a new timer`.
2. **Stopping an older timer while a newer one runs.**
   - Expected: the "still running?" check must use the running list, not `getMyActiveTimeEntry` (which only returns the newest). Otherwise the stop is refused with "Your timer was already stopped".
   - Test: Task 2 `stops a background timer that is not the newest`.
3. **Quick-starting from an entry while background timers run.**
   - Expected: the main timer is replaced; background timers keep running and are not overwritten.
   - Test: Task 2 `replacing the main timer leaves background timers alone`.
4. **Timer stopped elsewhere** (auto-stop at 17:30, another device).
   - Expected: on the next fetch it disappears from the list; stopping it shows "already stopped" and removes it.
   - Test: Task 2 `drops background timers that stopped on the server`.
5. **Organization that prevents overlapping entries.**
   - Expected: the server refuses a second timer and the UI hides "New timer".
   - Tests: Task 1 `test_store_rejects_second_running_entry_when_organization_prevents_overlapping`; Task 3 `hides New timer when the organization prevents overlapping`.

---

### Task 1: Backend — allow parallel running entries, list endpoint

**Files:**
- Modify: `app/Http/Controllers/Api/V1/TimeEntryController.php:593` (store)
- Modify: `app/Http/Controllers/Api/V1/UserTimeEntryController.php` (remove the warning; add `myRunning`)
- Modify: `routes/api.php:127` (add `Route::get('/users/me/time-entries/running', [UserTimeEntryController::class, 'myRunning'])->name('my-running');`, placed before `/active`)
- Modify: `app/Console/Commands/SelfHost/SelfHostDatabaseConsistency.php:80-86`. Remove the "Users with more than one running time entry" check; parallel timers are now valid.
- Modify: `resources/js/packages/api/src/openapi.json.client.ts`. Hand-add alias `getMyRunningTimeEntries`: `GET /v1/users/me/time-entries/running`, response `{ data: z.array(TimeEntry) }` using the existing `TimeEntry` schema, with errors 401.
- Tests:
  - `tests/Unit/Endpoint/Api/V1/TimeEntryEndpointTest.php`
  - `tests/Unit/Endpoint/Api/V1/UserTimeEntryEndpointTest.php`
  - `tests/Unit/Console/Commands/SelfHost/SelfHostDatabaseConsistencyCommandTest.php`

**Interfaces:**
- Produces: `GET /api/v1/users/me/time-entries/running` → `200 { data: TimeEntryResource[] }`.
  - Covers all running entries of the user across organizations, ordered by `start` desc.
  - Returns an empty list, not 404, when nothing runs.
- Produces (frontend): `api.getMyRunningTimeEntries({})`.

- [ ] **Step 1: Write the failing tests**

`TimeEntryEndpointTest`:
- Rename and rewrite `test_store_endpoint_fails_if_user_already_has_active_time_entry_and_tries_to_start_new_one` → `test_store_endpoint_starts_a_second_running_time_entry`.
  - Same arrange.
  - Expect 201 and `data.end` null.
  - Expect `TimeEntry::whereNull('end')->where('member_id', …)->count() === 2`.
- Add `test_store_rejects_second_running_entry_when_organization_prevents_overlapping`.
  - Same arrange, plus `$data->organization->update(['prevent_overlapping_time_entries' => true])`.
  - Expect 400, `error` true, `key` = `time_entry_still_running`. Verify the key against `TimeEntryStillRunningApiException::KEY`.

`UserTimeEntryEndpointTest`:
- Rename `test_my_active_endpoint_logs_a_warning_if_user_has_multiple_active_time_entries_and_return_the_latest_one` → `test_my_active_endpoint_returns_the_latest_of_multiple_running_time_entries`.
  - Keep the latest-id assertion.
  - Replace the Log expectation with `Log::spy()` + `$spy->shouldNotHaveReceived('warning')`.
- Add `test_my_running_endpoint_returns_all_running_time_entries_newest_first`.
  - Two running entries: started 2h ago and 1h ago. One stopped entry. One running entry of another user.
  - Expect 200 and `data` ids `[1h-ago, 2h-ago]`.
- Add `test_my_running_endpoint_returns_an_empty_list_when_nothing_runs`: 200, `data` `[]`.
- Add `test_my_running_endpoint_requires_authentication`: 401.

`SelfHostDatabaseConsistencyCommandTest`:
- Replace `test_checks_that_every_member_can_only_have_one_running_time_entry` with `test_multiple_running_time_entries_are_not_a_consistency_problem`.
  - Same arrange.
  - Expect `Command::SUCCESS` and output without `more than one running`.

- [ ] **Step 2: Run them to see them fail**

Run: `$PHP artisan test tests/Unit/Endpoint/Api/V1/TimeEntryEndpointTest.php --filter "running|active_time_entry|second" ; $PHP artisan test tests/Unit/Endpoint/Api/V1/UserTimeEntryEndpointTest.php tests/Unit/Console/Commands/SelfHost/SelfHostDatabaseConsistencyCommandTest.php`
Expected:
- The second-entry test fails with 400.
- The my-running tests fail with route not found.
- The consistency test fails with FAILURE.

- [ ] **Step 3: Implement**

- Store: throw only when `$organization->prevent_overlapping_time_entries && $request->input('end') === null && <member has running entry>`.
- `myRunning(): JsonResource`: return `TimeEntryResource::collection(...)`, ordered `start` desc. Docblock: `@operationId getMyRunningTimeEntries`, "This endpoint is independent of the organization."
- `myActive` docblock: "Get the most recently started running time entry of the current user". Remove the `Log` warning and its import.
- Consistency command: remove the block and its comment.

- [ ] **Step 4: Run the tests**

Run: same as Step 2, then the full `TimeEntryEndpointTest.php`.
Expected: all pass, except the 6 known Gotenberg PDF export failures.

- [ ] **Step 5: Static checks and commit**

Run:
```
vendor/bin/pint app tests routes
vendor/bin/phpstan analyse app/Http/Controllers/Api/V1/UserTimeEntryController.php app/Http/Controllers/Api/V1/TimeEntryController.php app/Console/Commands/SelfHost --memory-limit=2G
```
Expected: pint passes; phpstan shows only the known "ignored pattern not matched" artefact.

```bash
git add app routes resources/js/packages/api/src/openapi.json.client.ts tests
git commit -m "feat(time): allow parallel running time entries (KARA-98)"
```

### Task 2: Store — main timer plus background timers

**Files:**
- Modify: `resources/js/utils/useCurrentTimeEntry.ts`
- Modify: `resources/js/utils/useCurrentTimeEntry.test.ts`, `resources/js/utils/useCurrentTimeEntry.plane.test.ts`. Mock `getMyRunningTimeEntries` where they mocked `getMyActiveTimeEntry`.
- Create: `resources/js/utils/useCurrentTimeEntry.parallel.test.ts`

**Interfaces:**
- Consumes: `api.getMyRunningTimeEntries` (Task 1).
- Produces new store members (existing ones keep their names):
  - `backgroundTimeEntries: Ref<TimeEntry[]>`: running timers other than the main one, newest first.
  - `composingNewTimer: Ref<boolean>`: true after `startAnotherTimer()` until a timer is started or focused.
  - `hasRunningTimers: ComputedRef<boolean>`: `isActive || backgroundTimeEntries.length > 0`.
  - `startAnotherTimer(): void`
    - If `isActive`, `unshift` the main timer into `backgroundTimeEntries`.
    - Reset `currentTimeEntry` to empty and set `composingNewTimer = true`.
    - The live timer keeps running.
  - `focusTimer(timeEntryId: string): void`
    - Moves that background timer into `currentTimeEntry`.
    - The previous main timer, if running, goes to the background front; a draft is discarded.
    - Sets `composingNewTimer = false`.
  - `stopBackgroundTimer(timeEntryId: string, options?: { offerPlaneReview?: boolean }): Promise<boolean>`
    - Checks with `isStillRunning(timeEntry)`, then `api.updateTimeEntry({ user_id, start, end: now }, { params: { organization: timeEntry.organization_id, timeEntry: id } })`.
    - Removes the timer from the list, sets `planeReviewCandidate` (same rules as the main stop), and invalidates `['timeEntries']`.
    - If the timer no longer runs on the server, removes it, shows the existing "Your timer was already stopped" notification, and returns false.
  - `setActiveState(newState, options: { offerPlaneReview?: boolean; promoteNextTimer?: boolean } = {})`
    - `promoteNextTimer` defaults to `true`.
    - After stopping the main timer:
      - if `promoteNextTimer` and the background is non-empty, `currentTimeEntry = backgroundTimeEntries.shift()` and the live timer keeps running;
      - otherwise `stopLiveTimer()`.
    - Starting a timer sets `composingNewTimer = false`.
- Internal: `ensureCurrentTimeEntryIsStillRunning()` and the new background stop share `isStillRunning(id): Promise<boolean>`.
  - It reads `getMyRunningTimeEntries` and returns `data.some(e => e.id === id)`.
  - On a request error it returns `true` (keeps the old behaviour for non-404 errors).

- [ ] **Step 1: Write the failing tests** (`useCurrentTimeEntry.parallel.test.ts`)

Mock api, useUser, notification, vue-query, plane and useLocalStorage the way `useCurrentTimeEntry.plane.test.ts` does. Running fixtures: `r1` started 2h ago, `r2` started 1h ago, both in org-1.

- `fetch makes the newest running timer the main one and the others background`: running `[r2, r1]` → `currentTimeEntry.id === 'r2'`, background ids `['r1']`.
- `fetch keeps the focused main timer`: current is `r1` (set via `focusTimer` after a fetch) → after a re-fetch, current `r1`, background `['r2']`.
- `keeps the draft while composing a new timer`:
  - Fetch `[r2, r1]`, then `startAnotherTimer()`, then set `currentTimeEntry.description = 'draft'`, then fetch again.
  - Expect current id `''`, description `draft`, background `['r2','r1']`, `composingNewTimer` true.
- `startAnotherTimer moves the main timer to the background`: fetch `[r1]` → `startAnotherTimer()` → current empty, background `['r1']`, `hasRunningTimers` true.
- `starting the composed timer keeps the others running`:
  - After the previous test's state, `createTimeEntry` resolves `r3`, then `setActiveState(true)`.
  - Expect current `r3`, background `['r1']`, `composingNewTimer` false.
- `stopping the main timer promotes the newest background timer`:
  - Fetch `[r2, r1]`; `updateTimeEntry` resolves; running after the stop is `[r1]`.
  - `setActiveState(false)` → current `r1`, background `[]`.
- `replacing the main timer leaves background timers alone`:
  - Fetch `[r2, r1]` → `setActiveState(false, { promoteNextTimer: false })`.
  - Expect current id `''`, background `['r1']`, and `updateTimeEntry` called once with `timeEntry: 'r2'`.
- `stops a background timer that is not the newest`:
  - Fetch `[r2, r1]` → `stopBackgroundTimer('r1')` returns true.
  - Expect `updateTimeEntry` called with `params.timeEntry 'r1'` and `params.organization 'org-1'`, background `[]`, current still `r2`.
- `drops background timers that stopped on the server`:
  - Fetch `[r2, r1]`; the next running call returns `[r2]`.
  - `stopBackgroundTimer('r1')` returns false, `updateTimeEntry` is not called, background `[]`, and `addNotification` is called with `'error', 'Your timer was already stopped'`.
- `stopping a background task timer offers the Plane review`:
  - `r1` has `task_id 't1'`, Plane is enabled.
  - `stopBackgroundTimer('r1', { offerPlaneReview: true })` → `planeReviewCandidate` `{ timeEntryId: 'r1', taskId: 't1' }`.
- `focusTimer swaps main and background`: fetch `[r2, r1]` → `focusTimer('r1')` → current `r1`, background `['r2']`.

- [ ] **Step 2: Run to see them fail**

Run: `npx vitest run resources/js/utils/useCurrentTimeEntry.parallel.test.ts`
Expected: FAIL (`getMyRunningTimeEntries` not called; the new members are undefined).

- [ ] **Step 3: Implement in `useCurrentTimeEntry.ts`**

`fetchCurrentTimeEntry` uses `getMyRunningTimeEntries` and follows this algorithm:

```
running = response.data (newest first); on error: return without changes
if composingNewTimer: background = running; return
main = running.find(e => e.id === currentTimeEntry.id) ?? running[0] ?? null
if main: currentTimeEntry = main; startLiveTimer()
else if currentTimeEntry.id !== '': $reset()            // keep an unsaved draft (id '')
background = running.filter(e => e.id !== main?.id)
if !hasRunningTimers: stopLiveTimer()
```

Then update `ensureCurrentTimeEntryIsStillRunning` to use `isStillRunning`.

- [ ] **Step 4: Run the new and existing store tests**

Run: `npx vitest run resources/js/utils`
Expected: all pass, including the KARA-46 and KARA-47 store tests after their mock rename.

- [ ] **Step 5: Commit**

```bash
git add resources/js/utils/useCurrentTimeEntry.ts resources/js/utils/useCurrentTimeEntry*.test.ts
git commit -m "feat(time): track several running timers in the timer store (KARA-98)"
```

### Task 3: UI — New timer, running timers list, sidebar badge, quick-start callers

**Files:**
- Create: `resources/js/Components/RunningTimersList.vue`, `resources/js/Components/RunningTimersList.test.ts`
- Modify: `resources/js/Components/TimeTracker.vue`:
  - Add the `New timer` button and the list under the controls.
  - Change `watch(isActive)` to `watch(hasRunningTimers)` for the live timer.
- Modify: `resources/js/Components/CurrentSidebarTimer.vue` (badge)
- Modify the quick-start callers to pass `promoteNextTimer: false` in their `setActiveState(false)`:
  - `resources/js/Pages/Time.vue:60`
  - `resources/js/Pages/ReportingDetailed.vue:192`
  - `resources/js/Components/Dashboard/RecentlyTrackedTasksCardEntry.vue:33`

**Interfaces:**
- Consumes: the store members from Task 2; `useProjectsQuery`, `useTasksQuery`, `useOrganizationQuery`.
- Produces: `<RunningTimersList />`. It has no props and reads the store.

- [ ] **Step 1: Write the failing tests** (`RunningTimersList.test.ts`)

Mount with a real Pinia whose store state is seeded (`backgroundTimeEntries`, `now`). Mock `useProjectsQuery` and `useTasksQuery` (project `p1` "Website", color `#f00`; task `t1` "[KARA-98] Multi timer"), `@/utils/useUser`, and the api.

- `renders nothing without background timers`: no `Running timers` text.
- `lists background timers with project, task and live duration`:
  - Entry `r1` (project p1, task t1, description '', start = now − 1h 2m 3s).
  - Expect text `Running timers`, `Website`, `[KARA-98] Multi timer`, `No description`, `01:02:03`.
- `Stop calls stopBackgroundTimer with the Plane review offer`:
  - Spy on the store action.
  - Clicking `Stop` → called with `('r1', { offerPlaneReview: true })`.
- `Switch to focuses the timer`: clicking `Switch to` → `focusTimer('r1')`.

In `TimeTracker`, test through a small new test file `resources/js/Components/TimeTracker.parallel.test.ts`:
- `shows New timer only while a timer runs`.
- `hides New timer when the organization prevents overlapping`.

Mount `TimeTracker` with all heavy children stubbed: `TimeTrackerControls`, `TimeTrackerMoreOptionsDropdown`, `TimeEntryCreateModal`, `RunningTimersList`. Mock `useOrganizationQuery` to return `{ prevent_overlapping_time_entries: <flag> }` and seed `isActive` through `currentTimeEntry` (start set, end null, id 'r1').

- [ ] **Step 2: Run to see them fail**

Run: `npx vitest run resources/js/Components/RunningTimersList.test.ts resources/js/Components/TimeTracker.parallel.test.ts`
Expected: FAIL, missing component and button.

- [ ] **Step 3: Implement**

`RunningTimersList`:
- Card-like list under the tracker (`mt-3 space-y-2`).
- Heading `Running timers` (`text-xs font-medium text-text-secondary`).
- Each row:
  - project color dot + project name, or `No project`
  - task name when set
  - description, or `No description` (muted)
  - duration `HH:mm:ss` from `now − start`, using the same formatting helper as `CurrentSidebarTimer` (reuse it; do not add a new one)
  - `SecondaryButton size="small"` `Switch to`
  - a stop button (`size="small"`, label `Stop`, red stop icon as in `TimeTrackerStartStop` styling)

`TimeTracker.vue`:
- Show `SecondaryButton` `New timer` (`PlusIcon`) when `isActive && !organization?.prevent_overlapping_time_entries`. It calls `startAnotherTimer()`. Place it before `TimeTrackerMoreOptionsDropdown`.
- Render `<RunningTimersList />` below the controls row.

`CurrentSidebarTimer.vue`:
- When `backgroundTimeEntries.length > 0`, show `+{n}` next to the timer, with title `{n} more timer(s) running`.
- Its stop stays `setActiveState(false, { offerPlaneReview: true })`, so the next timer is promoted.

- [ ] **Step 4: Run all checks**

Run:
```
npx vitest run
npm run type-check
npx eslint <changed files>
npx prettier --check <changed files>
npm run build
```
Expected: all green; vitest shows the previous 120 tests plus the new ones.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components resources/js/Pages/Time.vue resources/js/Pages/ReportingDetailed.vue
git commit -m "feat(time): start, list and stop parallel timers in the UI (KARA-98)"
```
