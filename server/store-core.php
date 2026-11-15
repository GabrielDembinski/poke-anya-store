<?php
declare(strict_types=1);
require_once __DIR__ . '/auth-core.php';

function pa_store_db(): PDO {
    static $db;
    if ($db instanceof PDO) return $db;
    if (!extension_loaded('pdo_sqlite')) pa_auth_fail(503, 'storage_unavailable', 'O banco de dados da loja não está disponível.');
    $directory = pa_auth_config()['data_dir'];
    umask(0077); pa_auth_private_directory($directory);
    $db = new PDO('sqlite:' . $directory . '/commerce.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;');
    $db->exec("CREATE TABLE IF NOT EXISTS products (id TEXT PRIMARY KEY, nome TEXT NOT NULL, categoria TEXT NOT NULL, preco_cents INTEGER, descricao TEXT NOT NULL, imagem TEXT NOT NULL, estoque INTEGER NOT NULL CHECK(estoque>=0), created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS orders (id TEXT PRIMARY KEY, codigo TEXT NOT NULL UNIQUE, cliente_nome TEXT NOT NULL, cliente_contato TEXT NOT NULL, observacao TEXT NOT NULL, items_json TEXT NOT NULL, status TEXT NOT NULL CHECK(status IN ('pendente','aprovado','cancelado')), created_at TEXT NOT NULL, updated_at TEXT NOT NULL, token_hash TEXT, idempotency_hash TEXT UNIQUE, request_hash TEXT);
        CREATE TABLE IF NOT EXISTS movements (id TEXT PRIMARY KEY, produto_id TEXT NOT NULL, tipo TEXT NOT NULL, quantidade INTEGER NOT NULL, estoque_antes INTEGER NOT NULL, estoque_depois INTEGER NOT NULL, motivo TEXT NOT NULL, pedido_id TEXT, created_at TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS reviews (id TEXT PRIMARY KEY, produto_id TEXT NOT NULL, produto_nome TEXT NOT NULL, autor_id TEXT NOT NULL, nome TEXT NOT NULL, nota INTEGER NOT NULL CHECK(nota BETWEEN 1 AND 5), comentario TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL, legacy INTEGER NOT NULL DEFAULT 0, UNIQUE(produto_id,autor_id));
        CREATE TABLE IF NOT EXISTS media (id TEXT PRIMARY KEY, mime TEXT NOT NULL, content BLOB NOT NULL, created_at TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS store_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS store_rate_limits (key TEXT PRIMARY KEY, window_start INTEGER NOT NULL, attempts INTEGER NOT NULL);
        CREATE INDEX IF NOT EXISTS reviews_product ON reviews(produto_id);
        CREATE INDEX IF NOT EXISTS orders_status ON orders(status);");
    $columns=array_column($db->query('PRAGMA table_info(reviews)')->fetchAll(),'name');if(!in_array('legacy',$columns,true))$db->exec('ALTER TABLE reviews ADD COLUMN legacy INTEGER NOT NULL DEFAULT 0');
    $key = $db->prepare('INSERT OR IGNORE INTO store_meta(key,value) VALUES(?,?)'); $key->execute(['receipt_key', bin2hex(random_bytes(32))]);
    return $db;
}
function pa_store_text(mixed $value, string $label, int $minimum, int $maximum): string {
    if (!is_string($value) || !preg_match('//u', $value) || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value)) pa_auth_fail(400, 'invalid_input', $label . ' inválido.');
    $value = trim($value); $length = preg_match_all('/./us', $value);
    if ($length < $minimum || $length > $maximum) pa_auth_fail(400, 'invalid_input', $label . ' deve ter de ' . $minimum . ' a ' . $maximum . ' caracteres.');
    return $value;
}
function pa_store_id(mixed $value): string {
    if (!is_string($value) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,119}\z/', $value)) pa_auth_fail(400, 'invalid_id', 'Identificador inválido.');
    return $value;
}
function pa_store_int(mixed $value, int $minimum = 0, int $maximum = 1000000): int {
    if (!is_int($value) || $value < $minimum || $value > $maximum) pa_auth_fail(400, 'invalid_quantity', 'Informe uma quantidade inteira dentro do limite permitido.');
    return $value;
}
function pa_store_object(mixed $value): array {
    if (!is_array($value) || array_is_list($value)) pa_auth_fail(400, 'invalid_input', 'Os dados enviados não são válidos.');
    return $value;
}
function pa_store_date(mixed $value): string {
    if (!is_string($value) || !preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,6})?Z\z/', $value)) pa_auth_fail(400, 'invalid_date', 'Data inválida no backup.');
    try { $date = new DateTimeImmutable($value); } catch (Throwable) { pa_auth_fail(400, 'invalid_date', 'Data inválida no backup.'); }
    $errors = DateTimeImmutable::getLastErrors();
    if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) pa_auth_fail(400, 'invalid_date', 'Data inválida no backup.');
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
}
function pa_store_now(?string $previous = null): string {
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    if ($previous !== null && $now->format('Y-m-d\TH:i:s.v\Z') <= $previous) $now = (new DateTimeImmutable($previous))->modify('+1 millisecond');
    return $now->format('Y-m-d\TH:i:s.v\Z');
}
function pa_store_uid(string $prefix): string { return $prefix . '-' . bin2hex(random_bytes(16)); }
function pa_store_price(mixed $value): ?int {
    if ($value === null) return null;
    if ((!is_float($value) && !is_int($value)) || !is_finite((float)$value) || $value < 0 || $value > 10000000) pa_auth_fail(400, 'invalid_price', 'Informe um preço válido.');
    return (int) round($value * 100, 0, PHP_ROUND_HALF_UP);
}
function pa_store_image(mixed $value): string {
    $value = pa_store_text($value, 'Imagem', 0, 1500000);
    if ($value === '') return '';
    if (preg_match('~\Aassets/[A-Za-z0-9_./-]+\.(?:png|jpe?g|webp|gif)\z~i', $value)) {
        $assets=realpath(__DIR__.'/../assets');$file=realpath(__DIR__.'/../'.$value);
        if(!$assets||!$file||!pa_auth_inside($file,$assets)||!is_file($file)||filesize($file)>1048576)pa_auth_fail(400,'invalid_image','A imagem local não foi encontrada ou excede o limite permitido.');
        $bytes=file_get_contents($file);$info=@getimagesizefromstring($bytes);
        if(!$info||!in_array($info['mime']??'',['image/png','image/jpeg','image/webp','image/gif'],true))pa_auth_fail(400,'invalid_image','A imagem local não é uma foto válida.');
        return pa_store_image('data:'.$info['mime'].';base64,'.base64_encode($bytes));
    }
    if (preg_match('~\A/media/([a-f0-9]{64})\z~', $value, $match)) {
        $statement = pa_store_db()->prepare('SELECT 1 FROM media WHERE id=?'); $statement->execute([$match[1]]);
        if (!$statement->fetchColumn()) pa_auth_fail(400, 'image_not_found', 'Essa imagem não está no servidor. Envie a imagem novamente ou restaure um backup exportado.');
        return $value;
    }
    if (preg_match('~\Adata:(image/(?:jpeg|png|webp|gif));base64,([A-Za-z0-9+/]*={0,2})\z~D', $value, $match)) {
        $content = base64_decode($match[2], true);
        $info = $content !== false ? @getimagesizefromstring($content) : false;
        if ($content === false || strlen($content) > 1048576 || !$info || ($info['mime'] ?? '') !== $match[1] || $info[0] * $info[1] > 25000000) pa_auth_fail(400, 'invalid_image', 'Envie uma imagem JPG, PNG, WebP ou GIF válida de até 1 MB após a otimização.');
        $id = hash('sha256', $content);
        $query = pa_store_db()->prepare('INSERT OR IGNORE INTO media(id,mime,content,created_at) VALUES(?,?,?,?)');
        $query->bindValue(1, $id); $query->bindValue(2, $match[1]); $query->bindValue(3, $content, PDO::PARAM_LOB); $query->bindValue(4, pa_store_now()); $query->execute();
        return '/media/' . $id;
    }
    $parts = parse_url($value);
    if (strlen($value) > 2048 || !filter_var($value, FILTER_VALIDATE_URL) || !in_array($parts['scheme'] ?? '', ['http','https'], true) || isset($parts['user']) || isset($parts['pass'])) pa_auth_fail(400, 'invalid_image', 'Use uma imagem enviada ou um endereço HTTP/HTTPS válido.');
    return $value; // External images are fetched by the browser, never by this server.
}
function pa_store_media(string $id): void {
    if (!preg_match('/\A[a-f0-9]{64}\z/', $id)) { http_response_code(404); return; }
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET','HEAD'], true)) { http_response_code(405); header('Allow: GET, HEAD'); return; }
    try {
        $query = pa_store_db()->prepare('SELECT mime,content FROM media WHERE id=?'); $query->execute([$id]); $row = $query->fetch();
        if (!$row) { http_response_code(404); return; }
        $bytes = is_resource($row['content']) ? stream_get_contents($row['content']) : $row['content'];
        header('Content-Type: ' . $row['mime']); header('Content-Length: ' . strlen($bytes)); header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox"); header('Cache-Control: public, max-age=31536000, immutable');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') echo $bytes;
    } catch (Throwable) { http_response_code(503); }
}
function pa_store_product(array $row, bool $portable = false): array {
    $image = $row['imagem'];
    if ($portable && preg_match('~\A/media/([a-f0-9]{64})\z~', $image, $match)) {
        $query = pa_store_db()->prepare('SELECT mime,content FROM media WHERE id=?'); $query->execute([$match[1]]); $media = $query->fetch();
        if (!$media) pa_auth_fail(503, 'image_not_found', 'Uma imagem não foi encontrada. O backup não foi exportado.');
        $bytes = is_resource($media['content']) ? stream_get_contents($media['content']) : $media['content'];
        $image = 'data:' . $media['mime'] . ';base64,' . base64_encode($bytes);
    }
    return ['id'=>$row['id'],'nome'=>$row['nome'],'categoria'=>$row['categoria'],'preco'=>$row['preco_cents'] === null ? null : ((int)$row['preco_cents']) / 100,'descricao'=>$row['descricao'],'imagem'=>$image,'estoque'=>(int)$row['estoque'],'createdAt'=>$row['created_at'],'updatedAt'=>$row['updated_at']];
}
function pa_store_order(array $row): array { return ['id'=>$row['id'],'codigo'=>$row['codigo'],'cliente'=>['nome'=>$row['cliente_nome'],'contato'=>$row['cliente_contato']],'observacao'=>$row['observacao'],'items'=>json_decode($row['items_json'],true,32,JSON_THROW_ON_ERROR),'status'=>$row['status'],'createdAt'=>$row['created_at'],'updatedAt'=>$row['updated_at']]; }
function pa_store_reviews(bool $historical=false): array {
    $rows = pa_store_db()->query('SELECT r.* FROM reviews r '.($historical?'':'JOIN products p ON p.id=r.produto_id ').'ORDER BY r.created_at DESC,r.id DESC')->fetchAll();
    return array_map(static fn($r)=>['id'=>$r['id'],'produtoId'=>$r['produto_id'],'produtoNome'=>$r['produto_nome'],'autorId'=>pa_store_review_author($r['autor_id'],(bool)$r['legacy']),'nome'=>$r['nome'],'nota'=>(int)$r['nota'],'comentario'=>$r['comentario'],'createdAt'=>$r['created_at'],'updatedAt'=>$r['updated_at'],'legacy'=>(bool)$r['legacy'],'guest'=>str_starts_with($r['autor_id'],'visitante-')],$rows);
}
function pa_store_review_author(string $owner, bool $legacy=false): string {
    return $legacy || str_starts_with($owner,'visitante-') ? $owner : 'cliente-'.$owner;
}
function pa_store_snapshot(bool $admin = false, bool $portable = false): array {
    $data = ['version'=>1,'products'=>array_map(static fn($row)=>pa_store_product($row,$portable),pa_store_db()->query('SELECT * FROM products ORDER BY created_at,id')->fetchAll())];
    if (!$admin) { $data['reviews'] = pa_store_reviews(); return $data; }
    $data['orders'] = array_map('pa_store_order',pa_store_db()->query('SELECT * FROM orders ORDER BY created_at,id')->fetchAll());
    $data['movements'] = array_map(static fn($r)=>['id'=>$r['id'],'produtoId'=>$r['produto_id'],'tipo'=>$r['tipo'],'quantidade'=>(int)$r['quantidade'],'estoqueAntes'=>(int)$r['estoque_antes'],'estoqueDepois'=>(int)$r['estoque_depois'],'motivo'=>$r['motivo'],'pedidoId'=>$r['pedido_id'],'createdAt'=>$r['created_at']],pa_store_db()->query('SELECT * FROM movements ORDER BY created_at,id')->fetchAll());
    return $data;
}
function pa_store_tx(callable $action): mixed {
    $db = pa_store_db(); $db->exec('BEGIN IMMEDIATE');
    try { $result=$action($db); $db->exec('COMMIT'); return $result; }
    catch (Throwable $error) { try { $db->exec('ROLLBACK'); } catch (Throwable) {} throw $error; }
}
function pa_store_find_product(string $id): ?array { $query=pa_store_db()->prepare('SELECT * FROM products WHERE id=?');$query->execute([$id]);return $query->fetch() ?: null; }
function pa_store_movement(array $product, int $before, string $reason, string $type, ?string $orderId = null): void {
    $query=pa_store_db()->prepare('INSERT INTO movements VALUES(?,?,?,?,?,?,?,?,?)');
    $query->execute([pa_store_uid('mov'),$product['id'],$type,$product['estoque']-$before,$before,$product['estoque'],$reason,$orderId,pa_store_now()]);
}
function pa_store_normalize_product(array $input, ?array $existing = null, bool $import = false): array {
    $category=$input['categoria']??null;
    if (!in_array($category,['cartas','figures','chaveiros','pelucias','albuns','roupas'],true)) pa_auth_fail(400,'invalid_category','Escolha uma categoria válida.');
    $created=$import ? pa_store_date($input['createdAt']??null) : ($existing['created_at']??pa_store_now());
    $updated=$import ? pa_store_date($input['updatedAt']??null) : pa_store_now($existing['updated_at']??null);
    if ($updated<$created) pa_auth_fail(400,'invalid_date','A atualização não pode ser anterior à criação.');
    return ['id'=>$existing['id']??($import?pa_store_id($input['id']??null):pa_store_uid('prod')),'nome'=>pa_store_text($input['nome']??null,'Nome do produto',2,160),'categoria'=>$category,'preco_cents'=>pa_store_price($input['preco']??null),'descricao'=>pa_store_text($input['descricao']??'','Descrição',0,4000),'imagem'=>pa_store_image($input['imagem']??''),'estoque'=>pa_store_int($input['estoque']??0),'created_at'=>$created,'updated_at'=>$updated];
}
function pa_store_write_product(array $product): void {
    $query=pa_store_db()->prepare('INSERT INTO products(id,nome,categoria,preco_cents,descricao,imagem,estoque,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?) ON CONFLICT(id) DO UPDATE SET nome=excluded.nome,categoria=excluded.categoria,preco_cents=excluded.preco_cents,descricao=excluded.descricao,imagem=excluded.imagem,estoque=excluded.estoque,updated_at=excluded.updated_at');
    $query->execute(array_values($product));
}
function pa_store_save_product(array $input): array {
    return pa_store_tx(static function()use($input){
        $existing=isset($input['id'])?pa_store_find_product(pa_store_id($input['id'])):null;
        if(isset($input['id'])&&!$existing)pa_auth_fail(404,'product_not_found','Esse produto foi removido. Atualize a vitrine.');
        if($existing&&(!is_string($input['updatedAt']??null)||$input['updatedAt']!==$existing['updated_at']))pa_auth_fail(409,'product_changed','Este produto foi alterado enquanto você editava. Abra a edição novamente para conferir o estoque atualizado.');
        if(!$existing&&(int)pa_store_db()->query('SELECT COUNT(*) FROM products')->fetchColumn()>=500)pa_auth_fail(409,'catalog_limit','O catálogo atingiu o limite de 500 produtos.');
        $product=pa_store_normalize_product($input,$existing);pa_store_write_product($product);
        if(!$existing&&$product['estoque'])pa_store_movement($product,0,'Estoque inicial do produto','cadastro');
        elseif($existing&&$product['estoque']!==(int)$existing['estoque'])pa_store_movement($product,(int)$existing['estoque'],'Estoque ajustado na edição do produto','ajuste');
        return pa_store_product($product);
    });
}
function pa_store_remove_product(string $id): array { return pa_store_tx(static function()use($id){$product=pa_store_find_product($id);if(!$product)pa_auth_fail(404,'product_not_found','Produto não encontrado.');$removed=$product;$removed['estoque']=0;pa_store_movement($removed,(int)$product['estoque'],'Produto removido do catálogo','remocao');$query=pa_store_db()->prepare('DELETE FROM products WHERE id=?');$query->execute([$id]);return pa_store_product($product);}); }
function pa_store_adjust_stock(array $input): array {
    return pa_store_tx(static function()use($input){$product=pa_store_find_product(pa_store_id($input['id']??null));if(!$product)pa_auth_fail(404,'product_not_found','Produto não encontrado.');$before=(int)$product['estoque'];
        if(array_key_exists('quantity',$input)){$delta=pa_store_int($input['quantity'],-1000000);if(!$delta)pa_auth_fail(400,'invalid_quantity','Informe um ajuste diferente de zero.');$stock=pa_store_int($before+$delta);}else $stock=pa_store_int($input['stock']??null);
        $product['estoque']=$stock;$product['updated_at']=pa_store_now($product['updated_at']);pa_store_write_product($product);pa_store_movement($product,$before,pa_store_text($input['reason']??null,'Motivo',1,300),'ajuste');return pa_store_product($product);
    });
}
function pa_store_limit(string $scope,string $identifier,int $limit,int $seconds=900):void {
    $now=time();$query=pa_store_db()->prepare('INSERT INTO store_rate_limits(key,window_start,attempts) VALUES(?,?,1) ON CONFLICT(key) DO UPDATE SET attempts=CASE WHEN window_start<=? THEN 1 ELSE attempts+1 END,window_start=CASE WHEN window_start<=? THEN excluded.window_start ELSE window_start END RETURNING attempts');
    $query->execute([hash('sha256',$scope.':'.$identifier),$now,$now-$seconds,$now-$seconds]);$count=(int)$query->fetchColumn();$query->closeCursor();if($count>$limit){header('Retry-After: '.$seconds);pa_auth_fail(429,'too_many_attempts','Muitas solicitações. Aguarde alguns minutos antes de tentar novamente.');}
    if(random_int(1,100)===1){$delete=pa_store_db()->prepare('DELETE FROM store_rate_limits WHERE window_start<?');$delete->execute([$now-86400]);}
}
function pa_store_order_input(array $input):array {
    $client=pa_store_object($input['cliente']??null);
    $name=pa_store_text($client['nome']??null,'Nome do cliente',2,100);$contact=pa_store_text($client['contato']??null,'Contato do cliente',5,120);
    $digits=preg_replace('/\D/','',$contact);if(!preg_match('/\A[+\d\s().-]+\z/',$contact)||strlen($digits)<8||strlen($digits)>15)pa_auth_fail(400,'invalid_contact','Informe um WhatsApp válido com DDD.');
    $note=pa_store_text($input['observacao']??'','Observação',0,2000);$items=$input['items']??null;if(!is_array($items)||!array_is_list($items)||!count($items)||count($items)>100)pa_auth_fail(400,'invalid_items','Adicione de 1 a 100 produtos ao pedido.');
    $quantities=[];foreach($items as $item){$item=pa_store_object($item);$id=pa_store_id($item['produtoId']??null);$quantity=pa_store_int($item['quantidade']??null,1,99);$quantities[$id]=pa_store_int(($quantities[$id]??0)+$quantity,1,99);}ksort($quantities);
    return ['cliente'=>['nome'=>$name,'contato'=>$contact],'observacao'=>$note,'quantities'=>$quantities];
}
function pa_store_order_token(array $order):string { $secret=pa_store_db()->query("SELECT value FROM store_meta WHERE key='receipt_key'")->fetchColumn();return hash_hmac('sha256',$order['id'].':'.$order['idempotency_hash'],$secret); }
function pa_store_create_order(array $input):array {
    $normalized=pa_store_order_input($input);$key=pa_store_text($input['idempotencyKey']??null,'Chave do pedido',16,120);if(!preg_match('/\A[A-Za-z0-9._:-]+\z/',$key))pa_auth_fail(400,'invalid_idempotency_key','A chave do pedido é inválida.');
    $idem=hash('sha256',session_id().':'.$key);$requestHash=hash('sha256',json_encode($normalized,JSON_THROW_ON_ERROR));
    return pa_store_tx(static function()use($normalized,$idem,$requestHash){
        $find=pa_store_db()->prepare('SELECT * FROM orders WHERE idempotency_hash=?');$find->execute([$idem]);$existing=$find->fetch();
        if($existing){if(!hash_equals($existing['request_hash'],$requestHash))pa_auth_fail(409,'idempotency_conflict','Esse pedido já foi preparado com outros dados. Inicie um novo pedido.');return ['order'=>pa_store_order($existing),'orderToken'=>pa_store_order_token($existing)];}
        $items=[];foreach($normalized['quantities'] as $id=>$quantity){$product=pa_store_find_product($id);if(!$product)pa_auth_fail(409,'product_removed','Um produto da sua lista foi removido. Atualize a lista antes de preparar o pedido.');$items[]=['produtoId'=>$id,'nome'=>$product['nome'],'preco'=>$product['preco_cents']===null?null:((int)$product['preco_cents'])/100,'quantidade'=>$quantity];}
        $now=pa_store_now();$row=['id'=>pa_store_uid('ped'),'codigo'=>'PA-'.gmdate('ymd').'-'.strtoupper(bin2hex(random_bytes(5))),'cliente_nome'=>$normalized['cliente']['nome'],'cliente_contato'=>$normalized['cliente']['contato'],'observacao'=>$normalized['observacao'],'items_json'=>json_encode($items,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),'status'=>'pendente','created_at'=>$now,'updated_at'=>$now,'token_hash'=>null,'idempotency_hash'=>$idem,'request_hash'=>$requestHash];
        $token=pa_store_order_token($row);$row['token_hash']=hash('sha256',$token);$insert=pa_store_db()->prepare('INSERT INTO orders VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');$insert->execute(array_values($row));return ['order'=>pa_store_order($row),'orderToken'=>$token];
    });
}
function pa_store_receipt(string $id,string $token):array {
    if(!preg_match('/\A[a-f0-9]{64}\z/',$token))pa_auth_fail(404,'order_not_found','Pedido não encontrado.');
    $find=pa_store_db()->prepare('SELECT * FROM orders WHERE id=?');$find->execute([$id]);$row=$find->fetch();if(!$row||!is_string($row['token_hash'])||!hash_equals($row['token_hash'],hash('sha256',$token)))pa_auth_fail(404,'order_not_found','Pedido não encontrado.');return pa_store_order($row);
}
function pa_store_treat_order(string $id,bool $approve):array {
    return pa_store_tx(static function()use($id,$approve){$find=pa_store_db()->prepare('SELECT * FROM orders WHERE id=?');$find->execute([$id]);$row=$find->fetch();if(!$row)pa_auth_fail(404,'order_not_found','Pedido não encontrado.');if($row['status']!=='pendente')pa_auth_fail(409,'order_already_treated','Somente pedidos pendentes podem ser tratados. Este pedido já foi aprovado ou cancelado.');
        $items=json_decode($row['items_json'],true,32,JSON_THROW_ON_ERROR);
        if($approve)foreach($items as &$item){$product=pa_store_find_product($item['produtoId']);$available=$product?min((int)$product['estoque'],$item['quantidade']):0;$item['quantidadeEstoque']=$available;$item['quantidadeEncomenda']=$item['quantidade']-$available;if($available){$before=(int)$product['estoque'];$product['estoque']=$before-$available;$product['updated_at']=pa_store_now($product['updated_at']);pa_store_write_product($product);pa_store_movement($product,$before,'Venda aprovada: '.$row['codigo'],'aprovacao',$id);}}unset($item);
        $row['items_json']=json_encode($items,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$row['status']=$approve?'aprovado':'cancelado';$row['updated_at']=pa_store_now($row['updated_at']);$query=pa_store_db()->prepare('UPDATE orders SET items_json=?,status=?,updated_at=? WHERE id=?');$query->execute([$row['items_json'],$row['status'],$row['updated_at'],$id]);return pa_store_order($row);
    });
}
function pa_store_save_review(array $input,array $visitor):array {
    if(!is_string($visitor['id']??null)||!preg_match('/\Avisitante-[a-f0-9]{48}\z/',$visitor['id']))pa_auth_fail(403,'visitor_required','Recarregue a página para continuar a avaliação.');
    $productId=pa_store_id($input['produtoId']??null);$name=pa_store_text($input['nome']??null,'Nome',2,80);$rating=pa_store_int($input['nota']??null,1,5);$comment=pa_store_text($input['comentario']??null,'Comentário',10,1200);
    return pa_store_tx(static function()use($productId,$name,$rating,$comment,$visitor){$product=pa_store_find_product($productId);if(!$product)pa_auth_fail(404,'product_not_found','Este produto não está mais no catálogo.');$find=pa_store_db()->prepare('SELECT * FROM reviews WHERE produto_id=? AND autor_id=?');$find->execute([$productId,$visitor['id']]);$existing=$find->fetch();if($existing&&$existing['legacy'])pa_auth_fail(403,'review_forbidden','O histórico importado não pode ser alterado por visitantes.');$now=pa_store_now($existing['updated_at']??null);$id=$existing['id']??pa_store_uid('avaliacao');$created=$existing['created_at']??$now;
        $query=pa_store_db()->prepare('INSERT INTO reviews(id,produto_id,produto_nome,autor_id,nome,nota,comentario,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?) ON CONFLICT(produto_id,autor_id) DO UPDATE SET produto_nome=excluded.produto_nome,nome=excluded.nome,nota=excluded.nota,comentario=excluded.comentario,updated_at=excluded.updated_at');$query->execute([$id,$productId,$product['nome'],$visitor['id'],$name,$rating,$comment,$created,$now]);return ['id'=>$id,'produtoId'=>$productId,'produtoNome'=>$product['nome'],'autorId'=>$visitor['id'],'nome'=>$name,'nota'=>$rating,'comentario'=>$comment,'createdAt'=>$created,'updatedAt'=>$now,'legacy'=>false,'guest'=>true];
    });
}
function pa_store_remove_review(string $id,array $visitor):void { pa_store_tx(static function()use($id,$visitor){$query=pa_store_db()->prepare('SELECT autor_id,legacy FROM reviews WHERE id=?');$query->execute([$id]);$row=$query->fetch();if(!$row)pa_auth_fail(404,'review_not_found','Avaliação não encontrada.');if($row['legacy']||$row['autor_id']!==($visitor['id']??null))pa_auth_fail(403,'review_forbidden','Você só pode excluir avaliações criadas neste navegador.');$delete=pa_store_db()->prepare('DELETE FROM reviews WHERE id=?');$delete->execute([$id]);}); }

function pa_store_import_reviews(array $data):array {
    return pa_store_tx(static function()use($data){return pa_store_import_reviews_rows($data);});
}
function pa_store_import_reviews_rows(array $data):array {
    if(($data['version']??null)!==1||!is_array($data['reviews']??null)||!array_is_list($data['reviews'])||count($data['reviews'])>2000)pa_auth_fail(400,'invalid_backup','O arquivo deve conter até 2.000 avaliações válidas.');
    $seen=[];$pairs=[];$rows=[];
    foreach($data['reviews'] as $input){$input=pa_store_object($input);$sourceId=pa_store_id($input['id']??null);$pid=pa_store_id($input['produtoId']??null);$sourceAuthor=pa_store_id($input['autorId']??null);$namespace=array_key_exists('legacy',$input)&&$input['legacy']===false?'central:':'local:';$author=str_starts_with($sourceAuthor,'historico-')?$sourceAuthor:'historico-'.substr(hash('sha256',$namespace.$sourceAuthor),0,32);$id=str_starts_with($sourceId,'importada-')?$sourceId:'importada-'.substr(hash('sha256',$namespace.$sourceId),0,32);$pair=$pid.':'.$author;
        if(isset($seen[$sourceId]))pa_auth_fail(400,'invalid_backup','Há avaliações duplicadas no arquivo.');$seen[$sourceId]=true;$created=pa_store_date($input['createdAt']??null);$updated=pa_store_date($input['updatedAt']??null);if($updated<$created)pa_auth_fail(400,'invalid_backup','As datas de uma avaliação não são válidas.');
        $row=[$id,$pid,pa_store_text($input['produtoNome']??null,'Nome do produto',2,160),$author,pa_store_text($input['nome']??null,'Nome do perfil',2,80),pa_store_int($input['nota']??null,1,5),pa_store_text($input['comentario']??null,'Comentário',10,1200),$created,$updated];
        $find=pa_store_db()->prepare('SELECT produto_id,autor_id,legacy FROM reviews WHERE id=?');$find->execute([$sourceId]);$current=$find->fetch();
        if($current&&!$current['legacy']&&$current['produto_id']===$pid&&pa_store_review_author($current['autor_id'])===$sourceAuthor)continue;
        if(isset($pairs[$pair]))pa_auth_fail(400,'invalid_backup','Há avaliações duplicadas no arquivo.');$pairs[$pair]=true;
        $rows[]=$row;
    }
    $query=pa_store_db()->prepare('INSERT INTO reviews(id,produto_id,produto_nome,autor_id,nome,nota,comentario,created_at,updated_at,legacy) VALUES(?,?,?,?,?,?,?,?,?,1) ON CONFLICT(produto_id,autor_id) DO UPDATE SET produto_nome=excluded.produto_nome,nome=excluded.nome,nota=excluded.nota,comentario=excluded.comentario,updated_at=excluded.updated_at WHERE reviews.legacy=1');
    foreach($rows as $row)$query->execute($row);
    return ['imported'=>count($rows)];
}

function pa_store_import(array $data):array {
    return pa_store_tx(static function()use($data){
        if(($data['version']??null)!==1)pa_auth_fail(400,'invalid_backup','Versão do backup incompatível.');
        foreach(['products'=>500,'orders'=>5000,'movements'=>20000] as $key=>$limit)if(!isset($data[$key])||!is_array($data[$key])||!array_is_list($data[$key])||count($data[$key])>$limit)pa_auth_fail(400,'invalid_backup','A lista de '.$key.' não é válida para restauração.');
        $products=[];$orders=[];$movements=[];$codes=[];
        foreach($data['products'] as $input){$product=pa_store_normalize_product(pa_store_object($input),null,true);if(isset($products[$product['id']]))pa_auth_fail(400,'invalid_backup','Produto duplicado no backup.');$products[$product['id']]=$product;}
        foreach($data['orders'] as $input){$input=pa_store_object($input);$id=pa_store_id($input['id']??null);$code=pa_store_text($input['codigo']??null,'Código do pedido',3,40);if(isset($orders[$id])||isset($codes[$code]))pa_auth_fail(400,'invalid_backup','Pedido duplicado no backup.');$codes[$code]=true;$status=$input['status']??null;if(!in_array($status,['pendente','aprovado','cancelado'],true))pa_auth_fail(400,'invalid_backup','Status de pedido inválido.');
            $client=pa_store_object($input['cliente']??null);$name=pa_store_text($client['nome']??null,'Nome do cliente',2,100);$contact=pa_store_text($client['contato']??null,'Contato',5,120);$items=$input['items']??null;if(!is_array($items)||!array_is_list($items)||!count($items)||count($items)>100)pa_auth_fail(400,'invalid_backup','Itens de pedido inválidos.');$seen=[];$normalized=[];
            foreach($items as $item){$item=pa_store_object($item);$pid=pa_store_id($item['produtoId']??null);if(isset($seen[$pid]))pa_auth_fail(400,'invalid_backup','Produto repetido no pedido.');$seen[$pid]=true;$qty=pa_store_int($item['quantidade']??null,1,99);$cents=pa_store_price($item['preco']??null);$value=['produtoId'=>$pid,'nome'=>pa_store_text($item['nome']??null,'Nome do item',2,160),'preco'=>$cents===null?null:$cents/100,'quantidade'=>$qty];if($status==='aprovado'){$stock=pa_store_int($item['quantidadeEstoque']??null,0,99);$back=pa_store_int($item['quantidadeEncomenda']??null,0,99);if($stock+$back!==$qty)pa_auth_fail(400,'invalid_backup','Alocação de estoque inválida.');$value+=['quantidadeEstoque'=>$stock,'quantidadeEncomenda'=>$back];}elseif(isset($item['quantidadeEstoque'])||isset($item['quantidadeEncomenda']))pa_auth_fail(400,'invalid_backup','Pedido pendente ou cancelado não pode ter baixa de estoque.');$normalized[]=$value;}
            $created=pa_store_date($input['createdAt']??null);$updated=pa_store_date($input['updatedAt']??null);if($updated<$created)pa_auth_fail(400,'invalid_backup','Datas de pedido inválidas.');$orders[$id]=[$id,$code,$name,$contact,pa_store_text($input['observacao']??'','Observação',0,2000),json_encode($normalized,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$status,$created,$updated,null,null,null];
        }
        foreach($data['movements'] as $input){$input=pa_store_object($input);$id=pa_store_id($input['id']??null);if(isset($movements[$id]))pa_auth_fail(400,'invalid_backup','Movimentação duplicada no backup.');$type=$input['tipo']??null;if(!in_array($type,['cadastro','ajuste','aprovacao','remocao'],true))pa_auth_fail(400,'invalid_backup','Tipo de movimentação inválido.');$before=pa_store_int($input['estoqueAntes']??null);$after=pa_store_int($input['estoqueDepois']??null);$qty=pa_store_int($input['quantidade']??null,-1000000);if($after-$before!==$qty)pa_auth_fail(400,'invalid_backup','Saldo da movimentação inválido.');$orderId=isset($input['pedidoId'])?pa_store_id($input['pedidoId']):null;if($orderId&&!isset($orders[$orderId]))pa_auth_fail(400,'invalid_backup','Movimentação de pedido inexistente.');$movements[$id]=[$id,pa_store_id($input['produtoId']??null),$type,$qty,$before,$after,pa_store_text($input['motivo']??null,'Motivo',1,300),$orderId,pa_store_date($input['createdAt']??null)];}
        // Keep private receipt authorization on a same-server restoration of an existing order.
        $receipts=pa_store_db()->query('SELECT id,token_hash,idempotency_hash,request_hash FROM orders')->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
        pa_store_db()->exec('DELETE FROM products; DELETE FROM orders; DELETE FROM movements;');
        foreach($products as $product)pa_store_write_product($product);
        $write=pa_store_db()->prepare('INSERT INTO orders VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');foreach($orders as $id=>$order){if(isset($receipts[$id])){$order[9]=$receipts[$id]['token_hash'];$order[10]=$receipts[$id]['idempotency_hash'];$order[11]=$receipts[$id]['request_hash'];}$write->execute($order);}
        $write=pa_store_db()->prepare('INSERT INTO movements VALUES(?,?,?,?,?,?,?,?,?)');foreach($movements as $movement)$write->execute($movement);
        if(array_key_exists('reviews',$data))pa_store_import_reviews_rows(['version'=>1,'reviews'=>$data['reviews']]);
        return ['products'=>count($products),'orders'=>count($orders),'movements'=>count($movements)];
    });
}
