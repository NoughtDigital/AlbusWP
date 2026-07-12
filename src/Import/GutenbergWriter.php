<?php
namespace Albus\Import;

use Albus\Util\Logger;
use Albus\Util\Backup;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class GutenbergWriter {
    public function apply( int $post_id, string $blockMarkup, string $mode = 'safe' ) : void {
        Logger::debug( 'GutenbergWriter: Starting apply', [
            'post_id' => $post_id,
            'mode'    => $mode,
            'length'  => strlen( $blockMarkup ),
        ]);

        Backup::snapshot( $post_id, 'gutenberg', $mode );

        $result = wp_update_post([ 'ID' => $post_id, 'post_content' => $blockMarkup ], true );

        if ( is_wp_error( $result ) ) {
            throw new \Exception( 'Failed to update post: ' . $result->get_error_message() );
        }

        Backup::clear_builder_meta( $post_id );

        Logger::info( 'GutenbergWriter: Applied', [ 'post_id' => $post_id, 'mode' => $mode ] );
    }
}
