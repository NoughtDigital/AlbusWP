<?php
namespace Albus\Import;

use Albus\Util\Logger;
use Albus\Detect\Detector;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class BricksWriter {
    public function apply( int $post_id, array $bricksJson ) : void {
        Logger::debug( 'BricksWriter: Starting apply', [ 'post_id' => $post_id ] );
        
        // Check if Bricks is active
        if ( ! defined( 'BRICKS_VERSION' ) ) {
            Logger::warning( 'Bricks Builder is not active', [ 'post_id' => $post_id ] );
            throw new \Exception( 'Bricks Builder plugin is not active. Please install and activate Bricks Builder.' );
        }
        
        // backup original bricks data if any
        $existing = get_post_meta( $post_id, '_bricks_data', true );
        if ( ! empty( $existing ) ) {
            Logger::debug( 'Backing up existing Bricks data', [ 'post_id' => $post_id ] );
            add_post_meta( $post_id, '_albus_backup__bricks_data', $existing, true );
            add_post_meta( $post_id, '_albus_backup_meta', wp_json_encode([
                'date' => current_time( 'mysql' ),
                'type' => 'bricks',
                'source' => Detector::detect_source_for_post( $post_id )
            ]), true );
        }
        
        // Encode the JSON
        $encoded = wp_json_encode( $bricksJson );
        if ( $encoded === false ) {
            Logger::error( 'Failed to encode Bricks JSON', [ 'post_id' => $post_id, 'error' => json_last_error_msg() ] );
            throw new \Exception( 'Failed to encode Bricks data: ' . json_last_error_msg() );
        }
        
        Logger::debug( 'Updating post meta with Bricks data', [ 'post_id' => $post_id, 'data_length' => strlen($encoded) ] );
        
        update_post_meta( $post_id, '_bricks_data', $encoded );
        update_post_meta( $post_id, '_bricks_is_shortcode', '0' );
        update_post_meta( $post_id, '_bricks_editor_mode', 'bricks' );
        
        // Clear old builder data
        delete_post_meta( $post_id, '_elementor_edit_mode' );
        delete_post_meta( $post_id, '_elementor_data' );
        
        Logger::info( 'BricksWriter: Successfully applied Bricks data', [ 'post_id' => $post_id ] );
    }
}
