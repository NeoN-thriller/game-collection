# Game Collection

A self-hosted, invite-only web app for tracking a physical video game collection — what you own, what condition it's in, what it's worth, and what you still want.

Built with plain PHP, MySQL and vanilla JavaScript. No frameworks, no build step, no Composer — drop the files on a PHP-enabled web server, point it at a database, and go.

---

## Features

### Collection
- **Per-system game lists** — each system (e.g. *PAL Nintendo DS*) has a master list of games maintained by the admin.
- **Track every copy** — mark games as owned, and record multiple copies of the same game, each with its own details.
- **Rich per-copy details** in a slide-out edit drawer:
  - Condition — a simple label (*Mint / Good / Fair / Poor* by default), or a point grade (see below)
  - Completeness (*Sealed, CIB+, CIB, No Manual, No Box, No Game, Cart Only, Box Only, Manual Only* by default — fully customizable)
  - Played status (*Started, Finished, Stuck, Cheated, 100%* by default — fully customizable)
  - Price paid, personal price, and a buy-range (min / max) for wishlist hunting
  - Which price tier (loose / CIB / new) counts toward your collection value
  - Custom tags, notes, and an "upgrade wanted" flag with a reason
- **Photos** — upload photos per copy, rotate and crop them (free crop, Cropper.js), and choose a primary photo. Uploads are automatically resized and EXIF-rotated (size and quality configurable by the admin).
- **Delete a copy** for good from the edit drawer (its grading, photos and report link go with it; later copies move up one number).
- **History** — if the admin allows it, the drawer shows your own changes to that copy (from the activity log).
- **Finished / Started counters** — how many games you've finished or started, on the Collection page and in the dashboard totals (each played status counts as finished, started or neither).
- **Filtering and search** — by title, condition (label, point / simple grades, minimum score), completeness, played status, edition, variant, tag, owned / not owned, wishlisted, and upgrade-wanted.
- **Barcode scanning** — a **Scan** page (`scan.php`, linked in the header) reads EAN-13, EAN-8 and UPC-A codes with the phone or laptop camera, typed digits, or a USB / Bluetooth scanner (also straight from the header search box: type or scan a barcode and press Enter). A scan can **look up** the game (your copies, condition, completeness), **add it as owned** at once (with Undo), or put it on the **wishlist**. Barcodes are shared by everyone on the site and start empty: an unknown code is linked to its game right from the scanner (a code on several editions asks which one you hold; a print variant on a code is filled in on the new copy). A match in a system you hide offers to show it again. The edit drawer has a **Barcodes** section, and the collection table an optional **Barcodes** column. For bulk work, **Add barcodes** (`barcode_add.php`) lists one system's games: click a game and keep scanning (camera and/or USB scanner), each code is linked to that game and shown next to it; a code already on another game asks first, and your own new codes can be taken back for 12 hours. Optionally, selecting a game also opens its price history page (when it has a price link), Wikipedia or one of your own search sites in a side window, so you can check you have the right game.
- **Search all systems** — a search box in the header (press `/` to jump to it) finds games by title or edition across every visible system, grouped per system with owned copies and wishlist marks; optionally owned games only. Choosing a result opens that game in its system. A search that finds nothing in the current system offers the same search in all systems.
- **Configurable columns** — choose which columns are visible in the collection and wishlist tables, and in which order, from the **Columns** menu in the toolbar (switch on/off, drag or ↑ ↓; saved per user, also under Settings → Language & Appearance). With IGDB connected, the collection table also offers **Released, Developer, Publisher, Genres, User score** and **Critic score** (sortable).
- **Price changes** — a small ▲ / ▼ next to a loose, CIB or new price that the last price import of that game moved up or down (hover it for the old price).

### Editions & variants
- The admin can link games that the price source lists separately (the original, *[Platinum]*, *[Player's Choice]* …) into one **edition group**; the catalogue suggests likely groups, which can be accepted, adjusted or rejected.
- **Per user** (Settings → Editions & Variants): show one row per game with its editions folded under it, or one row per edition; whether a wish is for *any* edition or the exact one; and optional **print variants** (e.g. *UK*, *Benelux*) per copy.

### Compilations
- A compilation is a catalogue game that contains other games of the same system (e.g. *Dishonored Prey: The Arkane Collection* → *Dishonored [Definitive Edition]* and *Prey*). A game can be in any number of compilations.
- **Admin:** Settings → Catalogue → Compilations suggests likely compilations (titles with *Collection*, *Trilogy*, *Bundle*, … or with other games' names in them); accept, adjust or reject each one. Any game's contents can be edited with the **Contents** button in the game list.
- **Per user** (Settings → Editions & Variants): a compilation counts as its own game, or the games inside it count as owned (the compilation itself is then left out of the game totals).
- The drawer shows what a compilation contains and which compilations a game is in. A wished game stays on the wishlist when you own it in a compilation, with an "In your …" mark.

### Game info & covers (IGDB)
- Optional: connect the free [IGDB](https://www.igdb.com) API (a Twitch app of your own, two lines in `config.php`, non-commercial use) for **developer, publisher, genres, release date, user rating** (with its number of votes) and **critic rating** (only when there is one) in the edit drawer, plus a link to the game's IGDB page in the drawer's links. The info is site-wide: an admin matches the catalogue once, everyone sees it.
- **Release date per region:** the date of the system's region (PAL: Europe, then Australia / New Zealand) → the worldwide date → the earliest date elsewhere, shown with its region, e.g. *Mar 1990 (NA)*. TBD dates are skipped.
- **Matching** (Settings → IGDB): set each system's IGDB platform, then **Match** looks the games up by title on that platform: loose title comparison (accents, *&* / *and*, *Disney's …* prefixes, subtitles), alternative and regional titles (PAL names such as *Blue Shadow*). Sure matches are linked automatically, the rest wait under **To review** with their candidates; pick one, search by name, paste an igdb.com link or IGDB id, or mark a game *Not on IGDB*. A game added by hand is looked up straight away, and a renamed game again; imported games are left for **Match**, and while games are waiting, admins see a **To match** badge on Settings → IGDB and a notice on the dashboard. **Match all systems** runs Match on every system that still has games not looked up, one after another, and **Refresh all systems** does the same for Refresh (both leave out systems set to *Don't match*; Stop ends the whole run). A system that will never match (e.g. a *Hardware* system with consoles and peripherals) can be set to **Don't match**: Match and Refresh skip it, its games don't count as waiting and get no IGDB lookup when added.  Without the two IGDB lines in `config.php`, Settings → IGDB shows how to connect it. **Refresh** fetches the info of matched games again (ratings change), on demand.
- **Editions and compilations:** an IGDB entry that is an edition (*… Special Edition*) takes missing fields from its main game; a catalogue edition without its own match shows another edition's info; a compilation without its own match shows the combined developers / publishers / genres and earliest release date of the games inside it, and the drawer lists each contained game with its info.
- **Covers:** IGDB's cover (the system's regional box when IGDB has one) is stored as a link (shown from images.igdb.com, nothing is downloaded). Settings → General → **Covers** sets whether games show the uploaded cover or IGDB's; single games can differ (Settings → Catalogue game list, or the cover switch in the drawer). When a game lacks the chosen cover, the other one is shown.

### Condition grading
- Two methods, chosen per user in **Settings**: *Simple only*, *Points only*, or *Both* (with a default for new copies and a Simple | Points switch per copy).
- **Simple:** one label per copy.
- **Points:** every part of a copy (box, cartridge, manual, disc, jewel case…) starts at **100** and loses points for logged defects (+/− counters, and pick-one levels such as *Fading: minor / moderate / heavy*). The copy's score is a weighted average of the parts that are present — missing parts are skipped, not penalised.
  - A quantity per part (e.g. 2 posters, each graded separately) and your own extra items (e.g. a magazine flyer).
  - The score maps back to a label, so badges, filters and dashboard stats work the same for both methods.
- Switching method never loses data: point grades and simple labels are both kept on every copy.
- **Defect explanations:** every defect can have a description and up to 3 example photos (admin); an ⓘ next to the defect shows them in the drawer and on the condition report.
- The admin manages the **grade labels** (names, colours, the score each one starts at), **format profiles** (which parts a copy has and their weights, with a default per system) and **component templates** (categories and defects). Defaults are included for cartridges (cardboard box, dust sleeve, inner tray, plastic case), discs (jewel case, DVD case, slipcover / steelbook), UMDs, big-box PC games and boxed consoles.

### Condition reports & labels
- **Share a condition report** of an owned copy: a public link (`grade.php?t=…`) that shows the grade, the breakdown per part and the photos — never prices, notes, tags or your username. *Live* reports follow the current grading; a *snapshot* stays as it was. Links can be renewed or stopped at any time.
- **Photo tags:** mark which part (and which of its defects) a photo shows; the report groups the photos accordingly.
- **Printable labels** with a QR code to the report, in sticker sizes of your choice. Users design their own label templates (Settings → Label templates) and can share them; the admin manages the site-wide sizes and the default template.

### Dashboard
- Overall totals: systems, games, owned, completion %, copies, upgrades, wishlisted, total spent, owned value, average condition score, and the Finished / Started counters.
- A completion card per system with progress, spend, value, condition breakdown (per grade label), average score, counts, and a small value trend line.
- Hide systems you don't collect for, and drag the cards to reorder them.
- **Collection value over time** — a graph under the system cards: Total, By system (stacked) or Share %, with 3M / 6M / 1Y / All presets and two handles to pick any range (the cards' trend lines follow it). A point is recorded after every price import (all imports of one day form one point, e.g. when systems are updated one at a time), when games are added, removed or edited, and after a manual price change; the header splits a change into price effects and games added or removed. Tracking starts the day the 0.9.6 update runs (old prices are not known). **Hide graphs** folds the card away; Settings → Language & Appearance → *Show value graphs* removes the graphs from the dashboard completely (values keep being recorded).

### Pricing
- Import a **price CSV export** to add or update games with loose, CIB and new prices, product links and cover art.
- Matching order: price source product ID → console + title → add as a new game.
- A preview step shows what will be matched, added or updated before anything is written.

### Wishlist
- A dedicated wishlist view with your target buy prices.
- **Auction / search sites** — define your own search-link templates (e.g. eBay, Marktplaats) using `{system}`, `{title}` and `{region}` placeholders to get one-click searches per game.
- **Public sharing** — optionally share a read-only wishlist link (`wishlist.php?token=…`). The link can be regenerated at any time, which kills the old one.

### Backup & restore
- **Your own data** (Settings → Backup): export your collection (entries, condition grades and option lists) as JSON and import it again later (merges with existing data). Photos are backed up separately as per-system zips (kept for 24 hours).
- **Full site backup** (admins, **Settings → Site Backup**) — one zip with any mix of the database (an SQL dump of every table), all users' photos, and the site files (icons, default covers, defect example photos, languages, themes). Backups stay on the server in `uploads/backups/site/` (never served directly) until deleted. Big backups are built in short steps, so they don't run into the web server's time limit. Optional daily / weekly / monthly schedule (started by an admin's page view, no cron needed), keeping the last N; the database update on **Settings → Updates** saves a database backup first. Restoring is done by hand (import `database.sql`, copy `uploads/` back); the steps are on the page and in the zip's `README.txt`. The dump leaves out the list of stored backups; after a restore, zips still in `uploads/backups/site/` are picked up again (from their `backup.json`), so they can be downloaded, deleted and pruned as before.

### Admin settings
Admins see an extra **Site** group in **Settings** (General, Languages & Themes, Grading System, Label sizes, Catalogue, Barcodes, IGDB, Database export, Users & Invites, Activity log, Site Backup, Updates).
- **Site settings** — site name (and how its words are coloured), currency symbol and number format, date format, timezone, default region, the grading method and the completeness / played options new users start with, image settings (max width / height and JPEG quality), and with IGDB connected which **covers** games show (uploaded or IGDB). Prices are never converted: import them in your own currency.
- **Themes** — pick the site default theme; users can choose their own in Settings. Drop a `.css` file into `assets/themes/` to add one.
- **Languages** — English and Dutch included. Download a language file, translate it, upload it again; the page shows which texts are still missing compared to English. Users choose their own language in Settings.
- **Condition grading** — edit grade labels, format profiles, component templates and defect explanations; recalculate scores; **export / import the whole grading system** as JSON (merge by name, or replace), or reset it to the built-in defaults.
- **Label sizes** — the sticker sizes everyone can use for condition report labels.
- **Catalogue** — add **systems** (with an icon and region; optionally a second icon for light themes, used when the page has a light theme) and maintain their **game lists**: add titles one by one (optionally with their price source ID, price link and loose / CIB / new prices, which can be edited later too), bulk-import a pasted list (duplicates are detected and skipped), or import a price CSV; manage edition groups and compilations. Games are never deleted, only hidden.
- **Users & Invites** — generate and revoke **invite codes** (registration is invite-only), set users to active / inactive / banned, reset passwords, and view and clear **login lockouts**.
- **Barcodes** — every linked code, codes users reported as wrong (only admins remove barcodes; users report them), unknown scans waiting to be linked, codes on several games (different systems flagged as probable mistakes) and games without a code; edit, move, remove, CSV import (with a dry-run check) and export; a switch that turns barcode scanning off for the whole site (the data is kept); who may link barcodes (all users or admins only), how long unknown scans are kept, and a camera check for this browser.
- **IGDB** — connection help (the only thing shown until IGDB is connected), each system's IGDB platform, Match / Refresh with progress, and the games per state (to review, matched, not on IGDB, not looked up) with the matching tools described above.
- **Database export** — the game catalogue (systems, games, editions, barcodes; never anything from a user's collection) as **CSV, JSON or XML**, as an open dataset. Pick the columns and their order (system, price source id, EAN, title, edition, variant by default; also system name, region, base title, price link, loose / CIB / new price, currency, price date, barcode note and count, compilation contents, cover image link, game id, IGDB id, active, date added), one row per barcode or per game, which systems, and whether inactive games are included, with a live preview. Saved settings can be offered as a **public download link** that works without signing in.
- **Activity log** — what changed on the site and who did it: copies, catalogue, sharing, accounts and system changes, with an **Admin** tag on admin actions and a **Warning** tag on lockouts and failed backups. Filter by user, type, period and text, expand a row for old → new values, export to CSV. Other users' prices and notes show only as "changed". Log settings: a main on/off switch, which types are recorded, whether successful logins are, how long entries are kept (30 days / 90 days / 1 year / forever, cleaned up daily), whether masked IP addresses are stored, and whether users see the **History** of their own copies.
- **Updates** — see below.

### Security
- Passwords hashed with bcrypt (cost 12), minimum length of 12 characters.
- CSRF protection on every state-changing request (forms and `fetch()` calls).
- Login throttling per username and per IP, with escalating lockouts (15 min → 1 h → 24 h → 7 days).
- Optional "remember me" (30 days); only a SHA-256 hash of the token is stored.
- Changing a password signs out all other sessions and remembered devices.
- Session cookies are `HttpOnly` and `SameSite=Lax`; logout is a CSRF-protected POST.
- Nothing inside `uploads/` can run as a script; uploaded files are served with a strict Content-Security-Policy.

---

## Requirements

| Component | Version / notes |
|---|---|
| PHP | **8.1 or newer** (uses `never`, `mixed` and union return types) |
| PHP extensions | `pdo_mysql`, `mbstring`, `fileinfo`, `gd` (image resizing — with WebP support if you upload WebP), `json`, `zlib` (site backups); optional: `exif` (auto-rotates phone photos), `zip` (per-system photo backups), `curl` (update check, where `allow_url_fopen` also works; needed for IGDB) |
| Database | MySQL 8+ or MariaDB 10.5+ |
| Web server | IIS (`web.config` files included) or Apache (`.htaccess` files included). nginx works too, but you have to add the equivalent rules yourself (see below). |

---

## Installation

1. **Copy the files** to your web root (or a subfolder such as `/games`).

2. **Make these folders writable** by the web server user (on IIS usually `IUSR` / `IIS_IUSRS`, or the app pool identity):
   - `uploads/` (and its subfolders)
   - `lang/`, to upload language files later (optional)
   - the site folder itself, so the installer can write `config.php` (optional: otherwise it shows the file for you to save)

3. **Server rules** are included and need no setup: `web.config` (IIS) and `.htaccess` (Apache) in the site folder and in `uploads/`, `uploads/backups/` and `uploads/updates/`. They stop scripts from running in `uploads/`, keep backups and fetched release zips from being downloaded directly, hide `settings/`, add security headers (the `Permissions-Policy` allows the camera for the site itself, `camera=(self)`, for barcode scanning), and (IIS) register the `.woff2` font type. The included `web.config` does **not** choose a PHP version: IIS uses the server's default PHP handler. If the server has several PHP versions and its default is older than PHP 8 (every page then gives a 500 error), uncomment the short `<handlers>` section at the top of `web.config` and set the path to your PHP 8 `php-cgi.exe`. On Apache, `AllowOverride` must allow these files (`All`, or at least `FileInfo AuthConfig Options`). On **nginx**, add the same rules to your server block: deny `\.php$` and other scripts under `/uploads/`, deny `/uploads/backups/`, `/uploads/updates/` and `/settings/`, and deny `.sql` files. If you send a `Permissions-Policy` header, allow the camera with `camera=(self)`, or barcode scanning can only use typed digits.

4. **Open `install.php`** in the browser (e.g. `https://games.example.com/install.php`; any page sends you there while there is no `config.php`). The wizard:
   - checks the server (PHP version, extensions, writable folders),
   - asks for the database (MySQL 8+ / MariaDB 10.5+; an empty database, or it creates one),
   - asks for the site name, base URL, HTTPS, timezone and photo sizes,
   - asks for the region, currency and number / date format,
   - lets you tick the systems you collect (Nintendo, Sony, Sega, Microsoft, Atari),
   - sets the defaults for new users (grading method, completeness and played options, price tier) and the site theme,
   - creates your admin account and, if you like, three invite codes.

   Nothing is written until the last step. Then it creates the tables, saves everything, writes `config.php` and locks itself (`installed.lock`). Delete `install.php` afterwards if you like.

   **Run it right after uploading**: until it has run, anyone who opens `install.php` could set the site up.

**Without the wizard:** create the database, import `schema.sql` (it drops and recreates every table, so only on an empty database), copy `config.sample.php` to `config.php` and fill it in, and create the first admin directly in the `users` table (`role` = `admin`, `password` = a bcrypt hash made with `password_hash()`).

`config.php` is not in git (`config.sample.php` is), so updating the app never overwrites it.

**Optional — IGDB game info:** sign in at dev.twitch.tv/console with a Twitch account that has two-factor authentication on, register an application (any name, OAuth Redirect URL `http://localhost`, Client Type **Confidential**), open it with **Manage**, press **New Secret**, and add both values to `config.php` (see `config.sample.php`): `define('IGDB_CLIENT_ID', '…');` and `define('IGDB_CLIENT_SECRET', '…');`. Then set the systems' IGDB platforms and run Match on **Settings → IGDB**. The secret stays in `config.php`, so it's never part of a site backup.

### Upgrading an existing install

Admins see **Settings → Updates** (a *New* badge, and a notice on the dashboard, when a newer release is out). The site asks GitHub once a day for the latest release; the server needs outgoing HTTPS (the cURL extension, or `allow_url_fopen`).

1. Make a full backup on **Settings → Site Backup** and download it (or back up the database and the `uploads/` folder by hand).
2. On **Settings → Updates**, fetch the new version to the server, then download the zip from that page (it's kept in `uploads/updates/`, which is never served directly).
3. Extract it and upload the files inside its folder over the old ones (in binary mode if you use FTP). `config.php` isn't in the zip and `uploads/` only holds the folder rules, so your settings and photos stay. If you changed a `web.config` or `.htaccess`, merge the new version into yours instead of overwriting it. **IIS:** if you uncommented the `<handlers>` section at the top of `web.config` (or PHP Manager wrote one there), do the same in the new `web.config` before you upload it. Without it IIS falls back to the server's default PHP, which may be too old, and every page shows a 500 error; putting the section back fixes it.
4. Open the site. If the update changes the database, admins are sent to **Settings → Updates** to run the database update (a database backup is saved first, unless you untick it); other users see a short "being updated" notice until then. Each file in `migrations/` runs once, in number order, and is recorded in the `schema_migrations` table.

You can skip versions: the database update runs every migration the site hasn't had yet, in order.

On the first page load after the point-grading update (from very old installs), the app also seeds the default grading data from `assets/grading-defaults.json`, gives each system a default format profile based on its name, and converts the old Mint / Good / Fair / Poor values to grade labels. Systems it can't match are listed under **Settings → Grading System → Format Profiles**.

Installs from before the database updater: the first time it runs, it records which of the existing migrations your database already has (by checking the columns they add), so only the missing ones run.

### Publishing a release (for the maintainer)

1. Bump `APP_VERSION` in `version.php`.
2. Database changes:
   - **While building:** each change gets its own file in `migrations/dev/` (`NNN_name.sql`, the next number). Make the same change in `schema.sql` and add `'dev/NNN_name.sql'` to the `INSERT INTO schema_migrations` at the end of `schema.sql`. Your test site runs these when its `config.php` has `define('DEV_MIGRATIONS', true);` (see `config.sample.php`); the live site ignores them.
   - **At release:** combine the dev files into one `migrations/NNN_release_X.Y.Z.sql` (every step checks first, so it's safe to run again), replace the `'dev/…'` entries in that `INSERT` with its name, and delete the dev files. The live site then runs that one file; the test site runs it too, harmlessly.
3. Commit and push, then publish a GitHub release tagged `v` + the version (e.g. `v1.1.0`). Its description is shown as the release notes. Optionally attach your own `.zip`; otherwise GitHub's source zip is used.

---

## Usage

1. **Settings → Catalogue** (admin): add systems (e.g. *PAL Nintendo 64*, short name *N64*), then fill their game lists by pasting titles or importing a price CSV.
2. **Users:** open **Collection**, pick a system, and tick off what you own. Click a game to open the edit drawer and add details and photos.
3. **Settings:** choose your language and theme, condition grading method, visible systems and their order, table columns, the dashboard's value graphs, search sites, tags, completeness and played options, editions and variants, label templates, and wishlist sharing. You can also export or import a backup and change your password here.
4. **Dashboard:** see your progress and value at a glance.

### Price CSV format

The import expects a CSV with a header row. Required columns:

```
console,name,external_id,link,cib,coverArtBase64
```

Optional columns: `loose`, `new`. Other columns (such as a `coverArt` file name) are ignored.

The `console` value must match a system's **short name** (case-insensitive). Rows with an unknown console are flagged in the preview and skipped.

---

## Project structure

```
├── schema.sql           Database schema (fresh installs only)
├── migrations/          Database updates for existing installs (run from Settings → Updates; dev/ for test sites)
├── version.php          The installed version (APP_VERSION), compared with the latest GitHub release
├── install.php          Setup wizard (locks itself after installing)
├── boot.php             Loaded first by every page: sends you to install.php while there is no config.php
├── config.sample.php    Template for config.php (the installer writes config.php for you)
├── core.php             Sessions, DB, auth, CSRF, throttling, themes; loads the modules below (don't edit)
├── site.php             Site settings, money / number / date formatting, site name
├── i18n.php             Languages: t() lookups, the JS helpers, language file checks
├── grading.php          Condition grading: labels, profiles, templates, scoring, export/import
├── editions.php         Edition groups and their suggestions
├── compilations.php     Compilations and their suggestions
├── condition_report.php Shared condition reports, label templates and sizes
├── photo_tags.php       Which part / defects a photo shows
├── updates.php          Update check, release zip download, database update runner
├── site_backup.php      Full site backups: database dump, zip writer, schedule
├── activity.php         Activity log: logEvent(), clean-up, reading and rendering entries
├── barcodes.php         Barcodes: check digits, lookups, unknown scans and their clean-up
├── data_export.php      Database export: the catalogue as CSV / JSON / XML (Settings › Database export)
├── igdb.php             IGDB: API client, title matching, release date / cover choice, game info and covers for pages
├── index.php            Sign in / register (invite code)
├── dashboard.php        Overview and per-system completion cards
├── collection.php       Main collection table and edit drawer
├── wishlist.php         Wishlist (private, or public via share token)
├── scan.php             Barcode scanner (camera, typed digits, USB scanner)
├── barcode_add.php      Add barcodes: a system's games as a list, scan codes onto the selected game
├── grade.php            Public condition report (grade.php?t=…); grade_photo.php serves its photos
├── print_label.php      One printable condition report sticker
├── settings.php         Settings control panel: user and admin sections (settings.php?s=<section>)
├── settings/            Its section registry, POST handlers and one partial per section (not web-accessible)
├── admin.php            Redirects old admin links to settings.php
├── import_games.php     Bulk-import game titles for a system
├── price_import.php     Price CSV import
├── lang/                Language files (en.json is the base)
├── web.config           IIS configuration (.htaccess: the same for Apache)
├── api/                 JSON endpoints used by the front end
├── assets/css/          main.css (the default theme's variables) and vendor styles
├── assets/themes/       Theme files
├── assets/fonts/        Self-hosted fonts per theme (SIL Open Font License)
├── assets/js/           Page scripts: settings / admin, grading, drawer sections (editions, compilations, share, history, IGDB game info), photo tags, labels, activity log, site backup, barcode scanning, IGDB matching
├── assets/systems.json  Systems the installer offers (per maker; edit to add more)
├── assets/grading-defaults.json      Default grading system (labels, templates, profiles, system matching)
├── assets/grading-descriptions.json  Starter explanations of the default defects (English and Dutch)
└── uploads/             User photos (users/), default covers (defaults/), system icons (icons/), defect example photos (defects/), backups, fetched release zips
```

---

## Database

`schema.sql` creates 40 tables:

| Table | Purpose |
|---|---|
| `users` | Accounts, roles, wishlist sharing, and per-user preferences (language, theme, grading, columns, auction sites…) |
| `invite_codes` | Invite codes and who created or used them |
| `remember_tokens` | Hashed "remember me" tokens |
| `login_attempts` | Failed-login counters and lockouts |
| `app_settings` | Site-wide key/value settings |
| `systems` | Consoles / systems (name, short name, region, IGDB platform, icons for dark and light themes, order, default format profile) |
| `games` | Master game list per system, including price source IDs, price links, prices, the price before the last import that changed it, and which cover it shows |
| `game_igdb` | Per game: its IGDB match (automatic, confirmed, to review or not on IGDB), developer, publisher, genres, release date and region, ratings and the IGDB cover |
| `game_groups`, `edition_ignores` | Edition groups, and pairs of games marked as *not* editions of each other |
| `compilation_items` | What each compilation contains (compilation game → contained games) |
| `collection_entries` | One row per user, per game, per copy (owned, condition, prices, notes…) |
| `copy_photos` | Photos attached to a copy |
| `copy_photo_tags`, `copy_photo_tag_defects` | Which part unit and which of its defects a photo shows (for the condition report) |
| `copy_shares` | Shared condition report links (live or snapshot) |
| `label_sizes`, `label_templates` | Sticker sizes and label templates for printing condition reports |
| `user_system_prefs` | Per-user system visibility, order, and whether a system counts toward totals |
| `user_completeness_options`, `user_played_options`, `user_tag_options`, `user_variant_options` | Each user's own option lists |
| `grade_labels` | Condition labels: name, short code, colour, and the score each one starts at |
| `grade_templates`, `grade_categories`, `grade_defects` | How a part is graded: categories (max points) and defects (deductions, with an optional explanation) |
| `grade_defect_photos` | Example photos that show what a defect means (up to 3 per defect) |
| `grade_profiles`, `grade_profile_components` | Format profiles: which parts a copy has, their template and weight |
| `entry_parts`, `entry_part_units`, `entry_defects` | Per-copy point grading: included parts and quantities, one row per unit (cached score), and logged defects |
| `user_backups` | Per-system photo backup zips (generated on request, expire after 24 hours) |
| `site_backups` | Full site backups kept in `uploads/backups/site/` (manual, scheduled, or made before a database update) |
| `game_barcodes` | Barcodes linked to games (one row per code and game), shared by all users; can be reported for removal |
| `barcode_unknown` | Valid barcodes that were scanned but are not linked yet (kept 7 / 30 / 90 days or for ever, at most 500) |
| `activity_log` | The activity log: who changed what (texts are made when read, in the reader's language) |
| `value_history` | Each user's owned value per system over time (one row each time it changes), for the dashboard's value graphs |
| `schema_migrations` | Which files in `migrations/` this database already has |

Users and games are never deleted by the app (users are deactivated, games hidden). Deleting a copy removes its grading, photo tags, report links and photo records through `ON DELETE CASCADE`, and the app deletes its photo files.

---

## Notes

- The currency symbol, number and date format and the default region are site settings; prices are never converted.
- The app is built for small, trusted groups: there is no open sign-up, only invite codes.
- Barcode reading uses the browser's built-in `BarcodeDetector` where it reads EAN-13 (Chrome / Edge on Android, macOS and ChromeOS); other browsers (Safari, Firefox, Chrome on Windows) load the bundled pure-JavaScript reader `assets/js/vendor/zxing.min.js` (ZXing, `@zxing/library` 0.21.3, Apache-2.0, licence next to the file). No WebAssembly is used, so the Content-Security-Policy needs no change. The camera needs an HTTPS page.
- IGDB covers are shown straight from `images.igdb.com` (the Content-Security-Policy already allows HTTPS images); everything else from IGDB is stored in the database, and pages never call IGDB while you browse.
- Fonts are hosted on the site itself (`assets/fonts/`, SIL Open Font License; licences next to the files). No page loads anything from Google or other font services.

## License

No license has been chosen yet. Until one is added, all rights are reserved by the author.
