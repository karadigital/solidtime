<?php

declare(strict_types=1);

namespace App\Console\Commands\TimeEntry;

use App\Jobs\RecalculateSpentTimeForProject;
use App\Jobs\RecalculateSpentTimeForTask;
use App\Mail\TimeEntryAutoStoppedMail;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

class TimeEntryAutoStopCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'time-entry:auto-stop '.
        ' { --dry-run : Do not actually stop time entries or send emails, just output what would happen }';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Stops running time entries of users who enabled auto stop, at their configured stop time (Monday to Friday).';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->comment('Auto stopping running time entries...');
        $dryRun = (bool) $this->option('dry-run');

        $stoppedTimeEntries = 0;
        TimeEntry::query()
            ->whereNull('end')
            ->whereHas('user', function (Builder $query): void {
                /** @var Builder<User> $query */
                $query->where('is_placeholder', '=', false)
                    ->where('auto_stop_time_entries', '=', true);
            })
            ->with([
                'user',
                'project',
                'task',
            ])
            ->chunkById(500, function (Collection $timeEntries) use ($dryRun, &$stoppedTimeEntries): void {
                /** @var Collection<int, TimeEntry> $timeEntries */
                foreach ($timeEntries as $timeEntry) {
                    $user = $timeEntry->user;
                    $stopMoment = $this->stopMoment($timeEntry, $user);
                    if ($stopMoment === null || $stopMoment->isAfter(Carbon::now())) {
                        continue;
                    }

                    $this->info('Stopping time entry '.$timeEntry->getKey().' of user "'.$user->email.'" ('.$user->getKey().') at '.$stopMoment->toIso8601ZuluString());
                    $stoppedTimeEntries++;
                    if ($dryRun) {
                        continue;
                    }

                    $timeEntry->end = $stopMoment;
                    $timeEntry->save();

                    if ($timeEntry->project !== null) {
                        RecalculateSpentTimeForProject::dispatch($timeEntry->project);
                    }
                    if ($timeEntry->task !== null) {
                        RecalculateSpentTimeForTask::dispatch($timeEntry->task);
                    }

                    Mail::to($user->email)
                        ->queue(new TimeEntryAutoStoppedMail($timeEntry, $user));
                }
            });

        $this->comment('Finished auto stopping '.$stoppedTimeEntries.' time entries...');

        return self::SUCCESS;
    }

    /**
     * The moment (UTC) at which the running time entry has to be stopped, or null if it must not be stopped.
     * Only time entries started on a weekday (in the user's timezone) before the user's stop time are stopped,
     * at the stop time of the day they were started.
     */
    private function stopMoment(TimeEntry $timeEntry, User $user): ?Carbon
    {
        $localStart = $timeEntry->start->copy()->timezone($user->timezone);
        if (! $localStart->isWeekday()) {
            return null;
        }

        $localStopMoment = Carbon::parse($localStart->toDateString().' '.$user->auto_stop_time.':00', $user->timezone);
        if ($localStart->greaterThanOrEqualTo($localStopMoment)) {
            return null;
        }

        return $localStopMoment->utc();
    }
}
