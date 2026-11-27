@extends('staff-password.layout')

{{-- A gym the platform has paused (ADR 0043), in the staff member's language. --}}
@section('title', __('This gym is paused'))

@section('content')
    <h1>{{ $gym->name }}</h1>
    <p>{{ $message }}</p>

    @if ($otherGyms->isNotEmpty())
        <p style="color: var(--text); font-weight: 600; margin-bottom: 8px">{{ __('Your other gyms') }}</p>
        <ul style="margin: 0 0 20px; padding-left: 20px">
            @foreach ($otherGyms as $otherGym)
                <li><a href="{{ route('filament.staff.pages.dashboard', ['tenant' => $otherGym->slug]) }}">{{ $otherGym->name }}</a></li>
            @endforeach
        </ul>
    @endif

    <form method="POST" action="{{ route('filament.staff.auth.logout') }}">
        @csrf
        <button type="submit">{{ __('Sign out') }}</button>
    </form>
@endsection
