<link rel="stylesheet" href="<?=asset('css/publishing.css')?>">
<main class="write-main pub-main">
  <header class="writer-head"><div><span class="writer-kicker">Goal 2030 · read-only evidence</span><h1>Approved impact reporting</h1><p>View the high-level state of published aggregate evidence. Only the site owner can configure source endpoints, access their tokens or initiate provider checks.</p></div><a class="wbtn" href="<?=url('/about#goals-2030')?>">Public Goal 2030</a></header>
  <section class="pub-card"><h2>Source publication status</h2><p>A source connection and a formally approved monthly report are distinct. A missing or withdrawn report is never counted as zero or as progress.</p>
    <ul class="pub-list">
    <?php foreach(impact_sources() as $source=>$label):$snapshot=impact_snapshot($source);$data=$snapshot['data']??null;$approved=($snapshot['status']??'')==='available'&&is_array($data)&&($data['approved_for_publication']??false)===true;?>
      <li><strong><?=e($label)?></strong> · <?= $approved?'Approved source aggregate recorded':(($snapshot['status']??'')==='withheld'?'Publication withdrawn': 'Current approved report unavailable or pending') ?></li>
    <?php endforeach;?></ul>
    <p>Reporting dates, exact definitions and available publicly approved figures remain on the public Goal 2030 section. Private source URLs, bearer credentials and source records are not displayed here.</p>
  </section>
</main>
