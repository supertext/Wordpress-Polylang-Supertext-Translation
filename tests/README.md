# Tests

Two suites. Neither is shipped to the server (the deploy excludes `tests/`).

## Unit tests — `tests/unit/` (PHPUnit + Brain Monkey)

Fast, isolated tests of the plugin's logic with WordPress functions mocked (no DB,
no live site). Run in CI on every push (`.github/workflows/unit-tests.yml`) on PHP 8.2 (the oldest supported) and 8.5.

Requirements: PHP 8.2+ with `dom`, `mbstring`, `libxml`, and Composer.

```bash
cd tests/unit
composer install
composer test        # vendor/bin/phpunit
composer phpstan     # static analysis, see README.md → Code quality and security checks
```

Covers: human-order `Client` (request shaping + response parsing, mocked HTTP,
incl. `upload_file` with `DocumentTypeId 3` / `image/png` for screenshot references),
`Callback` (signed `ReferenceData` round-trip + payload parsing), `Writeback`
(translated-HTML parsing + Final-file selection), the YOOtheme `Layout` walker
(decode/collect/map/encode), the **VibeBoost** screenshot `Client` (URL encoding —
the secret token must stay inside the `url` param — defaults, and error paths), the
**Draft_Preview** token gate (`is_valid` match/wrong/disabled/expired + `preview_url`
`p` vs `page_id`), the **Settings** feature toggles (defaults / sanitize / getters), the AI **Language_Codes** rule (mapping, else exact match of the locale, else the SDK default, else that of the locale without `_formal`/`_informal`, else `unsupported_language`; the drop-down's localized options; formality only where Supertext offers it; see README.md → *Language codes*), the AI client refusing languages without a mapping or default without a request, *Translation review* (a review language's upload, status, download and delete go to the review service, others and the key check directly, an empty `SUPERTEXT_REVIEW_URL` turns it off, the default is Supertext's review service), the server-side check of saved mappings, the **Commando Center** glue (`Reporting`, `Commando`: key check, reports, ping, messages; a fake transport and `SUPERTEXT_REPORT_URL` on `127.0.0.1`, so nothing reaches the live center), the **interface translations** (German, French, Italian complete, `.mo` current), and *Export for translation* / *Import translation* (`Translation_File_Glue` with a fake store: reference round trip, the collector's keys and the language mapping in the jobs, other plugin / unknown reference / missing language / no permission, `ok` written, `source_changed` only with the option, untranslated / invalid / missing not written, files over 10 MB refused).

## End-to-end tests — `tests/e2e/` (Playwright)

Drive a real browser against a live WordPress site (admin login, Supertext pages,
bulk-action AI translation).

```bash
cd tests/e2e
cp .env.local.example .env.local   # fill SITE_URL / WP_USER / WP_PASS (gitignored)
npm install
npx playwright install chromium
npx playwright test
```

Notes:
- `.env.local` (credentials) and `node_modules/`, `test-results/` are gitignored.
- The AI translation test runs a real (slow, synchronous) translation; give it a
  generous timeout. It uses the post **"Playwright AI Test Post"** (German →
  English) and deletes any existing English translation first for a clean run.

### Exact steps each test runs

**`auth.setup.js` — login fixture (runs first):**
1. Go to `wp-login.php`.
2. Fill in `WP_USER` / `WP_PASS` and submit.
3. Save the authenticated session to `.auth/state.json` (reused by every test
   below, so they start logged in).

**`supertext.spec.js` → "Supertext admin pages" (4 smoke tests).** Each opens a
page, asserts it rendered, and screenshots it:

| Test | Steps |
|------|-------|
| Status page renders | goto `admin.php?page=supertext-polylang` → assert heading "Supertext for Polylang" + "Polylang patched" text → screenshot |
| Settings page | goto `…-settings` → assert "Translation Services (human)" heading + environment + email fields visible → screenshot |
| Orders page | goto `…-orders` → assert "Supertext Orders" heading → screenshot |
| Debug page | goto `…-debug` → assert "Order callbacks" heading → screenshot |

**`supertext.spec.js` → "AI translation via bulk action"** — the real end-to-end
test (*"Playwright AI Test Post" translates German → English*):
1. **Find the German source** — open the Posts list filtered by the title; pick the
   row whose German column shows the self-flag (the source, not the EN copy).
   Screenshot `list`.
2. **Delete any existing English translation** — if one exists, read its post ID and
   `DELETE` it via the REST API (`X-WP-Nonce`) for a clean run.
3. **Flush caches & wait for freshness** — trigger LiteSpeed "Purge All", then poll
   the list until the "add English translation" (+) icon reappears (so a stale object
   cache can't make the next step a no-op).
4. **Select + choose bulk action** — check the source row's checkbox → set Bulk
   actions to **Supertext AI Translation** → pick target language **English**.
   Screenshots `after-delete`, `before-apply`.
5. **Apply & verify** — click **Apply**, wait for the result URL
   (`supertext_created/errors=…`), read the admin notices, assert they mention
   "translation". Screenshot `result`.

**`supertext.spec.js` → "Settings — feature status, toggles & plugin detection":**
goto the Settings page → assert the status overview lists **Features & integrations**,
**Page screenshots (VibeBoost)** and **Integration: Gravity Forms**, that the two new
toggle checkboxes (`preview_links_enabled`, `screenshots_enabled`) are present, and
that the **Detect plugins** button renders. Screenshot `settings-features`.

**`supertext.spec.js` → "Secret preview link" (2 tests):**
1. *preview metabox renders* — open `post-new.php`, assert the **Supertext — Public
   preview link** meta box (`#supertext-preview`) with its enable checkbox + expiry
   field is present.
2. *a draft is viewable via its secret link and hidden without a valid token* — the full
   end-to-end guard:
   1. Create a draft via REST (unique marker in its content).
   2. Open the editor, dirty it via the block-editor data store (meta-box changes alone
      don't mark it dirty), tick **Enable public preview link** with a far-future expiry,
      **Save draft**, reload, and read the meta box's readonly URL — asserting it carries
      `?p=<id>` and a UUID `st_preview` token.
   3. In a **genuinely logged-out** browser context, assert the secret URL **renders** the
      draft, and that the same post **without** the token returns **404** with the content
      hidden. Screenshots `preview-enabled`, `preview-rendered`, then deletes the draft.

   > **Gotcha (important):** the logged-out context must be created with an *explicit
   > empty* storage state — `browser.newContext({ storageState: { cookies: [], origins:
   > [] } })`. A bare `browser.newContext()` **inherits the project's saved admin
   > session** (from `.auth/state.json`), so it is *not* anonymous and every draft would
   > appear visible — masking the gate. The token gate is also unit-tested in
   > `DraftPreviewTest::is_valid`.

**`docs.spec.js` — documentation screenshots (separate spec):** loops over 4 admin
URLs (`status`, `settings`, `orders`, `mlang_settings`), freezes animations, and
saves a full-page PNG of each into `docs/images/`.

### Seeing the steps after a run

- **Step screenshots** — each numbered step writes a numbered PNG into
  `test-results/steps/` (e.g. `…-01-list.png`, `…-05-result.png`).
- **Playwright trace** — on failure a `trace.zip` is saved; replay every action
  step-by-step with `npx playwright show-trace <zip>`.
