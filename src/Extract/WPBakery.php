<?php
namespace Albus\Extract;

use Albus\Util\ShortcodeParser;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class WPBakery {

    public function toNeutralFromContent( string $content ) : array {
        $tree = ShortcodeParser::parse_tree( $content );
        return $this->toNeutral( $tree );
    }

    /**
     * Extract from a post, including optional Design Options CSS meta.
     */
    public function toNeutralFromPost( int $post_id ) : array {
        $content = (string) get_post_field( 'post_content', $post_id );
        $neutral = $this->toNeutralFromContent( $content );

        $custom_css = get_post_meta( $post_id, '_wpb_shortcodes_custom_css', true );
        $page_css   = get_post_meta( $post_id, '_wpb_post_custom_css', true );
        if ( ( $custom_css || $page_css ) && ! empty( $neutral ) ) {
            // Attach page-level CSS to the first root section when present
            if ( ( $neutral[0]['type'] ?? '' ) === 'section' ) {
                if ( ! isset( $neutral[0]['style'] ) || ! is_array( $neutral[0]['style'] ) ) {
                    $neutral[0]['style'] = [];
                }
                if ( $custom_css ) {
                    $neutral[0]['style']['wpb_shortcodes_css'] = $custom_css;
                }
                if ( $page_css ) {
                    $neutral[0]['style']['wpb_page_css'] = $page_css;
                }
            }
        }

        return $neutral;
    }

    public function toNeutral( array $tree ) : array {
        $out = [];
        foreach ( $tree as $node ) {
            $mapped = $this->mapNode( $node );
            if ( $mapped === null ) {
                continue;
            }
            if ( isset( $mapped[0] ) && is_array( $mapped[0] ) ) {
                $out = array_merge( $out, $mapped );
            } else {
                $out[] = $mapped;
            }
        }
        return $out;
    }

    private function mapNode( array $node ) {
        $tag = $node['tag'] ?? '';

        if ( $tag === 'vc_section' ) {
            return [
                'type'     => 'section',
                'style'    => $this->sectionStyle( $node['attrs'] ?? [] ),
                'children' => $this->toNeutral( $node['children'] ?? [] ),
            ];
        }

        if ( $tag === 'vc_row' || $tag === 'vc_row_inner' ) {
            return [
                'type'     => 'section',
                'style'    => $this->sectionStyle( $node['attrs'] ?? [] ),
                'children' => $this->mapColumns( $node['children'] ?? [] ),
            ];
        }

        if ( $tag === 'vc_column' || $tag === 'vc_column_inner' ) {
            return [
                'type'     => 'column',
                'width'    => $this->columnWidth( $node['attrs'] ?? [] ),
                'children' => $this->toNeutral( $node['children'] ?? [] ),
            ];
        }

        if ( $tag === 'vc_column_text' || $tag === 'vc_text_block' ) {
            $html = $this->innerHTML( $node );
            if ( $html === '' ) {
                return null;
            }
            return [ 'type' => 'text', 'html' => $html ];
        }

        if ( $tag === 'vc_custom_heading' ) {
            $attrs = $node['attrs'] ?? [];
            $text = $attrs['text'] ?? '';
            $level = $this->headingLevelFromFontContainer( $attrs['font_container'] ?? '' );
            $link = $this->parseLink( $attrs['link'] ?? '' );
            if ( $link && $link !== '#' ) {
                $text = '<a href="' . esc_url( $link ) . '">' . $text . '</a>';
            }
            return [ 'type' => 'heading', 'level' => $level, 'text' => wp_kses_post( $text ) ];
        }

        if ( $tag === 'vc_single_image' ) {
            $attrs = $node['attrs'] ?? [];
            $id = intval( $attrs['image'] ?? 0 );
            $url = '';
            if ( ! empty( $attrs['custom_src'] ) ) {
                $url = $attrs['custom_src'];
            } elseif ( $id > 0 ) {
                $url = wp_get_attachment_url( $id ) ?: '';
            }
            return [ 'type' => 'image', 'id' => $id, 'url' => $url ];
        }

        if ( $tag === 'vc_btn' || $tag === 'vc_button' || $tag === 'vc_button2' ) {
            $attrs = $node['attrs'] ?? [];
            return [
                'type' => 'button',
                'text' => $attrs['title'] ?? ( $attrs['text'] ?? 'Button' ),
                'url'  => $this->parseLink( $attrs['link'] ?? ( $attrs['href'] ?? '' ) ),
            ];
        }

        if ( $tag === 'vc_empty_space' ) {
            $height = $node['attrs']['height'] ?? '32px';
            return [ 'type' => 'html', 'html' => '<div style="height:' . esc_attr( $height ) . ';"></div>' ];
        }

        if ( $tag === 'vc_separator' || $tag === 'vc_text_separator' || $tag === 'vc_zigzag' ) {
            return [ 'type' => 'html', 'html' => '<hr />' ];
        }

        if ( $tag === 'vc_raw_html' ) {
            $raw = $this->innerHTML( $node );
            // WPBakery often base64-encodes raw HTML
            $decoded = base64_decode( $raw, true );
            if ( $decoded !== false && $decoded !== '' ) {
                $raw = $decoded;
            }
            return [ 'type' => 'html', 'html' => $raw ];
        }

        if ( $tag === 'vc_video' ) {
            $link = $node['attrs']['link'] ?? '';
            if ( $link ) {
                return [ 'type' => 'html', 'html' => wp_oembed_get( $link ) ?: '<p>' . esc_html( $link ) . '</p>' ];
            }
            return null;
        }

        if ( $tag === 'text' ) {
            $t = trim( $node['text'] ?? '' );
            if ( $t === '' ) {
                return null;
            }
            return [ 'type' => 'html', 'html' => wp_kses_post( $t ) ];
        }

        // Unknown shortcode with children — flatten children
        $children = $this->toNeutral( $node['children'] ?? [] );
        if ( ! empty( $children ) ) {
            return $children;
        }

        $inner = $this->innerHTML( $node );
        if ( $inner !== '' ) {
            return [ 'type' => 'html', 'html' => $inner ];
        }

        if ( ! empty( $node['raw'] ) ) {
            return [ 'type' => 'html', 'html' => $node['raw'] ];
        }

        return null;
    }

    private function mapColumns( array $children ) : array {
        $cols = [];
        foreach ( $children as $ch ) {
            $tag = $ch['tag'] ?? '';
            if ( $tag === 'vc_column' || $tag === 'vc_column_inner' ) {
                $cols[] = [
                    'type'     => 'column',
                    'width'    => $this->columnWidth( $ch['attrs'] ?? [] ),
                    'children' => $this->toNeutral( $ch['children'] ?? [] ),
                ];
            } else {
                $mapped = $this->mapNode( $ch );
                if ( $mapped === null ) {
                    continue;
                }
                if ( isset( $mapped[0] ) && is_array( $mapped[0] ) ) {
                    $cols = array_merge( $cols, $mapped );
                } else {
                    $cols[] = $mapped;
                }
            }
        }
        return $cols;
    }

    private function sectionStyle( array $attrs ) : array {
        $style = [];
        if ( ! empty( $attrs['css'] ) ) {
            $style['css'] = $attrs['css'];
        }
        if ( isset( $attrs['el_id'] ) ) {
            $style['id'] = $attrs['el_id'];
        }
        if ( isset( $attrs['el_class'] ) ) {
            $style['class'] = $attrs['el_class'];
        }
        return $style;
    }

    private function columnWidth( array $attrs ) : int {
        $w = $attrs['width'] ?? '1/1';
        if ( strpos( $w, '/' ) !== false ) {
            $parts = array_map( 'intval', explode( '/', $w ) );
            $a = $parts[0] ?? 1;
            $b = $parts[1] ?? 1;
            if ( $b > 0 ) {
                return (int) round( $a * 100 / $b );
            }
        }
        return 100;
    }

    private function innerHTML( array $node ) : string {
        $content = '';
        foreach ( ( $node['children'] ?? [] ) as $child ) {
            if ( ( $child['tag'] ?? '' ) === 'text' ) {
                $content .= $child['text'] ?? '';
            } elseif ( ! empty( $child['raw'] ) && empty( $child['children'] ) && ( $child['tag'] ?? '' ) !== 'text' ) {
                // Nested shortcodes inside text blocks are uncommon; keep raw as fallback
                $content .= $child['raw'];
            } elseif ( ( $child['tag'] ?? '' ) === 'text' ) {
                $content .= $child['text'] ?? '';
            } else {
                // Recursively gather text from nested text nodes
                $content .= $this->innerHTML( $child );
            }
        }
        return trim( $content );
    }

    private function headingLevelFromFontContainer( string $fontContainer ) : int {
        $parts = explode( '|', $fontContainer );
        foreach ( $parts as $p ) {
            if ( strpos( $p, 'tag:' ) === 0 ) {
                $tag = strtolower( substr( $p, 4 ) );
                $level = intval( str_replace( 'h', '', $tag ) );
                if ( $level >= 1 && $level <= 6 ) {
                    return $level;
                }
            }
        }
        return 2;
    }

    private function parseLink( string $vcLink ) : string {
        if ( $vcLink === '' ) {
            return '#';
        }
        // Already a plain URL
        if ( strpos( $vcLink, 'url:' ) === false && ( strpos( $vcLink, 'http' ) === 0 || strpos( $vcLink, '/' ) === 0 ) ) {
            return $vcLink;
        }
        $parts = explode( '|', $vcLink );
        foreach ( $parts as $p ) {
            if ( strpos( $p, 'url:' ) === 0 ) {
                return rawurldecode( substr( $p, 4 ) );
            }
        }
        return '#';
    }
}
