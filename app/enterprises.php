<?php
declare(strict_types=1);
/** Private working copies and separately approved public enterprise profiles. */
final class EnterpriseException extends RuntimeException {}
function enterprise_stages(): array {return ['idea'=>'Idea & validation','building'=>'Building','operating'=>'Operating','paused'=>'Paused','closed'=>'Closed'];}
function enterprise_owner(int $uid): void {if($uid<1||$uid!==(int)(comm_config()['owner']??0))throw new EnterpriseException('Owner access required.');}
function enterprise_id(mixed $id): string {if(!is_string($id)||!preg_match('/^[a-f0-9]{16}$/D',$id))throw new EnterpriseException('Choose an enterprise.');return $id;}
function enterprise_records(): array {return comm_records('enterprise.profile',100);}
function enterprise_owned(int $uid,string $id): array {enterprise_owner($uid);$r=comm_get('enterprise.profile.'.enterprise_id($id));if(!$r)throw new EnterpriseException('Enterprise unavailable.');return $r;}
function enterprise_targets(mixed $raw): array {
 $raw=reader_text($raw,'Targets',8000);if($raw==='')return [];$out=[];
 foreach(explode("\n",$raw)as$line){if(trim($line)==='')continue;$p=array_map('trim',explode('|',$line));
  if(count($p)!==4||!preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,4})?$/D',$p[1])||(float)$p[1]<=0)throw new EnterpriseException('Use Target name | number | unit | YYYY-MM-DD for each target.');
  $date=DateTimeImmutable::createFromFormat('!Y-m-d',$p[3]);if(!$date||$date->format('Y-m-d')!==$p[3])throw new EnterpriseException('Review each target date.');
  $out[]=['label'=>reader_text($p[0],'Target name',160,true),'value'=>$p[1],'unit'=>reader_text($p[2],'Target unit',80,true),'date'=>$p[3]];if(count($out)>20)throw new EnterpriseException('Use up to 20 targets per enterprise.');
 }return $out;
}
function enterprise_fields(array $in): array {
 $f=[];foreach(['name'=>180,'sector'=>120,'summary'=>1000,'problem'=>6000,'solution'=>6000,'customers'=>3000,'involvement'=>3000,'successes'=>8000,'challenges'=>8000,'next_steps'=>4000,'private_notes'=>12000,'consent_reference'=>500,'targets_text'=>8000]as$key=>$max)$f[$key]=reader_text($in[$key]??'',ucwords(str_replace('_',' ',$key)),$max,$key==='name');
 $f['stage']=reader_choice($in['stage']??'idea',enterprise_stages(),'enterprise stage');$f+=location_fields($in);
 $f['website']=reader_text($in['website']??'','Website',1000);
 if($f['website']!==''&&(!filter_var($f['website'],FILTER_VALIDATE_URL)||parse_url($f['website'],PHP_URL_SCHEME)!=='https'||parse_url($f['website'],PHP_URL_USER)!==null||parse_url($f['website'],PHP_URL_PASS)!==null))throw new EnterpriseException('Use a public HTTPS enterprise website, without sign-in or private tokens.');
 $date=reader_text($in['established_on']??'','Establishment date',10);if($date!==''){$d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);if(!$d||$d->format('Y-m-d')!==$date||$date>date('Y-m-d'))throw new EnterpriseException('Use the actual establishment date; planned dates belong in targets.');}$f['established_on']=$date;
 $f['targets']=enterprise_targets($f['targets_text']);$ids=$in['post_ids']??[];if(!is_array($ids)||count($ids)>50)throw new EnterpriseException('Choose up to 50 related articles.');$f['post_ids']=[];foreach($ids as$id){if(!is_scalar($id)||!preg_match('/^[1-9][0-9]{0,9}$/D',(string)$id))throw new EnterpriseException('Choose valid articles.');$f['post_ids'][]=(int)$id;}$f['post_ids']=array_values(array_unique($f['post_ids']));
 return $f;
}
/** Whitelist: private notes and consent references never reach a public projection. */
function enterprise_public_fields(array $draft): array {
 return array_intersect_key($draft,array_flip(['name','sector','summary','problem','solution','customers','involvement','successes','challenges','next_steps','stage','country','county','sub_county','region','district','city','website','established_on','targets','post_ids','logo']));
}
function enterprise_save(int $uid,?string $id,array $in,?string $logo=null): string {
 enterprise_owner($uid);$fields=enterprise_fields($in);$action=$in['action']??'draft';if(!in_array($action,['draft','publish','unpublish'],true))throw new EnterpriseException('Choose a supported profile action.');
 return comm_lock('enterprise.catalog',function()use($uid,$id,$in,$fields,$action,$logo){
  $old=$id!==null?enterprise_owned($uid,$id):[];$version=filter_var($in['version']??0,FILTER_VALIDATE_INT);
  if($version===false||$version!==(int)($old['version']??0))throw new EnterpriseException('This enterprise changed. Reload before saving.');
  if(!$old&&count(enterprise_records())>=100)throw new EnterpriseException('Archive older planning records before adding more.');
  $id=$id??bin2hex(random_bytes(8));$draft=$fields;$draft['logo']=!empty($in['remove_logo'])?'':($logo??$old['draft']['logo']??'');
  $r=$old?:['id'=>$id,'slug'=>(trim(mb_substr(slugify($draft['name']),0,80),'-')?:'enterprise').'-'.substr($id,0,6),'created'=>time(),'public'=>null,'history'=>[]];
  if($action==='publish'){
   foreach(['sector','summary','problem','solution','involvement','consent_reference']as$key)if($draft[$key]==='')throw new EnterpriseException('Complete the public profile and permission reference before publishing.');
   if(($in['reviewed']??'')!=='1'||($in['consent_confirmed']??'')!=='1')throw new EnterpriseException('Confirm the claims review and permission to publish.');
   if(!empty($r['public']))$r['history']=array_slice(array_merge($r['history'],[['at'=>time(),'snapshot'=>$r['public']]]),-10);
   $r['public']=enterprise_public_fields($draft)+['id'=>$id,'slug'=>$r['slug'],'published_at'=>time(),'reviewed_at'=>time()];
  }elseif($action==='unpublish'){$r['public']=null;}
  $r['draft']=$draft;$r['version']=($r['version']??0)+1;$r['updated']=time();$r['updated_by']=$uid;$r['last_action']=$action;$r['audit']=array_slice(array_merge($r['audit']??[],[['action'=>$action,'actor'=>$uid,'at'=>time(),'version'=>$r['version']]]),-200);
  comm_put('enterprise.profile.'.$id,$r);return $id;
 });
}
function enterprise_public_profiles(): array {
 $out=[];foreach(enterprise_records()as$r)if(is_array($r['public']??null))$out[]=enterprise_public_fields($r['public'])+array_intersect_key($r['public'],array_flip(['id','slug','published_at','reviewed_at']));
 usort($out,static fn($a,$b)=>strcmp($a['name'],$b['name']));return $out;
}
function enterprise_public_profile(string $slug): ?array {foreach(enterprise_public_profiles()as$p)if($p['slug']===$slug)return $p;return null;}
function enterprise_articles(array $ids): array {
 if(!$ids)return [];$ids=array_values(array_filter($ids,static fn($i)=>is_int($i)&&$i>0));if(!$ids)return [];
 $q=db()->prepare("SELECT id,title,slug,standfirst,published_at FROM posts WHERE id IN (".implode(',',array_fill(0,count($ids),'?')).") AND status IN ('published','scheduled') AND published_at IS NOT NULL AND published_at<=? ORDER BY published_at DESC,id DESC");
 $q->execute([...$ids,journeys_now()]);return $q->fetchAll();
}
function enterprise_sitemap(): array {return array_merge([['loc'=>url('/enterprises')]],array_map(static fn($p)=>['loc'=>url('/enterprises/'.$p['slug']),'lastmod'=>date(DATE_ATOM,$p['reviewed_at'])],enterprise_public_profiles()));}
function enterprise_dispatch(string $path): bool {
 if($path==='/enterprises'){
  journeys_method(['GET','HEAD']);$profiles=enterprise_public_profiles();$filters=[];foreach(['q','country','stage','sector']as$key)$filters[$key]=is_string($_GET[$key]??null)&&mb_check_encoding($_GET[$key],'UTF-8')?mb_substr(trim($_GET[$key]),0,120):'';
  $countries=array_unique(array_column($profiles,'country'));$sectors=array_unique(array_column($profiles,'sector'));
  $profiles=array_values(array_filter($profiles,static function($p)use($filters){foreach(['country','stage','sector']as$key)if($filters[$key]!==''&&$p[$key]!==$filters[$key])return false;return $filters['q']===''||mb_stripos($p['name'].' '.$p['summary'].' '.$p['sector'],$filters['q'])!==false;}));
  render('enterprises/index',compact('profiles','filters','countries','sectors')+['page'=>'enterprises','seo'=>['title'=>'Enterprises · Waithaka Ndegwa','description'=>'Follow the targets, successes, challenges and learning of enterprises connected with the Goal2030 journey.','canonical'=>url('/enterprises'),'robots'=>array_filter($filters)?'noindex,follow':'index,follow']]);return true;
 }
 if(preg_match('~^/enterprises/([a-z0-9]+(?:-[a-z0-9]+)*)$~D',$path,$m)){
  journeys_method(['GET','HEAD']);$enterprise=enterprise_public_profile($m[1]);if(!$enterprise)not_found();$articles=enterprise_articles($enterprise['post_ids']??[]);
  render('enterprises/detail',compact('enterprise','articles')+['page'=>'enterprises','preview'=>false,'seo'=>['title'=>$enterprise['name'].' · Enterprise journey · Waithaka Ndegwa','description'=>mb_substr($enterprise['summary'],0,300),'canonical'=>url('/enterprises/'.$enterprise['slug'])]]);return true;
 }
 if(!preg_match('~^/write/enterprises(?:/(new|[a-f0-9]{16})(/preview)?)?$~D',$path,$m))return false;
 require_auth();private_response_headers();$uid=(int)current_user()['id'];if($uid!==(int)(comm_config()['owner']??0)){http_response_code(403);echo 'Owner access required.';return true;}journeys_method(['GET','HEAD','POST']);
 $part=$m[1]??'';if($part===''){if(is_post()){http_response_code(405);return true;}$records=enterprise_records();render_write('enterprises',['writeTitle'=>'Enterprise portfolio','records'=>$records]);return true;}
 $id=$part==='new'?null:enterprise_id($part);try{$record=$id?enterprise_owned($uid,$id):[];}catch(EnterpriseException){not_found();}$values=$record['draft']??['country'=>'Kenya','stage'=>'idea'];$error='';
 if(!empty($m[2])){if(!$id)not_found();$enterprise=enterprise_public_fields($values)+['slug'=>$record['slug'],'reviewed_at'=>time()];$articles=enterprise_articles($enterprise['post_ids']??[]);render_write('enterprise_preview',compact('enterprise','articles')+['writeTitle'=>'Private enterprise preview','preview'=>true]);return true;}
 if(is_post()){verify_csrf();try{$logo=null;if(!empty($_FILES['enterprise_logo']['name']))$logo=upload_featured_image($_FILES['enterprise_logo']);$saved=enterprise_save($uid,$id,$_POST,$logo);flash('success',($_POST['action']??'draft')==='publish'?'Reviewed enterprise profile published.':(($_POST['action']??'')==='unpublish'?'Enterprise profile removed from public view.':'Private working copy saved.'));redirect(url('/write/enterprises/'.$saved));}
  catch(EnterpriseException|ReaderValidationException $e){$error=$e->getMessage();$values=array_replace($values,$_POST);}
  catch(Throwable $e){$error='The enterprise could not be saved. Keep your changes and try again.';error_log('Enterprise save failed: '.get_class($e));}
 }
 $postOptions=journeys_recent_posts();render_write('enterprise_edit',compact('record','values','error','id','postOptions')+['writeTitle'=>$id?'Edit enterprise':'New enterprise']);return true;
}
