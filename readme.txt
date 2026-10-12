=== EventWillow Sync ===
Requires at least: 6.5
Requires PHP: 8.0
Stable tag: 0.2.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

== Description ==
Native WordPress events and channels, taxonomies, featured images and scoped Elementor dynamic fields for one-way synchronization. Optionally import directly from the EventWillow public API on a schedule; leave channel identifiers blank to import all channels. No ACF dependency. Elementor Pro is optional and needed for the dynamic-tag and Loop Builder features.

The plugin registers storage and presentation. An external authenticated synchronizer, such as n8n, imports data. It does not automatically connect to an EventSchedule installation or start scheduled jobs.

== Installation ==
Upload the eventwillow-sync ZIP and activate. If an earlier prototype plugin is active, deactivate it first; existing posts, metadata, taxonomies and Elementor templates are migrated automatically. Set page URL slugs and configure direct source imports under Events → Sync. Design directories and detail pages with Elementor Pro's Theme Builder. No content is deleted on deactivation.

== Changelog ==
= 0.2.0 =
Production naming: post types, taxonomies, REST routes and Elementor tags no longer use "lab" (existing sites migrate automatically; old links redirect). Adds configurable event and channel URL slugs, Elementor editing support and Theme Builder-ready directory and detail pages.

= 0.1.7 =
Allows syncing all source channels when no channel identifiers are configured.

= 0.1.8 =
Processes sync continuations in a bounded batch to improve progress with infrequent cron triggers.

= 0.1.6 =
Adds scheduled EventWillow API synchronization, import management and public custom-field projection.

= 0.1.0 =
Standalone packaging, provider-independent storage, scoped event/channel fields, date formats, Atomic background support and self-hosted updates.
