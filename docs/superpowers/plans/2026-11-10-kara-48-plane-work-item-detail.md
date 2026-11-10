# KARA-48 Plane Work Item Detail Panel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On the project page, clicking a task shows its Plane work item (state, priority, assignees, labels, dates, description) in the right column, fetched live from Plane, so users no longer need a second Plane tab.

**Architecture:** A new read-only endpoint `GET /api/v1/organizations/{organization}/tasks/{task}/plane` resolves the task's `[IDENT-N]` prefix, fetches the work item from Plane API v1 through the existing `PlaneClient` (KARA-47), sanitizes the description HTML server-side and caches the result for 60 s. `ProjectShow.vue` turns the right column into a `PlaneWorkItemPanel`, and the Project Members table moves into a modal opened by a "Show Member" button.

**Tech Stack:** Laravel 11, Symfony HtmlSanitizer, Laravel Http/Cache facades, Vue 3 + TanStack vue-query + Zodios, Vitest, PHPUnit.

**Spec:** Plane ticket KARA-48 ("[Time] View Detail Ticket") plus the requester's decisions in this session:
1. No comments in the panel.
2. Project Members move to a "Show Member" button that opens a modal with the member list.
3. Project page only; no other entry points.
4. Stacked PR on `feat/kara-47-plane-status-sync`.
5. Data is fetched live from Plane, not stored in solidtime.

## Global Constraints

- Branch: `feat/kara-48-plane-work-item-detail`, created from `feat/kara-47-plane-status-sync`. The PR targets `feat/kara-47-plane-status-sync` (stacked).
- The feature is only active when `PlaneClient::isEnabledForOrganization($organizationId)` is true. The frontend uses `isPlaneIntegrationEnabled()` from `resources/js/utils/plane.ts`.
- Map task to work item with `PlaneStatusService::workItemIdentifierFromTaskName()` on the backend and `getPlaneIdentifier()` on the frontend. Do not add a new regex.
- Plane API v1 call: `GET {base}/api/v1/workspaces/{slug}/work-items/{IDENT-N}/?expand=state,assignees,labels`. Plane answers 403 or 404 for unknown or inaccessible work items, and both mean "not found".
- Never send assignee emails or ids to the browser. Send `display_name` only.
- Plane's v1 asset endpoint returns 500 in our fork (`S3Storage(..., is_server=True)` TypeError), so images are **not** shown. Each `<image-component>` becomes the placeholder `<p><em>[Image – open in Plane to view]</em></p>`.
- Each `<mention-component …>` becomes the text `@mention`.
- Cache key `plane:work-item:{IDENT-N}`, TTL 60 seconds. Never cache failures (exceptions).
- Plane web link: `{app_url}/{workspace_slug}/browse/{IDENT-N}/`. `app_url` comes from the new env `PLANE_APP_URL`, falling back to `PLANE_BASE_URL` (locally web runs on :3000 and the API on :8000).
- User-facing copy (exact):
  - `Select a task to see its Plane details.`
  - `This task is not linked to a Plane work item.`
  - `{IDENT} was not found in Plane` / `The work item does not exist, or the Plane bot is not a member of its project.`
  - `Plane could not be reached. Please try again later.`
  - `No description.`
  - Buttons: `Open in Plane`, `Retry`, `Show Member`.
- PHP commands use `PHP_INI_SCAN_DIR=":/private/tmp/claude-501/-Users-karadigital-Documents-projects-solidtime/ada52bc6-c140-4c3e-b761-ce1774e3839b/scratchpad/phpini" DB_TEST_HOST=127.0.0.1 DB_TEST_DATABASE=solidtime_testing /opt/homebrew/opt/php@8.3/bin/php`, abbreviated below as `$PHP`. Do not modify `.env`.

## Review Focus

1. **XSS through the Plane description.** Inputs like `<script>`, `onerror=`, `javascript:` links or `<iframe>` must not reach `v-html`. Tested in Task 1.
2. **An employee viewing a task of a private project they are not a member of.** Expect 403 with no Plane call, the same visibility rule as the task list. Tested in Task 3.
3. **Rapidly clicking between tasks.** The panel must show the last-clicked task, never a stale response. vue-query keyed by task id handles this. Tested in Task 4 (`shows the detail of the newly selected task`).
4. **Plane rate limit (60/min per key)** when the same task is opened repeatedly. Expect one Plane request per minute per identifier. Tested in Task 2 (`test_caches_the_detail_for_60_seconds`).
5. **Plane down or slow.** Expect a 502 with the "could not be reached" copy and a Retry button. The timer and the rest of the page keep working. Tested in Tasks 3 and 4.

---

### Task 1: Description sanitizer

**Files:**
- Modify: `composer.json`, `composer.lock`. Add `symfony/html-sanitizer` as a direct dependency; it is currently only transitive through filament.
- Create: `app/Service/Plane/PlaneDescriptionSanitizer.php`
- Test: `tests/Unit/Service/Plane/PlaneDescriptionSanitizerTest.php`

**Interfaces:**
- Produces: `PlaneDescriptionSanitizer::sanitize(?string $html): string`. Returns `''` for null or blank input.

- [ ] **Step 1: Add the dependency**

Run: `~/.local/bin/composer require symfony/html-sanitizer:^7.4 --no-interaction` (use brew PHP 8.3 if the composer shim needs it).
Expected: composer.json gets `"symfony/html-sanitizer": "^7.4"`, and the lock changes only for that package's entry and the content-hash.

- [ ] **Step 2: Write the failing tests** (`#[CoversClass(PlaneDescriptionSanitizer::class)]`, extends `Tests\TestCase`)

| Test | Input | Expected |
|---|---|---|
| `test_keeps_basic_formatting` | `<p class="editor-paragraph-block" data-id="x">Hi <strong>you</strong></p><ul><li>a</li></ul>` | contains `<p>Hi <strong>you</strong></p>` and `<li>a</li>`; no `data-id`; no `class` |
| `test_removes_scripts_and_event_handlers` | `<p onclick="x()">a</p><script>alert(1)</script><img src="x" onerror="alert(1)"><iframe src="https://evil"></iframe>` | contains none of `script`, `onclick`, `onerror`, `iframe`, `alert` |
| `test_only_allows_http_links_and_opens_them_in_a_new_tab` | `<a href="javascript:alert(1)">x</a><a href="https://plane.test/a">y</a>` | contains no `javascript:`; the https anchor has `target="_blank"` and `rel="noopener noreferrer"` |
| `test_replaces_images_with_a_placeholder` | `<image-component src="9b76ba18-c388-403f-81a0-ea4e7145eeff" status="uploaded"></image-component>` | equals `<p><em>[Image – open in Plane to view]</em></p>` |
| `test_replaces_mentions_with_text` | `<p>Hi <mention-component entity_identifier="u1" entity_name="user_mention"></mention-component></p>` | equals `<p>Hi @mention</p>` |
| `test_returns_empty_string_for_empty_input` | `null`, `''`, `'<p></p>'` | `''` for null and `''`; `'<p></p>'` sanitizes to `<p></p>` |

- [ ] **Step 3: Run them to see them fail**

Run: `$PHP artisan test tests/Unit/Service/Plane/PlaneDescriptionSanitizerTest.php`
Expected: FAIL, class `PlaneDescriptionSanitizer` not found.

- [ ] **Step 4: Implement `sanitize(?string $html): string`**

1. Pre-process with `preg_replace`:
   - `/<image-component\b[^>]*>(\s*<\/image-component>)?/i` → the placeholder.
   - `/<mention-component\b[^>]*>(\s*<\/mention-component>)?/i` → `@mention`.
2. Run Symfony `HtmlSanitizer` configured as:
   - `(new HtmlSanitizerConfig())->allowSafeElements()`
   - `->allowLinkSchemes(['http', 'https', 'mailto'])`
   - `->forceAttribute('a', 'target', '_blank')`
   - `->forceAttribute('a', 'rel', 'noopener noreferrer')`
   - `->dropAttribute('class', '*')`
   - `->dropAttribute('data-id', '*')`
   - `->allowRelativeLinks(false)`
   - `->withMaxInputLength(200_000)`

Security rests on the sanitizer, not on the regex.

- [ ] **Step 5: Run the tests**

Run: same as Step 3.
Expected: 6 tests PASS. Adjust only the expected strings for harmless serializer formatting such as attribute order, never the security assertions.

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock app/Service/Plane/PlaneDescriptionSanitizer.php tests/Unit/Service/Plane/PlaneDescriptionSanitizerTest.php
git commit -m "feat(plane): sanitize Plane work item descriptions (KARA-48)"
```

### Task 2: Work item detail service

**Files:**
- Modify: `app/Service/Plane/PlaneClient.php` (add the method and `appUrl()`), `config/services.php` (add `'app_url' => env('PLANE_APP_URL')`), `.env.example` (add `PLANE_APP_URL=` with the comment `# Plane web URL for "Open in Plane" links; defaults to PLANE_BASE_URL`)
- Create: `app/Service/Plane/PlaneWorkItemDetailService.php`
- Test: `tests/Unit/Service/Plane/PlaneWorkItemDetailServiceTest.php`

**Interfaces:**
- Consumes: `PlaneDescriptionSanitizer::sanitize()` (Task 1); `PlaneApiException`.
- Produces:
  - `PlaneClient::getWorkItem(string $identifier): ?array<string, mixed>`. Returns the raw decoded JSON of the expand call, null on 403/404, and throws `PlaneApiException` otherwise.
  - `PlaneClient::workItemUrl(string $identifier): string`
  - `PlaneWorkItemDetailService::detail(string $identifier): ?array`. Returns null when the work item is not found. The returned shape is:

```php
array{
    identifier: string, name: string, url: string,
    state: array{name: string, color: string, group: string}|null,
    priority: string,                       // urgent|high|medium|low|none
    assignees: list<array{display_name: string}>,
    labels: list<array{name: string, color: string}>,
    start_date: string|null, target_date: string|null,
    description_html: string,               // sanitized
}
```

- [ ] **Step 1: Write the failing tests**

Configure Plane via `config([...])` in `setUp`, as in `PlaneStatusServiceTest`, with `base_url` `https://plane.test` and slug `kara`. Call `Cache::flush()` in `setUp`.

- `test_returns_the_mapped_detail`
  - Fake `https://plane.test/api/v1/workspaces/kara/work-items/KARA-48/*` with:
    - name `View Detail Ticket`, priority `high`
    - state `{id, name: In Progress, color: #f59e0b, group: started}`
    - assignees `[{id, display_name: akmal, email: a@x.test, first_name: A}]`
    - labels `[{id, name: frontend, color: #00f, project: p}]`
    - `start_date` null, `target_date` `2026-11-20`
    - `description_html` `<p>Hello</p><script>x</script>`
  - Assert the exact array:
    - `url` = `https://plane.test/kara/browse/KARA-48/`
    - `assignees` = `[['display_name' => 'akmal']]`
    - `labels` = `[['name' => 'frontend', 'color' => '#00f']]`
    - `description_html` = `<p>Hello</p>`
  - `Http::assertSent` URL contains `expand=state%2Cassignees%2Clabels` or `expand=state,assignees,labels`. Assert whichever the client produces, and keep it consistent.
- `test_uses_plane_app_url_for_the_link_when_configured`: with `services.plane.app_url` = `http://localhost:3000/`, expect `url` = `http://localhost:3000/kara/browse/KARA-48/`.
- `test_returns_null_when_plane_answers_404` and `test_returns_null_when_plane_answers_403`.
- `test_throws_when_plane_fails`: 500 → `PlaneApiException`.
- `test_caches_the_detail_for_60_seconds`:
  - Call twice: `Http::assertSentCount(1)`.
  - `$this->travel(61)->seconds()`, then call again: `Http::assertSentCount(2)`.
- `test_does_not_cache_failures`: the first response is 500 (exception caught in the test), the second is 200 → `assertSentCount(2)`, and the second call returns data.
- `test_handles_missing_optional_fields`: response `{name, priority: none, state: <uuid string, not expanded>, assignees: [], labels: [], description_html: null}` → `state` null, `description_html` `''`.

- [ ] **Step 2: Run to see them fail**

Run: `$PHP artisan test tests/Unit/Service/Plane/PlaneWorkItemDetailServiceTest.php`
Expected: FAIL, class not found.

- [ ] **Step 3: Implement**

- `getWorkItem` reuses `send()`, `ensureSuccessful()` and `workspaceUrl()`, with query `['expand' => 'state,assignees,labels']`.
- `workItemUrl` = `rtrim(app_url ?: base_url, '/').'/'.slug.'/browse/'.$identifier.'/'`.
- `detail()` uses `Cache::get` / `Cache::put('plane:work-item:'.$identifier, $detail, 60)`. Do not use `remember`, so that a not-found result (null) is still stored: store `['found' => false]` and map it back to null.
- Map `state` only when it is an array.

- [ ] **Step 4: Run the tests**

Run: same as Step 2. Also run `$PHP artisan test tests/Unit/Service/Plane`.
Expected: all PASS, and the KARA-47 tests are still green.

- [ ] **Step 5: Commit**

```bash
git add app/Service/Plane config/services.php .env.example tests/Unit/Service/Plane/PlaneWorkItemDetailServiceTest.php
git commit -m "feat(plane): fetch Plane work item details with a 60s cache (KARA-48)"
```

### Task 3: Task Plane endpoint

**Files:**
- Create: `app/Http/Controllers/Api/V1/TaskPlaneController.php`
- Modify: `routes/api.php`. Add to the `tasks.` group: `Route::get('/tasks/{task}/plane', [TaskPlaneController::class, 'show'])->name('plane');`
- Modify: `resources/js/packages/api/src/openapi.json.client.ts`. Hand-add the alias `getTaskPlaneWorkItem`, placed like `moveTimeEntryTaskToPlaneReview`, with path params `organization` and `task` and a response schema matching the shape below.
- Test: `tests/Unit/Endpoint/Api/V1/TaskPlaneEndpointTest.php`

**Interfaces:**
- Consumes: `PlaneWorkItemDetailService::detail()` (Task 2), `PlaneClient::isEnabledForOrganization()`, `PlaneStatusService::workItemIdentifierFromTaskName()`.
- Produces: `GET /api/v1/organizations/{organization}/tasks/{task}/plane` → `200 {data: <detail shape from Task 2>}`, plus these errors:
  - 403 (authorization)
  - 404 `{message: "This work item was not found in Plane."}`
  - 422 `{message: "This task is not linked to a Plane work item."}`
  - 502 `{message: "Plane could not be reached. Please try again later."}`
  - 503 `{message: "The Plane integration is not enabled for this organization."}`
- Frontend: `api.getTaskPlaneWorkItem({ params: { organization, task } })`.

- [ ] **Step 1: Write the failing tests**

Extend `ApiEndpointTestAbstract`, following the pattern in `TimeEntryPlaneEndpointTest`. Enable Plane for `$data->organization`, Http-fake Plane, and call `Cache::flush()`.

- `test_returns_the_work_item_detail`: user with `tasks:view` and `tasks:view:all`, task `[KARA-48] View detail` → 200, `data.identifier` `KARA-48`, `data.state.name`.
- `test_employee_cannot_view_a_task_of_a_private_project_they_are_not_member_of`:
  - Permissions `tasks:view` only.
  - The project is private and the user is not a project member.
  - Expect 403 and `Http::assertNothingSent()`.
- `test_employee_can_view_a_task_of_a_project_they_are_member_of`: 200.
- `test_task_of_another_organization_is_forbidden`: 403.
- `test_requires_authentication`: 401.
- `test_returns_503_when_plane_is_disabled_for_the_organization`: config `organization_id` set to another org id → 503 and nothing sent.
- `test_returns_422_for_tasks_without_identifier`: task `Finish migration` → 422 and nothing sent.
- `test_returns_404_when_the_work_item_is_not_found`: Plane 403 → 404.
- `test_returns_502_when_plane_fails`: Plane 500 → 502, and `Log::spy()` received a `warning` with `'Plane: could not load work item'`.

- [ ] **Step 2: Run to see them fail**

Run: `$PHP artisan test tests/Unit/Endpoint/Api/V1/TaskPlaneEndpointTest.php`
Expected: FAIL with 404 route-not-found responses.

- [ ] **Step 3: Implement `TaskPlaneController::show(Organization $organization, Task $task, PlaneClient $planeClient, PlaneWorkItemDetailService $detailService): JsonResponse`**

1. `checkPermission($organization, 'tasks:view')`.
2. If the task belongs to another org, throw `AuthorizationException`.
3. If the user lacks `tasks:view:all`, require `Task::query()->whereKey($task->getKey())->visibleByEmployee($this->user())->exists()`; otherwise throw `AuthorizationException`.
4. Then 503, then 422, then call the service: catch `PlaneApiException` → log a warning and return 502; on null return 404.
5. Add a docblock with `@operationId getTaskPlaneWorkItem` and a `@response` line, as in `TimeEntryPlaneController`.

- [ ] **Step 4: Run the tests**

Run: same as Step 2.
Expected: 9 PASS.

- [ ] **Step 5: Static checks**

Run:
```
vendor/bin/phpstan analyse app/Http/Controllers/Api/V1/TaskPlaneController.php app/Service/Plane tests/Unit/Endpoint/Api/V1/TaskPlaneEndpointTest.php tests/Unit/Service/Plane --memory-limit=2G
vendor/bin/pint --test app tests
```
Expected: no errors beyond the known "ignored pattern not matched" artefact of partial runs.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/V1/TaskPlaneController.php routes/api.php resources/js/packages/api/src/openapi.json.client.ts tests/Unit/Endpoint/Api/V1/TaskPlaneEndpointTest.php
git commit -m "feat(plane): endpoint to show a task's Plane work item (KARA-48)"
```

### Task 4: Work item detail panel (frontend)

**Files:**
- Create: `resources/js/Components/Common/Plane/PlaneWorkItemPanel.vue`
- Test: `resources/js/Components/Common/Plane/PlaneWorkItemPanel.test.ts`

**Interfaces:**
- Consumes: `api.getTaskPlaneWorkItem` (Task 3), `getPlaneIdentifier()`, `getCurrentOrganizationId()`.
- Produces: `<PlaneWorkItemPanel :task="Task | null" />`. It has no emits.

- [ ] **Step 1: Write the failing tests**

Mount with a real `VueQueryPlugin` and a fresh `QueryClient({ defaultOptions: { queries: { retry: false } } })`. Mock `@/packages/api/src` and `@/utils/useUser` as in `PlaneReviewPrompt.test.ts`.

- `shows a hint when no task is selected`: text `Select a task to see its Plane details.`; api not called.
- `shows that a task without identifier is not linked`: task `Finish migration` → `This task is not linked to a Plane work item.`; api not called.
- `renders the work item`:
  - The API resolves `{data: {...}}` with:
    - identifier `KARA-48`, name `View Detail Ticket`
    - state `{name: 'In Progress', color: '#f59e0b', group: 'started'}`
    - priority `high`
    - assignees `[{display_name: 'akmal'}]`
    - labels `[{name: 'frontend', color: '#00f'}]`
    - `description_html` `<p>Hello <strong>world</strong></p>`
    - url `https://plane.test/kara/browse/KARA-48/`
  - Assert:
    - The text contains `KARA-48`, `View Detail Ticket`, `In Progress`, `high`, `akmal` and `frontend`.
    - `strong` renders "world".
    - The link `Open in Plane` has that href and `target="_blank"`.
    - The api was called with `{ params: { organization: 'org-1', task: 't1' } }`.
- `shows No description for an empty description`.
- `shows the not found message on 404`: reject with `{ response: { status: 404 } }` → `KARA-48 was not found in Plane` and `The work item does not exist, or the Plane bot is not a member of its project.`
- `shows the unreachable message with retry on other errors`:
  - Reject with status 502 → `Plane could not be reached. Please try again later.`
  - Clicking `Retry` calls the api a second time.
- `shows the detail of the newly selected task`:
  - Mount with t1 → resolved.
  - `setProps({ task: t2 })`, where t2 is `[SUBE-12] Other` → the api is called for t2.
  - After flush the text shows SUBE-12 data, not KARA-48.

- [ ] **Step 2: Run to see them fail**

Run: `npx vitest run resources/js/Components/Common/Plane/PlaneWorkItemPanel.test.ts`
Expected: FAIL, component file not found.

- [ ] **Step 3: Implement**

- `useQuery`:
  - `queryKey: computed(() => ['planeWorkItem', getCurrentOrganizationId(), props.task?.id])`
  - `enabled: computed(() => !!props.task && identifier.value !== null)`
  - `staleTime: 60_000`
  - `retry: false`
- Read the error status from `error.response?.status` (Axios/Zodios error).
- Layout:
  - Header: identifier (muted), then name.
  - Meta row: state badge with a color dot from `state.color`, priority, assignees, labels (color dot + name), and `start_date → target_date` when set.
  - `Open in Plane` is a `SecondaryButton` wrapped in `<a target="_blank" rel="noopener noreferrer">`.
  - Description: `<div class="prose prose-sm dark:prose-invert max-w-none" v-html="detail.description_html">`, with `<!-- eslint-disable-next-line vue/no-v-html -->` and a comment noting that the HTML is sanitized server-side by `PlaneDescriptionSanitizer`.
  - Loading: `LoadingSpinner`.

- [ ] **Step 4: Run the tests**

Run: same as Step 2.
Expected: 7 PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/Common/Plane/PlaneWorkItemPanel.vue resources/js/Components/Common/Plane/PlaneWorkItemPanel.test.ts
git commit -m "feat(plane): work item detail panel (KARA-48)"
```

### Task 5: Project page — selectable tasks, panel and members modal

**Files:**
- Modify: `resources/js/Components/Common/Task/TaskTable.vue`, `resources/js/Components/Common/Task/TaskTableRow.vue`
- Create: `resources/js/Components/Common/ProjectMember/ProjectMembersModal.vue`
- Modify: `resources/js/Pages/ProjectShow.vue`
- Test: `resources/js/Components/Common/Task/TaskTable.test.ts`

**Interfaces:**
- Consumes: `PlaneWorkItemPanel` (Task 4), `isPlaneIntegrationEnabled()`, `ProjectMemberTable`, `ProjectMemberCreateModal`.
- Produces:
  - `TaskTable` gets an optional prop `selectedTaskId?: string | null` and an emit `select: [task: Task]`.
  - `TaskTableRow` gets an optional prop `selected?: boolean` and an emit `select`.
  - `ProjectMembersModal` uses `v-model:show` and has props `projectId: string` and `projectMembers: ProjectMember[]`.

- [ ] **Step 1: Write the failing tests** (`TaskTable.test.ts`)

Mock `@/utils/permissions` (all false), `@/utils/billing` (`isAllowedToPerformPremiumAction: () => true`) and `@/utils/useTasks`.

- `emits select when a task name is clicked`: click the button with the task name → `emitted('select')[0]` equals `[task]`.
- `marks the selected row`: with `selectedTaskId: 't1'`, the row button has `aria-current="true"`; other rows have no `aria-current`.

- [ ] **Step 2: Run to see them fail**

Run: `npx vitest run resources/js/Components/Common/Task/TaskTable.test.ts`
Expected: FAIL, no button and no emit.

- [ ] **Step 3: Implement**

- `TaskTableRow`:
  - The name cell becomes `<button type="button" class="text-left truncate hover:underline" :aria-current="selected ? 'true' : undefined" @click="emit('select')">`.
  - When `selected`, add a subtle background (`bg-card-background-active` or the equivalent existing token) on the row cells.
  - `TaskTable` forwards `@select` / `:selected="task.id === selectedTaskId"`.
- `ProjectMembersModal`:
  - `DialogModal` with title `Project Members`.
  - The content has the existing `Add Member` `SecondaryButton` + `ProjectMemberCreateModal`, then `ProjectMemberTable`.
  - The footer has `SecondaryButton` `Close`.
- `ProjectShow.vue`:
  - Remove the right-column Project Members card.
  - In the header next to `Edit Project`, add `SecondaryButton` `Show Member` (`UserGroupIcon`, `v-if="canViewProjectMembers()"`), which opens `ProjectMembersModal`.
  - Add `const selectedTask = ref<Task | null>(null)`.
  - When `isPlaneIntegrationEnabled()`:
    - The grid stays `lg:grid-cols-2`.
    - The right column is `CardTitle title="Ticket Detail"` + `Card` + `<PlaneWorkItemPanel :task="selectedTask">`.
    - `TaskTable` gets `:selected-task-id="selectedTask?.id ?? null" @select="selectedTask = $event"`.
  - Otherwise the task card takes the full width (`grid-cols-1`).

- [ ] **Step 4: Run tests and checks**

Run:
```
npx vitest run
npm run type-check
npx eslint resources/js/Pages/ProjectShow.vue resources/js/Components/Common/Task resources/js/Components/Common/ProjectMember/ProjectMembersModal.vue resources/js/Components/Common/Plane
npx prettier --check <same files>
```
Expected:
- Vitest: all pass (101 previous + 7 + 2 = 110).
- type-check: clean.
- lint and prettier: clean.

- [ ] **Step 5: Manual smoke** (local app on 127.0.0.1:8100, local Plane)

1. Open the SAND project page.
2. Click `[SAND-4] …`. The panel shows the "Backlog" state and the description `solidtime integration should be success`.
3. `Open in Plane` opens `http://localhost:3000/kara-digital/browse/SAND-4/` when `PLANE_APP_URL` is set; otherwise it uses the API host.
4. `Show Member` opens the modal.

- [ ] **Step 6: Commit**

```bash
git add resources/js/Components/Common/Task resources/js/Components/Common/ProjectMember/ProjectMembersModal.vue resources/js/Pages/ProjectShow.vue
git commit -m "feat(plane): show the Plane work item next to the task list (KARA-48)"
```
