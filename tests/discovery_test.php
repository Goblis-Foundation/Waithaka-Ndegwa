<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/functions.php';
require dirname(__DIR__).'/app/providers/http.php';
require dirname(__DIR__).'/app/discovery.php';
$CONFIG=['app_env'=>'testing','base_url'=>'https://www.waithakandegwa.com'];$checks=0;
function discovery_check(bool $condition,string $message): void {if(!$condition)throw new RuntimeException('FAIL: '.$message);$GLOBALS['checks']++;}
function discovery_rejects(callable $action,string $message): void {try{$action();}catch(RuntimeException){discovery_check(true,$message);return;}throw new RuntimeException('FAIL: '.$message);}

discovery_check(discovery_query('  Better   questions  ')==='Better questions','Search whitespace is normalized.');
foreach([[],null,false,24,str_repeat('é',201),"bad\nterm", "bad\0term", "\xff"]as$value)discovery_rejects(fn()=>discovery_query($value),'Malformed/oversized search input is rejected.');
discovery_check(discovery_query(str_repeat('é',200))===str_repeat('é',200),'Unicode length is measured in characters.');
discovery_check(discovery_document_matches(['title'=>'A 100% lesson','body'=>'<safe> ÉCOLE'], '100%'),'Percent signs are literal search characters.');
discovery_check(!discovery_document_matches(['body'=>'A learning story'],'%'),'A SQL wildcard cannot become a catch-all.');
discovery_check(discovery_document_matches(['body'=>'ÉCOLE learning'],'école'),'Matching ignores Unicode letter case.');
discovery_check(!discovery_document_matches(['body'=>'anything'],''),'Empty search does not enumerate every document.');
foreach(['/my-thoughts/hello','/startup-journeys/season-1','/resources/checklist','/watch/lesson','/founders','/']as$path)discovery_check(discovery_canonical_url(url($path))===url($path),'Canonical public paths are accepted.');
foreach([
 'https://www.waithakandegwa.co.ke/my-thoughts/hello','https://evil.invalid/my-thoughts/hello','http://www.waithakandegwa.com/my-thoughts/hello',
 'https://user@www.waithakandegwa.com/my-thoughts/hello','https://www.waithakandegwa.com:444/my-thoughts/hello',
 url('/newsletter/confirm?token=private'),url('/newsletter/unsubscribe'),url('/write/discovery'),url('/search?q=private'),
 url('/my-thoughts/hello?preview=1'),url('/my-thoughts/hello#fragment'),url('/my-thoughts/%2e%2e'),url('/resources/private/download'),
 url('/my-thoughts//hello'),url('/my-thoughts/hello')."\r\n",'//www.waithakandegwa.com/my-thoughts/hello'
]as$value)discovery_check(discovery_canonical_url($value)===null,'Foreign, private, capability and malformed URLs fail closed.');
discovery_check(discovery_public_timestamp('2026-02-28 12:00:00'),'A real public timestamp is valid.');
foreach(['2026-02-30 12:00:00','2026-02-28','2026-02-28T12:00:00Z','']as$value)discovery_check(!discovery_public_timestamp($value),'Malformed and normalized-away dates are rejected.');

$period=discovery_periods(28,new DateTimeImmutable('2026-03-10 00:30:00',new DateTimeZone('UTC')));
discovery_check($period['end']==='2026-03-06','Reporting dates use Pacific time rather than UTC/Nairobi midnight.');
foreach([28,90]as$days){$period=discovery_periods($days,new DateTimeImmutable('2026-10-21',new DateTimeZone('UTC')));
 discovery_check((new DateTimeImmutable($period['start']))->diff(new DateTimeImmutable($period['end']))->days===$days-1,'Current period includes exactly the requested days.');
 discovery_check((new DateTimeImmutable($period['previous_start']))->diff(new DateTimeImmutable($period['previous_end']))->days===$days-1,'Previous period has equal length.');
 discovery_check((new DateTimeImmutable($period['previous_end']))->modify('+1 day')->format('Y-m-d')===$period['start'],'Comparison periods are contiguous without overlap.');}
discovery_rejects(fn()=>discovery_periods(365),'Unsupported report range is rejected.');
$metric=['clicks'=>7,'impressions'=>70,'ctr'=>0.1,'position'=>4.2];
$current=discovery_gsc_rows(['rows'=>[['keys'=>['hello']]+$metric]],'query',100);
discovery_check($current[0]['clicks']===7.0,'Valid documented metrics are normalized.');
discovery_check(discovery_gsc_rows([],null,1)===[],'An empty API report stays explicitly empty.');
$comparison=discovery_gsc_comparison($current,[['key'=>'previous-only']+$metric]);
discovery_check(count($comparison)===2 && $comparison[0]['previous']===null && $comparison[1]['current']===null,'Missing query rows remain unknown on either side.');
foreach([
 ['rows'=>'oops'],['error'=>['message'=>'provider secret']],['rows'=>[['keys'=>[]]+$metric]],
 ['rows'=>[['keys'=>['a','b']]+$metric]],['rows'=>[['keys'=>['a'],'clicks'=>-1,'impressions'=>70,'ctr'=>0.1,'position'=>2]]],
 ['rows'=>[['keys'=>['a'],'clicks'=>1,'impressions'=>70,'ctr'=>1.2,'position'=>2]]],
 ['rows'=>[['keys'=>['a'],'clicks'=>'7','impressions'=>70,'ctr'=>0.1,'position'=>2]]],
 ['rows'=>[['keys'=>['a']]+$metric,['keys'=>['a']]+$metric]]
]as$response)discovery_rejects(fn()=>discovery_gsc_rows($response,'query',100),'Malformed provider rows cannot fabricate a successful report.');
discovery_rejects(fn()=>discovery_gsc_rows(['rows'=>[['keys'=>['a']]+$metric,['keys'=>['b']]+$metric]],'query',1),'Provider results cannot exceed the requested bound.');
discovery_check(discovery_indexnow_result(200,1)['status']==='accepted','HTTP200 is a received notification.');
discovery_check(discovery_indexnow_result(202,1)['status']==='verification_pending','HTTP202 retains pending ownership verification.');
foreach([400,403,422,301,204]as$code)discovery_check(discovery_indexnow_result($code,1)['status']==='rejected','Unsupported/invalid notification receipts are not accepted.');
foreach([0,408,429,500,503]as$code)discovery_check(discovery_indexnow_result($code,1)['status']==='retry','Transient notification outcomes get delayed retries.');
discovery_check(discovery_indexnow_result(429,1,'600')['delay']===600,'Retry-After seconds are respected.');
discovery_check(discovery_indexnow_result(503,2,gmdate(DATE_RFC7231,1000+1800),1000)['delay']===1800,'Retry-After HTTP dates are respected.');
discovery_check(discovery_indexnow_result(429,1,'999999999')['delay']===86400,'Excessive retry delays are bounded.');
discovery_check(discovery_indexnow_result(0,8)['status']==='rejected','Repeated failures stop for explicit review.');
discovery_check(discovery_indexnow_result(200,999)['status']==='accepted','A real acknowledgement is retained even after many attempts.');

$original=discovery_image_attributes('/assets/images/journey/speaking.webp');
discovery_check(isset($original['width'],$original['height']) && $original['width']>0,'Existing local photography exposes real dimensions.');
if(isset($original['srcset']))foreach(explode(', ',$original['srcset'])as$candidate){[$src,$width]=explode(' ',$candidate);$path=parse_url($src,PHP_URL_PATH);discovery_check(is_file(dirname(__DIR__).$path) && (int)$width===getimagesize(dirname(__DIR__).$path)[0],'Every responsive candidate is a real file with its actual width.');}
discovery_check(discovery_image_attributes('/assets/uploads/missing.webp')===['src'=>asset('images/social-default.png')],'Missing local files have a safe visible fallback.');
foreach(['/storage/private/proof.jpg','/assets/images/../../app/config.php','data:image/png;base64,abc','javascript:alert(1)','http://evil.invalid/a.jpg']as$src)discovery_check(discovery_image_attributes($src)===['src'=>asset('images/social-default.png')],'Unsafe/private image references have no derived candidates.');
discovery_check(discovery_image_attributes('https://cdn.example.invalid/photo.webp')===['src'=>'https://cdn.example.invalid/photo.webp'],'Remote HTTPS originals are preserved without fabricated srcset/network calls.');
$CONFIG['app_env']='production';discovery_rejects(fn()=>discovery_gsc_refresh(28,1,fn()=>[]),'Production cannot accept a report fixture.');
echo 'Discovery pure checks passed: '.$checks." assertions.\n";

discovery_check(discovery_gsc_row_limit('country')===300&&discovery_gsc_row_limit('query')===100&&discovery_gsc_row_limit(null)===1,'Worldwide country reports are not truncated at 100 rows; other report limits remain bounded.');
$countryRows=[];for($i=0;$i<150;$i++)$countryRows[]=['keys'=>['fixture-'.$i]]+$metric;
discovery_check(count(discovery_gsc_rows(['rows'=>$countryRows],'country',discovery_gsc_row_limit('country')))===150,'Country reports retain valid rows beyond the previous shortlist-sized cap.');

$pageSeo=['canonical'=>'https://www.waithakandegwa.com/about','title'=>'About','description'=>'Public profile'];
$globalSchema=global_webpage_schema($pageSeo);
discovery_check($globalSchema['inLanguage']==='en'&&$globalSchema['isPartOf']['@id']==='https://www.waithakandegwa.com/#website','Public English pages share the canonical website identity worldwide.');
discovery_check(global_webpage_schema($pageSeo,true)===[]&&global_webpage_schema($pageSeo+['robots'=>'noindex,nofollow'])===[],'Private and noindex pages do not gain public page structured data.');
discovery_check(global_webpage_schema(array_replace($pageSeo,['canonical'=>'https://other.invalid/about']))===[]&&global_webpage_schema(array_replace($pageSeo,['canonical'=>'https://www.waithakandegwa.com/about?token=secret']))===[],'External and capability/query addresses are never advertised as public canonical page identities.');
