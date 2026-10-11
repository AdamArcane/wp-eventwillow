=== EventWillow Sync ===
Requires at least: 6.5
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

== Description ==
Native WordPress events and channels, taxonomies, featured images and scoped Elementor dynamic fields for one-way synchronization. No ACF dependency. Elementor Pro is optional and needed for the dynamic-tag and Loop Builder features.

The plugin registers storage and presentation. An external authenticated synchronizer, such as n8n, imports data. It does not automatically connect to an EventSchedule installation or start scheduled jobs.

== Installation ==
Upload the eventwillow-sync ZIP and activate. For an existing CPT Lab installation, deactivate the old plugin before activating this plugin. Existing post IDs, metadata, REST routes and template bindings are retained. Do not activate both implementations. No content is deleted on deactivation.

== Changelog ==
= 0.1.0 =
Standalone packaging, provider-independent storage, scoped event/channel fields, date formats, Atomic background support and self-hosted updates.
