{{-- The platform's unpaid invoice, across the top of every page, for owners (ADR 0045). Inline styles: the panel has no Tailwind build of its own. --}}
@php($color = $overdue ? 'danger' : 'warning')
<div class="fi-platform-bill-banner" role="status" style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .5rem; margin-bottom: 1rem; padding: .75rem 1rem; border-radius: .75rem; font-size: .875rem; font-weight: 500; background: var(--{{ $color }}-50); color: var(--{{ $color }}-700); border: 1px solid var(--{{ $color }}-200);">
    <span>{{ $text }}</span>
    <a href="{{ $url }}" style="font-weight: 600; text-decoration: underline; color: inherit;">{{ __('See how to pay') }}</a>
</div>
