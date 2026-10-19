<?php
/**
 * Écran d'ouverture de l'inspection — Outils → Inspection Anode.
 *
 * Il n'existe que pour une raison, et elle est dirimante : la constante
 * `ANODE_INSPECTION` suppose un accès aux fichiers du site. Sur une reprise,
 * c'est précisément ce qui manque — sinon on exporterait le site et la question
 * ne se poserait pas.
 *
 * Cet écran demande une session d'administration **interactive**. On a d'abord
 * cru que c'était la garantie du dispositif — « un mot de passe d'application
 * n'ouvre pas wp-admin, donc il n'ouvre pas le livre ». Mesuré le 19/10/2026 :
 * c'est faux dans le cas courant. Le compte du pont est administrateur, et avec
 * son seul mot de passe d'application `GET /wp/v2/plugins` rend 200 — donc il
 * installe une extension, donc il exécute du PHP, donc il lit déjà tout. Par un
 * chemin plus direct, et sans laisser de trace.
 *
 * Cet écran reste **la** voie sur deux terrains, et c'est ce qui le justifie :
 * un site qui pose `DISALLOW_FILE_MODS`, où le raccourci par le PHP n'existe
 * plus et où `/inspect/open` se refuse ; et un site dont nous n'avons ni les
 * fichiers ni le mot de passe d'application, où il ne reste que le navigateur.
 *
 * La durée est bornée à trente jours, et le formulaire ne propose pas d'autre
 * voie : une inspection « jusqu'à nouvel ordre » est une inspection qu'on
 * oublie ouverte.
 *
 * @package Anode\Bridge
 */

declare( strict_types = 1 );

namespace Anode\Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin_Inspection {

	private const ACTION = 'anode_inspection';

	public function register(): void {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
		add_action( 'admin_notices', [ $this, 'notice' ] );
	}

	public function add_page(): void {
		add_management_page(
			'Inspection Anode',
			'Inspection Anode',
			'manage_options',
			'anode-inspection',
			[ $this, 'render' ]
		);
	}

	/**
	 * Bandeau sur toutes les pages d'administration tant que c'est ouvert.
	 *
	 * Une capacité ouverte dont rien ne parle est une capacité qu'on oublie :
	 * c'est le défaut que ce bandeau existe pour ne pas reproduire.
	 */
	public function notice(): void {
		$jusqu = Rest_Inspection::open_until();

		if ( null === $jusqu || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>Inspection Anode ouverte</strong> jusqu’au %s — '
				. 'le site est lisible intégralement par le serveur MCP. <a href="%s">Fermer</a></p></div>',
			esc_html( $jusqu ),
			esc_url( admin_url( 'tools.php?page=anode-inspection' ) )
		);
	}

	public function render(): void {
		$jusqu = Rest_Inspection::open_until();
		$max   = gmdate( 'Y-m-d', time() + Rest_Inspection::MAX_JOURS * DAY_IN_SECONDS );

		echo '<div class="wrap"><h1>Inspection Anode</h1>';

		echo '<p>L’inspection rend ce site lisible par le serveur MCP : fichiers de <code>wp-content</code>, '
			. 'métadonnées, options et tables. <strong>En lecture seule</strong>, les valeurs qui ressemblent à '
			. 'un secret sont expurgées, et chaque lecture est journalisée.</p>';

		if ( null !== $jusqu ) {
			printf( '<p><strong>Ouverte jusqu’au %s.</strong></p>', esc_html( $jusqu ) );
		} else {
			echo '<p><strong>Fermée.</strong> Aucune de ces routes ne répond.</p>';
		}

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		wp_nonce_field( self::ACTION );
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( self::ACTION ) );

		printf(
			'<p><label>Ouvrir jusqu’au <input type="date" name="until" max="%s" value="%s"></label> '
				. '<span class="description">trente jours au plus.</span></p>',
			esc_attr( $max ),
			esc_attr( $jusqu ?? gmdate( 'Y-m-d', time() + 7 * DAY_IN_SECONDS ) )
		);

		submit_button( 'Enregistrer', 'primary', 'ouvrir', false );
		echo ' ';
		submit_button( 'Fermer maintenant', 'delete', 'fermer', false );

		echo '</form></div>';
	}

	public function handle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permissions insuffisantes.' );
		}

		check_admin_referer( self::ACTION );

		if ( isset( $_POST['fermer'] ) ) {
			delete_option( Rest_Inspection::OPTION_UNTIL );
			Security::log( 'inspection.close' );
		} else {
			$demande = sanitize_text_field( wp_unslash( (string) ( $_POST['until'] ?? '' ) ) );
			$limite  = time() + Rest_Inspection::MAX_JOURS * DAY_IN_SECONDS;
			$date    = strtotime( $demande . ' 23:59:59' );

			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $demande ) || false === $date || $date > $limite ) {
				wp_die( 'Date invalide : une date au format AAAA-MM-JJ, dans les trente jours.' );
			}

			update_option( Rest_Inspection::OPTION_UNTIL, $demande, false );
			Security::log( 'inspection.open', [ 'until' => $demande ] );
		}

		wp_safe_redirect( admin_url( 'tools.php?page=anode-inspection' ) );
		exit;
	}
}
