<x-mail::message>
# Quiz Available

Hi {{ $studentName }},

A quiz is now available for your application to **{{ $opportunityTitle }}**.

@if ($timeLimitMinutes)
**Time limit:** {{ $timeLimitMinutes }} minutes
@endif
**Passing score:** {{ $passingScore }}%

<x-mail::button :url="$ctaUrl">
Take Quiz
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
