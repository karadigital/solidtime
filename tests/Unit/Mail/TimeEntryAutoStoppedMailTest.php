<?php

declare(strict_types=1);

namespace Tests\Unit\Mail;

use App\Mail\TimeEntryAutoStoppedMail;
use App\Models\TimeEntry;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCaseWithDatabase;

#[CoversClass(TimeEntryAutoStoppedMail::class)]
class TimeEntryAutoStoppedMailTest extends TestCaseWithDatabase
{
    public function test_mail_renders_description_and_stop_time_in_user_timezone(): void
    {
        // Arrange
        $user = $this->createUserWithPermission();
        $user->user->timezone = 'Asia/Jakarta';
        $user->user->save();
        $timeEntry = TimeEntry::factory()->forMember($user->member)->create([
            'description' => 'TEST 123',
            'start' => Carbon::parse('2026-11-11 02:00:00', 'UTC'),
            'end' => Carbon::parse('2026-11-11 10:30:00', 'UTC'),
        ]);
        $mail = new TimeEntryAutoStoppedMail($timeEntry, $user->user);

        // Act
        $rendered = $mail->render();

        // Assert
        $this->assertStringContainsString('"TEST 123" was stopped automatically at 17:30 on Wednesday, 11.11.2026', $rendered);
    }

    public function test_mail_renders_without_description(): void
    {
        // Arrange
        $user = $this->createUserWithPermission();
        $user->user->timezone = 'Asia/Jakarta';
        $user->user->save();
        $timeEntry = TimeEntry::factory()->forMember($user->member)->create([
            'description' => '',
            'start' => Carbon::parse('2026-11-11 02:00:00', 'UTC'),
            'end' => Carbon::parse('2026-11-11 10:30:00', 'UTC'),
        ]);
        $mail = new TimeEntryAutoStoppedMail($timeEntry, $user->user);

        // Act
        $rendered = $mail->render();

        // Assert
        $this->assertStringContainsString('Your running time entry was stopped automatically at 17:30', $rendered);
    }
}
