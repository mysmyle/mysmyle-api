@component('mail::message')
# Welcome to {{ config('app.name') }}

Hello{{ $name ? ' '.$name : '' }},

An account has been created for you at **{{ $clinicName }}**. Choose a password to finish setting it up:

@component('mail::button', ['url' => $url])
Set your password
@endcomponent

This link expires in {{ $ttlHours }} hours. If it does, ask for a new one.

@include('mail.partials.automated-notice')
@endcomponent
