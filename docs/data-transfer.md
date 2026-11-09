# Excel import, export and setup migration

Access: **Administrator only**, under **Administration → Excel Import & Export**.
Direct URLs, file download, staging, preview, confirm and cancel enforce the same permission.
Chairman, Member and Staff cannot use any transfer operation.

## Choose a dataset

| Selection | Workbook content |
| --- | --- |
| All setup data | Designations, Units, Users, Experts, Questions, Surveys |
| Users | Users and their Designations/Units |
| Expert Evaluation System | Experts, Questions and Surveys |
| Expert Pool | Expert and Departmental Expert profiles |
| Question Bank | Reusable questions, hints, options and rating settings |
| Surveys | Surveys plus the Question Bank, question assignments and display order |
| Designations / Units | The selected master data |

These workbooks migrate account/setup data and definitions. They are **not a database backup**:
evaluation submissions, historical answers/averages, audit history, deleted records,
sessions, API tokens and password-reset tokens are excluded. Import assigns new destination
record IDs/timestamps; a newly imported survey is attributed to the importing Administrator.
Retain a database backup for full history and disaster recovery.

## Download sample, template or export

- **Download Sample .xlsx** provides filled examples for the selected dataset. Replace the
  examples with real data before importing. User samples never contain a working password.
- **Download blank template** keeps the same sheet names and headers, without sample data.
- **Export .xlsx** contains current, non-deleted system records. Inactive records are included.
- User exports omit passwords by default. Optionally select **Include password hashes for
  account migration** to preserve login passwords. This is sensitive account data: protect
  the workbook and remove it when the migration is finished. Plaintext passwords are never exported.
- The `Format` sheet contains version identification and concise field instructions.

## Bulk import workflow

1. Download the dataset's sample/template. Keep worksheet names, headers and the `Format` sheet.
2. Fill rows below the headers; remove unused sample rows. Keep identifiers, phone numbers,
   UUID keys and emails as text in Excel so leading zeroes are preserved.
3. Upload `.xlsx` (maximum 10 MB, 5,000 data rows total, 40 MB expanded XML).
4. Review the summary of **new** and **update** rows. Choose a worksheet to inspect its
   paginated preview (20 rows per page). Errors include worksheet and Excel row number.
5. Resolve every error by cancelling and uploading a corrected workbook. Any invalid row
   blocks the entire import. No application records are written during staging.
6. Tick the review checkbox and press **Confirm & Import**. All rows save in a single
   transaction; a failure rolls back the entire import. Cancel discards the preview.

Staging belongs to the Administrator who uploaded the file, is encrypted in the database,
and expires after one hour. Passwords and hashes are masked in previews and absent from audit
details. Confirmation revalidates permissions, rows and relationships, checks for changes since
preview and locks affected records. Used previews cannot be confirmed twice. Successful imports
clear the staged payload immediately. Expired staging is removed on the next upload and by the
daily scheduled cleanup (keep the normal Laravel scheduler enabled).

## Matching and references

`key` is a portable UUID, separate from the database ID. It travels between installations.
The upgrade migration gives existing records a key; CRUD gives every new record a key.

- Keep exported keys to update/re-import records without duplication.
- Leave `key` blank to create a new record. The preview generates a UUID. For cross-sheet
  references in one workbook, provide UUIDs explicitly (copy the sample pattern with new UUIDs)
  or import and then export the referenced records first.
- Designations also match by name, Units by code, Users by email. This reuses independently
  seeded masters on a new installation. Their existing destination keys are preserved, and
  incoming references map to those records.
- Duplicate keys/natural keys and rows resolving to the same record are rejected. Conflicts
  with deleted records must be resolved before import. Import does not restore or delete records.
- Names alone do not merge Experts, Questions or Surveys. Keep their keys when updating.
- Unit codes cannot be renamed by import, since existing users reference those codes.
- All fields in an imported row replace the corresponding values; blank optional values clear
  them. User passwords are the exception: both password fields blank preserves an existing password.

## Field rules

| Field | Input |
| --- | --- |
| `is_active`, `is_required` | `1` or `0` |
| Expert `type` | `expert` or `departmental` |
| User `role` | `admin`, `chairman`, `member`, `staff` |
| `designation_key`, `unit_key` | UUID of an active master in the workbook or destination |
| User `mobile_number` | Optional; 11 digits, stored as text |
| User `password` | Optional plaintext input for new/reset credentials; at least 8 characters, mixed case, number and symbol |
| User `password_hash` | Optional exported hash, supported by destination's configured hashing algorithm |
| Question `type` | `select`, `text`, `textarea`, `radio`, `multiple`, `checkbox`, `date`, `time`, `rating` |
| Question `options` | JSON list, e.g. `["Yes","No"]`; select/radio/multiple need 2–50 distinct options |
| `rating_min`, `rating_max` | Defaults 1 and 5; maximum must exceed minimum; at most 101 scale values |
| Survey `status` | `draft`, `active`, `closed`; active needs at least one active assigned question |
| `allowed_roles` | JSON list: `["chairman","member"]`, `["chairman"]`, or `["member"]` |
| `question_keys` | Ordered JSON list of Question UUIDs; `[]` for no assigned questions |

Provide either `password` or `password_hash`, never both. New users with neither get a random,
unpublished password: set their password from **Users → Edit / Password** before they sign in.
No emails are sent automatically. Existing credentials stay unchanged when both fields are blank.
An Administrator cannot use import to remove their own active Administrator access.

Formulas are rejected; paste values instead. Export writes literal strings, including text that
starts with `=`, to avoid executing spreadsheet formulas. Unsupported sheets/formats are rejected.

## Moving setup to another installation

1. Update both installations to v0.7.0 or later and run the database migrations.
2. On the source, export **All setup data**. Include password hashes only if retaining user
   passwords is needed; source/destination must use compatible password hashing configuration.
3. Sign in as a local Administrator on the destination and upload the workbook.
4. Review matching seeded Designations/Units and any matching User email addresses. A row with
   the same email updates that destination account, so review roles and credential choices carefully.
5. Confirm, then check Users, Expert Pool, Question Bank and Survey question order.
6. An active survey preserves its source status and allowed roles; review these before confirming.
