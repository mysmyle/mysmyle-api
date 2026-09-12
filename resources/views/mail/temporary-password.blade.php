@component('mail::message')
# Your {{ config('app.name') }} account

Hello{{ $name ? ' '.$name : '' }},

An account has been set up for you. Sign in with this temporary password:

@component('mail::panel')
{{ $password }}
@endcomponent

You'll be asked to choose your own password the first time you sign in.

@component('mail::button', ['url' => $loginUrl])
Sign in
@endcomponent

@include('mail.partials.automated-notice')
@endcomponent
