@extends('staff-password.layout')

@section('title', 'Choose your password')

@section('content')
    <h1>Choose your password</h1>
    <p>Hello {{ $name }}. Choose a password of at least 8 characters. You'll sign in with your phone number and this password.</p>

    <form method="POST" action="{{ route('staff.password.update', ['token' => $token]) }}">
        @csrf
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="new-password" required autofocus>
        @error('password')
            <div class="error">{{ $message }}</div>
        @enderror

        <label for="password_confirmation">Password again</label>
        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>

        <button type="submit">Save password</button>
    </form>
@endsection
