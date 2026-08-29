<x-mail::message>
# Quiz Available

Hi {{ $studentName }},

@if ($availableAt)
You have been selected to complete a technical assessment for your application to **{{ $opportunityTitle }}**.

**Available from:** {{ $availableAt->format('M j, Y g:i A') }}
@if ($dueAt)
**Deadline:** {{ $dueAt->format('M j, Y g:i A') }}
@endif
@if ($timeLimitMinutes)
Once started, you will have **{{ $timeLimitMinutes }} minutes** to complete the assessment.
@endif
@else
A quiz is now available for your application to **{{ $opportunityTitle }}**.

@if ($timeLimitMinutes)
**Time limit:** {{ $timeLimitMinutes }} minutes
@endif
@endif
**Passing score:** {{ $passingScore }}%

<x-mail::button :url="$ctaUrl">
@if ($availableAt)
View Assessment
@else
Take Quiz
@endif
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
