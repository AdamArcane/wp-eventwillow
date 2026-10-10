# EventWillow Public Views

WordPress presentation for EventWillow events and channels, backed by the private Laravel site API. Browse within your WordPress theme and edit layouts with Elementor; Laravel manages source content, visibility, recurrence, permissions and transactions. Events and channels are not synchronized into WordPress posts.

## Installation

Install an `eventwillow-public.zip` release in WordPress. The plugin folder is `eventwillow-public`; the GitHub repository is `AdamArcane/wp-eventwillow`. PHP 8.0 or newer is required. Elementor is needed for the visual widgets, dynamic tags and reusable templates. Basic shortcode views work without Elementor.

Install the isolated `EventWillowPublicApi` module in the EventWillow Laravel app first. Its versioned endpoint base is `/api/eventwillow/v1`. This repository contains the WordPress plugin only; Laravel module source is maintained separately.

Configure the API base URL under **Settings → EventWillow Public Views**. Set the same `EW_SITE_API_KEY` privately on the Laravel server and the WordPress server, either through WordPress's environment or a constant in private server configuration. Never save this key in Elementor, plugin options, URLs or browser code.

Laravel also requires `EW_SITE_API_ALLOWED_IPS` and, when relevant, explicit `EW_SITE_API_TRUSTED_PROXIES`. A local development server can set `APP_ENV=local` and `EW_SITE_API_ENFORCE_IP_ALLOWLIST=false`. The key remains mandatory, and this bypass is never honored outside local mode. Regular user API keys cannot authenticate the site API.

## Editing the site

Select event and channel detail templates and reusable card templates in plugin settings. Choose actual API records for editor previews. Theme headers and footers surround plugin routes.

Elementor widgets include event feeds, event/channel directories, channel events, calendar, search, filters, fields, card image/button, actions, details and related channels. Dynamic tags work in native text, image and link controls. Fields include channel names and WordPress channel URLs. Dates support presets and custom PHP formats in the event timezone. Actions expose per-action label overrides; blank labels keep the API defaults.

Channel short description, channel description, channel type and channel accent color resolve from the current channel, or the first associated channel when used on an event. Generic Summary/Description still refer to the current record. Select Channel as the record type to use channel data in both the editor and the live page. On an event page this uses the first associated public channel, including its image, website and standalone URL. If the event has no public channel, channel fields stay empty. Event remains the default for existing controls; a standalone channel page always uses its current channel. The EventWillow Channel Accent Color dynamic tag is available in Elementor color controls; the text field exposes the same hex value. Accent colors require the updated Laravel serializer, which adds `accent_color` to channel details and event channel associations.

Select **Built-in configurable card** for image position, section visibility, spacing and color controls, or edit a reusable Elementor card template directly. Modern Elementor atomic template styles load alongside traditional styles. Record-dependent local styles are scoped to each card, so dynamic image backgrounds are not shared across events. Image fallbacks are resolved by Laravel from eligible public channel profiles.

Directories use `/events/` and `/channels/`; detail routes include stable IDs and descriptive slugs. Existing pages at directory paths take precedence: add a listing widget or shortcode there. Occurrence dates survive event links. Browsing stays in WordPress; tickets, RSVP, registration and follow actions open the native Laravel page.

Compatibility shortcodes: `[eventwillow_events]`, `[eventwillow_channels]` and `[eventwillow_event_feed type="upcoming" limit="3"]` (also `popular` and `new`).

Responses are cached for no more than two minutes. Expired data is not used during outages. Exclude plugin routes and pages from page/CDN caches that disregard the plugin's no-cache signals. Event/channel canonical URLs point to Laravel; directory and marketing pages remain independently indexable. API images remain remote and are not imported into the media library.

## Releases and R2

The **Release to R2** workflow follows the Arcane Tech plugin distribution pattern. Configure these repository Actions secrets:

- `R2_ACCOUNT_ID`
- `R2_ACCESS_KEY_ID`
- `R2_SECRET_ACCESS_KEY`

Use credentials authorized to write the existing `plugins` bucket. The workflow validates credentials before bumping or tagging.

For the first release, run **Actions → Release to R2 → Run workflow**, select `main` and choose `none` to release the version currently in the plugin. Choose `patch`, `minor` or `major` for later bumps. Alternatively, update the plugin Version header and `EWP_VERSION` together, commit, then push a matching `vX.Y.Z` tag. Manual release retries reuse their original bump commit; they do not bump again. Concurrent releases are serialized.

A successful run publishes a GitHub Release with the ZIP, manifest and checksum, and uploads:

- `plugins/eventwillow-public/eventwillow-public.zip` (latest, revalidated)
- `plugins/eventwillow-public/eventwillow-public-X.Y.Z.zip` (versioned, immutable cache)
- `plugins/eventwillow-public/info.json` (latest metadata, revalidated)

The public base is `https://files.arcanetechct.com/eventwillow-public/`. The manifest downloads the versioned ZIP. Retrying an older release does not replace a newer latest manifest/package. R2 publication does not deploy or activate the plugin on WordPress.

The built-in update checker follows ATS Dashboard's small manifest-based updater. WordPress shows newer R2 releases on its normal Plugins and Updates screens, including the version-details popup. Successful manifest checks are cached for six hours; failed checks retry after fifteen minutes. Dashboard → Updates → Check again clears this cache. Checks also work during cron and WP-CLI runs. Updates use the standard WordPress installer and its auto-update preference; automatic installation is not enabled by the plugin. The app API key is never sent to the update server.

To disable update checks, define `EW_UPDATES_ENABLED` as `false` in private `wp-config.php`. Forks can override `EW_UPDATE_MANIFEST_URL` there. The existing installation needs one manual ZIP update to receive the checker; subsequent releases can be installed through WordPress.

The package includes only runtime PHP, assets and templates, plus an optional readme/license. It excludes tests, local setup helpers, credentials, workflows, build scripts and Git files. GitHub CI validates PHP syntax and builds the same package on pushes and pull requests.

## Development

Build locally with `python3 scripts/release.py build`. Check the version with `python3 scripts/release.py version`. ZIP output and manifest are in ignored `build/`.

The `tests/` directory contains guarded DDEV-only fixtures and WP-CLI adapter checks. Do not run setup scripts against production. Run `wp eval-file wp-content/plugins/eventwillow-public/tests/adapter.php` in the configured local sandbox after installing the source. CI packaging/lint is separate from runtime/browser acceptance in WordPress and Elementor.
