# ADR 0001: Represent money as integer minor units

- **Status:** Accepted
- **Date:** 2026-09-27

## Context

The platform handles membership fees, invoices, payments, refunds, discounts and cash drawer balances. Every one of these must add up exactly: a cash drawer that is 0.01 ETB off at closing is a real problem for a gym owner.

Floating-point numbers cannot represent most decimal fractions exactly. In PHP, `0.1 + 0.2` is `0.30000000000000004`. Errors like this accumulate across thousands of payments and appear as unexplained differences in reports.

The options considered:

1. **Float** (`DOUBLE` in the database, `float` in PHP). Rejected: inexact.
2. **Decimal** (`NUMERIC(12,2)` in PostgreSQL, strings in PHP). Exact in the database, but PHP has no native decimal type, so calculations need a library such as brick/math or careful string handling everywhere. JSON clients (Flutter) tend to parse decimals as floats.
3. **Integer minor units** (`BIGINT` santim in the database, `int` in PHP). Exact, fast, native in PHP, PostgreSQL and Dart.

## Decision

Store and calculate money as an integer count of minor units (santim for ETB), always together with its currency.

- Database columns: `amount_minor BIGINT NOT NULL` plus `currency CHAR(3) NOT NULL`.
- In code: the `App\Domains\Shared\Money\Money` value object. Never pass raw integers or floats around as money.
- In APIs: `{"amount_minor": 150000, "currency": "ETB", "formatted": "1,500.00 ETB"}`.
- User input is parsed from **strings** with `Money::of('1,500.50')`, never from floats.
- Percentages are expressed in basis points (1500 = 15%) and rounded half away from zero.
- Splitting an amount uses `Money::allocate()`, which guarantees the parts sum to the original.

## Consequences

- No rounding drift in totals, balances or reports.
- Developers must remember that `150000` means 1,500.00 ETB. The column name suffix `_minor` and the `Money` object make this hard to forget.
- Currencies with a different number of decimals are supported through `Currency::decimals()`.
- Report SQL must divide by 100 only at the final display step, never before aggregating.
