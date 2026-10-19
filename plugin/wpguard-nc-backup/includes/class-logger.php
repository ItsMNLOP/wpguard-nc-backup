<?php
namespace WPGuard\NCBackup;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Structured per-job log entries with mandatory secret redaction
 * (threat-model T8). Entries are stored on the job row (Job_Store) as a
 * bounded-length array, not in a separate ever-growing table, for MVP.
 */
class Logger {

	/** @var string[] Secret values to strip from any string before it is persisted or displayed. */
	private array $secrets;

	public function __construct( array $secrets = array() ) {
		$this->secrets = array_values( array_filter( $secrets, static fn( $s ) => is_string( $s ) && '' !== $s ) );
	}

	public function redact( string $text ): string {
		foreach ( $this->secrets as $secret ) {
			if ( '' === $secret ) {
				continue;
			}
			$text = str_replace( $secret, '[redacted]', $text );
		}
		// Defense in depth: also strip anything that looks like a Basic auth header value.
		$text = preg_replace( '/Authorization:\s*Basic\s+[A-Za-z0-9+\/=]+/i', 'Authorization: Basic [redacted]', $text );
		return $text;
	}

	/**
	 * @param string $severity One of info|warning|error.
	 */
	public function entry( string $job_id, string $step, string $severity, string $message, array $context = array() ): array {
		$safe_context = array();
		foreach ( $context as $k => $v ) {
			$safe_context[ $k ] = is_string( $v ) ? $this->redact( $v ) : $v;
		}
		return array(
			'job_id'    => $job_id,
			'step'      => $step,
			'severity'  => $severity,
			'message'   => $this->redact( $message ),
			'context'   => $safe_context,
			'time_utc'  => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);
	}
}
