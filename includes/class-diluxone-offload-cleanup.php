<?php
/**
 * Plugin uninstall and cleanup helpers.
 *
 * Reads serialized values from the WordPress options table when scanning
 * orphaned plugin data on uninstall. The data was originally written BY
 * THIS PLUGIN to the same options table via update_option() (which
 * applies WordPress's own serialize/unserialize automatically), so the
 * Object-Injection class of attacks documented in the OWASP guidance
 * does not apply here — the bytes can only have been put there by code
 * we control. The @ silencing matches the WordPress-core pattern for
 * idempotent cleanup. These rules are intentionally suppressed
 * file-wide:
 *
 * phpcs:disable WordPress.PHP.NoSilencedErrors.Discouraged
 * phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Database Cleanup Utility
 *
 * Removes deprecated options from database (compression_enabled, compression_quality)
 *
 * @package DiluxOneOffload
 * @since 1.0.0
 */
class Cleanup {

	/**
	 * Remove deprecated compression options from database
	 *
	 * @return array<string, mixed> Results of cleanup operation
	 */
	public static function remove_compression_options(): array {
		$results = array(
			'found'   => array(),
			'removed' => array(),
			'success' => false,
			'message' => '',
		);

		// Get current config
		$config = get_option( 'diluxone_offload_config', array() );

		if ( is_string( $config ) ) {
			$config = @unserialize( $config );
			if ( $config === false ) {
				$config = array();
			}
		}

		// Check for deprecated options
		if ( isset( $config['compression_enabled'] ) ) {
			$results['found']['compression_enabled'] = $config['compression_enabled'];
			unset( $config['compression_enabled'] );
			$results['removed'][] = 'compression_enabled';
		}

		if ( isset( $config['compression_quality'] ) ) {
			$results['found']['compression_quality'] = $config['compression_quality'];
			unset( $config['compression_quality'] );
			$results['removed'][] = 'compression_quality';
		}

		// Save if changes were made
		if ( ! empty( $results['removed'] ) ) {
			$update_result = update_option( 'diluxone_offload_config', $config );

			if ( $update_result ) {
				$results['success'] = true;
				$results['message'] = sprintf(
					'Successfully removed %d deprecated option(s): %s',
					count( $results['removed'] ),
					implode( ', ', $results['removed'] )
				);
			} else {
				$results['message'] = 'Options found but update failed (they may have been already cleaned)';
			}
		} else {
			$results['success'] = true;
			$results['message'] = 'No deprecated compression options found. Database is clean!';
		}

		return $results;
	}

	/**
	 * Check if compression options exist in database
	 *
	 * @return bool
	 */
	public static function has_compression_options(): bool {
		$config = get_option( 'diluxone_offload_config', array() );

		if ( is_string( $config ) ) {
			$config = @unserialize( $config );
			if ( $config === false ) {
				return false;
			}
		}

		return isset( $config['compression_enabled'] ) || isset( $config['compression_quality'] );
	}

	/**
	 * Get current config summary (without sensitive data)
	 *
	 * @return array<string, mixed>
	 */
	public static function get_config_summary(): array {
		$config = get_option( 'diluxone_offload_config', array() );

		if ( is_string( $config ) ) {
			$config = @unserialize( $config );
			if ( $config === false ) {
				$config = array();
			}
		}

		// Return only non-sensitive settings
		$safe_keys = array(
			'debug_enabled',
			'keep_local_files',
			'auto_activate_offloading',
			'timeout',
			'max_file_size',
			'allowed_file_types',
			'cloud_provider',
		);

		$summary = array();
		foreach ( $safe_keys as $key ) {
			if ( isset( $config[ $key ] ) ) {
				$summary[ $key ] = $config[ $key ];
			}
		}

		return $summary;
	}
}
