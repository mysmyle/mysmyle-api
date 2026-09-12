@component('mail::message')
# Fixture

Set your password: {{ \App\Support\Frontend::url('/set-password/'.$token) }}

@include('mail.partials.automated-notice')
@endcomponent
