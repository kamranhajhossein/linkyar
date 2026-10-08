<?php
/**
 * Persian-aware text folding used for phrase matching. Mirrors foldText() in assets/editor.js exactly.
 *
 * @package Linkyar
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'LINKYAR_TESTING' ) ) {
	exit;
}

/**
 * Folds text so that «كتاب‌هاي», «کتاب های» and «کِتاب‌ها ی» compare equal.
 */
class Linkyar_Text {

	/**
	 * Character substitutions applied before classification.
	 *
	 * @var array<string,string>
	 */
	const MAP = array(
		'ي' => 'ی',
		'ى' => 'ی',
		'ئ' => 'ی',
		'ك' => 'ک',
		'ۀ' => 'ه',
		'ة' => 'ه',
		'أ' => 'ا',
		'إ' => 'ا',
		'آ' => 'ا',
		'ٱ' => 'ا',
		'ؤ' => 'و',
		'۰' => '0',
		'۱' => '1',
		'۲' => '2',
		'۳' => '3',
		'۴' => '4',
		'۵' => '5',
		'۶' => '6',
		'۷' => '7',
		'۸' => '8',
		'۹' => '9',
		'٠' => '0',
		'١' => '1',
		'٢' => '2',
		'٣' => '3',
		'٤' => '4',
		'٥' => '5',
		'٦' => '6',
		'٧' => '7',
		'٨' => '8',
		'٩' => '9',
	);

	/**
	 * Fold one character: '' = drop, ' ' = word separator, otherwise the folded character.
	 *
	 * @param string $c Single UTF-8 character.
	 * @return string
	 */
	public static function fold_char( $c ) {
		if ( isset( self::MAP[ $c ] ) ) {
			return self::MAP[ $c ];
		}
		if ( "\u{0640}" === $c || preg_match( '/^\p{M}$/u', $c ) ) {
			return ''; // Kashida, harakat, hamza-above and other combining marks.
		}
		if ( preg_match( '/^[\p{L}\p{N}]$/u', $c ) ) {
			return mb_strtolower( $c, 'UTF-8' );
		}
		return ' '; // Spaces, ZWNJ, punctuation, symbols.
	}

	/**
	 * Fold a string to single-spaced lowercase words with no leading/trailing space.
	 *
	 * @param string $text Plain text.
	 * @return string
	 */
	public static function fold( $text ) {
		$out  = '';
		$last = ' ';
		$chars = preg_split( '//u', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $chars ?: array() as $c ) {
			$f = self::fold_char( $c );
			if ( '' === $f || ( ' ' === $f && ' ' === $last ) ) {
				continue;
			}
			$out .= $f;
			$last = $f;
		}
		return rtrim( $out, ' ' );
	}

	/**
	 * HTML → plain text (block comments and tags removed, entities decoded, block boundaries become spaces).
	 *
	 * @param string $html Markup.
	 * @return string
	 */
	public static function plain( $html ) {
		$html = preg_replace( '~<(script|style)\b[^>]*>.*?</\1>~is', ' ', (string) $html );
		$html = preg_replace( '~<!--.*?-->|<[^>]+>~s', ' ', $html );
		return html_entity_decode( $html, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Number of words in a folded phrase.
	 *
	 * @param string $folded Folded phrase.
	 * @return int
	 */
	public static function words( $folded ) {
		return '' === $folded ? 0 : substr_count( $folded, ' ' ) + 1;
	}
}
