<?php
namespace Albus\Convert;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ToGutenberg {

    public function render( array $tree ) : string {
        // Wrap sections into groups; columns into columns block.
        $html = '';
        foreach ( $tree as $node ) {
            $html .= $this->renderNode( $node );
        }
        return $html;
    }

    private function renderNode( array $n ) : string {
        switch ( $n['type'] ?? '' ) {
            case 'section':
                $inner = '';
                $hasColumns = false;
                foreach ( ($n['children'] ?? []) as $child ) {
                    if ( ($child['type'] ?? '') === 'column' ) $hasColumns = true;
                }
                if ( $hasColumns ) {
                    $innerCols = '';
                    foreach ( ($n['children'] ?? []) as $col ) {
                        $innerCols .= '<!-- wp:column --><div class="wp-block-column">';
                        foreach ( ($col['children'] ?? []) as $child ) {
                            $innerCols .= $this->renderNode( $child );
                        }
                        $innerCols .= '</div><!-- /wp:column -->';
                    }
                    $inner = '<!-- wp:columns --><div class="wp-block-columns">' . $innerCols . '</div><!-- /wp:columns -->';
                } else {
                    foreach ( ($n['children'] ?? []) as $child ) {
                        $inner .= $this->renderNode( $child );
                    }
                }
                return '<!-- wp:group --><div class="wp-block-group">' . $inner . '</div><!-- /wp:group -->';

            case 'column':
                // Columns are handled inside section above.
                $out = '';
                foreach ( ($n['children'] ?? []) as $child ) $out .= $this->renderNode( $child );
                return $out;

            case 'heading':
                $level = max(1, min(6, intval($n['level'] ?? 2)));
                return sprintf('<!-- wp:heading {"level":%d} --><h%d>%s</h%d><!-- /wp:heading -->', $level, $level, $n['text'] ?? '', $level);

            case 'text':
                return sprintf('<!-- wp:paragraph --><p>%s</p><!-- /wp:paragraph -->', $n['html'] ?? '');

            case 'image':
                $id = intval($n['id'] ?? 0);
                $attrs = $id ? ' {"id":' . $id . '}' : '';
                $img = $id ? wp_get_attachment_image( $id, 'large' ) : '';
                if ( empty($img) ) $img = '<img alt="" />';
                return sprintf('<!-- wp:image%s --><figure class="wp-block-image">%s</figure><!-- /wp:image -->', $attrs, $img);

            case 'button':
                $url = esc_url( $n['url'] ?? '#' );
                $text = esc_html( $n['text'] ?? 'Button' );
                return '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"url":"' . $url . '"} --><div class="wp-block-button"><a class="wp-block-button__link" href="' . $url . '">' . $text . '</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';

            case 'html':
            default:
                $content = $n['html'] ?? '';
                return '<!-- wp:html -->' . $content . '<!-- /wp:html -->';
        }
    }
}
