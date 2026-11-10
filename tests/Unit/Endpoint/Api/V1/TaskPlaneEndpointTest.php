<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Api\V1;

use App\Http\Controllers\Api\V1\TaskPlaneController;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(TaskPlaneController::class)]
class TaskPlaneEndpointTest extends ApiEndpointTestAbstract
{
    private const string WORK_ITEM_URL = 'https://plane.test/api/v1/workspaces/kara/work-items/KARA-48/*';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.plane.api_key' => 'key',
            'services.plane.base_url' => 'https://plane.test',
            'services.plane.workspace_slug' => 'kara',
        ]);
        Cache::flush();
    }

    private function enablePlaneFor(Organization $organization): void
    {
        config(['services.plane.organization_id' => $organization->getKey()]);
    }

    private function createTask(Organization $organization, string $name = '[KARA-48] View detail', ?Project $project = null): Task
    {
        $project ??= Project::factory()->forOrganization($organization)->isPublic()->create();

        return Task::factory()->forOrganization($organization)->forProject($project)->create(['name' => $name]);
    }

    private function fakePlane(int $status = 200): void
    {
        Http::fake([self::WORK_ITEM_URL => Http::response([
            'id' => 'w1',
            'name' => 'View Detail Ticket',
            'priority' => 'high',
            'state' => ['id' => 's1', 'name' => 'In Progress', 'color' => '#f59e0b', 'group' => 'started'],
            'assignees' => [],
            'labels' => [],
            'description_html' => '<p>Hello</p>',
        ], $status)]);
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    private function getPlane(Organization $organization, Task $task): TestResponse
    {
        return $this->getJson(route('api.v1.tasks.plane', [$organization->getKey(), $task->getKey()]));
    }

    public function test_returns_the_work_item_detail(): void
    {
        // Arrange
        $data = $this->createUserWithPermission(['tasks:view', 'tasks:view:all']);
        $this->enablePlaneFor($data->organization);
        $task = $this->createTask($data->organization);
        $this->fakePlane();
        Passport::actingAs($data->user);

        // Act
        $response = $this->getPlane($data->organization, $task);

        // Assert
        $response->assertStatus(200);
        $response->assertJsonPath('data.identifier', 'KARA-48');
        $response->assertJsonPath('data.name', 'View Detail Ticket');
        $response->assertJsonPath('data.state.name', 'In Progress');
        $response->assertJsonPath('data.description_html', '<p>Hello</p>');
        $response->assertJsonPath('data.url', 'https://plane.test/kara/browse/KARA-48/');
    }

    public function test_employee_cannot_view_a_task_of_a_private_project_they_are_not_member_of(): void
    {
        // Arrange
        $data = $this->createUserWithPermission(['tasks:view']);
        $this->enablePlaneFor($data->organization);
        $privateProject = Project::factory()->forOrganization($data->organization)->isPrivate()->create();
        $task = $this->createTask($data->organization, project: $privateProject);
        Http::fake();
        Passport::actingAs($data->user);

        // Act
        $response = $this->getPlane($data->organization, $task);

        // Assert
        $response->assertStatus(403);
        Http::assertNothingSent();
    }

    public function test_employee_can_view_a_task_of_a_project_they_are_member_of(): void
    {
        // Arrange
        $data = $this->createUserWithPermission(['tasks:view']);
        $this->enablePlaneFor($data->organization);
        $privateProject = Project::factory()->forOrganization($data->organization)->isPrivate()->create();
        ProjectMember::factory()->forProject($privateProject)->forMember($data->member)->create();
        $task = $this->createTask($data->organization, project: $privateProject);
        $this->fakePlane();
        Passport::actingAs($data->user);

        // Act
        $response = $this->getPlane($data->organization, $task);

        // Assert
        $response->assertStatus(200);
        $response->assertJsonPath('data.identifier', 'KARA-48');
    }

    public function test_task_of_another_organization_is_forbidden(): void
    {
        // Arrange
        $data = $this->createUserWithPermission(['tasks:view', 'tasks:view:all']);
        $this->enablePlaneFor($data->organization);
        $otherTask = $this->createTask(Organization::factory()->create());
        Http::fake();
        Passport::actingAs($data->user);

        // Act
        $response = $this->getPlane($data->organization, $otherTask);

        // Assert
        $response->assertStatus(403);
        Http::assertNothingSent();
    }

    public function test_requires_authentication(): void
    {
        // Arrange
        $data = $this->createUserWithPermission(['tasks:view', 'tasks:view:all']);
        $task = $this->createTask($data->organization);

        // Act
        $response = $this->getPlane($data->organization, $task);

        // Assert
        $response->assertStatus(401);
    }

    public function test_returns_503_when_plane_is_disabled_for_the_organization(): void
    {
        // Arrange
        $data = $this->createUserWithPermission(['tasks:view', 'tasks:view:all']);
        $this->enablePlaneFor(Organization::factory()->create());
        $task = $this->createTask($data->organization);
        Http::fake();
        Passport::actingAs($data->user);

        // Act
        $response = $this->getPlane($data->organization, $task);

        // Assert
        $response->assertStatus(503);
        $response->assertJsonPath('message', 'The Plane integration is not enabled for this organization.');
        Http::assertNothingSent();
    }

    public function test_returns_422_for_tasks_without_identifier(): void
    {
        // Arrange
        $data = $this->createUserWithPermission(['tasks:view', 'tasks:view:all']);
        $this->enablePlaneFor($data->organization);
        $task = $this->createTask($data->organization, 'Finish migration');
        Http::fake();
        Passport::actingAs($data->user);

        // Act
        $response = $this->getPlane($data->organization, $task);

        // Assert
        $response->assertStatus(422);
        $response->assertJsonPath('message', 'This task is not linked to a Plane work item.');
        Http::assertNothingSent();
    }

    public function test_returns_404_when_the_work_item_is_not_found(): void
    {
        // Arrange
        $data = $this->createUserWithPermission(['tasks:view', 'tasks:view:all']);
        $this->enablePlaneFor($data->organization);
        $task = $this->createTask($data->organization);
        $this->fakePlane(403);
        Passport::actingAs($data->user);

        // Act
        $response = $this->getPlane($data->organization, $task);

        // Assert
        $response->assertStatus(404);
        $response->assertJsonPath('message', 'This work item was not found in Plane.');
    }

    public function test_returns_502_when_plane_fails(): void
    {
        // Arrange
        $data = $this->createUserWithPermission(['tasks:view', 'tasks:view:all']);
        $this->enablePlaneFor($data->organization);
        $task = $this->createTask($data->organization);
        $this->fakePlane(500);
        $logSpy = Log::spy();
        Passport::actingAs($data->user);

        // Act
        $response = $this->getPlane($data->organization, $task);

        // Assert
        $response->assertStatus(502);
        $response->assertJsonPath('message', 'Plane could not be reached. Please try again later.');
        $logSpy->shouldHaveReceived('warning', ['Plane: could not load work item', \Mockery::type('array')]);
    }
}
