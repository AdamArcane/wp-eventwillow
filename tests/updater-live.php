<?php
if (!defined('WP_CLI') || !WP_CLI || !str_ends_with(parse_url(home_url(), PHP_URL_HOST), '.ddev.site')) exit;
$updater = new EWP_Updater(EW_UPDATE_MANIFEST_URL, EWP_PATH . 'eventwillow-public.php', '0.0.0');
$updater->clear_cache_if_requested();
$result = $updater->check_for_update((object)['response'=>[], 'no_update'=>[]]);
$entry = $result->response['eventwillow-public/eventwillow-public.php'] ?? null;
if (!$entry || !version_compare($entry->new_version, '0.0.0', '>')) WP_CLI::error('Live R2 manifest check failed.');
WP_CLI::success('Live R2 manifest offers version ' . $entry->new_version . ' to simulated version 0.0.0; no update installed.');
