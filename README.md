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
  - Completeness (*Sealed, CIB, No Manual, No Box, Disc / Cart Only, Loose, Incomplete* — fully customizable)
  - Played status (*Finished, Started, Stuck, Cheated* — fully customizable)
  - Price paid, personal price, and a buy-range (min / max) for wishlist hunting
  - Which price tier (loose / CIB / new) counts toward your collection value
  - Custom tags, notes, and an "upgrade wanted" flag with a reason
- **Photos** — upload photos per copy, rotate them, and choose a primary photo. Uploads are automatically resized and EXIF-rotated (size and quality configurable by the admin).
- **Filtering and search** — by title, condition (label, point / simple grades, minimum score), completeness, played status, tag, owned / not owned, wishlisted, and upgrade-wanted.
- **Configurable columns** — choose which columns are visible in the collection and wishlist tables (saved per user).

### Condition grading
- Two methods, chosen per user in **Settings**: *Simple only*, *Points only*, or *Both* (with a default for new copies and a Simple | Points switch per copy).
- **Simple:** one label per copy.
- **Points:** every part of a copy (box, cartridge, manual, map, disc, jewel case…) starts at **100** and loses points for logged defects (+/− counters, and pick-one levels such as *Fading: minor / moderate / heavy*). The copy's score is a weighted average of the parts that are present — missing parts are skipped, not penalised.
  - A quantity per part (e.g. 2 posters, each graded separately) and your own extra items (e.g. a magazine flyer).
  - The score maps back to a label, so badges, filters and dashboard stats work the same for both methods.
- Switching method never loses data: point grades and simple labels are both kept on every copy.
- The admin manages the **grade labels** (names, colours, the score each one starts at), **format profiles** (which parts a copy has and their weights, with a default per system) and **component templates** (categories and defects). Defaults for cartridges, discs, UMDs, big-box PC games and boxed consoles are included.

### Compilations
- A compilation is a catalogue game that contains other games of the same system (e.g. *Dishonored Prey: The Arkane Collection* → *Dishonored [Definitive Edition]* and *Prey*). A game can be in any number of compilations.
- **Admin:** Settings → Catalogue → Compilations suggests likely compilations (titles with *Collection*, *Trilogy*, *Bundle*, … or with other games' names in them); accept, adjust or reject each one. Any game's contents can be edited with the **Contents** button in the game list.
- **Per user** (Settings → Editions & Variants): a compilation counts as its own game, or the games inside it count as owned (the compilation itself is then left out of the game totals).
- The drawer shows what a compilation contains and which compilations a game is in. A wished game stays on the wishlist when you own it in a compilation, with an "In your …" mark.

### Dashboard
- Overall totals: systems, games, owned, completion %, copies, upgrades, wishlisted, total spent, owned value, and average condition score.
- A completion card per system with progress, spend, value, condition breakdown (per grade label), average score, and counts.
- Hide systems you don't collect for, and reorder the ones you do.

### Pricing (PriceCharting)
- Import a **PriceCharting CSV export** to add or update games with loose, CIB and new prices, product links and cover art.
- Matching order: PriceCharting product ID → console + title → add as a new game.
- A preview step shows what will be matched, added or updated before anything is written.

### Wishlist
- A dedicated wishlist view with your target buy prices.
- **Auction / search sites** — define your own search-link templates (e.g. eBay, Marktplaats) using `{system}`, `{title}` and `{region}` placeholders to get one-click searches per game.
- **Public sharing** — optionally share a read-only wishlist link (`wishlist.php?token=…`). The link can be regenerated at any time, which kills the old one.

### Backup & restore
- Export your entire collection (entries, condition grades and option lists) as JSON, and import it again later (merges with existing data). Photos are backed up separately as per-system zips.

### Admin settings
Admins see an extra **Site** group in **Settings** (General, Languages & Themes, Grading System, Catalogue, Users & Invites).
- Generate and revoke **invite codes** (registration is invite-only).
- Manage **users** — activate / deactivate accounts and reset passwords.
- View and clear **login lockouts**.
- Add **systems** and set a system icon.
- Maintain **game lists** per system — add titles one by one, or bulk-import a pasted list (duplicates are detected and skipped).
- **Site settings** — site name (and how its words are coloured), currency symbol and number format, date format, default region, default language, and the grading method new users start with. Prices are never converted: import them in your own currency.
- **Themes** — pick the site default theme; users can choose their own in Settings. Drop a `.css` file into `assets/themes/` to add one.
- **Languages** — English and Dutch included. Download a language file, translate it, upload it again; the page shows which texts are still missing compared to English. Users choose their own language in Settings.
- **PriceCharting import** and **image settings** (max width / height and JPEG quality).
- **Condition grading** — edit grade labels, format profiles and component templates; recalculate scores; **export / import the whole grading system** as JSON (merge by name, or replace), or reset it to the built-in defaults.

### Security
- Passwords hashed with bcrypt (cost 12), minimum length of 12 characters.
- CSRF protection on every state-changing request (forms and `fetch()` calls).
- Login throttling per username and per IP, with escalating lockouts (15 min → 1 h → 24 h → 7 days).
- Optional "remember me" (30 days); only a SHA-256 hash of the token is stored.
- Changing a password signs out all other sessions and remembered devices.
- Session cookies are `HttpOnly` and `SameSite=Lax`; logout is a CSRF-protected POST.
- PHP execution is blocked inside the `uploads/` folder.

---

## Requirements

| Component | Version / notes |
|---|---|
| PHP | **8.1 or newer** (uses `never`, `mixed` and union return types) |
| PHP extensions | `pdo_mysql`, `mbstring`, `fileinfo`, `gd` (image resizing — with WebP support if you upload WebP), `json`; optional: `exif` (auto-rotates phone photos), `zip` (photo backups) |
| Database | MySQL 8+ or MariaDB 10.5+ |
| Web server | IIS (a `web.config` is included) or Apache / nginx |

---

## Installation

1. **Copy the files** to your web root (or a subfolder such as `/games`).

2. **Make these folders writable** by the web server user (on IIS usually `IUSR` / `IIS_IUSRS`, or the app pool identity):
   - `uploads/` (and its subfolders)
   - `lang/`, to upload language files later (optional)
   - the site folder itself, so the installer can write `config.php` (optional: otherwise it shows the file for you to save)

3. **Protect the uploads folder.**
   - **IIS:** the included `uploads/web.config` stops PHP from running there.
   - **Apache:** rename `uploads/htaccess.yxy` to `uploads/.htaccess`. It disables directory listing and blocks `.php` files.

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

### Upgrading an existing install

Admins see **Settings → Updates** (a *New* badge, and a notice on the dashboard, when a newer release is out). The site asks GitHub once a day for the latest release; the server needs outgoing HTTPS (the cURL extension, or `allow_url_fopen`).

1. Back up the database and the `uploads/` folder.
2. On **Settings → Updates**, fetch the new version to the server, then download the zip from that page (it's kept in `uploads/updates/`, which is never served directly).
3. Extract it and upload the files inside its folder over the old ones. `config.php` isn't in the zip; skip `web.config` / `.htaccess` if you changed them.
4. Open the site. If the update changes the database, admins are sent to **Settings → Updates** to run the database update; other users see a short "being updated" notice until then. Each file in `migrations/` runs once, in number order, and is recorded in the `schema_migrations` table.

On the first page load after the point-grading update, the app also seeds the default grading data from `assets/grading-defaults.json`, gives each system a default format profile based on its name, and converts the old Mint / Good / Fair / Poor values to grade labels. Systems it can't match are listed under **Settings → Grading System → Format Profiles**.

Installs from before the database updater: the first time it runs, it records which of the existing migrations your database already has (by checking the columns they add), so only the missing ones run.

### Publishing a release (for the maintainer)

1. Bump `APP_VERSION` in `version.php`.
2. Database change? Add `migrations/NNN_name.sql` (the next number), make the same change in `schema.sql`, and add the file name to the `INSERT INTO schema_migrations` at the end of `schema.sql`.
3. Commit and push, then publish a GitHub release tagged `v` + the version (e.g. `v1.1.0`). Its description is shown as the release notes. Optionally attach your own `.zip`; otherwise GitHub's source zip is used.

---

## Usage

1. **Settings → Catalogue** (admin): add systems (e.g. *PAL Nintendo 64*, short name *N64*), then fill their game lists by pasting titles or importing a PriceCharting CSV.
2. **Users:** open **Collection**, pick a system, and tick off what you own. Click a game to open the edit drawer and add details and photos.
3. **Settings:** choose your condition grading method, visible systems and their order, table columns, auction sites, tags, completeness and played options, and wishlist sharing. You can also export or import a backup and change your password here.
4. **Dashboard:** see your progress and value at a glance.

### PriceCharting CSV format

The import expects a CSV with a header row. Required columns:

```
console,name,data-product,link,cib,coverArtBase64
```

Optional columns: `loose`, `new`. Other columns (such as PriceCharting's `coverArt` file name) are ignored.

The `console` value must match a system's **short name** (case-insensitive). Rows with an unknown console are flagged in the preview and skipped.

---

## Project structure

```
├── schema.sql           Database schema (fresh installs only)
├── migrations/          Database updates for existing installs (run from Settings → Updates)
├── version.php          The installed version (APP_VERSION), compared with the latest GitHub release
├── updates.php          Update check, release zip download, database update runner
├── install.php          Setup wizard (locks itself after installing)
├── boot.php             Loaded first by every page: sends you to install.php while there is no config.php
├── index.php            Sign in / register (invite code)
├── dashboard.php        Overview and per-system completion cards
├── collection.php       Main collection table and edit drawer
├── wishlist.php         Wishlist (private, or public via share token)
├── settings.php         Settings control panel: user and admin sections (settings.php?s=<section>)
├── settings/            Its section registry, POST handlers and one partial per section (not web-accessible)
├── admin.php            Redirects old admin links to settings.php
├── import_games.php     Bulk-import game titles for a system
├── pc_import.php        PriceCharting CSV import
├── config.sample.php    Template for config.php (the installer writes config.php for you)
├── core.php             Sessions, DB, auth, CSRF, throttling (don't edit)
├── grading.php          Condition grading: labels, profiles, templates, scoring, export/import
├── site.php             Site settings, money / number / date formatting, site name
├── i18n.php             Languages: t() lookups, the JS helpers, language file checks
├── lang/                Language files (en.json is the base)
├── web.config           IIS configuration
├── api/                 JSON endpoints used by the front end
├── assets/css/main.css  Stylesheet (the default theme's variables)
├── assets/themes/       Theme files
├── assets/js/           settings.js / admin.js (settings page), grading.js (drawer editor + scoring), grading-admin.js (grading editors)
├── assets/systems.json  Systems the installer offers (per maker; edit to add more)
├── assets/grading-defaults.json  Default grading system (labels, templates, profiles, system matching)
└── uploads/             User photos, default images, backups, fetched release zips
```

---

## Database

`schema.sql` creates 31 tables, including:

| Table | Purpose |
|---|---|
| `users` | Accounts, roles, wishlist sharing, and per-user preferences (columns, auction sites) |
| `invite_codes` | Invite codes and who created or used them |
| `remember_tokens` | Hashed "remember me" tokens |
| `login_attempts` | Failed-login counters and lockouts |
| `systems` | Consoles / systems (name, short name, region, icon, order) |
| `games` | Master game list per system, including PriceCharting IDs and prices |
| `collection_entries` | One row per user, per game, per copy (owned, condition, prices, notes…) |
| `copy_photos` | Photos attached to a collection entry |
| `user_backups` | Per-system photo backup zips (generated on request, expire after 24 hours) |
| `user_system_prefs` | Per-user system visibility, order, and whether a system counts toward totals |
| `user_completeness_options` | Each user's completeness labels |
| `user_played_options` | Each user's played-status labels |
| `user_tag_options` | Each user's tag labels |
| `app_settings` | Site-wide key/value settings (e.g. the default weight of users' own items) |
| `compilation_items` | What each compilation contains (compilation game → contained games) |
| `schema_migrations` | Which files in `migrations/` this database already has |
| `grade_labels` | Condition labels: name, short code, colour, and the score each one starts at |
| `grade_templates`, `grade_categories`, `grade_defects` | How a part is graded: categories (max points) and defects (deductions) |
| `grade_profiles`, `grade_profile_components` | Format profiles: which parts a copy has, their template and weight |
| `entry_parts`, `entry_part_units`, `entry_defects` | Per-copy point grading: included parts and quantities, one row per unit (cached score), and logged defects |

Foreign keys use `ON DELETE CASCADE`, so deleting a user, system or game also removes its related entries and photo records. The image files themselves stay in `uploads/`.

---

## Notes

- Prices are shown in **euros (€)**, and new systems default to the **PAL** region.
- The app is built for small, trusted groups: there is no open sign-up, only invite codes.
- Fonts are loaded from Google Fonts (Bebas Neue and DM Mono).

## License

No license has been chosen yet. Until one is added, all rights are reserved by the author.
