# Supertext × Polylang — Project Handoff

Knowledge transfer from the first attempt (`C:\source\repos\supertext-wordpress`,
GitHub `Supertext/supertext-wordpress`). This restart targets a cleaner architecture
built on Polylang's machine-translation **service** model and its **export/import
(XLIFF)** pipeline, instead of hijacking the bulk action and faking a `post-new.php`
request.

---

## 1. Goal

WordPress plugin integrating **Supertext** translation with **Polylang Pro**:
- **AI translation** — DeepL via a Supertext-owned proxy.
- **Human (professional) translation** — submit content to Supertext, get it back, write it into the translated post.

---

## 2. Environment & infrastructure (reused)

- **Demo server (cyon, SFTP):** `s088.cyon.net`, user `supertex`.
- **WP install:** `/home/supertex/public_html/demo.supertext.com/wp-polylang-proxy/`
  (note: the *correct* install is `wp-polylang-proxy`, NOT `supertext-polylang`).
- **Deploy:** `deploy.ps1` = git commit/push + WinSCP SFTP sync. Credentials live in
  `deploy.local.ps1` (gitignored). Carried over from the old project.
- **New plugin folder on server:** `.../wp-content/plugins/supertext-polylang`
  (set in `deploy.local.ps1`, so it won't overwrite the old `supertext` plugin).
- **PHP 8.3 on server.** Target PHP 8.1+, WP 6.0+.
- **wp-config.php:** `define('EMPTY_TRASH_DAYS', 0);` was added (posts delete immediately,
  no trash) — backup at `wp-config.php.bak` on the server.
- **GitHub:** new repo `https://github.com/Supertext/Wordpress-Polylang-Supertext-Translation`.
- **Polylang Pro source (read-only reference):** `C:\source\PlayStuff\polylang-pro-supertext`.

Deploy is authorized to run autonomously (user confirmed "keep deploying").

---

## 3. DeepL proxy (critical)

- Polylang's own DeepL client (`modules/Machine_Translation/Clients/Deepl.php`) is
  **hardcoded** to the proxy:
  `const ROUTE = 'https://deepl-supertext-proxy.vercel.app/v2/';`
  So we may not even need our own `pre_http_request` interceptor for the AI path.
- The client **skips the HTTP call entirely if the Polylang DeepL API key is empty**
  (`request()` returns 403 without calling out). A **non-empty key must be set** in
  Polylang → Settings → Machine Translation. The proxy handles real auth/billing.

---

## 4. How Polylang's AI translation actually works

Trigger: `Action::new_post_translation()` hooked on `use_block_editor_for_post` @1900
(`modules/Machine_Translation/Posts/Action.php`). Fires on a real
`post-new.php?from_post=X&new_lang=fr&_wpnonce=…` page load. Four steps:

1. **Gather** —
   ```php
   $container      = new PLL_Export_Container( Data::class );
   $export_objects = new PLL_Export_Data_From_Posts( $this->model );
   $export_objects->send_to_export( $container, array( $from_post ), $new_lang );
   ```
2. **Translate** — `Processor::translate($container)` → `Clients\Deepl` → proxy; fills translations into the container.
3. **Save** — `Processor::save($container)` → `PLL_Translation_Post_Model::translate()` → creates/links the translated post and writes data.
4. Replaces `global $post` with the new translation, disables duplication.

**Guards inside `new_post_translation` (all must pass):** `$done` flag (one-shot per
request), `global $post` non-empty, `$polylang->links` set, and
`get_data_from_new_post_translation_request()` returns both `from_post` + `new_lang`
(requires `pagenow === 'post-new.php'`, `$_GET` `from_post`/`new_lang`/`post_type`/`_wpnonce`,
nonce action `new-post-translation`, a translated post type), and user meta
`pll_machine_translation_deepl[$post_type]` is active.

### ⚠️ The big gotcha (cost us hours)
`PLL_Export_Data_From_Posts::get_posts_to_export()`
(`services/exporter/export-data-from-posts.php`) **drops any source post that already
has a translation in the target language**, unless called with
`array( 'include_translated_items' => true )`. So **do NOT pre-create / pre-link the
target post before triggering MT** — the container ends up empty, no DeepL call fires,
and you get an untranslated copy. (In the old project we let Polylang's pipeline create
the post itself for AI; the human path pre-created a draft on purpose.)

---

## 5. Data gathering & third-party plugin integration

`send_to_export()` is the single "gather everything translatable" entry point. It is used
by **both** the MT processor (container = `Data::class`) **and** the file/XLIFF exporter
(container = an XLIFF format class) — see
`modules/import-export/export/export-bulk-option.php`:
```php
$container = new PLL_Export_Container( $file_format->get_export_class( $version ) ); // XLIFF
$export_objects->send_to_export( $container, $posts, $target_language,
    array( 'include_translated_items' => true ) );
$xliff = $export->get();
```

What it collects: post title, content, excerpt; post metas; linked posts; terms + term metas.

### Extension points (the "plugin support" system)
- **Simple meta keys** → filter `pll_post_metas_to_export` (and `pll_term_metas_to_export`,
  `pll_post_meta_encodings`). Default exports include `_wp_attachment_image_alt`, `footnotes`.
- **Complex/nested fields (ACF, page builders)** → action `pll_after_post_export`
  (and `pll_after_term_export`). The integration walks its structure and calls
  `$export->add_translation_entry( array $ref, string $source, string $target = '' )`,
  where `$ref = [ object_type, field_type, field_id, object_id ]` is the breadcrumb used
  to write the translation back precisely.
- **Linked objects** → filters `pll_collect_post_ids`, `pll_collect_term_ids`
  (+ `_in_blocks` variants) to pull in referenced posts/terms.
- **Write-back on import** → actions `pll_after_post_translation`,
  `pll_after_term_translation`; filter `pll_filter_translated_post`.

ACF is the reference implementation: `integrations/ACF/Dispatcher.php` +
`integrations/ACF/Strategy/Export.php` / `Import.php`. Per-field "Translation" setting
(`translate` / `translate_once` / `copy` / `sync` / `ignore`) added via `Field_Settings.php`.

### Page builders
- Bundled **Beaver Builder** / **Divi** integrations only **copy** the layout meta via
  `pll_copy_post_metas` — they do NOT extract strings for translation.
- To make a builder's text translatable: hook `pll_after_post_export`, decode the
  builder's data (e.g. Elementor stores `_elementor_data` JSON in post meta), walk the
  tree, emit one `add_translation_entry()` per text node; hook `pll_after_post_translation`
  to slot translations back by `field_id`. Storage dictates approach:
  JSON-in-meta (Elementor/BB) vs shortcodes-in-content (WPBakery) vs custom tables.

---

## 6. Import / write-back pipeline

`PLL_Import_Action` → `PLL_Import_Posts::translate()` → `PLL_Translation_Post_Model::translate()`
(same save logic as MT). Validates generator name (`PLL_Import_Export::APP_NAME`) and site.
XLIFF parsers in `modules/import-export/xliff/` (1.2 and 2.1). This is the path to reuse
for **human translation**: gather → XLIFF → Supertext → translated XLIFF back → import.

---

## 7. Human-translation UI built in the old project (to port/redesign)

- Bulk actions on `edit.php`: **Supertext AI Translation** + **Supertext Human Translation**.
- Human shows **3 required dropdowns** between the bulk-action select and Apply:
  1. **Target language** (Polylang languages)
  2. **Translation type** — Übersetzung BASIC `54`, PREMIUM `55`, CREATIVE `56`
  3. **Delivery** — 24h `2`, 48h `3`, 3 Tage `4`, 1 Woche `5`
- These are **real Supertext option IDs**.
- Old behavior: created a linked draft copy and stored selections as post meta
  `_supertext_service_id`, `_supertext_express`. To be replaced by a real order
  submission (XLIFF + selections) to the Supertext API.

---

## 8. Polylang MT service contract (for the restart)

To present as a first-class MT service in Polylang:
- **`Service_Interface`** (`modules/Machine_Translation/Services/Service_Interface.php`):
  `is_active()`, `get_slug()`, `get_name()`, `get_icon_properties()`, `get_icon()`,
  `get_client()`, `get_settings()`, `get_option_schema()` (static).
- **`Client_Interface`** (`modules/Machine_Translation/Clients/Client_Interface.php`):
  `translate(Translations, $target_language, $source_language = null)`,
  `is_api_key_valid(): WP_Error`, `get_usage()`.

⚠️ `Machine_Translation\Factory::SERVICES` is a **hardcoded const** (`[ Deepl::class ]`) with
**no public filter** to register a new service in this build. Implications for the restart:
- (a) Implement the interfaces and find/patch a registration path, or
- (b) Ship our own MT client/pipeline that reuses `send_to_export` + the Processor/import
      and routes to Supertext, or
- (c) Use the XLIFF export/import round-trip for the human path and keep DeepL-via-proxy
      for the AI path.

---

## 9. Open questions before building

- **Supertext order endpoint contract:** URL, auth, payload shape — does it accept XLIFF?
  How are target language / `service_id` / `express` passed?
- **Return path:** webhook callback (preferred) vs polling an order-status endpoint; is the
  returned file XLIFF?
- **Architecture decision:** register a custom Polylang MT service vs. run a parallel
  Supertext pipeline that reuses Polylang's gather/import.
- **Deploy target:** confirm the new plugin folder name / slug on the server.

---

## 10. Reference paths

- Polylang Pro source (read-only): `C:\source\PlayStuff\polylang-pro-supertext`
- Old plugin project: `C:\source\repos\supertext-wordpress` (full working bulk-action implementation)
- Old GitHub repo: `https://github.com/Supertext/supertext-wordpress`
