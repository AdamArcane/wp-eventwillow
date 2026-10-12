# Source adapter contract v1

The WordPress storage contract is independent of a source host, source API key, source branding, n8n, and the release service. EventSchedule support means implementing and verifying an adapter against upstream data; it is not supplied by changing a label.

## Native posts and taxonomy

Events: `/wp-json/wp/v2/ew-events`, post type `ew_event`. Channels: `/wp-json/wp/v2/ew-channels`, post type `ew_channel`. Use native `title`, `content`, `excerpt`, `status`, `slug`, and `featured_media`. Upload assets via the native media REST endpoint, then reference attachment IDs. Never replace WordPress publication date with event start time. Each recurring occurrence is a separate event projection.

Taxonomies: `ew_location` for both types; `ew_category`, `ew_format`, `ew_cost`, `ew_registration` for events. Create/reuse terms before writing their IDs to posts. Category is hierarchical; the others are flat.

## Metadata and relationships

`includes/schema.php` is the authoritative registered field list. Common fields include `ew_source_id`, `ew_source_key`, `ew_canonical_url`, `ew_timezone`, `ew_synced_at`, `ew_image_source`, and venue/address details. Source keys must distinguish source installation, record and occurrence to avoid collisions across installations. WordPress metadata does not enforce uniqueness: the synchronizer must handle idempotency and concurrent runs.

Event fields include ISO-8601 `ew_start_utc` / `ew_end_utc`, calendar `ew_occurrence_date`, display labels, price, registration/ticket/follow URLs and recurrence. `ew_channel_post_ids` is an array of channel post IDs. Channel fields contain public contacts, website, social links, accent color, channel type and header image attachment/URL. Event dynamic channel fields resolve the first published related channel; an event is never substituted for a missing channel.

Structured JSON is stored as JSON strings, not arbitrary nested REST metadata. See `ew_custom_fields_json`, `ew_price_json`, `ew_social_links_json`, `ew_actions_json`, `ew_sub_schedules_json`. Public event custom fields use `field_<12 lowercase hex characters>` keys with `{label: string, values: scalar[]}` definitions. The plugin exposes learned individual metadata and dynamic tags and clears values removed from a subsequent supplied custom-field payload. Private source fields must be excluded before transport. Send an empty JSON object explicitly to clear the projection; omitting the field retains previous values.

## Authentication and operation

Use HTTPS and an application password for a dedicated WordPress sync user with only the required post, taxonomy and media permissions. Standard WordPress REST permission checks apply; the plugin does not expose a public ingestion endpoint, retain source credentials or bypass authorization.

Sync channels before events, import images, then events and relationships. Use persistent source keys and compare source versions/content hashes to skip unchanged writes. Define handling for unpublished/deleted source records, stale images, failed imports and conflicts with local edits. Serialize overlapping runs or provide a locking mechanism. The existing n8n workflow is an EventWillow test adapter, not verified upstream EventSchedule compatibility.
