<?php
/**
 * Validation et assainissement des structures Bricks.
 *
 * Une structure Bricks est un tableau plat de nœuds reliés par `parent` et
 * `children`. Une structure incohérente (parent orphelin, cycle, id dupliqué)
 * casse le builder sans message d'erreur : on refuse en amont plutôt que
 * d'écrire des données corrompues en base.
 *
 * @package Anode\Bridge
 */

declare( strict_types = 1 );

namespace Anode\Bridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Validator {

	/** Identifiant Bricks : alphanumérique court. */
	private const ID_PATTERN = '/^[A-Za-z0-9_-]{3,32}$/';

	/** Nom d'élément Bricks (ex. « section », « heading », « my-component »). */
	private const NAME_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,63}$/i';

	/**
	 * Nom de classe CSS conforme BEM.
	 *
	 * bloc            : c-hero, l-grid, u-hidden
	 * élément         : c-hero__title
	 * modificateur    : c-hero--dark, c-hero__title--large
	 */
	public const BEM_PATTERN = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*(__[a-z][a-z0-9]*(-[a-z0-9]+)*)?(--[a-z][a-z0-9]*(-[a-z0-9]+)*)?$/';

	/**
	 * Nom de variable CSS (sans les deux tirets initiaux).
	 */
	private const VARIABLE_PATTERN = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/';

	/**
	 * Valide une structure d'éléments Bricks complète.
	 *
	 * @param mixed $elements Structure brute reçue de l'API.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error Structure assainie ou erreur détaillée.
	 */
	public static function elements( mixed $elements ): array|\WP_Error {
		if ( ! is_array( $elements ) ) {
			return self::error( 'La structure doit être un tableau d’éléments.' );
		}

		// Un contenu vide est légitime (page vidée).
		if ( ! $elements ) {
			return [];
		}

		$clean = [];
		$ids   = [];

		foreach ( array_values( $elements ) as $index => $element ) {
			$node = self::element( $element, $index );

			if ( $node instanceof \WP_Error ) {
				return $node;
			}

			if ( isset( $ids[ $node['id'] ] ) ) {
				return self::error( sprintf( 'Identifiant d’élément dupliqué : « %s ».', $node['id'] ) );
			}

			$ids[ $node['id'] ] = $index;
			$clean[]            = $node;
		}

		$integrity = self::check_tree_integrity( $clean, $ids );

		if ( $integrity instanceof \WP_Error ) {
			return $integrity;
		}

		return $clean;
	}

	/**
	 * Valide un nœud unique.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function element( mixed $element, int $index ): array|\WP_Error {
		if ( ! is_array( $element ) ) {
			return self::error( sprintf( 'Élément #%d : objet attendu.', $index ) );
		}

		$id   = $element['id'] ?? '';
		$name = $element['name'] ?? '';

		if ( ! is_string( $id ) || ! preg_match( self::ID_PATTERN, $id ) ) {
			return self::error( sprintf( 'Élément #%d : « id » manquant ou invalide (3 à 32 caractères alphanumériques).', $index ) );
		}

		if ( ! is_string( $name ) || ! preg_match( self::NAME_PATTERN, $name ) ) {
			return self::error( sprintf( 'Élément « %s » : « name » manquant ou invalide.', $id ) );
		}

		$parent = $element['parent'] ?? 0;

		if ( ! is_string( $parent ) && ! is_int( $parent ) ) {
			return self::error( sprintf( 'Élément « %s » : « parent » doit être 0 ou un identifiant.', $id ) );
		}

		if ( is_string( $parent ) && '' !== $parent && '0' !== $parent && ! preg_match( self::ID_PATTERN, $parent ) ) {
			return self::error( sprintf( 'Élément « %s » : identifiant parent invalide.', $id ) );
		}

		$children = $element['children'] ?? [];

		if ( ! is_array( $children ) ) {
			return self::error( sprintf( 'Élément « %s » : « children » doit être un tableau.', $id ) );
		}

		foreach ( $children as $child ) {
			if ( ! is_string( $child ) || ! preg_match( self::ID_PATTERN, $child ) ) {
				return self::error( sprintf( 'Élément « %s » : identifiant enfant invalide.', $id ) );
			}
		}

		$settings = $element['settings'] ?? [];

		if ( ! is_array( $settings ) ) {
			return self::error( sprintf( 'Élément « %s » : « settings » doit être un objet.', $id ) );
		}

		$settings = self::sanitize_settings( $settings, $name, $id );

		if ( $settings instanceof \WP_Error ) {
			return $settings;
		}

		$node = [
			'id'       => $id,
			'name'     => $name,
			'parent'   => ( '' === $parent || '0' === $parent ) ? 0 : $parent,
			'children' => array_values( $children ),
			'settings' => $settings,
		];

		/*
		 * Champs facultatifs conservés tels quels s'ils sont bien typés.
		 *
		 * `cid` et `properties` forment une instance de composant : le premier
		 * désigne le composant, le second porte les valeurs de ses propriétés.
		 * Sans `properties`, une instance afficherait les valeurs par défaut du
		 * composant sur toutes les pages.
		 */
		/*
		 * `global` désigne un élément global : Bricks le rend depuis sa
		 * définition partagée, pas depuis la copie locale de la page — laquelle
		 * est souvent périmée. Absent de cette liste, le champ était écarté à
		 * l'écriture et l'élément retombait sur sa copie, affichant d'anciennes
		 * valeurs sans la moindre erreur. Mesuré sur CPA : deux compteurs
		 * passaient de « 40 » et « + 10 000 » à « 24 » et « 7,000 » au simple
		 * aller-retour de lecture/écriture.
		 *
		 * `themeStyles` au pluriel est la forme que Bricks écrit ; `themeStyle`
		 * figurait seul, et ne correspondait à rien.
		 */
		foreach ( [ 'label', 'global', 'themeStyle', 'themeStyles', 'component', 'cid', 'instanceId', 'properties' ] as $optional ) {
			if ( isset( $element[ $optional ] ) && ( is_string( $element[ $optional ] ) || is_array( $element[ $optional ] ) ) ) {
				$node[ $optional ] = $element[ $optional ];
			}
		}

		/*
		 * Les valeurs de propriétés atterrissent dans les réglages du composant,
		 * élément « html » compris : elles doivent passer le même contrôle que
		 * le balisage écrit en clair, sinon l'instance devient la porte dérobée
		 * que la vérification de `settings.html` vient de fermer.
		 */
		if ( isset( $node['properties'] ) && is_array( $node['properties'] ) ) {
			$verdict = self::check_free_text( $node['properties'], $id );

			if ( $verdict instanceof \WP_Error ) {
				return $verdict;
			}
		}

		/*
		 * Un bouton sans libellé affiche « Je suis un bouton » dans le canevas.
		 *
		 * C'est un texte de remplissage de Bricks, servi dès que `settings.text`
		 * est vide ou absent. Il ne sort **jamais sur le front** : le site est donc
		 * juste, et le builder montre trois mots qui ne sont pas dans la maquette,
		 * par-dessus le voisinage. Mesuré sur un site du parc autour des flèches du
		 * sélecteur d'années, dont le chevron est un masque CSS et le libellé
		 * volontairement vide.
		 *
		 * Une **espace** suffit à l'écarter, et c'est pour cela qu'on la pose ici
		 * plutôt que dans chaque arbre : un libellé vide est un cas normal — une
		 * commande dont le dessin est en CSS —, et l'oublier une fois suffit à
		 * salir le canevas d'une page entière.
		 *
		 * Elle ne dispense de rien : un bouton dont le seul contenu est une espace
		 * n'a **aucun nom accessible**. Il lui faut un `aria-label`, que
		 * `check-gabarit.mjs` et l'audit d'accessibilité continuent d'exiger.
		 */
		if ( 'button' === $name ) {
			$libelle = $node['settings']['text'] ?? null;

			if ( ! is_string( $libelle ) || '' === trim( $libelle ) ) {
				$node['settings']['text'] = ' ';
			}
		}

		return $node;
	}

	/**
	 * Contrôle récursivement les valeurs libres d'un élément.
	 *
	 * Employé pour les valeurs de propriétés d'une instance **et** pour les
	 * réglages de n'importe quel élément : ce qui décide du danger est la valeur,
	 * pas le type d'élément qui la porte.
	 *
	 * @param array<string, mixed> $values Valeurs de propriétés ou réglages.
	 * @param string               $id     Identifiant de l'élément, pour les messages.
	 */
	private static function check_free_text( array $values, string $id ): bool|\WP_Error {
		foreach ( $values as $value ) {
			if ( is_array( $value ) ) {
				$verdict = self::check_free_text( $value, $id );

				if ( $verdict instanceof \WP_Error ) {
					return $verdict;
				}

				continue;
			}

			if ( ! is_string( $value ) || ! str_contains( $value, '<' ) ) {
				continue;
			}

			$verdict = self::check_raw_html( $value, $id );

			if ( $verdict instanceof \WP_Error ) {
				return $verdict;
			}
		}

		return true;
	}

	/**
	 * Contrôle les attributs personnalisés d'un élément.
	 *
	 * Deux familles sont refusées, et pour des raisons distinctes :
	 *
	 * - **`on…`** : ce sont des gestionnaires d'événement. Leur valeur est du
	 *   JavaScript, exécuté chez chaque visiteur. Aucun usage légitime ne passe
	 *   par le pont — un comportement s'écrit dans `assets/js/`, versionné.
	 * - **`style`, `srcdoc`, `formaction`, `xlink:href`, `data`** : ils portent
	 *   une URL ou du contenu que le navigateur interprète, donc une porte vers
	 *   `javascript:` ou une ressource distante.
	 *
	 * La valeur est éprouvée par les mêmes motifs que le HTML libre, ce qui
	 * couvre `javascript:` sous toutes ses formes camouflées.
	 *
	 * @param mixed  $attributes Valeur brute du réglage `_attributes`.
	 * @param string $id         Identifiant de l'élément, pour le message.
	 */
	private static function check_attributes( mixed $attributes, string $id ): bool|\WP_Error {
		if ( ! is_array( $attributes ) ) {
			return true;
		}

		$interdits = [ 'style', 'srcdoc', 'formaction', 'xlink:href', 'data', 'ping' ];

		foreach ( $attributes as $attribut ) {
			if ( ! is_array( $attribut ) ) {
				continue;
			}

			$nom = strtolower( trim( (string) ( $attribut['name'] ?? '' ) ) );

			if ( '' === $nom ) {
				continue;
			}

			if ( str_starts_with( $nom, 'on' ) ) {
				return self::error(
					sprintf(
						'Élément « %s » : l’attribut « %s » est un gestionnaire d’événement. Un comportement s’écrit dans assets/js/, pas dans un attribut.',
						$id,
						$nom
					),
					403
				);
			}

			if ( in_array( $nom, $interdits, true ) ) {
				return self::error(
					sprintf(
						'Élément « %s » : l’attribut « %s » n’est pas autorisé — il porte une URL ou du contenu interprété par le navigateur.',
						$id,
						$nom
					),
					403
				);
			}

			$valeur = (string) ( $attribut['value'] ?? '' );

			if ( '' === $valeur ) {
				continue;
			}

			// Même jeu de motifs que le HTML libre : `javascript:` camouflé,
			// ressource distante, balise injectée dans une valeur d'attribut.
			$verdict = self::check_raw_html( $valeur, $id );

			if ( $verdict instanceof \WP_Error ) {
				return $verdict;
			}
		}

		return true;
	}

	/**
	 * Assainit les réglages d'un élément.
	 *
	 * Point de sécurité : l'élément « code » de Bricks peut exécuter du PHP.
	 * Bricks protège l'exécution par une signature HMAC que ce pont ne peut pas
	 * produire — du code injecté ne s'exécuterait donc pas. On refuse malgré
	 * tout explicitement, pour que le refus soit lisible et ne dépende pas
	 * d'un détail d'implémentation de Bricks.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function sanitize_settings( array $settings, string $name, string $id ): array|\WP_Error {
		if ( 'code' === $name && ! empty( $settings['executeCode'] ) ) {
			return self::error(
				sprintf(
					'Élément « %s » : l’exécution de PHP (executeCode) est interdite via le pont REST. Utilisez le builder Bricks pour ce cas.',
					$id
				),
				403
			);
		}

		if ( 'html' === $name && isset( $settings['html'] ) ) {
			$verdict = self::check_raw_html( (string) $settings['html'], $id );

			if ( $verdict instanceof \WP_Error ) {
				return $verdict;
			}
		}

		/*
		 * L'élément « html » n'est pas le seul à rendre son réglage tel quel : un
		 * `heading`, un `text`, un libellé d'onglet, un titre d'accordéon en font
		 * autant. Le contrôle ne portait que sur `name === 'html'` — un <script>
		 * posé dans le titre d'un h1 partait donc en base et s'exécutait sur la
		 * page, alors que le même balisage était refusé deux lignes plus haut.
		 *
		 * On suit donc la valeur, sous-tableaux compris (un lien, une répétition
		 * d'onglets), et seulement quand elle contient un « < » : sans balise, il
		 * n'y a rien à refuser.
		 */
		$verdict = self::check_free_text( $settings, $id );

		if ( $verdict instanceof \WP_Error ) {
			return $verdict;
		}

		/*
		 * Les attributs personnalisés sont contrôlés à part, et il le faut.
		 *
		 * `_attributes` est disponible sur **tout** élément Bricks — bloc,
		 * section, bouton, image — et Bricks les rend tels quels dans la balise.
		 * Le contrôle ci-dessus ne les voyait pas : il ne suit que les valeurs
		 * contenant un « < », et `onclick` = `alert(1)` n'en a aucun.
		 *
		 * Reproduit sur un site en ligne : un appel au pont posant
		 * `_attributes: [{ name: "onclick", value: "alert(1)" }]` était accepté,
		 * écrit en base, et rendu en `<div … onclick="alert(1)">`. Toutes les
		 * gardes contre `<script>`, `on…=` et `javascript:` étaient contournées
		 * par ce seul chemin.
		 */
		$verdict = self::check_attributes( $settings['_attributes'] ?? null, $id );

		if ( $verdict instanceof \WP_Error ) {
			return $verdict;
		}

		// Une signature ne peut venir que du builder : on ne la propage jamais.
		unset( $settings['signature'] );

		if ( isset( $settings['query'] ) && is_array( $settings['query'] ) ) {
			unset( $settings['query']['signature'] );
		}

		return $settings;
	}

	/**
	 * Contrôle un balisage rendu tel quel — élément « html », mais aussi tout
	 * réglage de texte libre.
	 *
	 * Bricks rend plusieurs réglages sans conteneur ni signature — c'est ce qui
	 * permet d'y poser un SVG en ligne, et c'est aussi ce qui en fait une porte
	 * d'entrée. Le pont étant ouvert aux clients, on referme les usages qui n'ont
	 * rien à y faire :
	 *
	 *   • script, PHP, gestionnaires `on…` et `javascript:` — exécution ;
	 *   • iframe, object, embed, ressource distante — la règle zéro dépendance,
	 *     qu'aucune page ne doit pouvoir contourner par ce biais.
	 *
	 * Ce n'est pas un assainisseur général : c'est un refus net, avec un motif
	 * lisible, pour que l'auteur corrige au lieu de découvrir une amputation
	 * silencieuse de son balisage.
	 *
	 * Le contrôle portant désormais sur de la prose, chaque motif doit rester
	 * juste sur une phrase française : un refus injustifié bloque une écriture
	 * légitime, et le motif affiché n'aide alors personne.
	 */
	private static function check_raw_html( string $html, string $id ): bool|\WP_Error {
		$forbidden = [
			/*
			 * « base » complète la liste : son `href` ne charge rien, il réécrit
			 * toutes les URL relatives de la page — et c'est le contrôle des
			 * attributs, plus bas, qui l'attrapait jusqu'ici par accident.
			 */
			'/<\s*(script|iframe|object|embed|form|link|meta|base)\b/i' => 'la balise « %s » n’est pas autorisée dans un élément HTML',
			'/<\?/'                                               => 'le code PHP n’est pas autorisé',
			/*
			 * Un gestionnaire d'événement, et **seulement à l'intérieur d'une
			 * balise**.
			 *
			 * L'ancrage sur `<` n'est pas un raffinement : ce contrôle s'applique
			 * désormais à tout réglage de texte libre, et un motif non ancré
			 * refuserait « ondes = 3 » dans une phrase.
			 *
			 * Mais l'ancrage seul ne suffit pas, et c'est ce qui a été mesuré :
			 * `[^>]*` s'arrête au **premier** `>`, y compris celui contenu dans une
			 * valeur d'attribut. `<img alt=">" onerror ="alert(1)">` passait donc
			 * entre les deux motifs — le premier parce qu'il exige l'absence
			 * d'espace avant le `=`, le second parce qu'il ne franchissait pas le
			 * `>` des guillemets. Les valeurs entre guillemets sont vidées avant le
			 * contrôle (voir `sans_valeurs()`), ce qui rend la fin de balise
			 * lisible et ce motif suffisant à lui seul.
			 */
			/*
			 * Le séparateur toléré est admis **entre chaque lettre**, pas
			 * seulement avant le deux-points : `java\tscript:` s'exécute, et le
			 * motif d'avant ne le voyait pas. Ce sont exactement les caractères
			 * que l'analyseur d'URL retire — tabulation, retours, octet nul.
			 *
			 * L'espace ordinaire en est exclue, et il le faut : « JavaScript :
			 * les bases » est une phrase, et un schéma d'URL n'en contient pas.
			 */
			'/j[\t\r\n\x00]*a[\t\r\n\x00]*v[\t\r\n\x00]*a[\t\r\n\x00]*s[\t\r\n\x00]*c[\t\r\n\x00]*r[\t\r\n\x00]*i[\t\r\n\x00]*p[\t\r\n\x00]*t[\t\r\n\x00]*:/i' => 'les URL « javascript: » ne sont pas autorisées',
			/*
			 * Attributs qui **chargent** une ressource. `href` n'y figure plus :
			 * sur un `<a>` il navigue sans rien charger — refuser un lien externe
			 * au nom du zéro dépendance bloquait la moindre mention d'un site
			 * partenaire — et les balises qui chargent par `href`, `link` et
			 * `base`, sont refusées plus haut. Le `href` de `<use>` et `<image>`,
			 * lui, charge bel et bien : il a sa propre ligne.
			 *
			 * Deux motifs par famille : l'URL absolue, où qu'elle soit dans la
			 * valeur — un `srcset` mêle un fichier local et un CDN —, puis l'URL
			 * sans protocole, qui n'a de sens qu'en tête de valeur.
			 */
			'/\b(src|srcset|poster|data-src|data-srcset|xlink:href|background)\s*=\s*["\']?[^"\'>]*https?:\/\//i' => 'une ressource distante rompt la règle zéro dépendance',
			'/\b(src|srcset|poster|data-src|data-srcset|xlink:href|background)\s*=\s*["\']?\s*\/\//i'             => 'une ressource distante rompt la règle zéro dépendance',
			'/<\s*(use|image)\b[^>]*\bhref\s*=\s*["\']?[^"\'>]*(https?:)?\/\//i'                                 => 'une ressource distante rompt la règle zéro dépendance',
			/*
			 * Ni `src` ni `href` : une `url()` dans un attribut `style` et un
			 * `@import` dans une balise `<style>` chargeaient une image ou une
			 * feuille distante sans qu'aucun attribut de ressource apparaisse.
			 */
			'/url\(\s*["\']?\s*(https?:)?\/\//i'                   => 'une ressource distante rompt la règle zéro dépendance',
			'/@import\s+["\']?\s*(https?:)?\/\//i'                 => 'une ressource distante rompt la règle zéro dépendance',
		];

		/*
		 * Le gestionnaire d'événement se cherche sur la copie neutralisée, les
		 * autres motifs sur le texte d'origine — et cette séparation n'est pas un
		 * détail de forme.
		 *
		 * Le premier a besoin de savoir **où finit une balise**, ce que le `>` d'une
		 * valeur d'attribut fausse. Les seconds ont besoin de **lire les valeurs**,
		 * puisqu'une ressource distante est précisément une valeur : les chercher
		 * sur la copie vidée reviendrait à ne plus voir aucun `src="https://…"`.
		 */
		/*
		 * Le séparateur d'attributs inclut « / », pas seulement l'espace.
		 *
		 * `<a href="x"/onclick="alert(1)">` est du HTML valide : la barre oblique
		 * sépare deux attributs, et tous les navigateurs l'exécutent. Le motif
		 * n'exigeait qu'un caractère d'espacement — ce balisage traversait donc
		 * le filtre, partait en base, et s'exécutait chez chaque visiteur.
		 * Reproduit sur les quatre variantes : espace, tabulation, saut de ligne
		 * et barre oblique, avec et sans guillemets.
		 */
		if ( preg_match( '/<[^>]*[\s\/]on[a-z]+\s*=/i', self::sans_valeurs( $html ) ) ) {
			return self::error(
				sprintf(
					'Élément « %s » : les gestionnaires d’événement en attribut (on…=) ne sont pas autorisés.',
					$id
				),
				403
			);
		}

		/*
		 * Les motifs cherchent sur une forme **décodée**.
		 *
		 * Un navigateur décode les entités HTML d'un attribut avant de suivre
		 * l'URL : `java&#115;cript:alert(1)` devient `javascript:alert(1)` et
		 * s'exécute. Le motif, lui, ne voyait que la forme encodée et laissait
		 * passer — reproduit sur trois variantes.
		 *
		 * La recherche porte sur les deux formes : la décodée attrape ce genre
		 * de camouflage, la brute reste nécessaire pour tout ce que le décodage
		 * transformerait — un `&lt;script&gt;` écrit volontairement dans du
		 * texte ne doit pas devenir une balise aux yeux du contrôle.
		 */
		$decode = html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

		foreach ( $forbidden as $pattern => $reason ) {
			if ( preg_match( $pattern, $html, $matches ) || preg_match( $pattern, $decode, $matches ) ) {
				return self::error(
					sprintf(
						'Élément « %s » : %s.',
						$id,
						sprintf( $reason, strtolower( $matches[1] ?? '' ) )
					),
					403
				);
			}
		}

		return true;
	}

	/**
	 * Vide le contenu des valeurs entre guillemets, en gardant la longueur nulle.
	 *
	 * Lire du balisage à l'expression régulière suppose de savoir où finit une
	 * balise. `[^>]*` le suppose aussi — et se trompe dès qu'un `>` figure dans
	 * une valeur d'attribut, ce qu'aucune règle n'interdit :
	 *
	 *     <img alt=">" onerror ="alert(1)">
	 *
	 * Le gestionnaire d'événement se trouve alors **après** ce que le motif prend
	 * pour la fin de la balise, et il passe. Vider les valeurs d'abord rend la
	 * structure lisible sans avoir à écrire un analyseur :
	 *
	 *     <img alt="" onerror ="">
	 *
	 * Ce qui compte pour **ce** contrôle — les noms d'attributs, les noms de
	 * balises, les délimiteurs — est intégralement conservé. Ce qui disparaît, ce
	 * sont les valeurs : cette copie ne sert donc qu'au motif qui cherche une
	 * structure de balise, jamais à ceux qui cherchent dans une valeur.
	 */
	private static function sans_valeurs( string $html ): string {
		return (string) preg_replace( '/"[^"]*"|\'[^\']*\'/', '""', $html );
	}

	/**
	 * Vérifie la cohérence de l'arbre : références croisées et absence de cycle.
	 *
	 * @param array<int, array<string, mixed>> $elements Nœuds assainis.
	 * @param array<string, int>               $ids      Index id => position.
	 */
	private static function check_tree_integrity( array $elements, array $ids ): bool|\WP_Error {
		$has_root = false;

		foreach ( $elements as $node ) {
			if ( 0 === $node['parent'] ) {
				$has_root = true;
			} elseif ( ! isset( $ids[ $node['parent'] ] ) ) {
				return self::error(
					sprintf( 'Élément « %s » : le parent « %s » n’existe pas dans la structure.', $node['id'], $node['parent'] )
				);
			}

			foreach ( $node['children'] as $child ) {
				if ( ! isset( $ids[ $child ] ) ) {
					return self::error(
						sprintf( 'Élément « %s » : l’enfant « %s » n’existe pas dans la structure.', $node['id'], $child )
					);
				}

				if ( $elements[ $ids[ $child ] ]['parent'] !== $node['id'] ) {
					return self::error(
						sprintf(
							'Incohérence : « %s » est listé comme enfant de « %s », mais son parent déclaré est « %s ».',
							$child,
							$node['id'],
							(string) $elements[ $ids[ $child ] ]['parent']
						)
					);
				}
			}
		}

		if ( ! $has_root ) {
			return self::error( 'La structure ne contient aucun élément racine (parent = 0).' );
		}

		return self::check_cycles( $elements, $ids );
	}

	/**
	 * Détecte les cycles de parenté (A parent de B parent de A).
	 *
	 * @param array<int, array<string, mixed>> $elements Nœuds assainis.
	 * @param array<string, int>               $ids      Index id => position.
	 */
	private static function check_cycles( array $elements, array $ids ): bool|\WP_Error {
		$depth_limit = count( $elements ) + 1;

		foreach ( $elements as $node ) {
			$current = $node['parent'];
			$depth   = 0;

			while ( 0 !== $current ) {
				if ( ++$depth > $depth_limit ) {
					return self::error(
						sprintf( 'Cycle de parenté détecté autour de l’élément « %s ».', $node['id'] )
					);
				}

				$current = $elements[ $ids[ $current ] ]['parent'];
			}
		}

		return true;
	}

	/**
	 * Types de propriété reconnus par le panneau de composant de Bricks.
	 *
	 * Le type détermine le contrôle affiché à l'éditeur du site — un champ de
	 * texte, un sélecteur de média, une liste d'options. Un type inconnu produit
	 * un contrôle vide : la propriété existe, mais personne ne peut la remplir.
	 */
	private const PROPERTY_TYPES = [
		'text',
		'textarea',
		'editor',
		'number',
		'link',
		'image',
		'image-gallery',
		'video',
		'select',
		'toggle',
		'color',
		'icon',
		'svg',
		'class',
		'query',
		'datePicker',
	];

	/**
	 * Valide un composant Bricks.
	 *
	 * Un composant est un sous-arbre d'éléments plus une liste de propriétés
	 * reliées à des réglages précis de ce sous-arbre. Trois invariants tiennent
	 * l'ensemble, et leur rupture ne se voit pas dans le builder :
	 *
	 *   • l'identifiant du composant est celui de son élément racine — c'est par
	 *     là que Bricks retrouve la racine (`get_component_element_by_id`) ;
	 *   • le composant n'a qu'une racine, sans quoi les éléments suivants ne sont
	 *     jamais rendus ;
	 *   • chaque connexion de propriété désigne un élément qui existe, faute de
	 *     quoi la propriété s'affiche à l'éditeur sans rien piloter.
	 *
	 * @param mixed $component Composant brut reçu de l'API.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function component( mixed $component ): array|\WP_Error {
		if ( ! is_array( $component ) ) {
			return self::error( 'Le composant doit être un objet.' );
		}

		$label = $component['label'] ?? '';

		if ( ! is_string( $label ) || '' === trim( $label ) ) {
			return self::error( 'Composant : « label » manquant. C’est le nom affiché dans le builder.' );
		}

		$elements = self::elements( $component['elements'] ?? [] );

		if ( $elements instanceof \WP_Error ) {
			return $elements;
		}

		if ( ! $elements ) {
			return self::error( sprintf( 'Composant « %s » : aucun élément.', $label ) );
		}

		$roots = array_values(
			array_filter(
				$elements,
				static fn ( array $element ): bool => 0 === $element['parent']
			)
		);

		if ( count( $roots ) > 1 ) {
			return self::error(
				sprintf(
					'Composant « %s » : %d éléments racine. Un composant n’en a qu’un — enveloppez le tout dans une section ou un bloc.',
					$label,
					count( $roots )
				)
			);
		}

		$id = $component['id'] ?? '';

		if ( ! is_string( $id ) || ! preg_match( self::ID_PATTERN, $id ) ) {
			$id = $roots[0]['id'];
		}

		if ( $id !== $roots[0]['id'] ) {
			return self::error(
				sprintf(
					'Composant « %s » : l’identifiant du composant (« %s ») doit être celui de son élément racine (« %s »).',
					$label,
					$id,
					$roots[0]['id']
				)
			);
		}

		$properties = self::component_properties( $component['properties'] ?? [], $elements, $label );

		if ( $properties instanceof \WP_Error ) {
			return $properties;
		}

		$entry = [
			'id'         => $id,
			'label'      => sanitize_text_field( $label ),
			'category'   => is_string( $component['category'] ?? null ) && '' !== $component['category']
				? sanitize_text_field( $component['category'] )
				: 'components',
			'elements'   => $elements,
			'properties' => $properties,
		];

		if ( isset( $component['desc'] ) && is_string( $component['desc'] ) ) {
			$entry['desc'] = sanitize_text_field( $component['desc'] );
		}

		// Métadonnées du builder : conservées si elles existent déjà, sinon posées.
		$entry['_created']  = is_numeric( $component['_created'] ?? null ) ? (int) $component['_created'] : time();
		$entry['_user_id']  = is_numeric( $component['_user_id'] ?? null ) ? (int) $component['_user_id'] : get_current_user_id();
		$entry['_version']  = is_string( $component['_version'] ?? null ) && '' !== $component['_version']
			? $component['_version']
			: ( defined( 'BRICKS_VERSION' ) ? BRICKS_VERSION : '' );

		return $entry;
	}

	/**
	 * Valide les propriétés d'un composant et leurs connexions.
	 *
	 * @param mixed                            $properties Liste brute.
	 * @param array<int, array<string, mixed>> $elements   Éléments du composant, déjà assainis.
	 * @param string                           $label      Nom du composant, pour les messages.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	private static function component_properties( mixed $properties, array $elements, string $label ): array|\WP_Error {
		if ( ! is_array( $properties ) ) {
			return self::error( sprintf( 'Composant « %s » : « properties » doit être un tableau.', $label ) );
		}

		$known_ids = [];

		foreach ( $elements as $element ) {
			$known_ids[ $element['id'] ] = true;
		}

		$clean = [];
		$seen  = [];

		foreach ( array_values( $properties ) as $index => $property ) {
			if ( ! is_array( $property ) ) {
				return self::error( sprintf( 'Composant « %s », propriété #%d : objet attendu.', $label, $index ) );
			}

			$id = $property['id'] ?? '';

			if ( ! is_string( $id ) || ! preg_match( self::ID_PATTERN, $id ) ) {
				return self::error(
					sprintf(
						'Composant « %s », propriété #%d : « id » manquant ou invalide (3 à 32 caractères parmi A-Z, a-z, 0-9, _ et -).',
						$label,
						$index
					)
				);
			}

			if ( isset( $seen[ $id ] ) ) {
				return self::error( sprintf( 'Composant « %s » : propriété dupliquée « %s ».', $label, $id ) );
			}

			$seen[ $id ] = true;

			$type = $property['type'] ?? 'text';

			if ( ! is_string( $type ) || ! in_array( $type, self::PROPERTY_TYPES, true ) ) {
				return self::error(
					sprintf(
						'Composant « %s », propriété « %s » : type inconnu (« %s »). Valeurs possibles : %s.',
						$label,
						$id,
						is_string( $type ) ? $type : '',
						implode( ', ', self::PROPERTY_TYPES )
					)
				);
			}

			$connections = $property['connections'] ?? [];

			if ( ! is_array( $connections ) ) {
				return self::error(
					sprintf( 'Composant « %s », propriété « %s » : « connections » doit être un objet.', $label, $id )
				);
			}

			$clean_connections = [];

			foreach ( $connections as $element_id => $setting_keys ) {
				$element_id = (string) $element_id;

				if ( ! isset( $known_ids[ $element_id ] ) ) {
					return self::error(
						sprintf(
							'Composant « %s », propriété « %s » : l’élément « %s » n’existe pas dans le composant.',
							$label,
							$id,
							$element_id
						)
					);
				}

				if ( ! is_array( $setting_keys ) || ! $setting_keys ) {
					return self::error(
						sprintf(
							'Composant « %s », propriété « %s » : la connexion vers « %s » doit lister au moins un réglage.',
							$label,
							$id,
							$element_id
						)
					);
				}

				$keys = [];

				foreach ( $setting_keys as $setting_key ) {
					if ( ! is_string( $setting_key ) || '' === $setting_key ) {
						return self::error(
							sprintf( 'Composant « %s », propriété « %s » : nom de réglage invalide.', $label, $id )
						);
					}

					$keys[] = $setting_key;
				}

				$clean_connections[ $element_id ] = array_values( array_unique( $keys ) );
			}

			if ( ! $clean_connections ) {
				return self::error(
					sprintf(
						'Composant « %s », propriété « %s » : aucune connexion. Une propriété qui ne pilote aucun réglage '
						. 'apparaît à l’éditeur du site sans rien changer.',
						$label,
						$id
					)
				);
			}

			$entry = [
				'id'          => $id,
				'label'       => is_string( $property['label'] ?? null ) && '' !== $property['label']
					? sanitize_text_field( $property['label'] )
					: $id,
				'type'        => $type,
				'connections' => $clean_connections,
			];

			foreach ( [ 'default', 'options' ] as $optional ) {
				if ( isset( $property[ $optional ] ) ) {
					$entry[ $optional ] = $property[ $optional ];
				}
			}

			foreach ( [ 'group', 'desc' ] as $optional ) {
				if ( isset( $property[ $optional ] ) && is_string( $property[ $optional ] ) ) {
					$entry[ $optional ] = sanitize_text_field( $property[ $optional ] );
				}
			}

			if ( ! empty( $property['replace'] ) ) {
				$entry['replace'] = true;
			}

			$clean[] = $entry;
		}

		return $clean;
	}

	/**
	 * Valide une liste de classes globales Bricks.
	 *
	 * @param mixed $classes    Liste brute.
	 * @param bool  $strict_bem Refuse les noms non conformes BEM.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	/**
	 * Borne toute dimension fixe d'une classe : min et max, au même point de rupture.
	 *
	 * **Le défaut.** Une classe qui pose `width: 40px` sort juste sur le front et
	 * **redimensionnée dans le canevas** du builder : mesuré sur un site du parc,
	 * des pictogrammes de 40 px y faisaient 90, et des coches de 25 × 20 à
	 * l'identique, sur une page entière. La valeur fautive n'est écrite nulle part
	 * — elle est calculée : le canevas place ses éléments dans des conteneurs flex
	 * qu'il étire lui-même, et `width` seule ne contraint pas un élément flex. Ce
	 * sont `min-width` et `max-width` qui l'en empêchent.
	 *
	 * **Pourquoi ici.** Parce que ce n'est pas propre à un site : toute classe à
	 * dimension fixe écrite par l'API est concernée, sur chaque projet, et le
	 * défaut ne se voit sur aucune capture du front. Deux sites du parc étaient
	 * propres uniquement parce qu'ils avaient été corrigés à la main, un par un.
	 * Une règle qu'on doit se rappeler d'appliquer se reproduira ; posée par le
	 * pont, elle ne peut plus être oubliée.
	 *
	 * **La seule nuance, et elle n'est pas facultative : jamais une dimension
	 * fluide.** `100%`, `auto`, `max-content`, `fit-content` expriment une taille
	 * qui suit le parent ; la figer casse le conteneur **sur le front**, là où le
	 * visiteur le voit. Sur le site mesuré, 29 dimensions sur 61 étaient à borner
	 * et 32 devaient rester intactes. Seule une valeur numérique suivie de `px`,
	 * `rem` ou `em` est donc bornée.
	 *
	 * Une borne déjà posée n'est jamais écrasée : l'auteur qui a choisi un minimum
	 * différent de sa largeur garde son intention.
	 *
	 * @param array<string, mixed> $settings Réglages de la classe.
	 * @return array<string, mixed>
	 */
	/**
	 * Une dimension se borne-t-elle ?
	 *
	 * Deux formes, et deux seulement :
	 *
	 * - une longueur littérale — `40px`, `2.5rem`, `1.2em` ;
	 * - **un jeton** — `var(--taille-controle)`.
	 *
	 * **Le jeton a été ajouté le 07/08/2026.** Une classe qui déclare sa taille
	 * par un jeton plutôt qu'en pixels décrit exactement la même chose, et le
	 * motif limité aux longueurs littérales la laissait passer sans bornes. Le
	 * défaut se voit alors dans le canevas seulement, comme tous ceux de cette
	 * famille.
	 *
	 * **La borne reprend l'EXPRESSION, jamais une valeur résolue.** Un jeton peut
	 * être redéfini selon la portée — la même variable valant deux tailles
	 * différentes dans deux blocs. Résolue à l'écriture, la borne serait figée sur
	 * l'une des deux ; écrite telle quelle, elle se résout dans la même cascade
	 * que l'élément, donc toujours juste.
	 *
	 * **Ce qui reste exclu, et pourquoi.** `%` et les unités de fenêtre expriment
	 * une taille qui suit son parent : un `min-` les empêcherait de rétrécir, et
	 * le conteneur déborderait **sur le front**, là où le visiteur le voit.
	 * `auto` et la famille `*-content` n'ont pas le même sens sur une borne que
	 * sur une dimension — `max-width: auto` n'est même pas valide.
	 *
	 * @param string $valeur Valeur déclarée.
	 */
	private static function dimension_bornable( string $valeur ): bool {
		$valeur = trim( $valeur );

		if ( preg_match( '/^-?\d+(?:\.\d+)?(?:px|rem|em)$/', $valeur ) ) {
			return true;
		}

		// Un jeton seul, sans repli ni calcul autour : `var(--x)` et rien d'autre.
		// `var(--x, 100%)` porterait un repli fluide qu'on ne veut pas figer, et
		// `calc(var(--x) + 2px)` n'est pas une taille unique déclarée.
		return (bool) preg_match( '/^var\(\s*--[A-Za-z0-9_-]+\s*\)$/', $valeur );
	}

	/**
	 * Une borne écrite seule à un palier réclame sa jumelle **au même palier**.
	 *
	 * **Le défaut, mesuré le 07/08/2026.** Un bouton carré sortait en 34 × 44 sous
	 * le palier tablette. Sa classe portait un `_heightMin` à ce palier, posé par
	 * une passe sur les cibles tactiles, pendant que le `_heightMax` restait à sa
	 * valeur de base, plus petite. **En CSS le minimum l'emporte sur le maximum** :
	 * la hauteur suit le minimum, la largeur ne bouge pas, et rien ne le signale —
	 * ni erreur, ni avertissement, ni écart de hauteur de page.
	 *
	 * Borner est la règle ; elle ne disait pas que **les bornes doivent s'accorder
	 * entre elles**. Une borne posée seule crée exactement le défaut qu'elle
	 * prétend empêcher.
	 *
	 * **Ce que cette passe fait.** Là où un `Min` ou un `Max` est écrit à un palier
	 * sans sa jumelle, elle écrit la jumelle **avec la même valeur**. Le résultat
	 * est une taille exacte à ce palier — et non un intervalle dont une borne
	 * viendrait d'ailleurs.
	 *
	 * Elle ne touche jamais une paire déjà complète : deux bornes écrites côte à
	 * côte expriment une intention, et cette intention se respecte même si elle
	 * décrit un intervalle.
	 *
	 * @param array<string, mixed> $settings Réglages.
	 * @return array<string, mixed>
	 */
	private static function accorder_bornes( array $settings ): array {
		$paires = [
			'_widthMin'  => '_widthMax',
			'_widthMax'  => '_widthMin',
			'_heightMin' => '_heightMax',
			'_heightMax' => '_heightMin',
		];

		foreach ( array_keys( $settings ) as $key ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			$parts   = explode( ':', $key, 2 );
			$base    = $parts[0];
			$rupture = $parts[1] ?? '';

			// Le palier de base n'hérite de rien : une borne seule y est un choix.
			if ( '' === $rupture || ! isset( $paires[ $base ] ) ) {
				continue;
			}

			$jumelle = $paires[ $base ] . ':' . $rupture;

			if ( array_key_exists( $jumelle, $settings ) ) {
				continue;
			}

			$valeur = $settings[ $key ];

			if ( is_string( $valeur ) && '' !== trim( $valeur ) ) {
				$settings[ $jumelle ] = $valeur;
			}
		}

		return $settings;
	}

	private static function borner_dimensions( array $settings ): array {
		$bornes = [
			'_width'  => [ '_widthMin', '_widthMax' ],
			'_height' => [ '_heightMin', '_heightMax' ],
		];

		foreach ( $settings as $key => $value ) {
			if ( ! is_string( $key ) ) {
				continue;
			}

			$parts   = explode( ':', $key, 2 );
			$base    = $parts[0];
			$rupture = $parts[1] ?? '';

			if ( ! isset( $bornes[ $base ] ) || ! is_string( $value ) ) {
				continue;
			}

			if ( ! self::dimension_bornable( $value ) ) {
				continue;
			}

			foreach ( $bornes[ $base ] as $borne ) {
				$cle = '' === $rupture ? $borne : $borne . ':' . $rupture;

				if ( ! array_key_exists( $cle, $settings ) ) {
					$settings[ $cle ] = $value;
				}
			}
		}

		return self::accorder_bornes( $settings );
	}

	public static function global_classes( mixed $classes, bool $strict_bem = true ): array|\WP_Error {
		if ( ! is_array( $classes ) ) {
			return self::error( 'La liste des classes doit être un tableau.' );
		}

		$clean = [];
		$names = [];

		foreach ( array_values( $classes ) as $index => $class ) {
			if ( ! is_array( $class ) ) {
				return self::error( sprintf( 'Classe #%d : objet attendu.', $index ) );
			}

			$name = $class['name'] ?? '';

			if ( ! is_string( $name ) || '' === $name ) {
				return self::error( sprintf( 'Classe #%d : « name » manquant.', $index ) );
			}

			if ( $strict_bem && ! preg_match( self::BEM_PATTERN, $name ) ) {
				return self::error(
					sprintf(
						'Classe « %s » : nom non conforme BEM. Attendu bloc, bloc__element, bloc--modificateur ou bloc__element--modificateur, en minuscules.',
						$name
					)
				);
			}

			if ( isset( $names[ $name ] ) ) {
				return self::error( sprintf( 'Classe dupliquée : « %s ».', $name ) );
			}

			$names[ $name ] = true;

			$id = $class['id'] ?? '';

			if ( ! is_string( $id ) || ! preg_match( self::ID_PATTERN, $id ) ) {
				$id = Bricks_Adapter::generate_id();
			}

			$entry = [
				'id'       => $id,
				'name'     => $name,
				'settings' => is_array( $class['settings'] ?? null ) ? $class['settings'] : [],
			];

			if ( isset( $class['category'] ) && is_string( $class['category'] ) ) {
				$entry['category'] = $class['category'];
			}

			if ( isset( $class['modified'] ) && is_numeric( $class['modified'] ) ) {
				$entry['modified'] = (int) $class['modified'];
			}

			if ( isset( $class['selectors'] ) && is_array( $class['selectors'] ) ) {
				$entry['selectors'] = $class['selectors'];
			}

			/*
			 * « %root% » n'est résolu que par le builder. Écrit par l'API, il reste
			 * littéral dans la feuille : le navigateur écarte la règle, et avec elle
			 * ce qui suit jusqu'à l'accolade suivante. La route du CSS global le
			 * refusait déjà ; le CSS personnalisé d'une classe et celui de ses
			 * sous-sélecteurs, qui sortent dans la même feuille, le laissaient
			 * passer.
			 */
			$verdict = self::check_root_token( [ $entry['settings'], $entry['selectors'] ?? [] ], $name );

			if ( $verdict instanceof \WP_Error ) {
				return $verdict;
			}

			$entry['settings'] = self::borner_dimensions( $entry['settings'] );

			$clean[] = $entry;
		}

		return $clean;
	}

	/**
	 * Refuse le jeton « %root% » partout dans les réglages d'une classe.
	 *
	 * La recherche est récursive : le CSS personnalisé se range sous `_cssCustom`,
	 * mais aussi sous une clé de point de rupture, et chaque sous-sélecteur porte
	 * ses propres réglages.
	 *
	 * @param mixed  $value Réglages, sous-sélecteurs, ou n'importe quelle valeur.
	 * @param string $name  Nom de la classe, pour le message.
	 */
	private static function check_root_token( mixed $value, string $name ): bool|\WP_Error {
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				$verdict = self::check_root_token( $item, $name );

				if ( $verdict instanceof \WP_Error ) {
					return $verdict;
				}
			}

			return true;
		}

		if ( is_string( $value ) && str_contains( $value, '%root%' ) ) {
			return self::error(
				sprintf(
					'Classe « %1$s » : « %%root%% » n’est résolu que par le builder — écrivez le sélecteur réel (« .%1$s »).',
					$name
				)
			);
		}

		return true;
	}

	/**
	 * Valide une liste de variables globales Bricks.
	 *
	 * @param mixed $variables Liste brute.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public static function variables( mixed $variables ): array|\WP_Error {
		if ( ! is_array( $variables ) ) {
			return self::error( 'La liste des variables doit être un tableau.' );
		}

		$clean = [];
		$names = [];

		foreach ( array_values( $variables ) as $index => $variable ) {
			if ( ! is_array( $variable ) ) {
				return self::error( sprintf( 'Variable #%d : objet attendu.', $index ) );
			}

			$name = $variable['name'] ?? '';

			if ( ! is_string( $name ) || ! preg_match( self::VARIABLE_PATTERN, $name ) ) {
				return self::error(
					sprintf( 'Variable #%d : nom invalide (« %s »). Attendu en kebab-case, sans les deux tirets initiaux.', $index, is_string( $name ) ? $name : '' )
				);
			}

			if ( isset( $names[ $name ] ) ) {
				return self::error( sprintf( 'Variable dupliquée : « %s ».', $name ) );
			}

			$names[ $name ] = true;

			$value = $variable['value'] ?? '';

			if ( ! is_string( $value ) && ! is_numeric( $value ) ) {
				return self::error( sprintf( 'Variable « %s » : la valeur doit être une chaîne.', $name ) );
			}

			$id = $variable['id'] ?? '';

			if ( ! is_string( $id ) || ! preg_match( self::ID_PATTERN, $id ) ) {
				$id = Bricks_Adapter::generate_id();
			}

			$entry = [
				'id'    => $id,
				'name'  => $name,
				'value' => (string) $value,
			];

			if ( isset( $variable['category'] ) && is_string( $variable['category'] ) ) {
				$entry['category'] = $variable['category'];
			}

			$clean[] = $entry;
		}

		return $clean;
	}

	/**
	 * Valide une liste de catégories (classes ou variables).
	 *
	 * @param mixed $categories Liste brute.
	 *
	 * @return array<int, array<string, string>>|\WP_Error
	 */
	public static function categories( mixed $categories ): array|\WP_Error {
		if ( ! is_array( $categories ) ) {
			return self::error( 'La liste des catégories doit être un tableau.' );
		}

		$clean = [];

		foreach ( array_values( $categories ) as $index => $category ) {
			if ( ! is_array( $category ) ) {
				return self::error( sprintf( 'Catégorie #%d : objet attendu.', $index ) );
			}

			$name = $category['name'] ?? '';

			if ( ! is_string( $name ) || '' === trim( $name ) ) {
				return self::error( sprintf( 'Catégorie #%d : « name » manquant.', $index ) );
			}

			$id = $category['id'] ?? '';

			if ( ! is_string( $id ) || ! preg_match( self::ID_PATTERN, $id ) ) {
				$id = Bricks_Adapter::generate_id();
			}

			$clean[] = [
				'id'   => $id,
				'name' => sanitize_text_field( $name ),
			];
		}

		return $clean;
	}

	private static function error( string $message, int $status = 400 ): \WP_Error {
		return new \WP_Error( 'anode_bridge_invalid', $message, [ 'status' => $status ] );
	}
}
