<x-mail::message>
# Assessment Update

Hi {{ $studentName }},

Congratulations — you have successfully completed the assessment for **{{ $opportunityTitle }}** and have been selected to continue to the interview stage.

**Interview details**

**Type:** {{ $interviewTypeLabel }}
**When:** {{ $scheduledAt->format('F j, Y \a\t g:i A') }}
@if ($durationMinutes)
**Duration:** {{ $durationMinutes }} minutes
@endif
@if ($interviewType === 'online' && $meetingLink)
**Meeting link:** {{ $meetingLink }}
@endif
@if ($interviewType === 'onsite' && $location)
**Location:** {{ $location }}
@endif
@if ($interviewType === 'phone' && $contactPhone)
**Contact phone:** {{ $contactPhone }}
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
