<?php
declare( strict_types=1 );

namespace Trade\Telegram;

use Trade\AI\Service as AIService;
use Trade\Core\Db;
use Trade\Core\Store;

/** Telegram onboarding gateway: language → role → contextual Mini App. */
final class Conversation {
	public const TABLE = 'tb_bot_chats';
	private const DEFAULT_STATE = 'main';
	private const ANCHORS = array('🤖 AI Assistant'=>'assistant','❓ Help'=>'help','ℹ️ Status'=>'status','🌐 Language'=>'language','🏠 Home'=>'menu');
	private const COMMANDS = array('start','menu','help','assistant','status','cancel','language','app');
	public static function mini_app_url(): string { $opt=(string)get_option('trade_mini_app_url',''); return ''!==$opt?$opt:home_url('/wp-content/plugins/Trade/mini-app/'); }
	public static function step(int $chat_id,string $text,?Store $store=null,?Bot $bot=null,?int $from_id=null): array {
		$store=$store??Store::default(); $bot=$bot??new Bot(); $from_id??=$chat_id;
		$state=self::DEFAULT_STATE; $data=array();
		try { self::ensure_table(); [$state,$data]=self::load($store,$chat_id); } catch (\Throwable $e) { self::log_failure('load',$e,$chat_id); }
		try { $actions=self::dispatch($store,$state,$data,trim($text),$from_id); } catch (\Throwable $e) {
			self::log_failure('dispatch',$e,$chat_id);
			$actions=array('state'=>'main','data'=>$data,'replies'=>array('Sorry, something went wrong. Please try /start again.'));
		}
		try { self::save($store,$chat_id,$actions['state'],$actions['data']); } catch (\Throwable $e) { self::log_failure('save',$e,$chat_id); }
		$sent=[]; $sends=$bot->token_set(); $n=count($actions['replies']); $app_idx=$n>0&&!empty($actions['app_button'])?0:null; $menu_idx=$n>0&&!empty($actions['buttons'])?$n-1:null;
		foreach($actions['replies'] as $i=>$reply){$markup=null; if($sends&&$i===$app_idx)$markup=self::app_markup(); elseif($sends&&$i===$menu_idx)$markup=self::markup($actions['buttons']); if($sends)$bot->sendMessage($chat_id,$reply,null!==$markup?array('reply_markup'=>$markup):array()); $sent[]=$reply;}
		return $sent;
	}
	private static function ensure_table(): void {
		global $wpdb;
		if(!isset($wpdb))return;
		$wpdb->query("CREATE TABLE IF NOT EXISTS `tb_bot_chats` (\n  `chat_id` bigint(20) NOT NULL,\n  `state` varchar(40) NOT NULL DEFAULT 'main',\n  `data` longtext NOT NULL,\n  `updated_at` datetime NOT NULL,\n  PRIMARY KEY (`chat_id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci");
	}
	private static function log_failure(string $stage,\Throwable $e,int $chat_id): void { $context=array('stage'=>$stage,'chat_id'=>$chat_id,'type'=>get_class($e),'message'=>$e->getMessage()); update_option('trade_telegram_last_webhook_error',$context,false); if(defined('WP_DEBUG')&&WP_DEBUG)error_log('Trade Telegram webhook failure: '.wp_json_encode($context,JSON_UNESCAPED_UNICODE)); }
	private static function dispatch(Store $store,string $state,array $data,string $input,int $from_id): array {
		if(''!==$input&&'/'===$input[0])return self::command($store,strtolower(substr($input,1)),$data,$from_id);
		if('language'===$state)return self::set_language($store,$data,self::normalize_language($input),$from_id);
		if('role'===$state)return self::set_role($data,strtolower($input));
		if('assistant'===$state)return array('state'=>'assistant','data'=>$data,'replies'=>array('I can help with that. Open the Mini App for the guided workflow.'),'app_button'=>true);
		foreach(self::ANCHORS as $label=>$cmd)if(0===strcasecmp($label,$input))return self::command($store,$cmd,$data,$from_id); return self::command($store,'help',$data,$from_id);
	}
	private static function command(Store $store,string $cmd,array $data,int $from_id): array {
		if(!in_array($cmd,self::COMMANDS,true))$cmd='help';
		switch($cmd){
			case 'start': if(!empty($data['completed'])&&!empty($data['role'])&&!empty($data['language']))return self::ready($data); return array('state'=>'language','data'=>array(),'replies'=>array("👋 Welcome!\n\nFirst, choose your language."),'buttons'=>array('English (en)','አማርኛ (am)'));
			case 'app': return self::ready($data);
			case 'menu': return array('state'=>'main','data'=>$data,'replies'=>array('Main menu:'),'buttons'=>array_keys(self::ANCHORS));
			case 'language': return array('state'=>'language','data'=>$data,'replies'=>array('Choose your language:'),'buttons'=>array('English (en)','አማርኛ (am)'));
			case 'assistant': return array('state'=>'assistant','data'=>$data,'replies'=>array('🤖 Tell me what you need. I will help you find the right action.'));
			case 'status': return array('state'=>'main','data'=>$data,'replies'=>array("Trade Bot\nSchema: ".Db::VERSION."\nAI assistant: ".(AIService::ENABLED?'on':'off')));
			case 'help': return array('state'=>'main','data'=>$data,'replies'=>array("/start — onboarding\n/app — open the Mini App\n/menu — main menu\n/language — change language\n/assistant — AI help\n/cancel — menu"));
			case 'cancel': default: return array('state'=>'main','data'=>$data,'replies'=>array('Back to the menu.'),'buttons'=>array_keys(self::ANCHORS));
		}
	}
	private static function set_language(Store $store,array $data,string $code,int $from_id): array {
		$lang=$store->get_row('tb_languages','code = %s AND enabled = 1',array($code)); if(null===$lang)return array('state'=>'language','data'=>$data,'replies'=>array('Please choose a supported language.'),'buttons'=>array('English (en)','አማርኛ (am)'));
		$data['language']=$code; if($from_id>0)$store->update('tb_identity',array('language'=>$code),array('telegram_user_id'=>(string)$from_id)); return array('state'=>'role','data'=>$data,'replies'=>array('Great. Now, are you a merchant or a customer?'),'buttons'=>array('🛍 Merchant','🧍 Customer'));
	}
	private static function set_role(array $data,string $low): array {
		$role=null; if(str_contains($low,'merchant')||str_contains($low,'🛍')||'m'===$low)$role='merchant'; elseif(str_contains($low,'customer')||str_contains($low,'🧍')||'c'===$low)$role='customer';
		if(null===$role)return array('state'=>'role','data'=>$data,'replies'=>array('Please choose merchant or customer.'),'buttons'=>array('🛍 Merchant','🧍 Customer')); $data['role']=$role; $data['completed']=true; $data['completed_at']=gmdate('c'); return self::ready($data);
	}
	private static function ready(array $data): array { return array('state'=>'main','data'=>$data,'replies'=>array('All set. I remember your language and role, so the Mini App will open directly in the right place.'),'app_button'=>true); }
	private static function normalize_language(string $input): string { $input=strtolower(trim($input)); if(str_contains($input,'am')||str_contains($input,'አማርኛ'))return 'am'; return 'en'===$input||str_contains($input,'english')?'en':$input; }
	private static function load(Store $store,int $chat_id): array { $row=$store->get_row(self::TABLE,'chat_id = %d',array($chat_id)); if(null===$row)return array(self::DEFAULT_STATE,array()); $data=json_decode((string)($row['data']??''),true); return array((string)($row['state']??self::DEFAULT_STATE),is_array($data)?$data:array()); }
	private static function save(Store $store,int $chat_id,string $state,array $data): void { $fields=array('state'=>$state,'data'=>wp_json_encode($data,JSON_UNESCAPED_UNICODE),'updated_at'=>gmdate('Y-m-d H:i:s')); if(null===$store->get_row(self::TABLE,'chat_id = %d',array($chat_id)))$store->insert(self::TABLE,array_merge(array('chat_id'=>$chat_id),$fields)); else $store->update(self::TABLE,$fields,array('chat_id'=>$chat_id)); }
	private static function markup(array $buttons): array { $keyboard=[]; foreach($buttons as $label)$keyboard[]=array(array('text'=>$label)); return array('keyboard'=>$keyboard,'resize_keyboard'=>true,'one_time_keyboard'=>false); }
	private static function app_markup(): array { return array('inline_keyboard'=>array(array(array('text'=>'🚀 Open Mini App','web_app'=>array('url'=>self::mini_app_url()))))); }
}
