<?php
declare(strict_types=1);
/** Small escaped-text formatting subset. No raw HTML, remote embeds or executable links. */
function workspace_inline_text(string $text): string {
 $text=e($text);
 $text=preg_replace('/\*\*([^*\n]+)\*\*/u','<strong>$1</strong>',$text);
 return preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/u','<em>$1</em>',$text);
}
function workspace_rich_text(string $text): string {
 $html='';$list='';$paragraph=[];
 $flush=function()use(&$html,&$paragraph){if($paragraph){$html.='<p>'.implode('<br>',array_map('workspace_inline_text',$paragraph)).'</p>';$paragraph=[];}};
 $close=function()use(&$html,&$list){if($list!==''){$html.='</'.$list.'>';$list='';}};
 foreach(preg_split('/\R/u',$text)as$line){
  if(trim($line)===''){$flush();$close();continue;}
  if(preg_match('/^(#{1,3})\s+(.+)$/u',$line,$m)){$flush();$close();$level=strlen($m[1])+1;$html.='<h'.$level.'>'.workspace_inline_text($m[2]).'</h'.$level.'>';continue;}
  if(preg_match('/^(?:[-•]\s+|\d+\.\s+)(.+)$/u',$line,$m)){$flush();$kind=preg_match('/^\d/',$line)?'ol':'ul';if($kind!==$list){$close();$list=$kind;$html.='<'.$list.'>';}$html.='<li>'.workspace_inline_text($m[1]).'</li>';continue;}
  $close();if(str_starts_with($line,'> ')){$flush();$html.='<blockquote>'.workspace_inline_text(substr($line,2)).'</blockquote>';}else $paragraph[]=$line;
 }$flush();$close();return $html;
}
