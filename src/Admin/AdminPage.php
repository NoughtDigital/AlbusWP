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
use Albus\Import\GutenbergWriter;
use Albus\Import\BricksWriter;
use Albus\Util\Logger;

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
                <div class="albus-info-box">
                    <p><strong>What does Albus do?</strong> Albus converts legacy page builder content (WPBakery, Elementor) into modern formats (Gutenberg blocks or Bricks Builder).</p>
                    <p><strong>Safety First:</strong> All conversions create automatic backups. You can restore any post at any time from the Backups tab.</p>
                    <?php if ( ! ALBUS_IS_PRO ) : ?>
                    <p><strong>FREE Version:</strong> You can scan up to <?php echo ALBUS_FREE_SCAN_LIMIT; ?> pages and convert up to <?php echo ALBUS_FREE_CONVERT_LIMIT; ?> pages (WPBakery → Gutenberg). <a href="<?php echo esc_url( albus_get_upgrade_url() ); ?>" class="button button-primary">Upgrade to PRO</a> for unlimited access.</p>
                    <?php endif; ?>
                </div>
                
                <div class="albus-actions">
                    <button class="button button-primary" id="albus-scan">Scan Site</button>
                    <?php if ( $log_exists ) : ?>
                        <a href="<?php echo esc_url( ALBUS_URL . 'albus-debug.log' ); ?>" target="_blank" class="button">View Debug Log</a>
                    <?php endif; ?>
                </div>
                
                <div id="albus-bulk-actions" style="display:none;margin-top:1rem;">
                    <h3>Bulk Actions</h3>
                    <button class="button" id="albus-bulk-gutenberg">Convert All to Gutenberg</button>
                    <button class="button button-primary" id="albus-bulk-bricks">Convert All to Bricks</button>
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
                    <h3>How to Use Albus</h3>
                    <ol>
                        <li><strong>Scan:</strong> Click "Scan Site" to find all posts using WPBakery or Elementor</li>
                        <li><strong>Preview (Optional):</strong> Click "Preview" to see the converted content before applying</li>
                        <li><strong>Convert:</strong> Click the conversion button for your target format (Gutenberg or Bricks)</li>
                        <li><strong>Review:</strong> Check the converted post using the "Edit Post" link</li>
                        <li><strong>Restore (if needed):</strong> Go to Backups tab to restore any post</li>
                    </ol>
                </div>
                
                <div class="albus-info-box">
                    <h3>Bulk Conversion</h3>
                    <p>After scanning, you'll see bulk conversion buttons to convert all posts at once. This is useful for migrating entire sites.</p>
                    <p><strong>Tip:</strong> Test on a few posts first before doing a bulk conversion.</p>
                </div>
                
                <?php if ( ! ALBUS_IS_PRO ) : ?>
                <div class="albus-info-box" style="background:#f0f9ff;border-left:4px solid #3b82f6;">
                    <h3>Upgrade to PRO</h3>
                    <p><strong>Remove all limits and unlock premium features!</strong></p>
                    <p>AlbusWP PRO unlocks:</p>
                    <ul>
                        <li>✨ <strong>Unlimited Scans & Conversions</strong> - No more 10-page limits</li>
                        <li>✨ <strong>Bulk Conversion</strong> - Convert all pages with one click</li>
                        <li>✨ <strong>Elementor Support</strong> - Convert from the most popular page builder</li>
                        <li>✨ <strong>Divi Support</strong> - Support for Elegant Themes' Divi Builder</li>
                        <li>✨ <strong>Bricks Builder</strong> - Output to the modern Bricks Builder</li>
                        <li>✨ <strong>Advanced Style Mapping</strong> - Better CSS & typography conversion</li>
                        <li>✨ <strong>Multi-site Support</strong> - Use on unlimited sites</li>
                        <li>✨ <strong>Priority Support</strong> - Get help when you need it</li>
                        <li>✨ <strong>Future Features</strong> - Early access to Oxygen, Beaver Builder, and more</li>
                    </ul>
                    <p style="font-size:18px;margin:1rem 0;">
                        <strong>Try it risk-free with our 30-day money-back guarantee!</strong>
                    </p>
                    <p><a href="<?php echo esc_url( albus_get_upgrade_url() ); ?>" class="button button-primary button-large" style="font-size:16px;padding:8px 24px;">Upgrade to AlbusWP PRO</a></p>
                </div>
                <?php endif; ?>
                
                <div class="albus-warning-box">
                    <h3>Important Notes</h3>
                    <ul>
                        <li>Conversions modify your database directly (no file downloads)</li>
                        <li>Always backup your database before bulk conversions</li>
                        <li>Complex layouts may need manual adjustments after conversion</li>
                        <li>Custom CSS and JavaScript may need to be re-applied</li>
                    </ul>
                </div>
                
                <div class="albus-info-box">
                    <h3>Free vs PRO Features</h3>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                        <div>
                            <strong>FREE Version:</strong>
                            <ul>
                                <li>✅ Convert FROM WPBakery</li>
                                <li>✅ Convert TO Gutenberg</li>
                                <li>⚠️ Scan up to 10 pages</li>
                                <li>⚠️ Convert up to 10 pages</li>
                                <li>⚠️ Manual conversion (one-by-one)</li>
                                <li>✅ Automatic backups</li>
                                <li>✅ Restore functionality</li>
                            </ul>
                        </div>
                        <div>
                            <strong>PRO Version:</strong>
                            <ul>
                                <li>✨ <strong>Unlimited</strong> scans & conversions</li>
                                <li>✨ Convert FROM Elementor & Divi</li>
                                <li>✨ Convert TO Bricks Builder</li>
                                <li>✨ <strong>Bulk conversion</strong> (one-click)</li>
                                <li>✨ Advanced style mapping</li>
                                <li>✨ Multi-site support</li>
                                <li>✨ Priority support</li>
                                <li>✨ All FREE features included</li>
                            </ul>
                        </div>
                    </div>
                </div>
                
                <div class="albus-info-box">
                    <h3>Troubleshooting</h3>
                    <p><strong>Check the logs:</strong> <?php if ( $log_exists ) : ?><a href="<?php echo esc_url( ALBUS_URL . 'albus-debug.log' ); ?>" target="_blank">View Debug Log</a><?php else : ?>Debug log will appear after first conversion<?php endif; ?></p>
                    <p><strong>Common issues:</strong></p>
                    <ul>
                        <li>Bricks conversions fail? Make sure Bricks Builder plugin is installed and active</li>
                        <li>Missing content? Check that source builder data exists on the post</li>
                        <li>Complex layouts? May require manual adjustment after conversion</li>
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
                    <button class="button button-primary" id="albus-confirm-convert">Convert Now</button>
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

    public static function convert_post( int $post_id, string $target ) {
        Logger::info( 'Starting conversion', [ 'post_id' => $post_id, 'target' => $target ] );
        
        try {
            // Check conversion limits (FREE version only)
            if ( ! albus_can_convert() ) {
                $limit = ALBUS_FREE_CONVERT_LIMIT;
                Logger::warning( 'Conversion limit reached', [ 'post_id' => $post_id, 'limit' => $limit ] );
                return [ 
                    'ok' => false, 
                    'message' => "Free version limit reached! You've converted {$limit} pages. Upgrade to PRO for unlimited conversions.",
                    'requires_pro' => true,
                    'limit_reached' => true
                ];
            }
            
            // Detect source builder
            $source = Detector::detect_source_for_post( $post_id );
            if ( ! $source ) {
                Logger::warning( 'No supported builder content found', [ 'post_id' => $post_id ] );
                return [ 'ok' => false, 'message' => 'No supported builder content found.' ];
            }
            
            // Check if this specific conversion is allowed
            $allowed = albus_is_conversion_allowed( $source, $target );
            if ( ! $allowed['allowed'] ) {
                Logger::warning( 'Conversion not allowed in current tier', [ 'post_id' => $post_id, 'source' => $source, 'target' => $target ] );
                return [ 
                    'ok' => false, 
                    'message' => $allowed['message'],
                    'requires_pro' => true 
                ];
            }
            
            Logger::info( 'Detected source', [ 'post_id' => $post_id, 'source' => $source ] );

            // Extract to neutral tree
            $neutral = self::extract_content( $post_id, $source );
            
            if ( empty( $neutral ) ) {
                Logger::warning( 'Neutral tree is empty after extraction', [ 'post_id' => $post_id, 'source' => $source ] );
                return [ 'ok' => false, 'message' => 'No content extracted from ' . $source ];
            }

            // Convert to target
            if ( $target === 'gutenberg' ) {
                Logger::info( 'Converting to Gutenberg', [ 'post_id' => $post_id ] );
                $markup = ( new ToGutenberg() )->render( $neutral );
                Logger::debug( 'Gutenberg markup generated', [ 'post_id' => $post_id, 'length' => strlen($markup) ] );
                
                ( new GutenbergWriter() )->apply( $post_id, $markup );
                Logger::info( 'Gutenberg conversion complete', [ 'post_id' => $post_id ] );
                
                // Increment conversion counter for FREE version
                $new_count = albus_increment_conversion_count();
                $remaining = albus_get_remaining_conversions();
                
                $edit_url = get_edit_post_link( $post_id, 'raw' );
                $result = [ 
                    'ok' => true, 
                    'post_id' => $post_id, 
                    'source' => $source, 
                    'target' => $target,
                    'message' => 'Post updated successfully. Content converted to Gutenberg blocks.',
                    'edit_url' => $edit_url,
                    'details' => sprintf( 'Converted %d elements from %s', count($neutral), $source ),
                    'conversions_used' => $new_count,
                    'conversions_remaining' => $remaining
                ];
                
                // Add reminder about remaining conversions for FREE users
                if ( ! ALBUS_IS_PRO && $remaining > 0 && $remaining <= 3 ) {
                    $result['message'] .= " ({$remaining} free conversions remaining)";
                }
                
                return $result;
                
            } elseif ( $target === 'bricks' ) {
                Logger::info( 'Converting to Bricks', [ 'post_id' => $post_id ] );
                $json = ( new ToBricks() )->build( $neutral );
                Logger::debug( 'Bricks JSON generated', [ 'post_id' => $post_id, 'structure' => array_keys($json) ] );
                
                ( new BricksWriter() )->apply( $post_id, $json );
                Logger::info( 'Bricks conversion complete', [ 'post_id' => $post_id ] );
                
                // Increment conversion counter for FREE version
                $new_count = albus_increment_conversion_count();
                $remaining = albus_get_remaining_conversions();
                
                $edit_url = get_edit_post_link( $post_id, 'raw' );
                $result = [ 
                    'ok' => true, 
                    'post_id' => $post_id, 
                    'source' => $source, 
                    'target' => $target,
                    'message' => 'Post updated successfully. Content converted to Bricks Builder.',
                    'edit_url' => $edit_url,
                    'details' => sprintf( 'Converted %d elements from %s', count($neutral), $source ),
                    'conversions_used' => $new_count,
                    'conversions_remaining' => $remaining
                ];
                
                return $result;
                
            } else {
                Logger::error( 'Unknown target specified', [ 'post_id' => $post_id, 'target' => $target ] );
                return [ 'ok' => false, 'message' => 'Unknown target: ' . $target ];
            }
            
        } catch ( \Exception $e ) {
            Logger::error( 'Conversion failed with exception', [ 
                'post_id' => $post_id, 
                'target' => $target,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [ 
                'ok' => false, 
                'message' => 'Conversion failed: ' . $e->getMessage(),
                'error_details' => $e->getMessage()
            ];
        }
    }
    
    public static function bulk_convert( array $post_ids, string $target ) {
        // Check if bulk conversion is allowed
        if ( ! albus_can_bulk_convert() ) {
            Logger::warning( 'Bulk conversion requires PRO' );
            return [
                'ok' => false,
                'message' => 'Bulk conversion requires AlbusWP PRO. Please upgrade or convert pages one-by-one.',
                'requires_pro' => true
            ];
        }
        
        Logger::info( 'Starting bulk conversion', [ 'count' => count($post_ids), 'target' => $target ] );
        
        $results = [
            'ok' => true,
            'total' => count($post_ids),
            'success' => 0,
            'failed' => 0,
            'results' => []
        ];
        
        foreach ( $post_ids as $post_id ) {
            $post_id = intval( $post_id );
            $result = self::convert_post( $post_id, $target );
            
            if ( $result['ok'] ) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
            
            $results['results'][] = [
                'post_id' => $post_id,
                'ok' => $result['ok'],
                'message' => $result['message'] ?? ''
            ];
        }
        
        Logger::info( 'Bulk conversion complete', $results );
        return $results;
    }
    
    public static function restore_post( int $post_id ) {
        Logger::info( 'Restoring post from backup', [ 'post_id' => $post_id ] );
        
        try {
            // Check for Gutenberg backup
            $gutenberg_backup = get_post_meta( $post_id, '_albus_backup_post_content', true );
            
            // Check for Bricks backup
            $bricks_backup = get_post_meta( $post_id, '_albus_backup__bricks_data', true );
            
            if ( empty( $gutenberg_backup ) && empty( $bricks_backup ) ) {
                return [ 'ok' => false, 'message' => 'No backup found for this post.' ];
            }
            
            // Restore content
            if ( ! empty( $gutenberg_backup ) ) {
                wp_update_post([ 'ID' => $post_id, 'post_content' => $gutenberg_backup ]);
                delete_post_meta( $post_id, '_albus_backup_post_content' );
                Logger::info( 'Restored Gutenberg backup', [ 'post_id' => $post_id ] );
            }
            
            if ( ! empty( $bricks_backup ) ) {
                update_post_meta( $post_id, '_bricks_data', $bricks_backup );
                delete_post_meta( $post_id, '_albus_backup__bricks_data' );
                Logger::info( 'Restored Bricks backup', [ 'post_id' => $post_id ] );
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
            WHERE pm.meta_key IN ('_albus_backup_post_content', '_albus_backup__bricks_data', '_albus_backup_meta')
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
                $organized[$id]['backups'][] = 'gutenberg';
            } elseif ( $backup->meta_key === '_albus_backup__bricks_data' ) {
                $organized[$id]['backups'][] = 'bricks';
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
    
    public static function cleanup_old_backups( int $days = 30 ) {
        global $wpdb;
        
        Logger::info( 'Cleaning up old backups', [ 'days' => $days ] );
        
        // Find backup metadata older than X days
        $date_threshold = date( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
        
        $old_backups = $wpdb->get_results( $wpdb->prepare( "
            SELECT post_id, meta_value
            FROM {$wpdb->postmeta}
            WHERE meta_key = '_albus_backup_meta'
            AND meta_value LIKE %s
        ", '%"date":"' . substr( $date_threshold, 0, 10 ) . '%' ) );
        
        $deleted = 0;
        foreach ( $old_backups as $backup ) {
            $meta = json_decode( $backup->meta_value, true );
            if ( isset( $meta['date'] ) && strtotime( $meta['date'] ) < strtotime( $date_threshold ) ) {
                delete_post_meta( $backup->post_id, '_albus_backup_post_content' );
                delete_post_meta( $backup->post_id, '_albus_backup__bricks_data' );
                delete_post_meta( $backup->post_id, '_albus_backup_meta' );
                $deleted++;
            }
        }
        
        Logger::info( 'Backup cleanup complete', [ 'deleted' => $deleted ] );
        
        return [
            'ok' => true,
            'deleted' => $deleted,
            'message' => "Deleted {$deleted} old backup(s)"
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
                $content = get_post_field( 'post_content', $post_id );
                $neutral = ( new WPBakery() )->toNeutralFromContent( $content );
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
                
                // Bricks data might be JSON string or already decoded
                if ( is_string( $bricks_data ) ) {
                    $bricks_data = json_decode( $bricks_data, true );
                }
                
                if ( json_last_error() !== JSON_ERROR_NONE ) {
                    Logger::error( 'Bricks JSON decode error', [ 'post_id' => $post_id, 'error' => json_last_error_msg() ] );
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
