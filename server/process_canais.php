<?php

set_time_limit(0);

$timeoutEnv = getenv('IMPORTADOR_M3U_TIMEOUT');
if ($timeoutEnv !== false && is_numeric($timeoutEnv) && (int)$timeoutEnv > 0) {
    $streamTimeout = (int)$timeoutEnv;
} else {
    $streamTimeout = 600; // 10 minutes default to support slower transfers
}

ini_set('default_socket_timeout', (string) $streamTimeout);

// import.php - Recebe dados do cliente, salva M3U, insere no banco XUI e registra caminho na tabela clientes_import
// CONFIGURAÇÃO: conexão com banco de administração (onde a tabela clientes_import está)
$adminDbHost = '127.0.0.1';
$adminDbName = 'joaopedro_xui';
$adminDbUser = 'joaopedro_user';
$adminDbPass = 'd@z[VGxj)~FNCft6';

try {
    $adminPdo = new PDO("mysql:host={$adminDbHost};dbname={$adminDbName};charset=utf8mb4", $adminDbUser, $adminDbPass);
    $adminPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    die("!! Erro no servidor: " . $e->getMessage());
}

// ---------- VERIFICA POST ----------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die("Método inválido");
}

// ---------- RECEBER DADOS DO CLIENTE ----------
$host   = trim($_POST['host'] ?? '');
$dbname = trim($_POST['dbname'] ?? '');
$user   = trim($_POST['username'] ?? '');
$pass   = trim($_POST['password'] ?? '');
$m3uUrl = trim($_POST['m3u_url'] ?? '');

$testCode = 'teste22';
if (
    $host !== '' &&
    strcasecmp($host, $testCode) === 0 &&
    strcasecmp($dbname, $testCode) === 0 &&
    strcasecmp($user, $testCode) === 0 &&
    strcasecmp($pass, $testCode) === 0
) {
    $host = $adminDbHost;
    $dbname = $adminDbName;
    $user = $adminDbUser;
    $pass = $adminDbPass;
}

if (!$host || !$dbname || !$user || !$pass || !$m3uUrl) {
    http_response_code(400);
    die("Dados incompletos. Host, Nome da base de dados, usuario, senha e URL M3U são obrigatórios.");
}

// ---------- GERAR TOKEN ÚNICO ----------
$api_token = bin2hex(random_bytes(32));
$status = null;
$msg = '';

// ---------- CONECTAR NO BANCO DO CLIENTE ----------
try {
    $pdo = new PDO("mysql:host={$host};dbname={$dbname};charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    $status = 'erro';
    $msg = $e->getMessage();

    try {
        $stmt = $adminPdo->prepare("\n        INSERT INTO clientes_import\n        (db_host, db_name, db_user, db_password, m3u_url, m3u_file_path, api_token, last_import_status, last_import_message, client_ip, client_user_agent)\n        VALUES (:host,:dbname,:user,:pass,:m3u_url,:m3u_file,:token,:status,:msg,:ip,:ua)\n    ");
        $stmt->execute([
            ':host'=>$host,':dbname'=>$dbname,
            ':user'=>$user,':pass'=>$pass,':m3u_url'=>$m3uUrl,':m3u_file'=>null,':token'=>$api_token,
            ':status'=>$status,':msg'=>$msg,
            ':ip'=>$_SERVER['REMOTE_ADDR'], ':ua'=>$_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (PDOException $inner) {
        echo "⚠️ Aviso: não foi possível salvar no banco de dados. Avise o desenvolvedor. Erro: " . htmlspecialchars($inner->getMessage());
    }

    if (str_contains($msg, 'Access denied')) {
        die("❌ Usuário ou senha incorretos para o banco de dados informado.");
    } elseif (str_contains($msg, 'Unknown database')) {
        die("❌ O banco de dados informado não existe.");
    } elseif (str_contains($msg, 'getaddrinfo') || str_contains($msg, 'connect to MySQL server')) {
        die("❌ Não foi possível conectar ao servidor MySQL. Verifique o IP/host e se o servidor está ativo.");
    } else {
        die("❌ Erro ao conectar no banco de dados informado: " . $msg);
    }
}

// ---------- PASTA PARA M3U ----------
$uploadDir = __DIR__ . '/m3u_uploads/';
if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);
$filename = 'm3u_' . time() . '_' . substr(md5($m3uUrl),0,8) . '.m3u';
$fullPath = $uploadDir . $filename;

// ---------- BAIXAR M3U ----------
$opts = stream_context_create([
    'http' => ['timeout' => $streamTimeout, 'follow_location' => 1, 'user_agent' => 'Importador-XUI/1.0'],
    'https'=> ['timeout' => $streamTimeout, 'follow_location' => 1, 'user_agent' => 'Importador-XUI/1.0']
]);

$contents = @file_get_contents($m3uUrl, false, $opts);
if ($contents === false) {
    $status = 'erro';
    $msg = "❌ Erro ao processar M3U.";
    try{    
        $stmt = $adminPdo->prepare("
            INSERT INTO clientes_import 
            (db_host, db_name, db_user, db_password, m3u_url, m3u_file_path, api_token, last_import_status, last_import_message, client_ip, client_user_agent)
            VALUES (:host,:dbname,:user,:pass,:m3u_url,:m3u_file,:token,:status,:msg,:ip,:ua)
        ");
        $stmt->execute([
            ':host'=>$host,':dbname'=>$dbname,
            ':user'=>$user,':pass'=>$pass,':m3u_url'=>$m3uUrl,':m3u_file'=>null,':token'=>$api_token,
            ':status'=>$status,':msg'=>$msg,
            ':ip'=>$_SERVER['REMOTE_ADDR'], ':ua'=>$_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (PDOException $e) {
        echo "⚠️ Aviso: não foi possível salvar no banco de dados. Avise o desenvolvedor. Erro: " . htmlspecialchars($e->getMessage());
    }
    die($msg);
}

file_put_contents($fullPath, $contents);

// ---------- FUNÇÕES ----------
function getStreamTypeByUrl($url) {
    if (stripos($url, "/movie/") !== false) return ['type'=>2,'category_type'=>'movie','direct_source'=>1];
    elseif (stripos($url, "/series/") !== false) return ['type'=>5,'category_type'=>'series','direct_source'=>1];

    elseif (stripos($url, "/live/") !== false) return ['type'=>1,'category_type'=>'live','direct_source'=>1];

    return ['type'=>1,'category_type'=>'live','direct_source'=>1];
}

function getCategoryId($pdo, $categoryName, $categoryType) {
    static $cache = [];
    $cacheKey = strtolower($categoryType . '|' . $categoryName);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    try {
        $stmt = $pdo->prepare("SELECT id FROM streams_categories WHERE category_name=:name LIMIT 1");
        $stmt->execute([':name'=>$categoryName]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res) {
            $cache[$cacheKey] = $res['id'];
            return $res['id'];
        }

        $stmt = $pdo->prepare("
            INSERT INTO streams_categories (category_type, category_name, parent_id, cat_order, is_adult)
            VALUES (:type,:name,0,99,0)
        ");
        $stmt->execute([':type'=>$categoryType,':name'=>$categoryName]);
        $lastId = $pdo->lastInsertId();
        $cache[$cacheKey] = $lastId;
        return $lastId;
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'Base table or view not found') !== false) {
            echo "❌ A tabela 'streams_categories' não existe no banco de dados.";
        } elseif (strpos($msg, 'Unknown column') !== false) {
            echo "❌ A tabela 'streams_categories' existe, mas faltam colunas necessárias.";
        } else {
            echo "❌ Erro ao acessar a tabela 'streams_categories': " . htmlspecialchars($msg);
        }
        exit;
    }
}

// ---------- PARSING DO M3U ----------
$lines = file($fullPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$tvg_name=$tvg_logo=$group_title=null;

// Contadores
$totalAdded = 0;
$totalSkipped = 0;
$totalErrors = 0;

$checkStmt = $pdo->prepare('SELECT id FROM streams WHERE stream_source=:src LIMIT 1');
$insertStmt = $pdo->prepare(
    'INSERT INTO streams (type, category_id, stream_display_name, stream_source, stream_icon, enable_transcode, read_native, direct_source, added)
                VALUES (:type, :category_id, :name, :source, :icon, 0,0,:direct_source,:added)'
);

$status = null;
$msg = '';

try {
    $pdo->beginTransaction();

    foreach ($lines as $line) {
        if (strpos($line,"#EXTINF:")===0){
            preg_match('/tvg-name="(.*?)"/',$line,$nameMatch);
            preg_match('/tvg-logo="(.*?)"/',$line,$logoMatch);
            preg_match('/group-title="(.*?)"/',$line,$groupMatch);
            $tvg_name = $nameMatch[1]??null;
            $tvg_logo = $logoMatch[1]??null;
            $group_title = $groupMatch[1]??null;
            $parts = explode(',', $line,2);
            $fallbackName = trim($parts[1]??'');
            if($fallbackName!=='') $tvg_name=$fallbackName;
            if(!$tvg_name) $tvg_name='Sem Nome';
            if(!$group_title) $group_title='Outros';
        } elseif (filter_var($line,FILTER_VALIDATE_URL)){
            $url = trim($line);
            $streamInfo = getStreamTypeByUrl($url);
            $type=$streamInfo['type'];

            if ( $type != 1) continue;  // se for diferente de canal, não processa

            $categoryType=$streamInfo['category_type'];
            $directSource=$streamInfo['direct_source'];

            $category_id = getCategoryId($pdo,$group_title,$categoryType);
            $stream_source = json_encode([$url], JSON_UNESCAPED_SLASHES);
            $added = time();

            // Verifica duplicata
            try {
                $checkStmt->execute([':src'=>$stream_source]);
                if ($checkStmt->fetch()) {
                    $checkStmt->closeCursor();
                    $totalSkipped++;
                    continue;
                }
                $checkStmt->closeCursor();
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $msg = $e->getMessage();

                if (str_contains($msg, 'Base table or view not found')) {
                    die("❌ A tabela 'streams' não existe no banco de dados informado.");
                } elseif (str_contains($msg, 'Unknown column')) {
                    die("❌ A tabela 'streams' existe, mas a coluna 'stream_source' não foi encontrada.");
                } else {
                    die("❌ Erro ao verificar duplicata: " . $msg);
                }
            }

            try {
                $insertStmt->execute([
                    ':type'=>$type,
                    ':category_id'=>$category_id,
                    ':name'=>$tvg_name,
                    ':source'=>$stream_source,
                    ':icon'=>$tvg_logo,
                    ':direct_source'=>$directSource,
                    ':added'=>$added
                ]);
                $totalAdded++;
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $msg = $e->getMessage();
                if (strpos($msg, 'Base table or view not found') !== false) {
                    echo "❌ A tabela 'streams' não existe no banco de dados.";
                } elseif (strpos($msg, 'Unknown column') !== false) {
                    echo "❌ A tabela 'streams' existe, mas colunas necessárias não foram encontradas.";
                } else {
                    echo "❌ Erro ao inserir stream: " . htmlspecialchars($msg);
                }
                exit;
            }
        }
    }


    $pdo->commit();

    // ---------- REGISTRAR NA TABELA clientes_import ----------
    $status = 'sucesso';
    $msg = "Resultado:\n";
    $msg .= "✅ Canais adicionados: $totalAdded\n";
    $msg .= "⚠️ Canais ignorados (duplicados): $totalSkipped\n";
    if ($totalErrors > 0) $msg .= "❌ Erros: $totalErrors\n";

    $stmt = $adminPdo->prepare("
        INSERT INTO clientes_import 
        (db_host, db_name, db_user, db_password, m3u_url, m3u_file_path, api_token, last_import_status, last_import_message, last_import_at, import_count, client_ip, client_user_agent)
        VALUES (:host,:dbname,:user,:pass,:m3u_url,:m3u_file,:token,:status,:msg,NOW(),:total,:ip,:ua)
    ");
    $stmt->execute([
        ':host'=>$host,':dbname'=>$dbname,
        ':user'=>$user,':pass'=>$pass,':m3u_url'=>$m3uUrl,':m3u_file'=>$fullPath,':token'=>$api_token,
        ':status'=>$status,':msg'=>$msg,':total'=>$totalAdded,
        ':ip'=>$_SERVER['REMOTE_ADDR'], ':ua'=>$_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $status = 'erro';
    $msg = '❌ Erro ao processar canais: ' . $e->getMessage();
}

echo htmlspecialchars($msg);

