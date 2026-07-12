<?php
namespace Albus\Util;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Creates a draft duplicate of a post for safe conversion.
 * The live original is never modified by this class.
 */
class SafeDuplicate {

    /**
     * Duplicate a post as a private draft with content, meta, and taxonomies copied.
     *
     * @return int|\WP_Error New post ID or error.
     */
    public static function create( int $source_id, string $target ) {
        $source = get_post( $source_id );
        if ( ! $source || $source->post_status === 'trash' ) {
            return new \WP_Error( 'albus_missing_post', 'Source post not found.' );
        }

        $label = self::target_label( $target );
        $title = $source->post_title . ' (Albus → ' . $label . ' draft)';

        $new_id = wp_insert_post([
            'post_title'     => $title,
            'post_content'   => $source->post_content,
            'post_excerpt'   => $source->post_excerpt,
            'post_status'    => 'draft',
            'post_type'      => $source->post_type,
            'post_author'    => get_current_user_id() ?: $source->post_author,
            'post_parent'    => $source->post_parent,
            'menu_order'     => $source->menu_order,
            'comment_status' => $source->comment_status,
            'ping_status'    => $source->ping_status,
            // No post_name — draft slug stays out of public URLs
        ], true );

        if ( is_wp_error( $new_id ) ) {
            return $new_id;
        }

        self::copy_all_meta( $source_id, $new_id );
        self::copy_taxonomies( $source_id, $new_id );

        // Link draft ↔ original
        update_post_meta( $new_id, '_albus_source_post_id', $source_id );
        update_post_meta( $new_id, '_albus_conversion_target', $target );
        update_post_meta( $new_id, '_albus_safe_mode', '1' );
        update_post_meta( $new_id, '_albus_created_at', current_time( 'mysql' ) );

        $drafts = get_post_meta( $source_id, '_albus_safe_drafts', true );
        if ( ! is_array( $drafts ) ) {
            $drafts = [];
        }
        $drafts[] = [
            'draft_id' => $new_id,
            'target'   => $target,
            'date'     => current_time( 'mysql' ),
        ];
        update_post_meta( $source_id, '_albus_safe_drafts', $drafts );
        update_post_meta( $source_id, '_albus_last_safe_draft', $new_id );

        Logger::info( 'Safe draft created', [
            'source_id' => $source_id,
            'draft_id'  => $new_id,
            'target'    => $target,
        ]);

        return $new_id;
    }

    private static function copy_all_meta( int $from, int $to ) : void {
        $all = get_post_meta( $from );
        if ( ! is_array( $all ) ) {
            return;
        }

        $skip = [
            '_edit_lock',
            '_edit_last',
            '_albus_backup_full',
            '_albus_backup_post_content',
            '_albus_backup__bricks_data',
            '_albus_backup__elementor_data',
            '_albus_backup_meta',
            '_albus_safe_drafts',
            '_albus_last_safe_draft',
            '_albus_source_post_id',
            '_albus_conversion_target',
            '_albus_safe_mode',
        ];

        foreach ( $all as $key => $values ) {
            if ( in_array( $key, $skip, true ) ) {
                continue;
            }
            // Skip Albus runtime keys
            if ( strpos( $key, '_albus_' ) === 0 ) {
                continue;
            }
            foreach ( (array) $values as $value ) {
                // get_post_meta without single returns maybe_unserialized already in WP? 
                // Actually values from get_post_meta($id) are raw from DB (serialized strings).
                $decoded = maybe_unserialize( $value );
                add_post_meta( $to, $key, $decoded );
            }
        }
    }

    private static function copy_taxonomies( int $from, int $to ) : void {
        $taxonomies = get_object_taxonomies( get_post_type( $from ) );
        foreach ( $taxonomies as $taxonomy ) {
            $terms = wp_get_object_terms( $from, $taxonomy, [ 'fields' => 'ids' ] );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                wp_set_object_terms( $to, $terms, $taxonomy );
            }
        }
    }

    private static function target_label( string $target ) : string {
        $map = [
            'gutenberg' => 'Gutenberg',
            'elementor' => 'Elementor',
            'bricks'    => 'Bricks',
            'wpbakery'  => 'WPBakery',
        ];
        return $map[ $target ] ?? ucfirst( $target );
    }
}
