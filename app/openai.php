<?php
declare(strict_types=1);
final class OpenAiRequestException extends RuntimeException {
 public function __construct(public readonly string $reason,public readonly int $httpStatus=0){parent::__construct(match($reason){
 'not_configured'=>'OpenAI is not configured. Save the website project key in settings.',
 'authentication'=>'OpenAI rejected the saved credential. Check that the website project key is active and belongs to the intended organisation.',
 'permissions'=>'OpenAI denied access. Check Responses API write permission, project model access and account eligibility.',
 'model'=>'The configured OpenAI model is not available to this project. Review model access in the project settings.',
 'credits'=>'OpenAI reports that this project has no usable prepaid credit. Check the organisation and project associated with the saved key; do not buy more credit until they match.',
 'project_limit'=>'OpenAI reports that the project spend limit has been reached. Review that project limit before sending another request.',
 'organisation_limit'=>'OpenAI reports that the organisation spend or usage limit has been reached. Review the account limits before sending another request.',
 'quota'=>'OpenAI reports an account quota or billing restriction. Confirm the key’s organisation, project and billing status before sending another request.',
 'rate_limit'=>'OpenAI is temporarily rate limiting this project. Wait before trying another request.',
 'timeout'=>'OpenAI did not complete before the connection ended. Continue manually or try a smaller request later.',
 'connection'=>'The website could not establish a secure connection to OpenAI. Check server connectivity before trying again.',
 'token_limit'=>'OpenAI reached the output limit before finishing. Use a shorter source or a task with a larger output budget.',
 'refused'=>'OpenAI did not return a usable draft for this request. Review the source and continue manually if needed.',
 'request'=>'OpenAI rejected the request settings. Review the configured model and request compatibility.',
 'unavailable'=>'OpenAI is temporarily unavailable. Your source text is retained; no automatic retry was made.',
 default=>'OpenAI returned no usable completed text. Your source text is retained; no automatic retry was made.',
 });}
}
function openai_failure(int $status,array $data,int $curlError=0): OpenAiRequestException {
 if($curlError)return new OpenAiRequestException($curlError===28?'timeout':'connection');
 $error=is_array($data['error']??null)?$data['error']:[];$code=is_string($error['code']??null)?$error['code']:'';$type=is_string($error['type']??null)?$error['type']:'';
 $reason=match(true){$status===401=>'authentication',$status===403=>'permissions',$status===404=>'model',
 $status===429&&$code==='credit_balance_exhausted'=>'credits',$status===429&&$code==='project_spend_limit_exceeded'=>'project_limit',
 $status===429&&in_array($code,['organization_spend_limit_exceeded','organization_usage_limit_exceeded'],true)=>'organisation_limit',
 $status===429&&($type==='insufficient_quota'||$code==='insufficient_quota')=>'quota',$status===429=>'rate_limit',$status===408=>'timeout',$status>=500=>'unavailable',$status>=400=>'request',default=>'empty'};
 return new OpenAiRequestException($reason,$status);
}
function openai_completed_text(array $data): string {
 if(($data['status']??'')==='incomplete')throw new OpenAiRequestException(($data['incomplete_details']['reason']??'')==='max_output_tokens'?'token_limit':'refused');
 if(isset($data['error'])&&$data['error']!==null)throw new OpenAiRequestException('unavailable');
 if(isset($data['status'])&&$data['status']!=='completed')throw new OpenAiRequestException('empty');
 $out='';if(is_string($data['output_text']??null))$out=$data['output_text'];
 if($out==='')foreach($data['output']??[]as$item){if(!is_array($item)||($item['type']??'')!=='message')continue;foreach($item['content']??[]as$c){if(is_array($c)&&($c['type']??'')==='output_text'&&is_string($c['text']??null))$out.=$c['text'];}}
 $out=trim($out);if($out==='')throw new OpenAiRequestException('empty');return $out;
}
/** One stateless call. Diagnostics never include prompts, provider messages or credentials. */
function openai_generate(string $key,string $model,string $instructions,string $input,int $maxTokens,?callable $transport=null): string {
 if(trim($key)==='')throw new OpenAiRequestException('not_configured');if(!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,119}$/D',$model)||$maxTokens<1||$maxTokens>16000)throw new OpenAiRequestException('request');
 $body=['model'=>$model,'instructions'=>$instructions,'input'=>$input,'store'=>false,'max_output_tokens'=>$maxTokens];
 if($transport)$response=$transport($body);else{$ch=curl_init('https://api.openai.com/v1/responses');curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_POSTFIELDS=>json_encode($body,JSON_THROW_ON_ERROR),CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key,'Content-Type: application/json','Accept: application/json'],CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>30,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2]);$raw=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);$error=curl_errno($ch);curl_close($ch);$data=is_string($raw)?json_decode($raw,true):null;$response=['status'=>$status,'data'=>is_array($data)?$data:[],'curl_error'=>$error];}
 $status=(int)($response['status']??0);$data=is_array($response['data']??null)?$response['data']:[];$error=(int)($response['curl_error']??0);if($error||$status<200||$status>=300)throw openai_failure($status,$data,$error);return openai_completed_text($data);
}
