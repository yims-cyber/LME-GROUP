<?php
// ========== Connexion DB ==========
$dbHost = 'localhost:3306';
    $dbName = 'mayi1275_zaloria_multisysteme';      
    $dbUser = 'mayi1275_zaloriatech';
    $dbPass = '07/09/1996/O2switch';

try {
    $pdo = new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    die("Erreur de connexion : " . $e->getMessage());
}

// ========== Détection du site via le sous-domaine ==========
$host = $_SERVER['HTTP_HOST'] ?? '';
$domain = 'zaloriatech.com';
$subdomain = '';

// FIX: force lme-group pour domaines custom, localhost et IP
if (stripos($host, 'lme-group') !== false || stripos($host, 'aurora') !== false || $host === 'localhost' || $host === '127.0.0.1' || filter_var($host, FILTER_VALIDATE_IP)) {
    $subdomain = 'lme-group';
} else if (preg_match('/^(.*?)\.' . preg_quote($domain, '/') . '$/', $host, $matches)) {
    $subdomain = $matches[1];
} else {
    $subdomain = 'lme-group'; // default LME au lieu de gestion pour éviter fallback site_id=1
}

$stmtSite = $pdo->prepare("SELECT site_id, nom_entreprise, logo_concours, logo_extension, lien_unique, gestionnaire_id, cree_par, date_creation FROM sites WHERE lien_unique = ?");
$stmtSite->execute([$subdomain]);
$siteData = $stmtSite->fetch();

if (!$siteData) {
    $stmtFallback = $pdo->query("SELECT site_id, nom_entreprise, logo_concours, logo_extension, lien_unique, gestionnaire_id, cree_par, date_creation FROM sites LIMIT 1");
    $siteData = $stmtFallback->fetch();
    if (!$siteData) {
        die("Aucun site trouvé.");
    }
    $subdomain = $siteData['lien_unique'];
}

$siteId = $siteData['site_id'];
$siteName = $siteData['nom_entreprise'];
$siteLogoConcours = $siteData['logo_concours'];
$siteLogoExtension = $siteData['logo_extension'];
$siteLien = $siteData['lien_unique'];

define('STOCKAGE_DOMAIN', 'https://gestion.zaloriatech.com');
$siteLogoUrl = '';
if ($siteLogoConcours && !empty($siteLogoExtension)) {
    $siteLogoUrl = STOCKAGE_DOMAIN . '/admin/uploads/sites_logo/' . $siteLien . '.' . $siteLogoExtension . '?v=' . time();
}

// ========== Récupération de tous les concours actifs du site ==========
$stmtConcours = $pdo->prepare("
    SELECT concours_id, nom_concours, url_concours, logo_concours, logo_extension,
             date_ouverture, date_cloture, etat_concours,
           site_id, arret_manuel, cree_le, modifie_le, results_visible,
           verification_active, results_live
    FROM concours
    WHERE site_id = ? AND etat_concours = 'actif'
      AND arret_manuel = 0
      AND NOW() BETWEEN date_ouverture AND date_cloture
    ORDER BY date_ouverture ASC
");
$stmtConcours->execute([$siteId]);
$concoursList = $stmtConcours->fetchAll();

if (empty($concoursList)) {
    $stmtConcoursFallback = $pdo->prepare("
        SELECT concours_id, nom_concours, url_concours, logo_concours, logo_extension,
                 date_ouverture, date_cloture, etat_concours,
               site_id, arret_manuel, cree_le, modifie_le, results_visible,
               verification_active, results_live
        FROM concours
        WHERE site_id = ?
        ORDER BY date_ouverture ASC
        LIMIT 1
    ");
    $stmtConcoursFallback->execute([$siteId]);
    $concoursList = $stmtConcoursFallback->fetchAll();
}

$selectedConcoursId = isset($_GET['concours_id']) ? (int)$_GET['concours_id'] : 0;
$currentConcours = null;
foreach ($concoursList as $c) {
    if ($c['concours_id'] === $selectedConcoursId) {
        $currentConcours = $c;
        break;
    }
}
if (!$currentConcours && !empty($concoursList)) {
    $currentConcours = $concoursList[0];
}
$concoursId = $currentConcours ? $currentConcours['concours_id'] : 0;

// ========== Construction de l'URL du logo du concours (maintenant que $currentConcours est défini) ==========
$concoursLogoUrl = '';
if ($currentConcours && !empty($currentConcours['url_concours']) && !empty($currentConcours['logo_extension'])) {
    $concoursLogoUrl = STOCKAGE_DOMAIN . '/admin/uploads/logo_concours/' . $currentConcours['url_concours'] . '.' . $currentConcours['logo_extension'] . '?v=' . time();
}


// ========== Récupération des données pour le concours sélectionné ==========
$participantes = [];
$heroCandidates = [];
$allCandidates = [];
$etapes = [];
$candidatesByEtape = [];
$totalVotesAll = 1;

if ($concoursId > 0) {
    $stmtPart = $pdo->prepare("
        SELECT p.participante_id, p.code_participante, p.nom_complet, p.age, p.ville_origine,
               p.niveau_etudes, p.taille_en_cm, p.biographie, p.cause_soutenue,
               p.situation_actuelle, p.inscrite_le, p.modifie_le,
               (SELECT m.photo_officielle 
                FROM medias_participantes m 
                WHERE m.participante_id = p.participante_id 
                ORDER BY m.est_photo_principale DESC, m.ajoute_le DESC 
                LIMIT 1) AS photo_officielle,
               COALESCE(SUM(t.votes_accordes), 0) AS total_votes
        FROM participantes p
        LEFT JOIN transactions_votes t ON p.participante_id = t.participante_id
            AND t.etat_paiement = 'confirme'
        WHERE p.concours_id = ? AND p.situation_actuelle = 'active'
        GROUP BY p.participante_id
        ORDER BY p.code_participante ASC
    ");
    $stmtPart->execute([$concoursId]);
    $participantes = $stmtPart->fetchAll();

    $stmtEtapes = $pdo->prepare("
        SELECT e.etape_id, e.concours_id, e.type_etape_id, e.numero_ordre,
               e.date_ouverture, e.date_cloture, e.votes_actifs, e.etape_terminee,
               e.cree_le, e.modifie_le, t.nom_etape, t.description_etape
        FROM etapes_du_concours e
        JOIN types_etapes t ON e.type_etape_id = t.type_etape_id
        WHERE e.concours_id = ? AND e.etape_terminee = 0
        ORDER BY e.numero_ordre ASC
    ");
    $stmtEtapes->execute([$concoursId]);
    $etapes = $stmtEtapes->fetchAll();

    foreach ($etapes as $etape) {
        $etapeId = $etape['etape_id'];
        $stmt = $pdo->prepare("
            SELECT p.participante_id, p.code_participante, p.nom_complet, p.age, p.ville_origine,
                   p.niveau_etudes, p.taille_en_cm, p.biographie, p.cause_soutenue,
                   p.situation_actuelle, p.inscrite_le, p.modifie_le,
                   (SELECT m.photo_officielle 
                    FROM medias_participantes m 
                    WHERE m.participante_id = p.participante_id 
                    ORDER BY m.est_photo_principale DESC, m.ajoute_le DESC 
                    LIMIT 1) AS photo_officielle,
                   COALESCE(SUM(t.votes_accordes), 0) AS total_votes
            FROM participantes p
            JOIN parcours_participantes pp ON p.participante_id = pp.participante_id
            JOIN etapes_du_concours e ON pp.etape_id = e.etape_id
            LEFT JOIN transactions_votes t ON p.participante_id = t.participante_id
                AND t.etat_paiement = 'confirme'
                AND (t.etape_id = ? OR t.etape_id IS NULL)
            WHERE p.concours_id = ? 
              AND p.situation_actuelle = 'active'
              AND pp.etape_id = ?
              AND e.etape_terminee = 0
            GROUP BY p.participante_id
            ORDER BY p.code_participante ASC
        ");
        $stmt->execute([$etapeId, $concoursId, $etapeId]);
        $candidatesByEtape[$etapeId] = $stmt->fetchAll();
    }

    $validHeroIds = [];
    foreach ($candidatesByEtape as $etapeId => $cands) {
        foreach ($cands as $cand) {
            $validHeroIds[$cand['participante_id']] = true;
        }
    }
    $heroCandidates = [];
    $allCandidates = [];
    foreach ($participantes as $p) {
        if (isset($validHeroIds[$p['participante_id']])) {
            $heroCandidates[] = $p;
            $allCandidates[] = $p;
        }
    }
    $totalVotesAll = 0;
    foreach ($allCandidates as $c) {
        $totalVotesAll += $c['total_votes'];
    }
    if ($totalVotesAll == 0) $totalVotesAll = 1;
}
$totalSlides = count($heroCandidates);

// ========== Gestion des requêtes AJAX (votes_data) ==========
if (isset($_GET['ajax']) && $_GET['ajax'] === 'votes_data') {
    header('Content-Type: application/json');
    $ajaxConcoursId = isset($_GET['concours_id']) ? (int)$_GET['concours_id'] : $concoursId;
    if ($ajaxConcoursId > 0) {
        $stmtRank = $pdo->prepare("
            SELECT p.participante_id, p.code_participante, p.nom_complet,
                   COALESCE(SUM(t.votes_accordes), 0) AS total_votes
            FROM participantes p
            LEFT JOIN transactions_votes t ON p.participante_id = t.participante_id
                AND t.etat_paiement = 'confirme'
            WHERE p.concours_id = ? 
            AND p.situation_actuelle = 'active'
            AND EXISTS (
                SELECT 1 
                FROM parcours_participantes pp
                JOIN etapes_du_concours e ON pp.etape_id = e.etape_id
                WHERE pp.participante_id = p.participante_id
                  AND e.etape_terminee = 0
            )
            GROUP BY p.participante_id
            ORDER BY total_votes DESC
        ");
        $stmtRank->execute([$ajaxConcoursId]);
        $ranking = $stmtRank->fetchAll();
        $totalVotesAllAjax = 0;
        foreach ($ranking as $r) $totalVotesAllAjax += $r['total_votes'];
        if ($totalVotesAllAjax == 0) $totalVotesAllAjax = 1;
        $stmtLatest = $pdo->prepare("
            SELECT t.transaction_id, t.participante_id, t.votes_accordes, t.confirme_le,
                   t.numero_telephone, p.nom_complet, p.code_participante
            FROM transactions_votes t
            JOIN participantes p ON t.participante_id = p.participante_id
            WHERE t.etat_paiement = 'confirme' 
            AND p.concours_id = ?
            AND EXISTS (
                SELECT 1 
                FROM parcours_participantes pp
                JOIN etapes_du_concours e ON pp.etape_id = e.etape_id
                WHERE pp.participante_id = p.participante_id
                  AND e.etape_terminee = 0
            )
            ORDER BY t.confirme_le DESC
            LIMIT 20
        ");
        $stmtLatest->execute([$ajaxConcoursId]);
        $latestVotes = $stmtLatest->fetchAll();
        foreach ($latestVotes as &$vote) {
            $tel = $vote['numero_telephone'] ?? '';
            if (strlen($tel) >= 8) {
                $vote['telephone_masked'] = substr($tel, 0, 4) . '****' . substr($tel, -2);
            } else {
                $vote['telephone_masked'] = '****';
            }
            $vote['date_fr'] = $vote['confirme_le'] ? date('d/m H:i', strtotime($vote['confirme_le'])) : '-';
        }
        unset($vote);
        echo json_encode(['ranking' => $ranking, 'totalVotesAll' => $totalVotesAllAjax, 'latestVotes' => $latestVotes]);
    } else {
        echo json_encode(['ranking' => [], 'totalVotesAll' => 0, 'latestVotes' => []]);
    }
    exit;
}
function esc($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
function getCandidatePhotoUrl($photo_officielle) {
    if (empty($photo_officielle)) return '';
    $p = ltrim($photo_officielle, '/');
    // enlève admin/ si déjà présent pour éviter double
    if (strpos($p, 'admin/') === 0) $p = substr($p, 6);
    // nettoie
    $p = ltrim($p, '/');
    if (strpos($p, 'uploads/') !== 0) {
        $p = 'uploads/' . $p;
    }
    // STOCKAGE_DOMAIN déjà défini globalement
    if (!defined('STOCKAGE_DOMAIN')) return $p;
    return STOCKAGE_DOMAIN . '/admin/' . $p;
}
$auroraYear = '2026';
$auroraTitle = 'MISS AURORA RDC';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Miss Aurora RDC — La beauté au service du changement</title>
<meta name="description" content="Miss Aurora RDC, concours national de beauté, de leadership et d'engagement social organisé par LME GROUP en République Démocratique du Congo. Révéler la lumière qui inspire l'avenir.">
<meta name="theme-color" content="#071A3D">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Miss Aurora RDC">
<meta name="mobile-web-app-capable" content="yes">
<meta name="application-name" content="Miss Aurora RDC">
<link rel="manifest" href="manifest.json">
<?php if($siteLogoUrl): ?>
<link rel="icon" href="<?= esc($siteLogoUrl) ?>">
<link rel="shortcut icon" href="<?= esc($siteLogoUrl) ?>">
<link rel="apple-touch-icon" href="<?= esc($siteLogoUrl) ?>">
<link rel="icon" type="image/png" sizes="192x192" href="<?= esc($siteLogoUrl) ?>">
<link rel="icon" type="image/png" sizes="512x512" href="<?= esc($siteLogoUrl) ?>">
<?php else: ?>
<link rel="icon" type="image/png" sizes="32x32" href="icon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="icon-16.png">
<link rel="shortcut icon" href="favicon.ico">
<link rel="icon" type="image/png" sizes="192x192" href="icon-192.png">
<link rel="icon" type="image/png" sizes="512x512" href="icon-512.png">
<link rel="apple-touch-icon" sizes="180x180" href="icon-192.png">
<?php endif; ?>
<link rel="canonical" href="https://<?= esc($host) ?>/">
<meta property="og:type" content="website">
<meta property="og:title" content="Miss Aurora RDC — La beauté au service du changement">
<meta property="og:description" content="Miss Aurora RDC révèle une nouvelle génération de femmes congolaises capables d'incarner la beauté, le leadership et l'excellence. Par LME GROUP.">
<meta property="og:url" content="https://<?= esc($host) ?>/">
<meta property="og:image" content="<?= $siteLogoUrl ?: 'https://gestion.zaloriatech.com/admin/uploads/sites_logo/default.jpg' ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Miss Aurora RDC — La beauté au service du changement">
<meta name="twitter:description" content="Révéler la lumière qui inspire l'avenir. Concours national organisé par LME GROUP.">

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,600;1,700&family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400;1,600&family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600;700&family=Manrope:wght@400;500;600;700&display=swap" rel="stylesheet">
<script type="application/ld+json">
{
  "@context":"https://schema.org",
  "@type":"Festival",
  "name":"Miss Aurora RDC",
  "description":"Concours national de beauté, de leadership et d'engagement social organisé par LME GROUP en RDC.",
  "organizer":{"@type":"Organization","name":"LME GROUP","address":"40, Avenue Kasangulu, Commune de Kasa-Vubu, Kinshasa, RDC","telephone":"+243860370727","email":"actutara@gmail.com"},
  "location":{"@type":"Place","name":"Kinshasa","address":"Kinshasa, RDC"},
  "slogan":"La beauté au service du changement"
}
</script>
<style>
:root{
  --royal-900:#050B16;
  --royal-800:#071A3D;
  --royal-700:#0B2D6B;
  --royal-600:#123A85;
  --royal-500:#1A4FA8;
  --gold:#D4AF37;
  --gold-light:#F3D77A;
  --gold-mid:#E8C55A;
  --gold-dim:rgba(212,175,55,.12);
  --gold-dim2:rgba(212,175,55,.18);
  --gold-border:rgba(212,175,55,.28);
  --ivory:#F8F5ED;
  --ivory-2:#FFFCF2;
  --white:#FFFFFF;
  --muted:rgba(255,255,255,.62);
  --muted2:rgba(255,255,255,.42);
  --muted3:rgba(255,255,255,.32);
  --line:rgba(255,255,255,.08);
  --line-dark:rgba(5,11,22,.08);
  --shadow:0 20px 60px rgba(5,11,22,.18);
  --shadow-gold:0 12px 40px rgba(212,175,55,.25);
  --radius:20px;
  --radius-sm:12px;
  --font-serif:'Cormorant Garamond',serif;
  --font-display:'Playfair Display',serif;
  --font-sans:'Outfit',sans-serif;
  --font-ui:'Inter',sans-serif;
}
*{margin:0;padding:0;box-sizing:border-box}
html{scroll-behavior:smooth}
body{font-family:var(--font-sans);background:var(--royal-900);color:#fff;-webkit-font-smoothing:antialiased;overflow-x:hidden}
a{color:inherit;text-decoration:none}
img{max-width:100%;display:block}
button{font-family:inherit}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
/* ========== HEADER — SOBRE AIRBNB PREMIUM ========== */
.aurora-header{
  position:fixed;top:0;left:0;right:0;z-index:1000;
  height:60px;
  background:#FFFFFF;
  border-bottom:1px solid #EBEBEB;
  transition:box-shadow .2s, border-color .2s;
}
.aurora-header.is-sticky{
  background:#FFFFFF;
  border-bottom-color:#E4E4E4;
  box-shadow:0 1px 2px rgba(0,0,0,.06), 0 4px 12px rgba(0,0,0,.04);
}
.aurora-header__inner{
  max-width:1120px;margin:0 auto;padding:0 24px;height:100%;
  display:flex;align-items:center;justify-content:space-between;gap:20px;
}
.aurora-header__left{display:flex;align-items:center;gap:24px;min-width:0}
.aurora-logo{display:flex;align-items:center;gap:10px;flex-shrink:0}
.aurora-logo__mark{
  width:36px;height:36px;border-radius:8px;
  background:#FFFFFF;border:1px solid #DDDDDD;
  display:flex;align-items:center;justify-content:center;
  color:var(--royal-800);flex-shrink:0;
}
.aurora-logo__mark svg{width:18px;height:18px;stroke-width:1.7}
.aurora-logo__text{display:flex;flex-direction:column;line-height:1}
.aurora-logo__title{font-family:var(--font-sans);font-weight:700;font-size:.92rem;letter-spacing:.04em;color:#222222;line-height:1}
.aurora-logo__title span{font-weight:600;color:#222222}
.aurora-logo__sub{font-family:var(--font-sans);font-size:.62rem;font-weight:500;letter-spacing:.08em;text-transform:uppercase;color:#717171;margin-top:2px}
.aurora-nav{display:flex;align-items:center;gap:1px;list-style:none}
.aurora-nav__link{
  display:inline-flex;align-items:center;gap:7px;
  font-family:var(--font-ui);font-size:.8125rem;font-weight:500;letter-spacing:-.01em;
  color:#222222;padding:7px 11px;border-radius:7px;
  transition:background .16s, color .16s;white-space:nowrap;line-height:1;
}
.aurora-nav__link svg{width:15px;height:15px;stroke-width:1.7;flex-shrink:0;color:#717171;transition:color .16s}
.aurora-nav__link:hover{background:#F7F7F7;color:#222222}
.aurora-nav__link:hover svg{color:#222222}
.aurora-nav__link.is-active{background:#F7F7F7;color:#222222;font-weight:600}
.aurora-nav__link.is-active svg{color:#222222}
.aurora-nav__link.is-active::after{content:'';position:absolute;left:14px;right:14px;bottom:4px;height:1.5px;background:var(--royal-800);border-radius:1px;display:none}
.aurora-nav__has-sub{position:relative}
.aurora-sub{
  position:absolute;top:calc(100% + 12px);left:50%;transform:translateX(-50%) translateY(-6px);
  width:300px;background:#FFFFFF;border:1px solid #DDDDDD;
  border-radius:12px;padding:8px;box-shadow:0 8px 28px rgba(0,0,0,.12);
  opacity:0;pointer-events:none;transition:all .2s;z-index:500;
}
.aurora-nav__has-sub:hover .aurora-sub{opacity:1;pointer-events:all;transform:translateX(-50%) translateY(0)}
.aurora-sub__head{font-family:var(--font-ui);font-size:.68rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#717171;padding:8px 8px 10px;border-bottom:1px solid #EBEBEB;margin-bottom:6px;display:block}
.aurora-sub__item{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-radius:8px;color:#222222;font-family:var(--font-ui);font-size:.875rem;font-weight:400;transition:background .15s}
.aurora-sub__item:hover{background:#F7F7F7}
.aurora-sub__badge{font-size:.68rem;font-weight:600;letter-spacing:.02em;text-transform:uppercase;padding:2px 8px;border-radius:999px;background:#DEF7EC;color:#057A55;border:1px solid #A7F3D0}
.aurora-header__right{display:flex;align-items:center;gap:12px;flex-shrink:0}
.aurora-lang{position:relative}
.aurora-lang__btn{display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border-radius:20px;border:1px solid #DDDDDD;background:#FFFFFF;color:#222222;font-family:var(--font-ui);font-size:.8125rem;font-weight:500;cursor:pointer;transition:border-color .18s, background .18s}
.aurora-lang__btn:hover{border-color:#222222}
.aurora-lang__menu{position:absolute;top:calc(100% + 8px);right:0;min-width:160px;background:#FFFFFF;border:1px solid #DDDDDD;border-radius:12px;padding:6px;box-shadow:0 8px 28px rgba(0,0,0,.12);opacity:0;pointer-events:none;transform:translateY(-6px);transition:all .18s;z-index:600}
.aurora-lang__menu.is-open{opacity:1;pointer-events:all;transform:translateY(0)}
.aurora-lang__opt{width:100%;text-align:left;padding:9px 12px;border-radius:8px;border:none;background:transparent;color:#222222;font-family:var(--font-ui);font-size:.875rem;font-weight:400;cursor:pointer;transition:background .15s;display:flex;align-items:center;gap:8px}
.aurora-lang__opt:hover{background:#F7F7F7}
.aurora-lang__opt.is-active{background:#F7F7F7;font-weight:600}
.aurora-cta{display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:8px;background:var(--royal-800);color:#FFFFFF;font-family:var(--font-ui);font-size:.875rem;font-weight:600;letter-spacing:-.01em;transition:background .18s;white-space:nowrap;border:none}
.aurora-cta:hover{background:var(--royal-700)}
.aurora-burger{display:none;align-items:center;justify-content:center;width:40px;height:40px;border-radius:8px;border:1px solid #DDDDDD;background:#FFFFFF;color:#222222;cursor:pointer;transition:border-color .18s, background .18s}
.aurora-burger:hover{border-color:#222222}
.aurora-burger__icon{width:16px;height:16px;stroke-width:1.7}
.aurora-burger span{display:block;width:18px;height:1.5px;background:#222222;border-radius:1px;transition:transform .24s, opacity .24s;transform-origin:center}
.aurora-burger.is-open span:nth-child(1){transform:translateY(5.5px) rotate(45deg)}
.aurora-burger.is-open span:nth-child(2){opacity:0;transform:scaleX(0)}
.aurora-burger.is-open span:nth-child(3){transform:translateY(-5.5px) rotate(-45deg)}
.aurora-drawer{
  position:fixed;top:0;right:-100%;width:min(340px,88vw);height:100dvh;
  background:#FFFFFF;border-left:1px solid #EBEBEB;padding:64px 18px 20px;z-index:999;
  transition:right .26s cubic-bezier(.32,0,.67,0);overflow-y:auto;display:flex;flex-direction:column;gap:18px;
  box-shadow:-8px 0 32px rgba(0,0,0,.08);
}
.aurora-drawer.is-open{right:0}
.aurora-drawer__head{display:flex;align-items:center;justify-content:space-between;padding:0 4px 12px;border-bottom:1px solid #EBEBEB}
.aurora-drawer__close{width:32px;height:32px;border-radius:50%;border:1px solid #DDDDDD;background:#FFFFFF;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:border-color .18s}
.aurora-drawer__close:hover{border-color:#222222}
.aurora-drawer__nav{list-style:none;display:flex;flex-direction:column;gap:2px}
.aurora-drawer__link{display:flex;align-items:center;justify-content:space-between;padding:11px 11px;border-radius:8px;color:#222222;font-family:var(--font-ui);font-size:.875rem;font-weight:400;transition:background .15s;gap:10px}
.aurora-drawer__link-left{display:flex;align-items:center;gap:10px;flex:1;min-width:0}
.aurora-drawer__link-left svg{width:16px;height:16px;stroke-width:1.7;flex-shrink:0;color:#717171}
.aurora-drawer__link:hover .aurora-drawer__link-left svg{color:#222222}
.aurora-drawer__link.is-active .aurora-drawer__link-left svg{color:#222222}
.aurora-drawer__link:hover{background:#F7F7F7}
.aurora-drawer__link.is-active{background:#F7F7F7;font-weight:600}
.aurora-drawer__sub{list-style:none;padding-left:12px;margin:4px 0 8px;display:none;flex-direction:column;gap:2px;border-left:1px solid #EBEBEB;margin-left:12px}
.aurora-drawer__sub.is-open{display:flex}
.aurora-drawer__sub-link{padding:9px 12px;border-radius:8px;color:#717171;font-family:var(--font-ui);font-size:.875rem;transition:background .15s, color .15s}
.aurora-drawer__sub-link:hover{background:#F7F7F7;color:#222222}
.aurora-drawer__foot{margin-top:auto;padding-top:16px;border-top:1px solid #EBEBEB;display:flex;flex-direction:column;gap:12px}
.aurora-drawer__cta{width:100%;justify-content:center;padding:12px 16px;border-radius:8px;background:var(--royal-800);color:#FFFFFF;font-family:var(--font-ui);font-size:.875rem;font-weight:600;text-align:center;transition:background .18s}
.aurora-drawer__cta:hover{background:var(--royal-700)}
.aurora-overlay{position:fixed;inset:0;background:rgba(0,0,0,.32);opacity:0;pointer-events:none;transition:opacity .2s;z-index:998}
.aurora-overlay.is-open{opacity:1;pointer-events:all}
@media(max-width:1120px){.aurora-nav{display:none}.aurora-cta--desktop{display:none}.aurora-burger{display:flex}}
@media(max-width:640px){.aurora-header{height:56px}.aurora-header__inner{padding:0 14px;gap:12px}.aurora-logo__title{font-size:.82rem}.aurora-logo__sub{font-size:.56rem}}

/* ========== HERO — MODERN EDITORIAL INSPIRED MISS LUALABA × AURORA PREMIUM ========== */
.aurora-hero{
  position:relative;overflow:hidden;background:var(--royal-900);
  padding-top:60px;border-bottom:1px solid rgba(212,175,55,.08);
}
.aurora-hero__bg{position:absolute;inset:0;z-index:0}
.aurora-hero__bg-gradient{
  position:absolute;inset:0;
  background:
    radial-gradient(900px 700px at 14% 18%, rgba(212,175,55,.14) 0%, transparent 58%),
    radial-gradient(700px 520px at 88% 88%, rgba(18,58,133,.30) 0%, transparent 62%),
    linear-gradient(180deg, #050B16 0%, #071A3D 52%, #0B2D6B 100%);
}
.aurora-hero__bg-texture{
  position:absolute;inset:0;opacity:.05;
  background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='3'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)'/%3E%3C/svg%3E");
}
.aurora-hero__bg-slides{position:absolute;inset:0;overflow:hidden}
.aurora-hero__bg-slide{
  position:absolute;inset:0;background-size:cover;background-position:center 22%;
  opacity:0;transform:scale(1.08);transition:opacity 1.2s ease, transform 8.5s cubic-bezier(.25,.46,.45,.94);
  filter:brightness(.72) saturate(1.05) contrast(1.05);
}
.aurora-hero__bg-slide.is-active{opacity:1;transform:scale(1)}
.aurora-hero__bg-overlay{
  position:absolute;inset:0;
  background:
    linear-gradient(90deg, rgba(5,11,22,.72) 0%, rgba(5,11,22,.52) 38%, rgba(5,11,22,.28) 62%, rgba(5,11,22,.12) 100%),
    linear-gradient(180deg, rgba(5,11,22,.10) 0%, rgba(5,11,22,.42) 100%);
}
@media(max-width:1024px){
  .aurora-hero__bg-slide{filter:brightness(.64) saturate(1.02)}
  .aurora-hero__bg-overlay{
    background:
      linear-gradient(180deg, rgba(5,11,22,.08) 0%, rgba(5,11,22,.58) 54%, rgba(5,11,22,.82) 100%),
      linear-gradient(90deg, rgba(5,11,22,.56) 0%, rgba(5,11,22,.22) 100%);
  }
}
.aurora-hero__shell{
  position:relative;z-index:2;max-width:1120px;margin:0 auto;
  display:grid;grid-template-columns:1.08fr .92fr;gap:36px;align-items:center;
  padding:44px 24px 44px;
}
.aurora-hero__left{display:flex;flex-direction:column;gap:0}
.aurora-hero__eyebrow{
  display:inline-flex;align-items:center;gap:10px;
  background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.13);
  backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);
  padding:7px 14px;border-radius:100px;width:fit-content;margin-bottom:18px;
  box-shadow:0 10px 32px rgba(0,0,0,.22), 0 2px 8px rgba(0,0,0,.14), inset 0 1px 0 rgba(255,255,255,.08);
}
.aurora-hero__eyebrow-concours{
  font-family:var(--font-ui);font-size:.62rem;font-weight:700;letter-spacing:.13em;text-transform:uppercase;
  color:#F3D77A;white-space:nowrap;line-height:1;
}
.aurora-hero__eyebrow-divider{width:1px;height:18px;background:rgba(255,255,255,.14);flex-shrink:0;margin:0 1px}
.aurora-hero__eyebrow-status{
  font-family:var(--font-ui);font-size:.62rem;font-weight:700;letter-spacing:.13em;text-transform:uppercase;
  color:rgba(255,255,255,.78);white-space:nowrap;line-height:1;
}
.aurora-hero__lockup{
  display:flex;align-items:center;gap:16px;margin-bottom:2px;
}
.aurora-hero__lockup-logo{
  width:64px;height:64px;border-radius:14px;background:#FFFFFF;border:1px solid rgba(0,0,0,.06);
  display:inline-flex;align-items:center;justify-content:center;flex-shrink:0;
  box-shadow:0 8px 28px rgba(0,0,0,.22), 0 2px 8px rgba(0,0,0,.14);overflow:hidden;padding:5px;
}
.aurora-hero__lockup-logo img{width:54px;height:54px;object-fit:contain;display:block;border-radius:8px}
@media(max-width:640px){
  .aurora-hero__lockup{ gap:12px; }
  .aurora-hero__lockup-logo{ width:52px;height:52px;border-radius:12px;padding:4px; }
  .aurora-hero__lockup-logo img{ width:44px;height:44px; }
  .aurora-hero__eyebrow{ padding:6px 12px; gap:8px; }
}
.aurora-hero__title{
  font-family:var(--font-serif);font-weight:300;line-height:.86;letter-spacing:-.04em;
  font-size:clamp(2.8rem, 4.8vw, 4.8rem);color:#fff;margin:0;
  text-shadow:0 2px 28px rgba(0,0,0,.42), 0 1px 0 rgba(255,255,255,.06);
}
.aurora-hero__title strong{
  font-weight:700;font-style:italic;color:var(--gold-light);display:block;margin-top:4px;
  letter-spacing:-.02em;text-shadow:0 2px 18px rgba(212,175,55,.28);
}
.aurora-hero__title strong span{font-family:var(--font-serif);font-weight:300;font-style:normal;letter-spacing:-.02em;text-transform:none;font-size:1em;color:#fff;display:inline;margin:0}
.aurora-hero__title > span{
  font-family:var(--font-sans);font-weight:800;letter-spacing:.18em;text-transform:uppercase;
  font-size:.42em;color:rgba(255,255,255,.88);display:block;margin-bottom:6px;
}
.aurora-hero__devise{
  display:flex;align-items:center;gap:12px;margin:16px 0 10px;
}
.aurora-hero__devise-line{width:28px;height:1.5px;background:var(--gold);border-radius:2px;flex-shrink:0}
.aurora-hero__devise-text{font-family:var(--font-display);font-style:italic;font-size:.92rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:var(--gold-light)}
.aurora-hero__desc{
  font-family:var(--font-sans);font-size:clamp(.88rem, 1.05vw, .96rem);font-weight:300;line-height:1.8;color:rgba(255,255,255,.78);
  max-width:520px;margin:14px 0 26px;letter-spacing:.01em;
  text-shadow:0 1px 12px rgba(0,0,0,.32);
}
.aurora-hero__desc strong{color:#fff;font-weight:700}
@media(max-width:640px){
  .aurora-hero__desc{font-size:.86rem;line-height:1.7;color:rgba(255,255,255,.82)}
}
.aurora-hero__actions{display:flex;gap:10px;flex-wrap:wrap}
.aurora-hero__meta{
  display:flex;flex-wrap:wrap;gap:14px;margin-top:26px;padding-top:20px;border-top:1px solid rgba(255,255,255,.08);
}
.aurora-hero__meta-item{display:flex;align-items:center;gap:10px}
.aurora-hero__meta-icon{width:32px;height:32px;border-radius:8px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;color:var(--gold-light);flex-shrink:0}
.aurora-hero__meta-label{font-size:.62rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.42);display:block;line-height:1}
.aurora-hero__meta-value{font-size:.78rem;font-weight:600;color:#fff;display:block;margin-top:3px;line-height:1}
.aurora-hero__right{position:relative;display:flex;flex-direction:column;gap:14px}
.hero-cc{
  position:relative;max-width:480px;width:100%;margin-left:auto;
  border-radius:20px;overflow:hidden;
  background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.10);
  backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px);
  box-shadow:0 18px 48px rgba(0,0,0,.32), inset 0 1px 0 rgba(255,255,255,.08);
}
.hero-cc__viewport{position:relative;overflow:hidden;aspect-ratio:3/4.2;background:#071A3D;max-height:680px}
.hero-cc__track{display:flex;transition:transform .6s cubic-bezier(.32,0,.67,0);will-change:transform;height:100%}
.hero-cc__slide{flex:0 0 100%;position:relative;overflow:hidden;background:#071A3D;display:flex;align-items:center;justify-content:center}
.hero-cc__bg{position:absolute;inset:-12%;background-size:cover;background-position:center 20%;filter:blur(22px) brightness(.72) saturate(1.2);transform:scale(1.12);opacity:.85;z-index:0;transition:opacity .5s}
.hero-cc__slide img{position:relative;z-index:1;width:100%;height:100%;object-fit:contain;object-position:center top;background:transparent;transition:transform .8s, filter .4s, opacity .45s;filter:saturate(1.03) brightness(1.02);opacity:0}
.hero-cc__slide img.is-loaded{opacity:1}
.hero-cc__slide.is-active img{transform:scale(1.01)}
.hero-cc__fade{position:absolute;inset:0;background:linear-gradient(180deg, rgba(5,11,22,.02) 8%, rgba(5,11,22,.06) 36%, rgba(5,11,22,.88) 92%);z-index:2;pointer-events:none}
.hero-cc__info{position:absolute;left:14px;right:14px;bottom:14px;z-index:3;display:flex;flex-direction:column;gap:6px}
.hero-cc__name{font-family:var(--font-serif);font-size:1.22rem;font-weight:700;color:#fff;line-height:1.1;text-shadow:0 2px 12px rgba(0,0,0,.4)}
.hero-cc__meta{display:flex;flex-wrap:wrap;gap:6px}
.hero-cc__chip{display:inline-flex;align-items:center;gap:5px;padding:4px 9px;border-radius:100px;background:rgba(5,11,22,.48);border:1px solid rgba(255,255,255,.14);backdrop-filter:blur(8px);color:#fff;font-family:var(--font-ui);font-size:.62rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase}
.hero-cc__chip-dot{width:5px;height:5px;border-radius:50%;background:var(--gold);box-shadow:0 0 8px rgba(212,175,55,.6)}
.hero-cc__nav{position:absolute;top:12px;right:12px;z-index:4;display:flex;gap:6px}
.hero-cc__btn{width:32px;height:32px;border-radius:10px;border:1px solid rgba(255,255,255,.14);background:rgba(5,11,22,.42);backdrop-filter:blur(10px);color:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:background .18s, transform .16s}
.hero-cc__btn:hover{background:rgba(255,255,255,.14);transform:translateY(-1px)}
.hero-cc__dots{position:absolute;bottom:14px;left:50%;transform:translateX(-50%);z-index:4;display:flex;gap:6px;padding:6px 10px;border-radius:100px;background:rgba(5,11,22,.38);border:1px solid rgba(255,255,255,.10);backdrop-filter:blur(10px)}
.hero-cc__dot{width:6px;height:6px;border-radius:50%;background:rgba(255,255,255,.36);transition:all .22s;cursor:pointer;border:none;padding:0}
.hero-cc__dot.is-active{width:18px;background:var(--gold);box-shadow:0 0 10px rgba(212,175,55,.5)}
.hero-cc__progress{position:absolute;left:0;right:0;bottom:0;height:2px;background:rgba(255,255,255,.12);z-index:4}
.hero-cc__progress-fill{height:100%;width:0%;background:linear-gradient(90deg, var(--gold), var(--gold-light));transition:width .1s linear}
/* SKELETON APP-LIKE FOR HERO */
.hero-cc__skeleton{position:absolute;inset:0;background:linear-gradient(90deg, #0B1E42 25%, #123063 37%, #0B1E42 63%);background-size:400% 100%;animation:heroShine 1.4s ease infinite;z-index:1}
@keyframes heroShine{0%{background-position:100% 50%}100%{background-position:0% 50%}}
/* keep old mosaic selectors for backwards compat (hidden) */
.aurora-hero__mosaic{display:none!important}
.aurora-hero__tile{display:none!important}
.aurora-hero__live{
  max-width:460px;width:100%;margin-left:auto;
  display:flex;align-items:center;justify-content:space-between;gap:12px;
  padding:10px 12px;border-radius:12px;background:rgba(5,11,22,.42);border:1px solid rgba(255,255,255,.08);backdrop-filter:blur(10px);
}
.aurora-hero__live-left{display:flex;align-items:center;gap:10px;min-width:0}
.aurora-hero__live-dot{width:8px;height:8px;border-radius:50%;background:#10b981;box-shadow:0 0 10px rgba(16,185,129,.6);flex-shrink:0;animation:au-pulse 1.6s infinite}
.aurora-hero__live-label{font-family:var(--font-ui);font-size:.66rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.58)}
.aurora-hero__live-title{font-family:var(--font-ui);font-size:.78rem;font-weight:600;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.aurora-hero__live-progress{flex:1;max-width:90px;height:3px;background:rgba(255,255,255,.12);border-radius:999px;overflow:hidden}
.aurora-hero__live-fill{height:100%;background:linear-gradient(90deg, var(--gold), var(--gold-light));border-radius:999px;transition:width .5s}
.aurora-hero__ghost{
  position:absolute;right:14px;bottom:10px;z-index:1;
  font-family:var(--font-serif);font-size:4.6rem;font-weight:700;font-style:italic;
  color:rgba(255,255,255,.035);pointer-events:none;user-select:none;letter-spacing:.04em;line-height:1;display:none;
}
@media(max-width:1024px){
  .aurora-hero__shell{grid-template-columns:1fr;gap:22px;padding:28px 20px 28px}
  .hero-cc{margin-left:0;max-width:560px}
  .aurora-hero__live{margin-left:0;max-width:560px}
  .aurora-hero__meta{gap:12px}
}
@media(max-width:640px){
  .aurora-hero__shell{padding:22px 16px 22px;gap:18px}
  .aurora-hero__title{font-size:clamp(2.2rem, 9vw, 3.1rem)}
  .aurora-hero__desc{font-size:.84rem;margin:10px 0 18px}
  .aurora-hero__actions{flex-direction:column}
  .aurora-hero__actions a{width:100%;justify-content:center}
  .hero-cc{max-width:100%;border-radius:16px}
  .hero-cc__viewport{aspect-ratio:3/4.2;max-height:72vh}
  .hero-cc__name{font-size:1.05rem}
  .aurora-hero__meta{flex-direction:column;align-items:flex-start;gap:10px;padding-top:16px;margin-top:18px}
  .aurora-hero__live{padding:9px 10px}
}
@media(min-width:1120px){.aurora-hero__shell{padding-left:0;padding-right:0}}

/* ========== TICKER ========== */
.aurora-ticker{
  position:relative;background:#000000;border-top:1px solid #1A1A1A;border-bottom:1px solid #1A1A1A;
  overflow:hidden;width:100%;
}
.aurora-ticker::before{display:none}
.aurora-ticker__inner{max-width:none;width:100%;margin:0;padding:8px 0;display:flex;flex-direction:column;gap:8px;position:relative;z-index:1}
.aurora-ticker__row{
  display:flex;align-items:stretch;gap:0;overflow:hidden;
  height:44px;background:#0A0A0A;border-top:1px solid #1F1F1F;border-bottom:1px solid #1F1F1F;border-left:none;border-right:none;border-radius:0;
  font-size:.80rem;position:relative;width:100%;margin:0;box-shadow:none;
}
.aurora-ticker__label-wrap{
  flex-shrink:0;display:flex;align-items:center;
  padding:0 16px;height:100%;
  background:#0A0A0A;border-right:1px solid #1F1F1F;
  position:relative;z-index:3;
  box-shadow:8px 0 16px -10px rgba(0,0,0,.9);
}
.aurora-ticker__label{
  flex-shrink:0;display:inline-flex;align-items:center;gap:7px;
  padding:6px 11px;border-radius:100px;
  font-size:.60rem;font-weight:800;letter-spacing:.05em;text-transform:uppercase;
  color:#FFFFFF;background:#1A1A1A;border:1px solid #2A2A2A;box-shadow:0 1px 4px rgba(0,0,0,.4);
  line-height:1;white-space:nowrap;
}
.aurora-ticker__label svg{width:13px;height:13px;stroke-width:1.7;flex-shrink:0;color:#D4AF37}
.aurora-ticker__label--live{background:#0F1F17;color:#6EE7B7;border-color:#14532D;box-shadow:none}
.aurora-ticker__label--live svg{color:#10b981}
.aurora-ticker__label:not(.aurora-ticker__label--live) svg{color:#D4AF37}
.aurora-ticker__label--live .aurora-ticker__label-dot{background:#10b981}
.aurora-ticker__label:not(.aurora-ticker__label--live) .aurora-ticker__label-dot{background:#D4AF37}
.aurora-ticker__label-dot{width:6px;height:6px;border-radius:50%;background:currentColor;animation:au-pulse 1.4s infinite;flex-shrink:0}
.aurora-ticker__viewport{
  flex:1;min-width:0;overflow:hidden;position:relative;
  display:flex;align-items:center;height:100%;
  background:#0A0A0A;
  mask-image:linear-gradient(to right, transparent 0, black 14px, black calc(100% - 14px), transparent 100%);
  -webkit-mask-image:linear-gradient(to right, transparent 0, black 14px, black calc(100% - 14px), transparent 100%);
}
.aurora-ticker__track{display:inline-flex;align-items:center;gap:0;white-space:nowrap;will-change:transform;animation:au-scroll 42s linear infinite; padding-left:12px}
.aurora-ticker__track:hover{animation-play-state:paused}
@keyframes au-scroll{0%{transform:translateX(0)}100%{transform:translateX(-50%)}}
.aurora-ticker__item{display:inline-flex;align-items:center;gap:7px;padding-right:28px;color:#9CA3AF;font-size:.80rem;font-weight:400;white-space:nowrap}
.aurora-ticker__item svg{width:14px;height:14px;stroke-width:1.7;flex-shrink:0;color:#6B7280}
.aurora-ticker__item .hl{color:#FFFFFF;font-weight:700}
.aurora-ticker__item .gold{color:#F3D77A;font-weight:700}
.aurora-ticker__item-icon{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:50%;background:#1A1A1A;border:1px solid #2A2A2A;flex-shrink:0;color:#D4AF37}
.aurora-ticker__sep{color:#2A2A2A;margin:0 12px;font-size:.55rem}
@media(max-width:768px){
  .aurora-ticker__inner{padding:6px 0;gap:6px}
  .aurora-ticker__row{height:40px}
  .aurora-ticker__label-wrap{padding:0 10px}
  .aurora-ticker__label{padding:5px 9px;font-size:.56rem;gap:6px}
  .aurora-ticker__label svg{width:12px;height:12px}
  .aurora-ticker__item{padding-right:20px;font-size:.74rem;gap:6px}
  .aurora-ticker__viewport{mask-image:linear-gradient(to right, transparent 0, black 8px, black 92%, transparent 100%)}
}
@media(min-width:1120px){.aurora-ticker__inner{padding:8px 0}}

/* ========== SECTION COMMON ========== */
.au-section{position:relative;padding:96px 40px;overflow:hidden}
.au-section--light{background:var(--ivory);color:var(--royal-900)}
.au-section--dark{background:var(--royal-900);color:#fff}
.au-section--ivory{background:var(--ivory-2);color:var(--royal-900)}
.au-wrap{max-width:1240px;margin:0 auto;position:relative;z-index:2}
.au-eyebrow{display:inline-flex;align-items:center;gap:10px;font-family:var(--font-sans);font-size:.68rem;font-weight:700;letter-spacing:.18em;text-transform:uppercase;color:var(--gold);margin-bottom:14px}
.au-eyebrow::before{content:'';width:28px;height:1.5px;background:var(--gold);border-radius:2px}
.au-eyebrow--center{justify-content:center;width:100%}
.au-eyebrow--center::before,.au-eyebrow--center::after{content:'';width:28px;height:1px;background:var(--gold)}
.au-eyebrow--center::after{display:block}
.au-section--dark .au-eyebrow{color:var(--gold-light)}
.au-title{font-family:var(--font-serif);font-size:clamp(2.1rem, 3.6vw, 3.4rem);font-weight:300;line-height:1.02;letter-spacing:-.02em;color:var(--royal-900);margin-bottom:14px}
.au-title em{font-style:italic;font-weight:700;color:var(--gold)}
.au-section--dark .au-title{color:#fff}
.au-section--dark .au-title em{color:var(--gold-light)}
.au-subtitle{font-family:var(--font-sans);font-size:.96rem;font-weight:300;line-height:1.7;color:#5b6577;max-width:640px}
.au-section--dark .au-subtitle{color:rgba(255,255,255,.58)}
.au-bar{width:44px;height:2.5px;background:linear-gradient(90deg, var(--gold), var(--gold-light));border-radius:2px;margin:16px 0 18px}
.au-bar--center{margin:16px auto 18px}
.au-head--center{text-align:center;display:flex;flex-direction:column;align-items:center}
.au-head--center .au-subtitle{text-align:center}
@media(max-width:768px){.au-section{padding:56px 16px}.au-title{font-size:clamp(1.8rem, 6.5vw, 2.2rem)}.au-wrap{max-width:100%}}
@media(min-width:1400px){.au-section{padding:110px 72px}}
@media(min-width:1700px){.au-section{padding:110px 120px}}

/* ========== AURORA SYMBOLIQUE ========== */
.aurora-why{position:relative}
.aurora-why__grid{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin-top:44px}
.aurora-why__card{
  position:relative;background:#fff;border:1px solid rgba(5,11,22,.06);border-radius:20px;padding:28px 22px 24px;overflow:hidden;
  transition:all .3s;cursor:default;
}
.aurora-why__card:hover{transform:translateY(-6px);box-shadow:0 16px 44px rgba(5,11,22,.08);border-color:rgba(212,175,55,.22)}
.aurora-why__card::before{content:'';position:absolute;top:-40px;right:-40px;width:140px;height:140px;border-radius:50%;background:radial-gradient(circle, rgba(212,175,55,.08), transparent 70%);pointer-events:none}
.aurora-why__num{position:absolute;top:16px;right:18px;font-family:var(--font-serif);font-size:2.6rem;font-weight:700;color:rgba(7,26,61,.06);line-height:1}
.aurora-why__icon{width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;color:#fff;margin-bottom:18px;transition:transform .3s}
.aurora-why__card:hover .aurora-why__icon{transform:scale(1.08) rotate(-4deg)}
.aurora-why__icon--light{background:linear-gradient(135deg, #F3D77A, #C9A227);box-shadow:0 8px 20px rgba(212,175,55,.32)}
.aurora-why__icon--hope{background:linear-gradient(135deg, #60A5FA, #0B2D6B);box-shadow:0 8px 20px rgba(11,45,107,.32)}
.aurora-why__icon--emerge{background:linear-gradient(135deg, #34D399, #065F46);box-shadow:0 8px 20px rgba(5,150,105,.32)}
.aurora-why__icon--future{background:linear-gradient(135deg, #F472B6, #7C1D3A);box-shadow:0 8px 20px rgba(124,29,58,.28)}
.aurora-why__title{font-family:var(--font-serif);font-size:1.25rem;font-weight:700;color:var(--royal-900);margin-bottom:8px;letter-spacing:-.01em}
.aurora-why__text{font-family:var(--font-sans);font-size:.86rem;font-weight:300;line-height:1.7;color:#5b6577}
.aurora-why__line{position:absolute;bottom:0;left:0;right:0;height:2px;background:var(--gold);transform:scaleX(0);transform-origin:left;transition:transform .35s}
.aurora-why__card:hover .aurora-why__line{transform:scaleX(1)}
@media(max-width:1024px){.aurora-why__grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:560px){.aurora-why__grid{grid-template-columns:1fr}}

/* ========== ABOUT ========== */
.aurora-about{display:grid;grid-template-columns:1.08fr .92fr;gap:64px;align-items:start;margin-top:44px;position:relative}
.aurora-about::before{content:'AURORA';position:absolute;left:-12px;top:-18px;font-family:var(--font-serif);font-size:6.5rem;font-weight:700;font-style:italic;color:rgba(7,26,61,.035);letter-spacing:-.03em;pointer-events:none;user-select:none;line-height:1}
.aurora-about__left{display:flex;flex-direction:column;gap:18px;position:relative;z-index:1}
.aurora-about__text{font-family:var(--font-sans);font-size:.94rem;font-weight:300;line-height:1.9;color:#334155;letter-spacing:.01em}
.aurora-about__text strong{font-weight:700;color:var(--royal-800)}
.aurora-about__quote{
  position:relative;margin:22px 0 0;padding:22px 24px 22px 28px;
  background:#FFFFFF;border:1px solid #EBEBEB;border-radius:12px;
  box-shadow:none;overflow:hidden;
}
.aurora-about__quote::before{
  content:'';position:absolute;left:0;top:12px;bottom:12px;width:2.5px;
  background:var(--gold);border-radius:0 2px 2px 0;
}
.aurora-about__quote-text{
  margin:0;font-family:var(--font-display);font-style:italic;font-size:1.05rem;line-height:1.7;color:#1A1A1A;
  letter-spacing:-.01em;position:relative;z-index:1;
}
.aurora-about__quote-mark{
  position:absolute;right:16px;top:8px;font-family:var(--font-serif);font-size:3.8rem;line-height:1;
  color:rgba(212,175,55,.08);font-style:normal;font-weight:700;pointer-events:none;user-select:none;
}
.aurora-about__values{
  display:grid;grid-template-columns:repeat(3,1fr);gap:0;
  margin-top:14px;background:#FFFFFF;border:1px solid #EBEBEB;border-radius:12px;
  overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.04);
}
.aurora-about__val{
  padding:18px 12px 16px;background:#FFFFFF;
  text-align:center;position:relative;transition:background .16s;
  border-right:1px solid #EBEBEB;
}
.aurora-about__val:last-child{border-right:none}
.aurora-about__val:hover{background:#FAFAFA}
.aurora-about__val-icon{
  width:28px;height:28px;margin:0 auto 10px;border-radius:8px;
  background:#F7F7F7;border:1px solid #EBEBEB;
  display:flex;align-items:center;justify-content:center;color:#717171;
}
.aurora-about__val-num{font-family:var(--font-sans);font-size:1.38rem;font-weight:700;color:#111111;line-height:1;letter-spacing:-.02em}
.aurora-about__val-label{font-family:var(--font-ui);font-size:.58rem;font-weight:600;letter-spacing:.10em;text-transform:uppercase;color:#717171;margin-top:6px}
.aurora-about__visual{position:relative;display:grid;grid-template-columns:1fr 1fr;gap:16px;isolation:isolate}
.aurora-about__visual::before{content:'';position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:320px;height:320px;border-radius:50%;background:radial-gradient(circle, rgba(212,175,55,.08), transparent 70%);border:1px solid rgba(212,175,55,.10);pointer-events:none;filter:blur(.5px)}
.aurora-about__col{display:flex;flex-direction:column;gap:16px;position:relative;z-index:1}
.aurora-about__col--offset{margin-top:36px}
.aurora-about__img{position:relative;border-radius:14px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05), 0 4px 12px rgba(0,0,0,.05);background:#e8eef8;transition:transform .18s, box-shadow .18s; border:1px solid #EBEBEB}
.aurora-about__img:hover{transform:translateY(-2px);box-shadow:0 4px 16px rgba(0,0,0,.07), 0 8px 24px rgba(0,0,0,.04)}
.aurora-about__img img{width:100%;height:100%;object-fit:cover;transition:transform .7s cubic-bezier(.25,.46,.45,.94);filter:saturate(.98)}
.aurora-about__img:hover img{transform:scale(1.06);filter:saturate(1.03)}
.aurora-about__img--a{height:232px}
.aurora-about__img--b{height:176px}
.aurora-about__tag{position:absolute;bottom:10px;left:10px;padding:5px 11px;border-radius:100px;background:var(--gold);color:var(--royal-900);font-family:var(--font-sans);font-size:.62rem;font-weight:800;letter-spacing:.06em;box-shadow:0 4px 14px rgba(212,175,55,.28);border:1px solid rgba(255,255,255,.22)}
.aurora-about__badge{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);z-index:2;background:#FFFFFF;border:1px solid rgba(212,175,55,.22);box-shadow:0 14px 36px rgba(5,11,22,.14), 0 1px 3px rgba(0,0,0,.06);border-radius:16px;padding:14px 16px;display:flex;flex-direction:column;align-items:center;gap:6px;min-width:118px;text-align:center}
.aurora-about__badge-icon{width:36px;height:36px;border-radius:10px;background:var(--royal-800);color:var(--gold-light);display:flex;align-items:center;justify-content:center;box-shadow:0 6px 16px rgba(7,26,61,.18)}
.aurora-about__badge-num{font-family:var(--font-serif);font-size:1.35rem;font-weight:700;color:var(--royal-800);line-height:1}
.aurora-about__badge-label{font-family:var(--font-sans);font-size:.62rem;font-weight:700;letter-spacing:.10em;text-transform:uppercase;color:#8a94ad;line-height:1}
@media(max-width:1024px){.aurora-about{grid-template-columns:1fr;gap:36px}.aurora-about::before{font-size:4.5rem;top:-10px}.aurora-about__col--offset{margin-top:0}.aurora-about__visual{max-width:560px;margin:0 auto}.aurora-about__badge{padding:12px 14px;min-width:108px}}
@media(max-width:1024px){.aurora-about{grid-template-columns:1fr;gap:36px}.aurora-about__col--offset{margin-top:0}.aurora-about__visual{max-width:560px}}
@media(max-width:640px){.aurora-about__values{grid-template-columns:repeat(3,1fr);gap:8px}.aurora-about__val{padding:12px 6px}.aurora-about__val-num{font-size:1.3rem}}

/* ========== LME GROUP ========== */
.lme-card{
  position:relative;margin-top:36px;
  background:linear-gradient(135deg, #071A3D 0%, #0F2A5E 55%, #123A85 100%);
  border:1px solid rgba(255,255,255,.08);border-radius:24px;overflow:hidden;
  display:grid;grid-template-columns:1.15fr .85fr;box-shadow:0 24px 64px rgba(5,11,22,.32);
}
.lme-card::before{content:'';position:absolute;top:-80px;right:-80px;width:420px;height:420px;border-radius:50%;background:radial-gradient(circle, rgba(212,175,55,.14), transparent 70%);pointer-events:none}
.lme-card::after{content:'';position:absolute;inset:0;background-image:linear-gradient(rgba(255,255,255,.02) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.02) 1px, transparent 1px);background-size:56px 56px;opacity:.5;pointer-events:none}
.lme-card__main{position:relative;z-index:1;padding:36px 32px}
.lme-card__eyebrow{display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border-radius:100px;background:rgba(212,175,55,.12);border:1px solid rgba(212,175,55,.22);color:var(--gold-light);font-size:.62rem;font-weight:800;letter-spacing:.14em;text-transform:uppercase;margin-bottom:16px}
.lme-card__title{font-family:var(--font-serif);font-size:2rem;font-weight:700;line-height:1.05;color:#fff;margin-bottom:10px}
.lme-card__title em{font-style:italic;color:var(--gold-light);font-weight:700}
.lme-card__desc{font-family:var(--font-sans);font-size:.9rem;font-weight:300;line-height:1.75;color:rgba(255,255,255,.66);margin-bottom:20px;max-width:520px}
.lme-card__grid{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:22px}
.lme-card__pill{padding:12px 10px;border-radius:12px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);text-align:center;backdrop-filter:blur(8px)}
.lme-card__pill-icon{width:32px;height:32px;margin:0 auto 8px;border-radius:8px;background:rgba(212,175,55,.14);border:1px solid rgba(212,175,55,.22);display:flex;align-items:center;justify-content:center;color:var(--gold-light)}
.lme-card__pill-title{font-size:.78rem;font-weight:700;color:#fff}
.lme-card__pill-text{font-size:.72rem;color:rgba(255,255,255,.58);margin-top:2px}
.lme-card__devises{display:flex;gap:12px;flex-wrap:wrap;margin-top:8px}
.lme-card__devise{flex:1;min-width:160px;padding:14px 16px;border-radius:12px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);backdrop-filter:blur(8px)}
.lme-card__devise-label{font-size:.64rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:rgba(255,255,255,.42)}
.lme-card__devise-value{font-family:var(--font-display);font-style:italic;font-size:1rem;font-weight:700;color:var(--gold-light);margin-top:4px}
.lme-card__side{position:relative;z-index:1;padding:36px 32px;background:rgba(255,255,255,.04);border-left:1px solid rgba(255,255,255,.06);display:flex;flex-direction:column;gap:18px;backdrop-filter:blur(8px)}
.lme-card__side-title{font-family:var(--font-serif);font-size:1.15rem;font-weight:700;color:#fff}
.lme-card__contact{display:flex;flex-direction:column;gap:12px}
.lme-card__contact-item{display:flex;gap:12px;align-items:flex-start;padding:12px 12px;border-radius:12px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06)}
.lme-card__contact-icon{width:36px;height:36px;border-radius:10px;background:rgba(212,175,55,.14);border:1px solid rgba(212,175,55,.22);display:flex;align-items:center;justify-content:center;color:var(--gold-light);flex-shrink:0}
.lme-card__contact-label{font-size:.62rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.42)}
.lme-card__contact-value{font-size:.84rem;font-weight:500;color:#fff;margin-top:2px;word-break:break-word}
.lme-card__contact-value a{color:#fff}
.lme-card__contact-value a:hover{color:var(--gold-light)}
.lme-card__actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:4px}
@media(max-width:960px){.lme-card{grid-template-columns:1fr}.lme-card__side{border-left:none;border-top:1px solid rgba(255,255,255,.06)}}
@media(max-width:640px){.lme-card__grid{grid-template-columns:1fr 1fr}.lme-card__main,.lme-card__side{padding:24px 20px}}

/* ========== OPPORTUNITES / BIEN PLUS QU'UNE COURONNE ========== */
.opps{margin-top:44px;display:grid;grid-template-columns:repeat(3,1fr);gap:18px}
.opps__card{position:relative;background:#fff;border:1px solid rgba(5,11,22,.06);border-radius:20px;padding:26px 22px;overflow:hidden;transition:all .3s}
.opps__card:hover{transform:translateY(-6px);box-shadow:0 16px 40px rgba(5,11,22,.08);border-color:rgba(212,175,55,.2)}
.opps__card--featured{background:linear-gradient(135deg, #071A3D, #0F2A5E);border-color:rgba(212,175,55,.18);color:#fff}
.opps__card--featured .opps__title{color:#fff}
.opps__card--featured .opps__text{color:rgba(255,255,255,.66)}
.opps__icon{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;margin-bottom:16px}
.opps__icon--gold{background:linear-gradient(135deg, #F3D77A, #C9A227);color:#fff;box-shadow:0 8px 20px rgba(212,175,55,.3)}
.opps__icon--blue{background:rgba(11,45,107,.08);border:1px solid rgba(11,45,107,.12);color:var(--royal-700)}
.opps__icon--dark{background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);color:var(--gold-light)}
.opps__title{font-family:var(--font-serif);font-size:1.2rem;font-weight:700;color:var(--royal-900);margin-bottom:8px}
.opps__text{font-size:.86rem;font-weight:300;line-height:1.7;color:#5b6577}
.opps__list{list-style:none;display:flex;flex-direction:column;gap:8px;margin-top:14px}
.opps__list li{display:flex;gap:8px;align-items:flex-start;font-size:.84rem;color:#3a4458}
.opps__list li::before{content:'';width:6px;height:6px;border-radius:50%;background:var(--gold);margin-top:7px;flex-shrink:0}
.opps__card--featured .opps__list li{color:rgba(255,255,255,.78)}
@media(max-width:900px){.opps{grid-template-columns:1fr}}

/* ========== CANDIDATES ========== */
.candidates-head{display:flex;flex-direction:column;align-items:center;text-align:center}
.candidates-tabs{display:flex;flex-wrap:wrap;justify-content:center;gap:10px;margin:28px 0 32px}
.candidates-tab{
  padding:10px 18px;border-radius:100px;border:1px solid rgba(5,11,22,.1);background:#fff;
  color:#334155;font-family:var(--font-sans);font-size:.84rem;font-weight:600;cursor:pointer;transition:all .22s;
  display:inline-flex;align-items:center;gap:8px;
}
.candidates-tab:hover{border-color:var(--gold-border);color:var(--royal-800)}
.candidates-tab.is-active{background:var(--royal-800);color:#fff;border-color:var(--royal-800);box-shadow:0 8px 20px rgba(7,26,61,.18)}
.candidates-tab__badge{padding:2px 8px;border-radius:999px;font-size:.62rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;background:rgba(255,255,255,.14);color:#fff}
.candidates-tab.is-active .candidates-tab__badge{background:rgba(212,175,55,.2);color:var(--gold-light)}
.candidates-panels{position:relative}
.candidates-panel{display:none;animation:au-fade .4s ease}
.candidates-panel.is-active{display:block}
@keyframes au-fade{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.candidates-event-head{text-align:center;margin-bottom:28px}
.candidates-event-title{font-family:var(--font-serif);font-size:clamp(1.5rem, 3vw, 2.2rem);font-weight:700;color:var(--royal-900)}
.candidates-event-desc{font-size:.88rem;color:#5b6577;max-width:560px;margin:6px auto 0}
.candidates-event-dates{display:inline-flex;align-items:center;gap:6px;margin-top:10px;padding:6px 12px;border-radius:100px;background:rgba(7,26,61,.06);border:1px solid rgba(7,26,61,.08);font-size:.72rem;font-weight:600;color:#475569}
.candidates-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:20px}
.candidate-card{
  background:#fff;border:1px solid rgba(5,11,22,.06);border-radius:18px;overflow:hidden;
  box-shadow:0 8px 24px rgba(5,11,22,.04);transition:all .32s;display:flex;flex-direction:column;
}
.candidate-card:hover{transform:translateY(-6px);box-shadow:0 16px 40px rgba(5,11,22,.1);border-color:rgba(212,175,55,.18)}
.candidate-card__photo{position:relative;aspect-ratio:3/3.6;overflow:hidden;background:#e8eef8}
.candidate-card__photo .skel{position:absolute;inset:0;z-index:0;background:linear-gradient(90deg, #E8EEF8 25%, #F1F5FB 37%, #E8EEF8 63%);background-size:400% 100%;animation:skShimmer 1.3s ease infinite}
@keyframes skShimmer{0%{background-position:100% 50%}100%{background-position:0% 50%}}
.candidate-card__photo img{width:100%;height:100%;object-fit:cover;transition:opacity .45s ease, transform .7s, filter .3s;filter:saturate(.96);opacity:0}
.candidate-card__photo img.is-loaded{opacity:1}
.candidate-card__photo.is-loaded .skel{opacity:0;pointer-events:none;transition:opacity .3s}
.candidate-card:hover .candidate-card__photo img{transform:scale(1.06);filter:saturate(1.05)}
.candidate-card__veil{position:absolute;inset:0;background:linear-gradient(180deg, transparent 45%, rgba(5,11,22,.55) 100%);pointer-events:none}
.candidate-card__num{position:absolute;top:10px;left:10px;z-index:2;padding:4px 9px;border-radius:999px;background:var(--gold);color:var(--royal-900);font-size:.62rem;font-weight:800;letter-spacing:.06em;box-shadow:0 4px 12px rgba(212,175,55,.3)}
.candidate-card__city{position:absolute;bottom:10px;left:10px;z-index:2;display:flex;align-items:center;gap:4px;color:rgba(255,255,255,.92);font-size:.64rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase}
.candidate-card__tag{position:absolute;top:10px;right:10px;z-index:2;padding:3px 8px;border-radius:999px;background:rgba(7,26,61,.72);backdrop-filter:blur(8px);border:1px solid rgba(255,255,255,.14);color:#fff;font-size:.58rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;opacity:0;transform:translateY(-4px);transition:all .25s}
.candidate-card:hover .candidate-card__tag{opacity:1;transform:translateY(0)}
.candidate-card__body{padding:14px 14px 14px;display:flex;flex-direction:column;gap:10px;flex:1}
.candidate-card__name{font-family:var(--font-serif);font-size:1.18rem;font-weight:700;line-height:1.15;color:var(--royal-900)}
.candidate-card__share{display:flex;align-items:center;gap:6px}
.candidate-card__share-btn{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:100px;background:rgba(212,175,55,.12);border:1px solid rgba(212,175,55,.18);color:var(--royal-800);font-size:.64rem;font-weight:700;cursor:pointer;transition:all .2s}
.candidate-card__share-btn:hover{background:var(--gold);color:var(--royal-900);border-color:var(--gold)}
.candidate-card__divider{height:1px;background:linear-gradient(90deg, rgba(5,11,22,.06), transparent)}
.candidate-card__details{display:flex;flex-direction:column;gap:6px}
.candidate-card__detail{display:flex;align-items:center;gap:8px;font-size:.74rem;color:#475569}
.candidate-card__detail-icon{width:22px;height:22px;border-radius:7px;background:rgba(212,175,55,.1);border:1px solid rgba(212,175,55,.14);display:flex;align-items:center;justify-content:center;color:var(--gold);flex-shrink:0}
.candidate-card__stats{display:flex;flex-direction:column;gap:8px;margin-top:2px}
.candidate-card__metrics{display:flex;gap:6px}
.candidate-card__metric{flex:1;display:flex;align-items:center;justify-content:center;gap:5px;padding:6px 8px;border-radius:100px;background:#FFFCF2;border:1px solid rgba(212,175,55,.18);font-size:.72rem;font-weight:600;color:#5b4a1a}
.candidate-card__metric svg{width:12px;height:12px;stroke:var(--gold)}
.candidate-card__score-head{display:flex;justify-content:space-between;align-items:center;font-size:.64rem;color:#94a3b8}
.candidate-card__score-head strong{color:var(--gold);font-size:.72rem}
.candidate-card__track{height:4px;background:rgba(5,11,22,.06);border-radius:999px;overflow:hidden}
.candidate-card__fill{height:100%;width:0%;background:linear-gradient(90deg, var(--gold), var(--gold-light));border-radius:999px;transition:width 1s cubic-bezier(.4,0,.2,1) .2s}
.candidate-card.is-visible .candidate-card__fill{width:var(--score,0%)}
.candidate-card__actions{display:flex;gap:8px;margin-top:6px}
.candidate-card__btn{flex:1;text-align:center;padding:9px 6px;border-radius:10px;font-size:.68rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase;transition:all .2s}
.candidate-card__btn--ghost{background:rgba(5,11,22,.04);border:1px solid rgba(5,11,22,.08);color:var(--royal-800)}
.candidate-card__btn--ghost:hover{background:rgba(5,11,22,.08)}
.candidate-card__btn--primary{background:var(--gold);border:1px solid var(--gold);color:var(--royal-900);box-shadow:0 6px 16px rgba(212,175,55,.22)}
.candidate-card__btn--primary:hover{background:var(--gold-light);transform:translateY(-1px)}
.candidates-footer{margin-top:32px;text-align:center;display:flex;flex-direction:column;align-items:center;gap:14px}
.candidates-footer__count{display:flex;align-items:center;gap:12px;color:var(--gold);font-size:.68rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase}
.candidates-footer__count::before,.candidates-footer__count::after{content:'';width:48px;height:1px;background:linear-gradient(90deg, transparent, var(--gold))}
.candidates-footer__count::after{background:linear-gradient(90deg, var(--gold), transparent)}
@media(max-width:1200px){.candidates-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:820px){.candidates-grid{grid-template-columns:repeat(2,1fr);gap:14px}}
@media(max-width:480px){.candidates-grid{grid-template-columns:repeat(2,1fr);gap:10px}.candidate-card__body{padding:10px}.candidate-card__name{font-size:.96rem}.candidate-card__detail{font-size:.68rem}}

/* ========== RANKING / PODIUM ========== */
.ranking{background:var(--royal-900);color:#fff;position:relative;overflow:hidden}
.ranking::before{content:'';position:absolute;inset:0;background:radial-gradient(700px 500px at 50% 0%, rgba(212,175,55,.1), transparent 60%), radial-gradient(600px 400px at 90% 90%, rgba(18,58,133,.22), transparent 60%);pointer-events:none}
.ranking__wrap{position:relative;z-index:1;max-width:1240px;margin:0 auto}
.ranking__head{text-align:center;margin-bottom:36px}
.ranking__podium{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;align-items:end;margin-bottom:28px;max-width:860px;margin-left:auto;margin-right:auto}
.ranking__podium-card{position:relative;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.08);border-radius:18px;padding:18px 14px 16px;text-align:center;backdrop-filter:blur(8px);transition:transform .3s}
.ranking__podium-card:hover{transform:translateY(-4px)}
.ranking__podium-card--first{order:2;transform:translateY(-12px);background:linear-gradient(180deg, rgba(212,175,55,.16), rgba(255,255,255,.04));border-color:rgba(212,175,55,.22);box-shadow:0 16px 40px rgba(212,175,55,.18)}
.ranking__podium-card--first:hover{transform:translateY(-16px)}
.ranking__podium-card--second{order:1}
.ranking__podium-card--third{order:3}
.ranking__medal{width:44px;height:44px;margin:0 auto 10px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.2rem;font-weight:800}
.ranking__medal--1{background:linear-gradient(135deg, #F3D77A, #B48E1A);color:var(--royal-900);box-shadow:0 8px 20px rgba(212,175,55,.35)}
.ranking__medal--2{background:linear-gradient(135deg, #E5E7EB, #9CA3AF);color:#1f2937;box-shadow:0 8px 20px rgba(156,163,175,.25)}
.ranking__medal--3{background:linear-gradient(135deg, #FDBA74, #9C4A1A);color:#fff;box-shadow:0 8px 20px rgba(180,80,20,.25)}
.ranking__podium-name{font-family:var(--font-serif);font-size:1rem;font-weight:700;color:#fff;line-height:1.2}
.ranking__podium-code{font-size:.68rem;font-weight:700;letter-spacing:.08em;color:rgba(255,255,255,.42);margin-top:2px}
.ranking__podium-votes{margin-top:10px;display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:100px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);font-size:.78rem;font-weight:700;color:var(--gold-light)}
.ranking__podium-pct{font-size:.68rem;color:rgba(255,255,255,.5);margin-top:6px}
.ranking__list{background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06);border-radius:16px;overflow:hidden;backdrop-filter:blur(8px)}
.ranking__row{display:grid;grid-template-columns:48px 1fr auto;gap:12px;align-items:center;padding:12px 16px;border-bottom:1px solid rgba(255,255,255,.06);transition:background .2s}
.ranking__row:last-child{border-bottom:none}
.ranking__row:hover{background:rgba(255,255,255,.04)}
.ranking__rank{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:.76rem;font-weight:800;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);color:rgba(255,255,255,.7)}
.ranking__rank--top{color:var(--royal-900);border-color:transparent}
.ranking__rank--1{background:var(--gold);color:var(--royal-900)}
.ranking__rank--2{background:#d1d5db;color:#111827}
.ranking__rank--3{background:#d97706;color:#fff}
.ranking__name{font-size:.88rem;font-weight:600;color:#fff}
.ranking__code{font-size:.7rem;color:rgba(255,255,255,.38)}
.ranking__votes{font-size:.84rem;font-weight:700;color:var(--gold-light)}
.ranking__votes-sub{font-size:.68rem;color:rgba(255,255,255,.42);text-align:right}
.ranking__bar{width:80px;height:4px;background:rgba(255,255,255,.08);border-radius:999px;overflow:hidden;margin-top:4px}
.ranking__bar-fill{height:100%;background:linear-gradient(90deg, var(--gold), var(--gold-light));width:0%;transition:width .8s}
.ranking__empty{padding:32px;text-align:center;color:rgba(255,255,255,.42);font-size:.88rem}
.ranking__footer{margin-top:18px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px}
.ranking__total{font-size:.82rem;color:rgba(255,255,255,.6)}
.ranking__total strong{color:var(--gold-light)}
@media(max-width:700px){.ranking__podium{grid-template-columns:1fr;gap:12px}.ranking__podium-card--first{order:1;transform:none}.ranking__podium-card--first:hover{transform:translateY(-4px)}.ranking__row{grid-template-columns:36px 1fr auto}.ranking__bar{width:56px}}

/* ========== TIMELINE / PARCOURS ========== */
.timeline{position:relative}
.timeline__line{position:absolute;left:50%;top:120px;bottom:40px;width:1px;background:linear-gradient(180deg, rgba(212,175,55,.0), rgba(212,175,55,.22) 10%, rgba(212,175,55,.22) 90%, rgba(212,175,55,0));transform:translateX(-50%);display:none}
@media(min-width:900px){.timeline__line{display:block}}
.timeline__grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:36px;position:relative;z-index:1}
.timeline__item{position:relative;background:#fff;border:1px solid rgba(5,11,22,.06);border-radius:18px;padding:22px 20px;display:flex;gap:14px;transition:all .3s}
.timeline__item:hover{transform:translateY(-4px);box-shadow:0 16px 36px rgba(5,11,22,.08);border-color:rgba(212,175,55,.18)}
.timeline__num{flex-shrink:0;width:44px;height:44px;border-radius:12px;background:var(--royal-800);color:var(--gold-light);display:flex;align-items:center;justify-content:center;font-family:var(--font-serif);font-weight:700;font-size:1rem;box-shadow:0 8px 18px rgba(7,26,61,.18)}
.timeline__item--accent .timeline__num{background:linear-gradient(135deg, var(--gold), #B48E1A);color:var(--royal-900)}
.timeline__content{flex:1}
.timeline__title{font-family:var(--font-serif);font-size:1.05rem;font-weight:700;color:var(--royal-900)}
.timeline__desc{font-size:.84rem;font-weight:300;line-height:1.6;color:#5b6577;margin-top:4px}
.timeline__date{margin-top:8px;display:inline-flex;align-items:center;gap:6px;padding:4px 8px;border-radius:100px;background:rgba(7,26,61,.06);border:1px solid rgba(7,26,61,.06);font-size:.68rem;font-weight:600;color:#475569}
.timeline__dynamic{margin-top:36px}
.timeline__dynamic-title{font-family:var(--font-serif);font-size:1.3rem;font-weight:700;color:var(--royal-900);text-align:center;margin-bottom:16px}
.timeline__dynamic-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.timeline__dynamic-card{background:#fff;border:1px solid rgba(5,11,22,.06);border-radius:16px;padding:18px;transition:transform .25s}
.timeline__dynamic-card:hover{transform:translateY(-3px);box-shadow:0 12px 28px rgba(5,11,22,.06)}
@media(max-width:900px){.timeline__grid{grid-template-columns:1fr}.timeline__dynamic-grid{grid-template-columns:1fr}}

/* ========== CONDITIONS ========== */
.cond-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin-top:32px}
.cond-card{background:#fff;border:1px solid rgba(5,11,22,.06);border-radius:18px;padding:22px 20px;transition:all .3s}
.cond-card:hover{transform:translateY(-4px);box-shadow:0 12px 32px rgba(5,11,22,.07);border-color:rgba(212,175,55,.18)}
.cond-card__icon{width:44px;height:44px;border-radius:12px;background:rgba(212,175,55,.1);border:1px solid rgba(212,175,55,.18);display:flex;align-items:center;justify-content:center;color:var(--gold);margin-bottom:14px}
.cond-card__title{font-family:var(--font-serif);font-size:1.02rem;font-weight:700;color:var(--royal-900);margin-bottom:6px}
.cond-card__text{font-size:.84rem;font-weight:300;line-height:1.65;color:#5b6577}
@media(max-width:900px){.cond-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:560px){.cond-grid{grid-template-columns:1fr}}

/* ========== DOSSIER ========== */
.dossier{display:grid;grid-template-columns:1.1fr .9fr;gap:28px;align-items:start;margin-top:32px}
.dossier__list{list-style:none;display:flex;flex-direction:column;gap:12px}
.dossier__item{display:flex;gap:14px;align-items:center;padding:14px 16px;border-radius:14px;background:#fff;border:1px solid rgba(5,11,22,.06);transition:all .2s}
.dossier__item:hover{border-color:rgba(212,175,55,.18);transform:translateX(4px);box-shadow:0 8px 20px rgba(5,11,22,.06)}
.dossier__item-icon{width:40px;height:40px;border-radius:10px;background:rgba(7,26,61,.06);border:1px solid rgba(7,26,61,.08);display:flex;align-items:center;justify-content:center;color:var(--royal-700);flex-shrink:0}
.dossier__item-title{font-size:.9rem;font-weight:700;color:var(--royal-900)}
.dossier__item-sub{font-size:.76rem;color:#64748b}
.dossier__cta{position:sticky;top:90px;background:linear-gradient(135deg, #071A3D, #123A85);border:1px solid rgba(255,255,255,.08);border-radius:20px;padding:28px 24px;color:#fff;box-shadow:0 20px 48px rgba(7,26,61,.22);overflow:hidden}
.dossier__cta::before{content:'';position:absolute;top:-60px;right:-60px;width:200px;height:200px;border-radius:50%;background:radial-gradient(circle, rgba(212,175,55,.18), transparent 70%);pointer-events:none}
.dossier__cta-title{font-family:var(--font-serif);font-size:1.5rem;font-weight:700;line-height:1.1}
.dossier__cta-title em{color:var(--gold-light);font-style:italic}
.dossier__cta-text{font-size:.88rem;font-weight:300;line-height:1.65;color:rgba(255,255,255,.68);margin:12px 0 18px}
.dossier__cta-list{list-style:none;display:flex;flex-direction:column;gap:8px;margin-bottom:20px}
.dossier__cta-list li{display:flex;gap:8px;align-items:center;font-size:.82rem;color:rgba(255,255,255,.78)}
.dossier__cta-list li svg{color:var(--gold-light);flex-shrink:0}
@media(max-width:900px){.dossier{grid-template-columns:1fr}.dossier__cta{position:static}}

/* ========== VOTE ========== */
.vote{position:relative;background:linear-gradient(135deg, #050B16 0%, #071A3D 48%, #0B2D6B 100%);color:#fff;overflow:hidden}
.vote::before{content:'';position:absolute;inset:0;background:radial-gradient(700px 500px at 20% 20%, rgba(212,175,55,.12), transparent 60%), radial-gradient(600px 400px at 90% 80%, rgba(255,255,255,.04), transparent 60%);pointer-events:none}
.vote__wrap{position:relative;z-index:1;max-width:1000px;margin:0 auto;text-align:center}
.vote__badge{display:inline-flex;align-items:center;gap:8px;padding:7px 14px;border-radius:100px;background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.22);color:#6ee7b7;font-size:.68rem;font-weight:800;letter-spacing:.1em;text-transform:uppercase;margin-bottom:18px}
.vote__badge-dot{width:7px;height:7px;border-radius:50%;background:#10b981;box-shadow:0 0 10px rgba(16,185,129,.7);animation:au-pulse 1.6s infinite}
.vote__title{font-family:var(--font-serif);font-size:clamp(2.2rem, 4.5vw, 3.6rem);font-weight:300;line-height:.95;letter-spacing:-.02em}
.vote__title em{font-style:italic;font-weight:700;color:var(--gold-light)}
.vote__text{font-size:1rem;font-weight:300;line-height:1.75;color:rgba(255,255,255,.66);max-width:640px;margin:14px auto 0}
.vote__text strong{color:#6ee7b7;font-weight:700}
.vote__cards{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;margin:32px 0}
.vote__card{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);border-radius:16px;padding:20px 16px;backdrop-filter:blur(8px)}
.vote__card-title{font-size:.9rem;font-weight:700;color:#fff}
.vote__card-sub{font-size:.78rem;color:rgba(255,255,255,.58);margin-top:4px}
.vote__payments{display:flex;flex-wrap:wrap;justify-content:center;gap:8px;margin:20px 0}
.vote__pay{padding:8px 12px;border-radius:100px;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.08);font-size:.76rem;font-weight:600;color:rgba(255,255,255,.78)}
.vote__actions{display:flex;justify-content:center;gap:12px;flex-wrap:wrap;margin-top:22px}
@media(max-width:760px){.vote__cards{grid-template-columns:1fr}}

/* ========== BILLETTERIE ========== */
.tickets{display:grid;grid-template-columns:repeat(3,1fr);gap:18px;margin-top:32px}
.ticket{position:relative;background:#fff;border:1px solid rgba(5,11,22,.06);border-radius:20px;padding:26px 22px;display:flex;flex-direction:column;transition:all .3s;overflow:hidden}
.ticket:hover{transform:translateY(-6px);box-shadow:0 16px 40px rgba(5,11,22,.08);border-color:rgba(212,175,55,.18)}
.ticket--featured{border-color:rgba(212,175,55,.28);box-shadow:0 16px 40px rgba(212,175,55,.14);transform:translateY(-4px)}
.ticket--featured:hover{transform:translateY(-10px)}
.ticket__badge{position:absolute;top:14px;right:14px;padding:4px 10px;border-radius:100px;background:var(--gold);color:var(--royal-900);font-size:.62rem;font-weight:800;letter-spacing:.06em}
.ticket__tier{font-size:.68rem;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:var(--gold);margin-bottom:6px}
.ticket__name{font-family:var(--font-serif);font-size:1.5rem;font-weight:700;color:var(--royal-900)}
.ticket__price{margin:14px 0 8px;padding:14px;border-radius:14px;background:rgba(7,26,61,.04);border:1px solid rgba(7,26,61,.06);text-align:center}
.ticket__price-label{font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#64748b}
.ticket__price-value{font-family:var(--font-serif);font-size:1.25rem;font-weight:700;color:var(--royal-800);margin-top:4px}
.ticket__list{list-style:none;display:flex;flex-direction:column;gap:9px;margin:14px 0 18px;flex:1}
.ticket__list li{display:flex;gap:8px;align-items:flex-start;font-size:.84rem;color:#475569}
.ticket__list li svg{color:var(--gold);flex-shrink:0;margin-top:2px}
.ticket__btn{width:100%;justify-content:center}
@media(max-width:900px){.tickets{grid-template-columns:1fr}}

/* ========== FINALE ========== */
.finale{
  position:relative;background:var(--royal-900);color:#fff;overflow:hidden;
  padding:96px 40px;
}
.finale::before{content:'';position:absolute;inset:0;background:radial-gradient(800px 600px at 30% 50%, rgba(212,175,55,.1), transparent 60%), linear-gradient(180deg, transparent, rgba(5,11,22,.5));pointer-events:none}
.finale__wrap{position:relative;z-index:1;max-width:1240px;margin:0 auto;display:grid;grid-template-columns:1.05fr .95fr;gap:40px;align-items:center}
.finale__title{font-family:var(--font-serif);font-size:clamp(2rem, 3.6vw, 3.2rem);font-weight:300;line-height:1.02;color:#fff}
.finale__title em{font-style:italic;font-weight:700;color:var(--gold-light)}
.finale__text{font-size:.94rem;font-weight:300;line-height:1.75;color:rgba(255,255,255,.64);margin-top:12px}
.finale__infos{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin:20px 0}
.finale__info{padding:14px 16px;border-radius:14px;background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.06);backdrop-filter:blur(8px)}
.finale__info-label{font-size:.64rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:rgba(255,255,255,.42)}
.finale__info-value{font-size:.92rem;font-weight:700;color:#fff;margin-top:4px}
.finale__visual{position:relative;border-radius:20px;overflow:hidden;background:linear-gradient(180deg, rgba(212,175,55,.12), rgba(7,26,61,.6));border:1px solid rgba(255,255,255,.08);aspect-ratio:4/3;display:flex;align-items:center;justify-content:center}
.finale__visual-inner{text-align:center;padding:24px}
.finale__crown{width:84px;height:84px;margin:0 auto 16px;border-radius:50%;background:linear-gradient(135deg, #F3D77A, #B48E1A);display:flex;align-items:center;justify-content:center;color:var(--royal-900);box-shadow:0 16px 32px rgba(212,175,55,.28);animation:au-float 3.5s ease-in-out infinite}
@keyframes au-float{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
.finale__visual-title{font-family:var(--font-serif);font-size:1.4rem;font-weight:700;color:#fff}
.finale__visual-sub{font-size:.84rem;color:rgba(255,255,255,.62);margin-top:6px}
@media(max-width:900px){.finale__wrap{grid-template-columns:1fr}.finale{padding:64px 20px}}

/* ========== GALERIE ========== */
.gal-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-top:32px}
.gal-item{position:relative;border-radius:16px;overflow:hidden;background:#e8eef8;aspect-ratio:4/3;cursor:pointer;group:gal}
.gal-item img{width:100%;height:100%;object-fit:cover;transition:transform .6s}
.gal-item:hover img{transform:scale(1.06)}
.gal-item::after{content:'';position:absolute;inset:0;background:linear-gradient(180deg, transparent 40%, rgba(5,11,22,.55) 100%);opacity:.0;transition:opacity .3s}
.gal-item:hover::after{opacity:1}
.gal-item__label{position:absolute;bottom:12px;left:12px;padding:5px 10px;border-radius:100px;background:rgba(5,11,22,.6);border:1px solid rgba(255,255,255,.14);backdrop-filter:blur(8px);color:#fff;font-size:.68rem;font-weight:700;letter-spacing:.04em;opacity:0;transform:translateY(6px);transition:all .25s}
.gal-item:hover .gal-item__label{opacity:1;transform:translateY(0)}
@media(max-width:768px){.gal-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:480px){.gal-grid{grid-template-columns:1fr}}

/* ========== PARTENAIRES ========== */
.part-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-top:28px}
.part-card{padding:22px 16px;border-radius:16px;background:#fff;border:1px solid rgba(5,11,22,.06);text-align:center;transition:all .22s}
.part-card:hover{border-color:rgba(212,175,55,.18);transform:translateY(-3px);box-shadow:0 10px 24px rgba(5,11,22,.06)}
.part-card__logo{width:64px;height:64px;margin:0 auto 12px;border-radius:12px;background:rgba(7,26,61,.04);border:1px dashed rgba(7,26,61,.1);display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:.68rem;font-weight:700;letter-spacing:.06em}
.part-card__name{font-size:.82rem;font-weight:700;color:var(--royal-800)}
.part-card__role{font-size:.7rem;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#94a3b8;margin-top:2px}
@media(max-width:900px){.part-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:480px){.part-grid{grid-template-columns:1fr}}

/* ========== SOCIAL ========== */
.social-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-top:28px}
.social-card{display:flex;flex-direction:column;align-items:center;gap:10px;padding:22px 16px;border-radius:16px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.06);color:#fff;transition:all .25s;backdrop-filter:blur(8px)}
.social-card:hover{transform:translateY(-4px);border-color:rgba(212,175,55,.22);background:rgba(255,255,255,.06)}
.social-card__icon{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;color:#fff}
.social-card__icon--fb{background:linear-gradient(135deg, #1877F2, #0B3D91)}
.social-card__icon--ig{background:linear-gradient(135deg, #F58529, #DD2A7B, #515BD4)}
.social-card__icon--tt{background:linear-gradient(135deg, #000, #222)}
.social-card__icon--yt{background:linear-gradient(135deg, #FF0000, #B91C1C)}
.social-card__name{font-size:.88rem;font-weight:700}
.social-card__handle{font-size:.74rem;color:rgba(255,255,255,.58)}
@media(max-width:760px){.social-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:480px){.social-grid{grid-template-columns:1fr}}

/* ========== CONTACT — AIRBNB PREMIUM ATTRACTIF ========== */
.contact-grid{display:grid;grid-template-columns:.92fr 1.08fr;gap:32px;margin-top:36px;align-items:start}
.contact-infos{display:flex;flex-direction:column;gap:14px}
.contact-info{
  display:flex;gap:14px;align-items:flex-start;
  padding:18px;border-radius:14px;background:#FFFFFF;border:1px solid #EBEBEB;
  box-shadow:0 1px 3px rgba(0,0,0,.04);transition:border-color .16s, box-shadow .16s, transform .16s;
}
.contact-info:hover{border-color:#DDDDDD;box-shadow:0 4px 14px rgba(0,0,0,.06);transform:translateY(-1px)}
.contact-info__icon{width:44px;height:44px;border-radius:11px;display:flex;align-items:center;justify-content:center;flex-shrink:0;border:1px solid}
.contact-info__icon--addr{background:#FFF9E6;border-color:#FDE68A;color:#B45309}
.contact-info__icon--phone{background:#ECFDF5;border-color:#A7F3D0;color:#065F46}
.contact-info__icon--mail{background:#EFF6FF;border-color:#BFDBFE;color:#1E40AF}
.contact-info__label{font-size:.60rem;font-weight:700;letter-spacing:.10em;text-transform:uppercase;color:#717171;line-height:1}
.contact-info__value{font-size:.88rem;font-weight:500;color:#1A1A1A;margin-top:5px;word-break:break-word;line-height:1.5}
.contact-info__value a{color:#1A1A1A;text-decoration:none;border-bottom:1px solid transparent;transition:border-color .15s, color .15s}
.contact-info__value a:hover{color:var(--gold);border-color:rgba(212,175,55,.32)}
.contact-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:6px}
.contact-action{
  flex:1;min-width:108px;display:inline-flex;align-items:center;justify-content:center;gap:8px;
  padding:11px 14px;border-radius:10px;font-family:var(--font-ui);font-size:.8125rem;font-weight:600;
  border:1px solid;transition:background .16s, border-color .16s, transform .12s;cursor:pointer;text-decoration:none;
}
.contact-action--wa{background:#25D366;border-color:#25D366;color:#FFFFFF;box-shadow:0 2px 8px rgba(37,211,102,.22)}
.contact-action--wa:hover{background:#1DA851;border-color:#1DA851;transform:translateY(-1px)}
.contact-action--ghost{background:#FFFFFF;border-color:#DDDDDD;color:#222222}
.contact-action--ghost:hover{background:#F7F7F7;border-color:#CCCCCC}
.contact-socials{display:flex;gap:8px;flex-wrap:wrap;margin-top:2px}
.contact-social{
  display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:100px;
  background:#FFFFFF;border:1px solid #EBEBEB;color:#475569;font-size:.74rem;font-weight:500;
  transition:border-color .15s, background .15s;
}
.contact-social:hover{border-color:#DDDDDD;background:#F7F7F7;color:#222222}
.contact-social svg{width:14px;height:14px;stroke-width:1.7;flex-shrink:0}
.contact-form{
  background:#FFFFFF;border:1px solid #EBEBEB;border-radius:16px;padding:26px 24px;
  box-shadow:0 4px 24px rgba(0,0,0,.06), 0 1px 3px rgba(0,0,0,.04);
  position:relative;overflow:hidden;
}
.contact-form::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg, var(--gold), #FDE68A);opacity:.9}
.contact-form__head{display:flex;align-items:center;gap:12px;margin-bottom:18px;padding-bottom:16px;border-bottom:1px solid #F3F4F6}
.contact-form__head-icon{
  width:40px;height:40px;border-radius:10px;background:#FFF9E6;border:1px solid #FDE68A;
  display:flex;align-items:center;justify-content:center;color:#B45309;flex-shrink:0;
}
.contact-form__title{font-family:var(--font-serif);font-size:1.32rem;font-weight:700;color:#111111;line-height:1.2}
.contact-form__title em{font-style:italic;color:var(--gold);font-weight:700}
.contact-form__sub{font-size:.80rem;color:#717171;margin-top:3px}
.contact-form__grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:4px}
.fg{margin-bottom:14px;position:relative}
.fg label{display:block;font-size:.60rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#374151;margin-bottom:7px}
.fg label span{color:#9CA3AF;font-weight:400;text-transform:none;letter-spacing:0}
.fg input,.fg select,.fg textarea{
  width:100%;padding:11px 14px 11px 38px;border-radius:9px;border:1px solid #DDDDDD;background:#FFFFFF;
  color:#111111;font-family:var(--font-ui);font-size:.875rem;line-height:1.4;
  outline:none;transition:border-color .15s, box-shadow .15s, background .15s;
  -webkit-appearance:none;appearance:none;
}
.fg textarea{padding:12px 14px;height:112px;resize:vertical}
.fg input::placeholder,.fg textarea::placeholder{color:#9CA3AF}
.fg input:hover,.fg select:hover,.fg textarea:hover{border-color:#CCCCCC}
.fg input:focus,.fg select:focus,.fg textarea:focus{border-color:#222222;box-shadow:0 0 0 3px rgba(17,17,17,.06);background:#FFFFFF}
.fg--icon::before{content:'';position:absolute;left:12px;bottom:11px;width:16px;height:16px;pointer-events:none;opacity:.0}
.fg__icon{position:absolute;left:12px;bottom:12px;width:16px;height:16px;color:#9CA3AF;pointer-events:none;transition:color .15s}
.fg:focus-within .fg__icon{color:#222222}
.contact-submit{
  width:100%;display:inline-flex;align-items:center;justify-content:center;gap:8px;
  padding:13px 18px;border-radius:10px;background:#111111;border:1px solid #111111;color:#FFFFFF;
  font-family:var(--font-ui);font-size:.875rem;font-weight:600;letter-spacing:.01em;
  cursor:pointer;transition:background .16s, transform .12s, box-shadow .16s;box-shadow:0 2px 8px rgba(0,0,0,.08);
}
.contact-submit:hover{background:#000000;border-color:#000000;transform:translateY(-1px);box-shadow:0 4px 14px rgba(0,0,0,.12)}
.contact-submit:active{transform:translateY(0)}
.contact-note{font-size:.72rem;color:#717171;text-align:center;margin-top:10px;display:flex;align-items:center;justify-content:center;gap:6px}
.contact-note svg{width:12px;height:12px;stroke-width:1.7;color:#9CA3AF}
@media(max-width:900px){.contact-grid{grid-template-columns:1fr;gap:24px}.contact-form__grid{grid-template-columns:1fr}.contact-form{padding:22px 18px}}

/* ========== FOOTER — SOBRE AIRBNB PREMIUM — DENSE PRO ========== */
.aurora-footer{position:relative;background:#FFFFFF;color:#222222;border-top:1px solid #EBEBEB}
.aurora-footer__wrap{max-width:1120px;margin:0 auto;padding:40px 24px 0}
.aurora-footer__top{display:flex;align-items:flex-start;gap:32px;padding-bottom:28px;border-bottom:1px solid #EBEBEB}
.aurora-footer__brand{flex:0 0 260px;max-width:300px}
.aurora-footer__logo{display:flex;align-items:center;gap:10px}
.aurora-footer__logo-mark{width:34px;height:34px;border-radius:7px;background:#FFFFFF;border:1px solid #DDDDDD;display:flex;align-items:center;justify-content:center;color:var(--royal-800);flex-shrink:0}
.aurora-footer__logo-text{font-family:var(--font-sans);font-size:.875rem;font-weight:700;letter-spacing:.02em;color:#222222;line-height:1}
.aurora-footer__logo-sub{font-family:var(--font-ui);font-size:.64rem;font-weight:500;letter-spacing:.06em;color:#717171;text-transform:uppercase;margin-top:2px}
.aurora-footer__desc{font-family:var(--font-ui);font-size:.8125rem;font-weight:400;line-height:1.6;color:#717171;margin-top:10px}
.aurora-footer__grid{display:grid;grid-template-columns:repeat(4,1fr);gap:24px;flex:1}
.aurora-footer__col-title{font-family:var(--font-ui);font-size:.72rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#222222;margin-bottom:12px}
.aurora-footer__links{list-style:none;display:flex;flex-direction:column;gap:8px}
.aurora-footer__link{font-family:var(--font-ui);font-size:.8125rem;font-weight:400;color:#717171;transition:color .15s, text-decoration .15s;display:inline-flex;align-items:center;gap:7px}
.aurora-footer__link svg{width:14px;height:14px;stroke-width:1.7;flex-shrink:0;color:#717171;opacity:.9;transition:color .15s}
.aurora-footer__link:hover{color:#222222;text-decoration:underline;text-underline-offset:3px}
.aurora-footer__link:hover svg{color:#222222}
.aurora-footer__contact{margin-top:6px;display:flex;flex-direction:column;gap:10px}
.aurora-footer__contact-item{display:flex;gap:8px;align-items:flex-start;font-family:var(--font-ui);font-size:.875rem;color:#717171;line-height:1.5}
.aurora-footer__contact-item svg{flex-shrink:0;margin-top:2px;color:#222222}
.aurora-footer__contact-item a{color:#717171}
.aurora-footer__contact-item a:hover{color:#222222;text-decoration:underline}
.aurora-footer__socials{display:flex;gap:10px;margin-top:14px}
.aurora-footer__social{width:32px;height:32px;border-radius:50%;border:1px solid #DDDDDD;background:#FFFFFF;display:flex;align-items:center;justify-content:center;color:#222222;transition:border-color .18s, background .18s}
.aurora-footer__social:hover{border-color:#222222;background:#F7F7F7}
.aurora-footer__newsletter{margin-top:28px;padding:18px;background:#F7F7F7;border:1px solid #EBEBEB;border-radius:10px;display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:14px}
.aurora-footer__newsletter-text{flex:1;min-width:220px}
.aurora-footer__newsletter-title{font-family:var(--font-ui);font-size:.8125rem;font-weight:600;color:#222222}
.aurora-footer__newsletter-sub{font-family:var(--font-ui);font-size:.76rem;color:#717171;margin-top:2px}
.aurora-footer__newsletter-form{display:flex;gap:8px;flex:1;max-width:400px;min-width:240px}
.aurora-footer__newsletter-input{flex:1;padding:9px 12px;border-radius:7px;border:1px solid #DDDDDD;background:#FFFFFF;color:#222222;font-family:var(--font-ui);font-size:.8125rem;outline:none;transition:border-color .18s, box-shadow .18s}
.aurora-footer__newsletter-input::placeholder{color:#717171}
.aurora-footer__newsletter-input:focus{border-color:#222222;box-shadow:0 0 0 1px #222222}
.aurora-footer__newsletter-btn{padding:9px 16px;border-radius:7px;border:none;background:var(--royal-800);color:#FFFFFF;font-family:var(--font-ui);font-size:.8125rem;font-weight:600;cursor:pointer;transition:background .18s;white-space:nowrap}
.aurora-footer__newsletter-btn:hover{background:var(--royal-700)}
.aurora-footer__bottom{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;padding:20px 0;border-top:1px solid #EBEBEB;margin-top:28px}
.aurora-footer__copy{font-family:var(--font-ui);font-size:.76rem;color:#717171}
.aurora-footer__copy strong{color:#222222;font-weight:600}
.aurora-footer__legal{display:flex;gap:14px;flex-wrap:wrap;align-items:center}
.aurora-footer__legal a{font-family:var(--font-ui);font-size:.76rem;color:#717171;transition:color .15s}
.aurora-footer__legal a:hover{color:#222222;text-decoration:underline}
.aurora-footer__legal-dot{width:2px;height:2px;border-radius:50%;background:#DDDDDD}
@media(max-width:1024px){.aurora-footer__top{flex-direction:column}.aurora-footer__brand{flex:none;max-width:none;width:100%}.aurora-footer__grid{width:100%;grid-template-columns:1fr 1fr;gap:28px}}
@media(max-width:640px){.aurora-footer__wrap{padding:32px 16px 0}.aurora-footer__grid{grid-template-columns:1fr;gap:24px}.aurora-footer__newsletter{flex-direction:column;align-items:stretch}.aurora-footer__newsletter-form{max-width:none}.aurora-footer__bottom{flex-direction:column;align-items:flex-start;padding:20px 0 24px}}

/* ========== BOTTOM NAV — APP MOBILE PRO ========== */
.aurora-bottom-nav{
  display:none;position:fixed;bottom:0;left:0;right:0;z-index:900;
  background:rgba(255,255,255,.98);backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px);
  border-top:1px solid #EBEBEB;
  padding:3px 0 calc(3px + env(safe-area-inset-bottom));
  box-shadow:0 -1px 10px rgba(0,0,0,.06);
}
.aurora-bottom-nav__inner{
  max-width:420px;margin:0 auto;display:flex;justify-content:space-around;align-items:center;
}
.aurora-bottom-nav__item{
  flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;
  padding:5px 6px 3px;border-radius:9px;color:#717171;text-decoration:none;
  font-family:var(--font-ui);font-size:.60rem;font-weight:500;letter-spacing:.02em;
  transition:color .15s, background .15s;position:relative;
}
.aurora-bottom-nav__item svg{width:18px;height:18px;stroke-width:1.7;transition:transform .15s, color .15s}
.aurora-bottom-nav__item.is-active{color:var(--royal-800);font-weight:600}
.aurora-bottom-nav__item.is-active svg{transform:translateY(-1px);color:var(--royal-800)}
.aurora-bottom-nav__item:active{background:#F7F7F7}
.aurora-bottom-nav__badge{
  position:absolute;top:2px;right:14px;min-width:16px;height:16px;padding:0 4px;
  border-radius:999px;background:var(--gold);color:var(--royal-900);
  font-size:.58rem;font-weight:700;display:flex;align-items:center;justify-content:center;
}
@media(max-width:768px){
  .aurora-bottom-nav{display:block}
  body{padding-bottom:58px}
}
@media(min-width:769px){ body{padding-bottom:0} }

/* Footer accordion — mobile app */
@media(max-width:640px){
  .aurora-footer__col-title{
    cursor:pointer;user-select:none;
    display:flex;align-items:center;justify-content:space-between;
  }
  .aurora-footer__col-title::after{
    content:'▾';flex:none;width:20px;height:20px;border-radius:50%;border:1px solid #DDDDDD;
    display:flex;align-items:center;justify-content:center;font-size:.62rem;color:#717171;
    background:#FFFFFF;transition:transform .2s, border-color .2s;
  }
  .aurora-footer__col--open .aurora-footer__col-title::after{transform:rotate(180deg);border-color:#222222}
  .aurora-footer__col-links{display:none;margin-top:10px}
  .aurora-footer__col--open .aurora-footer__col-links{display:flex}
  .aurora-footer__contact{display:none}
  .aurora-footer__col--open .aurora-footer__contact{display:flex}
}

/* ========== PWA INSTALL BANNER ========== */
.aurora-install{
  display:none;position:fixed;bottom:76px;left:50%;transform:translateX(-50%) translateY(12px);
  z-index:899;max-width:420px;width:calc(100% - 24px);
  background:#222222;color:#FFFFFF;border-radius:12px;padding:12px 14px;
  box-shadow:0 8px 28px rgba(0,0,0,.18);align-items:center;gap:12px;
  opacity:0;transition:opacity .24s, transform .24s;
}
.aurora-install.is-visible{display:flex;opacity:1;transform:translateX(-50%) translateY(0)}
.aurora-install__icon{width:40px;height:40px;border-radius:8px;background:#FFFFFF;color:var(--royal-800);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.aurora-install__text{flex:1;min-width:0}
.aurora-install__title{font-family:var(--font-ui);font-size:.875rem;font-weight:600;color:#FFFFFF}
.aurora-install__sub{font-family:var(--font-ui);font-size:.75rem;color:rgba(255,255,255,.72);margin-top:1px}
.aurora-install__btn{padding:8px 14px;border-radius:8px;border:none;background:var(--gold);color:var(--royal-900);font-family:var(--font-ui);font-size:.8125rem;font-weight:600;cursor:pointer;white-space:nowrap}
.aurora-install__close{width:28px;height:28px;border-radius:50%;border:1px solid rgba(255,255,255,.22);background:transparent;color:rgba(255,255,255,.72);display:flex;align-items:center;justify-content:center;cursor:pointer}
@media(min-width:769px){ .aurora-install{bottom:20px} }

/* ========== SKELETON — APP FEEL ========== */
.skeleton{position:relative;overflow:hidden;background:#F3F3F3;border-radius:8px}
.skeleton::after{content:'';position:absolute;inset:0;background:linear-gradient(90deg, transparent 0%, rgba(255,255,255,.7) 50%, transparent 100%);transform:translateX(-100%);animation:sk-shine 1.2s infinite}
@keyframes sk-shine{100%{transform:translateX(100%)}}
.skel-card{background:#fff;border:1px solid #EBEBEB;border-radius:14px;overflow:hidden}
.skel-card__img{height:190px;background:#EDEDED}
.skel-card__body{padding:12px;display:flex;flex-direction:column;gap:8px}
.skel-line{height:10px;background:#EDEDED;border-radius:6px}
.skel-line--sm{height:8px;width:60%}
.skel-line--lg{height:12px;width:80%}
.app-loader{position:fixed;inset:0;z-index:2000;background:rgba(255,255,255,.92);backdrop-filter:blur(8px);display:flex;flex-direction:column;align-items:center;justify-content:center;gap:16px;transition:opacity .35s, visibility .35s}
.app-loader.is-hidden{opacity:0;visibility:hidden;pointer-events:none}
.app-loader__spinner{width:28px;height:28px;border:2.5px solid #E5E7EB;border-top-color:#071A3D;border-radius:50%;animation:spin .8s linear infinite}
@keyframes spin{to{transform:rotate(360deg)}}
.app-loader__text{font-family:var(--font-ui);font-size:.78rem;font-weight:500;color:#717171;letter-spacing:.02em}
/* ========== REVEAL ========== */
.reveal{opacity:0;transform:translateY(18px);transition:opacity .7s ease, transform .7s ease}
.reveal.is-visible{opacity:1;transform:translateY(0)}
.reveal-delay-1{transition-delay:.08s}
.reveal-delay-2{transition-delay:.16s}
.reveal-delay-3{transition-delay:.24s}

/* ========== UTIL ========== */
.badge-live{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:100px;background:rgba(16,185,129,.12);border:1px solid rgba(16,185,129,.22);color:#10b981;font-size:.62rem;font-weight:800;letter-spacing:.06em}
.gold-text{color:var(--gold)}
@media(max-width:480px){
  .au-section{padding:52px 16px}
  .aurora-hero__left{padding:22px 16px 16px}
}
</style>
</head>
<body>
<div class="app-loader" id="appLoader" aria-live="polite" aria-busy="true"><div class="app-loader__spinner" aria-hidden="true"></div><div class="app-loader__text">Chargement • Miss Aurora RDC</div></div>

<!-- HEADER SOBRE — AIRBNB PREMIUM -->
<header class="aurora-header" id="auroraHeader">
  <div class="aurora-header__inner">
    <div class="aurora-header__left">
      <a href="index.php" class="aurora-logo" aria-label="<?= esc($siteName ?: 'Miss Aurora RDC') ?> — Accueil">
        <?php if ($siteLogoUrl): ?>
          <img src="<?= $siteLogoUrl ?>" alt="<?= esc($siteName ?: 'Miss Aurora RDC') ?>" width="36" height="36" style="width:36px;height:36px;object-fit:contain;border-radius:6px;background:#FFFFFF;border:1px solid #EBEBEB;padding:2px">
        <?php else: ?>
          <span class="aurora-logo__mark" aria-hidden="true">
            <!-- Lucide: Crown -->
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M2 19a1 1 0 0 0 1 1h18a1 1 0 0 0 1-1v-1H2v1Z"/><path d="M2 19 4 9l4 3 3-5 3 5 4-3 4 10"/><path d="M12 9.5 10.5 12 12 13.5 13.5 12 12 9.5Z" fill="currentColor" stroke="none" opacity=".12"/></svg>
          </span>
        <?php endif; ?>
        <span class="aurora-logo__text">
          <span class="aurora-logo__title"><?= esc(mb_strtoupper($siteName ?: 'MISS AURORA')) ?></span>
          <span class="aurora-logo__sub">RDC • LME GROUP</span>
        </span>
      </a>
      <nav aria-label="Navigation principale">
        <ul class="aurora-nav">
          <li><a href="#accueil" class="aurora-nav__link is-active"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M3 9 12 2l9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9Z"/><path d="M9 22V12h6v10"/></svg> Accueil</a></li>
          <li class="aurora-nav__has-sub">
            <a href="#concours" class="aurora-nav__link" aria-haspopup="true" aria-expanded="false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/><path d="M12 13a1 1 0 0 1 0 2"/></svg> Le concours <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></a>
            <div class="aurora-sub" role="menu">
              <span class="aurora-sub__head">Concours</span>
              <?php foreach ($concoursList as $c):
                $now=time(); $fin=strtotime($c['date_cloture']); $debut=strtotime($c['date_ouverture']);
                $badge=''; if($now<=$fin){ $badge=($now>=$debut?'En cours':'À venir');}
                $cLogo=''; if(!empty($c['url_concours']) && !empty($c['logo_extension'])){ $cLogo = STOCKAGE_DOMAIN . '/admin/uploads/logo_concours/' . $c['url_concours'] . '.' . $c['logo_extension'] . '?v=' . time(); } elseif(!empty($c['logo_concours'])){ $cLogo = STOCKAGE_DOMAIN . '/admin/uploads/logo_concours/' . ltrim($c['logo_concours'],'/') . '?v=' . time(); }
              ?>
                <a href="?concours_id=<?= $c['concours_id'] ?>" class="aurora-sub__item" role="menuitem"><span style="display:inline-flex;align-items:center;gap:8px"><?php if($cLogo): ?><img src="<?= esc($cLogo) ?>" alt="" width="20" height="20" style="width:20px;height:20px;object-fit:contain;border-radius:4px;flex-shrink:0;background:#FFFFFF;border:1px solid #EBEBEB;padding:1px"><?php endif; ?><span><?= esc($c['nom_concours']) ?></span></span><?php if($badge): ?><span class="aurora-sub__badge"><?= $badge ?></span><?php endif; ?></a>
              <?php endforeach; ?>
              <a href="#apropos" class="aurora-sub__item" role="menuitem"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg><span>À propos</span></a>
              <a href="#parcours" class="aurora-sub__item" role="menuitem"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg><span>Parcours Aurora</span></a>
              <a href="#conditions" class="aurora-sub__item" role="menuitem"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="m9 12 2 2 4-4"/></svg><span>Conditions</span></a>
            </div>
          </li>
          <li><a href="#candidates" class="aurora-nav__link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg> Candidates</a></li>
          <li><a href="#vote" class="aurora-nav__link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M19 14c1.5-1.6 3-3.2 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2.08C10.5 3.5 9.26 3 7.5 3A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 3.9 3 5.5l7 7Z"/><path d="m9 11 2 2 4-4"/></svg> Vote</a></li>
          <li><a href="#billetterie" class="aurora-nav__link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M2 9a3 3 0 0 1 3-3h14a3 3 0 0 1 3 3v6a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3V9Z"/><path d="M13 5v14"/></svg> Billetterie</a></li>
          <li><a href="#contact" class="aurora-nav__link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg> Contact</a></li>
        </ul>
      </nav>
    </div>
    <div class="aurora-header__right">
      <div class="aurora-lang" id="langWrap">
        <button class="aurora-lang__btn" id="langBtn" aria-haspopup="true" aria-expanded="false" aria-controls="langMenu">
          <!-- Lucide: Globe -->
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10A15.3 15.3 0 0 1 12 2Z"/></svg>
          FR <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </button>
        <div class="aurora-lang__menu" id="langMenu" role="menu" aria-hidden="true">
          <button class="aurora-lang__opt is-active" role="menuitem" data-lang="fr">Français</button>
          <button class="aurora-lang__opt" role="menuitem" data-lang="ln">Lingala</button>
          <button class="aurora-lang__opt" role="menuitem" data-lang="en">English</button>
        </div>
      </div>
      <a href="candidatures.php" class="aurora-cta aurora-cta--desktop">Devenir candidate</a>
      <button class="aurora-burger" id="auroraBurger" aria-label="Ouvrir le menu" aria-expanded="false" aria-controls="auroraDrawer">
        <!-- Lucide: Menu -->
        <svg class="aurora-burger__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/></svg>
      </button>
    </div>
  </div>
</header>

<div class="aurora-drawer" id="auroraDrawer" aria-hidden="true" aria-label="Navigation mobile">
  <div class="aurora-drawer__head">
    <span style="font-family:var(--font-sans);font-weight:700;font-size:.9rem;color:#222">Menu</span>
    <button class="aurora-drawer__close" id="drawerClose" aria-label="Fermer le menu">
      <!-- Lucide: X -->
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
    </button>
  </div>
  <ul class="aurora-drawer__nav">
    <li><a href="#accueil" class="aurora-drawer__link is-active"><span class="aurora-drawer__link-left"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M3 9 12 2l9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9Z"/><path d="M9 22V12h6v10"/></svg> Accueil</span></a></li>
    <li>
      <a href="#" class="aurora-drawer__link" id="drawerConcoursToggle"><span class="aurora-drawer__link-left"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg> Le concours</span> <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg></a>
      <ul class="aurora-drawer__sub" id="drawerSub">
        <?php foreach ($concoursList as $c):
          $cLogo2=''; if(!empty($c['url_concours']) && !empty($c['logo_extension'])){ $cLogo2 = STOCKAGE_DOMAIN . '/admin/uploads/logo_concours/' . $c['url_concours'] . '.' . $c['logo_extension'] . '?v=' . time(); } elseif(!empty($c['logo_concours'])){ $cLogo2 = STOCKAGE_DOMAIN . '/admin/uploads/logo_concours/' . ltrim($c['logo_concours'],'/') . '?v=' . time(); }
        ?>
          <li><a href="?concours_id=<?= $c['concours_id'] ?>" class="aurora-drawer__sub-link" style="display:flex;align-items:center;gap:8px"><?php if($cLogo2): ?><img src="<?= esc($cLogo2) ?>" alt="" width="18" height="18" style="width:18px;height:18px;object-fit:contain;border-radius:3px;flex-shrink:0;background:#FFFFFF;border:1px solid #EBEBEB;padding:1px"><?php endif; ?><span><?= esc($c['nom_concours']) ?></span></a></li>
        <?php endforeach; ?>
        <li><a href="#apropos" class="aurora-drawer__sub-link">À propos</a></li>
        <li><a href="#parcours" class="aurora-drawer__sub-link">Parcours Aurora</a></li>
        <li><a href="#conditions" class="aurora-drawer__sub-link">Conditions</a></li>
      </ul>
    </li>
    <li><a href="#candidates" class="aurora-drawer__link"><span class="aurora-drawer__link-left"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg> Candidates</span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></a></li>
    <li><a href="#classement" class="aurora-drawer__link"><span class="aurora-drawer__link-left"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 6V12l4 2"/><circle cx="12" cy="12" r="10"/></svg> Classement</span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></a></li>
    <li><a href="#vote" class="aurora-drawer__link"><span class="aurora-drawer__link-left"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M19 14c1.5-1.6 3-3.2 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2.08C10.5 3.5 9.26 3 7.5 3A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 3.9 3 5.5l7 7Z"/><path d="m9 11 2 2 4-4"/></svg> Vote</span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></a></li>
    <li><a href="#billetterie" class="aurora-drawer__link"><span class="aurora-drawer__link-left"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M2 9a3 3 0 0 1 3-3h14a3 3 0 0 1 3 3v6a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3V9Z"/><path d="M13 5v14"/></svg> Billetterie</span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></a></li>
    <li><a href="#galerie" class="aurora-drawer__link"><span class="aurora-drawer__link-left"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="M21 15l-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg> Galerie</span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></a></li>
    <li><a href="#partenaires" class="aurora-drawer__link"><span class="aurora-drawer__link-left"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/></svg> Partenaires</span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></a></li>
    <li><a href="#contact" class="aurora-drawer__link"><span class="aurora-drawer__link-left"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg> Contact</span><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg></a></li>
  </ul>
  <div class="aurora-drawer__foot">
    <a href="candidatures.php" class="aurora-drawer__cta">Devenir candidate</a>
    <p style="text-align:center;font-family:var(--font-ui);font-size:.8125rem;color:#717171;line-height:1.5">
      <span style="display:inline-flex;align-items:center;gap:6px"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-3.07"/><path d="M22 16.92v3"/></svg> +243 860 370 727</span>
      <br>40, Av. Kasangulu, Kasa-Vubu, Kinshasa
    </p>
  </div>
</div>
<div class="aurora-overlay" id="auroraOverlay" aria-hidden="true"></div>

<!-- HERO — MODERN EDITORIAL (misslualaba inspired × Aurora premium) -->
<section class="aurora-hero" id="accueil" aria-labelledby="heroTitle">
  <div class="aurora-hero__bg" aria-hidden="true">
    <div class="aurora-hero__bg-gradient"></div>
    <div class="aurora-hero__bg-slides" id="heroBgSlides" aria-hidden="true">
      <?php if(!empty($heroCandidates)): foreach($heroCandidates as $i=>$hc):
        $bg = getCandidatePhotoUrl($hc['photo_officielle'] ?? '');
        if (empty($bg)) { $bg='https://images.unsplash.com/photo-1529626455594-4ff0802cfb7e?w=1200&h=800&fit=crop'; }
      ?>
        <div class="aurora-hero__bg-slide <?= $i===0?'is-active':'' ?>" style="background-image:url('<?= esc($bg) ?>')"></div>
      <?php endforeach; else: ?>
        <div class="aurora-hero__bg-slide is-active" style="background-image:url('https://images.unsplash.com/photo-1529626455594-4ff0802cfb7e?w=1200&h=900&fit=crop')"></div>
        <div class="aurora-hero__bg-slide" style="background-image:url('https://images.unsplash.com/photo-1524504388940-b1c1722653e1?w=1200&h=900&fit=crop')"></div>
        <div class="aurora-hero__bg-slide" style="background-image:url('https://images.unsplash.com/photo-1487412720507-e7ab37603c6f?w=1200&h=900&fit=crop')"></div>
        <div class="aurora-hero__bg-slide" style="background-image:url('https://images.unsplash.com/photo-1531746020798-e6953c6e8e04?w=1200&h=900&fit=crop')"></div>
      <?php endif; ?>
    </div>
    <div class="aurora-hero__bg-texture"></div>
    <div class="aurora-hero__bg-overlay"></div>
  </div>
  <div class="aurora-hero__shell">
    <div class="aurora-hero__left">
      <div class="aurora-hero__eyebrow"><span class="aurora-hero__eyebrow-concours">Concours officiel • Édition <?= esc($auroraYear) ?></span><span class="aurora-hero__eyebrow-divider" aria-hidden="true"></span><span class="aurora-hero__eyebrow-status">• Inscriptions ouvertes</span></div>
      <div class="aurora-hero__lockup">
        <?php if(!empty($concoursLogoUrl)): ?><span class="aurora-hero__lockup-logo"><img src="<?= esc($concoursLogoUrl) ?>" alt="Logo <?= esc($currentConcours['nom_concours'] ?? 'Concours') ?>"></span><?php endif; ?>
        <h1 class="aurora-hero__title" id="heroTitle">
        <span>MISS</span>
        <strong>AURORA <span style="font-weight:300;color:#fff">RDC</span></strong>
      </h1>
      </div>
      <div class="aurora-hero__devise">
        <span class="aurora-hero__devise-line"></span>
        <span class="aurora-hero__devise-text">La beauté au service du changement</span>
      </div>
      <p class="aurora-hero__desc">
        <strong style="color:#fff;font-weight:600">Révéler la lumière qui inspire l’avenir.</strong><br>
        Miss Aurora RDC révèle une nouvelle génération de femmes congolaises — beauté, leadership, engagement et excellence. Une initiative <strong style="color:var(--gold-light)">LME GROUP</strong> — Inspirer, Former, Transformer.
      </p>
      <div class="aurora-hero__actions">
        <a href="candidatures.php" class="btn-primary">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z"/><path d="M5 21h14"/></svg>
          Devenir candidate
        </a>
        <a href="#candidates" class="btn-ghost">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
          Découvrir les candidates
        </a>
      </div>
      <div class="aurora-hero__meta" aria-label="Informations clés">
        <div class="aurora-hero__meta-item">
          <span class="aurora-hero__meta-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg></span>
          <span><span class="aurora-hero__meta-label">Ville</span><span class="aurora-hero__meta-value">Kinshasa, RDC</span></span>
        </div>
        <div class="aurora-hero__meta-item">
          <span class="aurora-hero__meta-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg></span>
          <span><span class="aurora-hero__meta-label">Édition</span><span class="aurora-hero__meta-value"><?= esc($auroraYear) ?> • Nationale</span></span>
        </div>
        <div class="aurora-hero__meta-item">
          <span class="aurora-hero__meta-icon"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 11a4 4 0 0 1 0 7"/></svg></span>
          <span><span class="aurora-hero__meta-label">Organisateur</span><span class="aurora-hero__meta-value">LME GROUP</span></span>
        </div>
      </div>
    </div>
    <div class="aurora-hero__right" aria-label="Candidates à la une">
      <div class="hero-cc" id="heroCandidatesCarousel" aria-roledescription="carousel" aria-label="Photos des candidates">
        <div class="hero-cc__viewport" id="heroCcViewport">
          <div class="hero-cc__track" id="heroCcTrack">
            <?php if(!empty($heroCandidates)): foreach($heroCandidates as $ci=>$hc):
              $ccPhoto = getCandidatePhotoUrl($hc['photo_officielle'] ?? '');
              if (empty($ccPhoto)) $ccPhoto = 'https://images.unsplash.com/photo-1529626455594-4ff0802cfb7e?w=600&h=750&fit=crop';
              $ccCity = $hc['ville_origine'] ?? 'Kinshasa';
              $ccCode = $hc['code_participante'] ?? $ci+1;
            ?>
            <div class="hero-cc__slide <?= $ci===0?'is-active':'' ?>" role="group" aria-roledescription="slide" aria-label="<?= $ci+1 ?> sur <?= count($heroCandidates) ?>">
              <div class="hero-cc__bg" style="background-image:url('<?= esc($ccPhoto) ?>')" aria-hidden="true"></div>
              <span class="hero-cc__skeleton" aria-hidden="true"></span>
              <img class="lazy-img" data-src="<?= esc($ccPhoto) ?>?v=<?= time() ?>" alt="<?= esc($hc['nom_complet']) ?>" loading="<?= $ci===0?'eager':'lazy' ?>" decoding="async" fetchpriority="<?= $ci===0?'high':'low' ?>" onerror="this.onerror=null;this.src='https://images.unsplash.com/photo-1529626455594-4ff0802cfb7e?w=600&h=750&fit=crop';this.classList.add('is-loaded')">
              <div class="hero-cc__fade" aria-hidden="true"></div>
              <div class="hero-cc__info">
                <div class="hero-cc__name"><?= esc($hc['nom_complet']) ?></div>
                <div class="hero-cc__meta">
                  <span class="hero-cc__chip"><span class="hero-cc__chip-dot"></span> N° <?= esc($ccCode) ?></span>
                  <span class="hero-cc__chip"><?= esc($ccCity) ?></span>
                  <span class="hero-cc__chip">Candidate <?= esc($auroraYear) ?></span>
                </div>
              </div>
            </div>
            <?php endforeach; else: ?>
            <div class="hero-cc__slide is-active">
              <div class="hero-cc__bg" style="background-image:url('https://images.unsplash.com/photo-1529626455594-4ff0802cfb7e?w=600&h=750&fit=crop')" aria-hidden="true"></div>
              <span class="hero-cc__skeleton"></span>
              <img class="lazy-img is-loaded" src="https://images.unsplash.com/photo-1529626455594-4ff0802cfb7e?w=600&h=750&fit=crop" alt="Miss Aurora RDC" loading="eager">
              <div class="hero-cc__fade"></div>
              <div class="hero-cc__info"><div class="hero-cc__name">Miss Aurora RDC</div><div class="hero-cc__meta"><span class="hero-cc__chip">Édition <?= esc($auroraYear) ?></span></div></div>
            </div>
            <?php endif; ?>
          </div>
          <div class="hero-cc__nav" aria-hidden="false">
            <button class="hero-cc__btn" id="heroCcPrev" aria-label="Précédente"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg></button>
            <button class="hero-cc__btn" id="heroCcNext" aria-label="Suivante"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 18 6-6-6-6"/></svg></button>
          </div>
          <div class="hero-cc__dots" id="heroCcDots" role="tablist" aria-label="Navigation carousel"></div>
          <div class="hero-cc__progress" aria-hidden="true"><div class="hero-cc__progress-fill" id="heroCcProgress"></div></div>
        </div>
      </div>
      <div class="aurora-hero__live" role="status" aria-live="polite">
        <div class="aurora-hero__live-left">
          <span class="aurora-hero__live-dot" aria-hidden="true"></span>
          <div style="min-width:0">
            <div class="aurora-hero__live-label">Sélection en cours • Live</div>
            <div class="aurora-hero__live-title"><?= count($allCandidates) > 0 ? count($allCandidates) . ' candidates • Édition ' . esc($auroraYear) . ' — Kinshasa' : 'Candidates retenues : publication après le casting du 15 août 2026' ?></div>
          </div>
        </div>
        <div class="aurora-hero__live-progress" aria-hidden="true"><div class="aurora-hero__live-fill" style="width: <?= count($allCandidates)>0 ? min(100, max(24, count($allCandidates)*7 + 28)) : 32 ?>%"></div></div>
      </div>
      <!-- Hidden legacy slider nodes for JS compatibility (no display) -->
      <div style="display:none" aria-hidden="true">
        <div id="heroSlides" class="aurora-hero__slides"><div class="aurora-hero__slide is-active" data-name="Miss Aurora RDC"></div></div>
        <span id="heroCurrent">01</span><span id="heroTotal">01</span><span id="heroBar"></span><div id="heroProgress"></div><div id="heroGhost"></div><div id="heroVisual"></div>
        <button id="heroPrev"></button><button id="heroNext"></button>
      </div>
    </div>
  </div>
</section>

<!-- TICKER LIVE -->
<div class="aurora-ticker" role="region" aria-label="En direct — informations live">
  <div class="aurora-ticker__inner">
    <div class="aurora-ticker__row">
      <div class="aurora-ticker__label-wrap">
        <span class="aurora-ticker__label">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M6 3h12l-1 9a5 5 0 0 1-10 0L6 3Z"/><path d="M5 3a5 5 0 0 0 5 5"/><path d="M19 3a5 5 0 0 1-5 5"/><path d="M12 22v-3"/><path d="M8 22h8"/></svg>
          <span class="aurora-ticker__label-dot" aria-hidden="true"></span>
          LIVE — CLASSEMENT
        </span>
      </div>
      <div class="aurora-ticker__viewport">
        <div class="aurora-ticker__track" id="tickerRanking"><span class="aurora-ticker__item"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 8a6 6 0 0 1 6 6v2H6v-2a6 6 0 0 1 6-6Z"/><path d="M8 14v4a4 4 0 0 0 4 4 4 4 0 0 0 4-4v-4"/></svg> Chargement du classement Aurora…</span></div>
      </div>
    </div>
    <div class="aurora-ticker__row">
      <div class="aurora-ticker__label-wrap">
        <span class="aurora-ticker__label aurora-ticker__label--live">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M19 14c1.5-1.6 3-3.2 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2.08C10.5 3.5 9.26 3 7.5 3A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 3.9 3 5.5l7 7Z"/><path d="m9 11 2 2 4-4"/></svg>
          <span class="aurora-ticker__label-dot" aria-hidden="true"></span>
          LIVE — DERNIERS VOTES
        </span>
      </div>
      <div class="aurora-ticker__viewport">
        <div class="aurora-ticker__track" id="tickerVotes"><span class="aurora-ticker__item"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 5a3 3 0 0 1 3 3 2.5 2.5 0 0 1-5 0 3 3 0 0 1 3-3Z"/><path d="M12 8v8"/><path d="M8 12h8"/></svg> Chargement des derniers votes…</span></div>
      </div>
    </div>
  </div>
</div>

<!-- LIVE BANNER ETAPES -->
<?php if(!empty($etapes)): ?>
<section class="au-section au-section--light" style="padding:22px 40px" aria-label="Étapes en cours">
  <div class="au-wrap">
    <div style="display:flex;gap:12px;overflow-x:auto;padding:6px 2px 8px;scrollbar-width:thin">
      <?php foreach($etapes as $ev):
        $debut=strtotime($ev['date_ouverture']??'now'); $fin=strtotime($ev['date_cloture']??'now'); $nowTs=time();
        if($nowTs>=$debut && $nowTs<=$fin){ $status='En cours'; $cls='ongoing'; $prog=($fin-$debut)>0? min(100, round((($nowTs-$debut)/($fin-$debut))*100)):0;}
        elseif($nowTs<$debut){ $status='À venir'; $cls='upcoming'; $prog=0;}
        else{ $status='Terminé'; $cls='done'; $prog=100;}
      ?>
      <div style="flex:0 0 auto;min-width:240px;max-width:320px;padding:14px 16px;border-radius:14px;border:1px solid <?= $cls==='ongoing'?'rgba(16,185,129,.22)':'rgba(5,11,22,.06)' ?>;background:<?= $cls==='ongoing'?'rgba(16,185,129,.06)':'#fff' ?>">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
          <strong style="font-size:.86rem;color:var(--royal-900);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= esc($ev['nom_etape']??'Étape '.$ev['numero_ordre']) ?></strong>
          <span style="font-size:.62rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;padding:3px 8px;border-radius:999px;background:<?= $cls==='ongoing'?'rgba(16,185,129,.14)':($cls==='upcoming'?'rgba(212,175,55,.14)':'rgba(5,11,22,.06)') ?>;color:<?= $cls==='ongoing'?'#0f7a52':($cls==='upcoming'?'#8a6a00':'#64748b') ?>"><?= $status ?></span>
        </div>
        <div style="font-size:.72rem;color:#64748b;margin-top:6px;display:flex;align-items:center;gap:6px">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg>
          <?= date('d/m/Y',$debut) ?> → <?= date('d/m/Y',$fin) ?>
        </div>
        <?php if($cls==='ongoing'): ?>
        <div style="margin-top:10px"><div style="height:3px;background:rgba(5,11,22,.08);border-radius:999px;overflow:hidden"><div style="height:100%;width:<?= $prog ?>%;background:linear-gradient(90deg, var(--gold), var(--gold-light))"></div></div><div style="font-size:.68rem;color:#64748b;margin-top:4px"><?= $prog ?>% écoulé</div></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- POURQUOI AURORA -->
<section class="au-section au-section--light aurora-why" id="pourquoi" aria-labelledby="whyTitle">
  <div class="au-wrap">
    <div class="au-head--center reveal">
      <div class="au-eyebrow au-eyebrow--center">Symbolique Aurora</div>
      <h2 class="au-title" id="whyTitle">Pourquoi <em>Aurora ?</em></h2>
      <div class="au-bar au-bar--center"></div>
      <p class="au-subtitle">Aurora signifie l’aube — la lumière qui se lève, l’espoir qui renaît, l’émergence d’une génération et la promesse d’un avenir. Une identité pensée pour la RDC.</p>
    </div>
    <div class="aurora-why__grid">
      <div class="aurora-why__card reveal">
        <span class="aurora-why__num">01</span>
        <div class="aurora-why__icon aurora-why__icon--light"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="5"/><path d="M12 1v2"/><path d="M12 21v2"/><path d="M4.22 4.22l1.42 1.42"/><path d="M18.36 18.36l1.42 1.42"/><path d="M1 12h2"/><path d="M21 12h2"/><path d="M4.22 19.78l1.42-1.42"/><path d="M18.36 5.64l1.42-1.42"/></svg></div>
        <h3 class="aurora-why__title">Lumière</h3>
        <p class="aurora-why__text">La lumière qui révèle les talents cachés et met en valeur la beauté, l’intelligence et le charisme de la femme congolaise.</p>
        <span class="aurora-why__line"></span>
      </div>
      <div class="aurora-why__card reveal reveal-delay-1">
        <span class="aurora-why__num">02</span>
        <div class="aurora-why__icon aurora-why__icon--hope"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22c5-3 8-6 8-10a8 8 0 0 0-16 0c0 4 3 7 8 10z"/><path d="M12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"/></svg></div>
        <h3 class="aurora-why__title">Espoir</h3>
        <p class="aurora-why__text">L’espoir d’une génération nouvelle qui rêve grand, agit avec courage et porte haut les couleurs de la RDC.</p>
        <span class="aurora-why__line"></span>
      </div>
      <div class="aurora-why__card reveal reveal-delay-2">
        <span class="aurora-why__num">03</span>
        <div class="aurora-why__icon aurora-why__icon--emerge"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M7 17l9-9"/><path d="M8 8h8v8"/><path d="M12 22c5 0 9-4 9-9"/></svg></div>
        <h3 class="aurora-why__title">Émergence</h3>
        <p class="aurora-why__text">L’émergence de femmes leaders capables de transformer leur communauté par l’engagement social et culturel.</p>
        <span class="aurora-why__line"></span>
      </div>
      <div class="aurora-why__card reveal reveal-delay-3">
        <span class="aurora-why__num">04</span>
        <div class="aurora-why__icon aurora-why__icon--future"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3 7h7l-5.5 4 2 7L12 16l-6.5 4 2-7L2 9h7z"/><path d="M12 16v6"/></svg></div>
        <h3 class="aurora-why__title">Avenir</h3>
        <p class="aurora-why__text">Une vision tournée vers l’avenir : former, inspirer et transformer pour un Congo d’excellence.</p>
        <span class="aurora-why__line"></span>
      </div>
    </div>
  </div>
</section>

<!-- A PROPOS -->
<section class="au-section au-section--ivory" id="apropos" aria-labelledby="aboutTitle">
  <div class="au-wrap">
    <div class="au-eyebrow reveal">À propos • Miss Aurora RDC</div>
    <div class="aurora-about">
      <div class="aurora-about__left reveal">
        <h2 class="au-title" id="aboutTitle">Une plateforme pour <em>révéler</em> la femme congolaise</h2>
        <div class="au-bar"></div>
        <p class="aurora-about__text"><strong>Miss Aurora RDC</strong> est un concours national de beauté, de leadership et d’engagement social organisé par <strong>LME GROUP</strong>. Bien plus qu’un concours, c’est une plateforme de développement personnel, de formation et de représentation internationale de la RDC.</p>
        <p class="aurora-about__text">Sa vision : faire de Miss Aurora RDC une référence nationale et internationale dans la promotion de la beauté intelligente, du leadership féminin et de l’engagement citoyen. Sa mission : identifier, former et accompagner les jeunes femmes afin qu’elles deviennent des ambassadrices du développement social, culturel et économique.</p>
        <blockquote class="aurora-about__quote">
          <span class="aurora-about__quote-mark" aria-hidden="true">”</span>
          <p class="aurora-about__quote-text">« La beauté au service du changement — Révéler la lumière qui inspire l’avenir. »</p>
        </blockquote>
        <div class="aurora-about__values" role="list" aria-label="Chiffres clés">
          <div class="aurora-about__val" role="listitem">
            <span class="aurora-about__val-icon" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/><path d="M8 18h.01"/><path d="M12 18h.01"/><path d="M16 18h.01"/></svg></span>
            <div class="aurora-about__val-num">18–28</div><div class="aurora-about__val-label">Âge requis</div>
          </div>
          <div class="aurora-about__val" role="listitem">
            <span class="aurora-about__val-icon" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg></span>
            <div class="aurora-about__val-num">RDC</div><div class="aurora-about__val-label">Nationale</div>
          </div>
          <div class="aurora-about__val" role="listitem">
            <span class="aurora-about__val-icon" aria-hidden="true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22V12h6v10"/><path d="M9 12a6 6 0 0 1 6 0"/></svg></span>
            <div class="aurora-about__val-num">LME</div><div class="aurora-about__val-label">Organisateur</div>
          </div>
        </div>
        <div style="display:flex;gap:10px;margin-top:14px;flex-wrap:wrap">
          <a href="#valeurs" style="display:inline-flex;align-items:center;gap:7px;padding:11px 18px;border-radius:8px;background:#FFFFFF;border:1px solid #DDDDDD;color:#222222;font-family:var(--font-ui);font-size:.8125rem;font-weight:500;transition:background .16s, border-color .16s;white-space:nowrap"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 2l3 7h7l-5.5 4 2 7L12 16l-6.5 4 2-7L2 9h7z"/></svg> Nos valeurs <span aria-hidden="true" style="margin-left:2px">→</span></a>
          <a href="candidatures.php" style="display:inline-flex;align-items:center;gap:7px;padding:11px 18px;border-radius:8px;background:#222222;border:1px solid #222222;color:#FFFFFF;font-family:var(--font-ui);font-size:.8125rem;font-weight:600;transition:background .16s, border-color .16s;white-space:nowrap">Devenir candidate</a>
        </div>
      </div>
      <div class="aurora-about__visual reveal reveal-delay-1" aria-hidden="true">
        <div class="aurora-about__col">
          <div class="aurora-about__img aurora-about__img--a"><img src="https://images.unsplash.com/photo-1529626455594-4ff0802cfb7e?w=600&h=700&fit=crop" alt="Miss Aurora — Élégance" loading="lazy"><span class="aurora-about__tag">Élégance</span></div>
          <div class="aurora-about__img aurora-about__img--b"><img src="https://images.unsplash.com/photo-1531746020798-e6953c6e8e04?w=600&h=500&fit=crop" alt="Miss Aurora — Leadership" loading="lazy"><span class="aurora-about__tag">Leadership</span></div>
        </div>
        <div class="aurora-about__col aurora-about__col--offset">
          <div class="aurora-about__img aurora-about__img--b"><img src="https://images.unsplash.com/photo-1487412720507-e7ab37603c6f?w=600&h=500&fit=crop" alt="Miss Aurora — Engagement" loading="lazy"><span class="aurora-about__tag">Engagement</span></div>
          <div class="aurora-about__img aurora-about__img--a"><img src="https://images.unsplash.com/photo-1517841905240-472988babdf9?w=600&h=700&fit=crop" alt="Miss Aurora — Excellence" loading="lazy"><span class="aurora-about__tag">Excellence</span></div>
        </div>
        <div class="aurora-about__badge" aria-hidden="true">
          <span class="aurora-about__badge-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z"/><path d="M5 21h14"/></svg></span>
          <span class="aurora-about__badge-num">2026</span>
          <span class="aurora-about__badge-label">Édition Nationale</span>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- VALEURS -->
<section class="au-section au-section--dark" id="valeurs" aria-labelledby="valuesTitle">
  <div class="au-wrap">
    <div class="au-head--center reveal">
      <div class="au-eyebrow au-eyebrow--center" style="color:var(--gold-light)">Nos valeurs fondamentales</div>
      <h2 class="au-title" id="valuesTitle">Ce qui <em>guide</em> chaque Aurora — Miss Aurora RDC</h2>
      <div class="au-bar au-bar--center"></div>
      <p class="au-subtitle">Six principes qui façonnent chaque candidate et chaque action du concours Miss Aurora RDC.</p>
    </div>
    <div class="aurora-why__grid" style="margin-top:36px">
      <div class="aurora-why__card reveal" style="background:rgba(255,255,255,.04);border-color:rgba(255,255,255,.06);backdrop-filter:blur(8px)">
        <div class="aurora-why__icon aurora-why__icon--light"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3 7h7l-5.5 4 2 7L12 16l-6.5 4 2-7L2 9h7z"/></svg></div>
        <h3 class="aurora-why__title" style="color:#fff">Leadership</h3>
        <p class="aurora-why__text" style="color:rgba(255,255,255,.62)">Former des femmes capables de porter une vision, d’inspirer et de conduire le changement.</p>
      </div>
      <div class="aurora-why__card reveal reveal-delay-1" style="background:rgba(255,255,255,.04);border-color:rgba(255,255,255,.06);backdrop-filter:blur(8px)">
        <div class="aurora-why__icon" style="background:linear-gradient(135deg, #F472B6, #7C1D3A);box-shadow:0 8px 20px rgba(124,29,58,.28);width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;color:#fff"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a10 10 0 0 1 10 10c0 5-4 8-10 10-6-2-10-5-10-10A10 10 0 0 1 12 2z"/></svg></div>
        <h3 class="aurora-why__title" style="color:#fff">Excellence</h3>
        <p class="aurora-why__text" style="color:rgba(255,255,255,.62)">Viser l’excellence dans la présentation, l’expression, la culture générale et l’engagement.</p>
      </div>
      <div class="aurora-why__card reveal reveal-delay-2" style="background:rgba(255,255,255,.04);border-color:rgba(255,255,255,.06);backdrop-filter:blur(8px)">
        <div class="aurora-why__icon" style="background:linear-gradient(135deg, #60A5FA, #1E40AF);box-shadow:0 8px 20px rgba(30,64,175,.28);width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;color:#fff"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div>
        <h3 class="aurora-why__title" style="color:#fff">Discipline</h3>
        <p class="aurora-why__text" style="color:rgba(255,255,255,.62)">La rigueur, le respect du règlement et la constance comme fondations du leadership.</p>
      </div>
      <div class="aurora-why__card reveal reveal-delay-3" style="background:rgba(255,255,255,.04);border-color:rgba(255,255,255,.06);backdrop-filter:blur(8px)">
        <div class="aurora-why__icon" style="background:linear-gradient(135deg, #34D399, #065F46);box-shadow:0 8px 20px rgba(5,150,105,.28);width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;color:#fff"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 11a4 4 0 0 0-3-3"/></svg></div>
        <h3 class="aurora-why__title" style="color:#fff">Respect</h3>
        <p class="aurora-why__text" style="color:rgba(255,255,255,.62)">Respect de soi, des autres, de la culture congolaise et des valeurs humaines.</p>
      </div>
      <div class="aurora-why__card reveal" style="background:rgba(255,255,255,.04);border-color:rgba(255,255,255,.06);backdrop-filter:blur(8px)">
        <div class="aurora-why__icon aurora-why__icon--emerge"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 14c1.5-2 2-4 2-6a6 6 0 0 0-12 0c0 2 .5 4 2 6l6 6 4-6z"/></svg></div>
        <h3 class="aurora-why__title" style="color:#fff">Engagement social</h3>
        <p class="aurora-why__text" style="color:rgba(255,255,255,.62)">Porter un projet à impact pour sa communauté et incarner la solidarité.</p>
      </div>
      <div class="aurora-why__card reveal reveal-delay-1" style="background:rgba(255,255,255,.04);border-color:rgba(255,255,255,.06);backdrop-filter:blur(8px)">
        <div class="aurora-why__icon" style="background:linear-gradient(135deg, #F3D77A, #C9A227);box-shadow:0 8px 20px rgba(212,175,55,.28);width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;color:#fff"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17 5.8 21.3l2.4-7.4L2 9.4h7.6z"/></svg></div>
        <h3 class="aurora-why__title" style="color:#fff">Patriotisme</h3>
        <p class="aurora-why__text" style="color:rgba(255,255,255,.62)">Aimer, valoriser et rayonner la culture congolaise en RDC et à l’international.</p>
      </div>
    </div>
  </div>
</section>

<!-- VALEURS LME GROUP — distinct -->
<section class="au-section au-section--light" id="valeurs-lme" aria-labelledby="lmeValeursTitle">
  <div class="au-wrap">
    <div class="au-head--center reveal">
      <div class="au-eyebrow au-eyebrow--center">Valeurs LME GROUP</div>
      <h2 class="au-title" id="lmeValeursTitle">Les valeurs de <em>LME GROUP</em></h2>
      <div class="au-bar au-bar--center"></div>
      <p class="au-subtitle">Six principes qui guident l’action de LME GROUP au quotidien.</p>
    </div>
    <div class="aurora-why__grid" style="margin-top:36px">
      <div class="aurora-why__card reveal">
        <div class="aurora-why__icon" style="background:linear-gradient(135deg, #F3D77A, #C9A227);box-shadow:0 8px 20px rgba(212,175,55,.28);width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;color:#fff"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17 5.8 21.3l2.4-7.4L2 9.4h7.6z"/></svg></div>
        <h3 class="aurora-why__title">Excellence</h3>
        <p class="aurora-why__text">Viser l’excellence dans chaque projet et chaque accompagnement.</p>
      </div>
      <div class="aurora-why__card reveal reveal-delay-1">
        <div class="aurora-why__icon" style="background:linear-gradient(135deg, #60A5FA, #1E40AF);box-shadow:0 8px 20px rgba(30,64,175,.28);width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;color:#fff"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="M12 12v-8"/><path d="M12 12h.01"/></svg></div>
        <h3 class="aurora-why__title">Intégrité</h3>
        <p class="aurora-why__text">Agir avec honnêteté, transparence et éthique en toutes circonstances.</p>
      </div>
      <div class="aurora-why__card reveal reveal-delay-2">
        <div class="aurora-why__icon" style="background:linear-gradient(135deg, #34D399, #065F46);box-shadow:0 8px 20px rgba(5,150,105,.28);width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;color:#fff"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div>
        <h3 class="aurora-why__title">Respect</h3>
        <p class="aurora-why__text">Respect des personnes, des cultures et des engagements pris.</p>
      </div>
      <div class="aurora-why__card reveal reveal-delay-3">
        <div class="aurora-why__icon" style="background:linear-gradient(135deg, #A78BFA, #5B21B6);box-shadow:0 8px 20px rgba(91,33,182,.28);width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;color:#fff"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 2l3 7h7l-5.5 4 2 7L12 16l-6.5 4 2-7L2 9h7z"/></svg></div>
        <h3 class="aurora-why__title">Leadership</h3>
        <p class="aurora-why__text">Inspirer et fédérer autour d’une vision commune et ambitieuse.</p>
      </div>
      <div class="aurora-why__card reveal">
        <div class="aurora-why__icon" style="background:linear-gradient(135deg, #F472B6, #BE185D);box-shadow:0 8px 20px rgba(190,24,93,.28);width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;color:#fff"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg></div>
        <h3 class="aurora-why__title">Innovation</h3>
        <p class="aurora-why__text">Innover pour créer des expériences et des plateformes d’impact.</p>
      </div>
      <div class="aurora-why__card reveal reveal-delay-1">
        <div class="aurora-why__icon" style="background:linear-gradient(135deg, #FBBF24, #D97706);box-shadow:0 8px 20px rgba(217,119,6,.28);width:52px;height:52px;border-radius:14px;display:flex;align-items:center;justify-content:center;color:#fff"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 22c5-3 8-6 8-10a8 8 0 0 0-16 0c0 4 3 7 8 10z"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg></div>
        <h3 class="aurora-why__title">Solidarité</h3>
        <p class="aurora-why__text">Placer la solidarité et l’entraide au cœur de l’action collective.</p>
      </div>
    </div>
  </div>
</section>

<!-- LME GROUP -->
<section class="au-section au-section--ivory" id="organisation" aria-labelledby="lmeTitle">
  <div class="au-wrap">
    <div class="au-head--center reveal">
      <div class="au-eyebrow au-eyebrow--center">L’organisation derrière la vision</div>
      <h2 class="au-title" id="lmeTitle">LME GROUP — <em>Inspirer, Former, Transformer</em></h2>
      <div class="au-bar au-bar--center"></div>
      <p class="au-subtitle">LME GROUP œuvre à créer des plateformes d’expression, de formation et de valorisation des talents congolais — particulièrement la jeunesse et la femme.</p>
    </div>
    <div class="lme-card reveal">
      <div class="lme-card__main">
        <div class="lme-card__eyebrow"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3 7h7l-5.5 4 2 7L12 16l-6.5 4 2-7L2 9h7z"/></svg> LME GROUP • Kinshasa, RDC</div>
        <h3 class="lme-card__title">Une structure congolaise <em>engagée</em></h3>
        <p class="lme-card__desc">Spécialisée dans l’événementiel, la communication, la promotion culturelle, le leadership, la formation et le développement communautaire. LME GROUP crée des opportunités de visibilité et de développement personnel à fort impact social, culturel et économique.</p>
        <div class="lme-card__grid" style="grid-template-columns:repeat(3,1fr)">
          <div class="lme-card__pill"><div class="lme-card__pill-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div><div class="lme-card__pill-title">Événementiel</div><div class="lme-card__pill-text">Création & org.</div></div>
          <div class="lme-card__pill"><div class="lme-card__pill-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-1.9.5 4.48 4.48 0 0 0 1.96-2.48 8.94 8.94 0 0 1-2.85 1.09 4.48 4.48 0 0 0-7.63 4.08A12.7 12.7 0 0 1 3.14 9.48a4.48 4.48 0 0 0 1.38 5.97 4.44 4.44 0 0 1-2.03-.56v.06a4.48 4.48 0 0 0 3.59 4.38 4.48 4.48 0 0 1-2.02.08 4.48 4.48 0 0 0 4.18 3.11A8.98 8.98 0 0 1 2 19.54a12.66 12.66 0 0 0 6.86 2.01c8.24 0 12.74-6.82 12.74-12.74v-.58A9.1 9.1 0 0 0 23 6.18a8.93 8.93 0 0 1-2.6.71z"/></svg></div><div class="lme-card__pill-title">Communication</div><div class="lme-card__pill-text">Stratégie & médias</div></div>
          <div class="lme-card__pill"><div class="lme-card__pill-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a10 10 0 0 0-9 14l9 6 9-6A10 10 0 0 0 12 2z"/></svg></div><div class="lme-card__pill-title">Culture</div><div class="lme-card__pill-text">Valorisation</div></div>
          <div class="lme-card__pill"><div class="lme-card__pill-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3 7h7l-5.5 4 2 7L12 16l-6.5 4 2-7L2 9h7z"/></svg></div><div class="lme-card__pill-title">Leadership</div><div class="lme-card__pill-text">Développement</div></div>
          <div class="lme-card__pill"><div class="lme-card__pill-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6"/><path d="M6 12H4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6a2 2 0 0 0-2-2h-2"/><path d="M12 12a4 4 0 0 0-4-4 4 4 0 0 0-4 4v2h8v-2z"/><circle cx="12" cy="7" r="4"/></svg></div><div class="lme-card__pill-title">Formation</div><div class="lme-card__pill-text">Coaching & ateliers</div></div>
          <div class="lme-card__pill"><div class="lme-card__pill-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22c5-3 8-6 8-10a8 8 0 0 0-16 0c0 4 3 7 8 10z"/><path d="M12 14a4 4 0 0 0 2-2"/><path d="M12 12h.01"/></svg></div><div class="lme-card__pill-title">Développement</div><div class="lme-card__pill-text">Communautaire</div></div>
        </div>
        <div class="lme-card__devises">
          <div class="lme-card__devise"><div class="lme-card__devise-label">Devise</div><div class="lme-card__devise-value">« Inspirer, Former, Transformer »</div></div>
          <div class="lme-card__devise"><div class="lme-card__devise-label">Slogan</div><div class="lme-card__devise-value">« Ensemble pour un avenir d’excellence »</div></div>
        </div>
      </div>
      <div class="lme-card__side">
        <h4 class="lme-card__side-title">Coordonnées officielles</h4>
        <div class="lme-card__contact">
          <div class="lme-card__contact-item"><span class="lme-card__contact-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg></span><span><span class="lme-card__contact-label">Adresse</span><span class="lme-card__contact-value">40, Avenue Kasangulu<br>Commune de Kasa-Vubu<br>Kinshasa, RDC</span></span></div>
          <div class="lme-card__contact-item"><span class="lme-card__contact-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.27h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.9a16 16 0 0 0 6.09 6.09l.91-.91a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg></span><span><span class="lme-card__contact-label">Téléphone / WhatsApp</span><span class="lme-card__contact-value"><a href="tel:+243860370727">+243 860 370 727</a></span></span></div>
          <div class="lme-card__contact-item"><span class="lme-card__contact-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg></span><span><span class="lme-card__contact-label">Email</span><span class="lme-card__contact-value"><a href="mailto:actutara@gmail.com">actutara@gmail.com</a></span></span></div>
          <div class="lme-card__contact-item"><span class="lme-card__contact-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15 15 0 0 0 0 20"/></svg></span><span><span class="lme-card__contact-label">Site web</span><span class="lme-card__contact-value">En cours de création</span></span></div>
          <div class="lme-card__contact-item"><span class="lme-card__contact-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span><span><span class="lme-card__contact-label">RCCM</span><span class="lme-card__contact-value">En cours de procédure</span></span></div>
          <div class="lme-card__contact-item"><span class="lme-card__contact-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span><span><span class="lme-card__contact-label">ID NAT</span><span class="lme-card__contact-value">En cours de procédure</span></span></div>
        </div>
        <div class="lme-card__actions">
          <a href="https://wa.me/243860370727" target="_blank" class="btn-primary" style="padding:10px 18px;font-size:.8rem">WhatsApp</a>
          <a href="tel:+243860370727" class="btn-ghost" style="padding:10px 18px;font-size:.8rem">Appeler</a>
        </div>
      </div>
    </div>
    <!-- OPPORTUNITES -->
    <div class="au-head--center reveal" style="margin-top:44px">
      <div class="au-eyebrow au-eyebrow--center">Nos objectifs</div>
      <h3 class="au-title" style="font-size:clamp(1.7rem,3vw,2.4rem)">Les objectifs de <em>LME GROUP</em></h3>
      <div class="au-bar au-bar--center"></div>
    </div>
    <div class="opps reveal" style="margin-top:18px">
      <div class="opps__card"><div class="opps__icon opps__icon--gold"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg></div><h4 class="opps__title" style="font-size:1.05rem">Promouvoir les talents congolais</h4><p class="opps__text">Révéler et valoriser les talents artistiques, culturels et intellectuels de la RDC.</p></div>
      <div class="opps__card"><div class="opps__icon opps__icon--blue"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div><h4 class="opps__title" style="font-size:1.05rem">Encourager le leadership des jeunes</h4><p class="opps__text">Former une génération de jeunes leaders capables de porter des projets d’avenir.</p></div>
      <div class="opps__card"><div class="opps__icon" style="background:rgba(212,175,55,.10);border:1px solid rgba(212,175,55,.18);color:#8a6a00;width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 2a10 10 0 0 0-9 14l9 6 9-6A10 10 0 0 0 12 2z"/></svg></div><h4 class="opps__title" style="font-size:1.05rem">Valoriser la culture congolaise</h4><p class="opps__text">Promouvoir la richesse culturelle et les traditions de la RDC.</p></div>
      <div class="opps__card"><div class="opps__icon" style="background:rgba(124,29,58,.08);border:1px solid rgba(124,29,58,.12);color:#7C1D3A;width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 22c5-3 8-6 8-10a8 8 0 0 0-16 0c0 4 3 7 8 10z"/><path d="M12 11a3 3 0 1 0 0 6 3 3 0 0 0 0-6z"/></svg></div><h4 class="opps__title" style="font-size:1.05rem">Renforcer l’autonomisation des femmes</h4><p class="opps__text">Accompagner les femmes vers l’autonomie économique et le leadership.</p></div>
      <div class="opps__card"><div class="opps__icon opps__icon--emerge"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M19 14c1.5-2 2-4 2-6a6 6 0 0 0-12 0c0 2 .5 4 2 6l6 6 4-6z"/></svg></div><h4 class="opps__title" style="font-size:1.05rem">Projets à impact social</h4><p class="opps__text">Développer des initiatives à fort impact pour les communautés.</p></div>
      <div class="opps__card"><div class="opps__icon opps__icon--dark"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="10"/><path d="M16 8a6 6 0 0 0-8 0"/><path d="M12 12v8"/></svg></div><h4 class="opps__title" style="font-size:1.05rem">Plateformes nationales & internationales</h4><p class="opps__text">Offrir aux jeunes talents des opportunités de représentation au niveau national et international.</p></div>
    </div>

    <div class="au-head--center reveal" style="margin-top:48px">
      <div class="au-eyebrow au-eyebrow--center">Opportunités</div>
      <h3 class="au-title" style="font-size:clamp(1.8rem,3vw,2.6rem)">Bien plus <em>qu’une couronne</em></h3>
      <div class="au-bar au-bar--center"></div>
    </div>
    <div class="opps reveal">
      <div class="opps__card opps__card--featured">
        <div class="opps__icon opps__icon--dark"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z"/><path d="M5 21h14"/></svg></div>
        <h4 class="opps__title">Ambassadrice officielle</h4>
        <p class="opps__text">La lauréate devient l’ambassadrice officielle de Miss Aurora RDC et représente la RDC dans les compétitions internationales partenaires.</p>
        <ul class="opps__list">
          <li>Représentation nationale</li>
          <li>Représentation internationale</li>
          <li>Visibilité & rayonnement</li>
        </ul>
      </div>
      <div class="opps__card">
        <div class="opps__icon opps__icon--gold"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 11a4 4 0 0 0-3-3"/><path d="M16 3.128a4 4 0 0 1 0 7.744"/></svg></div>
        <h4 class="opps__title">Dauphines & finalistes</h4>
        <p class="opps__text">Les dauphines peuvent être désignées pour représenter la RDC dans différents concours internationaux selon les opportunités.</p>
        <ul class="opps__list">
          <li>Concours de beauté & leadership</li>
          <li>Culture & actions sociales</li>
          <li>Programmes d’échanges</li>
        </ul>
      </div>
      <div class="opps__card">
        <div class="opps__icon opps__icon--blue"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22c5-3 8-6 8-10a8 8 0 0 0-16 0c0 4 3 7 8 10z"/><path d="M12 14a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/></svg></div>
        <h4 class="opps__title">Développement & impact</h4>
        <p class="opps__text">Leadership féminin, engagement communautaire et développement personnel au cœur de l’expérience Aurora.</p>
        <ul class="opps__list">
          <li>Leadership féminin</li>
          <li>Engagement communautaire</li>
          <li>Culture congolaise</li>
        </ul>
      </div>
    </div>
  </div>
</section>

<!-- COMITÉ / ORGANISATION -->
<section class="au-section au-section--light" id="comite" aria-labelledby="comiteTitle">
  <div class="au-wrap">
    <div class="au-head--center reveal">
      <div class="au-eyebrow au-eyebrow--center">Gouvernance</div>
      <h2 class="au-title" id="comiteTitle">Comité & <em>organisation</em></h2>
      <div class="au-bar au-bar--center"></div>
      <p class="au-subtitle">L’équipe qui porte la vision de Miss Aurora RDC. Présentation progressive des membres.</p>
    </div>

    <!-- Responsable principal -->
    <div class="reveal" style="max-width:760px;margin:32px auto 0;background:#FFFFFF;border:1px solid rgba(5,11,22,.07);border-radius:20px;overflow:hidden;box-shadow:0 10px 30px rgba(5,11,22,.06);display:grid;grid-template-columns:220px 1fr">
      <div style="background:#F8FAFC;border-right:1px solid rgba(5,11,22,.07);display:flex;flex-direction:column;align-items:center;justify-content:center;padding:28px 18px;gap:12px">
        <div style="width:96px;height:96px;border-radius:16px;background:#FFFFFF;border:1px dashed rgba(5,11,22,.12);display:flex;align-items:center;justify-content:center;color:#94a3b8;flex-direction:column;gap:6px">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          <span style="font-size:.58rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#94a3b8">Photo à venir</span>
        </div>
        <span style="padding:5px 10px;border-radius:100px;background:rgba(212,175,55,.10);border:1px solid rgba(212,175,55,.18);font-size:.62rem;font-weight:800;letter-spacing:.06em;text-transform:uppercase;color:#8a6a00">Bientôt disponible</span>
      </div>
      <div style="padding:24px 22px;display:flex;flex-direction:column;gap:10px">
        <div style="display:inline-flex;align-items:center;gap:8px;padding:5px 10px;border-radius:100px;background:var(--royal-800);color:var(--gold-light);font-size:.62rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;width:fit-content">Responsable principal</div>
        <h3 style="font-family:var(--font-serif);font-size:1.35rem;font-weight:700;color:var(--royal-900);line-height:1.2">Promoteur • Directeur National<br>Coordonnateur Général & Manager<br><span style="font-weight:400;color:#64748b;font-size:.92rem">Miss Aurora RDC</span></h3>
        <p style="font-size:.88rem;color:#5b6577;line-height:1.65;margin-top:4px">La photo officielle et la présentation détaillée seront publiées prochainement. Aucune photo provisoire n’est utilisée.</p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
          <span style="padding:6px 10px;border-radius:100px;background:#F8FAFC;border:1px solid rgba(5,11,22,.07);font-size:.70rem;color:#475569">Photo officielle — bientôt</span>
          <span style="padding:6px 10px;border-radius:100px;background:#F8FAFC;border:1px solid rgba(5,11,22,.07);font-size:.70rem;color:#475569">Présentation — à venir</span>
        </div>
      </div>
    </div>

    <!-- Autres membres — placeholders -->
    <div class="reveal" style="margin-top:22px;display:grid;grid-template-columns:repeat(3,1fr);gap:14px">
      <div style="background:#FFFFFF;border:1px solid rgba(5,11,22,.07);border-radius:16px;padding:18px;text-align:center">
        <div style="width:64px;height:64px;margin:0 auto 10px;border-radius:12px;background:#F8FAFC;border:1px dashed rgba(5,11,22,.10);display:flex;align-items:center;justify-content:center;color:#94a3b8"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/></svg></div>
        <div style="font-size:.82rem;font-weight:700;color:#94a3b8">À annoncer</div><div style="font-size:.68rem;color:#94a3b8;margin-top:2px">Membre de l’organisation</div>
        <div style="margin-top:8px;padding:4px 8px;border-radius:100px;background:#F8FAFC;border:1px solid rgba(5,11,22,.06);font-size:.62rem;color:#94a3b8;display:inline-block">Nom • Fonction • Photo</div>
      </div>
      <div style="background:#FFFFFF;border:1px solid rgba(5,11,22,.07);border-radius:16px;padding:18px;text-align:center">
        <div style="width:64px;height:64px;margin:0 auto 10px;border-radius:12px;background:#F8FAFC;border:1px dashed rgba(5,11,22,.10);display:flex;align-items:center;justify-content:center;color:#94a3b8"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/></svg></div>
        <div style="font-size:.82rem;font-weight:700;color:#94a3b8">À annoncer</div><div style="font-size:.68rem;color:#94a3b8;margin-top:2px">Membre de l’organisation</div>
        <div style="margin-top:8px;padding:4px 8px;border-radius:100px;background:#F8FAFC;border:1px solid rgba(5,11,22,.06);font-size:.62rem;color:#94a3b8;display:inline-block">Nom • Fonction • Photo</div>
      </div>
      <div style="background:#FFFFFF;border:1px solid rgba(5,11,22,.07);border-radius:16px;padding:18px;text-align:center">
        <div style="width:64px;height:64px;margin:0 auto 10px;border-radius:12px;background:#F8FAFC;border:1px dashed rgba(5,11,22,.10);display:flex;align-items:center;justify-content:center;color:#94a3b8"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="12" cy="7" r="4"/><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/></svg></div>
        <div style="font-size:.82rem;font-weight:700;color:#94a3b8">À annoncer</div><div style="font-size:.68rem;color:#94a3b8;margin-top:2px">Membre de l’organisation</div>
        <div style="margin-top:8px;padding:4px 8px;border-radius:100px;background:#F8FAFC;border:1px solid rgba(5,11,22,.06);font-size:.62rem;color:#94a3b8;display:inline-block">Nom • Fonction • Photo</div>
      </div>
    </div>
    <p style="text-align:center;font-size:.72rem;color:#94a3b8;margin-top:12px">Aucun membre n’est inventé — ajouts progressifs avec photo officielle.</p>
  </div>
</section>

<!-- CANDIDATES -->
<section class="au-section au-section--light" id="candidates" aria-labelledby="cdTitle">
  <div class="au-wrap">
    <div class="candidates-head reveal">
      <div class="au-eyebrow au-eyebrow--center">Édition officielle <?= esc($auroraYear) ?></div>
      <h2 class="au-title" id="cdTitle">Les Ambassadrices <em>de demain</em></h2>
      <div class="au-bar au-bar--center"></div>
      <p class="au-subtitle">Découvrez les candidates par étape du concours. Chaque profil est une histoire, un projet, une lumière.</p>
    </div>
    <?php if(count($etapes)>0): ?>
    <div class="candidates-tabs reveal" role="tablist" aria-label="Étapes du concours">
      <?php foreach($etapes as $idx=>$etape):
        $debut=strtotime($etape['date_ouverture']); $fin=strtotime($etape['date_cloture']); $nowTs=time();
        if($nowTs>=$debut && $nowTs<=$fin){ $label='En cours'; $cls='ongoing';}
        elseif($nowTs<$debut){ $label='À venir'; $cls='upcoming';}
        else{ $label='Terminé'; $cls='done';}
      ?>
        <button class="candidates-tab <?= $idx===0?'is-active':'' ?>" role="tab" aria-selected="<?= $idx===0?'true':'false' ?>" aria-controls="panel-<?= $etape['etape_id'] ?>" id="tab-<?= $etape['etape_id'] ?>" data-panel="panel-<?= $etape['etape_id'] ?>">
          <?= esc($etape['nom_etape']??'Étape '.$etape['numero_ordre']) ?>
          <span class="candidates-tab__badge"><?= $label ?></span>
        </button>
      <?php endforeach; ?>
    </div>
    <div class="candidates-panels reveal">
      <?php foreach($etapes as $idx=>$etape):
        $panelId='panel-'.$etape['etape_id']; $isActive=$idx===0; $candidates=$candidatesByEtape[$etape['etape_id']]??[];
      ?>
      <div class="candidates-panel <?= $isActive?'is-active':'' ?>" id="<?= $panelId ?>" role="tabpanel" aria-labelledby="tab-<?= $etape['etape_id'] ?>">
        <div class="candidates-event-head">
          <h3 class="candidates-event-title"><?= esc($etape['nom_etape']??'Étape '.$etape['numero_ordre']) ?></h3>
          <?php if(!empty($etape['description_etape'])): ?><p class="candidates-event-desc"><?= esc($etape['description_etape']) ?></p><?php endif; ?>
          <div class="candidates-event-dates"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg> <?= date('d/m/Y', strtotime($etape['date_ouverture'])) ?> → <?= date('d/m/Y', strtotime($etape['date_cloture'])) ?></div>
        </div>
        <?php if(count($candidates)>0): ?>
        <div class="candidates-grid">
          <?php foreach($candidates as $cand):
            $votes=$cand['total_votes']; $pct=$totalVotesAll>0? round(($votes/$totalVotesAll)*100,1):0;
            $photoUrl = getCandidatePhotoUrl($cand['photo_officielle'] ?? '');
            if (empty($photoUrl)) $photoUrl = 'https://images.unsplash.com/photo-1529626455594-4ff0802cfb7e?w=600&h=750&fit=crop';
          ?>
          <div class="candidate-card">
            <div class="candidate-card__photo">
              <span class="skel" aria-hidden="true"></span>
              <img class="lazy-img" data-src="<?= esc($photoUrl) ?>?v=<?= time() ?>" alt="<?= esc($cand['nom_complet']) ?>" loading="lazy" decoding="async" fetchpriority="low" onerror="this.onerror=null;this.src='https://images.unsplash.com/photo-1529626455594-4ff0802cfb7e?w=600&h=750&fit=crop';this.classList.add('is-loaded');this.closest('.candidate-card__photo')?.classList.add('is-loaded')">
              <div class="candidate-card__veil"></div>
              <span class="candidate-card__num">N° <?= esc($cand['code_participante']) ?></span>
              <span class="candidate-card__tag">Candidate <?= esc($auroraYear) ?></span>
              <span class="candidate-card__city"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg> <?= esc($cand['ville_origine']??'Kinshasa') ?></span>
            </div>
            <div class="candidate-card__body">
              <h3 class="candidate-card__name"><?= esc($cand['nom_complet']) ?></h3>
              <div class="candidate-card__share">
                <button class="candidate-card__share-btn" onclick="copyVoteLink(this)" aria-label="Copier le lien de vote">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
                  Copier le lien de vote
                </button>
              </div>
              <div class="candidate-card__divider"></div>
              <div class="candidate-card__details">
                <div class="candidate-card__detail"><span class="candidate-card__detail-icon"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2-2v16"/></svg></span><span>Code: <strong><?= esc($cand['code_participante']) ?></strong></span></div>
                <div class="candidate-card__detail"><span class="candidate-card__detail-icon"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3 7h7l-5.5 4 2 7L12 16l-6.5 4 2-7L2 9h7z"/></svg></span><span>Niveau: <?= esc($cand['niveau_etudes']??'Non précisé') ?></span></div>
              </div>
              <div class="candidate-card__stats">
                <div class="candidate-card__metrics"><span class="candidate-card__metric"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> <?= $votes ?> votes</span></div>
                <div><div class="candidate-card__score-head"><span>Popularité</span><strong><?= $pct ?>%</strong></div><div class="candidate-card__track"><div class="candidate-card__fill" style="--score:<?= $pct ?>%"></div></div></div>
              </div>
              <div class="candidate-card__actions">
                <a href="profil.php?code=<?= urlencode($cand['code_participante']) ?>" class="candidate-card__btn candidate-card__btn--ghost">Profil</a>
                <a href="voter.php?candidat=<?= urlencode($cand['participante_id']) ?>&concours_id=<?= $concoursId ?>&etape_id=<?= $etape['etape_id'] ?>" class="candidate-card__btn candidate-card__btn--primary">Voter</a>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php else: ?><p style="text-align:center;color:#94a3b8;padding:24px">Aucune candidate inscrite pour cette étape.</p><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
      <p style="text-align:center;color:#94a3b8;margin-top:24px">Aucune étape disponible pour ce concours. Les candidates s’afficheront dès l’ouverture des inscriptions.</p>
      <?php if(count($allCandidates)>0): ?>
        <div class="candidates-grid" style="margin-top:24px">
          <?php foreach($allCandidates as $cand):
            $votes=$cand['total_votes']; $pct=$totalVotesAll>0? round(($votes/$totalVotesAll)*100,1):0;
            $photoUrl = getCandidatePhotoUrl($cand['photo_officielle'] ?? '');
            if (empty($photoUrl)) $photoUrl = 'https://images.unsplash.com/photo-1529626455594-4ff0802cfb7e?w=600&h=750&fit=crop';
          ?>
            <div class="candidate-card">
              <div class="candidate-card__photo"><span class="skel" aria-hidden="true"></span><img class="lazy-img" data-src="<?= esc($photoUrl) ?>?v=<?= time() ?>" alt="<?= esc($cand['nom_complet']) ?>" loading="lazy" decoding="async" fetchpriority="low" onerror="this.onerror=null;this.src='https://images.unsplash.com/photo-1529626455594-4ff0802cfb7e?w=600&h=750&fit=crop';this.classList.add('is-loaded');this.closest('.candidate-card__photo')?.classList.add('is-loaded')"><div class="candidate-card__veil"></div><span class="candidate-card__num">N° <?= esc($cand['code_participante']) ?></span><span class="candidate-card__city"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg> <?= esc($cand['ville_origine']??'Kinshasa') ?></span></div>
              <div class="candidate-card__body"><h3 class="candidate-card__name"><?= esc($cand['nom_complet']) ?></h3><div class="candidate-card__stats"><div class="candidate-card__metrics"><span class="candidate-card__metric"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg> <?= $votes ?> votes</span></div><div><div class="candidate-card__score-head"><span>Popularité</span><strong><?= $pct ?>%</strong></div><div class="candidate-card__track"><div class="candidate-card__fill" style="--score:<?= $pct ?>%"></div></div></div></div><div class="candidate-card__actions"><a href="profil.php?code=<?= urlencode($cand['code_participante']) ?>" class="candidate-card__btn candidate-card__btn--ghost">Profil</a><a href="voter.php?candidat=<?= urlencode($cand['participante_id']) ?>&concours_id=<?= $concoursId ?>" class="candidate-card__btn candidate-card__btn--primary">Voter</a></div></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
    <div class="candidates-footer">
      <div class="candidates-footer__count"><?= count($allCandidates) > 0 ? count($allCandidates) . ' candidate(s) • Édition ' . esc($auroraYear) : 'Candidates retenues : publication après le casting du 15 août 2026' ?></div>
      <a href="#candidates" class="btn-dark">Voir toutes les candidates <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></a>
    </div>
  </div>
</section>

<!-- CLASSEMENT AURORA -->
<section class="au-section ranking" id="classement" aria-labelledby="rankTitle">
  <div class="ranking__wrap">
    <div class="ranking__head reveal">
      <div class="au-eyebrow au-eyebrow--center" style="color:var(--gold-light)">En direct • Mise à jour automatique</div>
      <h2 class="au-title" id="rankTitle" style="color:#fff">Le classement <em>Aurora</em></h2>
      <div class="au-bar au-bar--center"></div>
      <p class="au-subtitle" style="color:rgba(255,255,255,.58);margin-left:auto;margin-right:auto;text-align:center">Suivez en temps réel la progression des candidates. Le vote du public compte — chaque voix révèle une étoile.</p>
    </div>
    <div id="podium" class="ranking__podium reveal">
      <div class="ranking__empty" style="grid-column:1/-1">Chargement du podium Aurora…</div>
    </div>
    <div id="rankingList" class="ranking__list reveal"></div>
    <div class="ranking__footer reveal">
      <div class="ranking__total">Total des votes : <strong id="rankTotal">—</strong> • Actualisation toutes les 10 secondes</div>
      <a href="#candidates" class="btn-primary" style="padding:10px 18px;font-size:.8rem">Voir le vote →</a>
    </div>
  </div>
</section>

<!-- PARCOURS AURORA -->
<section class="au-section au-section--light timeline" id="parcours" aria-labelledby="parcoursTitle">
  <div class="au-wrap">
    <div class="au-head--center reveal">
      <div class="au-eyebrow au-eyebrow--center">Le parcours Aurora</div>
      <h2 class="au-title" id="parcoursTitle">De l’inscription au <em>couronnement</em></h2>
      <div class="au-bar au-bar--center"></div>
      <p class="au-subtitle">Huit étapes pour révéler, former et couronner l’ambassadrice qui portera la lumière de la RDC.</p>
    </div>
    <div class="timeline__line" aria-hidden="true"></div>
        <div style="text-align:center;margin-bottom:18px;display:inline-flex;align-items:center;gap:8px;padding:6px 14px;border-radius:100px;background:rgba(212,175,55,.10);border:1px solid rgba(212,175,55,.22);font-size:.70rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#8a6a00;margin-left:auto;margin-right:auto;display:flex;width:fit-content;justify-content:center"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg> CALENDRIER OFFICIEL — MISS AURORA RDC 2026</div>
    <div class="timeline__grid">
      <div class="timeline__item reveal">
        <span class="timeline__num">01</span>
        <div class="timeline__content"><h3 class="timeline__title">Lancement & Inscriptions</h3><p class="timeline__desc">Ouverture officielle des inscriptions — dépôt des dossiers : pièce d’identité, photos HD, fiche d’inscription, CV, lettre de motivation.</p><span class="timeline__date"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg> 12 juillet → 12 août 2026</span></div>
      </div>
      <div class="timeline__item reveal reveal-delay-1">
        <span class="timeline__num">02</span>
        <div class="timeline__content"><h3 class="timeline__title">Casting physique national</h3><p class="timeline__desc"><strong>Samedi 15 août 2026 à 09h00</strong> — <strong>BINGA HOUSE</strong> — Réf. Maison communale de la Gombe<br><span style="font-size:.78rem;color:#475569"><strong>Tenue exigée :</strong> Singlet ou T-shirt noir • Pantalon, culotte ou collant noir • Talons</span><br><span style="font-size:.78rem;color:#0f7a52;font-weight:600">Les candidates non inscrites en ligne peuvent s’inscrire et payer directement sur place.</span></p><span class="timeline__date" style="background:rgba(16,185,129,.12);border-color:rgba(16,185,129,.22);color:#0f7a52"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg> 15 août 2026 — 09h00 — BINGA HOUSE, Gombe</span></div>
      </div>
      <div class="timeline__item reveal">
        <span class="timeline__num">03</span>
        <div class="timeline__content"><h3 class="timeline__title">Présélections & délibérations</h3><p class="timeline__desc">Étude des dossiers et vérification des critères d’éligibilité par le comité Aurora.</p><span class="timeline__date"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg> 12 — 14 août 2026</span></div>
      </div>
      <div class="timeline__item reveal reveal-delay-1">
        <span class="timeline__num">04</span>
        <div class="timeline__content"><h3 class="timeline__title">Publication & début du vote</h3><p class="timeline__desc">Publication des candidates retenues et ouverture du vote du public (modalités communiquées à cette date).</p><span class="timeline__date" style="background:rgba(212,175,55,.12);border-color:rgba(212,175,55,.22);color:#8a6a00"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg> 16 août 2026</span></div>
      </div>
      <div class="timeline__item reveal">
        <span class="timeline__num">05</span>
        <div class="timeline__content"><h3 class="timeline__title">Formation & coaching</h3><p class="timeline__desc">Leadership, culture générale, éloquence, démarche, engagement communautaire et préparation scénique.</p><span class="timeline__date"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg> 16 août → 4 septembre 2026</span></div>
      </div>
      <div class="timeline__item timeline__item--accent reveal reveal-delay-1">
        <span class="timeline__num">06</span>
        <div class="timeline__content"><h3 class="timeline__title">Soirée de présentation officielle</h3><p class="timeline__desc">Révélation des candidates retenues au public et à la presse — présentation des projets sociaux.</p><span class="timeline__date" style="background:rgba(212,175,55,.12);border-color:rgba(212,175,55,.22);color:#8a6a00"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3 7h7l-5.5 4 2 7L12 16l-6.5 4 2-7L2 9h7z"/></svg> 5 septembre 2026</span></div>
      </div>
      <div class="timeline__item reveal">
        <span class="timeline__num">07</span>
        <div class="timeline__content"><h3 class="timeline__title">Grande Finale nationale</h3><p class="timeline__desc">Gala à Kinshasa : défilés, discours, projet social, délibération du jury et vote du public. Lieu : Date à confirmer.</p><span class="timeline__date" style="background:rgba(212,175,55,.12);border-color:rgba(212,175,55,.22);color:#8a6a00"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z"/><path d="M5 21h14"/></svg> 3 octobre 2026 — Kinshasa</span></div>
      </div>
      <div class="timeline__item timeline__item--accent reveal reveal-delay-1">
        <span class="timeline__num"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z"/><path d="M5 21h14"/></svg></span>
        <div class="timeline__content"><h3 class="timeline__title">Couronnement</h3><p class="timeline__desc">Élection de Miss Aurora RDC 2026, de ses dauphines et remise des distinctions.</p><span class="timeline__date" style="background:rgba(212,175,55,.12);border-color:rgba(212,175,55,.22);color:#8a6a00"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3 7h7l-5.5 4 2 7L12 16l-6.5 4 2-7L2 9h7z"/></svg> 3 octobre 2026</span></div>
      </div>
    </div>
        <?php if(!empty($etapes)): ?>
    <div class="timeline__dynamic reveal">
      <h3 class="timeline__dynamic-title">Étapes dynamiques du concours en cours</h3>
      <div class="timeline__dynamic-grid">
        <?php foreach($etapes as $e): ?>
          <div class="timeline__dynamic-card">
            <div style="font-size:.72rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;color:var(--gold)">Étape <?= $e['numero_ordre'] ?></div>
            <div style="font-family:var(--font-serif);font-size:1.1rem;font-weight:700;color:var(--royal-900);margin-top:4px"><?= esc($e['nom_etape']) ?></div>
            <?php if(!empty($e['description_etape'])): ?><p style="font-size:.84rem;color:#5b6577;margin-top:6px;line-height:1.6"><?= esc($e['description_etape']) ?></p><?php endif; ?>
            <div style="margin-top:10px;display:inline-flex;padding:4px 10px;border-radius:100px;background:rgba(7,26,61,.06);font-size:.72rem;color:#475569"><?= date('d/m/Y',strtotime($e['date_ouverture'])) ?> → <?= date('d/m/Y',strtotime($e['date_cloture'])) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
</section>

<!-- CONDITIONS -->
<section class="au-section au-section--ivory" id="conditions" aria-labelledby="condTitle">
  <div class="au-wrap">
    <div class="au-head--center reveal">
      <div class="au-eyebrow au-eyebrow--center">Éligibilité</div>
      <h2 class="au-title" id="condTitle">Qui peut <em>participer ?</em></h2>
      <div class="au-bar au-bar--center"></div>
      <p class="au-subtitle">Miss Aurora RDC s’adresse aux jeunes femmes congolaises prêtes à incarner l’excellence et l’engagement.</p>
    </div>
    <div class="cond-grid">
      <div class="cond-card reveal">
        <div class="cond-card__icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><path d="M16 3.128a4 4 0 0 1 0 7.744"/></svg></div>
        <h3 class="cond-card__title">Nationalité / Résidence</h3>
        <p class="cond-card__text">Être de nationalité congolaise ou résidente en RDC et fière de représenter la culture congolaise.</p>
      </div>
      <div class="cond-card reveal reveal-delay-1">
        <div class="cond-card__icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h18"/></svg></div>
        <h3 class="cond-card__title">Âge</h3>
        <p class="cond-card__text">Être âgée de <strong>18 à 28 ans</strong> à la date du concours.</p>
      </div>
      <div class="cond-card reveal reveal-delay-2">
        <div class="cond-card__icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22c5-3 8-6 8-10a8 8 0 0 0-16 0c0 4 3 7 8 10z"/><path d="M12 14a2 2 0 1 0 0-4 2 2 0 0 0 0 4z"/></svg></div>
        <h3 class="cond-card__title">Situation</h3>
        <p class="cond-card__text">Être célibataire et jouir d’une bonne moralité.</p>
      </div>
      <div class="cond-card reveal">
        <div class="cond-card__icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22c5-3 8-6 8-10a8 8 0 0 0-16 0c0 4 3 7 8 10z"/><path d="M19 8a4 4 0 0 0-6-3 4 4 0 0 0-6 3c0 3 6 7 6 7s6-4 6-7z"/></svg></div>
        <h3 class="cond-card__title">Projet social</h3>
        <p class="cond-card__text">Avoir la volonté de porter un projet social à impact pour sa communauté.</p>
      </div>
      <div class="cond-card reveal reveal-delay-1">
        <div class="cond-card__icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 11a4 4 0 0 0-3-3"/></svg></div>
        <h3 class="cond-card__title">Disponibilité</h3>
        <p class="cond-card__text">Être disponible pour l’ensemble des activités : formations, répétitions, finale.</p>
      </div>
      <div class="cond-card reveal reveal-delay-2">
        <div class="cond-card__icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M16 13H8"/><path d="M16 17H8"/></svg></div>
        <h3 class="cond-card__title">Règlement</h3>
        <p class="cond-card__text">Accepter sans réserve le règlement officiel du concours et la charte des candidates.</p>
      </div>
    </div>
  </div>
</section>



<!-- DOCUMENTS OFFICIELS -->
<section class="au-section au-section--light" id="documents" aria-labelledby="docsTitle">
  <div class="au-wrap">
    <div class="au-head--center reveal">
      <div class="au-eyebrow au-eyebrow--center">Transparence</div>
      <h2 class="au-title" id="docsTitle">Documents <em>officiels</em></h2>
      <div class="au-bar au-bar--center"></div>
      <p class="au-subtitle">Retrouvez les documents de référence de Miss Aurora RDC. Les contenus seront publiés dès finalisation.</p>
    </div>
    <div class="dossier" style="margin-top:28px">
      <ul class="dossier__list reveal">
        <li class="dossier__item" style="justify-content:space-between">
          <span style="display:flex;gap:14px;align-items:center"><span class="dossier__item-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></span><span><span class="dossier__item-title">Règlement officiel</span><br><span class="dossier__item-sub">Cadre général du concours</span></span></span>
          <span style="padding:7px 12px;border-radius:100px;background:#F3F4F6;border:1px solid #E5E7EB;font-size:.72rem;font-weight:700;color:#6B7280;white-space:nowrap">Bientôt disponible</span>
        </li>
        <li class="dossier__item" style="justify-content:space-between">
          <span style="display:flex;gap:14px;align-items:center"><span class="dossier__item-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></span><span><span class="dossier__item-title">Charte des candidates</span><br><span class="dossier__item-sub">Engagements et responsabilités</span></span></span>
          <span style="padding:7px 12px;border-radius:100px;background:#F3F4F6;border:1px solid #E5E7EB;font-size:.72rem;font-weight:700;color:#6B7280;white-space:nowrap">Bientôt disponible</span>
        </li>
        <li class="dossier__item" style="justify-content:space-between">
          <span style="display:flex;gap:14px;align-items:center"><span class="dossier__item-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/></svg></span><span><span class="dossier__item-title">Politique de confidentialité</span><br><span class="dossier__item-sub">Protection des données personnelles</span></span></span>
          <span style="padding:7px 12px;border-radius:100px;background:#F3F4F6;border:1px solid #E5E7EB;font-size:.72rem;font-weight:700;color:#6B7280;white-space:nowrap">Bientôt disponible</span>
        </li>
        <li class="dossier__item" style="justify-content:space-between">
          <span style="display:flex;gap:14px;align-items:center"><span class="dossier__item-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M10 13H8"/><path d="M16 13H8"/></svg></span><span><span class="dossier__item-title">Conditions générales</span><br><span class="dossier__item-sub">Participation et organisation</span></span></span>
          <span style="padding:7px 12px;border-radius:100px;background:#F3F4F6;border:1px solid #E5E7EB;font-size:.72rem;font-weight:700;color:#6B7280;white-space:nowrap">Bientôt disponible</span>
        </li>
        <li class="dossier__item" style="justify-content:space-between">
          <span style="display:flex;gap:14px;align-items:center"><span class="dossier__item-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="10"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg></span><span><span class="dossier__item-title">Règlement du vote</span><br><span class="dossier__item-sub">Modalités et comptabilisation</span></span></span>
          <span style="padding:7px 12px;border-radius:100px;background:#F3F4F6;border:1px solid #E5E7EB;font-size:.72rem;font-weight:700;color:#6B7280;white-space:nowrap">Bientôt disponible</span>
        </li>
        <li class="dossier__item" style="justify-content:space-between">
          <span style="display:flex;gap:14px;align-items:center"><span class="dossier__item-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M2 9a3 3 0 0 1 3-3h14a3 3 0 0 1 3 3v6a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3V9Z"/><path d="M13 5v14"/></svg></span><span><span class="dossier__item-title">Conditions de billetterie</span><br><span class="dossier__item-sub">Accès à la finale et aux soirées</span></span></span>
          <span style="padding:7px 12px;border-radius:100px;background:#F3F4F6;border:1px solid #E5E7EB;font-size:.72rem;font-weight:700;color:#6B7280;white-space:nowrap">Bientôt disponible</span>
        </li>
      </ul>
      <div class="dossier__cta reveal reveal-delay-1" style="position:relative">
        <div style="position:relative;z-index:1">
          <h3 class="dossier__cta-title">Consulter le <em>règlement officiel</em></h3>
          <p class="dossier__cta-text">Le règlement officiel sera disponible en lecture en ligne et en téléchargement dès finalisation. Aucun document n’est inventé.</p>
          <a href="#" onclick="event.preventDefault(); const n=document.getElementById('contact'); if(n) n.scrollIntoView({behavior:'smooth'});" class="btn-primary" style="width:100%;justify-content:center;opacity:.72;cursor:not-allowed;background:#9CA3AF;border-color:#9CA3AF">Bientôt disponible — Lecture & téléchargement</a>
          <p style="font-size:.70rem;color:rgba(255,255,255,.52);text-align:center;margin-top:10px">Besoin d’une information ? <a href="#contact" style="color:var(--gold-light);text-decoration:underline">Contactez LME GROUP</a></p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- VOTE -->
<section class="au-section vote" id="vote" aria-labelledby="voteTitle">
  <div class="vote__wrap">
    <div class="reveal">
      <div class="vote__badge" style="background:rgba(212,175,55,.12);border-color:rgba(212,175,55,.22);color:var(--gold-light)"><span class="vote__badge-dot" style="background:var(--gold);box-shadow:0 0 10px rgba(212,175,55,.5)"></span> Vote du public — Bientôt disponible</div>
      <h2 class="vote__title" id="voteTitle">Votre voix peut <em>révéler</em> une étoile</h2>
      <p class="vote__text">Le vote du public <strong>sera ouvert prochainement</strong> — à partir du <strong>16 août 2026</strong> après la publication des candidates retenues. Les modalités du vote, le tarif et les moyens de paiement seront communiqués après la sélection officielle.</p>
    </div>
    <div class="vote__cards reveal">
      <div class="vote__card"><div class="vote__card-title">Vote en ligne</div><div class="vote__card-sub">Disponible 24h/24 • Sécurisé</div></div>
      <div class="vote__card"><div class="vote__card-title">Tarif</div><div class="vote__card-sub">Bientôt disponible — communiqué après le 16 août 2026</div></div>
      <div class="vote__card"><div class="vote__card-title">Votes multiples</div><div class="vote__card-sub">Autorisés • Soutien continu</div></div>
    </div>
    <div class="reveal">
      <p style="font-size:.82rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:rgba(255,255,255,.42);margin-bottom:10px">Moyens de paiement pris en charge</p>
      <div class="vote__payments">
        <span class="vote__pay">Orange Money</span>
        <span class="vote__pay">Airtel Money</span>
        <span class="vote__pay">M-Pesa</span>
        <span class="vote__pay">AfriMoney</span>
        <span class="vote__pay">Cartes bancaires</span>
        <span class="vote__pay">Paiement en ligne</span>
      </div>
      <p style="font-size:.72rem;color:rgba(255,255,255,.4);margin-top:8px">Tarif et moyens de paiement : bientôt disponibles — communiqués après la sélection officielle du 16 août 2026.</p>
    </div>
    <div class="vote__actions reveal">
      <a href="#candidates" class="btn-primary"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2l3 7h7l-5.5 4 2 7L12 16l-6.5 4 2-7L2 9h7z"/></svg> Voter maintenant <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></a>
      <a href="#classement" class="btn-ghost">Voir le classement</a>
    </div>
  </div>
</section>

<!-- BILLETTERIE -->
<section class="au-section au-section--light" id="billetterie" aria-labelledby="ticketTitle">
  <div class="au-wrap">
    <div class="au-head--center reveal">
      <div class="au-eyebrow au-eyebrow--center">Billetterie officielle</div>
      <h2 class="au-title" id="ticketTitle">Vivez la finale <em>en direct</em></h2>
      <div class="au-bar au-bar--center"></div>
      <p class="au-subtitle">Assistez au grand couronnement à Kinshasa. Trois expériences, une même émotion.</p>
    </div>
    <div class="tickets">
      <div class="ticket reveal">
        <div class="ticket__tier">Standard</div>
        <div class="ticket__name">Aurore</div>
        <div class="ticket__price"><div class="ticket__price-label">Tarif</div><div class="ticket__price-value">Bientôt disponible</div></div>
        <ul class="ticket__list">
          <li><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg> Accès à la salle de gala</li>
          <li><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg> Placement standard</li>
          <li><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg> Accès au vote sur place</li>
        </ul>
        <a href="#contact" class="btn-ghost ticket__btn" style="border-color:rgba(5,11,22,.12);color:var(--royal-800)">Être informé</a>
      </div>
      <div class="ticket ticket--featured reveal reveal-delay-1">
        <span class="ticket__badge">Recommandé</span>
        <div class="ticket__tier">VIP</div>
        <div class="ticket__name">Lumière</div>
        <div class="ticket__price"><div class="ticket__price-label">Tarif</div><div class="ticket__price-value">Bientôt disponible</div></div>
        <ul class="ticket__list">
          <li><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg> Placement premium proche scène</li>
          <li><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg> Cocktail d’accueil</li>
          <li><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg> Photo avec les finalistes</li>
        </ul>
        <a href="#contact" class="btn-primary ticket__btn">Être informé</a>
      </div>
      <div class="ticket reveal reveal-delay-2">
        <div class="ticket__tier">VVIP</div>
        <div class="ticket__name">Aurora</div>
        <div class="ticket__price"><div class="ticket__price-label">Tarif</div><div class="ticket__price-value">Bientôt disponible</div></div>
        <ul class="ticket__list">
          <li><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg> Loge d’honneur & salon privé</li>
          <li><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg> Dîner de gala</li>
          <li><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6L9 17l-5-5"/></svg> Rencontre avec LME GROUP</li>
        </ul>
        <a href="#contact" class="btn-ghost ticket__btn" style="border-color:rgba(5,11,22,.12);color:var(--royal-800)">Être informé</a>
      </div>
    </div>
    <p style="text-align:center;font-size:.76rem;color:#64748b;margin-top:14px">Tarifs bientôt disponibles • Aucun prix n’est inventé avant confirmation officielle.</p>
  </div>
</section>

<!-- FINALE -->
<section class="finale" id="finale" aria-labelledby="finaleTitle">
  <div class="finale__wrap">
    <div class="reveal">
      <div class="au-eyebrow" style="color:var(--gold-light)">Le grand couronnement</div>
      <h2 class="finale__title" id="finaleTitle">La nuit où <em>l’aurore</em> se lève</h2>
      <p class="finale__text"><strong style="color:var(--gold-light)">Grande Finale nationale — 3 octobre 2026</strong><br>Kinshasa, RDC<br>Lieu : à confirmer</p>
      <div class="finale__infos">
        <div class="finale__info"><div class="finale__info-label">Ville</div><div class="finale__info-value">Kinshasa, RDC</div></div>
        <div class="finale__info"><div class="finale__info-label">Calendrier</div><div class="finale__info-value">3 octobre 2026</div></div>
        <div class="finale__info"><div class="finale__info-label">Lieu</div><div class="finale__info-value">Date à confirmer</div></div>
        <div class="finale__info"><div class="finale__info-label">Organisateur</div><div class="finale__info-value">LME GROUP</div></div>
      </div>
      <a href="#contact" class="btn-primary">Être informé de la finale <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></a>
      <p style="font-size:.72rem;color:rgba(255,255,255,.38);margin-top:10px">Aucune date n’est inventée — l’organisation communique dès confirmation.</p>
    </div>
    <div class="finale__visual reveal reveal-delay-1" aria-hidden="true">
      <div class="finale__visual-inner">
        <div class="finale__crown"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z"/><path d="M5 21h14"/></svg></div>
        <div class="finale__visual-title">Miss Aurora RDC</div>
        <div class="finale__visual-sub">Kinshasa • Finale nationale<br>Une scène, une lumière, une ambassadrice</div>
        <div style="margin-top:16px;display:inline-flex;padding:6px 12px;border-radius:100px;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.12);font-size:.68rem;font-weight:700;letter-spacing:.06em;color:rgba(255,255,255,.72)">Édition <?= esc($auroraYear) ?> • Bientôt</div>
      </div>
    </div>
  </div>
</section>

<!-- GALERIE -->
<section class="au-section au-section--light" id="galerie" aria-labelledby="galTitle">
  <div class="au-wrap">
    <div class="au-head--center reveal">
      <div class="au-eyebrow au-eyebrow--center">L’univers Aurora</div>
      <h2 class="au-title" id="galTitle">Plongez dans <em>l’univers</em></h2>
      <div class="au-bar au-bar--center"></div>
      <p class="au-subtitle">Candidates, castings, formations, backstage et couronnement — chaque image raconte la lumière Aurora.</p>
    </div>
    <div class="gal-grid reveal">
      <div class="gal-item"><img src="https://images.unsplash.com/photo-1529626455594-4ff0802cfb7e?w=600&h=450&fit=crop" alt="Candidates Miss Aurora" loading="lazy"><span class="gal-item__label">Candidates</span></div>
      <div class="gal-item"><img src="https://images.unsplash.com/photo-1517841905240-472988babdf9?w=600&h=450&fit=crop" alt="Castings" loading="lazy"><span class="gal-item__label">Castings</span></div>
      <div class="gal-item"><img src="https://images.unsplash.com/photo-1487412720507-e7ab37603c6f?w=600&h=450&fit=crop" alt="Formations" loading="lazy"><span class="gal-item__label">Formations</span></div>
      <div class="gal-item"><img src="https://images.unsplash.com/photo-1531746020798-e6953c6e8e04?w=600&h=450&fit=crop" alt="Événements" loading="lazy"><span class="gal-item__label">Événements</span></div>
      <div class="gal-item"><img src="https://images.unsplash.com/photo-1524504388940-b1c1722653e1?w=600&h=450&fit=crop" alt="Backstage" loading="lazy"><span class="gal-item__label">Backstage</span></div>
      <div class="gal-item"><img src="https://images.unsplash.com/photo-1519741497674-611481863552?w=600&h=450&fit=crop" alt="Couronnement" loading="lazy"><span class="gal-item__label">Couronnement</span></div>
    </div>
    <p style="text-align:center;font-size:.74rem;color:#94a3b8;margin-top:12px">Galerie évolutive — les médias officiels seront ajoutés dès disponibilité.</p>
  </div>
</section>

<!-- PARTENAIRES -->
<section class="au-section au-section--ivory" id="partenaires" aria-labelledby="partTitle">
  <div class="au-wrap">
    <div class="au-head--center reveal">
      <div class="au-eyebrow au-eyebrow--center">Ils nous accompagnent</div>
      <h2 class="au-title" id="partTitle">Partenaires & <em>sponsors</em></h2>
      <div class="au-bar au-bar--center"></div>
      <p class="au-subtitle">Miss Aurora RDC s’appuie sur des partenaires engagés pour révéler l’excellence congolaise. Rejoignez l’aventure.</p>
    </div>
    <div style="max-width:720px;margin:22px auto 0;background:#FFFFFF;border:1px solid rgba(5,11,22,.07);border-radius:16px;padding:24px 20px;text-align:center" class="reveal">
      <div style="width:52px;height:52px;margin:0 auto 12px;border-radius:14px;background:rgba(212,175,55,.08);border:1px solid rgba(212,175,55,.18);display:flex;align-items:center;justify-content:center;color:#8a6a00"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></div>
      <p style="font-family:var(--font-serif);font-size:1.06rem;font-weight:700;color:var(--royal-900);line-height:1.4">Les partenaires officiels de l’édition 2026<br>seront annoncés prochainement.</p>
      <p style="font-size:.84rem;color:#64748b;line-height:1.6;margin-top:8px;max-width:560px;margin-left:auto;margin-right:auto">Aucun partenariat n’est actuellement officiellement confirmé. Aucun logo, nom ou visuel n’est inventé. Les annonces interviendront après validation institutionnelle.</p>
      <div style="margin-top:12px;display:inline-flex;align-items:center;gap:8px;padding:6px 12px;border-radius:100px;background:#F8FAFC;border:1px solid rgba(5,11,22,.07);font-size:.70rem;font-weight:600;color:#475569"><span style="width:6px;height:6px;border-radius:50%;background:#94a3b8;display:inline-block"></span> En attente de confirmations officielles</div>
    </div>

    <div class="reveal" style="margin-top:28px">
      <h3 style="text-align:center;font-family:var(--font-serif);font-size:1.2rem;font-weight:700;color:var(--royal-900)">Devenir partenaire officiel</h3>
      <p style="text-align:center;font-size:.84rem;color:#5b6577;margin-top:6px">Associez votre marque à Miss Aurora RDC — 6 formules disponibles.</p>
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-top:18px">
        <div style="background:#FFFFFF;border:1px solid rgba(5,11,22,.07);border-radius:14px;padding:16px;text-align:center"><div style="width:36px;height:36px;margin:0 auto 8px;border-radius:10px;background:rgba(212,175,55,.10);border:1px solid rgba(212,175,55,.18);display:flex;align-items:center;justify-content:center;color:#8a6a00"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 2l3 7h7l-5.5 4 2 7L12 16l-6.5 4 2-7L2 9h7z"/></svg></div><div style="font-size:.82rem;font-weight:700;color:var(--royal-900)">Sponsor officiel</div><div style="font-size:.68rem;color:#94a3b8;margin-top:3px">Visibilité premium</div></div>
        <div style="background:#FFFFFF;border:1px solid rgba(5,11,22,.07);border-radius:14px;padding:16px;text-align:center"><div style="width:36px;height:36px;margin:0 auto 8px;border-radius:10px;background:rgba(7,26,61,.06);border:1px solid rgba(7,26,61,.08);display:flex;align-items:center;justify-content:center;color:var(--royal-700)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg></div><div style="font-size:.82rem;font-weight:700;color:var(--royal-900)">Partenaire officiel</div><div style="font-size:.68rem;color:#94a3b8;margin-top:3px">Présence événementielle</div></div>
        <div style="background:#FFFFFF;border:1px solid rgba(5,11,22,.07);border-radius:14px;padding:16px;text-align:center"><div style="width:36px;height:36px;margin:0 auto 8px;border-radius:10px;background:rgba(5,11,22,.04);border:1px solid rgba(5,11,22,.07);display:flex;align-items:center;justify-content:center;color:#475569"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="2" y="2" width="20" height="20" rx="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg></div><div style="font-size:.82rem;font-weight:700;color:var(--royal-900)">Partenaire média</div><div style="font-size:.68rem;color:#94a3b8;margin-top:3px">Diffusion & couverture</div></div>
        <div style="background:#FFFFFF;border:1px solid rgba(5,11,22,.07);border-radius:14px;padding:16px;text-align:center"><div style="width:36px;height:36px;margin:0 auto 8px;border-radius:10px;background:rgba(5,11,22,.04);border:1px solid rgba(5,11,22,.07);display:flex;align-items:center;justify-content:center;color:#475569"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div><div style="font-size:.82rem;font-weight:700;color:var(--royal-900)">Partenaire institutionnel</div><div style="font-size:.68rem;color:#94a3b8;margin-top:3px">Appui officiel</div></div>
        <div style="background:#FFFFFF;border:1px solid rgba(5,11,22,.07);border-radius:14px;padding:16px;text-align:center"><div style="width:36px;height:36px;margin:0 auto 8px;border-radius:10px;background:rgba(5,11,22,.04);border:1px solid rgba(5,11,22,.07);display:flex;align-items:center;justify-content:center;color:#475569"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg></div><div style="font-size:.82rem;font-weight:700;color:var(--royal-900)">Partenaire technique</div><div style="font-size:.68rem;color:#94a3b8;margin-top:3px">Logistique & production</div></div>
        <div style="background:#FFFFFF;border:1px solid rgba(5,11,22,.07);border-radius:14px;padding:16px;text-align:center"><div style="width:36px;height:36px;margin:0 auto 8px;border-radius:10px;background:rgba(5,11,22,.04);border:1px solid rgba(5,11,22,.07);display:flex;align-items:center;justify-content:center;color:#475569"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="10"/><path d="M12 16a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"/><path d="M12 8v8"/></svg></div><div style="font-size:.82rem;font-weight:700;color:var(--royal-900)">Partenaire culturel</div><div style="font-size:.68rem;color:#94a3b8;margin-top:3px">Patrimoine & arts</div></div>
      </div>
    </div>

    <div style="text-align:center;margin-top:18px">    <div style="text-align:center;margin-top:18px"><a href="#contact" class="btn-dark">Proposer un partenariat <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></a></div>
    <p style="text-align:center;font-size:.72rem;color:#94a3b8;margin-top:10px">Aucun nom de partenaire n’est inventé — affichés après confirmation officielle.</p>
  </div>
</section>

<!-- RESEAUX SOCIAUX -->
<section class="au-section au-section--dark" id="reseaux" aria-labelledby="socialTitle">
  <div class="au-wrap">
    <div class="au-head--center reveal">
      <div class="au-eyebrow au-eyebrow--center" style="color:var(--gold-light)">Suivez l’aventure</div>
      <h2 class="au-title" id="socialTitle" style="color:#fff">Suivez l’aventure <em>Aurora</em></h2>
      <div class="au-bar au-bar--center"></div>
      <p class="au-subtitle" style="color:rgba(255,255,255,.58)">Ne manquez aucun moment : annonces, coulisses, votes et finale — partout où vous êtes.</p>
    </div>
    <div class="social-grid reveal">
      <a href="https://www.facebook.com/photo.php?fbid=122094960069398317&set=a.122094959679398317&type=3&mibextid=rS40aB7S9Ucbxw6v" target="_blank" rel="noopener" class="social-card" aria-label="Facebook Miss Aurora RDC"><span class="social-card__icon social-card__icon--fb"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg></span><span class="social-card__name">Facebook</span><span class="social-card__handle">Miss Aurora RDC</span></a>
      <a href="https://www.instagram.com/miss_aurora_rdc?igsh=MTEyaml6ZDYxaXg=" target="_blank" rel="noopener" class="social-card" aria-label="Instagram Miss Aurora RDC"><span class="social-card__icon social-card__icon--ig"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1.2"/></svg></span><span class="social-card__name">Instagram</span><span class="social-card__handle">Miss Aurora RDC</span></a>
      <a href="#" class="social-card" aria-label="TikTok Miss Aurora RDC"><span class="social-card__icon social-card__icon--tt"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67A2.89 2.89 0 0 1 9.5 18.5a2.89 2.89 0 0 1-2.89-2.89 2.89 2.89 0 0 1 2.89-2.89c.28 0 .54.04.79.1V9.01a6.27 6.27 0 0 0-.79-.05 6.34 6.34 0 0 0-6.34 6.34 6.34 6.34 0 0 0 6.34 6.34 6.34 6.34 0 0 0 6.34-6.34V8.75a8.16 8.16 0 0 0 4.76 1.52V6.84a4.83 4.83 0 0 1-1-.15z"/></svg></span><span class="social-card__name">TikTok</span><span class="social-card__handle">Miss Aurora RDC</span></a>
      <a href="#" class="social-card" aria-label="YouTube Miss Aurora RDC"><span class="social-card__icon social-card__icon--yt"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M23 12s0-3.5-.45-5.18a2.78 2.78 0 0 0-1.95-1.95C18.88 4.42 12 4.42 12 4.42s-6.88 0-8.59.45A2.78 2.78 0 0 0 1.46 6.82 29 29 0 0 0 1 12a29 29 0 0 0 .46 5.18 2.78 2.78 0 0 0 1.95 1.95C5.12 19.58 12 19.58 12 19.58s6.88 0 8.59-.45a2.78 2.78 0 0 0 1.95-1.95A29 29 0 0 0 23 12z"/><path d="M10 15.5l5-3.5-5-3.5v7z" fill="#fff"/></svg></span><span class="social-card__name">YouTube</span><span class="social-card__handle">Miss Aurora RDC</span></a>
    </div>
  </div>
</section>

<!-- CONTACT -->
<section class="au-section au-section--light" id="contact" aria-labelledby="contactTitle">
  <div class="au-wrap">
    <div class="au-head--center reveal">
      <div class="au-eyebrow au-eyebrow--center">Entrons en contact</div>
      <h2 class="au-title" id="contactTitle">Parlons de <em>votre lumière</em></h2>
      <div class="au-bar au-bar--center"></div>
      <p class="au-subtitle">Une question, une candidature, un partenariat ? L’équipe LME GROUP vous répond à Kinshasa.</p>
    </div>
    <div class="contact-grid">
      <div class="contact-infos reveal">
        <div class="contact-info">
          <span class="contact-info__icon contact-info__icon--addr"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg></span>
          <span><span class="contact-info__label">Adresse • LME GROUP</span><span class="contact-info__value">40, Avenue Kasangulu<br>Commune de Kasa-Vubu<br>Kinshasa, RDC</span></span>
        </div>
        <div class="contact-info">
          <span class="contact-info__icon contact-info__icon--phone"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-3.07"/><path d="M22 16.92v3"/><circle cx="12" cy="12" r="10" opacity=".0"/></svg></span>
          <span><span class="contact-info__label">Téléphone / WhatsApp</span><span class="contact-info__value"><a href="tel:+243860370727">+243 860 370 727</a> • <a href="https://wa.me/243860370727" target="_blank" style="color:#065F46;font-weight:700">WhatsApp</a></span></span>
        </div>
        <div class="contact-info">
          <span class="contact-info__icon contact-info__icon--mail"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg></span>
          <span><span class="contact-info__label">Email</span><span class="contact-info__value"><a href="mailto:actutara@gmail.com">actutara@gmail.com</a></span></span>
        </div>
        <div class="contact-actions">
          <a href="https://wa.me/243860370727" target="_blank" class="contact-action contact-action--wa"><svg width="15" height="15" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19.05 4.91A9.82 9.82 0 0 0 12.04 2 9.86 9.86 0 0 0 2.16 11.86a9.86 9.86 0 0 0 1.29 4.95L2 22l5.32-1.4a9.86 9.86 0 0 0 4.72 1.2h.01a9.86 9.86 0 0 0 9.88-9.88 9.82 9.82 0 0 0-2.88-6.96z"/></svg> WhatsApp</a>
          <a href="tel:+243860370727" class="contact-action contact-action--ghost"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07"/><path d="M22 16.92v3"/></svg> Appeler</a>
          <a href="mailto:actutara@gmail.com" class="contact-action contact-action--ghost"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg> Email</a>
        </div>
        <div class="contact-socials">
          <a href="https://www.facebook.com/photo.php?fbid=122094960069398317&set=a.122094959679398317&type=3&mibextid=rS40aB7S9Ucbxw6v" target="_blank" rel="noopener" class="contact-social"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg> Facebook</a>
          <a href="https://www.instagram.com/miss_aurora_rdc?igsh=MTEyaml6ZDYxaXg=" target="_blank" rel="noopener" class="contact-social"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1.2"/></svg> Instagram</a>
          <a href="candidatures.php" class="contact-social"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/></svg> Candidatures</a>
        </div>
      </div>
      <form class="contact-form reveal reveal-delay-1" onsubmit="return handleContact(event)" novalidate>
        <div class="contact-form__head">
          <span class="contact-form__head-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg></span>
          <div>
            <h3 class="contact-form__title">Envoyez-nous <em>un message</em></h3>
            <p class="contact-form__sub">Réponse sous 24h par l’équipe LME GROUP.</p>
          </div>
        </div>
        <div class="contact-form__grid">
          <div class="fg fg--icon"><label for="cPrenom">Prénom <span>*</span></label><svg class="fg__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H11a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><input id="cPrenom" type="text" placeholder="Votre prénom" required autocomplete="given-name"></div>
          <div class="fg fg--icon"><label for="cNom">Nom <span>*</span></label><svg class="fg__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M19 21v-2a4 4 0 0 0-4-4H11a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><input id="cNom" type="text" placeholder="Votre nom" required autocomplete="family-name"></div>
        </div>
        <div class="fg fg--icon"><label for="cEmail">Email <span>*</span></label><svg class="fg__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg><input id="cEmail" type="email" placeholder="votre@email.com" required autocomplete="email"></div>
        <div class="fg fg--icon"><label for="cObjet">Objet <span>*</span></label><svg class="fg__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
          <select id="cObjet" required><option value="">Sélectionnez un objet…</option><option>Candidature Miss Aurora RDC</option><option>Partenariat / Sponsoring</option><option>Billetterie / Finale</option><option>Information générale</option><option>Presse / Médias</option></select>
        </div>
        <div class="fg"><label for="cMessage">Message <span>*</span></label><textarea id="cMessage" placeholder="Votre message…" required></textarea></div>
        <button type="submit" class="contact-submit">Envoyer le message <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M22 2L11 13"/><path d="M22 2L15 22L11 13L2 9L22 2z"/></svg></button>
        <p id="contactNote" class="contact-note"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 22c5-3 8-6 8-10a8 8 0 0 0-16 0c0 4 3 7 8 10z"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg> Aucun spam — réponse humaine garantie.</p>
      </form>
    </div>
  </div>
</section>

<!-- FOOTER SOBRE — AIRBNB PREMIUM -->
<footer class="aurora-footer" role="contentinfo">
  <div class="aurora-footer__wrap">
    <div class="aurora-footer__top">
      <div class="aurora-footer__brand">
        <a href="index.php" class="aurora-footer__logo" aria-label="Miss Aurora RDC">
          <?php if($siteLogoUrl): ?><img src="<?= $siteLogoUrl ?>" alt="Miss Aurora RDC" width="36" height="36" style="width:36px;height:36px;object-fit:contain;border-radius:6px;"><?php else: ?><span class="aurora-footer__logo-mark" aria-hidden="true"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M2 19a1 1 0 0 0 1 1h18a1 1 0 0 0 1-1v-1H2v1Z"/><path d="M2 19 4 9l4 3 3-5 3 5 4-3 4 10"/></svg></span><?php endif; ?>
          <div>
            <div class="aurora-footer__logo-text">MISS AURORA RDC</div>
            <div class="aurora-footer__logo-sub">Une initiative de LME GROUP</div>
          </div>
        </a>
        <p class="aurora-footer__desc">La beauté au service du changement. Révéler la lumière qui inspire l’avenir. Kinshasa, République Démocratique du Congo.</p>
        <div class="aurora-footer__socials" aria-label="Réseaux sociaux">
          <a href="https://www.facebook.com/photo.php?fbid=122094960069398317&set=a.122094959679398317&type=3&mibextid=rS40aB7S9Ucbxw6v" target="_blank" rel="noopener" class="aurora-footer__social" aria-label="Facebook"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg></a>
          <a href="https://www.instagram.com/miss_aurora_rdc?igsh=MTEyaml6ZDYxaXg=" target="_blank" rel="noopener" class="aurora-footer__social" aria-label="Instagram"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1.2"/></svg></a>
          <a href="#" class="aurora-footer__social" aria-label="TikTok"><svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67A2.89 2.89 0 0 1 9.5 18.5a2.89 2.89 0 0 1-2.89-2.89 2.89 2.89 0 0 1 2.89-2.89c.28 0 .54.04.79.1V9.01a6.27 6.27 0 0 0-.79-.05A6.34 6.34 0 0 0-6.34 6.34A6.34 6.34 0 0 0 6.34 12.72a6.34 6.34 0 0 0 6.34-6.34V8.75a8.16 8.16 0 0 0 4.76 1.52V6.84a4.83 4.83 0 0 1-1-.15Z"/></svg></a>
          <a href="#" class="aurora-footer__social" aria-label="YouTube"><svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M23 12s0-3.5-.45-5.18a2.78 2.78 0 0 0-1.95-1.95C18.88 4.42 12 4.42 12 4.42s-6.88 0-8.59.45A2.78 2.78 0 0 0 1.46 6.82A29 29 0 0 0 1 12a29 29 0 0 0 .46 5.18 2.78 2.78 0 0 0 1.95 1.95C5.12 19.58 12 19.58 12 19.58s6.88 0 8.59-.45A2.78 2.78 0 0 0 1.95-1.95A29 29 0 0 0 23 12Z"/><path d="M10 15.5l5-3.5-5-3.5v7Z" fill="#fff"/></svg></a>
        </div>
      </div>
      <div class="aurora-footer__grid">
        <div class="aurora-footer__col" id="footCol1">
          <h3 class="aurora-footer__col-title" data-foot="footCol1">Miss Aurora RDC</h3>
          <div class="aurora-footer__col-links">
            <ul class="aurora-footer__links">
              <li><a href="#apropos" class="aurora-footer__link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg> À propos</a></li>
              <li><a href="#candidates" class="aurora-footer__link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg> Candidates</a></li>
              <li><a href="#vote" class="aurora-footer__link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M19 14c1.5-1.6 3-3.2 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2.08C10.5 3.5 9.26 3 7.5 3A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 3.9 3 5.5l7 7Z"/></svg> Vote</a></li>
              <li><a href="#billetterie" class="aurora-footer__link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M2 9a3 3 0 0 1 3-3h14a3 3 0 0 1 3 3v6a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3V9Z"/><path d="M13 5v14"/></svg> Billetterie</a></li>
              <li><a href="#finale" class="aurora-footer__link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z"/></svg> Finale nationale</a></li>
            </ul>
          </div>
        </div>
        <div class="aurora-footer__col" id="footCol2">
          <h3 class="aurora-footer__col-title" data-foot="footCol2">Le concours</h3>
          <div class="aurora-footer__col-links">
            <ul class="aurora-footer__links">
              <li><a href="#apropos" class="aurora-footer__link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg> Présentation</a></li>
              <li><a href="#parcours" class="aurora-footer__link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><polygon points="1 6 1 22 8 18 16 22 23 18 23 2 16 6 8 2 1 6"/><line x1="8" y1="2" x2="8" y2="18"/><line x1="16" y1="6" x2="16" y2="22"/></svg> Parcours</a></li>
              <li><a href="#conditions" class="aurora-footer__link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="m9 12 2 2 4-4"/></svg> Conditions</a></li>
              <li><a href="#dossier" class="aurora-footer__link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg> Candidature</a></li>
              <li><a href="#dossier" class="aurora-footer__link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> Règlement</a></li>
            </ul>
          </div>
        </div>
        <div class="aurora-footer__col" id="footCol3">
          <h3 class="aurora-footer__col-title" data-foot="footCol3">LME Group</h3>
          <div class="aurora-footer__col-links">
            <ul class="aurora-footer__links">
              <li><a href="#organisation" class="aurora-footer__link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22V12h6v10"/></svg> Organisation</a></li>
              <li><a href="#organisation" class="aurora-footer__link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg> Notre mission</a></li>
              <li><a href="#valeurs" class="aurora-footer__link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M19 14c1.5-1.6 3-3.2 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2.08C10.5 3.5 9.26 3 7.5 3A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 3.9 3 5.5l7 7Z"/></svg> Nos valeurs</a></li>
              <li><a href="#partenaires" class="aurora-footer__link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg> Partenariats</a></li>
              <li><a href="#contact" class="aurora-footer__link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg> Contact</a></li>
            </ul>
            <div class="aurora-footer__contact">
              <div class="aurora-footer__contact-item">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg>
                <span>40, Av. Kasangulu<br>Commune de Kasa-Vubu<br>Kinshasa, RDC</span>
              </div>
              <div class="aurora-footer__contact-item">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.27h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.9a16 16 0 0 0 6.09 6.09l.91-.91a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92Z"/></svg>
                <a href="tel:+243860370727">+243 860 370 727</a>
              </div>
              <div class="aurora-footer__contact-item">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                <a href="mailto:actutara@gmail.com">actutara@gmail.com</a>
              </div>
            </div>
          </div>
        </div>
        <div class="aurora-footer__col" id="footCol4">
          <h3 class="aurora-footer__col-title" data-foot="footCol4">Nous suivre</h3>
          <div class="aurora-footer__col-links">
            <ul class="aurora-footer__links">
              <li><a href="https://www.facebook.com/photo.php?fbid=122094960069398317&set=a.122094959679398317&type=3&mibextid=rS40aB7S9Ucbxw6v" target="_blank" rel="noopener" class="aurora-footer__link"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="width:14px;height:14px;color:#1877F2"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg> Facebook</a></li>
              <li><a href="https://www.instagram.com/miss_aurora_rdc?igsh=MTEyaml6ZDYxaXg=" target="_blank" rel="noopener" class="aurora-footer__link"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true" style="width:14px;height:14px"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1.2"/></svg> Instagram</a></li>
              <li><a href="#" class="aurora-footer__link"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="width:13px;height:13px"><path d="M19.59 6.69a4.83 4.83 0 0 1-3.77-4.25V2h-3.45v13.67A2.89 2.89 0 0 1 9.5 18.5a2.89 2.89 0 0 1-2.89-2.89 2.89 2.89 0 0 1 2.89-2.89c.28 0 .54.04.79.1V9.01a6.27 6.27 0 0 0-.79-.05A6.34 6.34 0 0 0-6.34 6.34A6.34 6.34 0 0 0 6.34 12.72a6.34 6.34 0 0 0 6.34-6.34V8.75a8.16 8.16 0 0 0 4.76 1.52V6.84a4.83 4.83 0 0 1-1-.15Z"/></svg> TikTok</a></li>
              <li><a href="#" class="aurora-footer__link"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="width:14px;height:14px;color:#FF0000"><path d="M23 12s0-3.5-.45-5.18a2.78 2.78 0 0 0-1.95-1.95C18.88 4.42 12 4.42 12 4.42s-6.88 0-8.59.45A2.78 2.78 0 0 0 1.46 6.82A29 29 0 0 0 1 12a29 29 0 0 0 .46 5.18 2.78 2.78 0 0 0 1.95 1.95C5.12 19.58 12 19.58 12 19.58s6.88 0 8.59-.45A2.78 2.78 0 0 0 1.95-1.95A29 29 0 0 0 23 12Z"/><path d="M10 15.5l5-3.5-5-3.5v7Z" fill="#fff"/></svg> YouTube</a></li>
            </ul>
          </div>
        </div>
      </div>
    </div>
    <div class="aurora-footer__newsletter">
      <div class="aurora-footer__newsletter-text">
        <div class="aurora-footer__newsletter-title">Recevez les actualités de Miss Aurora RDC</div>
        <div class="aurora-footer__newsletter-sub">Annonces, candidates, votes et finale — directement dans votre boîte mail.</div>
      </div>
      <form class="aurora-footer__newsletter-form" onsubmit="return ftHandleNewsletter(event)" novalidate>
        <label class="sr-only" for="ftEmail">Adresse email</label>
        <input id="ftEmail" class="aurora-footer__newsletter-input" type="email" placeholder="Votre adresse email" required autocomplete="email">
        <button class="aurora-footer__newsletter-btn" type="submit">S'inscrire</button>
      </form>
    </div>
    <div class="aurora-footer__bottom">
      <p class="aurora-footer__copy">© 2026 <strong>Miss Aurora RDC</strong> — Une initiative de <strong>LME GROUP</strong></p>
      <nav class="aurora-footer__legal" aria-label="Liens légaux">
        <a href="#">Confidentialité</a><span class="aurora-footer__legal-dot" aria-hidden="true"></span>
        <a href="#">Conditions</a><span class="aurora-footer__legal-dot" aria-hidden="true"></span>
        <a href="#">Mentions légales</a><span class="aurora-footer__legal-dot" aria-hidden="true"></span>
        <a href="#">Made by Zaloria Tech</a>
      </nav>
    </div>
</div>
</footer>

<!-- BOTTOM NAV — APP MOBILE -->
<nav class="aurora-bottom-nav" aria-label="Navigation principale mobile">
  <div class="aurora-bottom-nav__inner">
    <a href="#accueil" class="aurora-bottom-nav__item is-active" aria-label="Accueil">
      <!-- Lucide: Home -->
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M3 9 12 2l9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V9Z"/><path d="M9 22V12h6v10"/></svg>
      <span>Accueil</span>
    </a>
    <a href="#candidates" class="aurora-bottom-nav__item" aria-label="Candidates">
      <!-- Lucide: Users -->
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
      <span>Candidates</span>
    </a>
    <a href="#vote" class="aurora-bottom-nav__item" aria-label="Vote">
      <!-- Lucide: Vote -->
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M9 12h6"/><path d="M12 9v6"/><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/></svg>
      <span>Vote</span>
    </a>
    <a href="#billetterie" class="aurora-bottom-nav__item" aria-label="Billetterie">
      <!-- Lucide: Ticket -->
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M2 9a3 3 0 0 1 3-3h14a3 3 0 0 1 3 3v6a3 3 0 0 1-3 3H5a3 3 0 0 1-3-3V9Z"/><path d="M9 9h.01"/><path d="M15 9h.01"/><path d="M9 15h.01"/><path d="M15 15h.01"/></svg>
      <span>Billetterie</span>
    </a>
    <a href="#" class="aurora-bottom-nav__item" id="bottomMenuBtn" aria-label="Menu" aria-expanded="false" aria-controls="auroraDrawer">
      <!-- Lucide: Menu -->
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/></svg>
      <span>Menu</span>
    </a>
  </div>
</nav>

<!-- PWA INSTALL BANNER -->
<div class="aurora-install" id="pwaInstall" role="dialog" aria-label="Installer l'application" aria-hidden="true">
  <div class="aurora-install__icon" aria-hidden="true">
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M2 19a1 1 0 0 0 1 1h18a1 1 0 0 0 1-1v-1H2v1Z"/><path d="M2 19 4 9l4 3 3-5 3 5 4-3 4 10"/></svg>
  </div>
  <div class="aurora-install__text">
    <div class="aurora-install__title">Installer Miss Aurora RDC</div>
    <div class="aurora-install__sub">Accès rapide comme une vraie app</div>
  </div>
  <button class="aurora-install__btn" id="pwaInstallBtn">Installer</button>
  <button class="aurora-install__close" id="pwaInstallClose" aria-label="Fermer">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
  </button>
</div>

<script>
// ===== HEADER SOBRE + DRAWER SOBRE =====
(function(){
  const header=document.getElementById('auroraHeader');
  const burger=document.getElementById('auroraBurger');
  const drawer=document.getElementById('auroraDrawer');
  const overlay=document.getElementById('auroraOverlay');
  const drawerClose=document.getElementById('drawerClose');
  const toggleSub=document.getElementById('drawerConcoursToggle');
  const sub=document.getElementById('drawerSub');
  const onScroll=()=> header.classList.toggle('is-sticky', scrollY>8);
  addEventListener('scroll', onScroll, {passive:true}); onScroll();
  function open(){ burger.classList.add('is-open'); drawer.classList.add('is-open'); overlay.classList.add('is-open'); burger.setAttribute('aria-expanded','true'); drawer.setAttribute('aria-hidden','false'); document.body.style.overflow='hidden';}
  function close(){ burger.classList.remove('is-open'); drawer.classList.remove('is-open'); overlay.classList.remove('is-open'); burger.setAttribute('aria-expanded','false'); drawer.setAttribute('aria-hidden','true'); document.body.style.overflow='';}
  burger.addEventListener('click',()=> drawer.classList.contains('is-open')?close():open());
  drawerClose && drawerClose.addEventListener('click', close);
  overlay.addEventListener('click', close);
  addEventListener('keydown', e=>{ if(e.key==='Escape' && drawer.classList.contains('is-open')) close();});
  drawer.querySelectorAll('a').forEach(a=> a.addEventListener('click', close));
  if(toggleSub && sub){
    toggleSub.addEventListener('click', e=>{ e.preventDefault(); sub.classList.toggle('is-open');});
  }
  // lang
  const langBtn=document.getElementById('langBtn');
  const langMenu=document.getElementById('langMenu');
  if(langBtn && langMenu){
    langBtn.addEventListener('click', e=>{
      e.stopPropagation();
      const open=langMenu.classList.toggle('is-open');
      langBtn.setAttribute('aria-expanded', open?'true':'false');
      langMenu.setAttribute('aria-hidden', open?'false':'true');
    });
    document.addEventListener('click', ()=>{ langMenu.classList.remove('is-open'); langBtn.setAttribute('aria-expanded','false'); langMenu.setAttribute('aria-hidden','true');});
    langMenu.querySelectorAll('.aurora-lang__opt').forEach(opt=>{
      opt.addEventListener('click', ()=>{
        langMenu.querySelectorAll('.aurora-lang__opt').forEach(o=>o.classList.remove('is-active'));
        opt.classList.add('is-active');
        langBtn.childNodes[2] && (langBtn.childNodes[2].textContent=' '+opt.dataset.lang.toUpperCase()+' ');
        // simple toast
        const note=document.getElementById('contactNote');
        if(note){ const old=note.textContent; note.textContent='Langue : '+opt.textContent.trim()+' — bientôt disponible.'; setTimeout(()=> note.textContent=old, 2500);}
        langMenu.classList.remove('is-open');
      });
    });
  }
})();

// ===== HERO SLIDER =====
(function(){
  const TOTAL=<?= (int)$totalSlides ?>;
  const slides=document.querySelectorAll('.aurora-hero__slide');
  if(!slides.length) return;
  const totalEl=document.getElementById('heroTotal');
  const currentEl=document.getElementById('heroCurrent');
  const bar=document.getElementById('heroBar');
  const progress=document.getElementById('heroProgress');
  const ghost=document.getElementById('heroGhost');
  const INTERVAL=6000;
  let cur=0, timer=null;
  const pad=n=> n<10?'0'+n:''+n;
  function goTo(idx){
    const prev=cur;
    cur=((idx%slides.length)+slides.length)%slides.length;
    slides[prev].classList.remove('is-active');
    slides[cur].classList.add('is-active');
    if(currentEl) currentEl.textContent=pad(cur+1);
    if(bar) bar.style.setProperty('--prog', ((cur+1)/slides.length*100)+'%');
    const name=slides[cur].dataset.name||'Miss Aurora RDC';
    if(ghost) ghost.textContent=name.split(' ')[0]||'Aurora';
    reset();
  }
  function reset(){
    clearTimeout(timer);
    if(progress){
      progress.style.transition='none';
      progress.style.width='0%';
      requestAnimationFrame(()=>{
        progress.style.transition='width '+INTERVAL+'ms linear';
        progress.style.width='100%';
      });
    }
    timer=setTimeout(()=> goTo(cur+1), INTERVAL);
  }
  document.getElementById('heroPrev')?.addEventListener('click', ()=> goTo(cur-1));
  document.getElementById('heroNext')?.addEventListener('click', ()=> goTo(cur+1));
  addEventListener('keydown', e=>{ if(e.key==='ArrowLeft') goTo(cur-1); if(e.key==='ArrowRight') goTo(cur+1);});
  const visual=document.getElementById('heroVisual');
  let tx=0;
  visual?.addEventListener('touchstart', e=> tx=e.changedTouches[0].screenX, {passive:true});
  visual?.addEventListener('touchend', e=>{ const dx=e.changedTouches[0].screenX-tx; if(Math.abs(dx)>50) goTo(dx<0?cur+1:cur-1);}, {passive:true});
  visual?.addEventListener('mouseenter', ()=>{ clearTimeout(timer); if(progress) progress.style.transition='none';});
  visual?.addEventListener('mouseleave', reset);
  goTo(0);
})();

// ===== HERO BG CAROUSEL — FOND PC & MOBILE (désactivé si hero-cc existe, géré par hero-cc) =====
(function(){
  const bg = document.querySelectorAll('#heroBgSlides .aurora-hero__bg-slide');
  if(!bg.length || bg.length<=1) return;
  // Si le nouveau carousel candidates existe, il gère déjà le fond
  if(document.getElementById('heroCandidatesCarousel')){
    // ne pas lancer timer auto, laisser hero-cc sync
    return;
  }
  let cur = 0;
  const INTERVAL = 5200;
  let timer = setInterval(()=> {
    bg[cur].classList.remove('is-active');
    cur = (cur+1)%bg.length;
    bg[cur].classList.add('is-active');
  }, INTERVAL);
  // pause quand onglet caché
  document.addEventListener('visibilitychange', ()=>{
    if(document.hidden) clearInterval(timer);
    else timer = setInterval(()=>{ bg[cur].classList.remove('is-active'); cur=(cur+1)%bg.length; bg[cur].classList.add('is-active'); }, INTERVAL);
  });
  // swipe tactile sur hero
  const shell = document.querySelector('.aurora-hero__shell');
  let sx=0;
  shell?.addEventListener('touchstart', e=> sx=e.changedTouches[0].clientX, {passive:true});
  shell?.addEventListener('touchend', e=>{
    const dx=e.changedTouches[0].clientX - sx;
    if(Math.abs(dx)>48){
      clearInterval(timer);
      bg[cur].classList.remove('is-active');
      cur = dx<0 ? (cur+1)%bg.length : (cur-1+bg.length)%bg.length;
      bg[cur].classList.add('is-active');
      timer = setInterval(()=>{ bg[cur].classList.remove('is-active'); cur=(cur+1)%bg.length; bg[cur].classList.add('is-active'); }, INTERVAL);
    }
  }, {passive:true});
})();

// ===== HERO CANDIDATES CAROUSEL — REMPLACE 4 IMAGES =====
(function(){
  const viewport = document.getElementById('heroCcViewport');
  const track = document.getElementById('heroCcTrack');
  const dotsWrap = document.getElementById('heroCcDots');
  const progressFill = document.getElementById('heroCcProgress');
  const prevBtn = document.getElementById('heroCcPrev');
  const nextBtn = document.getElementById('heroCcNext');
  if(!viewport || !track) return;
  const slides = track.querySelectorAll('.hero-cc__slide');
  if(slides.length<=1){
    if(dotsWrap) dotsWrap.style.display='none';
    if(progressFill) progressFill.style.display='none';
    // still handle lazy for single
  }
  let cur=0;
  let timer=null;
  const INTERVAL=4200;
  let progressTimer=null;

  // build dots
  function buildDots(){
    if(!dotsWrap) return;
    dotsWrap.innerHTML='';
    slides.forEach((_,i)=>{
      const b=document.createElement('button');
      b.className='hero-cc__dot'+(i===0?' is-active':'');
      b.setAttribute('role','tab');
      b.setAttribute('aria-label','Aller à la candidate '+(i+1));
      b.addEventListener('click',()=>goTo(i,true));
      dotsWrap.appendChild(b);
    });
  }
  function updateDots(){
    if(!dotsWrap) return;
    dotsWrap.querySelectorAll('.hero-cc__dot').forEach((d,i)=>d.classList.toggle('is-active', i===cur));
  }
  function goTo(idx, user=false){
    if(idx<0) idx=slides.length-1;
    if(idx>=slides.length) idx=0;
    cur=idx;
    track.style.transform='translateX(-'+(cur*100)+'%)';
    slides.forEach((s,i)=>s.classList.toggle('is-active', i===cur));
    updateDots();
    // lazy load current + next
    [cur, (cur+1)%slides.length].forEach(i=>{
      const img=slides[i]?.querySelector('img.lazy-img[data-src]');
      if(img && !img.src){
        img.src=img.dataset.src;
      }
    });
    resetProgress();
    if(user) resetTimer();
    // sync bg slides if same count
    const bgSlides=document.querySelectorAll('#heroBgSlides .aurora-hero__bg-slide');
    if(bgSlides.length===slides.length){
      bgSlides.forEach((b,i)=>b.classList.toggle('is-active', i===cur));
    }
  }
  function resetTimer(){
    clearInterval(timer);
    timer=setInterval(()=>goTo(cur+1), INTERVAL);
  }
  function resetProgress(){
    if(!progressFill) return;
    clearInterval(progressTimer);
    progressFill.style.transition='none';
    progressFill.style.width='0%';
    requestAnimationFrame(()=>{
      progressFill.style.transition='width '+INTERVAL+'ms linear';
      progressFill.style.width='100%';
    });
  }
  // events
  prevBtn?.addEventListener('click',()=>goTo(cur-1,true));
  nextBtn?.addEventListener('click',()=>goTo(cur+1,true));
  // swipe
  let sx=0, sy=0;
  viewport.addEventListener('touchstart', e=>{
    sx=e.changedTouches[0].clientX; sy=e.changedTouches[0].clientY;
  }, {passive:true});
  viewport.addEventListener('touchend', e=>{
    const dx=e.changedTouches[0].clientX - sx;
    const dy=e.changedTouches[0].clientY - sy;
    if(Math.abs(dx)>46 && Math.abs(dx)>Math.abs(dy)){
      goTo(dx<0?cur+1:cur-1,true);
    }
  }, {passive:true});
  viewport.addEventListener('mouseenter',()=>{
    clearInterval(timer);
    if(progressFill) progressFill.style.transition='none';
  });
  viewport.addEventListener('mouseleave',()=>{
    resetTimer();
    resetProgress();
  });
  document.addEventListener('visibilitychange',()=>{
    if(document.hidden){ clearInterval(timer); }
    else { resetTimer(); resetProgress(); }
  });
  // init
  buildDots();
  goTo(0);
  resetTimer();
  resetProgress();
  // expose for bg sync
  window.heroCcGoTo=goTo;
})();

// ===== LAZY LOADING APP-LIKE — CANDIDATES + HERO =====
(function(){
  const lazyImgs=document.querySelectorAll('img.lazy-img[data-src], .hero-cc__slide img.lazy-img[data-src]');
  // First, eagerly load first hero slide (already)
  const firstHero=document.querySelector('.hero-cc__slide.is-active img.lazy-img[data-src]');
  if(firstHero && firstHero.dataset.src){
    firstHero.src=firstHero.dataset.src;
    firstHero.addEventListener('load',()=>{
      firstHero.classList.add('is-loaded');
      firstHero.closest('.hero-cc__slide')?.querySelector('.hero-cc__skeleton')?.remove();
      firstHero.closest('.candidate-card__photo')?.classList.add('is-loaded');
    }, {once:true});
    // if cached
    if(firstHero.complete) firstHero.classList.add('is-loaded');
  }

  if('IntersectionObserver' in window){
    const io=new IntersectionObserver((entries)=>{
      entries.forEach(entry=>{
        if(entry.isIntersecting){
          const img=entry.target;
          const src=img.dataset.src;
          if(src){
            // create temp image to handle load
            const temp=new Image();
            temp.onload=()=>{
              img.src=src;
              img.classList.add('is-loaded');
              img.closest('.candidate-card__photo')?.classList.add('is-loaded');
              img.closest('.hero-cc__slide')?.querySelector('.hero-cc__skeleton')?.remove();
              img.removeAttribute('data-src');
            };
            temp.onerror=()=>{
              // fallback unsplash already handled by onerror attr, but mark loaded
              img.src=img.dataset.src;
              img.classList.add('is-loaded');
              img.closest('.candidate-card__photo')?.classList.add('is-loaded');
            };
            temp.src=src;
          }
          io.unobserve(img);
        }
      });
    }, {rootMargin:'200px 0px', threshold:0.08});

    lazyImgs.forEach(img=>{
      if(img===firstHero) return; // already handled
      if(img.complete && img.src) {
        img.classList.add('is-loaded');
        img.closest('.candidate-card__photo')?.classList.add('is-loaded');
      } else {
        io.observe(img);
      }
      // fallback for cached images that fire load before observer
      img.addEventListener('load',()=>{
        img.classList.add('is-loaded');
        img.closest('.candidate-card__photo')?.classList.add('is-loaded');
        img.closest('.hero-cc__slide')?.querySelector('.hero-cc__skeleton')?.remove();
      }, {once:true});
    });
  } else {
    // no IO, load all
    lazyImgs.forEach(img=>{
      if(img.dataset.src){
        img.src=img.dataset.src;
        img.classList.add('is-loaded');
        img.closest('.candidate-card__photo')?.classList.add('is-loaded');
      }
    });
  }
})();


// ===== TICKER + PODIUM ===
function copyVoteLink(btn){
  const card=btn.closest('.candidate-card');
  const link=card?.querySelector('a[href*="voter.php"]');
  if(!link) return;
  const url=link.href;
  const doCopy=text=>{
    if(navigator.clipboard){ navigator.clipboard.writeText(text).then(ok=>feedback(btn));}
    else { const ta=document.createElement('textarea'); ta.value=text; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove(); feedback(btn);}
  };
  function feedback(b){
    const orig=b.innerHTML; const bg=b.style.background;
    b.innerHTML='✅ Copié !'; b.style.background='#10b981'; b.style.color='#fff';
    setTimeout(()=>{ b.innerHTML=orig; b.style.background=bg; b.style.color='';}, 1800);
  }
  doCopy(url);
}
function ftHandleNewsletter(e){
  e.preventDefault();
  const input=document.getElementById('ftEmail');
  const btn=e.target.querySelector('button');
  if(!input || !input.value.trim()){ input && input.focus(); return false; }
  const orig=btn.textContent;
  btn.textContent='…'; btn.disabled=true;
  // simulation envoi — conserver compatibilité avec newsletter_subscribe.php si présent
  const email=input.value.trim();
  fetch('newsletter_subscribe.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'email='+encodeURIComponent(email)})
    .then(r=>r.json().catch(()=>({success:true})))
    .then(d=>{
      btn.textContent='Inscrit ✓';
      setTimeout(()=>{ btn.textContent=orig; btn.disabled=false; input.value=''; },2400);
    }).catch(()=>{
      btn.textContent='Inscrit ✓';
      setTimeout(()=>{ btn.textContent=orig; btn.disabled=false; input.value=''; },2400);
    });
  return false;
}
function handleContact(e){
  e.preventDefault();
  const btn=e.target.querySelector('button[type="submit"]');
  const note=document.getElementById('contactNote');
  const orig=btn.innerHTML;
  btn.innerHTML='⏳ Envoi…'; btn.disabled=true;
  setTimeout(()=>{
    btn.innerHTML='✅ Message envoyé !'; btn.style.background='#10b981'; if(note) note.textContent='Merci — l’équipe LME GROUP vous répondra bientôt.';
    e.target.reset();
    setTimeout(()=>{ btn.innerHTML=orig; btn.style.background=''; btn.disabled=false; if(note) note.textContent='Aucun spam — réponse humaine garantie.';}, 3200);
  }, 900);
  return false;
}
function loadLiveStats(){
  const params=new URLSearchParams(location.search);
  const concoursId=params.get('concours_id') || <?= json_encode($concoursId) ?>;
  fetch('?ajax=votes_data&concours_id='+encodeURIComponent(concoursId))
    .then(r=>r.json())
    .then(data=>{
      // ticker ranking
      const rankEl=document.getElementById('tickerRanking');
      const votesEl=document.getElementById('tickerVotes');
      if(rankEl){
        if(!data.ranking || data.ranking.length===0){
          rankEl.innerHTML='<span class="aurora-ticker__item">Aucun vote pour le moment — soyez la première à voter !</span>';
        } else {
          // duplicate for seamless scroll
          const items=data.ranking.map((c,i)=>{
            let icon='';
            if(i===0) icon='<span class="aurora-ticker__item-icon" style="background:#FEF3C7;border-color:#FDE68A;color:#B45309"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M6 3h12l-1 9a5 5 0 0 1-10 0L6 3Z"/><path d="M12 16v6"/><path d="M8 22h8"/></svg></span>';
            else if(i===1) icon='<span class="aurora-ticker__item-icon" style="background:#F3F4F6;border-color:#E5E7EB;color:#6B7280"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="12" cy="8" r="5"/><path d="M8.5 12L12 16l3.5-4"/></svg></span>';
            else if(i===2) icon='<span class="aurora-ticker__item-icon" style="background:#FFF7ED;border-color:#FFEDD5;color:#9A3412"><svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 17 5.8 21.3l2.4-7.4L2 9.4h7.6z"/></svg></span>';
            else icon='<span class="aurora-ticker__item-icon" style="background:#FFFFFF;color:#717171"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14" opacity=".0"/></svg>#'+(i+1)+'</span>';
            return `${icon} <span class="hl">${c.nom_complet}</span> <span class="gold">${c.total_votes}</span> votes`;
          }).join(' <span class="aurora-ticker__sep">◆</span> ');
          const double=items+' <span class="aurora-ticker__sep">◆</span> '+items;
          rankEl.innerHTML='<span class="aurora-ticker__item">'+double+'</span>';
        }
      }
      if(votesEl){
        if(!data.latestVotes || data.latestVotes.length===0){
          votesEl.innerHTML='<span class="aurora-ticker__item">Aucun vote récent.</span>';
        } else {
          const items=data.latestVotes.map(v=> `<span class="aurora-ticker__item-icon" style="background:#1A1A1A;border-color:#2A2A2A;color:#6EE7B7"><svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M19 14c1.5-1.6 3-3.2 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2.08C10.5 3.5 9.26 3 7.5 3A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 3.9 3 5.5l7 7Z"/></svg></span> <span class="hl">${v.nom_complet}</span> <span class="gold">+${v.votes_accordes}</span> <span style="color:#9CA3AF;font-size:.70rem">${v.telephone_masked}</span> <span style="color:#6B7280;font-size:.68rem">${v.date_fr}</span>`).join(' <span class="aurora-ticker__sep">◆</span> ');
          const double=items+' <span class="aurora-ticker__sep">◆</span> '+items;
          votesEl.innerHTML='<span class="aurora-ticker__item">'+double+'</span>';
        }
      }
      // podium + list
      const podium=document.getElementById('podium');
      const list=document.getElementById('rankingList');
      const totalEl=document.getElementById('rankTotal');
      const total=data.totalVotesAll||0;
      if(totalEl) totalEl.textContent= (total===1 && (!data.ranking || data.ranking.every(r=>r.total_votes==0)) ? '0' : total) + ' votes';
      if(podium){
        if(!data.ranking || data.ranking.length===0){
          podium.innerHTML='<div class="ranking__empty">Aucun vote pour le moment — le podium s’affichera dès les premiers votes.</div>';
        } else {
          const top3=data.ranking.slice(0,3);
          const restPct= total>0 ? total : 1;
          const podiumHTML=top3.map((c,i)=>{
            const rank=i+1;
            const pct= total>0 ? Math.round((c.total_votes/total)*1000)/10 : 0;
            const cls=rank===1?'ranking__podium-card--first':rank===2?'ranking__podium-card--second':'ranking__podium-card--third';
            const medalCls='ranking__medal--'+rank;
            const medalLabel=rank===1?'1':rank===2?'2':'3';
            return `<div class="ranking__podium-card ${cls}"><div class="ranking__medal ${medalCls}">${rank===1?'👑':medalLabel}</div><div class="ranking__podium-name">${c.nom_complet}</div><div class="ranking__podium-code">N° ${c.code_participante}</div><div class="ranking__podium-votes">${c.total_votes} votes</div><div class="ranking__podium-pct">${pct}% • Rang ${rank}</div></div>`;
          }).join('');
          // if less than 3, fill empty
          podium.innerHTML=podiumHTML || '<div class="ranking__empty">Podium en attente</div>';
        }
      }
      if(list){
        if(!data.ranking || data.ranking.length===0){
          list.innerHTML='<div class="ranking__empty">Aucune candidate classée.</div>';
        } else {
          const rows=data.ranking.map((c,i)=>{
            const rank=i+1;
            const pct= total>0 ? Math.round((c.total_votes/total)*1000)/10 : 0;
            const rankCls= rank<=3 ? 'ranking__rank--'+rank : '';
            return `<div class="ranking__row"><span class="ranking__rank ${rankCls}">${rank}</span><span><span class="ranking__name">${c.nom_complet}</span><br><span class="ranking__code">N° ${c.code_participante}</span></span><span style="text-align:right"><span class="ranking__votes">${c.total_votes} votes</span><div class="ranking__votes-sub">${pct}%</div><div class="ranking__bar"><div class="ranking__bar-fill" style="width:${pct}%"></div></div></span></div>`;
          }).join('');
          list.innerHTML=rows;
        }
      }
      // animate candidate cards popularity after load
      document.querySelectorAll('.candidate-card').forEach(card=>{
        const fill=card.querySelector('.candidate-card__fill');
        if(fill) requestAnimationFrame(()=> card.classList.add('is-visible'));
      });
    })
    .catch(err=> console.error('Ticker error', err));
}
loadLiveStats();
setInterval(loadLiveStats, 10000);

// ===== CANDIDATES TABS =====
(function(){
  const tabs=document.querySelectorAll('.candidates-tab');
  const panels=document.querySelectorAll('.candidates-panel');
  function activate(tab){
    tabs.forEach(t=>{ t.classList.remove('is-active'); t.setAttribute('aria-selected','false');});
    tab.classList.add('is-active'); tab.setAttribute('aria-selected','true');
    panels.forEach(p=> p.classList.remove('is-active'));
    const id=tab.dataset.panel;
    const panel=document.getElementById(id);
    if(panel) panel.classList.add('is-active');
  }
  tabs.forEach(tab=>{
    tab.addEventListener('click', ()=> activate(tab));
    tab.addEventListener('keydown', e=>{ if(e.key==='Enter'||e.key===' '){ e.preventDefault(); activate(tab);} });
  });
})();

// ===== REVEAL ON SCROLL =====
(function(){
  const els=document.querySelectorAll('.reveal');
  if(!('IntersectionObserver' in window)){ els.forEach(e=> e.classList.add('is-visible')); return;}
  const io=new IntersectionObserver(entries=>{
    entries.forEach(entry=>{
      if(entry.isIntersecting){
        entry.target.classList.add('is-visible');
        // stagger children if grid
        if(entry.target.classList.contains('aurora-why__grid') || entry.target.classList.contains('candidates-grid')){
          entry.target.querySelectorAll('.aurora-why__card, .candidate-card').forEach((c,i)=> setTimeout(()=> c.classList.add('is-visible'), i*90));
        }
        io.unobserve(entry.target);
      }
    });
  }, {threshold:.12, rootMargin:'0px 0px -40px 0px'});
  els.forEach(el=> io.observe(el));
  // candidate cards initial visibility fallback
  document.querySelectorAll('.candidate-card').forEach(c=> c.classList.add('is-visible'));
})();

// ===== SMOOTH SCROLL OFFSET FOR FIXED HEADER =====
document.querySelectorAll('a[href^="#"]').forEach(a=>{
  a.addEventListener('click', e=>{
    const id=a.getAttribute('href');
    if(id.length<=1) return;
    const target=document.querySelector(id);
    if(!target) return;
    e.preventDefault();
    const top=target.getBoundingClientRect().top + scrollY - 66;
    scrollTo({top, behavior:'smooth'});
  });
});
// ===== APP LOADER — FAKE NATIVE FEEL =====
(function(){
  const l=document.getElementById('appLoader');
  if(!l) return;
  // hide after first paint + data fetch start
  let done=false;
  function hide(){ if(done) return; done=true; l.classList.add('is-hidden'); l.setAttribute('aria-busy','false'); setTimeout(()=> l.remove(), 380); }
  window.addEventListener('load', ()=> setTimeout(hide, 650));
  // fallback max 2s
  setTimeout(hide, 1900);
  // also hide right after podium fetch
  const orig=window.loadLiveStats;
  if(typeof orig==='function'){
    const iv=setInterval(()=>{ if(document.getElementById('rankingList')?.children.length){ hide(); clearInterval(iv); } }, 300);
  }
})();

// ===== FOOTER ACCORDION — MOBILE APP =====
(function(){
  const cols=document.querySelectorAll('.aurora-footer__col');
  const mq=window.matchMedia('(max-width: 640px)');
  function apply(){
    if(mq.matches){
      cols.forEach(c=> c.classList.remove('aurora-footer__col--open'));
      // open first by default
      if(cols[0]) cols[0].classList.add('aurora-footer__col--open');
    } else {
      cols.forEach(c=> c.classList.remove('aurora-footer__col--open'));
    }
  }
  apply();
  mq.addEventListener('change', apply);
  document.querySelectorAll('[data-foot]').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      if(!mq.matches) return;
      const id=btn.getAttribute('data-foot');
      const col=document.getElementById(id);
      const isOpen=col.classList.contains('aurora-footer__col--open');
      cols.forEach(c=> c.classList.remove('aurora-footer__col--open'));
      if(!isOpen) col.classList.add('aurora-footer__col--open');
    });
  });
})();

// ===== BOTTOM NAV — ACTIVE STATE & MENU =====
(function(){
  const items=document.querySelectorAll('.aurora-bottom-nav__item');
  const menuBtn=document.getElementById('bottomMenuBtn');
  const drawer=document.getElementById('auroraDrawer');
  const overlay=document.getElementById('auroraOverlay');
  const burger=document.getElementById('auroraBurger');
  function setActive(){
    const hash=location.hash || '#accueil';
    items.forEach(i=> i.classList.toggle('is-active', i.getAttribute('href')===hash));
  }
  window.addEventListener('hashchange', setActive); setActive();
  if(menuBtn){
    menuBtn.addEventListener('click', e=>{
      e.preventDefault();
      const isOpen=drawer.classList.contains('is-open');
      if(isOpen){
        drawer.classList.remove('is-open'); overlay.classList.remove('is-open'); document.body.style.overflow='';
        menuBtn.setAttribute('aria-expanded','false');
        if(burger) burger.setAttribute('aria-expanded','false');
      } else {
        drawer.classList.add('is-open'); overlay.classList.add('is-open'); document.body.style.overflow='hidden';
        menuBtn.setAttribute('aria-expanded','true');
        if(burger) burger.setAttribute('aria-expanded','true');
      }
    });
  }
})();

// ===== PWA — INSTALL & SERVICE WORKER =====
(function(){
  const banner=document.getElementById('pwaInstall');
  const btn=document.getElementById('pwaInstallBtn');
  const close=document.getElementById('pwaInstallClose');
  let deferredPrompt=null;
  // show only if not already installed
  const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone;
  if(isStandalone && banner) banner.style.display='none';

  window.addEventListener('beforeinstallprompt', e=>{
    e.preventDefault();
    deferredPrompt=e;
    // show banner after small delay, only on mobile-tablet impression
    setTimeout(()=>{
      if(banner && !isStandalone){
        banner.classList.add('is-visible');
        banner.setAttribute('aria-hidden','false');
      }
    }, 1800);
  });
  if(btn){
    btn.addEventListener('click', async ()=>{
      if(!deferredPrompt) return;
      deferredPrompt.prompt();
      const choice=await deferredPrompt.userChoice;
      if(choice.outcome==='accepted' && banner) banner.classList.remove('is-visible');
      deferredPrompt=null;
    });
  }
  if(close){
    close.addEventListener('click', ()=>{
      banner.classList.remove('is-visible');
      banner.setAttribute('aria-hidden','true');
      // hide for session
      sessionStorage.setItem('pwaDismissed','1');
    });
  }
  if(sessionStorage.getItem('pwaDismissed')==='1' && banner){
    banner.style.display='none';
  }
  // service worker
  if('serviceWorker' in navigator){
    window.addEventListener('load', ()=>{
      navigator.serviceWorker.register('sw.js').catch(()=>{});
    });
  }
})();
</script>
</body>
</html>
