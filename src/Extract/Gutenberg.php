<?php
namespace Albus\Extract;

use Albus\Util\Logger;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Gutenberg Block Extractor
 * 
 * Extracts content from Gutenberg blocks and converts to neutral format.
 * This enables Gutenberg → Bricks conversion.
 */
class Gutenberg {

    public function toNeutralFromContent( string $content ) : array {
        Logger::debug( 'Gutenberg: Starting extraction from content', [ 'length' => strlen( $content ) ] );
        
        if ( empty( $content ) ) {
            Logger::warning( 'Gutenberg: Content is empty' );
            return [];
        }
        
        // Parse Gutenberg blocks
        $blocks = parse_blocks( $content );
        
        if ( empty( $blocks ) ) {
            Logger::warning( 'Gutenberg: No blocks parsed' );
            return [];
        }
        
        return $this->processBlocks( $blocks );
    }

    private function processBlocks( array $blocks ) : array {
        $out = [];
        
        foreach ( $blocks as $block ) {
            $element = $this->processBlock( $block );
            if ( $element ) {
                if ( is_array( $element ) && isset( $element[0] ) && is_array( $element[0] ) ) {
                    // Multiple elements returned
                    $out = array_merge( $out, $element );
                } else {
                    $out[] = $element;
                }
            }
        }
        
        Logger::info( 'Gutenberg: Extraction complete', [ 'output_count' => count($out) ] );
        return $out;
    }

    private function processBlock( array $block ) {
        $blockName = $block['blockName'] ?? '';
        $attrs = $block['attrs'] ?? [];
        $innerHTML = $block['innerHTML'] ?? '';
        $innerBlocks = $block['innerBlocks'] ?? [];
        
        // Skip empty blocks
        if ( empty( $blockName ) && empty( trim( $innerHTML ) ) ) {
            return null;
        }
        
        // Process based on block type
        switch ( $blockName ) {
            // Core blocks
            case 'core/paragraph':
                $content = $this->extractTextContent( $innerHTML );
                if ( empty( trim( $content ) ) ) {
                    return null;
                }
                return [ 'type' => 'text', 'html' => $innerHTML ];
            
            case 'core/heading':
                $level = $attrs['level'] ?? 2;
                $content = $this->extractTextContent( $innerHTML );
                return [ 'type' => 'heading', 'level' => $level, 'text' => $content ];
            
            case 'core/image':
                $id = $attrs['id'] ?? 0;
                $url = $attrs['url'] ?? '';
                return [ 'type' => 'image', 'id' => intval( $id ), 'url' => $url ];
            
            case 'core/buttons':
                // Flatten buttons wrapper into individual button nodes
                $buttons = [];
                foreach ( $innerBlocks as $inner ) {
                    $mapped = $this->processBlock( $inner );
                    if ( $mapped ) {
                        if ( isset( $mapped[0] ) && is_array( $mapped[0] ) ) {
                            $buttons = array_merge( $buttons, $mapped );
                        } else {
                            $buttons[] = $mapped;
                        }
                    }
                }
                if ( empty( $buttons ) ) {
                    // Fallback: parse first link from HTML
                    $text = 'Button';
                    $url = '#';
                    if ( preg_match( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $innerHTML, $matches ) ) {
                        $url = $matches[1];
                        $text = strip_tags( $matches[2] );
                    }
                    return [ 'type' => 'button', 'text' => $text, 'url' => $url ];
                }
                return $buttons;

            case 'core/button':
                $text = $attrs['text'] ?? 'Button';
                $url = $attrs['url'] ?? '#';
                if ( ( $text === 'Button' || $url === '#' ) && preg_match( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $innerHTML, $matches ) ) {
                    if ( $url === '#' ) {
                        $url = $matches[1];
                    }
                    if ( $text === 'Button' ) {
                        $text = strip_tags( $matches[2] );
                    }
                }
                return [ 'type' => 'button', 'text' => $text, 'url' => $url ];
            
            case 'core/list':
                return [ 'type' => 'html', 'html' => $innerHTML ];
            
            case 'core/quote':
            case 'core/pullquote':
                return [ 'type' => 'html', 'html' => $innerHTML ];
            
            case 'core/code':
            case 'core/preformatted':
                return [ 'type' => 'html', 'html' => $innerHTML ];
            
            case 'core/html':
                return [ 'type' => 'html', 'html' => $innerHTML ];
            
            case 'core/separator':
                return [ 'type' => 'html', 'html' => '<hr />' ];
            
            case 'core/spacer':
                $height = $attrs['height'] ?? '50px';
                if ( is_numeric( $height ) ) {
                    $height = intval( $height ) . 'px';
                }
                return [ 'type' => 'html', 'html' => '<div style="height:' . esc_attr( (string) $height ) . ';"></div>' ];
            
            // Container blocks
            case 'core/group':
            case 'core/cover':
                $children = $this->processBlocks( $innerBlocks );
                return [ 'type' => 'section', 'style' => [], 'children' => $children ];
            
            case 'core/columns':
                $children = $this->processBlocks( $innerBlocks );
                return [ 'type' => 'section', 'style' => [], 'children' => $children ];
            
            case 'core/column':
                $width = $attrs['width'] ?? 100;
                $children = $this->processBlocks( $innerBlocks );
                
                // Convert width string (e.g., "33.33%") to integer percent
                if ( is_string( $width ) && strpos( $width, '%' ) !== false ) {
                    $width = (int) round( floatval( $width ) );
                } else {
                    $width = intval( $width );
                }
                if ( $width <= 0 ) {
                    $width = 100;
                }
                
                return [ 'type' => 'column', 'width' => $width, 'children' => $children ];
            
            // Media blocks
            case 'core/gallery':
                return [ 'type' => 'html', 'html' => $innerHTML ];
            
            case 'core/video':
            case 'core/audio':
            case 'core/embed':
                return [ 'type' => 'html', 'html' => $innerHTML ];
            
            case 'core/file':
                return [ 'type' => 'html', 'html' => $innerHTML ];
            
            // Table block
            case 'core/table':
                return [ 'type' => 'html', 'html' => $innerHTML ];
            
            // More advanced blocks
            case 'core/media-text':
                $children = $this->processBlocks( $innerBlocks );
                return [ 'type' => 'section', 'style' => [], 'children' => $children ];
            
            case 'core/social-links':
                return [ 'type' => 'html', 'html' => $innerHTML ];
            
            // Third-party blocks - treat as HTML
            default:
                // Check if there are inner blocks
                if ( ! empty( $innerBlocks ) ) {
                    $children = $this->processBlocks( $innerBlocks );
                    return [ 'type' => 'section', 'style' => [], 'children' => $children ];
                }
                
                // Otherwise return as HTML
                if ( ! empty( trim( $innerHTML ) ) ) {
                    Logger::debug( 'Gutenberg: Unknown block type, preserving as HTML', [ 'blockName' => $blockName ] );
                    return [ 'type' => 'html', 'html' => $innerHTML ];
                }
                
                return null;
        }
    }

    private function extractTextContent( string $html ) : string {
        // Remove HTML tags to get plain text
        $text = strip_tags( $html );
        return trim( $text );
    }

    /**
     * Check if content is Gutenberg blocks
     */
    public static function isGutenbergContent( string $content ) : bool {
        return strpos( $content, '<!-- wp:' ) !== false;
    }

    /**
     * Export Gutenberg blocks as JSON for debugging
     */
    public function exportJSON( string $content ) : array {
        $blocks = parse_blocks( $content );
        
        return [
            'block_count' => count( $blocks ),
            'blocks' => $blocks
        ];
    }
}

