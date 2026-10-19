<?php
/**
 * Inspection : lire un site entier, en lecture seule et pour un temps borné.
 *
 * ## Pourquoi ces routes existent
 *
 * Le pont n'exposait que ce qu'il sait écrire. C'est une bonne règle pour un
 * site qu'on construit, et une impasse pour un site qu'on **reprend** : mesuré
 * le 19/10/2026 sur labelequipee.com, un site client à refaire, quatre choses
 * restaient introuvables malgré un compte administrateur et son mot de passe
 * d'application —
 *
 * | Introuvable | Conséquence |
 * |---|---|
 * | les fichiers du thème enfant | ses hooks `gform_*` invisibles |
 * | les métadonnées non enregistrées | `wp/v2` ne rendait que `footnotes` |
 * | les tables propres aux extensions | définitions Gravity Forms hors de portée |
 * | les options hors liste blanche | `theme_mods`, réglages d'extensions |
 *
 * `export-wordpress.mjs` couvre déjà ce besoin **quand on a SSH**. Ces routes
 * sont pour l'autre cas, le plus fréquent en reprise : on n'a que HTTP.
 *
 * ## Pourquoi elles dorment
 *
 * Le pont vit en mu-plugin, que le client ne peut pas désactiver, et il est posé
 * sur des sites de production. Une route « lis n'importe quel fichier » ouverte
 * en permanence transformerait un mot de passe d'application qui fuit en
 * divulgation du code et de la base — sur chaque site du parc, sans que rien ne
 * le signale. C'est précisément le genre d'écart que ce dépôt refuse ailleurs :
 * liste blanche des réglages, PHP interdit dans Bricks, aucune extension tierce.
 *
 * D'où la forme retenue : **fermé par défaut, ouvert pour une durée écrite, et
 * visible tant que c'est ouvert.**
 *
 * ## Les deux façons d'ouvrir, et pourquoi il en faut deux
 *
 *     wp config set ANODE_INSPECTION 2026-11-15 --type=constant
 *
 * La constante suppose un accès fichier. Or le site qui a motivé ces routes est
 * justement celui où nous n'en avons pas : la constante n'y serait jamais posée.
 * D'où la seconde voie, l'option `anode_bridge_inspection_until`, que pose
 * l'écran Outils → Inspection Anode.
 *
 * Cet écran demande une **session d'administration interactive**. C'est ce qui
 * préserve la propriété qui compte : un mot de passe d'application qui fuit ne
 * suffit pas à ouvrir le livre, puisqu'il n'ouvre pas wp-admin.
 *
 * ## Ce qui reste refusé, même ouvert
 *
 * - toute écriture : ces routes n'en ont aucune ;
 * - tout ce qui sort de `wp-content` — `realpath()` tranche, pas la chaîne ;
 * - les fichiers binaires, et au-delà de 512 Kio par lecture ;
 * - les valeurs qui ressemblent à un secret, expurgées sans possibilité de
 *   contournement — leur **nom** reste visible, car savoir qu'une clé existe
 *   fait partie de la reprise.
 *
 * Et chaque lecture passe au journal d'audit : une inspection laisse une trace
 * aussi lisible qu'une écriture.
 *
 * @package Anode\Bridge
 */

declare( strict_types = 1 );

namespace Anode\Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rest_Inspection {

	/** Option portant la date de fin d'inspection (AAAA-MM-JJ). */
	public const OPTION_UNTIL = 'anode_bridge_inspection_until';

	/**
	 * Durée maximale d'une ouverture, en jours.
	 *
	 * Déclarée ici et lue par l'écran d'administration : deux constantes pour la
	 * même borne finiraient par diverger, et c'est la plus permissive qui ferait
	 * loi sans qu'on s'en aperçoive.
	 */
	public const MAX_JOURS = 30;

	/** Taille maximale rendue en une lecture de fichier. */
	private const MAX_BYTES = 524288;

	/** Lignes maximales rendues en une lecture de table. */
	private const MAX_ROWS = 200;

	/**
	 * Extensions lisibles.
	 *
	 * Une liste blanche, et non une liste noire : un `.sql` ou un `.zip` oublié
	 * dans `wp-content` est précisément ce qu'on ne veut pas servir par mégarde.
	 *
	 * @var list<string>
	 */
	private const EXTENSIONS = [
		'php', 'js', 'mjs', 'css', 'scss', 'json', 'txt', 'md',
		'html', 'htm', 'twig', 'yml', 'yaml', 'xml', 'svg', 'po', 'pot',
	];

	/**
	 * Motifs de clés dont la valeur est expurgée.
	 *
	 * Appliqués aux noms d'options, de métadonnées et de colonnes. La casse est
	 * ignorée.
	 *
	 * @var list<string>
	 */
	private const SECRETS = [
		'pass', 'pwd', 'secret', 'token', 'nonce', 'salt', 'private',
		'_key', 'key_', 'apikey', 'api_key', 'licen', 'auth', 'credential',
		'smtp', 'oauth', 'session', 'cookie', 'hash',
	];

	public function register_routes(): void {
		$lecture = [
			'methods'             => 'GET',
			'permission_callback' => [ self::class, 'permission' ],
		];

		register_rest_route( NAMESPACE_, '/inspect', $lecture + [ 'callback' => [ $this, 'status' ] ] );

		/*
		 * Ouvrir depuis le MCP : la garantie que cela coûte, mesurée.
		 *
		 * Premier jet : ces deux routes n'existaient pas, au motif qu'un mot de
		 * passe d'application ne devait pas suffire à ouvrir le site. Mesuré le
		 * 19/10/2026 sur la recette, ce raisonnement ne tenait pas : avec le
		 * même mot de passe, `GET /wp/v2/plugins` rend **200**. Le compte du pont
		 * est administrateur — il l'est par nécessité, les classes globales et
		 * les réglages l'exigent — donc il installe et active une extension,
		 * donc il exécute du PHP, donc il lit déjà tous les fichiers et toute la
		 * base. Par un chemin plus direct que celui-ci, et sans laisser la trace
		 * que l'inspection laisse.
		 *
		 * Refuser ces routes ne protégeait de rien : cela rendait seulement la
		 * voie légitime inconfortable. Ce qui garde de la valeur — fermé par
		 * défaut, date de fin, journal d'audit, sonde rouge — ne dépend pas de
		 * qui ouvre.
		 *
		 * **La réserve, elle, tient** : `DISALLOW_FILE_MODS` coupe l'installation
		 * d'extensions. Sur un site qui la pose, le raccourci par le PHP n'existe
		 * plus, et ouvrir par le MCP abaisserait réellement la barre. Ces routes
		 * s'y refusent donc, et renvoient à l'écran d'administration.
		 */
		$ouverture = [
			'methods'             => 'POST',
			'permission_callback' => [ Security::class, 'permission_manage' ],
		];

		register_rest_route( NAMESPACE_, '/inspect/open', $ouverture + [ 'callback' => [ $this, 'open' ] ] );
		register_rest_route( NAMESPACE_, '/inspect/close', $ouverture + [ 'callback' => [ $this, 'close' ] ] );
		register_rest_route( NAMESPACE_, '/inspect/files', $lecture + [ 'callback' => [ $this, 'files' ] ] );
		register_rest_route( NAMESPACE_, '/inspect/meta', $lecture + [ 'callback' => [ $this, 'meta' ] ] );
		register_rest_route( NAMESPACE_, '/inspect/options', $lecture + [ 'callback' => [ $this, 'options' ] ] );
		register_rest_route( NAMESPACE_, '/inspect/tables', $lecture + [ 'callback' => [ $this, 'tables' ] ] );
		register_rest_route( NAMESPACE_, '/inspect/table', $lecture + [ 'callback' => [ $this, 'table' ] ] );
	}

	/* --- Ouverture ------------------------------------------------------- */

	/**
	 * Date de fin d'inspection, ou null si fermée.
	 *
	 * La constante l'emporte sur l'option : elle demande un accès fichier, donc
	 * une intention plus forte.
	 */
	public static function open_until(): ?string {
		foreach ( [ self::from_constant(), self::from_option() ] as $date ) {
			if ( null !== $date && strtotime( $date . ' 23:59:59' ) >= time() ) {
				return $date;
			}
		}

		return null;
	}

	public static function is_open(): bool {
		return null !== self::open_until();
	}

	private static function from_constant(): ?string {
		return defined( 'ANODE_INSPECTION' ) ? self::normalize( (string) constant( 'ANODE_INSPECTION' ) ) : null;
	}

	private static function from_option(): ?string {
		$valeur = get_option( self::OPTION_UNTIL, '' );

		return is_string( $valeur ) && '' !== $valeur ? self::normalize( $valeur ) : null;
	}

	/**
	 * N'accepte qu'une date AAAA-MM-JJ.
	 *
	 * Un `true` posé par habitude — c'est ce que prend `ANODE_BLUEPRINT` —
	 * ouvrirait sans fin. Il est donc refusé, et l'inspection reste fermée.
	 */
	private static function normalize( string $valeur ): ?string {
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', trim( $valeur ) ) ? trim( $valeur ) : null;
	}

	/**
	 * Permission : administrateur **et** inspection ouverte.
	 */
	public static function permission(): bool|\WP_Error {
		$manage = Security::permission_manage();

		if ( $manage instanceof \WP_Error ) {
			return $manage;
		}

		if ( ! self::is_open() ) {
			return new \WP_Error(
				'anode_inspection_closed',
				'L’inspection est fermée sur ce site. Elle s’ouvre pour une durée bornée : '
					. 'Outils → Inspection Anode, ou wp config set ANODE_INSPECTION AAAA-MM-JJ --type=constant.',
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/* --- Routes ---------------------------------------------------------- */

	public function status( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response(
			[
				'open'       => true,
				'until'      => self::open_until(),
				'source'     => null !== self::from_constant() ? 'constante' : 'option',
				'content_dir' => wp_basename( WP_CONTENT_DIR ),
			]
		);
	}

	/**
	 * Ouvre l'inspection jusqu'à une date, avec un motif.
	 *
	 * Le motif n'est pas décoratif : c'est lui qu'on relit dans le journal six
	 * mois plus tard pour savoir si l'ouverture était justifiée. Il est donc
	 * exigé, et une chaîne vide est refusée.
	 */
	public function open( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		if ( defined( 'DISALLOW_FILE_MODS' ) && constant( 'DISALLOW_FILE_MODS' ) ) {
			return new \WP_Error(
				'anode_inspection_hardened',
				'Ce site pose DISALLOW_FILE_MODS : aucune extension ne peut y être installée, donc un mot de '
					. 'passe d’application n’y donne pas déjà la lecture des fichiers. Ouvrir l’inspection depuis '
					. 'le MCP abaisserait réellement la barre — elle s’ouvre ici depuis wp-admin, '
					. 'Outils → Inspection Anode.',
				[ 'status' => 403 ]
			);
		}

		$jusqu = self::normalize( (string) $request->get_param( 'until' ) );
		$motif = trim( (string) $request->get_param( 'reason' ) );

		if ( null === $jusqu ) {
			return new \WP_Error( 'anode_inspection_date', 'Fournissez « until » au format AAAA-MM-JJ.', [ 'status' => 400 ] );
		}

		if ( '' === $motif ) {
			return new \WP_Error(
				'anode_inspection_reason',
				'Fournissez « reason » : le motif de l’ouverture est ce qu’on relit dans le journal pour savoir '
					. 'si elle était justifiée.',
				[ 'status' => 400 ]
			);
		}

		$limite = time() + self::MAX_JOURS * DAY_IN_SECONDS;

		if ( (int) strtotime( $jusqu . ' 23:59:59' ) > $limite ) {
			return new \WP_Error(
				'anode_inspection_duree',
				sprintf( 'Trente jours au plus : le %s est trop loin.', $jusqu ),
				[ 'status' => 400 ]
			);
		}

		update_option( self::OPTION_UNTIL, $jusqu, false );
		Security::log( 'inspection.open', [ 'until' => $jusqu, 'reason' => $motif, 'via' => 'mcp' ] );

		return rest_ensure_response( [ 'open' => true, 'until' => $jusqu, 'reason' => $motif ] );
	}

	/**
	 * Referme l'inspection.
	 *
	 * Ne retire pas la constante — un fichier ne s'édite pas depuis une requête.
	 * Si l'ouverture vient d'elle, la réponse le dit plutôt que de laisser croire
	 * à une fermeture qui n'a pas eu lieu.
	 */
	public function close( \WP_REST_Request $request ): \WP_REST_Response {
		delete_option( self::OPTION_UNTIL );
		Security::log( 'inspection.close', [ 'via' => 'mcp' ] );

		$constante = self::from_constant();

		return rest_ensure_response(
			[
				'open'      => null !== $constante,
				'until'     => $constante,
				'constante' => null !== $constante
					? 'ANODE_INSPECTION est toujours posée dans wp-config.php : l’inspection reste ouverte par '
						. 'elle. La retirer : wp config delete ANODE_INSPECTION.'
					: null,
			]
		);
	}

	/**
	 * Liste un dossier, ou rend le contenu d'un fichier.
	 */
	public function files( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$demande = (string) $request->get_param( 'path' );
		$chemin  = $this->resolve( $demande );

		if ( $chemin instanceof \WP_Error ) {
			return $chemin;
		}

		Security::log( 'inspection.files', [ 'path' => $demande ] );

		if ( is_dir( $chemin ) ) {
			return rest_ensure_response( [ 'path' => $demande, 'type' => 'dossier', 'entries' => $this->listing( $chemin ) ] );
		}

		$extension = strtolower( pathinfo( $chemin, PATHINFO_EXTENSION ) );

		if ( ! in_array( $extension, self::EXTENSIONS, true ) && '.htaccess' !== wp_basename( $chemin ) ) {
			return new \WP_Error(
				'anode_inspection_extension',
				sprintf( 'Extension « %s » non lisible. Lisibles : %s.', $extension, implode( ', ', self::EXTENSIONS ) ),
				[ 'status' => 415 ]
			);
		}

		$taille  = (int) filesize( $chemin );
		$offset  = max( 0, (int) $request->get_param( 'offset' ) );
		$contenu = (string) file_get_contents( $chemin, false, null, $offset, self::MAX_BYTES ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		return rest_ensure_response(
			[
				'path'      => $demande,
				'type'      => 'fichier',
				'bytes'     => $taille,
				'offset'    => $offset,
				'truncated' => $offset + strlen( $contenu ) < $taille,
				'content'   => $contenu,
			]
		);
	}

	/**
	 * Toutes les métadonnées d'un contenu, protégées comprises.
	 *
	 * `wp/v2` ne rend que les metas **enregistrées** : sur un site hérité, cela
	 * revient à ne rien rendre. Mesuré sur une page YOOtheme : une seule clé,
	 * `footnotes`, pendant que la mise en page vivait ailleurs.
	 */
	public function meta( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id = (int) $request->get_param( 'post' );

		if ( ! get_post( $id ) ) {
			return new \WP_Error( 'anode_inspection_post', sprintf( 'Aucun contenu d’identifiant %d.', $id ), [ 'status' => 404 ] );
		}

		Security::log( 'inspection.meta', [ 'post' => $id ] );

		$metas = [];

		foreach ( get_post_meta( $id ) as $cle => $valeurs ) {
			$metas[] = [
				'key'    => $cle,
				'values' => array_map( fn ( $v ) => $this->redact( (string) $cle, (string) $v ), (array) $valeurs ),
			];
		}

		return rest_ensure_response( [ 'post' => $id, 'meta' => $metas ] );
	}

	/**
	 * Options, filtrées par préfixe ou par fragment.
	 */
	public function options( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		$prefixe  = (string) $request->get_param( 'prefix' );
		$fragment = (string) $request->get_param( 'search' );
		$limite   = min( self::MAX_ROWS, max( 1, (int) ( $request->get_param( 'limit' ) ?: 100 ) ) );

		$like = '' !== $prefixe
			? $wpdb->esc_like( $prefixe ) . '%'
			: '%' . $wpdb->esc_like( $fragment ) . '%';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$lignes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name LIMIT %d",
				$like,
				$limite
			),
			ARRAY_A
		);

		Security::log( 'inspection.options', [ 'prefix' => $prefixe, 'search' => $fragment, 'found' => count( (array) $lignes ) ] );

		return rest_ensure_response(
			[
				'options' => array_map(
					fn ( array $l ): array => [
						'name'  => $l['option_name'],
						'bytes' => strlen( (string) $l['option_value'] ),
						'value' => $this->redact( $l['option_name'], (string) $l['option_value'] ),
					],
					(array) $lignes
				),
			]
		);
	}

	/**
	 * Tables de cette installation, avec leur nombre de lignes.
	 */
	public function tables( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb;

		Security::log( 'inspection.tables' );

		$tables = [];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) ) as $nom ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
			$tables[] = [ 'name' => $nom, 'rows' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$nom}`" ) ];
		}

		return rest_ensure_response( [ 'prefix' => $wpdb->prefix, 'tables' => $tables ] );
	}

	/**
	 * Lignes d'une table de cette installation.
	 *
	 * Le nom est **vérifié contre la liste réelle** des tables, jamais interpolé
	 * depuis la requête : c'est la seule façon sûre d'écrire un identifiant SQL,
	 * que `prepare()` ne sait pas échapper.
	 */
	public function table( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		global $wpdb;

		$demande = (string) $request->get_param( 'name' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$connues = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ) );

		if ( ! in_array( $demande, $connues, true ) ) {
			return new \WP_Error(
				'anode_inspection_table',
				sprintf( 'Table « %s » inconnue sur cette installation.', $demande ),
				[ 'status' => 404 ]
			);
		}

		$limite = min( self::MAX_ROWS, max( 1, (int) ( $request->get_param( 'limit' ) ?: 50 ) ) );
		$offset = max( 0, (int) $request->get_param( 'offset' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		$lignes = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$demande}` LIMIT %d OFFSET %d", $limite, $offset ), ARRAY_A );

		Security::log( 'inspection.table', [ 'table' => $demande, 'limit' => $limite, 'offset' => $offset ] );

		return rest_ensure_response(
			[
				'table' => $demande,
				'rows'  => array_map( [ $this, 'redact_row' ], (array) $lignes ),
			]
		);
	}

	/**
	 * Expurge une ligne, en tenant compte de ce que la ligne **désigne**.
	 *
	 * Premier jet : l'expurgation se faisait sur le nom de colonne. Dans
	 * `wp_options` la colonne s'appelle `option_value` — aucun motif ne
	 * correspond, et lire la table rendait en clair la clé de licence que la
	 * route `/options` expurge. Mesuré sur la recette avant correction.
	 *
	 * La parade : si la ligne porte une colonne d'étiquette — `option_name`,
	 * `meta_key`, `name`, `key` — sa valeur sert aussi de clé d'expurgation pour
	 * toute la ligne. Une ligne nommée « …_license_key » est expurgée quel que
	 * soit le nom de la colonne qui la contient.
	 *
	 * @param array<string, mixed> $ligne
	 * @return array<string, string>
	 */
	private function redact_row( array $ligne ): array {
		$etiquette = '';
		$porteuse  = '';

		foreach ( [ 'option_name', 'meta_key', 'name', 'key', 'slug' ] as $colonne ) {
			if ( isset( $ligne[ $colonne ] ) && is_scalar( $ligne[ $colonne ] ) ) {
				$etiquette = (string) $ligne[ $colonne ];
				$porteuse  = $colonne;
				break;
			}
		}

		$rendue = [];

		foreach ( $ligne as $colonne => $valeur ) {
			/*
			 * La colonne d'étiquette n'est jamais expurgée — sinon elle s'expurge
			 * elle-même : mesuré sur la recette, une ligne « bricks_license_key »
			 * voyait son propre nom remplacé, et devenait introuvable. Or savoir
			 * qu'une clé existe, et à quel nom, fait partie de ce qu'on reprend ;
			 * c'est sa valeur qui ne doit pas sortir.
			 */
			$rendue[ $colonne ] = $colonne === $porteuse
				? (string) $valeur
				: $this->redact( $colonne . ' ' . $etiquette, (string) $valeur );
		}

		return $rendue;
	}

	/* --- Outils ---------------------------------------------------------- */

	/**
	 * Résout un chemin **dans** wp-content, ou refuse.
	 *
	 * `realpath()` et non une comparaison de chaînes : un lien symbolique ou un
	 * `..` encodé passe la seconde et pas la première.
	 */
	private function resolve( string $demande ): string|\WP_Error {
		$racine = realpath( WP_CONTENT_DIR );
		$cible  = realpath( rtrim( $racine . '/' . ltrim( $demande, '/' ), '/' ) );

		if ( false === $cible || ! str_starts_with( $cible, (string) $racine ) ) {
			return new \WP_Error(
				'anode_inspection_path',
				'Chemin hors de wp-content, ou inexistant. Les chemins sont relatifs à wp-content.',
				[ 'status' => 400 ]
			);
		}

		return $cible;
	}

	/**
	 * @return list<array{name: string, type: string, bytes: int}>
	 */
	private function listing( string $dossier ): array {
		$entrees = [];

		foreach ( (array) scandir( $dossier ) as $nom ) {
			if ( '.' === $nom || '..' === $nom ) {
				continue;
			}

			$chemin    = $dossier . '/' . $nom;
			$entrees[] = [
				'name'  => $nom,
				'type'  => is_dir( $chemin ) ? 'dossier' : 'fichier',
				'bytes' => is_dir( $chemin ) ? 0 : (int) filesize( $chemin ),
			];
		}

		return $entrees;
	}

	/**
	 * Expurge une valeur dont le nom ressemble à un secret.
	 *
	 * Le nom reste rendu : savoir qu'une clé d'API existe, et à quel nom, fait
	 * partie de ce qu'on reprend. Sa valeur, non.
	 */
	private function redact( string $nom, string $valeur ): string {
		$minuscule = strtolower( $nom );

		foreach ( self::SECRETS as $motif ) {
			if ( str_contains( $minuscule, $motif ) ) {
				return sprintf( '[expurgé — %d caractères]', strlen( $valeur ) );
			}
		}

		return strlen( $valeur ) > self::MAX_BYTES
			? substr( $valeur, 0, self::MAX_BYTES ) . sprintf( '… [tronqué, %d caractères au total]', strlen( $valeur ) )
			: $valeur;
	}
}
