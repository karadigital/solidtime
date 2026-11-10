<?php

declare(strict_types=1);

namespace Tests\Unit\Observers;

use App\Jobs\MovePlaneWorkItemToInProgress;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Observers\TimeEntryPlaneObserver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCaseWithDatabase;

#[CoversClass(TimeEntryPlaneObserver::class)]
class TimeEntryPlaneObserverTest extends TestCaseWithDatabase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.plane.api_key' => 'key',
            'services.plane.base_url' => 'https://plane.test',
            'services.plane.workspace_slug' => 'kara',
        ]);
        Bus::fake([MovePlaneWorkItemToInProgress::class]);
    }

    private function createTask(Organization $organization, string $name = '[KARA-47] Auto change'): Task
    {
        $project = Project::factory()->forOrganization($organization)->create();

        return Task::factory()->forOrganization($organization)->forProject($project)->create(['name' => $name]);
    }

    private function assertDispatchedFor(string $identifier, int $times = 1): void
    {
        Bus::assertDispatchedAfterResponse(
            MovePlaneWorkItemToInProgress::class,
            fn (MovePlaneWorkItemToInProgress $job): bool => $job->identifier === $identifier
        );
        Bus::assertDispatchedAfterResponseTimes(MovePlaneWorkItemToInProgress::class, $times);
    }

    public function test_dispatches_when_a_running_entry_is_created_on_a_plane_task(): void
    {
        // Arrange
        $data = $this->createUserWithPermission();
        $task = $this->createTask($data->organization);

        // Act
        TimeEntry::factory()->forMember($data->member)->forTask($task)->active()->create();

        // Assert
        $this->assertDispatchedFor('KARA-47');
    }

    public function test_dispatches_for_any_plane_project_prefix(): void
    {
        // Arrange
        $data = $this->createUserWithPermission();
        $task = $this->createTask($data->organization, '[SUBE-12] Something');

        // Act
        TimeEntry::factory()->forMember($data->member)->forTask($task)->active()->create();

        // Assert
        $this->assertDispatchedFor('SUBE-12');
    }

    public function test_dispatches_when_task_is_added_to_a_running_entry(): void
    {
        // Arrange
        $data = $this->createUserWithPermission();
        $task = $this->createTask($data->organization);
        $timeEntry = TimeEntry::factory()->forMember($data->member)->active()->create();
        Bus::assertNotDispatchedAfterResponse(MovePlaneWorkItemToInProgress::class);

        // Act
        $timeEntry->task_id = $task->getKey();
        $timeEntry->project_id = $task->project_id;
        $timeEntry->save();

        // Assert
        $this->assertDispatchedFor('KARA-47');
    }

    public function test_does_not_dispatch_when_other_fields_of_a_running_entry_change(): void
    {
        // Arrange
        $data = $this->createUserWithPermission();
        $task = $this->createTask($data->organization);
        $timeEntry = TimeEntry::factory()->forMember($data->member)->forTask($task)->active()->create();

        // Act
        $timeEntry->description = 'Changed description';
        $timeEntry->save();

        // Assert
        $this->assertDispatchedFor('KARA-47', 1);
    }

    public function test_does_not_dispatch_for_stopped_entries(): void
    {
        // Arrange
        $data = $this->createUserWithPermission();
        $task = $this->createTask($data->organization);

        // Act
        TimeEntry::factory()->forMember($data->member)->forTask($task)->create([
            'start' => Carbon::now()->subHour(),
            'end' => Carbon::now(),
        ]);

        // Assert
        Bus::assertNotDispatchedAfterResponse(MovePlaneWorkItemToInProgress::class);
    }

    public function test_does_not_dispatch_for_tasks_without_identifier(): void
    {
        // Arrange
        $data = $this->createUserWithPermission();
        $task = $this->createTask($data->organization, 'Finish migration');

        // Act
        TimeEntry::factory()->forMember($data->member)->forTask($task)->active()->create();

        // Assert
        Bus::assertNotDispatchedAfterResponse(MovePlaneWorkItemToInProgress::class);
    }

    public function test_does_not_dispatch_when_plane_is_not_configured(): void
    {
        // Arrange
        config(['services.plane.api_key' => '']);
        $data = $this->createUserWithPermission();
        $task = $this->createTask($data->organization);

        // Act
        TimeEntry::factory()->forMember($data->member)->forTask($task)->active()->create();

        // Assert
        Bus::assertNotDispatchedAfterResponse(MovePlaneWorkItemToInProgress::class);
    }

    public function test_starting_a_timer_does_not_call_plane_during_the_request(): void
    {
        // Arrange
        Http::fake();
        $data = $this->createUserWithPermission([
            'time-entries:create:own',
            'projects:view:all',
        ]);
        $task = $this->createTask($data->organization);
        Passport::actingAs($data->user);

        // Act
        $response = $this->postJson(route('api.v1.time-entries.store', [$data->organization->getKey()]), [
            'billable' => false,
            'start' => Carbon::now()->subMinute()->toIso8601ZuluString(),
            'member_id' => $data->member->getKey(),
            'project_id' => $task->project_id,
            'task_id' => $task->getKey(),
        ]);

        // Assert
        $response->assertStatus(201);
        Http::assertNothingSent();
        $this->assertDispatchedFor('KARA-47');
    }
}
