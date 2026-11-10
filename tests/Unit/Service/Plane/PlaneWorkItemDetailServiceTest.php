<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Plane;

use App\Service\Plane\PlaneApiException;
use App\Service\Plane\PlaneClient;
use App\Service\Plane\PlaneWorkItemDetailService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(PlaneWorkItemDetailService::class)]
#[CoversClass(PlaneClient::class)]
class PlaneWorkItemDetailServiceTest extends TestCase
{
    private const string URL = 'https://plane.test/api/v1/workspaces/kara/work-items/KARA-48/*';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.plane.api_key' => 'key',
            'services.plane.base_url' => 'https://plane.test',
            'services.plane.workspace_slug' => 'kara',
            'services.plane.app_url' => null,
        ]);
        Cache::flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function workItem(): array
    {
        return [
            'id' => 'w1',
            'name' => 'View Detail Ticket',
            'priority' => 'high',
            'state' => ['id' => 's1', 'name' => 'In Progress', 'color' => '#f59e0b', 'group' => 'started'],
            'assignees' => [['id' => 'u1', 'display_name' => 'akmal', 'email' => 'a@x.test', 'first_name' => 'A']],
            'labels' => [['id' => 'l1', 'name' => 'frontend', 'color' => '#00f', 'project' => 'p']],
            'start_date' => null,
            'target_date' => '2026-11-20',
            'description_html' => '<p>Hello</p><script>x</script>',
        ];
    }

    private function service(): PlaneWorkItemDetailService
    {
        return app(PlaneWorkItemDetailService::class);
    }

    public function test_returns_the_mapped_detail(): void
    {
        // Arrange
        Http::fake([self::URL => Http::response($this->workItem())]);

        // Act
        $detail = $this->service()->detail('KARA-48');

        // Assert
        $this->assertSame([
            'identifier' => 'KARA-48',
            'name' => 'View Detail Ticket',
            'url' => 'https://plane.test/kara/browse/KARA-48/',
            'state' => ['name' => 'In Progress', 'color' => '#f59e0b', 'group' => 'started'],
            'priority' => 'high',
            'assignees' => [['display_name' => 'akmal']],
            'labels' => [['name' => 'frontend', 'color' => '#00f']],
            'start_date' => null,
            'target_date' => '2026-11-20',
            'description_html' => '<p>Hello</p>',
        ], $detail);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://plane.test/api/v1/workspaces/kara/work-items/KARA-48/?expand=state%2Cassignees%2Clabels'
            && $request->hasHeader('X-Api-Key', 'key'));
    }

    public function test_uses_plane_app_url_for_the_link_when_configured(): void
    {
        // Arrange
        config(['services.plane.app_url' => 'http://localhost:3000/']);
        Http::fake([self::URL => Http::response($this->workItem())]);

        // Act
        $detail = $this->service()->detail('KARA-48');

        // Assert
        $this->assertSame('http://localhost:3000/kara/browse/KARA-48/', $detail['url'] ?? null);
    }

    public function test_returns_null_when_plane_answers_404(): void
    {
        Http::fake([self::URL => Http::response(['error' => 'not found'], 404)]);

        $this->assertNull($this->service()->detail('KARA-48'));
    }

    public function test_returns_null_when_plane_answers_403(): void
    {
        Http::fake([self::URL => Http::response(['error' => 'forbidden'], 403)]);

        $this->assertNull($this->service()->detail('KARA-48'));
    }

    public function test_throws_when_plane_fails(): void
    {
        Http::fake([self::URL => Http::response(['error' => 'boom'], 500)]);

        $this->expectException(PlaneApiException::class);

        $this->service()->detail('KARA-48');
    }

    public function test_caches_the_detail_for_60_seconds(): void
    {
        // Arrange
        Http::fake([self::URL => Http::response($this->workItem())]);

        // Act & Assert
        $this->service()->detail('KARA-48');
        $this->service()->detail('KARA-48');
        Http::assertSentCount(1);

        $this->travel(61)->seconds();
        $this->service()->detail('KARA-48');
        Http::assertSentCount(2);
    }

    public function test_caches_not_found_results(): void
    {
        // Arrange
        Http::fake([self::URL => Http::response(['error' => 'not found'], 404)]);

        // Act
        $first = $this->service()->detail('KARA-48');
        $second = $this->service()->detail('KARA-48');

        // Assert
        $this->assertNull($first);
        $this->assertNull($second);
        Http::assertSentCount(1);
    }

    public function test_does_not_cache_failures(): void
    {
        // Arrange
        Http::fake([self::URL => Http::sequence()
            ->push(['error' => 'boom'], 500)
            ->push($this->workItem()),
        ]);

        // Act
        try {
            $this->service()->detail('KARA-48');
            $this->fail('Expected PlaneApiException');
        } catch (PlaneApiException) {
            // expected
        }
        $detail = $this->service()->detail('KARA-48');

        // Assert
        Http::assertSentCount(2);
        $this->assertSame('View Detail Ticket', $detail['name'] ?? null);
    }

    public function test_handles_missing_optional_fields(): void
    {
        // Arrange
        Http::fake([self::URL => Http::response([
            'name' => 'Bare',
            'priority' => 'none',
            'state' => '5f0c1d9e-0000-0000-0000-000000000000',
            'assignees' => [],
            'labels' => [],
            'description_html' => null,
        ])]);

        // Act
        $detail = $this->service()->detail('KARA-48');

        // Assert
        $this->assertNotNull($detail);
        $this->assertNull($detail['state']);
        $this->assertSame('', $detail['description_html']);
        $this->assertSame([], $detail['assignees']);
        $this->assertNull($detail['start_date']);
        $this->assertNull($detail['target_date']);
    }
}
