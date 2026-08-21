<?php
// vote1_checkout.php — Redirection sécurisée vers MaishaPay Checkout pour carte
// Fix CORS + Livewire: on ne fait plus curl serveur qui affiche HTML depuis notre domaine (causait 404 /livewire/ + CORS)
// Maintenant on affiche un form auto-submit côté client qui POST vers https://marchand.maishapay.online/payment/vers1.0/merchant/checkout
// Le navigateur va sur le domaine Maishapay, assets chargés depuis Maishapay (même origine) => plus de CORS / Livewire not defined
// Secret exposé dans form (exigence Maishapay Checkout), mais masqué dans logs + .htaccess deny logs

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

$ref = trim($_GET['ref'] ?? $_GET['reference'] ?? '');
$token = trim($_GET['_token'] ?? $_GET['token'] ?? '');

// Nettoie ref si contient /?status= ou ?status= ou trailing slash (bug Maishapay)
if($ref){
    if(strpos($ref, '?')!==false) $ref = explode('?', $ref)[0];
    $ref = rtrim($ref, "/ \t\n\r\0\x0B");
    if(strpos($ref, '/')!==false && preg_match('/(lme-group-CARD-[A-Z0-9\-]+)/i', $ref, $m)){
        $ref = $m[1];
    } elseif(strpos($ref, '/')!==false){
        $parts = explode('/', $ref);
        $ref = end($parts);
        $ref = rtrim($ref, "/");
    }
}

// Si _token sans ref, tente session ou recherche par token ou dernière transaction
if(!$ref && $token){
    if(!empty($_SESSION['maishapay_ref'])){
        $ref = $_SESSION['maishapay_ref'];
    } elseif(!empty($_SESSION['maishapay_last_ref'])){
        $ref = $_SESSION['maishapay_last_ref'];
    } else {
        try{
            $pdoTmp = getDB();
            ensureMaishapaySchema($pdoTmp);
            // Cherche par token dans id/ref/message
            $stmtT = $pdoTmp->prepare("SELECT * FROM transactions_votes WHERE ref_transaction_unipesa LIKE ? OR id_transaction_unipesa LIKE ? OR message_retour LIKE ? ORDER BY transaction_id DESC LIMIT 1");
            $like = '%'.$token.'%';
            $stmtT->execute([$like, $like, $like]);
            $found = $stmtT->fetch();
            if($found){
                $ref = $found['numero_reference'];
            } else {
                $stmtLast = $pdoTmp->query("SELECT * FROM transactions_votes WHERE moyen_paiement IN ('carte','visa','mastercard') AND etat_paiement='en_attente' AND initie_le >= DATE_SUB(NOW(), INTERVAL 15 MINUTE) ORDER BY transaction_id DESC LIMIT 1");
                $last = $stmtLast->fetch();
                if($last) $ref = $last['numero_reference'];
            }
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
    $pdo=getDB();
    ensureMaishapaySchema($pdo);
    $stmt=$pdo->prepare("SELECT * FROM transactions_votes WHERE numero_reference=? LIMIT 1");
    $stmt->execute([$ref]);
    $tx=$stmt->fetch();
    if(!$tx){
        http_response_code(404);
        echo "<h2>Transaction introuvable: ".htmlspecialchars($ref)."</h2><p><a href='index.php'>Accueil</a></p>";
        exit;
    }
    if($tx['etat_paiement']==='confirme'){
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'https';
        $host = $_SERVER['HTTP_HOST'] ?? 'lme-group.zaloriatech.com';
        $url = $scheme.'://'.$host.'/vote1.php?candidat='.(int)$tx['participante_id'].'&concours_id='.(int)$tx['concours_id'].'&etape_id='.($tx['etape_id']? (int)$tx['etape_id']:'').'&receipt='.urlencode($ref);
        header('Location: '.$url);
        exit;
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'lme-group.zaloriatech.com';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'https';
    $callbackUrl = $scheme.'://'.$host.'/vote1_callback.php?ref='.urlencode($ref);

    // Log masqué
    file_put_contents(__DIR__.'/maishapay.log', date('c')." CHECKOUT CLIENT FORM ref=$ref montant=".$tx['montant_paye']." devise=".$tx['devise']." callback=$callbackUrl".PHP_EOL, FILE_APPEND);

    // Affiche page auto-submit vers Maishapay (évite CORS / Livewire not defined car on va sur domaine Maishapay)
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Redirection paiement sécurisé - LME GROUP</title>
<style>
body{font-family:Inter,Outfit,sans-serif;background:#050B16;color:#fff;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:20px}
.card{max-width:460px;width:100%;background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.02));border:1px solid rgba(255,255,255,.08);border-radius:20px;padding:28px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.3)}
.spinner{width:44px;height:44px;border:3px solid rgba(212,175,55,.15);border-top-color:#D4AF37;border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 18px}
@keyframes spin{to{transform:rotate(360deg)}}
h2{font-size:1.3rem;margin-bottom:8px}
p{color:rgba(255,255,255,.6);font-size:.88rem;line-height:1.5}
.small{font-size:.72rem;color:rgba(255,255,255,.35);margin-top:14px;word-break:break-all}
</style>
</head>
<body>
<div class="card">
  <div class="spinner"></div>
  <h2>Redirection vers paiement sécurisé</h2>
  <p>Vous allez être redirigé vers la page sécurisée MaishaPay pour saisir votre carte <b><?= htmlspecialchars($tx['provider_maishapay'] ?? 'Visa/Mastercard') ?></b>.<br>
  Référence: <b><?= htmlspecialchars($ref) ?></b><br>
  Montant: <b><?= htmlspecialchars($tx['montant_paye']) ?> <?= htmlspecialchars($tx['devise']) ?></b> • <?= (int)$tx['votes_accordes'] ?> votes</p>
  <p class="small">3D Secure • Chiffré • Ne fermez pas cette page</p>
  <p class="small" id="countdown">Redirection dans 1s...</p>
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
let c=1;
const el=document.getElementById('countdown');
const it=setInterval(()=>{c--; if(c<=0){clearInterval(it); el.textContent='Redirection...'; document.getElementById('maishaForm').submit();} else {el.textContent='Redirection dans '+c+'s...';}}, 800);
setTimeout(()=>{document.getElementById('maishaForm').submit();}, 1200);
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
