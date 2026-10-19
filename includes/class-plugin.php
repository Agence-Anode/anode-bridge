<?php
/**
 * Orchestrateur du plugin : enregistre les contrôleurs REST.
 *
 * @package Anode\Bridge
 */

declare( strict_types = 1 );

namespace Anode\Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	/**
	 * Contrôleurs REST à enregistrer.
	 *
	 * @var array<class-string>
	 */
	private const CONTROLLERS = [
		Rest_Site::class,
		Rest_Bricks::class,
		Rest_Design_System::class,
		Rest_Media::class,
		Rest_Content::class,
		Rest_Health::class,
		Rest_Inspection::class,
	];

	public function register(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'init', [ $this, 'expose_bricks_meta' ], 20 );

		/*
		 * L'écran d'ouverture de l'inspection ne se charge qu'en administration.
		 *
		 * Il est la seule façon d'ouvrir sur un site dont nous n'avons pas les
		 * fichiers — et il exige une session interactive, ce qui est exactement
		 * la propriété recherchée : un mot de passe d'application qui fuit
		 * n'ouvre pas wp-admin, donc n'ouvre pas le livre.
		 */
		if ( is_admin() ) {
			( new Admin_Inspection() )->register();
		}
	}

	public function register_routes(): void {
		foreach ( self::CONTROLLERS as $controller ) {
			( new $controller() )->register_routes();
		}
	}

	/**
	 * Rend les metas Bricks lisibles via l'API REST standard.
	 *
	 * Bricks préfixe ses metas par « _ » : elles sont protégées et invisibles
	 * pour wp/v2. On les expose en lecture seule pour permettre au MCP de
	 * repérer rapidement les pages construites avec Bricks sans requête
	 * supplémentaire. L'écriture passe exclusivement par anode/v1/bricks/*,
	 * qui valide et régénère le CSS.
	 *
	 * « Lecture seule » n'était qu'une intention : `register_post_meta` pose
	 * l'`auth_callback` comme filtre `auth_post_meta_<clé>_for_<type>`, et
	 * `map_meta_cap` s'en sert pour accorder `edit_post_meta` — y compris sur une
	 * méta protégée. Un callback qui répond « oui » rendait donc la méta
	 * inscriptible par /wp/v2/pages, hors de toute validation : basculer
	 * `_bricks_editor_mode` sur « wordpress » suffit à faire disparaître la mise
	 * en page du site, alors que la page n'a pas changé d'un octet.
	 *
	 * Le refus ne coûte rien en lecture : `WP_REST_Meta_Fields::get_value()` ne
	 * consulte pas l'`auth_callback` — seule l'écriture passe par lui.
	 */
	public function expose_bricks_meta(): void {
		if ( ! Bricks_Adapter::is_available() ) {
			return;
		}

		foreach ( Bricks_Adapter::editable_post_types() as $post_type ) {
			register_post_meta(
				$post_type,
				Bricks_Adapter::META_EDITOR_MODE,
				[
					'type'          => 'string',
					'single'        => true,
					'show_in_rest'  => true,
					'auth_callback' => static fn (): bool => false,
				]
			);
		}
	}
}
