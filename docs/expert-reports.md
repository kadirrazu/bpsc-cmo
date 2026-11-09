# Expert Evaluation Reports

Open **Expert Evaluation System → Reports** for detailed expert summaries and paginated
submissions. Use the highlighted **Report Center · Statistics & Downloads** link near
the page heading to open the separate landing page at `/modules/evaluation/reports`.
The landing page contains filters, statistics, report downloads and Survey Activity;
**View Detailed Reports** returns to the detailed page. Navigation preserves filters
and resets unrelated pagination, so the detailed page stays concise.

Administrator and Chairman can read both pages and download XLSX, PDF and legacy CSV
reports. Member and Staff cannot open reports or any report download endpoint. This
report access is separate from **Administration → Excel Import & Export**: setup data
migration, bulk import, staging and setup workbook export remain Administrator-only.
Expert Pool CRUD permissions and users' own response access are unchanged.

## Filters and statistics

Use the searchable Expert field, Pool type, Survey, viva date range and evaluator
role to filter the report. Summary browsing remains one expert at a time;
submissions remain 20 per page with numbered pagination.

| Statistic | Meaning |
| --- | --- |
| Total Surveys | Current, non-deleted workspace surveys, including drafts and closed surveys |
| Active Surveys | Current, non-deleted surveys with status `active`; counts describe now |
| Evaluated Experts | Distinct expert IDs among responses matching the filters |
| Participants | Distinct evaluator user IDs among matching responses, across all experts/surveys |
| Submissions | Number of matching evaluation responses |
| Surveys with Responses | Distinct survey IDs represented by matching responses |

Draft/closed totals accompany the workspace cards. Workspace survey totals are
not limited by viva dates or the selected expert; the four response cards do
follow every applied report filter and are independent of the current expert page.
Removed experts/surveys retain response history. Current workspace totals exclude
removed surveys, while historical response counts and Survey Activity can include them.

## Download one or multiple experts

1. Apply the desired report filters.
2. On the Report Center landing page, under **Download Expert Reports**, either keep **All experts matching current
   filters**, or search and add one/more experts. Selected experts appear as removable
   items; duplicate selections do not duplicate output. Clear selection returns to the
   filtered scope. Searching includes name, designation, organization and specialization.
3. For **Selected experts**, that selection replaces the single Expert filter. Date,
   survey, pool type and evaluator role filters still apply.
4. Choose **Download Excel** or **Download PDF**. Downloads include all matching
   submissions, independent of any page currently displayed in the browser.

Expert profiles without matching submissions may be explicitly selected; they are
listed with zero counts and a no-matching-data message. Filtered scope lists experts
with matching response data. Up to 500 expert IDs may be selected per request. The download form uses a CSRF-protected POST so larger selections do not run into browser/server URL-length limits.

### Excel workbook

- **Overview**: filter scope, generated timestamp and statistics.
- **Experts**: current profile, designation, organization, specialization, profile status
  and per-expert matching submission/participant/survey counts.
- **Question Summary**: answered/skipped counts, rating bounds, averages and distributions.
- **Submissions**: one row per response, including evaluator, viva/submission dates and reference.
- **Answers**: one row per question answer with submitted question text, hints and answer value.
- **Survey Activity**: current survey status and matching response/participant/expert counts.

Numeric counts/averages remain numeric; names and answers are literal cells, including
text beginning with `=`. The report is for analysis; it is separate from the setup-data
migration workbook and cannot be uploaded through Excel Import & Export.

### PDF

A4 report with filter overview and Survey Activity, followed by separate expert sections
containing question summaries, rating averages, submissions and historical answer snapshots.
Bengali text uses the project's Nikosh font via script/language font selection; English uses
DejaVu Sans. BPSC header, IT Section credit and page numbers repeat on every page. Long
narrative answers and long option distributions flow over page boundaries.

## Administrative Survey Activity

Administrator and Chairman see a table of surveys, including zero matching submissions, with current
status, filtered submissions, distinct evaluated experts, distinct participants and latest
viva date. The table uses numbered pagination (10 surveys per page). Removed surveys are
marked **Removed**. Use this to identify active surveys with no participation without
confusing them with surveys missing from a response-only listing.

Counts in this table follow all response filters. The survey inventory itself includes
all surveys, or the selected Survey if one is set. Status is current rather than status
at the time of submission. It does not imply which individual users have not participated.

## Limits and consistency

- Excel supports up to 5,000 submissions and 25,000 answer rows per file.
- PDF supports up to 500 submissions and 5,000 answer rows per file.
- Larger reports return a validation message; narrow the filters or download in batches.
- Downloads run in a database transaction and export actions are audited.
- Skipped answers are excluded from averages. Different question text/options/rating
  scales remain separate; averages across different scales are not combined.
- Multiple-select distributions count selections and may exceed submission counts.
- User-visible profile information is current; submission/answer sheets preserve the
  original expert/evaluator/survey/question snapshots recorded with each response.
