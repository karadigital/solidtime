<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\MovePlaneWorkItemToInProgress;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(MovePlaneWorkItemToInProgress::class)]
class MovePlaneWorkItemToInProgressTest extends TestCase
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

    public function test_job_moves_work_item_to_in_progress(): void
    {
        // Arrange
        Http::fake([
            self::BASE.'/work-items/KARA-47/' => Http::response(['id' => 'w1', 'project' => 'p1', 'state' => 's-todo']),
            self::BASE.'/projects/p1/states/' => Http::response(['results' => [
                ['id' => 's-todo', 'name' => 'Todo', 'group' => 'unstarted'],
                ['id' => 's-progress', 'name' => 'In Progress', 'group' => 'started'],
            ]]),
            self::BASE.'/projects/p1/work-items/w1/' => Http::response(['id' => 'w1']),
        ]);

        // Act
        app()->call([new MovePlaneWorkItemToInProgress('KARA-47'), 'handle']);

        // Assert
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH' && $request['state'] === 's-progress');
    }

    public function test_job_logs_and_swallows_plane_errors(): void
    {
        // Arrange
        Http::fake([
            self::BASE.'/work-items/KARA-47/' => Http::response('boom', 500),
        ]);
        $logSpy = Log::spy();

        // Act
        app()->call([new MovePlaneWorkItemToInProgress('KARA-47'), 'handle']);

        // Assert
        $logSpy->shouldHaveReceived('warning', ['Plane: could not move work item to In Progress', \Mockery::type('array')]);
    }
}
