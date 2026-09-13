<?php
/**
 * Validation helpers for sync operations.
 *
 * @package DiluxOneOffload
 */

namespace DiluxOneOffload;

use DiluxOneOffload\Enums\PluginState;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validation Helper
 *
 * Centraliza TODAS las validaciones para operaciones de sync
 * Se llama 2 veces por operación:
 * 1. Pre-check: Antes de mostrar modal con opciones
 * 2. Execution-check: Antes de ejecutar la acción (después de que usuario confirme)
 *
 * Esto previene race conditions cuando el usuario tarda en confirmar
 */
class ValidationHelper {

	/**
	 * Valida si se puede ejecutar una operación sync
	 *
	 * @param string $requesting_session_id ID de sesión del tab que solicita
	 * @param string $operation_type Tipo de operación: 'start_sync', 'retry_failed', 'clear_and_enable', 'enable_offloading', 'disconnect', 'cancel_sync'
	 * @return array<string, mixed> ['passed' => bool, 'reason' => string, 'details' => array]
	 */
	public static function validate_sync_operation( $requesting_session_id, $operation_type ) {
		Logger::debug( '[DiluxOne Offload Validation] Validating operation: ' . $operation_type . ' from session: ' . $requesting_session_id );

		// 1. Multi-tab check (aplica a operaciones que modifican sync)
		if ( self::requires_multi_tab_check( $operation_type ) ) {
			$multi_tab_result = self::validate_multi_tab( $requesting_session_id );
			if ( ! $multi_tab_result['passed'] ) {
				return $multi_tab_result;
			}
		}

		// 2. Plugin state check
		$state_result = self::validate_plugin_state( $operation_type );
		if ( ! $state_result['passed'] ) {
			return $state_result;
		}

		// 3. Files check (específico por operación)
		$files_result = self::validate_files_state( $operation_type );
		if ( ! $files_result['passed'] ) {
			return $files_result;
		}

		// ✅ Todas las validaciones pasaron
		Logger::info( '[DiluxOne Offload Validation] All validations PASSED for operation: ' . $operation_type );
		return array(
			'passed'  => true,
			'reason'  => '',
			'details' => array(),
		);
	}

	/**
	 * Determina si la operación requiere validación multi-tab
	 *
	 * @param mixed $operation_type
	 * @return bool
	 */
	private static function requires_multi_tab_check( $operation_type ) {
		return in_array(
			$operation_type,
			array(
				'start_sync',
				'retry_failed',
				'clear_and_enable',
				'disconnect',
				'prepare_resync',
				'cancel_sync', // ⭐ Reset Sync también necesita validación multi-tab
			),
			true
		);
	}

	/**
	 * Valida que no haya otro tab controlando la sync
	 *
	 * @param mixed $requesting_session_id
	 * @return array<string, mixed>
	 */
	private static function validate_multi_tab( $requesting_session_id ): array {
		$sync_meta = get_option( 'diluxone_offload_sync_meta', array() );

		if ( empty( $sync_meta ) ) {
			// No hay sync activa → OK
			return array(
				'passed'  => true,
				'reason'  => '',
				'details' => array(),
			);
		}

		$active_session  = $sync_meta['sync_session_id'] ?? '';
		$status          = $sync_meta['status'] ?? '';
		$last_heartbeat  = $sync_meta['last_heartbeat'] ?? 0;
		$is_reverse_sync = $sync_meta['is_reverse_sync'] ?? false;

		// Check si sync está terminada (completed, failed, completed_with_errors)
		// ⭐ FIX: Mover este check ANTES del timeout para evitar limpiar sync completadas
		if ( in_array( $status, array( 'completed', 'completed_with_errors', 'failed' ), true ) ) {
			// Sync terminada → OK
			// No limpiar aquí, activate_offloading() se encarga de limpiar
			return array(
				'passed'  => true,
				'reason'  => '',
				'details' => array(),
			);
		}

		// ⭐ FIX: Solo verificar heartbeat timeout si hay sync ACTIVA (status='started')
		// Si status='completed', la sync ya terminó y no debe validarse heartbeat
		if ( $status === 'started' ) {
			// Check timeout (90 segundos sin heartbeat)
			$heartbeat_timeout = 90;
			if ( time() - $last_heartbeat > $heartbeat_timeout ) {
				// ⭐ FIX: Si es reverse sync, NO cambiar estado (debe permanecer OFFLOADING_ACTIVE)
				if ( $is_reverse_sync ) {
					Logger::warning( '[DiluxOne Offload Validation] Reverse sync session expired (no heartbeat for ' . ( time() - $last_heartbeat ) . 's), clearing metadata but preserving OFFLOADING_ACTIVE state' );
					delete_option( 'diluxone_offload_sync_meta' );
					return array(
						'passed'  => true,
						'reason'  => '',
						'details' => array(),
					);
				}

				// Forward sync expirada → limpiar y resetear a CONFIGURED
				Logger::warning( '[DiluxOne Offload Validation] Forward sync session expired (no heartbeat for ' . ( time() - $last_heartbeat ) . 's), cleaning up' );
				ConfigManager::set_state( PluginState::CONFIGURED );
				ConfigManager::clear_sync_progress();
				return array(
					'passed'  => true,
					'reason'  => '',
					'details' => array(),
				);
			}
		}

		// Hay sync activa → verificar si este tab es el dueño
		if ( $active_session !== $requesting_session_id ) {
			// Otro tab es el dueño → BLOCK
			Logger::error( '[DiluxOne Offload Validation] FAILED: Another tab is active (active: ' . $active_session . ', requesting: ' . $requesting_session_id . ')' );
			return array(
				'passed'  => false,
				'reason'  => 'sync_active_in_another_tab',
				'details' => array( 'sync_meta' => $sync_meta ),
			);
		}

		// Este tab es el dueño → OK
		return array(
			'passed'  => true,
			'reason'  => '',
			'details' => array(),
		);
	}

	/**
	 * Valida el estado del plugin según la operación
	 *
	 * @param mixed $operation_type
	 * @return array<string, mixed>
	 */
	private static function validate_plugin_state( $operation_type ): array {
		$current_state = ConfigManager::get_state();

		switch ( $operation_type ) {
			case 'start_sync':
			case 'retry_failed':
				// No se puede iniciar sync si ya está SYNCING
				if ( $current_state === PluginState::SYNCING ) {
					Logger::error( '[DiluxOne Offload Validation] FAILED: Cannot start sync, state is already SYNCING' );
					return array(
						'passed'  => false,
						'reason'  => 'sync_already_active',
						'details' => array( 'current_state' => $current_state ),
					);
				}
				break;

			case 'enable_offloading':
				// Solo se puede activar offloading desde estado SYNCED
				if ( $current_state !== PluginState::SYNCED ) {
					Logger::error( '[DiluxOne Offload Validation] FAILED: Cannot enable offloading, state is ' . $current_state . ' (required: SYNCED)' );
					return array(
						'passed'  => false,
						'reason'  => 'state_conflict',
						'details' => array(
							'current_state'  => $current_state,
							'required_state' => PluginState::SYNCED,
						),
					);
				}
				break;

			case 'disconnect':
				// Solo se puede desconectar desde OFFLOADING_ACTIVE
				if ( $current_state !== PluginState::OFFLOADING_ACTIVE ) {
					Logger::error( '[DiluxOne Offload Validation] FAILED: Cannot disconnect, state is ' . $current_state . ' (required: OFFLOADING_ACTIVE)' );
					return array(
						'passed'  => false,
						'reason'  => 'state_conflict',
						'details' => array(
							'current_state'  => $current_state,
							'required_state' => PluginState::OFFLOADING_ACTIVE,
						),
					);
				}
				break;
		}

		return array(
			'passed'  => true,
			'reason'  => '',
			'details' => array(),
		);
	}

	/**
	 * Valida el estado de los archivos según la operación
	 *
	 * @param mixed $operation_type
	 * @return array<string, mixed>
	 */
	private static function validate_files_state( $operation_type ): array {
		if ( $operation_type !== 'enable_offloading' ) {
			// Otras operaciones no requieren validación de archivos
			return array(
				'passed'  => true,
				'reason'  => '',
				'details' => array(),
			);
		}

		// Enable offloading requiere que NO haya archivos failed o pending
		require_once DILUXONE_OFFLOAD_DIR . 'includes/class-diluxone-offload-db.php';
		$stats = DiluxOneOffloadDB::get_stats();

		$failed_count  = (int) ( $stats['failed_files'] ?? 0 );
		$pending_count = (int) ( $stats['pending_files'] ?? 0 );

		if ( $failed_count > 0 || $pending_count > 0 ) {
			Logger::error( '[DiluxOne Offload Validation] FAILED: Cannot enable offloading, failed=' . $failed_count . ', pending=' . $pending_count );
			return array(
				'passed'  => false,
				'reason'  => 'files_not_synced',
				'details' => array(
					'failed_count'  => $failed_count,
					'pending_count' => $pending_count,
					'stats'         => $stats,
				),
			);
		}

		return array(
			'passed'  => true,
			'reason'  => '',
			'details' => array(),
		);
	}
}
