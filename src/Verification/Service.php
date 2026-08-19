<?php
declare( strict_types=1 );

namespace Trade\Verification;

use Trade\Core\Audit;
use Trade\Core\Error;
use Trade\Core\Events;
use Trade\Core\Rest;
use Trade\Core\Store;
use Trade\Listings\Service as ListingsService;
use Trade\Merchant\Service as MerchantService;
use WP_REST_Request;

/** Merchant verification: mandatory manual MVP workflow. */
final class Service {
	private const TRANSITIONS=array(
		'none'=>array('pending'),
		'pending'=>array('verified','rejected'),
		'verified'=>array('revoked'),
		'rejected'=>array('pending'),
		'revoked'=>array(),
	);
	public static function routes(): void {
		Rest::register('verification','POST','tb_manage_own_merchant_profile',array(self::class,'create'));
		Rest::register('verification/(?P<merchant_id>[0-9]+)','GET','tb_manage_own_merchant_profile',array(self::class,'read'));
		Rest::register('verification/(?P<merchant_id>[0-9]+)/transition','POST','manage_options',array(self::class,'transition'));
		Rest::register('verification/documents','POST','tb_manage_own_merchant_profile',array(self::class,'document_upload'));
	}
	public static function create(WP_REST_Request $request): array { $payload=$request->get_json_params()?:array(); $merchant_id=(int)($payload['merchant_id']??get_current_user_id()); if($merchant_id!==get_current_user_id()&&!current_user_can('manage_options'))Error::throw_('FORBIDDEN_NOT_OWNER','verification',Error::text('FORBIDDEN_NOT_OWNER'),array('merchant_id'=>$merchant_id)); return array('data'=>self::create_verification($merchant_id)); }
	public static function read(WP_REST_Request $request): array { $merchant_id=(int)$request->get_param('merchant_id'); $row=self::merchant_row($merchant_id); if(null===$row)Error::throw_('MERCHANT_NOT_FOUND','verification',Error::text('MERCHANT_NOT_FOUND'),array('merchant_id'=>$merchant_id)); if($merchant_id!==get_current_user_id()&&!current_user_can('manage_options'))Error::throw_('FORBIDDEN_NOT_OWNER','verification',Error::text('FORBIDDEN_NOT_OWNER'),array('merchant_id'=>$merchant_id)); return array('data'=>$row); }
	public static function merchant_row(int $merchant_id,?Store $store=null): ?array { return ($store??Store::default())->get_row('tb_merchants','wp_user_id = %d',array($merchant_id)); }
	public static function create_verification(int $merchant_id,?Store $store=null): array {
		$store=$store??Store::default(); $row=self::merchant_row($merchant_id,$store); if(null===$row)Error::throw_('MERCHANT_NOT_FOUND','verification',Error::text('MERCHANT_NOT_FOUND'),array('merchant_id'=>$merchant_id));
		$status=(string)($row['verification_status']??'none'); if(in_array($status,array('pending','verified'),true))return array('merchant_id'=>$merchant_id,'status'=>$status);
		$now=gmdate('Y-m-d H:i:s'); $store->update('tb_merchants',array('verification_status'=>'pending'),array('wp_user_id'=>$merchant_id));
		$store->insert('tb_verification_documents',array('merchant_id'=>$merchant_id,'document_type'=>'profile','storage_key'=>'','status'=>'pending','created_at'=>$now,'updated_at'=>$now));
		Audit::write('verification.create','merchant',(string)$merchant_id,array('status'=>$status),array('status'=>'pending'),array(),'user',(string)$merchant_id,'rest');
		return array('merchant_id'=>$merchant_id,'status'=>'pending');
	}
	public static function apply_transition(array $row,string $to,string $actor,array $extra=array(),?Store $store=null): array {
		$store=$store??Store::default(); $merchant_id=(int)$row['wp_user_id']; $from=strtolower((string)($row['verification_status']??'none')); $to=strtolower($to);
		if(!isset(self::TRANSITIONS[$from])||!in_array($to,self::TRANSITIONS[$from],true))Error::throw_('VERIFICATION_INVALID_TRANSITION','verification',Error::text('VERIFICATION_INVALID_TRANSITION'),array('merchant_id'=>$merchant_id,'from'=>$from,'to'=>$to));
		if('admin'!==$actor)Error::throw_('VERIFICATION_ADMIN_REQUIRED','verification',Error::text('VERIFICATION_ADMIN_REQUIRED'),array('merchant_id'=>$merchant_id));
		if(in_array($to,array('rejected','revoked'),true)&&''===trim((string)($extra['reason']??'')))throw Error::validation(array('reason'),'verification');
		$now=gmdate('Y-m-d H:i:s'); $set=array('verification_status'=>$to);
		if('verified'===$to){$set['verified_at']=$now; $store->update('tb_merchants',$set,array('wp_user_id'=>$merchant_id)); $store->update_where('tb_verification_documents',array('status'=>'verified','verified_at'=>$now),'merchant_id = %d',array($merchant_id)); Events::emit('MERCHANT_VERIFIED',array('merchant_id'=>$merchant_id,'reviewed_by'=>$actor));}
		elseif('rejected'===$to){$store->update('tb_merchants',$set,array('wp_user_id'=>$merchant_id)); $reason=(string)$extra['reason']; $store->update_where('tb_verification_documents',array('status'=>'rejected','revocation_reason'=>$reason,'updated_at'=>$now),'merchant_id = %d',array($merchant_id)); Events::emit('MERCHANT_VERIFICATION_REJECTED',array('merchant_id'=>$merchant_id,'reviewed_by'=>$actor,'note'=>$reason));}
		else{$reason=(string)$extra['reason']; $store->update('tb_merchants',array('verification_status'=>'revoked','verified_at'=>null),array('wp_user_id'=>$merchant_id)); $store->update_where('tb_verification_documents',array('status'=>'revoked','revoked_at'=>$now,'revocation_reason'=>$reason),'merchant_id = %d',array($merchant_id)); foreach($store->get_rows('tb_listings','merchant_id = %d AND status = %s',array($merchant_id,'ACTIVE')) as $listing){ListingsService::apply_transition($listing,'PAUSED','admin','',$store);} Events::emit('MERCHANT_VERIFICATION_REVOKED',array('merchant_id'=>$merchant_id,'revoked_by'=>$actor,'reason'=>$reason));}
		Audit::write('verification.transition','merchant',(string)$merchant_id,array('status'=>$from),array('status'=>$to),array('actor'=>$actor,'reason'=>$extra['reason']??''),'user',(string)get_current_user_id(),'rest'); return array('merchant_id'=>$merchant_id,'status'=>$to,'from'=>$from);
	}
	/** Document types that count toward the seller level ladder (not the internal 'profile' row). */
	private const LEVEL_DOCS = array( 'national_id', 'trade_license', 'business_registration' );

	private const LEVEL_CAPS = array(
		'L0' => array( 'active_listings' => 1,   'images_per_listing' => 1 ),
		'L1' => array( 'active_listings' => 5,   'images_per_listing' => 3 ),
		'L2' => array( 'active_listings' => 25,  'images_per_listing' => 5 ),
		'L3' => array( 'active_listings' => 100, 'images_per_listing' => 10 ),
	);

	/** Seller level = number of verified documents (national_id/trade_license/business_registration). */
	public static function level_for( int $merchant_id, ?Store $store = null ): string {
		$store = $store ?? Store::default();
		$n = 0;
		foreach ( $store->get_rows( 'tb_verification_documents', 'merchant_id = %d', array( $merchant_id ) ) as $doc ) {
			if ( 'verified' === (string) ( $doc['status'] ?? '' ) && in_array( (string) ( $doc['document_type'] ?? '' ), self::LEVEL_DOCS, true ) ) {
				$n++;
			}
		}
		return 'L' . min( 3, $n );
	}

	public static function level_caps( string $level ): array {
		return self::LEVEL_CAPS[ $level ] ?? self::LEVEL_CAPS['L0'];
	}

	/** Write seller_level + the level's caps as entitlements; returns the level. */
	public static function sync_level( int $merchant_id, ?Store $store = null ): string {
		$store = $store ?? Store::default();
		$level = self::level_for( $merchant_id, $store );
		MerchantService::set_entitlement( $merchant_id, 'seller_level', $level, $store );
		foreach ( self::level_caps( $level ) as $key => $value ) {
			MerchantService::set_entitlement( $merchant_id, $key, (string) $value, $store );
		}
		return $level;
	}

	/** Admin approves the merchant: mark docs verified, transition, recompute level. */
	public static function approve_documents( int $merchant_id, ?Store $store = null ): array {
		$store = $store ?? Store::default();
		$row = self::merchant_row( $merchant_id, $store );
		if ( null === $row ) {
			Error::throw_( 'MERCHANT_NOT_FOUND', 'verification', Error::text( 'MERCHANT_NOT_FOUND' ), array( 'merchant_id' => $merchant_id ) );
		}
		$transition = self::apply_transition( $row, 'verified', 'admin', array(), $store );
		$level = self::sync_level( $merchant_id, $store );
		return array( 'transition' => $transition, 'level' => $level );
	}

	/** Upload a verification document file (multipart) and store it as a pending doc row. */
	public static function document_upload( WP_REST_Request $request ): array {
		$merchant_id   = (int) $request->get_param( 'merchant_id' );
		$document_type = sanitize_key( (string) $request->get_param( 'document_type' ) );
		$row           = self::merchant_row( $merchant_id );
		if ( null === $row ) {
			Error::throw_( 'MERCHANT_NOT_FOUND', 'verification', Error::text( 'MERCHANT_NOT_FOUND' ), array( 'merchant_id' => $merchant_id ) );
		}
		if ( $merchant_id !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			Error::throw_( 'FORBIDDEN_NOT_OWNER', 'core', Error::text( 'FORBIDDEN_NOT_OWNER' ), array( 'merchant_id' => $merchant_id ) );
		}
		if ( ! in_array( $document_type, array( 'national_id', 'trade_license', 'business_registration' ), true ) ) {
			throw Error::validation( array( 'document_type' ), 'verification' );
		}
		$files = $request->get_file_params();
		$file  = $files['file'] ?? null;
		if ( ! is_array( $file ) || (int) ( $file['error'] ?? 4 ) !== UPLOAD_ERR_OK ) {
			throw Error::validation( array( 'file' ), 'verification' );
		}
		$storage = self::store_document_file( (string) ( $file['tmp_name'] ?? '' ) );
		return array( 'data' => self::upload_document( $merchant_id, $document_type, $storage ) );
	}

	/** Insert a pending verification document (service-level). */
	public static function upload_document( int $merchant_id, string $document_type, string $storage_key, ?Store $store = null ): array {
		$store = $store ?? Store::default();
		$now   = gmdate( 'Y-m-d H:i:s' );
		$store->insert( 'tb_verification_documents', array(
			'merchant_id'   => $merchant_id,
			'document_type' => $document_type,
			'storage_key'   => $storage_key,
			'status'        => 'pending',
			'created_at'    => $now,
			'updated_at'    => $now,
		) );
		return array( 'document_id' => (int) $store->last_insert_id(), 'merchant_id' => $merchant_id, 'document_type' => $document_type, 'status' => 'pending' );
	}

	private static function store_document_file( string $tmp_name ): string {
		$storage_key = bin2hex( random_bytes( 16 ) );
		$dirs        = function_exists( 'wp_upload_dir' ) ? (array) wp_upload_dir() : array( 'basedir' => ABSPATH . 'wp-content/uploads' );
		$target      = rtrim( (string) ( $dirs['basedir'] ?? ABSPATH . 'wp-content/uploads' ), '/' ) . '/trade-media';
		if ( ! is_dir( $target ) ) {
			@mkdir( $target, 0755, true );
		}
		@copy( $tmp_name, $target . '/' . $storage_key );
		return $storage_key;
	}

public static function transition(WP_REST_Request $request): array {
		$merchant_id=(int)$request->get_param('merchant_id'); $row=self::merchant_row($merchant_id); if(null===$row)Error::throw_('MERCHANT_NOT_FOUND','verification',Error::text('MERCHANT_NOT_FOUND'),array('merchant_id'=>$merchant_id)); $payload=$request->get_json_params()?:array(); return array('data'=>self::apply_transition($row,(string)($payload['to']??''),'admin',array('reason'=>(string)($payload['reason']??''))));
	}
}
