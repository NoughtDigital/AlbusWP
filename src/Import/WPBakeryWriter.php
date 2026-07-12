<?php
namespace Albus\Import;

use Albus\Util\Logger;
use Albus\Util\Backup;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class WPBakeryWriter {

    public function apply( int $post_id, string $shortcodes, string $mode = 'safe' ) : void {
        Logger::debug( 'WPBakeryWriter: Starting apply', [
            'post_id' => $post_id,
            'mode'    => $mode,
            'length'  => strlen( $shortcodes ),
        ]);

        Backup::snapshot( $post_id, 'wpbakery', $mode );

        $result = wp_update_post([
            'ID'           => $post_id,
            'post_content' => $shortcodes,
        ], true );

        if ( is_wp_error( $result ) ) {
            throw new \Exception( 'Failed to update post: ' . $result->get_error_message() );
        }

        update_post_meta( $post_id, '_wpb_vc_js_status', 'true' );

        delete_post_meta( $post_id, '_elementor_edit_mode' );
        delete_post_meta( $post_id, '_elementor_data' );
        delete_post_meta( $post_id, '_elementor_css' );
        delete_post_meta( $post_id, '_bricks_page_content_2' );
        delete_post_meta( $post_id, '_bricks_data' );
        delete_post_meta( $post_id, '_bricks_editor_mode' );

        Logger::info( 'WPBakeryWriter: Applied', [ 'post_id' => $post_id, 'mode' => $mode ] );
    }
}
