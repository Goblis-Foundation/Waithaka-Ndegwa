<?php
$chosenTopics = is_array($values['topic_ids'] ?? null) ? array_filter($values['topic_ids'], 'is_scalar') : [];
$chosenJourneys = is_array($values['journey_ids'] ?? null) ? array_filter($values['journey_ids'], 'is_scalar') : [];
?>
<fieldset class="reader-choices"><legend>What would you like to receive?</legend>
  <label class="journey-check"><input type="checkbox" name="receive_all" value="1" <?=journeys_checked($values['receive_all'] ?? '1') ? 'checked' : ''?>><span>All new writing and Startup Journeys</span></label>
  <p class="journey-help">To receive only selected topics or journeys, clear “All new writing” and make your choices below. If you choose none, no new newsletter editions will match. A matching topic or journey is enough; both are not required.</p>
  <fieldset><legend>Topics</legend><?php foreach ($choices['topics'] as $choice): ?><label class="journey-check"><input type="checkbox" name="topic_ids[]" value="<?=(int)$choice['id']?>" <?=in_array((string)$choice['id'], array_map('strval', $chosenTopics), true) ? 'checked' : ''?>><span><?=e($choice['name'])?></span></label><?php endforeach; ?></fieldset>
  <?php if ($choices['journeys']): ?><fieldset><legend>Follow a journey</legend><?php foreach ($choices['journeys'] as $choice): ?><label class="journey-check"><input type="checkbox" name="journey_ids[]" value="<?=(int)$choice['id']?>" <?=in_array((string)$choice['id'], array_map('strval', $chosenJourneys), true) ? 'checked' : ''?>><span><?=e($choice['name'])?></span></label><?php endforeach; ?></fieldset><?php endif; ?>
</fieldset>
