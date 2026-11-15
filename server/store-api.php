<?php
declare(strict_types=1);
ini_set('display_errors','0');
require_once __DIR__.'/store-core.php';
require_once __DIR__.'/visitor-auth.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store, private');header('Pragma: no-cache');header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');
try{
    $route=$paStoreRoute??'';$method=$_SERVER['REQUEST_METHOD']??'GET';$admin=str_starts_with($route,'/api/admin/store');
    if($admin){require_once __DIR__.'/admin-auth.php';pa_admin_bootstrap(!($method==='GET'&&$route==='/api/admin/store'));pa_admin_require();}else pa_visitor_bootstrap();
    $result=['ok'=>true];
    if($method==='GET'){
        if($route==='/api/store/session')$result+=['csrf'=>pa_visitor_csrf(),'visitor'=>pa_visitor_profile()];
        elseif($route==='/api/store/catalog')$result+=pa_store_snapshot()+['csrf'=>pa_visitor_csrf(),'visitor'=>pa_visitor_profile()];
        elseif($route==='/api/admin/store')$result+=pa_store_snapshot(true)+['csrf'=>(string)$_SESSION['csrf']];
        elseif($route==='/api/admin/store/export')$result+=['data'=>pa_store_snapshot(true,true)+['reviews'=>pa_store_reviews(true)]];
        elseif(preg_match('~\A/api/store/orders/([A-Za-z0-9._:-]{1,120})\z~',$route,$match)){
            $authorization=$_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'';$token=preg_match('/\ABearer ([a-f0-9]{64})\z/',$authorization,$found)?$found[1]:'';
            pa_store_limit('receipt',$_SERVER['REMOTE_ADDR']??'unknown',120);$result+=['order'=>pa_store_receipt(pa_store_id($match[1]),$token)];
        }else pa_auth_fail(404,'not_found','Endereço da loja não encontrado.');
    }else{
        if($method!=='POST')pa_auth_fail(405,'method_not_allowed','Método não permitido.');
        $known=in_array($route,['/api/admin/store/products/save','/api/admin/store/products/remove','/api/admin/store/stock','/api/admin/store/orders/approve','/api/admin/store/orders/cancel','/api/admin/store/import','/api/admin/store/reviews/import','/api/store/orders','/api/store/reviews/save','/api/store/reviews/remove'],true);
        if(!$known)pa_auth_fail(404,'not_found','Endereço da loja não encontrado.');
        if($admin)pa_admin_csrf();else pa_visitor_require_csrf();
        if(!str_starts_with(strtolower($_SERVER['CONTENT_TYPE']??''),'application/json'))pa_auth_fail(415,'invalid_content_type','Envie os dados no formato esperado.');
        $limit=$route==='/api/admin/store/import'?20971520:2097152;
        if((int)($_SERVER['CONTENT_LENGTH']??0)>$limit)pa_auth_fail(413,'request_too_large','Os dados excedem o limite permitido.');
        $raw=file_get_contents('php://input',false,null,0,$limit+1);if($raw===false||strlen($raw)>$limit)pa_auth_fail(413,'request_too_large','Os dados excedem o limite permitido.');
        try{$decoded=json_decode($raw,false,64,JSON_THROW_ON_ERROR);}catch(JsonException){pa_auth_fail(400,'invalid_json','Os dados enviados não são válidos.');}
        if(!$decoded instanceof stdClass)pa_auth_fail(400,'invalid_json','Os dados enviados não são válidos.');
        $input=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
        if($admin){
            $value=match($route){
                '/api/admin/store/products/save'=>pa_store_save_product(pa_store_object($input['product']??null)),
                '/api/admin/store/products/remove'=>pa_store_remove_product(pa_store_id($input['id']??null)),
                '/api/admin/store/stock'=>pa_store_adjust_stock($input),
                '/api/admin/store/orders/approve'=>pa_store_treat_order(pa_store_id($input['id']??null),true),
                '/api/admin/store/orders/cancel'=>pa_store_treat_order(pa_store_id($input['id']??null),false),
                '/api/admin/store/import'=>pa_store_import(pa_store_object($input['data']??null)),
                '/api/admin/store/reviews/import'=>pa_store_import_reviews(pa_store_object($input['data']??null)),
            };
            $result+=['result'=>$value]+pa_store_snapshot(true)+['csrf'=>(string)$_SESSION['csrf']];
            if($route==='/api/admin/store/reviews/import')$result['reviews']=pa_store_reviews();
        }elseif($route==='/api/store/orders'){
            pa_store_limit('orders-ip',$_SERVER['REMOTE_ADDR']??'unknown',30);$result+=pa_store_create_order($input);
        }else{
            $visitor=pa_visitor_profile();
            pa_store_limit('reviews-ip',$_SERVER['REMOTE_ADDR']??'unknown',30);
            pa_store_limit('reviews-visitor',$visitor['id'],30);
            if($route==='/api/store/reviews/save'){
                $review=pa_store_save_review($input,$visitor);
                $_SESSION['visitor_name']=$review['nome'];
                $result+=['review'=>$review];
            }else pa_store_remove_review(pa_store_id($input['id']??null),$visitor);
            $result+=['reviews'=>pa_store_reviews()];
        }
    }
    if(!$admin)$result+=['visitor'=>pa_visitor_profile()];
    echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}catch(PaAuthError $error){http_response_code($error->status);$details=['code'=>$error->errorCode,'message'=>$error->getMessage()];if($error->field!==null)$details['field']=$error->field;echo json_encode(['ok'=>false,'error'=>$details],JSON_UNESCAPED_UNICODE);}
catch(Throwable $error){error_log('PokeAnya store: '.get_class($error));http_response_code(503);echo json_encode(['ok'=>false,'error'=>['code'=>'service_unavailable','message'=>'Não foi possível acessar os dados da loja. Tente novamente em instantes.']],JSON_UNESCAPED_UNICODE);}
