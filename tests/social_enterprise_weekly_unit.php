<?php
declare(strict_types=1);
$checks=0;
function sew(bool $ok,string $message):void{global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
$root=dirname(__DIR__);
$html=(string)file_get_contents($root.'/app/content/from-impact-evidence-to-economic-demand.html');
sew(strlen($html)>8000,'Substantive article source.');
sew(substr_count($html,'<h2>')>=7,'Structured analysis.');
foreach([
 'https://www.weforum.org/publications/the-impact-economy-turning-disclosure-into-demand',
 'https://socialfinance.org/news/social-finance-impact-first-fund-announces-two-new-investments-advancing-economic-opportunity-for-people-with-disabilities-and-americas-farmers/',
 'https://socialinnovationexchange.org/'
]as$url)sew(str_contains($html,$url),'Reviewed primary source retained: '.$url);
sew(!preg_match('/\[(?:VERIFY|ILLUSTRATIVE)\]/i',$html),'No unresolved editorial markers.');
$data=json_decode((string)file_get_contents($root.'/app/content/social-enterprise-weekly-20261106.json'),true,64,JSON_THROW_ON_ERROR);
sew(($data['release']??'')==='social-enterprise-weekly-20261106','Expected release identity.');
sew(($data['status']??'')==='prepared_not_provider_published','No fabricated provider publication claim.');
$expected=['linkedin','facebook','instagram','x','youtube','tiktok','threads','telegram','discord','signal'];
sew(array_keys($data['variants']??[])===$expected,'All ten configured channel variants prepared.');
foreach($expected as$slug){
 $text=(string)$data['variants'][$slug];
 sew(trim($text)!==''&&str_contains($text,$data['canonical_url']),$slug.' variant retains canonical article URL.');
}
sew(mb_strlen((string)$data['variants']['x'])<=280,'X copy respects 280-character limit.');
$script=(string)file_get_contents($root.'/bin/publish-social-enterprise-weekly-20261106.php');
sew(str_contains($script,"'published',0,NOW()"),'Article is published without homepage selection.');
sew(str_contains($script,'Existing weekly article preserved'),'Release is idempotent and preserves Writer edits.');
sew(!str_contains($script,'publishing_schedule(')&&!str_contains($script,'email_outbox'),'Release cannot bypass social or email approval.');
echo 'PASS: '.$checks." weekly research article and social preparation assertions.\n";
