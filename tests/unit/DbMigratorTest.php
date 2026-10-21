<?php
namespace WPGuard\NCBackup\Tests;
use WPGuard\NCBackup\DB_Migrator;

// Phase 2.5 (migration on restore): checking "this backup is from a
// different site" now means migration, not merely "proceed anyway" --
// every URL in the backup gets rewritten to this site's own address,
// serialization-safe, before anything is promoted live.

function test_replace_pairs_for_same_url_is_a_no_op() {
	wpguard_assert_equals( array(), DB_Migrator::replace_pairs_for( 'https://example.com', 'https://example.com' ), 'identical old/new home_url must produce nothing to replace -- the ordinary same-site restore, and a fresh install at the same, now-dead domain' );
}

function test_replace_pairs_for_empty_urls_is_a_no_op() {
	wpguard_assert_equals( array(), DB_Migrator::replace_pairs_for( '', 'https://example.com' ), 'an empty old URL must never produce a pair (would otherwise match -- and corrupt -- every string in the database)' );
}

function test_replace_pairs_for_includes_scheme_swapped_variant() {
	$pairs = DB_Migrator::replace_pairs_for( 'https://old.example', 'https://new.example' );
	$searches = array_column( $pairs, 0 );
	wpguard_assert( in_array( 'https://old.example', $searches, true ), 'the exact recorded old home_url must be one of the search terms' );
	wpguard_assert( in_array( 'http://old.example', $searches, true ), 'the http variant of the old home_url must also be covered -- pre-TLS-migration content on the old site may still link that way' );
	foreach ( $pairs as $pair ) {
		wpguard_assert_equals( 'https://new.example', $pair[1], 'every pair must replace with the same, single new home_url' );
	}
}

function test_replace_pairs_for_strips_trailing_slash() {
	$pairs = DB_Migrator::replace_pairs_for( 'https://old.example/', 'https://new.example/' );
	wpguard_assert_equals( 'https://new.example', $pairs[0][1], 'a trailing slash on either URL must not produce a distinct, narrower search/replace pair than the bare form' );
}

function test_is_serialized_value_recognizes_array() {
	wpguard_assert( true === DB_Migrator::is_serialized_value( serialize( array( 'a' => 1 ) ) ), 'a serialized array must be recognized' );
}

function test_is_serialized_value_recognizes_string() {
	wpguard_assert( true === DB_Migrator::is_serialized_value( serialize( 'hello' ) ), 'a serialized string must be recognized' );
}

function test_is_serialized_value_rejects_plain_url() {
	wpguard_assert( false === DB_Migrator::is_serialized_value( 'https://example.com/some/path' ), 'an ordinary URL must never be mistaken for a serialized value' );
}

function test_is_serialized_value_rejects_empty_string() {
	wpguard_assert( false === DB_Migrator::is_serialized_value( '' ), 'an empty string is not serialized' );
}

function test_replace_in_scalar_plain_string() {
	$pairs = array( array( 'https://old.example', 'https://new.example' ) );
	wpguard_assert_equals(
		'https://new.example/page',
		DB_Migrator::replace_in_scalar_or_serialized( 'https://old.example/page', $pairs ),
		'a plain (non-serialized) string must have the URL replaced directly'
	);
}

function test_replace_in_scalar_serialized_array_without_corrupting_length() {
	// The exact corruption this feature exists to avoid: a serialized
	// array whose replacement text is a DIFFERENT length than the
	// original -- a naive str_replace() on the raw bytes would leave
	// the string's byte-length prefix wrong, making it unparseable.
	$original = array( 'url' => 'https://old.example', 'label' => 'Visit old.example' );
	$serialized = serialize( $original );
	$pairs = array( array( 'https://old.example', 'https://a-much-longer-new-domain.example' ) );

	$result = DB_Migrator::replace_in_scalar_or_serialized( $serialized, $pairs );
	$unserialized = @unserialize( $result );

	wpguard_assert( false !== $unserialized, 'the result must still be valid, unserializable PHP serialization after a length-changing replacement' );
	wpguard_assert_equals( 'https://a-much-longer-new-domain.example', $unserialized['url'], 'the URL value inside the serialized array must be replaced' );
	wpguard_assert_equals( 'Visit old.example', $unserialized['label'], 'a bare-hostname mention (no scheme) inside unrelated text must NOT be touched -- only full, scheme-qualified URL occurrences are replaced' );
}

function test_replace_in_scalar_serialized_nested_structure() {
	$original = array(
		'widgets' => array(
			array( 'link' => 'http://old.example/a' ),
			array( 'link' => 'https://old.example/b' ),
		),
	);
	$pairs = DB_Migrator::replace_pairs_for( 'https://old.example', 'https://new.example' );
	$result = @unserialize( DB_Migrator::replace_in_scalar_or_serialized( serialize( $original ), $pairs ) );

	wpguard_assert_equals( 'https://new.example/a', $result['widgets'][0]['link'], 'the http variant must be canonicalized to the new site\'s one real (https) home_url, not an invented http variant of the new domain' );
	wpguard_assert_equals( 'https://new.example/b', $result['widgets'][1]['link'], 'the exact-scheme occurrence inside a nested array must be migrated' );
}

function test_replace_in_scalar_corrupt_serialized_value_falls_back_to_plain_replace() {
	// Shaped like a serialized string but with a byte count that
	// doesn't match its actual content -- unserialize() fails. Must
	// fall back to a plain replace rather than silently dropping the
	// value or throwing.
	$corrupt = 's:999:"https://old.example";';
	$pairs = array( array( 'https://old.example', 'https://new.example' ) );
	$result = DB_Migrator::replace_in_scalar_or_serialized( $corrupt, $pairs );
	wpguard_assert( false !== strpos( $result, 'https://new.example' ), 'a corrupt serialized-looking value must still have the plain URL text replaced, not be left untouched or dropped' );
}

function test_replace_in_scalar_incomplete_class_object_does_not_crash() {
	// Found live, restoring a real backup with Action Scheduler data
	// (used by WooCommerce and many other plugins): an object whose
	// class isn't loaded at this point in the restore unserializes
	// safely as __PHP_Incomplete_Class, but PHP forbids WRITING a
	// property on one -- a fatal error, not a catchable exception.
	// Simulated here with a class name that was never declared in this
	// test's own process, same mechanism a real unloaded plugin class
	// would hit.
	$class_name = 'WPGuard_Test_Never_Declared_Class';
	$url = 'https://old-site.example';
	$fixture = sprintf( 'O:%d:"%s":1:{s:3:"url";s:%d:"%s";}', strlen( $class_name ), $class_name, strlen( $url ), $url );
	$never_declared_object = unserialize( $fixture );
	wpguard_assert( $never_declared_object instanceof \__PHP_Incomplete_Class, 'the fixture itself must actually produce an incomplete-class object, or this test proves nothing' );

	$wrapper = array( 'nested' => $never_declared_object, 'plain' => 'https://old-site.example/page' );
	$pairs = array( array( 'https://old-site.example', 'https://new-site.example' ) );

	$result = DB_Migrator::replace_in_scalar_or_serialized( serialize( $wrapper ), $pairs );
	$unserialized = @unserialize( $result );

	wpguard_assert( false !== $unserialized, 'the overall result must still be valid, unserializable data -- not a fatal error and not corrupted' );
	wpguard_assert_equals( 'https://new-site.example/page', $unserialized['plain'], 'a sibling plain string must still be migrated normally' );
	wpguard_assert( $unserialized['nested'] instanceof \__PHP_Incomplete_Class, 'the incomplete-class object must survive, left alone rather than crashing the whole migration' );
}

function test_replace_pairs_for_refuses_subdirectory_migration_pair() {
	// Security audit MEDIUM-MIG-2: migrating to a subdirectory install
	// (https://example.com -> https://example.com/wordpress) makes the
	// new URL continue the old one with a further path segment --
	// replaying this pair on an already-migrated value (exactly what a
	// stale checkpoint resume does, relying on the replace being
	// idempotent) would append the suffix again every time. Must
	// refuse with a WP_Error (not silently return empty pairs, which
	// the first version of this fix did -- re-verification found that
	// let the restore fall through to an unmigrated result past the
	// point of no return instead of stopping while still read-only).
	wpguard_assert(
		is_wp_error( DB_Migrator::replace_pairs_for( 'https://example.com', 'https://example.com/wordpress' ) ),
		'a new URL that extends the old URL with a further path segment must be refused with a WP_Error -- not idempotent to replay on a checkpoint resume'
	);
}

function test_replace_pairs_for_allows_ordinary_different_domain_migration() {
	wpguard_assert( ! empty( DB_Migrator::replace_pairs_for( 'https://old.example', 'https://new.example' ) ), 'an ordinary different-domain migration (the whole point of this feature) must still produce real pairs' );
}

function test_replace_pairs_for_allows_scheme_only_migration() {
	// Security audit re-verification, NEW-HIGH-1: swap_scheme() on the
	// old URL can legitimately equal the new URL for an ordinary
	// scheme-only migration (http://example.com -> https://example.com),
	// producing an IDENTITY pair ('https://example.com','https://example.com').
	// The first version of the idempotency check wrongly refused the
	// entire migration over this harmless pair -- an identity
	// replacement is a guaranteed no-op, never unsafe.
	$pairs = DB_Migrator::replace_pairs_for( 'http://example.com', 'https://example.com' );
	wpguard_assert( ! is_wp_error( $pairs ), 'an ordinary scheme-only migration must never be refused' );
	wpguard_assert( ! empty( $pairs ), 'an ordinary scheme-only migration must still produce at least one real pair (the http variant -> the new https URL)' );
}

function test_replace_pairs_for_allows_port_addition() {
	// Security audit re-verification, NEW-HIGH-1: the first fix used a
	// bare strpos()-prefix check, which also wrongly refused this safe
	// case -- adding a port is not a path/query extension, and the
	// boundary-aware regex already prevents a second pass from
	// matching its own first pass's output here (":" is a rejected
	// continuation character).
	wpguard_assert( ! is_wp_error( DB_Migrator::replace_pairs_for( 'http://localhost', 'http://localhost:8080' ) ), 'adding a port must never be refused -- it is not a path/query extension and the boundary regex already makes it safe to replay' );
}

function test_replace_pairs_for_allows_longer_unrelated_hostname() {
	// e.g. https://example.co -> https://example.com -- shares a long
	// string prefix for an entirely unrelated reason (not a path
	// extension of the old URL); must not be refused.
	wpguard_assert( ! is_wp_error( DB_Migrator::replace_pairs_for( 'https://example.co', 'https://example.com' ) ), 'a longer but unrelated hostname that happens to share a string prefix must not be refused' );
}

function test_replace_pairs_for_allows_cctld_extension() {
	wpguard_assert( ! is_wp_error( DB_Migrator::replace_pairs_for( 'https://example.com', 'https://example.com.au' ) ), 'a ccTLD extension must not be refused -- the boundary regex already makes it safe to replay' );
}

function test_str_replace_does_not_overmatch_domain_suffix() {
	// Security audit MEDIUM-MIG-1: a plain str_replace() has no concept
	// of a URL boundary. Proven live: searching for "https://example.com"
	// wrongly matched inside "https://example.community/...".
	$pairs = array( array( 'https://old.example', 'https://new.example' ) );
	wpguard_assert_equals(
		'Visit https://old.example.evil.net/attack',
		DB_Migrator::replace_in_scalar_or_serialized( 'Visit https://old.example.evil.net/attack', $pairs ),
		'a domain that merely starts with the old URL as a substring (a longer, unrelated domain) must never be touched'
	);
}

function test_str_replace_still_matches_at_a_real_url_boundary() {
	$pairs = array( array( 'https://old.example', 'https://new.example' ) );
	wpguard_assert_equals(
		'Visit https://new.example/page and https://new.example.',
		DB_Migrator::replace_in_scalar_or_serialized( 'Visit https://old.example/page and https://old.example.', $pairs ),
		'the ordinary cases -- a URL ending at a slash, or at a sentence-ending period -- must still match and replace correctly'
	);
}

function test_replace_in_scalar_c_format_serializable_object_left_unchanged_not_corrupted() {
	// Security audit HIGH-MIG-1: a `C:`-format object (the Serializable
	// interface, common before its PHP 8.1 deprecation -- WooCommerce,
	// Gravity Forms, ACF, and others all had classes using it) loses its
	// entire inner payload when unserialize() can't instantiate the real
	// class -- proven live: re-serializing the resulting
	// __PHP_Incomplete_Class silently emitted an empty "O:ClassName:0:{}"
	// in place of the real payload, corrupting data on every row it
	// touched regardless of whether it even contained a URL.
	$class_name = 'WPGuard_Test_Never_Declared_Serializable';
	$payload = 'url=https://old-site.example&other=data';
	$fixture = sprintf( 'a:1:{i:0;C:%d:"%s":%d:{%s}}', strlen( $class_name ), $class_name, strlen( $payload ), $payload );

	$pairs = array( array( 'https://old-site.example', 'https://new-site.example' ) );
	$result = DB_Migrator::replace_in_scalar_or_serialized( $fixture, $pairs );

	wpguard_assert_equals( $fixture, $result, 'a value containing a C:-format object that cannot be losslessly round-tripped must be left completely untouched, not silently corrupted with an empty payload' );
}

function test_replace_in_scalar_double_serialized_value_survives_intact() {
	// Security audit re-verification, NEW-MEDIUM-1: WordPress's own
	// maybe_serialize() deliberately double-serializes a value that
	// already looks serialized when passed as a raw string (a common
	// real-world mistake: update_option($key, serialize($data)) instead
	// of update_option($key, $data)), to disambiguate "a literal string
	// that happens to look serialized" from real array data on a later
	// read. Proven live: treating the unserialized INNER string as a
	// plain leaf (a raw str_replace on its bytes) left its own
	// length-prefixed encoding stale while only the OUTER wrapper's
	// length got recomputed -- corruption invisible until something
	// unserializes it a second time.
	$inner = serialize( array( 'url' => 'https://old.example/page' ) );
	$double_serialized = serialize( $inner );

	$pairs = array( array( 'https://old.example', 'https://a-much-longer-new-domain.example' ) );
	$result = DB_Migrator::replace_in_scalar_or_serialized( $double_serialized, $pairs );

	$once = @unserialize( $result );
	wpguard_assert( false !== $once, 'the outer layer must still unserialize correctly' );
	$twice = @unserialize( $once );
	wpguard_assert( false !== $twice, 'the inner layer, after unserializing the outer, must ALSO still unserialize correctly -- this is exactly what failed before the fix' );
	wpguard_assert_equals( 'https://a-much-longer-new-domain.example/page', $twice['url'], 'the URL inside the double-serialized value must still be correctly migrated' );
}

function test_replace_in_scalar_no_pairs_is_a_no_op() {
	wpguard_assert_equals( 'https://old.example/page', DB_Migrator::replace_in_scalar_or_serialized( 'https://old.example/page', array() ), 'no pairs (the ordinary same-site restore) must leave the value completely untouched' );
}

return array(
	'test_replace_pairs_for_same_url_is_a_no_op',
	'test_replace_pairs_for_empty_urls_is_a_no_op',
	'test_replace_pairs_for_includes_scheme_swapped_variant',
	'test_replace_pairs_for_strips_trailing_slash',
	'test_is_serialized_value_recognizes_array',
	'test_is_serialized_value_recognizes_string',
	'test_is_serialized_value_rejects_plain_url',
	'test_is_serialized_value_rejects_empty_string',
	'test_replace_in_scalar_plain_string',
	'test_replace_in_scalar_serialized_array_without_corrupting_length',
	'test_replace_in_scalar_serialized_nested_structure',
	'test_replace_in_scalar_corrupt_serialized_value_falls_back_to_plain_replace',
	'test_replace_in_scalar_incomplete_class_object_does_not_crash',
	'test_replace_pairs_for_refuses_subdirectory_migration_pair',
	'test_replace_pairs_for_allows_ordinary_different_domain_migration',
	'test_replace_pairs_for_allows_scheme_only_migration',
	'test_replace_pairs_for_allows_port_addition',
	'test_replace_pairs_for_allows_longer_unrelated_hostname',
	'test_replace_pairs_for_allows_cctld_extension',
	'test_str_replace_does_not_overmatch_domain_suffix',
	'test_str_replace_still_matches_at_a_real_url_boundary',
	'test_replace_in_scalar_c_format_serializable_object_left_unchanged_not_corrupted',
	'test_replace_in_scalar_double_serialized_value_survives_intact',
	'test_replace_in_scalar_no_pairs_is_a_no_op',
);
