<?php
/**
 * vote1_callback.php — Callback Maishapay pour vote1 (Mobile Money + Carte)
 * Gère:
 *  - REST API callback (JSON POST) pour MOBILEMONEY et CARD
 *  - Checkout callback (GET avec status, description, transactionRefId, operatorRefId)
 *  - Mise à jour transactions_votes
 *  - Redirection vers vote1.php?candidat=...&receipt=ref pour affichage reçu
 * 
 * Logs: maishapay_callback.log, maishapay.log
 * Merchant ID: 000945 Sandbox
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

define('DB_HOST', 'localhost:3306');
define('DB_NAME', 'mayi1275_zaloria_multisysteme');
define('DB_USER', 'mayi1275_zaloriatech');
define('DB_PASS', '07/09/1996/O2switch');

define('PAIEMENT_ETAT_EN_ATTENTE', 'en_attente');
define('PAIEMENT_ETAT_CONFIRME',   'confirme');
define('PAIEMENT_ETAT_ECHEC',      'echoue');

function getDB(): PDO {
    static $pdo=null;
    if($pdo===null){
        $dsn='mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4';
        $pdo=new PDO($dsn,DB_USER,DB_PASS,[
            PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function logCallback($msg){
    file_put_contents(__DIR__.'/maishapay_callback.log', date('c').' '.$msg.PHP_EOL, FILE_APPEND);
}

// Récupère toutes les sources
$rawInput = file_get_contents('php://input');
$getParams = $_GET;
$postParams = $_POST;
$allParams = array_merge($getParams, $postParams);

logCallback("INCOMING IP:".$_SERVER['REMOTE_ADDR']." METHOD:".$_SERVER['REQUEST_METHOD']." GET:".json_encode($getParams)." POST:".json_encode($postParams)." RAW:".$rawInput." CT:".($_SERVER['CONTENT_TYPE']??''));

// Détection référence
$reference = null;
$possibleRefKeys = ['ref','reference','transactionRefId','transactionReference','originatingTransactionId','transactionId','order_id'];
foreach($possibleRefKeys as $k){
    if(isset($allParams[$k]) && !empty($allParams[$k])){
        $reference = trim($allParams[$k]);
        // Si c'est transactionId numérique Maishapay, on doit chercher par id_transaction_unipesa? Mais on préfère numero_reference
        // On vérifie si ça ressemble à notre format LME-GROUP-... ou CARD...
        if(strpos($reference, 'LME')!==false || strpos($reference, 'CARD')!==false || strlen($reference)>10){
            break;
        }
    }
}
// Si raw JSON contient référence
if(!$reference && $rawInput){
    $json = json_decode($rawInput, true);
    if($json){
        foreach($possibleRefKeys as $k){
            if(isset($json[$k]) && !empty($json[$k])){
                $reference = trim($json[$k]);
                if(strpos($reference, 'LME')!==false || strpos($reference, 'CARD')!==false || strlen($reference)>10) break;
            }
        }
        // aussi dans data
        if(!$reference && isset($json['data'])){
            foreach($possibleRefKeys as $k){
                if(isset($json['data'][$k])){
                    $reference = trim($json['data'][$k]);
                    break;
                }
            }
        }
    }
}

// Si ref dans URL query param ref (notre callbackUrl), on le prend en priorité
if(isset($_GET['ref']) && !empty($_GET['ref'])){
    $reference = trim($_GET['ref']);
}

// Statut
$status = null;
$statusKeys = ['status','statusCode','code'];
foreach($statusKeys as $k){
    if(isset($allParams[$k])){
        $status = $allParams[$k];
        break;
    }
}
if($status===null && $rawInput){
    $json = json_decode($rawInput, true);
    if($json){
        foreach($statusKeys as $k){
            if(isset($json[$k])){ $status = $json[$k]; break; }
            if(isset($json['data'][$k])){ $status = $json['data'][$k]; break; }
        }
    }
}

$description = $allParams['description'] ?? $allParams['statusDescription'] ?? $allParams['message'] ?? '';
$operatorRefId = $allParams['operatorRefId'] ?? $allParams['transactionId'] ?? $allParams['operatorRef'] ?? '';
$transactionId = $allParams['transactionId'] ?? $allParams['transactionRefId'] ?? $operatorRefId;

// Mapping statut
$internalStatus = PAIEMENT_ETAT_EN_ATTENTE;
$statusInt = is_numeric($status) ? (int)$status : 0;
$statusStr = strtolower((string)$status);

if(in_array($statusInt, [200,201,202]) || $statusStr==='accepted' || $statusStr==='success' || $statusStr==='200' || $statusStr==='202'){
    $internalStatus = PAIEMENT_ETAT_CONFIRME;
} elseif(in_array($statusInt, [400,401,402,403,404,405,500,502,503]) || strpos($statusStr,'fail')!==false || strpos($statusStr,'error')!==false || strpos($statusStr,'cancel')!==false || strpos($statusStr,'refused')!==false){
    $internalStatus = PAIEMENT_ETAT_ECHEC;
} else {
    // Si pas de statut mais on a une référence et on est en GET après paiement carte, on peut considérer succès si pas d'erreur explicite?
    // Pour Checkout, Maishapay docs: status=202 Accepted = succès
    // On laisse en_attente par défaut si statut vide, mais si method=card et pas d'erreur, on peut marquer confirme après vérif manuelle?
    // Pour l'instant, si description contient Accepted, on confirme
    if(stripos($description, 'accept')!==false){
        $internalStatus = PAIEMENT_ETAT_CONFIRME;
    }
}

logCallback("PARSED ref=$reference status=$status (int $statusInt str $statusStr) => internal=$internalStatus desc=$description opRef=$operatorRefId");

// Si pas de référence, on ne peut pas mettre à jour, mais on log et on affiche erreur
if(!$reference){
    logCallback("ERROR No reference found, cannot update DB");
    // Si c'est une requête navigateur, afficher page d'erreur
    if($_SERVER['REQUEST_METHOD']==='GET' && !empty($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'curl')===false){
        echo "<h2>Callback MaishaPay - Référence manquante</h2><p>Paramètres reçus: ".htmlspecialchars(json_encode($allParams))."</p><p>Raw: ".htmlspecialchars($rawInput)."</p>";
        echo "<p><a href='index.php'>Retour accueil</a></p>";
        exit;
    }
    echo "OK - No ref"; exit;
}

// Mise à jour DB
try{
    $pdo=getDB();
    // Vérifie si transaction existe
    $stmt=$pdo->prepare("SELECT * FROM transactions_votes WHERE numero_reference=? LIMIT 1");
    $stmt->execute([$reference]);
    $existing=$stmt->fetch();

    if(!$existing){
        // Peut-être que reference est en fait transactionId Maishapay numérique, on cherche par id_transaction_unipesa
        $stmt2=$pdo->prepare("SELECT * FROM transactions_votes WHERE id_transaction_unipesa=? ORDER BY transaction_id DESC LIMIT 1");
        $stmt2->execute([$reference]);
        $existing=$stmt2->fetch();
        if($existing){
            $reference = $existing['numero_reference']; // on corrige
        }
    }

    if($existing){
        // Si déjà confirmé/echoue, on ne écrase pas sauf si on passe de en_attente à confirme
        if($existing['etat_paiement']===PAIEMENT_ETAT_EN_ATTENTE || $internalStatus===PAIEMENT_ETAT_CONFIRME){
            $sql = "UPDATE transactions_votes SET etat_paiement=:s, confirme_le=NOW(), id_transaction_unipesa=:tid, ref_transaction_unipesa=:tref, message_retour=:msg WHERE numero_reference=:r";
            $pdo->prepare($sql)->execute([
                ':s'=>$internalStatus,
                ':tid'=>$transactionId ?: $existing['id_transaction_unipesa'],
                ':tref'=>$operatorRefId ?: $existing['ref_transaction_unipesa'],
                ':msg'=> $description ?: 'Callback MaishaPay '.$status,
                ':r'=>$reference,
            ]);
            logCallback("DB UPDATED ref=$reference to $internalStatus");
        } else {
            logCallback("DB SKIP already ".$existing['etat_paiement']." ref=$reference");
        }

        // Redirection navigateur si c'est un humain (pas webhook)
        $isBrowser = isset($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'Mozilla')!==false;
        $method = $_GET['method'] ?? $existing['moyen_paiement'] ?? 'mobile';
        $candidate_id = $_GET['candidate_id'] ?? $existing['participante_id'];
        $concours_id = $_GET['concours_id'] ?? $existing['concours_id'];
        $etape_id = $_GET['etape_id'] ?? $existing['etape_id'];

        if($isBrowser || isset($_GET['ref'])){
            // Redirige vers vote1.php avec receipt
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'https';
            $host = $_SERVER['HTTP_HOST'] ?? 'lme-group.zaloriatech.com';
            $redirectUrl = $scheme.'://'.$host.'/vote1.php?candidat='.(int)$candidate_id.'&concours_id='.(int)$concours_id.'&etape_id='.($etape_id? (int)$etape_id : '').'&receipt='.urlencode($reference).'&status='.urlencode($internalStatus).'&method='.urlencode($method);
            logCallback("REDIRECT BROWSER to $redirectUrl");
            header('Location: '.$redirectUrl);
            echo "<html><body>Redirection vers <a href='$redirectUrl'>$redirectUrl</a><script>window.location='$redirectUrl'</script></body></html>";
            exit;
        }

        echo "OK"; exit;

    } else {
        logCallback("DB NOT FOUND ref=$reference");
        // Même si pas trouvé, si c'est navigateur, on redirige quand même vers vote1 avec receipt pour afficher erreur
        if(isset($_GET['candidate_id'])){
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'https';
            $host = $_SERVER['HTTP_HOST'] ?? 'lme-group.zaloriatech.com';
            $redirectUrl = $scheme.'://'.$host.'/vote1.php?candidat='.(int)($_GET['candidate_id']).'&receipt='.urlencode($reference).'&status=notfound';
            header('Location: '.$redirectUrl);
            exit;
        }
        echo "OK - ref not found but logged"; exit;
    }

}catch(Exception $e){
    logCallback("DB ERROR ".$e->getMessage()." ref=$reference");
    http_response_code(500);
    echo "ERROR ".$e->getMessage();
    exit;
}
