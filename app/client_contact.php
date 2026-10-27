<?php
declare(strict_types=1);
/** Optional client contact details stay in the private client record, outside public/AI fields. */
function client_contact_values(array $input): array {return location_fields($input)+location_phone($input);}
function client_contact_save(string $id,array $input,string $actor): array {
    $profile=client_contact_values($input);
    return comm_lock('desk.catalog',function()use($id,$input,$actor,$profile){$r=desk_get($id);desk_expect($r,$input);if(empty($r['client']))throw new InvalidArgumentException('A client contact must be linked first.');$r['client']['contact_profile']=$profile;return desk_store($r,'private_client_contact_updated',$actor);});
}
function client_contact_dispatch(string $path): bool {
    if(!preg_match('~^/(client|write)/client-contact/([a-f0-9]{32})$~D',$path,$m))return false;
    private_response_headers();journeys_method(['GET','HEAD','POST']);$owner=$m[1]==='write';
    try{if($owner){$uid=correspondence_owner();$r=desk_get($m[2]);}else{$r=desk_private($m[2]);$uid=0;}if(empty($r['client']))throw new InvalidArgumentException('Client contact unavailable.');}
    catch(InvalidArgumentException){http_response_code(403);echo 'Open the current private workspace link or sign in as the owner.';return true;}
    $error='';$values=$r['client']['contact_profile']??['country'=>'Kenya','phone_code'=>'+254'];
    if(is_post()){verify_csrf();try{$r=client_contact_save($r['id'],$_POST,$owner?(string)$uid:'client');flash('success','Private contact details saved. They are not published or sent to AI.');redirect(url($path));}catch(InvalidArgumentException $e){$error=$e->getMessage();http_response_code(422);$values=array_filter($_POST,'is_string');}}
    $data=['record'=>$r,'values'=>$values,'owner'=>$owner,'error'=>$error,'writeTitle'=>'Private client contact'];
    if($owner)render_write('client_contact',$data);else desk_render('client_contact',$data,'Private client contact',$path,true);return true;
}
