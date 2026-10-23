<?php if(isset($pagination)&&$pagination['pages']>1):?>
<nav class="archive-pagination" aria-label="Archive pages">
  <p>Showing <?=e((string)$pagination['start'])?>–<?=e((string)$pagination['end'])?> of <?=e((string)$pagination['total'])?> · Page <?=e((string)$pagination['page'])?> of <?=e((string)$pagination['pages'])?></p>
  <div>
    <?php if($pagination['page']>2):?><a class="button button-secondary button-small" href="<?=e(url($pagination['first']))?>">First page</a><?php endif;?>
    <?php if($pagination['previous']):?><a class="button button-secondary button-small" rel="prev" href="<?=e(url($pagination['previous']))?>">← Newer</a><?php endif;?>
    <?php if($pagination['next']):?><a class="button button-secondary button-small" rel="next" href="<?=e(url($pagination['next']))?>">Older →</a><?php endif;?>
  </div>
</nav>
<?php endif;?>
