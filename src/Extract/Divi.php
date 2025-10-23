<?php
namespace Albus\Extract;

use Albus\Util\ShortcodeParser;
use Albus\Util\Logger;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Divi Builder Extractor
 * 
 * Extracts content from Divi Builder shortcodes and converts to neutral format.
 * Divi uses [et_pb_section], [et_pb_row], [et_pb_column], [et_pb_text], etc.
 */
class Divi {

    public function toNeutralFromContent( string $content ) : array {
        Logger::debug( 'Divi: Starting extraction from content' );
        $tree = ShortcodeParser::parse_tree( $content );
        return $this->toNeutral( $tree );
    }

    public function toNeutral( array $tree ) : array {
        $out = [];
        foreach ( $tree as $node ) {
            $tag = $node['tag'] ?? '';
            
            // Section (wrapper)
            if ( $tag === 'et_pb_section' ) {
                $out[] = [
                    'type' => 'section',
                    'style' => $this->extractStyle( $node['attrs'] ?? [] ),
                    'children' => $this->mapRows( $node['children'] ?? [] ),
                ];
            }
            // Row
            elseif ( $tag === 'et_pb_row' ) {
                $out[] = [
                    'type' => 'section',
                    'style' => $this->extractStyle( $node['attrs'] ?? [] ),
                    'children' => $this->mapColumns( $node['children'] ?? [] ),
                ];
            }
            // Column
            elseif ( $tag === 'et_pb_column' ) {
                $out[] = [
                    'type' => 'column',
                    'width' => $this->columnWidth( $node['attrs'] ?? [] ),
                    'children' => $this->toNeutral( $node['children'] ?? [] ),
                ];
            }
            // Text module
            elseif ( $tag === 'et_pb_text' ) {
                $html = $this->getInnerContent( $node );
                if ( ! empty( $html ) ) {
                    $out[] = [ 'type' => 'text', 'html' => $html ];
                }
            }
            // Image module
            elseif ( $tag === 'et_pb_image' ) {
                $attrs = $node['attrs'] ?? [];
                $src = $attrs['src'] ?? '';
                $id = 0;
                
                // Try to get attachment ID from URL
                if ( $src ) {
                    $id = attachment_url_to_postid( $src );
                }
                
                $out[] = [ 'type' => 'image', 'id' => $id, 'url' => $src ];
            }
            // Button module
            elseif ( $tag === 'et_pb_button' ) {
                $attrs = $node['attrs'] ?? [];
                $out[] = [ 
                    'type' => 'button', 
                    'text' => $attrs['button_text'] ?? 'Button',
                    'url' => $attrs['button_url'] ?? '#'
                ];
            }
            // Heading (Divi uses et_pb_text with heading tags often)
            elseif ( $tag === 'et_pb_heading' ) {
                $attrs = $node['attrs'] ?? [];
                $title = $attrs['title'] ?? $this->getInnerContent( $node );
                $level = 2; // Default to h2
                
                if ( isset( $attrs['title_level'] ) ) {
                    $level = intval( str_replace( 'h', '', $attrs['title_level'] ) );
                }
                
                $out[] = [ 'type' => 'heading', 'level' => $level, 'text' => wp_kses_post( $title ) ];
            }
            // Blurb module (icon + title + content)
            elseif ( $tag === 'et_pb_blurb' ) {
                $attrs = $node['attrs'] ?? [];
                $title = $attrs['title'] ?? '';
                $content = $this->getInnerContent( $node );
                
                $html = '';
                if ( $title ) $html .= '<h3>' . wp_kses_post( $title ) . '</h3>';
                if ( $content ) $html .= '<p>' . wp_kses_post( $content ) . '</p>';
                
                if ( $html ) {
                    $out[] = [ 'type' => 'html', 'html' => $html ];
                }
            }
            // CTA (Call to Action)
            elseif ( $tag === 'et_pb_cta' ) {
                $attrs = $node['attrs'] ?? [];
                $title = $attrs['title'] ?? '';
                $button_text = $attrs['button_text'] ?? '';
                $button_url = $attrs['button_url'] ?? '#';
                $content = $this->getInnerContent( $node );
                
                $html = '';
                if ( $title ) $html .= '<h3>' . wp_kses_post( $title ) . '</h3>';
                if ( $content ) $html .= '<p>' . wp_kses_post( $content ) . '</p>';
                if ( $button_text ) {
                    $html .= '<a href="' . esc_url( $button_url ) . '" class="button">' . esc_html( $button_text ) . '</a>';
                }
                
                if ( $html ) {
                    $out[] = [ 'type' => 'html', 'html' => $html ];
                }
            }
            // Video module
            elseif ( $tag === 'et_pb_video' ) {
                $attrs = $node['attrs'] ?? [];
                $src = $attrs['src'] ?? '';
                
                if ( $src ) {
                    $out[] = [ 'type' => 'html', 'html' => '[video src="' . esc_url( $src ) . '"]' ];
                }
            }
            // Slider
            elseif ( $tag === 'et_pb_slider' ) {
                $children = $node['children'] ?? [];
                $html = '<div class="slider">';
                
                foreach ( $children as $slide ) {
                    if ( ($slide['tag'] ?? '') === 'et_pb_slide' ) {
                        $heading = $slide['attrs']['heading'] ?? '';
                        $content = $this->getInnerContent( $slide );
                        
                        $html .= '<div class="slide">';
                        if ( $heading ) $html .= '<h3>' . wp_kses_post( $heading ) . '</h3>';
                        if ( $content ) $html .= '<div>' . wp_kses_post( $content ) . '</div>';
                        $html .= '</div>';
                    }
                }
                
                $html .= '</div>';
                $out[] = [ 'type' => 'html', 'html' => $html ];
            }
            // Testimonial
            elseif ( $tag === 'et_pb_testimonial' ) {
                $attrs = $node['attrs'] ?? [];
                $author = $attrs['author'] ?? '';
                $job_title = $attrs['job_title'] ?? '';
                $content = $this->getInnerContent( $node );
                
                $html = '<blockquote>';
                if ( $content ) $html .= '<p>' . wp_kses_post( $content ) . '</p>';
                if ( $author || $job_title ) {
                    $html .= '<cite>';
                    if ( $author ) $html .= '<strong>' . esc_html( $author ) . '</strong>';
                    if ( $job_title ) $html .= ' - ' . esc_html( $job_title );
                    $html .= '</cite>';
                }
                $html .= '</blockquote>';
                
                $out[] = [ 'type' => 'html', 'html' => $html ];
            }
            // Gallery
            elseif ( $tag === 'et_pb_gallery' ) {
                $attrs = $node['attrs'] ?? [];
                $gallery_ids = $attrs['gallery_ids'] ?? '';
                
                if ( $gallery_ids ) {
                    $ids = array_map( 'intval', explode( ',', $gallery_ids ) );
                    $html = '<div class="gallery">';
                    foreach ( $ids as $id ) {
                        if ( $id > 0 ) {
                            $html .= wp_get_attachment_image( $id, 'medium' );
                        }
                    }
                    $html .= '</div>';
                    $out[] = [ 'type' => 'html', 'html' => $html ];
                }
            }
            // Contact form
            elseif ( $tag === 'et_pb_contact_form' ) {
                $attrs = $node['attrs'] ?? [];
                $title = $attrs['title'] ?? 'Contact Form';
                
                $html = '<div class="contact-form">';
                $html .= '<h3>' . esc_html( $title ) . '</h3>';
                $html .= '<p><em>Contact form placeholder - original form fields preserved in source.</em></p>';
                $html .= '</div>';
                
                $out[] = [ 'type' => 'html', 'html' => $html ];
            }
            // Divider
            elseif ( $tag === 'et_pb_divider' ) {
                $out[] = [ 'type' => 'html', 'html' => '<hr />' ];
            }
            // Code module (raw HTML/CSS/JS)
            elseif ( $tag === 'et_pb_code' ) {
                $content = $this->getInnerContent( $node );
                if ( $content ) {
                    $out[] = [ 'type' => 'html', 'html' => $content ];
                }
            }
            // Generic text fallback
            elseif ( $tag === 'text' ) {
                $t = trim( $node['text'] ?? '' );
                if ( $t !== '' ) {
                    $out[] = [ 'type' => 'html', 'html' => wp_kses_post( $t ) ];
                }
            }
            // Unknown modules - preserve raw content
            else {
                $content = $this->getInnerContent( $node );
                if ( ! empty( $content ) ) {
                    Logger::debug( 'Divi: Unknown module type, preserving content', [ 'tag' => $tag ] );
                    $out[] = [ 'type' => 'html', 'html' => $content ];
                } elseif ( ! empty( $node['raw'] ) ) {
                    $out[] = [ 'type' => 'html', 'html' => $node['raw'] ];
                }
            }
        }
        
        Logger::info( 'Divi: Extraction complete', [ 'output_count' => count($out) ] );
        return $out;
    }

    private function mapRows( array $children ) : array {
        $rows = [];
        foreach ( $children as $ch ) {
            $tag = $ch['tag'] ?? '';
            if ( $tag === 'et_pb_row' ) {
                $rows[] = [
                    'type' => 'section',
                    'style' => $this->extractStyle( $ch['attrs'] ?? [] ),
                    'children' => $this->mapColumns( $ch['children'] ?? [] ),
                ];
            }
        }
        return $rows;
    }

    private function mapColumns( array $children ) : array {
        $cols = [];
        foreach ( $children as $ch ) {
            $tag = $ch['tag'] ?? '';
            if ( $tag === 'et_pb_column' ) {
                $cols[] = [
                    'type' => 'column',
                    'width' => $this->columnWidth( $ch['attrs'] ?? [] ),
                    'children' => $this->toNeutral( $ch['children'] ?? [] ),
                ];
            }
        }
        return $cols;
    }

    private function columnWidth( array $attrs ) : int {
        // Divi uses 'type' attribute like '1_2', '1_3', '1_4', '2_3', etc.
        $type = $attrs['type'] ?? '4_4';
        
        $widths = [
            '4_4' => 100,
            '1_2' => 50,
            '1_3' => 33,
            '2_3' => 67,
            '1_4' => 25,
            '3_4' => 75,
            '1_5' => 20,
            '2_5' => 40,
            '3_5' => 60,
            '4_5' => 80,
            '1_6' => 17,
            '5_6' => 83,
        ];
        
        return $widths[$type] ?? 100;
    }

    private function extractStyle( array $attrs ) : array {
        $style = [];
        
        // Background color
        if ( ! empty( $attrs['background_color'] ) ) {
            $style['background-color'] = $attrs['background_color'];
        }
        
        // Background image
        if ( ! empty( $attrs['background_image'] ) ) {
            $style['background-image'] = $attrs['background_image'];
        }
        
        // Admin label (useful for debugging)
        if ( ! empty( $attrs['admin_label'] ) ) {
            $style['label'] = $attrs['admin_label'];
        }
        
        // Module ID
        if ( ! empty( $attrs['module_id'] ) ) {
            $style['id'] = $attrs['module_id'];
        }
        
        // Module class
        if ( ! empty( $attrs['module_class'] ) ) {
            $style['class'] = $attrs['module_class'];
        }
        
        return $style;
    }

    private function getInnerContent( array $node ) : string {
        // Try to get content from children or raw
        $content = '';
        
        if ( ! empty( $node['children'] ) ) {
            foreach ( $node['children'] as $child ) {
                if ( isset( $child['text'] ) ) {
                    $content .= $child['text'];
                } elseif ( isset( $child['raw'] ) ) {
                    $content .= $child['raw'];
                }
            }
        }
        
        if ( empty( $content ) && ! empty( $node['raw'] ) ) {
            // Extract content between shortcode tags
            $raw = $node['raw'];
            if ( preg_match( '/\](.*?)(\[\/|$)/s', $raw, $matches ) ) {
                $content = $matches[1];
            }
        }
        
        return trim( $content );
    }
}

