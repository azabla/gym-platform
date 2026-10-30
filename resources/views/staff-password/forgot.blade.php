@extends('staff-password.layout')

@section('title', 'Forgot your password?')

@section('content')
    <h1>Forgot your password?</h1>

    @if (session('status'))
        <p class="status">{{ session('status') }}</p>
    @else
        <p>Enter the phone number you sign in with, and we'll send you a link by SMS to choose a new password.</p>
    @endif

    <form method="POST" action="{{ route('staff.password.send') }}">
        @csrf
        <label for="phone">Phone number</label>
        <input id="phone" name="phone" type="tel" autocomplete="tel" value="{{ old('phone') }}" required autofocus>
        @error('phone')
            <div class="error">{{ $message }}</div>
        @enderror

        <button type="submit">Send link</button>
    </form>

    <p style="margin: 20px 0 0"><a href="{{ route('filament.staff.auth.login') }}">Back to sign in</a></p>
@endsection
