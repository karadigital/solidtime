<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Api\V1;

use App\Http\Controllers\Api\V1\UserTimeEntryController;
use App\Models\TimeEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\UsesClass;

#[UsesClass(UserTimeEntryController::class)]
class UserTimeEntryEndpointTest extends ApiEndpointTestAbstract
{
    public function test_my_active_endpoint_returns_unauthorized_if_user_is_not_logged_in(): void
    {
        // Arrange
        $data = $this->createUserWithPermission();

        // Act
        $response = $this->getJson(route('api.v1.users.time-entries.my-active'));

        // Assert
        $response->assertUnauthorized();
    }

    public function test_my_active_endpoint_returns_current_time_entry_of_logged_in_user(): void
    {
        // Arrange
        $data = $this->createUserWithPermission();
        $activeTimeEntry = TimeEntry::factory()->forMember($data->member)->active()->create();
        $inactiveTimeEntry = TimeEntry::factory()->forMember($data->member)->create();
        Passport::actingAs($data->user);

        // Act
        $response = $this->getJson(route('api.v1.users.time-entries.my-active'));

        // Assert
        $response->assertSuccessful();
        $response->assertJsonPath('data.id', $activeTimeEntry->getKey());
    }

    public function test_my_active_endpoint_returns_the_latest_of_multiple_running_time_entries(): void
    {
        // Arrange
        $data = $this->createUserWithPermission();
        $activeTimeEntry1 = TimeEntry::factory()->forMember($data->member)->active()->start(Carbon::now()->subDay())->create();
        $activeTimeEntry2 = TimeEntry::factory()->forMember($data->member)->active()->start(Carbon::now())->create();
        $logSpy = Log::spy();
        Passport::actingAs($data->user);

        // Act
        $response = $this->getJson(route('api.v1.users.time-entries.my-active'));

        // Assert
        $logSpy->shouldNotHaveReceived('warning');
        $response->assertSuccessful();
        $response->assertJsonPath('data.id', $activeTimeEntry2->getKey());
    }

    public function test_my_active_endpoint_returns_not_found_if_user_has_no_active_time_entry(): void
    {
        // Arrange
        $data = $this->createUserWithPermission();
        $inactiveTimeEntry = TimeEntry::factory()->forMember($data->member)->create();
        Passport::actingAs($data->user);

        // Act
        $response = $this->getJson(route('api.v1.users.time-entries.my-active'));

        // Assert
        $response->assertNotFound();
    }

    public function test_my_running_endpoint_returns_all_running_time_entries_newest_first(): void
    {
        // Arrange
        $data = $this->createUserWithPermission();
        $older = TimeEntry::factory()->forMember($data->member)->active()->start(Carbon::now()->subHours(2))->create();
        $newer = TimeEntry::factory()->forMember($data->member)->active()->start(Carbon::now()->subHour())->create();
        TimeEntry::factory()->forMember($data->member)->create();
        $otherUser = $this->createUserWithPermission();
        TimeEntry::factory()->forMember($otherUser->member)->active()->create();
        Passport::actingAs($data->user);

        // Act
        $response = $this->getJson(route('api.v1.users.time-entries.my-running'));

        // Assert
        $response->assertStatus(200);
        $this->assertSame([$newer->getKey(), $older->getKey()], array_column($response->json('data'), 'id'));
    }

    public function test_my_running_endpoint_returns_an_empty_list_when_nothing_runs(): void
    {
        // Arrange
        $data = $this->createUserWithPermission();
        TimeEntry::factory()->forMember($data->member)->create();
        Passport::actingAs($data->user);

        // Act
        $response = $this->getJson(route('api.v1.users.time-entries.my-running'));

        // Assert
        $response->assertStatus(200);
        $response->assertExactJson(['data' => []]);
    }

    public function test_my_running_endpoint_requires_authentication(): void
    {
        // Act
        $response = $this->getJson(route('api.v1.users.time-entries.my-running'));

        // Assert
        $response->assertUnauthorized();
    }
}
