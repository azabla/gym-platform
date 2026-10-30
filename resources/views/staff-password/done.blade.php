@extends('staff-password.layout')

@section('title', 'Password saved')

@section('content')
    <h1>Password saved</h1>
    <p>Sign in with your phone number and your new password.</p>
    <a class="button" href="{{ route('filament.staff.auth.login') }}">Sign in</a>
@endsection
