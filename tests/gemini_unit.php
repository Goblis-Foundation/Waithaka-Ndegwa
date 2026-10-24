<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/providers/http.php';
require dirname(__DIR__).'/app/gemini.php';
function gc_check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);}
function gc_reject(callable $fn):void{try{$fn();}catch(RuntimeException){return;}throw new RuntimeException('Expected rejection');}
$b=google_gemini_interaction_body('gemini-3.8-flash','Do not perform external actions.','Synthetic text',500);
gc_check($b['store']===false&&!isset($b['previous_interaction_id']),'No server-side conversation state');
gc_check($b['generation_config']['max_output_tokens']===500&&$b['generation_config']['thinking_level']==='low','Bounded output and valid thinking level');
$schema=['type'=>'object','properties'=>['rows'=>['type'=>'array']]];$doc=google_gemini_interaction_body('gemini-3.8-flash','Review only',[['type'=>'document','mime_type'=>'application/pdf','data'=>base64_encode('synthetic fixture')]],1000,$schema);
gc_check($doc['response_format']['mime_type']==='application/json'&&$doc['response_format']['schema']===$schema,'Structured document response');
$r=['status'=>'completed','steps'=>[['type'=>'thought','content'=>[['type'=>'text','text'=>'DO NOT EXPOSE']]],['type'=>'model_output','content'=>[['type'=>'text','text'=>'Reviewed output'],['type'=>'image','data'=>'DO NOT EXPOSE']]]]];
gc_check(google_gemini_interaction_text($r)==='Reviewed output','Only model text enters the result');
gc_reject(fn()=>google_gemini_interaction_text(['status'=>'in_progress','steps'=>$r['steps']]));
$e=google_gemini_error(new PublishingRemoteException('secret-provider-body',503));
gc_check(str_contains($e->getMessage(),'HTTP 503')&&!str_contains($e->getMessage(),'secret-provider-body')&&!str_contains($e->getMessage(),'publication history'),'AI-specific non-secret diagnostics');
$models=google_gemini_catalog_rows([['name'=>'models/gemini-3.8-flash','supportedGenerationMethods'=>['generateContent']],['name'=>'models/unsafe/model','supportedGenerationMethods'=>['generateContent']],['name'=>'models/gemini-embedding-001','supportedGenerationMethods'=>['embedContent']]]);
gc_check(count($models)===1&&$models[0]['id']==='gemini-3.8-flash','Catalog excludes invalid and non-generation models');
echo "PASS: stateless Gemini requests, bounded structured documents, completed-output parsing and secret-free diagnostics.\n";
