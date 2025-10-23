<?php
namespace Albus\Util;

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Logger {
    
    private static function write( string $level, string $message, array $context = [] ) : void {
        $timestamp = current_time( 'Y-m-d H:i:s' );
        $context_str = ! empty( $context ) ? ' | ' . wp_json_encode( $context ) : '';
        $log_entry = sprintf( "[%s] [%s] %s%s\n", $timestamp, strtoupper($level), $message, $context_str );
        
        // Write to debug.log if WP_DEBUG_LOG is enabled
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( 'AlbusWP: ' . $log_entry );
        }
        
        // Also write to custom log file
        $log_file = ALBUS_PATH . 'albus-debug.log';
        file_put_contents( $log_file, $log_entry, FILE_APPEND );
    }
    
    public static function info( string $message, array $context = [] ) : void {
        self::write( 'info', $message, $context );
    }
    
    public static function error( string $message, array $context = [] ) : void {
        self::write( 'error', $message, $context );
    }
    
    public static function debug( string $message, array $context = [] ) : void {
        self::write( 'debug', $message, $context );
    }
    
    public static function warning( string $message, array $context = [] ) : void {
        self::write( 'warning', $message, $context );
    }
}

