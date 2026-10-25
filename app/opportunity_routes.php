<?php
declare(strict_types=1);
function opportunity_render(string $view,array $data,string $title,string $path,bool $private=false): void {
 if($private)private_response_headers();
 render('opportunities/'.$view,$data+['page'=>'founders','privatePage'=>$private,'suppressAnalytics'=>$private,'seo'=>['title'=>$title.' · Waithaka Ndegwa','description'=>'Reviewed enterprise employment opportunities, approved by their founders.','canonical'=>url($path),'robots'=>$private?'noindex,nofollow':'index,follow']]);
}
function opportunity_error(Throwable $e): string {if($e instanceof InvalidArgumentException){http_response_code(422);return $e->getMessage();}http_response_code(503);error_log('Opportunity request failed: '.get_class($e));return 'This request could not be completed. Your saved record remains available. Please try again.';}
function opportunity_dispatch(string $path): bool {
 if($path==='/opportunities'){
  journeys_method(['GET','HEAD']);$items=opportunity_public_list();$q=is_string($_GET['q']??null)&&mb_check_encoding($_GET['q'],'UTF-8')?mb_substr(trim($_GET['q']),0,120):'';$items=array_values(array_filter($items,static fn($p)=>$q===''||mb_stripos($p['title'].' '.$p['organisation'].' '.location_label($p),$q)!==false));opportunity_render('index',compact('items','q'),'Opportunities','/opportunities');return true;
 }
 if($path==='/opportunities/submit'){
  journeys_method(['GET','POST']);private_response_headers();$error='';$values=['country'=>'Kenya','phone_code'=>'+254','timezone'=>'Africa/Nairobi'];
  if(is_post()){verify_csrf();$values=array_filter($_POST,'is_scalar');try{$nonce=is_string($_POST['form_nonce']??null)?$_POST['form_nonce']:'';$issued=$_SESSION['opportunity_form']??[];if(!isset($issued[$nonce])||$issued[$nonce]<time()-7200)throw new OpportunityException('This form expired or was already submitted. Review the saved status or start a new form.');
    $email=journeys_email($_POST['contact_email']??'');$hold=[];if(!empty($_POST['company_website']))$hold[]='hidden_field';if(!journeys_rate_limit('opportunity-ip',ip_hash(),5))$hold[]='ip_limit';if(!journeys_rate_limit('opportunity-sender',$email,3,86400))$hold[]='sender_limit';
    $id=opportunity_submit($_POST,$hold);unset($_SESSION['opportunity_form'][$nonce]);flash('success','Your private opportunity is saved. Reference: '.strtoupper(substr($id,0,12)).'. It will be reviewed before any publication. A private link is queued to your email when notification checks allow.');redirect(url('/opportunities/submit'));
   }catch(Throwable $e){$error=opportunity_error($e);}}
  $nonce=bin2hex(random_bytes(16));$forms=array_filter($_SESSION['opportunity_form']??[],static fn($t)=>$t>time()-7200);$forms[$nonce]=time();$_SESSION['opportunity_form']=array_slice($forms,-10,null,true);opportunity_render('submit',compact('values','error','nonce'),'Submit an opportunity',$path,true);return true;
 }
 if($path==='/opportunities/review'){
  journeys_method(['GET','HEAD','POST']);private_response_headers();$id=is_string($_POST['id']??$_GET['id']??null)?($_POST['id']??$_GET['id']):'';$token=is_string($_POST['token']??$_GET['token']??null)?($_POST['token']??$_GET['token']):'';$error='';$done=false;
  if(is_post()){verify_csrf();try{if(!journeys_rate_limit('opportunity-response',ip_hash(),30))throw new OpportunityException('Too many responses. Try again later.');opportunity_respond($id,$token,$_POST);$done=true;}catch(Throwable $e){$error=opportunity_error($e);}}
  $record=$done?null:opportunity_token($id,$token);if(!$record&&!$done)http_response_code(410);$values=$record['fields']??[];opportunity_render('review',compact('record','values','error','done','id','token'),'Private opportunity review',$path,true);return true;
 }
 if(preg_match('~^/opportunities/([a-f0-9]{32})$~D',$path,$m)){
  journeys_method(['GET','HEAD']);try{$r=opportunity_record($m[1]);}catch(OpportunityException){not_found();return true;}$item=opportunity_public($r);
  if(!$item){http_response_code(410);opportunity_render('closed',[],'Opportunity unavailable',$path,true);return true;}opportunity_render('detail',['item'=>$item,'preview'=>false],$item['title'],$path);return true;
 }
 if(!preg_match('~^/write/opportunities(?:/([a-f0-9]{32}))?$~D',$path,$m))return false;
 require_auth();private_response_headers();$uid=(int)current_user()['id'];if($uid!==(int)(comm_config()['owner']??0)){http_response_code(403);echo 'Owner access required.';return true;}journeys_method(['GET','HEAD','POST']);$id=$m[1]??'';
 if($id===''){if(is_post()){http_response_code(405);return true;}$filter=is_string($_GET['status']??null)?$_GET['status']:'';$items=[];foreach(opportunity_index()as$i){$state=opportunity_state(['state'=>$i['state'],'rounds'=>$i['rounds'],'fields'=>['closes_at'=>$i['deadline']],'link'=>['expires'=>$i['link_expires']]]);$i['display_state']=$state;if($filter===''||$filter===$state)$items[]=$i;}usort($items,static fn($a,$b)=>$b['updated']<=>$a['updated']);render_write('opportunities',['writeTitle'=>'Opportunity review inbox','items'=>$items,'filter'=>$filter]);return true;}
 try{$record=opportunity_record($id);}catch(OpportunityException){not_found();return true;}$error='';$values=$record['fields'];
 if(is_post()){verify_csrf();try{opportunity_admin($uid,$id,$_POST);flash('success','Opportunity review saved. Email queue acceptance is not a delivery receipt.');redirect(url('/write/opportunities/'.$id));}catch(Throwable $e){$error=opportunity_error($e);}}
 render_write('opportunity_review',compact('record','values','error','id')+['writeTitle'=>'Review opportunity','enterprises'=>enterprise_records()]);return true;
}
