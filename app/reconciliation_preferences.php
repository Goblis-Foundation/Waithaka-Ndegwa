<?php
declare(strict_types=1);
function reconciliation_meeting_labels(array $cfg): array {
    if(($cfg['types']['media']['name']??null)==='Media & speaking')$cfg['types']['media']['name']='Media & interviews';return $cfg;
}
function reconciliation_preferences(): void {
    if(!in_array(app_config('app_env'),['production','testing'],true))return;
    comm_lock('config',static function(){$cfg=comm_get('config');if(!$cfg)return;$next=reconciliation_meeting_labels($cfg);if($next!==$cfg)comm_put('config',$next);});
}
