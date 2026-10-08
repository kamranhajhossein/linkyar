<?php
define( 'LINKYAR_TESTING', true );
require __DIR__ . '/../includes/class-linkyar-text.php';
$out = array();
foreach ( json_decode( file_get_contents( __DIR__ . '/cases.json' ), true ) as $c ) {
	$out[] = Linkyar_Text::fold( $c );
}
echo json_encode( $out, JSON_UNESCAPED_UNICODE );
