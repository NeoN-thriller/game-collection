# Game Collection

A self-hosted, invite-only web app for tracking a physical (PAL) video game collection — what you own, what condition it's in, what it's worth, and what you still want.

Built with plain PHP, MySQL and vanilla JavaScript. No frameworks, no build step, no Composer — drop the files on a PHP-enabled web server, point it at a database, and go.

---

## Features

### Collection
- **Per-system game lists** — each system (e.g. *PAL Nintendo DS*) has a master list of games maintained by the admin.
- **Track every copy** — mark games as owned, and record multiple copies of the same game, each with its own details.
- **Rich per-copy details** in a slide-out edit drawer:
  - Condition (Mint / Good / Fair / Poor)
  - Completeness (*Sealed, CIB, No Manual, No Box, Disc / Cart Only, Loose, Incomplete* — fully customizable)
  - Played status (*Finished, Started, Stuck, Cheated* — fully customizable)
  - Price paid, personal price, and a buy-range (min / max) for wishlist hunting
  - Which price tier (loose / CIB / new) counts toward your collection value
  - Custom tags, notes, and an "upgrade wanted" flag with a reason
- **Photos** — upload photos per copy, rotate them, and choose a primary photo. Uploads are automatically resized and EXIF-rotated (size and quality configurable by the admin).
- **Filtering and search** — by title, condition, completeness, played status, tag, owned / not owned, wishlisted, and upgrade-wanted.
- **Configurable columns** — choose which columns are visible in the collection and wishlist tables (saved per user).

### Dashboard
- Overall totals: systems, games, owned, completion %, copies, upgrades, wishlisted, total spent, and owned value.
- A completion card per system with progress, spend, value, condition breakdown, and counts.
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
- Export your entire collection (entries and photo references) as JSON, and import it again later (merges with existing data).

### Admin panel
- Generate and revoke **invite codes** (registration is invite-only).
- Manage **users** — activate / deactivate accounts and reset passwords.
- View and clear **login lockouts**.
- Add **systems** and set a system icon.
- Maintain **game lists** per system — add titles one by one, or bulk-import a pasted list (duplicates are detected and skipped).
- **PriceCharting import** and **image settings** (max width / height and JPEG quality).

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
| Database | MySQL 8+ (or a compatible MariaDB) |
| Web server | IIS (a `web.config` is included) or Apache / nginx |

---

## Installation

1. **Copy the files** to your web root (or a subfolder such as `/games`).

2. **Create a MySQL database** and a user with full rights on it, using the `utf8mb4` character set.

3. **Create the database tables.** The app uses these tables:

   `users`, `invite_codes`, `remember_tokens`, `login_attempts`, `systems`, `games`, `collection_entries`, `copy_photos`, `user_system_prefs`, `user_completeness_options`, `user_played_options`, `user_tag_options`

   > ⚠️ A schema file is not included in the repository yet. See [Database](#database) below.

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

---

## Usage

1. **Admin:** add systems (e.g. *PAL Nintendo 64*, short name *N64*), then fill their game lists by pasting titles or importing a PriceCharting CSV.
2. **Users:** open **Collection**, pick a system, and tick off what you own. Click a game to open the edit drawer and add details and photos.
3. **Settings:** choose visible systems and their order, table columns, auction sites, tags, completeness and played options, and wishlist sharing. You can also export or import a backup and change your password here.
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
├── web.config           IIS configuration
├── api/                 JSON endpoints used by the front end
├── assets/css/main.css  Stylesheet
└── uploads/             User photos, default images, image settings
```

---

## Database

The repository does not include a SQL schema file yet. The table names are listed under [Installation](#installation), and the column names can be found in the queries in `core.php`, `admin.php` and `api/*.php`.

Adding a `schema.sql` (a `mysqldump --no-data` of a working install) would make fresh installs a one-step process.

---

## Notes

- Prices are shown in **euros (€)**, and new systems default to the **PAL** region.
- The app is built for small, trusted groups: there is no open sign-up, only invite codes.
- Fonts are loaded from Google Fonts (Bebas Neue and DM Mono).

## License

No license has been chosen yet. Until one is added, all rights are reserved by the author.
