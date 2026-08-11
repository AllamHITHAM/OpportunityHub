<x-mail::message>
# You Received an Offer!

Hi {{ $studentName }},

Congratulations — you have received an offer for **{{ $opportunityTitle }}**.

@if ($startDate)
**Start date:** {{ $startDate->format('F j, Y') }}
@endif
@if ($compensation)
**Compensation:** {{ $compensation }}
@endif
@if ($offerMessage)

{{ $offerMessage }}
@endif

Full offer details are available in {{ config('app.name') }}.

<x-mail::button :url="$ctaUrl">
View Offer
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
