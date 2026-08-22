<?php
// maishapay_verify.php — Page admin pour vérifier l'état des transactions Maishapay / Unipesa et mettre à jour la table si en_attente
// Usage: https://lme-group.zaloriatech.com/maishapay_verify.php?key=LME2026VERIFY
// Sécurité: clé simple définie ci-dessous, à changer en prod

ini_set('display_errors', 1);
error_reporting(E_ALL);

define('VERIFY_ADMIN_KEY', 'LME2026VERIFY'); // change cette clé en prod
define('DB_HOST', 'localhost:3306');
define('DB_NAME', 'mayi1275_zaloria_multisysteme');
define('DB_USER', 'mayi1275_zaloriatech');
define('DB_PASS', '07/09/1996/O2switch');

define('MAISHA_GATEWAY_MODE', 1);
define('MAISHA_PUBLIC_KEY', 'MP-LIVEPK-Dcx4lX0$W5i5QieJu1bdAJt7oyW5v$5JRw.5u$VQ3.lp71x1.WyWVexI1qiSyvR1Ip$2xznuc5hQVQzmrwZO17f$7vmHOzauVIdRW$WqVu1D7vkO2WmX0IeS');
define('MAISHA_SECRET_KEY', 'MP-LIVEPK-1yVfuv1t2v.aFrVrOXUIPdABlg2uvjn8$ylt8tFisaiUMVydYeKyQ$bBU7GO5Ef62A601E3d3corYomiahe8uW$E0vSzcl9P$VOWxiWRh1A2w$H0ISup0y$T');
define('MAISHA_MERCHANT_ID', '000945');
define('MAISHA_REST_URL', 'https://marchand.maishapay.online/api/payment/rest/vers1.0/merchant');

define('UNIPESA_PUBLIC_ID',   'cdefaccbefd7e5fec36f514fd051f2185969e603');
define('UNIPESA_MERCHANT_ID', 'cdefa368fd86db654502ca1cb922bc5a1a691055');
define('UNIPESA_SECRET_KEY',  'cdbbf8a2f9e7790193d265acd4442275633ef46c280629a5181a46ee57e4e62799a2cdf6a5d9de5347163c6d79edbffa154eb274e6aca317320fe57a734874ce');
define('UNIPESA_BASE_URL',    'https://api.unipesa.tech');

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

function logVerify($msg){
    file_put_contents(__DIR__.'/maishapay_verify.log', date('c').' '.$msg.PHP_EOL, FILE_APPEND);
}

// Auth simple
$key = $_GET['key'] ?? $_POST['key'] ?? '';
if($key !== VERIFY_ADMIN_KEY){
    http_response_code(403);
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Accès refusé</title><style>body{font-family:Inter,sans-serif;background:#050B16;color:#fff;padding:40px;text-align:center}.card{max-width:400px;margin:40px auto;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:24px}</style></head><body><div class='card'><h2>🔒 Accès refusé</h2><p>Clé manquante ou invalide. Utilisez ?key=".VERIFY_ADMIN_KEY."</p><p><a href='index.php' style='color:#D4AF37'>Accueil</a></p></div></body></html>";
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$refParam = trim($_GET['ref'] ?? $_POST['ref'] ?? '');

// ===== Détection site_id pour filtrer juste ce site (lme-group) =====
function detectSiteId(PDO $pdo): ?int {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $domain = 'zaloriatech.com';
    $subdomain = '';
    if (stripos($host, 'lme-group') !== false || stripos($host, 'aurora') !== false || $host==='localhost' || $host==='127.0.0.1' || filter_var(explode(':',$host)[0], FILTER_VALIDATE_IP) || strpos($host,'e2b.dev')!==false) {
        $subdomain='lme-group';
    } else if (preg_match('/^(.*?)\\.'.preg_quote($domain,'/').'$/', $host, $m)) {
        $subdomain=$m[1];
    } else { $subdomain='lme-group'; }
    $stmt=$pdo->prepare("SELECT site_id FROM sites WHERE lien_unique=? LIMIT 1");
    $stmt->execute([$subdomain]);
    $row=$stmt->fetch();
    if($row) return (int)$row['site_id'];
    // fallback: si un seul site ou lme-group existe
    $stmt2=$pdo->prepare("SELECT site_id FROM sites WHERE lien_unique='lme-group' LIMIT 1");
    $stmt2->execute();
    $row2=$stmt2->fetch();
    if($row2) return (int)$row2['site_id'];
    // dernier fallback: premier site
    $stmt3=$pdo->query("SELECT site_id FROM sites LIMIT 1");
    $row3=$stmt3->fetch();
    return $row3 ? (int)$row3['site_id'] : null;
}

$pdo = getDB();
$currentSiteId = detectSiteId($pdo);
$currentSiteLien = 'lme-group';
try{
    $stmtLien=$pdo->prepare("SELECT lien_unique FROM sites WHERE site_id=?");
    $stmtLien->execute([$currentSiteId]);
    $rLien=$stmtLien->fetch();
    if($rLien) $currentSiteLien=$rLien['lien_unique'];
}catch(Exception $e){}

// Actions POST
$messageAction = '';
if($action==='check_one' && $refParam){
    // Vérifie une transaction en attente via Unipesa ou Maishapay - filtré par site_id
    $stmt=$pdo->prepare("SELECT * FROM transactions_votes WHERE numero_reference=? AND site_id=:sid LIMIT 1");
    $stmt->execute([$refParam, ':sid'=>$currentSiteId]);
    $tx=$stmt->fetch();
    if(!$tx){
        $messageAction = "Transaction $refParam introuvable.";
    } else {
        if(in_array($tx['moyen_paiement'], ['mpesa','airtel','orange','africell','vodacom'])){
            // Unipesa check
            $payload=['merchant_id'=>UNIPESA_MERCHANT_ID,'order_id'=>$refParam];
            $payload['signature']=calcUnipesaSignature($payload,UNIPESA_SECRET_KEY);
            $result=unipesaPost('status',$payload,10);
            logVerify("CHECK ONE UNIPESA ref=$refParam RESP:".$result['response']);
            if($result['response']){
                $data=json_decode($result['response'],true);
                $uniStatus=isset($data['status'])?(int)$data['status']:-1;
                $internal=unipesaStatusToInternal($uniStatus);
                $msg=$data['result']['message']??$data['provider_result']['message']??'';
                if($internal!==PAIEMENT_ETAT_EN_ATTENTE){
                    $pdo->prepare("UPDATE transactions_votes SET etat_paiement=:s, confirme_le=NOW(), id_transaction_unipesa=:tid, ref_transaction_unipesa=:tref, message_retour=:msg WHERE numero_reference=:r")
                        ->execute([
                            ':s'=>$internal,
                            ':tid'=>$data['transaction_id']??'',
                            ':tref'=>$data['transaction_ref']??'',
                            ':msg'=>$msg,
                            ':r'=>$refParam,
                        ]);
                    $messageAction = "✅ Unipesa ref $refParam mis à jour: $internal - $msg";
                } else {
                    $messageAction = "⏳ Unipesa ref $refParam toujours en_attente (status $uniStatus)";
                }
            } else {
                $messageAction = "❌ Erreur réseau Unipesa pour $refParam: ".$result['error'];
            }
        } else {
            // Maishapay - pas d'API status, on tente de vérifier si callback a déjà mis à jour ou on propose manuel
            // On tente quand même un appel REST avec transactionReference seul pour voir si Maishapay retourne info (testé 400 besoin amount etc)
            // Donc on se base sur DB et on informe
            $messageAction = "ℹ️ Maishapay CARD ref $refParam: pas d'endpoint status public chez Maishapay. Vérifiez manuellement sur https://marchand.maishapay.online (Merchant 000945) avec ref $refParam ou TxID ".$tx['id_transaction_unipesa'].". Si payé, utilisez action Marquer Confirmé.";
            // Si en_attente depuis >15min, on peut marquer echoue auto
            $initie = strtotime($tx['initie_le'] ?? '');
            if($initie && (time()-$initie)>900){
                $messageAction .= " | Transaction >15min en attente, vous pouvez la marquer échouée pour débloquer.";
            }
        }
    }
} elseif($action==='mark_confirme' && $refParam){
    $pdo->prepare("UPDATE transactions_votes SET etat_paiement='confirme', confirme_le=NOW(), message_retour=CONCAT(COALESCE(message_retour,''), ' | Confirmé manuellement via maishapay_verify.php par admin') WHERE numero_reference=? AND site_id=:sid")->execute([$refParam, ':sid'=>$currentSiteId]);
    logVerify("MARK CONFIRME ref=$refParam site=$currentSiteId par admin");
    $messageAction = "✅ Transaction $refParam (site $currentSiteLien) marquée CONFIRMÉE manuellement.";
} elseif($action==='mark_echoue' && $refParam){
    $pdo->prepare("UPDATE transactions_votes SET etat_paiement='echoue', confirme_le=NOW(), message_retour=CONCAT(COALESCE(message_retour,''), ' | Échoué manuellement via maishapay_verify.php par admin') WHERE numero_reference=? AND site_id=:sid")->execute([$refParam, ':sid'=>$currentSiteId]);
    logVerify("MARK ECHOUE ref=$refParam site=$currentSiteId par admin");
    $messageAction = "✅ Transaction $refParam (site $currentSiteLien) marquée ÉCHOUÉE manuellement.";
} elseif($action==='check_all_pending'){
    // Vérifie tous les en_attente Unipesa de CE SITE uniquement (même si page est pour carte, on garde pour debug)
    $stmt=$pdo->prepare("SELECT * FROM transactions_votes WHERE site_id=:sid AND etat_paiement='en_attente' AND moyen_paiement IN ('mpesa','airtel','orange','africell','vodacom') ORDER BY initie_le DESC LIMIT 50");
    $stmt->execute([':sid'=>$currentSiteId]);
    $rows=$stmt->fetchAll();
    $count=0; $updated=0;
    foreach($rows as $tx){
        $ref = $tx['numero_reference'];
        $payload=['merchant_id'=>UNIPESA_MERCHANT_ID,'order_id'=>$ref];
        $payload['signature']=calcUnipesaSignature($payload,UNIPESA_SECRET_KEY);
        $result=unipesaPost('status',$payload,8);
        if($result['response']){
            $data=json_decode($result['response'],true);
            $uniStatus=isset($data['status'])?(int)$data['status']:-1;
            $internal=unipesaStatusToInternal($uniStatus);
            $msg=$data['result']['message']??'';
            if($internal!==PAIEMENT_ETAT_EN_ATTENTE){
                $pdo->prepare("UPDATE transactions_votes SET etat_paiement=:s, confirme_le=NOW(), id_transaction_unipesa=:tid, ref_transaction_unipesa=:tref, message_retour=:msg WHERE numero_reference=:r")
                    ->execute([
                        ':s'=>$internal,
                        ':tid'=>$data['transaction_id']??'',
                        ':tref'=>$data['transaction_ref']??'',
                        ':msg'=>$msg,
                        ':r'=>$ref,
                    ]);
                $updated++;
            }
        }
        $count++;
        usleep(200000);
    }
    $messageAction = "✅ Vérification bulk Unipesa (site $currentSiteLien): $count transactions vérifiées, $updated mises à jour.";
    logVerify($messageAction);
} elseif($action==='auto_echoue_timeout'){
    // Marque echoue tous les CARD en_attente >15min de CE SITE uniquement
    $stmt=$pdo->prepare("UPDATE transactions_votes SET etat_paiement='echoue', message_retour=CONCAT(COALESCE(message_retour,''), ' | Auto echoue timeout via verify page >15min'), confirme_le=NOW() WHERE site_id=:sid AND etat_paiement='en_attente' AND (moyen_paiement IN ('carte','visa','mastercard') OR gateway_paiement='maishapay' OR provider_maishapay IN ('VISA','MASTERCARD')) AND initie_le < DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
    $stmt->execute([':sid'=>$currentSiteId]);
    $affected=$stmt->rowCount();
    $messageAction = "✅ Auto echoue timeout (site $currentSiteLien): $affected transactions CARD Visa/Mastercard en_attente >15min marquées échouées.";
    logVerify($messageAction);
}

// Liste des transactions - FILTRE SITE_ID + VISA/MASTERCARD UNIQUEMENT (demande user)
$filter = $_GET['filter'] ?? 'en_attente'; // en_attente, tous, confirme, echoue, carte
$where = "1=1";
$params=[];

// Filtre obligatoire site_id pour n'afficher que ce site
if($currentSiteId){
    $where.=" AND t.site_id = :site_id";
    $params[':site_id']=$currentSiteId;
}

// Filtre obligatoire carte visa/mastercard uniquement
$cardCondition = "(t.moyen_paiement IN ('carte','visa','mastercard') OR t.gateway_paiement='maishapay' OR t.provider_maishapay IN ('VISA','MASTERCARD'))";
$where.=" AND $cardCondition";

if($filter==='en_attente'){
    $where.=" AND t.etat_paiement='en_attente'";
} elseif($filter==='confirme'){
    $where.=" AND t.etat_paiement='confirme'";
} elseif($filter==='echoue'){
    $where.=" AND t.etat_paiement='echoue'";
} elseif($filter==='tous'){
    // tous les cartes de ce site
}

$search = trim($_GET['search'] ?? '');
if($search){
    $where.=" AND (t.numero_reference LIKE :s OR t.numero_telephone LIKE :s OR t.email_votant LIKE :s OR t.id_transaction_unipesa LIKE :s)";
    $params[':s']="%$search%";
}

$stmt=$pdo->prepare("SELECT t.*, p.nom_complet as candidate_name, p.code_participante FROM transactions_votes t LEFT JOIN participantes p ON p.participante_id=t.participante_id WHERE $where ORDER BY t.initie_le DESC LIMIT 200");
$stmt->execute($params);
$transactions=$stmt->fetchAll();

// Stats filtrées par site_id + carte uniquement
$statsWhere = "WHERE site_id = :site_id AND (moyen_paiement IN ('carte','visa','mastercard') OR gateway_paiement='maishapay' OR provider_maishapay IN ('VISA','MASTERCARD'))";
$statsStmt=$pdo->prepare("SELECT etat_paiement, COUNT(*) as cnt FROM transactions_votes $statsWhere GROUP BY etat_paiement");
$statsStmt->execute([':site_id'=>$currentSiteId]);
$stats=$statsStmt->fetchAll();
$statsMap=[]; foreach($stats as $s){ $statsMap[$s['etat_paiement']]=$s['cnt']; }
$totalPending=$statsMap['en_attente']??0;

// Stats globales pour info (optionnel)
$statsAll=$pdo->prepare("SELECT etat_paiement, COUNT(*) as cnt FROM transactions_votes WHERE site_id = :site_id GROUP BY etat_paiement");
$statsAll->execute([':site_id'=>$currentSiteId]);
$statsAllMap=[]; foreach($statsAll->fetchAll() as $s){ $statsAllMap[$s['etat_paiement']]=$s['cnt']; }


?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Maishapay Verify - Admin LME</title>
<style>
:root{--bg:#050B16;--gold:#D4AF37;--gold-lt:#F3D77A;--muted:rgba(255,255,255,.6);--green:#22c55e;--red:#ef4444;--yellow:#eab308}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:Inter,Outfit,sans-serif;background:var(--bg);color:#fff;padding:20px}
a{color:var(--gold-lt);text-decoration:none}
.container{max-width:1400px;margin:0 auto}
h1{font-size:1.6rem;margin-bottom:8px}
.subtitle{color:var(--muted);font-size:.9rem;margin-bottom:16px}
.card{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:14px;padding:16px;margin-bottom:16px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:8px;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);color:#fff;font-size:.78rem;cursor:pointer;transition:.15s;text-decoration:none}
.btn:hover{background:rgba(255,255,255,.10)}
.btn-gold{background:linear-gradient(135deg, var(--gold), var(--gold-lt));color:#050B16;border-color:var(--gold);font-weight:700}
.btn-green{background:rgba(34,197,94,.12);border-color:rgba(34,197,94,.22);color:#86efac}
.btn-red{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.22);color:#fca5a5}
.btn-yellow{background:rgba(234,179,8,.12);border-color:rgba(234,179,8,.22);color:#fde68a}
table{width:100%;border-collapse:collapse;font-size:.78rem}
th,td{padding:8px 10px;border-bottom:1px solid rgba(255,255,255,.06);text-align:left;vertical-align:top}
th{color:var(--muted);font-size:.68rem;text-transform:uppercase;letter-spacing:.06em}
.badge{display:inline-flex;padding:3px 8px;border-radius:100px;font-size:.66rem;font-weight:700}
.badge-en_attente{background:rgba(234,179,8,.12);color:#fde68a;border:1px solid rgba(234,179,8,.22)}
.badge-confirme{background:rgba(34,197,94,.12);color:#86efac;border:1px solid rgba(34,197,94,.22)}
.badge-echoue{background:rgba(239,68,68,.12);color:#fca5a5;border:1px solid rgba(239,68,68,.22)}
.alert{padding:12px 14px;border-radius:10px;margin-bottom:12px;font-size:.84rem}
.alert-success{background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.22);color:#86efac}
.alert-error{background:rgba(239,68,68,.10);border:1px solid rgba(239,68,68,.22);color:#fca5a5}
.alert-info{background:rgba(0,102,204,.10);border:1px solid rgba(0,102,204,.22);color:#7ab8ff}
.filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
.filters a{padding:6px 12px;border-radius:100px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);font-size:.72rem;color:var(--muted)}
.filters a.active{background:var(--gold);color:#050B16;border-color:var(--gold)}
.actions{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}
.mono{font-family:monospace;font-size:.74rem}
</style>
</head>
<body>
<div class="container">
  <h1>🔍 Maishapay Verify - Visa/Mastercard - <?= htmlspecialchars($currentSiteLien) ?> (site_id <?= (int)$currentSiteId ?>)</h1>
  <p class="subtitle">Vérifie uniquement les paiements <b>Carte Visa/Mastercard (Maishapay)</b> pour ce site <code><?= htmlspecialchars($currentSiteLien) ?> (ID <?= (int)$currentSiteId ?>)</code> via champ <code>site_id</code>. Met à jour <code>transactions_votes</code> si en_attente. Clé: <?= htmlspecialchars(VERIFY_ADMIN_KEY) ?> | En attente carte ce site: <b><?= (int)$totalPending ?></b> | Total site: <?= ($statsAllMap['en_attente']??0)+($statsAllMap['confirme']??0)+($statsAllMap['echoue']??0) ?> (tous moyens)</p>

  <?php if($messageAction): ?><div class="alert alert-success"><?= htmlspecialchars($messageAction) ?></div><?php endif; ?>

  <div class="card">
    <h3 style="margin-bottom:10px">📊 Stats</h3>
    <div style="display:flex;gap:12px;flex-wrap:wrap">
      <?php foreach($statsMap as $etat=>$cnt): ?>
        <span class="badge badge-<?= $etat ?>"><?= $etat ?>: <?= (int)$cnt ?></span>
      <?php endforeach; ?>
    </div>
    <div style="margin-top:12px" class="actions">
      <a href="?key=<?= urlencode(VERIFY_ADMIN_KEY) ?>&action=check_all_pending&filter=<?= urlencode($filter) ?>" class="btn btn-yellow" onclick="return confirm('Vérifier 50 transactions Mobile en_attente chez Unipesa ?')">🔄 Vérifier tous Mobile en_attente (Unipesa API)</a>
      <a href="?key=<?= urlencode(VERIFY_ADMIN_KEY) ?>&action=auto_echoue_timeout&filter=<?= urlencode($filter) ?>" class="btn btn-red" onclick="return confirm('Marquer échouées toutes les CARD en_attente >15min sans callback ?')">⏱️ Auto échouer CARD >15min</a>
      <a href="https://marchand.maishapay.online" target="_blank" class="btn">🔗 Dashboard Maishapay (Merchant 000945)</a>
      <a href="voter.php?candidat=1" class="btn">↩ Retour voter.php</a>
    </div>
  </div>

  <div class="card">
    <div class="filters">
      <a href="?key=<?= urlencode(VERIFY_ADMIN_KEY) ?>&filter=en_attente" class="<?= $filter==='en_attente'?'active':'' ?>">En attente Visa/MC (<?= $statsMap['en_attente']??0 ?>)</a>
      <a href="?key=<?= urlencode(VERIFY_ADMIN_KEY) ?>&filter=confirme" class="<?= $filter==='confirme'?'active':'' ?>">Confirmés Visa/MC (<?= $statsMap['confirme']??0 ?>)</a>
      <a href="?key=<?= urlencode(VERIFY_ADMIN_KEY) ?>&filter=echoue" class="<?= $filter==='echoue'?'active':'' ?>">Échoués Visa/MC (<?= $statsMap['echoue']??0 ?>)</a>
      <a href="?key=<?= urlencode(VERIFY_ADMIN_KEY) ?>&filter=tous" class="<?= $filter==='tous'?'active':'' ?>">Tous Visa/MC ce site</a>
    </div>
    <form method="GET" style="display:flex;gap:8px;margin-bottom:12px">
      <input type="hidden" name="key" value="<?= htmlspecialchars(VERIFY_ADMIN_KEY) ?>">
      <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Recherche ref, tel, email, TxID..." style="flex:1;padding:8px 12px;border-radius:8px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);color:#fff">
      <button class="btn">🔍 Chercher</button>
    </form>

    <div style="overflow-x:auto">
    <table>
      <thead>
        <tr>
          <th>Référence / Date</th>
          <th>Candidate / Votes / Montant</th>
          <th>Méthode / Gateway / Tel / Email</th>
          <th>État / Message retour (vraie raison)</th>
          <th>Maishapay Tx / PaymentPage</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($transactions as $tx): ?>
        <tr>
          <td>
            <div class="mono"><b><?= htmlspecialchars($tx['numero_reference']) ?></b></div>
            <div style="color:var(--muted);font-size:.68rem"><?= htmlspecialchars($tx['initie_le']) ?></div>
            <div style="color:var(--muted);font-size:.66rem">ID: <?= (int)$tx['transaction_id'] ?></div>
          </td>
          <td>
            <div><?= htmlspecialchars($tx['candidate_name'] ?? '—') ?> (<?= htmlspecialchars($tx['code_participante'] ?? $tx['participante_id']) ?>)</div>
            <div><?= (int)$tx['votes_accordes'] ?> votes</div>
            <div><b><?= htmlspecialchars($tx['montant_paye']) ?> <?= htmlspecialchars($tx['devise']) ?></b></div>
          </td>
          <td>
            <div><span class="badge badge-<?= $tx['etat_paiement'] ?>"><?= htmlspecialchars($tx['moyen_paiement']) ?></span></div>
            <div style="font-size:.68rem;color:var(--muted)"><?= htmlspecialchars($tx['gateway_paiement'] ?? '—') ?> / <?= htmlspecialchars($tx['provider_maishapay'] ?? '—') ?></div>
            <div class="mono"><?= htmlspecialchars($tx['numero_telephone']) ?></div>
            <div style="font-size:.68rem"><?= htmlspecialchars($tx['email_votant']) ?></div>
          </td>
          <td>
            <div><span class="badge badge-<?= $tx['etat_paiement'] ?>"><?= htmlspecialchars($tx['etat_paiement']) ?></span></div>
            <div style="max-width:320px;word-break:break-word;font-size:.72rem;margin-top:4px;background:rgba(255,255,255,.04);padding:6px 8px;border-radius:6px"><?= htmlspecialchars($tx['message_retour'] ?? '—') ?></div>
          </td>
          <td>
            <div class="mono" style="max-width:180px;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($tx['id_transaction_unipesa'] ?? '—') ?></div>
            <div style="font-size:.66rem;color:var(--muted);max-width:180px;overflow:hidden;text-overflow:ellipsis"><?= htmlspecialchars($tx['payment_page_url'] ?? '') ?></div>
            <?php if(!empty($tx['payment_page_url'])): ?><div><a href="<?= htmlspecialchars($tx['payment_page_url']) ?>" target="_blank" class="btn" style="padding:4px 8px;font-size:.66rem">🔗 PaymentPage</a></div><?php endif; ?>
          </td>
          <td>
            <div style="display:flex;flex-direction:column;gap:4px">
              <a href="?key=<?= urlencode(VERIFY_ADMIN_KEY) ?>&action=check_one&ref=<?= urlencode($tx['numero_reference']) ?>&filter=<?= urlencode($filter) ?>" class="btn btn-yellow" style="padding:4px 8px;font-size:.68rem">🔍 Vérifier état réel</a>
              <?php if($tx['etat_paiement']==='en_attente'): ?>
                <a href="?key=<?= urlencode(VERIFY_ADMIN_KEY) ?>&action=mark_confirme&ref=<?= urlencode($tx['numero_reference']) ?>&filter=<?= urlencode($filter) ?>" class="btn btn-green" style="padding:4px 8px;font-size:.68rem" onclick="return confirm('Marquer CONFIRMÉ manuellement ? Vérifiez d\'abord sur Maishapay dashboard !')">✅ Marquer Confirmé</a>
                <a href="?key=<?= urlencode(VERIFY_ADMIN_KEY) ?>&action=mark_echoue&ref=<?= urlencode($tx['numero_reference']) ?>&filter=<?= urlencode($filter) ?>" class="btn btn-red" style="padding:4px 8px;font-size:.68rem" onclick="return confirm('Marquer ÉCHOUÉ manuellement ?')">❌ Marquer Échoué</a>
              <?php endif; ?>
              <a href="voter.php?candidat=<?= (int)$tx['participante_id'] ?>&concours_id=<?= (int)$tx['concours_id'] ?>&receipt=<?= urlencode($tx['numero_reference']) ?>" target="_blank" class="btn" style="padding:4px 8px;font-size:.68rem">🧾 Voir reçu</a>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if(empty($transactions)): ?><tr><td colspan="6" style="text-align:center;color:var(--muted);padding:20px">Aucune transaction pour ce filtre.</td></tr><?php endif; ?>
      </tbody>
    </table>
    </div>
  </div>

  <div class="card">
    <h3>ℹ️ Comment ça marche</h3>
    <ul style="margin-left:18px;color:var(--muted);font-size:.82rem;line-height:1.6">
      <li><b>Mobile Money (Unipesa)</b> : bouton Vérifier appelle l'API Unipesa <code>/status</code> avec <code>order_id=reference</code> et signature HMAC SHA512. Si Unipesa retourne status 2 → confirme, >=3 → echoue avec vraie raison (solde insuffisant, annulé, etc). Met à jour <code>transactions_votes.message_retour</code>.</li>
      <li><b>Carte (Maishapay)</b> : Maishapay REST n'a pas d'endpoint status public (testé 404). On ne peut pas vérifier automatiquement. Vérifiez manuellement sur <a href="https://marchand.maishapay.online" target="_blank">marchand.maishapay.online</a> avec Merchant 000945, cherchez la ref ou TxID (ex: <?= htmlspecialchars($transactions[0]['id_transaction_unipesa'] ?? '2032xxx') ?>). Si payé sur dashboard mais en_attente chez nous (callback manqué), cliquez Marquer Confirmé.</li>
      <li><b>Auto echoue timeout</b> : les cartes CyberSource qui restent en_attente >15min sans callback (ex: Your order was declined) sont auto marquées échouées pour débloquer retry.</li>
      <li>Sécurité : changez <code>VERIFY_ADMIN_KEY</code> dans le fichier et gardez le fichier hors index (ou protégez par .htaccess).</li>
    </ul>
  </div>

</div>
</body>
</html>
