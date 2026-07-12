<?php
namespace Albus\Convert;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Convert neutral tree to Elementor _elementor_data JSON tree.
 * Uses modern container layout (flexbox).
 */
class ToElementor {

    public function build( array $tree ) : array {
        $out = [];
        foreach ( $tree as $node ) {
            $el = $this->mapNode( $node );
            if ( $el ) {
                $out[] = $el;
            }
        }
        // Ensure at least one root container
        if ( empty( $out ) ) {
            return [];
        }
        return $out;
    }

    private function mapNode( array $n ) {
        $type = $n['type'] ?? 'html';

        switch ( $type ) {
            case 'section':
                return $this->mapSection( $n );

            case 'column':
                return $this->mapColumnAsContainer( $n );

            case 'heading':
                $level = max( 1, min( 6, intval( $n['level'] ?? 2 ) ) );
                return $this->widget( 'heading', [
                    'title'       => wp_strip_all_tags( $n['text'] ?? '' ),
                    'header_size' => 'h' . $level,
                ]);

            case 'text':
                return $this->widget( 'text-editor', [
                    'editor' => $n['html'] ?? '',
                ]);

            case 'image':
                $id = intval( $n['id'] ?? 0 );
                $url = $n['url'] ?? '';
                if ( $id > 0 && $url === '' ) {
                    $url = wp_get_attachment_url( $id ) ?: '';
                }
                return $this->widget( 'image', [
                    'image' => [
                        'id'  => $id,
                        'url' => $url,
                    ],
                ]);

            case 'button':
                return $this->widget( 'button', [
                    'text' => $n['text'] ?? 'Button',
                    'link' => [
                        'url'         => $n['url'] ?? '#',
                        'is_external' => '',
                        'nofollow'    => '',
                    ],
                ]);

            case 'html':
            default:
                $html = $n['html'] ?? '';
                if ( $html === '' ) {
                    return null;
                }
                return $this->widget( 'html', [
                    'html' => $html,
                ]);
        }
    }

    private function mapSection( array $n ) : array {
        $children = $n['children'] ?? [];
        $hasColumns = false;
        foreach ( $children as $child ) {
            if ( ( $child['type'] ?? '' ) === 'column' ) {
                $hasColumns = true;
                break;
            }
        }

        $elements = [];
        if ( $hasColumns ) {
            foreach ( $children as $child ) {
                if ( ( $child['type'] ?? '' ) === 'column' ) {
                    $el = $this->mapColumnAsContainer( $child );
                } else {
                    $el = $this->wrapInContainer( [ $this->mapNode( $child ) ] );
                }
                if ( $el ) {
                    $elements[] = $el;
                }
            }
            return [
                'id'       => $this->newId(),
                'elType'   => 'container',
                'isInner'  => false,
                'settings' => [
                    'content_width'   => 'full',
                    'flex_direction'  => 'row',
                    'flex_wrap'       => 'wrap',
                ],
                'elements' => $elements,
            ];
        }

        foreach ( $children as $child ) {
            $el = $this->mapNode( $child );
            if ( $el ) {
                $elements[] = $el;
            }
        }

        return [
            'id'       => $this->newId(),
            'elType'   => 'container',
            'isInner'  => false,
            'settings' => [
                'content_width' => 'boxed',
            ],
            'elements' => $elements,
        ];
    }

    private function mapColumnAsContainer( array $n ) : array {
        $width = intval( $n['width'] ?? 100 );
        if ( $width <= 0 ) {
            $width = 100;
        }

        $elements = [];
        foreach ( ( $n['children'] ?? [] ) as $child ) {
            $el = $this->mapNode( $child );
            if ( $el ) {
                $elements[] = $el;
            }
        }

        return [
            'id'       => $this->newId(),
            'elType'   => 'container',
            'isInner'  => true,
            'settings' => [
                'content_width' => 'full',
                'width'         => [
                    'unit'  => '%',
                    'size'  => $width,
                    'sizes' => [],
                ],
            ],
            'elements' => $elements,
        ];
    }

    private function wrapInContainer( array $widgets ) : array {
        $elements = array_values( array_filter( $widgets ) );
        return [
            'id'       => $this->newId(),
            'elType'   => 'container',
            'isInner'  => true,
            'settings' => [ 'content_width' => 'full' ],
            'elements' => $elements,
        ];
    }

    private function widget( string $widgetType, array $settings ) : array {
        return [
            'id'         => $this->newId(),
            'elType'     => 'widget',
            'widgetType' => $widgetType,
            'isInner'    => false,
            'settings'   => $settings,
            'elements'   => [],
        ];
    }

    private function newId() : string {
        try {
            return substr( bin2hex( random_bytes( 4 ) ), 0, 7 );
        } catch ( \Exception $e ) {
            return substr( md5( uniqid( (string) mt_rand(), true ) ), 0, 7 );
        }
    }
}
