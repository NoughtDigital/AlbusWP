<?php
namespace Albus\Util;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shortcode tree parser for WPBakery / Divi-style nested shortcodes.
 * Captures attributes and inner text between open/close tags.
 */
class ShortcodeParser {

    /**
     * Shortcodes that are typically self-closing / void (no inner content).
     * WPBakery often writes these without a trailing slash or closing tag.
     */
    private static $void_tags = [
        // WPBakery
        'vc_btn', 'vc_button', 'vc_button2', 'vc_single_image', 'vc_custom_heading',
        'vc_empty_space', 'vc_separator', 'vc_text_separator', 'vc_zigzag',
        'vc_icon', 'vc_video', 'vc_gmaps', 'vc_progress_bar', 'vc_pie',
        'vc_facebook', 'vc_tweetmeme', 'vc_googleplus', 'vc_pinterest',
        'vc_flickr', 'vc_round_chart', 'vc_line_chart', 'vc_wp_search',
        'vc_wp_meta', 'vc_wp_recentcomments', 'vc_wp_calendar', 'vc_wp_pages',
        'vc_wp_tagcloud', 'vc_wp_custommenu', 'vc_wp_text', 'vc_wp_posts',
        'vc_wp_links', 'vc_wp_categories', 'vc_wp_archives', 'vc_wp_rss',
        'vc_gallery', 'vc_images_carousel', 'vc_posts_slider',
        // Divi common voids
        'et_pb_image', 'et_pb_button', 'et_pb_divider', 'et_pb_video',
        'et_pb_blurb', 'et_pb_social_media_follow',
    ];

    public static function parse_tree( string $content ) : array {
        $tokens = self::tokenize( $content );
        $stack = [
            [ 'tag' => '__root__', 'attrs' => [], 'children' => [], 'raw' => '' ],
        ];

        foreach ( $tokens as $t ) {
            $top = count( $stack ) - 1;

            if ( $t['type'] === 'open' ) {
                // Treat known void tags as self-closing even without trailing /
                if ( in_array( $t['tag'], self::$void_tags, true ) ) {
                    $stack[ $top ]['children'][] = [
                        'tag'      => $t['tag'],
                        'attrs'    => self::parse_attrs( $t['attrs'] ),
                        'children' => [],
                        'raw'      => $t['raw'],
                    ];
                    continue;
                }

                $node = [
                    'tag'      => $t['tag'],
                    'attrs'    => self::parse_attrs( $t['attrs'] ),
                    'children' => [],
                    'raw'      => $t['raw'],
                ];
                $stack[] = $node;
            } elseif ( $t['type'] === 'close' ) {
                if ( count( $stack ) <= 1 ) {
                    continue;
                }
                // Pop until matching tag (handles void tags wrongly opened before fix)
                $matched = false;
                for ( $i = count( $stack ) - 1; $i >= 1; $i-- ) {
                    if ( ( $stack[ $i ]['tag'] ?? '' ) === $t['tag'] ) {
                        while ( count( $stack ) - 1 > $i ) {
                            $orphan = array_pop( $stack );
                            $stack[ count( $stack ) - 1 ]['children'][] = $orphan;
                        }
                        $node = array_pop( $stack );
                        $stack[ count( $stack ) - 1 ]['children'][] = $node;
                        $matched = true;
                        break;
                    }
                }
                if ( ! $matched ) {
                    // Ignore unmatched close
                    continue;
                }
            } elseif ( $t['type'] === 'self' ) {
                $stack[ $top ]['children'][] = [
                    'tag'      => $t['tag'],
                    'attrs'    => self::parse_attrs( $t['attrs'] ),
                    'children' => [],
                    'raw'      => $t['raw'],
                ];
            } elseif ( $t['type'] === 'text' ) {
                $text = $t['text'];
                if ( $text === '' ) {
                    continue;
                }
                $stack[ $top ]['children'][] = [
                    'tag'  => 'text',
                    'text' => $text,
                ];
            }
        }

        while ( count( $stack ) > 1 ) {
            $node = array_pop( $stack );
            $stack[ count( $stack ) - 1 ]['children'][] = $node;
        }

        return $stack[0]['children'];
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
            $is_close = ! empty( $m[1][0] );
            $tag = strtolower( $m[2][0] );
            $attrs = trim( $m[3][0] );
            $raw = $m[0][0];
            if ( $is_close ) {
                $out[] = [ 'type' => 'close', 'tag' => $tag, 'attrs' => '', 'raw' => $raw ];
            } else {
                if ( substr( $attrs, -1 ) === '/' ) {
                    $attrs = rtrim( substr( $attrs, 0, -1 ) );
                    $out[] = [ 'type' => 'self', 'tag' => $tag, 'attrs' => $attrs, 'raw' => $raw ];
                } else {
                    $out[] = [ 'type' => 'open', 'tag' => $tag, 'attrs' => $attrs, 'raw' => $raw ];
                }
            }
            $pos = $end;
        }
        if ( $pos < strlen( $content ) ) {
            $out[] = [ 'type' => 'text', 'text' => substr( $content, $pos ) ];
        }
        return $out;
    }

    private static function parse_attrs( string $s ) : array {
        $attrs = [];
        $pattern = '/([a-zA-Z0-9_:-]+)\s*=\s*"([^"]*)"/';
        if ( preg_match_all( $pattern, $s, $m, PREG_SET_ORDER ) ) {
            foreach ( $m as $pair ) {
                $attrs[ strtolower( $pair[1] ) ] = html_entity_decode( $pair[2], ENT_QUOTES );
            }
        }
        return $attrs;
    }
}
