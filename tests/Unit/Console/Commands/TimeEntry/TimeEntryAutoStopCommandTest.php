<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\TimeEntry;

use App\Console\Commands\TimeEntry\TimeEntryAutoStopCommand;
use App\Jobs\RecalculateSpentTimeForProject;
use App\Jobs\RecalculateSpentTimeForTask;
use App\Mail\TimeEntryAutoStoppedMail;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCaseWithDatabase;

#[CoversClass(TimeEntryAutoStopCommand::class)]
class TimeEntryAutoStopCommandTest extends TestCaseWithDatabase
{
    /**
     * @return object{user: User, member: Member, organization: Organization}
     */
    private function createAutoStopUser(bool $enabled = true, string $stopTime = '17:30', string $timezone = 'Asia/Jakarta'): object
    {
        $data = $this->createUserWithPermission();
        $data->user->timezone = $timezone;
        $data->user->auto_stop_time_entries = $enabled;
        $data->user->auto_stop_time = $stopTime;
        $data->user->save();

        return $data;
    }

    private function createRunningEntry(Member $member, string $localStart, string $timezone = 'Asia/Jakarta'): TimeEntry
    {
        return TimeEntry::factory()->forMember($member)->active()->create([
            'start' => Carbon::parse($localStart, $timezone)->utc(),
        ]);
    }

    private function runCommand(string $localNow, string $timezone = 'Asia/Jakarta', bool $dryRun = false): int
    {
        // The test now must be in UTC (the app timezone), otherwise Carbon reads DB datetimes in the test now's timezone
        Carbon::setTestNow(Carbon::parse($localNow, $timezone)->utc());

        $exitCode = $this->withoutMockingConsoleOutput()->artisan('time-entry:auto-stop', $dryRun ? ['--dry-run' => true] : []);
        $this->assertIsInt($exitCode);

        return $exitCode;
    }

    public function test_stops_running_entry_at_stop_time_and_mails_user(): void
    {
        // Arrange
        $data = $this->createAutoStopUser();
        $timeEntry = $this->createRunningEntry($data->member, '2026-11-11 09:00:00');

        // Act
        $exitCode = $this->runCommand('2026-11-11 17:31:00');

        // Assert
        $timeEntry->refresh();
        $this->assertNotNull($timeEntry->end);
        $this->assertTrue($timeEntry->end->equalTo(Carbon::parse('2026-11-11 10:30:00', 'UTC')));
        Mail::assertQueued(TimeEntryAutoStoppedMail::class, function (TimeEntryAutoStoppedMail $mail) use ($data, $timeEntry): bool {
            return $mail->hasTo($data->user->email) && $mail->timeEntry->is($timeEntry) && $mail->user->is($data->user);
        });
        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertSame("Auto stopping running time entries...\n".
            'Stopping time entry '.$timeEntry->getKey().' of user "'.$data->user->email.'" ('.$data->user->getKey().") at 2026-11-11T10:30:00Z\n".
            "Finished auto stopping 1 time entries...\n", Artisan::output());
    }

    public function test_does_not_stop_before_stop_time(): void
    {
        // Arrange
        $data = $this->createAutoStopUser();
        $timeEntry = $this->createRunningEntry($data->member, '2026-11-11 09:00:00');

        // Act
        $this->runCommand('2026-11-11 17:29:00');

        // Assert
        $this->assertNull($timeEntry->refresh()->end);
        Mail::assertNothingOutgoing();
    }

    public function test_does_not_stop_when_user_has_auto_stop_disabled(): void
    {
        // Arrange
        $data = $this->createAutoStopUser(enabled: false);
        $timeEntry = $this->createRunningEntry($data->member, '2026-11-11 09:00:00');

        // Act
        $this->runCommand('2026-11-11 20:00:00');

        // Assert
        $this->assertNull($timeEntry->refresh()->end);
        Mail::assertNothingOutgoing();
    }

    public function test_never_stops_entry_started_after_stop_time(): void
    {
        // Arrange
        $data = $this->createAutoStopUser();
        $timeEntry = $this->createRunningEntry($data->member, '2026-11-11 18:00:00');

        // Act
        $this->runCommand('2026-11-12 20:00:00');

        // Assert
        $this->assertNull($timeEntry->refresh()->end);
        Mail::assertNothingOutgoing();
    }

    public function test_stops_entry_forgotten_over_the_weekend_at_the_stop_time_of_its_start_day(): void
    {
        // Arrange
        $data = $this->createAutoStopUser();
        $timeEntry = $this->createRunningEntry($data->member, '2026-11-13 09:00:00');

        // Act
        $this->runCommand('2026-11-16 08:00:00');

        // Assert
        $timeEntry->refresh();
        $this->assertNotNull($timeEntry->end);
        $this->assertTrue($timeEntry->end->equalTo(Carbon::parse('2026-11-13 10:30:00', 'UTC')));
        Mail::assertQueued(TimeEntryAutoStoppedMail::class, 1);
    }

    public function test_does_not_stop_entry_started_on_weekend(): void
    {
        // Arrange
        $data = $this->createAutoStopUser();
        $timeEntry = $this->createRunningEntry($data->member, '2026-11-14 09:00:00');

        // Act
        $this->runCommand('2026-11-14 18:00:00');

        // Assert
        $this->assertNull($timeEntry->refresh()->end);
        Mail::assertNothingOutgoing();
    }

    public function test_uses_custom_stop_time_and_user_timezone(): void
    {
        // Arrange
        $data = $this->createAutoStopUser(stopTime: '16:00', timezone: 'Europe/Vienna');
        $timeEntry = $this->createRunningEntry($data->member, '2026-11-11 08:00:00', 'Europe/Vienna');

        // Act
        $this->runCommand('2026-11-11 16:05:00', 'Europe/Vienna');

        // Assert
        $timeEntry->refresh();
        $this->assertNotNull($timeEntry->end);
        $this->assertTrue($timeEntry->end->equalTo(Carbon::parse('2026-11-11 15:00:00', 'UTC')));
    }

    public function test_does_not_stop_entries_of_placeholder_users(): void
    {
        // Arrange
        $data = $this->createAutoStopUser();
        $data->user->is_placeholder = true;
        $data->user->save();
        $timeEntry = $this->createRunningEntry($data->member, '2026-11-11 09:00:00');

        // Act
        $this->runCommand('2026-11-11 17:31:00');

        // Assert
        $this->assertNull($timeEntry->refresh()->end);
        Mail::assertNothingOutgoing();
    }

    public function test_running_twice_stops_and_mails_only_once(): void
    {
        // Arrange
        $data = $this->createAutoStopUser();
        $this->createRunningEntry($data->member, '2026-11-11 09:00:00');
        $this->runCommand('2026-11-11 17:31:00');

        // Act
        $this->runCommand('2026-11-11 17:32:00');

        // Assert
        Mail::assertQueued(TimeEntryAutoStoppedMail::class, 1);
        $this->assertSame("Auto stopping running time entries...\n".
            "Finished auto stopping 0 time entries...\n", Artisan::output());
    }

    public function test_dispatches_spent_time_recalculation_for_project_and_task(): void
    {
        // Arrange
        Queue::fake();
        $data = $this->createAutoStopUser();
        $project = Project::factory()->forOrganization($data->organization)->create();
        $task = Task::factory()->forOrganization($data->organization)->forProject($project)->create();
        TimeEntry::factory()->forMember($data->member)->forProject($project)->forTask($task)->active()->create([
            'start' => Carbon::parse('2026-11-11 09:00:00', 'Asia/Jakarta')->utc(),
        ]);

        // Act
        $this->runCommand('2026-11-11 17:31:00');

        // Assert
        Queue::assertPushed(RecalculateSpentTimeForProject::class);
        Queue::assertPushed(RecalculateSpentTimeForTask::class);
    }

    public function test_dry_run_does_not_stop_or_mail(): void
    {
        // Arrange
        $data = $this->createAutoStopUser();
        $timeEntry = $this->createRunningEntry($data->member, '2026-11-11 09:00:00');

        // Act
        $this->runCommand('2026-11-11 17:31:00', dryRun: true);

        // Assert
        $this->assertNull($timeEntry->refresh()->end);
        Mail::assertNothingOutgoing();
        $this->assertStringContainsString('Stopping time entry '.$timeEntry->getKey(), Artisan::output());
    }
}
