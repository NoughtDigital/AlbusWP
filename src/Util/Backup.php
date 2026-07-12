<?php
namespace Albus\Util;

use Albus\Detect\Detector;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Shared backup / cleanup helpers for import writers.
 *
 * Safety model:
 * - Always snapshot before any write
 * - Keep an append-only site conversion log
 * - Archive originals before in-place overwrite (never for safe drafts — original is untouched)
 * - Do not auto-delete conversion archives aggressively
 */
class Backup {

    const META_KEYS = [
        '_elementor_data',
        '_elementor_edit_mode',
        '_elementor_version',
        '_elementor_template_type',
        '_elementor_page_settings',
        '_elementor_css',
        '_bricks_page_content_2',
        '_bricks_page_header_2',
        '_bricks_page_footer_2',
        '_bricks_data',
        '_bricks_editor_mode',
        '_wpb_vc_js_status',
        '_wpb_shortcodes_custom_css',
        '_wpb_post_custom_css',
        '_wpb_post_custom_js_header',
        '_wpb_post_custom_js_footer',
    ];

    /**
     * Snapshot post_content and known builder meta before a conversion wipe.
     */
    public static function snapshot( int $post_id, string $target, string $mode = 'safe' ) : void {
        $source = Detector::detect_source_for_post( $post_id );

        $payload = [
            'date'         => current_time( 'mysql' ),
            'type'         => $target,
            'mode'         => $mode,
            'source'       => $source,
            'post_content' => get_post_field( 'post_content', $post_id ),
            'post_status'  => get_post_status( $post_id ),
            'post_title'   => get_the_title( $post_id ),
            'meta'         => [],
        ];

        foreach ( self::META_KEYS as $key ) {
            $val = get_post_meta( $post_id, $key, true );
            if ( $val !== '' && $val !== null && $val !== false ) {
                $payload['meta'][ $key ] = $val;
            }
        }

        // Keep legacy single-key backups for older restore UI
        if ( ! empty( $payload['post_content'] ) ) {
            update_post_meta( $post_id, '_albus_backup_post_content', $payload['post_content'] );
        }
        if ( ! empty( $payload['meta']['_bricks_page_content_2'] ) ) {
            update_post_meta( $post_id, '_albus_backup__bricks_data', $payload['meta']['_bricks_page_content_2'] );
        } elseif ( ! empty( $payload['meta']['_bricks_data'] ) ) {
            update_post_meta( $post_id, '_albus_backup__bricks_data', $payload['meta']['_bricks_data'] );
        }
        if ( ! empty( $payload['meta']['_elementor_data'] ) ) {
            update_post_meta( $post_id, '_albus_backup__elementor_data', $payload['meta']['_elementor_data'] );
        }

        update_post_meta( $post_id, '_albus_backup_full', $payload );
        update_post_meta( $post_id, '_albus_backup_meta', wp_json_encode([
            'date'   => $payload['date'],
            'type'   => $target,
            'mode'   => $mode,
            'source' => $source,
        ]) );

        // Append-only history (does not overwrite previous snapshots)
        $history = get_post_meta( $post_id, '_albus_backup_history', true );
        if ( ! is_array( $history ) ) {
            $history = [];
        }
        $history[] = $payload;
        // Cap history at 20 entries per post
        if ( count( $history ) > 20 ) {
            $history = array_slice( $history, -20 );
        }
        update_post_meta( $post_id, '_albus_backup_history', $history );

        self::log_conversion([
            'event'   => 'snapshot',
            'post_id' => $post_id,
            'target'  => $target,
            'mode'    => $mode,
            'source'  => $source,
            'date'    => $payload['date'],
        ]);
    }

    /**
     * Archive the live original before an in-place overwrite (extra safety layer).
     * Stores a frozen copy under a site option keyed by post ID + timestamp.
     */
    public static function archive_original( int $post_id, string $target ) : string {
        $archive_id = $post_id . '_' . time();
        $payload = [
            'archive_id'   => $archive_id,
            'post_id'      => $post_id,
            'target'       => $target,
            'date'         => current_time( 'mysql' ),
            'post_content' => get_post_field( 'post_content', $post_id ),
            'post_status'  => get_post_status( $post_id ),
            'post_title'   => get_the_title( $post_id ),
            'meta'         => [],
        ];

        foreach ( self::META_KEYS as $key ) {
            $val = get_post_meta( $post_id, $key, true );
            if ( $val !== '' && $val !== null && $val !== false ) {
                $payload['meta'][ $key ] = $val;
            }
        }

        $archives = get_option( 'albus_original_archives', [] );
        if ( ! is_array( $archives ) ) {
            $archives = [];
        }
        $archives[ $archive_id ] = $payload;
        update_option( 'albus_original_archives', $archives, false );

        update_post_meta( $post_id, '_albus_last_archive_id', $archive_id );

        self::log_conversion([
            'event'      => 'archive_original',
            'post_id'    => $post_id,
            'archive_id' => $archive_id,
            'target'     => $target,
            'date'       => $payload['date'],
        ]);

        return $archive_id;
    }

    /**
     * Restore from full backup snapshot when available.
     */
    public static function restore( int $post_id ) : bool {
        $full = get_post_meta( $post_id, '_albus_backup_full', true );
        if ( is_array( $full ) && ! empty( $full ) ) {
            if ( isset( $full['post_content'] ) ) {
                wp_update_post([
                    'ID'           => $post_id,
                    'post_content' => $full['post_content'],
                ]);
            }

            self::clear_builder_meta( $post_id );

            foreach ( ( $full['meta'] ?? [] ) as $key => $val ) {
                update_post_meta( $post_id, $key, $val );
            }

            self::log_conversion([
                'event'   => 'restore',
                'post_id' => $post_id,
                'date'    => current_time( 'mysql' ),
            ]);

            // Keep backup meta for audit — do not delete history
            return true;
        }

        // Legacy restore paths
        $restored = false;
        $gutenberg = get_post_meta( $post_id, '_albus_backup_post_content', true );
        if ( ! empty( $gutenberg ) ) {
            wp_update_post([ 'ID' => $post_id, 'post_content' => $gutenberg ]);
            $restored = true;
        }

        $bricks = get_post_meta( $post_id, '_albus_backup__bricks_data', true );
        if ( ! empty( $bricks ) ) {
            update_post_meta( $post_id, '_bricks_page_content_2', $bricks );
            $restored = true;
        }

        $elementor = get_post_meta( $post_id, '_albus_backup__elementor_data', true );
        if ( ! empty( $elementor ) ) {
            update_post_meta( $post_id, '_elementor_data', $elementor );
            update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
            $restored = true;
        }

        if ( $restored ) {
            self::log_conversion([
                'event'   => 'restore_legacy',
                'post_id' => $post_id,
                'date'    => current_time( 'mysql' ),
            ]);
        }

        return $restored;
    }

    public static function clear_builder_meta( int $post_id ) : void {
        $keys = [
            '_elementor_data',
            '_elementor_edit_mode',
            '_elementor_version',
            '_elementor_css',
            '_bricks_page_content_2',
            '_bricks_data',
            '_bricks_editor_mode',
            '_bricks_is_shortcode',
            '_wpb_vc_js_status',
            '_wpb_shortcodes_custom_css',
        ];
        foreach ( $keys as $key ) {
            delete_post_meta( $post_id, $key );
        }
    }

    public static function log_conversion( array $entry ) : void {
        $log = get_option( 'albus_conversion_log', [] );
        if ( ! is_array( $log ) ) {
            $log = [];
        }
        $log[] = $entry;
        if ( count( $log ) > 500 ) {
            $log = array_slice( $log, -500 );
        }
        update_option( 'albus_conversion_log', $log, false );
    }
}
