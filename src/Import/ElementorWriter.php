<?php
namespace Albus\Import;

use Albus\Util\Logger;
use Albus\Util\Backup;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ElementorWriter {

    public function apply( int $post_id, array $tree, string $mode = 'safe' ) : void {
        Logger::debug( 'ElementorWriter: Starting apply', [
            'post_id' => $post_id,
            'mode'    => $mode,
            'count'   => count( $tree ),
        ]);

        Backup::snapshot( $post_id, 'elementor', $mode );

        $json = wp_json_encode( $tree );
        if ( $json === false ) {
            throw new \Exception( 'Failed to encode Elementor data: ' . json_last_error_msg() );
        }

        update_post_meta( $post_id, '_elementor_data', wp_slash( $json ) );
        update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
        update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );

        if ( defined( 'ELEMENTOR_VERSION' ) ) {
            update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
        }

        delete_post_meta( $post_id, '_elementor_css' );
        delete_post_meta( $post_id, '_bricks_page_content_2' );
        delete_post_meta( $post_id, '_bricks_data' );
        delete_post_meta( $post_id, '_bricks_editor_mode' );
        delete_post_meta( $post_id, '_wpb_vc_js_status' );
        delete_post_meta( $post_id, '_wpb_shortcodes_custom_css' );

        wp_update_post([
            'ID'           => $post_id,
            'post_content' => '',
        ]);

        Logger::info( 'ElementorWriter: Applied', [ 'post_id' => $post_id, 'mode' => $mode ] );
    }
}
