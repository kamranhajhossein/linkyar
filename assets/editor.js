/* Linkyar – block editor sidebar. Plain ES5+ against WordPress globals, no build step. */
( function ( wp ) {
	'use strict';

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var Fragment = wp.element.Fragment;
	var C = wp.components;
	var select = wp.data.select;
	var dispatch = wp.data.dispatch;
	var useSelect = wp.data.useSelect;
	var rt = wp.richText;
	var apiFetch = wp.apiFetch;
	var editorPkg = wp.editor && wp.editor.PluginSidebar ? wp.editor : wp.editPost;
	var PluginSidebar = editorPkg.PluginSidebar;
	var PluginSidebarMoreMenuItem = editorPkg.PluginSidebarMoreMenuItem;
	var t = ( window.LINKYAR && window.LINKYAR.i18n ) || {};

	var BLOCKS = { 'core/paragraph': 'content', 'core/list-item': 'content', 'core/quote': null };

	/* ---------- Text folding: must stay identical to Linkyar_Text::fold() in PHP ---------- */
	var MAP = {
		'ي': 'ی', 'ى': 'ی', 'ئ': 'ی', 'ك': 'ک', 'ۀ': 'ه', 'ة': 'ه',
		'أ': 'ا', 'إ': 'ا', 'آ': 'ا', 'ٱ': 'ا', 'ؤ': 'و',
		'۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4', '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9',
		'٠': '0', '١': '1', '٢': '2', '٣': '3', '٤': '4', '٥': '5', '٦': '6', '٧': '7', '٨': '8', '٩': '9'
	};
	var RE_MARK = /^\p{M}$/u;
	var RE_WORD = /^[\p{L}\p{N}]$/u;

	function foldChar( c ) {
		if ( Object.prototype.hasOwnProperty.call( MAP, c ) ) {
			return MAP[ c ];
		}
		if ( c === 'ـ' || RE_MARK.test( c ) ) {
			return '';
		}
		if ( RE_WORD.test( c ) ) {
			return c.toLowerCase();
		}
		return ' ';
	}

	/**
	 * Fold text and keep, for every folded code point, the UTF-16 range it came from.
	 * Returns { chars: string[] (code points), start: number[], end: number[] }.
	 */
	function foldWithMap( text ) {
		var chars = [];
		var start = [];
		var end = [];
		var last = ' ';
		var i = 0;
		for ( var cp of text ) {
			var f = foldChar( cp );
			var len = cp.length;
			if ( f === '' ) {
				// Combining marks belong to the previous letter: extend its range.
				if ( chars.length && last !== ' ' ) {
					end[ end.length - 1 ] = i + len;
				}
			} else if ( ! ( f === ' ' && last === ' ' ) ) {
				chars.push( f );
				start.push( i );
				end.push( i + len );
				last = f;
			}
			i += len;
		}
		if ( chars.length && chars[ chars.length - 1 ] === ' ' ) {
			chars.pop();
			start.pop();
			end.pop();
		}
		return { chars: chars, start: start, end: end };
	}

	/* ---------- Reading blocks ---------- */
	function toHtml( value ) {
		if ( typeof value === 'string' ) {
			return value;
		}
		if ( value && typeof value.toHTMLString === 'function' ) {
			return value.toHTMLString();
		}
		return value ? String( value ) : '';
	}

	function collect( blocks, out ) {
		blocks.forEach( function ( b ) {
			var attr = BLOCKS[ b.name ];
			if ( attr && b.attributes[ attr ] ) {
				out.push( b );
			}
			if ( b.innerBlocks && b.innerBlocks.length ) {
				collect( b.innerBlocks, out );
			}
		} );
		return out;
	}

	/** Rich-text value of a block plus its text with already-linked characters blanked out. */
	function readBlock( block ) {
		var html = toHtml( block.attributes[ BLOCKS[ block.name ] ] );
		var value = rt.create( { html: html } );
		var text = value.text.split( '' );
		( value.formats || [] ).forEach( function ( fs, idx ) {
			if ( fs && fs.some( function ( f ) {
				return f && f.type === 'core/link';
			} ) ) {
				text[ idx ] = ' ';
			}
		} );
		return { html: html, value: value, text: text.join( '' ) };
	}

	function hrefs( html, out ) {
		var re = /<a\s[^>]*href\s*=\s*(["'])(.*?)\1/gi;
		var m;
		while ( ( m = re.exec( html ) ) ) {
			out.push( m[ 2 ] );
		}
		return out;
	}

	function scan() {
		var be = select( 'core/block-editor' );
		var blocks = collect( be.getBlocks(), [] );
		var links = [];
		var payload = blocks.map( function ( b ) {
			var r = readBlock( b );
			hrefs( r.html, links );
			return { id: b.clientId, text: r.text };
		} );
		return apiFetch( {
			path: '/linkyar/v1/suggest',
			method: 'POST',
			data: {
				post_id: select( 'core/editor' ).getCurrentPostId(),
				blocks: payload,
				links: links
			}
		} );
	}

	/** Wrap the suggested phrase with a link. Returns false if the text no longer matches. */
	function insert( s ) {
		var block = select( 'core/block-editor' ).getBlock( s.block_id );
		if ( ! block || ! BLOCKS[ block.name ] ) {
			return false;
		}
		var r = readBlock( block );
		var f = foldWithMap( r.text );
		var got = f.chars.slice( s.start, s.start + s.length ).join( '' );
		if ( got !== s.phrase ) {
			return false;
		}
		var from = f.start[ s.start ];
		var to = f.end[ s.start + s.length - 1 ];
		for ( var i = from; i < to; i++ ) {
			var fs = r.value.formats[ i ];
			if ( fs && fs.some( function ( x ) {
				return x && x.type === 'core/link';
			} ) ) {
				return false;
			}
		}
		var linked = rt.applyFormat( r.value, { type: 'core/link', attributes: { url: s.target.url } }, from, to );
		var attrs = {};
		attrs[ BLOCKS[ block.name ] ] = rt.toHTMLString( { value: linked } );
		dispatch( 'core/block-editor' ).updateBlockAttributes( block.clientId, attrs );
		dispatch( 'core/block-editor' ).selectBlock( block.clientId );
		return true;
	}

	/* ---------- UI ---------- */
	function fmt( str, n ) {
		return String( str || '' ).replace( '%d', n );
	}

	function Card( props ) {
		var s = props.s;
		var state = props.state;
		var badge = s.target.inbound === 0 ? el( 'span', { className: 'linkyar-badge is-orphan' }, t.orphan ) : el( 'span', { className: 'linkyar-badge' }, fmt( t.inboundN, s.target.inbound ) );
		return el(
			'div',
			{ className: 'linkyar-card' + ( state ? ' is-' + state : '' ) },
			el(
				'div',
				{ className: 'linkyar-card-head' },
				el( 'a', { href: s.target.url, target: '_blank', rel: 'noopener noreferrer', className: 'linkyar-target' }, s.target.title ),
				badge
			),
			el(
				'p',
				{ className: 'linkyar-context', dir: 'auto' },
				s.context.before + ' ',
				el( 'mark', null, s.context.match ),
				' ' + s.context.after
			),
			el(
				'div',
				{ className: 'linkyar-card-foot' },
				el( 'span', { className: 'linkyar-source' }, ( t.source && t.source[ s.source ] ) || s.source ),
				state === 'done' ? el( 'span', { className: 'linkyar-ok' }, '✓ ' + t.inserted ) :
					state === 'stale' ? el( 'span', { className: 'linkyar-warn' }, t.notFound ) :
						el(
							Fragment,
							null,
							el( C.Button, { variant: 'tertiary', isSmall: true, onClick: props.onSkip }, t.skip ),
							el( C.Button, { variant: 'primary', isSmall: true, onClick: props.onInsert }, t.insert )
						)
			)
		);
	}

	function Sidebar() {
		var postId = useSelect( function ( s ) {
			return s( 'core/editor' ).getCurrentPostId();
		}, [] );
		var _a = useState( null ), data = _a[ 0 ], setData = _a[ 1 ];
		var _b = useState( false ), busy = _b[ 0 ], setBusy = _b[ 1 ];
		var _c = useState( {} ), states = _c[ 0 ], setStates = _c[ 1 ];
		var _d = useState( '' ), error = _d[ 0 ], setError = _d[ 1 ];
		var _e = useState( null ), kw = _e[ 0 ], setKw = _e[ 1 ];
		var _f = useState( '' ), kwState = _f[ 0 ], setKwState = _f[ 1 ];

		function run() {
			setBusy( true );
			setError( '' );
			scan()
				.then( function ( d ) {
					setData( d );
					setStates( {} );
					if ( kw === null ) {
						setKw( d.keywords || '' );
					}
				} )
				.catch( function () {
					setError( t.error );
				} )
				.then( function () {
					setBusy( false );
				} );
		}

		useEffect( function () {
			if ( postId ) {
				run();
			}
		}, [ postId ] );

		function mark( key, v ) {
			var next = Object.assign( {}, states );
			next[ key ] = v;
			setStates( next );
		}

		function saveKw() {
			setKwState( 'saving' );
			apiFetch( { path: '/linkyar/v1/keywords', method: 'POST', data: { post_id: postId, keywords: kw || '' } } )
				.then( function () {
					setKwState( 'saved' );
				} )
				.catch( function () {
					setKwState( '' );
					setError( t.error );
				} );
		}

		var list = data && data.suggestions ? data.suggestions.filter( function ( s ) {
			return states[ s.target.id ] !== 'skip';
		} ) : [];

		return el(
			Fragment,
			null,
			el( PluginSidebarMoreMenuItem, { target: 'linkyar', icon: 'admin-links' }, t.menu ),
			el(
				PluginSidebar,
				{ name: 'linkyar', title: t.title, icon: 'admin-links' },
				el(
					C.PanelBody,
					{ initialOpen: true },
					data ? el( 'p', { className: 'linkyar-stat' + ( data.inbound ? '' : ' is-orphan' ) }, data.inbound ? fmt( t.thisInbound, data.inbound ) : t.thisOrphan ) : null,
					el( C.Button, { variant: 'secondary', onClick: run, isBusy: busy, disabled: busy, className: 'linkyar-scan' }, busy ? t.searching : ( data ? t.refresh : t.find ) ),
					error ? el( C.Notice, { status: 'error', isDismissible: false }, error ) : null,
					data && ! data.built ? el( 'p', { className: 'linkyar-tip' }, t.notBuilt ) : null,
					data && ! list.length && ! busy ? el( 'p', { className: 'linkyar-empty' }, t.none ) : null,
					list.map( function ( s ) {
						return el( Card, {
							key: s.target.id,
							s: s,
							state: states[ s.target.id ],
							onSkip: function () {
								mark( s.target.id, 'skip' );
							},
							onInsert: function () {
								mark( s.target.id, insert( s ) ? 'done' : 'stale' );
							}
						} );
					} )
				),
				el(
					C.PanelBody,
					{ title: t.keywords, initialOpen: false },
					el( C.TextareaControl, {
						value: kw || '',
						help: t.keywordsHelp,
						rows: 3,
						onChange: function ( v ) {
							setKw( v );
							setKwState( '' );
						}
					} ),
					el( C.Button, { variant: 'secondary', onClick: saveKw, isBusy: kwState === 'saving' }, kwState === 'saved' ? '✓ ' + t.saved : t.save )
				)
			)
		);
	}

	wp.plugins.registerPlugin( 'linkyar', { render: Sidebar, icon: 'admin-links' } );

	// Exposed for tests.
	window.linkyarFold = foldWithMap;
} )( window.wp );
