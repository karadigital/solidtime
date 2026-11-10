<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class TimeEntryAutoStoppedMail extends Mailable
{
    use Queueable, SerializesModels;

    public TimeEntry $timeEntry;

    public User $user;

    /**
     * Create a new message instance.
     */
    public function __construct(TimeEntry $timeEntry, User $user)
    {
        $this->timeEntry = $timeEntry;
        $this->user = $user;
    }

    /**
     * Build the message.
     */
    public function build(): self
    {
        $stoppedAt = $this->timeEntry->end?->copy()->timezone($this->user->timezone);

        return $this->markdown('emails.time-entry-auto-stopped', [
            'dashboardUrl' => URL::route('dashboard'),
            'stoppedAt' => $stoppedAt?->format('H:i'),
            'stoppedOn' => $stoppedAt?->format('l, d.m.Y'),
        ])
            ->subject(__('Your Time Tracker was stopped automatically'));
    }
}
