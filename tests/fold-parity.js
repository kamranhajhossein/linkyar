// Verifies assets/editor.js folds text exactly like Linkyar_Text::fold(), and that its offset map is correct.
// Run: node tests/fold-parity.js
'use strict';
const fs = require( 'fs' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );

const noop = () => {};
const Comp = new Proxy( {}, { get: () => noop } );
global.window = { LINKYAR: { i18n: {} } };
window.wp = {
	element: { createElement: noop, useState: () => [ null, noop ], useEffect: noop, Fragment: noop },
	components: Comp,
	data: { select: noop, dispatch: noop, useSelect: noop },
	richText: {},
	apiFetch: noop,
	editor: { PluginSidebar: noop, PluginSidebarMoreMenuItem: noop },
	plugins: { registerPlugin: noop },
};
eval( fs.readFileSync( path.join( __dirname, '../assets/editor.js' ), 'utf8' ) ); // eslint-disable-line no-eval
const fold = window.linkyarFold;

const cases = JSON.parse( fs.readFileSync( path.join( __dirname, 'cases.json' ), 'utf8' ) );
const php = JSON.parse( execFileSync( 'php', [ path.join( __dirname, 'fold.php' ) ] ).toString() );

let fail = 0;
cases.forEach( ( c, i ) => {
	const f = fold( c );
	const js = f.chars.join( '' );
	if ( js !== php[ i ] ) {
		fail++;
		console.log( `✗ fold mismatch #${ i }\n  php: ${ JSON.stringify( php[ i ] ) }\n  js:  ${ JSON.stringify( js ) }` );
		return;
	}
	// Each folded letter must come from an original range that folds back to the same letter.
	for ( let k = 0; k < f.chars.length; k++ ) {
		if ( f.chars[ k ] === ' ' ) {
			continue;
		}
		const src = fold( c.slice( f.start[ k ], f.end[ k ] ) ).chars.join( '' );
		if ( src !== f.chars[ k ] ) {
			fail++;
			console.log( `✗ map #${ i } at ${ k }: ${ JSON.stringify( src ) } != ${ JSON.stringify( f.chars[ k ] ) }` );
			return;
		}
	}
	console.log( `✓ ${ JSON.stringify( c ) } → ${ JSON.stringify( js ) }` );
} );
console.log( `\n${ cases.length } cases, ${ fail } failed` );
process.exit( fail ? 1 : 0 );
