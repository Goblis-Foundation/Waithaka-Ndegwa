<?php if(empty($privatePage)&&$ga4!==''):$visitorPresence=visitor_statistics_payload();?>
<div hidden aria-hidden="true" style="display:none" data-visitor-statistics data-endpoint="<?=e(url('/api/visitor-statistics'))?>" data-presence-endpoint="<?=e(url('/api/visitor-presence'))?>" data-presence-csrf="<?=e(csrf_token())?>" data-statistics="<?=e(json_encode($visitorPresence,JSON_THROW_ON_ERROR|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT))?>"></div>
<script src="<?=e(asset('js/visitor-statistics.js'))?>" defer></script>
<?php endif;?>
