<?php
define( 'ABSPATH', __DIR__ );
require dirname( __DIR__ ) . '/includes/class-calendar.php';
$cases = json_decode( file_get_contents( __DIR__ . '/calendar-cases.json' ), true, 512, JSON_THROW_ON_ERROR );
$checks = 0;
foreach ( $cases as $test ) {
    $actual = CPTAI_Calendar::translate( $test['input'], $test['source'], $test['target'], $test['dictionary'] ?? array() );
    if ( $actual !== $test['expected'] ) { throw new RuntimeException( json_encode( $test, JSON_UNESCAPED_UNICODE ) . ' => ' . $actual ); }
    $checks++;
}
$data = CPTAI_Calendar::data();
foreach ( $data as $source => $groups ) {
    foreach ( $data as $target => $destination ) {
        if ( $source === $target ) { continue; }
        foreach ( array( 'months', 'days' ) as $group ) {
            foreach ( $groups[ $group ] as $i => $names ) {
                $actual = CPTAI_Calendar::translate( $names[0], $source, $target );
                if ( mb_strtolower( $actual ) !== mb_strtolower( $destination[ $group ][ $i ][0] ) ) { throw new RuntimeException( "$source:$target:{$names[0]} => $actual" ); }
                $checks++;
            }
        }
    }
}
echo "PASS: $checks PHP calendar checks.\n";
