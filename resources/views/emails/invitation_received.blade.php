<x-mail::message>
# You Have Been Invited to Apply

Hi {{ $studentName }},

**{{ $organizationName }}** has invited you to apply for **{{ $opportunityTitle }}** on {{ config('app.name') }}.
@if ($invitationMessage)

{{ $invitationMessage }}
@endif

Review the invitation and choose to accept or decline it in the app.

<x-mail::button :url="$ctaUrl">
View Invitation
</x-mail::button>

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
