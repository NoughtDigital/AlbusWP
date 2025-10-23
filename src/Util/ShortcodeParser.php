<?php
namespace Albus\Util;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Extremely simplified shortcode tree parser for VC. Prototype only.
 * For production, replace with robust tokenizer.
 */
class ShortcodeParser {

    public static function parse_tree( string $content ) : array {
        $tokens = self::tokenize( $content );
        $stack = [[]];
        foreach ( $tokens as $t ) {
            if ( $t['type'] === 'open' ) {
                $node = [ 'tag' => $t['tag'], 'attrs' => self::parse_attrs($t['attrs']), 'children' => [], 'raw' => $t['raw'] ];
                array_push( $stack, $node );
            } elseif ( $t['type'] === 'close' ) {
                $node = array_pop( $stack );
                if ( ! empty( $node ) ) {
                    $top_index = count($stack)-1;
                    $stack[$top_index][] = $node;
                }
            } elseif ( $t['type'] === 'self' ) {
                $stack[count($stack)-1][] = [ 'tag' => $t['tag'], 'attrs' => self::parse_attrs($t['attrs']), 'children' => [], 'raw' => $t['raw'] ];
            } elseif ( $t['type'] === 'text' ) {
                $stack[count($stack)-1][] = [ 'tag' => 'text', 'text' => $t['text'] ];
            }
        }
        return $stack[0];
    }

    private static function tokenize( string $content ) : array {
        $out = [];
        $pattern = '/\[(\/)?([a-zA-Z0-9_]+)([^\]]*)\]/';
        $pos = 0;
        while ( preg_match( $pattern, $content, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
            $start = $m[0][1];
            $end   = $start + strlen( $m[0][0] );
            if ( $start > $pos ) {
                $out[] = [ 'type' => 'text', 'text' => substr( $content, $pos, $start - $pos ) ];
            }
            $is_close = !empty($m[1][0]);
            $tag = strtolower($m[2][0]);
            $attrs = trim($m[3][0]);
            $raw = $m[0][0];
            if ( $is_close ) {
                $out[] = [ 'type' => 'close', 'tag' => $tag, 'attrs' => '', 'raw' => $raw ];
            } else {
                // Detect self-closing like [vc_single_image ... /]
                if ( substr( $attrs, -1 ) === '/' ) {
                    $attrs = rtrim( substr( $attrs, 0, -1 ) );
                    $out[] = [ 'type' => 'self', 'tag' => $tag, 'attrs' => $attrs, 'raw' => $raw ];
                } else {
                    $out[] = [ 'type' => 'open', 'tag' => $tag, 'attrs' => $attrs, 'raw' => $raw ];
                }
            }
            $pos = $end;
        }
        if ( $pos < strlen($content) ) {
            $out[] = [ 'type' => 'text', 'text' => substr( $content, $pos ) ];
        }
        return $out;
    }

    private static function parse_attrs( string $s ) : array {
        $attrs = [];
        $pattern = '/([a-zA-Z0-9_:-]+)\s*=\s*"([^"]*)"/';
        if ( preg_match_all( $pattern, $s, $m, PREG_SET_ORDER ) ) {
            foreach ( $m as $pair ) {
                $attrs[ strtolower($pair[1]) ] = html_entity_decode( $pair[2], ENT_QUOTES );
            }
        }
        return $attrs;
    }
}
