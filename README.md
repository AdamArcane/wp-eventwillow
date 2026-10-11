# EventWillow Sync

Installable WordPress plugin for one-way synchronization of events and channels into native posts, metadata, attachments and taxonomies. No ACF dependency. Elementor Pro supplies optional dynamic tags and Loop Builder integration; storage works without Elementor.

## Install and migrate

Build with `python3 tools/build-release.py`. Upload the ZIP from `dist/` and activate **EventWillow Sync**. On an existing CPT Lab site, deactivate the old plugin first. Keep its Git checkout if used as the source repository. The ZIP contains only the permanent `eventwillow-sync/` folder and permanent entry point. The source-only compatibility loader is not shipped.

Internal `ew_lab_*` CPT/taxonomy/option identifiers, `ew_*` metadata, `ew-lab-*` Elementor bindings and `cpt-lab-*` REST/permalink routes deliberately remain compatible. Their public labels no longer say Lab. Renaming stored identifiers needs a separate migration; a cosmetic rename must not break templates, sync routes or existing links. No fixture data, credentials or n8n deployment is included in the installed plugin; direct sync scheduling is configured in WordPress. Deactivation preserves content and images. There is no destructive uninstall hook.

## Integration contract

See [docs/data-contract.md](docs/data-contract.md). The plugin can import directly from the EventWillow public API v1 using a site key and selected channel identifiers configured in the Sync submenu under Events. It validates complete paginated snapshots before updating or withdrawing managed records; scheduled runs are supported. Other integrations can also use standard authenticated WordPress REST writes. Never assume another upstream application's endpoints or payload match EventWillow's API. WordPress remains a one-way projection; edits here do not propagate to the source.

`eventwillow_sync_brand` changes the Elementor group branding. `eventwillow_sync_update_manifest_url` overrides or disables the self-hosted updater. They are independent of stored data and source authentication. Use Elementor Query ID `ew_upcoming` to order published events by start time and omit ended events; `ew_lab_upcoming` remains an alias.

## Updates and releases

The ATS Dashboard pattern is used: WordPress checks a hosted JSON manifest and installs its ZIP through the normal Plugins update screen. Success is cached six hours and failures fifteen minutes. WordPress's forced update checks clear the cache. The updater runs on scheduled checks and WP-CLI as well as in wp-admin, validates manifest identity and HTTPS download URLs, and has its own class/cache names.

Default manifest: `https://files.arcanetechct.com/eventwillow-sync/info.json`. This endpoint must be published before real update discovery works. Override with the `EVENTWILLOW_SYNC_UPDATE_MANIFEST_URL` constant in wp-config.php; set it to false to disable outbound checks. An upstream edition can remove this updater and use WordPress.org distribution.

Set matching versions in the plugin header, version constant and readme. Build locally with `python3 tools/build-release.py --version X.Y.Z`. ZIPs use an explicit allowlist, fixed file timestamps and a single canonical folder. The generated manifest includes a SHA-256 for release verification; the updater does not perform independent checksum verification.

The GitHub tag workflow builds, lints, and publishes an immutable versioned ZIP to R2, then replaces the manifest last. Configure repository secrets `R2_ACCOUNT_ID`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`; optional repository variables `R2_BUCKET` (defaults to plugins) and `RELEASE_BASE_URL`. Tag `vX.Y.Z` must match source. This workflow does not configure a Git remote or upload anything from the local workstation. Retain older ZIPs for rollback and install an older version manually if needed.

## Compatibility and checks

Declared minimums: WordPress 6.5 and PHP 8.0. Current sandbox validation uses WordPress 7.1.3, PHP 8.4, Elementor 4.3.4 and Pro 4.3.1; declared minimums are not a tested matrix. Atomic backgrounds reuse Elementor's internal renderer and are version-sensitive. Local class background variants are supported; global Atomic classes need separate validation. No Elementor vendor files are modified.

Run `wp eval-file tests/check-updater.php` and `wp eval-file tests/check-sync.php` on a disposable/local WordPress installation. The checks intercept HTTP and use temporary records/settings; the sync check removes its fixtures and restores saved options. Existing fixture-based rendering checks remain in the prototype project rather than the distributable ZIP. Direct sync supports the EventWillow public API v1 contract; adapting other sources and their access scopes remains the external adapter's responsibility.
