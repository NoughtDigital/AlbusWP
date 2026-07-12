<?php
namespace Albus\Admin;

use Albus\Detect\Detector;
use Albus\Extract\WPBakery;
use Albus\Extract\Elementor;
use Albus\Extract\Divi;
use Albus\Extract\Kirki;
use Albus\Extract\ClassicEditor;
use Albus\Extract\Gutenberg;
use Albus\Extract\Bricks as BricksExtractor;
use Albus\Convert\ToGutenberg;
use Albus\Convert\ToBricks;
use Albus\Convert\ToWPBakery;
use Albus\Convert\ToElementor;
use Albus\Import\GutenbergWriter;
use Albus\Import\BricksWriter;
use Albus\Import\WPBakeryWriter;
use Albus\Import\ElementorWriter;
use Albus\Util\Logger;
use Albus\Util\Backup;
use Albus\Util\SafeDuplicate;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class AdminPage {

    public static function init() {
        add_menu_page(
            __( 'Albus', 'albus' ),
            __( 'Albus', 'albus' ),
            'manage_options',
            'albus',
            [ __CLASS__, 'render' ],
            'dashicons-art',
            58
        );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
    }

    public static function assets( $hook ) {
        if ( $hook !== 'toplevel_page_albus' ) return;
        wp_enqueue_style( 'albus-admin', ALBUS_URL . 'assets/admin.css', [], ALBUS_VERSION );
        wp_enqueue_script( 'albus-admin', ALBUS_URL . 'assets/admin.js', ['jquery'], ALBUS_VERSION, true );
        wp_localize_script( 'albus-admin', 'Albus', [
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'rest'  => esc_url_raw( rest_url( 'albus/v1' ) ),
            'upgradeUrl' => esc_url( albus_get_upgrade_url() ),
            'isPro' => ALBUS_IS_PRO,
        ]);
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $log_file = ALBUS_PATH . 'albus-debug.log';
        $log_exists = file_exists( $log_file );
        ?>
        <div class="wrap albus">
            <h1>Albus - Page Builder Converter</h1>
            
            <div class="albus-tabs">
                <button class="albus-tab active" data-tab="scan">Scan & Convert</button>
                <button class="albus-tab" data-tab="backups">Backups</button>
                <button class="albus-tab" data-tab="help">Help</button>
            </div>
            
            <div class="albus-tab-content" id="tab-scan">
                <div class="albus-warning-box" style="border-left:4px solid #00a32a;background:#edfaef;">
                    <h3 style="margin-top:0;">Safe mode (default)</h3>
                    <p><strong>Albus never overwrites your live page unless you explicitly force it.</strong></p>
                    <ul>
                        <li>Conversion creates a <strong>draft duplicate</strong> of each page/post</li>
                        <li>The original stays published and untouched</li>
                        <li>You review the draft in Gutenberg / Elementor / Bricks, then cut over manually when ready</li>
                        <li>Every write is snapshotted; in-place mode also archives the original to a site archive log</li>
                    </ul>
                </div>

                <div class="albus-info-box">
                    <p><strong>What does Albus do?</strong> Converts page builder content between Gutenberg, WPBakery, Elementor, and Bricks — safely via draft copies by default.</p>
                    <?php if ( ! ALBUS_IS_PRO ) : ?>
                    <p><strong>FREE Version:</strong> Scan up to <?php echo (int) ALBUS_FREE_SCAN_LIMIT; ?> pages and convert up to <?php echo (int) ALBUS_FREE_CONVERT_LIMIT; ?> pages. <a href="<?php echo esc_url( albus_get_upgrade_url() ); ?>" class="button button-primary">Upgrade to PRO</a></p>
                    <?php endif; ?>
                    <p style="margin-bottom:0;">
                        <label>
                            <input type="checkbox" id="albus-inplace-mode" value="1" />
                            <strong>Dangerous:</strong> overwrite live posts in place (requires typing OVERWRITE LIVE)
                        </label>
                    </p>
                </div>
                
                <div class="albus-actions">
                    <button class="button button-primary" id="albus-scan">Scan Site</button>
                    <?php if ( $log_exists ) : ?>
                        <a href="<?php echo esc_url( ALBUS_URL . 'albus-debug.log' ); ?>" target="_blank" class="button">View Debug Log</a>
                    <?php endif; ?>
                </div>
                
                <div id="albus-bulk-actions" style="display:none;margin-top:1rem;">
                    <h3>Bulk Actions (safe drafts)</h3>
                    <p class="description">Bulk always creates draft copies. Live pages are not changed.</p>
                    <button class="button" id="albus-bulk-gutenberg">Draft-convert all → Gutenberg</button>
                    <button class="button" id="albus-bulk-wpbakery">Draft-convert all → WPBakery</button>
                    <button class="button" id="albus-bulk-elementor">Draft-convert all → Elementor</button>
                    <button class="button button-primary" id="albus-bulk-bricks">Draft-convert all → Bricks</button>
                    <div id="albus-bulk-progress" style="display:none;margin-top:1rem;">
                        <div class="albus-progress-bar">
                            <div class="albus-progress-fill"></div>
                        </div>
                        <p class="albus-progress-text"></p>
                    </div>
                </div>
                
                <div id="albus-results"></div>
            </div>
            
            <div class="albus-tab-content" id="tab-backups" style="display:none;">
                <div class="albus-info-box">
                    <h3 style="margin-top:0;">About Backups</h3>
                    <p>Albus automatically creates backups before converting any post. You can restore posts to their original state at any time.</p>
                    <ul>
                        <li>Backups are stored as post metadata in your database</li>
                        <li>Old backups (30+ days) are automatically cleaned up</li>
                        <li>You can manually restore individual posts or clean up old backups</li>
                    </ul>
                </div>
                <button class="button button-primary" id="albus-refresh-backups">Refresh Backups</button>
                <div id="albus-backups-list" style="margin-top:1rem;"></div>
            </div>
            
            <div class="albus-tab-content" id="tab-help" style="display:none;">
                <div class="albus-info-box">
                    <h3>How to Use Albus (safe workflow)</h3>
                    <ol>
                        <li><strong>Scan:</strong> Find pages using Gutenberg, WPBakery, Elementor, Bricks, Divi, or Classic</li>
                        <li><strong>Preview:</strong> Inspect converted output without writing anything</li>
                        <li><strong>Draft convert:</strong> Creates a draft copy — the live page stays untouched</li>
                        <li><strong>Review:</strong> Open the draft in the target builder and check the layout</li>
                        <li><strong>Cut over manually:</strong> When happy, publish the draft / replace the original yourself</li>
                    </ol>
                    <p>In-place overwrite is optional, off by default, and requires typing <code>OVERWRITE LIVE</code>.</p>
                </div>
                
                <div class="albus-info-box">
                    <h3>Bulk Conversion</h3>
                    <p>After scanning, use bulk actions to convert all listed posts to one target. Test a few posts first.</p>
                </div>
                
                <?php if ( ! ALBUS_IS_PRO ) : ?>
                <div class="albus-info-box" style="background:#f0f9ff;border-left:4px solid #3b82f6;">
                    <h3>Upgrade to PRO</h3>
                    <p><strong>Remove limits and unlock every conversion path.</strong></p>
                    <ul>
                        <li><strong>Unlimited</strong> scans and conversions</li>
                        <li><strong>Bulk conversion</strong> for whole sites</li>
                        <li>Elementor and Bricks as sources and targets</li>
                        <li>Full bidirectional paths between all four builders</li>
                        <li>Priority support</li>
                    </ul>
                    <p><a href="<?php echo esc_url( albus_get_upgrade_url() ); ?>" class="button button-primary button-large">Upgrade to AlbusWP PRO</a></p>
                </div>
                <?php endif; ?>
                
                <div class="albus-warning-box">
                    <h3>Important Notes</h3>
                    <ul>
                        <li>Conversions write directly to the database (with automatic backups)</li>
                        <li>Always keep a full database backup before bulk migrations</li>
                        <li>Complex layouts and custom CSS may need manual polish after conversion</li>
                    </ul>
                </div>
                
                <div class="albus-info-box">
                    <h3>Free vs PRO Features</h3>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                        <div>
                            <strong>FREE Version:</strong>
                            <ul>
                                <li>WPBakery / Divi / Classic / Kirki → Gutenberg</li>
                                <li>Gutenberg → Bricks or WPBakery</li>
                                <li>Scan up to <?php echo (int) ALBUS_FREE_SCAN_LIMIT; ?> pages</li>
                                <li>Convert up to <?php echo (int) ALBUS_FREE_CONVERT_LIMIT; ?> pages</li>
                                <li>Automatic backups and restore</li>
                            </ul>
                        </div>
                        <div>
                            <strong>PRO Version:</strong>
                            <ul>
                                <li>Unlimited scans and conversions</li>
                                <li>All paths: Gutenberg, WPBakery, Elementor, Bricks</li>
                                <li>Bulk conversion</li>
                                <li>Elementor as source and target</li>
                                <li>Priority support</li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <div class="albus-info-box">
                    <h3>Troubleshooting</h3>
                    <p><strong>Check the logs:</strong> <?php if ( $log_exists ) : ?><a href="<?php echo esc_url( ALBUS_URL . 'albus-debug.log' ); ?>" target="_blank">View Debug Log</a><?php else : ?>Debug log will appear after first conversion<?php endif; ?></p>
                    <ul>
                        <li>Bricks output stores data in <code>_bricks_page_content_2</code> — open the page in the Bricks editor to verify</li>
                        <li>Elementor output stores JSON in <code>_elementor_data</code> — CSS regenerates on next edit</li>
                        <li>Missing content? Use Debug Data on the scan card, then Export JSON</li>
                    </ul>
                </div>
                
                <div class="albus-stats">
                    <div class="albus-stat-card">
                        <span class="albus-stat-number" id="stat-converted">0</span>
                        <span class="albus-stat-label">Posts Converted</span>
                    </div>
                    <div class="albus-stat-card">
                        <span class="albus-stat-number" id="stat-backups">0</span>
                        <span class="albus-stat-label">Backups Available</span>
                    </div>
                </div>
            </div>
        </div>
        
        <div id="albus-preview-modal" class="albus-modal" style="display:none;">
            <div class="albus-modal-content">
                <span class="albus-modal-close">&times;</span>
                <h2>Preview Conversion</h2>
                <div class="albus-preview-info"></div>
                <div class="albus-preview-body">
                    <pre><code></code></pre>
                </div>
                <div class="albus-modal-actions">
                    <button class="button button-primary" id="albus-confirm-convert">Create safe draft</button>
                    <button class="button" id="albus-cancel-preview">Cancel</button>
                </div>
            </div>
        </div>
        <?php
    }

    public static function preview_post( int $post_id, string $target ) {
        Logger::info( 'Generating preview', [ 'post_id' => $post_id, 'target' => $target ] );
        
        try {
            $source = Detector::detect_source_for_post( $post_id );
            if ( ! $source ) {
                return [ 'ok' => false, 'message' => 'No supported builder content found.' ];
            }
            
            // Check if this specific conversion is allowed
            $allowed = albus_is_conversion_allowed( $source, $target );
            if ( ! $allowed['allowed'] ) {
                return [ 
                    'ok' => false, 
                    'message' => $allowed['message'],
                    'requires_pro' => true 
                ];
            }
            
            // Extract to neutral tree
            $neutral = self::extract_content( $post_id, $source );
            
            if ( empty( $neutral ) ) {
                return [ 'ok' => false, 'message' => 'No content extracted from ' . $source ];
            }
            
            // Generate preview based on target
            $preview = '';
            if ( $target === 'gutenberg' ) {
                $preview = ( new ToGutenberg() )->render( $neutral );
            } elseif ( $target === 'bricks' ) {
                $json = ( new ToBricks() )->build( $neutral );
                $preview = wp_json_encode( $json, JSON_PRETTY_PRINT );
            } elseif ( $target === 'wpbakery' ) {
                $preview = ( new ToWPBakery() )->render( $neutral );
            } elseif ( $target === 'elementor' ) {
                $json = ( new ToElementor() )->build( $neutral );
                $preview = wp_json_encode( $json, JSON_PRETTY_PRINT );
            } else {
                return [ 'ok' => false, 'message' => 'Unknown target: ' . $target ];
            }
            
            return [
                'ok' => true,
                'post_id' => $post_id,
                'source' => $source,
                'target' => $target,
                'preview' => $preview,
                'element_count' => count($neutral)
            ];
            
        } catch ( \Exception $e ) {
            Logger::error( 'Preview generation failed', [ 
                'post_id' => $post_id, 
                'error' => $e->getMessage() 
            ]);
            return [ 'ok' => false, 'message' => $e->getMessage() ];
        }
    }

    public static function convert_post( int $post_id, string $target, string $mode = 'safe', string $confirm_inplace = '' ) {
        Logger::info( 'Starting conversion', [
            'post_id' => $post_id,
            'target'  => $target,
            'mode'    => $mode,
        ]);

        try {
            $mode = ( $mode === 'inplace' ) ? 'inplace' : 'safe';

            if ( $mode === 'inplace' ) {
                if ( $confirm_inplace !== 'OVERWRITE LIVE' ) {
                    return [
                        'ok'      => false,
                        'message' => 'In-place overwrite blocked. Type OVERWRITE LIVE to confirm, or leave safe mode (draft duplicate) enabled.',
                        'blocked' => true,
                    ];
                }
            }

            if ( ! albus_can_convert() ) {
                $limit = ALBUS_FREE_CONVERT_LIMIT;
                return [
                    'ok'             => false,
                    'message'        => "Free version limit reached! You've converted {$limit} pages. Upgrade to PRO for unlimited conversions.",
                    'requires_pro'   => true,
                    'limit_reached'  => true,
                ];
            }

            $source = Detector::detect_source_for_post( $post_id );
            if ( ! $source ) {
                return [ 'ok' => false, 'message' => 'No supported builder content found.' ];
            }

            $allowed = albus_is_conversion_allowed( $source, $target );
            if ( ! $allowed['allowed'] ) {
                return [
                    'ok'           => false,
                    'message'      => $allowed['message'],
                    'requires_pro' => true,
                ];
            }

            // Extract from the ORIGINAL (always)
            $neutral = self::extract_content( $post_id, $source );
            if ( empty( $neutral ) ) {
                return [ 'ok' => false, 'message' => 'No content extracted from ' . $source ];
            }

            $original_id = $post_id;
            $write_id    = $post_id;

            if ( $mode === 'safe' ) {
                // Snapshot original for audit without modifying it
                Backup::snapshot( $original_id, $target, 'safe-original-audit' );

                $draft_id = SafeDuplicate::create( $original_id, $target );
                if ( is_wp_error( $draft_id ) ) {
                    return [
                        'ok'      => false,
                        'message' => 'Could not create safe draft: ' . $draft_id->get_error_message(),
                    ];
                }
                $write_id = (int) $draft_id;
            } else {
                // Extra archive before touching live content
                Backup::archive_original( $original_id, $target );
                Backup::snapshot( $original_id, $target, 'inplace' );
            }

            // Apply conversion to write target only
            if ( $target === 'gutenberg' ) {
                $markup = ( new ToGutenberg() )->render( $neutral );
                ( new GutenbergWriter() )->apply( $write_id, $markup, $mode );
                $message = ( $mode === 'safe' )
                    ? 'Safe draft created with Gutenberg blocks. Live original was not changed.'
                    : 'Live post overwritten with Gutenberg blocks (in-place).';
            } elseif ( $target === 'bricks' ) {
                $elements = ( new ToBricks() )->build( $neutral );
                ( new BricksWriter() )->apply( $write_id, $elements, $mode );
                $message = ( $mode === 'safe' )
                    ? 'Safe draft created for Bricks. Live original was not changed.'
                    : 'Live post overwritten with Bricks data (in-place).';
            } elseif ( $target === 'wpbakery' ) {
                $shortcodes = ( new ToWPBakery() )->render( $neutral );
                ( new WPBakeryWriter() )->apply( $write_id, $shortcodes, $mode );
                $message = ( $mode === 'safe' )
                    ? 'Safe draft created with WPBakery shortcodes. Live original was not changed.'
                    : 'Live post overwritten with WPBakery shortcodes (in-place).';
            } elseif ( $target === 'elementor' ) {
                $tree = ( new ToElementor() )->build( $neutral );
                ( new ElementorWriter() )->apply( $write_id, $tree, $mode );
                $message = ( $mode === 'safe' )
                    ? 'Safe draft created for Elementor. Live original was not changed.'
                    : 'Live post overwritten with Elementor data (in-place).';
            } else {
                return [ 'ok' => false, 'message' => 'Unknown target: ' . $target ];
            }

            Backup::log_conversion([
                'event'       => 'convert',
                'original_id' => $original_id,
                'write_id'    => $write_id,
                'source'      => $source,
                'target'      => $target,
                'mode'        => $mode,
                'date'        => current_time( 'mysql' ),
            ]);

            $new_count  = albus_increment_conversion_count();
            $remaining  = albus_get_remaining_conversions();
            $edit_url   = get_edit_post_link( $write_id, 'raw' );
            $view_url   = get_preview_post_link( $write_id );

            $result = [
                'ok'                    => true,
                'mode'                  => $mode,
                'post_id'               => $original_id,
                'draft_id'              => ( $mode === 'safe' ) ? $write_id : null,
                'write_id'              => $write_id,
                'source'                => $source,
                'target'                => $target,
                'message'               => $message,
                'edit_url'              => $edit_url,
                'preview_url'           => $view_url,
                'original_untouched'    => ( $mode === 'safe' ),
                'details'               => sprintf(
                    'Converted %d elements from %s. %s',
                    count( $neutral ),
                    $source,
                    $mode === 'safe'
                        ? 'Wrote to draft #' . $write_id . '; original #' . $original_id . ' unchanged.'
                        : 'Wrote in-place to #' . $write_id . '.'
                ),
                'conversions_used'      => $new_count,
                'conversions_remaining' => $remaining,
            ];

            if ( ! ALBUS_IS_PRO && $remaining > 0 && $remaining <= 3 ) {
                $result['message'] .= " ({$remaining} free conversions remaining)";
            }

            return $result;

        } catch ( \Exception $e ) {
            Logger::error( 'Conversion failed', [
                'post_id' => $post_id,
                'target'  => $target,
                'error'   => $e->getMessage(),
            ]);
            return [
                'ok'            => false,
                'message'       => 'Conversion failed: ' . $e->getMessage(),
                'error_details' => $e->getMessage(),
            ];
        }
    }
    
    public static function bulk_convert( array $post_ids, string $target, string $mode = 'safe', string $confirm_inplace = '' ) {
        if ( ! albus_can_bulk_convert() ) {
            return [
                'ok'           => false,
                'message'      => 'Bulk conversion requires AlbusWP PRO. Please upgrade or convert pages one-by-one.',
                'requires_pro' => true,
            ];
        }

        // Bulk never allows in-place — force safe
        $mode = 'safe';

        Logger::info( 'Starting bulk conversion (safe drafts only)', [
            'count'  => count( $post_ids ),
            'target' => $target,
        ]);

        $results = [
            'ok'      => true,
            'mode'    => 'safe',
            'total'   => count( $post_ids ),
            'success' => 0,
            'failed'  => 0,
            'results' => [],
        ];

        foreach ( $post_ids as $post_id ) {
            $post_id = intval( $post_id );
            $result  = self::convert_post( $post_id, $target, $mode, $confirm_inplace );

            if ( $result['ok'] ) {
                $results['success']++;
            } else {
                $results['failed']++;
            }

            $results['results'][] = [
                'post_id'  => $post_id,
                'draft_id' => $result['draft_id'] ?? null,
                'ok'       => $result['ok'],
                'message'  => $result['message'] ?? '',
            ];
        }

        return $results;
    }
    
    public static function restore_post( int $post_id ) {
        Logger::info( 'Restoring post from backup', [ 'post_id' => $post_id ] );
        
        try {
            if ( ! Backup::restore( $post_id ) ) {
                return [ 'ok' => false, 'message' => 'No backup found for this post.' ];
            }
            
            return [ 
                'ok' => true, 
                'post_id' => $post_id,
                'message' => 'Post restored successfully from backup.'
            ];
            
        } catch ( \Exception $e ) {
            Logger::error( 'Restore failed', [ 'post_id' => $post_id, 'error' => $e->getMessage() ] );
            return [ 'ok' => false, 'message' => 'Restore failed: ' . $e->getMessage() ];
        }
    }
    
    public static function get_backups() {
        global $wpdb;
        
        // Find all posts with backups
        $backups = $wpdb->get_results( "
            SELECT p.ID, p.post_title, p.post_type, pm.meta_key, pm.meta_value
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE pm.meta_key IN ('_albus_backup_post_content', '_albus_backup__bricks_data', '_albus_backup__elementor_data', '_albus_backup_full', '_albus_backup_meta')
            ORDER BY p.post_modified DESC
        " );
        
        $organized = [];
        foreach ( $backups as $backup ) {
            $id = $backup->ID;
            if ( ! isset( $organized[$id] ) ) {
                $organized[$id] = [
                    'post_id' => $id,
                    'title' => $backup->post_title,
                    'post_type' => $backup->post_type,
                    'backups' => [],
                    'meta' => null
                ];
            }
            
            if ( $backup->meta_key === '_albus_backup_post_content' ) {
                $organized[$id]['backups'][] = 'content';
            } elseif ( $backup->meta_key === '_albus_backup__bricks_data' ) {
                $organized[$id]['backups'][] = 'bricks';
            } elseif ( $backup->meta_key === '_albus_backup__elementor_data' ) {
                $organized[$id]['backups'][] = 'elementor';
            } elseif ( $backup->meta_key === '_albus_backup_full' ) {
                $organized[$id]['backups'][] = 'full';
            } elseif ( $backup->meta_key === '_albus_backup_meta' ) {
                $organized[$id]['meta'] = json_decode( $backup->meta_value, true );
            }
        }
        
        return [
            'ok' => true,
            'count' => count($organized),
            'items' => array_values($organized)
        ];
    }
    
    public static function cleanup_old_backups( int $days = 365 ) {
        // Safety: never auto-delete site archives or conversion history.
        // Only prune very old single-key backup meta (default 1 year).
        global $wpdb;

        Logger::info( 'Cleaning up old backup meta (conservative)', [ 'days' => $days ] );

        $date_threshold = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

        $old_backups = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_albus_backup_meta'"
        );

        $deleted = 0;
        foreach ( $old_backups as $backup ) {
            $meta = json_decode( $backup->meta_value, true );
            if ( ! isset( $meta['date'] ) || strtotime( $meta['date'] ) >= strtotime( $date_threshold ) ) {
                continue;
            }
            // Never delete history or archives; only stale primary snapshot keys
            delete_post_meta( $backup->post_id, '_albus_backup_post_content' );
            delete_post_meta( $backup->post_id, '_albus_backup__bricks_data' );
            delete_post_meta( $backup->post_id, '_albus_backup__elementor_data' );
            // Keep _albus_backup_full and _albus_backup_history for safety
            $deleted++;
        }

        return [
            'ok'      => true,
            'deleted' => $deleted,
            'message' => "Pruned {$deleted} old lightweight backup key(s). Full snapshots and archives were kept.",
        ];
    }
    
    /**
     * Export extracted content as JSON for debugging (FREE tier feature)
     */
    public static function export_json( int $post_id ) {
        Logger::info( 'Exporting JSON', [ 'post_id' => $post_id ] );
        
        try {
            $source = Detector::detect_source_for_post( $post_id );
            if ( ! $source ) {
                return [ 'ok' => false, 'message' => 'No supported builder content found.' ];
            }
            
            // Extract to neutral tree
            $neutral = self::extract_content( $post_id, $source );
            
            return [
                'ok' => true,
                'post_id' => $post_id,
                'post_title' => get_the_title( $post_id ),
                'source' => $source,
                'element_count' => count( $neutral ),
                'neutral_tree' => $neutral,
                'timestamp' => current_time( 'mysql' )
            ];
            
        } catch ( \Exception $e ) {
            Logger::error( 'JSON export failed', [ 
                'post_id' => $post_id, 
                'error' => $e->getMessage() 
            ]);
            return [ 'ok' => false, 'message' => $e->getMessage() ];
        }
    }
    
    /**
     * Extract content from a post based on detected source builder
     */
    private static function extract_content( int $post_id, string $source ) : array {
        Logger::debug( "Extracting from {$source}", [ 'post_id' => $post_id ] );
        
        $neutral = [];
        
        switch ( $source ) {
            case 'wpbakery':
                $neutral = ( new WPBakery() )->toNeutralFromPost( $post_id );
                break;
                
            case 'elementor':
                $meta = get_post_meta( $post_id, '_elementor_data', true );
                $data = is_string($meta) ? json_decode( $meta, true ) : $meta;
                
                if ( json_last_error() !== JSON_ERROR_NONE && is_string($meta) ) {
                    Logger::error( 'JSON decode error', [ 'post_id' => $post_id, 'error' => json_last_error_msg() ] );
                    return [];
                }
                
                $neutral = ( new Elementor() )->toNeutralFromData( $data );
                break;
                
            case 'divi':
                $content = get_post_field( 'post_content', $post_id );
                $neutral = ( new Divi() )->toNeutralFromContent( $content );
                break;
                
            case 'kirki':
                $neutral = ( new Kirki() )->toNeutralFromPost( $post_id );
                break;
                
            case 'classic':
                $content = get_post_field( 'post_content', $post_id );
                $neutral = ( new ClassicEditor() )->toNeutralFromContent( $content );
                break;
                
            case 'gutenberg':
                $content = get_post_field( 'post_content', $post_id );
                $neutral = ( new Gutenberg() )->toNeutralFromContent( $content );
                break;
                
            case 'bricks':
                $bricks_data = get_post_meta( $post_id, '_bricks_page_content_2', true );
                if ( empty( $bricks_data ) ) {
                    $bricks_data = get_post_meta( $post_id, '_bricks_data', true );
                }
                
                // Bricks stores a PHP array; legacy Albus may have stored JSON
                if ( is_string( $bricks_data ) ) {
                    $decoded = json_decode( $bricks_data, true );
                    if ( json_last_error() === JSON_ERROR_NONE ) {
                        $bricks_data = $decoded;
                    } else {
                        Logger::error( 'Bricks JSON decode error', [ 'post_id' => $post_id, 'error' => json_last_error_msg() ] );
                        return [];
                    }
                }
                
                if ( ! is_array( $bricks_data ) ) {
                    return [];
                }
                
                $neutral = ( new BricksExtractor() )->toNeutralFromData( $bricks_data );
                break;
                
            default:
                Logger::error( 'Unknown source type', [ 'source' => $source ] );
                return [];
        }
        
        Logger::debug( "Extraction complete from {$source}", [ 'post_id' => $post_id, 'node_count' => count($neutral) ] );
        return $neutral;
    }
}
