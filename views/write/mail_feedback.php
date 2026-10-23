<link rel="stylesheet" href="<?=asset('css/media-library.css')?>">
<main class="wk-media"><p><a href="<?=url('/write/operations')?>">← Operations</a> · <a href="<?=url('/write/newsletter')?>">Newsletter campaigns</a></p><h1>Mail delivery feedback</h1>
<p>Track provider delivery confirmations, bounces and complaints. A send acknowledgment means the provider accepted the email; a delivery event means the receiving mail server accepted it.</p>
<?php if($error):?><p role="alert" class="notice error"><?=e($error)?></p><?php endif;?>
<?php foreach(flashes()as$message):?><p role="status" class="notice <?=e($message['type'])?>"><?=e($message['message'])?></p><?php endforeach;?>
<section class="wk-media-upload"><h2>Feedback connection</h2>
<p><?= $configured?'An SNS topic is configured.':'Configure SES_SNS_TOPIC_ARN for the website’s SES event topic before enabling this connection.'?></p>
<p>Subscription: <strong><?=e($subscription['status']??'No confirmation received')?></strong>. Active address suppressions: <strong><?=$suppressed?></strong>.</p>
<?php if(($subscription['status']??'')==='pending'):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="confirm"><p>A signed confirmation has arrived for the configured topic. Confirm it to receive this website’s SES delivery events.</p><button class="btn" type="submit">Confirm configured SNS subscription</button></form><?php endif;?>
<p>Hard bounces and complaints prevent future sends to the affected address. They also suppress newsletter subscriptions. The original delivery record remains available.</p>
</section>
<section class="wk-media-upload"><h2>Recent events</h2>
<?php if(!$events):?><p>No authenticated provider events have been received.</p><?php else:?><div style="overflow-x:auto"><table><thead><tr><th>Event</th><th>Result</th><th>Outbox reference</th><th>Received (UTC)</th></tr></thead><tbody>
<?php foreach($events as$event):?><tr><td><?=e($event['event_type'])?></td><td><?=e(str_replace('_',' ',$event['action']))?></td><td><?=$event['outbox_id']?(int)$event['outbox_id']:'—'?></td><td><?=e($event['received_at'])?></td></tr><?php endforeach;?>
</tbody></table></div><?php endif;?>
</section></main>
