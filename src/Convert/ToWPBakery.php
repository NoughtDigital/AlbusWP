<?php
namespace Albus\Convert;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Convert neutral tree to WPBakery shortcode markup.
 */
class ToWPBakery {

    public function render( array $tree ) : string {
        $out = '';
        foreach ( $tree as $node ) {
            $out .= $this->renderNode( $node );
        }
        // If content has no rows, wrap in a single full-width row/column
        if ( $out !== '' && strpos( $out, '[vc_row' ) === false ) {
            $out = '[vc_row][vc_column width="1/1"]' . $out . '[/vc_column][/vc_row]';
        }
        return $out;
    }

    private function renderNode( array $n ) : string {
        switch ( $n['type'] ?? '' ) {
            case 'section':
                return $this->renderSection( $n );

            case 'column':
                $width = $this->fractionWidth( intval( $n['width'] ?? 100 ) );
                $inner = '';
                foreach ( ( $n['children'] ?? [] ) as $child ) {
                    $inner .= $this->renderNode( $child );
                }
                return '[vc_column width="' . esc_attr( $width ) . '"]' . $inner . '[/vc_column]';

            case 'heading':
                $level = max( 1, min( 6, intval( $n['level'] ?? 2 ) ) );
                $text = $this->escAttr( wp_strip_all_tags( $n['text'] ?? '' ) );
                return '[vc_custom_heading text="' . $text . '" font_container="tag:h' . $level . '|text_align:left"]';

            case 'text':
                $html = $n['html'] ?? '';
                return '[vc_column_text]' . $html . '[/vc_column_text]';

            case 'image':
                $id = intval( $n['id'] ?? 0 );
                $url = $n['url'] ?? '';
                if ( $id > 0 ) {
                    return '[vc_single_image image="' . $id . '" img_size="large"]';
                }
                if ( $url !== '' ) {
                    return '[vc_single_image source="external_link" custom_src="' . esc_url( $url ) . '"]';
                }
                return '';

            case 'button':
                $text = $this->escAttr( $n['text'] ?? 'Button' );
                $url = rawurlencode( $n['url'] ?? '#' );
                $link = 'url:' . $url . '|title:' . rawurlencode( $n['text'] ?? 'Button' );
                return '[vc_btn title="' . $text . '" link="' . $this->escAttr( $link ) . '"]';

            case 'html':
            default:
                $html = $n['html'] ?? '';
                if ( $html === '' ) {
                    return '';
                }
                // Prefer column_text for simple HTML; raw_html for complex
                if ( strip_tags( $html ) === $html || preg_match( '/^<(p|h[1-6]|ul|ol|li|div|br|strong|em|a)\b/i', $html ) ) {
                    return '[vc_column_text]' . $html . '[/vc_column_text]';
                }
                return '[vc_raw_html]' . base64_encode( $html ) . '[/vc_raw_html]';
        }
    }

    private function renderSection( array $n ) : string {
        $children = $n['children'] ?? [];
        $hasColumns = false;
        foreach ( $children as $child ) {
            if ( ( $child['type'] ?? '' ) === 'column' ) {
                $hasColumns = true;
                break;
            }
        }

        if ( $hasColumns ) {
            $inner = '';
            foreach ( $children as $child ) {
                if ( ( $child['type'] ?? '' ) === 'column' ) {
                    $inner .= $this->renderNode( $child );
                } else {
                    // Non-column siblings get their own full column
                    $inner .= '[vc_column width="1/1"]' . $this->renderNode( $child ) . '[/vc_column]';
                }
            }
            return '[vc_row]' . $inner . '[/vc_row]';
        }

        $inner = '';
        foreach ( $children as $child ) {
            $inner .= $this->renderNode( $child );
        }
        return '[vc_row][vc_column width="1/1"]' . $inner . '[/vc_column][/vc_row]';
    }

    private function fractionWidth( int $percent ) : string {
        $map = [
            100 => '1/1',
            92  => '11/12',
            83  => '5/6',
            80  => '4/5',
            75  => '3/4',
            67  => '2/3',
            66  => '2/3',
            60  => '3/5',
            58  => '7/12',
            50  => '1/2',
            42  => '5/12',
            40  => '2/5',
            33  => '1/3',
            25  => '1/4',
            20  => '1/5',
            17  => '1/6',
            8   => '1/12',
        ];
        $best = '1/1';
        $bestDiff = 999;
        foreach ( $map as $p => $frac ) {
            $diff = abs( $percent - $p );
            if ( $diff < $bestDiff ) {
                $bestDiff = $diff;
                $best = $frac;
            }
        }
        return $best;
    }

    private function escAttr( string $value ) : string {
        return str_replace( [ '"', '[', ']' ], [ '&quot;', '', '' ], $value );
    }
}
