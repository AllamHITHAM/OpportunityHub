<x-mail::message>
# Offer Declined

Hi {{ $recipientName }},

**{{ $studentName }}** has declined your offer for **{{ $opportunityTitle }}**.

<x-mail::button :url="$ctaUrl">
View Application
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
