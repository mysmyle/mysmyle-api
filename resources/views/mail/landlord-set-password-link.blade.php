@component('mail::message')
# Welcome to {{ config('app.name') }}

Hello{{ $name ? ' '.$name : '' }},

A platform administrator account has been created for you. Choose a password to finish setting it up:

@component('mail::button', ['url' => $url])
Set your password
@endcomponent

This link expires in {{ $ttlHours }} hours. If it does, ask another admin for a new one.

@include('mail.partials.automated-notice')
@endcomponent
