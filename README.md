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

### Admin panel
- Generate and revoke **invite codes** (registration is invite-only).
- Manage **users** — activate / deactivate accounts and reset passwords.
- View and clear **login lockouts**.
- Add **systems** and set a system icon.
- Maintain **game lists** per system — add titles one by one, or bulk-import a pasted list (duplicates are detected and skipped).
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
| PHP extensions | `pdo_mysql`, `gd` (image resizing — with WebP support if you upload WebP), `exif` (optional, auto-rotates phone photos), `json` |
| Database | MySQL 8+ or MariaDB 10.5+ |
| Web server | IIS (a `web.config` is included) or Apache / nginx |

---

## Installation

1. **Copy the files** to your web root (or a subfolder such as `/games`).

2. **Create a MySQL database** and a user with full rights on it, using the `utf8mb4` character set.

3. **Import the schema** into the new database:

   ```bash
   mysql -u game_user -p game_collection < schema.sql
   ```

   (Or import `schema.sql` with phpMyAdmin, Adminer or HeidiSQL.)

   > ⚠️ `schema.sql` **drops and recreates every table**. Only run it on an empty database, never on a live install.

4. **Edit `config.php`:**

   ```php
   define('BASE_URL',   'https://games.example.com'); // no trailing slash; include the subfolder if you use one
   define('DB_HOST',    'localhost');
   define('DB_NAME',    'game_collection');
   define('DB_USER',    'game_user');
   define('DB_PASS',    'a-strong-password');
   define('SESSION_NAME', 'gcollect_session');       // make this unique per site
   ```

   Upload limits (8 MB per image; JPEG / PNG / GIF / WebP) can be changed in the same file. Everything below the "DO NOT EDIT" line lives in `core.php`, so `config.php` doesn't need to change when you update the app.

5. **Make the upload folders writable** by the web server user (on IIS, usually `IIS_IUSRS`):

   ```
   uploads/
   uploads/users/
   uploads/defaults/
   ```

   `uploads/img_settings.json` must also be writable, because the admin image settings are saved there.

6. **Protect the uploads folder.**
   - **IIS:** make sure PHP cannot run inside `uploads/` (remove the PHP handler for that folder, or add a `web.config` there).
   - **Apache:** rename `uploads/htaccess.yxy` to `uploads/.htaccess`. It disables directory listing and blocks `.php` files.

7. **Create the first admin account** (see below), then sign in at `index.php`.

### Creating the first admin

Registration requires an invite code, and invite codes can only be made by an admin, so the first account has to be created directly in the database.

Generate a password hash:

```bash
php -r "echo password_hash('your-long-password', PASSWORD_BCRYPT, ['cost'=>12]), PHP_EOL;"
```

Then insert the user:

```sql
INSERT INTO users (username, password, role, status, wishlist_token)
VALUES ('admin', '<hash from above>', 'admin', 'active', SUBSTRING(SHA2(RAND(), 256), 1, 24));
```

After you sign in:
- Add your completeness and played-status options under **Settings** (these are only seeded automatically for users who register with an invite code).
- Use the **Admin** panel to add systems, fill the game lists, and generate invite codes for other users.

### Upgrading an existing install

1. Back up the database.
2. Copy the new files over the old ones (keep your `config.php`).
3. Run the migrations in `migrations/` that you haven't run yet, in date order, e.g.:

   ```bash
   mysql -u game_user -p game_collection < migrations/2026-09_point_grading.sql
   ```

4. Open any page. On first load the app seeds the default grading data from `assets/grading-defaults.json`, gives each system a default format profile based on its name, and converts the old Mint / Good / Fair / Poor values to grade labels. Systems it can't match are listed under **Admin → Format Profiles**.

---

## Usage

1. **Admin:** add systems (e.g. *PAL Nintendo 64*, short name *N64*), then fill their game lists by pasting titles or importing a PriceCharting CSV.
2. **Users:** open **Collection**, pick a system, and tick off what you own. Click a game to open the edit drawer and add details and photos.
3. **Settings:** choose your condition grading method, visible systems and their order, table columns, auction sites, tags, completeness and played options, and wishlist sharing. You can also export or import a backup and change your password here.
4. **Dashboard:** see your progress and value at a glance.

### PriceCharting CSV format

The import expects a CSV with a header row. Required columns:

```
console,name,data-product,link,cib,coverArtBase64
```

Optional columns: `loose`, `new`, `coverArt`.

The `console` value must match a system's **short name** (case-insensitive). Rows with an unknown console are flagged in the preview and skipped.

---

## Project structure

```
├── schema.sql           Database schema (fresh installs only)
├── migrations/          Schema updates for existing installs
├── index.php            Sign in / register (invite code)
├── dashboard.php        Overview and per-system completion cards
├── collection.php       Main collection table and edit drawer
├── wishlist.php         Wishlist (private, or public via share token)
├── settings.php         Per-user settings, backup/restore, password
├── admin.php            Admin panel
├── import_games.php     Bulk-import game titles for a system
├── pc_import.php        PriceCharting CSV import
├── config.php           Site configuration (edit this)
├── core.php             Sessions, DB, auth, CSRF, throttling (don't edit)
├── grading.php          Condition grading: labels, profiles, templates, scoring, export/import
├── web.config           IIS configuration
├── api/                 JSON endpoints used by the front end
├── assets/css/main.css  Stylesheet
├── assets/js/           grading.js (drawer editor + scoring), grading-admin.js (admin editors)
├── assets/grading-defaults.json  Default grading system (labels, templates, profiles, system matching)
└── uploads/             User photos, default images, image settings
```

---

## Database

`schema.sql` creates 23 tables:

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
