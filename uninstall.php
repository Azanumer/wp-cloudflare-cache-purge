<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}
delete_option( 'wccp_settings' );
delete_option( 'wccp_last_result' );
