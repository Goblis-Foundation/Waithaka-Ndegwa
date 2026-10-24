<?php
declare(strict_types=1);
final class GeminiRequestException extends RuntimeException {}
/** Stateless Gemini calls. Keys and provider bodies never enter diagnostics. */
function google_gemini_model_id(string $model): string {
 if(!preg_match('/^gemini-[a-z0-9.-]{1,80}$/D',$model))throw new InvalidArgumentException('Choose a valid Gemini model identifier.');return $model;
}
function google_gemini_interaction_body(string $model,string $instructions,string|array $input,int $limit,?array $schema=null): array {
 google_gemini_model_id($model);if($limit<1||$limit>12000)throw new InvalidArgumentException('Invalid AI output limit.');
 $body=['model'=>$model,'store'=>false,'system_instruction'=>$instructions,'input'=>$input,'generation_config'=>['max_output_tokens'=>$limit]];
 if($model==='gemini-3.8-flash')$body['generation_config']['thinking_level']='low';
 if($schema!==null)$body['response_format']=['type'=>'text','mime_type'=>'application/json','schema'=>$schema];
 return $body;
}
function google_gemini_error(PublishingRemoteException $error): GeminiRequestException {
 $code=$error->httpStatus;
 $message=match(true){
  $code===0=>'Gemini did not complete before the connection ended. Try a smaller request or continue manually.',
  in_array($code,[401,403],true)=>'Gemini access was denied (HTTP '.$code.'). Check the API key, enabled API and project restrictions.',
  $code===404=>'This Gemini model or API is unavailable to the configured project (HTTP 404). Refresh available models.',
  $code===429=>'Gemini quota or rate limit was reached (HTTP 429). Review the project usage and retry later.',
  $code>=500=>'Gemini is temporarily unavailable (HTTP '.$code.'). Retry later or choose another available model.',
  default=>'Gemini rejected the request (HTTP '.$code.'). Review the model and document format.'
 };
 return new GeminiRequestException($message.' This AI request did not publish content, send messages or change calendar events.');
}
function google_gemini_interaction_text(array $response): string {
 if(($response['status']??'')!=='completed')throw new GeminiRequestException('Gemini did not return a completed interpretation. No program items were created.');
 $text='';foreach($response['steps']??[]as$step)if(($step['type']??'')==='model_output')foreach($step['content']??[]as$part)if(($part['type']??'')==='text')$text.=(string)($part['text']??'');
 if(trim($text)===''||strlen($text)>1024*1024)throw new GeminiRequestException('Gemini returned no usable text. Continue manually or try a smaller document.');return $text;
}
function google_gemini_generate(string $instructions,string|array $input,int $limit,?array $schema=null): string {
 $key=trim((string)setting('google.gemini_key',''));if($key==='')throw new InvalidArgumentException('Save a Gemini API key in Google preferences first.');
 $model=google_gemini_model_id((string)setting('google.gemini_model','gemini-3.8-flash'));
 try{$response=publishing_http('POST','https://generativelanguage.googleapis.com/v1beta/interactions',['x-goog-api-key: '.$key],google_gemini_interaction_body($model,$instructions,$input,$limit,$schema))['data'];}
 catch(PublishingRemoteException $error){throw google_gemini_error($error);}
 return google_gemini_interaction_text($response);
}
function google_gemini_catalog_rows(array $models): array {
 $out=[];foreach($models as$m){$name=(string)($m['name']??'');if(!str_starts_with($name,'models/'))continue;$id=substr($name,7);if(!preg_match('/^gemini-[a-z0-9.-]{1,80}$/D',$id)||!in_array('generateContent',(array)($m['supportedGenerationMethods']??[]),true))continue;$out[$id]=['id'=>$id,'name'=>mb_substr((string)($m['displayName']??$id),0,180)];}return array_values($out);
}
function google_gemini_catalog(): array {
 $key=trim((string)setting('google.gemini_key',''));if($key==='')throw new InvalidArgumentException('Save a Gemini API key first.');
 $rows=[];$page='';try{for($n=0;$n<5;$n++){$r=publishing_http('GET','https://generativelanguage.googleapis.com/v1beta/models?pageSize=100'.($page!==''?'&pageToken='.rawurlencode($page):''),['x-goog-api-key: '.$key])['data'];$rows=array_merge($rows,google_gemini_catalog_rows($r['models']??[]));$page=(string)($r['nextPageToken']??'');if($page==='')break;}}
 catch(PublishingRemoteException $error){throw google_gemini_error($error);}
 $catalog=['models'=>$rows,'observed'=>time(),'incomplete'=>$page!==''];set_setting('google.gemini_catalog',json_encode($catalog,JSON_THROW_ON_ERROR),true);return $catalog;
}
