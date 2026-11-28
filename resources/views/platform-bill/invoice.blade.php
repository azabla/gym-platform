@php
    /** @var \App\Domains\PlatformBilling\Models\GymInvoice $invoice */
    /** @var \App\Domains\Organizations\Models\Organization $gym */
    $date = fn ($day) => \App\Domains\Shared\Dates\ReadableDate::long($day, app()->getLocale());
    $platform = config('platform');
    $perMonth = $invoice->monthly_price->add($invoice->extra_branch_price->multiply($invoice->branches - 1));
    $beforeDiscount = $perMonth->multiply($invoice->months);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $invoice->reference() }} · {{ $platform['name'] }}</title>
    <style>
        :root { --text: #18181b; --muted: #52525b; --border: #d4d4d8; --accent: #4f46e5; --paid: #047857; --void: #b91c1c; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 24px 16px; background: #f4f4f5; color: var(--text); font: 15px/1.5 system-ui, "Noto Sans Ethiopic", sans-serif; }
        main { max-width: 760px; margin: 0 auto; background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 32px; }
        header { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 16px; border-bottom: 2px solid var(--accent); padding-bottom: 16px; margin-bottom: 24px; }
        h1 { margin: 0; font-size: 1.5rem; }
        h2 { margin: 0 0 4px; font-size: .8rem; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
        .muted { color: var(--muted); }
        .parties { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .dates { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 24px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 16px; }
        th, td { text-align: left; padding: 8px 4px; border-bottom: 1px solid var(--border); vertical-align: top; }
        td.amount, th.amount { text-align: right; white-space: nowrap; }
        tr.total td { font-weight: 700; font-size: 1.1rem; border-bottom: 0; }
        .stamp { display: inline-block; padding: 4px 12px; border: 2px solid currentColor; border-radius: 6px; font-weight: 700; text-transform: uppercase; }
        .stamp.paid { color: var(--paid); } .stamp.void { color: var(--void); } .stamp.open { color: var(--accent); }
        .pay { border: 1px solid var(--border); border-radius: 8px; padding: 12px 16px; margin-top: 16px; }
        .pay ul { margin: 4px 0 0; padding-left: 20px; }
        .print { display: inline-block; margin: 0 auto 16px; padding: 8px 16px; border: 0; border-radius: 8px; background: var(--accent); color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
        .actions { max-width: 760px; margin: 0 auto; text-align: right; }
        @media print { body { background: #fff; padding: 0; } main { border: 0; padding: 0; } .actions { display: none; } }
    </style>
</head>
<body>
    <div class="actions"><button type="button" class="print" onclick="window.print()">{{ __('Print') }}</button></div>
    <main>
        <header>
            <div>
                <h1>{{ __('Invoice') }} {{ $invoice->reference() }}</h1>
                <div class="muted">{{ __('Prices include VAT.') }}</div>
            </div>
            <div>
                @if ($invoice->voided_at !== null)
                    <span class="stamp void">{{ __('Voided') }}</span>
                @elseif ($invoice->paid_at !== null)
                    <span class="stamp paid">{{ __('Paid') }}</span>
                @else
                    <span class="stamp open">{{ __('Due') }}</span>
                @endif
            </div>
        </header>

        <section class="parties">
            <div>
                <h2>{{ __('From') }}</h2>
                <strong>{{ $platform['name'] }}</strong><br>
                @if (filled($platform['address'])) {{ $platform['address'] }}<br> @endif
                @if (filled($platform['phone'])) {{ $platform['phone'] }}<br> @endif
                @if (filled($platform['tin'])) {{ __('TIN') }}: {{ $platform['tin'] }} @endif
            </div>
            <div>
                <h2>{{ __('To') }}</h2>
                <strong>{{ $gym->name }}</strong><br>
                @if (filled($invoice->tin)) {{ __('TIN') }}: {{ $invoice->tin }} @endif
            </div>
        </section>

        <section class="dates">
            <div><h2>{{ __('Issued') }}</h2>{{ $date($invoice->issued_on) }}</div>
            <div><h2>{{ __('Due') }}</h2>{{ $date($invoice->due_on) }}</div>
            <div><h2>{{ __('Period') }}</h2>{{ $date($invoice->period_starts_on) }} – {{ $date($invoice->period_ends_on) }}</div>
        </section>

        <table>
            <thead>
                <tr><th>{{ __('Description') }}</th><th class="amount">{{ __('Amount') }}</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ __(':plan plan, first branch', ['plan' => $invoice->plan_name]) }} · {{ trans_choice(':count month|:count months', $invoice->months) }} × {{ $invoice->monthly_price->format() }}</td>
                    <td class="amount">{{ $invoice->monthly_price->multiply($invoice->months)->format() }}</td>
                </tr>
                @if ($invoice->branches > 1)
                    <tr>
                        <td>{{ trans_choice(':count extra branch|:count extra branches', $invoice->branches - 1) }} · {{ trans_choice(':count month|:count months', $invoice->months) }} × {{ $invoice->extra_branch_price->format() }}</td>
                        <td class="amount">{{ $invoice->extra_branch_price->multiply(($invoice->branches - 1) * $invoice->months)->format() }}</td>
                    </tr>
                @endif
                @if ($invoice->discount_percent > 0)
                    <tr>
                        <td>{{ __(':percent% off for paying :months months at once', ['percent' => $invoice->discount_percent, 'months' => $invoice->months]) }}</td>
                        <td class="amount">−{{ $beforeDiscount->subtract($invoice->total)->format() }}</td>
                    </tr>
                @endif
                <tr class="total">
                    <td>{{ __('Total') }}</td>
                    <td class="amount">{{ $invoice->total->format() }}</td>
                </tr>
                <tr>
                    <td class="muted">{{ __('Of which VAT (15%)') }}</td>
                    <td class="amount muted">{{ $invoice->vat->format() }}</td>
                </tr>
            </tbody>
        </table>

        @if ($invoice->voided_at !== null)
            <p class="muted">{{ __('Voided: :reason', ['reason' => $invoice->void_reason]) }}</p>
        @elseif ($invoice->paid_at === null && $platform['pay_to'] !== [])
            <div class="pay">
                <strong>{{ __('How to pay') }}</strong>
                <div class="muted">{{ __('Pay into one of these accounts, with :reference as the reason, and keep the receipt.', ['reference' => $invoice->reference()]) }}</div>
                <ul>
                    @foreach ($platform['pay_to'] as $account)
                        <li>{{ $account }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </main>
</body>
</html>
