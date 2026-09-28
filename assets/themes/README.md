# Themes

Each `.css` file in this folder is a theme. Drop a file in and it shows up in
Settings (per user) and on the Admin page (site default). No code changes needed.

- **File name = id.** Lowercase letters, digits and `-` only (`my-theme.css`).
  Other names are ignored.
- **Header comment** on the first lines holds the metadata:

  ```css
  /* Theme:  My Theme
     Scheme: dark            (dark | light — sets the browser's form controls and scrollbars)
     Fonts:  https://fonts.googleapis.com/css2?family=...&display=swap   (optional)
     About:  One line shown in the theme picker.                        (optional) */
  ```

  Only Google Fonts URLs are accepted (it's the only font host the site's
  Content-Security-Policy allows).
- **Body:** override the `:root` variables from `assets/css/main.css`. Anything
  you leave out keeps the Arcade Gold value. The swatches in the pickers are read
  from `--bg --surface --accent --accent2 --wiiu --text`, so give those as hex.
  Small extra rules (e.g. a shadow) are fine; don't change layout.

Useful variables besides the colours: `--btn-bg` / `--on-accent` (primary
button), `--header-*` (top bar), `--scanlines` (`block` / `none`), `--radius`,
`--font-body`, `--font-display`, `--display-weight`, `--display-case`
(`uppercase` for display fonts with lowercase letters) and `--label-filter`
(e.g. `brightness(.72)` so admin-chosen grade label colours stay readable on a
light background).

Check contrast: body text and small coloured text at least 4.5:1 against
`--surface` and `--bg`.
