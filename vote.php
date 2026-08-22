<?php
// vote1.php — Miss Aurora RDC 2026 — Vote avec Mobile Money (Unipesa) + Carte Visa/Mastercard (Maishapay PRODUCTION)
// Clone de voter.php + ajout paiement carte via Maishapay Checkout PRODUCTION
// PRODUCTION Keys: MP-LIVEPK-Dcx4lX0$... / MP-LIVEPK-1yVfuv1t2v... - GatewayMode 1 LIVE
// Sécurité: secret jamais exposé côté client JS, via vote_checkout.php serveur, logs masqués, .htaccess deny logs

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

define('DB_HOST', 'localhost:3306');
define('DB_NAME', 'mayi1275_zaloria_multisysteme');
define('DB_USER', 'mayi1275_zaloriatech');
define('DB_PASS', '07/09/1996/O2switch');

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) { die("Erreur connexion: ".$e->getMessage()); }

// ===== Détection site =====
$host = $_SERVER['HTTP_HOST'] ?? '';
$domain = 'zaloriatech.com';
$subdomain = '';
if (stripos($host, 'lme-group') !== false || stripos($host, 'aurora') !== false || $host==='localhost' || $host==='127.0.0.1' || filter_var(explode(':',$host)[0], FILTER_VALIDATE_IP) || strpos($host,'e2b.dev')!==false) {
    $subdomain='lme-group';
} else if (preg_match('/^(.*?)\.'.preg_quote($domain,'/').'$/', $host, $m)) {
    $subdomain=$m[1];
} else { $subdomain='lme-group'; }

$stmtSite=$pdo->prepare("SELECT site_id, nom_entreprise, logo_concours, logo_extension, lien_unique FROM sites WHERE lien_unique=?");
$stmtSite->execute([$subdomain]);
$siteData=$stmtSite->fetch();
if(!$siteData){
    $stmtFallback=$pdo->query("SELECT site_id, nom_entreprise, logo_concours, logo_extension, lien_unique FROM sites LIMIT 1");
    $siteData=$stmtFallback->fetch();
    if(!$siteData) die("Aucun site trouvé.");
    $subdomain=$siteData['lien_unique'];
}
$siteId=$siteData['site_id'];
$siteName=$siteData['nom_entreprise'];
$siteLien=$siteData['lien_unique'];
define('STOCKAGE_DOMAIN','https://gestion.zaloriatech.com');
$siteLogoUrl='';
if(!empty($siteData['logo_concours']) && !empty($siteData['logo_extension'])){
    $siteLogoUrl=STOCKAGE_DOMAIN.'/admin/uploads/sites_logo/'.$siteLien.'.'.$siteData['logo_extension'].'?v='.time();
}

// ===== Params =====
$candidate_id = isset($_GET['candidat']) ? intval($_GET['candidat']) : 0;
$concours_id_param = isset($_GET['concours_id']) ? intval($_GET['concours_id']) : 0;
$etape_id_param = isset($_GET['etape_id']) ? intval($_GET['etape_id']) : 0;
$receipt_ref = isset($_GET['receipt']) ? trim($_GET['receipt']) : '';

$candidate=null; $error='';
if($candidate_id>0){
    $stmt=$pdo->prepare("SELECT p.participante_id, p.code_participante, p.nom_complet, p.ville_origine, p.niveau_etudes, p.situation_actuelle, p.concours_id, m.photo_officielle FROM participantes p LEFT JOIN medias_participantes m ON m.participante_id=p.participante_id AND m.est_photo_principale=1 WHERE p.participante_id=:id AND p.situation_actuelle='active' ORDER BY m.ajoute_le DESC LIMIT 1");
    $stmt->execute(['id'=>$candidate_id]);
    $candidate=$stmt->fetch();
    if(!$candidate){ $error="Candidate introuvable ou non active."; }
    else if(empty($candidate['photo_officielle'])){
        $stmt2=$pdo->prepare("SELECT photo_officielle FROM medias_participantes WHERE participante_id=? ORDER BY ajoute_le DESC LIMIT 1");
        $stmt2->execute([$candidate_id]);
        if($row=$stmt2->fetch()) $candidate['photo_officielle']=$row['photo_officielle'];
    }
}else{ $error="Aucune candidate sélectionnée."; }

$concours_id=null; $etape_id=null; $offres=[]; $votes_candidate=0; $etapeInfo=null;
if($candidate && !$error){
    if($concours_id_param>0){
        if($concours_id_param!=$candidate['concours_id']) $error="Le concours ne correspond pas à la candidate.";
        else $concours_id=$concours_id_param;
    } else $concours_id=$candidate['concours_id'];

    if($concours_id && $etape_id_param>0){
        $stmtE=$pdo->prepare("SELECT etape_id, date_ouverture, date_cloture, etape_terminee, numero_ordre FROM etapes_du_concours WHERE etape_id=:eid AND concours_id=:cid");
        $stmtE->execute(['eid'=>$etape_id_param,'cid'=>$concours_id]);
        $etape=$stmtE->fetch();
        if(!$etape) $error="Étape introuvable.";
        elseif($etape['etape_terminee']==1) $error="Cette étape est terminée.";
        else{
            $now=time(); $d=strtotime($etape['date_ouverture']); $f=strtotime($etape['date_cloture']);
            if($now<$d) $error="Étape pas encore ouverte (début ".date('d/m/Y',$d).").";
            elseif($now>$f) $error="Étape terminée (clôture ".date('d/m/Y',$f).").";
            else{ $etape_id=$etape_id_param; $etapeInfo=$etape; }
        }
    }

    if(!$error){
        if($etape_id!==null){
            $stmtO=$pdo->prepare("SELECT o.offre_id, o.nombre_votes_inclus, o.prix, o.devise FROM offres_votes o JOIN etapes_offres eo ON o.offre_id=eo.offre_id WHERE o.concours_id=:cid AND o.offre_visible=1 AND eo.etape_id=:eid ORDER BY o.nombre_votes_inclus ASC");
            $stmtO->execute(['cid'=>$concours_id,'eid'=>$etape_id]);
        }else{
            $stmtO=$pdo->prepare("SELECT offre_id, nombre_votes_inclus, prix, devise FROM offres_votes WHERE concours_id=:cid AND offre_visible=1 ORDER BY nombre_votes_inclus ASC");
            $stmtO->execute(['cid'=>$concours_id]);
        }
        $offres=$stmtO->fetchAll();

        $sqlVotes="SELECT COALESCE(SUM(votes_accordes),0) FROM transactions_votes WHERE participante_id=:pid AND concours_id=:cid AND etat_paiement='confirme'";
        $pVotes=['pid'=>$candidate_id,'cid'=>$concours_id];
        if($etape_id!==null){ $sqlVotes.=" AND etape_id=:eid"; $pVotes['eid']=$etape_id; }
        $stmtV=$pdo->prepare($sqlVotes);
        $stmtV->execute($pVotes);
        $votes_candidate=$stmtV->fetchColumn()?:0;
    }
}

function getClassement($pdo,$concours_id,$etape_id=null,$period='global'){
    $dateCond='';
    switch($period){
        case 'jour': $dateCond="AND DATE(t.confirme_le)=CURDATE()"; break;
        case 'semaine': $dateCond="AND YEARWEEK(t.confirme_le,1)=YEARWEEK(CURDATE(),1)"; break;
        case 'mois': $dateCond="AND MONTH(t.confirme_le)=MONTH(CURDATE()) AND YEAR(t.confirme_le)=YEAR(CURDATE())"; break;
    }
    $params=['cid'=>$concours_id,'cid2'=>$concours_id];
    $joinEtape='';
    $etapeCond='';
    if($etape_id!==null){
        $etapeCond="AND t.etape_id=:eid";
        $joinEtape="INNER JOIN parcours_participantes pe ON p.participante_id=pe.participante_id AND pe.etape_id=:eid";
        $params['eid']=$etape_id;
    }
    $sql="SELECT p.participante_id, p.nom_complet, p.code_participante, p.ville_origine, COALESCE(SUM(t.votes_accordes),0) as total_votes, m.photo_officielle FROM participantes p $joinEtape LEFT JOIN transactions_votes t ON t.participante_id=p.participante_id AND t.etat_paiement='confirme' AND t.concours_id=:cid $dateCond $etapeCond LEFT JOIN medias_participantes m ON m.participante_id=p.participante_id AND m.est_photo_principale=1 WHERE p.situation_actuelle='active' AND p.concours_id=:cid2 GROUP BY p.participante_id ORDER BY total_votes DESC, p.nom_complet ASC LIMIT 20";
    $stmt=$pdo->prepare($sql);
    $stmt->execute($params);
    $rows=$stmt->fetchAll();
    foreach($rows as &$r){
        if(empty($r['photo_officielle'])){
            $s2=$pdo->prepare("SELECT photo_officielle FROM medias_participantes WHERE participante_id=? ORDER BY ajoute_le DESC LIMIT 1");
            $s2->execute([$r['participante_id']]);
            if($rw=$s2->fetch()) $r['photo_officielle']=$rw['photo_officielle'];
        }
    }
    unset($r);
    return $rows;
}
$classements=[];
if($candidate && $concours_id){
    $classements=[
        'jour'=>getClassement($pdo,$concours_id,$etape_id,'jour'),
        'semaine'=>getClassement($pdo,$concours_id,$etape_id,'semaine'),
        'mois'=>getClassement($pdo,$concours_id,$etape_id,'mois'),
        'global'=>getClassement($pdo,$concours_id,$etape_id,'global'),
    ];
}
function getPhotoUrl($photo_officielle){
    if(empty($photo_officielle)) return 'https://images.unsplash.com/photo-1529626455594-4ff0802cfb7e?w=600&h=750&fit=crop';
    $p=ltrim($photo_officielle,'/');
    if(strpos($p,'admin/')===0) $p=substr($p,6);
    $p=ltrim($p,'/');
    if(strpos($p,'uploads/')!==0) $p='uploads/'.$p;
    return STOCKAGE_DOMAIN.'/admin/'.$p;
}
$jsOffres=json_encode(array_map(function($o){
    return ['id'=>$o['offre_id'],'nombre_votes'=>(int)$o['nombre_votes_inclus'],'prix'=>(float)$o['prix'],'devise'=>$o['devise']??'USD'];
},$offres));

$metaUrl=(isset($_SERVER['HTTPS'])?'https':'http').'://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
$metaTitle="Voter pour ".($candidate?$candidate['nom_complet']:'Candidate')." - ".htmlspecialchars($siteName);
if($candidate){
    $metaImage=getPhotoUrl($candidate['photo_officielle']);
    $metaDescClean="Votez pour ".$candidate['nom_complet']." (N°".$candidate['code_participante'].") - ".($candidate['ville_origine']??'Kinshasa')." - Miss Aurora RDC 2026";
}else{
    $metaImage=$siteLogoUrl?:STOCKAGE_DOMAIN.'/millenium.webp';
    $metaDescClean="Votez pour votre candidate préférée - Miss Aurora RDC";
}

// Si receipt présent, pré-charger transaction pour affichage direct
$receiptData = null;
if($receipt_ref){
    $stmtR=$pdo->prepare("SELECT * FROM transactions_votes WHERE numero_reference=? LIMIT 1");
    $stmtR->execute([$receipt_ref]);
    $receiptData=$stmtR->fetch();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($metaTitle) ?> - Carte + Mobile</title>
<meta name="description" content="<?= htmlspecialchars($metaDescClean) ?>">
<meta property="og:title" content="<?= htmlspecialchars($metaTitle) ?>">
<meta property="og:description" content="<?= htmlspecialchars($metaDescClean) ?>">
<meta property="og:image" content="<?= htmlspecialchars($metaImage) ?>">
<meta property="og:url" content="<?= htmlspecialchars($metaUrl) ?>">
<meta property="og:type" content="website">
<meta name="twitter:card" content="summary_large_image">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;0,700&family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<style>
:root{
  --bg:#050B16;--bg2:#0B1E42;--bg3:#071A3D;
  --gold:#D4AF37;--gold-lt:#F3D77A;--gold-dim:rgba(212,175,55,.12);--gold-bdr:rgba(212,175,55,.28);
  --white:#fff;--muted:rgba(255,255,255,.58);--muted2:rgba(255,255,255,.32);
  --green:#22c55e;--red:#ff6b6b;--radius:18px;--nav-h:60px;
  --font-serif:'Cormorant Garamond',serif;--font-sans:'Outfit',sans-serif;--font-ui:'Inter',sans-serif;
}
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:var(--font-sans);background:var(--bg);color:var(--white);-webkit-font-smoothing:antialiased;overflow-x:hidden}
a{color:inherit;text-decoration:none}
img{max-width:100%;display:block}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;font-weight:700;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;padding:13px 22px;border-radius:12px;border:none;cursor:pointer;transition:all .22s;min-height:48px}
.btn-gold{background:linear-gradient(135deg, var(--gold) 0%, var(--gold-lt) 100%);color:#050B16;box-shadow:0 10px 28px rgba(212,175,55,.32)}
.btn-gold:hover{transform:translateY(-1px);box-shadow:0 14px 32px rgba(212,175,55,.38)}
.btn-outline{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.12);color:#fff;backdrop-filter:blur(10px)}
.btn-outline:hover{background:rgba(255,255,255,.10);border-color:rgba(255,255,255,.18)}
.btn:disabled{opacity:.5;cursor:not-allowed;transform:none!important}
.btn--full{width:100%}
.aurora-header{position:fixed;top:0;left:0;right:0;z-index:1000;height:60px;background:#fff;border-bottom:1px solid #EBEBEB;display:flex;align-items:center;justify-content:space-between;padding:0 24px}
.aurora-header__logo{display:flex;align-items:center;gap:10px;font-weight:700;color:#111}
.aurora-header__logo img{width:34px;height:34px;object-fit:contain;border-radius:6px;border:1px solid #EBEBEB;padding:2px;background:#fff}
.aurora-header__nav{display:flex;gap:8px;align-items:center}
.aurora-header__nav a{font-family:var(--font-ui);font-size:.82rem;padding:7px 12px;border-radius:8px;color:#444;transition:background .15s}
.aurora-header__nav a:hover{background:#F5F5F5;color:#111}
.container{max-width:1120px;margin:0 auto;padding:calc(var(--nav-h) + 28px) 20px 80px}
h1{font-family:var(--font-serif);font-size:clamp(2rem,4vw,3rem);font-weight:300;text-align:center;margin-bottom:8px;letter-spacing:-.02em}
h1 em{font-style:italic;font-weight:700;color:var(--gold-lt)}
.subtitle{text-align:center;color:var(--muted);font-size:.92rem;margin-bottom:28px}
.card{background:linear-gradient(180deg, rgba(255,255,255,.04) 0%, rgba(255,255,255,.02) 100%);border:1px solid rgba(255,255,255,.08);border-radius:20px;padding:26px;margin-bottom:24px;backdrop-filter:blur(12px);box-shadow:0 12px 40px rgba(0,0,0,.22)}
.candidate-header{display:flex;gap:20px;align-items:center;margin-bottom:22px;padding-bottom:20px;border-bottom:1px solid rgba(255,255,255,.06)}
.candidate-header img{width:108px;height:108px;border-radius:18px;object-fit:cover;border:1px solid rgba(212,175,55,.22);box-shadow:0 8px 24px rgba(0,0,0,.24);flex-shrink:0;background:#0B1E42}
.candidate-header h2{font-family:var(--font-serif);font-size:1.6rem;font-weight:700;line-height:1.1}
.candidate-header .code{display:inline-flex;align-items:center;gap:6px;background:var(--gold);color:#050B16;padding:5px 12px;border-radius:100px;font-size:.68rem;font-weight:800;letter-spacing:.06em;margin-bottom:8px;box-shadow:0 4px 12px rgba(212,175,55,.28)}
.votes-count{background:rgba(212,175,55,.08);border:1px solid rgba(212,175,55,.14);border-radius:14px;padding:14px;text-align:center;margin-bottom:18px}
.votes-count .num{font-family:var(--font-serif);font-size:2.2rem;font-weight:700;color:var(--gold-lt);line-height:1}
.votes-count .label{font-size:.72rem;color:var(--muted);margin-top:4px}
.stepper{display:flex;align-items:center;justify-content:space-between;gap:0;margin:22px 0 26px;padding:14px 12px;border-radius:14px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06)}
.step{display:flex;flex-direction:column;align-items:center;gap:6px;flex:0 0 auto;min-width:64px;position:relative;opacity:.5;transition:opacity .22s, transform .22s}
.step.active{opacity:1;transform:translateY(-1px)}
.step.completed{opacity:1}
.step.completed .step-num{background:var(--green);border-color:var(--green);color:#fff;box-shadow:0 0 0 4px rgba(34,197,94,.12)}
.step.active .step-num{background:linear-gradient(135deg, var(--gold), var(--gold-lt));border-color:var(--gold);color:#050B16;box-shadow:0 0 0 4px rgba(212,175,55,.18), 0 8px 20px rgba(212,175,55,.28)}
.step-num{width:36px;height:36px;border-radius:50%;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.06);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.82rem;transition:all .22s}
.step-label{font-family:var(--font-ui);font-size:.62rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;white-space:nowrap}
.step-line{flex:1;height:2px;background:rgba(255,255,255,.08);border-radius:2px;position:relative;overflow:hidden;transition:background .22s}
.step-line.is-completed{background:var(--green)}
.step-line.is-active{background:linear-gradient(90deg, var(--green) 0%, var(--gold) 100%)}
@media(max-width:640px){
  .stepper{padding:10px 8px;gap:0;overflow-x:auto;scrollbar-width:none}
  .stepper::-webkit-scrollbar{display:none}
  .step{min-width:52px}
  .step-num{width:32px;height:32px;font-size:.76rem}
  .step-label{font-size:.54rem}
}
.packs-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-bottom:18px}
.pack-option{position:relative;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.07);border-radius:14px;padding:16px 12px;cursor:pointer;transition:all .22s;text-align:center;overflow:hidden}
.pack-option::before{content:'';position:absolute;inset:0;background:radial-gradient(300px 160px at 30% 0%, rgba(212,175,55,.10), transparent 70%);opacity:0;transition:opacity .22s;pointer-events:none}
.pack-option:hover{border-color:var(--gold-bdr);transform:translateY(-1px);box-shadow:0 8px 20px rgba(0,0,0,.18)}
.pack-option.selected{border-color:var(--gold);background:linear-gradient(180deg, rgba(212,175,55,.14), rgba(255,255,255,.02));box-shadow:0 0 0 1px var(--gold), 0 12px 28px rgba(212,175,55,.22)}
.pack-option.selected::before{opacity:1}
.pack-option.selected::after{content:'✓';position:absolute;top:8px;right:10px;color:var(--gold-lt);font-weight:800;font-size:.82rem}
.pack-option input{display:none}
.pack-option .votes{font-family:var(--font-serif);font-size:1.6rem;font-weight:700;color:var(--gold-lt);line-height:1}
.pack-option .price{font-family:var(--font-ui);font-size:.78rem;color:var(--muted);margin-top:6px;font-weight:600}
.pack-option .price small{font-weight:400;color:var(--muted2)}
.form-group{margin-bottom:16px}
.form-group label{display:block;font-family:var(--font-ui);font-size:.60rem;font-weight:700;text-transform:uppercase;letter-spacing:.10em;color:var(--muted);margin-bottom:7px}
.form-group input, .form-group select{width:100%;padding:13px 14px;border-radius:10px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.09);color:#fff;font-family:var(--font-sans);font-size:.92rem;outline:none;transition:.18s}
.form-group input:focus, .form-group select:focus{border-color:var(--gold);background:rgba(212,175,55,.06);box-shadow:0 0 0 3px rgba(212,175,55,.12)}
.form-group input.valid{border-color:rgba(34,197,94,.5);box-shadow:0 0 0 3px rgba(34,197,94,.12)}
.form-group input.invalid{border-color:rgba(239,68,68,.5);box-shadow:0 0 0 3px rgba(239,68,68,.12)}
.form-group select option{background:#0B1E42;color:#fff}
.phone-wrap{position:relative}
.operator-badge{display:none;align-items:center;gap:10px;margin-top:10px;padding:11px 13px;border-radius:12px;font-size:.82rem;font-weight:600;animation:fadeUp .24s ease}
.operator-badge.show{display:flex}
.operator-badge .op-dot{width:34px;height:34px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.70rem;color:#fff;flex-shrink:0;box-shadow:0 4px 12px rgba(0,0,0,.18)}
.operator-badge small{display:block;font-weight:400;color:var(--muted);font-size:.68rem;margin-top:2px}
.op-mpesa{background:rgba(226,6,19,.10);border:1px solid rgba(226,6,19,.28)}
.op-mpesa .op-dot{background:#e20613}
.op-airtel{background:rgba(237,28,36,.10);border:1px solid rgba(237,28,36,.28)}
.op-airtel .op-dot{background:#ed1c24}
.op-orange{background:rgba(255,121,0,.10);border:1px solid rgba(255,121,0,.28)}
.op-orange .op-dot{background:#ff7900}
.op-africell{background:rgba(126,52,161,.12);border:1px solid rgba(126,52,161,.32)}
.op-africell .op-dot{background:#7e34a1}
.op-check{margin-left:auto;color:var(--green);font-weight:800}
@keyframes fadeUp{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:none}}
.alert{padding:12px 14px;border-radius:10px;margin-bottom:16px;font-weight:600;font-size:.84rem}
.alert-error{background:rgba(239,68,68,.10);border:1px solid rgba(239,68,68,.24);color:#fca5a5}
.alert-success{background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.24);color:#86efac}
.summary{background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.06);border-radius:14px;padding:14px 16px;margin:18px 0}
.summary-row{display:flex;justify-content:space-between;gap:12px;padding:7px 0;border-bottom:1px solid rgba(255,255,255,.06);font-family:var(--font-ui);font-size:.82rem;color:var(--muted)}
.summary-row span:last-child{color:#fff;font-weight:600;text-align:right}
.summary-row:last-child{border:none}
.total{color:var(--gold-lt)!important;font-weight:700!important}
.error-msg{color:#fca5a5;font-size:.76rem;margin-top:6px;display:none;font-family:var(--font-ui)}
.error-msg.show{display:block}
.loading-state{display:none;text-align:center;padding:32px 16px}
.loading-state.show{display:block}
.spinner{width:42px;height:42px;border:3px solid rgba(212,175,55,.14);border-top-color:var(--gold);border-radius:50%;animation:spin .8s linear infinite;margin:0 auto 14px}
@keyframes spin{to{transform:rotate(360deg)}}
.loading-msg{color:#fff;font-weight:600;font-size:.92rem}
.loading-sub{color:var(--muted);font-size:.78rem;margin-top:8px;font-family:var(--font-ui)}
.receipt-container{display:none;background:linear-gradient(180deg, #0F1F3A 0%, #071A3D 100%);border:1px solid rgba(212,175,55,.22);border-radius:22px;padding:0;max-width:560px;margin:28px auto;box-shadow:0 24px 64px rgba(0,0,0,.32);overflow:hidden}
.receipt-container.show{display:block;animation:fadeUp .32s ease}
.receipt-header{background:linear-gradient(135deg, #050B16 0%, #0B1E42 100%);padding:22px 24px 16px;border-bottom:1px solid rgba(212,175,55,.18);text-align:center}
.receipt-logo-wrap{display:flex;align-items:center;gap:14px;justify-content:center;margin-bottom:14px}
.receipt-logo{width:48px;height:48px;object-fit:contain;border-radius:10px;background:#fff;padding:4px;border:1px solid rgba(212,175,55,.25);box-shadow:0 4px 16px rgba(0,0,0,.2)}
.receipt-logo-fallback{width:48px;height:48px;border-radius:10px;background:linear-gradient(135deg, #D4AF37, #F3D77A);color:#050B16;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1rem}
.receipt-header-text{text-align:left}
.receipt-title{font-family:var(--font-serif);font-weight:700;font-size:1.1rem;color:var(--gold-lt);letter-spacing:.04em}
.receipt-subtitle{font-family:var(--font-ui);font-size:.72rem;color:var(--muted);margin-top:2px}
.receipt-badge{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:100px;background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.22);color:#86efac;font-size:.68rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase}
.receipt-dot{width:6px;height:6px;border-radius:50%;background:var(--green);display:inline-block;box-shadow:0 0 0 4px rgba(34,197,94,.15)}
.receipt-candidate{display:flex;gap:16px;align-items:center;padding:18px 22px;background:rgba(255,255,255,.03);border-bottom:1px solid rgba(255,255,255,.06)}
.receipt-candidate-photo{width:64px;height:64px;border-radius:14px;object-fit:cover;border:1px solid rgba(212,175,55,.22);flex-shrink:0;background:#0B1E42}
.receipt-candidate-info{flex:1;min-width:0;text-align:left}
.receipt-candidate-name{font-family:var(--font-serif);font-weight:700;font-size:1.15rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.receipt-candidate-code{font-family:var(--font-ui);font-size:.76rem;color:var(--muted);margin-top:2px}
.receipt-candidate-amount{font-family:var(--font-ui);font-weight:700;color:var(--gold-lt);font-size:.88rem;margin-top:4px}
.receipt-ref-qr{font-family:var(--font-ui);font-size:.68rem;color:var(--muted);background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06);padding:6px 12px;border-radius:100px;max-width:90%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.receipt-details{padding:16px 22px;background:rgba(255,255,255,.02)}
.receipt-row{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.05);font-family:var(--font-ui);font-size:.80rem;color:var(--muted)}
.receipt-row span:last-child{color:#fff;font-weight:600;text-align:right;word-break:break-all;max-width:60%}
.receipt-row:last-child{border:none}
.receipt-status-confirmed{color:#86efac!important;font-weight:800!important}
.receipt-status-failed{color:#fca5a5!important;font-weight:800!important}
.receipt-footer{padding:14px 22px;background:rgba(0,0,0,.15);border-top:1px solid rgba(255,255,255,.06);text-align:center}
.receipt-actions{padding:18px 22px 22px;display:flex;flex-direction:column;gap:10px;background:rgba(255,255,255,.01)}
.qr-wrap{display:flex;justify-content:center;margin:16px 0}
.classement-tabs{display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap}
.tab-btn{padding:8px 16px;border-radius:100px;font-family:var(--font-ui);font-size:.70rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);color:var(--muted);cursor:pointer;transition:.18s}
.tab-btn.active{background:var(--gold);color:#050B16;border-color:var(--gold);box-shadow:0 6px 16px rgba(212,175,55,.22)}
.class-row{display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid rgba(255,255,255,.06)}
.class-rank{font-weight:700;width:28px;text-align:center;color:var(--muted);font-size:.84rem}
.class-thumb{width:40px;height:40px;border-radius:10px;object-fit:cover;flex-shrink:0;background:#0B1E42;border:1px solid rgba(255,255,255,.06)}
.class-info{flex:1;min-width:0}
.class-info strong{display:block;font-size:.86rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.class-info small{font-size:.70rem;color:var(--muted);font-family:var(--font-ui)}
.class-votes{font-weight:700;color:var(--gold-lt);white-space:nowrap;font-size:.84rem}
@media(max-width:768px){
  .container{padding:calc(var(--nav-h) + 16px) 12px 80px}
  .card{padding:18px 14px;border-radius:16px}
  .candidate-header{flex-direction:column;text-align:center;gap:14px;padding-bottom:16px}
  .candidate-header h2{font-size:1.35rem}
  .packs-grid{grid-template-columns:repeat(2,1fr);gap:10px}
  .pack-option .votes{font-size:1.25rem}
  .receipt-container{padding:20px 16px;margin:20px auto}
}
@media print{
  body *{visibility:hidden}
  #receiptBlock, #receiptBlock *{visibility:visible}
  #receiptBlock{position:absolute;left:0;top:0;width:100%;padding:20px;background:#fff!important;color:#000!important;box-shadow:none;border:none}
  #receiptBlock .btn{ display:none!important }
  #receiptBlock .receipt-details{border-color:#ddd;background:#f9f9f9!important}
  #receiptBlock .receipt-row{color:#333!important;border-color:#eee!important}
  #receiptBlock .receipt-row span:last-child{color:#000!important}
}
.aurora-nav__link{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border-radius:8px;font-family:var(--font-ui);font-size:.78rem;color:#444;transition:background .15s}
.aurora-nav__link:hover{background:#F5F5F5;color:#111}
.aurora-burger{display:none}
.aurora-drawer.is-open{right:0!important}
.aurora-overlay.is-open{opacity:1!important;pointer-events:all!important}
.aurora-bottom-nav{display:none}
@media(max-width:860px){
  .aurora-nav{display:none!important}
  .aurora-burger{display:flex!important}
}
@media(max-width:768px){
  .aurora-bottom-nav{display:block!important}
  body{padding-bottom:68px}
  .container{padding-bottom:100px}
}
@media(max-width:768px){
  .aurora-bottom-nav{display:block!important}
  body{padding-bottom:72px}
}
@media(max-width:640px){
  .stepper{padding:10px 8px;gap:0}
  .step{min-width:48px}
  .step-num{width:30px;height:30px;font-size:.74rem}
  .step-label{font-size:.52rem}
  .class-row{padding:9px 0;gap:10px}
  .class-rank{width:26px;font-size:.78rem}
  .class-thumb{width:36px;height:36px;border-radius:9px}
  .class-info strong{font-size:.80rem}
  .class-info small{font-size:.64rem}
  .class-votes{font-size:.76rem}
}
@media(max-width:380px){
  .container{padding-left:10px;padding-right:10px}
  .packs-grid{grid-template-columns:1fr 1fr;gap:8px}
  .pack-option{padding:12px 8px}
  .pack-option .votes{font-size:1.1rem}
  .class-row{gap:8px}
  .class-thumb{width:32px;height:32px}
  .class-info strong{font-size:.76rem}
}
/* Maishapay method tabs */
.method-tabs{display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.method-btn{flex:1;min-width:140px;display:flex;align-items:center;justify-content:center;gap:8px;padding:12px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.10);background:rgba(255,255,255,.04);color:var(--muted);font-family:var(--font-ui);font-weight:600;font-size:.80rem;cursor:pointer;transition:all .2s}
.method-btn.active{border-color:var(--gold);background:linear-gradient(180deg, rgba(212,175,55,.16), rgba(255,255,255,.02));color:#fff;box-shadow:0 0 0 1px var(--gold), 0 8px 20px rgba(212,175,55,.18)}
.method-btn:hover{border-color:rgba(255,255,255,.18);transform:translateY(-1px)}
.method-section{display:none}
.method-section.active{display:block;animation:fadeUp .22s ease}
.card-type-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px}
.card-type-option{position:relative;display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:12px;border:1px solid rgba(255,255,255,.08);background:rgba(255,255,255,.03);cursor:pointer;transition:all .18s}
.card-type-option.selected{border-color:var(--gold);background:rgba(212,175,55,.10);box-shadow:0 0 0 1px var(--gold)}
.card-type-option input{display:none}
.card-type-option .card-logo{width:36px;height:24px;border-radius:4px;background:#fff;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:.62rem;color:#111}
.card-type-option .card-logo.visa{background:#1A1F71;color:#fff}
.card-type-option .card-logo.mc{background:#EB001B;color:#fff}
.maisha-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:100px;background:rgba(0,102,204,.12);border:1px solid rgba(0,102,204,.22);color:#7ab8ff;font-family:var(--font-ui);font-size:.64rem;font-weight:600}
.share-msg-mini{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:100px;background:rgba(212,175,55,.10);border:1px solid rgba(212,175,55,.18);font-family:var(--font-ui);font-size:.64rem;font-weight:600;color:var(--gold-lt);max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
</style>
</head>
<body>
<header class="aurora-header" id="auroraHeader">
  <div class="aurora-header__inner" style="max-width:1120px;margin:0 auto;padding:0 16px;height:60px;display:flex;align-items:center;justify-content:space-between;width:100%">
    <div class="aurora-header__left" style="display:flex;align-items:center;gap:16px">
      <a href="index.php" class="aurora-header__logo">
        <?php if($siteLogoUrl): ?><img src="<?= htmlspecialchars($siteLogoUrl) ?>" alt="logo"><?php else: ?><span style="width:32px;height:32px;border-radius:8px;background:#071A3D;color:#D4AF37;display:flex;align-items:center;justify-content:center;font-weight:700">A</span><?php endif; ?>
        <span style="font-weight:700;color:#111;font-family:var(--font-sans);font-size:.9rem"><?= htmlspecialchars($siteName ?: 'LME GROUP') ?></span>
      </a>
      <nav class="aurora-nav" style="display:flex;align-items:center;gap:2px">
        <a href="index.php" class="aurora-nav__link"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M3 9 12 2l9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9Z"/><path d="M9 22V12h6v10"/></svg> Accueil</a>
        <a href="index.php#candidates" class="aurora-nav__link"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg> Candidates</a>
        <a href="index.php#classement" class="aurora-nav__link"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg> Classement</a>
      </nav>
    </div>
    <div class="aurora-header__right" style="display:flex;align-items:center;gap:10px">
      <span class="maisha-badge" style="display:none" id="maishaBadge">MaishaPay Secure</span>
      <a href="index.php#vote" class="btn btn-gold" style="padding:8px 14px;font-size:.76rem;min-height:36px;border-radius:8px">Voter</a>
      <button class="aurora-burger" id="auroraBurger" aria-label="Menu" aria-expanded="false" style="width:36px;height:36px;border-radius:8px;border:1px solid #DDD;background:#fff;display:none;align-items:center;justify-content:center;cursor:pointer"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/></svg></button>
    </div>
  </div>
</header>
<div class="aurora-drawer" id="auroraDrawer" aria-hidden="true" style="position:fixed;top:0;right:-100%;width:min(320px,88vw);height:100dvh;background:#fff;border-left:1px solid #EBEBEB;padding:64px 16px 20px;z-index:999;transition:right .26s;overflow-y:auto;display:flex;flex-direction:column;gap:16px;box-shadow:-8px 0 32px rgba(0,0,0,.08)">
  <div style="display:flex;justify-content:space-between;align-items:center;padding-bottom:12px;border-bottom:1px solid #EBEBEB"><span style="font-weight:700;color:#111">Menu</span><button id="drawerClose" style="width:32px;height:32px;border-radius:50%;border:1px solid #DDD;background:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg></button></div>
  <nav style="display:flex;flex-direction:column;gap:4px">
    <a href="index.php" style="padding:11px;border-radius:8px;color:#222;font-size:.88rem;display:flex;align-items:center;gap:10px"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M3 9 12 2l9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9Z"/><path d="M9 22V12h6v10"/></svg> Accueil</a>
    <a href="index.php#candidates" style="padding:11px;border-radius:8px;color:#222;font-size:.88rem;display:flex;align-items:center;gap:10px"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg> Candidates</a>
    <a href="index.php#classement" style="padding:11px;border-radius:8px;color:#222;font-size:.88rem;display:flex;align-items:center;gap:10px"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg> Classement</a>
    <a href="index.php#vote" style="padding:11px;border-radius:8px;color:#222;font-size:.88rem;display:flex;align-items:center;gap:10px"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M19 14c1.5-1.6 3-3.2 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2.08C10.5 3.5 9.26 3 7.5 3A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 3.9 3 5.5l7 7Z"/></svg> Vote</a>
  </nav>
  <div style="margin-top:auto;padding-top:16px;border-top:1px solid #EBEBEB;display:flex;flex-direction:column;gap:10px">
    <a href="candidatures.php" style="width:100%;padding:12px;border-radius:8px;background:#071A3D;color:#fff;text-align:center;font-weight:600;font-size:.88rem">Devenir candidate</a>
  </div>
</div>
<div class="aurora-overlay" id="auroraOverlay" style="position:fixed;inset:0;background:rgba(0,0,0,.32);opacity:0;pointer-events:none;transition:opacity .2s;z-index:998"></div>
<nav class="aurora-bottom-nav" style="display:none;position:fixed;bottom:0;left:0;right:0;z-index:900;background:rgba(255,255,255,.98);backdrop-filter:blur(12px);border-top:1px solid #EBEBEB;padding:3px 0 calc(3px + env(safe-area-inset-bottom));box-shadow:0 -1px 10px rgba(0,0,0,.06)">
  <div style="max-width:420px;margin:0 auto;display:flex;justify-content:space-around;align-items:center">
    <a href="index.php" style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;padding:5px;color:#717171;font-size:.60rem"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M3 9 12 2l9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9Z"/><path d="M9 22V12h6v10"/></svg> Accueil</a>
    <a href="index.php#candidates" style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;padding:5px;color:#717171;font-size:.60rem"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg> Candid.</a>
    <a href="#" style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;padding:5px;color:#071A3D;font-weight:600;font-size:.60rem"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M19 14c1.5-1.6 3-3.2 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2.08C10.5 3.5 9.26 3 7.5 3A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 3.9 3 5.5l7 7Z"/></svg> Vote</a>
    <a href="index.php#classement" style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;padding:5px;color:#717171;font-size:.60rem"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg> Class.</a>
    <a href="#" id="bottomMenuBtn" style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;padding:5px;color:#717171;font-size:.60rem"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/></svg> Menu</a>
  </div>
</nav>

<div class="container">
<?php if($error): ?>
  <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php if(!$candidate): ?><div style="text-align:center;margin-top:16px"><a href="index.php#candidates" class="btn btn-outline">← Voir candidates</a></div><?php endif; ?>
<?php else: ?>
  <h1>Voter pour <em><?= htmlspecialchars($candidate['nom_complet']) ?></em></h1>
  <p class="subtitle">Soutenez votre candidate — Mobile Money via <b>Unipesa/Avadapay</b> ou Carte Visa / Mastercard via <b>MaishaPay Secure</b></p>

  <?php if($receiptData): ?>
    <?php if($receiptData['etat_paiement']==='confirme'): ?>
      <div class="alert alert-success" style="text-align:center">✅ Paiement confirmé pour référence <b><?= htmlspecialchars($receiptData['numero_reference']) ?></b> — <?= (int)$receiptData['votes_accordes'] ?> votes ajoutés !</div>
    <?php elseif($receiptData['etat_paiement']==='echoue'): ?>
      <div class="alert alert-error" style="text-align:center">❌ Paiement échoué pour référence <b><?= htmlspecialchars($receiptData['numero_reference']) ?></b> — <?= htmlspecialchars($receiptData['message_retour'] ?? 'Vérifiez vos informations') ?>. Aucun vote compté. Vous pouvez réessayer.</div>
    <?php else: ?>
      <div class="alert" style="text-align:center;background:rgba(234,179,8,.12);border:1px solid rgba(234,179,8,.22);color:#fde68a">⏳ Paiement en attente pour référence <b><?= htmlspecialchars($receiptData['numero_reference']) ?></b> — <?= htmlspecialchars($receiptData['message_retour'] ?? 'Vérification en cours') ?>.</div>
    <?php endif; ?>
  <?php endif; ?>

  <!-- STEPPER -->
  <div class="stepper" id="stepper">
    <div class="step active" data-step="1"><div class="step-num">1</div><div class="step-label">Pack</div></div>
    <div class="step-line" id="line1"></div>
    <div class="step" data-step="2"><div class="step-num">2</div><div class="step-label">Méthode</div></div>
    <div class="step-line" id="line2"></div>
    <div class="step" data-step="3"><div class="step-num">3</div><div class="step-label">Paiement</div></div>
    <div class="step-line" id="line3"></div>
    <div class="step" data-step="4"><div class="step-num">4</div><div class="step-label">Reçu</div></div>
  </div>

  <div class="card" id="formCard">
    <div class="candidate-header">
      <img src="<?= getPhotoUrl($candidate['photo_officielle']) ?>?v=<?= time() ?>" alt="<?= htmlspecialchars($candidate['nom_complet']) ?>">
      <div style="flex:1;min-width:0">
        <span class="code">N° <?= htmlspecialchars($candidate['code_participante']) ?></span>
        <h2><?= htmlspecialchars($candidate['nom_complet']) ?></h2>
        <p style="color:var(--muted);font-family:var(--font-ui);font-size:.84rem;margin-top:4px"><?= htmlspecialchars($candidate['ville_origine']??'Kinshasa') ?> • <?= htmlspecialchars($candidate['niveau_etudes']??'') ?></p>
        <?php if($etapeInfo): ?><div style="margin-top:8px;display:inline-flex;padding:5px 10px;border-radius:100px;background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.20);color:#86efac;font-family:var(--font-ui);font-size:.66rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase">Étape: <?= htmlspecialchars($etapeInfo['numero_ordre']??$etape_id) ?> • En cours</div><?php endif; ?>
        <div style="margin-top:8px" class="maisha-badge">🔒 Paiement sécurisé • 3D Secure • Visa / Mastercard / Mobile Money</div>
      </div>
    </div>

    <div class="votes-count">
      <div class="num" id="votes-actuels"><?= (int)$votes_candidate ?></div>
      <div class="label">votes confirmés<?= $etape_id?' pour cette étape':'' ?></div>
    </div>

    <input type="hidden" id="concours_id" value="<?= $concours_id ?>">
    <input type="hidden" id="candidate_id" value="<?= $candidate_id ?>">
    <input type="hidden" id="etape_id" value="<?= $etape_id ?>">

    <h3 style="font-family:var(--font-serif);font-size:1.15rem;font-weight:700;margin-bottom:12px">1️⃣ Choisissez un pack</h3>
    <div class="packs-grid" id="packsGrid">
      <?php foreach($offres as $offre): ?>
        <label class="pack-option" data-id="<?= $offre['offre_id'] ?>">
          <input type="radio" name="offre_id" value="<?= $offre['offre_id'] ?>">
          <div class="votes"><?= $offre['nombre_votes_inclus'] ?> votes</div>
          <div class="price"><?= $offre['prix'] ?> <small><?= htmlspecialchars($offre['devise']??'USD') ?></small></div>
        </label>
      <?php endforeach; ?>
      <?php if(empty($offres)): ?><p style="color:var(--muted);font-size:.84rem">Aucun pack disponible pour le moment.</p><?php endif; ?>
    </div>
    <p class="error-msg" id="err-offre">Veuillez choisir un pack.</p>

    <h3 style="font-family:var(--font-serif);font-size:1.15rem;font-weight:700;margin:22px 0 12px">2️⃣ Méthode de paiement</h3>
    <div class="method-tabs" id="methodTabs">
      <button class="method-btn active" data-method="mobile"><span>📱</span> Mobile Money<br><small style="font-weight:400;font-size:.62rem">Airtel / Orange / M-Pesa / Africell</small></button>
      <button class="method-btn" data-method="card"><span>💳</span> Carte Bancaire<br><small style="font-weight:400;font-size:.62rem">Visa / Mastercard • 3D Secure</small></button>
    </div>

    <!-- Mobile Section -->
    <div class="method-section active" id="mobileSection">
      <div class="form-group">
        <label for="telephone">Numéro Mobile Money (détection auto)</label>
        <div class="phone-wrap">
          <input type="tel" id="telephone" inputmode="numeric" autocomplete="tel" placeholder="Ex: 0812345678" maxlength="17">
        </div>
        <p style="font-family:var(--font-ui);font-size:.70rem;color:var(--muted);margin-top:6px">Formats: 0812345678, 812345678, 243812345678 — détection Orange / Airtel / M-Pesa / Africell via <b>Unipesa/Avadapay</b> (comme voter.php).</p>
        <div class="operator-badge" id="operatorBadge">
          <div class="op-dot" id="opDot">—</div>
          <div><div id="opName">Opérateur</div><small id="opNumber"></small></div>
          <span class="op-check">✓</span>
        </div>
        <p class="error-msg" id="err-telephone">Numéro invalide ou opérateur non reconnu.</p>
      </div>
      <div class="form-group">
        <label for="message_user">Message de soutien (optionnel)</label>
        <input type="text" id="message_user" maxlength="255" placeholder="Ex: Je te soutiens !">
      </div>
      <div class="form-group">
        <label for="email_mobile">Email (optionnel pour reçu)</label>
        <input type="email" id="email_mobile" placeholder="vous@exemple.com">
      </div>
    </div>

    <!-- Card Section -->
    <div class="method-section" id="cardSection">
      <div style="background:rgba(0,102,204,.08);border:1px solid rgba(0,102,204,.18);border-radius:12px;padding:12px 14px;margin-bottom:14px;display:flex;gap:10px;align-items:center">
        <div style="width:36px;height:36px;border-radius:8px;background:#fff;display:flex;align-items:center;justify-content:center;font-size:18px">💳</div>
        <div style="flex:1">
          <div style="font-family:var(--font-ui);font-size:.78rem;font-weight:700;color:#7ab8ff">Paiement sécurisé par carte bancaire</div>
          <div style="font-family:var(--font-ui);font-size:.68rem;color:var(--muted);margin-top:2px">Vous serez redirigé vers la page sécurisée pour saisir votre carte. 3D Secure, Visa, Mastercard supportés. Paiement chiffré.</div>
        </div>
      </div>
      <div class="card-type-grid" id="cardTypeGrid">
        <label class="card-type-option selected" data-type="VISA">
          <input type="radio" name="card_type" value="VISA" checked>
          <div class="card-logo visa">VISA</div>
          <div><div style="font-weight:700;font-size:.82rem">Visa</div><small style="color:var(--muted);font-size:.66rem">Débit / Crédit</small></div>
          <span style="margin-left:auto;color:var(--gold)">✓</span>
        </label>
        <label class="card-type-option" data-type="MASTERCARD">
          <input type="radio" name="card_type" value="MASTERCARD">
          <div class="card-logo mc">MC</div>
          <div><div style="font-weight:700;font-size:.82rem">Mastercard</div><small style="color:var(--muted);font-size:.66rem">Débit / Crédit</small></div>
          <span style="margin-left:auto;color:var(--gold);display:none">✓</span>
        </label>
      </div>
      <div class="form-group">
        <label for="customer_name">Nom complet (pour reçu)</label>
        <input type="text" id="customer_name" placeholder="Ex: John Doe">
      </div>
      <div class="form-group">
        <label for="email_card">Email (obligatoire pour carte)</label>
        <input type="email" id="email_card" placeholder="vous@exemple.com">
        <p class="error-msg" id="err-email-card">Email requis pour paiement carte.</p>
      </div>
      <div class="form-group">
        <label for="phone_card">Téléphone (optionnel)</label>
        <input type="tel" id="phone_card" placeholder="+243 81...">
      </div>
    </div>

    <h3 style="font-family:var(--font-serif);font-size:1.15rem;font-weight:700;margin:22px 0 12px">3️⃣ Récapitulatif</h3>
    <div class="summary">
      <div class="summary-row"><span>Candidate</span><span><?= htmlspecialchars($candidate['nom_complet']) ?></span></div>
      <div class="summary-row"><span>Pack</span><span id="summary-votes">—</span></div>
      <div class="summary-row"><span>Méthode</span><span id="summary-method">Mobile Money</span></div>
      <div class="summary-row"><span>Détail</span><span id="summary-operator">—</span></div>
      <div class="summary-row"><span>Contact</span><span id="summary-phone">—</span></div>
      <div class="summary-row"><span>Montant</span><span class="total" id="summary-total">—</span></div>
    </div>

    <div id="globalAlert" style="display:none;margin-bottom:16px;padding:14px 16px;border-radius:12px;font-weight:600;font-size:.86rem;line-height:1.5;animation:fadeUp .24s ease"></div>
    <div class="error-msg" id="err-global" style="margin-bottom:12px"></div>

    <button type="button" id="payBtn" class="btn btn-gold btn--full">💳 Payer maintenant — Mobile Money</button>
    <p style="text-align:center;font-family:var(--font-ui);font-size:.70rem;color:var(--muted2);margin-top:10px" id="payHint">Paiement sécurisé • Mobile Money • Carte bancaire 3D Secure</p>

    <!-- Ancien form Checkout direct (exposait secret) supprimé pour sécurité. Maintenant redirection via vote_checkout.php serveur -->
    <form id="maishaCheckoutForm" style="display:none"></form>
  </div>

  <div id="loadingBlock" class="loading-state">
    <div class="spinner"></div>
    <div class="loading-msg" id="loadingMsg">Connexion…</div>
    <div class="loading-sub" id="loadingSub"></div>
    <p style="margin-top:16px;font-family:var(--font-ui);font-size:.72rem;color:var(--muted2)">Ne fermez pas cette page — traitement en cours…</p>
    <div style="margin-top:20px;display:flex;flex-direction:column;gap:10px;max-width:320px;margin-left:auto;margin-right:auto">
      <button class="btn btn-outline" id="cancelBtn" style="display:none">❌ Annuler / Marquer comme échoué pour réessayer</button>
      <p style="font-size:.68rem;color:var(--muted2);text-align:center">Si vous êtes bloqué sur CyberSource avec <b>Your order was declined</b>, cliquez Annuler puis réessayez avec une autre carte. Le système débloquera automatiquement après 15min.</p>
    </div>
  </div>

  <div id="receiptBlock" class="receipt-container">
    <div class="receipt-header">
      <div class="receipt-logo-wrap">
        <?php if($siteLogoUrl): ?>
          <img src="<?= htmlspecialchars($siteLogoUrl) ?>" alt="Logo" class="receipt-logo" onerror="this.style.display='none'">
        <?php else: ?>
          <div class="receipt-logo-fallback">LME</div>
        <?php endif; ?>
        <div class="receipt-header-text">
          <div class="receipt-title">MISS AURORA RDC</div>
          <div class="receipt-subtitle">LME GROUP • Reçu Officiel de Vote</div>
        </div>
      </div>
      <?php
        $badgeStyle = 'background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.22);color:#86efac';
        $badgeDot = '#22c55e';
        $badgeText = 'Paiement confirmé • Preuve de vote';
        if($receiptData){
          if($receiptData['etat_paiement']==='echoue'){
            $badgeStyle='background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.22);color:#fca5a5';
            $badgeDot='#ef4444';
            $badgeText='Paiement échoué • Aucun vote compté';
          } elseif($receiptData['etat_paiement']!=='confirme'){
            $badgeStyle='background:rgba(234,179,8,.12);border:1px solid rgba(234,179,8,.22);color:#fde68a';
            $badgeDot='#eab308';
            $badgeText='Paiement en attente • Vérification en cours';
          }
        }
      ?>
      <div id="rc-badge" class="receipt-badge" style="<?= $badgeStyle ?>"><span class="receipt-dot" style="background:<?= $badgeDot ?>"></span> <span id="rc-badge-text"><?= $badgeText ?></span></div>
    </div>

    <div class="receipt-candidate">
      <img id="rc-photo" src="<?= getPhotoUrl($candidate['photo_officielle']) ?>?v=<?= time() ?>" alt="Candidate" class="receipt-candidate-photo">
      <div class="receipt-candidate-info">
        <div class="receipt-candidate-name" id="rc-cand">—</div>
        <div class="receipt-candidate-code">N° <span id="rc-code">—</span> • <span id="rc-votes">—</span> votes</div>
        <div class="receipt-candidate-amount" id="rc-amount">—</div>
      </div>
    </div>

    <div class="qr-wrap" style="display:flex;flex-direction:column;align-items:center;gap:10px;margin:20px 0">
      <div id="qrcode" style="position:relative;width:148px;height:148px;background:#fff;border-radius:16px;padding:10px;box-shadow:0 12px 32px rgba(0,0,0,.22)"></div>
      <div class="receipt-ref-qr">Réf: <span id="qrRef" style="font-weight:800;color:#F3D77A"></span></div>
      <div style="font-family:var(--font-ui);font-size:.62rem;color:var(--muted);text-align:center">Scannez pour vérifier</div>
    </div>

    <div class="receipt-details" id="receiptDetails">
      <div class="receipt-row"><span>Référence</span><span><strong id="rc-ref">—</strong></span></div>
      <div class="receipt-row"><span>Montant</span><span id="rc-amount2">—</span></div>
      <div class="receipt-row"><span>Méthode</span><span id="rc-operator">—</span></div>
      <div class="receipt-row"><span>Contact</span><span id="rc-phone">—</span></div>
      <div class="receipt-row"><span>Date</span><span id="rc-date">—</span></div>
      <div class="receipt-row"><span>Statut</span><span id="rc-statut" class="receipt-status-confirmed">—</span></div>
      <div class="receipt-row"><span>Concours</span><span id="rc-concours">—</span></div>
      <div class="receipt-row"><span>Étape</span><span id="rc-etape">—</span></div>
    </div>

    <div class="receipt-footer">
      <div style="font-size:.68rem;color:var(--muted);line-height:1.5">
        Conservez ce reçu comme preuve. Vérifiable avec la référence sur <b>lme-group.zaloriatech.com/verify</b><br>
        40, Av. Kasangulu, Kasa-Vubu, Kinshasa, RDC • +243 860 370 727
      </div>
    </div>

    <div class="receipt-actions">
      <button class="btn btn-gold" onclick="downloadReceiptPDF()">📥 Télécharger le reçu (PDF)</button>
      <button class="btn btn-outline" onclick="window.print()">🖨️ Imprimer</button>
      <a href="vote.php?candidat=<?= $candidate_id ?>&concours_id=<?= $concours_id ?>&etape_id=<?= $etape_id ?>" class="btn btn-outline">↩ Voter à nouveau</a>
      <a href="index.php#candidates" class="btn btn-outline">Voir classement</a>
    </div>
  </div>

  <div class="card">
    <h3 style="font-family:var(--font-serif);font-size:1.4rem;font-weight:700;margin-bottom:14px">Classement <?= $etape_id?'— cette étape':'' ?></h3>
    <div class="classement-tabs" id="classTabs">
      <button class="tab-btn active" data-periode="jour">Aujourd'hui</button>
      <button class="tab-btn" data-periode="semaine">Semaine</button>
      <button class="tab-btn" data-periode="mois">Mois</button>
      <button class="tab-btn" data-periode="global">Global</button>
    </div>
    <div id="classContent">
      <?php foreach($classements as $periode=>$liste): ?>
        <div class="class-period" data-periode="<?= $periode ?>" style="display:<?= $periode==='jour'?'block':'none' ?>">
          <?php if(count($liste)==0): ?><p style="color:var(--muted);font-size:.86rem">Aucun vote pour cette période.</p><?php else: ?><?php $rank=1; foreach($liste as $c): $photo=getPhotoUrl($c['photo_officielle']??''); $name=htmlspecialchars($c['nom_complet']); $code=htmlspecialchars($c['code_participante']); $ville=htmlspecialchars($c['ville_origine']??''); $votes=(int)$c['total_votes']; ?>
            <div class="class-row"><span class="class-rank"><?= $rank<=3?['🥇','🥈','🥉'][$rank-1]:$rank ?></span><img src="<?= $photo ?>" alt="<?= $name ?>" class="class-thumb"><div class="class-info"><strong><?= $name ?></strong><small>N°<?= $code ?><?= $ville?' · '.$ville:'' ?></small></div><span class="class-votes"><?= $votes ?> votes</span></div>
          <?php $rank++; endforeach; ?><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>
</div>

<?php if($candidate): ?>
<script>
const OFFRES=<?= $jsOffres ?>;
const CANDIDATE_ID=<?= (int)$candidate_id ?>;
const CONCOURS_ID=<?= (int)$concours_id ?>;
const ETAPE_ID=<?= json_encode($etape_id) ?>;
const CANDIDATE_NAME=<?= json_encode($candidate['nom_complet']) ?>;
const CANDIDATE_CODE=<?= json_encode($candidate['code_participante']) ?>;
const CONCOURS_LABEL=<?= json_encode($siteName) ?>;
const RECEIPT_REF_FROM_URL=<?= json_encode($receipt_ref) ?>;
const RECEIPT_DATA=<?= json_encode($receiptData) ?>;

const OPERATORS={
  mpesa:{label:'M-Pesa',short:'MP',cls:'op-mpesa',prefixes:['81','82','83'], provider:'MPESA'},
  airtel:{label:'Airtel Money',short:'AM',cls:'op-airtel',prefixes:['97','98','99'], provider:'AIRTEL'},
  orange:{label:'Orange Money',short:'OM',cls:'op-orange',prefixes:['80','84','85','89'], provider:'ORANGE'},
  africell:{label:'Africell Money',short:'AF',cls:'op-africell',prefixes:['90','91'], provider:'AFRICELL'},
};
function detectOperator(raw){
  let d=(raw||'').replace(/\D/g,'');
  if(d.length===12 && d.startsWith('243')) d=d.slice(3);
  else if(d.length===10 && d[0]==='0') d=d.slice(1);
  if(d.length!==9 || d[0]==='0') return null;
  const pref=d.slice(0,2);
  for(const [op,meta] of Object.entries(OPERATORS)){
    if(meta.prefixes.includes(pref)){
      return{op,label:meta.label,short:meta.short,cls:meta.cls,provider:meta.provider,national:'0'+d,e164:'243'+d};
    }
  }
  return null;
}
function prettyPhone(national){ return national.replace(/^(\d{4})(\d{3})(\d{3})$/,'$1 $2 $3'); }

const packOptions=document.querySelectorAll('.pack-option');
const summaryVotes=document.getElementById('summary-votes');
const summaryTotal=document.getElementById('summary-total');
const summaryMethod=document.getElementById('summary-method');
const summaryOperator=document.getElementById('summary-operator');
const summaryPhone=document.getElementById('summary-phone');
const payBtn=document.getElementById('payBtn');
const payHint=document.getElementById('payHint');
const loadingBlock=document.getElementById('loadingBlock');
const loadingMsg=document.getElementById('loadingMsg');
const loadingSub=document.getElementById('loadingSub');
const receiptBlock=document.getElementById('receiptBlock');
const formCard=document.getElementById('formCard');
const phoneInput=document.getElementById('telephone');
const operatorBadge=document.getElementById('operatorBadge');
const opDot=document.getElementById('opDot');
const opName=document.getElementById('opName');
const opNumber=document.getElementById('opNumber');

let selectedOffreId=null, selectedOffre=null, currentOp=null, pollInterval=null;
let currentMethod='mobile';
let selectedCardType='VISA';

function updateStepper(step){
  document.querySelectorAll('.step').forEach(s=>{
    const n=parseInt(s.dataset.step);
    s.classList.remove('active','completed');
    if(n<step) s.classList.add('completed');
    if(n===step) s.classList.add('active');
  });
  document.querySelectorAll('.step-line').forEach(l=>{
    const num=parseInt(l.id.replace('line',''));
    l.classList.remove('is-active','is-completed');
    if(num<step) l.classList.add('is-completed');
    if(num===step-1) l.classList.add('is-active');
  });
}

packOptions.forEach(opt=>{
  opt.addEventListener('click',function(){
    packOptions.forEach(o=>o.classList.remove('selected'));
    this.classList.add('selected');
    this.querySelector('input').checked=true;
    selectedOffreId=parseInt(this.dataset.id);
    selectedOffre=OFFRES.find(o=>o.id===selectedOffreId);
    updateSummary();
    hideError('err-offre');
    hideGlobalAlert();
    updateStepper(2);
  });
});

function updateSummary(){
  if(!selectedOffre){
    summaryVotes.textContent='—';
    summaryTotal.textContent='—';
  } else {
    summaryVotes.textContent=selectedOffre.nombre_votes+' votes';
    summaryTotal.textContent=selectedOffre.prix+' '+(selectedOffre.devise||'USD');
  }
  if(currentMethod==='mobile'){
    summaryMethod.textContent='Mobile Money';
    summaryOperator.textContent=currentOp?currentOp.label:'—';
    summaryPhone.textContent=currentOp?prettyPhone(currentOp.national):(document.getElementById('email_mobile').value||'—');
  } else {
    summaryMethod.textContent='Carte Bancaire';
    summaryOperator.textContent=selectedCardType==='VISA'?'Visa':'Mastercard';
    summaryPhone.textContent=document.getElementById('email_card').value||document.getElementById('customer_name').value||'—';
  }
}

// Method tabs - Mobile = Unipesa, Carte = Maishapay (sécurisé, sans exposer merchant)
document.querySelectorAll('.method-btn').forEach(btn=>{
  btn.addEventListener('click',()=>{
    document.querySelectorAll('.method-btn').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    currentMethod=btn.dataset.method;
    document.querySelectorAll('.method-section').forEach(s=>s.classList.remove('active'));
    document.getElementById(currentMethod==='mobile'?'mobileSection':'cardSection').classList.add('active');
    if(currentMethod==='mobile'){
      payBtn.textContent='💳 Payer maintenant — Mobile Money';
      payHint.textContent='Paiement sécurisé Mobile Money (Airtel/Orange/M-Pesa/Africell)';
    } else {
      payBtn.textContent='💳 Payer par carte — Visa / Mastercard';
      payHint.textContent='Redirection vers page sécurisée 3D Secure • Visa / Mastercard';
    }
    updateSummary();
    updateStepper(2);
  });
});

// Card type selector
document.querySelectorAll('.card-type-option').forEach(opt=>{
  opt.addEventListener('click',function(){
    document.querySelectorAll('.card-type-option').forEach(o=>{
      o.classList.remove('selected');
      const chk=o.querySelector('span:last-child');
      if(chk) chk.style.display='none';
    });
    this.classList.add('selected');
    const chk=this.querySelector('span:last-child');
    if(chk) chk.style.display='inline';
    this.querySelector('input').checked=true;
    selectedCardType=this.dataset.type;
    updateSummary();
  });
});

phoneInput?.addEventListener('input',()=>{
  const raw=phoneInput.value;
  const digits=raw.replace(/\D/g,'');
  currentOp=detectOperator(raw);
  if(currentOp){
    phoneInput.classList.add('valid'); phoneInput.classList.remove('invalid');
    operatorBadge.className='operator-badge show '+currentOp.cls;
    opDot.textContent=currentOp.short;
    opName.textContent=currentOp.label+' détecté ('+currentOp.provider+')';
    opNumber.textContent=prettyPhone(currentOp.national)+' (+'+currentOp.e164+')';
    hideError('err-telephone');
    hideGlobalAlert();
    updateStepper(3);
  } else {
    operatorBadge.className='operator-badge';
    phoneInput.classList.remove('valid');
    const longEnough = digits.length>=9;
    phoneInput.classList.toggle('invalid', longEnough);
    if(longEnough){
      showError('err-telephone');
    } else {
      hideError('err-telephone');
      // si globalAlert affichait l'erreur tel, on l'enlève dès que corrigé / effacé
      const ga=document.getElementById('globalAlert');
      if(ga && ga.textContent.includes('Numéro invalide')) hideGlobalAlert();
    }
    if(selectedOffre) updateStepper(2);
  }
  updateSummary();
});

document.getElementById('email_mobile')?.addEventListener('input', updateSummary);
document.getElementById('email_card')?.addEventListener('input', ()=>{
  const v=document.getElementById('email_card').value.trim();
  if(v.includes('@') && v.length>5){
    hideError('err-email-card');
    hideGlobalAlert();
  }
  updateSummary();
});
document.getElementById('customer_name')?.addEventListener('input', updateSummary);
document.getElementById('phone_card')?.addEventListener('input', updateSummary);

function showError(id,msg){ 
  const el=document.getElementById(id); 
  const text = msg || (el ? el.textContent : '');
  if(el){
    if(msg) el.textContent=msg;
    // Pour err-global on n'affiche QUE globalAlert pour éviter doublon (ton screenshot MP260822.0042.D01381)
    if(id!=='err-global') el.classList.add('show');
  }
  // Affiche globalAlert stylé visible mobile pour toutes les erreurs importantes
  if(id==='err-global' || id==='err-offre' || id==='err-telephone' || id==='err-email-card'){
    showGlobalAlert(text, 'error');
  }
}
function hideError(id){
  const el=document.getElementById(id);
  if(el) el.classList.remove('show');
  // si c'était une erreur qui affichait globalAlert, on l'enlève aussi directement quand corrigé
  if(id==='err-offre' || id==='err-telephone' || id==='err-email-card' || id==='err-global'){
    hideGlobalAlert();
  }
}
function showGlobalAlert(message, type='error'){
  const ga=document.getElementById('globalAlert');
  if(!ga) return;
  ga.textContent=message;
  ga.style.display='block';
  if(type==='error'){
    ga.style.background='rgba(239,68,68,.12)'; ga.style.border='1px solid rgba(239,68,68,.28)'; ga.style.color='#fca5a5';
  } else if(type==='success'){
    ga.style.background='rgba(34,197,94,.12)'; ga.style.border='1px solid rgba(34,197,94,.24)'; ga.style.color='#86efac';
  } else {
    ga.style.background='rgba(234,179,8,.12)'; ga.style.border='1px solid rgba(234,179,8,.22)'; ga.style.color='#fde68a';
  }
  ga.scrollIntoView({behavior:'smooth', block:'center'});
  // auto hide après 8s pour erreur? non, on garde
}
function hideGlobalAlert(){ const ga=document.getElementById('globalAlert'); if(ga){ ga.style.display='none'; ga.textContent=''; } }

function hideAllBlocks(){ formCard.style.display='none'; loadingBlock.classList.remove('show'); receiptBlock.classList.remove('show'); }
function showFormBlock(){ hideAllBlocks(); formCard.style.display='block'; payBtn.disabled=false; hideGlobalAlert(); }
function showLoading(msg,sub){ hideAllBlocks(); loadingBlock.classList.add('show'); loadingMsg.textContent=msg||''; loadingSub.textContent=sub||''; const cb=document.getElementById('cancelBtn'); if(cb) cb.style.display='inline-flex'; hideGlobalAlert(); }

document.getElementById('cancelBtn')?.addEventListener('click', async()=>{
  const ref = lastReference || RECEIPT_REF_FROM_URL;
  if(!ref){ showFormBlock(); return; }
  if(!confirm('Annuler ce paiement et marquer comme échoué pour pouvoir réessayer ?')) return;
  try{
    const fd=new FormData(); fd.append('action','cancel_payment'); fd.append('reference',ref);
    const res=await fetch('vote_api.php',{method:'POST',body:fd});
    const data=await res.json();
    if(data.success){
      if(pollInterval) clearInterval(pollInterval);
      showFormBlock(); showError('err-global','Paiement annulé: '+ (data.message||'') + ' Vous pouvez réessayer.');
      updateStepper(2);
    } else {
      showError('err-global', data.message||'Erreur annulation');
    }
  }catch(e){ showError('err-global','Erreur réseau annulation'); }
});

payBtn.addEventListener('click', async()=>{
  document.querySelectorAll('.error-msg').forEach(el=>el.classList.remove('show'));
  if(!selectedOffreId){ showError('err-offre'); updateStepper(1); return; }

  if(currentMethod==='mobile'){
    currentOp=detectOperator(phoneInput.value);
    if(!currentOp){ showError('err-telephone'); phoneInput.focus(); updateStepper(2); return; }
    const messageUser=document.getElementById('message_user').value.trim();
    const emailMobile=document.getElementById('email_mobile').value.trim();
    payBtn.disabled=true;
    updateStepper(3);
    showLoading('Connexion à Unipesa — '+currentOp.label+'…','Numéro: '+prettyPhone(currentOp.national)+' • Provider: '+currentOp.provider+' • Gateway Unipesa/Avadapay');

    const fd=new FormData();
    fd.append('action','initiate_payment');
    fd.append('candidate_id',CANDIDATE_ID);
    fd.append('evenement_id',CONCOURS_ID);
    fd.append('pack_id',selectedOffreId);
    if(ETAPE_ID!==null) fd.append('etape_id',ETAPE_ID);
    fd.append('telephone',currentOp.e164);
    fd.append('provider',currentOp.provider);
    fd.append('message',messageUser);
    fd.append('email',emailMobile);
    fd.append('customer_name',CANDIDATE_NAME);

    try{
      const resp=await fetch('vote_api.php',{method:'POST',body:fd});
      const data=await resp.json();
      if(!data.success){
        showFormBlock(); showError('err-global',data.message||'Erreur inconnue.'); payBtn.disabled=false; return;
      }
      showLoading('Vérifiez votre téléphone '+prettyPhone(currentOp.national)+' et validez le paiement '+currentOp.label+'.','Référence Unipesa: '+data.reference+' • Gateway: '+(data.gateway||'unipesa')+' • Confirmez sur téléphone');
      startPolling(data.reference);
    }catch(e){
      console.error(e);
      showFormBlock(); showError('err-global','Erreur réseau. Vérifiez connexion.'); payBtn.disabled=false;
    }
  } else {
    // CARD flow via MaishaPay Checkout
    const emailCard=document.getElementById('email_card').value.trim();
    const customerName=document.getElementById('customer_name').value.trim() || CANDIDATE_NAME;
    const phoneCard=document.getElementById('phone_card').value.trim();
    if(!emailCard || !emailCard.includes('@')){ showError('err-email-card'); document.getElementById('email_card').focus(); return; }
    payBtn.disabled=true;
    updateStepper(3);
    showLoading('Préparation paiement carte '+selectedCardType+' via MaishaPay Checkout…','Montant: '+(selectedOffre?selectedOffre.prix+' '+(selectedOffre.devise||'USD'):'—')+' • Redirection sécurisée CyberSource');

    const fd=new FormData();
    fd.append('action','initiate_card_payment');
    fd.append('candidate_id',CANDIDATE_ID);
    fd.append('evenement_id',CONCOURS_ID);
    fd.append('pack_id',selectedOffreId);
    if(ETAPE_ID!==null) fd.append('etape_id',ETAPE_ID);
    fd.append('card_type',selectedCardType);
    fd.append('email',emailCard);
    fd.append('customer_name',customerName);
    fd.append('phone',phoneCard);

    try{
      const resp=await fetch('vote_api.php',{method:'POST',body:fd});
      const data=await resp.json();
      if(!data.success){
        showFormBlock(); showError('err-global',data.message||'Erreur carte.'); payBtn.disabled=false; return;
      }
      showLoading('Paiement carte sécurisé - onglet ouvert…','Référence: '+data.reference+' • Un onglet sécurisé vient de s'ouvrir pour saisir votre carte Visa/Mastercard (CyberSource 3D Secure). Complétez le paiement puis revenez sur cet onglet. Si l'onglet ne s'est pas ouvert, cliquez sur le lien dans le message ci-dessous.');
      lastReference = data.reference;
      const redirectUrl = data.checkout_redirect_url || ('vote_checkout.php?ref='+encodeURIComponent(data.reference));
      // Ouvre paiement dans nouvel onglet pour garder page vote ouverte et poller statut + revenir auto vers reçu (fix Cancel Order qui emmenait ailleurs)
      const win = window.open(redirectUrl, '_blank');
      if(!win){
        loadingSub.innerHTML = 'Popup bloqué. <a href="'+redirectUrl+'" target="_blank" style="color:#F3D77A;text-decoration:underline">Cliquez ici pour ouvrir le paiement sécurisé</a> • Réf: '+data.reference;
      }
      // Polling carte comme mobile money - vérifie vraie raison échec/succès et revient auto vers vote principal
      startPolling(data.reference);
      const cancelBtnEl = document.getElementById('cancelBtn');
      if(cancelBtnEl){
        cancelBtnEl.style.display='inline-flex';
        cancelBtnEl.textContent='🔄 J\'ai payé, vérifier le statut';
        cancelBtnEl.onclick = async (e) => {
          e.preventDefault();
          try{
            const fd2=new FormData(); fd2.append('action','check_payment'); fd2.append('reference',data.reference);
            const res2=await fetch('vote_api.php',{method:'POST',body:fd2});
            const info2=await res2.json();
            if(info2.statut==='confirme'){
              clearInterval(pollInterval);
              fillReceipt(data.reference,'Confirmé ✔ via '+(info2.details?.moyen_paiement||'carte'),info2.details);
              hideAllBlocks(); receiptBlock.classList.add('show');
              updateStepper(4);
              updateVotesActuels();
            }else if(info2.statut==='echoue'){
              clearInterval(pollInterval);
              showFormBlock();
              let realMsg = (info2.message || info2.details?.message_retour || 'annulé').replace(/^Paiement refusé:\s*/i,'');
              showError('err-global','Paiement refusé: '+realMsg);
              updateStepper(3);
            }else{
              showError('err-global','Toujours en attente: '+(info2.message||'Vérifiez que vous avez bien validé sur CyberSource. Réf: '+data.reference));
            }
          }catch(err){}
        };
      }
    }catch(e){
      console.error(e);
      showFormBlock(); showError('err-global','Erreur réseau carte.'); payBtn.disabled=false;
    }
  }
});

function startPolling(reference){
  let attempts=0; const max=40;
  if(pollInterval) clearInterval(pollInterval);
  pollInterval=setInterval(async()=>{
    attempts++;
    try{
      const fd=new FormData(); fd.append('action','check_payment'); fd.append('reference',reference);
      const res=await fetch('vote_api.php',{method:'POST',body:fd});
      const info=await res.json();
      if(info.statut==='confirme'){
        clearInterval(pollInterval);
        fillReceipt(reference,'Confirmé ✔ via MaishaPay '+ (info.details?.moyen_paiement||''),info.details);
        hideAllBlocks(); receiptBlock.classList.add('show');
        updateStepper(4);
        updateVotesActuels();
      }else if(info.statut==='echoue'){
        clearInterval(pollInterval); showFormBlock();
        // Récupère vraie raison d'échec depuis provider (solde insuffisant, etc) + ID transaction
        let realMsg = (info.message || info.details?.message_retour || '').trim();
        if(!realMsg) realMsg = info.details?.message_retour || 'annulé ou solde insuffisant';
        // Nettoie doublon "Paiement refusé: Paiement refusé:"
        realMsg = realMsg.replace(/^Paiement refusé:\s*/i, '').replace(/^Paiement échoué:\s*/i, '');
        // Si message contient déjà ID transaction, on le garde tel quel, sinon on ajoute ref
        const txId = info.details?.id_transaction_unipesa || info.details?.ref_transaction_unipesa || '';
        const ref = info.details?.numero_reference || '';
        let displayMsg = 'Paiement refusé: ' + realMsg;
        if(txId && !realMsg.includes(txId)) displayMsg += ' (ID: ' + txId + ')';
        if(ref && !realMsg.includes(ref) && !displayMsg.includes(ref)) displayMsg += ' [Réf: ' + ref + ']';
        showError('err-global', displayMsg);
        updateStepper(3);
      }else if(attempts>=max){
        clearInterval(pollInterval); showFormBlock(); showError('err-global','Temps d’attente dépassé. Si vous avez validé, vos votes seront comptés automatiquement. Réf: '+reference); updateStepper(3);
      }
    }catch(e){}
  },3000);
}

function fillReceipt(ref,statut,details){
  // Données sûres, pas d'infos non fondées
  const safeRef = ref || details?.numero_reference || '—';
  document.getElementById('rc-ref').textContent=safeRef;
  const qrRefEl=document.getElementById('qrRef');
  if(qrRefEl) qrRefEl.textContent=safeRef;

  // Candidate
  const candName = CANDIDATE_NAME || details?.nom_complet || '—';
  const candCode = CANDIDATE_CODE || '—';
  document.getElementById('rc-cand').textContent=candName;
  document.getElementById('rc-code').textContent=candCode;

  // Photo candidate dans reçu
  const rcPhoto = document.getElementById('rc-photo');
  if(rcPhoto){
    // si details a photo? sinon garde celle du header
    if(!rcPhoto.src || rcPhoto.src.includes('unsplash')){
      // garde existant
    }
  }

  const votes = selectedOffre ? selectedOffre.nombre_votes : (details?.votes_accordes || '—');
  document.getElementById('rc-votes').textContent=votes;

  const amount = selectedOffre ? selectedOffre.prix+' '+(selectedOffre.devise||'USD') : (details?.montant_paye ? details.montant_paye+' '+(details.devise||'USD') : '—');
  const amountEl = document.getElementById('rc-amount');
  if(amountEl) amountEl.textContent=amount;
  const amountEl2 = document.getElementById('rc-amount2');
  if(amountEl2) amountEl2.textContent=amount;

  // Méthode: map enum DB vers label propre, évite (undefined) et évite infos non fondées
  let methodLabel='—';
  if(details?.moyen_paiement){
    const mp = (details.moyen_paiement||'').toLowerCase();
    if(mp==='carte' || mp==='visa' || mp==='mastercard'){
      const mr = (details.message_retour||''+ (details.provider_maishapay||'') ).toUpperCase();
      const prov = (details.provider_maishapay||'').toUpperCase();
      if(prov==='MASTERCARD' || mr.includes('MASTER')) methodLabel='Carte Bancaire (Mastercard)';
      else if(prov==='VISA' || mr.includes('VISA')) methodLabel='Carte Bancaire (Visa)';
      else methodLabel='Carte Bancaire';
    } else if(mp==='mpesa') methodLabel='M-Pesa';
    else if(mp==='airtel') methodLabel='Airtel Money';
    else if(mp==='orange') methodLabel='Orange Money';
    else if(mp==='africell') methodLabel='Africell Money';
    else if(mp==='000000000' || mp==='') methodLabel='Carte Bancaire'; // fix ancien bug désaligné
    else methodLabel=mp.charAt(0).toUpperCase()+mp.slice(1);
  } else {
    if(currentMethod==='mobile'){
      methodLabel=currentOp?currentOp.label:'Mobile Money';
    } else {
      const ct = selectedCardType || (details?.provider_maishapay && details.provider_maishapay.toUpperCase().includes('MASTER') ? 'MASTERCARD' : 'VISA');
      methodLabel = ct==='MASTERCARD' ? 'Carte Bancaire (Mastercard)' : 'Carte Bancaire (Visa)';
    }
  }
  document.getElementById('rc-operator').textContent=methodLabel;

  // Contact masqué pour sécurité
  let contact='—';
  if(details?.email_votant && details.email_votant.includes('@')){
    const parts=details.email_votant.split('@');
    contact=parts[0].substring(0,2)+'***@'+parts[1];
  } else if(details?.numero_telephone && details.numero_telephone!=='000000000'){
    const tel = details.numero_telephone.replace(/\D/g,'');
    if(tel.length>6) contact=tel.substring(0,4)+'****'+tel.substring(tel.length-2);
    else contact=tel;
  } else {
    if(currentMethod==='mobile'){
      contact=currentOp?prettyPhone(currentOp.national):'—';
    } else {
      const emailCard=document.getElementById('email_card')?.value;
      if(emailCard && emailCard.includes('@')){
        const p=emailCard.split('@');
        contact=p[0].substring(0,2)+'***@'+p[1];
      }
    }
  }
  document.getElementById('rc-phone').textContent=contact;

  document.getElementById('rc-concours').textContent=CONCOURS_LABEL || 'LME GROUP';
  document.getElementById('rc-etape').textContent=ETAPE_ID?('Étape '+ETAPE_ID):(details?.etape_id ? 'Étape '+details.etape_id : 'Général');
  document.getElementById('rc-date').textContent=new Date().toLocaleString('fr-FR');

  let statutLabel='Confirmé';
  if(details?.etat_paiement){
    if(details.etat_paiement==='confirme') statutLabel='Confirmé';
    else if(details.etat_paiement==='echoue') statutLabel='Échoué';
    else statutLabel='En attente';
  } else {
    // si statut param contient 'confirme' ou 'en_attente'
    if(typeof statut==='string'){
      if(statut.toLowerCase().includes('confirme')) statutLabel='Confirmé';
      else if(statut.toLowerCase().includes('echoue')) statutLabel='Échoué';
      else if(statut.toLowerCase().includes('attente')) statutLabel='En attente';
      else statutLabel=statut;
    }
  }
  const statutEl=document.getElementById('rc-statut');
  if(statutEl){
    statutEl.textContent=statutLabel;
    statutEl.className = statutLabel==='Confirmé' ? 'receipt-status-confirmed' : (statutLabel==='Échoué' ? 'receipt-status-failed' : '');
  }
  // Badge dynamique selon statut (fix incohérence PAIEMENT CONFIRMÉ + Échoué)
  const badgeEl=document.getElementById('rc-badge');
  const badgeTextEl=document.getElementById('rc-badge-text');
  if(badgeEl && badgeTextEl){
    if(statutLabel==='Confirmé'){
      badgeEl.style.background='rgba(34,197,94,.12)'; badgeEl.style.borderColor='rgba(34,197,94,.22)'; badgeEl.style.color='#86efac';
      badgeTextEl.textContent='Paiement confirmé • Preuve de vote';
      badgeEl.querySelector('.receipt-dot').style.background='#22c55e';
    } else if(statutLabel==='Échoué'){
      badgeEl.style.background='rgba(239,68,68,.12)'; badgeEl.style.borderColor='rgba(239,68,68,.22)'; badgeEl.style.color='#fca5a5';
      badgeTextEl.textContent='Paiement échoué • Aucun vote compté';
      badgeEl.querySelector('.receipt-dot').style.background='#ef4444';
    } else {
      badgeEl.style.background='rgba(234,179,8,.12)'; badgeEl.style.borderColor='rgba(234,179,8,.22)'; badgeEl.style.color='#fde68a';
      badgeTextEl.textContent='Paiement en attente • Vérification en cours';
      badgeEl.querySelector('.receipt-dot').style.background='#eab308';
    }
  }

  const qrContainer=document.getElementById('qrcode');
  if(qrContainer){
    qrContainer.innerHTML='';
    const qrData = `https://lme-group.zaloriatech.com/verify?ref=${encodeURIComponent(ref)}&c=${encodeURIComponent(CANDIDATE_CODE)}&m=maishapay`;
    const qrcode = new QRCode(qrContainer, {
      text: qrData,
      width: 116,
      height: 116,
      colorDark: "#050B16",
      colorLight: "#ffffff",
      correctLevel: QRCode.CorrectLevel.H
    });
    setTimeout(()=>{
      let logoWrap = document.createElement('div');
      logoWrap.style.cssText='position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);width:36px;height:36px;background:#fff;border-radius:8px;border:2px solid #fff;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,0,0,.18);overflow:hidden';
      const logoImg = document.createElement('img');
      logoImg.src = '<?= htmlspecialchars($siteLogoUrl ?: 'https://gestion.zaloriatech.com/admin/uploads/sites_logo/default.jpg') ?>';
      logoImg.style.cssText='width:28px;height:28px;object-fit:contain;border-radius:4px';
      logoImg.onerror=()=>{ logoWrap.innerHTML='<span style="font-weight:800;font-size:.68rem;color:#071A3D">LME</span>'; };
      logoWrap.appendChild(logoImg);
      qrContainer.style.position='relative';
      qrContainer.appendChild(logoWrap);
    }, 180);
  }
}

async function updateVotesActuels(){
  try{
    const fd=new FormData(); fd.append('action','get_realtime_votes'); fd.append('evenement_id',CONCOURS_ID);
    const resp=await fetch('vote_api.php',{method:'POST',body:fd});
    const data=await resp.json();
    if(data.success && data.votes_per_candidate[CANDIDATE_ID]!==undefined){
      document.getElementById('votes-actuels').textContent=data.votes_per_candidate[CANDIDATE_ID];
    }
  }catch(e){}
}

async function downloadReceiptPDF(){
  const { jsPDF } = window.jspdf;
  const doc=new jsPDF({orientation:'portrait',unit:'mm',format:'a5'});
  const ref=document.getElementById('rc-ref').textContent || RECEIPT_REF_FROM_URL || '—';
  const cand=document.getElementById('rc-cand').textContent || CANDIDATE_NAME || '—';
  const code=document.getElementById('rc-code').textContent || CANDIDATE_CODE || '—';
  const votes=document.getElementById('rc-votes').textContent || '—';
  const amount=document.getElementById('rc-amount').textContent || document.getElementById('rc-amount2')?.textContent || '—';
  const oper=document.getElementById('rc-operator').textContent || '—';
  const phone=document.getElementById('rc-phone').textContent || '—';
  const date=document.getElementById('rc-date').textContent || new Date().toLocaleString('fr-FR');
  const statut=document.getElementById('rc-statut').textContent || 'Confirmé';
  const concours=document.getElementById('rc-concours').textContent || CONCOURS_LABEL || 'LME GROUP';
  const etape=document.getElementById('rc-etape').textContent || 'Général';

  // Header pro avec logo placeholder
  doc.setFillColor(5,11,22); doc.rect(0,0,148,36,'F');
  // Logo carré doré LME
  doc.setFillColor(212,175,55); doc.roundedRect(10,8,14,14,2,2,'F');
  doc.setTextColor(5,11,22); doc.setFont('helvetica','bold'); doc.setFontSize(10);
  doc.text('LME',13,17);
  doc.setTextColor(212,175,55); doc.setFontSize(14);
  doc.text('MISS AURORA RDC',28,14);
  doc.setTextColor(255,255,255); doc.setFontSize(9); doc.setFont('helvetica','normal');
  doc.text('Reçu Officiel de Vote - '+concours,28,20);
  doc.setFontSize(7); doc.setTextColor(180,180,180);
  doc.text('Preuve de paiement sécurisé • Vérifiable via QR code',28,26);
  // Ligne dorée
  doc.setFillColor(212,175,55); doc.rect(0,36,148,1,'F');

  // Corps
  doc.setTextColor(30,30,30);
  let y=46;
  doc.setFont('helvetica','bold'); doc.setFontSize(10);
  doc.text('Détails du vote',10,y); y+=6;
  doc.setFont('helvetica','normal'); doc.setFontSize(9);
  const addRow=(label,val)=>{
    if(y>185){ doc.addPage(); y=15; }
    doc.setFont('helvetica','bold'); doc.setTextColor(80,80,80); doc.text(label+':',10,y);
    doc.setFont('helvetica','normal'); doc.setTextColor(20,20,20); 
    const txt = String(val).substring(0,70);
    doc.text(txt,42,y);
    y+=6;
  };
  addRow('Référence', ref);
  addRow('Candidate', cand+' (N°'+code+')');
  addRow('Votes', votes);
  addRow('Montant', amount);
  addRow('Méthode', oper);
  addRow('Contact', phone);
  addRow('Date', date);
  addRow('Statut', statut);
  addRow('Étape', etape);

  // QR code depuis canvas
  try{
    const qrCanvas = document.querySelector('#qrcode canvas');
    const qrImg = document.querySelector('#qrcode img');
    let qrDataUrl=null;
    if(qrCanvas){
      qrDataUrl = qrCanvas.toDataURL('image/png');
    } else if(qrImg && qrImg.src){
      // si img, on dessine sur canvas temporaire
      const c=document.createElement('canvas'); c.width=116; c.height=116;
      const ctx=c.getContext('2d');
      ctx.drawImage(qrImg,0,0,116,116);
      qrDataUrl=c.toDataURL('image/png');
    }
    if(qrDataUrl){
      if(y>130){ doc.addPage(); y=15; }
      y+=4;
      doc.setFont('helvetica','bold'); doc.setFontSize(8); doc.setTextColor(80,80,80);
      doc.text('QR Vérification:',10,y); y+=4;
      doc.addImage(qrDataUrl, 'PNG', 10, y, 32, 32);
      doc.setFontSize(6); doc.setTextColor(100,100,100);
      doc.text('https://lme-group.zaloriatech.com/verify?ref='+ref, 46, y+6);
      doc.text('Scannez pour vérifier ce reçu', 46, y+10);
      y+=36;
    }
  }catch(e){ console.log('QR PDF error',e); }

  y+=4;
  if(y>170){ doc.addPage(); y=15; }
  doc.setFontSize(7); doc.setTextColor(100,100,100);
  doc.setFont('helvetica','normal');
  doc.text('Conservez ce reçu comme preuve. Vérifiable avec la référence auprès de LME GROUP.',10,y);
  y+=4;
  doc.text('40, Av. Kasangulu, Kasa-Vubu, Kinshasa, RDC | +243 860 370 727',10,y);
  y+=6;
  doc.setFont('helvetica','bold'); doc.setFontSize(7); doc.setTextColor(5,11,22);
  doc.text('REF: '+ref+' | '+cand+' | '+votes+' votes',10,y);

  doc.save('recu-vote-'+ref+'.pdf');
}

document.querySelectorAll('.tab-btn').forEach(btn=>{
  btn.addEventListener('click',()=>{
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    const periode=btn.dataset.periode;
    document.querySelectorAll('.class-period').forEach(p=>p.style.display=p.dataset.periode===periode?'block':'none');
  });
});

(function(){
  const burger=document.getElementById('auroraBurger');
  const drawer=document.getElementById('auroraDrawer');
  const overlay=document.getElementById('auroraOverlay');
  const closeBtn=document.getElementById('drawerClose');
  const bottomBtn=document.getElementById('bottomMenuBtn');
  function open(){ if(!drawer||!overlay||!burger) return; drawer.classList.add('is-open'); overlay.classList.add('is-open'); burger.setAttribute('aria-expanded','true'); drawer.setAttribute('aria-hidden','false'); document.body.style.overflow='hidden'; }
  function close(){ if(!drawer||!overlay||!burger) return; drawer.classList.remove('is-open'); overlay.classList.remove('is-open'); burger.setAttribute('aria-expanded','false'); drawer.setAttribute('aria-hidden','true'); document.body.style.overflow=''; }
  burger?.addEventListener('click',()=> drawer.classList.contains('is-open')?close():open());
  closeBtn?.addEventListener('click', close);
  overlay?.addEventListener('click', close);
  bottomBtn?.addEventListener('click', (e)=>{ e.preventDefault(); drawer.classList.contains('is-open')?close():open(); });
  document.addEventListener('keydown', e=>{ if(e.key==='Escape' && drawer?.classList.contains('is-open')) close(); });
  drawer?.querySelectorAll('a').forEach(a=>a.addEventListener('click', close));
})();

updateStepper(1);
updateSummary();

// Si receipt dans URL, afficher directement - sécurisé sans merchant data
if(RECEIPT_REF_FROM_URL){
  if(RECEIPT_DATA){
    selectedOffre = {nombre_votes: RECEIPT_DATA.votes_accordes, prix: RECEIPT_DATA.montant_paye, devise: RECEIPT_DATA.devise};
    // Détecte méthode depuis DB
    const mp = (RECEIPT_DATA.moyen_paiement||'').toLowerCase();
    if(mp==='carte'){
      currentMethod='card';
      const mr=(RECEIPT_DATA.message_retour||'').toUpperCase();
      selectedCardType = mr.includes('MASTER') ? 'MASTERCARD' : 'VISA';
    } else {
      currentMethod='mobile';
      currentOp = {label: mp==='mpesa'?'M-Pesa':mp==='airtel'?'Airtel Money':mp==='orange'?'Orange Money':mp==='africell'?'Africell Money':mp, national: RECEIPT_DATA.numero_telephone};
    }
    fillReceipt(RECEIPT_REF_FROM_URL, RECEIPT_DATA.etat_paiement, RECEIPT_DATA);
    hideAllBlocks();
    receiptBlock.classList.add('show');
    updateStepper(4);
  } else {
    showLoading('Vérification reçu '+RECEIPT_REF_FROM_URL+'…','');
    startPolling(RECEIPT_REF_FROM_URL);
  }
}
</script>
<?php endif; ?>
</body>
</html>
