<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Api\V1;

use App\Http\Controllers\Api\V1\TimeEntryPlaneController;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(TimeEntryPlaneController::class)]
class TimeEntryPlaneEndpointTest extends ApiEndpointTestAbstract
{
    private const string BASE = 'https://plane.test/api/v1/workspaces/kara';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.plane.api_key' => 'key',
            'services.plane.base_url' => 'https://plane.test',
            'services.plane.workspace_slug' => 'kara',
        ]);
    }

    private function createStoppedEntry(Organization $organization, Member $member, ?string $taskName = '[KARA-47] Auto change'): TimeEntry
    {
        $factory = TimeEntry::factory()->forMember($member)->forOrganization($organization);
        if ($taskName !== null) {
            $project = Project::factory()->forOrganization($organization)->create();
            $task = Task::factory()->forOrganization($organization)->forProject($project)->create(['name' => $taskName]);
            $factory = $factory->forTask($task);
        }

        return $factory->create();
    }

    private function fakePlaneWorkItem(): void
    {
        Http::fake([
            self::BASE.'/work-items/KARA-47/' => Http::response(['id' => 'w1', 'project' => 'p1', 'state' => 's-progress']),
            self::BASE.'/projects/p1/states/' => Http::response(['results' => [
                ['id' => 's-progress', 'name' => 'In Progress', 'group' => 'started'],
                ['id' => 's-review', 'name' => 'In Review', 'group' => 'started'],
            ]]),
            self::BASE.'/projects/p1/work-items/w1/' => Http::response(['id' => 'w1']),
        ]);
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    private function postReview(Organization $organization, TimeEntry $timeEntry): TestResponse
    {
        return $this->postJson(route('api.v1.time-entries.plane-review', [$organization->getKey(), $timeEntry->getKey()]));
    }

    public function test_review_moves_work_item_and_returns_outcome(): void
    {
        // Arrange
        $data = $this->createUserWithPermission(['time-entries:update:own']);
        $timeEntry = $this->createStoppedEntry($data->organization, $data->member);
        $this->fakePlaneWorkItem();
        Passport::actingAs($data->user);

        // Act
        $response = $this->postReview($data->organization, $timeEntry);

        // Assert
        $response->assertOk();
        $response->assertExactJson(['data' => [
            'identifier' => 'KARA-47',
            'outcome' => 'moved',
            'state' => 'In Review',
        ]]);
    }

    public function test_review_returns_not_found_outcome_for_unknown_work_item(): void
    {
        // Arrange
        $data = $this->createUserWithPermission(['time-entries:update:own']);
        $timeEntry = $this->createStoppedEntry($data->organization, $data->member, '[SOPI-293] Old identifier');
        Http::fake([self::BASE.'/work-items/SOPI-293/' => Http::response(['detail' => 'You do not have permission to perform this action.'], 403)]);
        Passport::actingAs($data->user);

        // Act
        $response = $this->postReview($data->organization, $timeEntry);

        // Assert
        $response->assertOk();
        $response->assertJsonPath('data.identifier', 'SOPI-293');
        $response->assertJsonPath('data.outcome', 'work_item_not_found');
        $response->assertJsonPath('data.state', null);
    }

    public function test_review_fails_for_entry_without_plane_task(): void
    {
        // Arrange
        $data = $this->createUserWithPermission(['time-entries:update:own']);
        $withoutTask = $this->createStoppedEntry($data->organization, $data->member, null);
        $withoutIdentifier = $this->createStoppedEntry($data->organization, $data->member, 'Finish migration');
        Http::fake();
        Passport::actingAs($data->user);

        // Act & Assert
        foreach ([$withoutTask, $withoutIdentifier] as $timeEntry) {
            $response = $this->postReview($data->organization, $timeEntry);
            $response->assertStatus(422);
            $response->assertJsonPath('message', 'This time entry has no task linked to a Plane work item.');
        }
        Http::assertNothingSent();
    }

    public function test_review_fails_when_plane_is_not_configured(): void
    {
        // Arrange
        config(['services.plane.api_key' => '']);
        $data = $this->createUserWithPermission(['time-entries:update:own']);
        $timeEntry = $this->createStoppedEntry($data->organization, $data->member);
        Passport::actingAs($data->user);

        // Act
        $response = $this->postReview($data->organization, $timeEntry);

        // Assert
        $response->assertStatus(503);
        $response->assertJsonPath('message', 'The Plane integration is not configured.');
    }

    public function test_review_returns_502_when_plane_errors(): void
    {
        // Arrange
        $data = $this->createUserWithPermission(['time-entries:update:own']);
        $timeEntry = $this->createStoppedEntry($data->organization, $data->member);
        Http::fake([self::BASE.'/work-items/KARA-47/' => Http::response('boom', 500)]);
        Passport::actingAs($data->user);

        // Act
        $response = $this->postReview($data->organization, $timeEntry);

        // Assert
        $response->assertStatus(502);
        $response->assertJsonPath('message', 'Plane could not be reached. Please try again later.');
    }

    public function test_review_fails_for_other_members_entry_without_update_all_permission(): void
    {
        // Arrange
        $data = $this->createUserWithPermission(['time-entries:update:own']);
        $otherMember = Member::factory()->forOrganization($data->organization)->create();
        $timeEntry = $this->createStoppedEntry($data->organization, $otherMember);
        Http::fake();
        Passport::actingAs($data->user);

        // Act
        $response = $this->postReview($data->organization, $timeEntry);

        // Assert
        $response->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_review_fails_for_entry_of_other_organization(): void
    {
        // Arrange
        $data = $this->createUserWithPermission(['time-entries:update:own', 'time-entries:update:all']);
        $otherOrganization = Organization::factory()->create();
        $otherMember = Member::factory()->forOrganization($otherOrganization)->create();
        $timeEntry = $this->createStoppedEntry($otherOrganization, $otherMember);
        Http::fake();
        Passport::actingAs($data->user);

        // Act
        $response = $this->postReview($data->organization, $timeEntry);

        // Assert
        $response->assertForbidden();
        Http::assertNothingSent();
    }
}
