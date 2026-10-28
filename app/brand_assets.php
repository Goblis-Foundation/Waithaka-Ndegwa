<?php
declare(strict_types=1);
/** Build raster icons from the approved outlined monogram, never from a generated portrait. */
function wn_brand_polygons(string $d): array {
 preg_match_all('/[A-Za-z]|[-+]?(?:\d*\.\d+|\d+)(?:[eE][-+]?\d+)?/',$d,$matches);$t=$matches[0];$i=0;$x=0.0;$y=0.0;$points=[];$polygons=[];
 while($i<count($t)){
  $c=$t[$i++];
  if($c==='M'||$c==='L'){
   if(!isset($t[$i+1])||!is_numeric($t[$i])||!is_numeric($t[$i+1]))throw new RuntimeException('Invalid approved vector point.');
   if($c==='M'&&$points){$polygons[]=$points;$points=[];}$x=(float)$t[$i++];$y=(float)$t[$i++];$points[]=[$x,$y];
  }elseif($c==='C'){
   $v=array_slice($t,$i,6);if(count($v)!==6||count(array_filter($v,'is_numeric'))!==6)throw new RuntimeException('Invalid approved vector curve.');$i+=6;[$a,$b,$c1,$d1,$e,$f]=array_map('floatval',$v);
   for($k=1;$k<=32;$k++){$u=$k/32;$w=1-$u;$points[]=[$w*$w*$w*$x+3*$w*$w*$u*$a+3*$w*$u*$u*$c1+$u*$u*$u*$e,$w*$w*$w*$y+3*$w*$w*$u*$b+3*$w*$u*$u*$d1+$u*$u*$u*$f];}$x=$e;$y=$f;
  }elseif($c==='Z'){$polygons[]=$points;$points=[];}
  else throw new RuntimeException('Unsupported command in approved monogram.');
 }
 if($points)$polygons[]=$points;return $polygons;
}
function wn_brand_build(string $directory): array {
 if(PHP_SAPI!=='cli')throw new RuntimeException('Brand compilation is CLI-only.');
 if(!extension_loaded('gd')||!function_exists('imagecreatefromwebp'))throw new RuntimeException('GD with WebP support is required.');
 if(!is_dir($directory)&&!mkdir($directory,0700,true))throw new RuntimeException('Cannot create the explicit brand output directory.');
 if(is_link($directory))throw new RuntimeException('Brand output must not be a symlink.');
 $root=dirname(__DIR__);$source=$root.'/assets/images/wn-approved-monogram.svg';$logo=$root.'/assets/images/brand-lockup.webp';
 if(hash_file('sha256',$logo)!=='f674df8d4a9f30df3ce663b7f001af8fd1b18984d251ff5924c9ff01bac77c0b')throw new RuntimeException('The approved horizontal logo bytes changed. Review the source before release.');
 $xml=new DOMDocument();if(!$xml->load($source,LIBXML_NONET))throw new RuntimeException('Approved monogram is missing.');$polygons=[];$paths=[];$approvedPaints=['#073B6A','#2F6F3E'];$painted=[];
 foreach($xml->getElementsByTagName('path')as$i=>$path){
  $d=$path->getAttribute('d');$fill=strtoupper($path->getAttribute('fill'));
  if(!isset($approvedPaints[$i])||$fill!==$approvedPaints[$i])throw new RuntimeException('Unexpected approved monogram colour or layer.');
  $group=wn_brand_polygons($d);$paths[]=$d;$painted[]=$group;array_push($polygons,...$group);
 }
 if(count($paths)!==2||count($polygons)<6)throw new RuntimeException('Unexpected monogram source.');
 $sizes=['icon.png'=>512,'icon-512.png'=>512,'icon-192.png'=>192,'maskable-icon-512.png'=>512,'apple-touch-icon.png'=>180,'favicon-32.png'=>32,'favicon-16.png'=>16,'favicon-48.png'=>48];$written=[];$pngs=[];
 foreach($sizes as$name=>$size){
  $n=$size*4;$im=imagecreatetruecolor($n,$n);$bg=imagecolorallocate($im,255,255,255);
  $blue=imagecolorallocate($im,7,59,106);$green=imagecolorallocate($im,47,111,62);
  imagefill($im,0,0,$bg);
  // Preserve the EXACT approved outlined WN paths and original dual colours.
  // The mark is centred without cropping, distortion or tiny wordmark text.
  $scale=$n*($size<=64?.88:.72)/915;$ox=($n-915*$scale)/2;$oy=($n-417*$scale)/2;
  foreach($painted as$layer=>$group)foreach($group as$polygon){
   $p=[];foreach($polygon as[$x,$y]){$p[]=(int)round($ox+$x*$scale);$p[]=(int)round($oy+$y*$scale);}
   if(count($p)>=6)imagefilledpolygon($im,$p,$layer===0?$blue:$green);
  }
  $out=imagecreatetruecolor($size,$size);imagecopyresampled($out,$im,0,0,0,0,$size,$size,$n,$n);ob_start();imagepng($out,null,9);$bytes=(string)ob_get_clean();imagedestroy($out);imagedestroy($im);
  if(file_put_contents($directory.'/'.$name,$bytes)===false)throw new RuntimeException('Cannot write compiled icon.');$written[]=$name;if($size<=48)$pngs[$size]=$bytes;
 }
 $header=imagecreatefromwebp($logo);if(!$header||imagesx($header)!==720||imagesy($header)!==160)throw new RuntimeException('Approved logo dimensions differ.');imagealphablending($header,false);imagesavealpha($header,true);
 if(!imagepng($header,$directory.'/brand-lockup.png',9))throw new RuntimeException('Cannot write PNG logo fallback.');imagedestroy($header);$written[]='brand-lockup.png';
 ksort($pngs);$offset=6+16*count($pngs);$ico=pack('vvv',0,1,count($pngs));$body='';foreach($pngs as$size=>$bytes){$ico.=pack('CCCCvvVV',$size,$size,0,0,1,32,strlen($bytes),$offset);$offset+=strlen($bytes);$body.=$bytes;}file_put_contents($directory.'/favicon.ico',$ico.$body);$written[]='favicon.ico';
 $svg='<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><title>Waithaka Ndegwa</title><rect width="512" height="512" fill="#ffffff"/><g fill-rule="evenodd" transform="translate(71.68 171.57) scale(.402885246)">';
 foreach($paths as$i=>$d)$svg.='<path fill="'.$approvedPaints[$i].'" d="'.htmlspecialchars($d,ENT_QUOTES|ENT_XML1,'UTF-8').'"/>';
 $svg.='</g></svg>';file_put_contents($directory.'/favicon.svg',$svg);$written[]='favicon.svg';
 sort($written);return $written;
}
if(PHP_SAPI==='cli'&&realpath((string)($_SERVER['SCRIPT_FILENAME']??''))===__FILE__){
 $args=getopt('',['output-dir:']);if(empty($args['output-dir'])||!is_string($args['output-dir']))throw new InvalidArgumentException('Supply --output-dir outside the application source.');
 $out=$args['output-dir'];$base=realpath(dirname(__DIR__));$resolved=is_dir($out)?realpath($out):realpath(dirname($out));if(!$resolved||$resolved===$base||str_starts_with($resolved,$base.DIRECTORY_SEPARATOR))throw new InvalidArgumentException('Write generated brand assets outside the source tree.');
 echo json_encode(['files'=>wn_brand_build($out)],JSON_THROW_ON_ERROR).PHP_EOL;
}
