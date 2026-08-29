<x-mail::message>
# Assessment Result

Hi {{ $studentName }},

@if ($passed)
Congratulations — you have successfully completed the assessment for your application to **{{ $opportunityTitle }}**. The organization will contact you regarding the next step in the recruitment process.
@else
Thank you for the time and effort you put into the assessment for your application to **{{ $opportunityTitle }}**. The organization will review your results as part of your overall application, and you will be notified of next steps.
@endif

<x-mail::button :url="$ctaUrl">
View Application
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
