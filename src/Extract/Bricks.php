<?php
namespace Albus\Extract;

use Albus\Util\Logger;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Bricks Builder Extractor
 *
 * Bricks stores a flat list of elements with parent/children ID references
 * in _bricks_page_content_2 (PHP array via post meta).
 */
class Bricks {

    /** @var array<string,array> */
    private $index = [];

    public function toNeutralFromData( $data ) : array {
        Logger::debug( 'Bricks: Starting extraction', [
            'data_type' => gettype( $data ),
            'is_array'  => is_array( $data ),
        ]);

        if ( ! is_array( $data ) || empty( $data ) ) {
            Logger::warning( 'Bricks: Data is empty or not an array' );
            return [];
        }

        // Detect nested-tree legacy Albus format vs real Bricks flat list
        $first = reset( $data );
        if ( is_array( $first ) && isset( $first['root'] ) ) {
            // Legacy Albus nested format
            $children = $first['root']['children'] ?? [];
            $out = [];
            foreach ( $children as $el ) {
                $mapped = $this->mapNestedLegacy( $el );
                if ( $mapped ) {
                    $out[] = $mapped;
                }
            }
            return $out;
        }

        $this->index = [];
        foreach ( $data as $element ) {
            if ( ! is_array( $element ) || empty( $element['id'] ) ) {
                continue;
            }
            $this->index[ (string) $element['id'] ] = $element;
        }

        $out = [];
        foreach ( $data as $element ) {
            if ( ! is_array( $element ) ) {
                continue;
            }
            $parent = $element['parent'] ?? 0;
            if ( ! empty( $parent ) && $parent !== 0 && $parent !== '0' ) {
                continue; // only top-level
            }
            $mapped = $this->mapElement( $element );
            if ( $mapped ) {
                $out[] = $mapped;
            }
        }

        Logger::info( 'Bricks: Extraction complete', [ 'output_count' => count( $out ) ] );
        return $out;
    }

    private function mapElement( array $element ) {
        $type = $element['name'] ?? $element['type'] ?? '';
        $settings = $element['settings'] ?? [];
        $childIds = $element['children'] ?? [];

        $childElements = [];
        if ( is_array( $childIds ) ) {
            foreach ( $childIds as $childId ) {
                if ( is_array( $childId ) ) {
                    // Nested object (legacy) — map directly
                    $mapped = $this->mapNestedLegacy( $childId );
                    if ( $mapped ) {
                        $childElements[] = $mapped;
                    }
                    continue;
                }
                $childId = (string) $childId;
                if ( ! isset( $this->index[ $childId ] ) ) {
                    continue;
                }
                $mapped = $this->mapElement( $this->index[ $childId ] );
                if ( $mapped ) {
                    $childElements[] = $mapped;
                }
            }
        }

        return $this->mapByType( $type, $settings, $childElements );
    }

    private function mapNestedLegacy( array $element ) {
        $type = $element['name'] ?? $element['type'] ?? '';
        $settings = $element['settings'] ?? [];
        if ( is_object( $settings ) ) {
            $settings = (array) $settings;
        }
        $children = [];
        foreach ( ( $element['children'] ?? [] ) as $child ) {
            if ( is_array( $child ) ) {
                $mapped = $this->mapNestedLegacy( $child );
                if ( $mapped ) {
                    $children[] = $mapped;
                }
            }
        }
        return $this->mapByType( $type, $settings, $children );
    }

    private function mapByType( string $type, array $settings, array $childElements ) {
        switch ( $type ) {
            case 'section':
            case 'container':
            case 'block':
            case 'div':
                // Containers that only wrap columns-like children stay as section
                $width = $this->widthPercent( $settings );
                if ( $width > 0 && $width < 100 && ! in_array( $type, [ 'section' ], true ) ) {
                    return [
                        'type'     => 'column',
                        'width'    => $width,
                        'style'    => $this->extractStyle( $settings ),
                        'children' => $childElements,
                    ];
                }
                return [
                    'type'     => 'section',
                    'style'    => $this->extractStyle( $settings ),
                    'children' => $childElements,
                ];

            case 'heading':
                $tag = $settings['tag'] ?? 'h2';
                $level = intval( str_replace( 'h', '', strtolower( (string) $tag ) ) );
                if ( $level < 1 || $level > 6 ) {
                    $level = 2;
                }
                return [
                    'type'  => 'heading',
                    'level' => $level,
                    'text'  => wp_kses_post( $settings['text'] ?? '' ),
                ];

            case 'text':
            case 'text-basic':
            case 'rich-text':
                $content = $settings['text'] ?? ( $settings['content'] ?? '' );
                return [ 'type' => 'text', 'html' => $content ];

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
                if ( $id === 0 && isset( $settings['attachmentId'] ) ) {
                    $id = intval( $settings['attachmentId'] );
                }
                if ( $id > 0 && $url === '' ) {
                    $url = wp_get_attachment_url( $id ) ?: '';
                }
                return [ 'type' => 'image', 'id' => $id, 'url' => $url ];

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

            case 'video':
                $videoType = $settings['videoType'] ?? 'media';
                $html = '';
                if ( $videoType === 'media' && isset( $settings['video'] ) ) {
                    $videoId = is_array( $settings['video'] ) ? ( $settings['video']['id'] ?? 0 ) : intval( $settings['video'] );
                    if ( $videoId > 0 ) {
                        $html = wp_video_shortcode( [ 'src' => wp_get_attachment_url( $videoId ) ] );
                    }
                } elseif ( isset( $settings['videoUrl'] ) ) {
                    $html = '[video src="' . esc_url( $settings['videoUrl'] ) . '"]';
                }
                return [ 'type' => 'html', 'html' => $html ];

            case 'divider':
                return [ 'type' => 'html', 'html' => '<hr />' ];

            case 'list':
                $items = $settings['items'] ?? [];
                $listType = $settings['listType'] ?? 'ul';
                $html = '<' . $listType . '>';
                foreach ( $items as $item ) {
                    $text = is_array( $item ) ? ( $item['text'] ?? '' ) : $item;
                    $html .= '<li>' . wp_kses_post( $text ) . '</li>';
                }
                $html .= '</' . $listType . '>';
                return [ 'type' => 'html', 'html' => $html ];

            case 'code':
                return [ 'type' => 'html', 'html' => $settings['code'] ?? ( $settings['content'] ?? '' ) ];

            case 'shortcode':
                return [ 'type' => 'html', 'html' => $settings['shortcode'] ?? '' ];

            default:
                if ( ! empty( $childElements ) ) {
                    return [ 'type' => 'section', 'style' => [], 'children' => $childElements ];
                }
                if ( isset( $settings['text'] ) || isset( $settings['content'] ) ) {
                    $content = $settings['text'] ?? ( $settings['content'] ?? '' );
                    if ( $content !== '' ) {
                        return [ 'type' => 'html', 'html' => wp_kses_post( $content ) ];
                    }
                }
                return null;
        }
    }

    private function widthPercent( array $settings ) : int {
        $w = $settings['_width'] ?? ( $settings['width'] ?? '' );
        if ( is_array( $w ) ) {
            $w = $w['value'] ?? ( $w['width'] ?? '' );
        }
        if ( is_string( $w ) && strpos( $w, '%' ) !== false ) {
            return (int) round( floatval( $w ) );
        }
        if ( is_numeric( $w ) ) {
            $n = intval( $w );
            return ( $n > 0 && $n <= 100 ) ? $n : 0;
        }
        return 0;
    }

    private function extractStyle( array $settings ) : array {
        $style = [];
        foreach ( [ '_background', '_backgroundColor', '_padding', '_margin', '_cssId', '_cssClasses' ] as $key ) {
            if ( isset( $settings[ $key ] ) ) {
                $outKey = ltrim( $key, '_' );
                if ( $key === '_cssId' ) {
                    $outKey = 'id';
                }
                if ( $key === '_cssClasses' ) {
                    $outKey = 'class';
                    $style[ $outKey ] = is_array( $settings[ $key ] )
                        ? implode( ' ', $settings[ $key ] )
                        : $settings[ $key ];
                } else {
                    $style[ $outKey ] = $settings[ $key ];
                }
            }
        }
        return $style;
    }
}
