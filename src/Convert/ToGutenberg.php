<?php
namespace Albus\Convert;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class ToGutenberg {

    public function render( array $tree ) : string {
        $blocks = [];
        foreach ( $tree as $node ) {
            $block = $this->toBlock( $node );
            if ( $block ) {
                $blocks[] = $block;
            }
        }

        if ( function_exists( 'serialize_blocks' ) ) {
            return serialize_blocks( $blocks );
        }

        // Fallback manual render
        $html = '';
        foreach ( $tree as $node ) {
            $html .= $this->renderNode( $node );
        }
        return $html;
    }

    private function toBlock( array $n ) {
        switch ( $n['type'] ?? '' ) {
            case 'section':
                return $this->sectionBlock( $n );

            case 'column':
                $inner = [];
                foreach ( ( $n['children'] ?? [] ) as $child ) {
                    $b = $this->toBlock( $child );
                    if ( $b ) {
                        $inner[] = $b;
                    }
                }
                $width = intval( $n['width'] ?? 100 );
                $attrs = [];
                if ( $width > 0 && $width < 100 ) {
                    $attrs['width'] = $width . '%';
                }
                return $this->makeBlock( 'core/column', $attrs, $inner, $this->columnInnerHTML( $inner, $attrs ) );

            case 'heading':
                $level = max( 1, min( 6, intval( $n['level'] ?? 2 ) ) );
                $text = $n['text'] ?? '';
                $html = '<h' . $level . ' class="wp-block-heading">' . $text . '</h' . $level . '>';
                return $this->makeBlock( 'core/heading', [ 'level' => $level ], [], $html );

            case 'text':
                $html = $n['html'] ?? '';
                // Avoid double-wrapping paragraphs
                if ( stripos( trim( $html ), '<p' ) !== 0 ) {
                    $html = '<p>' . $html . '</p>';
                }
                return $this->makeBlock( 'core/paragraph', [], [], $html );

            case 'image':
                return $this->imageBlock( $n );

            case 'button':
                return $this->buttonBlock( $n );

            case 'html':
            default:
                $content = $n['html'] ?? '';
                if ( $content === '' ) {
                    return null;
                }
                return $this->makeBlock( 'core/html', [], [], $content );
        }
    }

    private function sectionBlock( array $n ) {
        $children = $n['children'] ?? [];
        $hasColumns = false;
        foreach ( $children as $child ) {
            if ( ( $child['type'] ?? '' ) === 'column' ) {
                $hasColumns = true;
                break;
            }
        }

        if ( $hasColumns ) {
            $cols = [];
            foreach ( $children as $child ) {
                if ( ( $child['type'] ?? '' ) === 'column' ) {
                    $b = $this->toBlock( $child );
                    if ( $b ) {
                        $cols[] = $b;
                    }
                } else {
                    // Wrap non-column in a full-width column
                    $inner = $this->toBlock( $child );
                    if ( $inner ) {
                        $cols[] = $this->makeBlock( 'core/column', [], [ $inner ], $this->wrapInner( [ $inner ], 'wp-block-column' ) );
                    }
                }
            }
            $columns = $this->makeBlock( 'core/columns', [], $cols, $this->wrapInner( $cols, 'wp-block-columns' ) );
            return $this->makeBlock( 'core/group', [], [ $columns ], $this->wrapInner( [ $columns ], 'wp-block-group' ) );
        }

        $inner = [];
        foreach ( $children as $child ) {
            $b = $this->toBlock( $child );
            if ( $b ) {
                $inner[] = $b;
            }
        }
        return $this->makeBlock( 'core/group', [], $inner, $this->wrapInner( $inner, 'wp-block-group' ) );
    }

    private function imageBlock( array $n ) {
        $id = intval( $n['id'] ?? 0 );
        $url = $n['url'] ?? '';
        $attrs = [];
        if ( $id ) {
            $attrs['id'] = $id;
        }
        if ( $url ) {
            $attrs['url'] = $url;
        }
        $img = '';
        if ( $id ) {
            $img = wp_get_attachment_image( $id, 'large' );
            if ( empty( $url ) ) {
                $url = wp_get_attachment_url( $id ) ?: '';
                if ( $url ) {
                    $attrs['url'] = $url;
                }
            }
        }
        if ( empty( $img ) && $url ) {
            $img = '<img src="' . esc_url( $url ) . '" alt="" />';
        }
        if ( empty( $img ) ) {
            $img = '<img alt="" />';
        }
        $html = '<figure class="wp-block-image">' . $img . '</figure>';
        return $this->makeBlock( 'core/image', $attrs, [], $html );
    }

    private function buttonBlock( array $n ) {
        $url = $n['url'] ?? '#';
        $text = $n['text'] ?? 'Button';
        $buttonHtml = '<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a></div>';
        $button = $this->makeBlock( 'core/button', [
            'url'  => $url,
            'text' => $text,
        ], [], $buttonHtml );
        $buttonsHtml = '<div class="wp-block-buttons">' . $buttonHtml . '</div>';
        // Rebuild with proper innerContent null slot
        return [
            'blockName'    => 'core/buttons',
            'attrs'        => [],
            'innerBlocks'  => [ $button ],
            'innerHTML'    => $buttonsHtml,
            'innerContent' => [ '<div class="wp-block-buttons">', null, '</div>' ],
        ];
    }

    private function makeBlock( string $name, array $attrs, array $innerBlocks, string $innerHTML ) : array {
        $innerContent = [];
        if ( empty( $innerBlocks ) ) {
            $innerContent = [ $innerHTML ];
        } else {
            // Best-effort: wrapper with null slots — serialize_blocks prefers this
            $innerContent = $this->buildInnerContent( $innerHTML, count( $innerBlocks ) );
        }
        return [
            'blockName'    => $name,
            'attrs'        => $attrs,
            'innerBlocks'  => $innerBlocks,
            'innerHTML'    => $innerHTML,
            'innerContent' => $innerContent,
        ];
    }

    private function buildInnerContent( string $html, int $childCount ) : array {
        // Simple strategy: open tag, nulls, close tag if div wrapper present
        if ( preg_match( '/^(<[a-z0-9]+[^>]*>)(.*)(<\/[a-z0-9]+>)$/is', trim( $html ), $m ) ) {
            $content = [ $m[1] ];
            for ( $i = 0; $i < $childCount; $i++ ) {
                $content[] = null;
            }
            $content[] = $m[3];
            return $content;
        }
        $content = [];
        for ( $i = 0; $i < $childCount; $i++ ) {
            $content[] = null;
        }
        return $content;
    }

    private function wrapInner( array $blocks, string $class ) : string {
        return '<div class="' . esc_attr( $class ) . '"></div>';
    }

    private function columnInnerHTML( array $inner, array $attrs ) : string {
        $style = '';
        if ( ! empty( $attrs['width'] ) ) {
            $style = ' style="flex-basis:' . esc_attr( $attrs['width'] ) . '"';
        }
        return '<div class="wp-block-column"' . $style . '></div>';
    }

    /** Manual fallback when serialize_blocks is unavailable */
    private function renderNode( array $n ) : string {
        switch ( $n['type'] ?? '' ) {
            case 'section':
                $inner = '';
                $hasColumns = false;
                foreach ( ( $n['children'] ?? [] ) as $child ) {
                    if ( ( $child['type'] ?? '' ) === 'column' ) {
                        $hasColumns = true;
                    }
                }
                if ( $hasColumns ) {
                    $innerCols = '';
                    foreach ( ( $n['children'] ?? [] ) as $col ) {
                        if ( ( $col['type'] ?? '' ) !== 'column' ) {
                            continue;
                        }
                        $w = intval( $col['width'] ?? 100 );
                        $attrs = ( $w > 0 && $w < 100 ) ? ' {"width":"' . $w . '%"}' : '';
                        $style = ( $w > 0 && $w < 100 ) ? ' style="flex-basis:' . $w . '%"' : '';
                        $innerCols .= '<!-- wp:column' . $attrs . ' --><div class="wp-block-column"' . $style . '>';
                        foreach ( ( $col['children'] ?? [] ) as $child ) {
                            $innerCols .= $this->renderNode( $child );
                        }
                        $innerCols .= '</div><!-- /wp:column -->';
                    }
                    $inner = '<!-- wp:columns --><div class="wp-block-columns">' . $innerCols . '</div><!-- /wp:columns -->';
                } else {
                    foreach ( ( $n['children'] ?? [] ) as $child ) {
                        $inner .= $this->renderNode( $child );
                    }
                }
                return '<!-- wp:group --><div class="wp-block-group">' . $inner . '</div><!-- /wp:group -->';

            case 'heading':
                $level = max( 1, min( 6, intval( $n['level'] ?? 2 ) ) );
                return sprintf( '<!-- wp:heading {"level":%d} --><h%d class="wp-block-heading">%s</h%d><!-- /wp:heading -->', $level, $level, $n['text'] ?? '', $level );

            case 'text':
                $html = $n['html'] ?? '';
                if ( stripos( trim( $html ), '<p' ) !== 0 ) {
                    $html = '<p>' . $html . '</p>';
                }
                return '<!-- wp:paragraph -->' . $html . '<!-- /wp:paragraph -->';

            case 'image':
                $id = intval( $n['id'] ?? 0 );
                $url = $n['url'] ?? '';
                $attrs = [];
                if ( $id ) {
                    $attrs[] = '"id":' . $id;
                }
                if ( $url ) {
                    $attrs[] = '"url":"' . esc_url( $url ) . '"';
                }
                $attrStr = $attrs ? ' {' . implode( ',', $attrs ) . '}' : '';
                $img = $id ? wp_get_attachment_image( $id, 'large' ) : '';
                if ( empty( $img ) && $url ) {
                    $img = '<img src="' . esc_url( $url ) . '" alt="" />';
                }
                if ( empty( $img ) ) {
                    $img = '<img alt="" />';
                }
                return '<!-- wp:image' . $attrStr . ' --><figure class="wp-block-image">' . $img . '</figure><!-- /wp:image -->';

            case 'button':
                $url = esc_url( $n['url'] ?? '#' );
                $text = esc_html( $n['text'] ?? 'Button' );
                return '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="' . $url . '">' . $text . '</a></div><!-- /wp:button --></div><!-- /wp:buttons -->';

            case 'html':
            default:
                return '<!-- wp:html -->' . ( $n['html'] ?? '' ) . '<!-- /wp:html -->';
        }
    }
}
