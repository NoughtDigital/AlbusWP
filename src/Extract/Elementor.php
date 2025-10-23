<?php
namespace Albus\Extract;

use Albus\Util\Logger;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Elementor {

    public function toNeutralFromData( $data ) : array {
        Logger::debug( 'Elementor: Starting extraction', [ 
            'data_type' => gettype($data), 
            'is_array' => is_array($data),
            'element_count' => is_array($data) ? count($data) : 0
        ]);
        
        if ( ! is_array( $data ) ) {
            Logger::warning( 'Elementor: Data is not an array', [ 'type' => gettype($data) ] );
            return [];
        }
        
        if ( empty( $data ) ) {
            Logger::warning( 'Elementor: Data array is empty' );
            return [];
        }
        
        $out = [];
        foreach ( $data as $index => $el ) {
            Logger::debug( 'Elementor: Processing element', [ 
                'index' => $index,
                'elType' => $el['elType'] ?? 'unknown',
                'widgetType' => $el['widgetType'] ?? 'none',
                'has_elements' => isset($el['elements']),
                'elements_count' => count($el['elements'] ?? [])
            ]);
            
            $n = $this->mapNode( $el );
            if ( $n ) {
                $out[] = $n;
                Logger::debug( 'Elementor: Mapped node successfully', [ 'type' => $n['type'] ] );
            } else {
                Logger::debug( 'Elementor: Node mapping returned null', [ 
                    'elType' => $el['elType'] ?? 'unknown',
                    'widgetType' => $el['widgetType'] ?? 'none'
                ]);
            }
        }
        
        Logger::info( 'Elementor: Extraction complete', [ 'output_count' => count($out) ] );
        return $out;
    }

    private function mapNode( array $el ) {
        $type = $el['elType'] ?? $el['widgetType'] ?? '';
        
        // Process children first
        $children = [];
        foreach ( ($el['elements'] ?? []) as $child ) {
            $m = $this->mapNode( $child );
            if ( $m ) $children[] = $m;
        }
        
        // Handle sections (old system)
        if ( $type === 'section' ) {
            Logger::debug( 'Elementor: Mapping section', [ 'children_count' => count($children) ] );
            return [ 'type' => 'section', 'style' => [], 'children' => $children ];
        }
        
        // Handle containers (new flexbox system)
        if ( $type === 'container' ) {
            Logger::debug( 'Elementor: Mapping container', [ 'children_count' => count($children) ] );
            // Treat containers like sections
            return [ 'type' => 'section', 'style' => [], 'children' => $children ];
        }
        
        // Handle columns (old system)
        if ( $type === 'column' ) {
            $width = intval( $el['settings']['_inline_size'] ?? 0 );
            if ( $width <= 0 ) $width = 100;
            Logger::debug( 'Elementor: Mapping column', [ 'width' => $width, 'children_count' => count($children) ] );
            return [ 'type' => 'column', 'width' => $width, 'children' => $children ];
        }
        
        // Handle widgets
        $widgetType = $el['widgetType'] ?? '';
        
        // Heading widget
        if ( $widgetType === 'heading' ) {
            $text = wp_kses_post( $el['settings']['title'] ?? '' );
            // header_size can be 'h1', 'h2', etc. or just '2', '3', etc.
            $header_size = $el['settings']['header_size'] ?? 'h2';
            $level = is_numeric($header_size) ? intval($header_size) : intval(str_replace('h', '', strtolower($header_size)));
            if ( $level < 1 || $level > 6 ) $level = 2;
            return [ 'type' => 'heading', 'level' => $level, 'text' => $text ];
        }
        
        // Text editor widget
        if ( $widgetType === 'text-editor' ) {
            $html = $el['settings']['editor'] ?? '';
            return [ 'type' => 'text', 'html' => $html ];
        }
        
        // Image widget
        if ( $widgetType === 'image' ) {
            $id = intval( $el['settings']['image']['id'] ?? 0 );
            return [ 'type' => 'image', 'id' => $id ];
        }
        
        // Button widget
        if ( $widgetType === 'button' ) {
            $text = $el['settings']['text'] ?? 'Button';
            $url = '#';
            if ( isset($el['settings']['link']) ) {
                if ( is_array($el['settings']['link']) ) {
                    $url = $el['settings']['link']['url'] ?? '#';
                } else {
                    $url = $el['settings']['link'];
                }
            }
            return [ 'type' => 'button', 'text' => $text, 'url' => $url ];
        }
        
        // Icon-box widget (title + description + icon)
        if ( $widgetType === 'icon-box' ) {
            $title = wp_kses_post( $el['settings']['title_text'] ?? '' );
            $description = wp_kses_post( $el['settings']['description_text'] ?? '' );
            $html = '';
            if ( $title ) $html .= '<h3>' . $title . '</h3>';
            if ( $description ) $html .= '<p>' . $description . '</p>';
            return [ 'type' => 'html', 'html' => $html ];
        }
        
        // Call-to-action widget (basically a complex banner/card)
        if ( $widgetType === 'call-to-action' ) {
            $title = wp_kses_post( $el['settings']['title'] ?? '' );
            $description = wp_kses_post( $el['settings']['description'] ?? '' );
            $image_id = intval( $el['settings']['bg_image']['id'] ?? 0 );
            
            $html = '';
            if ( $image_id > 0 ) {
                $html .= wp_get_attachment_image( $image_id, 'large' );
            }
            if ( $title ) $html .= '<h3>' . $title . '</h3>';
            if ( $description ) $html .= '<p>' . $description . '</p>';
            
            return [ 'type' => 'html', 'html' => $html ];
        }
        
        // Image carousel widget
        if ( $widgetType === 'image-carousel' ) {
            $carousel = $el['settings']['carousel'] ?? [];
            if ( ! is_array($carousel) ) $carousel = [];
            
            $html = '<div class="image-carousel">';
            foreach ( $carousel as $slide ) {
                $image_id = intval( $slide['id'] ?? 0 );
                if ( $image_id > 0 ) {
                    $html .= wp_get_attachment_image( $image_id, 'large' );
                }
            }
            $html .= '</div>';
            
            return [ 'type' => 'html', 'html' => $html ];
        }
        
        // Divider widget
        if ( $widgetType === 'divider' ) {
            return [ 'type' => 'html', 'html' => '<hr />' ];
        }
        
        // Star rating widget
        if ( $widgetType === 'star-rating' ) {
            $rating = floatval( $el['settings']['rating'] ?? 5 );
            $html = '<div class="star-rating">';
            for ( $i = 0; $i < 5; $i++ ) {
                $html .= ( $i < $rating ) ? '★' : '☆';
            }
            $html .= '</div>';
            return [ 'type' => 'html', 'html' => $html ];
        }
        
        // Testimonial carousel widget
        if ( $widgetType === 'testimonial-carousel' ) {
            $slides = $el['settings']['slides'] ?? [];
            if ( ! is_array($slides) ) $slides = [];
            
            $html = '<div class="testimonials">';
            foreach ( $slides as $slide ) {
                $content = wp_kses_post( $slide['content'] ?? '' );
                $name = wp_kses_post( $slide['name'] ?? '' );
                $title = wp_kses_post( $slide['title'] ?? '' );
                
                $html .= '<blockquote>';
                if ( $content ) $html .= '<p>' . $content . '</p>';
                if ( $name || $title ) {
                    $html .= '<cite>';
                    if ( $name ) $html .= '<strong>' . $name . '</strong>';
                    if ( $title ) $html .= ' - ' . $title;
                    $html .= '</cite>';
                }
                $html .= '</blockquote>';
            }
            $html .= '</div>';
            
            return [ 'type' => 'html', 'html' => $html ];
        }
        
        // Form widget (basic representation)
        if ( $widgetType === 'form' ) {
            $form_name = $el['settings']['form_name'] ?? 'Contact Form';
            $fields = $el['settings']['form_fields'] ?? [];
            
            $html = '<form>';
            $html .= '<h3>' . esc_html($form_name) . '</h3>';
            if ( is_array($fields) ) {
                foreach ( $fields as $field ) {
                    $label = $field['field_label'] ?? '';
                    $type = $field['field_type'] ?? 'text';
                    if ( $label ) {
                        $html .= '<label>' . esc_html($label) . '</label>';
                    }
                    if ( $type === 'textarea' ) {
                        $html .= '<textarea></textarea>';
                    } else {
                        $html .= '<input type="' . esc_attr($type) . '" />';
                    }
                }
            }
            $html .= '<button type="submit">' . esc_html($el['settings']['button_text'] ?? 'Submit') . '</button>';
            $html .= '</form>';
            
            return [ 'type' => 'html', 'html' => $html ];
        }
        
        // Social icons widget
        if ( $widgetType === 'social-icons' ) {
            $icons = $el['settings']['social_icon_list'] ?? [];
            if ( ! is_array($icons) ) $icons = [];
            
            $html = '<div class="social-icons">';
            foreach ( $icons as $icon ) {
                $link = $icon['link']['url'] ?? '#';
                $social = $icon['social'] ?? '';
                $html .= '<a href="' . esc_url($link) . '">' . esc_html($social) . '</a> ';
            }
            $html .= '</div>';
            
            return [ 'type' => 'html', 'html' => $html ];
        }
        
        // Slides widget (slider/carousel)
        if ( $widgetType === 'slides' ) {
            $slides = $el['settings']['slides'] ?? [];
            if ( ! is_array($slides) ) $slides = [];
            
            $html = '<div class="slides">';
            foreach ( $slides as $slide ) {
                $heading = wp_kses_post( $slide['heading'] ?? '' );
                $description = wp_kses_post( $slide['description'] ?? '' );
                
                $html .= '<div class="slide">';
                if ( $heading ) $html .= '<h3>' . $heading . '</h3>';
                if ( $description ) $html .= '<div>' . $description . '</div>';
                $html .= '</div>';
            }
            $html .= '</div>';
            
            return [ 'type' => 'html', 'html' => $html ];
        }
        
        // If we have children but couldn't map the element type, still return the children as a section
        if ( ! empty( $children ) ) {
            Logger::debug( 'Elementor: Unknown type with children, wrapping as section', [ 
                'type' => $type, 
                'widgetType' => $widgetType 
            ]);
            return [ 'type' => 'section', 'style' => [], 'children' => $children ];
        }
        
        // For unknown widgets, try to extract any text content as HTML
        if ( ! empty( $widgetType ) ) {
            Logger::debug( 'Elementor: Unknown widget type, attempting generic extraction', [ 
                'widgetType' => $widgetType 
            ]);
            
            // Try to find any text content in settings
            $html = '';
            if ( isset($el['settings']) && is_array($el['settings']) ) {
                // Common text fields
                foreach ( ['content', 'text', 'html', 'editor', 'description'] as $field ) {
                    if ( isset($el['settings'][$field]) && is_string($el['settings'][$field]) ) {
                        $html = $el['settings'][$field];
                        break;
                    }
                }
            }
            
            if ( ! empty( $html ) ) {
                Logger::debug( 'Elementor: Extracted text content from unknown widget', [ 
                    'widgetType' => $widgetType,
                    'length' => strlen($html)
                ]);
                return [ 'type' => 'html', 'html' => $html ];
            }
        }
        
        Logger::debug( 'Elementor: Could not map element', [ 
            'elType' => $type,
            'widgetType' => $widgetType
        ]);
        
        return null;
    }
}
