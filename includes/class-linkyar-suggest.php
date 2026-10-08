<?php
/**
 * Finds internal-link opportunities in a piece of text.
 *
 * @package Linkyar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Suggestion engine.
 */
class Linkyar_Suggest {

	const SOURCE_WEIGHT = array(
		'custom' => 30,
		'focus'  => 25,
		'title'  => 10,
	);

	/**
	 * Find link opportunities.
	 *
	 * @param int                                $post_id Current post (never suggested to itself).
	 * @param array<int,array{id:string,text:string}> $blocks  Plain-text chunks; offsets refer to fold(text).
	 * @param int[]                              $linked  Post IDs already linked from this content.
	 * @param int                                $limit   Max suggestions.
	 * @return array
	 */
	public static function find( $post_id, array $blocks, array $linked, $limit = 25 ) {
		$index   = Linkyar_Index::get();
		$inbound = Linkyar_Links::inbound_counts();
		$skip    = array_flip( array_merge( array( (int) $post_id ), array_map( 'intval', $linked ) ) );
		$max_per = (int) apply_filters( 'linkyar_max_links_per_block', 2 );

		$folded = array();
		foreach ( $blocks as $i => $b ) {
			$text = isset( $b['text'] ) ? (string) $b['text'] : '';
			if ( '' !== trim( $text ) ) {
				$folded[ $i ] = ' ' . Linkyar_Text::fold( $text ) . ' ';
			}
		}
		if ( ! $folded ) {
			return array();
		}

		// Best occurrence per target.
		$candidates = array();
		foreach ( $index['targets'] as $id => $t ) {
			if ( isset( $skip[ $id ] ) || empty( $t['phrases'] ) ) {
				continue;
			}
			$best = null;
			foreach ( $t['phrases'] as $phrase => $source ) {
				$needle = ' ' . $phrase . ' ';
				foreach ( $folded as $bi => $hay ) {
					$pos = strpos( $hay, $needle );
					if ( false === $pos ) {
						continue;
					}
					$words = Linkyar_Text::words( $phrase );
					$in    = isset( $inbound[ $id ] ) ? $inbound[ $id ] : 0;
					$score = $words * 8 + self::SOURCE_WEIGHT[ $source ] + ( 0 === $in ? 20 : max( 0, 10 - 2 * $in ) );
					if ( null === $best || $score > $best['score'] ) {
						// Byte offset in the padded string → character offset in the unpadded folded text.
						$start = mb_strlen( substr( $hay, 0, $pos ), 'UTF-8' );
						$best  = array(
							'block'   => $bi,
							'start'   => $start,
							'length'  => mb_strlen( $phrase, 'UTF-8' ),
							'phrase'  => $phrase,
							'source'  => $source,
							'score'   => $score,
							'context' => self::context( $hay, $pos, strlen( $phrase ) ),
						);
					}
					break; // First block containing this phrase is enough.
				}
			}
			if ( $best ) {
				$best['target'] = array(
					'id'      => (int) $id,
					'title'   => $t['title'],
					'url'     => $t['url'],
					'type'    => $t['type'],
					'inbound' => isset( $inbound[ $id ] ) ? $inbound[ $id ] : 0,
				);
				$candidates[] = $best;
			}
		}

		usort(
			$candidates,
			static function ( $a, $b ) {
				return $b['score'] <=> $a['score'] ?: $a['block'] <=> $b['block'] ?: $a['start'] <=> $b['start'];
			}
		);

		// Drop overlapping spans and cap links per block.
		$taken = array();
		$per   = array();
		$out   = array();
		foreach ( $candidates as $c ) {
			$b   = $c['block'];
			$end = $c['start'] + $c['length'];
			if ( isset( $per[ $b ] ) && $per[ $b ] >= $max_per ) {
				continue;
			}
			foreach ( isset( $taken[ $b ] ) ? $taken[ $b ] : array() as $span ) {
				if ( $c['start'] < $span[1] && $end > $span[0] ) {
					continue 2;
				}
			}
			$taken[ $b ][] = array( $c['start'], $end );
			$per[ $b ]     = isset( $per[ $b ] ) ? $per[ $b ] + 1 : 1;
			$c['block_id'] = isset( $blocks[ $b ]['id'] ) ? (string) $blocks[ $b ]['id'] : '';
			$out[]         = $c;
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * A short folded snippet around a match, with the match wrapped in ‹ › markers for the UI.
	 *
	 * @param string $hay Padded folded text.
	 * @param int    $pos Byte offset of the leading space of the match.
	 * @param int    $len Byte length of the phrase.
	 * @return array{before:string,match:string,after:string}
	 */
	private static function context( $hay, $pos, $len ) {
		$before = trim( substr( $hay, 0, $pos + 1 ) );
		$after  = trim( substr( $hay, $pos + 1 + $len ) );
		$bw     = explode( ' ', $before );
		$aw     = explode( ' ', $after );
		return array(
			'before' => ( count( $bw ) > 6 ? '… ' : '' ) . implode( ' ', array_slice( $bw, -6 ) ),
			'match'  => substr( $hay, $pos + 1, $len ),
			'after'  => implode( ' ', array_slice( $aw, 0, 6 ) ) . ( count( $aw ) > 6 ? ' …' : '' ),
		);
	}
}
