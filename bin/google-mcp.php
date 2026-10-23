<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
// Launch explicitly under a trusted local MCP host. Never expose through HTTP or a shell tool.
if(getenv('WAITHAKA_GOOGLE_MCP')!=='1'){fwrite(STDERR,"Google analytics MCP is disabled. Enable only for an approved local client.\n");exit(1);}
require dirname(__DIR__).'/app/bootstrap.php';
require dirname(__DIR__).'/app/google_mcp.php';
$initialized=false;
while(($line=fgets(STDIN,65537))!==false){
    if(!str_ends_with($line,"\n")&&!feof(STDIN)){while(($rest=fgets(STDIN,65537))!==false&&!str_ends_with($rest,"\n")){}$response=['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32600,'message'=>'Request too large.']];}
    else try{$request=json_decode($line,true,32,JSON_THROW_ON_ERROR);$response=is_array($request)&&!array_is_list($request)?google_mcp_request($request,$initialized):['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32600,'message'=>'Invalid request.']];}
    catch(Throwable){$response=['jsonrpc'=>'2.0','id'=>null,'error'=>['code'=>-32700,'message'=>'Parse error.']];}
    if($response!==null){fwrite(STDOUT,json_encode($response,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE)."\n");fflush(STDOUT);}
}
