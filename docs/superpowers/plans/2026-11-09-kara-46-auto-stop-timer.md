# KARA-46: Auto Stop Running Timer at End of Workday — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Users can opt in (profile setting) to have their running timer stopped automatically at a configurable time (default 17:30, their own timezone) on Monday–Friday, and get an email when it happens.

**Architecture:** Two new columns on `users` exposed through the existing `PUT /api/v1/users/{user}` + `UserResource`, edited in the Profile → "Profile Information" form. A new console command `time-entry:auto-stop`, scheduled every minute in `app/Console/Kernel.php` (prod scheduler already runs), finds running entries of opted-in users and sets `end` to the stop moment of the entry's start day, then dispatches the same spent-time recalculation jobs the API dispatches and queues a mail. Modelled on `time-entry:send-still-running-mails` (`app/Console/Commands/TimeEntry/TimeEntrySendStillRunningMailsCommand.php`).

**Tech Stack:** Laravel (PHP 8.3, PostgreSQL), PHPUnit; Vue 3 + TypeScript, Zodios client, Vitest.

**Spec:** Plane ticket KARA-46 — "[Time] Give option to enable auto stop Time timer when EOD (17:30)" (requested for a colleague who often forgets to stop the timer). Decisions by the requester on 2026-11-09:
1. Setting is **per user** only.
2. Timers **started after the stop time are left running** — never force-stopped.
3. A timer forgotten since an earlier day is stopped **at the stop time of the day it started**.
4. **Email the user** when their timer is auto-stopped.
5. Applies **Monday–Friday only**.

## Global Constraints

- Columns: `users.auto_stop_time_entries` boolean, default `false`; `users.auto_stop_time` string(5) `H:i`, default `'17:30'`.
- API field names are exactly `auto_stop_time_entries` (bool) and `auto_stop_time` (string `HH:MM`, 24h, validated `date_format:H:i`).
- All day/time rules use the **user's `timezone`** and the entry's **start** in that timezone:
  - start weekday must be Mon–Fri (`Carbon::isWeekday()`), else never stopped;
  - stop moment = start's local date at `auto_stop_time`, seconds `00`;
  - start `>=` stop moment → never stopped;
  - stopped only once `now() >=` stop moment; `end` = stop moment (UTC), not `now()`.
- Placeholder users (`is_placeholder = true`) are never touched.
- Scheduler toggle: `config('scheduling.tasks.time_entry_auto_stop')` ← env `SCHEDULING_TASK_TIME_ENTRY_AUTO_STOP`, default `true`.
- Branch: `git checkout main && git checkout -b feat/kara-46-auto-stop-timer`.
- Local PHP test runs: `PHP_INI_SCAN_DIR=":<scratchpad>/phpini" DB_TEST_HOST=127.0.0.1 DB_TEST_DATABASE=solidtime_testing /opt/homebrew/opt/php@8.3/bin/php artisan test …` (memory ini + test DB from the KARA-32 session). Full suite has 29 known env failures (S3 bucket / export) that also fail on `main`.

## Review Focus

1. **Timer started Friday before the stop time, still running on Monday** → stopped at Friday's stop time (weekend in between must not matter). → Task 3 `test_stops_entry_forgotten_over_the_weekend_at_the_stop_time_of_its_start_day`.
2. **User timezone far from UTC** (Asia/Jakarta, UTC+7) → 17:30 local = 10:30 UTC is used, not 17:30 UTC. → every Task 3 test uses `Asia/Jakarta` and asserts the UTC instant.
3. **Scheduler runs the command again after it already stopped the entry** → no second stop, no second email. → Task 3 `test_running_twice_stops_and_mails_only_once`.
4. **Auto-stopped entry with a project/task** → project/task spent time is recalculated like a manual stop. → Task 3 `test_dispatches_spent_time_recalculation_for_project_and_task`.
5. **User clears/garbles the stop time field** (`''`, `'25:00'`, `'5pm'`) → 422, stored value unchanged. → Task 1 `test_update_fails_if_auto_stop_time_is_invalid`.

---

### Task 1: User auto-stop settings (DB + API)

**Files:**
- Create: `database/migrations/2026_11_09_000001_add_auto_stop_settings_to_users_table.php` (pattern: `2025_10_24_120845_add_employees_can_manage_tasks_to_organizations_table.php`)
- Modify: `app/Models/User.php` (`@property bool $auto_stop_time_entries`, `@property string $auto_stop_time`; cast `'auto_stop_time_entries' => 'boolean'`; `$attributes` defaults `false` / `'17:30'`)
- Modify: `app/Http/Requests/V1/User/UserUpdateRequest.php` (rules `'auto_stop_time_entries' => ['boolean']`, `'auto_stop_time' => ['date_format:H:i']`; getters `getAutoStopTimeEntries(): ?bool`, `getAutoStopTime(): ?string`, same style as `getTimezone()`)
- Modify: `app/Http/Controllers/Api/V1/UserController.php` `update()` (assign when getter `!== null`, next to timezone/week_start)
- Modify: `app/Http/Resources/V1/User/UserResource.php` (add both fields with `/** @var … */` doc comments like the others)
- Test: `tests/Unit/Endpoint/Api/V1/UserEndpointTest.php`

**Interfaces:**
- Produces: `User::$auto_stop_time_entries: bool`, `User::$auto_stop_time: string ('H:i')`; API request/response keys `auto_stop_time_entries`, `auto_stop_time`.

- [ ] **Step 1: Write the failing tests** (same Arrange/Act/Assert style as `test_update_changes_user_name_timezone_and_week_start`)
  - `test_me_returns_auto_stop_defaults`: `GET route('api.v1.users.me')` → `data.auto_stop_time_entries === false`, `data.auto_stop_time === '17:30'`.
  - `test_update_changes_auto_stop_settings`: PUT `{auto_stop_time_entries: true, auto_stop_time: '16:45'}` → response has both; `fresh()` user has `true` / `'16:45'`.
  - `test_update_fails_if_auto_stop_time_is_invalid`: data provider or loop over `['', '25:00', '5pm', '17:30:00']` → each `assertStatus(422)` + `assertJsonValidationErrors(['auto_stop_time'])`; user still `'17:30'`.
  - `test_update_fails_if_auto_stop_time_entries_is_not_boolean`: `'yes'` → 422 on `auto_stop_time_entries`.
- [ ] **Step 2: Run** `… artisan test --filter='auto_stop' tests/Unit/Endpoint/Api/V1/UserEndpointTest.php` → Expected: all 4 FAIL (missing keys / 200 instead of 422).
- [ ] **Step 3: Implement** migration, model, request, controller, resource as listed. Run `… artisan migrate` against the dev DB is NOT needed (tests use RefreshDatabase).
- [ ] **Step 4: Run** `… artisan test tests/Unit/Endpoint/Api/V1/UserEndpointTest.php` → Expected: all PASS.
- [ ] **Step 5: Lint** `php vendor/bin/phpstan analyse --memory-limit=2G -c phpstan.neon <changed php files>` → `[OK] No errors`; `php vendor/bin/pint <changed php files>` → passed.
- [ ] **Step 6: Commit** `feat(profile): add auto stop timer settings to users (KARA-46)`.

---

### Task 2: "Timer auto-stopped" email

**Files:**
- Create: `app/Mail/TimeEntryAutoStoppedMail.php` (mirror `TimeEntryStillRunningMail`: public `TimeEntry $timeEntry`, `User $user`, `build()` → markdown `emails.time-entry-auto-stopped`, subject `__('Your Time Tracker was stopped automatically')`)
- Create: `resources/views/emails/time-entry-auto-stopped.blade.php` (mirror `time-entry-still-running.blade.php`)
- Test: `tests/Unit/Mail/TimeEntryAutoStoppedMailTest.php` (extends `TestCaseWithDatabase`, like `TimeEntryStillRunningMailTest`)

**Interfaces:**
- Produces: `new TimeEntryAutoStoppedMail(TimeEntry $timeEntry, User $user)`; view variables `dashboardUrl` (`URL::route('dashboard')`), `stoppedAt` = `$timeEntry->end` in `$user->timezone` formatted `'H:i'`, `stoppedOn` = same formatted `'l, d.m.Y'`.

Exact copy (use `__()` with placeholders):
- With description: `Your running time entry ":description" was stopped automatically at :time on :date, as set in your profile settings.`
- Without description: `Your running time entry was stopped automatically at :time on :date, as set in your profile settings.`
- Then: `If you were still working, you can adjust the time entry or start a new one in solidtime:` + button `Go to solidtime` → `$dashboardUrl`.

- [ ] **Step 1: Write the failing tests**
  - `test_mail_renders_description_and_stop_time_in_user_timezone`: user timezone `Asia/Jakarta`; entry description `'TEST 123'`, `end` = `Carbon::parse('2026-11-11 10:30:00', 'UTC')` → rendered contains `'"TEST 123" was stopped automatically at 17:30 on Wednesday, 11.11.2026'`.
  - `test_mail_renders_without_description`: description `''` → contains `'Your running time entry was stopped automatically at 17:30'`.
- [ ] **Step 2: Run** `… artisan test tests/Unit/Mail/TimeEntryAutoStoppedMailTest.php` → Expected: FAIL (class not found).
- [ ] **Step 3: Implement** mail class + view.
- [ ] **Step 4: Run** same → PASS. Lint as Task 1 Step 5.
- [ ] **Step 5: Commit** `feat(time-entry): add auto stopped mail (KARA-46)`.

---

### Task 3: `time-entry:auto-stop` command + schedule

**Files:**
- Create: `app/Console/Commands/TimeEntry/TimeEntryAutoStopCommand.php`
- Modify: `app/Console/Kernel.php` (`$schedule->command('time-entry:auto-stop')->when(fn (): bool => config('scheduling.tasks.time_entry_auto_stop'))->everyMinute()->withoutOverlapping();`)
- Modify: `config/scheduling.php` (`'time_entry_auto_stop' => (bool) env('SCHEDULING_TASK_TIME_ENTRY_AUTO_STOP', true),`)
- Test: `tests/Unit/Console/Commands/TimeEntry/TimeEntryAutoStopCommandTest.php` (extends `TestCaseWithDatabase`, style of `TimeEntrySendStillRunningMailsCommandTest`)

**Interfaces:**
- Consumes: Task 1 `User::$auto_stop_time_entries`, `User::$auto_stop_time`; Task 2 `TimeEntryAutoStoppedMail(TimeEntry, User)`.
- Produces: signature `time-entry:auto-stop {--dry-run : Do not actually stop time entries or send emails, just output what would happen}`; description `Stops running time entries of users who enabled auto stop, at their configured stop time (Monday to Friday).`; private `stopMoment(TimeEntry $timeEntry, User $user): ?Carbon` implementing the Global Constraints rules (returns null when the entry must not be stopped yet/ever).

Behaviour:
- Query: `TimeEntry::whereNull('end')->whereHas('user', is_placeholder = false AND auto_stop_time_entries = true)->with(['user', 'project', 'task'])`, iterate with `chunkById(500, …)` (rows are modified while iterating).
- Per entry with non-null `stopMoment` that is `<= now()`: unless dry-run → `end = stopMoment`, `save()`, dispatch `RecalculateSpentTimeForProject::dispatch($project)` / `RecalculateSpentTimeForTask::dispatch($task)` when set (same jobs as `TimeEntryController::update`), `Mail::to($user->email)->queue(new TimeEntryAutoStoppedMail($timeEntry, $user))`.
- Output exactly: `Auto stopping running time entries...`, per entry `Stopping time entry <id> of user "<email>" (<user id>) at <stopMoment UTC ISO 8601, toIso8601ZuluString()>`, then `Finished auto stopping <n> time entries...`.

All tests: user timezone `Asia/Jakarta`, `auto_stop_time_entries = true`, `auto_stop_time = '17:30'` unless stated; `Carbon::setTestNow(...)` with local Jakarta times; entry via `TimeEntry::factory()->forMember($member)->active()->create(['start' => …])`. Wed = 2026-11-11.

- [ ] **Step 1: Write the failing tests**
  - `test_stops_running_entry_at_stop_time_and_mails_user`: start Wed 09:00, now Wed 17:31 → `end` equals `Carbon::parse('2026-11-11 10:30:00', 'UTC')`; `Mail::assertQueued(TimeEntryAutoStoppedMail::class, fn ($m) => $m->hasTo($email) && $m->timeEntry->is($entry))`; exit `SUCCESS`; exact output as above.
  - `test_does_not_stop_before_stop_time`: now Wed 17:29 → `end` null, `Mail::assertNothingOutgoing()`.
  - `test_does_not_stop_when_user_has_auto_stop_disabled`: flag false, now Wed 20:00 → untouched.
  - `test_never_stops_entry_started_after_stop_time`: start Wed 18:00, now Thu 20:00 → untouched.
  - `test_stops_entry_forgotten_over_the_weekend_at_the_stop_time_of_its_start_day`: start Fri 2026-11-13 09:00, now Mon 2026-11-16 08:00 → `end` = Fri 17:30 Jakarta (`2026-11-13 10:30:00 UTC`).
  - `test_does_not_stop_entry_started_on_weekend`: start Sat 2026-11-14 09:00, now Sat 18:00 → untouched.
  - `test_uses_custom_stop_time_and_user_timezone`: user `Europe/Vienna`, `auto_stop_time = '16:00'`, start Wed 08:00 Vienna, now Wed 16:05 Vienna → `end` = `2026-11-11 15:00:00 UTC`.
  - `test_does_not_stop_entries_of_placeholder_users`: user `is_placeholder = true` → untouched.
  - `test_running_twice_stops_and_mails_only_once`: run command twice at Wed 17:31 → `Mail::assertQueued(TimeEntryAutoStoppedMail::class, 1)`; second run output says `Finished auto stopping 0 time entries...`.
  - `test_dispatches_spent_time_recalculation_for_project_and_task`: `Queue::fake()`; entry `forProject`/`forTask` → `Queue::assertPushed(RecalculateSpentTimeForProject::class)` and `RecalculateSpentTimeForTask::class`.
  - `test_dry_run_does_not_stop_or_mail`: `--dry-run` at Wed 17:31 → `end` null, nothing outgoing, output still lists the entry.
- [ ] **Step 2: Run** `… artisan test tests/Unit/Console/Commands/TimeEntry/TimeEntryAutoStopCommandTest.php` → Expected: all FAIL (command not defined).
- [ ] **Step 3: Implement** command, config key, schedule entry.
- [ ] **Step 4: Run** same file → all PASS; `… artisan schedule:list | grep auto-stop` → shows `* * * * *  php artisan time-entry:auto-stop`.
- [ ] **Step 5: Lint** as Task 1 Step 5.
- [ ] **Step 6: Commit** `feat(time-entry): auto stop running timers at the user's stop time (KARA-46)`.

---

### Task 4: Profile form UI

**Files:**
- Modify: `resources/js/packages/api/src/openapi.json.client.ts` (`UserResource`: `auto_stop_time_entries: z.boolean(), auto_stop_time: z.string()`; `UserUpdateRequest` (partial): same two keys). Hand-edit; do not regenerate the whole client.
- Modify: `resources/js/Pages/Profile/Partials/UpdateProfileInformationForm.vue`
- Create: `resources/js/Pages/Profile/Partials/UpdateProfileInformationForm.test.ts`

**Interfaces:**
- Consumes: Task 1 API keys.
- Produces: form refs `autoStopTimeEntries: Ref<boolean>`, `autoStopTime: Ref<string>` seeded in `seedForm()`, sent by `buildPayload()` only when changed; elements `#auto_stop_time_entries` (`Checkbox` from `@/packages/ui/src`, `v-model:checked`) and `#auto_stop_time` (`TextInput type="time"`, `:disabled="!isUserLoaded || !autoStopTimeEntries"`), placed after "Start of the week".

Copy:
- Checkbox label: `Automatically stop my running timer at the end of the workday`
- Time label: `Stop time`
- Help text under it: `Applies Monday to Friday in your timezone. Timers started after this time keep running. You will get an email when your timer is stopped.`
- `FieldError` for both keys from `fieldErrors`.

- [ ] **Step 1: Write the failing tests** (mount with mocks: `@/utils/useUserQuery` → `useUserQuery: () => ({ user: ref(fixture) })`, `useUpdateUserMutation: () => ({ mutateAsync, isPending: ref(false), error: ref(null) })`, the two other mutations as `{ mutateAsync: vi.fn(), isPending: ref(false) }`; `@inertiajs/vue3` → `usePage: () => ({ props: { timezones: { 'Asia/Jakarta': 'Asia/Jakarta' }, weekdays: { monday: 'Monday' } } })`; fixture user has `timezone 'Asia/Jakarta'`, `week_start 'monday'`, `auto_stop_time_entries false`, `auto_stop_time '17:30'`; use `vi.hoisted` for fixtures)
  - `it('disables the stop time input while auto stop is off')` → `#auto_stop_time` has `disabled` attribute.
  - `it('sends the auto stop settings when they are changed')` → check `#auto_stop_time_entries`, set `#auto_stop_time` to `'16:00'`, `form.trigger('submit')` → `mutateAsync` called with `{ userId: fixture.id, body: { auto_stop_time_entries: true, auto_stop_time: '16:00' } }`.
  - `it('does not send auto stop settings when unchanged')` → submit without changes → `mutateAsync` not called.
- [ ] **Step 2: Run** `npx vitest run resources/js/Pages/Profile/Partials/UpdateProfileInformationForm.test.ts` → Expected: first two FAIL (elements missing), third PASS.
- [ ] **Step 3: Implement** zod schema keys + form fields as above.
- [ ] **Step 4: Run** the test file → 3 passed; `npx vitest run` → all pass; `npm run type-check` → exit 0; `npx eslint` + `npx prettier --check` on changed files → clean.
- [ ] **Step 5: Manual check** (if a browser is available): `npm run build`, open `http://localhost:8100/user/profile`, toggle the checkbox, set a time, Save → "Saved."; reload keeps values. Before that run `php artisan migrate` on the dev DB (additive migration, only new columns with defaults).
- [ ] **Step 6: Commit** `feat(profile): auto stop timer settings in profile form (KARA-46)`.
