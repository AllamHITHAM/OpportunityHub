<x-mail::message>
# Interview Rescheduled

Hi {{ $studentName }},

Your interview for **{{ $opportunityTitle }}** has been rescheduled. Here are the updated details:

**Type:** {{ $interviewTypeLabel }}
**New date/time:** {{ $scheduledAt->format('F j, Y \a\t g:i A') }}
@if ($durationMinutes)
**Duration:** {{ $durationMinutes }} minutes
@endif
@if ($interviewType === 'online' && $meetingLink)
**Meeting link:** {{ $meetingLink }}
@endif
@if ($interviewType === 'onsite' && $location)
**Location:** {{ $location }}
@endif
@if ($interviewerName)
**Interviewer:** {{ $interviewerName }}
@endif

<x-mail::button :url="$ctaUrl">
View Application
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
