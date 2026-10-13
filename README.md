# Transformer X (web)

Transformer X is the web version of the Access tool **DataTransformer v3.5**. It takes the product upload sheet (`.xlsx`, 38 columns) and turns it into a WooCommerce product import file (`.xlsx`, 111 columns). Its output is identical to the Access export. The test suite checks this cell by cell against files the Access application produced.

It is PHP 8.2 on the server, MariaDB/MySQL for users and statistics, and a single-page front end in plain HTML, CSS and JavaScript modules (no framework, no build step).

## The original Access tool

`legacy/` holds the application this project replaces, kept as the reference for its behaviour:

- `DataTransformer  - v3.5.accdb`: the Access front end (form `frmImportData`, modules `modCategory` and `modFillAtt`, query `qryExportData`). Note the two spaces in the name.
- `Full Product Upload V5 sheet.xlsx`: a real upload sheet (4,259 products). The same file is `tests/fixtures/input/sample_v5.xlsx`.

The web server never serves this folder.

## Setup (XAMPP on Windows)

1. Requirements: PHP 8.2+ with `zip`, `pdo_mysql`, `mbstring`, `fileinfo` (in XAMPP, remove the `;` before `extension=zip` in `php.ini` and restart Apache); MariaDB 10.4+; Apache with `mod_rewrite`, `mod_headers` and `AllowOverride All`.
2. `php C:\xampp\php\composer.phar install --no-dev` (leave out `--no-dev` to run the tests).
3. `php bin/install.php --admin=<username> --admin-name="Full Name"`. Optional flags: `--db-root-user`, `--db-root-pass`, `--db-host`, `--db-name` (default `transformer_x`), `--timezone` (default `Asia/Karachi`).
   - It creates the database account `tx_app`, with SELECT/INSERT/UPDATE/DELETE on the app database only.
   - It applies `database/schema.sql` and writes `config/config.php`.
   - It prints a one-time password for the first administrator, who must change it at first sign-in.
4. Open `http://localhost/transformer-x/`.

The timezone sets the output file names (`yyyymmdd_hhnnss.xlsx`, local time, as in Access) and the dates in history and statistics.

## How a conversion flows

1. **Upload** (`POST /api/imports`). The file is checked for size, extension, real file type, a sane zip structure (to block zip bombs) and no macros.
   - The first sheet is read the way `DoCmd.TransferSpreadsheet acImport` would store it. The uploaded file is then deleted at once.
   - Every row is validated and converted to build the preview and the statistics.
   - Rows are staged in `import_rows` only while the preview is open.
2. **Preview**: paged input and output grids, a per-row explanation of every calculation, and errors and warnings.
   - Errors block conversion: header problems, wrong cell types, text over 255 characters, and blank or duplicate Internal Reference.
3. **Convert** (`POST /api/imports/{id}/convert`).
   - The import is claimed atomically, so a double click can't convert it twice.
   - The workbook is written to `storage/tmp`, the statistics are recorded, the staged rows are deleted, and the file is streamed to the browser and then deleted.
4. Unconverted previews expire after `preview_expiry_hours` (default 24). Housekeeping runs on normal requests at most every 10 minutes.

Kept permanently: one `imports` row per upload, `import_issue_counts`, and `conversions` plus `conversion_breakdown` for each conversion. No spreadsheet is ever stored.

## Fidelity to the Access tool

`src/Engine` ports `qryExportData`, `BuildCategories`, `BuildAttributesTable` and the Access import and export behaviour. These rules were measured against the real Access application, not assumed:

| Behaviour | Rule |
|---|---|
| Regular price | `FormatCurrency(IIf([Product Type]='Parcel', Sales Price × Average Weight, Sales Price × Forecasted Quantity), 2)`. The double is taken to 15 significant digits, then rounded half-up to cents. en-US format: `$1,195.00`, negatives `($5.00)`, and a negative that rounds to 0 gives `$0.00`. |
| `'Parcel'` comparison | Jet rules: ignores capitals and trailing spaces, but not leading spaces or tabs. |
| Size attribute | Calculated field `[Length] & "x" & [Width] & "x" & [Depth] & " mm"`. Numbers use 14 significant digits; whole numbers below 1E+15 are printed in full; E-notation is used at 1E+15 and above and below 1E-15. |
| Number cell typed into a text column | Becomes text exactly like C `%.11g` (`1e-05`, `1.2345678901e+12`). |
| Text in a number column | Access rejects it (Type Conversion Failure). The app reports it as an error. |
| Export | Trailing spaces are removed from every text cell. Strings starting with `=` stay text, never formulas. `""` columns are written as no cell. The sheet is named `qryExportData`. |
| Categories | Scripting.Dictionary semantics: insertion order and case-sensitive de-duplication. Colours are trimmed; Species, Cutting Style, DIAMOND Shape and Product Type are not. |
| Attributes | The first 18 non-blank fields in the fixed order. VBA `Trim` (spaces only). Species is renamed to "Gemstone". |

Business decisions taken with the product owner:

- The price formula is kept as in Access. The preview warns about $0.00 prices, negative prices and per-piece prices multiplied by a quantity other than 1.
- The price stays text (`$1,195.00`).
- Blank or duplicate Internal Reference values are blocked. Access would silently drop or multiply those rows.

## Tests

```
php vendor/bin/phpunit                     # everything (the http group needs Apache + MariaDB running)
php vendor/bin/phpunit --testsuite engine  # conversion engine only, no server needed
php bin/convert.php in.xlsx out.xlsx       # command-line conversion
php tests/tools/compare_xlsx.php a.xlsx b.xlsx
```

- `tests/Engine/GoldenParityTest.php` converts `tests/fixtures/input/*.xlsx` and compares the results cell by cell with `tests/fixtures/golden/*.xlsx`, which the Access app exported. The inputs are the real V5 sample (4,259 rows) and three edge-case files.
- To regenerate the reference files after changing `tests/fixtures/make_edge_cases.php`, run `tests/tools/access_golden.ps1` on a machine with Access. It uses `legacy/` by default. It works on a copy of the database.
- `tests/App/HttpFlowTest.php` covers sign-in, CSRF, lockout, the forced password change, roles, upload, preview, conversion (checked against the Access reference again), blocked and rejected files, history and statistics. It cleans up its `tx_test_*` users.

## Security summary

- **Sessions:** strict mode, `HttpOnly` + `SameSite=Strict` cookie scoped to the app path, ID rotated at sign-in and password change, 30-minute idle timeout (configurable) and an 8-hour absolute limit.
- **CSRF:** synchroniser token in `X-CSRF-Token` on every write, plus an Origin check. Writes must be JSON or multipart.
- **Passwords:** bcrypt via `password_hash`, minimum 10 characters, and a forced change after creation or reset.
- **Brute force:** accounts lock after 5 wrong passwords for 15 minutes (configurable), and there is a per-IP limit of 20 failures in 15 minutes. Unknown usernames take the same time to reject as wrong passwords.
- **Access control:** roles are checked on the server for every endpoint. Users only see their own imports; admins see all, but only the uploader can convert.
- **Database:** prepared statements everywhere, and a least-privilege database account.
- **Browser:** a strict Content-Security-Policy (no inline script or style), `frame-ancestors 'none'`, `nosniff`, `Referrer-Policy` and `Permissions-Policy`. The UI never inserts server data as HTML.
- **Files on the server:** only `public/` is reachable over HTTP. `src`, `config`, `storage`, `vendor`, `tests` and `bin` are denied twice: by the rewrite and by their own `.htaccess`.
- **Errors:** the browser gets a reference number; details go to `storage/logs`.
- **Audit log:** sign-ins, lockouts, uploads, conversions, discards, and user and settings changes.
- **Production:** serve over HTTPS. The session cookie then becomes `Secure` automatically and HSTS is sent.
