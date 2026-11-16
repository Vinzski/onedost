# OneDOST — Coding Standards

## Stack

- **Laravel 13** (PHP 8.4, Herd) + **Inertia.js v3** + **Vue 3** (Options API)
- **MySQL 8** locally — database `onedost` on `127.0.0.1:3306`. **SQLite** online
  (one file, no database server — see "Going online" below); tests run on SQLite too,
  so code must work on both
- **Bootstrap 5.3** + **bootstrap-vue-next** (CSS from CDN; do NOT add Bootstrap to npm)
- **Remix Icon** (CDN; do NOT add to npm)
- **Ziggy** for named routes in Vue (`route()`)
- **PhpSpreadsheet / PhpWord** for Excel and Word templates; **FPDI + FPDF** (`setasign/fpdi`, `setasign/fpdf`) for PDF templates
- **Pest 4** for all tests
- **Vite 8** for asset bundling (single `resources/js/app.js` entry — no CSS Vite inputs)
- Database driver for sessions, cache and queue
- Inertia SSR is off (`INERTIA_SSR_ENABLED=false`); pages render in the browser only

Local URL: http://onedost.test (served by Herd from `C:\Herd\OneDOST`).

---

## Project layout

```
app/
  Console/Commands/  # artisan commands (e.g. travel:sync-places)
  Enums/             # backed enums: each owns its label, Bootstrap variant and options()
  Http/
    Controllers/   # grouped by domain
    Middleware/    # HandleInertiaRequests shares auth
    Requests/      # Form Requests, grouped by domain
    Resources/     # API Resources
  Models/
  Services/        # all business logic, grouped by domain
  Traits/          # HandlesTransaction

resources/
  css/
    tokens.css     # design tokens, app shell, skeleton + toast animations
    app.css        # imports tokens.css only
  js/
    Components/
      Toast/       # ToastContainer.vue
      ui/          # shared UI components
    Pages/         # Inertia pages (mirrored by domain)
    Shared/
      Layouts/     # AppLayout.vue
      mixins/      # themeMixin.js
      stores/      # toast.js
  views/
    app.blade.php  # Inertia root; CDN links live here

tests/
  Feature/
  Unit/
```

---

## PHP / Laravel conventions

- **Controllers** — thin; delegate all business logic to a Service.
- **Services** — one class per domain, injected via constructor DI.
- **Form Requests** — one per action (e.g. `StoreProjectRequest`). Never validate inline in a controller.
- **DB transactions** — always use `HandlesTransaction::handleTransaction()`. The callback must return `['data', 'message', 'info', 'status']`.
- **Flash messages** — `Inertia::flash(['message' => '...', 'info' => '...', 'status' => true/false])` then `return back();`. Do NOT share flash as a prop: Inertia replays props on partial reloads and the toast fires again.
- **Routes** — named routes only (`route('dashboard')`). Group by domain prefix.
  Every route goes inside the `auth` group in `routes/web.php`; only `login`,
  `login.store` (and Laravel's `/up`) are open.
- **Models** — use `$fillable`; avoid `$guarded = []`.
- No `dd()`, `var_dump()`, or debug calls in committed code.

## Vue / Inertia conventions

- **Options API only** — no Composition API (`<script setup>` is forbidden).
- **`<script>` before `<template>`** in every SFC.
- **No inline styles** except for one-off values that cannot be expressed in Bootstrap utilities.
- **Layouts** — default layout is `AppLayout` (applied automatically in `app.js`). Override per page with `layout: null` or a custom layout component.
- **Components** — every BootstrapVueNext component (`BCard`, `BButton`, …) plus `Link` and `Head` is registered globally; no per-file imports needed.
- **Routing** — always use the `route()` Ziggy helper, never hardcoded paths.
- **`$toast`** — inject toasts via `this.$toast.add(message, type)` where `type` ∈ `success | warning | error | info`.
- **Flash handling** — `app.js` listens for Inertia's `flash` event and turns each flash into one toast. Do not duplicate this in pages. In a visit callback, read it from `page.flash`.
- **Resources** — `JsonResource::withoutWrapping()` is on: a single resource arrives as a plain object; paginated collections keep `{ data, links, meta }`.
- **Same-component navigation** — Inertia reuses the page component when moving between two records of the same page (e.g. edit A → edit B). A `useForm()` created in `data()` keeps A's values; watch the record id and rebuild the form (see `Liquidation/Edit.vue`).
- **Formatting** — use `@/Shared/format.js` (`peso`, `date`, `dateTime`, `daysUntil`); dates travel as `YYYY-MM-DD`.

## CSS / Theming

- Bootstrap utility classes are preferred over custom CSS.
- Custom styles belong in `tokens.css` (global tokens/animations) or a scoped `<style>` block in the component.
- Do NOT import Bootstrap or Remix Icon via npm; they load from CDN in `app.blade.php`. Keep the bootstrap-vue-next CDN version in step with the npm version.
- Dark mode is driven by `data-bs-theme` on `<html>`. The `themeMixin` handles cycling and persistence.
- Skeleton loading: add class `skeleton` to any element; it animates automatically.

## Modules

OneDOST is a portal of operations for DOST Region IX. Each module gets
its own route prefix, `Controllers/<Module>/`, `Services/<Module>/`,
`Requests/<Module>/`, `Pages/<Module>/`, a sidebar link under "Modules" in
`AppLayout`, and a card on the Dashboard. The `travelers` table (saved people)
is shared by every module.

The Deposit Slip and Cheque Tracker is not part of OneDOST: it is its own app,
RPMOne (`C:\Herd\RPMOne`, http://rpmone.test). Migration
`2026_10_07_000012_drop_cheque_tracker_tables` removed its tables from here.

### Booking Request (`/booking-requests`)

The Supply Unit's "Request for Airline Booking": one flight (out, and back
unless one-way) for one or more passengers.

- **Template** — `resources/templates/booking/booking-request.xlsx`, derived from
  the region's form: same layout and wording, but its Excel checkbox controls
  (which PhpSpreadsheet can't tick and Google Sheets can't show) became printed
  ☐/☑ cells, and the shared email cell of passengers 1–2 was split.
  `BookingRequestWorkbook` fills three passengers per sheet (columns G–H, J–K,
  M–N) and adds sheets for more, numbering on (PASSENGER 4…); unused columns
  stay blank. Edit the template in Excel only if cell positions stay the same.
- **Records** — `booking_requests` (flight, travel order, funds, signatories,
  tracking: `submitted_on` → with the Supply Unit, `booking_reference` →
  booked) and `booking_passengers` (one per column). Enums `FlightTrip`
  (one-way / return / multi city) and `Airline`.
- **Passengers remembered** — saving upserts each passenger's booking details
  (`Traveler::BOOKING_FIELDS`: title, first/last name as on the ID, birth date,
  contact, email, ID no.) onto their `travelers` row, matched by
  `traveler_id`, then first+last name, then full name; someone new becomes a
  saved traveller. A blank never erases what was saved.
- **Start from a travel order** — `BookingRequestService::travelOrders()` groups
  cash advances and claims by travel order: trip, destination city ("Cebu City"
  → "Cebu") and everyone on it, filled in as passengers from their profiles
  (`passengers.js` splits "VINCE BRADLEY C. MULOC" into first/last when a
  profile has none).
- **Required on the form** — `BookingRequest::missing()` (ETDs, return date,
  each passenger's contact, email and birth date); `passengers.js`
  `missingFor()` mirrors it live — change both.

### Cash Advance (`/cash-advances`)

Before travel. Papers: Travel Order (from VRAMS, ticked off), Itinerary of
Travel and the No Pending Cash Advance certification (both generated).

- **Documents** (`Services/CashAdvance/`): `CashAdvanceDocuments` builds the IOT
  from `forms.xlsx` (IOT sheet only, via the shared `ItineraryOfTravelSheet`) and
  `NoPendingCashAdvanceCertificate` fills
  `resources/templates/cash-advance/no-pending-cash-advance.docx` (letterhead;
  each `${placeholder}` sits in one run). Dated `requested_on`; amount in words
  via `AmountInWords`.
- **Amount** — always the Itinerary of Travel total (`CashAdvance::requestedAmount()`);
  there is no separate amount field.
- **Start liquidation** — `CashAdvanceService::liquidate()` copies traveller,
  travel order, trip, signatories, Travel Order tick and itinerary into a new
  liquidation (`liquidations.cash_advance_id`, unique) with the advance as its
  cash advance amount. A second call opens the existing one. "Add travellers"
  never copies `cash_advance_id`.
- **Team** — see "Several travellers on one travel order" below. Cash advances
  also download everyone's IOT on the travel order as one workbook, a sheet
  per traveller (`CashAdvanceDocuments::TEAM_ITINERARIES`).
- **Status** — liquidated (its liquidation submitted) · liquidating · upcoming ·
  due · overdue (30 days after return with no submitted liquidation).

### Shared travel code

Cash advances and liquidations share: `Models/Concerns/DescribesTrip` (itinerary
totals, deadline, date wording, initials, signatories),
`Http/Requests/Concerns/ValidatesTrip`, `Services/Travel/TripWriter` (traveller
profile + itinerary), `Services/Travel/ItineraryOfTravelSheet` + `WritesCells`,
and `Components/Travel/*` (TravelerFields, TripFields, ItineraryEditor,
SignatoryFields). Settings for both live in `config/onedost.php` → `liquidation`.

Itinerary legs may leave `date` blank: the leg continues the day above, so the
IOT merges its date cell with that day (and CENRR particulars borrow the date).
Means of transport is optional ("None" in the dropdown).

**Fares from home (fares memo).** `config/onedost.php` → `home_fares` holds the
memo's caps by where a traveller lives and how far: home ↔ office and home ↔
airport/wharf/bus terminal. Each `travelers` row saves `home_area`,
`office_distance` and `terminal_distance` (band labels such as "8–15 km"); the
trip forms send them (`HomeFares::TRAVELER_FIELDS`) and `TripWriter` saves them
on the traveller, never on the trip. `HomeFares::legKind()` decides which legs
follow the memo (place mentions residence/home *and* office/DOST or
airport/port/wharf/terminal/IBT); `Components/Travel/fares.js` mirrors it —
change both. In the form, changing the distances moves residence legs still at
the old fare (or blank); typing a residence leg fills its fare; rides once at
the destination stay hand-entered (the editor shows the memo as a guide).
`commute_fare` / `terminal_fare` apply until the distances are set.

**"Start from a typical trip"** builds the usual legs with the usual times
(home → office 7–8 AM, out 10 AM–2 PM, "Still in" from 8 AM, back 8 AM–2 PM,
office → home 2–3 PM), leaving from the office by official vehicle or from
the airport, wharf or bus terminal (`terminals`; travel times left blank).

**Several travellers on one travel order.** `team()` on both services lists the
records sharing `travel_order_no` (claims: same kind). "Add travellers"
(`addTravelers()`, `POST …/{id}/travelers`, `Components/Travel/TripTeam.vue`)
makes one copy per name: same trip and itinerary (claims: plus reports and
photos), the person's saved details, and their own fares on residence legs
(`TripWriter::newcomers()` / `travelerColumns()` / `itemsFor()`). Names already
on the travel order are skipped; unsaved names get a blank position. Claims
never copy the cash advance, DV/OR numbers or submission date.

**Destination and per diem (EO 77 s. 2019).** `destination_code` (nullable) is
the PSGC code of the place picked in `Components/Travel/DestinationPicker.vue`;
the venue stays free text for the forms. The code's first two digits are the
region, and `config/onedost.php` → `liquidation.dte_rates` maps each region to
its daily travel expense (₱1,500 / ₱1,800 / ₱2,200); `per_diem_rate` applies
only while no destination is picked. Picking a place fills a blank (or
auto-filled) venue and moves per diem figures still at the old rate (full or
half) to the new one; hand-typed amounts stay. The official station
(`official_station_place`, Zamboanga City) shows the 50-km warning.

**Lunch provided.** `lunch_provided` (cash advances and liquidations; copied
by `liquidate()` and "Add travellers") takes `liquidation.lunch_deduction`
(₱150) off the per diem of each day *between* departure and return — never
the days going and coming back. The form does it (`places.js` `applyLunch()`):
ticking moves middle-day figures still at the full rate to rate − ₱150,
unticking moves them back, the typical-trip starter uses it, and a new
destination moves rate − ₱150 figures too. The server only stores the flag.

- Places: `resources/data/places.json` (provinces, cities, municipalities; one
  per line). `Services/Travel/Destinations` reads it on the server (validation,
  `rate()`); `Components/Travel/places.js` lazy-loads it in the browser (search,
  venue guess for older records, rate). Refresh from the PSGC with
  `php artisan travel:sync-places` (`DestinationListBuilder`; it warns if a
  region has no rate in config).

### Reimbursement (`/reimbursements`)

The same record, page and services as a travel liquidation, `kind = reimbursement`
(`App\Enums\ClaimKind`): the traveller claims back their own spending.

- No cash advance: cash advance amount, DV and OR are always null (forced in
  `LiquidationService::fill()`); never offered or linked to a cash advance.
- No Liquidation Report — dropped from the workbook and the checklist.
- Amount claimed = total spent (`Liquidation::reimbursement()`).
- No 30-day deadline: status is just submitted / pending / travelling.
- Routes are registered for both prefixes from one loop in `routes/web.php`;
  `ClaimKind::fromRoute()` reads the kind from the route name, and the
  `liquidation` route binding (AppServiceProvider) only finds records of that
  kind, so `/liquidations/{id}` never opens a reimbursement or vice versa. Pages
  get `kind` + `routePrefix` props and build routes with `to(name, params)`.

### Travel Liquidation (`/liquidations`)

One record per traveller per trip; every post-travel document is generated from it.

- **Documents** (`Services/Liquidation/`):
  - `LiquidationWorkbook` fills `resources/templates/liquidation/forms.xlsx`:
    Itinerary of Travel, Travel Accomplishment Report, Certificate of Travel
    Completed, CENRR, Liquidation Report. Sheets get the traveller's initials
    ("IOT VCM"). Itinerary rows grow/shrink to the trip; the CENRR sheet
    repeats its two-certificate block per pair of certificates and is dropped
    when there are none.
- **CENRR** — `liquidations.receipt_certificates` (JSON): a list of
  certificates, each up to 3 lines (particulars + amount) totalling at most
  ₱300 (`cenrr_max_lines` / `cenrr_max_amount`). Entered directly; "Fill from
  itinerary fares" in the form packs the itinerary's fares into certificates.
- **Checklist** — `Liquidation::checklist()` lists the papers submitted with a
  liquidation (generated ones + the Travel Order from VRAMS and the
  Certificate of Appearance, tracked by `has_travel_order` /
  `has_certificate_of_appearance`). `Edit.vue` mirrors the same rules live —
  change both together.
  - `ActivityReportDocument` builds the Activity Report with PhpWord (landscape,
    narrative + photos).
  - `LiquidationDocuments` serves each as a temp download, or both as a zip.
  - The "no pending cash advance" certification is deliberately NOT generated —
    it belongs to the cash advance request, not the liquidation.
- **Templates** were derived from the region's own files. Edit them in Excel only
  if cell positions stay the same — the generator writes to fixed cells.
- **Dropdowns** — fund cluster and means of transport come from
  `config/onedost.php` (`fund_clusters`, `transport_means`) and render with
  `Components/ui/SelectOrOther.vue`; "Others (indicate)" stores free text.
- **Money** — totals, refund (cash advance − spent) and reimbursement live on the
  `Liquidation` model. Deadline = return date + 30 days (COA Circular 2012-001).
- **Travellers** — saving a liquidation upserts a `travelers` profile by name; the
  form offers saved names and fills in position/station/division.
- **Photos** — stored on the `local` (private) disk under `liquidations/{id}/`,
  re-encoded to JPEG at most 1600px; max 6 per liquidation. On a new
  liquidation, picked photos are held in the browser and sent with the first
  save (multipart; Inertia's default indexed array keys keep `items[0][...]`
  intact). After that they upload immediately via the photo endpoint.
- Defaults (signatories, per diem rate, fares, fund cluster) are in
  `config/onedost.php` → `liquidation`. Signatories are copied onto each record.

### Daily Time Record (`/dtr`)

Why each blank on someone's DTR is blank: their DTR, imported from the DTR
system, plus notes made on the day or later.

- **Import** — `DtrPdfReader` reads the DTR system's PDF (CSC Form No. 48, made
  by dompdf; sample in `storage/app/imports/files/dtr/`). The page holds copies
  side by side (days 1–15, 16–30), each shading the other half's rows dark grey,
  so an empty white row is a real blank. Columns come from the header labels
  (Arrival, Departure, Arrival, Departure, Hours, Minutes) and each text run goes
  to the nearest one; text that isn't a time ("NON-WORKING DAY", "HALF DAY") is a
  remark covering the times it spans. The printed name ("MULOC, VINCE BRADLEY C.")
  is matched with `Traveler::findPerson()`, or saved as a new person. Importing
  again replaces those days (`dtr_days`: one row per person per day, blank is
  null). A PDF it can't read fails validation on `file`, saying why.
- **Notes** — `dtr_notes`: one day or a run of days (two months at most), which
  times (`DtrSlot`: Time in, Lunch out, Lunch in, Time out = A.M./P.M.
  arrival/departure; empty = whole day, and all four are saved as that), a
  reason (`DtrReason`), details (required for "Other") and the actual time
  (kept only for one time on one day). A note doesn't wait for the DTR: "Something
  off today?" saves it on the day and it's matched when the DTR is imported.
- **Explained automatically** — `DtrService::away()`: a liquidation or
  reimbursement, cash advance or booking request (by `traveler_id`; trips also by
  `traveler_name`) spanning a day, or a leave application that isn't
  disapproved, explains all of that day's blanks. One trip shows a day, best
  evidence first: liquidation, cash advance, booking.
- **Day status** — off (a remark across all four times), complete, explained or
  unexplained; not imported / upcoming otherwise. Only days up to today count;
  "today" is Manila's (`onedost.timezone`) but kept at app-timezone midnight,
  like every stored date, so they compare.
- **Print** — `/dtr/print`: the month's blanks and their explanations (any still
  unexplained flagged), with lines for the employee and the In-Charge
  (`onedost.dtr.in_charge_*`).
- The page opens on whoever's DTR was last imported or noted, at this month, and
  lists other months that still have blanks to explain.

#### The standalone DTR page (`standalone/dtr/`)

The same DTR feature as a single page with no server, for free static hosting
(Netlify Drop, GitHub Pages) or opening `index.html` straight from disk. Records
live in the browser's `localStorage` and travel as a pretty-printed JSON file
(Save file / Open file; opening merges or replaces). Not part of the Laravel app
or its `app:package` zip.

- Plain files, no build: Vue 3 (Options API, template strings), Bootstrap and
  Remix Icon are copied into `vendor/` so it works offline; `sw.js` keeps the
  offline copy (bump `CACHE` when files change). Classic scripts that attach to
  `globalThis` (`DtrReader`, `DtrRecord`, `DtrApp`) so they run from `file://`.
- `js/reader.js` mirrors `DtrPdfReader`, and `js/record.js` mirrors
  `DtrService::month()`'s statuses (no trips or leave: those live in OneDOST),
  `DtrReason` and `DtrSlot` — **change both sides together**.
- Tests: `npm run test:dtr` (Node's test runner): the reader on a synthetic DTR
  and the sample PDF, the record rules, files and merging, and a server render
  of the whole page.

### Leave Application (`/leave-applications`)

The Application for Leave, CSC Form No. 6 (Revised 2020), as TOS files it: one
record per employee per leave.

- **Template** — `resources/templates/leave/application-for-leave.pdf` is the
  region's filled-in sample (`storage/app/imports/files/Leave-Form_TOS.pdf`, an
  Excel export) with the sample entries and signatories cut from the page's
  content stream (an incremental update; every line, box and label kept).
  `LeaveApplicationForm` imports that page with FPDI and writes over it:
  values in Helvetica Bold 8.8 in the text boxes the form left for them, a
  ZapfDingbats ✔ centred on each ticked box. Positions are PDF points measured
  off the template (`SLOTS`, `*_BOXES`, `DETAIL_LINES`): a new template means
  measuring again. Text that doesn't fit is set smaller, then cut with "…".
  The download opens inline, to print.
- **Records** — `leave_applications`: the employee (office, last/first/middle
  name, position, optional salary, date of filing), 6.A type (`LeaveType`;
  "Others" writes its own text instead of ticking), 6.B box (`LeaveDetail`;
  `LeaveType::details()` says which boxes go with which leave) and its line
  (place, country or illness), 6.C working days (halves allowed) and dates,
  6.D commutation, signatories (7.A certifier, 7.B recommender, 7.C approver,
  defaults in `config/onedost.php` → `leave`) and tracking (`submitted_on`,
  `outcome` → `LeaveOutcome`). The rest of section 7 stays blank for the
  signatories.
- **Days and dates** — the form counts weekdays from the first to the last day
  (`Components/LeaveApplication/leave.js`; holidays are taken out by hand) and
  a typed count such as 0.5 survives date changes. Inclusive dates are worded
  from the dates ("October 7-8, 2026": `LeaveApplication::wordDates()` and
  `leave.js` `inclusiveDates()` — change both) unless typed in by hand.
- **Employee remembered** — saving finds their `travelers` row
  (`Traveler::findPerson()`: id, then first + last name, then full name, as
  booking requests do) and saves the name parts there (`middle_name` is this
  form's); someone new becomes a saved person. Their position fills only a
  blank, so an itinerary's wording isn't changed.

## Testing

- All tests are written with **Pest** (`tests/Feature/`, `tests/Unit/`).
- Use `RefreshDatabase` for any test that touches the DB.
- Feature tests that render a page call `$this->withoutVite()`.
- Assert flash with `->assertInertiaFlash('status', true)`.
- Feature tests run signed in (`tests/Pest.php` acts as an unsaved user); a test
  of the login itself starts with `$this->app['auth']->forgetGuards()`.
- Run with: `php artisan test`

## Login and security

- **One login, no sign-up.** `php artisan app:user {email}` makes it or sets a
  new password (and signs out remembered devices); there's no password-reset
  email. `LoginRequest` gives one answer for a wrong email or password, and
  locks out after 5 wrong tries a minute (email + IP) or 20 an hour (email).
- **Signed out, the page gets only the login routes** (`config/ziggy.php`
  group `guest`, used by `@routes` in `app.blade.php`) — not the app's route list.
- **`SecurityHeaders`** (web group): nosniff, SAMEORIGIN framing, same-origin
  referrer, no indexing, and HSTS once live on HTTPS. In production
  `AppServiceProvider` forces https URLs when `APP_URL` is https and prohibits
  destructive DB commands (`migrate:fresh`, `db:wipe`).
- **A 419** (page open past the session) goes back with a flash instead of a
  bare error page (`bootstrap/app.php`).
- **Uploads:** the `local` disk keeps `serve` off — photos are served by their
  own route, behind the login. Photos are scaled down in the browser before
  sending (`Shared/images.js`) and rejected over 10,000 px a side, so a small
  server never decodes a huge image. The DTR reader caps each PDF stream at 8 MB.
- **Shared props:** `auth.user` carries name and email only.

## Going online (SQLite, free PHP hosting)

`docs/DEPLOY.md` is the step-by-step guide (AlwaysData free plan). In short:

- `php artisan app:package --first --url=https://…` — the first upload: the app
  with production-only libraries (a `composer install --no-dev` in a temp
  folder), built pages, a SQLite copy of every record (`SqliteExporter`), the
  uploaded photos, and a production `.env` with a new key. Later updates:
  `php artisan app:package` (no `.env`, database or photos, so it can't
  overwrite the live data). Zips land in `build/` (git-ignored).
  `PackageApp::NEVER` keeps `public/hot`, local caches and page sources out;
  `storage/app/imports` (reference files) never ships.
- `php artisan app:export-sqlite [path]` — the same SQLite copy on its own.
  DATE columns are rewritten as `Y-m-d 00:00:00`, the way Eloquent writes them
  on SQLite. Sessions, cache, jobs and reset tokens aren't copied.
- **Download backup** (`/backup`, side menu) — the database as SQLite plus
  `storage/app/private`, as one zip.
- The host runs Linux, where file names are case-sensitive and Windows' aren't:
  class names, page names and template paths must match their files exactly.
- Production needs PHP 8.3+ with gd, zip, dom/xml, fileinfo, mbstring, zlib and
  pdo_sqlite; `php artisan optimize` must keep working (no `env()` outside
  `config/`, no closures that can't be cached).

## Git workflow

- `main` — production-ready only; no direct commits.
- `staging` — QA branch.
- `dev` — all feature work happens here; branch off `dev` for features.
- Commit message format: `type: short description` (e.g. `feat: add project listing`, `fix: null flash crash`).

## Commands

```bash
# Start everything — server, queue worker, Vite and the log viewer — in one
# dashboard (`php artisan dev`; Ctrl+C stops it all)
composer run dev

# Run tests
php artisan test
npm run test:dtr        # the standalone DTR page (standalone/dtr/)

# Build assets
npm run build

# Refresh the Philippine place list (PSGC) used for travel destinations
php artisan travel:sync-places

# Make the login, or set a new password
php artisan app:user you@example.com

# Make the zip to upload (docs/DEPLOY.md); --first adds your data and a .env
php artisan app:package --first --url=https://you.alwaysdata.net
php artisan app:package

# Copy every record into a SQLite file
php artisan app:export-sqlite

# Lint PHP
./vendor/bin/pint
```

## Environment notes

- `php`, `composer` and `laravel` are Herd `.bat` shims — run them from PowerShell, not Git Bash.
- `.npmrc` sets `ignore-scripts=true`.
- `composer run dev` is Laravel's `php artisan dev`. Its log pane, `pail`, needs the pcntl
  extension, which PHP on Windows lacks, so `AppServiceProvider` registers
  `php artisan log:tail` (`app/Console/Commands/TailLog.php`) as the logs pane there.
- `package.json` overrides `shell-quote` to `^1.12.0` because every `concurrently` release pins a vulnerable `1.9.0`. Remove the override once `concurrently` ships a fix.
