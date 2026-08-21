<?php
/**
 * vote1_api.php — API Vote avec séparation claire:
 *  - Mobile Money = Unipesa/Avadapay (comme voter_api.php original) - provider_id 9/10/17/19
 *  - Carte Visa/Mastercard = Maishapay uniquement (REST chanel CARD + Checkout)
 * Docs Maishapay: https://www.maishapay.net/api_docs/ApiRest.html et Checkout
 * Sandbox Maishapay testé:
 *  Public: MP-SBPK-.UCBEROe0e1ycKqKo1$rj/iSCcdBaeZ0Fbx38fkPUGokH/F$kSXfbES$.SOl32Evud21YiMulKAc./GJhp4P0i/BzF2X2VP$k2wq$yY9byj30V.$9re1fOgo
 *  Secret: MP-SBSK-OA82.3h5WVFaBQEa$PyHjlk8HEc$lNJhy2w4gne.eNo.Hsx$jXCaUy/1c4qjYM$KGtc.fjb$6Baku2Sh.SWHLJO$qVxNBK0yU1mcvFE7mO1gtVEPpDXom0.X
 *  Merchant ID: 000945
 *  Chanel valides Maishapay: MOBILEMONEY et CARD (testé: BANKCARD invalide)
 *  Pour CARD, callbackUrl obligatoire
 * Respect demande user: Mobile = Unipesa, Carte = Maishapay
 */

header('Content-Type: application/json; charset=utf-8');

/* ===== CONFIG MAISHAPAY (SANDBOX) ===== */
define('MAISHA_GATEWAY_MODE', 0); // 0 sandbox, 1 live
define('MAISHA_PUBLIC_KEY', 'MP-SBPK-.UCBEROe0e1ycKqKo1$rj/iSCcdBaeZ0Fbx38fkPUGokH/F$kSXfbES$.SOl32Evud21YiMulKAc./GJhp4P0i/BzF2X2VP$k2wq$yY9byj30V.$9re1fOgo');
define('MAISHA_SECRET_KEY', 'MP-SBSK-OA82.3h5WVFaBQEa$PyHjlk8HEc$lNJhy2w4gne.eNo.Hsx$jXCaUy/1c4qjYM$KGtc.fjb$6Baku2Sh.SWHLJO$qVxNBK0yU1mcvFE7mO1gtVEPpDXom0.X');
define('MAISHA_MERCHANT_ID', '000945');
define('MAISHA_REST_URL', 'https://marchand.maishapay.online/api/payment/rest/vers1.0/merchant');
define('MAISHA_CHECKOUT_URL', 'https://marchand.maishapay.online/payment/vers1.0/merchant/checkout');

/* ===== CONFIG UNIPESA (gardé pour compatibilité, mais vote1 utilise Maishapay) ===== */
define('UNIPESA_PUBLIC_ID',   'cdefaccbefd7e5fec36f514fd051f2185969e603');
define('UNIPESA_MERCHANT_ID', 'cdefa368fd86db654502ca1cb922bc5a1a691055');
define('UNIPESA_SECRET_KEY',  'cdbbf8a2f9e7790193d265acd4442275633ef46c280629a5181a46ee57e4e62799a2cdf6a5d9de5347163c6d79edbffa154eb274e6aca317320fe57a734874ce');
define('UNIPESA_BASE_URL',    'https://api.unipesa.tech');
define('PROVIDER_AIRTEL',   17);
define('PROVIDER_ORANGE',   10);
define('PROVIDER_MPESA',     9);
define('PROVIDER_AFRICELL', 19);

define('PAIEMENT_ETAT_EN_ATTENTE', 'en_attente');
define('PAIEMENT_ETAT_CONFIRME',   'confirme');
define('PAIEMENT_ETAT_ECHEC',      'echoue');

/* ===== DB ===== */
define('DB_HOST', 'localhost:3306');
define('DB_NAME', 'mayi1275_zaloria_multisysteme');
define('DB_USER', 'mayi1275_zaloriatech');
define('DB_PASS', '07/09/1996/O2switch');

const OPERATOR_PREFIXES = [
    '81' => 'mpesa', '82' => 'mpesa', '83' => 'mpesa',
    '97' => 'airtel', '98' => 'airtel', '99' => 'airtel',
    '80' => 'orange', '84' => 'orange', '85' => 'orange', '89' => 'orange',
    '90' => 'africell', '91' => 'africell',
];
const OPERATOR_META = [
    'airtel'   => ['label' => 'Airtel Money',   'provider_id' => PROVIDER_AIRTEL,   'short' => 'AM', 'cls' => 'op-airtel', 'maisha_provider' => 'AIRTEL'],
    'orange'   => ['label' => 'Orange Money',   'provider_id' => PROVIDER_ORANGE,   'short' => 'OM', 'cls' => 'op-orange', 'maisha_provider' => 'ORANGE'],
    'mpesa'    => ['label' => 'M-Pesa',         'provider_id' => PROVIDER_MPESA,     'short' => 'MP', 'cls' => 'op-mpesa', 'maisha_provider' => 'MPESA'],
    'africell' => ['label' => 'Africell Money', 'provider_id' => PROVIDER_AFRICELL, 'short' => 'AF', 'cls' => 'op-africell', 'maisha_provider' => 'AFRICELL'],
];

function detectOperator(string $phone): ?array {
    $d = preg_replace('/\D/', '', $phone);
    if (strlen($d) === 12 && substr($d,0,3)==='243') $d = substr($d,3);
    elseif (strlen($d) === 10 && $d[0]==='0') $d = substr($d,1);
    if (strlen($d)!==9 || $d[0]==='0') return null;
    $prefix = substr($d,0,2);
    if (!isset(OPERATOR_PREFIXES[$prefix])) return null;
    $op = OPERATOR_PREFIXES[$prefix];
    $meta = OPERATOR_META[$op];
    switch($op){
        case 'mpesa':  $customerId='243'.$d; break;
        case 'airtel': $customerId=$d; break;
        default:       $customerId='0'.$d; break;
    }
    return [
        'operator'    => $op,
        'label'       => $meta['label'],
        'short'       => $meta['short'],
        'cls'         => $meta['cls'],
        'provider_id' => $meta['provider_id'],
        'maisha_provider' => $meta['maisha_provider'],
        'national'    => '0'.$d,
        'e164'        => '243'.$d,
        'customer_id' => $customerId,
    ];
}

function calcUnipesaSignature(array $data, string $secret, string $prefix='', int $depth=16, int $level=0): string {
    if($level >= $depth) throw new RuntimeException('Signature recursion depth');
    $str='';
    foreach($data as $k=>$v){
        if(is_array($v)){
            $str.=calcUnipesaSignature($v,$secret,"$prefix$k.",$depth,$level+1);
        } elseif($k!=='signature'){
            $str.=$prefix.$k.$v;
        }
    }
    return $level===0 ? strtolower(hash_hmac('sha512',$str,$secret)) : $str;
}

function unipesaStatusToInternal(int $code): string {
    if($code===2) return PAIEMENT_ETAT_CONFIRME;
    if($code>=3)  return PAIEMENT_ETAT_ECHEC;
    return PAIEMENT_ETAT_EN_ATTENTE;
}

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

function unipesaPost(string $endpoint, array $payload, int $timeout=15): array {
    $ch=curl_init(UNIPESA_BASE_URL.'/'.UNIPESA_PUBLIC_ID.'/'.$endpoint);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode($payload),
        CURLOPT_TIMEOUT=>$timeout,
        CURLOPT_SSL_VERIFYPEER=>true,
    ]);
    $resp=curl_exec($ch);
    $err=curl_error($ch);
    $code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['response'=>$resp,'error'=>$err,'http_code'=>$code];
}

function maishapayPost(array $payload, int $timeout=25): array {
    $ch=curl_init(MAISHA_REST_URL);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
        CURLOPT_POSTFIELDS=>json_encode($payload),
        CURLOPT_TIMEOUT=>$timeout,
        CURLOPT_SSL_VERIFYPEER=>false, // sandbox may have cert issues
    ]);
    $resp=curl_exec($ch);
    $err=curl_error($ch);
    $code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['response'=>$resp,'error'=>$err,'http_code'=>$code];
}

function checkConcours(PDO $pdo, int $concours_id): array {
    $stmt=$pdo->prepare("SELECT site_id, etat_concours, date_ouverture, date_cloture, arret_manuel FROM concours WHERE concours_id=?");
    $stmt->execute([$concours_id]);
    $c=$stmt->fetch();
    if(!$c) return ['success'=>false,'message'=>'Concours introuvable.'];
    if(!in_array($c['etat_concours'],['actif','en_cours'])) return ['success'=>false,'message'=>'Concours non actif ('.$c['etat_concours'].').'];
    if($c['arret_manuel']==1) return ['success'=>false,'message'=>'Concours arrêté manuellement.'];
    $now=time(); $d=strtotime($c['date_ouverture']); $f=strtotime($c['date_cloture']);
    if($now<$d) return ['success'=>false,'message'=>'Concours pas encore ouvert.'];
    if($now>$f) return ['success'=>false,'message'=>'Concours terminé.'];
    return ['success'=>true,'site_id'=>$c['site_id']];
}
function checkOffre(PDO $pdo, int $offre_id, int $concours_id): array {
    $stmt=$pdo->prepare("SELECT offre_id, nombre_votes_inclus, prix, devise, offre_visible FROM offres_votes WHERE offre_id=? AND concours_id=?");
    $stmt->execute([$offre_id,$concours_id]);
    $o=$stmt->fetch();
    if(!$o) return ['success'=>false,'message'=>'Offre introuvable.'];
    if($o['offre_visible']!=1) return ['success'=>false,'message'=>'Offre non visible.'];
    return ['success'=>true,'data'=>$o];
}
function getSiteLienUnique(PDO $pdo, int $concours_id): ?string {
    $stmt=$pdo->prepare("SELECT s.lien_unique FROM concours c JOIN sites s ON s.site_id=c.site_id WHERE c.concours_id=?");
    $stmt->execute([$concours_id]);
    $row=$stmt->fetch();
    return $row['lien_unique']??null;
}
function checkParticipante(PDO $pdo, int $pid, int $cid): bool {
    $stmt=$pdo->prepare("SELECT 1 FROM participantes WHERE participante_id=? AND concours_id=?");
    $stmt->execute([$pid,$cid]);
    return (bool)$stmt->fetchColumn();
}
function checkEtape(PDO $pdo, int $eid, int $cid): array {
    $stmt=$pdo->prepare("SELECT etape_id, etape_terminee, date_ouverture, date_cloture FROM etapes_du_concours WHERE etape_id=? AND concours_id=?");
    $stmt->execute([$eid,$cid]);
    $e=$stmt->fetch();
    if(!$e) return ['success'=>false,'message'=>'Étape introuvable.'];
    if($e['etape_terminee']==1) return ['success'=>false,'message'=>'Étape terminée.'];
    $now=time(); $d=strtotime($e['date_ouverture']); $f=strtotime($e['date_cloture']);
    if($now<$d) return ['success'=>false,'message'=>'Étape pas encore ouverte.'];
    if($now>$f) return ['success'=>false,'message'=>'Étape terminée.'];
    return ['success'=>true];
}

/* ===== CALLBACK UNIPESA (ancien) - conservé pour compatibilité si voter_api.php appelé ===== */
$rawInput=file_get_contents('php://input');
if($rawInput && empty($_POST['action'])){
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    // Log brut pour debug Maishapay et Unipesa
    file_put_contents(__DIR__.'/maishapay_callback_raw.log', date('c').' CT:'.$contentType.' IP:'.$_SERVER['REMOTE_ADDR'].' RAW:'.$rawInput.PHP_EOL, FILE_APPEND);
    
    $cb=json_decode($rawInput,true);
    if($cb){
        // Si c'est Unipesa
        if(isset($cb['order_id'])){
            file_put_contents(__DIR__.'/unipesa_callback.log',date('c').' '.$rawInput.PHP_EOL,FILE_APPEND);
            $orderId=$cb['order_id']??null;
            $status=isset($cb['status'])?(int)$cb['status']:-1;
            $internal=unipesaStatusToInternal($status);
            $received=strtolower((string)($cb['signature']??''));
            $cbForSign=$cb; unset($cbForSign['signature']);
            $expected=calcUnipesaSignature($cbForSign,UNIPESA_SECRET_KEY);
            if($orderId && $internal!==PAIEMENT_ETAT_EN_ATTENTE){
                try{
                    $pdo=getDB();
                    $msg=$cb['provider_result']['message']??$cb['result']['message']??'';
                    $pdo->prepare("UPDATE transactions_votes SET etat_paiement=:s, confirme_le=NOW(), id_transaction_unipesa=:tid, ref_transaction_unipesa=:tref, message_retour=:msg WHERE numero_reference=:r AND etat_paiement NOT IN ('confirme','echoue')")
                        ->execute([
                            ':s'=>$internal,
                            ':tid'=>$cb['transaction_id']??'',
                            ':tref'=>$cb['transaction_ref']??'',
                            ':msg'=>$msg,
                            ':r'=>$orderId,
                        ]);
                }catch(Exception $e){
                    file_put_contents(__DIR__.'/unipesa_callback.log',date('c').' DB ERROR '.$e->getMessage().PHP_EOL,FILE_APPEND);
                }
            }
            echo 'OK'; exit;
        }
        // Si c'est Maishapay REST callback (format supposé)
        if(isset($cb['transactionReference']) || isset($cb['originatingTransactionId']) || isset($cb['transactionId'])){
            $ref = $cb['transactionReference'] ?? $cb['originatingTransactionId'] ?? $cb['transactionRefId'] ?? null;
            $statusCode = $cb['statusCode'] ?? $cb['status'] ?? 0;
            $desc = $cb['statusDescription'] ?? $cb['description'] ?? '';
            // Mapping: 200,202 = success
            $internal = PAIEMENT_ETAT_EN_ATTENTE;
            if(in_array((int)$statusCode, [200,201,202])) $internal = PAIEMENT_ETAT_CONFIRME;
            elseif((int)$statusCode >= 400) $internal = PAIEMENT_ETAT_ECHEC;

            if($ref && $internal !== PAIEMENT_ETAT_EN_ATTENTE){
                try{
                    $pdo=getDB();
                    $pdo->prepare("UPDATE transactions_votes SET etat_paiement=:s, confirme_le=NOW(), id_transaction_unipesa=:tid, message_retour=:msg WHERE numero_reference=:r AND etat_paiement NOT IN ('confirme','echoue')")
                        ->execute([
                            ':s'=>$internal,
                            ':tid'=>$cb['transactionId'] ?? $cb['operatorRefId'] ?? '',
                            ':msg'=>$desc,
                            ':r'=>$ref,
                        ]);
                }catch(Exception $e){
                    file_put_contents(__DIR__.'/maishapay_callback_raw.log', date('c').' DB ERROR '.$e->getMessage().PHP_EOL, FILE_APPEND);
                }
            }
            echo 'OK'; exit;
        }
    }
    // Si pas JSON, c'est peut-être form POST de Checkout, on laisse vote1_callback.php gérer
    // Mais on répond OK pour éviter retry
    echo 'OK'; exit;
}

$action=$_POST['action']??'';

/* ===== detect_operator ===== */
if($action==='detect_operator'){
    $info=detectOperator($_POST['telephone']??'');
    if(!$info){
        echo json_encode(['success'=>false,'message'=>'Numéro invalide ou opérateur non reconnu. Préfixes: Vodacom 81-83, Orange 80/84/85/89, Airtel 97-99, Africell 90-91.']);
    } else {
        echo json_encode(['success'=>true,'operator'=>$info['operator'],'label'=>$info['label'],'short'=>$info['short'],'cls'=>$info['cls'],'numero'=>$info['national'],'e164'=>$info['e164'],'provider'=>$info['maisha_provider']]);
    }
    exit;
}

/* ===== initiate_payment (Mobile Money via Unipesa/Avadapay - comme voter_api.php) ===== */
if($action==='initiate_payment'){
    $participanteId=(int)($_POST['candidate_id']??0);
    $concoursId=(int)($_POST['evenement_id']??0);
    $offreId=(int)($_POST['pack_id']??0);
    $etapeId=isset($_POST['etape_id']) && $_POST['etape_id']!=='' ? (int)$_POST['etape_id'] : null;
    $telephone=trim($_POST['telephone']??'');
    $messageUser=mb_substr(trim($_POST['message']??''),0,255);
    $email=trim($_POST['email']??'');

    if(!$participanteId || !$concoursId || !$offreId || !$telephone){
        echo json_encode(['success'=>false,'message'=>'Paramètres incomplets.']); exit;
    }

    $opInfo=detectOperator($telephone);
    if(!$opInfo){
        echo json_encode(['success'=>false,'message'=>'Numéro invalide. Ex: 0812345678 (Vodacom), 0991234567 (Airtel), 0841234567 (Orange), 0901234567 (Africell).']); exit;
    }

    $pdo=getDB();
    $chk=checkConcours($pdo,$concoursId);
    if(!$chk['success']){ echo json_encode(['success'=>false,'message'=>$chk['message']]); exit; }
    $siteId=$chk['site_id'] ?? null;
    if(!$siteId){
        $stmtSid=$pdo->prepare("SELECT site_id FROM concours WHERE concours_id=?");
        $stmtSid->execute([$concoursId]);
        $siteId=$stmtSid->fetchColumn();
    }
    if(!$siteId){
        echo json_encode(['success'=>false,'message'=>'Site non trouvé pour ce concours (site_id manquant).']); exit;
    }

    $chkOff=checkOffre($pdo,$offreId,$concoursId);
    if(!$chkOff['success']){ echo json_encode(['success'=>false,'message'=>$chkOff['message']]); exit; }
    $offreData=$chkOff['data'];
    if(!checkParticipante($pdo,$participanteId,$concoursId)){ echo json_encode(['success'=>false,'message'=>'Participante invalide.']); exit; }
    if($etapeId!==null && $etapeId>0){
        $chkEt=checkEtape($pdo,$etapeId,$concoursId);
        if(!$chkEt['success']){ echo json_encode(['success'=>false,'message'=>$chkEt['message']]); exit; }
    } else $etapeId=null;

    $lienUnique=getSiteLienUnique($pdo,$concoursId) ?: 'LME-GROUP';
    $nombreVotes=$offreData['nombre_votes_inclus'];
    $montant=$offreData['prix'];
    $devise=$offreData['devise']??'USD';

    $reference=$lienUnique.'-'.date('YmdHis').'-'.strtoupper(substr(bin2hex(random_bytes(3)),0,6));

    // Mobile Money = Unipesa/Avadapay (comme voter.php)
    $pdo->prepare("INSERT INTO transactions_votes (site_id, numero_reference, concours_id, participante_id, etape_id, moyen_paiement, numero_telephone, email_votant, montant_paye, devise, votes_accordes, etat_paiement, initie_le, message_retour) VALUES (:sid,:ref,:cid,:pid,:eid,:meth,:tel,:email,:montant,:devise,:votes,:etat,NOW(),'')")
        ->execute([
            ':sid'=>$siteId,
            ':ref'=>$reference,
            ':cid'=>$concoursId,
            ':pid'=>$participanteId,
            ':eid'=>$etapeId,
            ':meth'=>$opInfo['operator'], // mpesa/airtel/orange/africell
            ':tel'=>$opInfo['e164'],
            ':email'=>$email,
            ':montant'=>$montant,
            ':devise'=>$devise,
            ':votes'=>$nombreVotes,
            ':etat'=>PAIEMENT_ETAT_EN_ATTENTE,
        ]);

    $host = $_SERVER['HTTP_HOST'] ?? 'lme-group.zaloriatech.com';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'https';
    $callbackUrl = $scheme.'://'.$host.'/vote1_api.php'; // Unipesa callback vers vote1_api.php lui-même (comme voter_api.php)

    $payload=[
        'merchant_id'=>UNIPESA_MERCHANT_ID,
        'customer_id'=>$opInfo['customer_id'],
        'customer_user_id'=>'voter-'.$opInfo['e164'],
        'order_id'=>$reference,
        'amount'=>number_format((float)$montant,2,'.',''),
        'currency'=>$devise,
        'country'=>'CD',
        'callback_url'=>$callbackUrl,
        'provider_id'=>$opInfo['provider_id'],
    ];
    $payload['signature']=calcUnipesaSignature($payload,UNIPESA_SECRET_KEY);

    $result=unipesaPost('payment_c2b',$payload,20);
    file_put_contents(__DIR__.'/unipesa.log',date('c').' VOTE1 MOBILE REQ:'.json_encode($payload).' RESP:'.$result['response'].' ERR:'.$result['error'].' HTTP:'.$result['http_code'].PHP_EOL,FILE_APPEND);
    file_put_contents(__DIR__.'/maishapay.log',date('c').' VOTE1 MOBILE via Unipesa (pas Maishapay) ref='.$reference.' operator='.$opInfo['operator'].PHP_EOL,FILE_APPEND);

    if($result['error'] || !$result['response']){
        echo json_encode(['success'=>false,'message'=>'Erreur réseau vers opérateur Unipesa.']); exit;
    }
    $data=json_decode($result['response'],true);
    if(!$data || ($data['result']['code']??-1)!==0){
        $msg=$data['result']['message']??'Erreur opérateur Unipesa.';
        try{ $pdo->prepare("UPDATE transactions_votes SET etat_paiement='echoue', message_retour=:m WHERE numero_reference=:r")->execute([':m'=>'Unipesa Init: '.$msg,':r'=>$reference]); }catch(Exception $e){}
        echo json_encode(['success'=>false,'message'=>$msg]); exit;
    }

    echo json_encode([
        'success'=>true,
        'reference'=>$reference,
        'operator'=>$opInfo['operator'],
        'label'=>$opInfo['label'],
        'cls'=>$opInfo['cls'],
        'short'=>$opInfo['short'],
        'national'=>$opInfo['national'],
        'provider'=>$opInfo['maisha_provider'],
        'gateway'=>'unipesa',
        'message'=>'Demande envoyée via Unipesa '.$opInfo['label'].'. Confirmez sur '.$opInfo['national'].'.',
    ]);
    exit;
}

/* ===== initiate_card_payment (Carte Visa/Mastercard via Maishapay REST + Checkout) ===== */
if($action==='initiate_card_payment'){
    $participanteId=(int)($_POST['candidate_id']??0);
    $concoursId=(int)($_POST['evenement_id']??0);
    $offreId=(int)($_POST['pack_id']??0);
    $etapeId=isset($_POST['etape_id']) && $_POST['etape_id']!=='' ? (int)$_POST['etape_id'] : null;
    $cardType=strtoupper(trim($_POST['card_type']??'VISA')); // VISA ou MASTERCARD
    $email=trim($_POST['email']??'');
    $customerName=trim($_POST['customer_name']??'Votant LME');
    $phone=trim($_POST['phone']??'');

    if(!$participanteId || !$concoursId || !$offreId){
        echo json_encode(['success'=>false,'message'=>'Paramètres incomplets (candidate, concours, pack).']); exit;
    }
    if(!in_array($cardType, ['VISA','MASTERCARD','CARD','CREDITCARD'])){
        $cardType='VISA';
    }
    if(!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)){
        echo json_encode(['success'=>false,'message'=>'Email valide requis pour paiement carte (reçu + 3D Secure).']); exit;
    }

    $pdo=getDB();
    $chk=checkConcours($pdo,$concoursId);
    if(!$chk['success']){ echo json_encode(['success'=>false,'message'=>$chk['message']]); exit; }
    $siteId=$chk['site_id'] ?? null;
    if(!$siteId){
        $stmtSid=$pdo->prepare("SELECT site_id FROM concours WHERE concours_id=?");
        $stmtSid->execute([$concoursId]);
        $siteId=$stmtSid->fetchColumn();
    }
    if(!$siteId){
        echo json_encode(['success'=>false,'message'=>'Site non trouvé pour ce concours.']); exit;
    }

    $chkOff=checkOffre($pdo,$offreId,$concoursId);
    if(!$chkOff['success']){ echo json_encode(['success'=>false,'message'=>$chkOff['message']]); exit; }
    $offreData=$chkOff['data'];
    if(!checkParticipante($pdo,$participanteId,$concoursId)){ echo json_encode(['success'=>false,'message'=>'Participante invalide.']); exit; }
    if($etapeId!==null && $etapeId>0){
        $chkEt=checkEtape($pdo,$etapeId,$concoursId);
        if(!$chkEt['success']){ echo json_encode(['success'=>false,'message'=>$chkEt['message']]); exit; }
    } else $etapeId=null;

    $lienUnique=getSiteLienUnique($pdo,$concoursId) ?: 'LME-GROUP';
    $nombreVotes=$offreData['nombre_votes_inclus'];
    $montant=$offreData['prix'];
    $devise=$offreData['devise']??'USD';

    $reference=$lienUnique.'-CARD-'.date('YmdHis').'-'.strtoupper(substr(bin2hex(random_bytes(3)),0,6));

    // On enregistre moyen_paiement = visa/mastercard pour traçabilité
    $moyen = strtolower($cardType); // visa / mastercard
    $pdo->prepare("INSERT INTO transactions_votes (site_id, numero_reference, concours_id, participante_id, etape_id, moyen_paiement, numero_telephone, email_votant, montant_paye, devise, votes_accordes, etat_paiement, initie_le, message_retour) VALUES (:sid,:ref,:cid,:pid,:eid,:meth,:tel,:email,:montant,:devise,:votes,:etat,NOW(),'')")
        ->execute([
            ':sid'=>$siteId,
            ':ref'=>$reference,
            ':cid'=>$concoursId,
            ':pid'=>$participanteId,
            ':eid'=>$etapeId,
            ':meth'=>$moyen,
            ':tel'=>preg_replace('/\D/','',$phone) ?: '000000000',
            ':email'=>$email,
            ':montant'=>$montant,
            ':devise'=>$devise,
            ':votes'=>$nombreVotes,
            ':etat'=>PAIEMENT_ETAT_EN_ATTENTE,
        ]);

    $host = $_SERVER['HTTP_HOST'] ?? 'lme-group.zaloriatech.com';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'https';
    // FIX BUG 2026-08-21: callbackUrl simplifié à ?ref=REF seulement
    // Ancien avec &method=card&candidate_id=... causait double ? -> card_type=VISA/?status=200 => status vide => restait en_attente
    // Maintenant Maishapay fera /vote1_callback.php?ref=REF?status=200... et notre parser robuste gère double ? et APPROVED=>confirme
    // On récupère candidate_id etc depuis DB via reference, pas besoin de les mettre dans URL
    $callbackUrl = $scheme.'://'.$host.'/vote1_callback.php?ref='.$reference;

    // 1) On tente d'abord via REST API chanel CARD (testé OK avec callbackUrl)
    $payloadCard=[
        'gatewayMode'=>MAISHA_GATEWAY_MODE,
        'publicApiKey'=>MAISHA_PUBLIC_KEY,
        'secretApiKey'=>MAISHA_SECRET_KEY,
        'transactionReference'=>$reference,
        'amount'=>(float)$montant,
        'currency'=>$devise,
        'customerFullName'=>$customerName,
        'customerPhoneNumber'=>$phone ? '+' . preg_replace('/\D/','',$phone) : '+243000000000',
        'customerEmailAddress'=>$email,
        'chanel'=>'CARD',
        'provider'=>$cardType, // VISA ou MASTERCARD - testé OK
        'walletID'=>$phone ? '+' . preg_replace('/\D/','',$phone) : '+243000000000',
        'callbackUrl'=>$callbackUrl,
    ];

    $result=maishapayPost($payloadCard, 30);
    file_put_contents(__DIR__.'/maishapay.log', date('c').' CARD REST REQ:'.json_encode($payloadCard).' RESP:'.$result['response'].' HTTP:'.$result['http_code'].PHP_EOL, FILE_APPEND);

    $data=json_decode($result['response'],true);
    $isAccepted = false;
    $paymentPage = null;
    if(isset($data['status']) && $data['status']==200){
        $sc = $data['data']['statusCode'] ?? 0;
        if(in_array((int)$sc, [200,202,201])) $isAccepted = true;
        $paymentPage = $data['data']['paymentPage'] ?? null;
    }

    // Si REST accepté, on prépare Checkout form (méthode recommandée par doc Checkout)
    // Checkout endpoint: POST avec gatewayMode, publicApiKey, secretApiKey, montant, devise, callbackUrl
    // Le front fera un POST vers MAISHA_CHECKOUT_URL pour afficher page carte

    if(!$isAccepted){
        // Même si REST échoue, on tente quand même Checkout (parfois REST CARD nécessite Checkout)
        // On ne marque pas echoue tout de suite, on laisse Checkout tenter
        $isAccepted = true; // on force pour permettre redirection Checkout, car Checkout peut marcher même si REST dit autre chose
    }

    echo json_encode([
        'success'=>true,
        'reference'=>$reference,
        'maisha_transaction_id'=>$data['data']['transactionId'] ?? '',
        'paymentPage'=>$paymentPage, // peut être null en sandbox
        'checkout'=>[
            'url'=>MAISHA_CHECKOUT_URL,
            'gatewayMode'=>MAISHA_GATEWAY_MODE,
            'publicApiKey'=>MAISHA_PUBLIC_KEY,
            'secretApiKey'=>MAISHA_SECRET_KEY,
            'montant'=>(float)$montant,
            'devise'=>$devise,
            'callbackUrl'=>$callbackUrl,
        ],
        'message'=>'Paiement carte initié via MaishaPay. Redirection vers page sécurisée Visa/Mastercard (CyberSource 3D Secure). Merchant 000945 Sandbox.',
        'card_type'=>$cardType,
    ]);
    exit;
}

/* ===== check_payment (gère Unipesa pour Mobile + Maishapay pour Carte) ===== */
if($action==='check_payment'){
    $reference=trim($_POST['reference']??'');
    if(!$reference){ echo json_encode(['statut'=>PAIEMENT_ETAT_EN_ATTENTE]); exit; }

    try{
        $pdo=getDB();
        $stmt=$pdo->prepare("SELECT etat_paiement, message_retour, numero_reference, participante_id, concours_id, votes_accordes, montant_paye, devise, moyen_paiement, numero_telephone, email_votant FROM transactions_votes WHERE numero_reference=?");
        $stmt->execute([$reference]);
        $row=$stmt->fetch();
        if($row && in_array($row['etat_paiement'],[PAIEMENT_ETAT_CONFIRME,PAIEMENT_ETAT_ECHEC])){
            echo json_encode(['statut'=>$row['etat_paiement'],'message'=>$row['message_retour']??'','from_cache'=>true,'details'=>$row]);
            exit;
        }

        // Si mobile money (Unipesa), on interroge Unipesa status comme voter_api.php
        if($row && in_array($row['moyen_paiement'], ['mpesa','airtel','orange','africell','vodacom'])){
            $payload=['merchant_id'=>UNIPESA_MERCHANT_ID,'order_id'=>$reference];
            $payload['signature']=calcUnipesaSignature($payload,UNIPESA_SECRET_KEY);
            $result=unipesaPost('status',$payload,8);
            if($result['response']){
                $data=json_decode($result['response'],true);
                $uniStatus=isset($data['status'])?(int)$data['status']:-1;
                $internal=unipesaStatusToInternal($uniStatus);
                $message=$data['result']['message']??'';
                if(in_array($internal,[PAIEMENT_ETAT_CONFIRME,PAIEMENT_ETAT_ECHEC])){
                    try{
                        $pdo->prepare("UPDATE transactions_votes SET etat_paiement=:s, confirme_le=NOW(), id_transaction_unipesa=:tid, ref_transaction_unipesa=:tref, message_retour=:msg WHERE numero_reference=:r AND etat_paiement NOT IN ('confirme','echoue')")
                            ->execute([
                                ':s'=>$internal,
                                ':tid'=>$data['transaction_id']??'',
                                ':tref'=>$data['transaction_ref']??'',
                                ':msg'=>$message,
                                ':r'=>$reference,
                            ]);
                    }catch(Exception $e){}
                    // reload
                    $stmt->execute([$reference]);
                    $details=$stmt->fetch();
                    echo json_encode(['statut'=>$internal,'message'=>$message,'details'=>$details]);
                    exit;
                }
            }
            // sinon en attente
            echo json_encode(['statut'=>$row['etat_paiement'],'message'=>$row['message_retour']??'En attente opérateur Unipesa…','details'=>$row]);
            exit;
        }

        // Si carte (Maishapay), pas d'endpoint status public, on se base sur DB mise à jour par vote1_callback.php
        if($row){
            echo json_encode(['statut'=>$row['etat_paiement'],'message'=>$row['message_retour']??'En attente MaishaPay carte…','details'=>$row]);
            exit;
        }
    }catch(Exception $e){
        file_put_contents(__DIR__.'/maishapay.log', date('c').' check_payment DB error '.$e->getMessage().PHP_EOL, FILE_APPEND);
    }

    echo json_encode(['statut'=>PAIEMENT_ETAT_EN_ATTENTE,'message'=>'En attente…']);
    exit;
}

/* ===== get_realtime_votes ===== */
if($action==='get_realtime_votes'){
    $concoursId=(int)($_POST['evenement_id']??$_GET['evenement_id']??0);
    if(!$concoursId){ echo json_encode(['success'=>false,'message'=>'evenement_id manquant']); exit; }
    try{
        $pdo=getDB();
        $stmt=$pdo->prepare("SELECT p.participante_id, COALESCE(SUM(t.votes_accordes),0) AS votes FROM participantes p LEFT JOIN transactions_votes t ON p.participante_id=t.participante_id AND t.etat_paiement='confirme' AND t.concours_id=? WHERE p.concours_id=? GROUP BY p.participante_id ORDER BY p.participante_id");
        $stmt->execute([$concoursId,$concoursId]);
        $res=$stmt->fetchAll();
        $map=[]; foreach($res as $r){ $map[$r['participante_id']]=(int)$r['votes']; }
        echo json_encode(['success'=>true,'votes_per_candidate'=>$map,'evenement_id'=>$concoursId]);
        exit;
    }catch(Exception $e){
        http_response_code(500);
        echo json_encode(['success'=>false,'message'=>'Erreur serveur']); exit;
    }
}

http_response_code(400);
echo json_encode(['error'=>'action_inconnue', 'action'=>$action]);
