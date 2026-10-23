# ADR 0008: Billing and payments

- **Status:** Accepted
- **Date:** 2026-10-23

## Context

Memberships are sold (ADR 0006, ADR 0007), but nothing recorded the money. Gyms in Ethiopia take cash, Telebirr, CBE Birr and bank transfers, often in parts: half today and the rest next week. Staff need to:

- give the member a receipt;
- see who still owes money, and how much;
- count the day's takings for each branch and method, to close the cash drawer;
- fix a payment recorded by mistake;
- give money back when a membership is cancelled.

Mistakes and fraud at the front desk are real risks, so every correction must leave a trail.

Members also want to pay online. The options were:

- **a gateway, Chapa:** one integration accepts Telebirr, CBE Birr, M-Pesa, Ebirr and cards, and confirms each payment by webhook;
- **direct integrations:** each provider needs its own merchant contract and keys, request signing and a whitelisted server IP (Telebirr requires RSA signing), and we would build and maintain each one ourselves.

## Decision

### Payments belong to memberships

- A payment is money a member paid towards one membership.
- A refund is the same record going the other way (`type` = `refund`), with a positive amount, so one table, one list and one sequence of receipt numbers cover both.
- Invoices (money owed for anything else, such as personal training, lockers or products) come when gyms sell those. Payments will then point at an invoice instead of a membership.

### What is owed

- The balance due is the membership's price, less its payments, plus its refunds. Voided payments don't count.
- It is worked out from the payments every time, never stored, so it can't drift away from them. `Payment::balancesOf()` does it in one query for a whole page of memberships.
- A cancelled membership owes nothing more. What was paid towards it can be refunded, up to that amount.
- A payment can't be more than the balance due, so there is no overpaying and no member credit to track.
- Membership responses show `amount_paid` and `balance_due`, and member responses show `balance_due` across the member's memberships. These are looked up in the HTTP layer, because Memberships doesn't depend on Billing (enforced by the architecture tests).

### Methods and transaction numbers

- Staff record payments by cash, Telebirr, CBE Birr or bank transfer.
- Every method but cash needs its transaction number (`reference`), copied from the confirmation. Cash has none. A check constraint enforces both.
- Transaction numbers are stored without spaces and in capitals.
- One gym can't record the same number twice for one method, among payments that aren't voided (a partial unique index). So the same transfer can't be entered twice, and voiding a payment frees its number.

### Receipts

- Each gym numbers its receipts 1, 2, 3 with no gaps, refunds included.
  - The next number comes from `receipt_counters`, with one `INSERT … ON CONFLICT … RETURNING` inside the payment's own transaction.
  - The gym's counter row stays locked until the payment is saved, so numbers never repeat, and a payment that fails rolls its number back.
  - Payments are never deleted, so a missing number would mean something is wrong.
- A receipt is JSON (`GET payments/{id}`) that an app can print. It shows:
  - the number, amount, method, transaction number and date;
  - the branch and who took the money;
  - the member, and the membership with its balance;
  - the void, if any.
- PDF receipts wait, because they need a new package.
- These are payment receipts for members, not Ministry of Revenue fiscal receipts.

### Date, branch and the day's takings

- **Date:**
  - `paid_on` is the gym's date (ADR 0006), today by default;
  - only owners, managers and accountants can enter an earlier date, for example for a bank transfer that arrived yesterday;
  - nobody can enter a future date.
- **Branch:**
  - the branch is where the money was taken, so each branch can count its own drawer;
  - it defaults to the staff member's branch when they work at only one, and must be a branch they work at.
- **The list:**
  - `GET payments` lists the gym's payments and refunds, filtered by day, branch, method and member;
  - `meta.totals` gives the total received, the total refunded, the net, and the net for each method, across every page;
  - voided payments are listed but don't count.

### Corrections: void and refund

- **Void:** the payment was recorded by mistake. It stays on record, with who voided it, when and why, but it no longer counts.
- **Refund:** money given back after a cancellation, up to what was paid, with its own method, transaction number and receipt.
- A payment can't be voided while a refund depends on it, because that would leave more refunded than paid. Void the refund first.

### Two desks at once

- Each rule has one check on `Payment`: `refusalToReceive()`, `refusalToRefund()` and `refusalToVoid()`. Requests turn a refusal into a 422 with its message.
- `receive()`, `refund()` and `void()` lock the membership's row first, then run the same check again, so two desks can't both take the last payment.
- If that race happens, the domain throws `PaymentRefused`, which `bootstrap/app.php` turns into the same 422.

### The Accountant role

| Role | See payments and receipts | Record a payment | Refund, void, use an earlier date |
|---|---|---|---|
| Owner, Manager | Yes | Yes | Yes |
| Accountant | Yes | Yes | Yes |
| Receptionist | Yes | Yes, dated today | No |
| Trainer | No | No | No |

- Like every staff member, accountants see members, plans and memberships. They can't register or edit members, sell, freeze, cancel or change plans.
- Every staff member, trainers included, sees the balance due on members and memberships, so anyone can send a member who owes to the front desk.

### Online payments: Chapa, next

- The next piece adds Chapa rather than direct integrations: one integration covers every channel, and webhooks confirm payments, so nobody types a transaction number. The cost is Chapa's fee on each payment.
- Each gym connects its own Chapa account, so members pay the gym directly and the platform never holds their money.
- An online payment becomes an ordinary payment record, so balances, receipts and totals work unchanged.
- Laravel's built-in HTTP client is enough, so Chapa needs no new package.

### Deferred

- Chapa online payments.
- Invoices for other products.
- PDF receipts, and fiscal receipts.
- Reports, such as revenue over time and who owes.
- Member credit (paying before a sale), and refunds without a cancellation.
- Gyms that take more than one currency: the totals assume one currency per gym (ETB).

## Consequences

- The front desk can take part payments and give numbered receipts, and owners see who owes at a glance.
- A branch can count its cash drawer from one request.
- Mistakes are corrected without deleting anything: voids and refunds keep the trail, and receipt numbers stay gapless.
- Every payment, refund and void briefly locks its membership's row, so money changes on one membership happen one at a time. Different memberships don't wait for each other.
- The receipt counter makes payments within one gym wait for each other for the length of a short transaction.
- Online payments can plug in later without changing balances, receipts or totals.
