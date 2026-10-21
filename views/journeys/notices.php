<?php if(!empty($error)):?><div class="journey-notice journey-notice-error" role="alert"><?=e($error)?></div><?php endif;?>
<?php foreach(flashes() as $notice):?><div class="journey-notice <?=$notice['type']==='error'?'journey-notice-error':''?>" role="status"><?=e($notice['message'])?></div><?php endforeach;?>
