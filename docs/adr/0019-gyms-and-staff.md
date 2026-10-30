# ADR 0019: Gyms and staff

- **Status:** Accepted
- **Date:** 2026-10-30

## Context

Only the seeder could create a gym or add staff, so no real gym could start using the system. Staff signed in with an email and a password, but gyms in Addis know their staff by phone number, and the SMS sender (ADR 0010) is already in place. Nothing stopped a gym from losing its last owner, and removing someone would have broken the history of who took a payment or sold a membership.

## Decision

### Creating a gym

- **`php artisan gyms:create`,** run by us as the platform, not by gyms. It creates the gym, its first branch and its owner, then sends the owner an SMS invite.
- **Options or questions:** `--name`, `--slug`, `--timezone` (default `Africa/Addis_Ababa`), `--branch` (default `Main Branch`), `--owner-name` and `--owner-phone`. It asks for what it isn't given.
- **Checks:** it refuses a slug that is taken, badly formed, or used by the panel itself (`login`, `logout`, `register` and the like). It also refuses a phone or time zone it can't read. Nothing is created unless everything is valid.

### Who manages staff

- **Owners** manage everyone, including other owners.
- **Managers** manage receptionists, trainers and accountants, and only at branches the manager works at. A manager limited to some branches can't give anyone all branches, and only sees staff at their own branches.
- **No one changes or archives themselves,** so no one can lock themselves out.
- **Where the rules live:**
  - `StaffRole::isManagedBy()` and `Staff::covers()` decide who may touch whom, and `StaffPolicy` uses them;
  - `Staff::mayAssign()` decides which role and branches someone may give;
  - the API's requests and the panel both call these, so the two refuse the same things.

### Adding, changing and archiving

- **`Staff::add()`** adds a user with a role and branches; none given means all branches. Owners always work at all branches. Someone who already works at the gym is refused (`StaffRefused`, a 422 on `phone`).
- **Archiving is a soft delete.**
  - **Losing access:** the staff record is looked up on every API and panel request (`ActAsOrganization`, `ActAsTenant`). An archived staff member therefore loses that gym on their next request, with no session to clear. Their other gyms, and the members app, are untouched.
  - **History keeps the name:** every link to a staff member (sold by, received by, checked in by and the like) includes archived records.
- **Adding an archived person again** restores their old record, so their history stays theirs.
- **The last owner:** `reassign()` and `archive()` lock the gym's owners and refuse to demote or archive the last one (`StaffRefused`).
- **One user per phone:** someone added by phone who already uses the app, as a member or at another gym, stays the same user, with their own name.

### Signing in with a phone

- **Panel:** the sign-in page asks for the phone and password, matched however the number is typed, and links to "Forgot your password?".
- **API:** `POST auth/tokens` takes `phone` and `password`. Email still works for accounts that have one, such as the demo logins.
- **Same answer:** unknown numbers, wrong passwords and members without a password all get the same answer, in the same time.

### Password links

- **`password_links`** holds an invite (valid 7 days) or a reset (valid 60 minutes).
  - The token is stored as a hash keyed with the app key.
  - A link works once, and issuing a new one cancels the user's earlier links.
  - Expired links are pruned daily.
- **The SMS** names the gym and the app, never a person, and is sent from the queue like sign-in codes.
- **Who gets what:**
  - staff added without a password get an invite;
  - those who already have a password (staff at another gym) are only told they were added;
  - an owner or manager can send a pending invite again.
- **The pages are plain Laravel pages,** not Filament:
  - `/password/{token}` to choose a password;
  - `/password/forgot` to ask for a reset. It answers every number the same, in the same time, and is throttled like sign-in codes. An SMS goes only to a staff member's number.

### API

Under `organizations/{gym}/`, for owners and managers:

| Endpoint | What it does |
|---|---|
| `GET staff` | Oldest first, 25 per page; `?archived=1` lists archived staff. Each has name, phone, role, branches, `invite_pending` and `archived_at`. |
| `POST staff` | `name`, `phone`, `role`, `branch_ids` (empty or absent for all branches). Answers 201 and sends the SMS. |
| `PATCH staff/{id}` | `role` and/or `branch_ids` |
| `DELETE staff/{id}` | Archive |
| `POST staff/{id}/restore` | Restore |
| `POST staff/{id}/invite` | Send the invite again (202) |

The panel has the same on its Staff page.

### Deferred

- Removing an owner's access to every gym at once, or deleting a user.
- Staff choosing their own phone number or name.
- Email invites.

## Consequences

- A real gym can start: we run `gyms:create`, the owner sets a password from the SMS, and adds the rest of the staff.
- Staff need a phone that receives SMS to get in, and the SMS bill grows with invites and resets. The throttles keep it bounded.
- `GET me` still lists only gyms where the user is active staff.
- The phone number is the identity: someone added by phone signs in to the members app with the same number. That number must really be theirs, as the owner vouches when adding them.
