<?php
/** EventWillow public API v1 client. */
defined('ABSPATH') || exit;
final class EventWillow_Sync_Source {
    public static function page(array $settings, string $resource, array $query): array {
        $response = EventWillow_Sync::request(add_query_arg($query, rtrim($settings['url'], '/') . '/' . $resource), [
            'timeout' => 15, 'redirection' => 0, 'limit_response_size' => 4 * 1024 * 1024,
            'headers' => ['Accept' => 'application/json', 'X-EventWillow-Site-Key' => EventWillow_Sync::key()],
        ]);
        if (is_wp_error($response)) throw new RuntimeException('Could not reach the source API. Check the URL and certificate.');
        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200) throw new RuntimeException('Source API returned HTTP ' . $status . '.');
        $body = json_decode(wp_remote_retrieve_body($response), true);
        $meta = $body['meta'] ?? [];
        if (!is_array($body) || !is_array($body['data'] ?? null) || ($meta['api_version'] ?? '') !== '1'
            || !is_int($meta['total'] ?? null) || $meta['total'] < 0 || $meta['total'] > 5000
            || !is_int($meta['last_page'] ?? null) || $meta['last_page'] < 1 || $meta['last_page'] > 100
            || ($meta['page'] ?? null) !== $query['page']) throw new RuntimeException('Invalid or oversized source snapshot.');
        $generated = strtotime($meta['generated_at'] ?? '');
        if (!$generated || $generated < time() - 120 || $generated > time() + 60) throw new RuntimeException('Source page is expired or has an invalid timestamp.');
        if ($resource === 'events' && (($meta['mode'] ?? '') !== 'occurrences' || ($meta['from'] ?? '') !== $query['from'] || ($meta['to'] ?? '') !== $query['to'])) throw new RuntimeException('Source returned the wrong occurrence window.');
        return $body;
    }
    public static function validate(array $channels, array $events, array $settings): void {
        $ids = [];
        foreach ($channels as $c) {
            if (!is_array($c) || !is_string($c['id'] ?? null) || !$c['id'] || !is_string($c['identifier'] ?? null) || !is_string($c['name'] ?? null) || isset($ids[$c['id']])) throw new RuntimeException('Invalid or duplicate source channel.');
            $ids[$c['id']] = true;
        }
        $keys = [];
        foreach ($events as $e) {
            if (!is_array($e) || !is_string($e['id'] ?? null) || !is_string($e['name'] ?? null)
                || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $e['occurrence_date'] ?? '')
                || ($e['key'] ?? '') !== $e['id'] . ':' . $e['occurrence_date'] || isset($keys[$e['key']])
                || !strtotime($e['starts_at'] ?? '') || !strtotime($e['ends_at'] ?? '') || !is_array($e['channels'] ?? null) || !$e['channels']) throw new RuntimeException('Invalid or duplicate source occurrence.');
            new DateTimeZone($e['timezone'] ?? 'UTC');
            $selected = !$settings['channels'];
            foreach ($e['channels'] as $c) {
                if (!isset($ids[$c['id'] ?? ''])) throw new RuntimeException('An event references an unavailable channel.');
                if (in_array($c['identifier'] ?? '', $settings['channels'], true)) $selected = true;
            }
            if (!$selected) throw new RuntimeException('Source returned an event outside the selected channels.');
            $keys[$e['key']] = true;
        }
    }
}
