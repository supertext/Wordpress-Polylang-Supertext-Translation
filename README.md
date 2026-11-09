# Supertext for Polylang

Adds **Supertext** as a native machine-translation service in **Polylang Pro**, so it
appears in *Languages → Settings → Machine Translation* right next to DeepL. Content is
translated directly through Supertext — **no DeepL proxy required**. It also adds
**human (professional)** translation orders directly from WordPress, and
**translation files**: export for translation (XLIFF 1.2 or HTML) and import of translated
files (Supertext SDK 0.5.0).

> 📘 **Looking for installation & usage instructions (with screenshots)?**
> See the **[User Guide](docs/README.md)**. This README covers the developer/architecture
> details.

## How it works

Polylang Pro's MT layer is built around three swappable interfaces. This plugin implements
all three:

| File | Implements | Role |
|------|-----------|------|
| `includes/Machine_Translation/Service.php` | `Service_Interface` | Identity, icon, language-code mapping, wiring |
| `includes/Machine_Translation/Client.php` | `Client_Interface` | The actual `translate()` / `get_usage()` / key check |
| `includes/Machine_Translation/Settings.php` | `Settings_Interface` | The settings form (API key, endpoint, language mapping with Translation review); its key check is the Commando Center's |

The service is registered through the `pll_mt_services` filter (see the patch below).

### Translation protocol — Supertext AI **file** translation

The client uses Supertext's **AI file translation** endpoints
(`https://api.supertext.com/v1/`, `Authorization: Supertext-Auth-Key {key}`), not the
text endpoint. File translation accepts up to **1,000,000 characters per request**, so a
whole post (title + content + excerpt + metas) is sent as a *single* call — avoiding the
text endpoint's 10k-char / 5-req-per-second limits.

Because the file endpoint is **asynchronous**, `Client::translate()` performs the full
round-trip synchronously within Polylang's MT contract. The protocol is done by the
official **Supertext PHP SDK** ([Supertext/Supertext-API-SDK-and-Guide](https://github.com/Supertext/Supertext-API-SDK-and-Guide)),
shipped inside the plugin in `includes/Sdk/` (namespace `Supertext\Polylang\Sdk`, so it
can't clash with another plugin that ships the SDK); HTTP goes through WordPress
(`wp_remote_request()`, `Machine_Translation\Wp_Http_Transport`):

1. Serialize the entity's strings into one HTML document, each wrapped in
   `<div data-st-id="N">…</div>` (Supertext translates each such element on its own and
   preserves markup/attributes).
2. `POST /translate/ai/file` (multipart) → `file_id`.
3. Poll `GET /translate/ai/file/{file_id}/status` every ~2s until `done`
   (handling `translating` / `error` / `limit_exceeded` / `deleted`).
4. `GET /translate/ai/file/{file_id}/translation` → translated HTML.
5. Split by `data-st-id` and map back onto the entries; `DELETE` the file.

HTTP 429 (Supertext's per-second rate limit) is retried up to 4 times (`Retry-After`, else
1/2/4/8 s). A pasted `Supertext-Auth-Key ` prefix on the API key is stripped. Errors come
back as `WP_Error` codes `supertext_<code>` (e.g. `supertext_invalid_language_pair`,
`supertext_quota_exceeded`, `supertext_unsupported_language`, `supertext_unsupported_feature`),
as before; every SDK error code has its own translated message in `Client::to_wp_error()`.

The SDK provides the translation client and the API key check (`Client::checkApiKey()`,
`Protocol::apiKeyFingerprint()`, `Supertext::SDK_VERSION`); version reports are
[Supertext Plugin Health](#supertext-plugin-health)'s. **Never edit `includes/Sdk/`**: fix the
SDK in its own repository, then run `scripts/vendor-sdk.sh [ref]` (`SDK_DIR=/path/to/clone`
uses a local clone); `includes/Sdk/COMMIT` names the revision. Human (professional) translation
orders use a different Supertext API and keep their own client (`includes/Human_Translation/`).

> ⚠️ This blocks the request while polling. AI file translation is usually quick, but for
> very large content it can approach PHP execution limits. A future iteration could move
> this to a background job. Polling interval/timeout are filterable (see below).

`Translations` is called once per entity (post, linked term), so a single post translation
is a small number of file calls, comfortably under the file endpoint's 1-req-per-second cap.

#### Filters

| Filter | Default | Purpose |
|--------|---------|---------|
| `supertext_polylang_endpoint` | `https://api.supertext.com/v1/` | API base URL (also settable in the UI) |
| `supertext_polylang_auth_headers` | `Authorization: Supertext-Auth-Key {key}`, `Accept`, `User-Agent` | Headers of every AI request |
| `supertext_polylang_review_endpoint` | `SUPERTEXT_REVIEW_URL` (constant, else environment variable), else `https://localizationreview-production.up.railway.app/v1/` | Base URL of the review service for languages with *Translation review* on (`''` = review off for every language), see [Translation review](#translation-review) |
| `supertext_polylang_language_code` | The mapped code, else the exact match of the locale, else the SDK default (also for the locale without `_formal`/`_informal`), else `''` | The Supertext target code of a Polylang language (`''` = not supported: `unsupported_language`); wins over the mapping drop-down, which then says so |
| `supertext_polylang_poll_interval` | `2` (seconds) | Status poll interval |
| `supertext_polylang_poll_timeout` | `180` (seconds) | Max time to wait for `done` |
| `supertext_polylang_screenshot_endpoint` | `https://vibeboost.me/api/screenshot` | [VibeBoost Screenshots](https://vibeboost.me) capture endpoint used to attach a page screenshot (DocumentTypeId 3) to human orders |
| `supertext_polylang_human_services` | the list loaded from `api/v1/util/ordertype/{ServiceID}` | Override/extend the human **translation types** offered when ordering (see below) |
| `supertext_polylang_quote_currency` | `''` (account default) | Currency to request on the human-order price quote |
| `supertext_polylang_accept_language` | BCP-47 of the user's back-end language (`de-CH,de;q=0.9`) | `Accept-Language` sent to the order API, which decides the language of the translation types, delivery options and error messages |

Politeness (formal/informal) is sent only for targets where `Languages::supportsPoliteness()` is
true: the language's *Formality* in the mapping (preselected from the locale), else `*_formal` → `more`, `*_informal` → `less`,
else Supertext decides.

#### Language codes

Supertext accepts only the codes of its `GET features` list (the SDK's
`Supertext\Polylang\Sdk\V1\Languages`, 28 sources and 37 targets): sources bare (`en`),
targets regional only for de/en/fr/it/pt (`de-CH`, `en-US`, `pt-BR`), a script for zh/sr
(`zh-Hans`, `sr-Latn`), `gsw-u-sd-chzh`/`gsw-u-sd-chbe`, and bare for all others (`es`, `nl`,
`sl`, `fi`, `ja`). Anything else is rejected with `INVALID_LANGUAGE_PAIR`.

A Polylang language is resolved by its **locale** (`Service::resolve_target()`,
`Machine_Translation\Language_Codes`, no WordPress dependency,
`tests/unit/cases/LanguageCodesTest.php`):

1. the administrator's mapping: a drop-down per Polylang language (*Language mapping* in the
   Supertext section of Polylang's machine-translation settings, stored as
   `machine_translation_services.supertext.languages` = Polylang slug → Supertext code), then
   the `supertext_polylang_language_code` filter (wins; the mapping table says so);
2. else an exact match of the locale, apart from case and `_`/`-` (`de_CH` → `de-CH`);
3. else the SDK default (`Languages::target()` → `defaultTarget()`, SDK 0.4.0): `de`/`en`/`fr`/
   `it`/`pt` → `de-DE`/`en-US`/`fr-FR`/`it-IT`/`pt-PT`, `zh` → `zh-Hans`, `sr` → `sr-Latn`,
   `zh_TW`/`zh_HK`/`zh_MO` → `zh-Hant`, `zh_CN`/`zh_SG` → `zh-Hans`, a region of a bare target
   → the bare target (`es_ES` → `es`, `nl_NL` → `nl`);
4. else (WordPress-specific, `Language_Codes::suggest()`) the same for the locale without its
   formal/informal variant: `de_DE_formal` → `de-DE`, `de_CH_informal` → `de-CH`,
   `nl_NL_formal` → `nl`;
5. else the SDK error `unsupported_language`: nothing is sent, and the editor gets
   `supertext_unsupported_language` naming the language and the drop-down to set.

Nothing else is guessed: `en_IE`, `de_LI`, `fr_BE`, `gsw`, `sr_RS`, `ca_valencia`,
`pt_PT_ao90` need a mapping. The drop-down (`Language_Codes::targets()`, the SDK's
`targetOptions()` as *Name (code)*, names localized with PHP intl's `Locale::getDisplayName()`
in the user's back-end language where intl is installed, else English) preselects the saved
code, else the suggestion of steps 2–4 (`Language_Codes::suggest()`), else *— choose —*. So the
screen and the translation always agree, and an untouched mapping behaves like the
suggestion. Saving is validated server-side (`Service::sanitize_saved_options()` on
`pre_update_option_polylang`): only Supertext's targets are stored (in Supertext's spelling),
anything else becomes `''`; formality only `more`/`less` and only for a target that offers it.

Upgrading from earlier versions needs no migration: the option is the same slug → code map; a blank entry
(formerly *Automatic*, an exact match) now shows and uses the suggestion, which for every
locale that had an exact match is the same code.

One mapping serves both directions: the source is `Languages::source()` of the language's
target code (`de-CH` → `de`), so a source language needs no separate setting. The mapping
table shows each language's status, and the status panel on **Supertext → Settings** lists
languages without a mapping and without a default.

Formality (`machine_translation_services.supertext.politeness` = slug → `more`/`less`/`''`)
is offered and sent only where `Languages::supportsPoliteness()` is true (the drop-down shows
or hides it as the language changes); it is preselected from the locale (`*_formal` → *Formal*,
`*_informal` → *Informal*). Otherwise nothing is sent (Supertext would answer
`unsupported_feature`).

#### Translation review

*Translation review* is a checkbox per Polylang language in the mapping table (stored as
`machine_translation_services.supertext.review` = slug → bool, off by default; a hidden `0`
before each box saves an untick; `Service::sanitize_saved_options()` keeps booleans only; no
migration: a missing entry is off). Translations **into** a language with review on go
through Supertext's review service, the translation proxy of
[Supertext Localization Review](https://github.com/Supertext/Localization-Review-Supertext-Translation):
`Client::route_for()` picks the base URL per target language and `Client::sdk()` builds the
SDK client for it with the same key, timeouts, transport (`Wp_Http_Transport`) and
User-Agent (`supertext-wordpress-polylang/<version>`, the version of the reports). The SDK's
`translateTexts()` does upload, status, download and delete on that one client, so a job is
always collected where it was submitted. The proxy speaks the same API v1, forwards to the
live Supertext API with the plugin's key, stores every segment per key and serves reviewers'
corrections on the next upload, so nothing else changes.

The review URL is `Client::review_url()`: the `SUPERTEXT_REVIEW_URL` constant, else the
environment variable, else `Client::REVIEW_URL`, then the `supertext_polylang_review_endpoint`
filter. `''` (or the same URL as the API) turns review off for every language. The key check,
Commando Center reports, translation files (export/import, no API calls) and human
(professional) translation orders never use the review service; an authentication failure through it isn't reported to the Commando Center as a key
check result. Tests: `MachineTranslationClientTest` (a review language's four calls go to the
review URL, others directly, the key check directly, an empty URL or the API's own URL turns it
off, the default is the review service, saved values are booleans).

Known limitations: corrections reach WordPress only with the next translation (no push); the
review service only works with live keys.

## Translation files: export for translation / import translation

Besides AI and human translation, editors can export a post or page as a file, have it translated elsewhere and
import the result: the **Export for translation** row action in the posts and pages lists opens *Supertext →
Translation files*, where they choose XLIFF 1.2 (for translation tools such as Trados, memoQ or Phrase; several
languages in one file) or HTML (one language per file, for translators or Supertext's web app, which keeps the
file's head and `data-st-id` attributes), and later upload the translated file. The import saves drafts through the
same write-back as human orders and shows what was imported and what was skipped (source changed since the export,
not translated, formatting changed, not in the file). Files over 10 MB, files from another system and translations
that add markup the source doesn't have are refused; uploads aren't stored. No screenshot yet: the screenshots come
from a live site with Polylang Pro.

*Export for translation* (XLIFF 1.2 or HTML) and *Import translation* with the SDK's
`TranslationFiles` (SDK 0.5.0, `Supertext\Polylang\Sdk\Files`; integration guide →
*Translation files: XLIFF and HTML*). Nothing here calls the API, and no API key is needed.

| File | Role |
|------|------|
| `includes/Admin/Files_Page.php` | *Supertext → Translation files* (`edit_posts`): the export form (opened from the **Export for translation** row action in the posts/pages lists, next to the Supertext bulk actions), the download (`admin-post.php?action=supertext_polylang_export_file`, nonce, `edit_post`), the upload (`…_import_file`, nonce; over 10 MB refused before reading; parsed in memory, never stored) and the summary (kept 60 s in a per-user transient for the redirect). Registered only with Polylang Pro. |
| `includes/Files/Translation_File_Glue.php` | No WordPress dependency (unit-tested with a fake store, `tests/unit/cases/FileExportImportTest.php`): builds the jobs, checks imported jobs, counts the results, hands `ok` texts (and `source_changed` ones with the option) to the store. |
| `includes/Files/Polylang_File_Store.php` | Segments from `Human_Translation\Content::collect()` (Polylang's export pipeline with `include_translated_items`, the entries the AI client sends, all as HTML like the AI client), keyed by Polylang's translation context; permissions; write-back through `Human_Translation\Writeback::save_map()` (Polylang's MT `Processor::save()`, shared with human orders: creates or updates the linked translation as a draft, YOOtheme/ACF write-back). |

- **Job metadata:** `plugin` = `supertext-wordpress-polylang`; `reference` = `post:<post id>:<Polylang language slug>`
  (e.g. `post:42:de`; anything else is *not found*); `targetLanguage` = `Service::resolve_target()` (the mapping,
  an exact match, the default; the `supertext_polylang_language_code` filter applies), `sourceLanguage` = the post
  language's code in its source form (as the AI client sends it); a language without a Supertext code is sent as
  its locale with `-` for `_` (`en_IE` → `en-IE`), so it doesn't block an export. File names
  `wordpress-<post id>-<target>.xlf` / `.html`, `wordpress-<post id>.xlf` for several languages.
- **Import checks per job:** `plugin` is ours (else *other system*), the reference parses and the post exists with a
  Polylang language (*not found*), the language exists and isn't the post's own (*no language*), the user may
  `edit_post` the original and its translation, or create a post of its type (WordPress's *Sorry, you are not
  allowed to edit this post.*). `untranslated`, `invalid_markup` and `missing` are only counted.

## Commando Center: version reports, API key check, ping

Protocol: [Commando-Center-Supertext-Translation](https://github.com/supertext/Commando-Center-Supertext-Translation)
→ `docs/DEVELOPER.md` → *Report protocol*. [Supertext Plugin Health](#supertext-plugin-health)'s
`InstallationReporter`, `reportsEnabled()` and `pingResponse()` implement it, with the SDK's
`checkApiKey()` and `apiKeyFingerprint()` as its key checker and `Supertext::SDK_VERSION` as
`sdk_version`; `includes/Reporting/Reporting.php` (no WordPress functions) loads the state, runs
one reporter action and stores the new state, never throwing; `includes/Reporting/Commando.php`
gives it what WordPress knows and wires the hooks.

- **Two keys, by name** (Plugin Health ≥ 0.2, field `api_keys`): `AI translation` (Polylang's
  Supertext service option `api_key`, checked with the SDK's `checkApiKey()`) and, once the
  account email or the Order API Key is set, `Professional orders` (the email and the key from
  `supertext_polylang_settings`, checked with an authenticated `GET api/v1/util/ordertype/<ServiceID>`
  of the chosen environment, which costs nothing: 2xx `ok`, 401/403 `invalid`, no answer or 5xx
  `unreachable`, else `error`; email or key missing: `missing` without a request). The names are
  the dashboard's keys: never translate or rename `Reporting::KEY_AI` / `KEY_ORDERS`. Plugin Health
  gets one key value per name; the orders value combines email and key
  (`Reporting::orders_key()`), so a change of either is checked again; the checker recognizes it
  by its prefix. Neither the keys nor the email are sent or stored, only their SHA-256 fingerprints.
- **Installed plugins and languages** (Plugin Health ≥ 0.4, event `extensions`): **Send configuration
  to Supertext** in the status panel (admin-post action
  `supertext_polylang_send_extensions`) sends one report with `Commando::installed_extensions()`
  (every plugin, `get_plugins()` with `active` from `is_plugin_active()`, network-wide included;
  must-use plugins and drop-ins, type `mu-plugin`, `drop-in`; themes, `wp_get_themes()`, active =
  the current theme or its parent; name, version, active and type, never paths, settings or licence
  keys) and `Commando::language_mapping()` (every Polylang language as its locale, `code`, with
  the Supertext code it is translated as, `supertext`: the same resolution as
  `Service::resolve_target()`, i.e. the language's drop-down, an exact match, the SDK's default,
  the `supertext_polylang_language_code` filter; `null` when it isn't mapped). Only by hand, only
  while version reports are on; the result (*Sent: N plugins and themes, M languages.*, failed,
  or reporting off) is shown once on the next page view. Other reports never carry the lists.

- **Setting:** `version_reports` in the option `supertext_polylang_settings` (*Send version
  information to Supertext*, on by default, on **Supertext → Settings**).
  `SUPERTEXT_VERSION_REPORTS` (`0/false/off/no`, `1/true/on/yes`) on the server wins
  (`reportsEnabled()`); the page says so. Off: no reports, ping 404. `SUPERTEXT_REPORT_URL`
  sends reports elsewhere (tests: a fake transport).
- **Report fields:** `site_url` = `home_url( '/' )`; plugin `supertext-wordpress-polylang`
  with `SUPERTEXT_POLYLANG_VERSION` (the plugin header's version); system `WordPress` with
  `get_bloginfo( 'version' )`; runtime PHP; `callback.url` = `rest_url( 'supertext/v1/ping' )`.
  HTTP goes through `Wp_Http_Transport` (`wp_remote_request()`; Plugin Health's reports via
  `Reporting\Report_Transport`, which only converts between the two libraries' HTTP classes),
  with their timeouts (3 s per report, 10 s for the key check). The key check uses the Supertext environment
  chosen in the settings.
- **When:**
  - `afterSettingsSaved`: Polylang's machine-translation save calls
    `Settings::is_api_key_valid()`, which runs it with the key and environment being saved
    and returns the check's message as Polylang's notice; the `update_option_polylang` hook
    runs it on `shutdown` when the Supertext options changed without that call. The save of
    **Supertext → Settings** (`pre_update_option_supertext_polylang_settings`, options.php)
    runs it with the new setting and shows the message as a settings notice. At most once per
    request.
  - `onRequest`: `admin_init` on the Supertext pages and Polylang's settings page, and every
    AI translation (`Client::translate_segments()`). Runs once per request on `shutdown`,
    after `fastcgi_finish_request()` where available. Never on front-end views.
  - `authenticationFailed`: an AI translation failed with `authentication_failure`
    (HTTP 401/403; key `AI translation`), or an order request got 401/403
    (`Human_Translation\Client`; key `Professional orders`); replaces `onRequest` for that request.
  - `testConnection`: **Test connection** in the status panel (admin-post action
    `supertext_polylang_test_connection`; checks both keys, the results are shown once on the next
    page view, one notice per key).
  - `sendExtensions`: **Send configuration to Supertext** (see above).
- **State:** Plugin Health's `ReporterState` JSON in the option `supertext_polylang_reporter`
  (not autoloaded); it reads the JSON the Supertext SDK 0.1–0.2 reporter stored, so sites keep their
  history (no new `installed` report); since 0.14.0 it also holds `keyChecks` and
  `reportedKeyStatuses` (per key name). A stored single-key check counts as the AI key's. Unreadable state is treated as empty. The API key is never stored, sent or logged; only
  the SDK's fingerprint is kept. Errors are logged only with `WP_DEBUG`.
- **Ping:** REST route `GET /wp-json/supertext/v1/ping` (`permission_callback`
  `__return_true`, no login or nonce) answers `pingResponse()`'s status, headers
  (`Cache-Control: no-store`) and body.
- **Messages:** `Commando::message()` has the center's five texts, with the account and API
  key links (new tab) for `invalid` and `missing` (bare URLs where Polylang shows plain text);
  for `Professional orders`, `invalid` and `missing` have their own texts pointing to the Supertext
  account settings (where the Order API Key is). The status panel shows one row per key
  (`Commando::key_label()`); Polylang's settings show the AI key's.
- **Tests:** `tests/unit/cases/ReportingTest.php` and `CommandoTest.php` use a fake transport
  and `SUPERTEXT_REPORT_URL` pointing at `127.0.0.1`, so they never reach the live center.

### Supertext Plugin Health

[Supertext Plugin Health](https://github.com/supertext/Commando-Center-Supertext-Translation/tree/main/plugin-health)
(in the Commando Center's repository, `plugin-health/`) is the dependency-free library every
Supertext plugin uses for the version reports, the report of the API key check's result and the
answer of the ping endpoint; the key check itself is the SDK's. The plugin ships its PHP part in
`includes/PluginHealth/` (namespace `Supertext\Polylang\PluginHealth` instead of
`Supertext\PluginHealth`, loaded by the plugin's own autoloader and checked by PHPStan like
`includes/Sdk/`); `COMMIT` and `VERSION` there name the revision. **Never edit
`includes/PluginHealth/`**: fix it in Commando-Center-Supertext-Translation/plugin-health, then
run `scripts/vendor-plugin-health.sh <tag>` (e.g. `plugin-health-v0.3.0`; default `main`;
`PLUGIN_HEALTH_DIR=/path/to/clone` uses a local clone as it is).

## Interface languages

The plugin's strings use the text domain `supertext-polylang`. German, French and Italian
are in `languages/supertext-polylang-{de_DE,fr_FR,it_IT}.po` (formal address: Sie, vous,
Lei); the plugin loads the matching `.mo` on `init` for each user's back-end language, with
regional locales falling back to their language (`de_CH`, `de_DE_formal` → `de_DE`). After
changing a `.po`, run `php scripts/compile-translations.php` (no gettext tools needed);
`tests/unit/cases/TranslationFilesTest.php` checks that the three files have the same
strings, placeholders and URLs, that the `.mo` files are current, and that **every
translatable string of the plugin** is translated — it walks the main file plus all of
`includes/` (minus the vendored `Sdk/` and `PluginHealth/`), so a new string cannot ship
without all three translations. The whole back end is covered: menu entries, the status
page, both settings pages, orders, bulk actions, the string-translation grid, tools, the
Gravity Forms page, the preview-link box and every error message.

## YOOtheme Pro page-builder integration

YOOtheme stores each page's layout as JSON inside an HTML comment in `post_content`
(`<!-- {…} -->`). Translating that blob with any translator corrupts the JSON, so this
plugin translates it **field-by-field** via Polylang's export/import hooks
(`pll_export_post_fields`, `pll_after_post_export`, `pll_filter_translated_post`) — the same
pipeline used by both XLIFF and the Supertext MT path. Only **string-valued** props in the
translatable set are touched; structure, CSS, ids, and URLs are left intact. See
`includes/Integrations/YooTheme/`. The hooks register automatically — no configuration
needed.

#### YOOtheme filters

| Filter | Default | Purpose |
|--------|---------|---------|
| `supertext_polylang_yootheme_fields` | `['content','title','meta','alt','image_alt']` | Prop keys whose **string** values are translated |
| `supertext_polylang_yootheme_skip_content_types` | `['code']` | Element types whose `content` prop must NOT be translated (raw code) |

```php
// Translate an extra YOOtheme text prop (e.g. a custom element's "subtitle").
add_filter( 'supertext_polylang_yootheme_fields', function ( array $keys ): array {
    $keys[] = 'subtitle';
    return $keys;
} );
```

> All filters above are applied on every run with their built-in defaults, so the plugin
> works out of the box. Adding a callback (in an mu-plugin, theme `functions.php`, or this
> plugin) is only needed to *extend* the defaults.

## Gravity Forms integration

Gravity Forms aren't posts — they live in Gravity Forms' own tables — so they can't ride
Polylang's post pipeline like YOOtheme does. Instead the integration
(`includes/Integrations/GravityForms/`) collects a form's visible strings, registers them
with Polylang, and swaps them at render time.

- **Collection** — `Fields::collect()` walks a form and returns its translatable strings
  keyed by a stable path (`title`, `button.text`, `field.7.label`, `field.7.choice.2.text`,
  `field.9.input.1.customLabel`), keyed by field *id* so the paths survive field reordering.
  Covered properties: the form title/description/button text, and per field its `label`,
  `description`, `placeholder`, `errorMessage` and `content`, each choice's `text`, and each
  input's placeholder and **sub-label**.
- **Sub-labels** — multi-input fields (Name, Email-with-confirmation, …) render an input's
  `customLabel` when the admin set one, and only fall back to the built-in `label`
  otherwise. Collection *and* render therefore target `customLabel` when present (that is
  what the visitor actually sees); inputs flagged `isHidden` (e.g. an unused name
  prefix/suffix) are skipped. See `Fields::sublabel_prop()`. Getting this wrong stores a
  translation that never displays — see `tests/unit/cases/GravityFormsFieldsTest.php`.
- **Storage** — strings are registered with Polylang (`pll_register_string`, grouped per
  form) and their translations live in Polylang's own per-language store (`PLL_MO`), keyed
  by the *source string value*. A Supertext AI translation, an edit in Polylang's grid and
  an edit here are all the same record. Registration is per-request and gated by a
  persistent opt-in flag (`Strings::is_enabled()` — the **Add to String Translations**
  button); front-end rendering does not depend on that flag.
- **Render** — `Integration::translate_form()` hooks `gform_pre_render` and
  `gform_pre_validation`, and on a non-default language rewrites each visible string through
  `Fields::apply_callback()` with `pll__()`. Untranslated strings fall back to the source.

## Human translation types (loaded, not hardcoded)

The **Translation type** dropdown (Posts/Pages bulk action and the String Translations
grid) is loaded from the Supertext account rather than hardcoded, by
`includes/Human_Translation/Services.php`. Like the rest of the human/order API (orders,
quotes, file upload/download) this call goes through `Human_Translation\Client`, not the
Supertext SDK — the SDK covers the AI translation API only:

```
GET api/v1/util/ordertype/{ServiceID}      # Basic auth, same credential as orders
Accept-Language: de-CH,de;q=0.9            # the language the names come back in
```

```json
[
  {
    "Name": "Translation BASIC",
    "DeliveryOptions": null,
    "Description": "Machine translation with full post-editing by a qualified translator. …",
    "ShortDescription": "For general texts with a limited reach",
    "OrderTypeConfigurationId": 166,
    "OrderTypeId": 54
  }
]
```

`Services::parse()` keys the response by **`OrderTypeConfigurationId`** (the id sent as the
order's and the quote's `OrderTypeConfigurationId`) and keeps the matching **`OrderTypeId`**
(sent as the quote's `OrderTypeId`), plus `Name` → the option label and `ShortDescription`
→ its tooltip. The API's own ordering is preserved.

| Concern | Behaviour |
|---------|-----------|
| **ServiceID** | Settings → **ServiceID** (`service_id` in `supertext_polylang_settings`), default `46`. A blank/invalid value falls back to the default rather than emptying the dropdown. |
| **Language** | Every order-API request carries `Accept-Language`, built from the current user's back-end language (`Client::accept_language()`): `de_CH` → `de-CH,de;q=0.9`. That is what makes the dropdowns readable — the type `Name`/`Description` here *and* the delivery option `Name` of `translation/quote` come back localized, as do the API's error messages. Override it with the `supertext_polylang_accept_language` filter. |
| **Caching** | A `supertext_polylang_order_types_{env}_{ServiceID}_{language}` transient, 12 h, plus a per-request memo. The language is part of the key, so a German and a French admin never share a list. Saving the settings flushes it for the old *and* new env/ServiceID pair (`Services::on_settings_saved()`); `Services::flush()` does it on demand. |
| **Failure** | On a transport/HTTP error — or an empty list — `Services::FALLBACK` (the three standard types of ServiceID 46) is used and cached for 5 minutes, so ordering keeps working and the next page load retries. The request uses a 15 s timeout so a slow API can't stall the posts list. |
| **Validation** | `Services::exists()` gates every order and quote: a type the ServiceID doesn't offer is rejected. `Services::label()` falls back to `FALLBACK`, then to the raw id, so historical orders still render on the Orders page. |

`Bulk_Actions::service_label()` / `::order_type_id()` remain as thin delegates to
`Services`.

## Required Polylang patch (one line)

Polylang Pro keeps its MT services in a **hardcoded const** with no registration hook:

```php
// src/modules/Machine_Translation/Factory.php
const SERVICES = array( Deepl::class );
```

To let plugins register a service, `Factory::get_classnames()` must expose a filter. This
single method feeds three consumers — the service picker, the option defaults, and the
**strict option storage schema** (`additionalProperties: false`, so without it our
`supertext` options are stripped on save). Change:

```php
public static function get_classnames(): array {
    return self::SERVICES;
}
```

to:

```php
public static function get_classnames(): array {
    /**
     * Filters the list of machine translation service class names.
     *
     * @param string[] $services List of service class names.
     */
    return apply_filters( 'pll_mt_services', self::SERVICES );
}
```

Without this patch the plugin loads but stays inert, and shows an admin notice saying so.

> ⚠️ This patches Polylang Pro's own code, so it must be re-applied after every Polylang
> update. It is a minimal, upstreamable change — ideally Polylang/WP Syntex adds the filter
> to core so no patch is needed.

## Activating Supertext

1. Apply the patch above and activate this plugin.
2. *Languages → Settings → Machine Translation* → enable machine translation.
3. Choose **Supertext**, enter the API key (no Supertext account yet? create one at
   *[supertext.com](https://www.supertext.com/person/en/account/signin)*; then generate the key at
   *[Supertext → Integrations → API](https://www.supertext.com/en/integrations/api)*, requires the Admin role), and map each Polylang language to its Supertext
   code (the fields are pre-filled with BCP-47 suggestions; adjust where Supertext differs).
   Save.
4. `is_active()` becomes true once a key is stored; Supertext is then used for AI
   translation of posts/strings.

> Note: `Factory::get_active_service()` returns the **first** configured service. If a DeepL
> key is also set, DeepL (listed first) wins. Leave the DeepL key empty to use Supertext.

## Deployment

`deploy.ps1` commits/pushes and SFTP-syncs the repo root to the demo server's
`.../plugins/supertext-polylang` folder. `README.md`, `.git`, and the deploy scripts are
excluded from the sync. The **Polylang patch must be applied on the server too.**

### Demo site on Railway (Docker)

`demo/Dockerfile` builds a ready-to-use WordPress + Polylang site with this plugin, as
committed (build context is the repository root):

```sh
docker build -f demo/Dockerfile -t wordpress-supertext-demo .
```

It runs as the **WordPress** service in the Railway project *supertext-cms-demos-php*
(Dockerfile path `demo/Dockerfile`, port 8080, health check `/healthz`, database `wordpress_demo` on the shared **MySQL-8** service in `ams` (the older *MySQL* service's volume is in the US, which makes every page slow); `DEMO_*` reference the shared values of the Contao service)
and redeploys on every push to `main`. On every start `demo/docker/entrypoint.sh` creates
the database if needed, rebuilds `wp-config.php` from the variables, installs WordPress
once, activates the plugins and runs `demo/docker/setup.php`: languages English (default),
German, French and Italian, the demo accounts, the Supertext settings and five English
pages and posts to translate. No one has to register or click through an installer.

| Variable | Required | Purpose |
|---|---|---|
| `DATABASE_URL` (or `MYSQLHOST`, `MYSQLPORT`, `MYSQLUSER`, `MYSQLPASSWORD`) | yes | MySQL server |
| `WORDPRESS_DB_NAME` | no | Database name (default: the path of `DATABASE_URL`, else `wordpress`); created if missing |
| `DEMO_ADMIN_EMAIL`, `DEMO_ADMIN_PASSWORD` | yes | Administrator (login `admin` or the e-mail), created on the first start; existing accounts are never changed |
| `DEMO_EDITOR_EMAIL`, `DEMO_EDITOR_PASSWORD` | no | Editor account (login `editor` or the e-mail), can translate into every demo language |
| `SUPERTEXT_API_KEY`, `SUPERTEXT_ACCOUNT_EMAIL`, `SUPERTEXT_ENVIRONMENT` | no | Seed the human-translation settings while they are still empty |
| `POLYLANG_PRO_ZIP_URL` | no | Private download URL of the Polylang Pro zip. Without it the demo runs **free Polylang**: the Supertext settings, orders, tools and string translation work, but AI and human translation of posts need Polylang Pro (the settings page says so). With it, Pro is installed and patched on start |
| `DEMO_SITE_URL` | no | Public URL (default `https://$RAILWAY_PUBLIC_DOMAIN`) |
| `WORDPRESS_SALT` | no | Seed for the auth keys (default: derived from `DATABASE_URL`, so logins survive redeploys) |

The container has no volume: uploaded media is lost on redeploy; posts, settings and
accounts live in MySQL.

## Code quality and security checks

- **Checks** (`.github/workflows/checks.yml`): on every push and pull request, [actionlint](https://github.com/rhysd/actionlint) and [zizmor](https://docs.zizmor.sh) lint the workflows. On pull requests, dependency review fails a PR that adds a package with a known vulnerability (moderate or worse). Third-party actions are pinned to commit SHAs (Dependabot keeps them current); checkouts don't keep credentials (`persist-credentials: false`), and workflows get `contents: read` unless a job needs more (the release job: `contents: write`; the unit-test job: `checks: write` for its test report).
- **Links** (`.github/workflows/links.yml`): [lychee](https://lychee.cli.rs) checks the links in all Markdown files weekly and whenever docs change on `main`. Broken links open or update the issue "Broken links in the docs" (a docs push that breaks links also fails). Links that can't work from CI go in `.lycheeignore` (one regex per line).
- **PHPStan** (job `phpstan` in `unit-tests.yml`, config `phpstan.neon`): level 5 on the plugin's own code (`supertext-polylang.php`, `includes/`), not the tests. PHPStan and the stubs it needs (WordPress via [szepeviktor/phpstan-wordpress](https://github.com/szepeviktor/phpstan-wordpress), Polylang via `wpsyntex/polylang-stubs`, Gravity Forms via `php-stubs/gravity-forms-stubs`) are dev dependencies in `tests/unit/composer.json`. Polylang Pro and YOOtheme have no public stubs, so the findings about their classes (`WP_Syntex\Polylang_Pro\…`, `PLL_Base::$curlang`) are in the baseline. Locally: `cd tests/unit && composer install && composer phpstan` (or `tests/unit/vendor/bin/phpstan analyse` from the repository root). Existing findings that aren't simple to fix are listed in `phpstan-baseline.neon` (regenerate with `tests/unit/vendor/bin/phpstan analyse --generate-baseline` after fixing one). New code must not add findings.
- **GitHub settings** (set by Remy's setup script, not in the repo): secret scanning with push protection (a push containing a known token format is rejected; findings under *Security → Secret scanning*) and CodeQL default setup (findings under *Security → Code scanning* and as PR comments). CodeQL doesn't cover PHP, which is why this repo runs PHPStan.

Before starting work in this repo, look at its open findings: code scanning alerts, secret scanning alerts, Dependabot PRs and the "Broken links in the docs" issue.

<!-- supertext-plugins:start (shared list, keep identical in every Supertext plugin repo) -->
## Supertext plugins for other systems

Supertext offers AI and professional translation plugins for these systems:

### Content management systems (CMS)

| System | Plugin | Type of integration | What it does |
| --- | --- | --- | --- |
| Adobe Experience Manager | [supertext-aem-connector](https://github.com/Supertext/supertext-aem-connector) | Translation connector: two AEM content packages for AEM's Translation Integration Framework. | Sends AEM translation projects to Supertext and imports the results |
| ApostropheCMS | [Apostrophe-Supertext-Translation](https://github.com/Supertext/Apostrophe-Supertext-Translation) | Apostrophe module (npm): a translation provider for Apostrophe's own *Localize…* step. | Translates pages and pieces as editors localize them, widgets and rich text included |
| Contao | [Contao-Supertext-Translation](https://github.com/Supertext/Contao-Supertext-Translation) | Contao bundle (Composer) that adds a back-end action. | *Translate with Supertext* in the site structure: pages or whole websites into other languages |
| Craft CMS | [CraftCms-Supertext-Translation](https://github.com/Supertext/CraftCms-Supertext-Translation) | Craft plugin (Composer) with a panel on the entry page. | Translates entries into your other sites, Matrix and rich text included |
| Directus | [Directus-Supertext-Translation](https://github.com/Supertext/Directus-Supertext-Translation) | Directus extension bundle (npm): interface, endpoint, Flow operation and module. | *Translate with Supertext* box on the item form, fills the Translations field |
| django CMS | [djangoCMS-Supertext-Translation](https://github.com/Supertext/djangoCMS-Supertext-Translation) | Django app (Python package) that adds a toolbar entry. | Translates pages and their plugins from the toolbar |
| Drupal | [tmgmt_supertext_ai](https://www.drupal.org/project/tmgmt_supertext_ai) | Drupal module: a translator provider for the Translation Management Tool (TMGMT), by MD Systems. | Translates TMGMT jobs with Supertext AI |
| Ghost | [Ghost-Supertext-Translation](https://github.com/Supertext/Ghost-Supertext-Translation) | Separate connector service (Ghost has no admin plugins): works through internal tags, webhooks and the Admin API. | Tag a post `#translate-…` and a translated draft appears |
| Grav | [Grav-Supertext-Translation](https://github.com/Supertext/Grav-Supertext-Translation) | Grav 2 plugin with an Admin2 panel. | Supertext panel in the page editor, Markdown kept intact |
| HubSpot | [HubSpot-Supertext-Translation](https://github.com/Supertext/HubSpot-Supertext-Translation) | Hosted app connected to HubSpot with OAuth, working through HubSpot's multi-language content. | Translates website and landing pages, blog posts, marketing emails and knowledge base articles into draft language versions |
| Joomla | [Joomla-Supertext-Translation](https://github.com/Supertext/Joomla-Supertext-Translation) | Joomla system plugin (installable package). | Translates articles into linked, unpublished language versions |
| Magnolia | [Magnolia-Supertext-Translation](https://github.com/Supertext/Magnolia-Supertext-Translation) | Magnolia module with a *Translate with Supertext* action in the Pages app. | Translates pages, areas and components into the site's other languages |
| Neos | [Neos-Supertext-Translation](https://github.com/Supertext/Neos-Supertext-Translation) | Neos package (Composer) that hooks into the content repository, with a Supertext backend module for translation files. | Translates automatically when an editor creates a page in another language |
| Orchard Core | [OrchardCore-Supertext-Translation](https://github.com/Supertext/OrchardCore-Supertext-Translation) | Orchard Core module (.NET) with an admin page and a localization hook. | Translates content items into other cultures, on demand or on localization |
| Payload CMS | [Payload-Supertext-Translation](https://github.com/Supertext/Payload-Supertext-Translation) | Payload plugin (npm) added to `payload.config`. | *Translate* button for localized collections and globals |
| Silverstripe | [Silverstripe-Supertext-Translation](https://github.com/Supertext/Silverstripe-Supertext-Translation) | Silverstripe module (Composer) on top of Fluent. | Supertext tab translates pages and Elemental blocks into Fluent locales |
| Strapi | [Strapi-Supertext-Translation](https://github.com/Supertext/Strapi-Supertext-Translation) | Strapi 5 plugin (npm) with a Content Manager panel. | Translates entries into other locales from the Content Manager |
| TYPO3 | [Typo3-Supertext-Translation](https://github.com/Supertext/Typo3-Supertext-Translation) | TYPO3 extension (Composer) that hooks into TYPO3's own localization, with a Supertext backend module for translation files. | Translates pages and content elements as editors localize them |
| Umbraco | [Umbraco-Supertext-Translation](https://github.com/Supertext/Umbraco-Supertext-Translation) | Umbraco package (NuGet) with a backoffice extension. | *Translate with Supertext* for pages, block lists and grids included |
| Wagtail | [Wagtail-Supertext-Translation](https://github.com/Supertext/Wagtail-Supertext-Translation) | Python package: a machine translator for wagtail-localize. | Translates pages and snippets inside wagtail-localize's editor |
| WordPress (Polylang) | [Wordpress-Polylang-Supertext-Translation](https://github.com/Supertext/Wordpress-Polylang-Supertext-Translation) | WordPress plugin: a machine-translation service for Polylang Pro, plus professional translation orders. | AI translation next to DeepL in Polylang, and human translation orders |

### Product information management (PIM)

| System | Plugin | Type of integration | What it does |
| --- | --- | --- | --- |
| Akeneo PIM | [Akeneo-Supertext-Translation](https://github.com/Supertext/Akeneo-Supertext-Translation) | Akeneo PIM bundle with a *Translate with Supertext* action on the product page. | Translates products and product models into your other locales |
| AtroPIM | [AtroPIM-Supertext-Translation](https://github.com/Supertext/AtroPIM-Supertext-Translation) | AtroCore module with a button on the product and a mass action in the list. | Translates products and other AtroCore records into your other languages |
| Pimcore | [Pimcore-Supertext-Translation](https://github.com/Supertext/Pimcore-Supertext-Translation) | Pimcore bundle (Composer) with a *Translate with Supertext* button in Pimcore Studio. | Translates documents into linked language versions and data objects' localized fields |

### E-commerce

| System | Plugin | Type of integration | What it does |
| --- | --- | --- | --- |
| Magento | [Magento-Supertext-Translation](https://github.com/Supertext/Magento-Supertext-Translation) | Magento 2 module (also Mage-OS) with a mass action in the admin lists and a button on the edit pages. | Translates products, categories, CMS pages and blocks into your store views' languages |
| PrestaShop | [PrestaShop-Supertext-Translation](https://github.com/Supertext/PrestaShop-Supertext-Translation) | PrestaShop module with a bulk action in the back-office lists. | Translates products, categories and CMS pages into your shop's other languages |
| Shopify | [Shopify-Supertext-Translation](https://github.com/Supertext/Shopify-Supertext-Translation) | Shopify app in the Shopify admin. | Translates products, collections, pages and blog posts into all your shop's languages |
| Wix | [Wix-Supertext-Translation](https://github.com/Supertext/Wix-Supertext-Translation) | Wix app with a dashboard page (hosted service), working through Wix Multilingual. | *In development:* translates Wix Stores products and other Wix Multilingual content into your site's languages |

### Email marketing

| System | Plugin | Type of integration | What it does |
| --- | --- | --- | --- |
| Mailchimp | [Mailchimp-Supertext-Translation](https://github.com/Supertext/Mailchimp-Supertext-Translation) | Hosted app connected to Mailchimp with OAuth: Supertext folders in Mailchimp and a translate page. | *In development:* turns a campaign into one draft per language, each sent to the subscribers with that language |

### Design files (XLIFF round trip)

| Application | Plugin | Type of integration | What it does |
| --- | --- | --- | --- |
| Adobe InDesign | [Adobe-InDesign-Translation](https://github.com/Supertext/Adobe-InDesign-Translation) | InDesign scripts (ExtendScript). | Exports all text to XLIFF 1.2 for any CAT tool and imports the translations with formatting intact |
| Adobe Illustrator | [Adobe-Illustrator-Translation](https://github.com/Supertext/Adobe-Illustrator-Translation) | Illustrator scripts (ExtendScript). | Exports all text to XLIFF 1.2 for any CAT tool and imports the translations with formatting intact |
| CorelDRAW | [CorelDRAW-Supertext-Translation](https://github.com/Supertext/CorelDRAW-Supertext-Translation) | CorelDRAW VBA macro. | Exports all text to XLIFF 1.2 for any CAT tool and imports the translations with formatting intact |
<!-- supertext-plugins:end -->

## Support

Questions, problems or ideas? Use the [Supertext support form](https://integrations.supertext.com/en/support?platform=wordpress): describe what happened and you get an answer by email, in your language. Requests are tracked as issues in this repository, without your name and email address. Developers are welcome to open issues and pull requests here directly. Security issues: see [SECURITY.md](SECURITY.md).
