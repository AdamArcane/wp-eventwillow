# 0.1.0 local validation

Validated on wp-sandbox.ddev.site with WordPress 7.1.3 / PHP 8.4, Elementor 4.3.4 / Pro 4.3.1.

- Installable ZIP installed and activated through WP-CLI, then the existing Git repository moved into the permanent eventwillow-sync folder. The workspace wp-eventwillow symlink now targets that folder.
- 11 published event occurrences and one published channel preserved. Existing internal IDs, metadata and Elementor bindings retained.
- PHP syntax checks passed on all runtime files.
- 15 release/updater checks passed using intercepted HTTP: new/equal versions, plugin details, unrelated plugin isolation, success/failure caching, forced checks, HTTPS/slug/version validation, and CLI registration.
- 19 Atomic background checks passed using Elementor's current document rather than a template registry.
- 20 date formatting checks, 153 scoped field/context checks, 49 native storage checks and nine individual field checks passed. The separate details assertion script also exited successfully.
- Actual public page HTTP response contains occurrence-specific Moonlit and Community image CSS, existing event cards and no PHP fatal error. This is server rendering confirmation. Browser verification was unavailable because the CUA runtime failed during sandbox initialization against the obsolete uppercase Event-Willow path.
- The ZIP allowlist, canonical folder and manifest SHA-256 match the source.

The default hosted update manifest returned HTTP 404 on October 11, 2026. No R2 release has been uploaded. End-to-end hosted update installation and release publishing therefore remain unverified. The repository now uses https://github.com/AdamArcane/wp-eventwillow, retaining the previous public plugin in Git history. Existing R2 secret names are configured in that repository; their values and access permissions have not been verified by a release run.

A local pre-install database snapshot is retained at /tmp/eventwillow-before-plugin.sql inside the WordPress web container. The separately installed ZIP copy was retained at /tmp/eventwillow-sync-install-check on the host before relocating the Git checkout.
