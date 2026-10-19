<?php
/**
 * État de santé des extensions maison — chargement, version, fonctionnement.
 *
 * WordPress ne sait pas gérer les mu-plugins rangés en sous-dossier : il ne les
 * liste pas, ne dit pas s'ils ont été chargés, et n'affiche jamais leur version.
 * Un composant peut donc être **présent sur le disque et totalement inerte** —
 * chargeur absent, copie dans `plugins/` qu'on croit tester, erreur fatale
 * avalée par le silence de la production.
 *
 * Cette route répond à trois questions distinctes, et l'ordre compte :
 *
 *   1. le composant est-il **chargé** ? — un symbole de son espace de noms existe
 *      en mémoire. C'est la seule preuve : un fichier lisible ne prouve rien.
 *   2. quelle **version** tourne ? — relue dans l'en-tête du fichier chargé, pas
 *      dans le dépôt.
 *   3. **fait-il son travail** ? — une sonde par composant, qui vérifie ce qui
 *      casserait en silence : un dossier de cache non inscriptible, un `id` de
 *      champ SEO non exposé à l'API, un slug de connexion resté par défaut.
 *
 * @package Anode\Bridge
 */

declare( strict_types = 1 );

namespace Anode\Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rest_Health {

	/**
	 * Composants attendus, et le symbole qui prouve leur chargement.
	 *
	 * Le symbole est choisi dans l'espace de noms du composant : il ne peut pas
	 * être défini par autre chose. `anode-bridge` n'y figure pas — s'il ne
	 * répondait pas, il n'y aurait pas de réponse du tout.
	 *
	 * @var array<string, array{symbole: string, genre: string}>
	 */
	private const COMPOSANTS = [
		'anode-hardening'    => [ 'symbole' => 'Anode\Hardening\login_slug', 'genre' => 'function' ],
		'anode-seo'          => [ 'symbole' => 'Anode\Seo\Seo', 'genre' => 'class' ],
		'anode-forms'        => [ 'symbole' => 'Anode\Forms\definitions_dir', 'genre' => 'function' ],
		'anode-redirections' => [ 'symbole' => 'Anode\Redirections\table_path', 'genre' => 'function' ],
		'anode-updater'      => [ 'symbole' => 'Anode\Updater\Updater', 'genre' => 'class' ],
		/*
		 * Le confort du builder manquait à cette liste, et le manque était symétrique
		 * dans le blueprint. Le composant pouvait être absent, non chargé, ou
		 * servi sans ses ressources, et le point de santé répondait « tous les
		 * composants au vert ». C'est le défaut que le commentaire des quatre
		 * composants ci-dessous décrit déjà — une liste écrite pour ce qui existait ce
		 * jour-là, que personne ne complète quand un composant arrive.
		 */
		'anode-builder'      => [ 'symbole' => 'Anode\Builder\fonctions_actives', 'genre' => 'function' ],
		'anode-plan'         => [ 'symbole' => 'Anode\\Plan\\Plan', 'genre' => 'class' ],
		'anode-animations'   => [ 'symbole' => 'Anode\\Animations\\Animations', 'genre' => 'class' ],
		'anode-retours'      => [ 'symbole' => 'Anode\\Retours\\Retours', 'genre' => 'class' ],
	];

	/**
	 * Inventaires relevés une fois : la sonde les lit, la réponse les rend.
	 *
	 * @var list<array{file: string, name: string, version: ?string, active: bool}>|null
	 */
	private ?array $extensions = null;

	/** @var list<string>|null */
	private ?array $themes = null;

	public function register_routes(): void {
		register_rest_route(
			NAMESPACE_,
			'/health',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'get_health' ],
				'permission_callback' => [ Security::class, 'permission_read' ],
			]
		);
	}

	public function get_health(): \WP_REST_Response {
		$mu = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';

		$composants = [];
		$problemes  = [];

		/*
		 * Le chargeur d'abord : sans lui, tout le reste est sur le disque et rien
		 * n'est en mémoire. Le diagnostic « six composants absents » vient presque
		 * toujours de ce seul fichier.
		 */
		$chargeur = $mu . '/anode-loader.php';
		$loader   = [
			'present' => is_readable( $chargeur ),
			/*
			 * Chemin relatif à `wp-content` : la route est ouverte en lecture, et
			 * l'arborescence d'installation d'un serveur n'a pas à en sortir. Le
			 * chemin complet ne dit rien de plus à qui diagnostique.
			 */
			'path'    => 'mu-plugins/anode-loader.php',
		];

		if ( ! $loader['present'] ) {
			$problemes[] = 'Le chargeur anode-loader.php est absent : aucun mu-plugin en sous-dossier n’est chargé.';
		}

		foreach ( self::COMPOSANTS as $nom => $attendu ) {
			$dossier = $mu . '/' . $nom;
			$fichier = $dossier . '/' . $nom . '.php';
			$charge  = 'class' === $attendu['genre']
				? class_exists( $attendu['symbole'] )
				: function_exists( $attendu['symbole'] );

			$entree = [
				'name'      => $nom,
				'present'   => is_readable( $fichier ),
				'loaded'    => $charge,
				'version'   => $this->version( $fichier ),
				'checks'    => $charge ? $this->sondes( $nom ) : [],
			];

			if ( ! $entree['present'] ) {
				$problemes[] = sprintf( '%s : absent de mu-plugins/.', $nom );
			} elseif ( ! $charge ) {
				$problemes[] = sprintf(
					'%s : présent mais **non chargé** — %s introuvable en mémoire. Erreur fatale avalée, ou chargeur muet.',
					$nom,
					$attendu['symbole']
				);
			}

			foreach ( $entree['checks'] as $sonde ) {
				if ( ! $sonde['ok'] ) {
					$problemes[] = sprintf( '%s : %s', $nom, $sonde['detail'] );
				}
			}

			$composants[] = $entree;
		}

		/*
		 * Le pont lui-même : il répond, donc il tourne.
		 *
		 * Son entrée est ajoutée **après** la boucle ci-dessus — donc après la
		 * collecte des problèmes. Conséquence mesurée le 04/08/2026 : le blueprint
		 * répondait « ok: true, problems: 0 » avec la sonde `site-favicon` du pont
		 * au rouge. Toutes les sondes du pont étaient muettes depuis qu'elles
		 * existent, et le point de santé annonçait le contraire de ce qu'il avait
		 * mesuré. Ses échecs sont donc reversés explicitement.
		 */
		$sondes_pont = $this->sondes( 'anode-bridge' );

		$composants[] = [
			'name'    => 'anode-bridge',
			'present' => true,
			'loaded'  => true,
			'version' => ANODE_BRIDGE_VERSION,
			'checks'  => $sondes_pont,
		];

		foreach ( $sondes_pont as $sonde ) {
			if ( ! $sonde['ok'] ) {
				$problemes[] = sprintf( 'anode-bridge : %s', $sonde['detail'] );
			}
		}

		/*
		 * Une copie dans `plugins/` est inerte : le chargeur ne lit que les
		 * sous-dossiers de `mu-plugins/`. On croit alors tester une version qui
		 * n'est jamais exécutée — l'erreur a déjà fait perdre une demi-journée.
		 */
		$doublons = [];

		/*
		 * `array_keys(…) + […]` est une **union de tableaux**, pas une
		 * concaténation : les deux listes sont indexées depuis 0, la collision
		 * d'indice écartait « anode-bridge » — le seul composant qu'on installe
		 * parfois d'abord en extension, donc celui qui a le plus de chances
		 * d'avoir une copie inerte dans plugins/.
		 */
		foreach ( array_merge( array_keys( self::COMPOSANTS ), [ 'anode-bridge' ] ) as $nom ) {
			if ( is_dir( WP_PLUGIN_DIR . '/' . $nom ) ) {
				$doublons[]  = $nom;
				$problemes[] = sprintf(
					'%s : une copie existe dans plugins/. Elle est **inerte** — le chargeur ne lit que mu-plugins/.',
					$nom
				);
			}
		}

		/*
		 * La liste peut être en retard sur le disque — c'est arrivé deux fois ici, et
		 * une troisième sur le pont voisin. On le dit avant de conclure quoi que ce
		 * soit sur la santé du site : un composant hors liste n'est ni sondé, ni
		 * versionné dans la réponse, et son absence de la liste se lit exactement
		 * comme sa conformité.
		 */
		$inconnus = $this->hors_liste( $mu );

		foreach ( $inconnus as $nom ) {
			$problemes[] = sprintf(
				'%s : présent dans mu-plugins/ mais **absent de la liste des composants** du pont. Il n’est donc ni sondé ni suivi — sa version n’est pas relevée et une panne y serait muette. À déclarer dans Rest_Health::COMPOSANTS.',
				$nom
			);
		}

		$fatales = $this->fatales_recentes();

		if ( $fatales ) {
			$problemes[] = sprintf( '%d erreur(s) fatale(s) mentionnant « anode » dans le journal de débogage.', count( $fatales ) );
		}

		/*
		 * Une ligne de debug.log cite le chemin absolu du fichier, la trace
		 * d'appel et parfois un extrait de requête : c'est de la reconnaissance
		 * offerte à qui n'a que la lecture. Le **nombre** reste dans les
		 * problèmes — il suffit à savoir qu'il faut regarder — mais le contenu
		 * n'est servi qu'à l'administration.
		 */
		$journal = Security::can_manage() ? $fatales : [];

		return rest_ensure_response(
			[
				'ok'          => ! $problemes,
				/*
				 * Le masquage de l'URL de connexion pouvait s'éteindre selon
				 * l'environnement. Sans cette information, un test de bout en bout
				 * conclut soit à un défaut, soit — bien pire — à un succès qui ne
				 * prouve rien : « la page n'est pas en cache » est vrai quand le
				 * cache est éteint.
				 */
				'environment' => wp_get_environment_type(),
				'features'    => [
					'login_masking' => function_exists( 'Anode\Hardening\login_masking_enabled' )
						&& \Anode\Hardening\login_masking_enabled(),
				],
				'loader'      => $loader,
				'components'  => $composants,
				'unlisted'    => $inconnus,
				'shadowed'    => $doublons,
				/*
				 * L'inventaire, et pas seulement le verdict des sondes : c'était
				 * le trou nommé par /audit-wp — « le pont n'expose pas
				 * d'inventaire des extensions », donc un point qu'on déclarait
				 * non vérifié sur chaque site en ligne.
				 *
				 * Les versions restent à l'administration, comme les fatales :
				 * la route est ouverte à un compte qui n'a que `edit_pages`, et
				 * la version d'une extension tierce est de la reconnaissance.
				 * Les sondes, elles, répondent à tout le monde.
				 *
				 * `null` et non `[]` : « je ne le dis pas » n'est pas « il n'y
				 * en a aucune ». Un tableau vide se lirait comme un site propre.
				 */
				'plugins'     => Security::can_manage() ? $this->inventaire_extensions() : null,
				'themes'      => [
					'installed' => $this->inventaire_themes(),
					'active'    => get_stylesheet(),
					'parent'    => get_template(),
				],
				'fatals'      => $journal,
				'problems'    => $problemes,
			]
		);
	}

	/**
	 * Version déclarée dans l'en-tête du fichier réellement installé.
	 *
	 * C'est de là que découlent le tag, l'archive et la comparaison faite par
	 * `anode-updater` : lire ailleurs donnerait une réponse qui n'engage rien.
	 */
	private function version( string $fichier ): ?string {
		if ( ! is_readable( $fichier ) ) {
			return null;
		}

		$tete = (string) file_get_contents( $fichier, false, null, 0, 4096 );

		return preg_match( '/^\s*\*?\s*Version:\s*([0-9][0-9.]*)/mi', $tete, $trouve )
			? $trouve[1]
			: null;
	}

	/**
	 * Sondes fonctionnelles d'un composant.
	 *
	 * Chacune vise ce qui **casse en silence** : le composant est chargé, la page
	 * s'affiche, et la fonction ne se fait pas. Une sonde qui se contenterait de
	 * vérifier une constante ne vaudrait pas la ligne qu'elle occupe.
	 *
	 * @return list<array{id: string, ok: bool, detail: string}>
	 */
	private function sondes( string $nom ): array {
		switch ( $nom ) {
			case 'anode-forms':
				return $this->sondes_forms();

			case 'anode-hardening':
				return $this->sondes_hardening();

			case 'anode-seo':
				return $this->sondes_seo();

			case 'anode-redirections':
				return $this->sondes_redirections();

			case 'anode-updater':
				return $this->sondes_updater();

			case 'anode-builder':
				return $this->sondes_builder();

			case 'anode-bridge':
				return $this->sondes_bridge();

			case 'anode-animations':
				return $this->sondes_animations();

			case 'anode-plan':
				return $this->sondes_plan();
			case 'anode-retours':
				return $this->sondes_retours();
		}

		return [];
	}

	/**
	 * Les pages d'atelier : servies en préproduction, muettes en production.
	 *
	 * Le composant était suivi par le canal de mise à jour et **sondé par
	 * personne** : chargé, et rien de plus. Or ses deux façons de se tromper sont
	 * silencieuses et opposées.
	 *
	 * Servir `/plan` en **production**, d'abord : ces pages exposent le plan du
	 * site, ses formulaires, sa charte et ses composants à qui passe. Rien ne le
	 * signale — la page s'affiche, joliment.
	 *
	 * Ne rien servir en **préproduction**, ensuite : l'atelier est là pour être
	 * lu, et son absence ressemble à une page qui n'existe pas encore.
	 *
	 * On interroge donc l'environnement déclaré et la présence des routes, pas la
	 * simple existence du composant.
	 */
	private function sondes_plan(): array {
		/*
		 * L'autorité est celle que le composant interroge lui-même :
		 * `\Anode\Seo\Seo::production_declared()`. Une sonde qui appelle un
		 * symbole inventé retombe sur son repli et mesure autre chose — sans un
		 * mot, puisque `function_exists` rend simplement `false`.
		 */
		$production = is_callable( [ '\\Anode\\Seo\\Seo', 'production_declared' ] )
			? (bool) \Anode\Seo\Seo::production_declared()
			: 'production' === wp_get_environment_type();

		/*
		 * L'atelier ne passe pas par le REST : il pose des **règles de réécriture**
		 * et une variable de requête. Chercher une route `/v1/plan` rendait donc
		 * toujours « absent », y compris sur une préproduction où l'atelier
		 * fonctionne — une sonde qui cherche un motif absent du système mesuré ne
		 * rend pas « je ne sais pas », elle rend zéro.
		 *
		 * On interroge la variable de requête, que le composant déclare dès qu'il
		 * s'estime actif, et les règles réellement enregistrées.
		 */
		$declare = in_array( 'anode_plan', (array) apply_filters( 'query_vars', [] ), true );

		if ( ! $declare ) {
			foreach ( array_keys( (array) get_option( 'rewrite_rules', [] ) ) as $regle ) {
				if ( str_starts_with( (string) $regle, '^plan' ) ) {
					$declare = true;

					break;
				}
			}
		}

		/*
		 * `sonde()` prend QUATRE arguments — id, verdict, message d'échec, message
		 * de succès. Lui en passer trois lève une TypeError, et le pont rend 500
		 * sur toute sa route de santé : une sonde ajoutée pour surveiller un
		 * composant rendait muette la surveillance entière. Mesuré en déployant.
		 */
		return [
			$this->sonde(
				'plan-environnement',
				$production ? ! $declare : $declare,
				$production
					? 'les pages d’atelier sont servies EN PRODUCTION — plan du site, formulaires et charte exposés au public'
					: 'aucune route d’atelier déclarée alors que le site n’est pas en production',
				$production
					? 'muet en production, comme attendu'
					: 'servies en préproduction, comme attendu'
			),
		];
	}

	/**
	 * Le mode de retours : la porte d'abord, la mise en service ensuite.
	 *
	 * Deux sondes, et la distinction entre elles est tout l'intérêt.
	 *
	 * La PORTE ne pardonne pas : un bouton « Faire un retour » servi en
	 * production expose au public un relais d'envoi de courriel vers des boîtes
	 * de l'agence. Le htpasswd d'une préproduction ne protège pas de cela — il
	 * protège l'environnement, pas la route.
	 *
	 * La MISE EN SERVICE, elle, est facultative : tous les sites du parc n'ont
	 * pas de relecture en cours. Elle rend donc « non mis en service » plutôt que
	 * « au vert » — c'est le travers corrigé sur l'écran des mises à jour, qui
	 * disait « à jour » de tout ce qu'il n'avait pas pu comparer.
	 *
	 * @return list<array{id: string, ok: bool, detail: string}>
	 */
	private function sondes_retours(): array {
		/*
		 * L'autorité est celle que le composant interroge lui-même. Une sonde qui
		 * recopierait la détection d'environnement mesurerait sa propre copie —
		 * et rendrait vert un site que le composant, lui, juge en production.
		 */
		$actif = is_callable( [ '\\Anode\\Retours\\Retours', 'actif' ] )
			&& \Anode\Retours\Retours::actif();

		$production = is_callable( [ '\\Anode\\Seo\\Seo', 'production_declared' ] )
			? (bool) \Anode\Seo\Seo::production_declared()
			: 'production' === wp_get_environment_type();

		$configure = defined( 'ANODE_RETOURS_MDP' ) && '' !== trim( (string) ANODE_RETOURS_MDP );

		$destinataires = is_callable( [ '\\Anode\\Retours\\Envoi', 'destinataires' ] )
			? \Anode\Retours\Envoi::destinataires()
			: [];

		$sondes = [
			$this->sonde(
				'retours-porte',
				$production ? ! $actif : true,
				'le mode de retours est SERVI EN PRODUCTION : le bouton et la route d’envoi de courriel sont exposés au public',
				$production
					? 'muet en production, comme attendu'
					: 'hors production : la porte laisse passer, comme attendu'
			),
		];

		if ( $production ) {
			return $sondes;
		}

		$sondes[] = $this->sonde(
			'retours-service',
			true,
			'',
			$configure
				? sprintf(
					'en service — %d destinataire(s), %s',
					count( $destinataires ),
					'' !== ( is_callable( [ '\\Anode\\Retours\\Envoi', 'relais' ] ) ? \Anode\Retours\Envoi::relais() : '' )
						? 'envoi par relais'
						: 'envoi par courriel du site'
				)
				: 'non mis en service : ANODE_RETOURS_MDP n’est pas définie, le composant est inerte (wp config set ANODE_RETOURS_MDP "…" --type=constant)'
		);

		/*
		 * Configuré mais sans destinataire valable : les demandes du client
		 * partiraient nulle part, et le navigateur afficherait une erreur au
		 * moment précis où il vient d'écrire quinze retours. Rouge.
		 */
		if ( $configure ) {
			$sondes[] = $this->sonde(
				'retours-destinataires',
				[] !== $destinataires,
				'le mode de retours est en service et aucun destinataire n’est valable : chaque envoi sera refusé (ANODE_RETOURS_DESTINATAIRES, ou admin_email)',
				sprintf( '%d destinataire(s) valable(s)', count( $destinataires ) )
			);
		}

		return $sondes;
	}

	/**
	 * Les animations : chargées ne suffit pas, il faut qu'elles soient accrochées.
	 *
	 * Le composant n'expose ni option, ni table, ni route : tout son effet passe
	 * par deux accroches posées au constructeur. Une classe instanciée dont les
	 * accroches ont été retirées — `remove_action` d'un thème, ordre de chargement
	 * — laisse le symbole en mémoire et la page **sans une ligne de CSS**.
	 *
	 * Ce cas ne se voit sur aucune capture : sans le CSS, `[animate]` n'est plus
	 * masqué, donc tout s'affiche — à l'état final, sans animation. Le site paraît
	 * « sans effets », ce qu'on met sur le compte d'un choix de conception.
	 *
	 * C'était l'un des deux composants à **zéro sonde** : chargé, et rien de plus.
	 *
	 * @return list<array{id: string, ok: bool, detail: string}>
	 */
	private function sondes_animations(): array {
		/*
		 * Les accroches sont posées sur une **instance**, pas sur la classe : on ne
		 * peut donc pas les retrouver par leur rappel. On demande à WordPress si
		 * quelque chose est accroché à la priorité 99, qui est celle du composant.
		 */
		$accrochee = static function ( string $accroche ): bool {
			global $wp_filter;

			return isset( $wp_filter[ $accroche ] ) && ! empty( $wp_filter[ $accroche ]->callbacks[99] );
		};

		$css = $accrochee( 'wp_head' );
		$js  = $accrochee( 'wp_footer' );

		return [
			$this->sonde(
				'animations-accroches',
				$css && $js,
				sprintf(
					'accroche(s) absente(s) à la priorité 99 : %s. Le symbole est en mémoire mais rien n’est injecté — les éléments animés s’affichent à l’état final, sans animation, et rien ne le signale.',
					implode( ', ', array_filter( [ $css ? null : 'wp_head', $js ? null : 'wp_footer' ] ) )
				),
				'CSS et moteur accrochés à la page'
			),
		];
	}

	/**
	 * Un composant sur le disque que cette classe ne connaît pas.
	 *
	 * Deux fois de suite, la liste `COMPOSANTS` a été en retard sur le blueprint :
	 * les quatre composants propres à celui-ci, puis le confort du builder. Chaque
	 * fois, la réponse annonçait « tous les composants au vert » en en ignorant un
	 * — le pire des comptes rendus, puisqu'il ferme la question.
	 *
	 * Les commentaires qui décrivent ce défaut ne l'ont pas empêché : il s'est
	 * reproduit une troisième fois sur le pont voisin, avec les animations. La
	 * réponse doit donc être **dérivée du disque**, et non d'une liste tenue à la
	 * main.
	 *
	 * Le modèle de contenu d'un site (`<slug>-contenu`) n'est pas concerné : il est
	 * généré par site, il n'a pas de version publiée, et il n'a rien à faire dans
	 * une liste commune.
	 *
	 * @param string $mu Chemin du dossier des mu-plugins.
	 * @return list<string>
	 */
	private function hors_liste( string $mu ): array {
		$dossiers = is_dir( $mu ) ? (array) glob( $mu . '/anode-*', GLOB_ONLYDIR ) : [];
		$inconnus = [];

		foreach ( $dossiers as $chemin ) {
			$nom = basename( (string) $chemin );

			/*
			 * Le modèle de contenu porte le slug du site, et un slug peut commencer
			 * par le nom de la marque : `anode-studio-contenu` tombait donc dans le
			 * filet. Le commentaire ci-dessus l'exemptait, le code non — mesuré dès
			 * la première exécution, sur le site de la marque elle-même.
			 */
			if ( isset( self::COMPOSANTS[ $nom ] ) || 'anode-bridge' === $nom
				|| str_ends_with( $nom, '-contenu' ) ) {
				continue;
			}

			$inconnus[] = $nom;
		}

		return $inconnus;
	}

	/** @return list<array{id: string, ok: bool, detail: string}> */
	private function sondes_forms(): array {
		$dossier     = \Anode\Forms\definitions_dir();
		// Relatif au thème, comme les autres : la route est ouverte en lecture.
		$court       = 'theme/' . basename( dirname( $dossier ) ) . '/' . basename( $dossier );
		$definitions = is_dir( $dossier ) ? (array) glob( $dossier . '/*.json' ) : [];
		$invalides   = [];

		foreach ( $definitions as $chemin ) {
			$decode = json_decode( (string) file_get_contents( $chemin ), true );

			if ( ! is_array( $decode ) || ! isset( $decode['screens'] ) ) {
				$invalides[] = basename( $chemin );
			}
		}

		$routes = rest_get_server()->get_routes( NAMESPACE_ );

		return [
			$this->sonde(
				'forms-definitions',
				(bool) $definitions,
				sprintf( 'aucune définition de formulaire dans %s — un bloc data-form n’afficherait rien.', $court ),
				sprintf( '%d définition(s) de formulaire lisibles', count( $definitions ) )
			),
			$this->sonde(
				'forms-valides',
				! $invalides,
				sprintf( 'définition(s) illisibles ou sans « screens » : %s.', implode( ', ', $invalides ) ),
				'toutes les définitions ont des écrans'
			),
			$this->sonde(
				'forms-route',
				(bool) preg_grep( '#/form/#', array_keys( $routes ) ),
				'la route /form/<nom> n’est pas enregistrée — le navigateur ne pourra pas lire la définition.',
				'route /form/<nom> enregistrée'
			),
			$this->sonde_fichiers_proteges(),
			$this->sonde_relais_declares(),
			$this->sonde_clefs_connues(),
		];
	}

	/**
	 * Une définition porte-t-elle un réglage que le moteur ne lit pas ?
	 *
	 * Le moteur ignore en silence toute clé inconnue. Mesuré sur un site client
	 * en préproduction : `contact.json` et `reservation.json` portaient encore
	 * `"notify": "contact@exemple.fr"`, restée là quand l'envoi de courriel
	 * est parti avec le stockage des demandes. Les fichiers annonçaient donc un
	 * destinataire que plus une ligne ne lisait — et la sonde voisine, qui ne
	 * regarde que la présence d'un relais, n'avait rien à en dire.
	 *
	 * Une clé morte qui nomme une destination se lit comme un formulaire
	 * branché : c'est le contraire d'un détail de rangement.
	 *
	 * @return array<string, mixed>
	 */
	private function sonde_clefs_connues(): array {
		if ( ! function_exists( 'Anode\Forms\clefs_inconnues' ) || ! function_exists( 'Anode\Forms\definitions_dir' ) ) {
			return $this->sonde( 'forms-clefs', true, '', 'moteur de formulaire absent' );
		}

		$fautives = [];

		foreach ( glob( \Anode\Forms\definitions_dir() . '/*.json' ) ?: [] as $fichier ) {
			$definition = json_decode( (string) file_get_contents( $fichier ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( ! is_array( $definition ) ) {
				continue;
			}

			$inconnues = \Anode\Forms\clefs_inconnues( $definition );

			if ( $inconnues ) {
				$fautives[] = sprintf( '%s (%s)', basename( $fichier, '.json' ), implode( ', ', $inconnues ) );
			}
		}

		if ( ! $fautives ) {
			return $this->sonde( 'forms-clefs', true, '', 'aucun réglage mort dans les définitions' );
		}

		return $this->sonde(
			'forms-clefs',
			false,
			sprintf(
				'réglage(s) que le moteur ne lit pas : %s. Une clé morte qui nomme une destination donne à lire un formulaire branché.',
				implode( ' ; ', $fautives )
			),
			''
		);
	}


	/**
	 * Chaque formulaire a-t-il un relais où envoyer ?
	 *
	 * Le site n'enregistre rien : le webhook est la seule sortie d'une demande.
	 * Sans lui, chaque envoi est refusé — la personne le voit tout de suite, ce
	 * qui vaut mieux qu'un silence, mais le formulaire est inutilisable.
	 *
	 * C'est le défaut le plus probable d'une mise en ligne : la définition est
	 * livrée avec un `webhook` vide, et personne ne s'en aperçoit avant la
	 * première demande perdue.
	 *
	 * @return array<string, mixed>
	 */
	private function sonde_relais_declares(): array {
		if ( ! function_exists( 'Anode\Forms\definitions_dir' ) ) {
			return $this->sonde( 'forms-relais', true, '', 'moteur de formulaire absent' );
		}

		$sans         = [];
		$a_referencer = [];

		foreach ( glob( \Anode\Forms\definitions_dir() . '/*.json' ) ?: [] as $fichier ) {
			$definition = json_decode( (string) file_get_contents( $fichier ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			if ( ! is_array( $definition ) ) {
				continue;
			}

			/*
			 * La question posée est « le moteur enverra-t-il ? », donc c'est le
			 * moteur qu'on interroge — pas une seconde lecture du fichier.
			 *
			 * La sonde relevait auparavant les chaînes non vides elle-même. Deux
			 * écarts en découlaient, et le second est celui qui compte : une
			 * adresse en `http://` ou une référence `constante:X` dont la
			 * constante n'existe pas sur cette installation sont des chaînes non
			 * vides. La sonde répondait « tous les formulaires ont un relais »
			 * pendant que chaque envoi partait en 502 — exactement le vert
			 * trompeur que cette sonde existe pour empêcher.
			 */
			$retenues = \Anode\Forms\relais_declares( $definition );

			if ( $retenues ) {
				continue;
			}

			$nom        = basename( $fichier, '.json' );
			$manquantes = function_exists( 'Anode\Forms\references_non_resolues' )
				? \Anode\Forms\references_non_resolues( $definition )
				: [];

			if ( $manquantes ) {
				$a_referencer[] = sprintf( '%s → %s', $nom, implode( ', ', $manquantes ) );

				continue;
			}

			$sans[] = $nom;
		}

		/*
		 * Une constante absente se dit à part : le fichier versionné est
		 * correct, il nomme sa destination, et le geste de réparation tient en
		 * une ligne sur ce serveur-ci. Le confondre avec « aucun relais »
		 * envoyait relire un fichier qui n'a rien à corriger.
		 *
		 * C'est une erreur sur tous les environnements, blueprint compris : un
		 * blueprint n'a pas de relais du tout, il n'en référence donc aucun. Une
		 * référence posée est une intention explicite, et une intention non
		 * satisfaite n'a pas d'environnement où elle serait acceptable.
		 */
		if ( $a_referencer ) {
			return $this->sonde(
				'forms-relais',
				false,
				sprintf(
					'relais référencé mais absent de wp-config.php : %s. Chaque envoi de ces formulaires est refusé — poser la constante (wp config set <NOM> "https://…" --type=constant).',
					implode( ' ; ', $a_referencer )
				),
				''
			);
		}

		if ( ! $sans ) {
			return $this->sonde( 'forms-relais', true, '', 'tous les formulaires ont un relais' );
		}

		/*
		 * Un blueprint n'a pas de relais, et n'en aura jamais.
		 *
		 * La distinction manquait : le contrôle raisonnait par environnement, donc il
		 * traitait un blueprint comme une préproduction de client. Or on ne crée pas un
		 * scénario n8n pour un blueprint : ses formulaires sont là pour être copiés, pas
		 * pour recevoir. Une exigence qu'on sait ne pas devoir satisfaire est une
		 * exigence qu'on cesse de lire — y compris sur les sites où elle compte.
		 */
		if ( defined( 'ANODE_BLUEPRINT' ) && constant( 'ANODE_BLUEPRINT' ) ) {
			return $this->sonde(
				'forms-relais',
				true,
				'',
				sprintf(
					'blueprint : aucun relais attendu pour %s — leurs envois sont refusés, ce qui est le comportement voulu',
					implode( ', ', $sans )
				)
			);
		}

		/*
		 * Hors production, l'absence de relais reste attendue : un blueprint neuf
		 * n'a pas d'adresse où envoyer, et rendre la santé rouge en permanence
		 * ferait une alerte qu'on ne lit plus.
		 *
		 * Mais le détail ne dit plus « à renseigner avant la mise en ligne » : ces
		 * formulaires **refusent déjà** les envois, sur tous les environnements,
		 * et c'est voulu. La formulation précédente laissait croire que la
		 * préproduction acceptait les demandes en attendant — elle les acceptait
		 * en effet, et les jetait en répondant « merci ». Le comportement a été
		 * corrigé ; ce message était la seule trace qui l'annonçait de travers.
		 */
		$production = 'production' === wp_get_environment_type();

		return $this->sonde(
			'forms-relais',
			! $production,
			sprintf(
				'aucun webhook déclaré pour : %s. Le site n’enregistre rien — chaque envoi de ces formulaires est refusé.',
				implode( ', ', $sans )
			),
			sprintf(
				'aucun webhook pour %s : leurs envois sont refusés, ce qui est attendu hors production',
				implode( ', ', $sans )
			)
		);
	}

	/**
	 * Une définition déclare-t-elle un champ de type `file` ?
	 */
	private function un_formulaire_attend_un_fichier(): bool {
		if ( ! function_exists( 'Anode\Forms\definitions_dir' ) ) {
			return false;
		}

		foreach ( (array) glob( \Anode\Forms\definitions_dir() . '/*.json' ) as $chemin ) {
			$brut = json_decode( (string) file_get_contents( $chemin ), true );

			if ( ! is_array( $brut ) ) {
				continue;
			}

			$champs = array_merge(
				is_array( $brut['fields'] ?? null ) ? $brut['fields'] : [],
				...array_map(
					static fn ( $ecran ): array => is_array( $ecran['fields'] ?? null ) ? $ecran['fields'] : [],
					is_array( $brut['screens'] ?? null ) ? $brut['screens'] : []
				)
			);

			foreach ( $champs as $champ ) {
				if ( is_array( $champ ) && 'file' === ( $champ['type'] ?? '' ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Le dossier des pièces jointes est-il réellement hors de portée du web ?
	 *
	 * Le composant y pose un `.htaccess` qui refuse tout — mais **nginx ne le lit
	 * pas**, ni Caddy, ni LiteSpeed en mode natif. Sur ces serveurs, une pièce
	 * jointe reste servie à qui connaît son adresse. Le nom du fichier est tiré au
	 * sort sur 62^16, ce qui rend l'adresse indevinable ; c'est une protection
	 * réelle, mais c'en est **une seule**, et l'affirmer sans la mesurer serait
	 * exactement le genre de promesse qu'on découvre fausse le jour où elle
	 * compte.
	 *
	 * La sonde demande donc au serveur un fichier témoin du dossier, et rapporte
	 * ce qu'il en fait. La réponse est mise en cache un jour : c'est un réglage de
	 * serveur, il ne change pas d'une heure à l'autre.
	 *
	 * @return array{id: string, ok: bool, detail: string}
	 */
	private function sonde_fichiers_proteges(): array {
		/*
		 * Sans champ de fichier déclaré, rien n'est jamais déposé : la question ne
		 * se pose pas, et l'échec ferait crier au loup sur la totalité du parc.
		 * On la pose le jour où un formulaire attend une pièce jointe.
		 */
		if ( ! $this->un_formulaire_attend_un_fichier() ) {
			return $this->sonde(
				'forms-fichiers-proteges',
				true,
				'',
				'aucun formulaire n’attend de pièce jointe'
			);
		}

		$cache = get_transient( 'anode_bridge_fichiers_proteges' );

		if ( is_array( $cache ) ) {
			return $cache;
		}

		/*
		 * Le dossier et son témoin sont posés avant de mesurer : ils ne sont créés
		 * qu'au premier envoi, et un 404 sur un fichier absent ne dit rien de la
		 * protection — il l'annoncerait même comme acquise.
		 */
		if ( function_exists( 'Anode\Forms\proteger_dossier' ) ) {
			\Anode\Forms\proteger_dossier();
		}

		// Le témoin porte l'extension d'une vraie pièce jointe : un serveur refuse
		// volontiers un `.php` tout en servant les `.pdf` du même dossier.
		$base = (string) ( wp_upload_dir()['baseurl'] ?? '' ) . '/anode-formulaires/temoin-anode.pdf';

		$reponse = wp_remote_get(
			$base,
			[
				'timeout'   => 5,
				'sslverify' => false,
				// Un cache de page ne doit pas répondre à la place du serveur.
				'headers'   => [ 'Cache-Control' => 'no-cache' ],
			]
		);

		$code = is_wp_error( $reponse ) ? 0 : (int) wp_remote_retrieve_response_code( $reponse );

		/*
		 * 403 et 404 sont l'un et l'autre corrects : le premier est la règle
		 * serveur, le second un dossier que rien n'expose. Un 200 dit que le
		 * dossier est servi, et que seule l'imprévisibilité du nom protège.
		 */
		$sonde = $this->sonde(
			'forms-fichiers-proteges',
			200 !== $code,
			sprintf(
				'le dossier des pièces jointes est servi par le serveur web (HTTP %d) : seule l’imprévisibilité du nom protège. '
				. 'Ajoutez une règle serveur — voir docs/formulaires.md.',
				$code
			),
			0 === $code
				? 'dossier des pièces jointes injoignable — protection en place'
				: sprintf( 'dossier des pièces jointes refusé par le serveur (HTTP %d)', $code )
		);

		set_transient( 'anode_bridge_fichiers_proteges', $sonde, DAY_IN_SECONDS );

		return $sonde;
	}

	/** @return list<array{id: string, ok: bool, detail: string}> */
	private function sondes_hardening(): array {
		$slug = \Anode\Hardening\login_slug();

		return [
			$this->sonde(
				'hardening-login',
				'' !== $slug && 'wp-login' !== $slug && 'wp-login.php' !== $slug,
				'l’URL de connexion est restée celle de WordPress : le durcissement ne masque rien.',
				sprintf( 'URL de connexion déplacée (/%s)', $slug )
			),
			$this->sonde(
				'hardening-editeur',
				defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
				'DISALLOW_FILE_EDIT n’est pas actif : l’éditeur de fichiers du tableau de bord reste ouvert.',
				'éditeur de fichiers désactivé'
			),
			$this->sonde(
				'hardening-entetes',
				(bool) has_filter( 'wp_headers' ),
				'aucun filtre wp_headers : les en-têtes de sécurité ne sont pas émis.',
				'en-têtes de sécurité branchés'
			),
		];
	}

	/** @return list<array{id: string, ok: bool, detail: string}> */
	private function sondes_seo(): array {
		/*
		 * `Seo::FIELDS` liste des **suffixes** : la clé enregistrée porte le
		 * préfixe. Interroger le suffixe nu renvoie « absent » pour les cinq
		 * champs, sur un site où ils fonctionnent parfaitement.
		 */
		$champs   = array_keys( \Anode\Seo\Seo::FIELDS );
		$manquant = [];

		foreach ( $champs as $champ ) {
			if ( ! registered_meta_key_exists( 'post', \Anode\Seo\Seo::PREFIX . $champ, 'page' ) ) {
				$manquant[] = $champ;
			}
		}

		/*
		 * `anode-seo` s'efface d'elle-même si une extension SEO tierce est active.
		 * Le signaler comme un défaut serait faux : c'est le comportement voulu.
		 */
		$tierce = defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || class_exists( 'All_in_One_SEO_Pack' );

		return [
			$this->sonde(
				'seo-champs',
				$tierce || ! $manquant,
				sprintf(
					'champ(s) SEO non exposés à l’API REST : %s. Les scripts écriraient dans le vide.',
					implode( ', ', $manquant )
				),
				$tierce
					? 'en retrait : une extension SEO tierce est active'
					: sprintf( '%d champs SEO exposés à l’API', count( $champs ) )
			),
			$this->sonde(
				'seo-titre',
				$tierce || (bool) has_filter( 'pre_get_document_title' ) || (bool) has_filter( 'document_title_parts' ),
				'aucun filtre de titre : la balise <title> reste celle de WordPress.',
				$tierce ? 'titre laissé à l’extension tierce' : 'titre piloté par anode-seo'
			),
			/*
			 * Un domaine de recette traité en production.
			 *
			 * `wp_get_environment_type()` rend « production » quand rien n'est
			 * déclaré, et c'est l'état par défaut d'une installation neuve. Sur une
			 * préproduction, cette valeur ouvre le `robots.txt`, retire le
			 * `noindex`, et fait poser un an de HSTS. Mesuré en ligne : une
			 * préproduction du parc était dans cet état, et son seul filet était
			 * `blog_public`, une case décochable depuis les réglages de WordPress.
			 *
			 * La sonde ne se fie donc pas à la déclaration : elle la confronte au
			 * domaine, qui est le seul indice disponible quand personne n'a rien
			 * dit.
			 */
			/*
			 * `is_callable` et non `method_exists` : celle-ci répond « oui » pour
			 * une méthode privée, et c'est ce qui a fait tomber le point de santé
			 * en erreur fatale sur un site où `anode-seo` était d'une version
			 * antérieure. Un contrôle de santé est ce qu'on appelle quand quelque
			 * chose va mal — il ne doit jamais être la chose qui casse. Un
			 * composant plus ancien rend donc la sonde muette, pas mortelle.
			 */
			$this->sonde(
				'seo-environnement',
				! is_callable( [ \Anode\Seo\Seo::class, 'host_looks_transient' ] )
					|| ! \Anode\Seo\Seo::host_looks_transient()
					|| 'production' !== wp_get_environment_type(),
				sprintf(
					'le domaine « %s » est un domaine de recette, mais l’environnement vaut « production » : robots.txt ouvert, aucun noindex, HSTS posé pour un an. Déclarer WP_ENVIRONMENT_TYPE dans wp-config.php.',
					(string) wp_parse_url( home_url(), PHP_URL_HOST )
				),
				is_callable( [ \Anode\Seo\Seo::class, 'host_looks_transient' ] )
					? sprintf( 'environnement « %s », cohérent avec le domaine', wp_get_environment_type() )
					: 'non mesuré : anode-seo est d’une version antérieure à 1.3.0'
			),
		];
	}



	/** @return list<array{id: string, ok: bool, detail: string}> */
	private function sondes_redirections(): array {
		$table = \Anode\Redirections\table_path();

		// Relatif au thème : le détail est servi en lecture seule, et
		// l'arborescence du serveur n'a pas à en sortir.
		$court = 'theme/' . basename( dirname( $table ) ) . '/' . basename( $table );

		/*
		 * Pas de table = pas d'ancienne URL à reprendre. C'est le cas d'un site
		 * neuf, et ce n'est pas un défaut. Une table présente mais illisible,
		 * si : les anciennes adresses tomberaient en 404 sans un mot.
		 */
		$existe = file_exists( $table );

		return [
			$this->sonde(
				'redirections-table',
				! $existe || is_readable( $table ),
				sprintf( 'la table %s existe mais n’est pas lisible : les anciennes URL tombent en 404.', $court ),
				$existe ? 'table de redirections lisible' : 'aucune table — site sans ancienne URL à reprendre'
			),
			$this->sonde(
				'redirections-branchees',
				! $existe || (bool) has_action( 'template_redirect' ),
				'la table existe mais aucun template_redirect n’est branché.',
				'redirections branchées'
			),
		];
	}

	/**
	 * Le confort du builder.
	 *
	 * Deux choses peuvent le rendre inerte sans rien casser d'apparent : ses
	 * **ressources** absentes — le composant est alors chargé, ses réglages sont
	 * lus, et le navigateur reçoit deux URL en 404 —, et **toutes ses fonctions
	 * éteintes** par le filtre, auquel cas il ne sert rien du tout et c'est
	 * peut-être voulu.
	 *
	 * On ne sonde pas ce qu'il fait dans le builder : cela demande un vrai
	 * navigateur, et c'est le rôle de `bin/test-builder-interactions.mjs`.
	 *
	 * @return list<array{id: string, ok: bool, detail: string}>
	 */
	private function sondes_builder(): array {
		$actives  = \Anode\Builder\fonctions_actives();
		$allumees = array_keys( array_filter( $actives ) );

		$manquants = [];

		foreach ( [ 'builder.js', 'builder.css' ] as $fichier ) {
			if ( ! is_readable( WPMU_PLUGIN_DIR . '/anode-builder/assets/' . $fichier ) ) {
				$manquants[] = $fichier;
			}
		}

		return [
			$this->sonde(
				'builder-assets',
				! $manquants,
				sprintf(
					'ressource(s) absente(s) : %s. Le composant est chargé mais le navigateur reçoit un 404 — aucune des fonctions n’agit.',
					implode( ', ', $manquants )
				),
				/*
				 * Le message dit ce que la sonde a mesuré, et rien d'autre.
				 *
				 * Il annonçait le nombre de fonctions actives — la mesure de
				 * `builder-fonctions`, pas la sienne. Deux conséquences : on lisait
				 * « assets » au vert en croyant l'avoir vérifié alors qu'on lisait
				 * autre chose, et un site aux ressources en place mais aux fonctions
				 * toutes éteintes affichait « 0 fonction(s) active(s) : aucune » sur
				 * une sonde **verte**.
				 */
				'builder.js et builder.css lisibles'
			),
			$this->sonde(
				'builder-fonctions',
				(bool) $allumees,
				'les trois fonctions sont éteintes par le filtre anode/builder/fonctions : le composant ne sert à rien.',
				'au moins une fonction active'
			),
		];
	}

	/** @return list<array{id: string, ok: bool, detail: string}> */
	private function sondes_updater(): array {
		$liste = dirname( __DIR__, 2 ) . '/mu-plugins/anode-updater/composants.json';
		$mu    = ( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins' )
			. '/anode-updater/composants.json';
		$etat  = get_option( 'anode_updater_etat', [] );

		return [
			$this->sonde(
				'updater-liste',
				is_readable( $mu ) || is_readable( $liste ),
				'composants.json est introuvable : l’updater ne sait ni où publier ni où chercher.',
				'liste des composants lisible'
			),
			$this->sonde(
				'updater-derniere-verif',
				! is_array( $etat ) || empty( $etat['erreur'] ),
				sprintf(
					'la dernière vérification de mise à jour a échoué : %s',
					is_array( $etat ) ? (string) ( $etat['erreur'] ?? '' ) : ''
				),
				'dernière vérification sans erreur'
			),
			$this->sonde_age( $etat ),
			$this->sonde_canal( $etat ),
		];
	}

	/**
	 * Depuis combien de jours l'absence de vérification cesse d'être un aléa.
	 *
	 * La passe est quotidienne : deux rendez-vous manqués d'affilée ne
	 * s'expliquent plus par l'ordonnancement.
	 */
	private const VERIF_JOURS = 3;

	/**
	 * La dernière vérification est-elle trop vieille pour engager quoi que ce soit ?
	 *
	 * Les trois sondes de l'updater lisaient toutes le **même état stocké**, et
	 * aucune ne regardait sa date. `sonde_canal` en particulier annonce « canal
	 * ouvert — N composant(s) joignable(s) » à partir de `$etat['composants']`,
	 * sans appeler GitHub : c'est le compte rendu de la dernière passe, quel que
	 * soit son âge. Un site dont le cron a cessé de tourner garde donc son
	 * dernier état — pas d'erreur, tous les dépôts joignables — et rend trois
	 * voyants verts sur un canal qui ne reçoit plus rien.
	 *
	 * C'est exactement le défaut qui a fait naître `sonde_canal` — un vert rendu
	 * à vide — mais repoussé d'un cran : corrigé pour « n'a jamais tourné », pas
	 * pour « ne tourne plus ». Sur un parc qui exige désormais une signature, un
	 * canal muet ne se contente pas de retarder une fonctionnalité : il retient
	 * les correctifs de sécurité, et rien ne le dit.
	 *
	 * La date est déjà écrite à chaque passe (`verifie_le`, planificateur) — il
	 * n'y avait qu'à la lire.
	 *
	 * @param mixed $etat État de la dernière vérification.
	 * @return array{id: string, ok: bool, detail: string}
	 */
	private function sonde_age( $etat ): array {
		$verifie_le = is_array( $etat ) ? ( $etat['verifie_le'] ?? null ) : null;

		return $this->sonde(
			'updater-fraicheur',
			null === self::verification_perimee( $verifie_le, time() ),
			(string) self::verification_perimee( $verifie_le, time() ),
			is_string( $verifie_le ) && '' !== $verifie_le
				? sprintf( 'dernière passe le %s', $verifie_le )
				: 'aucune passe enregistrée'
		);
	}

	/**
	 * Motif du refus, ou null si la dernière passe est assez récente.
	 *
	 * Séparée de la sonde pour être vérifiable sans WordPress — c'est la règle,
	 * pas l'enveloppe, qui décide.
	 *
	 * Un état sans date n'est pas traité comme périmé : `sonde_canal` couvre
	 * déjà « rien n'a jamais tourné », et rendre deux rouges pour un seul fait
	 * ferait chercher deux causes.
	 *
	 * @param mixed $verifie_le Date ISO 8601 de la dernière passe.
	 */
	public static function verification_perimee( $verifie_le, int $maintenant, int $jours = self::VERIF_JOURS ): ?string {
		if ( ! is_string( $verifie_le ) || '' === trim( $verifie_le ) ) {
			return null;
		}

		$date = strtotime( $verifie_le );

		if ( false === $date ) {
			return sprintf( 'date de dernière vérification illisible : « %s »', $verifie_le );
		}

		$ecart = (int) floor( ( $maintenant - $date ) / DAY_IN_SECONDS );

		if ( $ecart < $jours ) {
			return null;
		}

		return sprintf(
			'aucune vérification de mise à jour depuis %d jour(s) : la passe est quotidienne, donc le planificateur '
				. 'ne tourne plus. Les autres sondes restent au vert — elles relisent le dernier état, sans le dater. '
				. 'Cause la plus fréquente : wp-cron.php inatteignable (authentification HTTP de préproduction, ou '
				. 'DISABLE_WP_CRON sans tâche système en relais).',
			$ecart
		);
	}

	/**
	 * Le canal de mise à jour est-il réellement ouvert ?
	 *
	 * **Cette sonde vaut pour tous les sites, blueprints compris.** Contrairement
	 * aux webhooks — qu'un blueprint n'aura jamais — le canal est nécessaire
	 * partout : un blueprint qui ne se met plus à jour est un blueprint qu'on
	 * clonera périmé.
	 *
	 * Elle existe parce que la précédente réussissait à vide. `updater-derniere-verif`
	 * lisait `! is_array( $etat ) || empty( $etat['erreur'] )` : un site qui n'a
	 * **jamais** vérifié n'a pas d'état, la condition est donc vraie, et le vert
	 * était rendu. Un site fraîchement mis en ligne passait — c'est précisément le
	 * moment où il fallait crier.
	 *
	 * ## Elle s'appelait `updater-jeton`, et ce nom est devenu faux
	 *
	 * Les dépôts de releases sont **publics** depuis le 06/08/2026. Le jeton
	 * n'ouvre donc plus rien : il relève seulement le quota d'API, de soixante
	 * appels par heure et par IP à cinq mille. Exiger sa présence reviendrait à
	 * exiger un porteur d'autorisation à nous sur le disque de chaque client —
	 * exactement ce que la décision de publier a retiré.
	 *
	 * Le nom a suivi la question. Garder `updater-jeton` serait pire qu'un nom
	 * démodé : la sonde passerait **verte sans jeton**, et un lecteur en
	 * conclurait qu'il est posé. Un nom qui trompe est un défaut, pas un détail.
	 *
	 * Deux états, là où il y en avait trois : rien n'a jamais tourné · un dépôt
	 * reste muet. La constante absente n'est plus un état — c'est le cas normal.
	 *
	 * @param mixed $etat État de la dernière vérification.
	 * @return array{id: string, ok: bool, detail: string}
	 */
	private function sonde_canal( $etat ): array {
		$composants = is_array( $etat ) && is_array( $etat['composants'] ?? null ) ? $etat['composants'] : null;

		if ( null === $composants ) {
			return $this->sonde(
				'updater-canal',
				false,
				'aucune vérification n’a jamais abouti : on ne sait pas si ce site reçoit '
					. 'quoi que ce soit. Lancer une passe et lire le résultat — Outils → Mises à jour du site.',
				''
			);
		}

		/*
		 * Un composant **déclaré** mais absent de l'état n'a jamais été vérifié.
		 *
		 * La sonde comptait les lignes de l'état et s'arrêtait là : elle annonçait
		 * « 8 composant(s) joignable(s) » et le vert, sur un canal qui en déclare
		 * neuf. Le neuvième venait d'être ajouté à la liste, et aucune passe n'avait
		 * tourné depuis — il était donc invisible, exactement au moment où il fallait
		 * le signaler.
		 *
		 * Mesuré le 06/08/2026 sur un blueprint. C'est la même faute que « ✓ 0
		 * surface(s) » : compter ce qu'on a trouvé, jamais ce qu'on attendait.
		 */
		$declares   = array_keys( \Anode\Updater\Updater::composants() );
		$jamais_vus = array_values( array_diff( $declares, array_keys( $composants ) ) );

		$muets  = [];
		$quotas = [];

		foreach ( $composants as $nom => $ligne ) {
			$etat = (string) ( $ligne['etat'] ?? '' );

			/*
			 * Le quota épuisé n'est pas une panne : sans jeton, GitHub accorde soixante
			 * appels par heure et par IP, et les sites d'un même hébergement la
			 * partagent. La passe suivante repartira seule. Le confondre avec un dépôt
			 * cassé enverrait chercher une faute là où il n'y a qu'une attente.
			 *
			 * C'est le planificateur qui le nomme — `etat: 'quota'`. Le lire ici dans
			 * un `code` que rien n'écrivait aurait rendu zéro à chaque passe, et zéro
			 * se lit comme une réponse.
			 */
			if ( 'quota' === $etat ) {
				$quotas[] = (string) $nom;

				continue;
			}

			if ( in_array( $etat, [ 'erreur', 'depot-absent' ], true ) ) {
				$muets[] = (string) $nom;
			}
		}

		$jeton = defined( 'ANODE_GITHUB_TOKEN' )
			&& is_string( constant( 'ANODE_GITHUB_TOKEN' ) )
			&& '' !== trim( (string) constant( 'ANODE_GITHUB_TOKEN' ) );

		return $this->sonde(
			'updater-canal',
			! $muets && ! $jamais_vus,
			sprintf(
				'%s%s',
				$muets
					? sprintf(
						'dépôt inatteignable pour : %s. Les dépôts étant publics, ce n’est pas une question '
							. 'de droit : dépôt renommé, nommé à tort dans la liste des composants, ou réseau '
							. 'sortant fermé. ',
						implode( ', ', $muets )
					)
					: '',
				$jamais_vus
					? sprintf(
						'jamais vérifié(s) : %s — déclaré(s) au canal, absent(s) de l’état. Aucune passe n’a '
							. 'tourné depuis leur ajout : lancer une vérification (Outils → Mises à jour du site).',
						implode( ', ', $jamais_vus )
					)
					: ''
			),
			sprintf(
				'canal ouvert — %d/%d composant(s) déclaré(s) vérifié(s), quota %s%s',
				count( $composants ),
				count( $declares ),
				$jeton ? 'relevé par une constante' : 'public (60 appels/h par IP, suffisant)',
				$quotas ? sprintf( ' · %d en attente de quota, sans conséquence', count( $quotas ) ) : ''
			)
		);
	}

	/** @return list<array{id: string, ok: bool, detail: string}> */
	private function sondes_bridge(): array {
		$routes = array_keys( rest_get_server()->get_routes( NAMESPACE_ ) );

		/*
		 * Une route manquante ne se voit qu'au moment où un outil l'appelle, et
		 * l'erreur dit « 404 » — pas « votre pont est à moitié enregistré ».
		 */
		$attendues = [ '/bricks/classes', '/bricks/components', '/bricks/custom-css', '/site', '/design-system', '/inspect' ];
		$absentes  = [];

		foreach ( $attendues as $route ) {
			if ( ! in_array( '/' . NAMESPACE_ . $route, $routes, true ) ) {
				$absentes[] = $route;
			}
		}

		return [
			$this->sonde(
				'bridge-routes',
				! $absentes,
				sprintf( 'route(s) non enregistrées : %s.', implode( ', ', $absentes ) ),
				sprintf( '%d routes enregistrées', count( $routes ) )
			),
			$this->sonde(
				'bridge-bricks',
				Bricks_Adapter::is_available(),
				'Bricks est introuvable : aucune mise en page ne peut être lue ni écrite.',
				'Bricks accessible'
			),
			$this->sonde_extensions_tierces(),
			$this->sonde_themes_superflus(),
			$this->sonde_favicon(),
			$this->sonde_inspection(),
		];
	}

	/**
	 * L'inspection est-elle restée ouverte ?
	 *
	 * Elle rend le site lisible intégralement — fichiers, options, tables. C'est
	 * légitime le temps d'une reprise, et c'est une surface d'exposition tout le
	 * reste du temps. Une capacité ouverte dont rien ne parle est une capacité
	 * qu'on oublie : la sonde passe donc au rouge tant qu'elle l'est, et dit
	 * jusqu'à quand.
	 *
	 * Elle expire d'elle-même ; cette sonde sert à ne pas attendre l'expiration
	 * pour s'en souvenir.
	 *
	 * @return array{id: string, ok: bool, detail: string}
	 */
	private function sonde_inspection(): array {
		$jusqu = Rest_Inspection::open_until();

		if ( null === $jusqu ) {
			return $this->sonde( 'inspection-fermee', true, '', 'inspection fermée — le site n’est pas lisible intégralement' );
		}

		return $this->sonde(
			'inspection-fermee',
			false,
			sprintf(
				'inspection OUVERTE jusqu’au %s : fichiers, options, métadonnées et tables sont lisibles par '
					. 'le serveur MCP. À refermer dès la reprise terminée — Outils → Inspection Anode, '
					. 'ou en retirant la constante ANODE_INSPECTION.',
				$jusqu
			),
			''
		);
	}

	/**
	 * Le site a-t-il un favicon ?
	 *
	 * Son absence ne se voit sur aucune capture, aucun audit fonctionnel, aucun
	 * contrôle du dépôt : WordPress n'émet simplement **aucune** balise `icon`,
	 * et le navigateur affiche son icône par défaut — une page blanche, ou
	 * l'initiale du domaine. Rien n'est cassé, rien ne l'annonce.
	 *
	 * Mesuré le 02/08/2026 sur `preprod.agence-anode.fr` : `site_icon` à 0, et le
	 * mot « favicon » absent de tout le dépôt — CLAUDE.md, docs, compétences.
	 *
	 * @return array{id: string, ok: bool, detail: string}
	 */
	private function sonde_favicon(): array {
		$id = (int) get_option( 'site_icon' );

		if ( ! $id || ! wp_attachment_is_image( $id ) ) {
			return $this->sonde(
				'site-favicon',
				false,
				'aucun favicon : WordPress n’émet aucune balise « icon », et l’onglet affiche l’icône par '
					. 'défaut du navigateur. Téléverser un PNG carré d’au moins 512 px, puis '
					. 'wp_update_settings { "site_icon": <id> }.',
				''
			);
		}

		/*
		 * Le réglage ne suffit pas. WordPress ne fabrique les tailles dédiées
		 * (32, 180, 192, 270) que dans l'assistant de recadrage : posé par
		 * l'API, le média n'en a aucune, et les balises sortent avec un
		 * `sizes="32x32"` qui pointe une image de 150 px. Ça marche, et c'est
		 * faux — donc invisible.
		 */
		$tailles  = (array) ( wp_get_attachment_metadata( $id )['sizes'] ?? [] );
		$absentes = array_diff( [ 'site_icon-32', 'site_icon-180', 'site_icon-192', 'site_icon-270' ], array_keys( $tailles ) );

		if ( $absentes ) {
			return $this->sonde(
				'site-favicon',
				false,
				sprintf(
					'favicon posé, mais sans ses tailles dédiées (%s) : les balises `icon` déclarent des '
						. 'dimensions qu’elles ne servent pas. Régénérer avec WP_Site_Icon::additional_sizes '
						. '— voir docs/seo.md.',
					implode( ', ', $absentes )
				),
				''
			);
		}

		return $this->sonde( 'site-favicon', true, '', sprintf( 'favicon posé (média %d), quatre tailles servies', $id ) );
	}

	/**
	 * Reste-t-il une extension que le site n'a pas déclarée ?
	 *
	 * Le dépôt exige zéro extension tierce, et l'outil qui le vérifie
	 * (`clean-plugins.mjs --check`) ne sait travailler que sur une installation
	 * du poste : sur un site en ligne — c'est-à-dire sur tous — la règle n'était
	 * vérifiée par rien. Elle se lisait, disait la doc, par `wp_site_info`.
	 *
	 * Or `wp_site_info` ne rend que les extensions **actives**. Mesuré le
	 * 02/08/2026 sur `preprod.agence-anode.fr` : `plugins: []` et `wp_health` sans
	 * un mot, pendant qu'Akismet et Hello Dolly dormaient dans `plugins/`. Une
	 * extension inactive n'est pas inoffensive — c'est du code sur le disque, qui
	 * suit ses propres mises à jour et porte ses propres failles, exactement
	 * l'argument qui fait retirer les thèmes par défaut.
	 *
	 * @return array{id: string, ok: bool, detail: string}
	 */
	private function sonde_extensions_tierces(): array {
		$inventaire = $this->inventaire_extensions();
		$fautives   = self::extensions_indesirables( $inventaire, $this->extensions_declarees() );

		if ( ! $fautives ) {
			return $this->sonde(
				'extensions-tierces',
				true,
				'',
				sprintf( 'aucune extension tierce dans plugins/ (%d présente(s))', count( $inventaire ) )
			);
		}

		$noms = array_map(
			/*
			 * Le slug, pas la version : la route est ouverte à un compte qui n'a
			 * que `edit_pages`, et la version d'une extension tierce est de la
			 * reconnaissance. Le slug suffit à agir — c'est ce que prend
			 * `wp plugin delete`.
			 */
			static function ( array $extension ): string {
				return sprintf( '%s (%s)', $extension['slug'], $extension['active'] ? 'active' : 'inactive' );
			},
			$fautives
		);

		return $this->sonde(
			'extensions-tierces',
			false,
			sprintf(
				'extension(s) non déclarées dans plugins/ : %s. Aucune extension tierce sur un site livré, '
					. 'active ou non : la retirer (wp plugin delete <slug>), ou la déclarer — site.json '
					. '("plugins": {"keep": […]}) puis ANODE_PLUGINS_AUTORISES dans wp-config.',
				implode( ' ; ', $noms )
			),
			''
		);
	}

	/**
	 * Deux thèmes, et seulement deux.
	 *
	 * Même raisonnement, et même angle mort : `wp_site_info` ne rend que le thème
	 * **actif** et son parent. Un `twentytwentyfour` ou le `bricks-child` livré
	 * avec Bricks pouvait donc rester sur le disque sans qu'aucun outil MCP ne le
	 * dise — le contrôle se faisait en SSH à la mise en ligne, ou jamais.
	 *
	 * @return array{id: string, ok: bool, detail: string}
	 */
	private function sonde_themes_superflus(): array {
		$installes = $this->inventaire_themes();
		$superflus = self::themes_superflus( $installes, get_stylesheet(), get_template() );

		if ( ! $superflus ) {
			return $this->sonde(
				'themes-superflus',
				true,
				'',
				sprintf( 'deux thèmes et deux seulement : %s', implode( ', ', $installes ) )
			);
		}

		return $this->sonde(
			'themes-superflus',
			false,
			sprintf(
				'thème(s) en trop sur le disque : %s. Deux thèmes et deux seulement — Bricks et l’enfant '
					. 'du site : wp theme delete <nom>.',
				implode( ', ', $superflus )
			),
			''
		);
	}

	/**
	 * Ce que le site déclare accepter dans `plugins/`.
	 *
	 * Les composants maison y figurent parce qu'une copie de l'un d'eux dans
	 * `plugins/` est déjà nommée par `shadowed`, avec la bonne explication : elle
	 * est **inerte**, pas tierce. Deux rouges pour un seul fait feraient chercher
	 * deux causes.
	 *
	 * @return list<string>
	 */
	private function extensions_declarees(): array {
		$declarees = defined( 'ANODE_PLUGINS_AUTORISES' )
			? array_filter( array_map( 'trim', explode( ',', (string) ANODE_PLUGINS_AUTORISES ) ) )
			: [];

		return array_values( array_merge( $declarees, array_keys( self::COMPOSANTS ), [ 'anode-bridge' ] ) );
	}

	/**
	 * Le slug d'une extension — dossier, ou fichier unique.
	 *
	 * Hello Dolly est un `hello.php` posé à la racine de `plugins/` : tout ce qui
	 * ne regarde que les sous-dossiers ne le voit pas. C'est la moitié du défaut
	 * mesuré le 02/08/2026, l'autre étant qu'il était inactif.
	 */
	public static function slug_extension( string $fichier ): string {
		return str_contains( $fichier, '/' )
			? (string) strtok( $fichier, '/' )
			: basename( $fichier, '.php' );
	}

	/**
	 * Les extensions présentes que le site n'a pas déclarées.
	 *
	 * Pure : c'est la décision, elle se teste sans WordPress.
	 *
	 * @param list<array{file: string, name: string, active: bool}> $inventaire
	 * @param list<string>                                          $declarees
	 * @return list<array{file: string, name: string, active: bool, slug: string}>
	 */
	public static function extensions_indesirables( array $inventaire, array $declarees ): array {
		$fautives = [];

		foreach ( $inventaire as $extension ) {
			$slug = self::slug_extension( (string) $extension['file'] );

			if ( in_array( $slug, $declarees, true ) ) {
				continue;
			}

			$fautives[] = array_merge( $extension, [ 'slug' => $slug ] );
		}

		return $fautives;
	}

	/**
	 * Les thèmes installés en plus de l'enfant du site et de son parent.
	 *
	 * `$parent` vaut `$actif` sur un thème sans parent : le filtre le supporte
	 * sans cas particulier.
	 *
	 * @param list<string> $installes
	 * @return list<string>
	 */
	public static function themes_superflus( array $installes, string $actif, string $parent ): array {
		return array_values( array_diff( $installes, array_filter( [ $actif, $parent ] ) ) );
	}

	/**
	 * Tout ce que `plugins/` contient, actif ou non.
	 *
	 * `get_plugins()` voit aussi bien un sous-dossier qu'un `hello.php` à la
	 * racine — c'est la raison de ne pas lister le dossier soi-même.
	 *
	 * @return list<array{file: string, name: string, version: ?string, active: bool}>
	 */
	private function inventaire_extensions(): array {
		if ( null !== $this->extensions ) {
			return $this->extensions;
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$actives = (array) get_option( 'active_plugins', [] );
		$liste   = [];

		foreach ( get_plugins() as $fichier => $entete ) {
			$liste[] = [
				'file'    => (string) $fichier,
				'name'    => (string) ( $entete['Name'] ?? $fichier ),
				'version' => ( (string) ( $entete['Version'] ?? '' ) ) ?: null,
				'active'  => in_array( (string) $fichier, $actives, true ),
			];
		}

		$this->extensions = $liste;

		return $liste;
	}

	/**
	 * Les dossiers de `themes/`, actif compris.
	 *
	 * @return list<string>
	 */
	private function inventaire_themes(): array {
		if ( null === $this->themes ) {
			$this->themes = array_values( array_keys( wp_get_themes() ) );
		}

		return $this->themes;
	}

	/**
	 * @return array{id: string, ok: bool, detail: string}
	 */
	private function sonde( string $id, bool $ok, string $echec, string $succes ): array {
		return [
			'id'     => $id,
			'ok'     => $ok,
			'detail' => $ok ? $succes : $echec,
		];
	}

	/**
	 * Erreurs fatales récentes mentionnant le code maison.
	 *
	 * Une fatale dans un mu-plugin ne s'affiche pas en production : le composant
	 * disparaît, et la page continue de se rendre sans lui. C'est exactement le
	 * cas que la sonde « chargé » attrape — celle-ci en donne la cause.
	 *
	 * @return list<string>
	 */
	private function fatales_recentes(): array {
		$journal = WP_CONTENT_DIR . '/debug.log';

		if ( ! is_readable( $journal ) ) {
			return [];
		}

		$taille = (int) filesize( $journal );
		$depuis = max( 0, $taille - 200000 );
		$texte  = (string) file_get_contents( $journal, false, null, $depuis );
		$lignes = [];

		foreach ( explode( "\n", $texte ) as $ligne ) {
			if ( false !== stripos( $ligne, 'fatal error' ) && false !== stripos( $ligne, 'anode' ) ) {
				$lignes[] = trim( $ligne );
			}
		}

		// Les dernières seulement : un journal ancien noierait le diagnostic.
		return array_slice( $lignes, -10 );
	}
}
