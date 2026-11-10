# Viva Board Distribution — v0.8.5

Expert Evaluation System is considered complete for the current scope. Viva Board
Distribution is an independent module, accessible from its own Dashboard card.
Dashboard and Administration remain the common shell.

## Roles and menus

| Role | Viva module access |
| --- | --- |
| Chairman | **1. Member Pools**, then **2. Board Distribution**; view/print/download distribution summaries within step 2 |
| Administrator | Candidate Import, read-only Board Overview/reports, Settings; maintain user/member location details through common Users management |
| Member / Staff | No Viva backend module or management endpoint access |
| Display viewer | Separate shared display login; no application account, role or backend access |

Chairman cannot import candidates or change module settings. Administrator cannot
execute board assignment or manage Chairman's pools. Permissions apply to direct
URLs and write requests as well as menu visibility.

## Member details

When Administrator creates/edits a User with Member or Chairman role, these optional
fields are available: **Member Number, Floor, Room Number, Lift Button Number, Name
of PO**. Text values are supported (for example `Ground`, `501/A`, `G`). Empty fields
are omitted from the live display; `0` remains a valid displayed value.

These fields are audited and included as trailing columns in User setup workbooks.
Older User exports without these columns remain importable and preserve any existing
member-location values. Original setup transfer remains Administrator-only.

## Step 1 — member pools (Chairman)

Create, edit/deactivate or remove a pool. Populate it with all active Members, all
active Chairmen, both roles, or specific eligible users. The member list is searchable;
edit the selected users to add or remove people. “All” captures users at save time;
new accounts are not silently enrolled later.

Pool and assignment lists show Chairmen first (by name), then Members with numeric
Member Numbers in ascending numeric order (`2`, `03`, `10`). Blank/non-numeric
Member Numbers follow, ordered by name. Names break equal-number ties. Sorting
does not alter stored numbers or remove leading zeroes.

Only active, non-deleted Member/Chairman users in an active pool can be assigned.
Inactive users or changed roles become ineligible immediately. Pool removal is soft:
previous assignments remain available for reports and the live display.

## Candidate import (Administrator)

Open **Candidate Import**, download the filled **Sample .xlsx** or blank template,
replace examples and upload one worksheet using these columns:

| Column | Rule |
| --- | --- |
| `post_code` | Required numeric identifier, up to 20 digits, stored as text |
| `post_name` | Required, up to 255 characters; one name per post/date |
| `vdate` | Required `DDMMYY` or `DDMMYYYY`, such as `091126` or `09112026` |
| `board` | Required numeric group 1–999; normalized to minimum two digits, so `1`, `01`, `001` all identify group `01` |
| `reg` | Required numeric identifier, up to 40 digits, stored as text with leading zeroes intact |
| `name` | Optional; up to 255 characters; this final column/header can be omitted |

Keep identifiers/date strings as **Text** in Excel. Explicit numeric zero-padding formats
are honoured for identifiers. A lost leading day-zero in five/seven-digit `vdate`
values is recovered. Excel serial dates and formulas are not accepted. For two-digit
years, `00–69` means `2000–2069` and `70–99` means `1970–1999`; calendar dates are
validated and stored as proper `YYYY-MM-DD` dates (supported years 1900–2099).

Uploads support 10 MB, 5,000 rows, and 40 MB expanded workbook content. Staging is
encrypted, owner-bound, expires after one hour, and writes no candidate/board records.
Preview shows row errors, normalized date/group and create/update action, with 20 rows
per page. Any error blocks the entire import. Confirm requires explicit approval,
revalidates database state and saves all rows atomically; failures roll back all writes.
Successful imports clear the staged payload; cancel deletes it. Expired previews are
cleaned on uploads and by daily Laravel scheduling.

A registration is unique within its viva date and post code. Reimporting existing
identifiers updates their unassigned candidate record, without duplication. Changing
its group before assignment moves that candidate; empty unassigned groups are removed.
The same registration can occur under another post/date. Duplicate rows in one upload
are rejected. A changed post name conflicting with existing data must be resolved by
removing/reimporting the affected unassigned boards.

Assigned boards are protected: Administrator cannot import into/change/remove their
candidate data. Unassigned boards and their candidate records can be removed from the
import list with confirmation. Import confirmation redirects to the first imported date.

## Step 2 — board distribution (Chairman)

1. Select **Viva Date**, the imported **Post Code / Name**, and a **Member Pool**.
2. Load boards. Groups are detected from candidate data for that exact date/post.
3. Select the unassigned boards to distribute.
4. **Random assignment** is first and selected by default. Pick exactly as many
   different available members as selected groups. The system randomly permutes
   those members onto the selected boards.
5. Alternatively, choose **Specific assignment** and select a different available
   member for each board.
6. Confirm distribution. All candidates in each selected group belong to its assigned
   member; candidates are never individually scattered across members.
7. Repeat for other post codes on that date. Previously assigned members are excluded
   from every other pool/post for the same date.

The identity of a board is **viva date + post code + normalized group**. Two posts can
both have `01`; these are separate boards. One member can have only **one board on a
calendar date across all posts and pools**. Another date can use the same member again.
Database unique constraints enforce board and member/day uniqueness, while per-day
transaction locking serializes competing imports/assignment changes.

All operational times use **Asia/Dhaka (BST, UTC+6)**. Default opening is **09:30 AM on
the selected viva date**. Administrator can change this time in Settings. Chairman
cannot assign before that date/time, including future dates. The server rechecks the
opening time when saving; disabling a UI control is not the enforcement mechanism.
Past-date records can be distributed/corrected after that day's opening time.

Distribution summary shows all assigned groups on the selected date, paginated 20 per
page. Download an entire **Day Report** or **This Post** as `.xlsx` with separate Boards
and Candidates sheets; identifiers remain literal strings. Files support up to 20,000
candidates, so narrow to one post for larger days. **Print This Page** prints the current
summary page. Administrator can view/download these reports without assignment rights.

Chairman can correct an assignment by removing it with a required reason and explicit
confirmation. This is audited and removes it from the live display; the group/member
become available for reassignment. It does not delete candidate data.

## Settings and protected live display

Administrator opens **Settings** to configure the opening time and a separate display
username/password. There is **no default display password**. Configure credentials
before opening the display. Blank password on later settings updates keeps the existing
password. Passwords are hashed, never shown in forms or audit details, and excluded from
validation-session flash data. Changes to username/password revoke existing display sessions.

Open `/viva-board-display` from any browser/screen and enter those shared credentials.
This login matches module settings, **not the CMO user list**. A CMO login does not bypass
it. It neither signs users into CMO nor logs their application account out when the display
signs out. Display login is rate-limited; sessions last up to 12 hours, subject to normal
application session lifetime/inactivity rules.

The screen shows assigned boards only: post code/name, group, assigned member, populated
optional member-location fields, candidate count and registration numbers. Candidate names,
user email/mobile and account credentials are not sent to the display feed. Current member
location information is used; assignments also keep a member snapshot for historical fallback.

It refreshes automatically approximately every **5 seconds**, without WebSockets or extra
workers. Conditional responses avoid retransmitting candidate lists when nothing changes.
Credentials/session validity are checked on every refresh, including unchanged-data requests.
Expiry/revocation clears the registration data and offers sign-in again. Network failure gives
a visible stale-data warning and retries. The default screen follows today's Bangladesh date,
including midnight rollover; a manually chosen date stays selected.

Display polling does not write a request audit log every five seconds. Display login/logout,
settings changes, imports, pool changes, distribution/corrections and report exports are audited.

## Installation and scope

Apply the root-relative patch, then run:

```bash
php artisan optimize:clear
php artisan migrate --force
```

Compiled frontend assets are included; no new Composer/npm dependency is required. Keep the
normal Laravel scheduler enabled for expired staging cleanup. Existing authentication,
Evaluation System data and report permissions remain intact. Fixed `README.md` is unchanged.

The generic setup-transfer workbook covers its existing datasets plus the new User details;
it does not migrate Viva pools, settings, candidate staging or assignments. Use the module's
candidate import and distribution reports for its workflows, and a database backup for a
complete system copy/history.
