<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$email=strtolower(trim((string)($argv[1]??'')));$name=trim((string)($argv[2]??'Waithaka Ndegwa'));
if(!filter_var($email,FILTER_VALIDATE_EMAIL)){fwrite(STDERR,"Usage: php bin/create-writer.php EMAIL [NAME] < private-password-file\n");exit(1);}
if((int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn()>0){fwrite(STDERR,"Writer already exists. Restore and use the existing account; this command will not replace it.\n");exit(1);}
$password=rtrim((string)fgets(STDIN),"\r\n");
if(strlen($password)<14){fwrite(STDERR,"Password must contain at least 14 characters.\n");exit(1);}
db()->prepare('INSERT INTO users(name,email,password_hash,is_active)VALUES(?,?,?,1)')->execute([$name,$email,password_hash($password,PASSWORD_DEFAULT)]);unset($password);
fwrite(STDOUT,"Initial Writer created. No existing accounts were changed.\n");
