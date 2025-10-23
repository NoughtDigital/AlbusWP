<?php
namespace Albus\Extract;

use Albus\Util\Logger;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Kirki Customizer Extractor
 * 
 * Extracts Kirki-based customizer settings and converts to neutral format.
 * Kirki is a customizer framework, so this focuses on theme mods and options.
 */
class Kirki {

    public function toNeutralFromPost( int $post_id ) : array {
        Logger::debug( 'Kirki: Starting extraction from post meta' );
        
        // Check for Kirki-specific post meta
        $kirki_data = get_post_meta( $post_id, '_kirki_data', true );
        
        if ( ! empty( $kirki_data ) && is_array( $kirki_data ) ) {
            return $this->toNeutral( $kirki_data );
        }
        
        // Fall back to checking for Kirki-style content in post content
        $content = get_post_field( 'post_content', $post_id );
        if ( empty( $content ) ) {
            Logger::warning( 'Kirki: No content found' );
            return [];
        }
        
        return $this->extractFromContent( $content );
    }

    public function toNeutral( array $data ) : array {
        $out = [];
        
        foreach ( $data as $key => $value ) {
            // Convert various Kirki field types to neutral format
            if ( is_array( $value ) ) {
                // Repeater fields or complex data
                $html = $this->convertRepeaterField( $value );
                if ( $html ) {
                    $out[] = [ 'type' => 'html', 'html' => $html ];
                }
            } elseif ( is_string( $value ) ) {
                // Check if it's HTML content
                if ( $this->isHTML( $value ) ) {
                    $out[] = [ 'type' => 'html', 'html' => wp_kses_post( $value ) ];
                } elseif ( $this->isImageURL( $value ) ) {
                    $id = attachment_url_to_postid( $value );
                    $out[] = [ 'type' => 'image', 'id' => $id, 'url' => $value ];
                } else {
                    // Plain text
                    $out[] = [ 'type' => 'text', 'html' => '<p>' . wp_kses_post( $value ) . '</p>' ];
                }
            }
        }
        
        Logger::info( 'Kirki: Extraction complete', [ 'output_count' => count($out) ] );
        return $out;
    }

    private function extractFromContent( string $content ) : array {
        $out = [];
        
        // Look for Kirki shortcodes or special markers
        // [kirki field="..."]
        if ( preg_match_all( '/\[kirki\s+field=["\']([^"\']+)["\']\]/', $content, $matches ) ) {
            foreach ( $matches[1] as $field_name ) {
                $value = get_theme_mod( $field_name );
                
                if ( ! empty( $value ) ) {
                    if ( is_string( $value ) && $this->isHTML( $value ) ) {
                        $out[] = [ 'type' => 'html', 'html' => wp_kses_post( $value ) ];
                    } elseif ( is_string( $value ) && $this->isImageURL( $value ) ) {
                        $id = attachment_url_to_postid( $value );
                        $out[] = [ 'type' => 'image', 'id' => $id, 'url' => $value ];
                    } elseif ( is_string( $value ) ) {
                        $out[] = [ 'type' => 'text', 'html' => '<p>' . wp_kses_post( $value ) . '</p>' ];
                    }
                }
            }
        }
        
        // If no Kirki shortcodes found, treat as regular content
        if ( empty( $out ) ) {
            Logger::debug( 'Kirki: No Kirki-specific markers found, treating as regular content' );
            $out[] = [ 'type' => 'html', 'html' => $content ];
        }
        
        return $out;
    }

    private function convertRepeaterField( array $items ) : string {
        $html = '<div class="kirki-repeater">';
        
        foreach ( $items as $item ) {
            if ( is_array( $item ) ) {
                $html .= '<div class="repeater-item">';
                foreach ( $item as $key => $value ) {
                    if ( is_string( $value ) ) {
                        $label = ucwords( str_replace( '_', ' ', $key ) );
                        $html .= '<div class="repeater-field">';
                        $html .= '<strong>' . esc_html( $label ) . ':</strong> ';
                        $html .= wp_kses_post( $value );
                        $html .= '</div>';
                    }
                }
                $html .= '</div>';
            }
        }
        
        $html .= '</div>';
        
        return $html;
    }

    private function isHTML( $string ) : bool {
        if ( ! is_string( $string ) ) {
            return false;
        }
        return $string !== strip_tags( $string );
    }

    private function isImageURL( $string ) : bool {
        if ( ! is_string( $string ) ) {
            return false;
        }
        $ext = strtolower( pathinfo( $string, PATHINFO_EXTENSION ) );
        return in_array( $ext, [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg' ] );
    }

    /**
     * Get Kirki configuration data for a post
     */
    public function getKirkiConfig( int $post_id ) : array {
        $config = [];
        
        // Try to extract Kirki configuration
        $all_mods = get_theme_mods();
        
        // Filter for post-specific Kirki settings
        foreach ( $all_mods as $key => $value ) {
            if ( strpos( $key, 'kirki_' ) === 0 || strpos( $key, '_kirki_' ) !== false ) {
                $config[$key] = $value;
            }
        }
        
        return $config;
    }

    /**
     * Export Kirki settings as JSON for debugging
     */
    public function exportJSON( int $post_id ) : array {
        $config = $this->getKirkiConfig( $post_id );
        $post_meta = get_post_meta( $post_id, '_kirki_data', true );
        
        return [
            'theme_mods' => $config,
            'post_meta' => $post_meta,
            'all_theme_mods' => get_theme_mods()
        ];
    }
}

