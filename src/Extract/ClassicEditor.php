<?php
namespace Albus\Extract;

use Albus\Util\Logger;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Classic Editor Extractor
 * 
 * Extracts content from Classic Editor (TinyMCE) and converts to neutral format.
 * Classic Editor content is stored as HTML in post_content.
 */
class ClassicEditor {

    public function toNeutralFromContent( string $content ) : array {
        Logger::debug( 'Classic Editor: Starting extraction from content', [ 'length' => strlen( $content ) ] );
        
        if ( empty( $content ) ) {
            Logger::warning( 'Classic Editor: Content is empty' );
            return [];
        }
        
        return $this->parseHTML( $content );
    }

    private function parseHTML( string $html ) : array {
        $out = [];
        
        // Use DOMDocument to parse HTML properly
        $dom = new \DOMDocument();
        
        // Suppress errors from malformed HTML
        libxml_use_internal_errors( true );
        
        // Load HTML with UTF-8 encoding
        $dom->loadHTML( '<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
        
        // Clear errors
        libxml_clear_errors();
        
        $body = $dom->getElementsByTagName( 'body' )->item( 0 );
        
        if ( ! $body ) {
            // Fallback: treat entire content as one HTML block
            Logger::debug( 'Classic Editor: Could not parse DOM, using fallback' );
            return [ [ 'type' => 'html', 'html' => $html ] ];
        }
        
        // Process each top-level element
        foreach ( $body->childNodes as $node ) {
            $element = $this->processNode( $node );
            if ( $element ) {
                $out[] = $element;
            }
        }
        
        // If we couldn't extract anything, return the raw HTML
        if ( empty( $out ) ) {
            Logger::debug( 'Classic Editor: No elements extracted, returning raw HTML' );
            $out[] = [ 'type' => 'html', 'html' => $html ];
        }
        
        Logger::info( 'Classic Editor: Extraction complete', [ 'output_count' => count($out) ] );
        return $out;
    }

    private function processNode( \DOMNode $node ) {
        // Skip text nodes that are just whitespace
        if ( $node->nodeType === XML_TEXT_NODE ) {
            $text = trim( $node->textContent );
            if ( empty( $text ) ) {
                return null;
            }
            return [ 'type' => 'text', 'html' => '<p>' . wp_kses_post( $text ) . '</p>' ];
        }
        
        if ( $node->nodeType !== XML_ELEMENT_NODE ) {
            return null;
        }
        
        $tagName = strtolower( $node->nodeName );
        
        // Headings
        if ( preg_match( '/^h([1-6])$/', $tagName, $matches ) ) {
            $level = intval( $matches[1] );
            $text = $this->getInnerHTML( $node );
            return [ 'type' => 'heading', 'level' => $level, 'text' => wp_kses_post( $text ) ];
        }
        
        // Paragraphs
        if ( $tagName === 'p' ) {
            $html = $this->getInnerHTML( $node );
            return [ 'type' => 'text', 'html' => '<p>' . wp_kses_post( $html ) . '</p>' ];
        }
        
        // Images
        if ( $tagName === 'img' ) {
            $src = $node->getAttribute( 'src' );
            $id = 0;
            
            // Try to get attachment ID from various class formats
            $class = $node->getAttribute( 'class' );
            if ( preg_match( '/wp-image-(\d+)/', $class, $matches ) ) {
                $id = intval( $matches[1] );
            } else {
                // Try to get ID from URL
                $id = attachment_url_to_postid( $src );
            }
            
            return [ 'type' => 'image', 'id' => $id, 'url' => $src ];
        }
        
        // Lists
        if ( $tagName === 'ul' || $tagName === 'ol' ) {
            $html = $this->getOuterHTML( $node );
            return [ 'type' => 'html', 'html' => wp_kses_post( $html ) ];
        }
        
        // Blockquotes
        if ( $tagName === 'blockquote' ) {
            $html = $this->getOuterHTML( $node );
            return [ 'type' => 'html', 'html' => wp_kses_post( $html ) ];
        }
        
        // Tables
        if ( $tagName === 'table' ) {
            $html = $this->getOuterHTML( $node );
            return [ 'type' => 'html', 'html' => wp_kses_post( $html ) ];
        }
        
        // Divs and other containers
        if ( $tagName === 'div' || $tagName === 'section' || $tagName === 'article' ) {
            $class = $node->getAttribute( 'class' );
            
            // Check if it's a WordPress gallery
            if ( strpos( $class, 'gallery' ) !== false ) {
                $html = $this->getOuterHTML( $node );
                return [ 'type' => 'html', 'html' => $html ];
            }
            
            // Check if it contains columns
            if ( strpos( $class, 'wp-block-columns' ) !== false || strpos( $class, 'columns' ) !== false ) {
                $html = $this->getOuterHTML( $node );
                return [ 'type' => 'html', 'html' => $html ];
            }
            
            // Otherwise, process children and return as section
            $children = [];
            foreach ( $node->childNodes as $child ) {
                $element = $this->processNode( $child );
                if ( $element ) {
                    $children[] = $element;
                }
            }
            
            if ( ! empty( $children ) ) {
                return [ 'type' => 'section', 'style' => [], 'children' => $children ];
            }
            
            // If no children, return as HTML
            $html = $this->getInnerHTML( $node );
            if ( ! empty( trim( $html ) ) ) {
                return [ 'type' => 'html', 'html' => wp_kses_post( $html ) ];
            }
            
            return null;
        }
        
        // Pre/Code blocks
        if ( $tagName === 'pre' || $tagName === 'code' ) {
            $html = $this->getOuterHTML( $node );
            return [ 'type' => 'html', 'html' => $html ];
        }
        
        // Shortcodes and other content - return as HTML
        $html = $this->getOuterHTML( $node );
        if ( ! empty( trim( $html ) ) ) {
            return [ 'type' => 'html', 'html' => wp_kses_post( $html ) ];
        }
        
        return null;
    }

    private function getInnerHTML( \DOMNode $node ) : string {
        $html = '';
        foreach ( $node->childNodes as $child ) {
            $html .= $node->ownerDocument->saveHTML( $child );
        }
        return $html;
    }

    private function getOuterHTML( \DOMNode $node ) : string {
        return $node->ownerDocument->saveHTML( $node );
    }

    /**
     * Check if content appears to be from Classic Editor
     * (i.e., not using blocks or page builders)
     */
    public static function isClassicContent( string $content ) : bool {
        // Not classic if it has Gutenberg block markers
        if ( strpos( $content, '<!-- wp:' ) !== false ) {
            return false;
        }
        
        // Not classic if it has common page builder shortcodes
        $builder_markers = [ '[vc_row', '[et_pb_', '[elementor-template', '[fusion_', '[fl_builder' ];
        foreach ( $builder_markers as $marker ) {
            if ( strpos( $content, $marker ) !== false ) {
                return false;
            }
        }
        
        // Likely classic if it's plain HTML
        return true;
    }
}

