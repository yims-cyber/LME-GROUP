<?php
/**
 * voter_callback.php — Callback Maishapay pour vote1 (Carte Visa/Mastercard uniquement)
 * Mobile Money = Unipesa (géré dans voter_api.php raw callback)
 * 
 * Fix du bug observé 2026-08-21:
 *  Maishapay Checkout fait: callbackUrl + "/?status=200&description=APPROVED..."
 *  Si callbackUrl contient déjà ?ref=...&method=card, on obtient:
 *    ...card_type=VISA/?status=200  => $_GET['status'] vide, card_type contient "/?status=200"
 *  Solution:
 *  - callbackUrl simplifié à ?ref=REF seulement (plus de &method etc)
 *  - Parsing robuste via REQUEST_URI regex pour status/description même si double ?
 *  - APPROVED => confirme (pas seulement Accepted)
 * 
 * Logs: maishapay_callback.log, maishapay_callback_raw.log
 * Merchant 000945 Sandbox
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

function ensureMaishapaySchema(PDO $pdo){
    static $done=false;
    if($done) return;
    $done=true;
    try{
        $stmt=$pdo->query("SHOW COLUMNS FROM transactions_votes LIKE 'moyen_paiement'");
        $col=$stmt->fetch();
        if($col && strpos($col['Type'],"'visa'")===false){
            $pdo->exec("ALTER TABLE transactions_votes MODIFY COLUMN moyen_paiement ENUM('mpesa','airtel','orange','africell','carte','especes','manuel','visa','mastercard','maishapay_card','maishapay') NOT NULL DEFAULT 'carte'");
        }
    } catch(Exception $e){}
    try{
        $stmt=$pdo->query("SHOW COLUMNS FROM transactions_votes LIKE 'gateway_paiement'");
        if($stmt->rowCount()==0){
            $pdo->exec("ALTER TABLE transactions_votes ADD COLUMN gateway_paiement ENUM('unipesa','maishapay') NULL DEFAULT NULL AFTER moyen_paiement");
        }
    } catch(Exception $e){}
    try{
        $stmt=$pdo->query("SHOW COLUMNS FROM transactions_votes LIKE 'provider_maishapay'");
        if($stmt->rowCount()==0){
            $pdo->exec("ALTER TABLE transactions_votes ADD COLUMN provider_maishapay VARCHAR(32) NULL DEFAULT NULL AFTER gateway_paiement");
        }
    } catch(Exception $e){}
    try{
        $stmt=$pdo->query("SHOW COLUMNS FROM transactions_votes LIKE 'est_paiement_maishapay'");
        if($stmt->rowCount()==0){
            $pdo->exec("ALTER TABLE transactions_votes ADD COLUMN est_paiement_maishapay TINYINT(1) NULL DEFAULT NULL AFTER provider_maishapay");
        }
    } catch(Exception $e){}
}

session_start();
$rawInput = file_get_contents('php://input');
$getParams = $_GET;
$postParams = $_POST;
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$allParams = array_merge($getParams, $postParams);

logCallback("INCOMING IP:".($_SERVER['REMOTE_ADDR']??'')." METHOD:".($_SERVER['REQUEST_METHOD']??'')." URI:".$requestUri." GET:".json_encode($getParams)." POST:".json_encode($postParams)." RAW:".$rawInput." CT:".($_SERVER['CONTENT_TYPE']??''));

// Helper pour extraire param via regex sur URI complète (gère double ? et /? )
function extractFromUri($uri, $key){
    // cherche ?key= ou &key= ou /?key= ou /&key=
    $pattern = '/[\/\?&]'.preg_quote($key,'/').'=([^&\s\?]+)/i';
    if(preg_match($pattern, $uri, $m)){
        return urldecode($m[1]);
    }
    // fallback: cherche key= n'importe où
    $pattern2 = '/'.preg_quote($key,'/').'=([^&\s]+)/i';
    if(preg_match($pattern2, $uri, $m)){
        // enlève éventuel /?status=200 collé dans valeur précédente
        $val = $m[1];
        // si valeur contient ?status= ou &status=, couper avant
        if(strpos($val, '?')!==false){
            $val = explode('?', $val)[0];
        }
        return urldecode($val);
    }
    return null;
}

// Détection référence - priorité ?ref= dans callbackUrl simplifié + gestion _token (bug vu sur voter_checkout.php?_token=...)
$reference = null;
$token = $getParams['_token'] ?? $getParams['token'] ?? null;
if(!$token){
    $token = extractFromUri($requestUri, '_token') ?? extractFromUri($requestUri, 'token');
}

$reference = $getParams['ref'] ?? $getParams['reference'] ?? null;
if(!$reference){
    $reference = extractFromUri($requestUri, 'ref');
}
if(!$reference){
    $reference = extractFromUri($requestUri, 'reference');
}
// Si _token présent sans ref, tente session
if(!$reference && $token){
    if(!empty($_SESSION['maishapay_ref'])){
        $reference = $_SESSION['maishapay_ref'];
        logCallback("FOUND ref via session for token $token => $reference");
    }
}
// Si toujours pas, cherche transactionReference dans URI ou POST
if(!$reference){
    $reference = $allParams['transactionReference'] ?? $allParams['originatingTransactionId'] ?? $allParams['transactionRefId'] ?? null;
    // transactionRefId de Maishapay peut être leur ID interne (ex 2RJ1-...), pas notre ref, donc on évite si ça ne ressemble pas à LME
    if($reference && strpos($reference, 'LME')===false && strpos($reference, 'CARD')===false && strpos($reference, 'lme-group')===false){
        // ce n'est pas notre ref, on cherche ref via ?ref= déjà fait, sinon on garde mais on tentera recherche DB par operatorRefId
        // on ne reset pas ici, on garde pour fallback
    }
}
if(!$reference && $rawInput){
    $json = json_decode($rawInput, true);
    if($json){
        $reference = $json['ref'] ?? $json['transactionReference'] ?? $json['originatingTransactionId'] ?? $json['transactionRefId'] ?? null;
        if(!$reference && isset($json['data'])){
            $reference = $json['data']['transactionReference'] ?? $json['data']['originatingTransactionId'] ?? null;
        }
    }
}

// Si ref contient encore "?status=" collé (bug VISA/?status=200) ou "/" final, on nettoie
if($reference){
    // Enlève ?status=... collé
    if(strpos($reference, '?')!==false){
        $reference = explode('?', $reference)[0];
    }
    // Enlève trailing slash / qui vient de ...REF/?status=200
    $reference = rtrim($reference, "/ \t\n\r\0\x0B");
    // Si contient encore / (ex VISA/REF), extrait vrai ref LME
    if(strpos($reference, '/')!==false){
        // cherche vrai ref LME dans URI (case-insensitive)
        if(preg_match('/(lme-group-CARD-[A-Z0-9\-]+)/i', $requestUri, $m)){
            $reference = $m[1];
        } elseif(preg_match('/(LME-[A-Z0-9\-]+)/i', $requestUri, $m)){
            $reference = $m[1];
        } else {
            // fallback: prend dernier segment après /
            $parts = explode('/', $reference);
            $reference = end($parts);
            $reference = rtrim($reference, "/");
        }
    }
    // Nettoie encore
    $reference = rtrim($reference, "/");
}

// Statut - extraction robuste
$status = $getParams['status'] ?? $getParams['statusCode'] ?? $getParams['code'] ?? null;
if(!$status){
    $status = extractFromUri($requestUri, 'status');
}
if(!$status){
    $status = extractFromUri($requestUri, 'statusCode');
}
if(!$status && $rawInput){
    $json = json_decode($rawInput, true);
    if($json){
        $status = $json['status'] ?? $json['statusCode'] ?? $json['code'] ?? null;
        if(!$status && isset($json['data'])){
            $status = $json['data']['statusCode'] ?? $json['data']['status'] ?? null;
        }
    }
}

// Description - APPROVED, etc
$description = $getParams['description'] ?? $getParams['statusDescription'] ?? $getParams['message'] ?? '';
if(!$description){
    $description = extractFromUri($requestUri, 'description') ?? '';
}
if(!$description && $rawInput){
    $json = json_decode($rawInput, true);
    if($json){
        $description = $json['description'] ?? $json['statusDescription'] ?? '';
    }
}

$operatorRefId = $getParams['operatorRefId'] ?? $getParams['transactionId'] ?? $allParams['operatorRefId'] ?? $allParams['transactionId'] ?? '';
if(!$operatorRefId){
    $operatorRefId = extractFromUri($requestUri, 'operatorRefId') ?? extractFromUri($requestUri, 'transactionId') ?? '';
}

$transactionRefId = $getParams['transactionRefId'] ?? $allParams['transactionRefId'] ?? '';
if(!$transactionRefId){
    $transactionRefId = extractFromUri($requestUri, 'transactionRefId') ?? '';
}

// Mapping statut -> interne
$internalStatus = PAIEMENT_ETAT_EN_ATTENTE;
$statusInt = is_numeric($status) ? (int)$status : 0;
$statusStr = strtolower((string)$status);
$descLower = strtolower((string)$description);

if(in_array($statusInt, [200,201,202]) || in_array($statusStr, ['200','201','202','approved','accepted','success'])){
    $internalStatus = PAIEMENT_ETAT_CONFIRME;
} elseif(in_array($statusInt, [400,401,402,403,404,405,500,502,503]) || strpos($statusStr,'fail')!==false || strpos($statusStr,'error')!==false || strpos($statusStr,'cancel')!==false || strpos($statusStr,'refused')!==false || strpos($statusStr,'declined')!==false){
    $internalStatus = PAIEMENT_ETAT_ECHEC;
} else {
    // Si pas de statut mais description = APPROVED => confirme (cas observé)
    if(stripos($description, 'approved')!==false || stripos($description, 'accept')!==false || stripos($description, 'success')!==false){
        $internalStatus = PAIEMENT_ETAT_CONFIRME;
    } elseif(stripos($description, 'fail')!==false || stripos($description, 'error')!==false || stripos($description, 'cancel')!==false || stripos($description, 'declined')!==false || stripos($description, 'refused')!==false){
        $internalStatus = PAIEMENT_ETAT_ECHEC;
    }
}

// Log parsé
logCallback("PARSED ref=$reference status=$status (int $statusInt str $statusStr) desc=$description => internal=$internalStatus opRef=$operatorRefId txRefId=$transactionRefId URI=$requestUri");

if(!$reference){
    logCallback("ERROR No reference found, cannot update DB - trying to find by operatorRefId $operatorRefId");
    // Tentative recherche par operatorRefId si ref manquante
    if($operatorRefId){
        try{
            $pdo=getDB();
            $stmt=$pdo->prepare("SELECT * FROM transactions_votes WHERE id_transaction_unipesa=? OR ref_transaction_unipesa=? ORDER BY transaction_id DESC LIMIT 1");
            $stmt->execute([$operatorRefId, $operatorRefId]);
            $found=$stmt->fetch();
            if($found){
                $reference = $found['numero_reference'];
                logCallback("FOUND ref by operatorRefId: $reference");
            }
        }catch(Exception $e){}
    }
    if(!$reference){
        if(isset($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'Mozilla')!==false){
            echo "<h2>Callback MaishaPay - Référence manquante</h2><p>URI: ".htmlspecialchars($requestUri)."</p><p>GET: ".htmlspecialchars(json_encode($getParams))."</p>";
            exit;
        }
        echo "OK - No ref"; exit;
    }
}

// Mise à jour DB avec auto ALTER une seule fois
try{
    $pdo=getDB();
    ensureMaishapaySchema($pdo);
    $stmt=$pdo->prepare("SELECT * FROM transactions_votes WHERE numero_reference=? LIMIT 1");
    $stmt->execute([$reference]);
    $existing=$stmt->fetch();

    // Fallback: cherche par operatorRefId si ref exacte non trouvée (cas transactionRefId Maishapay interne)
    if(!$existing && $operatorRefId){
        $stmt2=$pdo->prepare("SELECT * FROM transactions_votes WHERE id_transaction_unipesa=? OR ref_transaction_unipesa=? ORDER BY transaction_id DESC LIMIT 1");
        $stmt2->execute([$operatorRefId, $operatorRefId]);
        $existing=$stmt2->fetch();
        if($existing){
            $reference = $existing['numero_reference'];
        }
    }
    // Fallback: cherche par transactionRefId interne Maishapay si on a loggué transactionId 264433 etc
    if(!$existing && $transactionRefId){
        // transactionRefId peut être 2RJ1-... qui n'est pas dans notre DB, donc on ne trouvera pas, mais on tente
        $stmt3=$pdo->prepare("SELECT * FROM transactions_votes WHERE numero_reference LIKE ? ORDER BY transaction_id DESC LIMIT 1");
        $stmt3->execute(['%'.substr($transactionRefId,0,8).'%']);
        // pas fiable, on skip
    }

    if($existing){
        if($existing['etat_paiement']===PAIEMENT_ETAT_EN_ATTENTE || $internalStatus===PAIEMENT_ETAT_CONFIRME){
            $tid = $operatorRefId ?: $transactionRefId ?: $existing['id_transaction_unipesa'];
            $tref = $transactionRefId ?: $operatorRefId ?: $existing['ref_transaction_unipesa'];
            $msg = $description ?: 'Callback MaishaPay '.$status.' - Card '.($existing['moyen_paiement']??'');
            if($internalStatus===PAIEMENT_ETAT_EN_ATTENTE && stripos($description,'approved')!==false){
                $internalStatus = PAIEMENT_ETAT_CONFIRME;
            }
            // Détecte type carte pour enum visa/mastercard + provider
            $cardTypeDetected = 'VISA';
            if(preg_match('/MASTERCARD/i', $requestUri.$description.($existing['message_retour']??''))){
                $cardTypeDetected = 'MASTERCARD';
            } elseif(preg_match('/VISA/i', $requestUri.$description.($existing['message_retour']??''))){
                $cardTypeDetected = 'VISA';
            }
            $moyenEnum = ($cardTypeDetected==='MASTERCARD') ? 'mastercard' : 'visa';

            // UPDATE avec nouveaux champs NULL-safe pour autres structures
            try{
                $pdo->prepare("UPDATE transactions_votes SET etat_paiement=:s, confirme_le=NOW(), id_transaction_unipesa=:tid, ref_transaction_unipesa=:tref, message_retour=:msg, moyen_paiement=:moyen, gateway_paiement='maishapay', provider_maishapay=:provider, est_paiement_maishapay=1 WHERE numero_reference=:r")
                    ->execute([
                        ':s'=>$internalStatus,
                        ':tid'=>$tid,
                        ':tref'=>$tref,
                        ':msg'=>$msg.' - '.$cardTypeDetected.' Maishapay',
                        ':moyen'=>$moyenEnum,
                        ':provider'=>$cardTypeDetected,
                        ':r'=>$reference,
                    ]);
                logCallback("DB UPDATED (new cols NULL-safe) ref=$reference to $internalStatus moyen=$moyenEnum provider=$cardTypeDetected tid=$tid");
            } catch(PDOException $e){
                // Fallback si colonnes gateway/provider/est n'existent pas encore (structure sans carte) ou enum pas étendu
                $errMsg = $e->getMessage();
                if(strpos($errMsg,'Unknown column')!==false || strpos($errMsg,'Data truncated')!==false || strpos($errMsg,'Incorrect')!==false){
                    try{
                        // Essaie avec enum visa/mastercard seulement
                        $pdo->prepare("UPDATE transactions_votes SET etat_paiement=:s, confirme_le=NOW(), id_transaction_unipesa=:tid, ref_transaction_unipesa=:tref, message_retour=:msg, moyen_paiement=:moyen WHERE numero_reference=:r")
                            ->execute([
                                ':s'=>$internalStatus,
                                ':tid'=>$tid,
                                ':tref'=>$tref,
                                ':msg'=>$msg.' - '.$cardTypeDetected,
                                ':moyen'=>$moyenEnum,
                                ':r'=>$reference,
                            ]);
                        logCallback("DB UPDATED (enum visa/mastercard) ref=$reference to $internalStatus moyen=$moyenEnum");
                    } catch(PDOException $e2){
                        // Dernier fallback: carte générique (ancien enum)
                        $pdo->prepare("UPDATE transactions_votes SET etat_paiement=:s, confirme_le=NOW(), id_transaction_unipesa=:tid, ref_transaction_unipesa=:tref, message_retour=:msg WHERE numero_reference=:r")
                            ->execute([
                                ':s'=>$internalStatus,
                                ':tid'=>$tid,
                                ':tref'=>$tref,
                                ':msg'=>$msg,
                                ':r'=>$reference,
                            ]);
                        logCallback("DB UPDATED (fallback carte) ref=$reference to $internalStatus");
                    }
                } else {
                    // Autre erreur, fallback simple
                    $pdo->prepare("UPDATE transactions_votes SET etat_paiement=:s, confirme_le=NOW(), id_transaction_unipesa=:tid, ref_transaction_unipesa=:tref, message_retour=:msg WHERE numero_reference=:r")
                        ->execute([
                            ':s'=>$internalStatus,
                            ':tid'=>$tid,
                            ':tref'=>$tref,
                            ':msg'=>$msg,
                            ':r'=>$reference,
                        ]);
                    logCallback("DB UPDATED (fallback simple) ref=$reference to $internalStatus");
                }
            }
        } else {
            logCallback("DB SKIP already ".$existing['etat_paiement']." ref=$reference, keeping");
        }

        $isBrowser = isset($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'Mozilla')!==false;
        // Toujours rediriger navigateur vers reçu si ref présent
        if($isBrowser || isset($getParams['ref']) || strpos($requestUri, 'ref=')!==false){
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'https';
            $host = $_SERVER['HTTP_HOST'] ?? 'lme-group.zaloriatech.com';
            $candidate_id = $existing['participante_id'];
            $concours_id = $existing['concours_id'];
            $etape_id = $existing['etape_id'];
            $redirectUrl = $scheme.'://'.$host.'/voter.php?candidat='.(int)$candidate_id.'&concours_id='.(int)$concours_id.'&etape_id='.($etape_id? (int)$etape_id : '').'&receipt='.urlencode($reference).'&status='.urlencode($internalStatus).'&method=card';
            logCallback("REDIRECT BROWSER to $redirectUrl");
            header('Location: '.$redirectUrl);
            echo "<html><body>Redirection vers <a href='$redirectUrl'>$redirectUrl</a><script>window.location='$redirectUrl'</script></body></html>";
            exit;
        }

        echo "OK"; exit;

    } else {
        logCallback("DB NOT FOUND ref=$reference - INSERT? No, should exist from initiate_card_payment. Check transactions_votes.");
        // Si pas trouvé, on affiche quand même page
        if(isset($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'Mozilla')!==false){
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'https';
            $host = $_SERVER['HTTP_HOST'] ?? 'lme-group.zaloriatech.com';
            // tente de récupérer candidate_id depuis ref si possible? On a déjà ref
            echo "<h2>Transaction non trouvée: ".htmlspecialchars($reference)."</h2><p>Vérifiez transactions_votes. Status: $internalStatus Desc: ".htmlspecialchars($description)."</p>";
            echo "<p><a href='index.php'>Accueil</a></p>";
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
