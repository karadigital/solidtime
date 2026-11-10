<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Plane;

use App\Service\Plane\PlaneApiException;
use App\Service\Plane\PlaneClient;
use App\Service\Plane\PlaneStatusService;
use App\Service\Plane\PlaneTransitionOutcome;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(PlaneStatusService::class)]
#[CoversClass(PlaneClient::class)]
class PlaneStatusServiceTest extends TestCase
{
    private const string BASE = 'https://plane.test/api/v1/workspaces/kara';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.plane.api_key' => 'key',
            'services.plane.base_url' => 'https://plane.test/',
            'services.plane.workspace_slug' => 'kara',
        ]);
    }

    /**
     * @return list<array{id: string, name: string, group: string}>
     */
    private function states(bool $withInReview = true, bool $withReadyForTest = true, string $inProgressName = 'In Progress'): array
    {
        $states = [
            ['id' => 's-todo', 'name' => 'Todo', 'group' => 'unstarted'],
            ['id' => 's-progress', 'name' => $inProgressName, 'group' => 'started'],
            ['id' => 's-done', 'name' => 'Done', 'group' => 'completed'],
            ['id' => 's-cancelled', 'name' => 'Cancelled', 'group' => 'cancelled'],
        ];
        if ($withInReview) {
            $states[] = ['id' => 's-review', 'name' => 'In Review', 'group' => 'started'];
        }
        if ($withReadyForTest) {
            $states[] = ['id' => 's-test', 'name' => 'Ready for Test', 'group' => 'started'];
        }

        return $states;
    }

    /**
     * @param  list<array{id: string, name: string, group: string}>  $states
     */
    private function fakePlane(string $currentStateId, array $states): void
    {
        Http::fake([
            self::BASE.'/work-items/KARA-47/' => Http::response(['id' => 'w1', 'project' => 'p1', 'state' => $currentStateId]),
            self::BASE.'/projects/p1/states/' => Http::response(['results' => $states]),
            self::BASE.'/projects/p1/work-items/w1/' => Http::response(['id' => 'w1']),
        ]);
    }

    private function assertNoPatchSent(): void
    {
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PATCH');
    }

    private function service(): PlaneStatusService
    {
        return app(PlaneStatusService::class);
    }

    public function test_parses_identifier_from_task_name(): void
    {
        $this->assertSame('KARA-47', PlaneStatusService::workItemIdentifierFromTaskName('[KARA-47] Auto change'));
        $this->assertSame('A1B-2', PlaneStatusService::workItemIdentifierFromTaskName('[A1B-2] x'));
        $this->assertSame('SUBE-12', PlaneStatusService::workItemIdentifierFromTaskName('[SUBE-12] Something'));
        $this->assertNull(PlaneStatusService::workItemIdentifierFromTaskName('KARA-47 no brackets'));
        $this->assertNull(PlaneStatusService::workItemIdentifierFromTaskName('[-35] x'));
        $this->assertNull(PlaneStatusService::workItemIdentifierFromTaskName('Finish migration'));
    }

    public function test_is_not_configured_when_a_value_is_missing(): void
    {
        $this->assertTrue(app(PlaneClient::class)->isConfigured());
        config(['services.plane.workspace_slug' => '']);
        $this->assertFalse(app(PlaneClient::class)->isConfigured());
    }

    public function test_moves_to_in_progress(): void
    {
        $this->fakePlane('s-todo', $this->states());

        $result = $this->service()->moveToInProgress('KARA-47');

        $this->assertSame(PlaneTransitionOutcome::Moved, $result->outcome);
        $this->assertSame('In Progress', $result->stateName);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request->url() === self::BASE.'/projects/p1/work-items/w1/'
            && $request['state'] === 's-progress'
            && $request->hasHeader('X-Api-Key', 'key'));
    }

    public function test_matches_state_names_case_insensitively(): void
    {
        $this->fakePlane('s-todo', $this->states(inProgressName: ' in progress '));

        $result = $this->service()->moveToInProgress('KARA-47');

        $this->assertSame(PlaneTransitionOutcome::Moved, $result->outcome);
    }

    public function test_does_not_touch_completed_or_cancelled_work_items(): void
    {
        foreach (['s-done', 's-cancelled'] as $closedState) {
            $this->fakePlane($closedState, $this->states());

            $this->assertSame(PlaneTransitionOutcome::WorkItemClosed, $this->service()->moveToInProgress('KARA-47')->outcome);
            $this->assertSame(PlaneTransitionOutcome::WorkItemClosed, $this->service()->moveToReview('KARA-47')->outcome);
        }
        $this->assertNoPatchSent();
    }

    public function test_does_not_patch_when_already_in_target_state(): void
    {
        $this->fakePlane('s-progress', $this->states());

        $result = $this->service()->moveToInProgress('KARA-47');

        $this->assertSame(PlaneTransitionOutcome::AlreadyInState, $result->outcome);
        $this->assertSame('In Progress', $result->stateName);
        $this->assertNoPatchSent();
    }

    public function test_review_prefers_in_review(): void
    {
        $this->fakePlane('s-progress', $this->states());

        $result = $this->service()->moveToReview('KARA-47');

        $this->assertSame(PlaneTransitionOutcome::Moved, $result->outcome);
        $this->assertSame('In Review', $result->stateName);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && $request['state'] === 's-review');
    }

    public function test_review_falls_back_to_ready_for_test(): void
    {
        $this->fakePlane('s-progress', $this->states(withInReview: false));

        $result = $this->service()->moveToReview('KARA-47');

        $this->assertSame(PlaneTransitionOutcome::Moved, $result->outcome);
        $this->assertSame('Ready for Test', $result->stateName);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && $request['state'] === 's-test');
    }

    public function test_review_does_nothing_when_no_review_state_exists(): void
    {
        $this->fakePlane('s-progress', $this->states(withInReview: false, withReadyForTest: false));

        $result = $this->service()->moveToReview('KARA-47');

        $this->assertSame(PlaneTransitionOutcome::StateNotFound, $result->outcome);
        $this->assertNull($result->stateName);
        $this->assertNoPatchSent();
    }

    public function test_returns_work_item_not_found_on_404(): void
    {
        Http::fake([
            self::BASE.'/work-items/SOPI-293/' => Http::response(['error' => 'not found'], 404),
        ]);

        $result = $this->service()->moveToReview('SOPI-293');

        $this->assertSame(PlaneTransitionOutcome::WorkItemNotFound, $result->outcome);
        Http::assertSentCount(1);
    }

    public function test_returns_work_item_not_found_on_403_for_unknown_project_identifier(): void
    {
        // Plane answers 403 (not 404) when the project identifier is unknown (f.e. an old one) or the bot is no member
        Http::fake([
            self::BASE.'/work-items/SOPI-293/' => Http::response(['detail' => 'You do not have permission to perform this action.'], 403),
        ]);

        $result = $this->service()->moveToReview('SOPI-293');

        $this->assertSame(PlaneTransitionOutcome::WorkItemNotFound, $result->outcome);
        Http::assertSentCount(1);
    }

    public function test_throws_plane_api_exception_on_server_error(): void
    {
        Http::fake([
            self::BASE.'/work-items/KARA-47/' => Http::response('boom', 500),
        ]);

        $this->expectException(PlaneApiException::class);

        $this->service()->moveToInProgress('KARA-47');
    }
}
