<?php
namespace Albus\Import;

use Albus\Util\Logger;
use Albus\Util\Backup;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class BricksWriter {

    public function apply( int $post_id, array $elements, string $mode = 'safe' ) : void {
        Logger::debug( 'BricksWriter: Starting apply', [
            'post_id' => $post_id,
            'mode'    => $mode,
            'count'   => count( $elements ),
        ]);

        if ( ! defined( 'BRICKS_VERSION' ) && ! defined( 'BRICKS_DB_PAGE_CONTENT' ) ) {
            Logger::warning( 'Bricks is not active; writing _bricks_page_content_2 anyway', [ 'post_id' => $post_id ] );
        }

        Backup::snapshot( $post_id, 'bricks', $mode );

        $meta_key = defined( 'BRICKS_DB_PAGE_CONTENT' ) ? BRICKS_DB_PAGE_CONTENT : '_bricks_page_content_2';

        update_post_meta( $post_id, $meta_key, $elements );
        update_post_meta( $post_id, '_bricks_editor_mode', 'bricks' );

        delete_post_meta( $post_id, '_bricks_data' );
        delete_post_meta( $post_id, '_bricks_is_shortcode' );
        delete_post_meta( $post_id, '_elementor_edit_mode' );
        delete_post_meta( $post_id, '_elementor_data' );
        delete_post_meta( $post_id, '_elementor_css' );
        delete_post_meta( $post_id, '_wpb_vc_js_status' );
        delete_post_meta( $post_id, '_wpb_shortcodes_custom_css' );

        $current = get_post_field( 'post_content', $post_id );
        if ( $current === '' || strpos( (string) $current, '[vc_' ) !== false || strpos( (string) $current, '<!-- wp:' ) !== false ) {
            wp_update_post([
                'ID'           => $post_id,
                'post_content' => '<!-- Bricks Builder content — converted by Albus (draft safe mode) -->',
            ]);
        }

        Logger::info( 'BricksWriter: Applied', [ 'post_id' => $post_id, 'mode' => $mode ] );
    }
}
