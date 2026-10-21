<?php
namespace WPGuard\NCBackup\Tests;
use WPGuard\NCBackup\Job_Orchestrator;

// security audit finding MEDIUM-ZIP-2: a part holding only zero-byte files after a
// size-based rollover could read current_part_bytes === 0 and never get
// finalized/uploaded/restored, while still being referenced by a
// resolved_files entry. These cover the exact boundary directly,
// deterministically -- reproducing it through a real filesystem walk
// would depend on DirectoryIterator's unspecified iteration order.

function test_should_finalize_when_bytes_present() {
	wpguard_assert( true === \WPGuard\NCBackup\Job_Orchestrator::should_finalize_trailing_part( 1024, 1, 1 ), 'a part with real bytes must be finalized' );
}

function test_should_finalize_zero_byte_only_part_after_rollover() {
	// The exact MEDIUM-ZIP-2 scenario: a part holds only zero-byte
	// files (file_count=1) after a prior part was already finalized
	// (parts_meta_count=1) -- bytes alone reads 0 and must NOT be
	// trusted as "nothing here."
	wpguard_assert( true === \WPGuard\NCBackup\Job_Orchestrator::should_finalize_trailing_part( 0, 1, 1 ), 'a part holding only zero-byte files must still be finalized -- byte count alone cannot detect this' );
}

function test_should_finalize_first_part_even_if_empty() {
	// parts_meta_count===0 means nothing has ever been finalized yet --
	// a genuinely empty site's single (empty) part must still be
	// finalized once, matching the pre-incremental behavior this
	// condition preserves.
	wpguard_assert( true === \WPGuard\NCBackup\Job_Orchestrator::should_finalize_trailing_part( 0, 0, 0 ), 'the very first part must be finalized even with zero bytes and zero files (an empty site)' );
}

function test_should_not_finalize_already_finalized_empty_trailing_state() {
	// After the LAST real part was already finalized at a rollover
	// boundary (parts_meta_count>0) and the trailing part has
	// genuinely nothing in it (bytes=0, file_count=0 -- not even a
	// zero-byte file was added), there is nothing left to finalize.
	wpguard_assert( false === \WPGuard\NCBackup\Job_Orchestrator::should_finalize_trailing_part( 0, 0, 1 ), 'a trailing part with no bytes and no files at all, after a real part was already finalized, must not be finalized again' );
}

return array(
	'test_should_finalize_when_bytes_present',
	'test_should_finalize_zero_byte_only_part_after_rollover',
	'test_should_finalize_first_part_even_if_empty',
	'test_should_not_finalize_already_finalized_empty_trailing_state',
);
