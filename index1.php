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
$domain = 'zaloriatech.com'; // domaine principal
$subdomain = '';

if (preg_match('/^(.*?)\.' . preg_quote($domain, '/') . '$/', $host, $matches)) {
    $subdomain = $matches[1];
} else {
    $subdomain = 'gestion'; // défaut
}

// Recherche du site correspondant
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

// Si aucun concours actif, on prend le premier (même inactif) comme fallback
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

// ========== Gestion du concours sélectionné ==========
// On vérifie si un concours_id est passé en GET, sinon on prend le premier
$selectedConcoursId = isset($_GET['concours_id']) ? (int)$_GET['concours_id'] : 0;
$currentConcours = null;
foreach ($concoursList as $c) {
    if ($c['concours_id'] === $selectedConcoursId) {
        $currentConcours = $c;
        break;
    }
}
if (!$currentConcours && !empty($concoursList)) {
    $currentConcours = $concoursList[0]; // premier par défaut
}
$concoursId = $currentConcours ? $currentConcours['concours_id'] : 0;

// ========== Récupération des données pour le concours sélectionné ==========
$participantes = [];
$heroCandidates = [];
$allCandidates = [];
$etapes = [];
$candidatesByEtape = [];
$totalVotesAll = 1; // valeur par défaut

if ($concoursId > 0) {
    // ----- Candidates (toutes) avec votes -----
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

    // ----- Étapes (uniquement celles non terminées) -----
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

    // ----- Candidates par étape (via parcours_participantes) avec vérification de l'étape non terminée -----
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

    // ----- Filtrer les candidates pour le Hero et la liste globale (uniquement celles dans une étape non terminée) -----
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

    // ----- Total votes (pour les pourcentages) -----
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
    // Pour le classement, on utilise le concours_id fourni ou celui sélectionné
    $ajaxConcoursId = isset($_GET['concours_id']) ? (int)$_GET['concours_id'] : $concoursId;
    if ($ajaxConcoursId > 0) {
        // Classement : seules les candidates dont l'étape est non terminée
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

        $totalVotesAll = 0;
        foreach ($ranking as $r) $totalVotesAll += $r['total_votes'];
        if ($totalVotesAll == 0) $totalVotesAll = 1;

        // Derniers votes : même filtre
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

        echo json_encode(['ranking' => $ranking, 'totalVotesAll' => $totalVotesAll, 'latestVotes' => $latestVotes]);
    } else {
        echo json_encode(['ranking' => [], 'totalVotesAll' => 0, 'latestVotes' => []]);
    }
    exit;
}

function esc($s) { return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= esc($siteName) ?></title>
    <?php if ($siteLogoUrl): ?>
            <link rel="icon" type="image/png" href="<?= $siteLogoUrl ?>">
        <?php endif ?>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,700;1,300;1,700&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="index.css?time=<?php echo time();?>">
<style>
/* Refonte identité LME GROUP : surcharge visuelle sans retirer les modules existants */
:root{--gold:#dcae42;--crimson:#145cc5;--ink:#08264d}.ml-header{box-shadow:0 1px 0 rgba(8,38,77,.09)}.ml-hero:before{content:"";position:absolute;inset:0;background:radial-gradient(circle at 82% 25%,rgba(220,174,66,.27),transparent 26%),linear-gradient(100deg,rgba(8,38,77,.98) 0%,rgba(8,38,77,.76) 52%,rgba(8,38,77,.2) 100%)}.ml-hero__title em{color:var(--gold);font-style:normal}.or__eyebrow,.vl__eyebrow{color:#145cc5}.ml-btn--primary{background:#145cc5}.or__mosaic .or__img-wrap{border-radius:16px}.or__mosaic:before{content:"✦";position:absolute;z-index:3;right:8%;top:31%;font-size:64px;color:#dcae42;text-shadow:0 8px 20px rgba(8,38,77,.28)}.or__mosaic{position:relative}.ft__desc{max-width:330px}.vb__event-badge--ongoing{background:#145cc5}
.or__img-wrap--icon{display:flex;align-items:center;justify-content:center;border-radius:20px}
.or__img-wrap--brand{background:linear-gradient(150deg,#0d3d7a,#145cc5);border-radius:20px;min-height:220px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:14px;position:relative;overflow:hidden}
.or__img-wrap--brand::after{content:'';position:absolute;inset:0;background:radial-gradient(circle at 75% 15%,rgba(220,174,66,.25),transparent 55%)}
.or__brand-mark{width:58px;height:58px;border-radius:16px;background:rgba(255,255,255,.12);border:1px solid rgba(220,174,66,.4);color:#dcae42;display:flex;align-items:center;justify-content:center;position:relative;z-index:1}
.or__brand-text{position:relative;z-index:1;font-family:'Cormorant Garamond',serif;font-size:1.5rem;font-weight:700;color:#fff;text-align:center;line-height:1.15;letter-spacing:.02em}
.or__brand-text em{font-style:italic;color:#dcae42}

/* ═══════ FICHE CONCOURS (style Airbnb : cartes blanches, ombres douces, pills) ═══════ */
.fc{position:relative;background:#FAFAF8;padding:120px 60px;overflow:hidden}
.fc__pattern{position:absolute;inset:0;z-index:0;background-image:radial-gradient(circle at 15% 20%,rgba(20,92,197,.05),transparent 40%),radial-gradient(circle at 85% 80%,rgba(220,174,66,.06),transparent 40%);pointer-events:none}
.fc__wrap{position:relative;z-index:2;max-width:1240px;margin:0 auto}
.fc__head{text-align:center;max-width:720px;margin:0 auto 64px}
.fc__eyebrow{display:inline-flex;align-items:center;gap:9px;font-family:'Outfit',sans-serif;font-size:11px;font-weight:700;letter-spacing:.2em;text-transform:uppercase;color:#145cc5;background:rgba(20,92,197,.07);border:1px solid rgba(20,92,197,.18);padding:8px 18px;border-radius:999px;margin-bottom:22px}
.fc__eyebrow-dot{width:6px;height:6px;border-radius:50%;background:#dcae42}
.fc__title{font-family:'Cormorant Garamond',serif;font-size:clamp(2.2rem,4.5vw,3.6rem);font-weight:300;line-height:1.05;color:#0C0C0C;letter-spacing:-.02em;margin-bottom:18px}
.fc__title em{font-style:italic;font-weight:700;color:#dcae42}
.fc__bar{width:44px;height:2px;background:linear-gradient(90deg,#dcae42,#145cc5);margin:0 auto 22px;border-radius:2px}
.fc__lead{font-family:'Outfit',sans-serif;font-size:1.02rem;font-weight:300;color:#666;line-height:1.8}

.fc__identity{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin-bottom:56px}
.fc__id-card{background:#fff;border-radius:20px;padding:26px 22px;box-shadow:0 8px 28px rgba(8,38,77,.06);border:1px solid rgba(8,38,77,.05);transition:transform .3s ease,box-shadow .3s ease}
.fc__id-card:hover{transform:translateY(-4px);box-shadow:0 16px 40px rgba(8,38,77,.1)}
.fc__id-label{display:block;font-family:'Outfit',sans-serif;font-size:.68rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:#dcae42;margin-bottom:10px}
.fc__id-value{font-family:'Cormorant Garamond',serif;font-size:1.25rem;font-weight:600;color:#08264d;line-height:1.35}

.fc__vm{display:grid;grid-template-columns:1fr 1fr;gap:22px;margin-bottom:22px}
.fc__vm-card{background:#08264d;border-radius:24px;padding:38px;position:relative;overflow:hidden}
.fc__vm-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:linear-gradient(90deg,#dcae42,#145cc5)}
.fc__vm-icon{width:52px;height:52px;border-radius:14px;background:rgba(220,174,66,.12);border:1px solid rgba(220,174,66,.3);color:#dcae42;display:flex;align-items:center;justify-content:center;margin-bottom:20px}
.fc__vm-title{font-family:'Cormorant Garamond',serif;font-size:1.5rem;font-weight:700;color:#fff;margin-bottom:12px}
.fc__vm-text{font-family:'Outfit',sans-serif;font-size:.92rem;font-weight:300;color:rgba(255,255,255,.68);line-height:1.75}

.fc__two-col{display:grid;grid-template-columns:1fr 1fr;gap:22px;margin-bottom:22px}
.fc__panel{background:#fff;border-radius:24px;padding:38px;box-shadow:0 8px 28px rgba(8,38,77,.06);border:1px solid rgba(8,38,77,.05)}
.fc__panel--wide{grid-column:1/-1}
.fc__panel-title{display:flex;align-items:center;gap:12px;font-family:'Cormorant Garamond',serif;font-size:1.4rem;font-weight:700;color:#08264d;margin-bottom:22px}
.fc__panel-num{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:9px;background:rgba(220,174,66,.12);color:#b5882a;font-family:'Outfit',sans-serif;font-size:.78rem;font-weight:700}
.fc__list{list-style:none;display:flex;flex-direction:column;gap:12px}
.fc__list li{position:relative;padding-left:22px;font-family:'Outfit',sans-serif;font-size:.92rem;color:#4a4a4a;line-height:1.6}
.fc__list li::before{content:'';position:absolute;left:0;top:9px;width:8px;height:8px;border-radius:2px;background:#dcae42;transform:rotate(45deg)}
.fc__tags{display:flex;flex-wrap:wrap;gap:10px}
.fc__tag{font-family:'Outfit',sans-serif;font-size:.82rem;font-weight:600;color:#145cc5;background:rgba(20,92,197,.07);border:1px solid rgba(20,92,197,.16);padding:9px 18px;border-radius:999px}
.fc__cond-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 24px;margin-bottom:26px}
.fc__cond-item{display:flex;align-items:center;gap:10px;font-family:'Outfit',sans-serif;font-size:.9rem;color:#4a4a4a}
.fc__cond-item svg{color:#1F7A52;flex-shrink:0}
.fc__docs{font-family:'Outfit',sans-serif;font-size:.85rem;color:#777;line-height:1.8;padding-top:20px;border-top:1px solid #EDE9E0}
.fc__docs-label{font-weight:700;color:#08264d}
.fc__steps{display:flex;flex-wrap:wrap;gap:12px}
.fc__step{display:flex;align-items:center;gap:10px;background:#FAFAF8;border:1px solid #EDE9E0;border-radius:14px;padding:12px 18px;font-family:'Outfit',sans-serif;font-size:.85rem;font-weight:600;color:#4a4a4a}
.fc__step-num{display:flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:#08264d;color:#fff;font-size:.72rem;font-weight:700;flex-shrink:0}
.fc__step--gold{background:rgba(220,174,66,.1);border-color:rgba(220,174,66,.3)}
.fc__step--gold .fc__step-num{background:#dcae42;color:#08264d}
.fc__pay-row{display:flex;flex-wrap:wrap;gap:10px}
.fc__pay{display:inline-flex;align-items:center;gap:7px;font-family:'Outfit',sans-serif;font-size:.8rem;font-weight:600;color:#08264d;background:#FAFAF8;border:1px solid #EDE9E0;padding:9px 16px;border-radius:10px}
.fc__pay--std{color:#666}
.fc__pay--vip{color:#145cc5;border-color:rgba(20,92,197,.25);background:rgba(20,92,197,.05)}
.fc__pay--vvip{color:#b5882a;border-color:rgba(220,174,66,.35);background:rgba(220,174,66,.08)}
.fc__social{display:flex;align-items:center;justify-content:center;gap:18px;margin-top:56px;padding-top:40px;border-top:1px solid #EDE9E0}
.fc__social-label{font-family:'Outfit',sans-serif;font-size:.78rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#999}
.fc__social-links{display:flex;gap:10px}
.fc__social-link{width:42px;height:42px;border-radius:50%;background:#fff;border:1px solid #EDE9E0;color:#08264d;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px rgba(8,38,77,.06);transition:all .25s ease}
.fc__social-link:hover{background:#08264d;color:#dcae42;transform:translateY(-3px)}
.fc__social-link{position:relative}
.fc__social-tip{position:absolute;bottom:calc(100% + 10px);left:50%;transform:translateX(-50%);white-space:nowrap;font-family:'Outfit',sans-serif;font-size:.68rem;font-weight:600;color:#fff;background:#08264d;padding:5px 10px;border-radius:6px;opacity:0;pointer-events:none;transition:opacity .2s ease}
.fc__social-link:hover .fc__social-tip{opacity:1}
.fc__visual{margin-bottom:22px}
.fc__visual-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:22px}
.fc__visual-item{padding:20px;background:#FAFAF8;border-radius:16px;border:1px solid #EDE9E0}
.fc__visual-icon{width:44px;height:44px;border-radius:12px;background:rgba(220,174,66,.12);color:#b5882a;display:flex;align-items:center;justify-content:center;margin-bottom:14px}
.fc__visual-title{font-family:'Cormorant Garamond',serif;font-size:1.1rem;font-weight:700;color:#08264d;margin-bottom:8px}
.fc__opp-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:22px}
.fc__opp-item{padding:22px;background:linear-gradient(160deg,rgba(20,92,197,.04),rgba(220,174,66,.04));border-radius:16px;border:1px solid rgba(8,38,77,.06)}
.fc__opp-icon{width:40px;height:40px;border-radius:10px;background:#08264d;color:#dcae42;display:flex;align-items:center;justify-content:center;margin-bottom:14px}
.pt__confirm-note{text-align:center;margin-top:40px;font-family:'Outfit',sans-serif;font-size:.82rem;color:rgba(255,255,255,.4);letter-spacing:.02em}
.pt__confirm-note em{font-style:italic;color:#C6973F}
@media (max-width:960px){.fc{padding:80px 28px}.fc__identity{grid-template-columns:1fr 1fr}.fc__vm,.fc__two-col{grid-template-columns:1fr}.fc__cond-grid{grid-template-columns:1fr}.fc__visual-grid,.fc__opp-grid{grid-template-columns:1fr}}
@media (max-width:560px){.fc__identity{grid-template-columns:1fr}.fc__panel{padding:28px 22px}}
</style>
</head>
<body>

<!-- ═══ HEADER ═══ -->
<header class="ml-header is-sticky" id="mlHeader">
    <a href="index.php" class="ml-logo" aria-label="<?= esc($siteName) ?> — Accueil">
        <?php if ($siteLogoUrl): ?>
            <img src="<?= $siteLogoUrl ?>" alt="Logo <?= esc($siteName) ?>" width="90" height="50" style="object-fit:contain;">
        <?php else: ?>
            <img src="millenium.webp" width="90" height="50" alt="Logo par défaut">
        <?php endif; ?>
        <span class="ml-logo__text"><?= esc($siteName) ?></span>
    </a>

    <nav class="ml-nav" aria-label="Navigation principale">
        <ul class="ml-nav__list">
            <li><a href="index.php" class="ml-nav__link is-active">Accueil</a></li>
            <li><a href="#apropos" class="ml-nav__link">LME GROUP</a></li>
            <li class="ml-nav__has-sub">
                <span class="ml-nav__link" style="cursor:pointer;">Compétition <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg></span>
                <div class="ml-nav__submenu">
                    <span class="submenu-header">Choisissez un concours</span>
                    <?php foreach ($concoursList as $c): ?>
                        <a href="?concours_id=<?= $c['concours_id'] ?>" class="submenu-item">
                            <?= esc($c['nom_concours']) ?>
                            <?php
                                $now = time();
                                $fin = strtotime($c['date_cloture']);
                                if ($now <= $fin) {
                                    echo '<span class="submenu-item-badge">' . (($now >= strtotime($c['date_ouverture'])) ? 'En cours' : 'À venir') . '</span>';
                                }
                            ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </li>
            <li><a href="#candidates" class="ml-nav__link">Candidates</a></li>
            <li><a href="#partenariat" class="ml-nav__link">Collaborations</a></li>
            <li><a href="#contact" class="ml-nav__link">Contact</a></li>
            <li><a href="candidatures.php" class="ml-nav__link" style="background:var(--gold);color:#000!important;border-radius:6px;font-weight:700;">Participer</a></li>
        </ul>
    </nav>

    <button class="ml-menu-toggle" id="mlToggle" aria-label="Ouvrir le menu" aria-expanded="false" aria-controls="mlDrawer">
        <span></span><span></span><span></span>
    </button>
</header>

<div class="ml-mobile-menu" id="mlDrawer" aria-label="Navigation mobile" aria-hidden="true">
    <ul class="ml-mobile-menu__list">
        <li><a href="index.php" class="ml-mobile-menu__link is-active">Accueil</a></li>
        <li><a href="#apropos" class="ml-mobile-menu__link">LME GROUP</a></li>
        <li class="ml-mobile-menu__item--has-sub">
            <div class="ml-mobile-menu__toggle" data-target="competition-mobile-sub">
                Compétition
                <svg class="ml-mobile-menu__arrow" xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
            </div>
            <ul class="ml-mobile-menu__sub" id="competition-mobile-sub">
                <?php foreach ($concoursList as $c): ?>
                    <li><a href="?concours_id=<?= $c['concours_id'] ?>" class="ml-mobile-menu__sub-link">
                        <?= esc($c['nom_concours']) ?>
                        <?php
                            $now = time();
                            $fin = strtotime($c['date_cloture']);
                            if ($now <= $fin) {
                                echo '<span class="ml-mobile-menu__badge">' . (($now >= strtotime($c['date_ouverture'])) ? 'En cours' : 'À venir') . '</span>';
                            }
                        ?>
                    </a></li>
                <?php endforeach; ?>
            </ul>
        </li>
        <li><a href="#candidates" class="ml-mobile-menu__link">Candidates</a></li>
        <li><a href="#partenariat" class="ml-mobile-menu__link">Collaborations</a></li>
        <li><a href="#contact" class="ml-mobile-menu__link">Contact</a></li>
        <li><a href="candidatures.php" class="ml-mobile-menu__link" style="border:1px solid var(--gold);border-radius:8px;text-align:center;">Participer →</a></li>
    </ul>
    <div class="ml-mobile-menu__foot"><?= esc($siteName) ?> — 2026</div>
</div>

<div class="ml-overlay" id="mlOverlay" aria-hidden="true"></div>

<!-- ═══ HERO ═══ -->
<section class="ml-hero" id="mlHero">
    <?php if ($totalSlides > 0): ?>
        <?php foreach ($heroCandidates as $index => $c): 
            $role = esc($c['niveau_etudes'] ?? '') . ' · ' . esc($c['ville_origine'] ?? 'Kinshasa');
        ?>
            <?php
                $photoUrl = '';
                if (!empty($c['photo_officielle'])) {
                    $path = ltrim($c['photo_officielle'], '/');
                    if (strpos($path, 'admin/') !== 0) {
                        $path = 'admin/' . $path;
                    }
                    $photoUrl = STOCKAGE_DOMAIN . '/' . $path;
                }
                ?>
                <div class="ml-hero__slide <?= $index === 0 ? 'is-active' : '' ?>" 
                     style="background-image:url('<?= esc($photoUrl) ?>');background-repeat:no-repeat;background-size:contain;background-position:right;" 
                     data-subtitle="<?= esc($c['nom_complet']) ?>" 
                     data-desc="<?= $role ?>"></div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="ml-hero__slide is-active" style="background:#000;" data-subtitle="Aucune candidate" data-desc="Revenez bientôt"></div>
    <?php endif; ?>

    <div class="ml-hero__overlay"></div>
    <div class="ml-hero__line"></div>

    <div class="ml-hero__counter">
        <span class="ml-counter-current" id="mlCurrent">01</span>
        <span class="ml-counter-bar" id="mlBar" style="--prog: 25%;"></span>
        <span><?= str_pad($totalSlides, 2, '0', STR_PAD_LEFT) ?></span>
    </div>

    <div class="ml-hero__content" id="mlContent">
        <div class="ml-hero__badge">
            <span class="ml-badge-dot"></span>
            LME GROUP · Kinshasa, République Démocratique du Congo
        </div>
        <h1 class="ml-hero__title">LME <em>GROUP</em></h1>
        <div class="ml-hero__descriptor">
            <span class="ml-descriptor-line"></span>
            <span class="ml-hero__subtitle" id="mlSubtitle">Beauté</span>
        </div>
        <p class="ml-hero__desc" id="mlDesc">Nous créons des événements, des formations et des plateformes qui révèlent les talents et font avancer la RDC.</p>
        <div class="ml-hero__btns">
            <a href="candidatures.php" class="ml-btn ml-btn--primary">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 12 2 2 4-4"></path><path d="M5 7c0-1.1.9-2 2-2h10a2 2 0 0 1 2 2v12H5V7Z"></path><path d="M22 19H2"></path></svg>
                Participer à nos projets
            </a>
            <a href="#candidates" class="ml-btn ml-btn--outline">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><path d="M16 3.128a4 4 0 0 1 0 7.744"></path><circle cx="9" cy="7" r="4"></circle></svg>
                Découvrir les talents
            </a>
        </div>
    </div>

    <div class="ml-hero__ghost" id="mlGhost">Beauté</div>

    <div class="ml-hero__nav">
        <button class="ml-nav-btn" id="mlPrev" aria-label="Précédent"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="18 15 12 9 6 15"></polyline></svg></button>
        <button class="ml-nav-btn" id="mlNext" aria-label="Suivant"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><polyline points="6 9 12 15 18 9"></polyline></svg></button>
    </div>

    <div class="ml-hero__progress"><div class="ml-progress-fill" id="mlProgress"></div></div>
</section>

<!-- ═══ LIVE BANNER ═══ -->
<div class="vb" role="banner" aria-label="Bannière des événements">
    <div class="vb__inner">
        <div class="vb__events" id="vbEvents">
            <?php foreach ($etapes as $ev): 
                $debut = strtotime($ev['date_ouverture'] ?? 'now');
                $fin = strtotime($ev['date_cloture'] ?? 'now');
                $nowTs = time();
                if ($nowTs >= $debut && $nowTs <= $fin) {
                    $statusClass = 'ongoing';
                    $label = 'En cours';
                    $progress = ($fin - $debut) > 0 ? min(100, round((($nowTs - $debut) / ($fin - $debut)) * 100)) : 0;
                } else if ($nowTs < $debut) {
                    $statusClass = 'upcoming';
                    $label = 'À venir';
                    $progress = 0;
                } else {
                    $statusClass = 'upcoming';
                    $label = 'Terminé';
                    $progress = 100;
                }
            ?>
            <div class="vb__event-item vb__event-item--<?= $statusClass ?>">
                <div class="vb__event-header">
                    <span class="vb__event-name" title="<?= esc($ev['nom_etape'] ?? $ev['etape_id']) ?>"><?= esc($ev['nom_etape'] ?? 'Étape ' . $ev['numero_ordre']) ?></span>
                    <span class="vb__event-badge vb__event-badge--<?= $statusClass ?>"><?= $label ?></span>
                </div>
                <div class="vb__event-dates">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="1em" height="1em" style="vertical-align: middle; margin-right: 4px;">
                        <rect x="3" y="4" width="18" height="18" rx="2"></rect>
                        <line x1="16" y1="2" x2="16" y2="6"></line>
                        <line x1="8" y1="2" x2="8" y2="6"></line>
                        <line x1="3" y1="10" x2="21" y2="10"></line>
                    </svg>
                    <?= date('d/m/Y', $debut) ?> → <?= date('d/m/Y', $fin) ?>
                </div>
                <?php if ($statusClass === 'ongoing'): ?>
                <div class="vb__event-progress">
                    <div class="vb__event-progress-track"><div class="vb__event-progress-fill" style="width:<?= $progress ?>%"></div></div>
                    <div class="vb__event-progress-label"><?= $progress ?>% écoulé</div>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        
    </div>
</div>

<!-- ═══ TICKER ═══ -->
<div class="ticker-section">
    <div class="ticker-container">
        <div class="ticker-row">
            <span class="ticker-label">🏆 Classement</span>
            <div class="ticker-track" id="ticker-ranking"><span class="ticker-item">Chargement...</span></div>
        </div>
        <div class="ticker-row">
            <span class="ticker-label">⚡ Derniers votes</span>
            <div class="ticker-track" id="ticker-votes"><span class="ticker-item">Chargement...</span></div>
        </div>
    </div>
</div>

<!-- ═══ ABOUT LME GROUP ═══ -->
<section class="or" id="apropos" aria-labelledby="or-title">
 <div class="or__grid"><div class="or__left">
  <span class="or__eyebrow">LME GROUP · Kinshasa</span>
  <h2 class="or__title" id="or-title">Inspirer. Former. <span class="or__title-gold">Transformer.</span></h2><div class="or__bar"></div>
  <p class="or__text"><strong>LME GROUP</strong> est une structure congolaise spécialisée dans l’organisation d’événements, la communication, la promotion culturelle, le développement du leadership ainsi que l’accompagnement des initiatives sociales et entrepreneuriales en République Démocratique du Congo.</p>
  <p class="or__text">Nous créons des plateformes d’expression, de formation et de valorisation des talents, particulièrement pour la jeunesse et les femmes. Notre ambition : faire émerger des initiatives responsables, compétentes et engagées pour le développement durable du pays.</p>
  <div class="or__stats"><div class="or__stat"><span class="or__stat-num">6</span><span class="or__stat-label">Domaines d’expertise</span></div><div class="or__stat"><span class="or__stat-num">RDC</span><span class="or__stat-label">Ancrage national</span></div><div class="or__stat"><span class="or__stat-num">100%</span><span class="or__stat-label">Engagés pour l’impact</span></div></div>
  <div class="or__motto">
    <div class="or__motto-item"><div class="or__motto-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg></div><div><span class="or__motto-label">Vision</span><p class="or__motto-text" style="font-style:normal;font-size:.92rem;font-weight:400;">Contribuer à l'émergence d'une jeunesse congolaise responsable, compétente et engagée dans le développement durable du pays.</p></div></div>
    <div class="or__motto-item"><div class="or__motto-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 11 18-5v12L3 14v-3Z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg></div><div><span class="or__motto-label">Mission</span><p class="or__motto-text" style="font-style:normal;font-size:.92rem;font-weight:400;">Créer des opportunités de visibilité, de formation et de développement personnel à travers des projets innovants à fort impact social, culturel et économique.</p></div></div>
  </div>
  <div class="or__motto" style="margin-top:14px;">
    <div class="or__motto-item"><div class="or__motto-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 3a2.85 2.83 0 1 1 4 4L7.5 20.5 2 22l1.5-5.5Z"/></svg></div><div><span class="or__motto-label">Devise</span><p class="or__motto-text">« Inspirer, Former, Transformer »</p></div></div>
    <div class="or__motto-item"><div class="or__motto-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></div><div><span class="or__motto-label">Slogan</span><p class="or__motto-text">« Ensemble pour un avenir d’excellence »</p></div></div>
  </div>
  <div class="or__domains">
    <span class="or__domains-label">Domaines d’activités</span>
    <div class="or__domains-tags">
        <span class="or__domain-tag">Événementiel</span>
        <span class="or__domain-tag">Communication</span>
        <span class="or__domain-tag">Culture</span>
        <span class="or__domain-tag">Leadership</span>
        <span class="or__domain-tag">Formation</span>
        <span class="or__domain-tag">Développement communautaire</span>
    </div>
  </div>
  <a href="#partenariat" class="or__link">Construire un projet avec nous <svg class="or__link-arrow" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg></a>
 </div><div class="or__mosaic" aria-hidden="true"><div class="or__col"><div class="or__img-wrap or__img-wrap--brand"><span class="or__brand-mark"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z"/><path d="M5 21h14"/></svg></span><span class="or__brand-text">LME<br><em>GROUP</em></span></div><div class="or__img-wrap or__img-wrap--icon" style="background:#FBF1DC;color:#B5882A;min-height:140px;"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M12 2v20"/><path d="M2 5h20"/><path d="M5 5v13a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V5"/><path d="M9 9h6"/></svg></div></div><div class="or__col or__col--offset"><div class="or__img-wrap or__img-wrap--icon" style="background:#EAF1FB;color:#145CC5;min-height:190px;"><svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><path d="M16 3.128a4 4 0 0 1 0 7.744"/><circle cx="9" cy="7" r="4"/></svg></div><div class="or__img-wrap or__img-wrap--icon" style="background:#08264d;color:#dcae42;min-height:180px;"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg></div></div></div></div>

  <!-- Objectifs LME GROUP -->
  <div class="or__objectives">
    <span class="or__objectives-label">Nos objectifs</span>
    <div class="or__objectives-grid">
        <div class="or__obj-item"><span class="or__obj-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6 9 17l-5-5"/></svg></span> Promouvoir les talents congolais</div>
        <div class="or__obj-item"><span class="or__obj-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6 9 17l-5-5"/></svg></span> Encourager le leadership des jeunes</div>
        <div class="or__obj-item"><span class="or__obj-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6 9 17l-5-5"/></svg></span> Valoriser la culture congolaise</div>
        <div class="or__obj-item"><span class="or__obj-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6 9 17l-5-5"/></svg></span> Renforcer l'autonomisation des femmes</div>
        <div class="or__obj-item"><span class="or__obj-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6 9 17l-5-5"/></svg></span> Développer des projets à impact social</div>
        <div class="or__obj-item"><span class="or__obj-icon"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M20 6 9 17l-5-5"/></svg></span> Offrir des plateformes de représentation nationale et internationale</div>
    </div>
  </div>
</section>

<!-- ═══ VALUES ═══ -->
<section class="vl" aria-labelledby="vl-title">
    <div class="vl__grid-bg" aria-hidden="true"></div>
    <div class="vl__inner">
        <div class="vl__eyebrow"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11.017 2.814a1 1 0 0 1 1.966 0l1.051 5.558a2 2 0 0 0 1.594 1.594l5.558 1.051a1 1 0 0 1 0 1.966l-5.558 1.051a2 2 0 0 0-1.594 1.594l-1.051 5.558a1 1 0 0 1-1.966 0l-1.051-5.558a2 2 0 0 0-1.594-1.594l-5.558-1.051a1 1 0 0 1 0-1.966l5.558-1.051a2 2 0 0 0 1.594-1.594z"></path></svg> Ce qui nous définit</div>
        <h2 class="vl__title" id="vl-title">Les valeurs qui <em>nous rassemblent</em></h2>
        <p class="vl__subtitle">Des principes qui guident chacune de nos actions, de nos collaborations et de nos projets</p>
        <div class="vl__cards">
            <div class="vl__card vl__card--gold">
                <span class="vl__num" aria-hidden="true">01</span>
                <div class="vl__icon vl__icon--gold" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z"></path><path d="M5 21h14"></path></svg></div>
                <h3 class="vl__card-title">Excellence</h3>
                <p class="vl__card-text">Nous plaçons la qualité, la rigueur et le dépassement de soi au cœur de chaque projet.</p>
                <span class="vl__card-line"></span>
            </div>
            <div class="vl__card vl__card--crimson">
                <span class="vl__num" aria-hidden="true">02</span>
                <div class="vl__icon vl__icon--crimson" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5"></path></svg></div>
                <h3 class="vl__card-title">Solidarité</h3>
                <p class="vl__card-text">Créer et soutenir des projets utiles aux communautés, avec une attention particulière à l’autonomisation et à l’inclusion.</p>
                <span class="vl__card-line"></span>
            </div>
            <div class="vl__card vl__card--gold">
                <span class="vl__num" aria-hidden="true">03</span>
                <div class="vl__icon vl__icon--violet" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 12 2 2 4-4"/><path d="M5 7c0-1.1.9-2 2-2h10a2 2 0 0 1 2 2v12H5V7Z"/><path d="M22 19H2"/></svg></div>
                <h3 class="vl__card-title">Intégrité</h3>
                <p class="vl__card-text">Agir avec honnêteté, transparence et cohérence dans chacun de nos engagements.</p>
                <span class="vl__card-line"></span>
            </div>
            <div class="vl__card vl__card--blue">
                <span class="vl__num" aria-hidden="true">04</span>
                <div class="vl__icon vl__icon--teal" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg></div>
                <h3 class="vl__card-title">Respect</h3>
                <p class="vl__card-text">Considérer chaque personne, chaque culture et chaque idée avec dignité et bienveillance.</p>
                <span class="vl__card-line"></span>
            </div>
            <div class="vl__card vl__card--blue">
                <span class="vl__num" aria-hidden="true">05</span>
                <div class="vl__icon vl__icon--blue" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"></path></svg></div>
                <h3 class="vl__card-title">Leadership</h3>
                <p class="vl__card-text">Faire grandir des leaders responsables, capables de porter une vision et de transformer leur environnement.</p>
                <span class="vl__card-line"></span>
            </div>
            <div class="vl__card vl__card--green">
                <span class="vl__num" aria-hidden="true">06</span>
                <div class="vl__icon vl__icon--green" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"></path><path d="M2 12h20"></path></svg></div>
                <h3 class="vl__card-title">Innovation &amp; culture</h3>
                <p class="vl__card-text">Valoriser l’identité congolaise tout en imaginant de nouvelles solutions pour répondre aux enjeux de demain.</p>
                <span class="vl__card-line"></span>
            </div>
        </div>
    </div>
</section>

<!-- ═══ FICHE CONCOURS — MISS AURORA RDC ═══ -->
<section class="fc" id="concours" aria-labelledby="fc-title">
    <div class="fc__pattern" aria-hidden="true"></div>
    <div class="fc__wrap">
        <div class="fc__head">
            <span class="fc__eyebrow"><span class="fc__eyebrow-dot"></span> Miss Aurora RDC · Édition officielle</span>
            <h2 class="fc__title" id="fc-title">La beauté au service <em>du changement</em></h2>
            <div class="fc__bar"></div>
            <p class="fc__lead">Miss Aurora RDC est un concours national de beauté, de leadership et d'engagement social organisé par LME GROUP. Il vise à révéler, former et accompagner les jeunes femmes congolaises capables d'incarner les valeurs d'excellence, de responsabilité et d'impact communautaire.</p>
            <p class="fc__lead" style="margin-top:14px;">Au-delà d'un concours de beauté, Miss Aurora RDC constitue une plateforme de développement personnel, de leadership féminin et de représentation internationale de la République Démocratique du Congo.</p>
            <p class="fc__lead" style="margin-top:14px;font-style:italic;">Le mot « Aurora » signifie l'aube. Il symbolise la lumière, l'espoir, le renouveau et l'émergence d'une nouvelle génération de femmes leaders capables d'apporter une contribution positive à la société.</p>
        </div>

        <!-- Identité du concours -->
        <div class="fc__identity">
            <div class="fc__id-card">
                <span class="fc__id-label">Devise</span>
                <p class="fc__id-value">« La beauté au service du changement »</p>
            </div>
            <div class="fc__id-card">
                <span class="fc__id-label">Slogan</span>
                <p class="fc__id-value">« Révéler la lumière qui inspire l'avenir »</p>
            </div>
            <div class="fc__id-card">
                <span class="fc__id-label">Ville de la finale</span>
                <p class="fc__id-value">Kinshasa, RDC</p>
            </div>
            <div class="fc__id-card">
                <span class="fc__id-label">Couleurs officielles</span>
                <p class="fc__id-value">Or · Blanc · Bleu Royal</p>
            </div>
        </div>

        <!-- Identité visuelle -->
        <div class="fc__panel fc__panel--wide fc__visual">
            <h3 class="fc__panel-title"><span class="fc__panel-num">✦</span> Identité visuelle</h3>
            <div class="fc__visual-grid">
                <div class="fc__visual-item">
                    <div class="fc__visual-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z"/><path d="M5 21h14"/></svg></div>
                    <h4 class="fc__visual-title">Le logo</h4>
                    <p class="fc__vm-text" style="color:#666;">Une couronne stylisée associée à une lumière d'aurore, symbolisant l'espoir, l'élégance et l'excellence.</p>
                </div>
                <div class="fc__visual-item">
                    <div class="fc__visual-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 21h14"/><path d="M12 3v6"/><path d="m8 13 4-4 4 4"/><path d="M5 21c1-4 3-7 7-7s6 3 7 7"/></svg></div>
                    <h4 class="fc__visual-title">La couronne</h4>
                    <p class="fc__vm-text" style="color:#666;">Symbole du leadership, de la responsabilité et de l'excellence féminine portées par chaque candidate.</p>
                </div>
                <div class="fc__visual-item">
                    <div class="fc__visual-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="4"/><path d="M3 9h18"/></svg></div>
                    <h4 class="fc__visual-title">Charte graphique</h4>
                    <p class="fc__vm-text" style="color:#666;">Conforme à l'identité visuelle de Miss Aurora RDC et de LME GROUP, sur tous les supports officiels.</p>
                </div>
            </div>
        </div>

        <!-- Vision / Mission -->
        <div class="fc__vm">
            <div class="fc__vm-card">
                <div class="fc__vm-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg></div>
                <h3 class="fc__vm-title">Notre vision</h3>
                <p class="fc__vm-text">Faire de Miss Aurora RDC une référence nationale et internationale dans la promotion de la beauté intelligente, du leadership féminin et de l'engagement citoyen.</p>
            </div>
            <div class="fc__vm-card">
                <div class="fc__vm-icon"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m3 11 18-5v12L3 14v-3Z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg></div>
                <h3 class="fc__vm-title">Notre mission</h3>
                <p class="fc__vm-text">Identifier, former et accompagner les jeunes femmes afin qu'elles deviennent des ambassadrices du développement social, culturel et économique de la RDC, au niveau national et international.</p>
            </div>
        </div>

        <!-- Valeurs du concours -->
        <div class="fc__panel fc__panel--wide">
            <h3 class="fc__panel-title"><span class="fc__panel-num">01</span> Valeurs portées par le concours</h3>
            <div class="fc__tags">
                <span class="fc__tag">Leadership</span>
                <span class="fc__tag">Excellence</span>
                <span class="fc__tag">Discipline</span>
                <span class="fc__tag">Respect</span>
                <span class="fc__tag">Engagement social</span>
                <span class="fc__tag">Patriotisme</span>
            </div>
        </div>

        <!-- Participation -->
        <div class="fc__panel fc__panel--wide">
            <h3 class="fc__panel-title"><span class="fc__panel-num">02</span> Conditions de participation</h3>
            <div class="fc__cond-grid">
                <div class="fc__cond-item"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 6 9 17l-5-5"/></svg> Être de nationalité congolaise ou résidente en RDC</div>
                <div class="fc__cond-item"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 6 9 17l-5-5"/></svg> Être âgée de 18 à 28 ans</div>
                <div class="fc__cond-item"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 6 9 17l-5-5"/></svg> Être célibataire</div>
                <div class="fc__cond-item"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 6 9 17l-5-5"/></svg> Avoir une bonne moralité</div>
                <div class="fc__cond-item"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 6 9 17l-5-5"/></svg> Porter la volonté d'un projet social</div>
                <div class="fc__cond-item"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 6 9 17l-5-5"/></svg> Accepter le règlement officiel</div>
            </div>
            <div class="fc__docs">
                <span class="fc__docs-label">Documents demandés :</span>
                Copie de la pièce d'identité · Photos récentes · Fiche d'inscription · Curriculum Vitae · Lettre de motivation
            </div>
        </div>

        <!-- Déroulement -->
        <div class="fc__panel fc__panel--wide">
            <h3 class="fc__panel-title"><span class="fc__panel-num">03</span> Déroulement du concours</h3>
            <div class="fc__steps">
                <div class="fc__step"><span class="fc__step-num">1</span><span>Lancement officiel</span></div>
                <div class="fc__step"><span class="fc__step-num">2</span><span>Inscriptions</span></div>
                <div class="fc__step"><span class="fc__step-num">3</span><span>Présélections</span></div>
                <div class="fc__step"><span class="fc__step-num">4</span><span>Castings</span></div>
                <div class="fc__step"><span class="fc__step-num">5</span><span>Formation et coaching</span></div>
                <div class="fc__step"><span class="fc__step-num">6</span><span>Soirée de présentation</span></div>
                <div class="fc__step"><span class="fc__step-num">7</span><span>Finale nationale</span></div>
                <div class="fc__step fc__step--gold"><span class="fc__step-num">8</span><span>Couronnement</span></div>
            </div>
            <div class="fc__docs" style="margin-top:24px;"><span class="fc__docs-label">Calendrier :</span> selon le programme annuel de l'organisation — finale à Kinshasa.</div>
        </div>

        <!-- Opportunités offertes aux lauréates -->
        <div class="fc__panel fc__panel--wide fc__opp">
            <h3 class="fc__panel-title"><span class="fc__panel-num">04</span> Opportunités offertes aux lauréates</h3>
            <div class="fc__opp-grid">
                <div class="fc__opp-item">
                    <div class="fc__opp-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z"/><path d="M5 21h14"/></svg></div>
                    <p class="fc__vm-text" style="color:#4a4a4a;">La gagnante de Miss Aurora RDC devient l'<strong>ambassadrice officielle</strong> du concours et représente la RDC dans les compétitions internationales partenaires.</p>
                </div>
                <div class="fc__opp-item">
                    <div class="fc__opp-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><path d="M16 3.128a4 4 0 0 1 0 7.744"/><circle cx="9" cy="7" r="4"/></svg></div>
                    <p class="fc__vm-text" style="color:#4a4a4a;">Les <strong>dauphines</strong> peuvent aussi être désignées pour représenter la RDC dans des concours internationaux de beauté, leadership, culture et actions sociales.</p>
                </div>
                <div class="fc__opp-item">
                    <div class="fc__opp-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg></div>
                    <p class="fc__vm-text" style="color:#4a4a4a;">Ces représentations contribuent au <strong>rayonnement de la femme congolaise</strong> et au renforcement de l'image de la RDC sur la scène internationale.</p>
                </div>
            </div>
        </div>

        <!-- Vote & billetterie -->
        <div class="fc__two-col">
            <div class="fc__panel">
                <h3 class="fc__panel-title"><span class="fc__panel-num">05</span> Vote en ligne</h3>
                <p class="fc__vm-text" style="color:#666;margin-bottom:20px;">Le vote en ligne est ouvert au public durant toute la compétition, via un système de paiement sécurisé. <span style="color:#08264d;font-weight:600;">Prix du vote : à définir.</span></p>
                <div class="fc__pay-row">
                    <span class="fc__pay"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><rect x="2" y="5" width="20" height="14" rx="3"/><path d="M2 10h20"/></svg> Orange Money</span>
                    <span class="fc__pay"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><rect x="2" y="5" width="20" height="14" rx="3"/><path d="M2 10h20"/></svg> Airtel Money</span>
                    <span class="fc__pay"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><rect x="2" y="5" width="20" height="14" rx="3"/><path d="M2 10h20"/></svg> M-Pesa</span>
                    <span class="fc__pay"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><rect x="2" y="5" width="20" height="14" rx="3"/><path d="M2 10h20"/></svg> AfriMoney</span>
                    <span class="fc__pay"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><rect x="1" y="4" width="22" height="16" rx="3"/><path d="M1 9h22"/></svg> Carte bancaire</span>
                    <span class="fc__pay"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20"/></svg> Paiement en ligne</span>
                </div>
            </div>
            <div class="fc__panel">
                <h3 class="fc__panel-title"><span class="fc__panel-num">06</span> Billetterie</h3>
                <p class="fc__vm-text" style="color:#666;margin-bottom:20px;">Assistez à la soirée de finale grâce à nos billets, disponibles en trois formules.</p>
                <div class="fc__pay-row">
                    <span class="fc__pay fc__pay--std"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M3 10a2 2 0 0 0 0 4v3a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1v-3a2 2 0 0 1 0-4V7a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1Z"/></svg> Standard</span>
                    <span class="fc__pay fc__pay--vip"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M3 10a2 2 0 0 0 0 4v3a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1v-3a2 2 0 0 1 0-4V7a1 1 0 0 0-1-1H4a1 1 0 0 0-1 1Z"/></svg> VIP</span>
                    <span class="fc__pay fc__pay--vvip"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z"/></svg> VVIP</span>
                </div>
            </div>
        </div>

        <!-- Réseaux sociaux -->
        <div class="fc__social">
            <span class="fc__social-label">Suivez Miss Aurora RDC</span>
            <div class="fc__social-links">
                <a href="https://facebook.com/MissAuroraRDC" target="_blank" rel="noopener" class="fc__social-link" aria-label="Facebook — Miss Aurora RDC"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M22 12a10 10 0 1 0-11.6 9.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.4h-1.3c-1.2 0-1.6.8-1.6 1.6V12h2.8l-.4 2.9h-2.4v7A10 10 0 0 0 22 12Z"/></svg><span class="fc__social-tip">Miss Aurora RDC</span></a>
                <a href="https://instagram.com/MissAuroraRDC" target="_blank" rel="noopener" class="fc__social-link" aria-label="Instagram — Miss Aurora RDC"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1"/></svg><span class="fc__social-tip">Miss Aurora RDC</span></a>
                <a href="https://tiktok.com/@MissAuroraRDC" target="_blank" rel="noopener" class="fc__social-link" aria-label="TikTok — Miss Aurora RDC"><svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor"><path d="M16.6 5.8a4.5 4.5 0 0 1-3.3-1.4v10.2a4.9 4.9 0 1 1-4.2-4.9v2.2a2.7 2.7 0 1 0 1.9 2.6V2h2.2a4.5 4.5 0 0 0 3.4 3.7Z"/></svg><span class="fc__social-tip">Miss Aurora RDC</span></a>
                <a href="https://youtube.com/@MissAuroraRDC" target="_blank" rel="noopener" class="fc__social-link" aria-label="YouTube — Miss Aurora RDC"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22.5 12s0-3.2-.4-4.7a3 3 0 0 0-2.1-2.1C18.5 4.8 12 4.8 12 4.8s-6.5 0-8 .4A3 3 0 0 0 1.9 7.3C1.5 8.8 1.5 12 1.5 12s0 3.2.4 4.7a3 3 0 0 0 2.1 2.1c1.5.4 8 .4 8 .4s6.5 0 8-.4a3 3 0 0 0 2.1-2.1c.4-1.5.4-4.7.4-4.7Z"/><path d="m9.8 15 5.2-3-5.2-3Z"/></svg><span class="fc__social-tip">Miss Aurora RDC</span></a>
            </div>
        </div>
    </div>
</section>

<!-- ═══ CANDIDATES (avec onglets par étape) ═══ -->
<section class="cd" id="candidates" aria-labelledby="cd-title">
    <div class="cd__pattern" aria-hidden="true"></div>
    <div class="cd__wrap">
        <div class="cd__head">
            <div class="cd__eyebrow"><span class="cd__eyebrow-line"></span> Édition officielle 2026 <span class="cd__eyebrow-line"></span></div>
            <h2 class="cd__title" id="cd-title"><span class="cd__title-icon"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg></span> Candidates du concours</h2>
            <div class="cd__bar"></div>
            <p class="cd__subtitle"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"></rect><line x1="16" y1="2" x2="16" y2="6"></line><line x1="8" y1="2" x2="8" y2="6"></line><line x1="3" y1="10" x2="21" y2="10"></line></svg> Découvrez les candidates par étape du concours</p>
        </div>

        <!-- Onglets des étapes -->
        <?php if (count($etapes) > 0): ?>
        <div class="cd__tabs" role="tablist" aria-label="Étapes du concours">
            <?php foreach ($etapes as $idx => $etape): 
                $debut = strtotime($etape['date_ouverture']);
                $fin   = strtotime($etape['date_cloture']);
                $nowTs = time();
                if ($nowTs >= $debut && $nowTs <= $fin) {
                    $statusLabel = 'En cours';
                    $badgeClass = 'cd__tab-badge--ongoing';
                } elseif ($nowTs < $debut) {
                    $statusLabel = 'À venir';
                    $badgeClass = 'cd__tab-badge--upcoming';
                } else {
                    $statusLabel = 'Terminé';
                    $badgeClass = '';
                }
            ?>
            <button class="cd__tab-btn <?= $idx === 0 ? 'is-active' : '' ?>" 
                    role="tab" 
                    aria-selected="<?= $idx === 0 ? 'true' : 'false' ?>" 
                    aria-controls="panel-<?= $etape['etape_id'] ?>" 
                    id="tab-<?= $etape['etape_id'] ?>"
                    data-panel="panel-<?= $etape['etape_id'] ?>">
                <?= esc($etape['nom_etape'] ?? 'Étape ' . $etape['numero_ordre']) ?>
                <?php if ($statusLabel): ?>
                    <span class="cd__tab-badge <?= $badgeClass ?>"><?= $statusLabel ?></span>
                <?php endif; ?>
            </button>
            <?php endforeach; ?>
        </div>

        <div class="cd__panels">
            <?php foreach ($etapes as $idx => $etape): 
                $panelId = 'panel-' . $etape['etape_id'];
                $isActive = $idx === 0;
                // Récupérer les candidates pour cette étape
                $candidates = $candidatesByEtape[$etape['etape_id']] ?? [];
            ?>
            <div class="cd__panel <?= $isActive ? 'is-active' : '' ?>" 
                 id="<?= $panelId ?>" 
                 role="tabpanel" 
                 aria-labelledby="tab-<?= $etape['etape_id'] ?>">
                <div class="cd__event-head">
                    <h3 class="cd__title" style="font-size: clamp(1.6rem, 3vw, 2.8rem);">
                        <?= esc($etape['nom_etape'] ?? 'Étape ' . $etape['numero_ordre']) ?>
                    </h3>
                    <?php if (!empty($etape['description_etape'])): ?>
                    <p class="cd__subtitle"><?= esc($etape['description_etape']) ?></p>
                    <?php endif; ?>
                    <div class="cd__event-badge">
                        <span style="display:inline-block;font-size:0.65rem;font-weight:600;letter-spacing:0.1em;text-transform:uppercase;color:#999;background:#f0f0f0;padding:3px 14px;border-radius:30px;">
                            <?= date('d/m/Y', strtotime($etape['date_ouverture'])) ?> → <?= date('d/m/Y', strtotime($etape['date_cloture'])) ?>
                        </span>
                    </div>
                </div>

                <!-- Grille des candidates -->
                <?php if (count($candidates) > 0): ?>
                <div class="cd__grid">
                    <?php foreach ($candidates as $cand): 
                        $votes = $cand['total_votes'];
                        $pct = $totalVotesAll > 0 ? round(($votes / $totalVotesAll) * 100, 1) : 0;
                    ?>
                    <div class="cd__card">
                        <div class="cd__photo">
                            <?php
                                $photoUrl = '';
                                if (!empty($cand['photo_officielle'])) {
                                    $path = ltrim($cand['photo_officielle'], '/');
                                    if (strpos($path, 'admin/') !== 0) {
                                        $path = 'admin/' . $path;
                                    }
                                    $photoUrl = STOCKAGE_DOMAIN . '/' . $path;
                                }
                                ?>
                                <img src="<?= esc($photoUrl) ?>?v=<?= time() ?>" alt="<?= esc($cand['nom_complet']) ?>" loading="lazy">
                            <div class="cd__photo-veil"></div>
                            <span class="cd__num">N° <?= esc($cand['code_participante']) ?></span>
                            <p class="cd__photo-city"><svg width="9" height="9" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 10c0 6-8 12-8 12s-8-6-8-10a8 8 0 0 1 16 0Z"></path><circle cx="12" cy="10" r="3"></circle></svg> <?= esc($cand['ville_origine'] ?? 'Kinshasa') ?></p>
                            <span class="cd__tag">Candidate 2026</span>
                        </div>
                        <div class="cd__body">
                            <h3 class="cd__name"><?= esc($cand['nom_complet']) ?></h3>
                            
                            <div class="cd__share-row" style="display:flex;align-items:center;gap:6px;margin-bottom:10px;">
                                
                                
                                <!-- Bouton Partager (ouvre le lien de vote) -->
                                <a onclick="copyVoteLink(this)"  class="cd__share-btn" style="cursor:pointer;display:inline-flex;align-items:center;gap:4px;background:#C6973F;color:#fff;border:none;border-radius:20px;padding:4px 12px;font-size:0.6rem;font-weight:600;text-decoration:none;transition:all 0.2s;">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"></path><polyline points="16 6 12 2 8 6"></polyline><line x1="12" y1="2" x2="12" y2="15"></line></svg>
                                    Copier le lien de vote
                                </a>
                            </div>
                            <script>
                                function copyVoteLink(btn) {
                                    // Trouver la carte parente
                                    const card = btn.closest('.cd__card');
                                    // Trouver le lien "Voter" dans cette même carte
                                    const voteLink = card.querySelector('a[href*="voter.php"]');
                                    
                                    if (voteLink) {
                                        const url = voteLink.href; // Récupère l'URL absolue
                                        
                                        // Utiliser l'API Clipboard moderne
                                        if (navigator.clipboard) {
                                            navigator.clipboard.writeText(url).then(() => {
                                                const originalHTML = btn.innerHTML;
                                                const originalBg = btn.style.background;
                                                btn.innerHTML = '✅ Copié !';
                                                btn.style.background = '#22c55e';
                                                btn.style.color = '#fff';
                                                setTimeout(() => {
                                                    btn.innerHTML = originalHTML;
                                                    btn.style.background = originalBg;
                                                    btn.style.color = '#fff';
                                                }, 2000);
                                            });
                                        } else {
                                            // Fallback pour les vieux navigateurs
                                            const textArea = document.createElement('textarea');
                                            textArea.value = url;
                                            document.body.appendChild(textArea);
                                            textArea.select();
                                            document.execCommand('copy');
                                            document.body.removeChild(textArea);
                                            
                                            const originalHTML = btn.innerHTML;
                                            const originalBg = btn.style.background;
                                            btn.innerHTML = '✅ Copié !';
                                            btn.style.background = '#22c55e';
                                            btn.style.color = '#fff';
                                            setTimeout(() => {
                                                btn.innerHTML = originalHTML;
                                                btn.style.background = originalBg;
                                                btn.style.color = '#C6973F';
                                            }, 2000);
                                        }
                                    }
                                }
                            </script>
                            <div class="cd__divider"></div>
                            <div class="cd__details">
                                <div class="cd__detail-row"><span class="cd__detail-icon"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="20" height="14" x="2" y="7" rx="2" ry="2"></rect><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path></svg></span><span class="cd__detail-label">Code: <?= esc($cand['code_participante']) ?></span></div>
                                <div class="cd__detail-row"><span class="cd__detail-icon"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11.525 2.295a.53.53 0 0 1 .95 0l2.31 4.679a2.123 2.123 0 0 0 1.595 1.16l5.166.756a.53.53 0 0 1 .294.904l-3.736 3.638a2.123 2.123 0 0 0-.611 1.878l.882 5.14a.53.53 0 0 1-.771.56l-4.618-2.428a2.122 2.122 0 0 0-1.973 0L6.396 21.01a.53.53 0 0 1-.77-.56l.881-5.139a2.122 2.122 0 0 0-.611-1.879L2.16 9.795a.53.53 0 0 1 .294-.906l5.165-.755a2.122 2.122 0 0 0 1.597-1.16z"></path></svg></span><span class="cd__detail-label">Niveau: <?= esc($cand['niveau_etudes'] ?? 'Non précisé') ?></span></div>
                            </div>
                            <div class="cd__stats">
                                <div class="cd__metrics">
                                    <div class="cd__metric-item"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg> <span><?= $votes ?> <strong>votes</strong></span></div>
                                </div>
                                <div class="cd__score-wrap">
                                    <div class="cd__score-head"><span>Popularité</span> <strong><?= $pct ?>%</strong></div>
                                    <div class="cd__score-track"><div class="cd__score-fill" style="--score:<?= $pct ?>%"></div></div>
                                </div>
                            </div>
                            <div style="display:flex;gap:8px;margin-top:8px;">
                                <a href="profil.php?code=<?= urlencode($cand['code_participante']) ?>" style="flex:1;text-align:center;padding:6px 0;border-radius:6px;font-size:0.7rem;font-weight:600;text-transform:uppercase;background:rgba(0,0,0,.04);border:1px solid #ddd;color:#333;text-decoration:none;">Profil</a>
                                <!-- MODIFICATION : ajout de concours_id et etape_id dans le lien Voter -->
                                <a href="voter.php?candidat=<?= urlencode($cand['participante_id']) ?>&concours_id=<?= $concoursId ?>&etape_id=<?= $etape['etape_id'] ?>" style="flex:1;text-align:center;padding:6px 0;border-radius:6px;font-size:0.7rem;font-weight:700;text-transform:uppercase;background:#C6973F;color:#fff;border:none;text-decoration:none;">Voter</a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                    <p style="text-align:center;color:#999;">Aucune candidate inscrite pour cette étape.</p>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p style="text-align:center;color:#999;">Aucune étape disponible pour ce concours.</p>
        <?php endif; ?>

        <div class="cd__footer">
            <div class="cd__footer-inner">
                <div class="cd__footer-deco"><?= count($allCandidates) ?> candidate(s)</div>
                <a href="#candidates" class="cd__cta">Voir toutes les candidates <svg class="cd__cta-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg></a>
            </div>
        </div>
    </div>
</section>

<!-- ═══ PARTICIPATION ═══ -->
<section class="pt" id="partenariat" aria-labelledby="pt-title">
    <div class="pt__tex" aria-hidden="true"></div>
    <div class="pt__wrap">
        <div class="pt__head">
            <div class="pt__eyebrow"><span class="pt__eyebrow-line"></span> Rejoignez l'aventure <span class="pt__eyebrow-line"></span></div>
            <h2 class="pt__title" id="pt-title">Participez à <em>l'Aventure</em></h2>
            <div class="pt__bar"></div>
            <p class="pt__subtitle">Rejoignez <?= esc($siteName) ?> 2026 en tant que candidate ou soutenez notre mission en devenant partenaire officiel</p>
        </div>
        <div class="pt__grid">
            <div class="pt__card pt__card--gold">
                <div class="pt__topbar"></div>
                <div class="pt__inner">
                    <div class="pt__icon pt__icon--gold" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z"></path><path d="M5 21h14"></path></svg></div>
                    <h3 class="pt__card-title">Devenir Candidate</h3>
                    <p class="pt__card-desc">Vous avez entre 18 et 30 ans, résidez à Kinshasa ? Postulez dès maintenant pour <?= esc($siteName) ?> 2026.</p>
                    <ul class="pt__criteria" role="list">
                        <li class="pt__criteria-item"><span class="pt__criteria-check" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"></path></svg></span>18–30 ans, résidente de Kinshasa</li>
                        <li class="pt__criteria-item"><span class="pt__criteria-check" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"></path></svg></span>Nationalité congolaise, célibataire</li>
                        <li class="pt__criteria-item"><span class="pt__criteria-check" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"></path></svg></span>Diplôme d'État ou certificat équivalent</li>
                        <li class="pt__criteria-item"><span class="pt__criteria-check" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"></path></svg></span>Bonne moralité, disponible pour toutes les activités</li>
                    </ul>
                    <div class="pt__sep"></div>
                    <a href="candidatures.php" class="pt__btn pt__btn--gold">Postuler maintenant <svg class="pt__btn-arrow" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg></a>
                    <p class="pt__deadline"><span class="pt__deadline-dot"></span> Inscriptions ouvertes — <?= esc($siteName) ?> 2026</p>
                </div>
            </div>
            <div class="pt__card pt__card--crimson">
                <div class="pt__topbar"></div>
                <div class="pt__inner">
                    <div class="pt__icon pt__icon--crimson" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 9.5a5.5 5.5 0 0 1 9.591-3.676.56.56 0 0 0 .818 0A5.49 5.49 0 0 1 22 9.5c0 2.29-1.5 4-3 5.5l-5.492 5.313a2 2 0 0 1-3 .019L5 15c-1.5-1.5-3-3.2-3-5.5"></path></svg></div>
                    <h3 class="pt__card-title">Devenir Partenaire</h3>
                    <p class="pt__card-desc">Associez votre marque à l'engagement social et culturel de <?= esc($siteName) ?> 2026.</p>
                    <ul class="pt__criteria" role="list">
                        <li class="pt__criteria-item"><span class="pt__criteria-check" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"></path></svg></span>Visibilité et notoriété locale ciblée</li>
                        <li class="pt__criteria-item"><span class="pt__criteria-check" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"></path></svg></span>Présence officielle pendant tous les événements</li>
                        <li class="pt__criteria-item"><span class="pt__criteria-check" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"></path></svg></span>Contenus promotionnels sur tous les supports médias</li>
                        <li class="pt__criteria-item"><span class="pt__criteria-check" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6 9 17l-5-5"></path></svg></span>Image positive, crédible et statut officiel</li>
                    </ul>
                    <div class="pt__sep"></div>
                    <a href="#contact" class="pt__btn pt__btn--crimson">Proposer un partenariat <svg class="pt__btn-arrow" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg></a>
                    <p class="pt__deadline"><span class="pt__deadline-dot"></span> Partenariats disponibles — Contactez-nous</p>
                </div>
            </div>
        </div>
        <p class="pt__confirm-note">Sponsors &amp; partenaires officiels — <em>à confirmer prochainement.</em></p>
    </div>
</section>

<!-- ═══ VOTE CTA ═══ -->
<section class="vv" aria-labelledby="vv-title">
    <div class="vv__pattern" aria-hidden="true"></div>
    <div class="vv__bg-photo" aria-hidden="true"></div>
    <div class="vv__wrap" id="vvWrap">
        <div class="vv__crown" aria-hidden="true">
            <div class="vv__crown-ring"><svg xmlns="http://www.w3.org/2000/svg" width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="#C6973F" stroke-width="1.8"><path d="M11.562 3.266a.5.5 0 0 1 .876 0L15.39 8.87a1 1 0 0 0 1.516.294L21.183 5.5a.5.5 0 0 1 .798.519l-2.834 10.246a1 1 0 0 1-.956.734H5.81a1 1 0 0 1-.957-.734L2.02 6.02a.5.5 0 0 1 .798-.519l4.276 3.664a1 1 0 0 0 1.516-.294z"></path><path d="M5 21h14"></path></svg></div>
        </div>
        <div class="vv__live-badge"><span class="vv__live-ping" aria-hidden="true"><span class="vv__live-core"></span><span class="vv__live-ring"></span></span> Vote du public — ouvert</div>
        <h2 class="vv__title" id="vv-title">Votre Vote <em>Compte !</em></h2>
        <div class="vv__bar"></div>
        <p class="vv__text">Le vote du public est <strong>actuellement ouvert</strong>. Participez à l'élection de la prochaine <?= esc($siteName) ?> — ambassadrice des causes sociales, culturelles et humanitaires de la ville.</p>
        <div class="vv__stats" role="list" aria-label="Chiffres du vote">
            <div class="vv__stat" role="listitem"><span class="vv__stat-num">2026</span><span class="vv__stat-label">Édition</span></div>
            <div class="vv__stat" role="listitem"><span class="vv__stat-num">Kinshasa</span><span class="vv__stat-label">Ville</span></div>
            <div class="vv__stat" role="listitem"><span class="vv__stat-num">Multiple Votes</span><span class="vv__stat-label">Par personne</span></div>
            <div class="vv__stat" role="listitem"><span class="vv__stat-num">Gala</span><span class="vv__stat-label">Résultats à la finale</span></div>
        </div>
        <div class="vv__btns">
            <a href="vote.php" class="vv__btn vv__btn--primary"><span class="vv__btn-live" aria-hidden="true"></span><svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="m9 12 2 2 4-4"></path><path d="M5 7c0-1.1.9-2 2-2h10a2 2 0 0 1 2 2v12H5V7Z"></path><path d="M22 19H2"></path></svg> Voter maintenant <svg class="vv__btn-arrow" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg></a>
            <a href="#candidates" class="vv__btn vv__btn--secondary">Voir les candidates <svg class="vv__btn-arrow" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg></a>
        </div>
        
    </div>
</section>

<!-- ═══ CONTACT ═══ -->
<section class="sec" id="contact" style="background:var(--bg2);padding:96px 0;position:relative;">
    <div class="sec-wrap" style="max-width:1300px;margin:0 auto;padding:0 40px;">
        <div class="reveal" style="text-align:center;opacity:1;transform:none;">
            <div class="sec-label" style="display:inline-flex;align-items:center;gap:10px;font-size:.6rem;font-weight:700;letter-spacing:.26em;text-transform:uppercase;color:var(--gold);margin-bottom:14px;">Nous rejoindre</div>
            <h2 class="sec-title" style="font-family:'Cormorant Garamond',serif;font-size:clamp(2rem,4.5vw,4rem);font-weight:300;line-height:1;letter-spacing:-.02em;color:#fff;margin-bottom:12px;text-align:center;">Contact & <em style="font-style:italic;font-weight:700;color:var(--gold-lt);">Inscription</em></h2>
            <div class="sec-bar" style="width:38px;height:2px;background:linear-gradient(90deg,var(--gold),var(--gold-lt));border-radius:2px;margin:14px auto 18px;"></div>
        </div>
        <div class="contact-grid" style="display:grid;grid-template-columns:1fr 1.2fr;gap:64px;align-items:start;margin-top:52px;">
            <div class="reveal" style="opacity:1;transform:none;">
                <div class="contact-items" style="display:flex;flex-direction:column;gap:18px;margin-bottom:32px;">
                    <div class="contact-item" style="display:flex;align-items:flex-start;gap:14px;">
                        <div class="ico" style="width:44px;height:44px;border-radius:10px;background:var(--gold-dim);border:1px solid var(--gold-bdr);display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--gold);"><svg viewBox="0 0 24 24" style="width:18px;height:18px;stroke:currentColor;stroke-width:2;fill:none;"><path d="M20 10c0 6-8 12-8 12s-8-6-8-10a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/></svg></div>
                        <div><div class="contact-item__lab" style="font-size:.6rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--muted2);margin-bottom:3px;">Adresse</div><div class="contact-item__val" style="font-size:.88rem;color:#fff;">40, Avenue Kasangulu, Commune de Kasa-Vubu<br>Kinshasa, RDC</div></div>
                    </div>
                    <div class="contact-item" style="display:flex;align-items:flex-start;gap:14px;">
                        <div class="ico" style="width:44px;height:44px;border-radius:10px;background:var(--gold-dim);border:1px solid var(--gold-bdr);display:flex;align-items:center;justify-content:center;flex-shrink:0;color:var(--gold);"><svg viewBox="0 0 24 24" style="width:18px;height:18px;stroke:currentColor;stroke-width:2;fill:none;"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.27h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.9a16 16 0 0 0 6.09 6.09l.91-.91a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg></div>
                        <div><div class="contact-item__lab" style="font-size:.6rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--muted2);margin-bottom:3px;">Téléphone / WhatsApp</div><div class="contact-item__val" style="font-size:.88rem;color:#fff;"><a href="tel:+243821835560" style="color:#fff;transition:color .2s;">+243 860 370 727</a></div></div>
                    </div>
                </div>
                <div class="socials" style="display:flex;gap:9px;flex-wrap:wrap;">
                    <a href="#" class="soc-btn" style="display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border-radius:8px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);font-size:.7rem;font-weight:600;color:var(--muted);transition:all .22s;">Facebook</a>
                    <a href="#" class="soc-btn" style="display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border-radius:8px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);font-size:.7rem;font-weight:600;color:var(--muted);transition:all .22s;">Instagram</a>
                    <a href="https://wa.me/243828277768" target="_blank" class="soc-btn" style="display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border-radius:8px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);font-size:.7rem;font-weight:600;color:var(--muted);transition:all .22s;">WhatsApp</a>
                    <a href="candidatures.php" class="soc-btn" style="display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border-radius:8px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.07);font-size:.7rem;font-weight:600;color:var(--muted);transition:all .22s;">Candidatures</a>
                </div>
            </div>
            <div class="contact-form reveal" style="transition-delay:.15s;opacity:1;transform:none;background:rgba(255,255,255,.025);border:1px solid rgba(255,255,255,.06);border-radius:18px;padding:36px;">
                <h3 style="font-family:'Cormorant Garamond',serif;font-size:1.7rem;font-weight:700;color:#fff;margin-bottom:24px;">Envoyez-nous <em style="font-style:italic;color:var(--gold-lt);">un message</em></h3>
                <div class="fg-row" style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <div class="fg" style="margin-bottom:16px;"><label style="display:block;font-size:.62rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--muted2);margin-bottom:7px;">Prénom</label><input type="text" placeholder="Votre prénom" style="width:100%;padding:12px 15px;border-radius:9px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.09);color:#fff;font-family:'Montserrat',sans-serif;font-size:.82rem;font-weight:300;outline:none;transition:border-color .22s,background .22s;"></div>
                    <div class="fg" style="margin-bottom:16px;"><label style="display:block;font-size:.62rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--muted2);margin-bottom:7px;">Nom</label><input type="text" placeholder="Votre nom" style="width:100%;padding:12px 15px;border-radius:9px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.09);color:#fff;font-family:'Montserrat',sans-serif;font-size:.82rem;font-weight:300;outline:none;transition:border-color .22s,background .22s;"></div>
                </div>
                <div class="fg" style="margin-bottom:16px;"><label style="display:block;font-size:.62rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--muted2);margin-bottom:7px;">Email</label><input type="email" placeholder="votre@email.com" style="width:100%;padding:12px 15px;border-radius:9px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.09);color:#fff;font-family:'Montserrat',sans-serif;font-size:.82rem;font-weight:300;outline:none;transition:border-color .22s,background .22s;"></div>
                <div class="fg" style="margin-bottom:16px;"><label style="display:block;font-size:.62rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--muted2);margin-bottom:7px;">Objet</label><select style="width:100%;padding:12px 15px;border-radius:9px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.09);color:#fff;font-family:'Montserrat',sans-serif;font-size:.82rem;font-weight:300;outline:none;transition:border-color .22s,background .22s;appearance:none;cursor:pointer;"><option value="">Sélectionnez un objet…</option><option>Proposition de projet</option><option>Partenariat / Sponsoring</option><option>Information générale</option><option>Presse / Médias</option></select></div>
                <div class="fg" style="margin-bottom:16px;"><label style="display:block;font-size:.62rem;font-weight:700;letter-spacing:.14em;text-transform:uppercase;color:var(--muted2);margin-bottom:7px;">Message</label><textarea placeholder="Votre message…" style="width:100%;padding:12px 15px;border-radius:9px;background:rgba(255,255,255,.04);border:1px solid rgba(255,255,255,.09);color:#fff;font-family:'Montserrat',sans-serif;font-size:.82rem;font-weight:300;outline:none;transition:border-color .22s,background .22s;height:90px;resize:vertical;"></textarea></div>
                <button class="btn btn-gold" style="width:100%;justify-content:center;display:inline-flex;align-items:center;gap:8px;font-family:'Montserrat',sans-serif;font-size:.72rem;font-weight:700;letter-spacing:.07em;text-transform:uppercase;padding:12px 26px;border-radius:8px;border:none;cursor:pointer;transition:all .25s;background:var(--gold);color:#000;" onclick="handleSubmit(this)">Envoyer le message →</button>
            </div>
        </div>
    </div>
</section>

<!-- ═══ FOOTER ═══ -->
<footer class="ft" role="contentinfo">
    <div class="ft__tex" aria-hidden="true"></div>
    <div class="ft__wrap">
        <div class="ft__grid">
            <div>
                <a href="index.php" class="ft__logo" aria-label="<?= esc($siteName) ?> — Accueil">
                    <?php if ($siteLogoUrl): ?>
                        <img src="<?= $siteLogoUrl ?>" alt="Logo <?= esc($siteName) ?>" width="150" height="100" style="object-fit:contain;">
                    <?php else: ?>
                        <img src="millenium.webp" width="150" height="100" alt="Logo par défaut">
                    <?php endif; ?>
                    <span class="ft__logo-text"><?= esc($siteName) ?></span>
                </a>
                <p class="ft__desc">LME GROUP organise des événements et accompagne les initiatives qui font rayonner les talents, la culture, le leadership et le développement communautaire en RDC.</p>
                <div class="ft__socials" role="list" aria-label="Réseaux sociaux">
                    <a href="#" class="ft__social" role="listitem" aria-label="Facebook"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"></path></svg></a>
                    <a href="#" class="ft__social" role="listitem" aria-label="Instagram"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="20" height="20" x="2" y="2" rx="5" ry="5"></rect><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"></path><line x1="17.5" x2="17.51" y1="6.5" y2="6.5"></line></svg></a>
                    <a href="#" class="ft__social" role="listitem" aria-label="X"><svg xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"></path></svg></a>
                    <a href="#" class="ft__social" role="listitem" aria-label="YouTube"><svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 0 24 24" fill="currentColor"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.95-1.96C18.88 4 12 4 12 4s-6.88 0-8.59.46A2.78 2.78 0 0 0 1.46 6.42 29 29 0 0 0 1 12a29 29 0 0 0 .46 5.58 2.78 2.78 0 0 0 1.95 1.96C5.12 20 12 20 12 20s6.88 0 8.59-.46a2.78 2.78 0 0 0 1.96-1.96A29 29 0 0 0 23 12a29 29 0 0 0-.46-5.58z"></path><polygon points="9.75 15.02 15.5 12 9.75 8.98 9.75 15.02" fill="#080808"></polygon></svg></a>
                </div>
            </div>
            <div><h3 class="ft__col-title">Navigation</h3><ul class="ft__nav-list" role="list">
                <li><a href="#concept" class="ft__nav-link"><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg> À propos</a></li>
                <li><a href="#phases" class="ft__nav-link"><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg> Compétition</a></li>
                <li><a href="#candidates" class="ft__nav-link"><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg> Candidates</a></li>
                <li><a href="#contact" class="ft__nav-link"><svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg> Contact</a></li>
            </ul></div>
            <div><h3 class="ft__col-title">Contact</h3><div class="ft__contact-list">
                <div class="ft__contact-item"><span class="ft__contact-icon" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"></path><circle cx="12" cy="10" r="3"></circle></svg></span><span class="ft__contact-text">40, Avenue Kasangulu, Commune de Kasa-Vubu<br>Kinshasa, RDC</span></div>
                <div class="ft__contact-item"><span class="ft__contact-icon" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.27h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.9a16 16 0 0 0 6.09 6.09l.91-.91a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"></path></svg></span><span class="ft__contact-text"><a href="https://wa.me/243860370727" target="_blank">+243 860 370 727</a><br><a href="tel:+243860370727">+243 860 370 727</a></span></div>
                <div class="ft__contact-item"><span class="ft__contact-icon" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect width="20" height="16" x="2" y="4" rx="2"></rect><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"></path></svg></span><span class="ft__contact-text"><a href="mailto:actutara@gmail.com">actutara@gmail.com</a></span></div>
                <div class="ft__contact-item"><span class="ft__contact-icon" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><path d="M2 12h20"></path><path d="M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20"></path></svg></span><span class="ft__contact-text">Site web : <em style="color:rgba(255,255,255,.6);font-style:italic;">en cours de création</em></span></div>
                <div class="ft__contact-item"><span class="ft__contact-icon" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12h6"></path><path d="M9 16h6"></path><rect x="4" y="3" width="16" height="18" rx="2"></rect></svg></span><span class="ft__contact-text">RCCM / ID NAT : <em style="color:rgba(255,255,255,.6);font-style:italic;">en cours de procédure</em></span></div>
            </div></div>
            <div><h3 class="ft__col-title">Newsletter</h3><p class="ft__nl-desc">Recevez les actualités, opportunités et projets portés par LME GROUP.</p>
            <form class="ft__nl-form" onsubmit="ftHandleNewsletter(event)" novalidate=""><div class="ft__nl-input-wrap"><input class="ft__nl-input" type="email" placeholder="Votre adresse email" required="" autocomplete="email" aria-label="Adresse email newsletter"></div>
            <button class="ft__nl-btn" type="submit">S'inscrire <svg class="ft__nl-arrow" xmlns="http://www.w3.org/2000/svg" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14"></path><path d="m12 5 7 7-7 7"></path></svg></button>
            <p class="ft__nl-note">Aucun spam. Désinscription libre à tout moment.</p></form></div>
        </div>
        <div class="ft__bottom">
            <p class="ft__copy">© 2026 <strong><?= esc($siteName) ?></strong>. Tous droits réservés. <strong>Inspirer · Former · Transformer</strong></p>
            <nav class="ft__legal" aria-label="Liens légaux"><a href="#" class="ft__legal-link">Made by Zaloria Tech</a></nav>
        </div>
    </div>
</footer>

<script>
// ─── HEADER ───
(function() {
    const header = document.getElementById('mlHeader');
    const toggle = document.getElementById('mlToggle');
    const drawer = document.getElementById('mlDrawer');
    const overlay = document.getElementById('mlOverlay');
    const THRESHOLD = 40;

    function onScroll() { header.classList.toggle('is-sticky', window.scrollY > THRESHOLD); }
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    function openDrawer() { toggle.classList.add('is-open'); drawer.classList.add('is-open'); overlay.classList.add('is-open'); toggle.setAttribute('aria-expanded', 'true'); drawer.setAttribute('aria-hidden', 'false'); document.body.style.overflow = 'hidden'; }
    function closeDrawer() { toggle.classList.remove('is-open'); drawer.classList.remove('is-open'); overlay.classList.remove('is-open'); toggle.setAttribute('aria-expanded', 'false'); drawer.setAttribute('aria-hidden', 'true'); document.body.style.overflow = ''; document.dispatchEvent(new Event('drawerClosed')); }
    toggle.addEventListener('click', () => drawer.classList.contains('is-open') ? closeDrawer() : openDrawer());
    overlay.addEventListener('click', closeDrawer);
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && drawer.classList.contains('is-open')) closeDrawer(); });
})();

// ─── MOBILE SUBMENU ───
document.querySelectorAll('.ml-mobile-menu__toggle').forEach(toggle => {
    toggle.addEventListener('click', function(e) {
        const targetId = this.dataset.target;
        const submenu = document.getElementById(targetId);
        if (submenu) {
            submenu.classList.toggle('open');
            this.querySelector('.ml-mobile-menu__arrow').classList.toggle('open');
        }
    });
});
document.addEventListener('drawerClosed', function() {
    document.querySelectorAll('.ml-mobile-menu__sub.open').forEach(sub => sub.classList.remove('open'));
    document.querySelectorAll('.ml-mobile-menu__arrow.open').forEach(arrow => arrow.classList.remove('open'));
});

// ─── HERO SLIDER ───
(function() {
    const TOTAL = <?= $totalSlides ?>;
    if (TOTAL === 0) return;
    const INTERVAL = 6000;
    const slides = document.querySelectorAll('.ml-hero__slide');
    const subtitle = document.getElementById('mlSubtitle');
    const descEl = document.getElementById('mlDesc');
    const ghost = document.getElementById('mlGhost');
    const counter = document.getElementById('mlCurrent');
    const bar = document.getElementById('mlBar');
    const progress = document.getElementById('mlProgress');
    const hero = document.getElementById('mlHero');
    let cur = 0, timer = null;

    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function goTo(idx) {
        const prev = cur;
        cur = ((idx % TOTAL) + TOTAL) % TOTAL;
        slides[prev].classList.remove('is-active');
        slides[cur].classList.add('is-active');
        counter.textContent = pad(cur + 1);
        bar.style.setProperty('--prog', ((cur + 1) / TOTAL * 100) + '%');
        const d = slides[cur].dataset;
        [subtitle, descEl, ghost].forEach(el => { el.style.transition = 'opacity .3s, transform .3s'; el.style.opacity = '0'; el.style.transform = 'translateY(-10px)'; });
        setTimeout(() => {
            subtitle.textContent = d.subtitle || '';
            descEl.textContent = d.desc || '';
            ghost.textContent = d.subtitle || '';
            [subtitle, descEl, ghost].forEach(el => { el.style.opacity = '1'; el.style.transform = 'translateY(0)'; });
        }, 320);
        resetTimer();
    }
    function resetTimer() {
        clearTimeout(timer);
        progress.style.transition = 'none';
        progress.style.width = '0%';
        requestAnimationFrame(() => {
            progress.style.transition = 'width ' + INTERVAL + 'ms linear';
            progress.style.width = '100%';
        });
        timer = setTimeout(() => goTo(cur + 1), INTERVAL);
    }
    document.getElementById('mlPrev').onclick = () => goTo(cur - 1);
    document.getElementById('mlNext').onclick = () => goTo(cur + 1);
    document.addEventListener('keydown', e => { if (e.key === 'ArrowLeft') goTo(cur - 1); if (e.key === 'ArrowRight') goTo(cur + 1); });
    let tx = 0;
    hero.addEventListener('touchstart', e => { tx = e.changedTouches[0].screenX; }, { passive: true });
    hero.addEventListener('touchend', e => { const dx = e.changedTouches[0].screenX - tx; if (Math.abs(dx) > 50) goTo(dx < 0 ? cur + 1 : cur - 1); }, { passive: true });
    hero.addEventListener('mouseenter', () => { clearTimeout(timer); progress.style.transition = 'none'; });
    hero.addEventListener('mouseleave', resetTimer);
    goTo(0);
})();

// ─── TICKER AJAX ───
function loadLiveStats() {
    const urlParams = new URLSearchParams(window.location.search);
    const concoursId = urlParams.get('concours_id') || <?= json_encode($concoursId) ?>;
    fetch('?ajax=votes_data&concours_id=' + encodeURIComponent(concoursId))
        .then(response => response.json())
        .then(data => {
            let rankingText = '';
            if (data.ranking.length === 0) {
                rankingText = 'Aucun vote pour le moment.';
            } else {
                const items = data.ranking.map((cand, index) => {
                    const rank = index + 1;
                    const medal = rank === 1 ? '🥇' : rank === 2 ? '🥈' : rank === 3 ? '🥉' : `#${rank}`;
                    return `${medal} <span class="gold-text">${cand.nom_complet}</span> <span class="votes-count">${cand.total_votes}</span> votes`;
                });
                rankingText = items.join(' <span class="separator">◆</span> ');
            }
            document.getElementById('ticker-ranking').innerHTML = `<span class="ticker-item">${rankingText}</span>`;

            let votesText = '';
            if (data.latestVotes.length === 0) {
                votesText = 'Aucun vote récent.';
            } else {
                const items = data.latestVotes.map(vote => {
                    return `<span class="highlight">${vote.nom_complet}</span> +<span class="gold-text">${vote.votes_accordes}</span> <span style="color:var(--muted);font-size:0.75rem;">${vote.telephone_masked}</span> <span style="color:rgba(255,255,255,0.3);font-size:0.7rem;">${vote.date_fr}</span>`;
                });
                votesText = items.join(' <span class="separator">◆</span> ');
            }
            document.getElementById('ticker-votes').innerHTML = `<span class="ticker-item">${votesText}</span>`;
        })
        .catch(error => console.error('Erreur ticker:', error));
}
loadLiveStats();
setInterval(loadLiveStats, 10000);

// ─── NEWSLETTER ───
function ftHandleNewsletter(e) {
    e.preventDefault();
    const form = e.target;
    const btn = form.querySelector('.ft__nl-btn');
    const input = form.querySelector('.ft__nl-input');
    const note = form.querySelector('.ft__nl-note');
    const origBtnHTML = btn.innerHTML;
    const origNote = note.textContent;
    btn.innerHTML = '⏳ Envoi...';
    btn.disabled = true;
    fetch('newsletter_subscribe.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'email=' + encodeURIComponent(input.value.trim())
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            btn.innerHTML = '✅ Inscrit !';
            btn.style.background = '#1F7A52';
            note.textContent = data.message;
            input.value = '';
        } else {
            btn.innerHTML = origBtnHTML;
            note.textContent = data.message;
            note.style.color = '#e74c3c';
        }
    })
    .catch(() => {
        btn.innerHTML = origBtnHTML;
        note.textContent = 'Erreur réseau. Veuillez réessayer.';
        note.style.color = '#e74c3c';
    })
    .finally(() => {
        btn.disabled = false;
        setTimeout(() => {
            btn.innerHTML = origBtnHTML;
            btn.style.background = '';
            btn.style.color = '';
            note.textContent = origNote;
            note.style.color = '';
        }, 4000);
    });
}

// ─── CONTACT FORM ───
function handleSubmit(btn) {
    btn.textContent = '✓ Message envoyé !';
    btn.style.background = '#22c55e';
    setTimeout(() => { btn.textContent = 'Envoyer le message →'; btn.style.background = ''; }, 3200);
}

// ─── ONGLETS CANDIDATES ───
(function() {
    const tabs = document.querySelectorAll('.cd__tab-btn');
    const panels = document.querySelectorAll('.cd__panel');

    function activateTab(tab) {
        // Désactiver tous les onglets
        tabs.forEach(t => {
            t.classList.remove('is-active');
            t.setAttribute('aria-selected', 'false');
        });
        // Activer l'onglet cible
        tab.classList.add('is-active');
        tab.setAttribute('aria-selected', 'true');

        // Masquer tous les panneaux
        panels.forEach(p => p.classList.remove('is-active'));
        // Afficher le panneau correspondant
        const panelId = tab.getAttribute('data-panel');
        const panel = document.getElementById(panelId);
        if (panel) panel.classList.add('is-active');
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', function(e) {
            activateTab(this);
        });
        tab.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                activateTab(this);
            }
        });
    });

    // Si un onglet est déjà actif, on s'assure que le panneau correspondant est visible
    const activeTab = document.querySelector('.cd__tab-btn.is-active');
    if (activeTab) {
        const panelId = activeTab.getAttribute('data-panel');
        const panel = document.getElementById(panelId);
        if (panel) {
            panels.forEach(p => p.classList.remove('is-active'));
            panel.classList.add('is-active');
        }
    }
})();

// ─── REVEAL ───
const reveals = document.querySelectorAll('.or__left, .or__mosaic, .vl__card, .cd__card, .pt__card, .vv__wrap');
const io = new IntersectionObserver(entries => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            if (entry.target.classList.contains('vl__card') || entry.target.classList.contains('cd__card') || entry.target.classList.contains('pt__card')) {
                const idx = Array.from(entry.target.parentElement.children).indexOf(entry.target);
                setTimeout(() => entry.target.classList.add('is-visible'), idx * 100);
            } else {
                entry.target.classList.add('is-visible');
            }
            io.unobserve(entry.target);
        }
    });
}, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });
reveals.forEach(r => io.observe(r));
</script>
</body>
</html>