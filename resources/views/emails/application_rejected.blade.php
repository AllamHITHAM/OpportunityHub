<x-mail::message>
# Application Update

Hi {{ $studentName }},

Thank you for your interest in **{{ $opportunityTitle }}**. After careful consideration, your application was not selected to move forward.

We appreciate the time you invested in applying, and we encourage you to keep exploring other opportunities on {{ config('app.name') }}.

<x-mail::button :url="$ctaUrl">
View Application
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
