<?php
/**
 * Tests du validateur, sans WordPress.
 *
 * Le validateur est la pièce la plus critique du pont : c'est lui qui empêche
 * d'écrire une structure Bricks corrompue en base, et qui bloque l'exécution
 * de PHP arbitraire. Il doit donc être testable sans installer WordPress.
 *
 * Lancement : php plugin/anode-bridge/tests/test-validator.php
 *
 * @package Anode\Bridge
 */

declare( strict_types = 1 );

/* --- Stubs WordPress minimaux --------------------------------------- */

define( 'ABSPATH', __DIR__ );

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct(
			private string $code = '',
			private string $message = '',
			private array $data = []
		) {}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}

		public function get_error_data(): array {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $value ): string {
		return trim( strip_tags( $value ) );
	}
}

/*
 * Doublures des rares fonctions dont la classe REST a besoin pour être
 * chargée. On ne teste ici que sa règle d'exposition des types de contenu :
 * le reste demande WordPress.
 */
if ( ! class_exists( 'WP_Post_Type' ) ) {
	class WP_Post_Type {
		public function __construct( public string $name = '', public $show_in_rest = false ) {}
	}
}

$GLOBALS['anode_types'] = [];

if ( ! function_exists( 'get_post_type_object' ) ) {
	function get_post_type_object( string $type ) {
		return $GLOBALS['anode_types'][ $type ] ?? null;
	}
}

foreach ( [ 'register_rest_route', 'sanitize_key', 'rest_ensure_response', 'get_permalink', 'get_post_meta', 'get_option', 'get_posts', 'add_action', 'add_filter', 'esc_url_raw', 'wp_parse_url', 'get_current_user_id' ] as $stub ) {
	if ( ! function_exists( $stub ) ) {
		eval( "function {$stub}() { return null; }" ); // phpcs:ignore Squiz.PHP.Eval.Discouraged
	}
}

require_once __DIR__ . '/../includes/class-bricks-adapter.php';
require_once __DIR__ . '/../includes/class-validator.php';
require_once __DIR__ . '/../includes/class-rest-content.php';

/*
 * Chargé pour une seule règle, qui n'a pourtant pas de plus mauvais endroit
 * pour être vérifiée : la reprise de l'identifiant d'une classe existante. La
 * méthode ne touche ni à WordPress ni à la base — elle décide simplement de ce
 * qui part en base, et son erreur détache toutes les classes de toutes les pages.
 */
require_once __DIR__ . '/../includes/class-rest-bricks.php';

use Anode\Bridge\Validator;

/* --- Micro-harnais -------------------------------------------------- */

$passed = 0;
$failed = 0;

/**
 * @param callable(): void $fn
 */
function test( string $name, callable $fn ): void {
	global $passed, $failed;

	try {
		$fn();
		++$passed;
		echo "  ✓ {$name}\n";
	} catch ( Throwable $error ) {
		++$failed;
		echo "  ✗ {$name}\n    {$error->getMessage()}\n";
	}
}

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function assert_error( mixed $value, string $needle, string $message ): void {
	assert_true( $value instanceof WP_Error, $message . ' (aucune erreur renvoyée)' );
	assert_true(
		str_contains( $value->get_error_message(), $needle ),
		$message . " (message inattendu : « {$value->get_error_message()} »)"
	);
}

/* --- Structure d'éléments ------------------------------------------- */

echo "\nValidation de structure Bricks\n";

test(
	'une structure valide est acceptée et normalisée',
	function (): void {
		$result = Validator::elements(
			[
				[ 'id' => 'aaa111', 'name' => 'section', 'parent' => 0, 'children' => [ 'bbb222' ], 'settings' => [] ],
				[ 'id' => 'bbb222', 'name' => 'heading', 'parent' => 'aaa111', 'children' => [], 'settings' => [ 'text' => 'Bonjour' ] ],
			]
		);

		assert_true( is_array( $result ), 'tableau attendu' );
		assert_true( count( $result ) === 2, 'deux éléments attendus' );
		assert_true( 0 === $result[0]['parent'], 'la racine a pour parent l’entier 0' );
		assert_true( 'Bonjour' === $result[1]['settings']['text'], 'les réglages sont conservés' );
	}
);

test(
	'une structure vide est acceptée (page vidée)',
	function (): void {
		assert_true( [] === Validator::elements( [] ), 'un tableau vide doit être accepté' );
	}
);

test(
	'un parent inexistant est refusé',
	function (): void {
		$result = Validator::elements(
			[
				[ 'id' => 'aaa111', 'name' => 'section', 'parent' => 0, 'children' => [] ],
				[ 'id' => 'bbb222', 'name' => 'heading', 'parent' => 'zzz999', 'children' => [] ],
			]
		);

		assert_error( $result, 'n’existe pas', 'un parent orphelin doit être refusé' );
	}
);

test(
	'un enfant inexistant est refusé',
	function (): void {
		$result = Validator::elements(
			[ [ 'id' => 'aaa111', 'name' => 'section', 'parent' => 0, 'children' => [ 'fantome' ] ] ]
		);

		assert_error( $result, 'n’existe pas', 'un enfant fantôme doit être refusé' );
	}
);

test(
	'une relation parent/enfant incohérente est refusée',
	function (): void {
		$result = Validator::elements(
			[
				[ 'id' => 'aaa111', 'name' => 'section', 'parent' => 0, 'children' => [ 'bbb222' ] ],
				[ 'id' => 'bbb222', 'name' => 'heading', 'parent' => 0, 'children' => [] ],
			]
		);

		assert_error( $result, 'Incohérence', 'un enfant dont le parent ne correspond pas doit être refusé' );
	}
);

test(
	'un cycle de parenté est détecté',
	function (): void {
		$result = Validator::elements(
			[
				[ 'id' => 'aaa111', 'name' => 'section', 'parent' => 'bbb222', 'children' => [ 'bbb222' ] ],
				[ 'id' => 'bbb222', 'name' => 'block', 'parent' => 'aaa111', 'children' => [ 'aaa111' ] ],
			]
		);

		assert_true( $result instanceof WP_Error, 'un cycle doit être refusé' );
	}
);

test(
	'une structure sans racine est refusée',
	function (): void {
		$result = Validator::elements(
			[ [ 'id' => 'aaa111', 'name' => 'section', 'parent' => 'aaa111', 'children' => [] ] ]
		);

		assert_true( $result instanceof WP_Error, 'une structure sans racine doit être refusée' );
	}
);

test(
	'un identifiant dupliqué est refusé',
	function (): void {
		$result = Validator::elements(
			[
				[ 'id' => 'aaa111', 'name' => 'section', 'parent' => 0, 'children' => [] ],
				[ 'id' => 'aaa111', 'name' => 'block', 'parent' => 0, 'children' => [] ],
			]
		);

		assert_error( $result, 'dupliqué', 'les identifiants doivent être uniques' );
	}
);

test(
	'un identifiant ou un nom invalide est refusé',
	function (): void {
		assert_error(
			Validator::elements( [ [ 'id' => 'a', 'name' => 'section', 'parent' => 0 ] ] ),
			'invalide',
			'un identifiant trop court doit être refusé'
		);

		assert_error(
			Validator::elements( [ [ 'id' => 'aaa111', 'name' => '<script>', 'parent' => 0 ] ] ),
			'invalide',
			'un nom d’élément non alphanumérique doit être refusé'
		);
	}
);

echo "\nSécurité\n";

test(
	'un élément code exécutant du PHP est refusé',
	function (): void {
		$result = Validator::elements(
			[
				[
					'id'       => 'aaa111',
					'name'     => 'code',
					'parent'   => 0,
					'children' => [],
					'settings' => [ 'executeCode' => true, 'code' => 'system("rm -rf /");' ],
				],
			]
		);

		assert_error( $result, 'interdite', 'executeCode doit être bloqué' );
		assert_true( 403 === ( $result->get_error_data()['status'] ?? 0 ), 'le statut doit être 403' );
	}
);

test(
	'une signature de code fournie par l’API est retirée',
	function (): void {
		$result = Validator::elements(
			[
				[
					'id'       => 'aaa111',
					'name'     => 'code',
					'parent'   => 0,
					'children' => [],
					'settings' => [ 'code' => '<p>Bonjour</p>', 'signature' => 'contrefaçon' ],
				],
			]
		);

		assert_true( is_array( $result ), 'un bloc de code sans exécution reste autorisé' );
		assert_true( ! isset( $result[0]['settings']['signature'] ), 'la signature ne doit jamais être propagée' );
		assert_true( '<p>Bonjour</p>' === $result[0]['settings']['code'], 'le code non exécuté est conservé' );
	}
);

/**
 * Construit un élément « html » portant le balisage donné.
 *
 * @return array<int, array<string, mixed>>
 */
function html_element( string $markup ): array {
	return [
		[
			'id'       => 'aaa111',
			'name'     => 'html',
			'parent'   => 0,
			'children' => [],
			'settings' => [ 'html' => $markup ],
		],
	];
}

test(
	'un SVG en ligne est accepté dans un élément html',
	function (): void {
		$svg    = '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 6l6 4 6-4" stroke="currentColor"/></svg>';
		$result = Validator::elements( html_element( $svg ) );

		assert_true( is_array( $result ), 'un SVG décoratif doit passer' );
		assert_true( $svg === $result[0]['settings']['html'], 'le balisage est conservé tel quel' );
	}
);

test(
	'un élément html contenant du script est refusé',
	function (): void {
		$result = Validator::elements( html_element( '<div><script>alert(1)</script></div>' ) );

		assert_error( $result, 'script', 'une balise script doit être refusée' );
		assert_true( 403 === ( $result->get_error_data()['status'] ?? 0 ), 'le statut doit être 403' );
	}
);

test(
	'vider les valeurs d’attributs ne fait pas perdre les ressources distantes',
	function (): void {
		/*
		 * Le contrôle des gestionnaires lit une copie où les valeurs entre
		 * guillemets sont vidées — sans quoi un « > » dedans lui cache la fin de
		 * la balise. Mais une ressource distante **est** une valeur : la chercher
		 * sur cette même copie reviendrait à ne plus jamais la voir. Les deux
		 * lectures sont donc séparées, et ce test le prouve.
		 */
		foreach ( [
			'<img src="https://cdn.exemple.com/a.jpg">',
			'<img srcset="/a.jpg 1x, https://cdn.exemple.com/a2.jpg 2x">',
			'<div style="background: url(https://cdn.exemple.com/f.png)"></div>',
		] as $markup ) {
			assert_error(
				Validator::elements( html_element( $markup ) ),
				'distante',
				"« {$markup} » aurait dû être refusé"
			);
		}
	}
);

test(
	'une phrase contenant « on … = » n’est pas prise pour un gestionnaire',
	function (): void {
		/*
		 * Le contrôle s'applique à tout réglage de texte libre : un motif non
		 * ancré sur une balise refuserait de la prose parfaitement légitime.
		 */
		foreach ( [
			'<p>Les ondes = 3 hertz</p>',
			'<p>bon = mauvais, on = off</p>',
			'<p>Une phrase sans balise où on = 1</p>',
		] as $markup ) {
			$result = Validator::elements( html_element( $markup ) );

			assert_true( is_array( $result ), "« {$markup} » aurait dû passer" );
		}
	}
);

test(
	'un gestionnaire d’événement posé en attribut personnalisé est refusé',
	function (): void {
		/*
		 * `_attributes` est disponible sur tout élément Bricks, et Bricks le rend
		 * tel quel dans la balise. Le contrôle du texte libre ne le voyait pas :
		 * il ne suit que les valeurs contenant un « < », et `alert(1)` n'en a
		 * aucun. Reproduit sur un site en ligne — le pont acceptait l'écriture,
		 * et la page servait `<div … onclick="alert(1)">`.
		 */
		foreach ( [ 'onclick', 'onerror', 'onfocus', 'ONMOUSEOVER', ' onload ' ] as $nom ) {
			$element = [
				[
					'id'       => 'aaa111',
					'name'     => 'block',
					'parent'   => 0,
					'children' => [],
					'settings' => [ '_attributes' => [ [ 'name' => $nom, 'value' => 'alert(1)' ] ] ],
				],
			];

			assert_error(
				Validator::elements( $element ),
				'gestionnaire',
				"« {$nom} » aurait dû être refusé"
			);
		}
	}
);

test(
	'un attribut qui porte une URL interprétée est refusé',
	function (): void {
		foreach ( [ 'style', 'srcdoc', 'formaction', 'data' ] as $nom ) {
			$element = [
				[
					'id'       => 'aaa111',
					'name'     => 'block',
					'parent'   => 0,
					'children' => [],
					'settings' => [ '_attributes' => [ [ 'name' => $nom, 'value' => 'x' ] ] ],
				],
			];

			assert_error( Validator::elements( $element ), 'autorisé', "« {$nom} » aurait dû être refusé" );
		}
	}
);

test(
	'la valeur d’un attribut est éprouvée comme du HTML libre',
	function (): void {
		// Un `href` légitime, mais dont la valeur camoufle un javascript:.
		$element = [
			[
				'id'       => 'aaa111',
				'name'     => 'block',
				'parent'   => 0,
				'children' => [],
				'settings' => [ '_attributes' => [ [ 'name' => 'href', 'value' => 'java&#115;cript:alert(1)' ] ] ],
			],
		];

		assert_error( Validator::elements( $element ), 'javascript', 'valeur camouflée refusée' );
	}
);

test(
	'un attribut personnalisé légitime passe',
	function (): void {
		// Le contrôle ne doit pas fermer l'usage normal : `tabindex`, `role`,
		// `data-*` et `aria-*` sont exactement ce pour quoi le réglage existe.
		$element = [
			[
				'id'       => 'aaa111',
				'name'     => 'block',
				'parent'   => 0,
				'children' => [],
				'settings' => [
					'_attributes' => [
						[ 'name' => 'tabindex', 'value' => '-1' ],
						[ 'name' => 'role', 'value' => 'region' ],
						[ 'name' => 'data-form', 'value' => 'contact' ],
						[ 'name' => 'aria-label', 'value' => 'Nos offres' ],
						[ 'name' => 'animate', 'value' => 'fade-up' ],
					],
				],
			],
		];

		assert_true( is_array( Validator::elements( $element ) ), 'attributs légitimes acceptés' );
	}
);

test(
	'une URL « javascript: » camouflée est refusée',
	function (): void {
		/*
		 * Un navigateur décode les entités d'un attribut avant de suivre l'URL,
		 * et son analyseur retire tabulations et retours à la ligne **à
		 * l'intérieur** du schéma. Les trois formes ci-dessous s'exécutent donc
		 * chez le visiteur, et traversaient le contrôle.
		 */
		foreach (
			[
				'<a href="javascript:alert(1)">x</a>',
				'<a href="java&#115;cript:alert(1)">x</a>',
				'<a href="&#106;avascript:alert(1)">x</a>',
				"<a href=\"java\tscript:alert(1)\">x</a>",
				"<a href=\"ja\nvascript:alert(1)\">x</a>",
				'<a href="JAVASCRIPT:alert(1)">x</a>',
			] as $markup
		) {
			assert_error(
				Validator::elements( html_element( $markup ) ),
				'javascript',
				"« {$markup} » aurait dû être refusé"
			);
		}
	}
);

test(
	'une phrase contenant « JavaScript : » n’est pas prise pour une URL',
	function (): void {
		// L'espace ordinaire est exclue du motif, et il le faut : un schéma
		// d'URL n'en contient pas, une phrase oui.
		assert_true(
			is_array( Validator::elements( html_element( '<p>JavaScript : les bases</p>' ) ) ),
			'phrase acceptée'
		);
	}
);

test(
	'un gestionnaire d’événement en attribut est refusé',
	function (): void {
		/*
		 * Deux formes échappent chacune à un motif pris seul : l'espace avant le
		 * signe égal, et un attribut précédent qui contient un « > » — il ferme la
		 * balise pour une expression régulière, pas pour le navigateur.
		 *
		 * C'est leur **combinaison** qui a réellement traversé le contrôle, et ce
		 * cas manquait ici : chacune était couverte séparément, aucune ne l'était
		 * ensemble. Refuser un test de plus par forme n'aurait rien changé ; c'est
		 * le croisement qu'il fallait écrire.
		 */
		$cas = [
			'<svg onload="alert(1)"></svg>',
			'<svg onload ="alert(1)"></svg>',
			'<img alt=">" onerror="alert(1)">',
			'<img alt=">" onerror ="alert(1)">',
			"<img alt='>' onerror ='alert(1)'>",
			"<img src=\"/a.jpg\" onerror\n=\"alert(1)\">",
			'<img src="/a.jpg" ONERROR="alert(1)">',
			'<div data-x=">"><span onclick="x()">a</span></div>',

			/*
			 * La barre oblique sépare deux attributs, exactement comme l'espace.
			 * `<a href="x"/onclick="…">` est du HTML valide que tous les
			 * navigateurs exécutent — et le motif ne cherchait qu'un caractère
			 * d'espacement. Ces trois formes traversaient le filtre.
			 */
			'<a href="x"/onclick="alert(1)">a</a>',
			'<img src=x/onerror=alert(1)>',
			'<svg/onload="alert(1)"></svg>',
		];

		foreach ( $cas as $markup ) {
			assert_error(
				Validator::elements( html_element( $markup ) ),
				'gestionnaires',
				"« {$markup} » aurait dû être refusé"
			);
		}
	}
);

test(
	'une URL javascript: est refusée',
	function (): void {
		$result = Validator::elements( html_element( '<a href="javascript:alert(1)">Lien</a>' ) );

		assert_error( $result, 'javascript', 'une URL javascript: doit être refusée' );
	}
);

test(
	'une ressource distante est refusée dans un élément html',
	function (): void {
		$result = Validator::elements( html_element( '<img src="https://cdn.exemple.com/pixel.gif" alt="">' ) );

		assert_error( $result, 'zéro dépendance', 'une ressource distante doit être refusée' );
	}
);

test(
	'une iframe est refusée dans un élément html',
	function (): void {
		$result = Validator::elements( html_element( '<iframe src="/interne"></iframe>' ) );

		assert_error( $result, 'iframe', 'une iframe doit être refusée, même locale' );
	}
);

test(
	'du PHP est refusé dans un élément html',
	function (): void {
		$result = Validator::elements( html_element( '<?php system("id"); ?>' ) );

		assert_error( $result, 'PHP', 'du PHP doit être refusé' );
	}
);

test(
	'un script dans le texte d’un titre est refusé',
	function (): void {
		$result = Validator::elements(
			[
				[
					'id'       => 'aaa111',
					'name'     => 'heading',
					'parent'   => 0,
					'children' => [],
					'settings' => [ 'text' => 'Bonjour<script>alert(1)</script>' ],
				],
			]
		);

		assert_error( $result, 'script', 'un heading rend son texte tel quel : le contrôle ne peut pas dépendre du type d’élément' );
	}
);

test(
	'un script dans un réglage imbriqué est refusé',
	function (): void {
		// Le libellé d'un onglet, le titre d'un accordéon : les réglages de
		// Bricks se nichent souvent dans une répétition.
		$result = Validator::elements(
			[
				[
					'id'       => 'aaa111',
					'name'     => 'tabs',
					'parent'   => 0,
					'children' => [],
					'settings' => [ 'tabs' => [ [ 'title' => '<script>alert(1)</script>' ] ] ],
				],
			]
		);

		assert_error( $result, 'script', 'un réglage imbriqué est rendu comme les autres' );
	}
);

test(
	'les ressources distantes déguisées sont refusées',
	function (): void {
		$cas = [
			'<img srcset="local.jpg 1x, https://cdn.exemple.com/x.jpg 2x" alt="">',
			'<video poster="https://cdn.exemple.com/p.jpg"></video>',
			'<div style="background-image:url(https://cdn.exemple.com/f.png)"></div>',
			'<style>@import url(https://polices.exemple.com/p.css);</style>',
			'<style>@import "https://polices.exemple.com/p.css";</style>',
			'<svg><use href="https://cdn.exemple.com/i.svg#x"/></svg>',
			'<img src="//cdn.exemple.com/x.gif" alt="">',
		];

		foreach ( $cas as $markup ) {
			assert_error(
				Validator::elements( html_element( $markup ) ),
				'zéro dépendance',
				"« {$markup} » aurait dû être refusé"
			);
		}
	}
);

test(
	'une balise base est refusée',
	function (): void {
		// Son href ne charge rien : il réécrit toutes les URL relatives de la page.
		assert_error(
			Validator::elements( html_element( '<base href="https://exemple.com/">' ) ),
			'base',
			'une balise base doit être refusée'
		);
	}
);

test(
	'un texte enrichi légitime reste accepté',
	function (): void {
		/*
		 * Le contrôle porte désormais sur de la prose : un refus injustifié
		 * bloquerait une écriture parfaitement valide. Les trois derniers cas sont
		 * ceux que les motifs d'origine refusaient à tort — un lien externe n'est
		 * pas une dépendance, une espace avant le deux-points est une règle
		 * typographique française, et « ondes = » n'est pas un gestionnaire
		 * d'événement.
		 */
		$cas = [
			'<strong>Bonjour</strong> et bienvenue',
			'Site : <a href="https://www.exemple.fr">exemple.fr</a>',
			'Programme <b>web</b> : JavaScript : les bases',
			'Puissance des <b>ondes</b> = 3 W',
			'<img src="/wp-content/uploads/logo.svg" alt="">',
		];

		foreach ( $cas as $texte ) {
			$result = Validator::elements(
				[
					[
						'id'       => 'aaa111',
						'name'     => 'text',
						'parent'   => 0,
						'children' => [],
						'settings' => [ 'text' => $texte ],
					],
				]
			);

			assert_true( is_array( $result ), "« {$texte} » aurait dû passer, il a été refusé" );
		}
	}
);

test(
	'une signature imbriquée dans une requête est retirée',
	function (): void {
		$result = Validator::elements(
			[
				[
					'id'       => 'aaa111',
					'name'     => 'div',
					'parent'   => 0,
					'children' => [],
					'settings' => [ 'query' => [ 'objectType' => 'post', 'signature' => 'contrefaçon' ] ],
				],
			]
		);

		assert_true( is_array( $result ), 'structure attendue' );
		assert_true( ! isset( $result[0]['settings']['query']['signature'] ), 'la signature de requête doit être retirée' );
	}
);

/* --- Classes globales ----------------------------------------------- */

echo "\nTypes de contenu exposés\n";

test(
	'un type déclaré show_in_rest est servi',
	function (): void {
		$GLOBALS['anode_types']['evenement'] = new \WP_Post_Type( 'evenement', true );

		$resultat = \Anode\Bridge\Rest_Content::readable_post_type( 'evenement' );

		assert_true( $resultat instanceof \WP_Post_Type, 'un type éditorial doit passer' );
	}
);

test(
	'un type privé est refusé, pas servi à moitié',
	function (): void {
		// Le cas réel : les demandes reçues par formulaire, dont le titre
		// portait l'adresse du demandeur. Un compte d'édition les listait.
		$GLOBALS['anode_types']['anode_soumission'] = new \WP_Post_Type( 'anode_soumission', false );

		$resultat = \Anode\Bridge\Rest_Content::readable_post_type( 'anode_soumission' );

		assert_error( $resultat, 'n’est pas exposé', 'un type privé doit être refusé' );
		assert_true( 403 === ( $resultat->get_error_data()['status'] ?? 0 ), 'le statut doit être 403' );
	}
);

test(
	'un type inconnu répond 404',
	function (): void {
		$resultat = \Anode\Bridge\Rest_Content::readable_post_type( 'type_qui_nexiste_pas' );

		assert_error( $resultat, 'inconnu', 'un type inconnu doit être signalé comme tel' );
		assert_true( 404 === ( $resultat->get_error_data()['status'] ?? 0 ), 'le statut doit être 404' );
	}
);

echo "\nClasses globales BEM\n";

test(
	'les noms BEM canoniques sont acceptés',
	function (): void {
		$result = Validator::global_classes(
			[
				[ 'name' => 'c-hero' ],
				[ 'name' => 'c-hero__title' ],
				[ 'name' => 'c-hero--dark' ],
				[ 'name' => 'c-hero__title--large' ],
				[ 'name' => 'l-grid-3' ],
				[ 'name' => 'u-visually-hidden' ],
			]
		);

		assert_true( is_array( $result ), 'les six formes canoniques doivent être acceptées' );
		assert_true( count( $result ) === 6, 'six classes attendues' );

		foreach ( $result as $class ) {
			assert_true( isset( $class['id'] ) && strlen( $class['id'] ) >= 3, 'un identifiant est généré' );
		}
	}
);

test(
	'les noms non conformes BEM sont refusés',
	function (): void {
		foreach ( [ 'C-Hero', 'c hero', 'c-hero__a__b', 'c-hero--a--b', '1-bloc' ] as $name ) {
			assert_error(
				Validator::global_classes( [ [ 'name' => $name ] ] ),
				'BEM',
				"« {$name} » aurait dû être refusé"
			);
		}
	}
);

test(
	'le mode non strict accepte les classes héritées',
	function (): void {
		$result = Validator::global_classes( [ [ 'name' => 'legacyClass' ] ], false );

		assert_true( is_array( $result ), 'le mode non strict doit accepter les noms hérités' );
	}
);

test(
	'une classe dupliquée est refusée',
	function (): void {
		assert_error(
			Validator::global_classes( [ [ 'name' => 'c-hero' ], [ 'name' => 'c-hero' ] ] ),
			'dupliquée',
			'les doublons doivent être refusés'
		);
	}
);

test(
	'« %root% » est refusé dans le CSS personnalisé d’une classe',
	function (): void {
		assert_error(
			Validator::global_classes(
				[ [ 'name' => 'c-hero', 'settings' => [ '_cssCustom' => '%root% { display: grid; }' ] ] ]
			),
			'%root%',
			'le jeton n’est résolu que par le builder : la feuille sort littérale, donc cassée'
		);
	}
);

test(
	'« %root% » est refusé dans un sous-sélecteur',
	function (): void {
		assert_error(
			Validator::global_classes(
				[
					[
						'name'      => 'c-hero',
						'settings'  => [],
						'selectors' => [
							[ 'selector' => 'svg', 'settings' => [ '_cssCustom' => '%root% svg { width: 1em; }' ] ],
						],
					],
				]
			),
			'%root%',
			'un sous-sélecteur sort dans la même feuille que le reste'
		);
	}
);

test(
	'le mode replace conserve l’identifiant d’une classe déjà connue',
	function (): void {
		/*
		 * Le cas qui vide un site sans rien changer aux pages : les éléments
		 * désignent les classes par identifiant, et le validateur en génère un neuf
		 * dès qu'il n'en reçoit pas.
		 */
		$existant = [ [ 'id' => 'abc123', 'name' => 'c-hero', 'settings' => [] ] ];
		$entrant  = Validator::global_classes( [ [ 'name' => 'c-hero' ], [ 'name' => 'c-hero__title' ] ] );

		assert_true( is_array( $entrant ), 'les deux classes doivent être valides' );
		assert_true( 'abc123' !== $entrant[0]['id'], 'le validateur attribue bien un identifiant neuf' );

		// Depuis PHP 8.1, la réflexion atteint une méthode privée sans autorisation
		// préalable : `setAccessible()` ne servirait plus qu'à émettre un avis.
		$methode = new ReflectionMethod( \Anode\Bridge\Rest_Bricks::class, 'keep_existing_ids' );

		[ $resultat, $ajoutees, $mises_a_jour ] = $methode->invoke( new \Anode\Bridge\Rest_Bricks(), $existant, $entrant );

		assert_true( 2 === count( $resultat ), 'la liste finale est bien la liste entrante' );
		assert_true( 'abc123' === $resultat[0]['id'], 'une classe reconnue par son nom garde son identifiant' );
		assert_true( 'c-hero__title' === $resultat[1]['name'], 'l’ordre de la liste entrante est conservé' );
		assert_true( 1 === $ajoutees && 1 === $mises_a_jour, 'le compte rendu distingue l’ajout de la mise à jour' );
	}
);

/* --- Conditions d'affichage d'un template ---------------------------- */

echo "\nConditions d’affichage d’un template\n";

/**
 * Ces cas sont relevés, pas imaginés.
 *
 * Le 31/07/2026, poser le gabarit dans le template « 404 » de
 * blueprint.agence-anode.fr sans en repasser les conditions a remplacé
 * `templateConditions` par « any ». Bricks a alors servi la page d'erreur à la
 * place de **tout** le site — accueil comprise, en HTTP 200 — et l'appel avait
 * répondu « mis à jour avec 10 élément(s) ».
 */
$conditions = new ReflectionMethod( \Anode\Bridge\Rest_Bricks::class, 'conditions_du_template' );

test(
	'des conditions fournies font foi',
	function () use ( $conditions ): void {
		$voulues = [ [ 'main' => 'archiveType', 'archiveType' => [ 'postType' ] ] ];

		assert_true(
			$voulues === $conditions->invoke( null, $voulues, [ [ 'main' => 'any' ] ], 'archive' ),
			'l’appelant décide quand il se prononce'
		);
	}
);

test(
	'une mise à jour sans conditions conserve celles du site',
	function () use ( $conditions ): void {
		/*
		 * Les conditions posées ici sont volontairement **différentes** du défaut
		 * que le type produirait : sans cela, le test passerait aussi bien si la
		 * conservation n'était pas implémentée du tout. C'est le piège du test
		 * qui ne peut pas échouer.
		 */
		$posees = [ [ 'main' => 'ids', 'ids' => [ 42 ] ] ];

		assert_true(
			$posees === $conditions->invoke( null, null, $posees, 'content' ),
			'écraser une condition existante déplace un template que personne n’a demandé à déplacer'
		);

		$restreinte = [ [ 'main' => 'error' ], [ 'main' => 'search' ] ];

		assert_true(
			$restreinte === $conditions->invoke( null, null, $restreinte, 'error' ),
			'même sur un type qui a un défaut, c’est le site qui fait foi'
		);
	}
);

test(
	'un template « error » créé sans conditions ne capture pas le site',
	function () use ( $conditions ): void {
		$resultat = $conditions->invoke( null, null, null, 'error' );

		assert_true(
			[ [ 'main' => 'error' ] ] === $resultat,
			'« any » sur un type error sert la page d’erreur partout : c’est le défaut mesuré le 31/07/2026'
		);
	}
);

test(
	'un template « search » créé sans conditions vise la recherche',
	function () use ( $conditions ): void {
		assert_true(
			[ [ 'main' => 'search' ] ] === $conditions->invoke( null, null, null, 'search' ),
			'Bricks a une condition dédiée (includes/templates.php, case « search »)'
		);
	}
);

test(
	'un type servi partout garde bien « any »',
	function () use ( $conditions ): void {
		assert_true(
			[ [ 'main' => 'any' ] ] === $conditions->invoke( null, null, [], 'content' ),
			'sans condition, Bricks n’applique un template nulle part'
		);
	}
);

/* --- Variables ------------------------------------------------------ */

echo "\nVariables globales\n";

test(
	'les noms de variables en kebab-case sont acceptés',
	function (): void {
		$result = Validator::variables(
			[
				[ 'name' => 'color-primary', 'value' => '#2f6df6' ],
				[ 'name' => 'space-l', 'value' => '2rem' ],
			]
		);

		assert_true( is_array( $result ) && count( $result ) === 2, 'deux variables attendues' );
		assert_true( '#2f6df6' === $result[0]['value'], 'la valeur est conservée' );
	}
);

test(
	'un nom de variable invalide est refusé',
	function (): void {
		foreach ( [ '--color-primary', 'Color-Primary', 'color_primary', '1-color' ] as $name ) {
			assert_error(
				Validator::variables( [ [ 'name' => $name, 'value' => '#fff' ] ] ),
				'invalide',
				"« {$name} » aurait dû être refusé"
			);
		}
	}
);

test(
	'une variable dupliquée est refusée',
	function (): void {
		assert_error(
			Validator::variables(
				[
					[ 'name' => 'color-primary', 'value' => '#fff' ],
					[ 'name' => 'color-primary', 'value' => '#000' ],
				]
			),
			'dupliquée',
			'les doublons doivent être refusés'
		);
	}
);

test(
	'une valeur numérique est convertie en chaîne',
	function (): void {
		$result = Validator::variables( [ [ 'name' => 'line-height-base', 'value' => 1.5 ] ] );

		assert_true( is_array( $result ), 'structure attendue' );
		assert_true( '1.5' === $result[0]['value'], 'la valeur doit être convertie en chaîne' );
	}
);

/* --- Composants ----------------------------------------------------- */

echo "\nValidation des composants\n";

/**
 * Composant minimal valide : une section, un titre, une propriété reliée.
 *
 * @param array<string, mixed> $surcharge Champs à remplacer.
 *
 * @return array<string, mixed>
 */
function composant_valide( array $surcharge = [] ): array {
	return array_merge(
		[
			'id'         => 'hero01',
			'label'      => 'Section hero',
			'elements'   => [
				[ 'id' => 'hero01', 'name' => 'section', 'parent' => 0, 'children' => [ 'titre1' ], 'settings' => [] ],
				[ 'id' => 'titre1', 'name' => 'heading', 'parent' => 'hero01', 'children' => [], 'settings' => [ 'text' => 'Titre' ] ],
			],
			'properties' => [
				[
					'id'          => 'titre',
					'label'       => 'Titre',
					'type'        => 'text',
					'connections' => [ 'titre1' => [ 'text' ] ],
				],
			],
		],
		$surcharge
	);
}

test(
	'un composant valide est accepté et normalisé',
	function (): void {
		$result = Validator::component( composant_valide() );

		assert_true( is_array( $result ), 'structure attendue, erreur reçue' );
		assert_true( 'hero01' === $result['id'], 'l’identifiant doit être conservé' );
		assert_true( 'components' === $result['category'], 'une catégorie par défaut est posée' );
		assert_true( 2 === count( $result['elements'] ), 'les deux éléments doivent survivre' );
		assert_true(
			[ 'titre1' => [ 'text' ] ] === $result['properties'][0]['connections'],
			'la connexion doit être conservée telle quelle'
		);
	}
);

test(
	'un composant sans nom est refusé',
	function (): void {
		assert_error(
			Validator::component( composant_valide( [ 'label' => '  ' ] ) ),
			'label',
			'le nom est ce que voit l’éditeur du site'
		);
	}
);

test(
	'un composant à plusieurs racines est refusé',
	function (): void {
		$elements   = composant_valide()['elements'];
		$elements[] = [ 'id' => 'autre1', 'name' => 'section', 'parent' => 0, 'children' => [], 'settings' => [] ];

		assert_error(
			Validator::component( composant_valide( [ 'elements' => $elements ] ) ),
			'éléments racine',
			'Bricks ne rend que la première racine : les suivantes disparaissent en silence'
		);
	}
);

test(
	'un identifiant de composant qui n’est pas celui de la racine est refusé',
	function (): void {
		assert_error(
			Validator::component( composant_valide( [ 'id' => 'autre1' ] ) ),
			'élément racine',
			'Bricks retrouve la racine par l’identifiant du composant'
		);
	}
);

test(
	'une connexion vers un élément absent est refusée',
	function (): void {
		assert_error(
			Validator::component(
				composant_valide(
					[
						'properties' => [
							[ 'id' => 'titre', 'type' => 'text', 'connections' => [ 'fantome' => [ 'text' ] ] ],
						],
					]
				)
			),
			'n’existe pas dans le composant',
			'une connexion vers le vide donne une propriété qui ne fait rien'
		);
	}
);

test(
	'une propriété sans connexion est refusée',
	function (): void {
		assert_error(
			Validator::component(
				composant_valide( [ 'properties' => [ [ 'id' => 'titre', 'type' => 'text' ] ] ] )
			),
			'aucune connexion',
			'une propriété orpheline trompe l’éditeur du site'
		);
	}
);

test(
	'un type de propriété inconnu est refusé',
	function (): void {
		assert_error(
			Validator::component(
				composant_valide(
					[
						'properties' => [
							[ 'id' => 'titre', 'type' => 'wysiwyg', 'connections' => [ 'titre1' => [ 'text' ] ] ],
						],
					]
				)
			),
			'type inconnu',
			'un type inconnu produit un contrôle vide dans le builder'
		);
	}
);

test(
	'une propriété dupliquée est refusée',
	function (): void {
		$property = [ 'id' => 'titre', 'type' => 'text', 'connections' => [ 'titre1' => [ 'text' ] ] ];

		assert_error(
			Validator::component( composant_valide( [ 'properties' => [ $property, $property ] ] ) ),
			'dupliquée',
			'deux propriétés du même nom : la seconde masque la première'
		);
	}
);

test(
	'une instance de composant conserve son cid et ses valeurs',
	function (): void {
		$result = Validator::elements(
			[
				[
					'id'         => 'inst01',
					'name'       => 'section',
					'parent'     => 0,
					'children'   => [],
					'settings'   => [],
					'cid'        => 'hero01',
					'properties' => [ 'titre' => 'Bonjour' ],
				],
			]
		);

		assert_true( is_array( $result ), 'structure attendue' );
		assert_true( 'hero01' === $result[0]['cid'], 'le cid désigne le composant' );
		assert_true(
			[ 'titre' => 'Bonjour' ] === $result[0]['properties'],
			'sans « properties », toutes les instances afficheraient la même valeur'
		);
	}
);

test(
	'une valeur de propriété ne peut pas glisser de script dans une instance',
	function (): void {
		assert_error(
			Validator::elements(
				[
					[
						'id'         => 'inst01',
						'name'       => 'section',
						'parent'     => 0,
						'children'   => [],
						'settings'   => [],
						'cid'        => 'hero01',
						'properties' => [ 'icone' => '<svg><script>alert(1)</script></svg>' ],
					],
				]
			),
			'script',
			'une propriété reliée à un élément « html » contournerait le contrôle du balisage'
		);
	}
);

/* --- Le canevas : bornes et libellés --------------------------------- */

test( 'une dimension fixe reçoit son minimum et son maximum', function (): void {
	// Sans bornes, le canevas redimensionne l'élément : 40 px rendus à 90.
	// La valeur fautive n'est écrite nulle part, elle est calculée.
	$result = Validator::global_classes(
		[ [ 'name' => 'c-fait__icone', 'settings' => [ '_width' => '40px', '_height' => '40px' ] ] ]
	);

	$s = $result[0]['settings'];

	assert_true( '40px' === ( $s['_widthMin'] ?? null ), 'min-width absent' );
	assert_true( '40px' === ( $s['_widthMax'] ?? null ), 'max-width absent' );
	assert_true( '40px' === ( $s['_heightMin'] ?? null ), 'min-height absent' );
	assert_true( '40px' === ( $s['_heightMax'] ?? null ), 'max-height absent' );
} );

test( 'une dimension fluide n’est jamais bornée', function (): void {
	// La figer casserait le conteneur sur le front, là où le visiteur le voit.
	// Sur un site du parc, 32 dimensions sur 61 étaient dans ce cas.
	foreach ( [ '100%', 'auto', 'max-content', 'fit-content', 'calc(100% - 20px)' ] as $valeur ) {
		$result = Validator::global_classes(
			[ [ 'name' => 'l-grille', 'settings' => [ '_width' => $valeur ] ] ]
		);

		$s = $result[0]['settings'];

		assert_true(
			! isset( $s['_widthMin'] ) && ! isset( $s['_widthMax'] ),
			"« {$valeur} » a été bornée — le conteneur casse sur le front"
		);
	}
} );

test( 'une borne déjà posée n’est pas écrasée', function (): void {
	// Un minimum différent de la largeur est une intention, pas un oubli.
	$result = Validator::global_classes(
		[ [ 'name' => 'c-carte', 'settings' => [ '_width' => '300px', '_widthMin' => '200px' ] ] ]
	);

	assert_true( '200px' === $result[0]['settings']['_widthMin'], 'le minimum choisi a été écrasé' );
	assert_true( '300px' === $result[0]['settings']['_widthMax'], 'le maximum n’a pas été posé' );
} );

test( 'une dimension bornée à un point de rupture garde son suffixe', function (): void {
	$result = Validator::global_classes(
		[ [ 'name' => 'c-hero__note', 'settings' => [ '_width:mobile_portrait' => '280px' ] ] ]
	);

	$s = $result[0]['settings'];

	assert_true( '280px' === ( $s['_widthMin:mobile_portrait'] ?? null ), 'borne posée hors du point de rupture' );
	assert_true( ! isset( $s['_widthMin'] ), 'une borne de base a été posée par erreur' );
} );

test( 'un bouton sans libellé reçoit une espace', function (): void {
	// Sinon Bricks affiche « Je suis un bouton » dans le canevas, par-dessus
	// son voisinage. Le mot ne sort jamais sur le front : invisible aux captures.
	foreach ( [ '', '   ', null ] as $vide ) {
		$reglages = null === $vide ? [] : [ 'text' => $vide ];
		$result   = Validator::elements(
			[ [ 'id' => 'abc123', 'name' => 'button', 'parent' => 0, 'children' => [], 'settings' => $reglages ] ]
		);

		assert_true( is_array( $result ), 'élément refusé' );
		assert_true( ' ' === $result[0]['settings']['text'], 'le remplissage de Bricks n’est pas écarté' );
	}
} );

test( 'un bouton qui a un libellé le garde', function (): void {
	$result = Validator::elements(
		[ [ 'id' => 'abc123', 'name' => 'button', 'parent' => 0, 'children' => [], 'settings' => [ 'text' => 'Candidater' ] ] ]
	);

	assert_true( 'Candidater' === $result[0]['settings']['text'], 'le libellé a été écrasé' );
} );


/* --- Le bornage des dimensions (§39, et l'accord des bornes) --------- */

/** Écrit une classe par le validateur et rend ses réglages. */
function reglages_apres( array $settings ): array {
	$out = Validator::global_classes( [ [ 'name' => 'c-essai', 'settings' => $settings ] ] );
	assert_true( is_array( $out ), 'la classe devait passer' );

	return $out[0]['settings'];
}

test( 'une longueur littérale reçoit ses quatre bornes', function (): void {
	$r = reglages_apres( [ '_width' => '40px', '_height' => '40px' ] );

	foreach ( [ '_widthMin', '_widthMax', '_heightMin', '_heightMax' ] as $cle ) {
		assert_true( '40px' === ( $r[ $cle ] ?? null ), "borne {$cle} absente" );
	}
} );

test( 'un jeton est borné, avec son EXPRESSION et non une valeur résolue', function (): void {
	// Le cas mesuré le 07/08/2026 : la bascule de thème portait
	// `var(--control-h)`, que l'ancien motif laissait passer sans bornes.
	$r = reglages_apres( [ '_width' => 'var(--control-h)', '_height' => 'var(--control-h)' ] );

	assert_true( 'var(--control-h)' === ( $r['_widthMax'] ?? null ), 'le jeton n’est pas borné' );
	assert_true( 'var(--control-h)' === ( $r['_heightMin'] ?? null ), 'le jeton n’est pas borné en hauteur' );
} );

test( 'une dimension fluide n’est jamais bornée', function (): void {
	// Figer un pourcentage empêche l’élément de rétrécir : le conteneur
	// déborde SUR LE FRONT, là où le visiteur le voit.
	foreach ( [ '100%', 'auto', 'fit-content', 'max-content', '50vw', 'var(--x, 100%)', 'calc(var(--x) + 2px)' ] as $fluide ) {
		$r = reglages_apres( [ '_width' => $fluide ] );
		assert_true( ! isset( $r['_widthMin'] ), "« {$fluide} » a été borné à tort" );
	}
} );

test( 'une borne déjà posée n’est pas écrasée', function (): void {
	$r = reglages_apres( [ '_width' => '40px', '_widthMin' => '10px' ] );

	assert_true( '10px' === $r['_widthMin'], 'le minimum choisi par l’auteur a été écrasé' );
	assert_true( '40px' === $r['_widthMax'], 'le maximum manquant n’a pas été posé' );
} );

test( 'une borne écrite seule à un palier reçoit sa jumelle au même palier', function (): void {
	/*
	 * LE défaut du 07/08/2026, à l’octet près : `_heightMin:tablet_portrait`
	 * posé seul, `_heightMax` restant à la valeur de base. En CSS le minimum
	 * l’emporte sur le maximum — la boîte sortait en 34 × 44.
	 */
	$r = reglages_apres( [
		'_width'                    => 'var(--control-h)',
		'_height'                   => 'var(--control-h)',
		'_heightMin:tablet_portrait' => '44px',
	] );

	assert_true( '44px' === ( $r['_heightMax:tablet_portrait'] ?? null ), 'la jumelle manque : min > max hérité' );
} );

test( 'une paire complète à un palier reste intacte', function (): void {
	$r = reglages_apres( [
		'_widthMin:mobile_portrait' => '20px',
		'_widthMax:mobile_portrait' => '60px',
	] );

	assert_true( '20px' === $r['_widthMin:mobile_portrait'], 'le minimum a bougé' );
	assert_true( '60px' === $r['_widthMax:mobile_portrait'], 'un intervalle voulu a été écrasé' );
} );

test( 'une borne seule au palier de BASE n’hérite de rien, donc reste seule', function (): void {
	$r = reglages_apres( [ '_heightMin' => '44px' ] );

	assert_true( ! isset( $r['_heightMax'] ), 'un plafond a été inventé là où rien ne le contredit' );
} );

echo "\n{$passed} test(s) réussi(s), {$failed} échec(s).\n";

exit( $failed > 0 ? 1 : 0 );
