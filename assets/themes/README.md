# Themes

Each `.css` file in this folder is a theme. Drop a file in and it shows up in
Settings (per user) and on the Admin page (site default). No code changes needed.

- **File name = id.** Lowercase letters, digits and `-` only (`my-theme.css`).
  Other names are ignored.
- **Header comment** on the first lines holds the metadata:

  ```css
  /* Theme:  My Theme
     Scheme: dark            (dark | light — sets the browser's form controls and scrollbars)
     Fonts:  fonts/my-theme.css   (optional)
     About:  One line shown in the theme picker.   (optional) */
  ```

  Fonts are hosted on the site itself (nothing is loaded from Google or other
  servers, for visitors' privacy, and the Content-Security-Policy only allows
  this site). Put the `.woff2` files in `assets/fonts/<family>/` with the font's
  licence (Google Fonts are SIL Open Font License: keep `OFL.txt` next to them),
  and an `assets/fonts/<theme>.css` with the `@font-face` rules pointing at them
  (paths relative to that CSS file). See `assets/fonts/nintendo.css`.
- **Body:** override the `:root` variables from `assets/css/main.css`. Anything
  you leave out keeps the Arcade Gold value. The swatches in the pickers are read
  from `--bg --surface --accent --accent2 --wiiu --text`, so give those as hex.
  Small extra rules (e.g. a shadow) are fine; don't change layout.

Useful variables besides the colours: `--btn-bg` / `--on-accent` (primary
button), `--header-*` (top bar), `--scanlines` (`block` / `none`), `--radius`,
`--font-body`, `--font-display`, `--display-weight`, `--display-case`
(`uppercase` for display fonts with lowercase letters) and `--label-filter`
(e.g. `brightness(.72)` so admin-chosen grade label colours stay readable on a
light background) and `--stat-scale` (size of the numbers in the dashboard and
collection stat bars; `1` suits a narrow display font like Bebas Neue, wider
fonts need less, e.g. `.8`, so an amount like "€ 12.345" still fits its box).

Check contrast: body text and small coloured text at least 4.5:1 against
`--surface` and `--bg`.
