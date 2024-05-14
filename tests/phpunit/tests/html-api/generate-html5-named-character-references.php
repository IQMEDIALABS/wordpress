<?php

require_once __DIR__ . '/../../../../src/wp-includes/class-wp-token-map.php';

/**
 * Stores a mapping from HTML5 named character reference to its transformation metadata.
 *
 * Example:
 *
 *     $entities['&copy;'] === array(
 *         'codepoints' => array( 0xA9 ),
 *         'characters' => '©',
 *     );
 *
 * @see https://html.spec.whatwg.org/entities.json
 *
 * @var array.
 */
$entities = json_decode(
	file_get_contents( __DIR__ . '/../../data/html5-entities.json' ),
	JSON_OBJECT_AS_ARRAY
);

/**
 * Direct mapping from character reference name to UTF-8 string.
 *
 * Example:
 *
 *     $character_references['&copy;'] === '©';
 *
 * @var array.
 */
$character_references = array();
foreach ( $entities as $reference => $metadata ) {
	$reference_without_ampersand_prefix                          = substr( $reference, 1 );
	$character_references[ $reference_without_ampersand_prefix ] = $metadata['characters'];
}

$html5_map       = WP_Token_Map::from_array( $character_references );
$module_contents = <<<EOF
<?php

/**
 * Auto-generated class for looking up HTML named character references.
 *
 * To regenerate, run the generation script directly.
 *
 * Example:
 *
 *     php tests/phpunit/tests/html-api/generate-html5-named-character-references.php
 *
 * @package WordPress
 * @since 6.6.0
 */

// phpcs:disable

global \$html5_named_character_references;

/**
 * Set of named character references in the HTML5 specification.
 *
 * This list will never change, according to the spec. Each named
 * character reference is case-sensitive and the presence or absence
 * of the semicolon is significant. Without the semicolon, the rules
 * for an ambiguous ampersand govern whether the following text is
 * to be interpreted as a character reference or not.
 *
 * @link https://html.spec.whatwg.org/entities.json.
 */
\$html5_named_character_references = {$html5_map->precomputed_php_source_table()};

EOF;

file_put_contents(
	__DIR__ . '/../../../../src/wp-includes/html-api/html5-named-character-references.php',
	$module_contents
);
