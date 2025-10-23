<?php
namespace Albus\Extract;

use Albus\Util\ShortcodeParser;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class WPBakery {

    public function toNeutralFromContent( string $content ) : array {
        $tree = ShortcodeParser::parse_tree( $content );
        return $this->toNeutral( $tree );
    }

    public function toNeutral( array $tree ) : array {
        $out = [];
        foreach ( $tree as $node ) {
            $tag = $node['tag'] ?? '';
            if ( $tag === 'vc_row' ) {
                $out[] = [
                    'type' => 'section',
                    'style' => $this->sectionStyle( $node['attrs'] ?? [] ),
                    'children' => $this->mapChildren( $node['children'] ?? [] ),
                ];
            } elseif ( $tag === 'vc_column' ) {
                $out[] = [
                    'type' => 'column',
                    'width' => $this->columnWidth( $node['attrs'] ?? [] ),
                    'children' => $this->toNeutral( $node['children'] ?? [] ),
                ];
            } elseif ( $tag === 'vc_text_block' ) {
                $out[] = [ 'type' => 'text', 'html' => $this->innerHTML( $node ) ];
            } elseif ( $tag === 'vc_single_image' ) {
                $out[] = [ 'type' => 'image', 'id' => intval( $node['attrs']['image'] ?? 0 ), 'url' => '' ];
            } elseif ( $tag === 'vc_btn' ) {
                $out[] = [ 'type' => 'button', 'text' => $node['attrs']['title'] ?? 'Button', 'url' => $this->parseLink( $node['attrs']['link'] ?? '' ) ];
            } elseif ( $tag === 'text' ) {
                $t = trim( $node['text'] ?? '' );
                if ( $t !== '' ) $out[] = [ 'type' => 'html', 'html' => wp_kses_post( $t ) ];
            } else {
                if ( ! empty( $node['raw'] ) ) $out[] = [ 'type' => 'html', 'html' => $node['raw'] ];
            }
        }
        return $out;
    }

    private function mapChildren( array $children ) : array {
        $cols = [];
        foreach ( $children as $ch ) {
            if ( ($ch['tag'] ?? '') === 'vc_column' ) {
                $cols[] = [
                    'type' => 'column',
                    'width' => $this->columnWidth( $ch['attrs'] ?? [] ),
                    'children' => $this->toNeutral( $ch['children'] ?? [] ),
                ];
            }
        }
        return $cols;
    }

    private function sectionStyle( array $attrs ) : array {
        $style = [];
        if ( ! empty( $attrs['css'] ) ) $style['css'] = $attrs['css'];
        if ( isset($attrs['el_id']) ) $style['id'] = $attrs['el_id'];
        return $style;
    }

    private function columnWidth( array $attrs ) : int {
        // vc_column width like "1/2", "1/3"
        $w = $attrs['width'] ?? '1/1';
        if ( strpos($w, '/') !== false ) {
            list($a,$b) = array_map('intval', explode('/', $w));
            if ($b>0) return intval($a*100/$b);
        }
        return 100;
    }

    private function innerHTML( array $node ) : string {
        // In prototype, we don't have inner capture; VC content often follows as text between tags.
        // Returning empty; parser enhancement would be needed for exact inner.
        return '';
    }

    private function parseLink( string $vcLink ) : string {
        // WPBakery link format: url:...|title:...|target:...
        $parts = explode('|', $vcLink);
        foreach ( $parts as $p ) {
            if ( strpos($p,'url:') === 0 ) return substr($p, 4);
        }
        return '#';
    }
}
