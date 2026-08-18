<?php
declare( strict_types=1 );

namespace Trade\Identity;

use Trade\Catalog\Service as CatalogService;
use Trade\Core\Rest;
use Trade\Core\Store;
use Trade\Core\Error;
use Trade\Core\Audit;
use Trade\Core\Events;
use Trade\Core\Throttle;
use Trade\Telegram\Verify;
use WP_REST_Request;

/** Identity module — Telegram initData authentication and contextual session bootstrap. */
final class Service {
	public static function routes(): void {
		Rest::register( 'auth/session', 'POST', '', array( self::class, 'login' ) );
		Rest::register( 'me', 'GET', 'tb_session', array( self::class, 'me_read' ) );
		Rest::register( 'me', 'PATCH', 'tb_session', array( self::class, 'me_update' ) );
	}

	public static function login( WP_REST_Request $request ): array {
		$params=$request->get_json_params()?:array(); $init_data=$params['init_data']??'';
		if(!is_string($init_data)||''===trim($init_data)||mb_strlen($init_data)>32768)throw Error::validation(array('init_data'),'identity');
		$tg=Verify::verify($init_data,(string)get_option('trade_telegram_bot_token',''));
		$replay=Throttle::hit('replay:'.hash('sha256',$init_data),300,1); if(!$replay['allowed'])Error::throw_('AUTH_REPLAY_DETECTED','identity',Error::text('AUTH_REPLAY_DETECTED'),array('reason'=>'initdata_reuse'));
		$rate=Throttle::hit('auth:'.$tg['user_id'],60,10); if(!$rate['allowed'])Error::throw_('RATE_LIMITED','identity',Error::text('RATE_LIMITED'),array('retry_after'=>$rate['retry_after']));
		$wp_user_id=self::find_identity($tg['user_id']); $session=Session::issue($wp_user_id);
		Audit::write('user.login','user',(string)$wp_user_id,array(),array('session_issued'=>true),array(),'telegram',(string)$wp_user_id);
		$context=self::onboarding_context($tg['user_id'],$wp_user_id);
		return array('data'=>array('session_token'=>$session['token'],'expires_at'=>gmdate('c',$session['expires_at']),'onboarding_state'=>$context['onboarding_state'],'role'=>$context['role'],'language'=>$context['language'],'launch_screen'=>$context['launch_screen'],'verification_status'=>$context['verification_status']));
	}

	/** Context already collected by Telegram bot; Mini App must not ask for it again. */
	private static function onboarding_context(int $telegram_user_id,int $wp_user_id): array {
		$store=Store::default(); $identity=$store->get_row('tb_identity','wp_user_id = %d',array($wp_user_id));
		$chat=$store->get_row('tb_bot_chats','chat_id = %d',array($telegram_user_id)); $data=$chat?json_decode((string)($chat['data']??''),true):array(); $data=is_array($data)?$data:array();
		$role=(string)($data['role']??'customer'); $language=(string)($data['language']??$identity['language']??'en');
		$completed=!empty($data['completed'])&&in_array($role,array('merchant','customer'),true)&&''!==$language;
		$merchant=$store->get_row('tb_merchants','wp_user_id = %d',array($wp_user_id)); $verification=$merchant?(string)($merchant['verification_status']??'none'):'none';
		$screen='customer_home'; if('merchant'===$role)$screen='verified'===$verification?'merchant_home':'merchant_verification';
		return array('onboarding_state'=>$completed?'complete':'incomplete','role'=>$role,'language'=>$language,'launch_screen'=>$screen,'verification_status'=>$verification);
	}

	private static function find_identity(int $telegram_user_id): int {
		$row=Store::default()->get_row('tb_identity','telegram_user_id = %s',array((string)$telegram_user_id)); if($row)return (int)$row['wp_user_id'];
		$user_id=wp_insert_user(array('user_login'=>'tgu_'.$telegram_user_id,'user_email'=>'tg'.$telegram_user_id.'@local.invalid','user_pass'=>wp_generate_password(64,false,false),'role'=>'subscriber'));
		if(is_object($user_id)&&method_exists($user_id,'get_error_code'))Error::throw_('INTERNAL_ERROR','identity',Error::text('INTERNAL_ERROR'),array('reason'=>'user_create_failed'));
		$now=gmdate('Y-m-d H:i:s'); $store=Store::default();
		$store->insert('tb_identity',array('telegram_user_id'=>(string)$telegram_user_id,'wp_user_id'=>(int)$user_id,'language'=>'en','created_at'=>$now));
		$store->insert('tb_customer_profiles',array('wp_user_id'=>(int)$user_id,'display_name'=>'','location_id'=>null,'created_at'=>$now));
		Events::emit('USER_REGISTERED',array('wp_user_id'=>(int)$user_id,'telegram_user_id'=>$telegram_user_id)); return (int)$user_id;
	}

	public static function me_read(WP_REST_Request $request): array {
		$uid=get_current_user_id(); $store=Store::default(); $identity=$store->get_row('tb_identity','wp_user_id = %d',array($uid)); $profile=$store->get_row('tb_customer_profiles','wp_user_id = %d',array($uid));
		return array('data'=>array('language'=>$identity['language']??'en','display_name'=>$profile['display_name']??'','location_id'=>isset($profile['location_id'])&&null!==$profile['location_id']?(int)$profile['location_id']:null));
	}

	public static function me_update(WP_REST_Request $request): array {
		$uid=get_current_user_id(); $params=$request->get_json_params()?:array();
		foreach(array_keys($params) as $k)if(!in_array($k,array('language','display_name','location_id'),true))throw Error::validation(array($k),'identity');
		$store=Store::default();
		if(array_key_exists('language',$params)){$lang=(string)$params['language']; if(!$store->get_row('tb_languages','code = %s AND enabled = 1',array($lang)))throw Error::validation(array('language'),'identity'); $before=$store->get_row('tb_identity','wp_user_id = %d',array($uid)); $store->update('tb_identity',array('language'=>$lang),array('wp_user_id'=>$uid)); Audit::write('identity.language','identity',(string)$uid,array('language'=>$before['language']??null),array('language'=>$lang));}
		$profile=$store->get_row('tb_customer_profiles','wp_user_id = %d',array($uid)); $set=array();
		if(array_key_exists('display_name',$params)){ $name=trim((string)$params['display_name']); if(mb_strlen($name)>100)throw Error::validation(array('display_name'),'identity'); $set['display_name']=$name; }
		if(array_key_exists('location_id',$params)){ if(null!==$params['location_id']){$lid=(int)$params['location_id']; if($lid<=0)throw Error::validation(array('location_id'),'identity'); if(!CatalogService::location_exists($lid))Error::throw_('LOCATION_NOT_FOUND','catalog',Error::text('LOCATION_NOT_FOUND'),array('location_id'=>$lid)); $set['location_id']=$lid;}else $set['location_id']=null; }
		if($set){if($profile)$store->update('tb_customer_profiles',$set,array('wp_user_id'=>$uid));else $store->insert('tb_customer_profiles',array_merge(array('wp_user_id'=>$uid,'created_at'=>gmdate('Y-m-d H:i:s')),$set)); Audit::write('profile.update','customer_profile',(string)$uid,array(),$set);}
		return self::me_read($request);
	}
}
