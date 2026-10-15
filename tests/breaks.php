<?php
// BR sentence grouping with either the real lexical API or legacy DOM capture.
if ( empty( $argv[1] ) || ! is_file( $argv[1] . '/wp-includes/html-api/class-wp-html-tag-processor.php' ) ) { exit( 1 ); }
if ( ( $argv[2] ?? '' ) === 'legacy' ) { require __DIR__ . '/run.php'; }
else { require __DIR__ . '/core.php'; }
$checks = 0;
function br_doc( string $body ): CPTAI_HTML { return new CPTAI_HTML( '<!doctype html><html lang="es"><body>' . $body . '</body></html>' ); }
$original = 'An entire creative team [cptai_br_1] at your fingertips!';
$translated = 'Todo un equipo creativo [cptai_br_1] al alcance de tu mano!';
$markup = '<p class="elementor-heading-title elementor-size-default">An entire creative team<br> at your fingertips!</p>';
$doc = br_doc( $markup );
check( array( $original ) === $doc->strings(), 'one complete sentence for user BR example, no independent fragments' );
check( array( $original ) === br_doc( "<p>An entire creative team<BR class=\"desktop-break\" />\n at your fingertips!</p>" )->strings(), 'BR spelling and attributes do not change dictionary key' );
check( array( $original ) === br_doc( '<span>An entire creative team<br>at your fingertips!</span>' )->strings(), 'outer inline wrapper does not change key' );
check( array( $original ) === br_doc( '<p>An entire<!--comment--> creative team<br>at your fingertips!</p>' )->strings(), 'comments do not divide phrases' );
$multi = br_doc( '<h2>First<br><br>Last</h2>' );
check( array( 'First [cptai_br_1] [cptai_br_2] Last' ) === $multi->strings(), 'consecutive breaks preserve blank line in single string' );
$styled = '<p class="heading">An entire <strong class="accent">creative</strong> team<br class="mobile">at <a href="#form">your fingertips!</a></p>';
$styled_key = 'An entire [cptai_tag_1]creative[cptai_tag_2] team [cptai_br_1] at [cptai_tag_3]your fingertips![cptai_tag_4]';
check( array( $styled_key ) === br_doc( $styled )->strings(), 'inline styling and links represented without HTML in dictionary' );
check( array( 'One', 'Two', 'Three', 'Four' ) === br_doc( '<p>One</p><p>Two<br></p><p>Three</p><div>Four</div>' )->strings(), 'separate blocks and trailing BR stay independent' );
check( ! in_array( $original, br_doc( '<p translate="no">An entire creative team<br>at your fingertips!</p>' )->strings(), true ), 'excluded parent not grouped or captured' );
check( array( 'First', 'Last' ) === br_doc( '<p>First<br data-cptai-ignore>Last</p>' )->strings(), 'ignored BR is a boundary' );
check( array( 'First', 'Last' ) === br_doc( '<p>First<code>private<br>code</code>Last</p>' )->strings(), 'code is not pulled into a sentence' );
check( array( '123', '456' ) !== br_doc( '<p>123<br>456</p>' )->strings() && ! br_doc( '<p>123<br>456</p>' )->strings(), 'pure numbers are not captured by marker letters' );
check( CPTAI_Provider::valid( $original, $translated ), 'complete translation with marker accepted' );
check( ! CPTAI_Provider::valid( $original, 'Todo un equipo creativo al alcance de tu mano!' ), 'missing BR rejected' );
check( ! CPTAI_Provider::valid( $original, 'Un equipo <br> a tu alcance' ), 'HTML replacement rejected' );
check( ! CPTAI_Provider::valid( 'First [cptai_br_1] [cptai_br_2] Last', 'Primero [cptai_br_2] [cptai_br_1] Último' ), 'reordered markers rejected' );
check( ! CPTAI_Provider::valid( 'First [cptai_br_1] [cptai_br_2] Last', 'Primero [cptai_br_1] New words [cptai_br_2] Último' ), 'cannot invent words without a source text node' );
check( ! CPTAI_Provider::valid( $original, $translated . ' [cptai_br_1]' ), 'duplicated marker rejected' );
if ( CPTAI_HTML::preserves_markup() ) {
    $out = $doc->render( array( $original => $translated, 'An entire creative team' => 'Incorrect fragment' ), 'es' );
    check( str_contains( $out, '<p class="elementor-heading-title elementor-size-default">Todo un equipo creativo<br>al alcance de tu mano!</p>' ), 'whole translation written to original text slots, BR and classes identical' );
    check( ! str_contains( $out, 'cptai_br' ) && ! str_contains( $out, 'Incorrect fragment' ), 'tokens not rendered and fragment translation cannot win' );
    check( str_contains( $doc->render( array( 'An entire creative team' => 'Old fragment' ), 'es' ), $markup ), 'no full translation keeps source sentence instead of independently translating fragments' );
    $same = $doc->render( array(), 'es' );
    check( str_contains( $same, $markup ), 'uncorrected group keeps exact original markup' );
    $style_translation = 'Todo un equipo [cptai_tag_1]creativo[cptai_tag_2] completo [cptai_br_1] al [cptai_tag_3]alcance de tu mano![cptai_tag_4]';
    $out = br_doc( $styled )->render( array( $styled_key => $style_translation ), 'es' );
    check( str_contains( $out, '<strong class="accent">creativo</strong>' ) && str_contains( $out, '<br class="mobile">' ) && str_contains( $out, '<a href="#form">alcance de tu mano!</a>' ), 'styled text preserves nodes attributes and link' );
    $out = $multi->render( array( 'First [cptai_br_1] [cptai_br_2] Last' => 'Primero [cptai_br_1] [cptai_br_2] Último' ), 'es' );
    check( str_contains( $out, '<h2>Primero<br><br>Último</h2>' ), 'blank line remains exactly two BR tags' );
    $out = br_doc( '<p>A &amp; B<br>More words</p>' )->render( array( 'A & B [cptai_br_1] More words' => 'A y B [cptai_br_1] Más palabras & datos' ), 'es' );
    check( str_contains( $out, '<p>A y B<br>Más palabras &amp; datos</p>' ), 'entity decoding and output escaping remain correct' );
    $out = br_doc( '<p>March<br>2026</p>' )->render( array(), 'en' );
    check( str_contains( $out, '<p>March<br>2026</p>' ), 'source date not changed by grouping' );
}
echo 'PASS: ' . $checks . ' BR checks (' . ( CPTAI_HTML::preserves_markup() ? 'lexical' : 'legacy' ) . ").\n";
