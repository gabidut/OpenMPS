<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../api/lib.php';
try {
    $path=mps_api_tokens_file();if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);
    $lock=fopen($path.'.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Cannot lock token store');
    $tokens=mps_api_read_tokens();$action=$argv[1]??'help';
    if($action==='list'){foreach($tokens as $id=>$token)echo $id."\t".$token['label']."\t".(!empty($token['revoked'])?'revoked':implode(',',$token['scopes'])).PHP_EOL;exit;}
    if($action==='create'){
        $label=trim($argv[2]??'CPM');if($label===''||strlen($label)>80||preg_match('/[\x00-\x1f\x7f]/',$label))throw new RuntimeException('Invalid label');
        $scopes=explode(',',$argv[3]??'read,publish');if(array_diff($scopes,['read','publish','recover'])||!$scopes)throw new RuntimeException('Scopes: read,publish,recover');
        $raw='omps_'.rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');$id=bin2hex(random_bytes(8));
        $tokens[$id]=['label'=>$label,'hash'=>hash('sha256',$raw),'scopes'=>array_values(array_unique($scopes)),'created_at'=>gmdate('c'),'revoked'=>false];
    }elseif($action==='revoke'){$id=$argv[2]??'';if(!isset($tokens[$id]))throw new RuntimeException('Unknown token ID');$tokens[$id]['revoked']=true;
    }else{echo "Usage: php tools/api_token.php create LABEL [read,publish,recover]\n       php tools/api_token.php list\n       php tools/api_token.php revoke ID\n";exit;}
    $temp=tempnam(dirname($path),'.api-token-');chmod($temp,0600);file_put_contents($temp,"<?php\nreturn ".var_export($tokens,true).";\n",LOCK_EX);
    if(!rename($temp,$path)){unlink($temp);throw new RuntimeException('Cannot save tokens');}chmod($path,0600);
    if(isset($raw))echo "Token ID: $id\nToken (shown once): $raw\n";else echo "Revoked: $id\n";
} catch(Throwable $e){fwrite(STDERR,$e->getMessage().PHP_EOL);exit(1);}
