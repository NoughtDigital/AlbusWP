<?php
namespace Albus\Extract;

use Albus\Util\Logger;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bricks Builder Extractor
 * 
 * Extracts content from Bricks Builder data and converts to neutral format.
 * This enables Bricks → Gutenberg reverse conversion (PRO feature).
 */
class Bricks {

    public function toNeutralFromData( $data ) : array {
        Logger::debug( 'Bricks: Starting extraction', [ 
            'data_type' => gettype($data), 
            'is_array' => is_array($data)
        ]);
        
        if ( ! is_array( $data ) ) {
            Logger::warning( 'Bricks: Data is not an array', [ 'type' => gettype($data) ] );
            return [];
        }
        
        if ( empty( $data ) ) {
            Logger::warning( 'Bricks: Data array is empty' );
            return [];
        }
        
        // Bricks data is typically an array of elements
        $out = [];
        foreach ( $data as $element ) {
            if ( ! is_array( $element ) ) {
                continue;
            }
            
            $mapped = $this->mapElement( $element );
            if ( $mapped ) {
                $out[] = $mapped;
            }
        }
        
        Logger::info( 'Bricks: Extraction complete', [ 'output_count' => count($out) ] );
        return $out;
    }

    private function mapElement( array $element ) {
        $type = $element['name'] ?? $element['type'] ?? '';
        $settings = $element['settings'] ?? [];
        $children = $element['children'] ?? [];
        
        // Process children recursively
        $childElements = [];
        if ( ! empty( $children ) && is_array( $children ) ) {
            foreach ( $children as $child ) {
                if ( is_array( $child ) ) {
                    $mapped = $this->mapElement( $child );
                    if ( $mapped ) {
                        $childElements[] = $mapped;
                    }
                }
            }
        }
        
        // Map Bricks element types to neutral format
        switch ( $type ) {
            // Container elements
            case 'section':
            case 'container':
            case 'block':
            case 'div':
                return [ 
                    'type' => 'section', 
                    'style' => $this->extractStyle( $settings ), 
                    'children' => $childElements 
                ];
            
            // Heading
            case 'heading':
                $tag = $settings['tag'] ?? 'h2';
                $level = intval( str_replace( 'h', '', strtolower( $tag ) ) );
                if ( $level < 1 || $level > 6 ) $level = 2;
                
                $text = $settings['text'] ?? '';
                return [ 'type' => 'heading', 'level' => $level, 'text' => wp_kses_post( $text ) ];
            
            // Text / Rich Text
            case 'text':
            case 'text-basic':
            case 'rich-text':
                $content = $settings['text'] ?? $settings['content'] ?? '';
                return [ 'type' => 'text', 'html' => $content ];
            
            // Image
            case 'image':
                $id = 0;
                $url = '';
                
                if ( isset( $settings['image'] ) ) {
                    if ( is_array( $settings['image'] ) ) {
                        $id = intval( $settings['image']['id'] ?? 0 );
                        $url = $settings['image']['url'] ?? '';
                    } elseif ( is_numeric( $settings['image'] ) ) {
                        $id = intval( $settings['image'] );
                    } elseif ( is_string( $settings['image'] ) ) {
                        $url = $settings['image'];
                        $id = attachment_url_to_postid( $url );
                    }
                }
                
                // Fallback to attachmentId
                if ( $id === 0 && isset( $settings['attachmentId'] ) ) {
                    $id = intval( $settings['attachmentId'] );
                }
                
                return [ 'type' => 'image', 'id' => $id, 'url' => $url ];
            
            // Button
            case 'button':
                $text = $settings['text'] ?? 'Button';
                $url = '#';
                
                if ( isset( $settings['link'] ) ) {
                    if ( is_array( $settings['link'] ) ) {
                        $url = $settings['link']['url'] ?? '#';
                    } elseif ( is_string( $settings['link'] ) ) {
                        $url = $settings['link'];
                    }
                }
                
                return [ 'type' => 'button', 'text' => $text, 'url' => $url ];
            
            // Video
            case 'video':
                $videoType = $settings['videoType'] ?? 'media';
                $html = '';
                
                if ( $videoType === 'media' && isset( $settings['video'] ) ) {
                    $videoId = is_array( $settings['video'] ) ? ($settings['video']['id'] ?? 0) : intval( $settings['video'] );
                    if ( $videoId > 0 ) {
                        $html = wp_video_shortcode( [ 'src' => wp_get_attachment_url( $videoId ) ] );
                    }
                } elseif ( isset( $settings['videoUrl'] ) ) {
                    $html = '[video src="' . esc_url( $settings['videoUrl'] ) . '"]';
                }
                
                return [ 'type' => 'html', 'html' => $html ];
            
            // Icon
            case 'icon':
                $icon = $settings['icon'] ?? '';
                if ( is_array( $icon ) ) {
                    $iconClass = $icon['library'] . ' ' . $icon['icon'];
                    return [ 'type' => 'html', 'html' => '<i class="' . esc_attr( $iconClass ) . '"></i>' ];
                }
                return null;
            
            // Divider
            case 'divider':
                return [ 'type' => 'html', 'html' => '<hr />' ];
            
            // List
            case 'list':
                $items = $settings['items'] ?? [];
                $listType = $settings['listType'] ?? 'ul';
                
                $html = '<' . $listType . '>';
                foreach ( $items as $item ) {
                    $text = is_array( $item ) ? ($item['text'] ?? '') : $item;
                    $html .= '<li>' . wp_kses_post( $text ) . '</li>';
                }
                $html .= '</' . $listType . '>';
                
                return [ 'type' => 'html', 'html' => $html ];
            
            // Code
            case 'code':
                $content = $settings['code'] ?? $settings['content'] ?? '';
                return [ 'type' => 'html', 'html' => $content ];
            
            // Map
            case 'map':
                $address = $settings['address'] ?? '';
                return [ 'type' => 'html', 'html' => '<p><strong>Map:</strong> ' . esc_html( $address ) . '</p>' ];
            
            // Form
            case 'form':
                $html = '<div class="bricks-form-placeholder"><p><em>Contact form placeholder</em></p></div>';
                return [ 'type' => 'html', 'html' => $html ];
            
            // Shortcode
            case 'shortcode':
                $shortcode = $settings['shortcode'] ?? '';
                return [ 'type' => 'html', 'html' => $shortcode ];
            
            // Posts / Query Loop
            case 'posts':
                $html = '<div class="posts-placeholder"><p><em>Posts query placeholder</em></p></div>';
                return [ 'type' => 'html', 'html' => $html ];
            
            // Slider / Carousel
            case 'slider':
            case 'carousel':
                $slides = $settings['slides'] ?? [];
                $html = '<div class="slider">';
                foreach ( $slides as $slide ) {
                    $content = is_array( $slide ) ? ($slide['content'] ?? '') : $slide;
                    $html .= '<div class="slide">' . wp_kses_post( $content ) . '</div>';
                }
                $html .= '</div>';
                return [ 'type' => 'html', 'html' => $html ];
            
            // Testimonial
            case 'testimonial':
                $content = $settings['content'] ?? '';
                $author = $settings['author'] ?? '';
                $occupation = $settings['occupation'] ?? '';
                
                $html = '<blockquote>';
                if ( $content ) $html .= '<p>' . wp_kses_post( $content ) . '</p>';
                if ( $author || $occupation ) {
                    $html .= '<cite>';
                    if ( $author ) $html .= '<strong>' . esc_html( $author ) . '</strong>';
                    if ( $occupation ) $html .= ' - ' . esc_html( $occupation );
                    $html .= '</cite>';
                }
                $html .= '</blockquote>';
                
                return [ 'type' => 'html', 'html' => $html ];
            
            // Unknown elements - try to preserve content
            default:
                Logger::debug( 'Bricks: Unknown element type', [ 'type' => $type ] );
                
                // If has children, return as section
                if ( ! empty( $childElements ) ) {
                    return [ 'type' => 'section', 'style' => [], 'children' => $childElements ];
                }
                
                // Try to extract any text content
                if ( isset( $settings['text'] ) || isset( $settings['content'] ) ) {
                    $content = $settings['text'] ?? $settings['content'] ?? '';
                    if ( ! empty( $content ) ) {
                        return [ 'type' => 'html', 'html' => wp_kses_post( $content ) ];
                    }
                }
                
                return null;
        }
    }

    private function extractStyle( array $settings ) : array {
        $style = [];
        
        // Extract common styling properties
        if ( isset( $settings['_background'] ) ) {
            $style['background'] = $settings['_background'];
        }
        
        if ( isset( $settings['_backgroundColor'] ) ) {
            $style['background-color'] = $settings['_backgroundColor'];
        }
        
        if ( isset( $settings['_padding'] ) ) {
            $style['padding'] = $settings['_padding'];
        }
        
        if ( isset( $settings['_margin'] ) ) {
            $style['margin'] = $settings['_margin'];
        }
        
        if ( isset( $settings['_cssId'] ) ) {
            $style['id'] = $settings['_cssId'];
        }
        
        if ( isset( $settings['_cssClasses'] ) ) {
            $style['class'] = is_array( $settings['_cssClasses'] ) 
                ? implode( ' ', $settings['_cssClasses'] ) 
                : $settings['_cssClasses'];
        }
        
        return $style;
    }
}

