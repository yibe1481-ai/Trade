<?php
declare( strict_types=1 );

namespace Trade\Core;

/**
 * Domain exception carrying the §B.10 envelope fields. Controllers raise it;
 * Rest::register catches it and renders the envelope. Models never touch WP API types.
 */
final class Exception extends \RuntimeException {

	private readonly string $error_code;

	public function __construct(
		string $code,
		public readonly string $module,
		string $message,
		public readonly array $context = array(),
		public readonly ?bool $retryable = null,
	) {
		parent::__construct( $message );
		$this->error_code = $code;
	}

	public function error_code(): string {
		return $this->error_code;
	}
}