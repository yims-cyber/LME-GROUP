<?php
// vote1_checkout.php — FORCE marchand.maishapay.online/payment/vers1.0/merchant/checkout pour PC et mobile
// FIX: secureacceptance.cybersource.com/paymentmethods Cancel Order -> https://www.arakapay.com/ (perd retour)
// Alors que marchand.maishapay.online/payment/vers1.0/merchant/checkout fait bien retour vers voter_callback/vote1.php
// Demande user: même lien PC/mobile = marchand.maishapay.online/payment/vers1.0/merchant/checkout

session_start();
ini_set('display_errors', 0);
error_reporting(E_ALL);

define('DB_HOST', 'localhost:3306');
define('DB_NAME', 'mayi1275_zaloria_multisysteme');
define('DB_USER', 'mayi1275_zaloriatech');
define('DB_PASS', '07/09/1996/O2switch');

define('MAISHA_GATEWAY_MODE', 1);
define('MAISHA_PUBLIC_KEY', 'MP-LIVEPK-Dcx4lX0$W5i5QieJu1bdAJt7oyW5v$5JRw.5u$VQ3.lp71x1.WyWVexI1qiSyvR1Ip$2xznuc5hQVQzmrwZO17f$7vmHOzauVIdRW$WqVu1D7vkO2WmX0IeS');
define('MAISHA_SECRET_KEY', 'MP-LIVEPK-1yVfuv1t2v.aFrVrOXUIPdABlg2uvjn8$ylt8tFisaiUMVydYeKyQ$bBU7GO5Ef62A601E3d3corYomiahe8uW$E0vSzcl9P$VOWxiWRh1A2w$H0ISup0y$T');
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
function ensureMaishapaySchema(PDO $pdo){
    static $done=false; if($done) return; $done=true;
    try{ $stmt=$pdo->query("SHOW COLUMNS FROM transactions_votes LIKE 'moyen_paiement'"); $col=$stmt->fetch(); if($col && strpos($col['Type'],"'visa'")===false){ $pdo->exec("ALTER TABLE transactions_votes MODIFY COLUMN moyen_paiement ENUM('mpesa','airtel','orange','africell','carte','especes','manuel','visa','mastercard','maishapay_card','maishapay') NOT NULL DEFAULT 'carte'"); } }catch(Exception $e){}
    try{ $stmt=$pdo->query("SHOW COLUMNS FROM transactions_votes LIKE 'gateway_paiement'"); if($stmt->rowCount()==0){ $pdo->exec("ALTER TABLE transactions_votes ADD COLUMN gateway_paiement ENUM('unipesa','maishapay') NULL DEFAULT NULL AFTER moyen_paiement"); } }catch(Exception $e){}
    try{ $stmt=$pdo->query("SHOW COLUMNS FROM transactions_votes LIKE 'provider_maishapay'"); if($stmt->rowCount()==0){ $pdo->exec("ALTER TABLE transactions_votes ADD COLUMN provider_maishapay VARCHAR(32) NULL DEFAULT NULL AFTER gateway_paiement"); } }catch(Exception $e){}
    try{ $stmt=$pdo->query("SHOW COLUMNS FROM transactions_votes LIKE 'est_paiement_maishapay'"); if($stmt->rowCount()==0){ $pdo->exec("ALTER TABLE transactions_votes ADD COLUMN est_paiement_maishapay TINYINT(1) NULL DEFAULT NULL AFTER provider_maishapay"); } }catch(Exception $e){}
}

$ref = trim($_GET['ref'] ?? $_GET['reference'] ?? '');
$token = trim($_GET['_token'] ?? $_GET['token'] ?? '');
$actionParam = strtolower(trim($_GET['action'] ?? $_GET['status'] ?? ''));
$isCancelAction = in_array($actionParam, ['cancel','canceled','cancelled','annuler','abort','declined','failed','cancelorder']);

if($ref){ if(strpos($ref, '?')!==false) $ref = explode('?', $ref)[0]; $ref = rtrim($ref, "/ \t\n\r\0\x0B"); if(strpos($ref, '/')!==false && preg_match('/(lme-group-CARD-[A-Z0-9\-]+)/i', $ref, $m)){ $ref = $m[1]; } elseif(strpos($ref, '/')!==false){ $parts = explode('/', $ref); $ref = end($parts); $ref = rtrim($ref, "/"); } }

if(!$ref && $token){
    if(!empty($_SESSION['maishapay_ref'])){ $ref = $_SESSION['maishapay_ref']; }
    elseif(!empty($_SESSION['maishapay_last_ref'])){ $ref = $_SESSION['maishapay_last_ref']; }
    else {
        try{
            $pdoTmp = getDB(); ensureMaishapaySchema($pdoTmp);
            $stmtT = $pdoTmp->prepare("SELECT * FROM transactions_votes WHERE ref_transaction_unipesa LIKE ? OR id_transaction_unipesa LIKE ? OR message_retour LIKE ? ORDER BY transaction_id DESC LIMIT 1");
            $like = '%'.$token.'%'; $stmtT->execute([$like, $like, $like]); $found = $stmtT->fetch();
            if($found){ $ref = $found['numero_reference']; }
            else { $stmtLast = $pdoTmp->query("SELECT * FROM transactions_votes WHERE moyen_paiement IN ('carte','visa','mastercard') AND etat_paiement='en_attente' AND initie_le >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) ORDER BY transaction_id DESC LIMIT 1"); $last = $stmtLast->fetch(); if($last) $ref = $last['numero_reference']; }
        } catch(Exception $e){}
    }
    file_put_contents(__DIR__.'/maishapay.log', date('c')." CHECKOUT _token=$token fallback ref=$ref".PHP_EOL, FILE_APPEND);
}

if(!$ref){
    http_response_code(400);
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'><title>Référence manquante</title><style>body{font-family:Inter,sans-serif;background:#050B16;color:#fff;padding:20px;text-align:center}.card{max-width:480px;margin:40px auto;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:24px}a{color:#D4AF37}</style></head><body><div class='card'><h2>Référence manquante</h2><p>Token: ".htmlspecialchars(substr($token,0,30))."</p><p><a href='index.php'>Accueil</a></p></div></body></html>";
    exit;
}

$_SESSION['maishapay_ref'] = $ref;
$_SESSION['maishapay_last_ref'] = $ref;

try{
    $pdo=getDB(); ensureMaishapaySchema($pdo);
    $stmt=$pdo->prepare("SELECT * FROM transactions_votes WHERE numero_reference=? LIMIT 1");
    $stmt->execute([$ref]); $tx=$stmt->fetch();
    if(!$tx){ http_response_code(404); echo "<h2>Transaction introuvable: ".htmlspecialchars($ref)."</h2><p><a href='index.php'>Accueil</a></p>"; exit; }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'https';
    $host = $_SERVER['HTTP_HOST'] ?? 'lme-group.zaloriatech.com';
    $voteBase = $scheme.'://'.$host.'/vote1.php?candidat='.(int)$tx['participante_id'].'&concours_id='.(int)$tx['concours_id'].'&etape_id='.($tx['etape_id']? (int)$tx['etape_id']:'').'&receipt='.urlencode($ref);

    if($isCancelAction && $tx['etat_paiement']==='en_attente'){
        try{ $pdo->prepare("UPDATE transactions_votes SET etat_paiement='echoue', message_retour=CONCAT(COALESCE(message_retour,''), ' | Annulé par user depuis checkout / Cancel Order CyberSource -> arakapay.com'), confirme_le=NOW() WHERE numero_reference=? AND etat_paiement='en_attente'")->execute([$ref]); file_put_contents(__DIR__.'/maishapay.log', date('c')." CHECKOUT CANCEL action=$actionParam ref=$ref marqué echoue".PHP_EOL, FILE_APPEND); $tx['etat_paiement']='echoue'; }catch(Exception $e){}
    }

    if($tx['etat_paiement']==='confirme'){ header('Location: '.$voteBase); exit; }
    if($tx['etat_paiement']==='echoue'){
        $url = $voteBase.'&status=echoue';
        file_put_contents(__DIR__.'/maishapay.log', date('c')." CHECKOUT echoue auto redirect to $url ref=$ref".PHP_EOL, FILE_APPEND);
        header('Location: '.$url);
        echo "<!DOCTYPE html><html><head><meta charset='utf-8'><meta http-equiv='refresh' content='1;url=".htmlspecialchars($url)."'><title>Paiement échoué</title><style>body{font-family:Inter,sans-serif;background:#050B16;color:#fff;padding:20px;text-align:center}.card{max-width:480px;margin:40px auto;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:16px;padding:24px}a{color:#D4AF37}</style></head><body><div class='card'><h2>Paiement annulé / échoué</h2><p>Réf: ".htmlspecialchars($ref)."</p><p>".htmlspecialchars($tx['message_retour']??'')."</p><p>Retour vers vote1.php...</p><p><a href='$url'>Retour et réessayer</a></p><script>window.location='".htmlspecialchars($url)."'</script></div></body></html>";
        exit;
    }

    $paymentPageUrl = $tx['payment_page_url'] ?? null;
    $cancelUrl = $scheme.'://'.$host.'/vote1_checkout.php?ref='.urlencode($ref).'&action=cancel';
    $callbackUrl = $scheme.'://'.$host.'/vote1_callback.php?ref='.urlencode($ref);

    file_put_contents(__DIR__.'/maishapay.log', date('c')." CHECKOUT en_attente ref=$ref FORCE marchand.maishapay.online/checkout (uniform PC/mobile) pp=".substr($paymentPageUrl??'',0,80).PHP_EOL, FILE_APPEND);

    // FORCE même lien PC/mobile = marchand.maishapay.online/payment/vers1.0/merchant/checkout
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Paiement carte - LME GROUP - Checkout Maishapay</title>
<style>
body{font-family:Inter,Outfit,sans-serif;background:#050B16;color:#fff;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px}
.card{max-width:520px;width:100%;background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.02));border:1px solid rgba(255,255,255,.08);border-radius:20px;padding:28px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.spinner{width:44px;height:44px;border:3px solid rgba(212,175,55,.15);border-top-color:#D4AF37;border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 18px}
@keyframes spin{to{transform:rotate(360deg)}}
h2{font-size:1.25rem;margin-bottom:8px}
p{color:rgba(255,255,255,.6);font-size:.88rem;line-height:1.5}
.small{font-size:.72rem;color:rgba(255,255,255,.35);margin-top:14px;word-break:break-all}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;font-weight:700;font-size:.82rem;padding:12px 20px;border-radius:10px;border:none;cursor:pointer;transition:.2s;text-decoration:none;min-height:44px}
.btn-gold{background:linear-gradient(135deg, #D4AF37, #F3D77A);color:#050B16;box-shadow:0 8px 20px rgba(212,175,55,.28)}
.btn-gold:hover{transform:translateY(-1px)}
.btn-outline{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);color:#fff}
.btn-outline:hover{background:rgba(255,255,255,.10)}
.actions{display:flex;flex-direction:column;gap:10px;margin-top:18px}
</style>
</head>
<body>
<div class="card">
  <div class="spinner"></div>
  <h2>Paiement carte sécurisé - Maishapay Checkout</h2>
  <p>Vous allez être redirigé vers <b>https://marchand.maishapay.online/payment/vers1.0/merchant/checkout</b> (même lien PC et mobile).<br>
  Réf: <b><?= htmlspecialchars($ref) ?></b><br>
  Montant: <b><?= htmlspecialchars($tx['montant_paye']) ?> <?= htmlspecialchars($tx['devise']) ?></b> • <?= (int)$tx['votes_accordes'] ?> votes<br>
  <span style="color:#7ab8ff">Provider: <?= htmlspecialchars($tx['provider_maishapay'] ?? 'Visa/Mastercard') ?></span></p>
  <p class="small">Ce lien gère bien le retour quand on clique Cancel (contrairement à secureacceptance.cybersource.com/paymentmethods qui va vers arakapay.com). La carte prend du temps pour donner le statut, polling auto en cours...</p>
  <div class="actions">
    <button class="btn btn-gold" id="continueBtn">💳 Continuer vers Maishapay Checkout</button>
    <a href="<?= htmlspecialchars($cancelUrl) ?>" class="btn btn-outline">❌ Annuler et retourner à vote1.php</a>
    <a href="<?= htmlspecialchars($voteBase.'&status=en_attente') ?>" class="btn btn-outline">↩ Retour à vote1.php (sans annuler)</a>
  </div>
  <p class="small" id="countdown">Redirection auto dans 2s vers Maishapay Checkout...</p>
  <p class="small" id="pollInfo" style="margin-top:8px;color:#7ab8ff"></p>
  <?php if($paymentPageUrl): ?><p class="small">PaymentPage stockée (non utilisée direct pour uniformiser): <?= htmlspecialchars(substr($paymentPageUrl,0,80)) ?>...</p><?php endif; ?>
</div>

<form id="maishaForm" method="POST" action="<?= htmlspecialchars(MAISHA_CHECKOUT_URL) ?>">
  <input type="hidden" name="gatewayMode" value="<?= (int)MAISHA_GATEWAY_MODE ?>">
  <input type="hidden" name="publicApiKey" value="<?= htmlspecialchars(MAISHA_PUBLIC_KEY) ?>">
  <input type="hidden" name="secretApiKey" value="<?= htmlspecialchars(MAISHA_SECRET_KEY) ?>">
  <input type="hidden" name="montant" value="<?= htmlspecialchars($tx['montant_paye']) ?>">
  <input type="hidden" name="devise" value="<?= htmlspecialchars($tx['devise'] ?? 'USD') ?>">
  <input type="hidden" name="callbackUrl" value="<?= htmlspecialchars($callbackUrl) ?>">
</form>

<script>
const el=document.getElementById('countdown');
const pollInfo=document.getElementById('pollInfo');
const ref="<?= htmlspecialchars($ref) ?>";
const voteBase="<?= htmlspecialchars($voteBase) ?>";
const apiFile="vote1_api.php";
let c=2;
const it=setInterval(()=>{c--; if(c<=0){clearInterval(it); if(el) el.textContent='Redirection vers Maishapay Checkout...'; document.getElementById('maishaForm').submit();} else {if(el) el.textContent='Redirection auto dans '+c+'s vers https://marchand.maishapay.online/payment/vers1.0/merchant/checkout';}}, 800);
setTimeout(()=>{document.getElementById('maishaForm').submit();}, 2000);
document.getElementById('continueBtn')?.addEventListener('click',()=>{document.getElementById('maishaForm').submit();});

let pollAttempts=0;
const pollMax=200;
const pollInterval=setInterval(async ()=>{
  pollAttempts++;
  try{
    const fd=new FormData(); fd.append('action','check_payment'); fd.append('reference',ref);
    const res=await fetch(apiFile,{method:'POST',body:fd});
    const info=await res.json();
    if(pollInfo) pollInfo.textContent='Vérif '+pollAttempts+'/'+pollMax+': '+info.statut+' - '+(info.message||'').substring(0,100);
    if(info.statut==='confirme'){
      clearInterval(pollInterval); clearInterval(it);
      if(el) el.textContent='Paiement confirmé ! Redirection vers reçu...';
      window.location=voteBase;
    }else if(info.statut==='echoue'){
      clearInterval(pollInterval); clearInterval(it);
      if(el) el.textContent='Paiement échoué/annulé: '+(info.message||'').substring(0,120)+' - Retour vers vote...';
      window.location=voteBase+'&status=echoue';
    }
    if(pollAttempts>=pollMax){ clearInterval(pollInterval); if(el) el.textContent='Toujours en attente après 10min. Cliquez Annuler pour revenir avec vraie raison.'; }
  }catch(e){}
}, 3000);
</script>
</body>
</html>
<?php
    exit;
}catch(Exception $e){
    file_put_contents(__DIR__.'/maishapay.log', date('c').' CHECKOUT ERROR ref='.$ref.' '.$e->getMessage().PHP_EOL, FILE_APPEND);
    http_response_code(500);
    echo "<h2>Erreur serveur</h2><p>".htmlspecialchars($e->getMessage())."</p><p><a href='index.php'>Accueil</a></p>";
    exit;
}
