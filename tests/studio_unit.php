<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/functions.php';
require dirname(__DIR__).'/app/studio.php';
function studio_check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
foreach(['',str_repeat('x',6001),[],null,"\xff"]as$bad){$rejected=false;try{studio_input($bad);}catch(InvalidArgumentException){$rejected=true;}studio_check($rejected,'Malformed or excessive input must reject.');}
$fields=studio_capture_fields('A thought',"<script>alert('x')</script>\nA new line");
studio_check($fields['id']===0&&!isset($fields['status'])&&!isset($fields['published_at']),'Capture never requests publication or edits an existing post.');
studio_check(str_contains($fields['content_html'],'&lt;script&gt;')&&!str_contains($fields['content_html'],'<script>'),'Capture escapes input markup.');
studio_check(!isset($fields['source_notes'])&&!isset($fields['api_key']),'Capture does not attach private research or credentials.');
foreach(array_keys(studio_modes())as$mode){$instruction=studio_instructions($mode);studio_check(str_contains($instruction,'[VERIFY]')&&str_contains($instruction,'untrusted source material')&&str_contains($instruction,'human review'),'All AI modes retain evidence, injection and review boundaries.');}
$rejected=false;try{studio_instructions('publish');}catch(InvalidArgumentException){$rejected=true;}studio_check($rejected,'No AI publication mode exists.');
echo "PASS: capture escaping, input bounds, evidence and AI task boundaries.\n";
