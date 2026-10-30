@extends('staff-password.layout')

@section('title', 'Link no longer works')

@section('content')
    <h1>This link no longer works</h1>
    <p>It was already used, it expired, or a newer link was sent. Ask for a new one, or ask the gym's owner or manager to send your invite again.</p>
    <a class="button" href="{{ route('staff.password.forgot') }}">Get a new link</a>
@endsection
