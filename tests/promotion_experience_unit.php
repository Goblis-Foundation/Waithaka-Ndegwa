<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
require dirname(__DIR__).'/app/publishing_desk_policy.php';
require dirname(__DIR__).'/app/promotion_experience.php';
function e(string $s):string{return htmlspecialchars($s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function url(string $s=''):string{return 'https://www.example.org'.$s;}
function asset(string $s):string{return '/assets/'.$s;}
function csrf_field():string{return '<input type="hidden" name="csrf" value="fixture-csrf">';}
$n=0;function px(bool $ok,string $why):void{global $n;$n++;if(!$ok)throw new RuntimeException($why);}
function render_promote(array $values=[],bool $received=false,string $error=''):string{$nonce=str_repeat('a',32);ob_start();require dirname(__DIR__).'/views/desk/promote.php';return (string)ob_get_clean();}
function markup(string $html):DOMXPath{$doc=new DOMDocument();$old=libxml_use_internal_errors(true);$doc->loadHTML('<?xml encoding="utf-8" ?>'.$html,LIBXML_NONET);libxml_clear_errors();libxml_use_internal_errors($old);return new DOMXPath($doc);}
$html=render_promote(['package'=>'three_weekly','organisation'=>'Business <script>bad()</script>','client_email'=>'client@example.org']);$dom=markup($html);
px($dom->query('//*[@data-promote-step]')->length===5,'Five real form steps, not a static journey diagram.');
px($dom->query('//*[@data-promote-step and @hidden]')->length===0,'No-JavaScript fallback exposes all fields.');
px($dom->query('//form[@method="post" and @enctype="multipart/form-data"]')->length===1,'One protected native form remains authoritative.');
px($dom->query('//input[@name="csrf"]')->length===1&&$dom->query('//input[@name="form_nonce"]')->length===1,'CSRF and single-use request nonce retained.');
px($dom->query('//input[@name="package" and @type="radio"]')->length===count(desk_packages()),'Packages are interactive native choices using existing prices.');
px($dom->query('//input[@name="package" and @value="three_weekly" and @checked]')->length===1,'Selected package persists after a rejected submission.');
foreach(promote_groups()as$fields)foreach($fields as$key=>$spec){$nodes=$dom->query('//*[@name="'.$key.'"]');px($nodes->length===1,'Exactly one saved input for '.$key);$node=$nodes->item(0);px($node->getAttribute('maxlength')===(string)$spec[2],'Server-aligned text limit for '.$key);px($dom->query('//label[@for="'.$node->getAttribute('id').'"]')->length===1,'Explicit field label for '.$key);}
px(str_contains($html,'&lt;script&gt;bad()&lt;/script&gt;')&&!str_contains($html,'<script>bad()'),'Saved inputs are escaped.');
px(str_contains($html,'28 days')&&str_contains($html,'No payment at this stage'),'Scope and financial boundaries stay explicit.');
px(!str_contains($html,'localStorage')&&!str_contains($html,'sessionStorage'),'No implicit browser storage of contact details.');
$success=render_promote([],true);px(!str_contains($success,'data-promote-form')&&str_contains($success,'Your brief has been saved.'),'Success does not leave a duplicate submission form.');
$error=render_promote(['package'=>'weekly','authority_ack'=>'1'],false,'Server review required');px(str_contains($error,'data-promote-server-error')&&str_contains($error,'Server review required'),'Server error remains visible and recoverable.');
$r=['id'=>str_repeat('a',32),'state'=>'submitted','fields'=>['title'=>'Fixture'],'creatives'=>[],'client_approval'=>null,'owner_approval'=>null,'public'=>null,'public_visible'=>false];$post=['title'=>'Fixture'];
$progress=promote_client_progress($r,$post,[],[]);px($progress['next']['title']==='Your brief is with the editor','Fresh submission does not claim review/payment completion.');
$r['order']=str_repeat('b',32);$order=['id'=>$r['order'],'record'=>$r['id'],'state'=>'quoted','quote'=>['hash'=>'exact'],'accepted_hash'=>null];
px(promote_client_progress($r,$post,[$order],[])['next']['title']==='Review your quotation','Existing quote exposes the correct next action.');
$order['accepted_hash']='exact';$order['state']='payment_unconfirmed';$progress=promote_client_progress($r,$post,[$order],[]);px($progress['next']['title']==='Check payment arrangements'&&str_contains($progress['steps'][2]['status'],'Not yet verified'),'Uncertain checkout is never treated as paid.');
$order['state']='paid';$r['review_digest']=desk_digest($r,$post);px(promote_client_progress($r,$post,[$order],[])['next']['title']==='Your factual review is ready','Client approval is requested only for an exact review-ready version.');
$r['client_approval']=$r['review_digest'];px(promote_client_progress($r,$post,[$order],[])['next']['title']==='Awaiting final editorial approval','Client factual approval does not publish.');
$r['owner_approval']=$r['review_digest'];px(promote_client_progress($r,$post,[$order],[])['next']['title']==='Awaiting eligible publication','Final approval does not claim a live article.');
$r['public_visible']=true;$r['public']=['digest'=>'public'];$r['state']='published';$jobs=[['status'=>'published','external_url'=>'https://example.org/post'],['status'=>'scheduled','external_url'=>''],['status'=>'published','external_url'=>'']];
$progress=promote_client_progress($r,$post,[$order],$jobs);px(str_contains($progress['steps'][5]['status'],'1 of 3'),'Only published jobs with actual links count as evidence.');
$order['paused']=true;px(promote_client_progress($r,$post,[$order],$jobs)['blocked'],'Paused order cannot invite normal progression.');
$order['record']=str_repeat('f',32);px(promote_client_progress($r,$post,[$order],[])['steps'][2]['status']==='Not requested yet','Foreign order never establishes this client payment state.');
$post['title']='Changed after review';px(promote_client_progress($r,$post,[],[])['steps'][3]['status']==='Awaiting a review-ready version','Material changes do not retain stale client approval.');
$js=file_get_contents(dirname(__DIR__).'/assets/js/promotion-experience.js');px(!preg_match('/localStorage|sessionStorage|\bfetch\s*\(|XMLHttpRequest/',$js),'Interactive preview makes no storage or network calls.');px(str_contains($js,'textContent')&&!str_contains($js,'innerHTML'),'Review text never interprets client HTML.');px(str_contains($js,"window.addEventListener('pageshow'")&&str_contains($js,'submitting'),'Duplicate clicks and back-forward-cache recovery are handled.');
echo 'PASS: '.$n." promotion form, progressive enhancement, scope, privacy and evidence-led client-journey checks.\n";
