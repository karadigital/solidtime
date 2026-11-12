@component('mail::message')
@if(empty($timeEntry->description))
{{ __('Your running time entry was stopped automatically at :time on :date, as set in your profile settings.', ['time' => $stoppedAt, 'date' => $stoppedOn]) }}
@else
{{ __('Your running time entry ":description" was stopped automatically at :time on :date, as set in your profile settings.', ['description' => $timeEntry->description, 'time' => $stoppedAt, 'date' => $stoppedOn]) }}
@endif

{{ __('If you were still working, you can adjust the time entry or start a new one in solidtime:') }}

@component('mail::button', ['url' => $dashboardUrl])
{{ __('Go to solidtime') }}
@endcomponent

@endcomponent
