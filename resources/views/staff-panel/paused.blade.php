@extends('staff-password.layout')

{{-- A gym the platform has paused (ADR 0043), in the staff member's language. --}}
@section('title', __('This gym is paused'))

@section('content')
    <h1>{{ $gym->name }}</h1>
    <p>{{ $message }}</p>

    {{-- An owner of a gym paused for an unpaid bill sees what to pay (ADR 0045). --}}
    @if (($unpaid ?? collect())->isNotEmpty())
        <p style="color: var(--text); font-weight: 600; margin-bottom: 8px">{{ __('To reopen the gym, pay') }}</p>
        <ul style="margin: 0 0 12px; padding-left: 20px">
            @foreach ($unpaid as $invoice)
                <li><a href="{{ route('platform-bill.invoice', $invoice->getKey()) }}" target="_blank">{{ $invoice->reference() }}</a>: {{ $invoice->balance()->format() }}</li>
            @endforeach
        </ul>
        @if ($payTo !== [])
            <p style="margin-bottom: 8px">{{ __('Pay into one of these accounts with the invoice number as the reason:') }}</p>
            <ul style="margin: 0 0 12px; padding-left: 20px">
                @foreach ($payTo as $account)
                    <li>{{ $account }}</li>
                @endforeach
            </ul>
        @endif
        <p>{{ __('Once the platform records your payment, the gym reopens by itself.') }}</p>
    @endif

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
