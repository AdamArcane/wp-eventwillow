<?php
if (!defined('ABSPATH')) exit;
final class EWP_API {
    public static ?int $expires = null;
    public static function settings(): array {
        return wp_parse_args(get_option('ewp_settings', []), [
            'api_url' => '', 'event_template' => 0, 'channel_template' => 0, 'card_template' => 0,
            'channel_card_template' => 0, 'preview_event' => '', 'preview_channel' => '',
            'unavailable' => 'Events are temporarily unavailable. Please try again shortly.',
        ]);
    }
    public static function get(string $path, array $query = []) {
        $base = rtrim(self::settings()['api_url'], '/');
        if (!$base || !preg_match('~^(events|channels|facets)(/[a-zA-Z0-9_-]+){0,2}$~D', $path)) return new WP_Error('ewp_configuration', 'Configure the EventWillow API connection.');
        ksort($query); $generation = (int)get_option('ewp_cache_generation', 0);
        $key = 'ewp_' . hash('sha256', $base . '/' . $path . wp_json_encode($query) . $generation);
        $cached = get_transient($key);
        if (is_array($cached) && ($cached['expires'] ?? 0) > time()) {
            self::$expires = min(self::$expires ?? PHP_INT_MAX, $cached['expires']);
            return $cached['response'];
        }
        $siteKey = defined('EW_SITE_API_KEY') ? (string)EW_SITE_API_KEY : (string)getenv('EW_SITE_API_KEY');
        if ($siteKey === '') return new WP_Error('ewp_configuration', 'Configure the server-side EventWillow integration key.');
        $url = $base . '/' . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        $response = wp_remote_get($url, [
            'timeout' => 15, 'redirection' => 0, 'headers' => ['Accept' => 'application/json', 'X-EventWillow-Site-Key' => $siteKey, 'User-Agent' => 'EventWillow-WordPress/' . EWP_VERSION . '; ' . home_url('/')],
            'limit_response_size' => 4 * 1024 * 1024,
        ]);
        if (is_wp_error($response)) return new WP_Error('ewp_connection', 'Unable to reach the EventWillow API.');
        $status = wp_remote_retrieve_response_code($response);
        if ($status === 404) return new WP_Error('ewp_missing', 'This content is no longer available.');
        if ($status !== 200) return new WP_Error('ewp_http', 'EventWillow returned HTTP ' . $status . '.', ['status' => $status]);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || !array_key_exists('data', $body) || ($body['meta']['api_version'] ?? '') !== '1') return new WP_Error('ewp_version', 'Unsupported EventWillow API response.');
        $generated = strtotime($body['meta']['generated_at'] ?? '');
        $ttl = min(120, max(0, (int)($body['meta']['max_age'] ?? 0)));
        $expires = min(time() + $ttl, ($generated ?: 0) + $ttl);
        if ($expires <= time()) return new WP_Error('ewp_expired', 'EventWillow returned expired public data.');
        self::$expires = min(self::$expires ?? PHP_INT_MAX, $expires);
        set_transient($key, ['expires' => $expires, 'response' => $body], $expires - time());
        return $body;
    }
    public static function clear(): void { update_option('ewp_cache_generation', (int)get_option('ewp_cache_generation', 0) + 1, false); }
}
