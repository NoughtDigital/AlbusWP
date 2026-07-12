<?php
/**
 * Plugin Name: AlbusWP
 * Plugin URI:  https://nought.digital
 * Description: Prototype: Convert legacy Page Builder content into Bricks Builder or Gutenberg blocks.
 * Version:     0.1.0
 * Author:      Nought Digital (Jake Henshall)
 * Author URI:  https://nought.digital
 * License:     GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: albus
 *
 * @package Albus
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'albus_fs' ) ) {
    // Create a helper function for easy SDK access.
    function albus_fs() {
        global $albus_fs;

        if ( ! isset( $albus_fs ) ) {
            $autoload = __DIR__ . '/vendor/autoload.php';
            if ( ! file_exists( $autoload ) ) {
                return false;
            }

            // Include Freemius SDK via Composer autoloader
            require_once $autoload;

            if ( ! function_exists( 'fs_dynamic_init' ) ) {
                return false;
            }

            // Activate multisite network integration.
            if ( ! defined( 'WP_FS__PRODUCT_21382_MULTISITE' ) ) {
                define( 'WP_FS__PRODUCT_21382_MULTISITE', true );
            }

            $albus_fs = fs_dynamic_init( array(
                'id'                  => '21382',
                'slug'                => 'albuswp',
                'type'                => 'plugin',
                'public_key'          => 'pk_6b3d1ae09d867038448bc6b7fb247',
                'is_premium'          => false,
                'has_addons'          => false,
                'has_paid_plans'      => true,
                'menu'                => array(
                    'slug'           => 'albus',
                    'account'        => false,
                    'support'        => false,
                ),
            ) );
        }

        return $albus_fs;
    }

    // Init Freemius when Composer deps are present.
    if ( false !== albus_fs() ) {
        do_action( 'albus_fs_loaded' );
    } else {
        add_action( 'admin_notices', function () {
            if ( ! current_user_can( 'activate_plugins' ) ) {
                return;
            }
            echo '<div class="notice notice-error"><p>'
                . esc_html__( 'AlbusWP: Freemius SDK is missing. Run composer install in the plugin directory.', 'albus' )
                . '</p></div>';
        } );
    }
}

define( 'ALBUS_VERSION', '0.1.1' );
define( 'ALBUS_PATH', plugin_dir_path( __FILE__ ) );
define( 'ALBUS_URL', plugin_dir_url( __FILE__ ) );

// Version control: Check Freemius license or manual override
if ( ! defined( 'ALBUS_IS_PRO' ) ) {
    // Check if user has active Freemius license
    $fs = function_exists( 'albus_fs' ) ? albus_fs() : false;
    $is_pro = $fs && is_object( $fs ) && method_exists( $fs, 'is_premium' ) && $fs->is_premium();
    define( 'ALBUS_IS_PRO', $is_pro );
}

// Feature limits based on version
define( 'ALBUS_FREE_SCAN_LIMIT', 10 );
define( 'ALBUS_FREE_CONVERT_LIMIT', 10 );

function albus_is_conversion_allowed( $source, $target ) {
    // Same-source is a no-op
    if ( $source === $target ) {
        return [ 'allowed' => false, 'message' => 'Source and target builders are the same.' ];
    }

    $valid = [ 'gutenberg', 'wpbakery', 'elementor', 'bricks', 'divi', 'kirki', 'classic' ];
    if ( ! in_array( $source, $valid, true ) || ! in_array( $target, [ 'gutenberg', 'wpbakery', 'elementor', 'bricks' ], true ) ) {
        return [ 'allowed' => false, 'message' => 'Unsupported conversion path.' ];
    }

    if ( ALBUS_IS_PRO ) {
        return [ 'allowed' => true ];
    }
    
    // FREE tier conversions (sensible starter paths)
    $free_conversions = [
        'wpbakery'  => [ 'gutenberg' ],
        'divi'      => [ 'gutenberg' ],
        'kirki'     => [ 'gutenberg' ],
        'classic'   => [ 'gutenberg' ],
        'gutenberg' => [ 'bricks', 'wpbakery' ],
    ];
    
    if ( isset( $free_conversions[$source] ) && in_array( $target, $free_conversions[$source], true ) ) {
        return [ 'allowed' => true ];
    }
    
    return [ 
        'allowed' => false, 
        'message' => sprintf( 'Converting from %s to %s requires AlbusWP PRO.', ucfirst($source), ucfirst($target) )
    ];
}

function albus_is_source_allowed( $source ) {
    if ( ALBUS_IS_PRO ) {
        return true;
    }
    // Free sources: WPBakery, Divi, Kirki, Classic Editor, Gutenberg
    return in_array( $source, [ 'wpbakery', 'divi', 'kirki', 'classic', 'gutenberg' ], true );
}

function albus_is_target_allowed( $target ) {
    // All four primary targets are recognised; FREE/PRO path rules live in albus_is_conversion_allowed()
    if ( ! in_array( $target, [ 'gutenberg', 'wpbakery', 'elementor', 'bricks' ], true ) ) {
        return false;
    }
    if ( ALBUS_IS_PRO ) {
        return true;
    }
    // Free can target Gutenberg, Bricks, WPBakery (path still gated)
    return in_array( $target, [ 'gutenberg', 'bricks', 'wpbakery' ], true );
}

function albus_can_bulk_convert() {
    // Bulk conversion is PRO only
    return ALBUS_IS_PRO;
}

function albus_get_conversion_count() {
    return (int) get_option( 'albus_conversion_count', 0 );
}

function albus_increment_conversion_count() {
    $count = albus_get_conversion_count();
    update_option( 'albus_conversion_count', $count + 1 );
    return $count + 1;
}

function albus_can_convert() {
    if ( ALBUS_IS_PRO ) {
        return true;
    }
    return albus_get_conversion_count() < ALBUS_FREE_CONVERT_LIMIT;
}

function albus_get_remaining_conversions() {
    if ( ALBUS_IS_PRO ) {
        return -1; // Unlimited
    }
    $used = albus_get_conversion_count();
    return max( 0, ALBUS_FREE_CONVERT_LIMIT - $used );
}

function albus_get_upgrade_url() {
    $fs = function_exists( 'albus_fs' ) ? albus_fs() : false;
    if ( $fs && is_object( $fs ) && method_exists( $fs, 'get_upgrade_url' ) ) {
        return $fs->get_upgrade_url();
    }
    return admin_url( 'admin.php?page=albus-pricing' );
}

// PSR-4 style autoloader for Albus\ namespace.
spl_autoload_register( function ( $class ) {
    if ( 0 !== strpos( $class, 'Albus\\' ) ) {
        return;
    }
    $rel = str_replace( ['Albus\\', '\\'], ['', '/'], $class );
    $file = ALBUS_PATH . 'src/' . $rel . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
});

// Admin page boot.
add_action( 'admin_menu', function () {
    \Albus\Admin\AdminPage::init();
});

// Simple REST endpoints for conversions (prototype).
add_action( 'rest_api_init', function () {
    register_rest_route( 'albus/v1', '/scan', [
        'methods'  => 'GET',
        'callback' => function () {
            return \Albus\Detect\Detector::scan();
        },
        'permission_callback' => function () { return current_user_can( 'manage_options' ); }
    ]);

    register_rest_route( 'albus/v1', '/preview', [
        'methods'  => 'POST',
        'callback' => function ( WP_REST_Request $req ) {
            $post_id = intval( $req->get_param('post_id') );
            $target  = sanitize_text_field( $req->get_param('target') );
            return \Albus\Admin\AdminPage::preview_post( $post_id, $target );
        },
        'permission_callback' => function () { return current_user_can( 'manage_options' ); }
    ]);

    register_rest_route( 'albus/v1', '/convert', [
        'methods'  => 'POST',
        'callback' => function ( WP_REST_Request $req ) {
            $post_id = intval( $req->get_param('post_id') );
            $target  = sanitize_text_field( $req->get_param('target') );
            $mode    = sanitize_text_field( $req->get_param('mode') ?: 'safe' );
            $confirm = sanitize_text_field( $req->get_param('confirm_inplace') ?: '' );
            return \Albus\Admin\AdminPage::convert_post( $post_id, $target, $mode, $confirm );
        },
        'permission_callback' => function () { return current_user_can( 'manage_options' ); }
    ]);
    
    register_rest_route( 'albus/v1', '/bulk-convert', [
        'methods'  => 'POST',
        'callback' => function ( WP_REST_Request $req ) {
            $post_ids = $req->get_param('post_ids');
            $target   = sanitize_text_field( $req->get_param('target') );
            // Bulk is always safe — ignore inplace attempts
            return \Albus\Admin\AdminPage::bulk_convert( $post_ids, $target, 'safe', '' );
        },
        'permission_callback' => function () { return current_user_can( 'manage_options' ); }
    ]);
    
    register_rest_route( 'albus/v1', '/restore', [
        'methods'  => 'POST',
        'callback' => function ( WP_REST_Request $req ) {
            $post_id = intval( $req->get_param('post_id') );
            return \Albus\Admin\AdminPage::restore_post( $post_id );
        },
        'permission_callback' => function () { return current_user_can( 'manage_options' ); }
    ]);
    
    register_rest_route( 'albus/v1', '/backups', [
        'methods'  => 'GET',
        'callback' => function () {
            return \Albus\Admin\AdminPage::get_backups();
        },
        'permission_callback' => function () { return current_user_can( 'manage_options' ); }
    ]);
    
    register_rest_route( 'albus/v1', '/cleanup-backups', [
        'methods'  => 'POST',
        'callback' => function ( WP_REST_Request $req ) {
            $days = intval( $req->get_param('days') ?: 30 );
            return \Albus\Admin\AdminPage::cleanup_old_backups( $days );
        },
        'permission_callback' => function () { return current_user_can( 'manage_options' ); }
    ]);
    
    // JSON Export endpoint - exports extracted content as JSON for debugging (FREE tier)
    register_rest_route( 'albus/v1', '/export-json/(?P<id>\d+)', [
        'methods'  => 'GET',
        'callback' => function ( WP_REST_Request $req ) {
            $post_id = intval( $req->get_param('id') );
            return \Albus\Admin\AdminPage::export_json( $post_id );
        },
        'permission_callback' => function () { return current_user_can( 'manage_options' ); }
    ]);
    
    // Debug endpoint to view raw builder data
    register_rest_route( 'albus/v1', '/debug-raw/(?P<id>\d+)', [
        'methods'  => 'GET',
        'callback' => function ( WP_REST_Request $req ) {
            $post_id = intval( $req->get_param('id') );
            
            $source = \Albus\Detect\Detector::detect_source_for_post( $post_id );
            $data = [];
            
            if ( $source === 'elementor' ) {
                $meta = get_post_meta( $post_id, '_elementor_data', true );
                $data = is_string($meta) ? json_decode( $meta, true ) : $meta;
            } elseif ( $source === 'bricks' ) {
                $bricks_data = get_post_meta( $post_id, '_bricks_page_content_2', true );
                if ( empty( $bricks_data ) ) {
                    $bricks_data = get_post_meta( $post_id, '_bricks_data', true );
                }
                $data = is_string($bricks_data) ? json_decode( $bricks_data, true ) : $bricks_data;
            } else {
                $data = get_post_field( 'post_content', $post_id );
            }
            
            return [
                'ok' => true,
                'post_id' => $post_id,
                'source' => $source,
                'data_type' => gettype($data),
                'raw_data' => $data,
                'json_error' => json_last_error_msg()
            ];
        },
        'permission_callback' => function () { return current_user_can( 'manage_options' ); }
    ]);
});

// Auto cleanup old backups daily
add_action( 'wp_scheduled_delete', function () {
    // Conservative: only prune lightweight keys after 1 year; keep full snapshots/archives
    \Albus\Admin\AdminPage::cleanup_old_backups( 365 );
});


