<?php
function wpguard_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new \RuntimeException( $message );
	}
}
function wpguard_assert_equals( $expected, $actual, string $message ): void {
	if ( $expected !== $actual ) {
		throw new \RuntimeException( "{$message} (expected " . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) . ')' );
	}
}
