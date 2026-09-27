<?php
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
// Uninstall removes this add-on's stored credentials, mappings and cache only.
wp_clear_scheduled_hook('kmw_refresh');
foreach (['kmw_settings','kmw_state','kmw_mappings','kmw_lock','kmw_management','kmw_inventory','kmw_pending','kmw_provision_lock'] as $option) { delete_option($option); }
