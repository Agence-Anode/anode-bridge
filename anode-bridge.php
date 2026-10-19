<?php
/**
 * Plugin Name:       Anode Bridge
 * Plugin URI:        https://github.com/theoanode/anode-wp
 * Description:       Pont REST sécurisé entre WordPress/Bricks et le serveur MCP Anode. Expose les données Bricks (contenu, classes globales, variables, templates) et le design system via l'API REST, sans accès SSH.
 * Version:           2.22.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Anode
 * Author URI:        https://agence-anode.fr
 * License:           GPL-2.0-or-later
 * Text Domain:       anode-bridge
 *
 * @package Anode\Bridge
 */

declare( strict_types = 1 );

namespace Anode\Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * La version est lue dans l'en-tête du fichier, pas recopiée en dessous.
 *
 * Le doublon dérive : mesuré deux fois sur les extensions de ce dépôt — en-tête
 * 1.2.0 contre constante 1.1.0 — et c'est toujours la constante que le code
 * rapporte. Or celle-ci sert à décider qu'une mise à jour a eu lieu : figée,
 * elle laisse le pont croire qu'il n'a pas changé, et ses routes ne sont jamais
 * réenregistrées après une montée de version.
 *
 * `get_file_data()` lit les seize premiers kilo-octets, sans charger le fichier
 * deux fois, et c'est la fonction que WordPress emploie lui-même pour ça.
 */
const NAMESPACE_ = 'anode/v1';

define(
	'ANODE_BRIDGE_VERSION',
	get_file_data( __FILE__, [ 'version' => 'Version' ] )['version'] ?: '0.0.0'
);

define( 'ANODE_BRIDGE_FILE', __FILE__ );
define( 'ANODE_BRIDGE_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Autoloader PSR-4 minimaliste pour le namespace Anode\Bridge.
 *
 * Anode\Bridge\Rest_Bricks  ->  includes/class-rest-bricks.php
 */
spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, __NAMESPACE__ . '\\' ) ) {
			return;
		}

		$relative = substr( $class, strlen( __NAMESPACE__ ) + 1 );
		$file     = ANODE_BRIDGE_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Point d'entrée : instancie le plugin une seule fois.
 */
function bootstrap(): Plugin {
	static $plugin = null;

	if ( null === $plugin ) {
		$plugin = new Plugin();
		$plugin->register();
	}

	return $plugin;
}

bootstrap();

/**
 * Installation : capacités et version.
 *
 * Passe par un contrôle de version plutôt que par le seul hook d'activation :
 * celui-ci ne se déclenche pas lorsque le pont est installé en mu-plugin —
 * le mode recommandé, puisqu'un client ne peut alors pas le désactiver par
 * mégarde et perdre tout pilotage du site.
 */
add_action(
	'init',
	static function (): void {
		if ( get_option( 'anode_bridge_version' ) === ANODE_BRIDGE_VERSION ) {
			return;
		}

		Security::grant_capability_to_admins();
		update_option( 'anode_bridge_version', ANODE_BRIDGE_VERSION, false );
	},
	5
);

// Conservé pour l'installation classique en extension : pose les capacités
// immédiatement, sans attendre la première requête.
if ( function_exists( 'register_activation_hook' ) ) {
	register_activation_hook(
		__FILE__,
		static function (): void {
			Security::grant_capability_to_admins();
			update_option( 'anode_bridge_version', ANODE_BRIDGE_VERSION, false );
		}
	);
}
