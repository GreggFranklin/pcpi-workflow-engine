<?php
/**
 * Plugin Name: _PCPI Workflow Engine
 * Description: Central workflow registry + Gravity Forms glue for PCPI.
 * Version:     1.3.0
 * Author:      Gregg Franklin, Marc Benzakein
 * License:     GPLv2 or later
 * Text Domain: pcpi-workflow-engine
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PCPI_WF_ENGINE_VERSION', '1.3.0' );
define( 'PCPI_WF_ENGINE_FILE', __FILE__ );
define( 'PCPI_WF_ENGINE_DIR', plugin_dir_path( __FILE__ ) );
define( 'PCPI_WF_ENGINE_URL', plugin_dir_url( __FILE__ ) );

// -----------------------------------------------------------------------------
// Load Dependency Checker FIRST
// -----------------------------------------------------------------------------
require_once PCPI_WF_ENGINE_DIR . 'includes/utils/class-dependency-checker.php';

// -----------------------------------------------------------------------------
// Run Dependency Check
// -----------------------------------------------------------------------------
PCPI_Dependency_Checker::check([
    'GFAPI' => 'Gravity Forms',
], 'PCPI Workflow Engine');

// -----------------------------------------------------------------------------
// Load Plugin ONLY if dependencies exist
// -----------------------------------------------------------------------------
if ( class_exists( 'GFAPI' ) ) {

	require_once PCPI_WF_ENGINE_DIR . 'includes/class-pcpi-workflow-engine.php';

	/**
	 * Boot after all plugins are loaded
	 */
	add_action( 'plugins_loaded', [ 'PCPI_Workflow_Engine', 'init' ] );

	/**
	 * Add dashicons
	 */
	add_action( 'wp_enqueue_scripts', function () {
		wp_enqueue_style( 'dashicons' );
	});

}