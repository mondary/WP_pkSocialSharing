<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

delete_option('pkliap_options');
delete_option('pkliap_oauth_state');

