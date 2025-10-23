<?php
namespace Albus\Detect;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Detector {

    public static function scan() : array {
        $is_pro = defined( 'ALBUS_IS_PRO' ) && ALBUS_IS_PRO;
        $scan_limit = $is_pro ? 500 : ALBUS_FREE_SCAN_LIMIT;
        
        $query = new \WP_Query([
            'post_type'      => ['page','post'],
            'posts_per_page' => $scan_limit,
            'post_status'    => 'any',
            'fields'         => 'ids',
        ]);
        
        $found = [];
        $free_count = 0;
        $pro_count = 0;
        $scanned_count = 0;
        
        foreach ( $query->posts as $pid ) {
            $scanned_count++;
            $src = self::detect_source_for_post( $pid );
            if ( $src ) {
                $is_free = albus_is_source_allowed( $src );
                $found[] = [ 
                    'id' => $pid, 
                    'title' => get_the_title( $pid ), 
                    'source' => $src, 
                    'edit' => get_edit_post_link($pid,''),
                    'requires_pro' => ! $is_free
                ];
                
                if ( $is_free ) {
                    $free_count++;
                } else {
                    $pro_count++;
                }
            }
        }
        
        // Get total posts for free version notice
        $total_posts = 0;
        if ( ! $is_pro ) {
            $counts = wp_count_posts('page');
            $total_posts += $counts->publish;
            $counts = wp_count_posts('post');
            $total_posts += $counts->publish;
        }
        
        return [ 
            'count' => count($found), 
            'items' => $found,
            'is_pro' => $is_pro,
            'free_count' => $free_count,
            'pro_count' => $pro_count,
            'scan_limit' => $scan_limit,
            'total_posts' => $total_posts,
            'scanned_count' => $scanned_count,
            'is_limited' => ! $is_pro && $total_posts > $scan_limit,
            'conversions_used' => albus_get_conversion_count(),
            'conversions_remaining' => albus_get_remaining_conversions(),
            'can_bulk' => albus_can_bulk_convert()
        ];
    }

    public static function detect_source_for_post( int $post_id ) : ?string {
        $content = get_post_field( 'post_content', $post_id );
        
        // Check for Bricks Builder
        $bricks_data = get_post_meta( $post_id, '_bricks_page_content_2', true );
        if ( empty( $bricks_data ) ) {
            $bricks_data = get_post_meta( $post_id, '_bricks_data', true );
        }
        if ( ! empty( $bricks_data ) ) {
            return 'bricks';
        }
        
        // Check for Elementor
        $el = get_post_meta( $post_id, '_elementor_data', true );
        if ( ! empty( $el ) ) {
            return 'elementor';
        }
        
        // Check for WPBakery
        if ( is_string($content) && strpos( $content, '[vc_row' ) !== false ) {
            return 'wpbakery';
        }
        
        // Check for Divi
        if ( is_string($content) && strpos( $content, '[et_pb_section' ) !== false ) {
            return 'divi';
        }
        
        // Check for Kirki (theme customizer)
        $kirki_data = get_post_meta( $post_id, '_kirki_data', true );
        if ( ! empty( $kirki_data ) ) {
            return 'kirki';
        }
        
        // Check for Gutenberg blocks
        if ( is_string($content) && strpos( $content, '<!-- wp:' ) !== false ) {
            return 'gutenberg';
        }
        
        // Check for Classic Editor (fallback if content exists but no builder detected)
        if ( is_string($content) && ! empty( trim( $content ) ) ) {
            // Use ClassicEditor helper to determine if it's classic content
            require_once ALBUS_PATH . 'src/Extract/ClassicEditor.php';
            if ( \Albus\Extract\ClassicEditor::isClassicContent( $content ) ) {
                return 'classic';
            }
        }
        
        return null;
    }
}
