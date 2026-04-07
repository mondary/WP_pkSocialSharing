<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

delete_option('pkliap_options');
delete_option('pkliap_oauth_state');
delete_option('pkliap_access_token');
delete_option('pkliap_access_token_expires_at');
delete_option('pkliap_refresh_token');
delete_option('pkliap_refresh_token_expires_at');
global $wpdb;
if ($wpdb) {
	$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_pkliap_oauth_state_%' OR option_name LIKE '_transient_timeout_pkliap_oauth_state_%'");
}
