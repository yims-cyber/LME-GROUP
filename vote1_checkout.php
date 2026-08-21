<?php
// vote1_checkout.php — Page intermédiaire sécurisée pour paiement carte via Maishapay Checkout
// Ne expose jamais secretApiKey au client JS, le POST vers Maishapay est fait côté serveur
// Usage: vote1.php -> initiate_card_payment -> retourne reference -> redirect vers vote1_checkout.php?ref=REF

ini_set('display_errors', 0);
error_reporting(E_ALL);

define('DB_HOST', 'localhost:3306');
define('DB_NAME', 'mayi1275_zaloria_multisysteme');
define('DB_USER', 'mayi1275_zaloriatech');
define('DB_PASS', '07/09/1996/O2switch');

define('MAISHA_GATEWAY_MODE', 0);
define('MAISHA_PUBLIC_KEY', 'MP-SBPK-.UCBEROe0e1ycKqKo1$rj/iSCcdBaeZ0Fbx38fkPUGokH/F$kSXfbES$.SOl32Evud21YiMulKAc./GJhp4P0i/BzF2X2VP$k2wq$yY9byj30V.$9re1fOgo');
define('MAISHA_SECRET_KEY', 'MP-SBSK-OA82.3h5WVFaBQEa$PyHjlk8HEc$lNJhy2w4gne.eNo.Hsx$jXCaUy/1c4qjYM$KGtc.fjb$6Baku2Sh.SWHLJO$qVxNBK0yU1mcvFE7mO1gtVEPpDXom0.X');
define('MAISHA_CHECKOUT_URL', 'https://marchand.maishapay.online/payment/vers1.0/merchant/checkout');

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

$ref = trim($_GET['ref'] ?? $_GET['reference'] ?? '');
if(!$ref){
    http_response_code(400);
    echo "<h2>Référence manquante</h2><p><a href='index.php'>Accueil</a></p>";
    exit;
}

try{
    $pdo=getDB();
    $stmt=$pdo->prepare("SELECT * FROM transactions_votes WHERE numero_reference=? LIMIT 1");
    $stmt->execute([$ref]);
    $tx=$stmt->fetch();
    if(!$tx){
        http_response_code(404);
        echo "<h2>Transaction introuvable: ".htmlspecialchars($ref)."</h2>";
        exit;
    }
    if($tx['etat_paiement']==='confirme'){
        // déjà confirmé, redirige vers reçu
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'https';
        $host = $_SERVER['HTTP_HOST'] ?? 'lme-group.zaloriatech.com';
        $url = $scheme.'://'.$host.'/vote1.php?candidat='.(int)$tx['participante_id'].'&concours_id='.(int)$tx['concours_id'].'&etape_id='.($tx['etape_id']? (int)$tx['etape_id']:'').'&receipt='.urlencode($ref);
        header('Location: '.$url);
        exit;
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'lme-group.zaloriatech.com';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'https';
    $callbackUrl = $scheme.'://'.$host.'/vote1_callback.php?ref='.$ref;

    $payload = [
        'gatewayMode' => MAISHA_GATEWAY_MODE,
        'publicApiKey' => MAISHA_PUBLIC_KEY,
        'secretApiKey' => MAISHA_SECRET_KEY,
        'montant' => (float)$tx['montant_paye'],
        'devise' => $tx['devise'] ?? 'USD',
        'callbackUrl' => $callbackUrl,
    ];

    // Log masqué (sans secret)
    $logPayload = $payload;
    $logPayload['publicApiKey'] = substr($logPayload['publicApiKey'],0,10).'***';
    $logPayload['secretApiKey'] = '***MASKED***';
    file_put_contents(__DIR__.'/maishapay.log', date('c').' CHECKOUT SERVER POST ref='.$ref.' payload='.json_encode($logPayload).PHP_EOL, FILE_APPEND);

    $ch=curl_init(MAISHA_CHECKOUT_URL);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>http_build_query($payload),
        CURLOPT_TIMEOUT=>30,
        CURLOPT_SSL_VERIFYPEER=>false,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $resp=curl_exec($ch);
    $err=curl_error($ch);
    $code=curl_getinfo($ch,CURLINFO_HTTP_CODE);
    curl_close($ch);

    if($err || !$resp){
        file_put_contents(__DIR__.'/maishapay.log', date('c').' CHECKOUT ERROR ref='.$ref.' err='.$err.' code='.$code.PHP_EOL, FILE_APPEND);
        echo "<h2>Erreur connexion MaishaPay</h2><p>".htmlspecialchars($err)."</p><p><a href='vote1.php?candidat=".(int)$tx['participante_id']."'>Retour</a></p>";
        exit;
    }

    // La réponse est HTML de MaishaPay Payment Panel, on l'affiche directement
    // On ajoute un bandeau sécurité au dessus
    // Si la réponse contient déjà <html>, on l'affiche telle quelle mais on injecte notre bandeau via str_replace
    if(stripos($resp, '<html')!==false){
        // Injecte bandeau après <body>
        $banner = "<div style='background:#071A3D;color:#fff;padding:10px 16px;font-family:Inter,sans-serif;font-size:.82rem;text-align:center;border-bottom:2px solid #D4AF37'>🔒 Paiement sécurisé • Réf: ".htmlspecialchars($ref)." • ".(int)$tx['votes_accordes']." votes • ".htmlspecialchars($tx['montant_paye'])." ".htmlspecialchars($tx['devise'])." • Ne fermez pas cette page</div>";
        $resp = preg_replace('/<body[^>]*>/i', '$0'.$banner, $resp, 1);
        echo $resp;
    } else {
        // Si pas HTML complet, on affiche dans notre template
        echo "<!DOCTYPE html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'><title>Paiement sécurisé - LME GROUP</title></head><body style='margin:0;background:#050B16;color:#fff;font-family:Inter,sans-serif'>";
        echo "<div style='background:#071A3D;padding:16px;text-align:center;border-bottom:1px solid rgba(212,175,55,.3)'>🔒 Paiement sécurisé • Réf: ".htmlspecialchars($ref)."</div>";
        echo $resp;
        echo "</body></html>";
    }
    exit;

}catch(Exception $e){
    file_put_contents(__DIR__.'/maishapay.log', date('c').' CHECKOUT EXCEPTION ref='.$ref.' '.$e->getMessage().PHP_EOL, FILE_APPEND);
    http_response_code(500);
    echo "<h2>Erreur serveur</h2><p>".htmlspecialchars($e->getMessage())."</p>";
    exit;
}
