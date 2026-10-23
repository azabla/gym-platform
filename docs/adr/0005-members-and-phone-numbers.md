# ADR 0005: Members and phone numbers

- **Status:** Accepted
- **Date:** 2026-10-23

## Context

Members are a gym's customers. Staff register them at the front desk, look them up when they arrive, and will later charge them and check them in.

In Ethiopia the phone number is how a gym reaches people and how staff find them, and SMS reminders will depend on it. People write the same number in many ways: `0911 23 45 67`, `911234567`, `+251 911 234 567`. Ethiopian names are a given name followed by the father's name (and often the grandfather's), so "first name / last name" fields do not fit.

## Decision

### Names

One `full_name` field.

### Phone numbers

- Stored as E.164 strings (`+251911234567`) in `VARCHAR(16)` columns, never as typed. In code, `App\Domains\Shared\PhoneNumber\PhoneNumber::of()` reads Ethiopian numbers however they are written. Numbers from other countries need a leading `+`.
- One active member per phone number in each gym, enforced by a partial unique index (`WHERE deleted_at IS NULL`). Archived members free their number.

### Members

- Required: full name, phone number and home branch. Optional: email, gender, date of birth, and an emergency contact (name and phone, given together).
- The home branch is linked with a composite foreign key, so it must belong to the member's own gym (ADR 0003).
- Removing a member archives them (a soft delete). The record stays for history.

### Access

- Every staff role can look members up, at every branch of the gym, because members may visit any branch.
- Owners, managers and receptionists register, edit and archive members. Trainers only look them up.
- A new member's home branch must be a branch the staff member works at.

### Deferred

- Member photos (object storage).
- Member sign-in to the app, which links a member to a User.
- Member numbers or cards for check-in.
- Restoring archived members.
- Family members sharing one phone number.
- Faster name search for very large gyms (a trigram index).

## Consequences

- Duplicate checks and phone search are reliable, and SMS can use the stored numbers directly.
- Apps can show numbers in any local format; the API always returns E.164.
- Two family members cannot share one phone number in the same gym.
- Search uses `ILIKE '%…%'`, which is fine for thousands of members. Very large gyms will need a trigram index.
