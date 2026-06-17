<?php
// Only run when WP uninstalls the plugin
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

delete_option( 'lat_settings' );
