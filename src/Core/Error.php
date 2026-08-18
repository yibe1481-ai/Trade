<?php
declare( strict_types=1 );

namespace Trade\Core;

use Trade\Localization\Lang;

/** Stable REST error envelope and HTTP mapping. */
final class Error {
	private const STATUS=array(
		'VALIDATION_FAILED'=>400,'AUTH_INVALID_SIGNATURE'=>401,'AUTH_EXPIRED_INITDATA'=>401,'AUTH_REPLAY_DETECTED'=>401,'AUTH_SESSION_EXPIRED'=>401,
		'IDEMPOTENCY_KEY_REUSED'=>422,'REVIEW_NOT_ELIGIBLE'=>422,'ENTITLEMENT_LIMIT_REACHED'=>422,'FORBIDDEN_CAPABILITY'=>403,'FORBIDDEN_NOT_OWNER'=>403,
		'MERCHANT_NOT_FOUND'=>404,'MERCHANT_NOT_VERIFIED'=>403,'LOCATION_NOT_FOUND'=>404,'CATEGORY_NOT_FOUND'=>404,'LISTING_NOT_FOUND'=>404,'LISTING_IMAGE_NOT_FOUND'=>404,
		'ORDER_NOT_FOUND'=>404,'REQUEST_NOT_FOUND'=>404,'ORDER_INVALID_TRANSITION'=>409,'REQUEST_INVALID_TRANSITION'=>409,'LISTING_INVALID_TRANSITION'=>409,
		'LISTING_NOT_AVAILABLE'=>409,'ORDER_ALREADY_OPEN'=>409,'INVENTORY_INSUFFICIENT_STOCK'=>409,'CONFLICT_STALE_VERSION'=>409,'REQUEST_IN_PROGRESS'=>409,'JOB_LEASE_LOST'=>409,
		'VERIFICATION_INVALID_TRANSITION'=>409,'VERIFICATION_ADMIN_REQUIRED'=>403,'RATE_LIMITED'=>429,'AI_BUDGET_EXHAUSTED'=>429,'INTERNAL_ERROR'=>500,'AI_PROVIDER_UNAVAILABLE'=>503,'TELEGRAM_UNAVAILABLE'=>503,
	);
	public static function status(string $code): int{return self::STATUS[$code]??500;}
	public static function retryable(string $code): bool{return in_array(self::status($code),array(429,503),true);}
	public static function envelope(string $code,string $module,string $message,array $context=array(),?bool $retryable=null): array{return array('success'=>false,'error'=>array('code'=>$code,'module'=>$module,'message'=>$message,'retryable'=>$retryable??self::retryable($code),'request_id'=>Request::id(),'context'=>$context));}
	public static function ok(array $data,array $meta=array()): array{return array('success'=>true,'data'=>$data,'meta'=>$meta);}
	public static function validation(array $fields,string $module='core'): Exception{return new Exception('VALIDATION_FAILED',$module,'Request failed validation.',array('fields'=>$fields));}
	public static function throw_(string $code,string $module,string $message,array $context=array()): never{throw new Exception($code,$module,$message,$context);}
	public static function text(string $code,?string $lang=null): string{return Lang::text($code,$lang);}
}
