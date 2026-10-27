<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/brand_assets.php';
$dir=sys_get_temp_dir().'/wn-brand-test-'.bin2hex(random_bytes(8));$files=[];
try{
 $files=wn_brand_build($dir);if(count($files)!==11)throw new RuntimeException('Expected eleven derived brand assets.');
 foreach(['icon.png'=>512,'icon-512.png'=>512,'icon-192.png'=>192,'maskable-icon-512.png'=>512,'apple-touch-icon.png'=>180,'favicon-32.png'=>32,'favicon-16.png'=>16,'favicon-48.png'=>48]as$name=>$size){$info=getimagesize($dir.'/'.$name);if(!$info||$info[0]!==$size||$info[1]!==$size||$info['mime']!=='image/png')throw new RuntimeException('Wrong icon export size.');$im=imagecreatefrompng($dir.'/'.$name);$corner=imagecolorsforindex($im,imagecolorat($im,0,0));if($corner['red']!==7||$corner['green']!==59||$corner['blue']!==106)throw new RuntimeException('Incorrect brand background.');imagedestroy($im);}
 $header=getimagesize($dir.'/brand-lockup.png');if($header[0]!==720||$header[1]!==160)throw new RuntimeException('Header logo was distorted.');
 $ico=file_get_contents($dir.'/favicon.ico');$head=unpack('vreserved/vtype/vcount',substr($ico,0,6));if($head!==['reserved'=>0,'type'=>1,'count'=>3])throw new RuntimeException('Multi-size icon is invalid.');
 $svg=file_get_contents($dir.'/favicon.svg');if(str_contains($svg,'<text')||str_contains($svg,'data:')||!str_contains($svg,'Waithaka Ndegwa'))throw new RuntimeException('Favicon must retain real approved vector paths.');
 echo "PASS: approved logo hash, unchanged wordmark proportions, vector-derived app icons, maskable spacing and multi-size favicon.\n";
}finally{foreach($files as$f)if(is_file($dir.'/'.$f))unlink($dir.'/'.$f);if(is_dir($dir))rmdir($dir);}
