<?php
namespace Albus\Convert;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Convert neutral tree to Bricks flat element list.
 *
 * Output shape (stored as PHP array in _bricks_page_content_2):
 * [
 *   [ 'id' => 'abc123', 'name' => 'section', 'parent' => 0, 'children' => ['...'], 'settings' => [...] ],
 *   ...
 * ]
 */
class ToBricks {

    /** @var array */
    private $elements = [];

    public function build( array $tree ) : array {
        $this->elements = [];

        if ( empty( $tree ) ) {
            return [];
        }

        // Wrap orphan content (headings/text/etc at root) in a section
        $all_sections = true;
        foreach ( $tree as $node ) {
            if ( ( $node['type'] ?? '' ) !== 'section' ) {
                $all_sections = false;
                break;
            }
        }

        if ( $all_sections ) {
            foreach ( $tree as $node ) {
                $this->addNode( $node, 0 );
            }
        } else {
            $this->addSection([
                'type'     => 'section',
                'style'    => [],
                'children' => $tree,
            ], 0 );
        }

        return $this->elements;
    }

    private function addNode( array $n, $parentId ) : string {
        $type = $n['type'] ?? 'html';

        switch ( $type ) {
            case 'section':
                return $this->addSection( $n, $parentId );

            case 'column':
                return $this->addColumn( $n, $parentId );

            case 'heading':
                $id = $this->newId();
                $level = max( 1, min( 6, intval( $n['level'] ?? 2 ) ) );
                $this->elements[] = [
                    'id'       => $id,
                    'name'     => 'heading',
                    'parent'   => $parentId,
                    'children' => [],
                    'settings' => [
                        'text' => $n['text'] ?? '',
                        'tag'  => 'h' . $level,
                    ],
                ];
                return $id;

            case 'text':
                $id = $this->newId();
                $this->elements[] = [
                    'id'       => $id,
                    'name'     => 'text',
                    'parent'   => $parentId,
                    'children' => [],
                    'settings' => [
                        'text' => $n['html'] ?? '',
                    ],
                ];
                return $id;

            case 'image':
                $id = $this->newId();
                $imgId = intval( $n['id'] ?? 0 );
                $url = $n['url'] ?? '';
                if ( $imgId > 0 && $url === '' ) {
                    $url = wp_get_attachment_url( $imgId ) ?: '';
                }
                $this->elements[] = [
                    'id'       => $id,
                    'name'     => 'image',
                    'parent'   => $parentId,
                    'children' => [],
                    'settings' => [
                        'image' => [
                            'id'  => $imgId,
                            'url' => $url,
                            'size' => 'large',
                        ],
                    ],
                ];
                return $id;

            case 'button':
                $id = $this->newId();
                $this->elements[] = [
                    'id'       => $id,
                    'name'     => 'button',
                    'parent'   => $parentId,
                    'children' => [],
                    'settings' => [
                        'text' => $n['text'] ?? 'Button',
                        'link' => [
                            'type' => 'external',
                            'url'  => $n['url'] ?? '#',
                        ],
                    ],
                ];
                return $id;

            case 'html':
            default:
                $id = $this->newId();
                $this->elements[] = [
                    'id'       => $id,
                    'name'     => 'code',
                    'parent'   => $parentId,
                    'children' => [],
                    'settings' => [
                        'code' => $n['html'] ?? '',
                    ],
                ];
                return $id;
        }
    }

    private function addSection( array $n, $parentId ) : string {
        $sectionId = $this->newId();
        $containerId = $this->newId();

        $childIds = [];
        $hasColumns = false;
        foreach ( ( $n['children'] ?? [] ) as $child ) {
            if ( ( $child['type'] ?? '' ) === 'column' ) {
                $hasColumns = true;
            }
        }

        if ( $hasColumns ) {
            // section > container (flex row) > column containers
            $colIds = [];
            foreach ( ( $n['children'] ?? [] ) as $child ) {
                if ( ( $child['type'] ?? '' ) === 'column' ) {
                    $colIds[] = $this->addColumn( $child, $containerId );
                } else {
                    $colIds[] = $this->addNode( $child, $containerId );
                }
            }
            $this->elements[] = [
                'id'       => $containerId,
                'name'     => 'container',
                'parent'   => $sectionId,
                'children' => $colIds,
                'settings' => [
                    '_display'        => 'flex',
                    '_direction'      => 'row',
                    '_alignItems'     => 'stretch',
                    '_justifyContent' => 'flex-start',
                ],
            ];
            $childIds = [ $containerId ];
        } else {
            $innerIds = [];
            foreach ( ( $n['children'] ?? [] ) as $child ) {
                $innerIds[] = $this->addNode( $child, $containerId );
            }
            $this->elements[] = [
                'id'       => $containerId,
                'name'     => 'container',
                'parent'   => $sectionId,
                'children' => $innerIds,
                'settings' => [],
            ];
            $childIds = [ $containerId ];
        }

        $settings = [];
        if ( ! empty( $n['style'] ) && is_array( $n['style'] ) ) {
            if ( ! empty( $n['style']['padding'] ) ) {
                $settings['_padding'] = $n['style']['padding'];
            }
            if ( ! empty( $n['style']['margin'] ) ) {
                $settings['_margin'] = $n['style']['margin'];
            }
            if ( ! empty( $n['style']['id'] ) ) {
                $settings['_cssId'] = $n['style']['id'];
            }
            if ( ! empty( $n['style']['class'] ) ) {
                $settings['_cssClasses'] = $n['style']['class'];
            }
        }

        $this->elements[] = [
            'id'       => $sectionId,
            'name'     => 'section',
            'parent'   => $parentId,
            'children' => $childIds,
            'settings' => $settings,
        ];

        return $sectionId;
    }

    private function addColumn( array $n, $parentId ) : string {
        $id = $this->newId();
        $width = intval( $n['width'] ?? 100 );
        if ( $width <= 0 ) {
            $width = 100;
        }

        $childIds = [];
        foreach ( ( $n['children'] ?? [] ) as $child ) {
            $childIds[] = $this->addNode( $child, $id );
        }

        $this->elements[] = [
            'id'       => $id,
            'name'     => 'container',
            'parent'   => $parentId,
            'children' => $childIds,
            'settings' => [
                '_width' => $width . '%',
            ],
        ];

        return $id;
    }

    private function newId() : string {
        // Bricks uses 6-char alphanumeric IDs
        try {
            return substr( bin2hex( random_bytes( 4 ) ), 0, 6 );
        } catch ( \Exception $e ) {
            return substr( md5( uniqid( (string) mt_rand(), true ) ), 0, 6 );
        }
    }
}
