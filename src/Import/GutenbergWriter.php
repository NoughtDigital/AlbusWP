<?php
namespace Albus\Import;

use Albus\Util\Logger;
use Albus\Detect\Detector;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class GutenbergWriter {
    public function apply( int $post_id, string $blockMarkup ) : void {
        Logger::debug( 'GutenbergWriter: Starting apply', [ 'post_id' => $post_id, 'markup_length' => strlen($blockMarkup) ] );
        
        // backup original content
        $existing = get_post_field( 'post_content', $post_id );
        if ( ! empty( $existing ) ) {
            Logger::debug( 'Backing up existing post content', [ 'post_id' => $post_id ] );
            add_post_meta( $post_id, '_albus_backup_post_content', $existing, true );
            add_post_meta( $post_id, '_albus_backup_meta', wp_json_encode([
                'date' => current_time( 'mysql' ),
                'type' => 'gutenberg',
                'source' => Detector::detect_source_for_post( $post_id )
            ]), true );
        }
        
        // set post to use the block editor content
        $result = wp_update_post([ 'ID' => $post_id, 'post_content' => $blockMarkup ], true );
        
        if ( is_wp_error( $result ) ) {
            Logger::error( 'Failed to update post content', [ 
                'post_id' => $post_id, 
                'error' => $result->get_error_message() 
            ]);
            throw new \Exception( 'Failed to update post: ' . $result->get_error_message() );
        }
        
        Logger::debug( 'Post content updated successfully', [ 'post_id' => $post_id ] );
        
        // Clear Elementor/Bricks flags if present
        delete_post_meta( $post_id, '_elementor_edit_mode' );
        delete_post_meta( $post_id, '_elementor_data' );
        delete_post_meta( $post_id, '_bricks_data' );
        delete_post_meta( $post_id, '_bricks_editor_mode' );
        
        Logger::info( 'GutenbergWriter: Successfully applied Gutenberg blocks', [ 'post_id' => $post_id ] );
    }
}
