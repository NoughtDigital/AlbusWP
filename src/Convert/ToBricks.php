<?php
namespace Albus\Convert;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ToBricks {

    public function build( array $tree ) : array {
        $children = [];
        foreach ( $tree as $node ) {
            $children[] = $this->node( $node );
        }
        return [
            'root' => [
                'id' => 'root',
                'type' => 'root',
                'children' => $children
            ],
            'settings' => new \stdClass()
        ];
    }

    private function node( array $n ) {
        $type = $n['type'] ?? 'html';
        switch ( $type ) {
            case 'section':
                return [
                    'id' => uniqid('sec_'),
                    'type' => 'section',
                    'settings' => (object)($n['style'] ?? []),
                    'children' => array_map( fn($c) => $this->node($c), $n['children'] ?? [] ),
                ];
            case 'column':
                return [
                    'id' => uniqid('col_'),
                    'type' => 'container',
                    'settings' => (object)['width' => intval($n['width'] ?? 100) ],
                    'children' => array_map( fn($c) => $this->node($c), $n['children'] ?? [] ),
                ];
            case 'heading':
                return [
                    'id' => uniqid('h_'),
                    'type' => 'heading',
                    'settings' => (object)['tag' => 'h' . intval($n['level'] ?? 2), 'text' => $n['text'] ?? '' ],
                    'children' => []
                ];
            case 'text':
                return [
                    'id' => uniqid('rt_'),
                    'type' => 'rich-text',
                    'settings' => (object)['content' => $n['html'] ?? '' ],
                    'children' => []
                ];
            case 'image':
                return [
                    'id' => uniqid('img_'),
                    'type' => 'image',
                    'settings' => (object)['attachmentId' => intval($n['id'] ?? 0) ],
                    'children' => []
                ];
            case 'button':
                return [
                    'id' => uniqid('btn_'),
                    'type' => 'button',
                    'settings' => (object)['text' => $n['text'] ?? 'Button', 'link' => $n['url'] ?? '#' ],
                    'children' => []
                ];
            case 'html':
            default:
                return [
                    'id' => uniqid('code_'),
                    'type' => 'code',
                    'settings' => (object)['content' => $n['html'] ?? '' ],
                    'children' => []
                ];
        }
    }
}
