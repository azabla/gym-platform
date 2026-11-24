# ADR 0036: Member photos

- **Status:** Accepted
- **Date:** 2026-11-24

## Context

At the door, the desk saw a member's name, membership and balance (ADR 0011), but not whether the person holding the phone was the member. A QR code can be shown from someone else's phone. A photo lets the desk check at a glance, and makes the members list quicker to read.

A photo of a person's face is personal data. The R2 bucket the app already uses has a public `r2.dev` address, so anything in it is reachable by anyone who has the link.

## Decision

### Who adds a photo

- **The desk.** Staff who may edit members (owner, manager, receptionist) add, replace or remove it, in the panel or the staff API. Trainers can't.
- **The member.** They set or remove their own from the members app, at every gym where their phone is registered. The desk can replace it.

### What is kept

`App\Domains\Members\Photos\MemberPhoto` turns any upload into one small file:
- **Upright.** It turns the image the way the camera says it was held (EXIF orientation).
- **Square and small.** It cuts a square from the centre and resizes it to **512 px**.
- **Nothing extra.** It saves the result as a JPEG (quality 82), which leaves out everything the phone wrote into the file, such as where the photo was taken.

Uploads are JPEG, PNG or WebP of up to 5 MB, at least 200 px wide and high. Anything else is refused (`MemberPhotoRefused`, a 422 on `photo`). GD and EXIF were already in every PHP image, so no package was added.

### Where it is kept

- **Path.** `members.photo_path` points at `member-photos/{gym id}/{random}.jpg`. Every change gets a new name, and the old file is deleted.
- **Disk.** Photos live on `config('filesystems.photos_disk')`:
  - in development, the private `local` disk;
  - in production, the `photos` disk: a **private** R2 bucket of its own (`PHOTOS_AWS_BUCKET`, same credentials), never the public one.
- **Links.** Photos are only shown through **links that work for 10 minutes** (`temporaryUrl()`). On R2 these are signed by the app, without a call to R2, so a list of 25 members costs nothing extra.

### Where it shows

| Where | What |
|---|---|
| Panel: member page | A large round photo at the top of "At a glance", with **Add photo** or **Change photo**, and **Remove photo** |
| Panel: members and check-ins lists | A small round photo beside each name |
| Panel: desk check-in | The photo with "let in" |
| Staff API | `photo_url` on a member, and on a check-in's `member`, so the staff app's scan result shows it |
| Members app | `photo_url` on `member` in `GET /me/gyms` |

Without a photo, the panel shows the member's initials on a circle, drawn by the app so no outside service learns the name. The API gives `photo_url: null`.

- **Upload in the panel.** It uses Filament's image editor, cropped square. On a phone or tablet the browser offers the camera.
- **Endpoints.**
  - staff: `PUT` and `DELETE organizations/{gym}/members/{member}/photo`;
  - members app: `PUT` and `DELETE /me/photo`.

  Uploads are multipart, with the file as `photo`.

## Consequences

- **Production needs a third R2 bucket**, private, plus `PHOTOS_DISK=photos` and `PHOTOS_AWS_BUCKET` on Render. Until then photos go to the server's own disk, which Render wipes on each deploy.
- **Links expire.** A screen left open over 10 minutes shows broken images until it reloads, so the apps should reload photo links when they refresh data, never store them.
- **A member's photo follows their phone.** Setting it in the app replaces the photo at every gym. A gym that wants its own can replace it at the desk.
- **Archived members keep their photo.** It goes only when the desk or the member removes it.

### Deferred

- Taking a photo with a desktop webcam inside the panel.
- Showing the photo on the members app's QR screen.
- Deleting photos automatically some time after a member is archived.
