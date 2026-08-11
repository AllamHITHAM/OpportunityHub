<x-mail::message>
# Offer Accepted

Hi {{ $recipientName }},

Good news — **{{ $studentName }}** has accepted your offer for **{{ $opportunityTitle }}**.

<x-mail::button :url="$ctaUrl">
View Application
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
