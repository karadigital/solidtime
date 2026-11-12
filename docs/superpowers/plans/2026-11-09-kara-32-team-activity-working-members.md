# KARA-32: Show All Working Members in Team Activity — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The dashboard "Team Activity" card shows every member whose timer is currently running (no cap), and tops the list up to 4 entries with the most recently active idle members.

**Architecture:** The cap lives in `DashboardService::latestTeamActivity()` (`->slice(0, 4)`). Replace it with: all working members first (newest start first), then idle members (newest start first) only until the list reaches 4. The response shape of `GET /api/v1/organizations/{organization}/charts/latest-team-activity` does not change, so the generated API client needs no regeneration. On the frontend, `TeamActivityCard.vue` gets a scrollable list so a long list does not stretch the dashboard grid row.

**Tech Stack:** Laravel (PHP 8.3, PostgreSQL `DISTINCT ON`), PHPUnit; Vue 3 + TypeScript, Vitest + @vue/test-utils (happy-dom).

**Spec:** Plane ticket KARA-32 — "[Time] Issue report: as Admin, cannot see more than 4 ppl working in Dashboard" (https://time.shrwt.id/dashboard). "Should be able to see the working pulse of other ppl." Behaviour decided with the requester on 2026-11-09: *all working members always shown, sorted on top; if fewer than 4 are working, fill the remaining slots with other members' latest activity up to 4 total; list scrolls when long.*

## Global Constraints

- Minimum list length target: **4** (same number as today). Working members are never cut; idle members fill only up to 4 total.
- Response item shape stays exactly `{member_id: string, name: string, description: string|null, time_entry_id: string, task_id: string|null, status: bool}` — no new/renamed keys.
- Permission stays `charts:view:all` (unchanged; employees still don't see the card).
- Work on a branch: `git checkout -b fix/kara-32-team-activity-working-members`.
- PHP tests use connection `pgsql_test` (`phpunit.xml`), configured by `DB_TEST_HOST/PORT/DATABASE/USERNAME/PASSWORD` in `.env`. Locally that means a `testing` database on the `psql_db` container (`127.0.0.1:54321`, user `postgres`) and PHP 8.3 with `pdo_pgsql` — see the local setup notes from this session.

## Review Focus

1. **More than 4 members are working at once** → every one of them is listed, none dropped. → Task 1, `test_latest_team_activity_returns_all_working_members_even_if_more_than_four`.
2. **An idle member started an entry more recently than a working member** → the working member is still listed above the idle one. → Task 1, `test_latest_team_activity_lists_working_members_first_and_fills_up_to_four_with_idle_members`.
3. **A member's running entry is not their entry with the latest `start`** (e.g. they added a manual entry dated after their running timer started) → the member is still reported as working, with the running entry's id/description. → Task 1, `test_latest_team_activity_reports_member_as_working_when_running_entry_is_not_their_latest_start`.
4. **Running entries in another organization** → never appear in this organization's list. → asserted inside Task 1's first test.
5. **Many working members (e.g. 10+)** → the card does not grow the dashboard row; its list scrolls. → Task 2, `TeamActivityCard.test.ts` + visual check.

---

### Task 1: Backend — return all working members, fill to 4 with idle

**Files:**
- Modify: `app/Service/DashboardService.php:339-376` (`latestTeamActivity()` + its docblock, which also has the typo "Rhe")
- Test: `tests/Unit/Service/DashboardServiceTest.php` (add 3 tests next to the existing `test_latest_team_activity_returns_the_most_current_working_users_and_what_they_are_working_on`, which must keep passing unchanged)

**Interfaces:**
- Consumes: nothing new. Factories already used in this file: `Organization::factory()`, `Member::factory()->forOrganization($org)`, `TimeEntry::factory()->forMember($m)->forOrganization($org)->active()`.
- Produces: `DashboardService::latestTeamActivity(Organization $organization): array` — same signature and `@return` shape as today; new ordering/limit semantics as in the Goal.

- [ ] **Step 1: Write the failing tests**

Add to `DashboardServiceTest` (same Arrange/Act/Assert style as the existing test; compare with `assertSame` on the list of `time_entry_id`s plus `status` so ordering is pinned):

```php
public function test_latest_team_activity_returns_all_working_members_even_if_more_than_four(): void
{
    // Arrange: 6 members with a running entry, started 10,20,30,40,50,60 minutes ago
    //          + 1 idle member whose stopped entry started 5 minutes ago
    //          + 1 member of ANOTHER organization with a running entry started 1 minute ago
    // Act
    $result = $this->dashboardService->latestTeamActivity($organization);
    // Assert
    $this->assertSame(
        [$running10->getKey(), $running20->getKey(), $running30->getKey(), $running40->getKey(), $running50->getKey(), $running60->getKey()],
        array_column($result, 'time_entry_id')
    );
    $this->assertSame([true, true, true, true, true, true], array_column($result, 'status'));
}

public function test_latest_team_activity_lists_working_members_first_and_fills_up_to_four_with_idle_members(): void
{
    // Arrange: 2 running entries started 30 and 40 minutes ago (2 members)
    //          3 stopped entries started 5, 10, 20 minutes ago (3 other members)
    // Assert time_entry_id order: [running30, running40, stopped5, stopped10]
    // Assert status:              [true, true, false, false]
}

public function test_latest_team_activity_reports_member_as_working_when_running_entry_is_not_their_latest_start(): void
{
    // Arrange: one member with a running entry started 60 minutes ago (description 'Running')
    //          and a stopped entry for the SAME member started 5 minutes ago (description 'Manual')
    // Assert: exactly one result row for that member, with
    //         'time_entry_id' => running entry id, 'description' => 'Running', 'status' => true
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=test_latest_team_activity tests/Unit/Service/DashboardServiceTest.php`
Expected: the 3 new tests FAIL (first: only 4 ids returned; second: idle entries ordered before working ones; third: `status` false / `Manual` entry returned). The existing `..._what_they_are_working_on` test PASSES.

- [ ] **Step 3: Implement in `DashboardService::latestTeamActivity()`**

- In the `DISTINCT ON (member_id)` query, add an ordering between `member_id` and `start desc` so a running entry wins over a later-started stopped one: `->orderByRaw('"end" is null desc')`. Keep the existing select, `whereBelongsTo` and `with(['member' => ['user']])`.
- Replace `->sortByDesc('start')->slice(0, 4)` with: sort by `start` desc, `partition` on `end === null` into working/idle, then `working->concat(idle->take(max(0, 4 - working->count())))`. Use a named constant or local variable for `4` (e.g. `$minimumEntries = 4`).
- Keep building `$response` exactly as now (the loop appends with `[]`, so collection keys don't matter).
- Fix the docblock: "All members of the organization that are currently working, followed by the most recently active idle members until the list has at least 4 entries, …" (keep the `@return` line unchanged).

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/Service/DashboardServiceTest.php tests/Unit/Endpoint/Api/V1/ChartEndpointTest.php`
Expected: all PASS.

- [ ] **Step 5: Static analysis & formatting**

Run: `composer analyse` → Expected: `[OK] No errors`.
Run: `composer fix` → Expected: no remaining changes after it runs (commit whatever it formatted).

- [ ] **Step 6: Commit**

```bash
git add app/Service/DashboardService.php tests/Unit/Service/DashboardServiceTest.php
git commit -m "fix(dashboard): show all working members in team activity (KARA-32)"
```

---

### Task 2: Frontend — scrollable Team Activity list

**Files:**
- Modify: `resources/js/Components/Dashboard/TeamActivityCard.vue` (the `<div v-else-if="latestTeamActivity">` wrapper around the `TeamActivityCardEntry` loop)
- Create: `resources/js/Components/Dashboard/TeamActivityCard.test.ts`

**Interfaces:**
- Consumes: Task 1's endpoint response (shape unchanged; may now contain more than 4 items).
- Produces: wrapper element `data-testid="team_activity_list"` with classes `max-h-64 overflow-y-auto` (256px ≈ 4 entries; taller lists scroll inside the card).

- [ ] **Step 1: Write the failing test** `resources/js/Components/Dashboard/TeamActivityCard.test.ts`

Mock the data layer so the component mounts without vue-query/Inertia (follow `resources/js/Components/Timesheet/TimesheetCell.test.ts` style; put the fixture in `vi.hoisted` because `vi.mock` factories are hoisted):
- `vi.mock('@tanstack/vue-query', …)` → `useQuery` returns `{ data: ref(sixWorkingEntries), isLoading: ref(false) }`
- `vi.mock('@/utils/useUser', …)` → `getCurrentOrganizationId: () => 'org-1'`
- `vi.mock('@/packages/api/src', …)` → `{ api: { latestTeamActivity: vi.fn() } }`
- `vi.mock('@inertiajs/vue3', …)` → `{ router: { visit: vi.fn() } }`

`sixWorkingEntries`: 6 items `{ member_id: 'm1'..'m6', name: 'Member 1'..'Member 6', description: 'Task n', time_entry_id: 'te1'..'te6', task_id: null, status: true }`.

```ts
describe('TeamActivityCard', () => {
    it('renders every returned member, not just the first four', () => {
        const wrapper = mount(TeamActivityCard);
        for (let i = 1; i <= 6; i++) {
            expect(wrapper.text()).toContain(`Member ${i}`);
        }
        expect(wrapper.text().match(/working/g)).toHaveLength(6);
    });

    it('makes the activity list scrollable so long lists do not stretch the card', () => {
        const wrapper = mount(TeamActivityCard);
        const list = wrapper.get('[data-testid="team_activity_list"]');
        expect(list.classes()).toEqual(expect.arrayContaining(['max-h-64', 'overflow-y-auto']));
    });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/Components/Dashboard/TeamActivityCard.test.ts`
Expected: first test PASSES (the component never capped the list itself), second FAILS with "Unable to get [data-testid="team_activity_list"]". If the first test fails for a mocking reason, fix the mocks before continuing.

- [ ] **Step 3: Add `data-testid="team_activity_list"` and `class="max-h-64 overflow-y-auto"` to the `v-else-if="latestTeamActivity"` div in `TeamActivityCard.vue`**

No other template/logic changes.

- [ ] **Step 4: Run tests, type-check, lint**

Run: `npx vitest run resources/js/Components/Dashboard/TeamActivityCard.test.ts` → Expected: 2 passed.
Run: `npm run type-check` → Expected: exit 0.
Run: `npm run lint && npm run format:check` → Expected: exit 0.

- [ ] **Step 5: Visual check in the running app**

With the app running locally (`php artisan serve` + `npm run dev` or `npm run build`), as an admin of an org with ≥6 members, start running time entries for 6 members (e.g. via `php artisan tinker`: `TimeEntry::factory()->forMember($m)->forOrganization($org)->active()->create()` per member). Open `/dashboard` at `xl` width (≥1280px — the card is hidden at `lg`).
Expected: all 6 show "working"; the card stays roughly the height of its neighbours and the list scrolls; no horizontal overflow.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/Dashboard/TeamActivityCard.vue resources/js/Components/Dashboard/TeamActivityCard.test.ts
git commit -m "fix(dashboard): make team activity list scrollable (KARA-32)"
```
