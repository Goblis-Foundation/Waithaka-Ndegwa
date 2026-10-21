<link rel="stylesheet" href="<?=asset('css/publishing.css')?>">
<main class="write-main pub-main"><div class="writer-head"><div><span class="writer-kicker">Connected account report</span><h1><?=e($writeTitle)?></h1><p class="pub-lead"><?=e($connection['display_name'])?> · <?=e($connection['selected_resource'])?></p></div><a class="wbtn" href="<?=url('/write/connections')?>">Back to connections</a></div>
<section class="pub-card">
<?php if($connection['service']==='google_search_console'):?><h2>Search performance by page</h2><p class="pub-help">The last 28 complete days ending three days ago. These are Google Search figures for the selected property, not native social reach.</p><div class="pub-table-wrap"><table class="pub-table"><thead><tr><th>Page</th><th>Clicks</th><th>Impressions</th><th>CTR</th><th>Position</th></tr></thead><tbody><?php foreach($report['rows']??[] as $row):?><tr><td><?=e(implode(' · ',$row['keys']??[]))?></td><td><?=e((string)$row['clicks'])?></td><td><?=e((string)$row['impressions'])?></td><td><?=number_format((float)$row['ctr']*100,1)?>%</td><td><?=number_format((float)$row['position'],1)?></td></tr><?php endforeach;?></tbody></table></div><?php if(empty($report['rows'])):?><p>No rows were returned for this period. This does not mean that the connection failed.</p><?php endif;?>
<?php elseif($connection['service']==='google_analytics'):?><h2>Website readership by channel</h2><p class="pub-help">Last 28 days through yesterday. Collection depends on the GA4 tag, consent and the selected property’s settings.</p><div class="pub-table-wrap"><table class="pub-table"><thead><tr><th>Traffic channel</th><th>Active users</th><th>Sessions</th><th>Page / screen views</th></tr></thead><tbody><?php foreach($report['rows']??[] as $row):?><tr><td><?=e($row['dimensionValues'][0]['value']??'')?></td><?php foreach($row['metricValues']??[] as $metric):?><td><?=e($metric['value']??'')?></td><?php endforeach;?></tr><?php endforeach;?></tbody></table></div><?php if(empty($report['rows'])):?><p>No readership rows were returned for this period.</p><?php endif;?>
<?php elseif($connection['service']==='tiktok_read'):?>
  <h2>TikTok profile and public videos</h2>
  <p class="pub-help">Current profile and video totals from TikTok for this confirmed account. These figures are a snapshot, with up to 20 recent public videos. They do not describe a fixed reporting period or measure growth.</p>
  <?php $account=$report['account']??[];$summary=$report['summary']??[];?>
  <h3><?=e($account['display_name']??$connection['display_name'])?></h3>
  <?php if(!empty($account['profile_url'])):?><p><a href="<?=e($account['profile_url'])?>" target="_blank" rel="noopener noreferrer">Open connected TikTok profile ↗</a></p><?php endif;?>
  <dl class="pub-provider">
    <?php foreach(['follower_count'=>'Followers','following_count'=>'Following','likes_count'=>'Profile likes','video_count'=>'Public videos'] as $key=>$label):?>
      <dt><?=e($label)?></dt><dd><?=e((string)($summary[$key]??'Not returned'))?></dd>
    <?php endforeach;?>
  </dl>
  <p class="pub-help">Read at <?=e($report['observed_at']??'Not returned')?>. Missing figures are shown as “Not returned”. The account’s professional label and any verified badge do not establish publishing permissions.</p>
  <div class="pub-table-wrap"><table class="pub-table"><thead><tr><th>Video</th><th>Published (UTC)</th><th>Views</th><th>Likes</th><th>Comments</th><th>Shares</th></tr></thead><tbody>
  <?php foreach($report['rows']??[] as $row):?>
    <tr>
      <td><?php $videoTitle=($row['title']??'')?:'Untitled video';if(!empty($row['share_url'])):?><a href="<?=e($row['share_url'])?>" target="_blank" rel="noopener noreferrer"><?=e($videoTitle)?> ↗</a><?php else:?><?=e($videoTitle)?><?php endif;?></td>
      <td><?php if(!empty($row['create_time'])):?><time datetime="<?=e(gmdate('c',(int)$row['create_time']))?>"><?=e(gmdate('j M Y',(int)$row['create_time']))?></time><?php else:?>Not returned<?php endif;?></td>
      <?php foreach(['view_count','like_count','comment_count','share_count'] as $key):?><td><?=e((string)($row[$key]??'Not returned'))?></td><?php endforeach;?>
    </tr>
  <?php endforeach;?>
  </tbody></table></div>
  <?php if(empty($report['rows'])):?><p>TikTok returned no public videos for this account. Private posts are not included in this report.</p><?php endif;?>
  <?php if(!empty($report['has_more'])):?><p class="pub-help">More public videos are available on TikTok. This report shows the latest page returned by the API.</p><?php endif;?>
<?php else:?><h2>YouTube channel overview</h2><p class="pub-help">Current channel totals from the YouTube Data API. These are not a period-specific YouTube Analytics report.</p><?php foreach($report['items']??[] as $item):?><h3><?=e($item['snippet']['title']??$item['id'])?></h3><dl class="pub-provider"><dt>Videos</dt><dd><?=e($item['statistics']['videoCount']??'Not returned')?></dd><dt>Channel views</dt><dd><?=e($item['statistics']['viewCount']??'Not returned')?></dd><dt>Subscribers</dt><dd><?=!empty($item['statistics']['hiddenSubscriberCount'])?'Hidden':e($item['statistics']['subscriberCount']??'Not returned')?></dd></dl><?php endforeach;?><?php if(empty($report['items'])):?><p>The selected channel was not returned. Reconnect and verify the destination.</p><?php endif;?><?php endif;?>
</section></main>
