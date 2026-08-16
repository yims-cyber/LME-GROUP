<?php
// voter.php — Miss Aurora RDC 2026 — Vote avec stepper visuel + reçu téléchargeable
// Logique: packs -> téléphone (détection opérateur auto) -> paiement -> reçu
// Base: mayi1275_zaloria_multisysteme

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
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($metaTitle) ?></title>
<meta name="description" content="<?= htmlspecialchars($metaDescClean) ?>">
<meta property="og:title" content="<?= htmlspecialchars($metaTitle) ?>">
<meta property="og:description" content="<?= htmlspecialchars($metaDescClean) ?>">
<meta property="og:image" content="<?= htmlspecialchars($metaImage) ?>">
<meta property="og:url" content="<?= htmlspecialchars($metaUrl) ?>">
<meta property="og:type" content="website">
<meta name="twitter:card" content="summary_large_image">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;0,700&family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
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

/* NAV sobre */
.aurora-header{position:fixed;top:0;left:0;right:0;z-index:1000;height:60px;background:#fff;border-bottom:1px solid #EBEBEB;display:flex;align-items:center;justify-content:space-between;padding:0 24px}
.aurora-header__logo{display:flex;align-items:center;gap:10px;font-weight:700;color:#111}
.aurora-header__logo img{width:34px;height:34px;object-fit:contain;border-radius:6px;border:1px solid #EBEBEB;padding:2px;background:#fff}
.aurora-header__nav{display:flex;gap:8px;align-items:center}
.aurora-header__nav a{font-family:var(--font-ui);font-size:.82rem;padding:7px 12px;border-radius:8px;color:#444;transition:background .15s}
.aurora-header__nav a:hover{background:#F5F5F5;color:#111}

/* CONTAINER */
.container{max-width:1120px;margin:0 auto;padding:calc(var(--nav-h) + 28px) 20px 80px}
h1{font-family:var(--font-serif);font-size:clamp(2rem,4vw,3rem);font-weight:300;text-align:center;margin-bottom:8px;letter-spacing:-.02em}
h1 em{font-style:italic;font-weight:700;color:var(--gold-lt)}
.subtitle{text-align:center;color:var(--muted);font-size:.92rem;margin-bottom:28px}

/* CARD */
.card{background:linear-gradient(180deg, rgba(255,255,255,.04) 0%, rgba(255,255,255,.02) 100%);border:1px solid rgba(255,255,255,.08);border-radius:20px;padding:26px;margin-bottom:24px;backdrop-filter:blur(12px);box-shadow:0 12px 40px rgba(0,0,0,.22)}
.candidate-header{display:flex;gap:20px;align-items:center;margin-bottom:22px;padding-bottom:20px;border-bottom:1px solid rgba(255,255,255,.06)}
.candidate-header img{width:108px;height:108px;border-radius:18px;object-fit:cover;border:1px solid rgba(212,175,55,.22);box-shadow:0 8px 24px rgba(0,0,0,.24);flex-shrink:0;background:#0B1E42}
.candidate-header h2{font-family:var(--font-serif);font-size:1.6rem;font-weight:700;line-height:1.1}
.candidate-header .code{display:inline-flex;align-items:center;gap:6px;background:var(--gold);color:#050B16;padding:5px 12px;border-radius:100px;font-size:.68rem;font-weight:800;letter-spacing:.06em;margin-bottom:8px;box-shadow:0 4px 12px rgba(212,175,55,.28)}
.votes-count{background:rgba(212,175,55,.08);border:1px solid rgba(212,175,55,.14);border-radius:14px;padding:14px;text-align:center;margin-bottom:18px}
.votes-count .num{font-family:var(--font-serif);font-size:2.2rem;font-weight:700;color:var(--gold-lt);line-height:1}
.votes-count .label{font-size:.72rem;color:var(--muted);margin-top:4px}

/* STEPPER */
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

/* PACKS */
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

/* FORM */
.form-group{margin-bottom:16px}
.form-group label{display:block;font-family:var(--font-ui);font-size:.60rem;font-weight:700;text-transform:uppercase;letter-spacing:.10em;color:var(--muted);margin-bottom:7px}
.form-group input{width:100%;padding:13px 14px;border-radius:10px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.09);color:#fff;font-family:var(--font-sans);font-size:.92rem;outline:none;transition:.18s}
.form-group input:focus{border-color:var(--gold);background:rgba(212,175,55,.06);box-shadow:0 0 0 3px rgba(212,175,55,.12)}
.form-group input.valid{border-color:rgba(34,197,94,.5);box-shadow:0 0 0 3px rgba(34,197,94,.12)}
.form-group input.invalid{border-color:rgba(239,68,68,.5);box-shadow:0 0 0 3px rgba(239,68,68,.12)}
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

/* RECEIPT */
.receipt-container{display:none;background:linear-gradient(180deg, #0F1F3A 0%, #071A3D 100%);border:1px solid rgba(212,175,55,.22);border-radius:20px;padding:26px;max-width:560px;margin:28px auto;box-shadow:0 24px 64px rgba(0,0,0,.32)}
.receipt-container.show{display:block;animation:fadeUp .28s ease}
.receipt-head{text-align:center;margin-bottom:18px}
.receipt-head h2{font-family:var(--font-serif);font-size:1.8rem;font-weight:700}
.receipt-head p{color:var(--muted);font-size:.82rem;margin-top:4px}
.receipt-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:100px;background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.22);color:#86efac;font-size:.70rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;margin-top:10px}
.receipt-details{text-align:left;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06);border-radius:14px;padding:16px;margin:18px 0}
.receipt-row{display:flex;justify-content:space-between;gap:12px;padding:9px 0;border-bottom:1px solid rgba(255,255,255,.06);font-family:var(--font-ui);font-size:.82rem;color:var(--muted);flex-wrap:wrap}
.receipt-row span:last-child{color:#fff;font-weight:600;text-align:right;word-break:break-all}
.receipt-row:last-child{border:none}
.receipt-actions{display:flex;flex-direction:column;gap:10px;margin-top:18px}
.qr-wrap{display:flex;justify-content:center;margin:16px 0}
.qr-box{width:128px;height:128px;border-radius:12px;background:#fff;padding:8px;display:flex;align-items:center;justify-content:center;color:#111;font-weight:800;font-size:.62rem;text-align:center;letter-spacing:.04em}

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
</style>
</head>
<body>
<header class="aurora-header">
  <a href="index.php" class="aurora-header__logo">
    <?php if($siteLogoUrl): ?><img src="<?= htmlspecialchars($siteLogoUrl) ?>" alt="logo"><?php endif; ?>
    <span><?= htmlspecialchars($siteName) ?></span>
  </a>
  <nav class="aurora-header__nav">
    <a href="index.php">Accueil</a>
    <a href="index.php#candidates">Candidates</a>
    <a href="index.php#classement">Classement</a>
  </nav>
</header>

<div class="container">
<?php if($error): ?>
  <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php if(!$candidate): ?><div style="text-align:center;margin-top:16px"><a href="index.php#candidates" class="btn btn-outline">← Voir candidates</a></div><?php endif; ?>
<?php else: ?>
  <h1>Voter pour <em><?= htmlspecialchars($candidate['nom_complet']) ?></em></h1>
  <p class="subtitle">Soutenez votre candidate préférée — paiement sécurisé Mobile Money</p>

  <!-- STEPPER -->
  <div class="stepper" id="stepper">
    <div class="step active" data-step="1"><div class="step-num">1</div><div class="step-label">Pack</div></div>
    <div class="step-line" id="line1"></div>
    <div class="step" data-step="2"><div class="step-num">2</div><div class="step-label">Téléphone</div></div>
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

    <h3 style="font-family:var(--font-serif);font-size:1.15rem;font-weight:700;margin:22px 0 12px">2️⃣ Votre numéro Mobile Money</h3>
    <div class="form-group">
      <label for="telephone">Numéro (détection auto opérateur)</label>
      <div class="phone-wrap">
        <input type="tel" id="telephone" inputmode="numeric" autocomplete="tel" placeholder="Ex: 0812345678" maxlength="17">
      </div>
      <p style="font-family:var(--font-ui);font-size:.70rem;color:var(--muted);margin-top:6px">Formats: 0812345678, 812345678, 243812345678, +243 81… — détection Orange / Airtel / M-Pesa / Africell auto.</p>
      <div class="operator-badge" id="operatorBadge">
        <div class="op-dot" id="opDot">—</div>
        <div><div id="opName">Opérateur</div><small id="opNumber"></small></div>
        <span class="op-check">✓</span>
      </div>
      <p class="error-msg" id="err-telephone">Numéro invalide ou opérateur non reconnu (Vodacom 81-83 · Orange 80/84/85/89 · Airtel 97-99 · Africell 90/91).</p>
    </div>

    <div class="form-group">
      <label for="message_user">Message de soutien (optionnel)</label>
      <input type="text" id="message_user" maxlength="255" placeholder="Ex: Je te soutiens !">
    </div>

    <h3 style="font-family:var(--font-serif);font-size:1.15rem;font-weight:700;margin:22px 0 12px">3️⃣ Récapitulatif</h3>
    <div class="summary">
      <div class="summary-row"><span>Candidate</span><span><?= htmlspecialchars($candidate['nom_complet']) ?></span></div>
      <div class="summary-row"><span>Pack</span><span id="summary-votes">—</span></div>
      <div class="summary-row"><span>Opérateur</span><span id="summary-operator">—</span></div>
      <div class="summary-row"><span>Numéro</span><span id="summary-phone">—</span></div>
      <div class="summary-row"><span>Montant</span><span class="total" id="summary-total">—</span></div>
    </div>

    <div class="error-msg" id="err-global" style="margin-bottom:12px"></div>

    <button type="button" id="payBtn" class="btn btn-gold btn--full">💳 Payer maintenant — valider le vote</button>
    <p style="text-align:center;font-family:var(--font-ui);font-size:.70rem;color:var(--muted2);margin-top:10px">Paiement sécurisé via Unipesa • Confirmation sur téléphone</p>
  </div>

  <div id="loadingBlock" class="loading-state">
    <div class="spinner"></div>
    <div class="loading-msg" id="loadingMsg">Connexion à l'opérateur…</div>
    <div class="loading-sub" id="loadingSub"></div>
    <p style="margin-top:16px;font-family:var(--font-ui);font-size:.72rem;color:var(--muted2)">Ne fermez pas cette page — vérification en cours…</p>
  </div>

  <div id="receiptBlock" class="receipt-container">
    <div class="receipt-head">
      <h2>Reçu Officiel</h2>
      <p>Miss Aurora RDC — LME GROUP</p>
      <div class="receipt-badge"><span style="width:6px;height:6px;border-radius:50%;background:var(--green);display:inline-block"></span> Paiement confirmé • Preuve de vote</div>
    </div>
    <div class="qr-wrap">
      <div class="qr-box" id="qrBox">LME<br>2026<br><span id="qrRef" style="font-size:.52rem;word-break:break-all"></span></div>
    </div>
    <div class="receipt-details" id="receiptDetails">
      <div class="receipt-row"><span>Référence</span><span><strong id="rc-ref">—</strong></span></div>
      <div class="receipt-row"><span>Candidate</span><span id="rc-cand">—</span></div>
      <div class="receipt-row"><span>Code</span><span id="rc-code">—</span></div>
      <div class="receipt-row"><span>Votes</span><span id="rc-votes">—</span></div>
      <div class="receipt-row"><span>Montant</span><span id="rc-amount">—</span></div>
      <div class="receipt-row"><span>Opérateur</span><span id="rc-operator">—</span></div>
      <div class="receipt-row"><span>Numéro</span><span id="rc-phone">—</span></div>
      <div class="receipt-row"><span>Concours</span><span id="rc-concours">—</span></div>
      <div class="receipt-row"><span>Étape</span><span id="rc-etape">—</span></div>
      <div class="receipt-row"><span>Date</span><span id="rc-date">—</span></div>
      <div class="receipt-row"><span>Statut</span><span id="rc-statut" style="color:var(--green);font-weight:700">—</span></div>
    </div>
    <div class="receipt-actions">
      <button class="btn btn-gold" onclick="downloadReceiptPDF()">📥 Télécharger le reçu (PDF)</button>
      <button class="btn btn-outline" onclick="window.print()">🖨️ Imprimer</button>
      <a href="voter.php?candidat=<?= $candidate_id ?>&concours_id=<?= $concours_id ?>&etape_id=<?= $etape_id ?>" class="btn btn-outline">↩ Voter à nouveau</a>
      <a href="index.php#candidates" class="btn btn-outline">Voir classement</a>
    </div>
    <p style="font-family:var(--font-ui);font-size:.68rem;color:var(--muted2);margin-top:14px;text-align:center">Conservez ce reçu comme preuve. Vérifiable avec la référence.</p>
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

const OPERATORS={
  mpesa:{label:'M-Pesa',short:'MP',cls:'op-mpesa',prefixes:['81','82','83']},
  airtel:{label:'Airtel Money',short:'AM',cls:'op-airtel',prefixes:['97','98','99']},
  orange:{label:'Orange Money',short:'OM',cls:'op-orange',prefixes:['80','84','85','89']},
  africell:{label:'Africell Money',short:'AF',cls:'op-africell',prefixes:['90','91']},
};
function detectOperator(raw){
  let d=(raw||'').replace(/\D/g,'');
  if(d.length===12 && d.startsWith('243')) d=d.slice(3);
  else if(d.length===10 && d[0]==='0') d=d.slice(1);
  if(d.length!==9 || d[0]==='0') return null;
  const pref=d.slice(0,2);
  for(const [op,meta] of Object.entries(OPERATORS)){
    if(meta.prefixes.includes(pref)){
      return{op,label:meta.label,short:meta.short,cls:meta.cls,national:'0'+d,e164:'243'+d};
    }
  }
  return null;
}
function prettyPhone(national){ return national.replace(/^(\d{4})(\d{3})(\d{3})$/,'$1 $2 $3'); }

const packOptions=document.querySelectorAll('.pack-option');
const summaryVotes=document.getElementById('summary-votes');
const summaryTotal=document.getElementById('summary-total');
const summaryOperator=document.getElementById('summary-operator');
const summaryPhone=document.getElementById('summary-phone');
const payBtn=document.getElementById('payBtn');
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
let lastReference=null, lastDetails=null;

function updateStepper(step){
  // step 1..4
  document.querySelectorAll('.step').forEach(s=>{
    const n=parseInt(s.dataset.step);
    s.classList.remove('active','completed');
    if(n<step) s.classList.add('completed');
    if(n===step) s.classList.add('active');
  });
  document.querySelectorAll('.step-line').forEach(l=>{
    const id=l.id; // line1, line2, line3
    const num=parseInt(id.replace('line',''));
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
    updateStepper(2);
    // focus phone
    setTimeout(()=>phoneInput.focus(), 200);
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
  summaryOperator.textContent=currentOp?currentOp.label+' • '+prettyPhone(currentOp.national):'—';
  summaryPhone.textContent=currentOp?prettyPhone(currentOp.national):'—';
}

phoneInput.addEventListener('input',()=>{
  hideError('err-telephone');
  const raw=phoneInput.value;
  const digits=raw.replace(/\D/g,'');
  currentOp=detectOperator(raw);
  if(currentOp){
    phoneInput.classList.add('valid'); phoneInput.classList.remove('invalid');
    operatorBadge.className='operator-badge show '+currentOp.cls;
    opDot.textContent=currentOp.short;
    opName.textContent=currentOp.label+' détecté';
    opNumber.textContent=prettyPhone(currentOp.national)+' (+'+currentOp.e164+')';
    updateStepper(3);
  } else {
    operatorBadge.className='operator-badge';
    phoneInput.classList.remove('valid');
    phoneInput.classList.toggle('invalid', digits.length>=9);
    if(digits.length>=9) showError('err-telephone');
    // reste à l'étape 2 si pas de pack, sinon 2
    if(selectedOffre) updateStepper(2);
  }
  updateSummary();
});

function showError(id,msg){ const el=document.getElementById(id); if(msg) el.textContent=msg; el.classList.add('show'); }
function hideError(id){ document.getElementById(id).classList.remove('show'); }
function hideAllBlocks(){ formCard.style.display='none'; loadingBlock.classList.remove('show'); receiptBlock.classList.remove('show'); }
function showFormBlock(){ hideAllBlocks(); formCard.style.display='block'; payBtn.disabled=false; }
function showLoading(msg,sub){ hideAllBlocks(); loadingBlock.classList.add('show'); loadingMsg.textContent=msg||''; loadingSub.textContent=sub||''; }

payBtn.addEventListener('click', async()=>{
  document.querySelectorAll('.error-msg').forEach(el=>el.classList.remove('show'));
  if(!selectedOffreId){ showError('err-offre'); updateStepper(1); return; }
  currentOp=detectOperator(phoneInput.value);
  if(!currentOp){ showError('err-telephone'); phoneInput.focus(); updateStepper(2); return; }
  const messageUser=document.getElementById('message_user').value.trim();
  payBtn.disabled=true;
  updateStepper(3);
  showLoading('Connexion à '+currentOp.label+'…','Numéro: '+prettyPhone(currentOp.national));

  const fd=new FormData();
  fd.append('action','initiate_payment');
  fd.append('candidate_id',CANDIDATE_ID);
  fd.append('evenement_id',CONCOURS_ID);
  fd.append('pack_id',selectedOffreId);
  if(ETAPE_ID!==null) fd.append('etape_id',ETAPE_ID);
  fd.append('telephone',currentOp.e164);
  fd.append('message',messageUser);

  try{
    const resp=await fetch('voter_api.php',{method:'POST',body:fd});
    const data=await resp.json();
    if(!data.success){
      showFormBlock(); showError('err-global',data.message||'Erreur inconnue.'); payBtn.disabled=false; return;
    }
    lastReference=data.reference;
    showLoading('Vérifiez votre téléphone '+prettyPhone(currentOp.national)+' et validez le paiement '+currentOp.label+'.','Référence: '+data.reference);
    startPolling(data.reference);
  }catch(e){
    showFormBlock(); showError('err-global','Erreur réseau. Vérifiez connexion.'); payBtn.disabled=false;
  }
});

function startPolling(reference){
  let attempts=0; const max=40;
  if(pollInterval) clearInterval(pollInterval);
  pollInterval=setInterval(async()=>{
    attempts++;
    try{
      const fd=new FormData(); fd.append('action','check_payment'); fd.append('reference',reference);
      const res=await fetch('voter_api.php',{method:'POST',body:fd});
      const info=await res.json();
      if(info.statut==='confirme'){
        clearInterval(pollInterval);
        lastDetails=info.details||{};
        fillReceipt(reference,'Confirmé ✔',info.details);
        hideAllBlocks(); receiptBlock.classList.add('show');
        updateStepper(4);
        updateVotesActuels();
      }else if(info.statut==='echoue'){
        clearInterval(pollInterval); showFormBlock(); showError('err-global','Paiement refusé: '+(info.message||'annulé ou solde insuffisant.')); updateStepper(3);
      }else if(attempts>=max){
        clearInterval(pollInterval); showFormBlock(); showError('err-global','Temps d’attente dépassé. Si vous avez validé, vos votes seront comptés automatiquement.'); updateStepper(3);
      }
    }catch(e){}
  },3000);
}

function fillReceipt(ref,statut,details){
  document.getElementById('rc-ref').textContent=ref;
  document.getElementById('qrRef').textContent=ref;
  document.getElementById('rc-cand').textContent=CANDIDATE_NAME;
  document.getElementById('rc-code').textContent=CANDIDATE_CODE;
  document.getElementById('rc-votes').textContent=selectedOffre?selectedOffre.nombre_votes:'—';
  document.getElementById('rc-amount').textContent=selectedOffre?selectedOffre.prix+' '+(selectedOffre.devise||'USD'):'—';
  document.getElementById('rc-operator').textContent=currentOp?currentOp.label:'—';
  document.getElementById('rc-phone').textContent=currentOp?prettyPhone(currentOp.national):'—';
  document.getElementById('rc-concours').textContent=CONCOURS_LABEL;
  document.getElementById('rc-etape').textContent=ETAPE_ID?('Étape '+ETAPE_ID):'Général';
  document.getElementById('rc-date').textContent=new Date().toLocaleString('fr-FR');
  document.getElementById('rc-statut').textContent=statut;
  lastDetails=details;
}

async function updateVotesActuels(){
  try{
    const fd=new FormData(); fd.append('action','get_realtime_votes'); fd.append('evenement_id',CONCOURS_ID);
    const resp=await fetch('voter_api.php',{method:'POST',body:fd});
    const data=await resp.json();
    if(data.success && data.votes_per_candidate[CANDIDATE_ID]!==undefined){
      document.getElementById('votes-actuels').textContent=data.votes_per_candidate[CANDIDATE_ID];
    }
  }catch(e){}
}

// Téléchargement reçu PDF
async function downloadReceiptPDF(){
  const { jsPDF } = window.jspdf;
  const doc=new jsPDF({orientation:'portrait',unit:'mm',format:'a5'});
  const ref=document.getElementById('rc-ref').textContent;
  const cand=document.getElementById('rc-cand').textContent;
  const code=document.getElementById('rc-code').textContent;
  const votes=document.getElementById('rc-votes').textContent;
  const amount=document.getElementById('rc-amount').textContent;
  const oper=document.getElementById('rc-operator').textContent;
  const phone=document.getElementById('rc-phone').textContent;
  const date=document.getElementById('rc-date').textContent;
  const statut=document.getElementById('rc-statut').textContent;

  // En-tête
  doc.setFillColor(5,11,22); doc.rect(0,0,148,32,'F');
  doc.setTextColor(212,175,55); doc.setFont('helvetica','bold'); doc.setFontSize(14);
  doc.text('MISS AURORA RDC',10,14);
  doc.setTextColor(255,255,255); doc.setFontSize(10); doc.text('Reçu Officiel de Vote - LME GROUP',10,20);
  doc.setFontSize(8); doc.setTextColor(180,180,180); doc.text('Preuve de paiement Mobile Money',10,26);

  // Corps
  doc.setTextColor(20,20,20); doc.setFont('helvetica','normal'); doc.setFontSize(10);
  let y=42;
  const addRow=(label,val)=>{
    doc.setFont('helvetica','bold'); doc.text(label+':',10,y);
    doc.setFont('helvetica','normal'); doc.text(String(val),45,y);
    y+=7;
  };
  addRow('Référence',ref);
  addRow('Candidate',cand+' (N°'+code+')');
  addRow('Votes',votes);
  addRow('Montant',amount);
  addRow('Opérateur',oper);
  addRow('Numéro',phone);
  addRow('Date',date);
  addRow('Statut',statut);
  addRow('Concours',CONCOURS_LABEL);

  y+=6;
  doc.setFontSize(8); doc.setTextColor(100,100,100);
  doc.text('Conservez ce reçu comme preuve. Vérifiable avec la référence auprès de LME GROUP.',10,y);
  y+=4; doc.text('40, Av. Kasangulu, Kasa-Vubu, Kinshasa, RDC | +243 860 370 727',10,y);

  // QR simulé = référence en gros
  doc.setFont('helvetica','bold'); doc.setFontSize(8); doc.setTextColor(5,11,22);
  doc.text('REF: '+ref,10,y+10);

  doc.save('recu-vote-'+ref+'.pdf');
}

// Onglets classement
document.querySelectorAll('.tab-btn').forEach(btn=>{
  btn.addEventListener('click',()=>{
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    const periode=btn.dataset.periode;
    document.querySelectorAll('.class-period').forEach(p=>p.style.display=p.dataset.periode===periode?'block':'none');
  });
});

updateStepper(1);
updateSummary();
</script>
<?php endif; ?>
</body>
</html>
